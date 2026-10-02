<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Import\Row;
use App\Services\SiteLayout;
use Illuminate\Database\Eloquent\Model;

/**
 * The category (and brand) TITLE HEADER: the title and description of a
 * category page, over the category's picture -- or, when it has none, in a
 * light box with a soft pattern of beauty-product icons. (Lanes PT and PY)
 *
 * THE OWNER, IN HIS WORDS. Lane PT: "We have a banner image on each category
 * on the old site. Need to bring that on the category pages as title
 * background, like on https://kbeautybliss.com/sunscreens/ -- and we also need
 * the same title, same description for each category if any have on our old
 * site."
 *
 * Lane PY, on PT's preview: "The title will be get from the category name
 * itself, if custom title or description not entered by me. also give optio
 * to center, left and for arabic ofcourse by default make the title name left
 * side as before, i just wanted the background image or light colored box
 * containing skincare makeup etc products icons. if no image. but i can be
 * able to update that background iamge, title font size etc and description
 * etc for each category. and will have control of overall section height
 * padding etc. and some type of shadow behind the name, so it will not merged
 * with the background. please give me multiple options to chooose from."
 *
 * This class is the ONE reader: the controllers ask forModel() and the Blade
 * component draws what it returns.
 *
 * WHAT DECIDES WHETHER IT DRAWS, in order:
 *
 *   1. The owner's own banner wins. Lane BW's `banner` is something he
 *      designed by hand; when it is on, this returns null and that banner
 *      carries the page's <h1> as before.
 *   2. Appearance -> Site layout -> Category header -> the main switch (ON),
 *      and for a brand "Brand pages too".
 *   3. A picture -- the category's header picture (imported, or chosen in
 *      Catalog -> Categories -> Edit), or with the fallback switch on its
 *      square category picture -- draws the PICTURE header.
 *   4. No picture: the LIGHT BOX, when "When a category has no picture, show a
 *      light box" is on (ON for categories, he asked) -- or, for a brand,
 *      "Light box on brand pages with no picture" (OFF: he did not ask about
 *      brands, so a brand with no picture stays exactly as 2.60.346 left it,
 *      on its own page and on /shop/?filter_brands=).
 *   5. Otherwise NULL, and the page renders the plain title it always did.
 *
 * WHICH WORDS. The title is the category's own custom header title when one
 * is set -- an imported old-shop title lands in the same field and is visible
 * and clearable in the category editor -- and otherwise the category NAME.
 * The description is the custom header description when set, otherwise the
 * category's own description. The custom title, subtitle and description are
 * English: an Arabic page keeps the category's translated name and
 * description rather than showing an English heading.
 *
 * HOW IT LOOKS is the shop's settings with the category's own overrides laid
 * over them (`header_style`, sanitised by sanitizeStyle() on the way in AND
 * on the way out): alignment, the text treatment, the box style, the title
 * size and the height. Anything the category leaves blank follows the shop.
 *
 * SECURE BY CONSTRUCTION. Every value here is either third-party data from an
 * export or typed in an admin box:
 *
 *   - the picture goes into an <img src>, so it is http(s) or a site-relative
 *     path with no `..`, or nothing -- PageBanner::safeImage()'s rule;
 *   - the title and subtitle are printed escaped;
 *   - the description is printed RAW, so it is RichText::forDisplay()'d here,
 *     on every render: the allowlist is the control, whatever wrote the row;
 *   - the `class` attribute is built only from words checked against the
 *     lists below; nothing typed is ever a class;
 *   - the `style` attribute carries custom properties whose NAMES are the
 *     constants in PX_VARS, each holding an integer clamped to its setting's
 *     own range, plus at most two colours that must match ^#[0-9A-F]{6}$ at
 *     the moment they are printed. Nothing from a setting is interpolated into
 *     CSS syntax.
 */
final class TitleHeader
{
    /** Plain-text length past which the description is clamped with "Read more". */
    public const LONG_DESCRIPTION = 180;

    /** Intrinsic size written on the <img>, for its ratio: the box is reserved before the bytes arrive. */
    public const IMG_WIDTH = 1600;

    public const IMG_HEIGHT = 500;

    /** Logical alignment: `start` is left in English and right in Arabic. */
    public const ALIGNS = ['start', 'center', 'end'];

    private const TONES = ['light', 'dark'];

    /** The box styles that carry the icon pattern; `plain` is the colour alone. */
    public const ICON_BOXES = ['blush', 'cream', 'mint', 'lilac', 'custom'];

    /** The box styles whose ground is the owner's own colour, written as --kbb-th-bg. */
    private const OWN_COLOUR_BOXES = ['plain', 'custom'];

    /**
     * Every pixel value on the header, as the CONSTANT custom property it is
     * written to and the setting it is read from. SiteLayoutDefaultsMatchCssTest
     * checks that every range on the Category header tabs is in here (or read
     * by name below), so a slider cannot save and move nothing.
     */
    public const PX_VARS = [
        '--kbb-th-h' => 'cat_header_h_phone',
        '--kbb-th-hd' => 'cat_header_h_desktop',
        '--kbb-th-ts' => 'cat_header_title_phone',
        '--kbb-th-tsd' => 'cat_header_title_desktop',
        '--kbb-th-ds' => 'cat_header_desc_phone',
        '--kbb-th-dsd' => 'cat_header_desc_desktop',
        '--kbb-th-py' => 'cat_header_pad_y_phone',
        '--kbb-th-pyd' => 'cat_header_pad_y_desktop',
        '--kbb-th-px' => 'cat_header_pad_x_phone',
        '--kbb-th-pxd' => 'cat_header_pad_x_desktop',
        '--kbb-th-r' => 'cat_header_radius',
        '--kbb-th-mt' => 'cat_header_mt_phone',
        '--kbb-th-mtd' => 'cat_header_mt_desktop',
        '--kbb-th-mb' => 'cat_header_mb_phone',
        '--kbb-th-mbd' => 'cat_header_mb_desktop',
        '--kbb-th-mw' => 'cat_header_maxw',
    ];

    /**
     * The per-category NUMBERS a category may override, and the shop setting
     * each one replaces -- whose range it is also clamped to.
     */
    public const STYLE_NUMBERS = [
        'title_phone' => 'cat_header_title_phone',
        'title_desktop' => 'cat_header_title_desktop',
        'h_phone' => 'cat_header_h_phone',
        'h_desktop' => 'cat_header_h_desktop',
    ];

    /** The per-category CHOICES, each checked against its own list. */
    public const STYLE_CHOICES = ['align', 'treatment', 'box'];

    /**
     * The header for a Category or a Brand, resolved for drawing, or null.
     *
     * @param  string  $title  the page's own heading -- the translated name
     * @param  array<string, mixed>|null  $banner  PageBanner::forModel()'s answer; when set, this returns null
     * @param  bool  $brand  a brand page, which has its own switches
     * @return array<string, mixed>|null
     */
    public static function forModel(?Model $model, string $title, ?array $banner = null, bool $brand = false): ?array
    {
        if ($model === null || $banner !== null) {
            return null;
        }

        $settings = self::settings();

        if (! $settings['cat_header'] || ($brand && ! $settings['cat_header_brands'])) {
            return null;
        }

        $image = self::safeImage($model->getAttribute('header_image'));

        if ($image === null && $settings['cat_header_fallback']) {
            $image = self::safeImage($model->getAttribute($brand ? 'logo' : 'image'));
        }

        if ($image === null && ! ($brand ? $settings['cat_header_box_brands'] : $settings['cat_header_box'])) {
            return null;
        }

        $kind = $image === null ? 'box' : 'img';

        // A brand has no per-brand style column; its page follows the shop.
        $own = $brand ? [] : self::sanitizeStyle($model->getAttribute('header_style'));

        $english = Locale::segment() === '';

        /*
         * "The title will be get from the category name itself, if custom
         * title ... not entered by me." The custom title -- which is also where
         * an imported old-shop title lands -- wins in English; the Arabic page
         * keeps the translated name, because the custom title has no Arabic.
         */
        $override = $english ? self::text($model->getAttribute('header_title'), 160) : '';
        $heading = $override !== '' ? $override : self::text($title, 160);

        $subtitle = $english ? self::text($model->getAttribute('header_subtitle'), 300) : '';

        /*
         * "... if custom title or description not entered by me." The custom
         * header description wins in English; otherwise the category's own,
         * translated. Through the same allowlist either way.
         */
        $custom = $english ? $model->getAttribute('header_description') : null;
        $raw = is_string($custom) && ! RichText::isBlank($custom)
            ? $custom
            : (method_exists($model, 't') ? $model->t('description') : $model->getAttribute('description'));
        $description = RichText::isBlank(is_string($raw) ? $raw : '') ? '' : RichText::forDisplay((string) $raw);

        $align = self::pick($own['align'] ?? null, self::ALIGNS)
            ?? self::pick($settings['cat_header_align'], self::ALIGNS)
            ?? 'start';

        $treatments = array_keys(SiteLayout::TREATMENTS);
        $treatment = self::pick($own['treatment'] ?? null, $treatments)
            ?? self::pick($settings[$kind === 'img' ? 'cat_header_treatment' : 'cat_header_box_treatment'], $treatments)
            ?? ($kind === 'img' ? 'shadow' : 'none');

        $boxes = array_keys(SiteLayout::BOX_STYLES);
        $box = $kind === 'box'
            ? (self::pick($own['box'] ?? null, $boxes) ?? self::pick($settings['cat_header_box_style'], $boxes) ?? 'blush')
            : null;

        $ground = self::hex($settings['cat_header_box_bg'], '#FFF4EE');
        $ink = self::hex($settings['cat_header_box_icon'], '#EFA889');

        $tone = self::tone($settings['cat_header_text'], $kind, $box, $ground);

        $class = 'kbb-th kbb-th--'.$kind.' kbb-th--'.$tone.' kbb-th--a-'.$align.' kbb-th--t-'.$treatment
            .($box !== null ? ' kbb-th--box-'.$box : '');

        return [
            'kind' => $kind,
            'image' => $image,
            'icons' => $box !== null && in_array($box, self::ICON_BOXES, true),
            'box' => $box,
            'heading' => $heading,
            'subtitle' => $subtitle,
            'description' => $description,
            'long' => $description !== '' && mb_strlen(RichText::toText($description)) > self::LONG_DESCRIPTION,
            'tone' => $tone,
            'align' => $align,
            'treatment' => $treatment,
            'class' => $class,
            'style' => self::style($settings, $own, $box, $ground, $ink),
        ];
    }

    /**
     * The `style` attribute: constant names, clamped integers, checked colours.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $own  sanitizeStyle()'s answer
     */
    private static function style(array $settings, array $own, ?string $box, string $ground, string $ink): string
    {
        $values = [];

        foreach (self::PX_VARS as $var => $key) {
            $values[$key] = (int) $settings[$key];
        }

        foreach (self::STYLE_NUMBERS as $mine => $key) {
            if (isset($own[$mine])) {
                $values[$key] = (int) $own[$mine];
            }
        }

        $out = [];

        foreach (self::PX_VARS as $var => $key) {
            $out[] = $var.':'.self::clampTo($key, $values[$key]).'px';
        }

        $out[] = '--kbb-th-ov:'.round(max(0, min(85, (int) $settings['cat_header_overlay'])) / 100, 2);
        $out[] = '--kbb-th-lines:'.max(1, min(10, (int) $settings['cat_header_lines']));
        $out[] = '--kbb-th-tw:'.(in_array((string) $settings['cat_header_weight'], ['500', '600', '700', '800'], true)
            ? (int) $settings['cat_header_weight'] : 700);

        if ($box !== null && in_array($box, self::OWN_COLOUR_BOXES, true)) {
            $out[] = '--kbb-th-bg:'.$ground;

            if ($box === 'custom') {
                $out[] = '--kbb-th-ic:'.$ink;
            }
        }

        return implode(';', $out);
    }

    /**
     * White or dark words. "Automatic" is white over a picture and dark on a
     * light box -- and white on a box whose OWN colour is dark, worked out
     * here from that colour's luminance so the owner's own navy box does not
     * get navy words.
     */
    private static function tone(mixed $setting, string $kind, ?string $box, string $ground): string
    {
        if (in_array($setting, self::TONES, true)) {
            return (string) $setting;
        }

        if ($kind === 'img') {
            return 'light';
        }

        if ($box !== null && in_array($box, self::OWN_COLOUR_BOXES, true)) {
            return self::luminance($ground) < 0.4 ? 'light' : 'dark';
        }

        return 'dark';
    }

    /** WCAG relative luminance of a #RRGGBB colour, 0 (black) to 1 (white). */
    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $c = [];

        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /**
     * A category's own `header_style`, reduced to what it may say.
     *
     * Run on the way IN (Admin\CategoriesApiController) and on the way OUT
     * (forModel()), so a row written by anything -- an import, a console, a
     * hand-edited database -- still cannot put a word that is not on a list
     * into a class, or a number outside its slider's range into a style.
     *
     *   align / treatment / box   one of their own lists, or absent
     *   title_* / h_*             a whole number, clamped to the matching
     *                             shop setting's range, or absent
     *
     * Absent means "follow the shop", which is what a blank box in the editor
     * sends. An empty answer is stored as NULL by the controller.
     *
     * @return array<string, int|string>
     */
    public static function sanitizeStyle(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $lists = [
            'align' => self::ALIGNS,
            'treatment' => array_keys(SiteLayout::TREATMENTS),
            'box' => array_keys(SiteLayout::BOX_STYLES),
        ];

        $out = [];

        foreach (self::STYLE_CHOICES as $key) {
            $picked = self::pick($raw[$key] ?? null, $lists[$key]);

            if ($picked !== null) {
                $out[$key] = $picked;
            }
        }

        foreach (self::STYLE_NUMBERS as $key => $setting) {
            $v = $raw[$key] ?? null;

            if (is_int($v) || (is_float($v) && is_finite($v))) {
                $out[$key] = self::clampTo($setting, (int) $v);
            } elseif (is_string($v) && preg_match('/^\s*(\d{1,5})\s*$/', $v, $m) === 1) {
                $out[$key] = self::clampTo($setting, (int) $m[1]);
            }
        }

        return $out;
    }

    /** An integer held to one Site layout slider's own min and max. */
    public static function clampTo(string $setting, int $value): int
    {
        $bounds = SiteLayout::SCHEMA[$setting][4] ?? [];

        return max((int) ($bounds['min'] ?? 0), min((int) ($bounds['max'] ?? 2000), $value));
    }

    /** $value when it is one of $allowed, else null. */
    private static function pick(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** A colour as #RRGGBB, checked again at the moment it is printed. */
    private static function hex(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : $fallback;
    }

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        $all = app(SiteLayout::class)->all();
        $out = [];

        foreach (SiteLayout::HEADER_KEYS as $key) {
            $out[$key] = $all[$key] ?? SiteLayout::SCHEMA[$key][2] ?? null;
        }

        return $out;
    }

    /* ─────────────────────────────────────────────────── the import side ── */

    /**
     * A category or brand description as the importer stores it.
     *
     * HTML is cleaned (RichText::clean) -- it is third-party markup. PLAIN TEXT
     * IS STORED AS IT CAME, deliberately: clean() serialises through
     * DOMDocument, which writes `&` as `&amp;`, and the plain category title
     * block (`<p class="psub">{{ $sub }}</p>`, unchanged by this lane) escapes
     * what it prints -- so cleaning "Masks & Peels" would put "Masks &amp;amp;
     * Peels" on every category page that has no header. Plain text has nothing
     * in it to clean, and everything that prints it raw goes through
     * RichText::forDisplay() on the way out regardless.
     */
    public static function importDescription(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return str_contains($raw, '<') ? RichText::clean($raw) : $raw;
    }

    /**
     * The `header_*` columns from a categories.csv / brands.csv row.
     *
     * ONLY THE COLUMNS THE FILE ACTUALLY HAS. An export from a plugin older than
     * 1.11.0 has none of them, and writing null for each would erase a header
     * the last 1.11.0 import brought in -- so an older file leaves them alone.
     *
     * @return array<string, string|null>
     */
    public static function importColumns(Row $row): array
    {
        $out = [];

        if ($row->has('banner_image')) {
            $out['header_image'] = self::safeImage($row->text('banner_image'));
        }

        if ($row->has('banner_source_key')) {
            $source = self::text($row->text('banner_source_key'), 255);
            $out['header_source'] = $source === '' ? null : $source;
        }

        if ($row->has('title_override')) {
            $title = self::text($row->text('title_override'), 300);
            $out['header_title'] = $title === '' ? null : $title;
        }

        if ($row->has('subtitle')) {
            $subtitle = self::text($row->text('subtitle'), 300);
            $out['header_subtitle'] = $subtitle === '' ? null : $subtitle;
        }

        return $out;
    }

    /**
     * http(s), or a site-relative path with no `..` -- or nothing. This value
     * lands in an <img src>; in particular no `data:` and no `javascript:`.
     */
    public static function safeImage(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        if ($v === '' || strlen($v) > 2048 || preg_match('/[\s"<>\\\\]/', $v) === 1) {
            return null;
        }

        if (preg_match('#^https?://[^/]#i', $v) === 1) {
            return $v;
        }

        if (str_starts_with($v, '/') && ! str_starts_with($v, '//') && ! str_contains($v, '..')) {
            return $v;
        }

        return null;
    }

    /** One line of plain text, tags removed, at most $max characters. */
    private static function text(mixed $v, int $max): string
    {
        if (! is_string($v)) {
            return '';
        }

        $v = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $v)), 0, $max);
    }
}
