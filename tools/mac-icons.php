<?php

declare(strict_types=1);

/*
 * Lane MAC: draw the owner app's icons from the shop's logo — the "KB" of the
 * K-Beauty Bliss wordmark, white on the Petal rose (#A8475C), the same mark
 * the app's sign-in screen shows. The shop's logo is set in type, not an
 * image, so the icon is set in type too.
 *
 *     php tools/mac-icons.php
 *
 * Writes resources/owner-app/icons/{icon-192,icon-512,maskable-512,apple-180,badge-96}.png,
 * which vite.config.js lists as inputs so they are hashed into public/build.
 * GD only; run once, commit the PNGs.
 */

$root = dirname(__DIR__);
$out = $root.'/resources/owner-app/icons';
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
@mkdir($out, 0775, true);

function oa_icon(string $file, int $size, string $font, bool $maskable, bool $badge = false): void
{
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefill($im, 0, 0, $clear);

    $pink = imagecolorallocate($im, 0xA8, 0x47, 0x5C);   // Petal rose (--acc)
    $white = imagecolorallocate($im, 255, 255, 255);

    if ($badge) {
        // Android's status-bar badge: a white silhouette on transparent.
        $ink = $white;
    } else {
        $ink = $white;
        if ($maskable) {
            imagefilledrectangle($im, 0, 0, $size, $size, $pink);
        } else {
            // A rounded square, radius 22%.
            $r = (int) round($size * 0.22);
            imagefilledrectangle($im, $r, 0, $size - $r - 1, $size - 1, $pink);
            imagefilledrectangle($im, 0, $r, $size - 1, $size - $r - 1, $pink);
            foreach ([[$r, $r], [$size - $r - 1, $r], [$r, $size - $r - 1], [$size - $r - 1, $size - $r - 1]] as [$cx, $cy]) {
                imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $pink);
            }
        }
    }

    // Maskable icons keep their mark inside the 80% safe zone.
    $text = 'KB';
    $pt = $size * ($maskable ? 0.30 : ($badge ? 0.44 : 0.38));
    $box = imagettfbbox($pt, 0, $font, $text);
    $w = $box[2] - $box[0];
    $h = $box[1] - $box[7];
    $x = (int) round(($size - $w) / 2 - $box[0]);
    $y = (int) round(($size + $h) / 2 - $box[1]);
    imagettftext($im, $pt, 0, $x, $y, $ink, $font, $text);

    imagepng($im, $file, 9);
    imagedestroy($im);
}

oa_icon($out.'/icon-192.png', 192, $font, false);
oa_icon($out.'/icon-512.png', 512, $font, false);
oa_icon($out.'/maskable-512.png', 512, $font, true);
oa_icon($out.'/apple-180.png', 180, $font, true);   // iOS rounds the corners itself
oa_icon($out.'/badge-96.png', 96, $font, false, true);

foreach (glob($out.'/*.png') as $f) {
    echo basename($f), ' ', filesize($f), " bytes\n";
}
