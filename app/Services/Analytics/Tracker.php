<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Security\CountryDb;
use App\Services\Security\GoodBots;
use App\Services\SettingsService;
use App\Support\InstantNav;
use App\Support\IpRange;
use App\Support\StoreTime;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One opened page, recorded.                                         (Lane AN)
 *
 * Called by the /api/viewed beacon (App\Http\Controllers\Store\
 * ViewedController) that every shop page sends once, after load, from the
 * bundle (resources/js/kbb/hit.js). WHAT IT COSTS: a settings read the
 * request memo already holds, two string hashes, a binary search in the
 * country file, and ONE INSERT into an_hits. No SELECT, no UPDATE.
 *
 * WHAT IT REFUSES, before anything is written: tracking switched off, a
 * prefetch (Sec-Purpose), a signed-in administrator (the `kbb_ah` hint cookie
 * the console sets at sign-in), an address on the owner's exclude list, and
 * anything whose user agent is a crawler (GoodBots' families plus the generic
 * patterns below) or empty.
 *
 * PRIVACY: no IP and no user agent is stored. The visitor is a 16-hex slice of
 * sha256(salt + IP + UA) where the salt is random, one per shop day, and its
 * file is deleted two days later -- after which nobody, the owner included,
 * can tie a hash back to an address. The session is the same visitor plus the
 * minute the browser says its 30-minute session started. No cookie is set.
 *
 * Every field is allowlisted and capped here; nothing from the request reaches
 * the table that is not one of these columns at one of these lengths.
 */
final class Tracker
{
    public const SETTING_ON = 'an_tracking';

    public const SETTING_EXCLUDE = 'an_exclude_ips';

    /** Crawlers and tools that run JavaScript or post by hand. */
    public const BOT_RE = '/bot|crawl|spider|slurp|headless|phantom|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|httpclient|scrapy|preview|scan|check|validator|feedfetcher|yandex|baidu|semrush|ahrefs|mj12|dotbot|petal/i';

    public const SALT_DIR = 'app/analytics';

    private static ?string $botRe = null;

    /** @var array<string, string> */
    private static array $salts = [];

    /** Record one hit for this beacon request. Never throws. */
    public static function record(Request $request, int $kind = 0): bool
    {
        try {
            $row = self::row($request, $kind);

            if ($row === null) {
                return false;
            }

            DB::table('an_hits')->insert($row);

            if ($kind === 0 && $row['e'] === 1) {
                Attribution::fromBeacon($request, $row);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The row this request would write, or null when it must not be counted.
     *
     * @return array<string, mixed>|null
     */
    public static function row(Request $request, int $kind = 0): ?array
    {
        $settings = app(SettingsService::class);

        if (! (bool) $settings->get(self::SETTING_ON, true)) {
            return null;
        }

        if (InstantNav::isSpeculative($request) || $request->cookies->has('kbb_ah')) {
            return null;
        }

        $ua = (string) $request->userAgent();

        if (self::isBot($ua)) {
            return null;
        }

        $ip = (string) $request->ip();

        if (self::excluded($ip, (string) $settings->get(self::SETTING_EXCLUDE, ''))) {
            return null;
        }

        $nowMin = intdiv(time(), 60);
        $v = self::visitor($ip, $ua);

        if ($kind === 1) {
            // An add to cart: no page fields, the visitor only.
            return ['m' => $nowMin, 'v' => $v, 's' => self::session($v, 0, $nowMin), 'k' => 1, 'e' => 0,
                'path' => '', 'title' => '', 'ref' => '', 'ch' => 'direct', 'src' => '', 'med' => '', 'cmp' => '',
                'dev' => self::device($ua), 'br' => self::browser($ua), 'os' => self::os($ua),
                'cc' => self::country($ip), 'lang' => ''];
        }

        $path = self::path((string) $request->input('p', ''));

        if ($path === null) {
            return null;
        }

        $host = self::host((string) $request->input('r', ''), (string) $request->getHost());
        $src = self::utm($request->input('us'), 60);
        $med = self::utm($request->input('um'), 40);
        $click = (string) $request->input('ck', '');
        $click = isset(Channels::CLICKS[$click]) ? $click : '';
        $entry = $request->input('n') === '1' ? 1 : 0;

        return [
            'm' => $nowMin,
            'v' => $v,
            's' => self::session($v, (int) $request->input('ss', 0), $nowMin),
            'k' => self::isCheckout($path) ? 2 : 0,
            'e' => $entry,
            'path' => $path,
            'title' => self::text($request->input('t'), 120),
            // Source fields describe how a session ARRIVED, so only the first
            // page of one carries them; a later page's referrer is the shop.
            'ref' => $entry ? $host : '',
            'ch' => $entry ? Channels::classify($host, $src, $med, $click) : 'direct',
            'src' => $entry ? $src : '',
            'med' => $entry ? $med : '',
            'cmp' => $entry ? self::utm($request->input('uc'), 100) : '',
            'dev' => self::device($ua),
            'br' => self::browser($ua),
            'os' => self::os($ua),
            'cc' => self::country($ip),
            'lang' => preg_match('/^(en|ar)$/', (string) $request->input('l', '')) ? (string) $request->input('l') : '',
        ];
    }

    public static function isBot(string $ua): bool
    {
        if ($ua === '' || strlen($ua) < 12) {
            return true;
        }

        self::$botRe ??= GoodBots::regex(array_keys(GoodBots::FAMILIES));

        return preg_match(self::BOT_RE, $ua) === 1 || (self::$botRe !== '' && preg_match(self::$botRe, $ua) === 1);
    }

    /** An exact address or a CIDR on the owner's list (comma, space or newline separated). */
    public static function excluded(string $ip, string $list): bool
    {
        if ($list === '' || $ip === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            if (str_contains($item, '/') ? IpRange::contains($item, $ip) : IpRange::normalise($item) === IpRange::normalise($ip)) {
                return true;
            }
        }

        return false;
    }

    public static function visitor(string $ip, string $ua): string
    {
        return substr(hash('sha256', self::salt().'|'.$ip.'|'.$ua), 0, 16);
    }

    /**
     * The visitor plus the minute the browser's session began. A start the
     * browser could not have had (missing, the future, over a day old) falls
     * back to one session per visitor per day rather than one per page.
     */
    public static function session(string $visitor, int $start, int $nowMin): string
    {
        $anchor = ($start > $nowMin - 1440 && $start <= $nowMin + 1) ? 'm'.$start : 'd'.StoreTime::now()->format('Ymd');

        return substr(hash('sha256', $visitor.'|'.$anchor), 0, 16);
    }

    /** Today's salt: random, one file per shop day, created once. */
    public static function salt(): string
    {
        $day = StoreTime::now()->format('Ymd');

        if (isset(self::$salts[$day])) {
            return self::$salts[$day];
        }

        $dir = storage_path(self::SALT_DIR);
        $file = $dir.'/salt-'.$day.'.key';
        $salt = @file_get_contents($file);

        if (! is_string($salt) || strlen($salt) !== 64) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            // 'x': only one process creates it; a loser reads the winner's.
            $h = @fopen($file, 'x');
            if ($h !== false) {
                fwrite($h, bin2hex(random_bytes(32)));
                fclose($h);
                @chmod($file, 0600);
            }
            $salt = @file_get_contents($file);
            if (! is_string($salt) || strlen($salt) !== 64) {
                // Unwritable storage: a per-process salt still hides the address.
                $salt = bin2hex(random_bytes(32));
            }
        }

        self::$salts = [$day => $salt];

        return $salt;
    }

    /** Delete salt files older than yesterday. Returns how many. */
    public static function forgetOldSalts(): int
    {
        $keep = [StoreTime::now()->format('Ymd'), StoreTime::now()->subDay()->format('Ymd')];
        $n = 0;

        foreach (glob(storage_path(self::SALT_DIR).'/salt-*.key') ?: [] as $f) {
            if (! in_array(substr(basename($f), 5, 8), $keep, true) && @unlink($f)) {
                $n++;
            }
        }

        return $n;
    }

    /** A same-site path, base path removed, query and fragment dropped, capped. */
    public static function path(string $p): ?string
    {
        if ($p === '' || $p[0] !== '/' || str_starts_with($p, '//')) {
            return null;
        }

        $p = (string) preg_replace('/[?#].*$/s', '', $p);
        $base = Url::base();

        if ($base !== '' && str_starts_with($p, $base)) {
            $p = substr($p, strlen($base)) ?: '/';
        }

        $p = (string) preg_replace('/[^\x21-\x7E]/', '', $p);

        return mb_substr($p, 0, 191);
    }

    /** The referrer's bare domain, or '' for none, garbage or this shop. */
    public static function host(string $referrer, string $self): string
    {
        if ($referrer === '' || strlen($referrer) > 2000) {
            return '';
        }

        // The bundle sends the bare domain (never the full referrer, whose
        // query belongs to another site); a full URL is accepted too.
        $host = strtolower(str_contains($referrer, '://') ? (string) parse_url($referrer, PHP_URL_HOST) : $referrer);
        $host = (string) preg_replace('/^www\./', '', $host);

        if ($host === '' || ! preg_match('/^[a-z0-9.-]{1,100}$/', $host)) {
            return '';
        }

        return $host === preg_replace('/^www\./', '', strtolower($self)) ? '' : $host;
    }

    public static function utm(mixed $v, int $max): string
    {
        if (! is_string($v)) {
            return '';
        }

        $v = mb_strtolower(trim($v));
        $v = (string) preg_replace('/[^\p{L}\p{N} ._\-+:\/|]/u', '', $v);

        return mb_substr($v, 0, $max);
    }

    public static function text(mixed $v, int $max): string
    {
        if (! is_string($v)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)), 0, $max);
    }

    public static function device(string $ua): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $ua)) {
            return 'tablet';
        }

        return preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua) ? 'mobile' : 'desktop';
    }

    public const BROWSERS = [
        '/Edg(e|A|iOS)?\//' => 'Edge',
        '/OPR\/|Opera/' => 'Opera',
        '/SamsungBrowser/' => 'Samsung',
        '/Instagram/' => 'Instagram',
        '/FBAN|FBAV|FB_IAB/' => 'Facebook',
        '/musical_ly|TikTok|Bytedance/i' => 'TikTok',
        '/Snapchat/' => 'Snapchat',
        '/CriOS|Chrome\//' => 'Chrome',
        '/FxiOS|Firefox\//' => 'Firefox',
        '/Safari\//' => 'Safari',
    ];

    public static function browser(string $ua): string
    {
        foreach (self::BROWSERS as $re => $name) {
            if (preg_match($re, $ua)) {
                return $name;
            }
        }

        return 'Other';
    }

    public static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            (bool) preg_match('/Android/', $ua) => 'Android',
            (bool) preg_match('/Windows/', $ua) => 'Windows',
            (bool) preg_match('/CrOS/', $ua) => 'ChromeOS',
            (bool) preg_match('/Mac OS X|Macintosh/', $ua) => 'macOS',
            (bool) preg_match('/Linux/', $ua) => 'Linux',
            default => 'Other',
        };
    }

    public static function country(string $ip): string
    {
        $bin = IpRange::pack($ip);

        return $bin === null ? '' : strtoupper(substr(CountryDb::lookup($bin), 0, 2));
    }

    public static function isCheckout(string $path): bool
    {
        return (bool) preg_match('#^(/ar)?/checkout/?$#', $path);
    }
}
