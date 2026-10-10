<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\SettingsService;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The open pixel of a marketing email, and what its loads are honestly worth.
 *                                                                  (Lane ER)
 *
 * The owner, 10 October: "for marketing emails i need the full report like how
 * many opened, how many clicked and came to the website". Lane MK had no pixel
 * on purpose (D10): Apple Mail Privacy Protection loads every picture of every
 * message on Apple's servers whether or not anybody reads it, so a raw open
 * rate is inflated by however many recipients use Apple Mail. Opens are back,
 * as an ESTIMATE, with the machine loads counted apart instead of hidden:
 *
 *   human    a mail program's own fetch                       counted
 *   proxy    Gmail's (or Yahoo's) image proxy: it fetches when counted (it IS
 *            the person opens the message, then CACHES — so a      a real open)
 *            second open is usually not seen
 *   apple    Apple's MPP prefetch: the bare "Mozilla/5.0" user  counted, and
 *            agent, an Apple address (17.0.0.0/8, 2620:149::/32,  shown apart as
 *            2a01:b740::/32), or an Apple Mail fetch within       "Apple Mail
 *            FAST_SECONDS of delivery                             auto-opens"
 *   scanner  a security scanner or link checker (bot user agent) NOT counted
 *
 * A send keeps the BEST class it has shown (human > proxy > apple > scanner),
 * and a click always counts as an open — a click is proof the message was read.
 *
 * THE TOKEN: base-36 send id, a dash, and 32 hex of HMAC-SHA256 over the id
 * and the send's own random token, keyed with APP_KEY (UnsubscribeToken's
 * shape, its own PURPOSE). No address, no campaign name, nothing personal is
 * in the URL. A forged, tampered or unknown token gets the same GIF and writes
 * nothing; the lookup is by primary key and does the same work either way.
 *
 * ONE WRITE PER LOAD: an UPDATE by primary key. The address itself is never
 * stored, only its class.
 */
final class OpenPixel
{
    public const PURPOSE = 'mkt-open';

    public const SETTING = 'mkt_open_tracking';

    /** Within this many seconds of delivery an Apple Mail fetch is the MPP prefetch. */
    public const FAST_SECONDS = 10;

    /** Rank of each class: a send keeps the highest it has shown. */
    public const RANK = ['scanner' => 1, 'apple' => 2, 'proxy' => 3, 'human' => 4];

    /** Opens that count: everything but a scanner. */
    public const COUNTED = ['human', 'proxy', 'apple'];

    /** The smallest transparent GIF there is: 42 bytes, a constant. */
    public const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public const APPLE_RANGES = ['17.0.0.0/8', '2620:149::/32', '2a01:b740::/32'];

    public const GOOGLE_RANGES = [
        '66.249.64.0/19', '66.102.0.0/20', '64.233.160.0/19', '72.14.192.0/18', '74.125.0.0/16',
        '209.85.128.0/17', '2001:4860::/32', '2404:6800::/32', '2607:f8b0::/32', '2800:3f0::/32', '2a00:1450::/32', '2c0f:fb50::/32',
    ];

    /** Scanners, link checkers and HTTP libraries — never a person reading. */
    public const BOT_RE = '/bot\b|crawl|spider|scan|curl|wget|python|java\/|go-http|okhttp|headless|phantom|libwww|httpclient|axios|node-fetch|barracuda|mimecast|proofpoint|safelinks|symantec|forcepoint|trendmicro|sophos/i';

    public static function enabled(): bool
    {
        return (string) app(SettingsService::class)->get(self::SETTING, '1') !== '0';
    }

    public static function token(int $sendId, string $sendToken): string
    {
        return base_convert((string) $sendId, 10, 36) . '-' . self::signature($sendId, $sendToken);
    }

    public static function url(int $sendId, string $sendToken): string
    {
        return Url::external('/email/o/' . self::token($sendId, $sendToken) . '.gif');
    }

    /**
     * The one hook in a sent message: the pixel, just before </body>. Nothing
     * when open tracking is off.
     */
    public static function inject(string $html, int $sendId, string $sendToken): string
    {
        if (! self::enabled()) {
            return $html;
        }

        $img = '<img src="' . e(self::url($sendId, $sendToken)) . '" width="1" height="1" alt="" '
            . 'style="display:block;width:1px;height:1px;border:0;margin:0;padding:0;overflow:hidden">';
        $at = strripos($html, '</body>');

        return $at === false ? $html . $img : substr($html, 0, $at) . $img . substr($html, $at);
    }

    /**
     * The send row for a token, or null. The same work for a forged token, a
     * well-formed one for a row that does not exist, and a valid one.
     */
    public static function find(string $token): ?object
    {
        [$rawId, $sig] = array_pad(explode('-', $token, 2), 2, '');
        $id = preg_match('/^[0-9a-z]{1,13}$/', $rawId) === 1 ? (int) base_convert($rawId, 36, 10) : 0;

        $row = $id > 0
            ? DB::table('mkt_sends')->where('id', $id)
                ->first(['id', 'token', 'status', 'sent_at', 'first_open_at', 'open_count', 'open_class'])
            : null;

        $expected = self::signature($id, $row !== null ? (string) $row->token : str_repeat('0', 40));
        $valid = preg_match('/^[0-9a-f]{32}$/', $sig) === 1 && hash_equals($expected, $sig);

        return $valid && $row !== null ? $row : null;
    }

    /** Record one load of the pixel. Never throws: the GIF goes back either way. */
    public static function record(string $token, string $ua, string $ip): bool
    {
        try {
            if (! self::enabled()) {
                return false;
            }

            $row = self::find($token);

            if ($row === null || $row->status !== 'sent') {
                return false;
            }

            $now = now();
            $since = $row->sent_at !== null ? $now->getTimestamp() - strtotime((string) $row->sent_at) : null;
            $uaClass = self::uaClass($ua);
            $ipClass = self::ipClass($ip);
            $class = self::classify($ua, $uaClass, $ipClass, $since);
            $keep = (string) ($row->open_class ?? '');
            $best = (self::RANK[$class] ?? 0) > (self::RANK[$keep] ?? 0) ? $class : $keep;

            $set = ['open_count' => DB::raw('open_count + 1')];

            if ($row->first_open_at === null) {
                $set['first_open_at'] = $now;
            }

            if ($best !== $keep) {
                $set += ['open_class' => $best, 'open_ua' => $uaClass, 'open_ip' => $ipClass];
            }

            return DB::table('mkt_sends')->where('id', $row->id)->update($set) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /** mail | proxy | apple | bot | none — what the user agent looks like. */
    public static function uaClass(string $ua): string
    {
        $ua = trim($ua);

        return match (true) {
            $ua === '' => 'none',
            preg_match('/GoogleImageProxy|ggpht\.com|YahooMailProxy/i', $ua) === 1 => 'proxy',
            $ua === 'Mozilla/5.0' => 'apple',
            preg_match(self::BOT_RE, $ua) === 1 => 'bot',
            default => 'mail',
        };
    }

    /** apple | google | other — the class of the address, never the address. */
    public static function ipClass(string $ip): string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'other';
        }

        return match (true) {
            IpUtils::checkIp($ip, self::APPLE_RANGES) => 'apple',
            IpUtils::checkIp($ip, self::GOOGLE_RANGES) => 'google',
            default => 'other',
        };
    }

    /**
     * human | proxy | apple | scanner, from the evidence of one load.
     *
     * @param  int|null  $since  seconds since the message was handed over
     */
    public static function classify(string $ua, string $uaClass, string $ipClass, ?int $since): string
    {
        if ($uaClass === 'bot' || $uaClass === 'none') {
            return $ipClass === 'apple' ? 'apple' : 'scanner';
        }

        if ($uaClass === 'proxy' || $ipClass === 'google') {
            return 'proxy';
        }

        if ($uaClass === 'apple' || $ipClass === 'apple') {
            return 'apple';
        }

        // Apple Mail's own user agent (AppleWebKit, no Safari/Chrome) inside the
        // first seconds after delivery is the MPP prefetch, not a reader.
        $appleMail = preg_match('/AppleWebKit/i', $ua) === 1 && preg_match('/Safari|Chrome|Firefox|Edg/i', $ua) !== 1;

        if ($appleMail && $since !== null && $since >= 0 && $since <= self::FAST_SECONDS) {
            return 'apple';
        }

        return 'human';
    }

    private static function signature(int $sendId, string $sendToken): string
    {
        return substr(hash_hmac('sha256', self::PURPOSE . '|' . $sendId . '|' . $sendToken, (string) config('app.key')), 0, 32);
    }
}
