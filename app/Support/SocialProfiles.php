<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The shop's social profile addresses — the global `social_*` settings.
 *
 * ONE SET OF KEYS, TWO SCREENS (Lane QK2). Store → SEO & Meta → Settings →
 * Social profiles has always edited these; Appearance → Footer → Social
 * profiles now edits the SAME rows, because the owner looked for them where the
 * icons are drawn ("we don't have any social profiles anywhere"). There are no
 * `sitefooter_` copies: a copy would leave the Contact page, the emails and
 * the Organization schema reading the old address.
 *
 * Both screens save through PUT admin-api/settings, so there is one door, one
 * capability (`store.settings`) and one rule — check() below, which
 * AdminController::checkSetting() calls for the `profileurl` type.
 */
final class SocialProfiles
{
    /**
     * key => [label, placeholder, hint]. The SEO screen's six, in the footer's
     * order. The hint is for the two SiteFooter::socials() draws no icon for.
     */
    public const FIELDS = [
        'social_instagram' => ['Instagram', 'https://instagram.com/kbeautybliss', ''],
        'social_tiktok' => ['TikTok', 'https://tiktok.com/@kbeautybliss', ''],
        'social_facebook' => ['Facebook', 'https://facebook.com/kbeautybliss', ''],
        'social_youtube' => ['YouTube', 'https://youtube.com/@kbeautybliss', ''],
        'social_pinterest' => ['Pinterest', 'https://pinterest.com/kbeautybliss', 'Google only: the footer draws no Pinterest icon.'],
        'social_linkedin' => ['LinkedIn', 'https://linkedin.com/company/kbeautybliss', 'Google only: the footer draws no LinkedIn icon.'],
    ];

    /**
     * What the shop prints for a key whose row was never written — the
     * fallbacks SiteFooter::socials() passes to get(). The screen opens showing
     * these, not blanks, so a Save that nobody typed into cannot strip an
     * icon off the shop. SocialProfilesFooterScreenTest pins them to the footer.
     */
    public const SHOP_DEFAULTS = [
        'social_instagram' => 'https://www.instagram.com/kbeauty.bliss/',
        'social_tiktok' => 'https://www.tiktok.com/@kbeauty.bliss',
        'social_facebook' => 'https://www.facebook.com/kbeautyblissuae',
    ];

    public const MAX = 500;

    /**
     * The values the shop is using right now, from one read of the map.
     *
     * @return array<string, string>
     */
    public static function values(SettingsService $settings): array
    {
        $all = $settings->all();
        $out = [];

        foreach (self::FIELDS as $key => $_) {
            $out[$key] = array_key_exists($key, $all)
                ? (string) $all[$key]
                : (self::SHOP_DEFAULTS[$key] ?? '');
        }

        return $out;
    }

    /**
     * Null when $value may be stored, else the reason it may not.
     *
     * Empty is allowed (it hides that icon). Otherwise an http/https address,
     * read the way a browser reads it (SafeUrl::web), with no whitespace and
     * at most MAX characters. `javascript:`, `data:`, `mailto:` and a bare
     * "instagram.com/x" are all refused: every reader prints this as an href
     * or as schema.org `sameAs`, and none of those is a page about the shop.
     */
    public static function check(string $value, string $label): ?string
    {
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::MAX) {
            return "“{$label}” is too long — at most ".self::MAX.' characters.';
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/u', $value) === 1
            || SafeUrl::web($value) !== $value
            || preg_match('#^https?://[^/?\#]*\.[^/?\#]+#i', $value) !== 1) {
            return "“{$label}” must be a full web address starting https://, like https://instagram.com/yourshop.";
        }

        return null;
    }
}
