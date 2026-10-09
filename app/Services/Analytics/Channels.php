<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Where a visit came from, as ONE constant table.                    (Lane AN)
 *
 * The owner: "from which source the order came, google organic, instagram,
 * tiktok, direct visit etc etc. and also track the campaign if paid."
 *
 * A visit is classified server-side from four things the beacon carries: the
 * referrer's domain, utm_source, utm_medium, and WHICH click id the address
 * had (gclid / fbclid / ttclid / msclkid -- the type only, never its value).
 *
 *   1. utm_medium says email           -> Email.
 *   2. The PLATFORM, first match wins: utm_source (SOURCES), then the
 *      referrer domain (HOSTS), then the click id (gclid = Google, ttclid =
 *      TikTok, msclkid = Bing, fbclid = Facebook unless the referrer said
 *      Instagram).
 *   3. PAID when utm_medium is a paid medium (PAID_MEDIUM) or the click id is
 *      an ads-only one (gclid, ttclid, msclkid). fbclid alone is NOT paid:
 *      Meta adds it to every outbound link, organic posts included.
 *   4. A platform with an Ads twin becomes it when paid. No platform: paid is
 *      Other Ads; any referrer or utm_source is Referral; nothing is Direct.
 *
 * Every row has its own test in SiteAnalyticsChannelsTest.
 */
final class Channels
{
    /** key => label. A key is at most 16 characters (orders.src_channel). */
    public const MAP = [
        'google' => 'Google Organic',
        'google_ads' => 'Google Ads',
        'instagram' => 'Instagram',
        'instagram_ads' => 'Instagram Ads',
        'facebook' => 'Facebook',
        'facebook_ads' => 'Facebook Ads',
        'tiktok' => 'TikTok',
        'tiktok_ads' => 'TikTok Ads',
        'snapchat' => 'Snapchat',
        'snapchat_ads' => 'Snapchat Ads',
        'whatsapp' => 'WhatsApp',
        'bing' => 'Bing',
        'bing_ads' => 'Bing Ads',
        'yahoo' => 'Yahoo',
        'duckduckgo' => 'DuckDuckGo',
        'email' => 'Email',
        'other_ads' => 'Other Ads',
        'referral' => 'Referral',
        'direct' => 'Direct',
    ];

    /** Platforms that have an "… Ads" twin. */
    public const HAS_ADS = ['google', 'instagram', 'facebook', 'tiktok', 'snapchat', 'bing'];

    /** utm_source (lowercased) => platform. */
    public const SOURCES = [
        'google' => 'google', 'google_ads' => 'google', 'googleads' => 'google', 'adwords' => 'google', 'gads' => 'google',
        'instagram' => 'instagram', 'ig' => 'instagram', 'insta' => 'instagram',
        'facebook' => 'facebook', 'fb' => 'facebook', 'meta' => 'facebook', 'facebook_ads' => 'facebook',
        'tiktok' => 'tiktok', 'tiktok_ads' => 'tiktok', 'tt' => 'tiktok',
        'snapchat' => 'snapchat', 'snap' => 'snapchat',
        'whatsapp' => 'whatsapp', 'wa' => 'whatsapp',
        'bing' => 'bing', 'microsoft' => 'bing',
        'yahoo' => 'yahoo',
        'duckduckgo' => 'duckduckgo', 'ddg' => 'duckduckgo',
    ];

    /** Referrer domain pattern => platform. Matched against the bare host. */
    public const HOSTS = [
        '/(^|\.)google\.[a-z]{2,3}(\.[a-z]{2})?$|googlequicksearchbox/' => 'google',
        '/(^|\.)instagram\.com$/' => 'instagram',
        '/(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com)$/' => 'facebook',
        '/(^|\.)tiktok\.com$/' => 'tiktok',
        '/(^|\.)snapchat\.com$/' => 'snapchat',
        '/(^|\.)(wa\.me|whatsapp\.com|whatsapp\.net)$|^l\.wl\.co$/' => 'whatsapp',
        '/(^|\.)bing\.com$/' => 'bing',
        '/(^|\.)yahoo\.[a-z.]{2,6}$/' => 'yahoo',
        '/(^|\.)duckduckgo\.com$/' => 'duckduckgo',
    ];

    /** utm_medium values that mean money was spent. */
    public const PAID_MEDIUM = '/^(cpc|ppc|cpm|cpv|paid|paid[_ -]?(social|search|media)?|ads?|display|sponsored|retargeting|remarketing)$/';

    /** Click-id type => [platform, always paid]. */
    public const CLICKS = [
        'g' => ['google', true],
        't' => ['tiktok', true],
        'm' => ['bing', true],
        'f' => ['facebook', false],
    ];

    /**
     * @param  string  $host    referrer domain, bare ('' none or internal)
     * @param  string  $source  utm_source, lowercased
     * @param  string  $medium  utm_medium, lowercased
     * @param  string  $click   '', or a CLICKS key
     */
    public static function classify(string $host, string $source, string $medium, string $click): string
    {
        if (preg_match('/^(e-?mail|newsletter)$/', $medium)) {
            return 'email';
        }

        $platform = self::SOURCES[$source] ?? null;

        if ($platform === null && $host !== '') {
            foreach (self::HOSTS as $re => $p) {
                if (preg_match($re, $host)) {
                    $platform = $p;
                    break;
                }
            }
        }

        if ($platform === null && isset(self::CLICKS[$click])) {
            $platform = self::CLICKS[$click][0];
        }

        $paid = ($medium !== '' && preg_match(self::PAID_MEDIUM, $medium) === 1)
            || (isset(self::CLICKS[$click]) && self::CLICKS[$click][1]);

        if ($platform !== null) {
            return $paid && in_array($platform, self::HAS_ADS, true) ? $platform.'_ads' : $platform;
        }

        if ($paid) {
            return 'other_ads';
        }

        return ($host !== '' || $source !== '') ? 'referral' : 'direct';
    }

    public static function label(?string $key): string
    {
        return $key === null || $key === '' ? 'Unknown' : (self::MAP[$key] ?? 'Unknown');
    }

    public static function valid(?string $key): bool
    {
        return is_string($key) && isset(self::MAP[$key]);
    }
}
