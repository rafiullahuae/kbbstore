<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A browser's user-agent string as two short words a person reads: the
 * browser and the device. "Safari" / "iPhone", "Instagram" / "Android".
 * (Lane OR, for Store → Cart Tracking.)
 *
 * The owner, 10 October: "i need to get the user browser etc information under
 * the country, so i can be clear which browser users use more."
 *
 * WHY HERE AND NOT A LIBRARY. A dependency is ruled out (CLAUDE.md, "no new
 * dependency"), and the question asked is coarse: which browser, which kind of
 * device. Ten ordered patterns answer it; a full device database would answer
 * questions nobody asked and weigh more than the screen.
 *
 * ORDER IS THE WHOLE TRICK. Every in-app browser and every Chromium fork also
 * says "Chrome" and "Safari", so the specific names are tried first: the
 * Instagram, Facebook, TikTok and Snapchat in-app browsers, then Edge, Samsung
 * Internet and Opera, then Chrome (CriOS on an iPhone is still Chrome), then
 * Firefox (FxiOS likewise), and Safari last, only when nothing else claimed it.
 *
 * Pure: no I/O, no settings, the same string always gives the same pair. The
 * string itself is never printed anywhere new by this class.
 */
final class UserAgentLabel
{
    public const UNKNOWN = 'Unknown';

    public const BOT = 'Bot / script';

    /** [pattern, browser], first match wins. */
    private const BROWSERS = [
        ['/Instagram/i', 'Instagram'],
        ['/FBAN|FBAV|FB_IAB|FBIOS/', 'Facebook'],
        ['/musical_ly|BytedanceWebview|TikTok/i', 'TikTok'],
        ['/Snapchat/i', 'Snapchat'],
        ['/Edg(e|A|iOS)?\//', 'Edge'],
        ['/SamsungBrowser/', 'Samsung Internet'],
        ['/OPR\/|Opera/', 'Opera'],
        ['/CriOS|Chrome\//', 'Chrome'],
        ['/FxiOS|Firefox\//', 'Firefox'],
        ['/Safari\//', 'Safari'],
    ];

    /** Anything that is not a person's browser. */
    private const BOT_PATTERN = '/bot\b|bot\/|crawl|spider|slurp|headless|curl\/|wget|python|go-http|okhttp|java\/|axios|node-fetch|scrapy|httpclient|lighthouse|pagespeed/i';

    /** @return array{browser: string, device: string} */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);

        if ($ua === '') {
            return ['browser' => self::UNKNOWN, 'device' => self::UNKNOWN];
        }

        if (preg_match(self::BOT_PATTERN, $ua) === 1) {
            return ['browser' => self::BOT, 'device' => self::BOT];
        }

        $browser = self::UNKNOWN;

        foreach (self::BROWSERS as [$pattern, $name]) {
            if (preg_match($pattern, $ua) === 1) {
                $browser = $name;
                break;
            }
        }

        return ['browser' => $browser, 'device' => self::device($ua)];
    }

    private static function device(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPod') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => str_contains($ua, 'Mobile') ? 'Android phone' : 'Android tablet',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Windows') => 'Windows',
            // An iPad in desktop mode says Macintosh; a Mac is the honest answer
            // the string allows.
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => self::UNKNOWN,
        };
    }
}
