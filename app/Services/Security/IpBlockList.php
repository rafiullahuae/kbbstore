<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\IpBlock;
use App\Services\CartTracking\CartTrackingSettings;
use App\Services\SecurityModule;
use App\Support\IpRange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * THE block list — one for the whole shop.                         (Lane CT)
 *
 * The owner, 4 October: "ability to block the full ip range with simple block /
 * unblock icon. this is very important bcz we received a lot of fake COD
 * orders, so we need to block those users. and also a lot of un-friendly bots."
 *
 * Before this there was no block list anywhere: SecurityModule records and
 * reports and, by its own design, refuses nothing. So this is not a second list
 * beside an existing one — it is the first, it lives under Security, and every
 * add and remove is written to the Security audit trail through
 * SecurityModule::record().
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ENFORCED WITH NO QUERY AND NO CACHE READ PER REQUEST
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * rebuild() compiles the rows into a PHP file under storage/framework:
 *
 *     [4 => [24 => ['cb007100' => [id, expires]], 32 => [...]], 6 => [...]]
 *
 * — one hash map per prefix length in use, keyed by the masked network in hex.
 * A lookup is: pack the address once, then for each prefix length present
 * (in practice two: /32 and /24) mask and isset(). With 1,000 blocks that is
 * still two isset() calls. The file is include()d, so under opcache the array
 * lives in shared memory and costs nothing to "load"; it carries the handful
 * of Cart Tracking settings the request path needs too, so BlockGate and
 * CartTracker never touch SettingsService.
 *
 * NOT the cache store: on a host whose CACHE_STORE is `database`, a
 * Cache::get() is a query on every page, which is exactly what this exists to
 * avoid.
 *
 * INVALIDATION IS A WRITE, NOT A TTL. Every add, remove and settings save calls
 * rebuild(), which writes a temp file and rename()s it over the old one (atomic
 * on one filesystem) and tells opcache. A request in flight sees the old list
 * or the new one, never half.
 *
 * A MISSING FILE IS "NO BLOCKS, NOT READY", never a query on the request path:
 * the request goes through untracked, and the file is rebuilt after the
 * response, once, under a lock. The migration builds it too.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT CAN NEVER BE BLOCKED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   - the admin's OWN address, refused with a sentence that says so;
 *   - loopback, private, CGNAT and link-local space, the server's own address
 *     and Cloudflare's edge — the places a proxy lives. If real-client-IP
 *     restoration ever broke, every shopper would arrive as one of these, and
 *     blocking it would shut the shop (IpRange::UNBLOCKABLE);
 *   - anything wider than /16 (IPv6 /32): that is an internet provider, not a
 *     visitor.
 */
final class IpBlockList
{
    public const VERSION = 2;

    /** Seconds before a failed build (no table yet) is retried. */
    private const RETRY = 60;

    private const LOCK = 'kbb.ipblocks.rebuild';

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    public function __construct(private SecurityModule $security) {}

    /* ═══════════════════════════════════════════════ the request path ═══ */

    public static function path(): string
    {
        // A test process gets a file of its own, so two lanes' suites — or a
        // preview server and a suite in one worktree — never share one.
        $suffix = app()->runningUnitTests() ? '-test-'.getmypid() : '';

        return storage_path('framework/kbb-ip-blocks'.$suffix.'.php');
    }

    /**
     * The compiled list and the request-path settings.
     *
     * @return array{v:int, ready:bool, retry:int, n:int, settings:array<string,mixed>, 4:array, 6:array}
     */
    public static function compiled(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $data = @include self::path();

        if (! is_array($data) || ($data['v'] ?? 0) !== self::VERSION) {
            $data = self::empty(false, 0);
            self::rebuildLater();
        } elseif (! $data['ready'] && time() >= (int) $data['retry']) {
            self::rebuildLater();
        }

        return self::$memo = $data;
    }

    /** The request-path settings (track, bots_leave, block_scope, thresholds). */
    public static function settings(): array
    {
        return self::compiled()['settings'];
    }

    /**
     * Is this address blocked? [id, cidr] of the first matching block, or null.
     *
     * @return array{0:int, 1:string}|null
     */
    public static function match(?string $ip): ?array
    {
        $c = self::compiled();

        if ($c['n'] === 0) {
            return null;
        }

        $bin = IpRange::pack($ip);

        if ($bin === null) {
            return null;
        }

        $family = strlen($bin) === 4 ? 4 : 6;

        foreach ($c[$family] as $prefix => $networks) {
            $hit = $networks[bin2hex(IpRange::mask($bin, (int) $prefix))] ?? null;

            // The application clock (not time()), and only read for a hit
            // that has an expiry at all.
            if ($hit !== null && ($hit[1] === 0 || $hit[1] > now()->getTimestamp())) {
                return [(int) $hit[0], inet_ntop(IpRange::mask($bin, (int) $prefix)).'/'.$prefix];
            }
        }

        return null;
    }

    /**
     * Count a refused request against its block. At most one write a minute per
     * block: a flood of refusals is one Cache::increment each and one UPDATE a
     * minute, never one UPDATE per refusal.
     */
    public static function recordHit(int $id): void
    {
        try {
            $key = 'kbb.ipblocks.hits.'.$id;
            Cache::add($key, 0, 86400);
            Cache::increment($key);

            if (Cache::add('kbb.ipblocks.flush.'.$id, 1, 60)) {
                self::flushHits($id);
            }
        } catch (\Throwable) {
            // Counting is never worth a 500 on a refusal.
        }
    }

    /** Move the counted hits into the row. */
    public static function flushHits(int $id): void
    {
        try {
            $n = (int) Cache::pull('kbb.ipblocks.hits.'.$id, 0);

            if ($n > 0) {
                IpBlock::query()->whereKey($id)->update([
                    'hits' => \Illuminate\Support\Facades\DB::raw('hits + '.$n),
                    'last_hit_at' => now(),
                ]);
            }
        } catch (\Throwable) {
        }
    }

    /** Hits counted but not yet flushed, for the Blocked tab. */
    public static function pendingHits(int $id): int
    {
        try {
            return (int) Cache::get('kbb.ipblocks.hits.'.$id, 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /* ═══════════════════════════════════════════════ compiling ═══ */

    /**
     * Read the rows and the settings, write the file. Returns what it wrote.
     *
     * Never throws: a missing table (the seconds between a package landing and
     * its migration running) writes a NOT READY file that is retried in a
     * minute, and the shop carries on unblocked and untracked meanwhile.
     */
    public static function rebuild(): array
    {
        try {
            $data = self::empty(true, 0, CartTrackingSettings::fromDatabase());

            foreach (IpBlock::query()->get(['id', 'family', 'prefix', 'network', 'expires_at']) as $row) {
                $expires = $row->expires_at?->getTimestamp() ?? 0;

                if ($expires !== 0 && $expires <= now()->getTimestamp()) {
                    continue;
                }

                $data[(int) $row->family][(int) $row->prefix][(string) $row->network] = [(int) $row->id, $expires];
                $data['n']++;
            }

            // Longest prefix first, so the most specific block is the one named.
            krsort($data[4]);
            krsort($data[6]);
        } catch (\Throwable $e) {
            $data = self::empty(false, time() + self::RETRY);
        }

        self::write($data);

        return self::$memo = $data;
    }

    /** Drop the in-process copy (the file stays). */
    public static function forget(): void
    {
        self::$memo = null;
    }

    /**
     * An empty, ready list with the default settings, written without a query.
     *
     * For the test suite: every test starts on a freshly migrated database with
     * no blocks and no settings rows, which is exactly this, and writing it
     * directly means no test's first request pays a rebuild — so a test that
     * counts queries counts the same with this feature as without it.
     */
    public static function seedEmpty(): void
    {
        self::$memo = null;
        $defaults = [];

        foreach (CartTrackingSettings::SCHEMA as $key => $def) {
            $defaults[$key] = $def[2];
        }

        self::write(self::empty(true, 0, $defaults));
        self::$memo = null;

        static $cleanup = false;

        if (! $cleanup && app()->runningUnitTests()) {
            $cleanup = true;
            $path = self::path();
            register_shutdown_function(static fn () => @unlink($path));
        }
    }

    private static function rebuildLater(): void
    {
        try {
            app()->terminating(static function (): void {
                try {
                    if (Cache::add(self::LOCK, 1, 30)) {
                        self::rebuild();
                        Cache::forget(self::LOCK);
                    }
                } catch (\Throwable $e) {
                    Log::warning('ip block list rebuild failed', ['exception' => class_basename($e)]);
                }
            });
        } catch (\Throwable) {
        }
    }

    private static function empty(bool $ready, int $retry, ?array $settings = null): array
    {
        if ($settings === null) {
            $settings = [];

            foreach (CartTrackingSettings::SCHEMA as $key => $def) {
                $settings[$key] = $def[2];
            }

            // Not ready means the schema may not exist: track nothing.
            $settings['track'] = $ready && $settings['track'];
        }

        return ['v' => self::VERSION, 'ready' => $ready, 'retry' => $retry, 'n' => 0, 'settings' => $settings, 4 => [], 6 => []];
    }

    private static function write(array $data): void
    {
        $path = self::path();
        $tmp = $path.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        try {
            if (@file_put_contents($tmp, '<?php return '.var_export($data, true).';'."\n", LOCK_EX) === false) {
                return;
            }

            if (! @rename($tmp, $path)) {
                @unlink($tmp);

                return;
            }

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        } catch (\Throwable) {
            @unlink($tmp);
        }
    }

    /* ═══════════════════════════════════════════════ managing ═══ */

    /**
     * Why $input may not be blocked, in a sentence for the owner — or null.
     *
     * @param  ?string  $adminIp  the address of the admin asking
     */
    public function refusal(?array $range, ?string $adminIp): ?string
    {
        if ($range === null) {
            return 'That is not an IP address or a range. Use 203.0.113.7, 203.0.113.0/24 or 2001:db8::/64.';
        }

        if ($range['prefix'] < IpRange::MIN_PREFIX[$range['family']]) {
            return sprintf(
                '%s is too wide: a range wider than /%d is a whole internet provider, and would shut out real customers with it. Block a narrower range.',
                $range['cidr'], IpRange::MIN_PREFIX[$range['family']]
            );
        }

        if ($adminIp !== null && IpRange::contains($range['cidr'], $adminIp)) {
            return sprintf(
                'That would block you: your own address (%s) is inside %s. Refused, so you cannot lock yourself out of the shop.',
                IpRange::normalise($adminIp), $range['cidr']
            );
        }

        $server = IpRange::normalise($_SERVER['SERVER_ADDR'] ?? null);

        if ($server !== null && IpRange::contains($range['cidr'], $server)) {
            return sprintf('%s contains this server\'s own address. Refused.', $range['cidr']);
        }

        $guard = IpRange::protectedRange($range['cidr']);

        if ($guard !== null) {
            return sprintf(
                '%s overlaps %s, which is a private network, the shop\'s own proxy or Cloudflare — not a visitor. If the shop ever saw every shopper as that address, blocking it would shut everyone out, so it is refused.',
                $range['cidr'], $guard
            );
        }

        return null;
    }

    /**
     * Block an address or range.
     *
     * @param  array{reason?:?string, source?:string, cart_id?:?int, days?:?int, admin_ip?:?string, admin_id?:?int, admin_name?:?string}  $opts
     * @return array{ok:bool, message:string, block?:array<string,mixed>, existing?:bool}
     */
    public function block(string $input, array $opts = []): array
    {
        $range = IpRange::parse($input);
        $why = $this->refusal($range, $opts['admin_ip'] ?? null);

        if ($why !== null) {
            return ['ok' => false, 'message' => $why];
        }

        $existing = IpBlock::query()->where('cidr', $range['cidr'])->first();

        if ($existing !== null && ($existing->expires_at === null || $existing->expires_at->isFuture())) {
            return ['ok' => true, 'existing' => true, 'message' => $range['cidr'].' is already blocked.', 'block' => self::row($existing)];
        }

        $covering = $this->covering($range);

        if ($covering !== null) {
            return ['ok' => true, 'existing' => true, 'message' => $range['cidr'].' is already inside the blocked range '.$covering->cidr.'.', 'block' => self::row($covering)];
        }

        $days = isset($opts['days']) ? (int) $opts['days'] : 0;

        $attributes = [
            'family' => $range['family'],
            'prefix' => $range['prefix'],
            'network' => $range['network'],
            'reason' => self::clip($opts['reason'] ?? null, 190),
            'source' => in_array($opts['source'] ?? '', ['cart', 'manual', 'bulk'], true) ? $opts['source'] : 'manual',
            'cart_id' => isset($opts['cart_id']) ? (int) $opts['cart_id'] : null,
            'created_by_id' => $opts['admin_id'] ?? null,
            'created_by' => self::clip($opts['admin_name'] ?? null, 120),
            'hits' => 0,
            'last_hit_at' => null,
            'expires_at' => $days > 0 ? now()->addDays(min(3650, $days)) : null,
        ];

        // An EXPIRED row for the same range is reused rather than duplicated:
        // `cidr` is unique, and the history of that row is the same range's.
        $block = $existing ?? new IpBlock(['cidr' => $range['cidr']]);
        $block->fill($attributes)->save();

        self::rebuild();

        $this->security->record('ipblock.added', 'Blocked '.$range['cidr'].($attributes['reason'] ? ' — '.$attributes['reason'] : ''), [
            'subject' => $range['cidr'],
            'severity' => 'notice',
        ]);

        return ['ok' => true, 'message' => $range['cidr'].' is blocked.', 'block' => self::row($block)];
    }

    /** @return array{ok:bool, message:string} */
    public function unblock(int $id): array
    {
        $block = IpBlock::query()->find($id);

        if ($block === null) {
            return ['ok' => false, 'message' => 'That block no longer exists.'];
        }

        $cidr = (string) $block->cidr;
        $block->delete();
        self::rebuild();
        Cache::forget('kbb.ipblocks.hits.'.$id);

        $this->security->record('ipblock.removed', 'Unblocked '.$cidr, ['subject' => $cidr, 'severity' => 'notice']);

        return ['ok' => true, 'message' => $cidr.' is unblocked.'];
    }

    /**
     * Every block whose range contains $ip (or equals the range), live ones only.
     *
     * @return list<IpBlock>
     */
    public function blocksFor(?string $ip): array
    {
        if (IpRange::pack($ip) === null) {
            return [];
        }

        return IpBlock::query()
            ->where('family', IpRange::family($ip))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->filter(fn (IpBlock $b) => IpRange::contains((string) $b->cidr, $ip))
            ->values()
            ->all();
    }

    /** A live block that already contains the whole of $range, if any. */
    private function covering(array $range): ?IpBlock
    {
        $network = (string) hex2bin($range['network']);

        $candidates = IpBlock::query()
            ->where('family', $range['family'])
            ->where('prefix', '<', $range['prefix'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        foreach ($candidates as $b) {
            if (bin2hex(IpRange::mask($network, (int) $b->prefix)) === (string) $b->network) {
                return $b;
            }
        }

        return null;
    }

    /** The allowlist of what a block row looks like to the screen. */
    public static function row(IpBlock $b): array
    {
        return [
            'id' => (int) $b->id,
            'cidr' => (string) $b->cidr,
            'single' => ($b->family === 4 && $b->prefix === 32) || ($b->family === 6 && $b->prefix === 128),
            'reason' => $b->reason,
            'source' => (string) $b->source,
            'cart_id' => $b->cart_id !== null ? (int) $b->cart_id : null,
            'by' => $b->created_by,
            'at' => $b->created_at?->toIso8601String(),
            'hits' => (int) $b->hits + self::pendingHits((int) $b->id),
            'last_hit_at' => $b->last_hit_at?->toIso8601String(),
            'expires_at' => $b->expires_at?->toIso8601String(),
            'expired' => $b->expires_at !== null && $b->expires_at->isPast(),
        ];
    }

    private static function clip(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
