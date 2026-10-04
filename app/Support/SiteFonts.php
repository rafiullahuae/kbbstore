<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SiteLayout;

/**
 * The shop's body and headings fonts — Appearance → Site layout → Fonts.
 *                                                                 (Lane FS)
 *
 * Both ship at Outfit, the shop's typeface today, and while they do this class
 * answers '' for the stylesheet and the layout's own Outfit preload for the
 * preload: a shop that applies the package gains not one byte.
 *
 * MOVED, it prints one <style id="kbb-site-fonts"> through
 * partials/shop-appearance-css (after every page sheet, which is what lets a
 * `:root` custom property win on source order) carrying:
 *
 *   · the @font-face of each chosen family — nothing for one nobody chose;
 *   · body: `--sans`, the variable every page sheet sets its body text from,
 *     plus the three product-card rules that name Outfit outright;
 *   · headings: h1–h3 at the weakest specificity there is, (0,0,1), so a
 *     heading a component styles on purpose (the review wall's Fraunces
 *     titles) keeps its face and every other heading takes the new one.
 *
 * On an Arabic page Cairo is kept BEHIND the chosen family, exactly as the
 * layout keeps it behind Outfit, so Arabic text still has its glyphs.
 *
 * ONE PRELOAD, FOR THE BODY FONT ONLY — the face the LCP text resolves to.
 * The heading face is discovered from the stylesheet on the same connection.
 */
final class SiteFonts
{
    /** @return array{body: string, heading: string} */
    public static function chosen(): array
    {
        $all = app(SiteLayout::class)->all();
        $body = $all['font_body'] ?? FontLibrary::DEFAULT;
        $heading = $all['font_heading'] ?? FontLibrary::DEFAULT;

        return [
            'body' => FontLibrary::exists($body) ? $body : FontLibrary::DEFAULT,
            'heading' => FontLibrary::exists($heading) ? $heading : FontLibrary::DEFAULT,
        ];
    }

    /**
     * The families this class prints faces for, so a homepage section that
     * picks the same one does not print it twice.
     *
     * @return list<string>
     */
    public static function families(): array
    {
        return array_values(array_unique(array_filter(self::chosen(), fn ($k) => $k !== FontLibrary::DEFAULT)));
    }

    /** What the layout preloads: Outfit's own tag while the body font is Outfit. */
    public static function preloadTags(): string
    {
        return FontLibrary::preloadTag(self::chosen()['body']);
    }

    public static function css(): string
    {
        ['body' => $body, 'heading' => $heading] = self::chosen();

        if ($body === FontLibrary::DEFAULT && $heading === FontLibrary::DEFAULT) {
            return '';
        }

        $arabic = Locale::current() !== Locale::DEFAULT;
        $out = '';

        foreach (self::families() as $key) {
            $out .= FontLibrary::faceCss($key, $arabic);
        }

        if ($body !== FontLibrary::DEFAULT) {
            $stack = FontLibrary::stack($body);
            $out .= ':root,.kbb-checkout{--sans:'.$stack.'}'
                .'.kbb-card .cn,.kbb-badge,.kbb-card-cart{font-family:var(--sans)}';

            if ($arabic) {
                $out .= 'html[lang="ar"],html[lang="ar"] .kbb-checkout{--sans:'.self::withCairo($stack).'}'
                    .'html[lang="ar"] body,html[lang="ar"] .kbb-card .cn,html[lang="ar"] .kbb-badge,html[lang="ar"] .kbb-card-cart{font-family:var(--sans)}';
            }
        }

        if ($heading !== FontLibrary::DEFAULT) {
            $stack = FontLibrary::stack($heading);
            $out .= 'h1,h2,h3{font-family:'.($arabic ? self::withCairo($stack) : $stack).'}';
        }

        return $out;
    }

    /** "'X',fallbacks" → "'X','Cairo',fallbacks". */
    private static function withCairo(string $stack): string
    {
        $comma = strpos($stack, ',');

        return substr($stack, 0, (int) $comma).",'Cairo'".substr($stack, (int) $comma);
    }
}
