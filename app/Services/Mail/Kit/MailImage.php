<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Models\Setting;
use App\Support\ImageVariants;
use App\Support\LegacyImageRedirect;
use App\Support\SiteHost;
use App\Support\SiteUrl;
use App\Support\Url;

/**
 * Every picture an email prints: a file that EXISTS in this web root, as a
 * JPEG, on the shop's own https origin.                              (Lane EM)
 *
 * THE OWNER, 8 October, over a screenshot of order 56171's confirmation with
 * the alt text standing in for every product under "YOUR ITEMS": "the images
 * are failing to load in the emails ... must not failed in any case."
 *
 * ── WHAT BROKE THEM, MEASURED ──────────────────────────────────────────────
 * MailKit::image() asked ImageVariants::variantUrl() for the 200px copy and
 * then sent the answer through Url::media(). variantUrl() hands back a
 * ROOT-relative "/img-cache/200/wp-content/uploads/…", and Url::media() puts
 * "/wp-content/uploads/" in front of anything that does not already start
 * with it, so every product whose shop-tile copy existed went out as
 *   https://extrabeauty.ae/wp-content/uploads/img-cache/200/wp-content/uploads/….webp
 * — a 404, and the shop makes those copies the first time a tile is viewed,
 * so it was every product anybody had ever bought. An admin upload
 * ("/uploads/products/x.webp") went out as /wp-content/uploads/uploads/… and
 * 404'd with or without a copy. Neither was ever checked against the disk.
 *
 * ── WHAT THIS DOES INSTEAD ─────────────────────────────────────────────────
 *  1. The stored value becomes a web-root-relative path: a root-relative path
 *     (with or without the base path, or a stale one like /kbb-upgrade), an
 *     absolute URL on this shop's own hosts, or — for another host, the old
 *     WordPress included — the same uploads path, because the importer and
 *     the sideloader keep it. Segments are checked ("..", ".", empty, NUL,
 *     backslash, control bytes refused) and the file is realpath()-contained
 *     in public_path() before anything reads it.
 *  2. A path with no file behind it follows LegacyImageRedirect::targetFor()
 *     — WebP conversions and Image SEO renames — to the file that shows it
 *     today, so an old name never reaches an inbox.
 *  3. The email copy: img-cache/mail/<w>/<path>.jpg, a baseline JPEG on white
 *     (Outlook for Windows, some Yahoo and older Android clients do not draw
 *     WebP, and most of this catalogue is WebP). Made ONCE with GD, the same
 *     library ImageVariants uses, written beside and renamed so a half file is
 *     never served; remade only when the original is newer. Encoding is never
 *     allowed to fail the send: any problem and the original (a file that
 *     exists) is printed instead.
 *  4. Nothing local at all: a neutral placeholder JPEG this class makes once
 *     (img-cache/mail/placeholder-<w>.jpg) — never a broken frame. A picture
 *     still on ANOTHER https host with no local copy is printed as-is, because
 *     that is exactly the address the shop's own pages print for it and this
 *     class makes no network call to second-guess it.
 *
 * ── THE ORIGIN ─────────────────────────────────────────────────────────────
 * https, always, on the first PUBLIC host of: Settings → Site address (the
 * main address the aliases forward to), SEO's Site URL (the canonical base),
 * APP_URL. "localhost", an IP literal and reserved test suffixes are refused,
 * so a worker with a half-loaded config cannot write one into an inbox; with
 * no public host at all a local picture is left out (the kit draws its empty
 * box) rather than guessed. Never the request: an email is out-of-band.
 *
 * ── COST ───────────────────────────────────────────────────────────────────
 * Not on any shop page: nothing outside the mail kit calls this. Per picture,
 * one realpath and one stat of the copy; no network call, ever. An encode
 * (~5–40 ms) happens once per picture per width, at most MAX_ENCODES a
 * minute per process, so a large campaign cannot stall a worker (a picture
 * past the allowance prints its original, a file that exists, and gets its
 * copy on a later send).
 */
final class MailImage
{
    /** Under ImageVariants::DIR, so it is web root, gitignored and NEVER_SHIP. */
    public const DIR = 'mail';

    /** 128 for the 64px item box at 2x; 400 for 200px cards; 600 for full-width blocks. */
    public const WIDTHS = [128, 400, 600];

    private const QUALITY = 82;

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** A product shot past this many pixels is not decoded inside a send. */
    private const MAX_PIXELS = 16_000_000;

    /** Encodes per minute per process: a long-lived queue worker gets a fresh allowance each minute. */
    private const MAX_ENCODES = 24;

    private static int $encodes = 0;

    private static int $window = 0;

    /** The email copy width for a box drawn $box CSS pixels wide (2x, capped). */
    public static function widthFor(int $box): int
    {
        foreach (self::WIDTHS as $w) {
            if ($w >= $box * 2) {
                return $w;
            }
        }

        return self::WIDTHS[count(self::WIDTHS) - 1];
    }

    /**
     * The https URL of a picture an email may print, or null.
     *
     * $width is one of WIDTHS. $placeholder false returns null where the
     * placeholder would go (a full-width block is better left out).
     */
    public static function src(mixed $stored, int $width, bool $placeholder = true): ?string
    {
        try {
            return self::resolve(is_string($stored) ? trim($stored) : '', $width, $placeholder);
        } catch (\Throwable) {
            // Never the reason an email did not go.
            return null;
        }
    }

    /** Reset the per-process encode budget. Test seam. */
    public static function resetBudget(): void
    {
        self::$encodes = 0;
        self::$window = 0;
    }

    /**
     * The email header's logo: [https URL, pixel width, pixel height], or null.
     *
     * A local JPEG, PNG, GIF or SVG prints as the file it is; a WebP gets a PNG
     * copy (a logo is drawn on the pink header, and a JPEG would put a white
     * box round it). A logo on another https host is the owner's choice and
     * prints as-is with no size. A local path with no file, an http URL or
     * anything else is null, and the header prints the wordmark instead of a
     * broken frame.
     *
     * @return array{0: string, 1: int|null, 2: int|null}|null
     */
    public static function logo(mixed $stored): ?array
    {
        try {
            $stored = is_string($stored) ? trim($stored) : '';

            if ($stored === '' || str_starts_with(strtolower($stored), 'mailto:')) {
                return null;
            }

            $rel = self::localPath($stored, ['svg']);
            $file = $rel === null ? null : self::inside($rel);

            if ($rel === null || $file === null) {
                $foreign = self::foreignHttps($stored);

                return $foreign === null ? null : [$foreign, null, null];
            }

            $origin = self::origin();

            if ($origin === '') {
                return null;
            }

            $info = @getimagesize($file);
            [$w, $h, $type] = is_array($info) ? [(int) $info[0], (int) $info[1], (int) ($info[2] ?? 0)] : [0, 0, 0];

            if ($type === IMAGETYPE_WEBP) {
                $copy = self::copy($rel, $file, 340, true);

                if ($copy !== null) {
                    $size = @getimagesize(public_path($copy));

                    return [$origin.'/'.self::encode($copy), is_array($size) ? (int) $size[0] : null, is_array($size) ? (int) $size[1] : null];
                }
            }

            return [$origin.'/'.self::encode($rel), $w > 0 ? $w : null, $h > 0 ? $h : null];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * https://<public host><base path>, or '' when no public host is known.
     */
    public static function origin(): string
    {
        $map = [];

        try {
            $map = Setting::map();
        } catch (\Throwable) {
        }

        $candidates = [];

        try {
            $candidates[] = SiteHost::canonical();
        } catch (\Throwable) {
        }

        $candidates[] = (string) parse_url((string) ($map['site_url'] ?? ''), PHP_URL_HOST);
        $candidates[] = (string) parse_url(SiteUrl::externalOrigin(), PHP_URL_HOST);

        foreach ($candidates as $host) {
            $host = strtolower(rtrim(trim((string) $host), '.'));

            if (self::publicHost($host)) {
                return 'https://'.$host.Url::base();
            }
        }

        return '';
    }

    private static function resolve(string $stored, int $width, bool $placeholder): ?string
    {
        if (! in_array($width, self::WIDTHS, true)) {
            $width = self::widthFor((int) ceil($width / 2));
        }

        $rel = $stored === '' ? null : self::localPath($stored);
        $file = $rel === null ? null : self::inside($rel);

        // An old name: a WebP conversion or an Image SEO rename moved it.
        if ($rel !== null && $file === null) {
            $rel = LegacyImageRedirect::targetFor($rel);
            $file = $rel === null ? null : self::inside($rel);
        }

        // Not here, and on another https host: the address the shop prints.
        if ($file === null && $stored !== '' && ($foreign = self::foreignHttps($stored)) !== null) {
            return $foreign;
        }

        $origin = self::origin();

        if ($origin === '') {
            return null;
        }

        if ($rel !== null && $file !== null) {
            $copy = self::copy($rel, $file, $width);

            return $origin.'/'.self::encode($copy ?? $rel);
        }

        if (! $placeholder) {
            return null;
        }

        $ph = self::placeholder($width);

        return $ph === null ? null : $origin.'/'.$ph;
    }

    /**
     * The stored value as a web-root-relative path, or null when it does not
     * name a picture this server could hold.
     */
    /** @param list<string> $extra */
    private static function localPath(string $stored, array $extra = []): ?string
    {
        if ($stored === '' || strlen($stored) > 1000 || preg_match('/[\x00-\x1F\x7F\\\\]/', $stored) === 1) {
            return null;
        }

        if (str_starts_with($stored, '//')) {
            $stored = 'https:'.$stored;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $stored) === 1) {
            $parts = parse_url($stored);

            if (! is_array($parts) || ! isset($parts['host'], $parts['path'])
                || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                return null;
            }

            $path = (string) $parts['path'];

            if (! in_array(strtolower((string) $parts['host']), self::ownHosts(), true)) {
                // Another host: only its uploads path, which the importer and
                // the sideloader keep byte for byte when they copy a picture.
                return self::candidates([self::uploadsCut($path, $extra)]);
            }
        } else {
            $path = (string) (preg_split('/[?#]/', $stored)[0] ?? '');

            if (! str_starts_with($path, '/')) {
                $path = '/'.$path;
            }
        }

        $base = Url::base();

        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        }

        return self::candidates([self::clean($path, $extra), self::uploadsCut($path, $extra)]);
    }

    /**
     * The first candidate with a file behind it, else the first candidate:
     * "/kbb-upgrade/uploads/x.webp" is tried as written and then cut at its
     * uploads root, so a stale subfolder on a row still finds the picture.
     *
     * @param  list<string|null>  $candidates
     */
    private static function candidates(array $candidates): ?string
    {
        $candidates = array_values(array_unique(array_filter($candidates, 'is_string')));

        foreach ($candidates as $rel) {
            if (self::inside($rel) !== null) {
                return $rel;
            }
        }

        return $candidates[0] ?? null;
    }

    /** "…/wp-content/uploads/x.jpg" or "…/uploads/x.jpg" from anywhere in a path. */
    /** @param list<string> $extra */
    private static function uploadsCut(string $path, array $extra = []): ?string
    {
        foreach (['/wp-content/uploads/', '/uploads/'] as $root) {
            $at = strpos($path, $root);

            if ($at !== false) {
                return self::clean(substr($path, $at), $extra);
            }
        }

        return null;
    }

    /** Decoded, segment-checked, picture-typed — or null. */
    /** @param list<string> $extra */
    private static function clean(string $path, array $extra = []): ?string
    {
        $rel = rawurldecode(ltrim($path, '/'));

        if ($rel === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $rel) === 1) {
            return null;
        }

        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        // Its own output is not an input.
        if (str_starts_with($rel, ImageVariants::DIR.'/'.self::DIR.'/')) {
            return null;
        }

        return in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), [...self::EXTENSIONS, ...$extra], true) ? $rel : null;
    }

    /** The real file, only when it is a file inside the web root. */
    private static function inside(string $rel): ?string
    {
        $root = realpath(public_path());
        $file = realpath(public_path($rel));

        if ($root === false || $file === false || ! is_file($file)) {
            return null;
        }

        return str_starts_with($file, rtrim($root, '/').'/') ? $file : null;
    }

    /** https URL on another host, printed as the shop prints it; null otherwise. */
    private static function foreignHttps(string $stored): ?string
    {
        $url = str_starts_with($stored, '//') ? 'https:'.$stored : $stored;
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || in_array(strtolower((string) $parts['host']), self::ownHosts(), true)
            || ! self::publicHost(strtolower((string) $parts['host']))
            || preg_match('#^https://[^\s\'"<>\\\\]+$#i', $url) !== 1) {
            return null;
        }

        return $url;
    }

    /** @return list<string> */
    private static function ownHosts(): array
    {
        $hosts = [];

        try {
            $hosts[] = SiteHost::canonical();
            $hosts = array_merge($hosts, SiteHost::aliases());
        } catch (\Throwable) {
        }

        try {
            $hosts[] = (string) parse_url((string) (Setting::map()['site_url'] ?? ''), PHP_URL_HOST);
        } catch (\Throwable) {
        }

        $hosts[] = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $out = [];

        foreach ($hosts as $h) {
            $h = strtolower(trim((string) $h));

            if ($h !== '') {
                $out[] = $h;
                $out[] = str_starts_with($h, 'www.') ? substr($h, 4) : 'www.'.$h;
            }
        }

        return array_values(array_unique($out));
    }

    private static function publicHost(string $host): bool
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            return false;
        }

        foreach (['.localhost', '.local', '.test', '.invalid', '.internal', '.example', '.lan', '.home.arpa'] as $reserved) {
            if (str_ends_with($host, $reserved)) {
                return false;
            }
        }

        return true;
    }

    /**
     * img-cache/mail/<w>/<rel>.jpg, made when missing or older than the
     * original; null when it cannot be made (the caller prints the original).
     */
    private static function copy(string $rel, string $file, int $width, bool $png = false): ?string
    {
        $target = ImageVariants::DIR.'/'.self::DIR.'/'.$width.'/'.$rel.($png ? '.png' : '.jpg');
        $dest = public_path($target);

        if (is_file($dest) && (int) @filemtime($dest) >= (int) @filemtime($file)) {
            return $target;
        }

        if (time() - self::$window >= 60) {
            [self::$window, self::$encodes] = [time(), 0];
        }

        if (self::$encodes >= self::MAX_ENCODES || ! ImageVariants::available()) {
            return null;
        }

        $info = @getimagesize($file);

        if (! is_array($info) || (int) $info[0] < 1 || (int) $info[1] < 1
            || (int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            return null;
        }

        [$sw, $sh, $type] = [(int) $info[0], (int) $info[1], (int) ($info[2] ?? 0)];

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($file),
            default => false,
        };

        if ($src === false) {
            return null;
        }

        self::$encodes++;
        $w = min($width, $sw);
        $h = max(1, (int) round($sh * $w / $sw));

        try {
            $out = imagecreatetruecolor($w, $h);

            if ($png) {
                imagealphablending($out, false);
                imagesavealpha($out, true);
                imagefilledrectangle($out, 0, 0, $w, $h, (int) imagecolorallocatealpha($out, 0, 0, 0, 127));
            } else {
                // A JPEG has no alpha; a cut-out goes on white, not GD's black.
                imagefilledrectangle($out, 0, 0, $w, $h, (int) imagecolorallocate($out, 255, 255, 255));
            }

            imagecopyresampled($out, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);

            return self::write($out, $target, $png) ? $target : null;
        } finally {
            imagedestroy($src);
        }
    }

    /** A neutral square in the kit's own soft pink, made once per width. */
    private static function placeholder(int $width): ?string
    {
        $target = ImageVariants::DIR.'/'.self::DIR.'/placeholder-'.$width.'.jpg';

        if (is_file(public_path($target))) {
            return $target;
        }

        if (! ImageVariants::available()) {
            return null;
        }

        $out = imagecreatetruecolor($width, $width);
        imagefilledrectangle($out, 0, 0, $width, $width, (int) imagecolorallocate($out, 0xFF, 0xF0, 0xF4));

        return self::write($out, $target, false) ? $target : null;
    }

    /** Written beside and renamed, so the web server never serves half a file. */
    private static function write(\GdImage $out, string $target, bool $png): bool
    {
        try {
            $dest = public_path($target);
            $dir = \dirname($dest);

            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return false;
            }

            $tmp = $dest.'.'.bin2hex(random_bytes(4)).'.part';
            imageinterlace($out, false);

            $ok = $png ? @imagepng($out, $tmp, 6) : @imagejpeg($out, $tmp, self::QUALITY);

            if ($ok !== true || ! is_file($tmp) || ! @rename($tmp, $dest)) {
                @unlink($tmp);

                return false;
            }

            @chmod($dest, 0644);

            return true;
        } finally {
            imagedestroy($out);
        }
    }

    private static function encode(string $rel): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $rel)));
    }
}
