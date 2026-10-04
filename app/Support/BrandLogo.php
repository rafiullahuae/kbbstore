<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * A brand's logo, its circle and the ring round it in the brand's own colour.
 *                                                                   (Lane BH)
 *
 * The owner, 4 October: "provide facility to upload the brand logo, and it will
 * be auto circled with outer brand color border. the brand color the system can
 * fetch from the logo image itself."
 *
 * ── WHERE THE COLOUR COMES FROM ─────────────────────────────────────────────
 *
 * colourOf() reads the logo FILE, server side, with GD, whenever a logo is
 * saved (Catalog → Brands → Edit, and the quick edit on the brand page) and
 * once for every brand already in the shop (the migration that adds the
 * column). It is stored in `brands.logo_color`, so the storefront never
 * decodes an image: the brand page prints one validated hex.
 *
 * ── IT NEVER FETCHES A URL ──────────────────────────────────────────────────
 *
 * localFile() maps a logo address onto a file under THIS shop's public uploads
 * and answers null for anything else. An address on another host -- the old
 * WooCommerce domain, a CDN, anything an operator pastes -- is not fetched:
 * an admin form that makes the server request an arbitrary URL is a server-side
 * request forgery, and a ring colour is not worth one. Such a brand simply has
 * no extracted colour and its ring is the shop pink (or the owner's own
 * choice, `ring_color`).
 *
 * ── BOUNDED ──────────────────────────────────────────────────────────────────
 *
 * The file must be a real image GD can read, at most MAX_BYTES and MAX_PIXELS,
 * and it is resampled to SAMPLE px on its long side before a single pixel is
 * looked at -- at most 32 × 32 = 1,024 pixels of arithmetic per logo.
 */
final class BrandLogo
{
    /** The long side every logo is resampled to before it is read. */
    public const SAMPLE = 32;

    /** Uploads stop at 5 MB (MediaUploadController); a logo past 8 MB is not read. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** About 4000 × 3000. GD holds 4 bytes a pixel; this keeps a decode near 48 MB. */
    public const MAX_PIXELS = 12_000_000;

    /** The two web-root directories uploads live in. */
    public const UPLOAD_DIRS = ['uploads', 'wp-content/uploads'];

    /**
     * The CSS custom property the ring colour is printed under. A CONSTANT
     * name: only the value comes from data, and only after clean().
     */
    public const RING_PROPERTY = '--brw-ring';

    /** @var array<string, bool> */
    private static array $columns = [];

    /**
     * #rgb or #rrggbb, case-insensitive, as lower-case #rrggbb; null for
     * anything else -- blank, a name, `red;background:url(...)`.
     */
    public static function clean(mixed $hex): ?string
    {
        if (! is_string($hex)) {
            return null;
        }

        $hex = strtolower(trim($hex));

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $hex, $m) !== 1) {
            return null;
        }

        $digits = $m[1];

        if (strlen($digits) === 3) {
            $digits = $digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        }

        return '#'.$digits;
    }

    /**
     * The colour the ring is drawn in: the owner's own choice, else the one
     * taken from the logo, else null -- and null means the stylesheet's
     * fallback, the shop pink. Both reads go through clean(), so whatever is
     * in the column, only a #rrggbb ever reaches the page.
     */
    public static function ring(Brand $brand): ?string
    {
        return self::clean($brand->getAttribute('ring_color')) ?? self::clean($brand->getAttribute('logo_color'));
    }

    /** `style="--brw-ring:#rrggbb"`'s value, or null. */
    public static function ringStyle(?string $ring): ?string
    {
        $ring = self::clean($ring);

        return $ring === null ? null : self::RING_PROPERTY.':'.$ring;
    }

    /**
     * Whether the two colour columns exist yet.
     *
     * Asked by every WRITER, never by the storefront. A package's files land
     * before its migrations run, and a fresh install runs every older
     * migration -- some of which create brands -- before this one, so a write
     * of `logo_color` that did not ask first is an "unknown column" on exactly
     * the installs nobody is watching. The storefront only READS the
     * attribute, which is null on a row loaded before the column existed.
     */
    public static function columnsReady(): bool
    {
        $key = 'brands';

        if (! array_key_exists($key, self::$columns)) {
            try {
                // ONE schema read: a missing table lists no columns.
                self::$columns[$key] = Schema::hasColumns('brands', ['logo_color', 'ring_color']);
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$columns[$key];
    }

    /** For the migration and the tests: ask the schema again. */
    public static function forgetColumns(): void
    {
        self::$columns = [];
    }

    /**
     * Keep a logo address to something that is safe as an <img src>: http(s),
     * or a site-relative path with no "..". Blank is null. Anything else --
     * `data:` (an SVG document that can carry script), `javascript:` -- is
     * refused with the sentence the brand editor has always given.
     *
     * Moved here from Admin\BrandsApiController so the quick edit on the brand
     * page refuses exactly what Catalog → Brands refuses.
     */
    public static function safeUrl(mixed $logo, string $field = 'logo'): ?string
    {
        $logo = trim((string) $logo);

        if ($logo === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $logo) === 1) {
            return $logo;
        }

        if (str_starts_with($logo, '/') && ! str_starts_with($logo, '//') && ! str_contains($logo, '..')) {
            return $logo;
        }

        throw ValidationException::withMessages([
            $field => 'The logo must be an uploaded image or an http(s) URL.',
        ]);
    }

    /**
     * The colour of the logo at this address, or null. Never fetches.
     */
    public static function colourOf(?string $logo): ?string
    {
        $file = self::localFile((string) $logo);

        return $file === null ? null : self::colourOfFile($file);
    }

    /**
     * The file under this shop's public uploads that a logo address names, or
     * null when it names anything else.
     *
     *   /uploads/brands/x.png                      → public/uploads/brands/x.png
     *   /kbb-upgrade/uploads/brands/x.png          → the same, base path removed
     *   https://<this shop>/uploads/brands/x.png   → the same, host checked
     *   https://elsewhere.example/x.png            → null, and NOT fetched
     *   /uploads/../../.env, /etc/passwd, /index.php → null
     */
    public static function localFile(string $logo): ?string
    {
        $logo = trim($logo);

        if ($logo === '' || strlen($logo) > 2048 || str_contains($logo, "\0")) {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $logo) === 1) {
            $parts = parse_url($logo);

            if (! is_array($parts) || ! isset($parts['host'], $parts['path'])
                || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }

            if (! in_array(strtolower((string) $parts['host']), self::ownHosts(), true)) {
                return null;
            }

            $path = (string) $parts['path'];
        } elseif (str_starts_with($logo, '/')) {
            $path = (string) (parse_url($logo, PHP_URL_PATH) ?? '');
        } else {
            return null;
        }

        $base = '';

        try {
            $base = Url::base();
        } catch (\Throwable) {
        }

        foreach (array_unique(array_filter([$base, self::siteBasePath()])) as $prefix) {
            if (str_starts_with($path, $prefix.'/')) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        $rel = rawurldecode(ltrim($path, '/'));

        if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '\\')) {
            return null;
        }

        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $inside = false;

        foreach (self::UPLOAD_DIRS as $dir) {
            if (str_starts_with($rel, $dir.'/')) {
                $inside = $dir;
                break;
            }
        }

        if ($inside === false) {
            return null;
        }

        $root = realpath(public_path($inside));
        $file = realpath(public_path($rel));

        if ($root === false || $file === false || ! is_file($file)
            || ! str_starts_with($file, rtrim($root, '/').'/')) {
            return null;
        }

        return $file;
    }

    /**
     * The dominant saturated colour of an image file, or null.
     *
     * Resampled to SAMPLE px; a pixel more than half transparent, near white,
     * near black or grey is skipped; the rest vote, weighted by saturation, for
     * one of 24 hue slices (15° each). The winning slice and its two
     * neighbours -- so a red that straddles 0°/360°, or an orange that sits on
     * a slice boundary, is not split in two -- give the colour: the
     * saturation-weighted average of the pixels that voted for them. Fewer than
     * 3% of the visible pixels agreeing is no colour at all, so a black
     * wordmark with a speck of red in it answers null rather than red.
     */
    public static function colourOfFile(string $file): ?string
    {
        try {
            $size = @filesize($file);

            if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
                return null;
            }

            $info = @getimagesize($file);

            if (! is_array($info)) {
                return null;
            }

            [$w, $h, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];

            if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
                return null;
            }

            $src = self::open($file, $type);

            if ($src === null) {
                return null;
            }

            $scale = min(1.0, self::SAMPLE / max($w, $h));
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));

            $img = imagecreatetruecolor($tw, $th);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefilledrectangle($img, 0, 0, $tw - 1, $th - 1, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
            imagecopyresampled($img, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            unset($src);

            $votes = array_fill(0, 24, 0.0);
            $sums = array_fill(0, 24, [0.0, 0.0, 0.0]);
            $visible = 0;

            for ($y = 0; $y < $th; $y++) {
                for ($x = 0; $x < $tw; $x++) {
                    $c = imagecolorat($img, $x, $y);
                    $alpha = ($c >> 24) & 0x7F;

                    if ($alpha > 63) {
                        continue; // more than half transparent
                    }

                    $visible++;

                    $r = ($c >> 16) & 0xFF;
                    $g = ($c >> 8) & 0xFF;
                    $b = $c & 0xFF;

                    $max = max($r, $g, $b);
                    $min = min($r, $g, $b);
                    $delta = $max - $min;
                    $light = ($max + $min) / 510;

                    if ($light > 0.93 || $light < 0.10 || $delta < 40) {
                        continue; // near white, near black, or grey
                    }

                    $sat = $delta / (255 - abs($max + $min - 255));

                    if ($sat < 0.25) {
                        continue;
                    }

                    $hue = match ($max) {
                        $r => fmod((($g - $b) / $delta) + 6, 6),
                        $g => (($b - $r) / $delta) + 2,
                        default => (($r - $g) / $delta) + 4,
                    } * 60;

                    $slice = ((int) floor($hue / 15)) % 24;
                    $votes[$slice] += $sat;
                    $sums[$slice][0] += $r * $sat;
                    $sums[$slice][1] += $g * $sat;
                    $sums[$slice][2] += $b * $sat;
                }
            }

            unset($img);

            if ($visible === 0) {
                return null;
            }

            $best = -1;
            $bestWeight = 0.0;

            for ($i = 0; $i < 24; $i++) {
                $weight = $votes[($i + 23) % 24] + $votes[$i] + $votes[($i + 1) % 24];

                if ($weight > $bestWeight) {
                    $bestWeight = $weight;
                    $best = $i;
                }
            }

            // The weights are saturations (0..1), so this is "at least 3% of
            // the visible pixels, fully saturated, or more of them less so".
            if ($best < 0 || $bestWeight < max(2.0, 0.03 * $visible)) {
                return null;
            }

            $rgb = [0.0, 0.0, 0.0];

            foreach ([($best + 23) % 24, $best, ($best + 1) % 24] as $i) {
                for ($k = 0; $k < 3; $k++) {
                    $rgb[$k] += $sums[$i][$k];
                }
            }

            return sprintf('#%02x%02x%02x',
                (int) max(0, min(255, round($rgb[0] / $bestWeight))),
                (int) max(0, min(255, round($rgb[1] / $bestWeight))),
                (int) max(0, min(255, round($rgb[2] / $bestWeight))));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recompute one brand's `logo_color` from its logo and save it, never
     * throwing. The migration's backfill, one brand at a time.
     */
    public static function refresh(Brand $brand): ?string
    {
        if (! self::columnsReady()) {
            return null;
        }

        $colour = self::colourOf((string) $brand->getAttribute('logo'));

        try {
            Brand::query()->whereKey($brand->getKey())->update(['logo_color' => $colour]);
        } catch (\Throwable) {
            return null;
        }

        return $colour;
    }

    /** @return \GdImage|null */
    private static function open(string $file, int $type)
    {
        $image = match (true) {
            $type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg') => @imagecreatefromjpeg($file),
            $type === IMAGETYPE_PNG && function_exists('imagecreatefrompng') => @imagecreatefrompng($file),
            $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($file),
            $type === IMAGETYPE_GIF && function_exists('imagecreatefromgif') => @imagecreatefromgif($file),
            defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF && function_exists('imagecreatefromavif') => @imagecreatefromavif($file),
            default => false,
        };

        return $image === false ? null : $image;
    }

    /** The hosts that ARE this shop: the site URL, the app URL, this request. */
    private static function ownHosts(): array
    {
        $hosts = [];

        try {
            $hosts[] = parse_url((string) (Setting::map()['site_url'] ?? ''), PHP_URL_HOST);
        } catch (\Throwable) {
        }

        $hosts[] = parse_url((string) config('app.url'), PHP_URL_HOST);

        try {
            if (app()->bound('request')) {
                $hosts[] = request()->getHost();
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($h) => is_string($h) ? strtolower($h) : '',
            $hosts
        ))));
    }

    /** /kbb-upgrade when site_url carries one, which an uploaded logo's URL then does too. */
    private static function siteBasePath(): string
    {
        try {
            $path = (string) (parse_url((string) (Setting::map()['site_url'] ?? ''), PHP_URL_PATH) ?? '');
        } catch (\Throwable) {
            return '';
        }

        $path = rtrim($path, '/');

        return $path === '' ? '' : '/'.ltrim($path, '/');
    }
}
