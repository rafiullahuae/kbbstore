<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The picture a shared product link carries: a JPEG made for link previews,
 * behind og:image and twitter:image.                                (Lane QB)
 *
 * THE OWNER, 2 October: "the share icons don't bring the image along with the
 * message."
 *
 * ── WHY THEY DID NOT, READ OFF THE PAGE RATHER THAN GUESSED ────────────────
 *
 * A wa.me / t.me / sms: link carries TEXT. None of them can attach a file; the
 * picture in the bubble is a LINK PREVIEW, which the sender's app builds by
 * fetching the shared URL and reading its Open Graph tags. So the question was
 * never the share link, it was what og:image names. The product page published
 * (measured on the preview, tools/qb-preview.sh, before this change):
 *
 *   <meta property="og:image" content="https://…/uploads/products/x.webp">
 *   <meta property="og:image:width" content="1600"> (height 1600)
 *
 * — the ORIGINAL upload, which for this catalogue is mostly WebP straight out
 * of the WooCommerce export, at full size. Absolute and early in the <head>
 * (byte ~1000 of the document), so neither of the usual suspects; the picture
 * itself is the problem:
 *
 *   · WhatsApp's link-preview fetcher does not reliably accept WebP — previews
 *     on several clients come back text-only for a .webp og:image — and it
 *     skips pictures it judges too heavy (the widely-reproduced ceiling is
 *     ~300 KB). A 1600px original is over it more often than not.
 *   · Facebook and LinkedIn crop a SQUARE picture to 1.91:1 in a link card,
 *     which takes the top and bottom quarter off a bottle; X's large card is
 *     2:1 and does the same.
 *
 * ── SO: ONE JPEG PER PHOTOGRAPH, 1200×630, LETTERBOXED ON WHITE ────────────
 *
 *   format    JPEG, baseline. The one format every preview fetcher accepts.
 *   shape     1200×630 (1.91:1) — Facebook's, LinkedIn's and WhatsApp's large
 *             card — with the whole photograph fitted INSIDE on white, never
 *             cropped. A product shot on white reads as the product centred
 *             on a white card, which is the Amazon look he pointed at, and no
 *             network has anything left to crop. A square 1200×1200 was the
 *             alternative and was rejected for exactly the crop above.
 *   small     A source too small to fill 630 rows is not blown up: the canvas
 *             shrinks with it (same 1.91:1, never under 600×315, Facebook's
 *             floor for a large card).
 *   weight    Quality 84, stepped down to 60 until the file is under BUDGET
 *             (300 KB). A real product shot on white lands at 40–110 KB on
 *             the first try; the steps exist for a noisy photograph.
 *   alpha     A cut-out PNG/WebP is composited onto white (a JPEG has no
 *             alpha, and GD's default would paint it black).
 *
 * ── WHERE IT LIVES ──────────────────────────────────────────────────────────
 *
 * public_path('img-cache/share/<the original's path>.jpg'). Under ImageVariants'
 * own cache directory, so everything that header says applies unchanged: it is
 * the WEB root (a different directory from the app root on this host — see
 * bootstrap/app.php), it is gitignored, `public/img-cache/` is on
 * BuildPackage::NEVER_SHIP, and the whole directory can be deleted at any time.
 * `.jpg` is APPENDED rather than swapped in, so `x.webp` and `x.jpg` uploaded
 * side by side can never share one file.
 *
 * ── WHEN IT IS MADE, AND WHY NOT INSIDE THE REQUEST ────────────────────────
 *
 * Decoding a 1600px WebP and encoding a JPEG costs ~60–150 ms here. That is
 * too much to put in front of a shopper, and this host has no queue worker.
 * Three ways in, none of which a shopper waits for:
 *
 *   1. AFTER THE RESPONSE of the first product-page view that finds it
 *      missing — app()->terminating(). Symfony's Response::send() calls
 *      fastcgi_finish_request() (or litespeed_finish_request()), so on the
 *      live PHP-FPM the shopper has the whole page before the encode starts.
 *      A cache lock keeps a burst of views to one encode per photograph.
 *   2. `php artisan kbb:share-images` — the whole catalogue at once. The live
 *      server has a shell (CLAUDE.md), so this is the right backfill after
 *      the package is applied.
 *   3. Nothing else. Not on upload: most uploads are not a product's main
 *      photograph, and the import does not upload.
 *
 * UNTIL IT EXISTS, og:image is the original exactly as before — the page is
 * never worse than it was. A share IMMEDIATELY after the very first view can
 * still get the original; the command above closes that window for the
 * existing catalogue.
 *
 * STALE IS TREATED AS MISSING. If the original's mtime is newer than the JPEG
 * (replaced by FTP or a restored backup), url() answers null — the original
 * is published — and the next view makes a fresh copy.
 *
 * Never throws to a caller on the storefront: every failure is "no share
 * image", which is "the page as it was".
 */
final class ShareImage
{
    /** Below ImageVariants::DIR, so one cache directory holds every derivative. */
    public const DIR = 'img-cache/share';

    /**
     * (2.60.365) The SQUARE card's directory, beside the wide one. Appearance
     * → Product page → Share · Link preview card → "Picture shape" picks which
     * one og:image names; each shape keeps its own files, so switching back
     * and forth never serves a picture of the other shape.
     */
    public const DIR_SQUARE = 'img-cache/share-sq';

    /** The square card's side, and the smallest a small source shrinks it to. */
    public const SQUARE = 1200;

    public const MIN_SQUARE = 600;

    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /** The smallest canvas a small source gets: Facebook's floor for a large card. */
    public const MIN_HEIGHT = 315;

    /** Bytes. WhatsApp's fetcher is the strictest, and skips heavier pictures. */
    public const BUDGET = 300 * 1024;

    /** Tried in order until the encode is under BUDGET. */
    public const QUALITIES = [84, 76, 68, 60];

    /** Seconds a "making it now" lock holds, so a burst of views costs one encode. */
    private const LOCK_SECONDS = 600;

    /**
     * What a product page publishes for this photograph, or null for "use the
     * original". Schedules the copy after the response when it is missing.
     *
     * @return array{url: string, path: string, width: int, height: int}|null
     */
    public static function forPage(?string $image): ?array
    {
        if ($image === null || trim($image) === '') {
            return null;
        }

        try {
            $found = self::find($image);

            /*
             * (Integrator, 2.60.364) THE FIRST SHARE CAME BACK WITH NO PICTURE.
             * The owner: "when share the product, it picks short description
             * and title. but no image." The first request for a product whose
             * copy is missing is, more often than not, WhatsApp's OWN fetcher
             * reading the link a shopper just pasted. After-response made the
             * JPEG for the NEXT request, so that fetcher was handed the WebP
             * original, which it drops, and it cached the bare preview for
             * that URL. Measured on the preview: fetch 1 published
             * fino.webp, fetch 2 fino.webp.jpg. A link-preview fetcher is not
             * a shopper and does not mind ~100 ms, so for one the copy is
             * made NOW and this very response names it.
             */
            if ($found === null && self::isPreviewFetcher()) {
                self::make($image);
                $found = self::find($image);
            }

            if ($found === null) {
                self::makeAfterResponse($image);
            }

            return $found;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The share copy if it is on disk and not older than its original.
     *
     * `url` keeps the original's own prefix (nothing, a base path, or this
     * origin) exactly as ImageVariants does, so it is never more broken than
     * the `src` beside it; `path` is the same address root-relative, which the
     * sheet's script fetches for the phone's share sheet (same origin, always).
     *
     * @return array{url: string, path: string, width: int, height: int}|null
     */
    public static function find(string $image): ?array
    {
        $loc = ImageVariants::locate($image);

        if ($loc === null) {
            return null;
        }

        $target = public_path(self::dir().'/'.$loc['fsRel'].'.jpg');

        if (! is_file($target) || (int) @filemtime($target) < (int) @filemtime($loc['file'])) {
            return null;
        }

        $info = @getimagesize($target);

        if (! is_array($info) || (int) ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
            return null;
        }

        $url = $loc['prefix'].'/'.self::dir().'/'.$loc['rel'].'.jpg';
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');

        return ['url' => $url, 'path' => $path, 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * Link-preview fetchers, by the names they send. iMessage fetches as
     * "facebookexternalhit … Twitterbot", so it is covered by those two.
     */
    public const PREVIEW_FETCHERS = '#WhatsApp|facebookexternalhit|Facebot|TelegramBot|Twitterbot|LinkedInBot|Slackbot|Discordbot|Pinterest|SkypeUriPreview|Snapchat|Viber|redditbot|vkShare|Embedly|Iframely#i';

    /** Whether the current request is an app building a link preview. */
    public static function isPreviewFetcher(): bool
    {
        $ua = app()->bound('request') ? (string) request()->userAgent() : '';

        return $ua !== '' && preg_match(self::PREVIEW_FETCHERS, $ua) === 1;
    }

    /** Whether the owner picked the square card. Never throws: any failure is the wide card. */
    public static function square(): bool
    {
        try {
            return app(\App\Services\ProductTrustShare::class)->choice('card_picture') === 'square';
        } catch (\Throwable) {
            return false;
        }
    }

    /** The directory of the picked shape. */
    public static function dir(): string
    {
        return self::square() ? self::DIR_SQUARE : self::DIR;
    }

    /** Register one after-response encode for this photograph, at most once per lock window. */
    public static function makeAfterResponse(string $image): void
    {
        if (! ImageVariants::available() || ImageVariants::locate($image) === null) {
            return;
        }

        if (! Cache::add('kbb.share-image.'.sha1($image.'|'.self::dir()), 1, self::LOCK_SECONDS)) {
            return;
        }

        app()->terminating(static function () use ($image): void {
            try {
                $result = self::make($image);

                if ($result['reason'] !== null && $result['reason'] !== 'fresh') {
                    Log::info('Share image not made', ['image' => $image, 'reason' => $result['reason']]);
                }
            } catch (\Throwable $e) {
                Log::warning('Share image failed', ['image' => $image, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Make (or re-make, if stale) the share copy of one photograph.
     *
     * @return array{made: bool, bytes: int, width: int, height: int, quality: int, reason: ?string}
     */
    public static function make(string $image): array
    {
        $none = static fn (string $why): array => ['made' => false, 'bytes' => 0, 'width' => 0, 'height' => 0, 'quality' => 0, 'reason' => $why];

        if (! ImageVariants::available()) {
            return $none('no image library');
        }

        $loc = ImageVariants::locate($image);

        if ($loc === null) {
            return $none('not a local image');
        }

        if (($found = self::find($image)) !== null) {
            return ['made' => false, 'bytes' => (int) @filesize(public_path(self::dir().'/'.$loc['fsRel'].'.jpg')),
                'width' => $found['width'], 'height' => $found['height'], 'quality' => 0, 'reason' => 'fresh'];
        }

        $info = @getimagesize($loc['file']);

        if (! is_array($info) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) {
            return $none('unreadable image');
        }

        [$sw, $sh, $type] = [(int) $info[0], (int) $info[1], (int) ($info[2] ?? 0)];

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($loc['file']),
            IMAGETYPE_PNG => @imagecreatefrompng($loc['file']),
            IMAGETYPE_WEBP => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($loc['file']) : false,
            default => false,
        };

        if ($src === false) {
            return $none('could not decode');
        }

        [$cw, $ch, $dx, $dy, $dw, $dh] = self::geometry($sw, $sh, self::square());

        $out = imagecreatetruecolor($cw, $ch);

        try {
            imagefilledrectangle($out, 0, 0, $cw, $ch, (int) imagecolorallocate($out, 255, 255, 255));
            // Blending ON: a transparent pixel lands as the white beneath it.
            imagealphablending($out, true);
            imagecopyresampled($out, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);

            $bytes = '';
            $used = 0;

            foreach (self::QUALITIES as $q) {
                ob_start();
                imagejpeg($out, null, $q);
                $bytes = (string) ob_get_clean();
                $used = $q;

                if (strlen($bytes) <= self::BUDGET) {
                    break;
                }
            }
        } finally {
            imagedestroy($out);
            imagedestroy($src);
        }

        if ($bytes === '') {
            return $none('could not encode');
        }

        $target = public_path(self::dir().'/'.$loc['fsRel'].'.jpg');
        $dir = \dirname($target);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $none('cache directory not writable');
        }

        // Beside, then renamed: a reader sees the whole JPEG or no file.
        $tmp = $target.'.'.bin2hex(random_bytes(4)).'.part';

        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || ! @rename($tmp, $target)) {
            @unlink($tmp);

            return $none('could not write');
        }

        @chmod($target, 0644);

        return ['made' => true, 'bytes' => strlen($bytes), 'width' => $cw, 'height' => $ch, 'quality' => $used, 'reason' => null];
    }

    /**
     * The canvas and where the photograph sits on it: fitted inside, centred,
     * never cropped, never enlarged past its own pixels.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} [canvasW, canvasH, x, y, w, h]
     */
    public static function geometry(int $sw, int $sh, bool $square = false): array
    {
        [$W, $H, $min] = $square ? [self::SQUARE, self::SQUARE, self::MIN_SQUARE] : [self::WIDTH, self::HEIGHT, self::MIN_HEIGHT];
        $ch = $H;

        // A source that cannot fill the full card at its own size shrinks the
        // card with it, down to the large-card floor.
        $fit = min($W / $sw, $H / $sh);

        if ($fit > 1) {
            $ch = max($min, min($H, (int) ceil(max($sh, $sw * $H / $W))));
        }

        $cw = (int) round($ch * $W / $H);
        $scale = min($cw / $sw, $ch / $sh);
        $dw = max(1, (int) round($sw * $scale));
        $dh = max(1, (int) round($sh * $scale));

        return [$cw, $ch, intdiv($cw - $dw, 2), intdiv($ch - $dh, 2), $dw, $dh];
    }

    /** Remove the share copy of a photograph that has gone away. Never throws. */
    public static function forget(string $image): bool
    {
        try {
            $loc = ImageVariants::locate($image);
            $fsRel = $loc['fsRel'] ?? null;

            if ($fsRel === null) {
                // The original is already gone, so locate() cannot see it; the
                // path is still a path. Same segment rules as ImageVariants.
                $rel = ltrim((string) parse_url($image, PHP_URL_PATH), '/');
                $base = ltrim(Url::base(), '/');

                if ($base !== '' && str_starts_with($rel, $base.'/')) {
                    $rel = substr($rel, strlen($base) + 1);
                }

                $fsRel = rawurldecode($rel);

                foreach (explode('/', $fsRel) as $seg) {
                    if ($seg === '' || $seg === '.' || $seg === '..' || str_contains($seg, "\0")) {
                        return false;
                    }
                }
            }

            $gone = false;

            // Both shapes: a photograph that has gone away takes both cards with it.
            foreach ([self::DIR, self::DIR_SQUARE] as $dir) {
                $file = public_path($dir.'/'.$fsRel.'.jpg');
                $gone = (is_file($file) && @unlink($file)) || $gone;
            }

            return $gone;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
