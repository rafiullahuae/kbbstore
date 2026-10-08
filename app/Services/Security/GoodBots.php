<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\IpRange;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Search engines, ad checkers and link previews: always allowed — when they
 * are who they say they are.                                       (Lane FW)
 *
 * The owner: "always allow the google and other friendly bots!" And Google Ads
 * checks every landing page with AdsBot-Google, so refusing it costs ads.
 *
 * A USER AGENT IS A CLAIM, NOT A PROOF. Anybody can send "Googlebot". So a
 * claim is checked against the operator's own published method:
 *
 *   1. IP-RANGE FILES (no DNS, no cost per request). Google, Bing, Apple and
 *      DuckDuckGo publish JSON lists; Meta documents its ranges as the routes
 *      of AS32934 in RADb (used for facebookexternalhit and WhatsApp
 *      previews). refresh() downloads them into storage/app/firewall/
 *      bot-ranges.php; a lookup is a binary search over a packed string, like
 *      HostingNetworks.
 *   2. FORWARD-CONFIRMED REVERSE DNS, where the operator documents a domain:
 *      the address's PTR must end in that domain AND the name must resolve
 *      back to the same address. Done AFTER THE RESPONSE IS SENT
 *      (app()->terminating(), which runs after fastcgi_finish_request()), at
 *      most DNS_PER_MINUTE lookups a minute, and cached per address for 24 h —
 *      so a flood of fake "Googlebots" can neither slow a page nor make the
 *      server hammer its resolver.
 *
 * Until a claim is checked it is "pending" and the visitor is treated as any
 * other visitor (counted, never refused for being a bot). Only a DNS answer
 * that says no makes it FAKE — never a range file that may be a day out of
 * date — and a resolver failure caches nothing, so an outage cannot brand
 * Googlebot a fake.
 *
 * A family with no published method (TikTok, Telegram, X, LinkedIn) cannot be
 * verified at all: its claim earns GET-only relief from country rules (a link
 * preview is one GET) and nothing else. It is never called fake.
 */
final class GoodBots
{
    /**
     * family => [label, UA alternation, reverse-DNS suffixes, range sources]
     *
     * Range sources are official URLs (checked 8 Oct 2026: Google moved its
     * files to /crawling/ipranges/ on 31 March 2026) or `whois:AS…`.
     */
    public const FAMILIES = [
        'google' => ['Google: Googlebot, AdsBot (Google Ads landing-page checks), Storebot, Inspection Tool',
            'Googlebot|AdsBot-Google|Mediapartners-Google|Google-InspectionTool|GoogleOther|Storebot-Google|APIs-Google|FeedFetcher-Google|Google-Read-Aloud|Google-Site-Verification|Google-Safety|Google-Extended',
            ['googlebot.com', 'google.com', 'googleusercontent.com'],
            ['https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
                'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
                'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json']],
        'bing' => ['Bing: Bingbot and Bing previews',
            'bingbot|BingPreview|msnbot|adidxbot|MicrosoftPreview',
            ['search.msn.com'],
            ['https://www.bing.com/toolbox/bingbot.json']],
        'apple' => ['Apple: Applebot (Siri, Spotlight)', 'Applebot', ['applebot.apple.com'],
            ['https://search.developer.apple.com/applebot.json']],
        'duckduckgo' => ['DuckDuckGo: DuckDuckBot', 'DuckDuckBot|DuckAssistBot', [],
            ['https://duckduckgo.com/duckduckbot.json']],
        'yandex' => ['Yandex: YandexBot', 'YandexBot|YandexImages|YandexMobileBot|YandexAccessibilityBot|YandexRenderResourcesBot',
            ['yandex.ru', 'yandex.net', 'yandex.com'], []],
        'meta' => ['Meta: Facebook & Instagram link previews and ad checks', 'facebookexternalhit|facebookcatalog|meta-externalagent|meta-externalfetcher|Facebot', [],
            ['whois:AS32934']],
        'whatsapp' => ['WhatsApp link previews', 'WhatsApp/', [], ['whois:AS32934']],
        'pinterest' => ['Pinterest: Pinterestbot', 'Pinterestbot|Pinterest/0\\.', ['pinterest.com', 'pinterestcrawler.com'], []],
        'tiktok' => ['TikTok link previews (cannot be verified: TikTok publishes no method)', 'TikTokSpider', [], []],
        'telegram' => ['Telegram link previews (cannot be verified)', 'TelegramBot', [], []],
        'x' => ['X (Twitter) link previews (cannot be verified)', 'Twitterbot', [], []],
        'linkedin' => ['LinkedIn link previews (cannot be verified)', 'LinkedInBot', [], []],
    ];

    /** Reverse-DNS checks a minute, shop-wide. */
    public const DNS_PER_MINUTE = 20;

    /** @var array<string, mixed>|null */
    private static ?array $ranges = null;

    private static ?string $rangesPath = null;

    /** @var (\Closure(string, int): (array|false))|null  tests stand in for the resolver */
    private static ?\Closure $resolver = null;

    /** @var array<string, true> addresses already queued for a DNS check in this process */
    private static array $queued = [];

    /**
     * One case-insensitive pattern with a named group per enabled family,
     * compiled once into the firewall file. '' when every family is off.
     *
     * @param  list<string>  $families
     */
    public static function regex(array $families): string
    {
        $parts = [];

        foreach ($families as $f) {
            if (isset(self::FAMILIES[$f])) {
                $parts[] = '(?<'.$f.'>'.self::FAMILIES[$f][1].')';
            }
        }

        return $parts === [] ? '' : '#'.implode('|', $parts).'#i';
    }

    /** The family whose group matched, from preg_match()'s $m. */
    public static function familyOf(array $m): ?string
    {
        foreach (self::FAMILIES as $f => $_) {
            if (isset($m[$f]) && $m[$f] !== '') {
                return $f;
            }
        }

        return null;
    }

    /**
     * Is this a real $family crawler? true / false (FAKE) / null (pending or
     * not verifiable).
     */
    public static function verify(string $family, string $bin, string $ip): ?bool
    {
        $def = self::FAMILIES[$family] ?? null;

        if ($def === null) {
            return null;
        }

        $data = self::ranges();
        $packed = $data['families'][$family] ?? null;

        if (is_array($packed) && PackedRanges::contains($packed[strlen($bin) === 4 ? 'v4' : 'v6'] ?? '', $bin)) {
            return true;
        }

        if ($def[2] === []) {
            return null;
        }

        $cached = FirewallStore::get('fw:g:'.bin2hex($bin));

        if ($cached === '1') {
            return true;
        }

        if ($cached === '0') {
            return false;
        }

        self::verifyLater($family, $bin, $ip);

        return null;
    }

    /** Queue the DNS check for after the response. */
    private static function verifyLater(string $family, string $bin, string $ip): void
    {
        $key = bin2hex($bin);

        if (isset(self::$queued[$key])) {
            return;
        }

        self::$queued[$key] = true;

        try {
            app()->terminating(static function () use ($family, $bin, $ip, $key): void {
                unset(self::$queued[$key]);

                if (FirewallStore::bump('fw:dnsq:'.intdiv(FirewallStore::now(), 60), 120) > self::DNS_PER_MINUTE) {
                    return;
                }

                $answer = self::dnsVerify($ip, self::FAMILIES[$family][2]);

                if ($answer === null) {
                    return; // resolver trouble: try again on a later visit
                }

                FirewallStore::put('fw:g:'.$key, $answer ? '1' : '0', $answer ? 86400 : 21600);

                if (! $answer) {
                    FirewallLog::hit('fake_bot', $bin, '', false);
                }
            });
        } catch (\Throwable) {
        }
    }

    /**
     * Forward-confirmed reverse DNS. true / false, or null when the resolver
     * could not answer (no cache entry is written for null).
     *
     * @param  list<string>  $suffixes
     */
    public static function dnsVerify(string $ip, array $suffixes): ?bool
    {
        $bin = IpRange::pack($ip);

        if ($bin === null) {
            return false;
        }

        $lookup = self::$resolver ?? static fn (string $name, int $type): array|false => @dns_get_record($name, $type);
        $ptr = $lookup(self::arpa($bin), DNS_PTR);

        if ($ptr === false) {
            return null;
        }

        $host = strtolower(rtrim((string) ($ptr[0]['target'] ?? ''), '.'));

        if ($host === '') {
            return false;
        }

        $ok = false;

        foreach ($suffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $ok = true;
                break;
            }
        }

        if (! $ok) {
            return false;
        }

        $v4 = strlen($bin) === 4;
        $records = $lookup($host, $v4 ? DNS_A : DNS_AAAA);

        if ($records === false) {
            return null;
        }

        foreach ($records as $r) {
            $addr = $v4 ? ($r['ip'] ?? null) : ($r['ipv6'] ?? null);

            if ($addr !== null && IpRange::pack((string) $addr) === $bin) {
                return true;
            }
        }

        return false;
    }

    public static function arpa(string $bin): string
    {
        if (strlen($bin) === 4) {
            return implode('.', array_reverse(array_map('ord', str_split($bin)))).'.in-addr.arpa';
        }

        return implode('.', array_reverse(str_split(bin2hex($bin)))).'.ip6.arpa';
    }

    /* ═══════════════════════════════════════════════ the range files ═══ */

    public static function rangesPath(): string
    {
        return self::$rangesPath ?? storage_path('app/firewall/bot-ranges'.(app()->runningUnitTests() ? '-test-'.getmypid() : '').'.php');
    }

    /** @return array{families: array<string, array{v4:string, v6:string}>, meta: array<string, mixed>} */
    public static function ranges(): array
    {
        if (self::$ranges !== null) {
            return self::$ranges;
        }

        $data = @include self::rangesPath();

        if (! is_array($data) || ! isset($data['families']) || ! is_array($data['families'])) {
            $data = ['families' => [], 'meta' => []];
        }

        // Stored as base64 so the file stays plain text; decoded once.
        foreach ($data['families'] as $f => $p) {
            $data['families'][$f] = [
                'v4' => (string) base64_decode((string) ($p['v4'] ?? ''), true),
                'v6' => (string) base64_decode((string) ($p['v6'] ?? ''), true),
            ];
        }

        $data['meta'] = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        return self::$ranges = $data;
    }

    public static function forget(): void
    {
        self::$ranges = null;
        self::$queued = [];
        self::$rangesPath = null;
        self::$resolver = null;
    }

    /** @param (\Closure(string, int): (array|false))|null $resolver */
    public static function useResolver(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Tests write their range file somewhere of their own. */
    public static function useRangesPath(?string $path): void
    {
        self::$ranges = null;
        self::$rangesPath = $path;
    }

    /**
     * Download every published list and write the range file.
     *
     * A family whose download fails, or comes back with fewer than half the
     * ranges it had, KEEPS its previous ranges: a truncated or hijacked answer
     * must not shrink "who is Google" to nothing. Every entry is parsed by
     * IpRange before it is kept.
     *
     * @param  (callable(string): ?string)|null  $fetch  for tests: URL => body
     * @return array<string, array{ok:bool, v4:int, v6:int, error:?string}>
     */
    public static function refresh(?callable $fetch = null): array
    {
        $fetch ??= static function (string $source): ?string {
            if (str_starts_with($source, 'whois:')) {
                return self::whoisRoutes(substr($source, 6));
            }

            $r = Http::timeout(30)->connectTimeout(10)->withHeaders(['Accept' => 'application/json'])->get($source);

            return $r->successful() ? $r->body() : null;
        };

        $old = self::ranges();
        $families = $old['families'];
        $report = [];
        $fetched = [];

        foreach (self::FAMILIES as $family => $def) {
            if ($def[3] === []) {
                continue;
            }

            $cidrs = [];
            $error = null;

            foreach ($def[3] as $source) {
                try {
                    $fetched[$source] ??= $fetch($source);
                    $body = $fetched[$source];
                } catch (\Throwable $e) {
                    $body = null;
                    $error = mb_substr($e->getMessage(), 0, 160);
                }

                if ($body === null || $body === '') {
                    $error ??= 'no answer from '.$source;
                    continue;
                }

                array_push($cidrs, ...self::cidrsIn($body));
            }

            $packed = PackedRanges::pack($cidrs);
            $before = isset($families[$family]) ? PackedRanges::count($families[$family]) : 0;
            $now = PackedRanges::count($packed);

            if ($now === 0 || ($before > 0 && $now < intdiv($before, 2)) || ($error !== null && $now < $before)) {
                $report[$family] = ['ok' => false, 'v4' => 0, 'v6' => 0, 'error' => $error ?? 'too few ranges ('.$now.' vs '.$before.'), kept the old list'];
                continue;
            }

            $families[$family] = $packed;
            $report[$family] = ['ok' => true, 'v4' => intdiv(strlen($packed['v4']), 8), 'v6' => intdiv(strlen($packed['v6']), 32), 'error' => $error];
        }

        $data = ['families' => $families, 'meta' => ['at' => now()->toIso8601String(), 'report' => $report]];
        self::write(['families' => array_map(static fn (array $p): array => ['v4' => base64_encode($p['v4']), 'v6' => base64_encode($p['v6'])], $families), 'meta' => $data['meta']]);
        self::$ranges = $data;

        return $report;
    }

    /** For the screen: per family, how many ranges and when. */
    public static function about(): array
    {
        $data = self::ranges();
        $out = [];

        foreach (self::FAMILIES as $family => $def) {
            $p = $data['families'][$family] ?? null;
            $out[$family] = [
                'label' => $def[0],
                'method' => $def[3] !== [] ? ($def[2] !== [] ? 'ranges+dns' : 'ranges') : ($def[2] !== [] ? 'dns' : 'none'),
                'ranges' => is_array($p) ? PackedRanges::count($p) : 0,
            ];
        }

        return ['families' => $out, 'at' => $data['meta']['at'] ?? null];
    }

    /**
     * Every IPv4/IPv6 prefix in a JSON body (Google's and Bing's
     * {"prefixes":[{"ipv4Prefix":…}]}, or any other shape) or in plain text.
     *
     * @return list<string>
     */
    public static function cidrsIn(string $body): array
    {
        $json = json_decode($body, true);
        $strings = [];

        if (is_array($json)) {
            array_walk_recursive($json, static function ($v) use (&$strings): void {
                if (is_string($v)) {
                    $strings[] = $v;
                }
            });
        } else {
            preg_match_all('#\b(?:route6?:\s*)?([0-9a-fA-F:.]+/\d{1,3})#', $body, $m);
            $strings = $m[1];
        }

        $out = [];

        foreach ($strings as $s) {
            $s = trim($s);

            if (str_contains($s, '/') && ($r = IpRange::parse($s)) !== null && $r['prefix'] >= ($r['family'] === 4 ? 8 : 16)) {
                $out[] = $r['cidr'];
            }
        }

        return $out;
    }

    /** Meta's documented method: the routes RADb lists for its AS. */
    private static function whoisRoutes(string $asn): ?string
    {
        if (preg_match('/^AS\d{1,10}$/', $asn) !== 1) {
            return null;
        }

        $fp = @fsockopen('whois.radb.net', 43, $errno, $err, 10);

        if ($fp === false) {
            return null;
        }

        stream_set_timeout($fp, 20);
        fwrite($fp, '-i origin '.$asn."\r\n");
        $out = '';

        while (! feof($fp) && strlen($out) < 4_000_000) {
            $chunk = fread($fp, 65536);

            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($fp)['timed_out'] ?? false) {
                    break;
                }
                continue;
            }

            $out .= $chunk;
        }

        fclose($fp);

        return $out === '' ? null : $out;
    }

    private static function write(array $data): void
    {
        $path = self::rangesPath();
        @mkdir(dirname($path), 0775, true);
        $tmp = $path.'.'.getmypid().'.tmp';

        if (@file_put_contents($tmp, '<?php return '.var_export($data, true).';'."\n", LOCK_EX) !== false && @rename($tmp, $path)) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }

            return;
        }

        @unlink($tmp);
        Log::warning('firewall: could not write the bot range file');
    }
}
