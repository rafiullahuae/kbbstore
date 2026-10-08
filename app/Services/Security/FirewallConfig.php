<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\IpRange;
use Illuminate\Support\Facades\DB;

/**
 * Store → Security → Firewall: what the owner chose.               (Lane FW)
 *
 * Kept in `firewall_rules` (kind, subject, value), never in `settings`: the
 * settings map is unserialised on every shop page, and the request path must
 * not pay for an allow list it reads from a compiled file anyway.
 *
 * NOTHING HERE RUNS ON A REQUEST. compile() is called from
 * IpBlockList::rebuild(), on a write, and its answer rides in the same
 * include()d file as the block list — so the firewall adds no query, no cache
 * read and no settings read to the request path for its configuration.
 *
 * Every value is clamped on the way in AND on the way out, like
 * CartTrackingSettings: a select stores one of its own options or the default,
 * a range is clamped, a switch is a bool, an address is parsed by IpRange.
 *
 * ▲ DEFAULTS. `mode` ships as MONITOR — the brief's choice, so the owner can
 * watch a week of what would be refused before anything is. CN, RU, SG and HK
 * ship as Protect because the owner named them ("bots from china, russia,
 * singapore, hongkong"); every other country is Allow. Every good-bot family
 * ships ON ("always allow the google and other friendly bots").
 */
final class FirewallConfig
{
    /** key => [type, label, default, help, bounds|options] */
    public const SCHEMA = [
        'mode' => ['select', 'Firewall', 'monitor',
            'Off: nothing runs. Monitor: everything is counted and logged and NOTHING is refused — watch the live view first. On: refusals are enforced.',
            ['off' => 'Off', 'monitor' => 'Monitor (log only)', 'enforce' => 'On (enforce)']],
        'scope' => ['select', 'What a banned address cannot do', 'commerce',
            'Cart, checkout and forms: a banned address can still read the shop. The whole storefront: every shop page answers 429. The admin, webhooks and payment returns are never refused either way.',
            ['commerce' => 'Cart, checkout and forms', 'site' => 'The whole storefront']],
        'ip_10s' => ['range', 'Per address, in 10 seconds', 60,
            'More requests than this from one address within 10 seconds is a flood. Measured in Chrome: a fast shopper skimming with hover-prefetch peaked at 9 page and cart requests in 10 seconds.',
            ['min' => 20, 'max' => 600, 'step' => 5, 'unit' => ' requests']],
        'ip_60s' => ['range', 'Per address, in 60 seconds', 240,
            'The sustained limit for one address. The same fast shopper peaked at 26 in a minute.',
            ['min' => 60, 'max' => 3000, 'step' => 10, 'unit' => ' requests']],
        'net_60s' => ['range', 'Per range (/24 or IPv6 /64), in 60 seconds', 900,
            'For bots that rotate addresses inside one network. Kept high: a mobile carrier puts many real customers in one range.',
            ['min' => 120, 'max' => 10000, 'step' => 20, 'unit' => ' requests']],
        'prefetch_10s' => ['range', 'Prefetches per address, in 10 seconds', 80,
            'Hover-prefetch requests are counted on their own, never with page views, so prefetch can never get a shopper banned. The fast shopper peaked at 19 in 10 seconds.',
            ['min' => 20, 'max' => 600, 'step' => 5, 'unit' => ' prefetches']],
        'ban_minutes' => ['range', 'First ban lasts', 10,
            'A flood earns a temporary ban that ends by itself. Each repeat within 24 hours doubles it.',
            ['min' => 1, 'max' => 240, 'step' => 1, 'unit' => ' min']],
        'ban_max_minutes' => ['range', 'Longest ban', 1440,
            'Repeat offenders double up to this, then stay here.',
            ['min' => 10, 'max' => 10080, 'step' => 10, 'unit' => ' min']],
        'protect_percent' => ['range', 'Protect countries: flood limits at', 50,
            'A country set to Protect gets this share of the limits above.',
            ['min' => 20, 'max' => 100, 'step' => 5, 'unit' => '%']],
        'protect_proof' => ['bool', 'Protect countries: cart, checkout and forms need a page-load proof', true,
            'Invisible: the page a visitor opens carries a signed cookie, and adding to cart, checking out or sending a form must bring it back. A script that posts without loading a page is refused. No captcha, no extra request, no delay.'],
        'fake_bots' => ['bool', 'Refuse fake search-engine bots', true,
            'A visitor that calls itself Googlebot (or Bing, Apple, Yandex, Pinterest) but does not come from that company\'s network, checked by the company\'s own published method. No real browser ever sends those names.'],
    ];

    public const COUNTRY_ACTIONS = ['allow' => 'Allow', 'watch' => 'Watch', 'protect' => 'Protect', 'block' => 'Block'];

    /** The owner named these four. Every other country is Allow. */
    public const COUNTRY_DEFAULTS = ['CN' => 'protect', 'RU' => 'protect', 'SG' => 'protect', 'HK' => 'protect'];

    /** Most entries the allow list may hold: it is compiled into every request's file. */
    public const ALLOW_MAX = 500;

    /* ═══════════════════════════════════════════════ reading ═══ */

    /**
     * Everything, from the table — for the screen and for compile().
     * A missing table (the seconds before the migration) is the defaults.
     *
     * @return array{settings: array<string,mixed>, countries: array<string,string>, bots: array<string,bool>, allow: list<array{cidr:string, note:?string, by:?string, at:?string}>}
     */
    public static function all(): array
    {
        $rows = [];

        try {
            $rows = DB::table('firewall_rules')->orderBy('id')->get(['kind', 'subject', 'value', 'note', 'created_by', 'created_at'])->all();
        } catch (\Throwable) {
        }

        return self::fromRows($rows);
    }

    /** @param iterable<object> $rows */
    public static function fromRows(iterable $rows): array
    {
        $settings = self::defaults();
        $countries = self::COUNTRY_DEFAULTS;
        $bots = array_map(static fn () => true, GoodBots::FAMILIES);
        $allow = [];

        foreach ($rows as $row) {
            $kind = (string) $row->kind;
            $subject = (string) $row->subject;

            if ($kind === 'setting' && isset(self::SCHEMA[$subject])) {
                $settings[$subject] = self::cast($subject, json_decode((string) $row->value, true));
            } elseif ($kind === 'country' && preg_match('/^[A-Z]{2}$/', $subject) === 1) {
                $countries[$subject] = isset(self::COUNTRY_ACTIONS[(string) $row->value]) ? (string) $row->value : 'allow';
            } elseif ($kind === 'bot' && isset(GoodBots::FAMILIES[$subject])) {
                $bots[$subject] = (string) $row->value === '1';
            } elseif ($kind === 'allow' && ($r = IpRange::parse($subject)) !== null) {
                $allow[] = ['cidr' => $r['cidr'], 'note' => $row->note, 'by' => $row->created_by ?? null, 'at' => isset($row->created_at) ? (string) $row->created_at : null];
            }
        }

        ksort($countries);

        return ['settings' => $settings, 'countries' => $countries, 'bots' => $bots, 'allow' => $allow];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = $def[2];
        }

        return $out;
    }

    public static function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key];

        return match ($def[0]) {
            'bool' => is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $def[2]),
            'select' => is_string($value) && isset($def[4][$value]) ? $value : $def[2],
            'range' => is_numeric($value)
                ? max($def[4]['min'], min($def[4]['max'], (int) round((float) $value)))
                : $def[2],
            default => $def[2],
        };
    }

    /* ═══════════════════════════════════════════════ compiling ═══ */

    /**
     * What the request path needs, as plain arrays for var_export().
     *
     * `skip`  hash maps per family and prefix, exactly like the block list:
     *         the owner's always-allow list PLUS IpRange::UNBLOCKABLE (loopback,
     *         private space, the proxy, Cloudflare). If the real-client-IP
     *         restoration ever broke and every shopper arrived as the proxy's
     *         address, the firewall must count nobody rather than ban the shop.
     */
    public static function compile(?array $all = null): array
    {
        $all ??= self::all();
        $s = $all['settings'];

        $skip = [4 => [], 6 => []];
        $cidrs = array_merge(IpRange::UNBLOCKABLE, array_column($all['allow'], 'cidr'));

        foreach ($cidrs as $cidr) {
            $r = IpRange::parse($cidr);

            if ($r !== null) {
                $skip[$r['family']][$r['prefix']][$r['network']] = 1;
            }
        }

        krsort($skip[4]);
        krsort($skip[6]);

        $countries = array_filter($all['countries'], static fn (string $a): bool => $a !== 'allow');

        return [
            'mode' => $s['mode'],
            'scope' => $s['scope'],
            'ip_10s' => $s['ip_10s'],
            'ip_60s' => $s['ip_60s'],
            'net_60s' => $s['net_60s'],
            'prefetch_10s' => $s['prefetch_10s'],
            'ban' => $s['ban_minutes'],
            'ban_max' => $s['ban_max_minutes'],
            'protect_pct' => $s['protect_percent'],
            'proof' => $s['protect_proof'],
            'fake' => $s['fake_bots'],
            'countries' => $countries,
            'botre' => GoodBots::regex(array_keys(array_filter($all['bots']))),
            'skip' => $skip,
            'allow_n' => count($all['allow']),
        ];
    }

    /** The compiled shape with the shipped defaults and no table read. */
    public static function compileDefaults(): array
    {
        return self::compile(self::fromRows([]));
    }

    /* ═══════════════════════════════════════════════ writing ═══ */

    /** @param array<string, mixed> $values  unknown keys are ignored */
    public static function save(array $values, ?string $by = null): void
    {
        // Choosing a mode from the screen (or the console) supersedes the
        // emergency marker; `kbb:firewall off` writes it again after this.
        if (isset($values['mode'])) {
            @unlink(Firewall::killSwitch());
        }

        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                self::put('setting', (string) $key, json_encode(self::cast((string) $key, $value)), null, $by);
            }
        }

        IpBlockList::rebuild();
    }

    /** @param array<string, string> $rules  CC => allow|watch|protect|block */
    public static function setCountries(array $rules, ?string $by = null): int
    {
        $n = 0;

        foreach ($rules as $cc => $action) {
            $cc = strtoupper((string) $cc);

            if (preg_match('/^[A-Z]{2}$/', $cc) !== 1 || ! isset(self::COUNTRY_ACTIONS[(string) $action])) {
                continue;
            }

            self::put('country', $cc, (string) $action, null, $by);
            $n++;
        }

        IpBlockList::rebuild();

        return $n;
    }

    /** @param array<string, bool> $switches  family => on */
    public static function setBots(array $switches, ?string $by = null): void
    {
        foreach ($switches as $family => $on) {
            if (isset(GoodBots::FAMILIES[$family])) {
                self::put('bot', (string) $family, filter_var($on, FILTER_VALIDATE_BOOLEAN) ? '1' : '0', null, $by);
            }
        }

        IpBlockList::rebuild();
    }

    /**
     * Add to the always-allow list. Validated by IpRange::parse(): an address,
     * or a range no wider than /16 (IPv6 /32) — wider is a whole provider, and
     * "always allow" for a provider is how a bot network gets let in.
     *
     * @return array{ok:bool, message:string, cidr?:string}
     */
    public static function allow(string $input, ?string $note, ?string $by = null): array
    {
        $r = IpRange::parse($input);

        if ($r === null) {
            return ['ok' => false, 'message' => 'That is not an IP address or a range. Use 203.0.113.7, 203.0.113.0/24 or 2001:db8::/64.'];
        }

        if ($r['prefix'] < IpRange::MIN_PREFIX[$r['family']]) {
            return ['ok' => false, 'message' => $r['cidr'].' is too wide to always allow: that is a whole internet provider. Use /'.IpRange::MIN_PREFIX[$r['family']].' or narrower.'];
        }

        if (count(self::all()['allow']) >= self::ALLOW_MAX) {
            return ['ok' => false, 'message' => 'The always-allow list holds '.self::ALLOW_MAX.' entries at most. Remove one first.'];
        }

        $note = is_string($note) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $note) ?? ''), 0, 190) : null;
        self::put('allow', $r['cidr'], null, $note === '' ? null : $note, $by);
        IpBlockList::rebuild();

        return ['ok' => true, 'message' => $r['cidr'].' is always allowed.', 'cidr' => $r['cidr']];
    }

    public static function disallow(string $cidr): bool
    {
        $r = IpRange::parse($cidr);

        if ($r === null) {
            return false;
        }

        $n = DB::table('firewall_rules')->where('kind', 'allow')->where('subject', $r['cidr'])->delete();
        IpBlockList::rebuild();

        return $n > 0;
    }

    private static function put(string $kind, string $subject, ?string $value, ?string $note, ?string $by): void
    {
        $now = now();
        $by = $by !== null ? mb_substr($by, 0, 120) : null;

        $exists = DB::table('firewall_rules')->where('kind', $kind)->where('subject', $subject)->exists();

        if ($exists) {
            DB::table('firewall_rules')->where('kind', $kind)->where('subject', $subject)
                ->update(['value' => $value, 'note' => $note, 'created_by' => $by, 'updated_at' => $now]);
        } else {
            DB::table('firewall_rules')->insert([
                'kind' => $kind, 'subject' => $subject, 'value' => $value, 'note' => $note,
                'created_by' => $by, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
