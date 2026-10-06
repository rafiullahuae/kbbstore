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
 * Lane BR3, the owner: "with logo circle or rectangle to choose from, and all
 * controls options like box spacings, positioning etc and to adjust the height
 * of overal header also, and font sizes etc. ... allow controls for desktop and
 * mobile both on the front-end." So the panel's padding, inset and gap, its
 * place on the banner, the phone capsule's place, the card below the banner,
 * and the name, description and logo sizes -- laptop and phone apart.
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

    /** Lane BR3: where the laptop panel sits on the banner, across and up/down. */
    public const PANEL_X = ['left', 'center', 'right'];

    public const PANEL_Y = ['middle', 'top', 'bottom'];

    /** Lane BR3: where the phone's capsule sits on the banner. */
    /** Lane BR4: Bottom centre first -- "centered align" -- so it prints no class. */
    public const PILL_AT = ['bottom-center', 'bottom-left', 'bottom-right', 'top-left', 'top-center', 'top-right'];

    /**
     * Lane BR4: the name's and the description's alignment. CENTRE FIRST, so
     * the owner's "centered align" is the stylesheet's own layout and prints
     * no class; Left and Right print `brw-ph--na-left` and the like.
     */
    public const ALIGNS = ['center', 'left', 'right'];

    /**
     * Lane BR4: "Show the brand logo", per device. OFF FIRST: off is the
     * owner's default and the stylesheet's own layout, so it prints no class,
     * and On prints `brw-ph--lg-on` / `brw-ph--lgm-on`. The shop's value is a
     * switch (SiteLayout `bool`); shop() says it in these words.
     */
    public const LOGO_SHOWS = ['off', 'on'];

    /** The choices whose shop setting is a switch: true is 'on', false 'off'. */
    public const SWITCHES = ['logo_show', 'logo_show_m'];

    /**
     * Lane BR4: about how many characters of the description fill one line of
     * the panel at its shipped type size and width -- laptop at 15px in a 60%
     * panel, phone at 14px in the card on a 360-390px screen. A description
     * longer than its lines' worth is cut there by CSS and gets "Read more";
     * a shorter one is printed whole with no button. COUNTED HERE, ON THE
     * SERVER, from the text: nothing on the page is measured. Scaled by the
     * type size and (laptop) the panel width actually chosen, so a bigger font
     * reaches the button sooner. An estimate errs the safe way: text that is
     * not cut never hides anything, so a slightly long two-line description
     * shows as three lines rather than losing its end with no way to open it.
     */
    public const LINE_CHARS = ['laptop' => 80, 'phone' => 44];

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
        // Lane BR3 -- laptop: the panel's box and type.
        'pad' => ['brand_panel_pad', 8, 60, '--brw-ph-pad', 'px'],
        'inset' => ['brand_panel_inset', 0, 120, '--brw-ph-in', 'px'],
        'gap' => ['brand_desc_gap', 0, 40, '--brw-ph-gap', 'px'],
        'name' => ['brand_name_fs', 18, 56, '--brw-ph-fn', 'px'],
        'desc' => ['brand_desc_fs', 12, 22, '--brw-ph-fd', 'px'],
        'logo_size' => ['brand_logo_size', 40, 120, '--brw-ph-lg', 'px'],
        // Lane BR3 -- phone: the capsule, the card below the banner, the type.
        'inset_m' => ['brand_pill_inset_m', 0, 40, '--brw-ph-im', 'px'],
        'gap_m' => ['brand_card_gap_m', 0, 40, '--brw-ph-gm', 'px'],
        'card_pad_m' => ['brand_card_pad_m', 6, 32, '--brw-ph-cp', 'px'],
        'name_m' => ['brand_name_fs_m', 14, 36, '--brw-ph-fnm', 'px'],
        'desc_m' => ['brand_desc_fs_m', 12, 20, '--brw-ph-fdm', 'px'],
        'logo_size_m' => ['brand_logo_size_m', 28, 80, '--brw-ph-lgm', 'px'],
        // Lane BR4: how many lines of the description show before "Read more".
        'lines' => ['brand_desc_lines', 1, 6, '--brw-ph-dl', ''],
        'lines_m' => ['brand_desc_lines_m', 1, 6, '--brw-ph-dlm', ''],
        // 6 Oct: the header's outer spacing (kbb-brand-header.css, "OUTER SPACING").
        'space_top' => ['brand_space_top', 0, 80, '--brw-ph-st', 'px'],
        'space_x' => ['brand_space_x', 0, 80, '--brw-ph-sx', 'px'],
        'space_top_m' => ['brand_space_top_m', 0, 60, '--brw-ph-stm', 'px'],
        'space_x_m' => ['brand_space_x_m', 0, 40, '--brw-ph-sxm', 'px'],
    ];

    /**
     * Lane BR3. The sizes the stylesheet already draws when its property is
     * absent -- each `var(--x, <this>)` fallback in kbb-brand-header.css, and
     * each the shop's shipped value (SiteLayout::SCHEMA). A size at this value
     * is NOT printed, so a brand nobody has touched keeps its markup byte for
     * byte, and the laptop's name and inset keep their narrow-screen clamp().
     * BrandPanelControlsTest pins the three places to one another.
     *
     * @var array<string, int>
     */
    public const QUIET = [
        'pad' => 26, 'inset' => 36, 'gap' => 12, 'name' => 34, 'desc' => 15, 'logo_size' => 72,
        'inset_m' => 12, 'gap_m' => 12, 'card_pad_m' => 14, 'name_m' => 22, 'desc_m' => 14, 'logo_size_m' => 52,
        'lines' => 2, 'lines_m' => 2,
        'space_top' => 22, 'space_x' => 22, 'space_top_m' => 22, 'space_x_m' => 22,
    ];

    /**
     * Lane BR3. Choices whose FIRST option is the stylesheet's own layout and
     * prints no class; any other prints `brw-ph--<prefix>-<option>`.
     *
     * @var array<string, string>
     */
    public const QUIET_CHOICES = ['panel_x' => 'px', 'panel_y' => 'py', 'pill_at' => 'at',
        // Lane BR4: the logo switches, and the alignments (centre first).
        'logo_show' => 'lg', 'logo_show_m' => 'lgm', 'name_align' => 'na', 'desc_align' => 'da', 'desc_align_m' => 'dam'];

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
        'panel_x' => ['brand_panel_x', self::PANEL_X],
        'panel_y' => ['brand_panel_y', self::PANEL_Y],
        'pill_at' => ['brand_pill_at', self::PILL_AT],
        'logo_show' => ['brand_logo_show', self::LOGO_SHOWS],
        'logo_show_m' => ['brand_logo_show_m', self::LOGO_SHOWS],
        'name_align' => ['brand_name_align', self::ALIGNS],
        'desc_align' => ['brand_desc_align', self::ALIGNS],
        'desc_align_m' => ['brand_desc_align_m', self::ALIGNS],
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

            if (in_array($key, self::SWITCHES, true)) {
                // A switch on Site layout; absent is its shipped Off.
                $out[$key] = $v === true || $v === 1 || $v === '1' ? 'on' : 'off';

                continue;
            }

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
     * ── LANE BR4: EVERY BRAND, BANNER OR NOT ────────────────────────────────
     *
     * $banner is PageBanner::forModel()'s answer for this brand. Until BR4 a
     * brand with the owner's own page banner never reached here at all
     * (BrandController::hero() kept it on the Compact row under the banner),
     * which is every brand he had given a banner -- the live Anua among them.
     * Now the Panel draws for it too, and the banner contributes its PICTURE:
     *
     *   picture      the page banner's picture when it has one, else the
     *                brand's header_image (the imported title-header picture),
     *                else the no-picture ground in the brand's own shades. The
     *                banner wins because it is the one the owner chose, and
     *                the one the pencil's "Banner picture" changes on such a
     *                brand (StorefrontAdminController::mode()), so an upload
     *                there is what the page shows. A brand has no phone-only
     *                picture (the banner has no such field; header_style's
     *                img_phone is a category's), so laptop and phone draw the
     *                same picture, the phone cropping it to cover.
     *   heading      not the banner's: the page's one <h1> is the brand name.
     *   description  the brand's own (TitleHeader::brandDescription); only when
     *                it has none, the banner's line under its heading, escaped
     *                -- printed once, here, and never by the banner as well.
     *
     * @param  array<string, mixed>  $layout  SiteLayout::all()
     * @param  array<string, mixed>|null  $banner  PageBanner::forModel()
     * @return array{image:?string, class:string, style:string, description:string, logo:bool, more:bool}
     */
    public static function forBrand(Brand $brand, array $layout, ?array $banner = null): array
    {
        $v = self::sanitize($brand->getAttribute('header_layout')) + self::shop($layout);
        $image = TitleHeader::safeImage($banner['image'] ?? null)
            ?? TitleHeader::safeImage($brand->getAttribute('header_image'));

        $description = TitleHeader::brandDescription($brand);

        if ($description === '' && is_string($banner['subheading'] ?? null) && trim($banner['subheading']) !== '') {
            $description = e(trim($banner['subheading']));
        }

        $class = 'brw-ph brw-ph--'.$v['panel'].' brw-ph--pill-'.$v['pill'].' brw-ph--logo-'.$v['logo']
            .' brw-ph--pos-'.$v['position'].($image === null ? ' brw-ph--noimg' : '');

        foreach (self::QUIET_CHOICES as $key => $prefix) {
            if ($v[$key] !== self::CHOICES[$key][1][0]) {
                $class .= ' brw-ph--'.$prefix.'-'.$v[$key];
            }
        }

        /*
         * Lane BR4: "Read more" per device, from the text's length against the
         * lines that device shows (see LINE_CHARS). `brw-ph--more-l` / `-p`
         * turn the cut and the button on for that device; neither, and the
         * description is printed whole with no button at all.
         */
        $more = ['l' => false, 'p' => false];

        if ($description !== '') {
            $length = mb_strlen(trim(RichText::toText($description)));
            $laptop = (int) floor(self::LINE_CHARS['laptop'] * self::QUIET['desc'] / max(1, (int) $v['desc']) * (int) $v['content'] / 60);
            $phone = (int) floor(self::LINE_CHARS['phone'] * self::QUIET['desc_m'] / max(1, (int) $v['desc_m']));
            $more = ['l' => $length > $laptop * (int) $v['lines'], 'p' => $length > $phone * (int) $v['lines_m']];
        }

        foreach ($more as $device => $on) {
            $class .= $on ? ' brw-ph--more-'.$device : '';
        }

        $style = [];

        foreach (self::RANGES as $key => [, , , $property, $unit]) {
            if ((self::QUIET[$key] ?? null) !== (int) $v[$key]) {
                $style[] = $property.':'.(int) $v[$key].$unit;
            }
        }

        $base = BrandLogo::ring($brand) ?? self::FALLBACK_COLOUR;
        $style[] = '--brw-ph-dk:'.self::mix($base, '#000000', 30);
        $style[] = '--brw-ph-lt:'.self::mix($base, '#ffffff', 10);

        return [
            'image' => $image,
            'class' => $class,
            'style' => implode(';', $style),
            'description' => $description,
            // No logo markup at all when it is off on both devices: no empty
            // circle, no gap. On one device only, the class hides it on the other.
            'logo' => $v['logo_show'] === 'on' || $v['logo_show_m'] === 'on',
            'more' => $more['l'] || $more['p'],
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
