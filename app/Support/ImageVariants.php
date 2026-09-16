<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone-sized copies of the photographs this site serves, and the srcset that
 * offers them.
 *
 * THE PROBLEM. A product tile is a box between 153 and 399 CSS pixels wide —
 * measured across viewports from 360 to 1920 on /shop, a category archive and
 * the related-products grid — and the file behind it is a 1000x1000
 * photograph. On a 390px phone the nine tiles a shopper actually fetches are
 * about 2.0MB of image to paint nine 187x180 frames. The bytes are real and
 * the pixels are thrown away.
 *
 * WHY THE COPIES ARE MADE ON THE WAY IN AND NOT ON THE WAY OUT. This store is
 * on shared hosting with no shell, no queue worker and no CDN. Resizing when a
 * photograph is *requested* would put an image decode in front of every tile
 * on a cold page: twenty-five tiles is twenty-five PHP processes on a host
 * that has a handful, and the shopper waits for all of them. Measured here,
 * one 1000x1000 JPEG costs ~137ms and ~7.6MB of resident memory to turn into
 * both sizes; a 4000x4000 upload costs ~544ms and ~65MB. That is an
 * acceptable price once, when an image is uploaded. It is not a price to pay
 * per page view, per tile, forever.
 *
 * So a variant exists because something made it — an upload, or a batch the
 * owner started from the Media Library — and never because a page asked for
 * it.
 *
 * WHY THE SRCSET IS BUILT FROM THE FILESYSTEM AND NOT FROM A DATABASE COLUMN.
 * A srcset that names a file which is not there is worse than no srcset at
 * all: the browser fetches it, gets a 404, and the tile is blank — and unlike
 * a stale `src` there is no second candidate to fall back to. A column can
 * disagree with the disk (a restored backup, a half-finished batch, a package
 * that removed files — this repository has had all three). The disk cannot
 * disagree with itself. Two is_file() calls per tile, fifty on a full grid,
 * are answered from the kernel's dentry cache and out of PHP's own stat cache;
 * they cost far less than one 190KB download they save.
 *
 * Deliberately NOT memoised in a static. CLAUDE.md records what a process-level
 * memo does to a long-lived process, and this one would be wrong in exactly the
 * process that matters: the admin request that has just generated a batch of
 * variants and then renders a page describing them.
 *
 * WHERE THE FILES LIVE, AND WHY NOT IN THE REPOSITORY. Under
 * public_path('img-cache/<width>/<the original's path>'). public_path() is the
 * web root, which on this host is a different directory from the application
 * root (see bootstrap/app.php), and it is where uploads already go — the
 * directory is brought into existence by PHP's own mkdir() here, the same way
 * MediaUploadController already creates public/uploads/<folder>, because the
 * host has no shell to create it with.
 *
 * Generated files are not source. They are gitignored, so nothing the packager
 * builds from git history can contain them, and 'public/img-cache/' is on
 * BuildPackage::NEVER_SHIP as well. Both matter: a package that carries
 * generated images is a package that can delete them on the next install, and
 * deleting files the product pages depend on is exactly how 2.60.102-.106 took
 * this shop down.
 *
 * A mirrored path rather than a `-400w` suffix beside the original, so that a
 * file which happens to be named `photo-400w.jpg` can never be mistaken for a
 * variant of `photo.jpg`, and so the whole cache is one directory the owner
 * can delete without touching a single original.
 */
final class ImageVariants
{
    /**
     * The widths generated, smallest first.
     *
     * 400 is the phone: a 187px frame at device-pixel-ratio 2 needs 374 real
     * pixels. 800 is the same frame at ratio 3 and a 2x desktop. Nothing
     * larger is generated because no tile frame on this site is wider than
     * 399 CSS pixels, so 800 already covers every case a browser can ask for.
     */
    public const WIDTHS = [400, 800];

    /** The web-root directory the copies live under. */
    public const DIR = 'img-cache';

    /** JPEG and WebP quality. 82 is the knee: 25KB at 400px, no visible loss at tile size. */
    private const QUALITY = 82;

    /** Extensions that can be resized. SVG has no pixels; GIF is usually an animation. */
    private const RESIZABLE = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Whether this PHP can resize an image at all.
     *
     * GD only. Imagick is not installed on any machine this lane could test on,
     * and an untested second code path that silently produces nothing is worse
     * than one path that says plainly it is unavailable — the caller can then
     * tell the owner, instead of a batch that reports success and writes no
     * files. When this is false nothing is generated, no srcset is emitted, and
     * every tile keeps the original it has today.
     */
    public static function available(): bool
    {
        return \extension_loaded('gd') && \function_exists('imagecreatetruecolor');
    }

    /**
     * The srcset value for a photograph, or '' when there is nothing to offer.
     *
     * Only variants that are on disk right now appear. If none is, the caller
     * emits no srcset and no sizes, and the browser loads `src` exactly as it
     * does today — which is the whole reason this returns a string to be tested
     * rather than a list to be trusted.
     *
     * The original is NOT listed as a candidate. Naming it would mean stating
     * its intrinsic width, and the only honest source for that is
     * getimagesize() — a file read per tile, to advertise a candidate no
     * browser would ever choose: the widest frame on the site is 399 CSS
     * pixels, so even at device-pixel-ratio 3 the 800w copy is the largest
     * anything can ask for.
     */
    public static function srcsetFor(string $image): string
    {
        $parts = self::split($image);

        if ($parts === null) {
            return '';
        }

        [$prefix, $rel, $fsRel] = $parts;
        $candidates = [];

        foreach (self::WIDTHS as $width) {
            if (is_file(public_path(self::DIR.'/'.$width.'/'.$fsRel))) {
                $candidates[] = $prefix.'/'.self::DIR.'/'.$width.'/'.$rel.' '.$width.'w';
            }
        }

        return implode(', ', $candidates);
    }

    /**
     * What the tile tells the browser about how wide the photograph will be
     * drawn, so it can choose between the candidates before layout exists.
     *
     * Measured, not guessed. The frame is .pc .ph, a fixed 180px-tall box whose
     * width is the grid column's:
     *
     *   viewport   360  390  430  600  680  768  820 | 821  900 1000 1024 1080 1200 1280+
     *   frame px   172  187  207  292  332  373  399 | 260  286  227  235  253  215  225
     *
     * Two columns at or below 820, three or four above it, and the width above
     * 820 is not monotonic because the filter sidebar appears and disappears —
     * which is precisely why a single flat figure is wrong below that
     * breakpoint and right above it. 300px is above every measured frame from
     * 821 upwards, so the declaration never understates the box; 50vw is the
     * two-column rule the CSS actually applies below it.
     */
    public static function sizesAttribute(): string
    {
        return '(max-width: 820px) 50vw, 300px';
    }

    /**
     * Make the missing copies of one photograph.
     *
     * Idempotent: a variant that is already there is left alone, so a batch
     * that was interrupted can simply be run again.
     *
     * @return array{made: int, skipped: int, reason: ?string}
     */
    public static function generate(string $image): array
    {
        if (! self::available()) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'no image library'];
        }

        $parts = self::split($image);

        if ($parts === null) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'not a local image'];
        }

        [, , $fsRel] = $parts;
        $source = self::insidePublicRoot($fsRel);

        if ($source === null) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'file not found'];
        }

        $info = @getimagesize($source);

        if (! is_array($info) || (int) $info[0] < 1) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'unreadable image'];
        }

        [$srcWidth, $srcHeight] = [(int) $info[0], (int) $info[1]];
        $type = (int) ($info[2] ?? 0);

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'not a resizable type'];
        }

        $made = $skipped = 0;
        $wanted = [];

        foreach (self::WIDTHS as $width) {
            // Never upscale. A 300px original has no 400px version that is not
            // a lie about how much detail is in it, and a srcset candidate
            // whose width descriptor overstates the pixels behind it is how a
            // browser ends up choosing the blurriest file on offer.
            if ($srcWidth <= $width) {
                continue;
            }

            if (is_file(public_path(self::DIR.'/'.$width.'/'.$fsRel))) {
                $skipped++;

                continue;
            }

            $wanted[] = $width;
        }

        if ($wanted === []) {
            return ['made' => 0, 'skipped' => $skipped, 'reason' => null];
        }

        // One decode for every width. Reading the original twice is the single
        // most expensive thing this function could do.
        $src = self::read($source, $type);

        if ($src === null) {
            return ['made' => 0, 'skipped' => $skipped, 'reason' => 'could not decode'];
        }

        try {
            foreach ($wanted as $width) {
                $height = max(1, (int) round($srcHeight * ($width / $srcWidth)));

                if (self::write($src, $srcWidth, $srcHeight, $width, $height, $type, self::DIR.'/'.$width.'/'.$fsRel)) {
                    $made++;
                }
            }
        } finally {
            imagedestroy($src);
        }

        return ['made' => $made, 'skipped' => $skipped, 'reason' => null];
    }

    /**
     * Whether this photograph is one this server could resize: a file of a
     * resizable type, sitting in this web root, reachable at the path the tile
     * asks for it by.
     *
     * The negative answer is the useful one. It is what lets a screen say "440
     * of these are on another domain" instead of reporting a backlog that can
     * never go down.
     */
    public static function isLocal(string $image): bool
    {
        $parts = self::split($image);

        return $parts !== null && self::insidePublicRoot($parts[2]) !== null;
    }

    /**
     * Whether every copy this photograph should have already exists.
     *
     * "Should have" is the point: an original narrower than a target width is
     * complete without it, so a small logo does not sit in the backlog forever
     * being counted as unfinished work.
     *
     * The missing files are looked for first and the original is opened only if
     * one of them is absent. Once a catalogue has been through the batch that
     * is the common case by a wide margin, and it turns a screen that reads
     * every original's header into one that does not touch them at all.
     */
    public static function isComplete(string $image): bool
    {
        $parts = self::split($image);

        if ($parts === null) {
            return true;
        }

        [, , $fsRel] = $parts;
        $missing = [];

        foreach (self::WIDTHS as $width) {
            if (! is_file(public_path(self::DIR.'/'.$width.'/'.$fsRel))) {
                $missing[] = $width;
            }
        }

        if ($missing === []) {
            return true;
        }

        $source = self::insidePublicRoot($fsRel);

        if ($source === null) {
            return true;
        }

        $info = @getimagesize($source);

        if (! is_array($info)) {
            return true;
        }

        foreach ($missing as $width) {
            if ((int) $info[0] > $width) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Split a rendered image reference into the prefix it is served under and
     * the path below the web root, or null when it is not this site's file.
     *
     * THE PREFIX IS KEPT RATHER THAN REBUILT. Whatever the tile's `src` is
     * served under — nothing, "/kbb-upgrade", or an absolute origin — the
     * variant is offered under exactly the same thing, with only the path
     * swapped. So a variant URL can never be more broken, or less, than the
     * `src` beside it; if the catalogue holds a path missing its base prefix,
     * both are wrong together and the tile still shows a photograph from `src`
     * in every browser that does not support srcset. Rebuilding the prefix from
     * config instead is how this repository produced /kbb-upgrade/kbb-upgrade/
     * twice already.
     *
     * @return array{0: string, 1: string, 2: string}|null [prefix, url path, filesystem path]
     */
    private static function split(string $image): ?array
    {
        $image = trim($image);

        if ($image === '' || str_contains($image, "\0")) {
            return null;
        }

        $prefix = '';

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $image) === 1) {
            // An absolute URL is a promise about some origin's bytes. Only this
            // request's own origin can be checked against this web root, and a
            // photograph on the old WooCommerce domain is not ours to resize.
            $parsed = parse_url($image);

            if (! is_array($parsed) || ! isset($parsed['host'], $parsed['path'])) {
                return null;
            }

            // A cache-buster or a fragment would be dropped from the variant
            // URL and kept on the src, which is two different requests for what
            // is meant to be the same photograph. Left alone instead.
            if (isset($parsed['query']) || isset($parsed['fragment'])) {
                return null;
            }

            if (! app()->bound('request') || app()->runningInConsole()) {
                return null;
            }

            $host = strtolower((string) $parsed['host']);

            if ($host !== strtolower(request()->getHost())) {
                return null;
            }

            $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
            $prefix = (isset($parsed['scheme']) ? $parsed['scheme'].':' : '').'//'.$parsed['host'].$port;
            $path = (string) $parsed['path'];
        } elseif (str_starts_with($image, '/')) {
            $path = $image;
        } else {
            // A bare relative path resolves against whichever page is being
            // rendered, so it already means different files on /shop/ and on
            // /product/x/. Nothing sensible can be done with it.
            return null;
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            return null;
        }

        // The base path the site is served under is part of the prefix, not part
        // of the path below the web root: public_path() IS the directory that
        // /kbb-upgrade addresses.
        $base = Url::base();

        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $prefix .= $base;
            $path = substr($path, strlen($base));
        }

        $rel = ltrim($path, '/');

        if ($rel === '') {
            return null;
        }

        // The stored URL may be percent-encoded; the file on disk is not. The
        // two forms are kept apart on purpose — the decoded one addresses the
        // filesystem, the encoded one goes back into the HTML unchanged.
        $fsRel = rawurldecode($rel);

        if (str_contains($fsRel, "\0")) {
            return null;
        }

        foreach (explode('/', $fsRel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        // Its own cache is not an input to itself.
        if (str_starts_with($fsRel, self::DIR.'/')) {
            return null;
        }

        $extension = strtolower(pathinfo($fsRel, PATHINFO_EXTENSION));

        if (! in_array($extension, self::RESIZABLE, true)) {
            return null;
        }

        return [$prefix, $rel, $fsRel];
    }

    /**
     * The absolute path of a web-root-relative file, or null when it is not
     * there or has escaped the web root.
     *
     * The segment check in split() already rejects "..", and this is the second
     * belt: a symlink inside the web root can point anywhere, and realpath is
     * what follows it before anything is read.
     */
    private static function insidePublicRoot(string $fsRel): ?string
    {
        $root = realpath(public_path());
        $file = realpath(public_path($fsRel));

        if ($root === false || $file === false || ! is_file($file)) {
            return null;
        }

        return str_starts_with($file, rtrim($root, '/').'/') ? $file : null;
    }

    /** @return \GdImage|null */
    private static function read(string $file, int $type)
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            default => false,
        };

        return $image === false ? null : $image;
    }

    /**
     * Resample into one width and put it where the srcset will look for it.
     *
     * WRITTEN BESIDE AND THEN RENAMED. A file being written is a file the web
     * server will happily serve half of, and a half-written JPEG in a srcset is
     * a broken tile that stays broken until something overwrites it. rename()
     * within one filesystem is atomic, so the path either does not exist or
     * holds a complete image.
     */
    private static function write(
        \GdImage $src,
        int $srcWidth,
        int $srcHeight,
        int $width,
        int $height,
        int $type,
        string $target
    ): bool {
        $destination = public_path($target);
        $directory = \dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        $out = imagecreatetruecolor($width, $height);

        if ($out === false) {
            return false;
        }

        try {
            // PNG and WebP can carry transparency, and a truecolor canvas starts
            // opaque black. Without this a cut-out product shot gains a black
            // background at 400px and keeps a white one at 1000px.
            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($out, false);
                imagesavealpha($out, true);
                imagefilledrectangle($out, 0, 0, $width, $height, (int) imagecolorallocatealpha($out, 0, 0, 0, 127));
            }

            imagecopyresampled($out, $src, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);

            $temporary = $destination.'.'.bin2hex(random_bytes(4)).'.part';

            $written = match ($type) {
                IMAGETYPE_JPEG => @imagejpeg($out, $temporary, self::QUALITY),
                IMAGETYPE_PNG => @imagepng($out, $temporary, 6),
                IMAGETYPE_WEBP => @imagewebp($out, $temporary, self::QUALITY),
                default => false,
            };

            if ($written !== true || ! is_file($temporary)) {
                @unlink($temporary);

                return false;
            }

            if (! @rename($temporary, $destination)) {
                @unlink($temporary);

                return false;
            }

            @chmod($destination, 0644);

            return true;
        } finally {
            imagedestroy($out);
        }
    }
}
