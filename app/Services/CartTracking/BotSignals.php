<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Support\ShareImage;

/**
 * Is this cart a bot? A transparent, additive score.               (Lane CT)
 *
 * The owner, 4 October: "with additional column 'Bot' the ans will yes or No."
 * The answer has to be one he can check, so it is not a model and not a
 * service — it is a sum of named signals, each worth a fixed number of points,
 * and every cart stores WHICH signals fired (a bitmask) as well as the total.
 * The screen prints the reasons beside the Yes/No; the threshold is a setting.
 *
 * | bit  | signal       | pts | fires when                                       |
 * |------|--------------|-----|--------------------------------------------------|
 * |    1 | UA_EMPTY     |  60 | no User-Agent at all                             |
 * |    2 | UA_TOOL      |  70 | curl, wget, python-requests, Go, Java, PHP …     |
 * |    4 | UA_HEADLESS  |  60 | HeadlessChrome, Puppeteer, Playwright, Selenium  |
 * |    8 | UA_CRAWLER   |  80 | a crawler or scraper names itself (…bot, spider) |
 * |   16 | NO_JS        |  30 | the cart changed without the shop's own cart     |
 * |      |              |     | script — the request lacked its X-KBB-Hm header  |
 * |   32 | FAST         |  35 | added within `speed_ms` of the page opening      |
 * |   64 | HOSTING      |  25 | the address is a datacenter / VPN network        |
 * |  128 | BURST_IP     |  40 | ≥ `burst_ip` carts from this address in window   |
 * |  256 | BURST_NET    |  25 | ≥ `burst_net` carts from this /24 (/64) in window|
 * | 1024 | UA_OLD       |  20 | a browser years out of date (Chrome < 70 …)     |
 *
 * Bot = total ≥ `bot_threshold` (default 50). So one strong signal is enough
 * (a named crawler, curl, a headless browser, no user agent), and two weaker
 * ones together are (no script + a hosting network = 55). A real shopper on a
 * VPN scores 25 and is not called a bot; a real shopper who simply clicks fast
 * scores 35 and is not either.
 *
 * WHY A BITMASK AND A TOTAL, NOT JUST A TOTAL. The total is what the list sorts
 * and filters on with an index; the mask is what lets the hover say WHY, and
 * lets a signal be added later without re-scoring history.
 *
 * Everything here is local string work: no DNS, no HTTP, no database.
 */
final class BotSignals
{
    public const UA_EMPTY = 1;
    public const UA_TOOL = 2;
    public const UA_HEADLESS = 4;
    public const UA_CRAWLER = 8;
    public const NO_JS = 16;
    public const FAST = 32;
    public const HOSTING = 64;
    public const BURST_IP = 128;
    public const BURST_NET = 256;
    public const UA_OLD = 1024;

    public const WEIGHTS = [
        self::UA_EMPTY => 60,
        self::UA_TOOL => 70,
        self::UA_HEADLESS => 60,
        self::UA_CRAWLER => 80,
        self::NO_JS => 30,
        self::FAST => 35,
        self::HOSTING => 25,
        self::BURST_IP => 40,
        self::BURST_NET => 25,
        self::UA_OLD => 20,
    ];

    /** The header the shop's cart script sends: milliseconds since the page opened. */
    public const HEADER = 'X-KBB-Hm';

    /**
     * Search engines and link previews. NEVER refused by "Ask bots to leave",
     * on any URL — they do not add to cart, and refusing Googlebot a /cart/
     * fetch is how a shop teaches Google it is broken. Reverse-DNS
     * verification would make this airtight and costs a lookup per request, so
     * it is not done: a scraper that lies about being Googlebot still scores
     * as a crawler on its cart, and its address can be blocked by hand.
     */
    public const GOOD_CRAWLERS = '#Googlebot|Google-InspectionTool|GoogleOther|Storebot-Google|AdsBot-Google|Mediapartners-Google|APIs-Google|FeedFetcher-Google|bingbot|BingPreview|msnbot|adidxbot|DuckDuckBot|Applebot|YandexBot|YandexImages|Baiduspider|PetalBot|SeznamBot|Qwantify|Yahoo! Slurp#i';

    private const TOOLS = '#^(curl|wget|python|aiohttp|httpx|go-http-client|okhttp|java/|apache-httpclient|libwww-perl|lwp::|php/|guzzlehttp|axios|node-fetch|undici|node\.js|scrapy|postmanruntime|insomnia|httpie|ruby|faraday|dart/|reqwest|colly|winhttp|powershell|mechanize|http_request2|got \(|superagent|okhttp)#i';

    private const HEADLESS = '#HeadlessChrome|PhantomJS|Puppeteer|Playwright|Selenium|WebDriver|SlimerJS|Splash|Nightmare|jsdom|Chrome-Lighthouse|Lighthouse|PTST/|HeadlessShell#i';

    /**
     * A crawler that names itself. Checked after the good list.
     *
     * NOT a bare "bot" word: "Android 9; CUBOT X19" is a phone. A crawler names
     * itself with a version ("…Bot/7.0") or a contact URL ("+http://…"), and
     * that is what is matched, beside the names worth knowing by name.
     */
    private const CRAWLER = '#bot/|bot\.html|\+https?://|crawl|spider|scrape|slurp|archiver|fetcher|AhrefsBot|SemrushBot|MJ12bot|DotBot|BLEXBot|DataForSeoBot|serpstatbot|Bytespider|GPTBot|ClaudeBot|CCBot|anthropic-ai|PerplexityBot|Amazonbot|ImagesiftBot|MegaIndex|Barkrowler|Seekport|ZoominfoBot|Expanse|CensysInspect|zgrab|masscan|nmap|Nuclei|sqlmap|nikto#i';

    /**
     * Flags read from one User-Agent string, and the name to show for it.
     *
     * @return array{0:int, 1:?string}  [flags, label]
     */
    public static function agent(?string $ua): array
    {
        $ua = trim((string) $ua);

        if ($ua === '') {
            return [self::UA_EMPTY, null];
        }

        if (preg_match(self::GOOD_CRAWLERS, $ua, $m) || preg_match(ShareImage::PREVIEW_FETCHERS, $ua, $m)) {
            return [self::UA_CRAWLER, $m[0]];
        }

        if (preg_match(self::TOOLS, $ua, $m)) {
            return [self::UA_TOOL, self::name($ua)];
        }

        if (preg_match(self::HEADLESS, $ua, $m)) {
            return [self::UA_HEADLESS, $m[0]];
        }

        if (preg_match(self::CRAWLER, $ua, $m)) {
            return [self::UA_CRAWLER, self::name($ua)];
        }

        if (self::outdated($ua)) {
            return [self::UA_OLD, null];
        }

        return [0, null];
    }

    /** Is this one of the search engines / link previews that must never be refused? */
    public static function isGoodCrawler(?string $ua): bool
    {
        $ua = (string) $ua;

        return $ua !== '' && (preg_match(self::GOOD_CRAWLERS, $ua) === 1 || preg_match(ShareImage::PREVIEW_FETCHERS, $ua) === 1);
    }

    /**
     * Why "Ask bots to leave" refuses this request, or null to let it through.
     *
     * ONLY THE SIGNALS NO REAL BROWSER SENDS. An empty user agent, a scripting
     * library, a headless browser, a scraper that names itself. Not the speed,
     * the missing script header, the hosting network or the burst counts —
     * those describe a CART after the fact and each one has an innocent
     * reading on its own; refusing on them at the door would turn away real
     * customers on a VPN or a fast thumb.
     */
    public static function leaveReason(?string $ua): ?string
    {
        if (self::isGoodCrawler($ua)) {
            return null;
        }

        [$flags] = self::agent($ua);

        return match (true) {
            ($flags & self::UA_EMPTY) !== 0 => 'empty user agent',
            ($flags & self::UA_TOOL) !== 0 => 'scripted client',
            ($flags & self::UA_HEADLESS) !== 0 => 'headless browser',
            ($flags & self::UA_CRAWLER) !== 0 => 'crawler',
            default => null,
        };
    }

    /** Flags from the shop's own script header: none, too fast, or no script. */
    public static function script(?string $header, int $speedMs): array
    {
        if ($header === null || $header === '' || ! preg_match('/^\d{1,9}$/', $header)) {
            return [self::NO_JS, null];
        }

        $ms = (int) $header;

        return $ms < $speedMs ? [self::FAST, $ms] : [0, $ms];
    }

    /** The total for a set of flags, capped at 100. */
    public static function score(int $flags): int
    {
        $sum = 0;

        foreach (self::WEIGHTS as $bit => $points) {
            if (($flags & $bit) !== 0) {
                $sum += $points;
            }
        }

        return min(100, $sum);
    }

    /**
     * The reasons, in words, for the hover and the cart detail.
     *
     * @param  array<string, mixed>  $settings  CartTrackingSettings::all()
     * @return list<array{code:string, points:int, text:string}>
     */
    public static function reasons(int $flags, ?string $ua, ?int $speedMs, array $settings): array
    {
        [, $label] = self::agent($ua);
        $label = $label !== null ? mb_substr($label, 0, 40) : null;

        $text = [
            self::UA_EMPTY => 'No user agent — real browsers always send one',
            self::UA_TOOL => 'Scripted client'.($label ? ' ('.$label.')' : ''),
            self::UA_HEADLESS => 'Headless browser'.($label ? ' ('.$label.')' : ''),
            self::UA_CRAWLER => 'Crawler'.($label ? ': '.$label : ''),
            self::NO_JS => 'The shop\'s cart script never ran',
            self::FAST => 'Added '.($speedMs !== null ? number_format($speedMs / 1000, 1).' s' : 'moments').' after the page opened',
            self::HOSTING => 'Datacenter or VPN network',
            self::BURST_IP => (int) ($settings['burst_ip'] ?? 5).'+ carts from this address within '.(int) ($settings['burst_minutes'] ?? 60).' min',
            self::BURST_NET => (int) ($settings['burst_net'] ?? 15).'+ carts from this range within '.(int) ($settings['burst_minutes'] ?? 60).' min',
            self::UA_OLD => 'A browser version years out of date',
        ];

        $codes = [
            self::UA_EMPTY => 'ua_empty', self::UA_TOOL => 'ua_tool', self::UA_HEADLESS => 'ua_headless',
            self::UA_CRAWLER => 'ua_crawler', self::NO_JS => 'no_js', self::FAST => 'fast',
            self::HOSTING => 'hosting', self::BURST_IP => 'burst_ip', self::BURST_NET => 'burst_net',
            self::UA_OLD => 'ua_old',
        ];

        $out = [];

        foreach (self::WEIGHTS as $bit => $points) {
            if (($flags & $bit) !== 0) {
                $out[] = ['code' => $codes[$bit], 'points' => $points, 'text' => $text[$bit]];
            }
        }

        return $out;
    }

    /** The product token a tool or crawler sends ("python-requests/2.31" → "python-requests"). */
    private static function name(string $ua): string
    {
        if (preg_match('#([A-Za-z][\w.\-]*(?:bot|spider|crawler|Bot|Spider|Crawler)[\w.\-]*)#', $ua, $m)) {
            return $m[1];
        }

        return (string) preg_replace('#[/ ].*$#', '', $ua);
    }

    /** A mainstream browser so old that no customer in 2026 runs it. */
    private static function outdated(string $ua): bool
    {
        if (str_contains($ua, 'MSIE ') || str_contains($ua, 'Trident/')) {
            return true;
        }

        if (preg_match('#(?:Chrome|CriOS)/(\d+)\.#', $ua, $m) && ! str_contains($ua, 'Edge/')) {
            return (int) $m[1] < 70;
        }

        if (preg_match('#Firefox/(\d+)\.#', $ua, $m)) {
            return (int) $m[1] < 60;
        }

        return false;
    }
}
