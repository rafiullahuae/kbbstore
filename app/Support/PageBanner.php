<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The banner on a category archive and on a brand page — one place that knows
 * its shape, so the admin that writes it and the Blade that draws it cannot
 * drift apart.
 *
 * TWO ENTRY POINTS, AND THEY ARE DELIBERATELY NOT THE SAME FUNCTION.
 *
 *   sanitize()  — what the admin API stores. Trusts nothing, clamps
 *                 everything, and returns null for "store no banner". It runs
 *                 once, on write.
 *
 *   forModel()  — what a storefront view draws. Returns null when there is
 *                 nothing to draw, and otherwise a fully resolved array with
 *                 no missing keys, so the Blade is free of `?? ''`. It runs on
 *                 every request, so it does no work a write could have done.
 *
 * Splitting them is what makes the default safe. A row that has never been
 * touched has `banner` NULL, forModel() returns null on the first line, and
 * the page renders exactly what it rendered before this existed. The default
 * is OFF because nothing is stored — not because a flag says false.
 *
 * THE NO-IMAGE CASE. Two of the three styles are built around a photograph.
 * An operator who ticks "show the banner", picks Full-bleed and then does not
 * upload anything must not get a broken image icon across the top of the
 * category page, and must not get an empty 320px-tall box either. So the
 * resolved style falls back to `tint`, which needs no photograph at all, and
 * `fallback` is set so the markup can say why. The operator's *stored* choice
 * is untouched — upload an image later and the Full-bleed they picked comes
 * back on its own.
 */
final class PageBanner
{
    /**
     * The three styles, and the class suffix each one draws with.
     *
     * `full`  — image edge to edge under a gradient scrim.
     * `split` — image one side, text on a tinted panel the other.
     * `tint`  — a brand-coloured wash and large type. No photograph.
     */
    public const STYLES = ['full', 'split', 'tint'];

    public const STYLE_DEFAULT = 'full';

    /** The style that needs no photograph, and so the one anything degrades to. */
    public const STYLE_NO_IMAGE = 'tint';

    /** Which styles are built around a photograph. */
    private const NEEDS_IMAGE = ['full', 'split'];

    /** Light type on a dark treatment, or dark type on a light one. */
    public const TONES = ['light', 'dark'];

    public const TONE_DEFAULT = 'light';

    /** The storefront's own pink. Matches --pink in kbb-shop.css. */
    public const TINT_DEFAULT = '#e0567b';

    /**
     * Intrinsic size written onto the <img>.
     *
     * Not the size it renders at — the point is the ratio. width/height on the
     * element is what lets the browser reserve the box before the bytes
     * arrive, which together with aspect-ratio in the stylesheet is why the
     * heading does not jump down the page mid-load.
     */
    public const IMG_WIDTH = 1600;

    public const IMG_HEIGHT = 640;

    /**
     * Read the banner off a Category or a Brand, resolved for rendering.
     *
     * Returns null when there is nothing to draw, which is the common case and
     * the cheap one: a null column short-circuits before any work.
     *
     * $fallbackHeading is the page's own H1 text — the category or brand name.
     * An operator who enables a banner and types nothing gets the page's real
     * heading rather than a blank bar, because a banner with no words in it is
     * never what they meant.
     */
    public static function forModel(?Model $model, string $fallbackHeading = ''): ?array
    {
        if (! $model) {
            return null;
        }

        $raw = $model->getAttribute('banner');

        // The cast gives an array; a row written before the cast existed, or
        // by a raw insert in a test, can still be a JSON string.
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        return self::resolve(is_array($raw) ? $raw : null, $fallbackHeading);
    }

    /**
     * Turn a stored bag into something a view can draw, or null.
     *
     * Kept separate from forModel() so it can be exercised without a model,
     * and so a caller holding an already-decoded array does not have to fake
     * one.
     */
    public static function resolve(?array $raw, string $fallbackHeading = ''): ?array
    {
        if (! $raw || ! self::truthy($raw['enabled'] ?? false)) {
            return null;
        }

        $image = self::safeImage($raw['image'] ?? null);
        $stored = self::style($raw['style'] ?? null);

        // The whole point of the no-image case: degrade, do not break.
        $fallback = $image === null && in_array($stored, self::NEEDS_IMAGE, true);
        $style = $fallback ? self::STYLE_NO_IMAGE : $stored;

        $heading = self::text($raw['heading'] ?? null, 160);

        if ($heading === '') {
            $heading = self::text($fallbackHeading, 160);
        }

        return [
            'style' => $style,
            'stored_style' => $stored,
            'fallback' => $fallback,
            // `tint` never draws a photograph even when one is stored, so the
            // view is handed null rather than being trusted to ignore it.
            'image' => $style === self::STYLE_NO_IMAGE ? null : $image,
            'image_alt' => self::text($raw['image_alt'] ?? null, 200),
            'heading' => $heading,
            'subheading' => self::text($raw['subheading'] ?? null, 320),
            'tone' => self::tone($raw['tone'] ?? null),
            'overlay' => self::overlay($raw['overlay'] ?? null),
            'tint' => self::tint($raw['tint'] ?? null),
        ];
    }

    /**
     * What the admin API stores. Null means "clear the banner".
     *
     * Returns null for a bag that is absent, not an array, or has the switch
     * off — so turning a banner off does not leave a row carrying an image URL
     * and a heading that nothing renders. A stored `{"enabled":false}` and a
     * stored NULL have to mean the same thing, or the default-off guarantee
     * has two code paths instead of one.
     */
    public static function sanitize(mixed $raw): ?array
    {
        if (! is_array($raw) || ! self::truthy($raw['enabled'] ?? false)) {
            return null;
        }

        return [
            'enabled' => true,
            'style' => self::style($raw['style'] ?? null),
            'image' => self::safeImage($raw['image'] ?? null),
            'image_alt' => self::text($raw['image_alt'] ?? null, 200),
            'heading' => self::text($raw['heading'] ?? null, 160),
            'subheading' => self::text($raw['subheading'] ?? null, 320),
            'tone' => self::tone($raw['tone'] ?? null),
            'overlay' => self::overlay($raw['overlay'] ?? null),
            'tint' => self::tint($raw['tint'] ?? null),
        ];
    }

    /** JSON gives true/false, a form gives "1"/"0"/"on"/"". All of them mean the same thing. */
    private static function truthy(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
    }

    private static function style(mixed $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';

        return in_array($v, self::STYLES, true) ? $v : self::STYLE_DEFAULT;
    }

    private static function tone(mixed $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';

        return in_array($v, self::TONES, true) ? $v : self::TONE_DEFAULT;
    }

    /**
     * How dark the scrim over the photograph is, 0-90 per cent.
     *
     * Capped below 100 on purpose: a fully opaque scrim hides the image the
     * operator just uploaded, and every report of that would read as "the
     * banner is broken". The floor is 0 because a bright, high-key photograph
     * with dark type over it needs none.
     */
    private static function overlay(mixed $v): int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return 55;
        }

        return max(0, min(90, (int) round((float) $v)));
    }

    /**
     * A #rgb or #rrggbb colour, normalised to six digits and lower case.
     *
     * This value is interpolated into a `style` attribute as a custom
     * property, so it is the one field on this bag that could carry markup or
     * a second declaration out of the admin and into every visitor's page.
     * Matching the whole string against a hex pattern — rather than escaping
     * it on the way out — means there is no version of it that escaping could
     * get wrong.
     */
    private static function tint(mixed $v): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v) !== 1) {
            return self::TINT_DEFAULT;
        }

        if (strlen($v) === 4) {
            return '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
        }

        return $v;
    }

    /**
     * The same rule BrandsApiController applies to a logo, for the same
     * reason: this value lands in an <img src>. http(s) or a site-relative
     * path with no "..", and nothing else — in particular no `data:`, which
     * for image/svg+xml renders as a document and can carry script.
     *
     * Invalid is null rather than an exception. A banner image is not worth
     * failing a category save over, and a null image is a case this class
     * already has a defined answer for.
     */
    private static function safeImage(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        if ($v === '' || strlen($v) > 2048) {
            return null;
        }

        if (preg_match('#^https?://#i', $v) === 1) {
            return $v;
        }

        if (str_starts_with($v, '/') && ! str_contains($v, '..')) {
            return $v;
        }

        return null;
    }

    private static function text(mixed $v, int $max): string
    {
        if (! is_string($v)) {
            return '';
        }

        // Newlines collapse: these render as one heading line and one
        // paragraph, and a pasted multi-line block would otherwise decide the
        // banner's height for itself.
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');

        return mb_substr($v, 0, $max);
    }
}
