<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;

/**
 * The brand page's PANEL header (Lane BR2): the banner as the background, and
 * on it a panel holding the logo, the brand name beside it and the description
 * under them. On a phone the logo and name sit in a pill on the banner and the
 * description moves below it, into a light card.
 *
 * The owner: "logo will be on th background image, beside logo, brand name,
 * and downside brand description. and put a nice background of the content to
 * not merge with the background, and content should should not full width,
 * almost 60% of the page width, and in mobile the description will come under
 * banner, not on the banner." Then: "in mobile logo and name with capsule type
 * or rectangle background. and content will come downside the header area."
 *
 * ── WHERE EACH VALUE COMES FROM ─────────────────────────────────────────────
 *
 * The brand's own choice (`brands.header_layout`, set in the "Edit brand
 * header" pop-up on the brand page), else the shop's (Appearance → Site layout
 * → Brand page). A blank or missing own value follows the shop.
 *
 * ── WHAT REACHES THE PAGE ───────────────────────────────────────────────────
 *
 * `class` is built only from option keys checked against the lists below, and
 * `style` only from integers clamped to RANGES under constant property names
 * plus colours that went through BrandLogo::clean(). Nothing typed is printed.
 */
final class BrandPanel
{
    public const LOGO_SHAPES = ['circle', 'rect'];

    public const PANELS = ['frost', 'brand'];

    public const PILLS = ['capsule', 'rect'];

    public const POSITIONS = ['left', 'center', 'right'];

    /**
     * own key => [shop setting, min, max, CSS property, unit]. The shop's
     * setting has the same bounds (SiteLayout::SCHEMA), so a value is held to
     * one range whichever end it came from.
     *
     * @var array<string, array{0:string, 1:int, 2:int, 3:string, 4:string}>
     */
    public const RANGES = [
        'width' => ['brand_header_w', 60, 100, '--brw-ph-w', '%'],
        'height' => ['brand_banner_h', 160, 460, '--brw-ph-h', 'px'],
        'height_m' => ['brand_banner_h_m', 100, 300, '--brw-ph-hm', 'px'],
        'content' => ['brand_content_w', 40, 85, '--brw-ph-cw', '%'],
    ];

    /**
     * own key => [shop setting, allowed values].
     *
     * @var array<string, array{0:string, 1:list<string>}>
     */
    public const CHOICES = [
        'logo' => ['brand_logo_shape', self::LOGO_SHAPES],
        'panel' => ['brand_panel_style', self::PANELS],
        'pill' => ['brand_pill', self::PILLS],
        'position' => ['brand_img_pos', self::POSITIONS],
    ];

    /** Used when a brand has no colour of its own: the shop pink. */
    private const FALLBACK_COLOUR = '#e0567b';

    private static ?bool $column = null;

    /**
     * Whether `brands.header_layout` exists yet. Asked by the WRITER only: a
     * package's files land before its migrations run. The storefront only
     * reads the attribute, which is null on a row without the column.
     */
    public static function columnReady(): bool
    {
        if (self::$column === null) {
            try {
                self::$column = \Illuminate\Support\Facades\Schema::hasColumn('brands', 'header_layout');
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$column;
    }

    public static function forgetColumn(): void
    {
        self::$column = null;
    }

    /**
     * A brand's own choices, cleaned: only known keys, a choice only when it is
     * one of its options, a size only as an integer inside its range. Anything
     * else is dropped, which means "follow the shop".
     *
     * @return array<string, string|int>
     */
    public static function sanitize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach (self::CHOICES as $key => [, $allowed]) {
            $v = $raw[$key] ?? null;

            if (is_string($v) && in_array($v, $allowed, true)) {
                $out[$key] = $v;
            }
        }

        foreach (self::RANGES as $key => [, $min, $max]) {
            $v = $raw[$key] ?? null;

            if (is_int($v) || (is_string($v) && preg_match('/^\d{1,4}$/', $v) === 1) || (is_float($v) && is_finite($v))) {
                $out[$key] = max($min, min($max, (int) $v));
            }
        }

        return $out;
    }

    /**
     * The shop's values, as the pop-up shows them under "Shop setting".
     *
     * @param  array<string, mixed>  $layout  SiteLayout::all()
     * @return array<string, string|int>
     */
    public static function shop(array $layout): array
    {
        $out = [];

        foreach (self::CHOICES as $key => [$setting, $allowed]) {
            $v = $layout[$setting] ?? null;
            $out[$key] = is_string($v) && in_array($v, $allowed, true) ? $v : $allowed[0];
        }

        foreach (self::RANGES as $key => [$setting, $min, $max]) {
            $out[$key] = max($min, min($max, (int) ($layout[$setting] ?? $min)));
        }

        return $out;
    }

    /**
     * The header for one brand, resolved for drawing.
     *
     * @param  array<string, mixed>  $layout  SiteLayout::all()
     * @return array{image:?string, class:string, style:string, description:string}
     */
    public static function forBrand(Brand $brand, array $layout): array
    {
        $v = self::sanitize($brand->getAttribute('header_layout')) + self::shop($layout);
        $image = TitleHeader::safeImage($brand->getAttribute('header_image'));

        $class = 'brw-ph brw-ph--'.$v['panel'].' brw-ph--pill-'.$v['pill'].' brw-ph--logo-'.$v['logo']
            .' brw-ph--pos-'.$v['position'].($image === null ? ' brw-ph--noimg' : '');

        $style = [];

        foreach (self::RANGES as $key => [, , , $property, $unit]) {
            $style[] = $property.':'.(int) $v[$key].$unit;
        }

        $base = BrandLogo::ring($brand) ?? self::FALLBACK_COLOUR;
        $style[] = '--brw-ph-dk:'.self::mix($base, '#000000', 30);
        $style[] = '--brw-ph-lt:'.self::mix($base, '#ffffff', 10);

        return [
            'image' => $image,
            'class' => $class,
            'style' => implode(';', $style),
            'description' => TitleHeader::brandDescription($brand),
        ];
    }

    /**
     * `$percent` of $hex over $with, as lower-case #rrggbb. Both arguments are
     * already #rrggbb (BrandLogo::clean() or a constant).
     */
    public static function mix(string $hex, string $with, int $percent): string
    {
        $p = max(0, min(100, $percent)) / 100;
        $out = '#';

        for ($i = 1; $i <= 5; $i += 2) {
            $a = hexdec(substr($hex, $i, 2));
            $b = hexdec(substr($with, $i, 2));
            $out .= sprintf('%02x', (int) round($a * $p + $b * (1 - $p)));
        }

        return $out;
    }
}
