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
 *
 * HAND-MADE CUT-OUTS WIN (the owner, 6 November 2026, on the automatic ones
 * in 2.60.649: "completely shit results"). A hand-made cut-out is installed at
 * the SAME served path as an automatic one, so the shop, the banner's stored
 * snapshot and every URL stay exactly as they are; what changes is that
 * make() -- "Prepare cut-outs", "Re-cut", "Remake" -- re-installs the hand-made
 * copy instead of cutting over it. Its master copy lives outside img-cache,
 * under a name derived from the photo's path (sha1), never the uploader's:
 *
 *   storage/app/cutouts/{240,480}/<sha1>.webp   the owner's upload (wins)
 *   resources/cutouts/{240,480}/<sha1>.webp     shipped by a package, listed
 *                                               in resources/cutouts/index.json
 *   storage/app/cutouts/auto/<sha1>             "Use automatic": the shipped
 *                                               one is set aside for this photo
 *
 * Only automatic() -- the admin's "Use automatic" -- cuts over a hand-made one.
 */
final class ProductCutouts
{
    public const DIR = 'img-cache/cut';

    /** An owner's upload: PNG or WebP with transparency, at most this many bytes. */
    public const UPLOAD_MAX_BYTES = 4 * 1024 * 1024;

    /** Refused before decoding: a small file can declare a huge canvas. */
    private const UPLOAD_MAX_PIXELS = 25_000_000;

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
        $v = 0;
        foreach (self::HEIGHTS as $h) {
            $file = public_path(self::DIR . '/' . $h . '/' . $rel . '.webp');
            if (is_file($file)) {
                $size = @getimagesize($file);
                if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
                    $found[$h] = [$size[0], $size[1]];
                    $v = max($v, (int) @filemtime($file));
                }
            }
        }

        if ($found === []) {
            return null;
        }

        $base = Url::base();
        $enc = implode('/', array_map('rawurlencode', explode('/', $rel)));
        // ?v= the file's time: the files are served for a year, and a remade or
        // hand-made cut-out keeps its path, so without it a visitor who saw the
        // automatic one keeps seeing it.
        $q = $v > 0 ? '?v=' . $v : '';
        $url = static fn (int $h): string => $base . '/' . self::DIR . '/' . $h . '/' . $enc . '.webp' . $q;
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
    public static function make(string $image, bool $whiteItem = false, int $threshold = 246, bool $automatic = false): array
    {
        $t0 = microtime(true);
        $rel = self::relative($image);

        if ($rel === null) {
            return ['ok' => false, 'reason' => 'not a photo on this site'];
        }

        // A hand-made cut-out is put back, never cut over. Only automatic()
        // passes $automatic, after the owner pressed "Use automatic".
        if (! $automatic && ($own = self::ownFiles($rel)) !== null) {
            if (! self::install($rel, $own)) {
                return ['ok' => false, 'reason' => 'could not write the cut-out'];
            }
            $size = @getimagesize($own[480]);

            return ['ok' => true, 'own' => true, 'w' => (int) ($size[0] ?? 0), 'h' => (int) ($size[1] ?? 0), 'ms' => (int) round((microtime(true) - $t0) * 1000)];
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

    /** Is the cut-out for this image a hand-made one (uploaded or shipped)? Disk only. */
    public static function isOwn(string $image): bool
    {
        $rel = self::relative($image);

        return $rel !== null && self::ownFiles($rel) !== null;
    }

    /**
     * The owner's own cut-out for one image: a PNG or WebP with transparency.
     * Validated from its bytes, decoded by GD (so nothing but pixels survives),
     * trimmed to what is not transparent, re-encoded as WebP at 480 and 240
     * tall under a name derived from the PHOTO's path, then installed where
     * the shop reads it.
     *
     * @return array{ok: bool, reason?: string, w?: int, h?: int}
     */
    /**
     * The owner's own cut-out, from a file in the shared Media Library (the
     * admin's ONE image upload path): $url is what kbbPickMedia handed back.
     * Only a file the library keeps -- under uploads/ (or the old wp-content/uploads/), never img-cache,
     * never "..", never another host -- and then the same checks as upload().
     */
    public static function fromMedia(string $image, string $url): array
    {
        $rel = self::relative($url);
        if ($rel === null || ! (str_starts_with($rel, 'uploads/') || str_starts_with($rel, 'wp-content/uploads/'))) {
            return ['ok' => false, 'reason' => 'choose a picture from the Media Library'];
        }

        return self::upload($image, public_path($rel));
    }

    public static function upload(string $image, string $file): array
    {
        $rel = self::relative($image);
        if ($rel === null) {
            return ['ok' => false, 'reason' => 'this product\'s photo is not on this site'];
        }
        if (! is_file($file) || ($bytes = (int) @filesize($file)) < 1) {
            return ['ok' => false, 'reason' => 'no file arrived'];
        }
        if ($bytes > self::UPLOAD_MAX_BYTES) {
            return ['ok' => false, 'reason' => 'the file is larger than 4 MB'];
        }
        $info = @getimagesize($file);
        $type = is_array($info) ? (int) $info[2] : 0;
        if (! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['ok' => false, 'reason' => 'the file is not a PNG or WebP image'];
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > self::UPLOAD_MAX_PIXELS) {
            return ['ok' => false, 'reason' => 'the image is too small or too large (at most 25 megapixels)'];
        }
        if (! function_exists('imagewebp')) {
            return ['ok' => false, 'reason' => 'this server cannot write WebP'];
        }
        $im = $type === IMAGETYPE_PNG ? @imagecreatefrompng($file) : @imagecreatefromwebp($file);
        if (! $im) {
            return ['ok' => false, 'reason' => 'the image could not be read'];
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);

        $box = self::opaqueBox($im);
        if ($box === null) {
            imagedestroy($im);

            return ['ok' => false, 'reason' => 'the image has no transparent background -- save the cut-out as a PNG or WebP with transparency'];
        }
        [$x0, $y0, $cw, $ch] = $box;

        $key = sha1($rel);
        $written = [];
        foreach (self::HEIGHTS as $th) {
            $tw = max(1, (int) round($cw * $th / $ch));
            $out = imagecreatetruecolor($tw, $th);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopyresampled($out, $im, 0, 0, $x0, $y0, $tw, $th, $cw, $ch);
            $dest = self::uploadedRoot() . '/' . $th . '/' . $key . '.webp';
            if (! is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0755, true);
            }
            $ok = @imagewebp($out, $dest, self::QUALITY);
            imagedestroy($out);
            if (! $ok) {
                imagedestroy($im);
                foreach ($written as $f) {
                    @unlink($f);
                }

                return ['ok' => false, 'reason' => 'could not write the cut-out'];
            }
            $written[$th] = $dest;
        }
        imagedestroy($im);

        @unlink(self::uploadedRoot() . '/auto/' . $key);
        if (! self::install($rel, $written)) {
            return ['ok' => false, 'reason' => 'could not write the cut-out'];
        }
        $size = @getimagesize($written[480]);

        return ['ok' => true, 'w' => (int) ($size[0] ?? 0), 'h' => (int) ($size[1] ?? 0)];
    }

    /**
     * "Use automatic": the owner's upload is deleted, a shipped hand-made one
     * is set aside for this photo, and the automatic cut-out is made.
     *
     * @return array{ok: bool, reason?: string, w?: int, h?: int, ms?: int}
     */
    public static function automatic(string $image, bool $whiteItem = false): array
    {
        $rel = self::relative($image);
        if ($rel === null) {
            return ['ok' => false, 'reason' => 'not a photo on this site'];
        }
        $key = sha1($rel);
        foreach (self::HEIGHTS as $h) {
            @unlink(self::uploadedRoot() . '/' . $h . '/' . $key . '.webp');
        }
        if (self::filesIn(self::shippedRoot(), $key) !== null) {
            $off = self::uploadedRoot() . '/auto/' . $key;
            if (! is_dir(dirname($off))) {
                @mkdir(dirname($off), 0755, true);
            }
            @file_put_contents($off, $rel);
        }
        self::forget($image);

        return self::make($image, $whiteItem, 246, true);
    }

    /**
     * Put every shipped hand-made cut-out where the shop reads it, over the
     * automatic one -- unless the owner uploaded his own or chose automatic
     * for that photo. Run by the migration that ships them.
     *
     * @param  list<string>|null  $rels  photo paths; null reads resources/cutouts/index.json
     * @return list<string>  the photo paths installed
     */
    public static function installShipped(?array $rels = null): array
    {
        if ($rels === null) {
            $index = @file_get_contents(self::shippedRoot() . '/index.json');
            $rels = is_string($index) ? json_decode($index, true) : null;
        }
        $done = [];
        foreach (is_array($rels) ? $rels : [] as $rel) {
            if (! is_string($rel) || self::relative('/' . $rel) !== $rel) {
                continue;
            }
            $own = self::ownFiles($rel);
            if ($own !== null && self::install($rel, $own)) {
                $done[] = $rel;
            }
        }

        return $done;
    }

    /**
     * The hand-made master copies for a photo path, upload first, or null.
     *
     * @return array<int,string>|null  height => file
     */
    private static function ownFiles(string $rel): ?array
    {
        $key = sha1($rel);
        $up = self::filesIn(self::uploadedRoot(), $key);
        if ($up !== null) {
            return $up;
        }
        if (is_file(self::uploadedRoot() . '/auto/' . $key)) {
            return null;
        }

        return self::filesIn(self::shippedRoot(), $key);
    }

    /** @return array<int,string>|null */
    private static function filesIn(string $root, string $key): ?array
    {
        $out = [];
        foreach (self::HEIGHTS as $h) {
            $f = $root . '/' . $h . '/' . $key . '.webp';
            if (! is_file($f)) {
                return null;
            }
            $out[$h] = $f;
        }

        return $out;
    }

    /** Copy master copies to the served path; false if any copy failed. */
    private static function install(string $rel, array $files): bool
    {
        foreach ($files as $h => $src) {
            $dest = public_path(self::DIR . '/' . $h . '/' . $rel . '.webp');
            if (! is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0755, true);
            }
            if (! @copy($src, $dest)) {
                return false;
            }
        }

        return true;
    }

    private static function uploadedRoot(): string
    {
        return storage_path('app/cutouts');
    }

    private static function shippedRoot(): string
    {
        return resource_path('cutouts');
    }

    /**
     * The box [x, y, w, h] of what is not fully transparent, or null when the
     * image has no transparency to speak of (a photo on a white background
     * uploaded by mistake would otherwise ship as a white box).
     *
     * @param  \GdImage  $im
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private static function opaqueBox($im): ?array
    {
        // Sampled on a copy at most 400px, so a 5000px upload costs the same.
        $W = imagesx($im);
        $H = imagesy($im);
        $s = min(1.0, 400 / max($W, $H));
        $w = max(1, (int) round($W * $s));
        $h = max(1, (int) round($H * $s));
        $sm = imagecreatetruecolor($w, $h);
        imagealphablending($sm, false);
        imagesavealpha($sm, true);
        imagecopyresampled($sm, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        $x0 = $w;
        $y0 = $h;
        $x1 = -1;
        $y1 = -1;
        $clear = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $a = (imagecolorat($sm, $x, $y) >> 24) & 127;
                if ($a >= 120) {
                    $clear++;

                    continue;
                }
                $x0 = min($x0, $x);
                $x1 = max($x1, $x);
                $y0 = min($y0, $y);
                $y1 = max($y1, $y);
            }
        }
        imagedestroy($sm);
        if ($x1 < 0 || $clear < $w * $h * 0.02) {
            return null;
        }
        $bx = (int) floor($x0 / $s);
        $by = (int) floor($y0 / $s);
        $bw = min($W - $bx, (int) ceil(($x1 + 1) / $s) - $bx);
        $bh = min($H - $by, (int) ceil(($y1 + 1) / $s) - $by);

        return [$bx, $by, max(1, $bw), max(1, $bh)];
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
