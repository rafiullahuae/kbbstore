<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\IpRange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Store → Security → Firewall → Cloud networks.                   (Lane FWN)
 *
 * The owner's Analytics showed "visitors" from Singapore, the US, Hong Kong
 * and Brazil, 95% desktop, and Live activity showed who they were: Alibaba
 * Cloud (47.84.129.63) and Tencent Cloud (43.173.182.215, 43.172.194.156)
 * servers, rotating addresses across countries. Offered "a ready list of the
 * big cloud companies' ranges (Alibaba, Tencent and similar), which no real
 * shopper uses", he said "yes please". A server in a data centre is not a
 * person holding a phone, so a provider switched to Block is refused exactly
 * like a country on Block, within the firewall's own scope.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT IS OFFERED, AND WHAT IS NOT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * PROVIDERS: each with the ASNs whose registered holder IS that company,
 * checked against each ASN's registry record (ipverse/asn-ip, 19 Oct 2026).
 * Alibaba, Tencent and Huawei ship on Block (the owner's request). The rest
 * are offered OFF. Amazon, Google and Microsoft are NOT offered at all
 * (NOT_OFFERED): Stripe, Tabby, Tamara, Meta, WhatsApp, couriers and uptime
 * monitors call the shop from them, and real people's VPNs and office proxies
 * leave through them.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE REQUEST PATH: no query, no settings read, no network, no file parse
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The enabled providers are compiled (compileFor()) into one PHP file under
 * storage/framework — IPv4 as sorted integer start/end arrays, IPv6 as sorted
 * fixed-width hex — and include()d, so under OPcache it is an immutable array
 * in shared memory. A lookup is one binary search (~11 steps for 1,000
 * ranges). The firewall's compiled settings (IpBlockList::compiled()['fw'])
 * carry `cloud` = the enabled keys; the compiled file names the set it was
 * built for, and a mismatch is answered "not listed" (fail open) and rebuilt
 * after the response — never a refusal from a stale list.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE DATA, and why a bad download can never replace a good list
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Source: https://raw.githubusercontent.com/ipverse/asn-ip/master/as/<ASN>/
 * ipv4-aggregated.txt and ipv6-aggregated.txt (one CIDR a line, `#` comments),
 * RIPEstat announced-prefixes as the fallback. Refreshed weekly by
 * `kbb:firewall data --auto`, the "Refresh cloud lists" button on the Data
 * tab, or `kbb:firewall cloud refresh`.
 *
 * Stored as text ("<provider> AS<n> <cidr>" a line, under a header carrying a
 * SHA-256 of the body) in storage/app/firewall/cloud-networks.txt. A copy built
 * on 19 Oct 2026 ships in resources/firewall/cloud-networks.txt (`.txt`: the
 * updater writes nothing else into resources/), so the list works the moment
 * the package is applied, even on a server that cannot download.
 *
 * Every line is validated by IpRange; private, reserved, Cloudflare (all of
 * IpRange::UNBLOCKABLE), documentation and too-wide ranges are dropped. A
 * provider whose download fails, comes back empty, shrinks by more than half
 * or exceeds MAX_PER_PROVIDER keeps its previous list.
 */
final class CloudNetworks
{
    /**
     * key => [label, ASNs, ships on Block?, who]. Keys are ≤ 12 characters:
     * the log's reason is "net_<key>" in a 16-character column.
     */
    public const PROVIDERS = [
        'alibaba' => ['Alibaba Cloud', [45102, 37963, 24429, 134963], true,
            'Alibaba Cloud / Aliyun: Alibaba (US) Technology, Hangzhou Alibaba Advertising, Zhejiang Taobao Network, Alibaba Cloud (Singapore)'],
        'tencent' => ['Tencent Cloud', [132203, 45090, 133478], true,
            'Tencent Cloud: Shenzhen Tencent Computer Systems (two networks), Tencent Cloud Computing (Beijing)'],
        'huawei' => ['Huawei Cloud', [136907, 55990, 141180, 131444], true,
            'Huawei Cloud: Huawei International (HWCLOUDS and its sister networks), Huawei Cloud Service data center'],
        'digitalocean' => ['DigitalOcean', [14061], false, 'DigitalOcean LLC'],
        'ovh' => ['OVHcloud', [16276], false, 'OVH SAS'],
        'hetzner' => ['Hetzner', [24940], false, 'Hetzner Online GmbH'],
        'contabo' => ['Contabo', [51167], false, 'Contabo GmbH'],
        'vultr' => ['Vultr (Choopa)', [20473], false, 'The Constant Company (Vultr / Choopa)'],
        'linode' => ['Linode (Akamai Cloud)', [63949], false, 'Akamai Technologies (Linode)'],
        'oracle' => ['Oracle Cloud', [31898], false, 'Oracle Corporation (Oracle Cloud Infrastructure)'],
    ];

    public const NOT_OFFERED = 'Amazon (AWS), Google Cloud and Microsoft Azure are not offered: Stripe, Tabby, Tamara, Meta, WhatsApp, couriers and uptime monitors call the shop from them, and real people\'s VPNs and office proxies use them.';

    public const ACTIONS = ['block' => 'Block', 'allow' => 'Allow'];

    public const SOURCE_URL = 'https://raw.githubusercontent.com/ipverse/asn-ip/master/as/%d/ipv%d-aggregated.txt';

    public const FALLBACK_URL = 'https://stat.ripe.net/data/announced-prefixes/data.json?resource=AS%d';

    public const BUNDLED = 'firewall/cloud-networks.txt';

    public const HEADER = '# KBB cloud networks v1';

    /** Wider than this is not one company's network: dropped. */
    public const MIN_PREFIX = [4 => 12, 6 => 19];

    public const MAX_PER_PROVIDER = 6000;

    public const MAX_TOTAL = 30000;

    /** Never a shopper and never a cloud: on top of IpRange::UNBLOCKABLE. */
    private const RESERVED = [
        '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '255.255.255.255/32', '2001:db8::/32', '64:ff9b::/96', '100::/64',
    ];

    private const COMPILED_V = 1;

    private const LOCK = 'kbb.cloudnets.rebuild';

    /** @var array<string, mixed>|false|null the compiled file */
    private static mixed $compiled = null;

    /** @var array<string, mixed>|null the parsed source */
    private static ?array $source = null;

    private static ?string $sourceOverride = null;

    /** Tests that start from an empty list rather than the bundled one. */
    private static bool $useBundled = true;

    private static bool $queued = false;

    /** A test changed the source list: its compiled file must not outlive it. */
    private static bool $dirty = false;

    /* ═══════════════════════════════════════════════ the request path ═══ */

    /**
     * The provider key whose list holds $bin (IpRange::pack()), or null.
     * $for is the firewall's compiled `cloud` (the enabled keys, comma-joined).
     */
    public static function match(string $bin, string $for): ?string
    {
        $c = self::$compiled ??= self::includeCompiled();

        if (! is_array($c) || $c['for'] !== $for) {
            self::rebuildLater($for);

            return null; // fail open: never refuse from a list built for another set
        }

        if (strlen($bin) === 4) {
            $x = unpack('N', $bin)[1];
            $s = $c['s4'];
            $lo = 0;
            $hi = count($s) - 1;

            // The last range whose start is ≤ the address.
            while ($lo < $hi) {
                $mid = ($lo + $hi + 1) >> 1;

                if ($s[$mid] <= $x) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }

            return $hi >= 0 && $s[$lo] <= $x && $x <= $c['e4'][$lo] ? $c['names'][$c['p4'][$lo]] : null;
        }

        $x = bin2hex($bin);
        $s = $c['s6'];
        $lo = 0;
        $hi = count($s) - 1;

        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;

            if (strcmp($s[$mid], $x) <= 0) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $hi >= 0 && strcmp($s[$lo], $x) <= 0 && strcmp($x, $c['e6'][$lo]) <= 0 ? $c['names'][$c['p6'][$lo]] : null;
    }

    private static function includeCompiled(): array|false
    {
        $data = @include self::compiledPath();

        return is_array($data) && ($data['v'] ?? 0) === self::COMPILED_V ? $data : false;
    }

    private static function rebuildLater(string $for): void
    {
        if (self::$queued) {
            return;
        }

        self::$queued = true;

        try {
            app()->terminating(static function () use ($for): void {
                self::$queued = false;

                try {
                    if (Cache::add(self::LOCK, 1, 30)) {
                        self::compileFor($for, true);
                        Cache::forget(self::LOCK);
                    }
                } catch (\Throwable $e) {
                    Log::warning('cloud network list rebuild failed', ['exception' => class_basename($e)]);
                }
            });
        } catch (\Throwable) {
        }
    }

    /* ═══════════════════════════════════════════════ compiling ═══ */

    public static function compiledPath(): string
    {
        return storage_path('framework/kbb-cloud-nets'.(app()->runningUnitTests() ? '-test-'.getmypid() : '').'.php');
    }

    /** The enabled set as the compiled firewall carries it. @param array<string,string> $actions */
    public static function enabledKey(array $actions): string
    {
        $on = [];

        foreach (self::PROVIDERS as $key => $_) {
            if (($actions[$key] ?? null) === 'block') {
                $on[] = $key;
            }
        }

        return implode(',', $on);
    }

    /**
     * Write the request-path file for the enabled set $for. Skipped when the
     * file is already built for that set from the same source, so the block
     * list's rebuild (which calls this) costs one include() when nothing moved.
     */
    public static function compileFor(string $for, bool $force = false): void
    {
        $src = self::sourceStamp();
        $existing = self::includeCompiled();

        if (! $force && is_array($existing) && $existing['for'] === $for && $existing['src'] === $src) {
            self::$compiled = $existing;

            return;
        }

        $keys = $for === '' ? [] : array_values(array_intersect(explode(',', $for), array_keys(self::PROVIDERS)));
        $providers = self::load()['providers'];
        $server = IpRange::pack($_SERVER['SERVER_ADDR'] ?? null);
        $pairs = [4 => [], 6 => []];

        foreach ($keys as $i => $key) {
            foreach ($providers[$key]['cidrs'] ?? [] as $cidr) {
                $r = IpRange::parse($cidr);

                if ($r === null) {
                    continue;
                }

                $start = (string) hex2bin($r['network']);
                $end = self::last($start, $r['prefix']);

                // The server's own address is never in the list (a shop
                // hosted on a listed cloud still reaches itself).
                if ($server !== null && strlen($server) === strlen($start)
                    && strcmp($server, $start) >= 0 && strcmp($server, $end) <= 0) {
                    continue;
                }

                $pairs[$r['family']][] = [$start, $end, $i];
            }
        }

        $out = ['v' => self::COMPILED_V, 'for' => $for, 'src' => $src, 'names' => $keys,
            's4' => [], 'e4' => [], 'p4' => [], 's6' => [], 'e6' => [], 'p6' => []];

        foreach ([4, 6] as $family) {
            $list = $pairs[$family];
            usort($list, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: $a[2] <=> $b[2]);
            $merged = [];

            foreach ($list as [$s, $e, $p]) {
                $n = count($merged);

                if ($n > 0) {
                    $prev = &$merged[$n - 1];

                    if (strcmp($s, $prev[1]) <= 0) {
                        // Overlap: extend a same-provider range, otherwise keep
                        // only the part after it, so the ranges stay disjoint.
                        if (strcmp($e, $prev[1]) <= 0) {
                            unset($prev);
                            continue;
                        }

                        if ($prev[2] === $p) {
                            $prev[1] = $e;
                            unset($prev);
                            continue;
                        }

                        $s = self::next($prev[1]);
                    } elseif ($prev[2] === $p && $s === self::next($prev[1])) {
                        $prev[1] = $e;
                        unset($prev);
                        continue;
                    }

                    unset($prev);
                }

                $merged[] = [$s, $e, $p];
            }

            foreach ($merged as [$s, $e, $p]) {
                if ($family === 4) {
                    $out['s4'][] = unpack('N', $s)[1];
                    $out['e4'][] = unpack('N', $e)[1];
                    $out['p4'][] = $p;
                } else {
                    $out['s6'][] = bin2hex($s);
                    $out['e6'][] = bin2hex($e);
                    $out['p6'][] = $p;
                }
            }
        }

        self::writePhp(self::compiledPath(), $out);
        self::$compiled = $out;
    }

    /** Changes whenever the source file in use changes: cheap (one stat). */
    private static function sourceStamp(): string
    {
        $path = self::sourcePath();
        clearstatcache(true, $path);

        return is_file($path) ? 'd:'.filemtime($path).':'.filesize($path) : 'b:'.@filesize(resource_path(self::BUNDLED));
    }

    /* ═══════════════════════════════════════════════ the source list ═══ */

    public static function sourcePath(): string
    {
        return self::$sourceOverride ?? storage_path('app/firewall/cloud-networks'.(app()->runningUnitTests() ? '-test-'.getmypid() : '').'.txt');
    }

    /** Tests point the downloaded list somewhere of their own. */
    public static function useSourcePath(?string $path, bool $bundled = true): void
    {
        self::$sourceOverride = $path;
        self::$useBundled = $bundled;
        self::$source = null;
        self::$compiled = null;
        self::$dirty = true;
    }

    /**
     * The list in use: the downloaded one when it verifies, else the bundled
     * copy, else nothing.
     *
     * @return array{ok:bool, from:string, at:?string, providers: array<string, array{asns: array<int,int>, cidrs: list<string>, lines: list<array{0:int, 1:string}>}>, error:?string}
     */
    public static function load(): array
    {
        if (self::$source !== null) {
            return self::$source;
        }

        $mine = self::verify(self::sourcePath());

        if ($mine['ok']) {
            return self::$source = $mine + ['from' => 'downloaded'];
        }

        $bundled = self::$useBundled ? self::verify(resource_path(self::BUNDLED)) : ['ok' => false];

        if ($bundled['ok']) {
            return self::$source = $bundled + ['from' => 'bundled'];
        }

        return self::$source = ['ok' => false, 'from' => 'none', 'at' => null, 'providers' => [], 'error' => $mine['error']];
    }

    /**
     * Parse and check a list file: header, checksum, every line.
     *
     * @return array{ok:bool, at:?string, providers: array<string, array{asns: array<int,int>, cidrs: list<string>}>, error:?string}
     */
    public static function verify(string $path): array
    {
        $fail = static fn (string $why): array => ['ok' => false, 'at' => null, 'providers' => [], 'error' => $why];

        if (! is_file($path)) {
            return $fail('not downloaded yet');
        }

        $text = @file_get_contents($path, false, null, 0, 4_000_000);

        if (! is_string($text) || ! str_starts_with($text, self::HEADER)) {
            return $fail('not a cloud network list (wrong header)');
        }

        $at = null;
        $sha = null;
        $body = [];

        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                continue;
            }

            if ($line[0] === '#') {
                if (preg_match('/^#\s*built (\S+)/', $line, $m) === 1) {
                    $at = $m[1];
                } elseif (preg_match('/^#\s*sha256 ([0-9a-f]{64})$/', $line, $m) === 1) {
                    $sha = $m[1];
                }

                continue;
            }

            $body[] = $line;
        }

        if ($sha === null || ! hash_equals($sha, hash('sha256', implode("\n", $body)))) {
            return $fail('checksum does not match: the list is damaged');
        }

        $providers = [];
        $total = 0;

        foreach ($body as $line) {
            if (preg_match('/^([a-z]{2,12}) AS(\d{1,10}) (\S{1,49})$/', $line, $m) !== 1
                || ! isset(self::PROVIDERS[$m[1]]) || ! in_array((int) $m[2], self::PROVIDERS[$m[1]][1], true)
                || ($cidr = self::clean($m[3])) === null) {
                return $fail('a line is not valid: '.mb_substr($line, 0, 60));
            }

            $providers[$m[1]]['asns'][(int) $m[2]] = ($providers[$m[1]]['asns'][(int) $m[2]] ?? 0) + 1;
            $providers[$m[1]]['cidrs'][] = $cidr;
            $providers[$m[1]]['lines'][] = [(int) $m[2], $cidr];
            $total++;
        }

        foreach ($providers as $key => $p) {
            if (count($p['cidrs']) > self::MAX_PER_PROVIDER) {
                return $fail(self::PROVIDERS[$key][0].' has too many ranges');
            }
        }

        if ($total === 0 || $total > self::MAX_TOTAL) {
            return $fail($total === 0 ? 'the list is empty' : 'the list is too large');
        }

        return ['ok' => true, 'at' => $at, 'providers' => $providers, 'error' => null];
    }

    /**
     * A CIDR fit for the list, canonical, or null: parseable, no wider than
     * MIN_PREFIX, a public unicast range, clear of IpRange::UNBLOCKABLE and
     * of documentation and other reserved space.
     */
    public static function clean(string $cidr): ?string
    {
        if (! str_contains($cidr, '/')) {
            return null;
        }

        $r = IpRange::parse($cidr);

        if ($r === null || $r['prefix'] < self::MIN_PREFIX[$r['family']]) {
            return null;
        }

        if ($r['family'] === 6 && (hexdec($r['network'][0]) & 0xE) !== 0x2) {
            return null; // outside 2000::/3, the only global unicast space
        }

        if (IpRange::protectedRange($r['cidr']) !== null) {
            return null;
        }

        foreach (self::RESERVED as $reserved) {
            if (IpRange::overlaps($reserved, $r['cidr'])) {
                return null;
            }
        }

        return $r['cidr'];
    }

    /** @param array<string, array{lines: list<array{0:int, 1:string}>}> $providers */
    public static function serialise(array $providers, string $at): string
    {
        $body = [];

        foreach (self::PROVIDERS as $key => $_) {
            foreach ($providers[$key]['lines'] ?? [] as [$asn, $cidr]) {
                $body[] = $key.' AS'.$asn.' '.$cidr;
            }
        }

        $joined = implode("\n", $body);

        return self::HEADER."\n"
            .'# built '.$at.' from ipverse/asn-ip (aggregated, per ASN); RIPEstat announced-prefixes as fallback'."\n"
            .'# sha256 '.hash('sha256', $joined)."\n"
            .$joined."\n";
    }

    /* ═══════════════════════════════════════════════ refreshing ═══ */

    /**
     * Download every provider's ranges and write the list. A provider whose
     * download fails, comes back empty, shrinks by more than half or is too
     * large keeps its previous ranges; nothing usable at all keeps the whole
     * previous file. Then the request-path file is rebuilt.
     *
     * @param  (callable(string): ?string)|null  $fetch  URL → body (tests)
     * @return array<string, array{ok:bool, ranges:int, error:?string}>
     */
    public static function refresh(?callable $fetch = null): array
    {
        $fetch ??= static function (string $url): ?string {
            $r = Http::timeout(30)->connectTimeout(10)->withHeaders(['User-Agent' => 'KBB-Firewall/1.0'])->get($url);

            return $r->successful() ? $r->body() : null;
        };

        $old = self::load();
        $lines = [];
        $report = [];

        // The previous list's lines, by provider: what a failure keeps.
        $previous = array_map(static fn (array $p): array => $p['lines'], $old['providers']);

        foreach (self::PROVIDERS as $key => [$label, $asns]) {
            $new = [];
            $error = null;

            foreach ($asns as $asn) {
                $got = [];

                foreach ([4, 6] as $v) {
                    try {
                        $body = $fetch(sprintf(self::SOURCE_URL, $asn, $v));
                    } catch (\Throwable $e) {
                        $body = null;
                    }

                    if (is_string($body)) {
                        array_push($got, ...self::cidrsIn($body));
                    }
                }

                if ($got === []) {
                    try {
                        $body = $fetch(sprintf(self::FALLBACK_URL, $asn));
                    } catch (\Throwable) {
                        $body = null;
                    }

                    $got = is_string($body) ? self::cidrsIn($body) : [];
                }

                if ($got === []) {
                    $error = 'no ranges for AS'.$asn;
                    break;
                }

                foreach (array_unique($got) as $cidr) {
                    $new[] = [$asn, $cidr];
                }
            }

            $before = count($previous[$key] ?? []);
            $now = count($new);

            if ($error === null && $now > self::MAX_PER_PROVIDER) {
                $error = $now.' ranges is more than '.self::MAX_PER_PROVIDER;
            } elseif ($error === null && $before > 0 && $now < intdiv($before, 2)) {
                $error = 'only '.$now.' ranges against '.$before.' before';
            }

            if ($error !== null) {
                $lines[$key] = $previous[$key] ?? [];
                $report[$key] = ['ok' => false, 'ranges' => count($lines[$key]), 'error' => $error.'; kept the previous list'];
                continue;
            }

            $lines[$key] = $new;
            $report[$key] = ['ok' => true, 'ranges' => $now, 'error' => null];
        }

        $anyNew = array_filter($report, static fn (array $r): bool => $r['ok']) !== [];
        $total = array_sum(array_map('count', $lines));

        if ($anyNew && $total > 0 && $total <= self::MAX_TOTAL) {
            $text = self::serialise(array_map(static fn (array $l): array => ['lines' => $l], $lines), now()->toIso8601String());
            $path = self::sourcePath();
            @mkdir(dirname($path), 0775, true);
            $tmp = $path.'.'.getmypid().'.tmp';

            // Checked as the request path would read it BEFORE it replaces anything.
            if (@file_put_contents($tmp, $text, LOCK_EX) !== false && self::verify($tmp)['ok'] && @rename($tmp, $path)) {
                self::$source = null;
                self::$dirty = true;
            } else {
                @unlink($tmp);
                foreach ($report as $key => $r) {
                    $report[$key] = ['ok' => false, 'ranges' => count($previous[$key] ?? []), 'error' => 'the new list could not be written; kept the previous list'];
                }
            }
        }

        try {
            self::compileFor((string) (IpBlockList::compiled()['fw']['cloud'] ?? ''), true);
        } catch (\Throwable) {
        }

        return $report;
    }

    /**
     * CIDRs in an ipverse text body ("#" comments, one per line) or a RIPEstat
     * JSON answer, each cleaned; anything else is ignored.
     *
     * @return list<string>
     */
    public static function cidrsIn(string $body): array
    {
        $candidates = [];
        $json = str_starts_with(ltrim($body), '{') ? json_decode($body, true) : null;

        if (is_array($json)) {
            foreach ((array) ($json['data']['prefixes'] ?? []) as $p) {
                if (is_array($p) && is_string($p['prefix'] ?? null)) {
                    $candidates[] = $p['prefix'];
                }
            }
        } else {
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);

                if ($line !== '' && $line[0] !== '#') {
                    $candidates[] = $line;
                }
            }
        }

        $out = [];

        foreach ($candidates as $c) {
            $clean = self::clean($c);

            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════ the screen ═══ */

    /**
     * Per provider: label, ASNs, how many ranges the list in use holds.
     *
     * @return array{from:string, at:?string, ok:bool, error:?string, total:int, providers: array<string, array{label:string, asns:list<int>, v4:int, v6:int, ranges:int}>}
     */
    public static function about(): array
    {
        $src = self::load();
        $out = [];
        $total = 0;

        foreach (self::PROVIDERS as $key => [$label, $asns]) {
            $v4 = 0;
            $v6 = 0;

            foreach ($src['providers'][$key]['cidrs'] ?? [] as $cidr) {
                str_contains($cidr, ':') ? $v6++ : $v4++;
            }

            $out[$key] = ['label' => $label, 'asns' => $asns, 'v4' => $v4, 'v6' => $v6, 'ranges' => $v4 + $v6];
            $total += $v4 + $v6;
        }

        return ['from' => $src['from'], 'at' => $src['at'], 'ok' => $src['ok'], 'error' => $src['error'], 'total' => $total, 'providers' => $out];
    }

    /** How old the list in use is, in days (null: none) — for the weekly schedule. */
    public static function ageDays(): ?float
    {
        $path = self::sourcePath();

        return is_file($path) && self::load()['from'] === 'downloaded' ? (time() - (int) filemtime($path)) / 86400 : null;
    }

    public static function forget(): void
    {
        self::$compiled = null;
        self::$source = null;
        self::$queued = false;

        if (self::$dirty && app()->runningUnitTests()) {
            @unlink(self::compiledPath());

            if (self::$sourceOverride === null) {
                @unlink(self::sourcePath());
            }
        }

        self::$dirty = false;
        self::$sourceOverride = null;
        self::$useBundled = true;
    }

    /** Drop the in-process copies only (files stay). */
    public static function flush(): void
    {
        self::$compiled = null;
        self::$source = null;
    }

    /* ═══════════════════════════════════════════════ helpers ═══ */

    private static function writePhp(string $path, array $data): void
    {
        @mkdir(dirname($path), 0775, true);
        $tmp = $path.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($tmp, '<?php return '.var_export($data, true).';'."\n", LOCK_EX) !== false && @rename($tmp, $path)) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }

            return;
        }

        @unlink($tmp);
        Log::warning('firewall: could not write the cloud network file');
    }

    private static function last(string $network, int $prefix): string
    {
        $out = '';

        for ($i = 0, $len = strlen($network); $i < $len; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $out .= chr(ord($network[$i]) | (0xFF >> $bits));
        }

        return $out;
    }

    private static function next(string $bin): string
    {
        for ($i = strlen($bin) - 1; $i >= 0; $i--) {
            $b = ord($bin[$i]);

            if ($b < 255) {
                return substr($bin, 0, $i).chr($b + 1).str_repeat("\0", strlen($bin) - $i - 1);
            }
        }

        return $bin;
    }
}
