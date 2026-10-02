<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Import\Row;
use App\Services\SiteLayout;
use Illuminate\Database\Eloquent\Model;

/**
 * The category (and brand) TITLE HEADER the old shop had: the banner picture
 * behind the title, the title, and the description. (Lane PT)
 *
 * THE OWNER, IN HIS WORDS: "We have a banner image on each category on the old
 * site. Need to bring that on the category pages as title background, like on
 * https://kbeautybliss.com/sunscreens/ -- and we also need the same title, same
 * description for each category if any have on our old site."
 *
 * Exporter 1.11.0 carries the banner (`banner_image`), the key it came from and
 * any title / subtitle written for the header; CategoryImporter and
 * BrandImporter store them in `header_*` (see the migration for why not in
 * Lane BW's `banner` column). This class is the ONE reader: the controllers ask
 * forModel() and the Blade component draws what it returns.
 *
 * WHAT DECIDES WHETHER IT DRAWS, in order:
 *
 *   1. The owner's own banner wins. Lane BW's `banner` is something he
 *      designed by hand on Catalog -> Brands -> Banner; when it is on, this
 *      returns null and that banner carries the page's <h1> as before.
 *   2. Appearance -> Site layout -> Category header -> the main switch. ON as
 *      shipped, because he asked for it.
 *   3. A picture: the imported banner, or -- only when "When no banner was
 *      imported, use the category picture" is on -- the category's own image.
 *      No picture means NULL, and the page renders the plain title it always
 *      rendered, byte for byte (StorefrontEnglishUnchangedTest).
 *
 * SECURE BY CONSTRUCTION. Every value here is third-party data from an export:
 *
 *   - the picture goes into an <img src>, so it is http(s) or a site-relative
 *     path with no `..`, or nothing -- PageBanner::safeImage()'s rule;
 *   - the title and subtitle are printed escaped;
 *   - the description is printed RAW, so it is RichText::forDisplay()'d here,
 *     on every render: the allowlist is the control, whatever wrote the row;
 *   - the numbers that reach the `style` attribute are integers cast by
 *     SiteLayout (a range clamps), in custom properties whose names are
 *     constants below, and the two words (tone, alignment) are select values
 *     looked up against their own lists -- nothing from a setting is
 *     interpolated into CSS syntax.
 */
final class TitleHeader
{
    /** Plain-text length past which the description is clamped with "Read more". */
    public const LONG_DESCRIPTION = 180;

    /** Intrinsic size written on the <img>, for its ratio: the box is reserved before the bytes arrive. */
    public const IMG_WIDTH = 1600;

    public const IMG_HEIGHT = 500;

    private const TONES = ['light', 'dark'];

    private const ALIGNS = ['center', 'left'];

    /**
     * The header for a Category or a Brand, resolved for drawing, or null.
     *
     * @param  string  $title  the page's own heading -- the translated name
     * @param  array<string, mixed>|null  $banner  PageBanner::forModel()'s answer; when set, this returns null
     * @param  bool  $brand  a brand page, which has its own switch
     * @return array<string, mixed>|null
     */
    public static function forModel(?Model $model, string $title, ?array $banner = null, bool $brand = false): ?array
    {
        if ($model === null || $banner !== null) {
            return null;
        }

        $image = self::safeImage($model->getAttribute('header_image'));
        $settings = self::settings();

        if (! $settings['cat_header'] || ($brand && ! $settings['cat_header_brands'])) {
            return null;
        }

        if ($image === null && $settings['cat_header_fallback']) {
            $image = self::safeImage($model->getAttribute($brand ? 'logo' : 'image'));
        }

        if ($image === null) {
            return null;
        }

        /*
         * "The same title" -- the one written for the header on the old shop
         * when there was one. English only: that text has no Arabic, so an
         * Arabic page keeps the category's own translated name rather than
         * showing an English heading.
         */
        $override = self::text($model->getAttribute('header_title'), 160);
        $heading = $override !== '' && Locale::segment() === '' ? $override : self::text($title, 160);

        $subtitle = Locale::segment() === '' ? self::text($model->getAttribute('header_subtitle'), 300) : '';

        $raw = method_exists($model, 't') ? $model->t('description') : $model->getAttribute('description');
        $description = RichText::isBlank(is_string($raw) ? $raw : '') ? '' : RichText::forDisplay((string) $raw);

        $tone = in_array($settings['cat_header_text'], self::TONES, true) ? $settings['cat_header_text'] : 'light';
        $align = in_array($settings['cat_header_align'], self::ALIGNS, true) ? $settings['cat_header_align'] : 'center';

        return [
            'image' => $image,
            'heading' => $heading,
            'subtitle' => $subtitle,
            'description' => $description,
            'long' => $description !== '' && mb_strlen(RichText::toText($description)) > self::LONG_DESCRIPTION,
            'tone' => $tone,
            'align' => $align,
            'style' => '--kbb-th-h:'.(int) $settings['cat_header_h_phone'].'px'
                .';--kbb-th-hd:'.(int) $settings['cat_header_h_desktop'].'px'
                .';--kbb-th-ov:'.round(max(0, min(85, (int) $settings['cat_header_overlay'])) / 100, 2)
                .';--kbb-th-lines:'.max(1, min(10, (int) $settings['cat_header_lines'])),
        ];
    }

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        $all = app(SiteLayout::class)->all();
        $out = [];

        foreach (SiteLayout::HEADER_KEYS as $key) {
            $out[$key] = $all[$key] ?? null;
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
