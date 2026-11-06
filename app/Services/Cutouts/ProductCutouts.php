<?php

declare(strict_types=1);

namespace App\Services\Cutouts;

use App\Support\Url;

/**
 * Transparent product cut-outs for the homepage occasion banner and the free
 * gifts strip (the owner, 6 November 2026: "super transparent", and later
 * "the trimming is not correctly, still white edges are there").
 *
 * The shop's product photographs are products on a white background. This
 * takes that background away, from the FULL-SIZE photograph, the same way the
 * approved previews were cut (docs/plans/gift-strip/tools/cutout2.py):
 *
 *   1. flood the white background in from the four edges (min channel >= thr),
 *      so white INSIDE the product (a label, a cap) is never touched;
 *   2. in a thin band along that edge, turn white into transparency in
 *      proportion to how white the pixel is (colour-to-alpha) -- skipped for a
 *      white product, whose own rim is near-white;
 *   3. take the white back out of the rim's colour (un-premultiply), so no
 *      pale halo shows on a dark or coloured background;
 *   4. pull the edge in by one pixel and soften it; drop stray specks; crop.
 *
 * WHEN. Only when a person asks (the admin's "Prepare cut-outs"), never on a
 * page view -- the rule ImageVariants sets for every generated image on this
 * host: no queue, no shell, a handful of PHP workers. One 1000x1000 photograph
 * is worked at most 900px tall, measured ~1-2 s in GD.
 *
 * WHERE. public_path('img-cache/cut/<height>/<the original's path>.webp') --
 * under img-cache, so it is gitignored and on BuildPackage::NEVER_SHIP, and a
 * package can never delete it. Two heights, 240 and 480.
 *
 * READING. for() answers from the disk (is_file + the image header), never
 * from a column that could disagree with it. No file, null: the caller shows
 * nothing rather than a white box.
 */
final class ProductCutouts
{
    public const DIR = 'img-cache/cut';

    public const HEIGHTS = [240, 480];

    private const WORK_MAX = 900;

    private const QUALITY = 86;

    private const SOURCES = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * The cut-out for a stored image, or null when none has been made.
     *
     * @return array{src: string, srcset: string, w: int, h: int}|null  w/h are the 480 copy's (or the largest present)
     */
    public static function for(string $image): ?array
    {
        $rel = self::relative($image);

        if ($rel === null) {
            return null;
        }

        $found = [];
        foreach (self::HEIGHTS as $h) {
            $file = public_path(self::DIR . '/' . $h . '/' . $rel . '.webp');
            if (is_file($file)) {
                $size = @getimagesize($file);
                if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
                    $found[$h] = [$size[0], $size[1]];
                }
            }
        }

        if ($found === []) {
            return null;
        }

        $base = Url::base();
        $enc = implode('/', array_map('rawurlencode', explode('/', $rel)));
        $url = static fn (int $h): string => $base . '/' . self::DIR . '/' . $h . '/' . $enc . '.webp';
        $parts = [];
        foreach ($found as $h => [$w]) {
            $parts[] = $url($h) . ' ' . $w . 'w';
        }
        $top = (int) array_key_last($found);

        return ['src' => $url($top), 'srcset' => implode(', ', $parts), 'w' => $found[$top][0], 'h' => $found[$top][1]];
    }

    /** Is there a cut-out for this image? Disk only. */
    public static function has(string $image): bool
    {
        return self::for($image) !== null;
    }

    /**
     * Make (or remake) the cut-out copies of one stored image.
     *
     * @param  bool  $whiteItem  a white product: skip step 2 so its rim is kept
     * @param  int  $threshold  how white the background must be (240..254)
     * @return array{ok: bool, reason?: string, w?: int, h?: int, ms?: int}
     */
    public static function make(string $image, bool $whiteItem = false, int $threshold = 246): array
    {
        $t0 = microtime(true);
        $rel = self::relative($image);

        if ($rel === null) {
            return ['ok' => false, 'reason' => 'not a photo on this site'];
        }

        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $src = public_path($rel);

        if (! in_array($ext, self::SOURCES, true) || ! is_file($src)) {
            return ['ok' => false, 'reason' => 'photo file not found'];
        }

        if (! function_exists('imagewebp')) {
            return ['ok' => false, 'reason' => 'this server cannot write WebP'];
        }

        $im = match ($ext) {
            'png' => @imagecreatefrompng($src),
            'webp' => @imagecreatefromwebp($src),
            default => @imagecreatefromjpeg($src),
        };

        if (! $im) {
            return ['ok' => false, 'reason' => 'photo could not be read'];
        }

        $cut = self::cut($im, $whiteItem, max(230, min(254, $threshold)));
        imagedestroy($im);

        if ($cut === null) {
            return ['ok' => false, 'reason' => 'no product found on a white background'];
        }

        $w = imagesx($cut);
        $h = imagesy($cut);
        foreach (self::HEIGHTS as $th) {
            $tw = max(1, (int) round($w * $th / $h));
            $out = imagecreatetruecolor($tw, $th);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopyresampled($out, $cut, 0, 0, 0, 0, $tw, $th, $w, $h);
            $file = public_path(self::DIR . '/' . $th . '/' . $rel . '.webp');
            if (! is_dir(dirname($file))) {
                @mkdir(dirname($file), 0755, true);
            }
            $ok = @imagewebp($out, $file, self::QUALITY);
            imagedestroy($out);
            if (! $ok) {
                imagedestroy($cut);

                return ['ok' => false, 'reason' => 'could not write the cut-out'];
            }
        }
        imagedestroy($cut);

        return ['ok' => true, 'w' => $w, 'h' => $h, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
    }

    /** Delete the cut-out copies of one image (a product dropped from a pool keeps them; this is for "remake"). */
    public static function forget(string $image): void
    {
        $rel = self::relative($image);
        if ($rel === null) {
            return;
        }
        foreach (self::HEIGHTS as $h) {
            @unlink(public_path(self::DIR . '/' . $h . '/' . $rel . '.webp'));
        }
    }

    /**
     * The image's path below the web root, or null. Root-relative paths only,
     * or an absolute URL on this site's own host; never "..", never the cache.
     */
    public static function relative(string $image): ?string
    {
        $image = trim($image);
        if ($image === '' || str_contains($image, "\0") || str_contains($image, '?') || str_contains($image, '#')) {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $image) === 1) {
            $p = parse_url($image);
            $own = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (! is_array($p) || ! isset($p['host'], $p['path']) || ! is_string($own) || strcasecmp($p['host'], $own) !== 0) {
                return null;
            }
            $image = (string) $p['path'];
        }

        if (! str_starts_with($image, '/')) {
            return null;
        }

        $base = Url::base();
        if ($base !== '' && str_starts_with($image, $base . '/')) {
            $image = substr($image, strlen($base));
        }

        $rel = rawurldecode(ltrim($image, '/'));
        if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, 'img-cache/')) {
            return null;
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return null;
            }
        }

        return $rel;
    }

    /**
     * The cut itself. Returns a new truecolor image with alpha, cropped, or
     * null when the photograph has no white background to take away.
     *
     * @param  \GdImage  $im
     */
    public static function cut($im, bool $whiteItem, int $thr)
    {
        // Working data is kept in BYTE STRINGS, one byte per pixel, not PHP
        // arrays: a 900x900 photo as eight int/float arrays measured 240 MB of
        // memory; as strings it is a few MB, which a shared host can afford.
        $W = imagesx($im);
        $H = imagesy($im);
        $s = min(1.0, self::WORK_MAX / max($W, $H));
        $w = max(1, (int) round($W * $s));
        $h = max(1, (int) round($H * $s));
        $wk = imagecreatetruecolor($w, $h);
        imagecopyresampled($wk, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        $n = $w * $h;

        $mn = str_repeat("\0", $n);
        for ($y = 0, $i = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++, $i++) {
                $c = imagecolorat($wk, $x, $y);
                $mn[$i] = chr(min(($c >> 16) & 255, ($c >> 8) & 255, $c & 255));
            }
        }

        // 1. flood the background in from the edges ("\1" = background)
        $bg = str_repeat("\0", $n);
        $stack = [];
        for ($x = 0; $x < $w; $x++) {
            $stack[] = $x;
            $stack[] = ($h - 1) * $w + $x;
        }
        for ($y = 0; $y < $h; $y++) {
            $stack[] = $y * $w;
            $stack[] = $y * $w + $w - 1;
        }
        $bgCount = 0;
        while ($stack !== []) {
            $i = array_pop($stack);
            if ($bg[$i] === "\1" || ord($mn[$i]) < $thr) {
                continue;
            }
            $bg[$i] = "\1";
            $bgCount++;
            $x = $i % $w;
            if ($x > 0) {
                $stack[] = $i - 1;
            }
            if ($x < $w - 1) {
                $stack[] = $i + 1;
            }
            if ($i >= $w) {
                $stack[] = $i - $w;
            }
            if ($i < $n - $w) {
                $stack[] = $i + $w;
            }
        }
        unset($stack);

        if ($bgCount < $n * 0.05 || $bgCount > $n * 0.995) {
            imagedestroy($wk);

            return null;
        }

        // the band: product pixels within 3 px of the background
        $near = self::grow($bg, $w, $n, 3);

        // 2. alpha 0..255: background 0; colour-to-alpha in the band
        $a = str_repeat("\xff", $n);
        for ($i = 0; $i < $n; $i++) {
            if ($bg[$i] === "\1") {
                $a[$i] = "\0";
            } elseif (! $whiteItem && $near[$i] === "\1") {
                $a[$i] = chr((int) min(255, max(0, round((255 - ord($mn[$i])) * 255 / 40))));
            }
        }
        unset($near, $bg, $mn);

        // 4. pull the edge in 1 px, then soften INWARD only (a background
        // pixel stays clear, or its white comes back as a halo)
        $e = $a;
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] === "\0") {
                continue;
            }
            $x = $i % $w;
            $e[$i] = chr(min(ord($a[$i]), $x > 0 ? ord($a[$i - 1]) : 0, $x < $w - 1 ? ord($a[$i + 1]) : 0, $i >= $w ? ord($a[$i - $w]) : 0, $i < $n - $w ? ord($a[$i + $w]) : 0));
        }
        $f = $e;
        for ($i = 0; $i < $n; $i++) {
            if ($e[$i] === "\0" || $e[$i] === "\xff" && ($i % $w) > 0 && ($i % $w) < $w - 1 && $i >= $w && $i < $n - $w
                && $e[$i - 1] === "\xff" && $e[$i + 1] === "\xff" && $e[$i - $w] === "\xff" && $e[$i + $w] === "\xff") {
                continue;
            }
            $x = $i % $w;
            $sum = ord($e[$i]) * 2 + ($x > 0 ? ord($e[$i - 1]) : 0) + ($x < $w - 1 ? ord($e[$i + 1]) : 0) + ($i >= $w ? ord($e[$i - $w]) : 0) + ($i < $n - $w ? ord($e[$i + $w]) : 0);
            $f[$i] = chr((int) round($sum / 6));
        }
        unset($a, $e);

        // stray specks: keep pieces (solid pixels) of at least 0.5% of the largest
        $lab = [];
        $sizes = [];
        $seen = str_repeat("\0", $n);
        for ($i0 = 0; $i0 < $n; $i0++) {
            if ($seen[$i0] === "\1" || ord($f[$i0]) <= 160) {
                continue;
            }
            $seen[$i0] = "\1";
            $q = [$i0];
            $members = [];
            while ($q !== []) {
                $i = array_pop($q);
                $members[] = $i;
                $x = $i % $w;
                foreach ([$x > 0 ? $i - 1 : -1, $x < $w - 1 ? $i + 1 : -1, $i >= $w ? $i - $w : -1, $i < $n - $w ? $i + $w : -1] as $j) {
                    if ($j >= 0 && $seen[$j] === "\0" && ord($f[$j]) > 160) {
                        $seen[$j] = "\1";
                        $q[] = $j;
                    }
                }
            }
            $sizes[] = count($members);
            $lab[] = $members;
        }
        unset($seen);
        if ($sizes === []) {
            imagedestroy($wk);

            return null;
        }
        $minKeep = max($sizes) * 0.005;
        $keep = str_repeat("\0", $n);
        foreach ($lab as $k => $members) {
            if ($sizes[$k] >= $minKeep) {
                foreach ($members as $i) {
                    $keep[$i] = "\1";
                }
            }
        }
        unset($lab);
        $keep = self::grow($keep, $w, $n, 2); // a soft rim 2 px wide survives beside a kept piece

        // crop to what is left
        $x0 = $w;
        $y0 = $h;
        $x1 = -1;
        $y1 = -1;
        for ($i = 0; $i < $n; $i++) {
            if ($keep[$i] === "\0" || ord($f[$i]) <= 25) {
                $f[$i] = "\0";

                continue;
            }
            $x = $i % $w;
            $y = intdiv($i, $w);
            $x0 = min($x0, $x);
            $x1 = max($x1, $x);
            $y0 = min($y0, $y);
            $y1 = max($y1, $y);
        }
        unset($keep);
        if ($x1 < $x0) {
            imagedestroy($wk);

            return null;
        }

        // 3. write it, taking the white back out of each rim pixel's colour
        $cw = $x1 - $x0 + 1;
        $ch = $y1 - $y0 + 1;
        $out = imagecreatetruecolor($cw, $ch);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $i = $y * $w + $x;
                $al = ord($f[$i]);
                $c = imagecolorat($wk, $x, $y);
                $r = ($c >> 16) & 255;
                $g = ($c >> 8) & 255;
                $b = $c & 255;
                if ($al > 5 && $al < 255) {
                    $k = $al / 255;
                    $r = (int) max(0, min(255, round(($r - (1 - $k) * 255) / $k)));
                    $g = (int) max(0, min(255, round(($g - (1 - $k) * 255) / $k)));
                    $b = (int) max(0, min(255, round(($b - (1 - $k) * 255) / $k)));
                }
                $ga = 127 - (int) round($al * 127 / 255);
                imagesetpixel($out, $x - $x0, $y - $y0, ($ga << 24) | ($r << 16) | ($g << 8) | $b);
            }
        }
        imagedestroy($wk);

        return $out;
    }

    /** Grow a "\1" mask by $steps pixels (4-neighbourhood). */
    private static function grow(string $m, int $w, int $n, int $steps): string
    {
        for ($k = 0; $k < $steps; $k++) {
            $next = $m;
            for ($i = 0; $i < $n; $i++) {
                if ($m[$i] === "\1") {
                    continue;
                }
                $x = $i % $w;
                if (($x > 0 && $m[$i - 1] === "\1") || ($x < $w - 1 && $m[$i + 1] === "\1") || ($i >= $w && $m[$i - $w] === "\1") || ($i < $n - $w && $m[$i + $w] === "\1")) {
                    $next[$i] = "\1";
                }
            }
            $m = $next;
        }

        return $m;
    }
}
