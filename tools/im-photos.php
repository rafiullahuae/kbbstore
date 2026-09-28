<?php
/*
 * Real photographs for the Lane IM measurement, written straight into the
 * preview's web root.
 *
 * SYNTHETIC BUT NOISY, ON PURPOSE. The number this lane reports is a BYTE
 * count, and bytes come from entropy: a flat gradient compresses to nothing and
 * would make every "before" figure a fraction of what a product shot really
 * costs. These are per-pixel noise over a soft wash, which is a worst case for
 * JPEG and therefore an honest-to-pessimistic stand-in for a 1000x1000 K-beauty
 * bottle on white. The ImageVariants docblock measured its own numbers the same
 * way and for the same reason.
 *
 *   php tools/im-photos.php <web-root> <count>
 */
$root = rtrim((string) ($argv[1] ?? ''), '/');
$count = max(1, (int) ($argv[2] ?? 6));

if ($root === '' || ! is_dir($root)) {
    fwrite(STDERR, "usage: php tools/im-photos.php <web-root> [count]\n");
    exit(1);
}

@mkdir($root.'/uploads/im', 0755, true);

mt_srand(20260928);

for ($n = 1; $n <= $count; $n++) {
    $w = 1000;
    $im = imagecreatetruecolor($w, $w);

    // A wash so the thumbnails are visibly different from one another in a
    // screenshot, and noise on top so the file is a real size.
    $hue = ($n * 47) % 200;

    for ($y = 0; $y < $w; $y++) {
        for ($x = 0; $x < $w; $x += 1) {
            $base = 150 + (int) (60 * sin(($x + $y) / 90.0));
            imagesetpixel($im, $x, $y, (int) imagecolorallocate(
                $im,
                max(0, min(255, $base + $hue + mt_rand(-26, 26))),
                max(0, min(255, $base + mt_rand(-26, 26))),
                max(0, min(255, $base + (200 - $hue) + mt_rand(-26, 26)))
            ));
        }
    }

    imagejpeg($im, $root.'/uploads/im/shot-'.$n.'.jpg', 85);
    imagedestroy($im);
}

foreach (glob($root.'/uploads/im/*.jpg') ?: [] as $f) {
    printf("%-28s %7.1f KB\n", basename($f), filesize($f) / 1024);
}
