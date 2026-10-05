<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * JPEG/PNG -> WebP with GD, and nothing else. (Lane WP)
 *
 * The owner: "whenever we upload jpg or png images, can we convert auto into
 * webp format, bcz all our existing images are in webp from existing site."
 *
 * Pure: it reads one file and writes one file, and knows nothing about the
 * database, settings or URLs. WebpBulk decides WHICH file and WHERE; this
 * decides whether the bytes are a picture worth converting, and converts it.
 *
 * WHAT IT REFUSES, AND WHY EACH IS A REFUSAL RATHER THAN A BEST EFFORT
 *
 *  - Anything whose MAGIC BYTES are not JPEG or PNG. The extension is a string
 *    somebody typed. A GIF (usually an animation), an SVG (no pixels) and a
 *    file that is already WebP are left exactly as they are.
 *  - A decompression bomb. getimagesize() reads the header only, so a
 *    5 MB PNG that claims 30000x30000 is caught BEFORE GD allocates the
 *    3.6 GB it would decode into. MAX_MEGAPIXELS is the ceiling, and a second
 *    check asks whether this PHP has the memory left for the decode at all.
 *  - A WebP that is not smaller. The point is speed; a conversion that adds
 *    bytes is reported as skipped and the original stays as it is.
 *
 * WHAT IT KEEPS, AND WHAT IT DROPS
 *
 *  - PNG transparency: alpha blending off and imagesavealpha() on, and a
 *    palette PNG is promoted to truecolor first, because GD's WebP encoder
 *    only carries alpha from a truecolor image.
 *  - JPEG orientation: a phone photo is stored sideways with an EXIF tag that
 *    says so. The tag is read here (no exif extension needed — Cloudways
 *    builds vary) and the pixels are turned, because the WebP that comes out
 *    carries no EXIF at all.
 *  - Metadata is dropped, all of it: GD never copies EXIF, GPS or ICC into
 *    what it encodes. That is the "strip metadata" rule, and also the reason a
 *    customer's phone location cannot leak through a converted photo.
 *  - Never upscaled. $maxWidth only ever shrinks.
 *
 * WRITTEN BESIDE AND RENAMED, like every other image writer in this repo
 * (ImageVariants::write): the web server can never serve half a WebP.
 */
final class WebpConverter
{
    /**
     * The decompression-bomb ceiling, in pixels. 40 MP is above every phone
     * camera's default JPEG (12 to 24 MP; the 48 MP sensors bin down) and
     * below anything a 256 MB PHP worker can decode with room to spare
     * (40 MP x 4 bytes = 160 MB for the bitmap alone).
     */
    public const MAX_PIXELS = 40_000_000;

    /** Bytes GD needs per decoded pixel, plus headroom for its own structures. */
    private const BYTES_PER_PIXEL = 5;

    public const TYPE_JPEG = 'jpeg';

    public const TYPE_PNG = 'png';

    public const TYPE_GIF = 'gif';

    public const TYPE_WEBP = 'webp';

    public const TYPE_SVG = 'svg';

    /** Can this PHP encode WebP at all? */
    public static function available(): bool
    {
        return self::unavailableReason() === null;
    }

    /**
     * Why this server cannot convert, in words for the admin, or null when it
     * can. Asked of the RUNNING PHP, never assumed: the Cloudways build is not
     * this machine's.
     */
    public static function unavailableReason(): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatetruecolor')) {
            return 'This server\'s PHP has no GD image library, so it cannot make WebP files. Uploads are stored exactly as they arrive.';
        }

        if (! function_exists('imagewebp') || ! (imagetypes() & IMG_WEBP)) {
            return 'This server\'s GD library was built without WebP support, so it cannot make WebP files. Uploads are stored exactly as they arrive.';
        }

        if (! function_exists('imagecreatefromjpeg') || ! function_exists('imagecreatefrompng')) {
            return 'This server\'s GD library cannot read JPEG or PNG, so it cannot convert them. Uploads are stored exactly as they arrive.';
        }

        return null;
    }

    /**
     * What the file IS, from its first bytes. Null for anything else.
     */
    public static function sniff(string $file): ?string
    {
        $fh = @fopen($file, 'rb');

        if ($fh === false) {
            return null;
        }

        $head = (string) fread($fh, 512);
        fclose($fh);

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => self::TYPE_JPEG,
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => self::TYPE_PNG,
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => self::TYPE_GIF,
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => self::TYPE_WEBP,
            preg_match('/^(\xEF\xBB\xBF)?\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $head) === 1 => self::TYPE_SVG,
            default => null,
        };
    }

    /**
     * Width and height off the header, without decoding. Null if unreadable.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function dimensions(string $file): ?array
    {
        $info = @getimagesize($file);

        if (! is_array($info) || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /** Is this header a decompression bomb by this class's ceiling? */
    public static function isBomb(int $width, int $height): bool
    {
        return $width * $height > self::MAX_PIXELS;
    }

    /**
     * Convert one file.
     *
     * $destination === null measures only: the WebP is encoded in memory and
     * its size reported, nothing is written. That is the dry run.
     *
     * @return array{ok: bool, reason: ?string, type: ?string, bytes_before: int, bytes_after: int, width: int, height: int, resized: bool}
     */
    /**
     * $always (uploads, 2.60.388): the owner, "from anywhere i upload any image,
     * it should be must converted to webp". When the first WebP is not smaller,
     * a lower quality (a photo) and lossless WebP (a PNG graphic) are tried, and
     * the smallest WebP is kept even if it is still not smaller than the file
     * uploaded. The bulk run leaves $always off: there "only when smaller" holds.
     */
    public static function convert(string $source, ?string $destination, int $quality = 82, int $maxWidth = 0, bool $always = false): array
    {
        $out = ['ok' => false, 'reason' => null, 'type' => null, 'bytes_before' => (int) @filesize($source),
            'bytes_after' => 0, 'width' => 0, 'height' => 0, 'resized' => false];

        if (self::unavailableReason() !== null) {
            return ['reason' => 'unavailable'] + $out;
        }

        $type = self::sniff($source);
        $out['type'] = $type;

        if ($type !== self::TYPE_JPEG && $type !== self::TYPE_PNG) {
            return ['reason' => 'not_jpeg_or_png'] + $out;
        }

        $size = self::dimensions($source);

        if ($size === null) {
            return ['reason' => 'unreadable'] + $out;
        }

        [$w, $h] = $size;

        if (self::isBomb($w, $h)) {
            return ['reason' => 'too_many_pixels'] + $out;
        }

        if (! self::memoryFor($w, $h)) {
            return ['reason' => 'not_enough_memory'] + $out;
        }

        $image = $type === self::TYPE_JPEG ? @imagecreatefromjpeg($source) : @imagecreatefrompng($source);

        if (! $image instanceof \GdImage) {
            return ['reason' => 'could_not_decode'] + $out;
        }

        try {
            if ($type === self::TYPE_PNG) {
                if (! imageistruecolor($image)) {
                    imagepalettetotruecolor($image);
                }
                imagealphablending($image, false);
                imagesavealpha($image, true);
            } else {
                $image = self::orient($image, self::jpegOrientation($source));
            }

            $image = self::fit($image, $maxWidth, $out['resized']);
            $out['width'] = imagesx($image);
            $out['height'] = imagesy($image);

            $quality = max(1, min(100, $quality));

            if ($destination === null) {
                ob_start();
                $encoded = @imagewebp($image, null, $quality);
                $bytes = (string) ob_get_clean();

                if (! $encoded || $bytes === '') {
                    return ['reason' => 'encode_failed'] + $out;
                }

                $out['bytes_after'] = strlen($bytes);
            } else {
                $temporary = dirname($destination).'/.'.bin2hex(random_bytes(6)).'.webp.part';

                if (! @imagewebp($image, $temporary, $quality) || ! is_file($temporary)) {
                    @unlink($temporary);

                    return ['reason' => 'encode_failed'] + $out;
                }

                clearstatcache(true, $temporary);
                $out['bytes_after'] = (int) filesize($temporary);

                if ($always && $out['bytes_after'] >= $out['bytes_before']) {
                    $tries = $out['type'] === self::TYPE_PNG && defined('IMG_WEBP_LOSSLESS')
                        ? [IMG_WEBP_LOSSLESS, max(50, $quality - 12)]
                        : [max(50, $quality - 12), max(50, $quality - 24)];

                    foreach (array_unique($tries) as $q) {
                        $alt = dirname($destination).'/.'.bin2hex(random_bytes(6)).'.webp.part';

                        if (@imagewebp($image, $alt, $q) && is_file($alt)) {
                            clearstatcache(true, $alt);
                            $size = (int) filesize($alt);

                            if ($size > 0 && $size < $out['bytes_after']) {
                                @unlink($temporary);
                                $temporary = $alt;
                                $out['bytes_after'] = $size;
                                continue;
                            }
                        }

                        @unlink($alt);
                    }
                }

                if (! $always && $out['bytes_after'] >= $out['bytes_before']) {
                    @unlink($temporary);

                    return ['reason' => 'webp_not_smaller'] + $out;
                }

                @chmod($temporary, 0644);

                if (! @rename($temporary, $destination)) {
                    @unlink($temporary);

                    return ['reason' => 'write_failed'] + $out;
                }
            }
        } finally {
            imagedestroy($image);
        }

        if ($out['bytes_after'] >= $out['bytes_before']) {
            return ['reason' => 'webp_not_smaller'] + $out;
        }

        return ['ok' => true] + $out;
    }

    /**
     * A free `<stem>.webp` beside the source, reserved so nothing else can take
     * it. `photo.jpg` -> `photo.webp`; if that exists, `photo-1.webp`, and so
     * on. The reservation is an exclusive create (`x`), so two conversions can
     * never be handed the same name and an existing file is never overwritten.
     *
     * Returns the absolute path, or null if no name could be reserved.
     */
    public static function reserveName(string $source): ?string
    {
        $dir = dirname($source);
        $stem = pathinfo($source, PATHINFO_FILENAME);

        for ($n = 0; $n < 1000; $n++) {
            $candidate = $dir.'/'.$stem.($n === 0 ? '' : '-'.$n).'.webp';

            if (file_exists($candidate)) {
                continue;
            }

            $fh = @fopen($candidate, 'x');

            if ($fh !== false) {
                fclose($fh);

                return $candidate;
            }
        }

        return null;
    }

    /**
     * The EXIF Orientation tag of a JPEG, 1 to 8, read straight from the APP1
     * segment. 1 when absent or unreadable. No exif extension needed.
     */
    public static function jpegOrientation(string $file): int
    {
        $fh = @fopen($file, 'rb');

        if ($fh === false) {
            return 1;
        }

        try {
            if (fread($fh, 2) !== "\xFF\xD8") {
                return 1;
            }

            for ($i = 0; $i < 32; $i++) {
                $marker = fread($fh, 4);

                if ($marker === false || strlen($marker) < 4 || $marker[0] !== "\xFF") {
                    return 1;
                }

                $code = ord($marker[1]);
                $length = unpack('n', substr($marker, 2, 2))[1];

                // Start of scan or end of image: no more metadata segments.
                if ($code === 0xDA || $code === 0xD9 || $length < 2) {
                    return 1;
                }

                $data = $length > 2 ? (string) fread($fh, $length - 2) : '';

                if ($code === 0xE1 && str_starts_with($data, "Exif\0\0")) {
                    return self::tiffOrientation(substr($data, 6));
                }
            }
        } finally {
            fclose($fh);
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $order = substr($tiff, 0, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }

        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';

        $ifd = unpack($long, substr($tiff, 4, 4))[1];

        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $count = unpack($short, substr($tiff, $ifd, 2))[1];

        for ($i = 0; $i < min($count, 256); $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }

            if (unpack($short, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * Turn the pixels so the picture stands the way the camera's tag says.
     * imagerotate() turns counter-clockwise, so "rotate 90 clockwise" is -90.
     */
    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        $rotate = static function (\GdImage $img, int $degrees): \GdImage {
            $turned = imagerotate($img, $degrees, 0);

            if ($turned instanceof \GdImage) {
                imagedestroy($img);

                return $turned;
            }

            return $img;
        };

        switch ($orientation) {
            case 2: imageflip($image, IMG_FLIP_HORIZONTAL); break;
            case 3: $image = $rotate($image, 180); break;
            case 4: imageflip($image, IMG_FLIP_VERTICAL); break;
            case 5: $image = $rotate($image, -90); imageflip($image, IMG_FLIP_HORIZONTAL); break;
            case 6: $image = $rotate($image, -90); break;
            case 7: $image = $rotate($image, -90); imageflip($image, IMG_FLIP_VERTICAL); break;
            case 8: $image = $rotate($image, 90); break;
        }

        return $image;
    }

    /** Shrink to $maxWidth if wider. Never enlarges. */
    private static function fit(\GdImage $image, int $maxWidth, bool &$resized): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        if ($maxWidth < 1 || $w <= $maxWidth) {
            return $image;
        }

        $nh = max(1, (int) round($h * ($maxWidth / $w)));
        $out = imagecreatetruecolor($maxWidth, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
        imagedestroy($image);
        $resized = true;

        return $out;
    }

    /** Is there memory left in this PHP for a decode of this size? */
    private static function memoryFor(int $w, int $h): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));

        if ($limit <= 0) {
            return true;
        }

        $needed = $w * $h * self::BYTES_PER_PIXEL;

        return memory_get_usage(true) + $needed < $limit;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $n = (int) $value;

        return match ($unit) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
