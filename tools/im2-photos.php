<?php
/*
 * Photographs for the Lane IM2 measurement, written straight into the
 * preview's web root.
 *
 * SYNTHETIC BUT NOISY, for the reason tools/im-photos.php states and this lane
 * inherits: the number reported is a BYTE count, and bytes come from entropy.
 * A flat gradient compresses to nothing and would make every "before" figure a
 * fraction of what a real product shot costs. Per-pixel noise over a soft wash
 * is a worst case for JPEG and therefore honest-to-pessimistic.
 *
 * WHAT THIS ADDS TO ROUND ONE'S SCRIPT, and why each is load-bearing:
 *
 *   MIXED ASPECT RATIOS. The owner's ask is a SQUARE strip, and the whole
 *   question of whether a square derivative is worth generating turns on what
 *   a non-square photograph costs. A harness of 1000x1000 shots cannot answer
 *   it: every width-based variant of a square original is already square, so
 *   the measurement would read "the square crop saves nothing" for a reason
 *   that has nothing to do with the catalogue. So the gallery carries one
 *   portrait (9:16), one landscape (16:9), one 3:4 and two squares -- the pair
 *   the coordinator asked for, in one strip.
 *
 *   REVIEW PHOTOGRAPHS. Separate files under /uploads/reviews/, because the
 *   point of the review half of this lane is that the batch never reached
 *   them. Sharing a file with the gallery would let a gallery copy stand in
 *   for a review one and hide exactly the defect being measured.
 *
 *   php tools/im2-photos.php <web-root>
 */
$root = rtrim((string) ($argv[1] ?? ''), '/');

if ($root === '' || ! is_dir($root)) {
    fwrite(STDERR, "usage: php tools/im2-photos.php <web-root>\n");
    exit(1);
}

@mkdir($root.'/uploads/im2', 0755, true);
@mkdir($root.'/uploads/reviews', 0755, true);

mt_srand(20260929);

/** A noisy photograph of the given pixel size. */
function im2_photo(int $w, int $h, int $hue, string $file): void
{
    $im = imagecreatetruecolor($w, $h);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $base = 150 + (int) (60 * sin(($x + $y) / 90.0));
            imagesetpixel($im, $x, $y, (int) imagecolorallocate(
                $im,
                max(0, min(255, $base + $hue + mt_rand(-26, 26))),
                max(0, min(255, $base + mt_rand(-26, 26))),
                max(0, min(255, $base + (200 - $hue) + mt_rand(-26, 26)))
            ));
        }
    }

    imagejpeg($im, $file, 85);
    imagedestroy($im);
}

/*
 * THE GALLERY. Shot 1 is square because it is also the main frame and the
 * product card's tile, and a catalogue's featured image is overwhelmingly
 * square on this shop. Shots 2 and 3 are the pair that makes the square-crop
 * question answerable at all.
 */
$gallery = [
    ['shot-1.jpg', 1000, 1000,  20],   // square
    ['shot-2.jpg', 1000, 1778,  70],   // PORTRAIT 9:16 -- taller than wide
    ['shot-3.jpg', 1778, 1000, 120],   // LANDSCAPE 16:9 -- wider than tall
    ['shot-4.jpg', 1000, 1333, 160],   // portrait 3:4
    ['shot-5.jpg', 1000, 1000, 190],   // square
];

foreach ($gallery as [$name, $w, $h, $hue]) {
    im2_photo($w, $h, $hue, $root.'/uploads/im2/'.$name);
}

/*
 * THE REVIEW PHOTOGRAPHS. A shopper's phone, which is where these come from:
 * 9:16 portrait at handset resolution, plus one landscape because a review
 * photograph is the least controlled image on this site and a batch that only
 * works on portraits is not a fix.
 */
$reviews = [
    ['rev-1.jpg', 1080, 1920,  40],
    ['rev-2.jpg', 1080, 1920,  90],
    ['rev-3.jpg', 1920, 1080, 140],
    ['rev-4.jpg', 1200, 1200, 180],
    ['rev-5.jpg', 1080, 1440,  10],
    ['rev-6.jpg', 1080, 1920, 110],
];

foreach ($reviews as [$name, $w, $h, $hue]) {
    im2_photo($w, $h, $hue, $root.'/uploads/reviews/'.$name);
}

/*
 * THE UNSIZED PRODUCT'S PHOTOGRAPHS. Same shapes, different files, so the
 * fallback is photographed on a gallery and a review that have genuinely never
 * been through the batch rather than on the absence of a copy of a file whose
 * copy exists elsewhere.
 */
$cold = [
    ['cold-1.jpg', 1000, 1000,  30],
    ['cold-2.jpg', 1000, 1778,  80],
    ['cold-3.jpg', 1778, 1000, 130],
];

foreach ($cold as [$name, $w, $h, $hue]) {
    im2_photo($w, $h, $hue, $root.'/uploads/im2/'.$name);
}

im2_photo(1080, 1920, 60, $root.'/uploads/reviews/cold-rev.jpg');

/*
 * A REALISTIC MEDIA LIBRARY: 36 DISTINCT files.
 *
 * Distinct matters more than it looks. Thirty-six tiles pointing at one URL
 * would measure the browser's cache and report a number a tenth of the truth.
 * Generating 36 photographs a pixel at a time would take a minute, so one large
 * noisy canvas is built once and 36 different regions of it are cropped out --
 * every file has its own bytes, and the entropy is the same worst-case JPEG
 * noise the rest of this script uses.
 */
@mkdir($root.'/uploads/im2/lib', 0755, true);

$canvasSide = 2400;
$canvas = imagecreatetruecolor($canvasSide, $canvasSide);

for ($y = 0; $y < $canvasSide; $y++) {
    for ($x = 0; $x < $canvasSide; $x++) {
        $base = 150 + (int) (60 * sin(($x + $y) / 90.0));
        imagesetpixel($canvas, $x, $y, (int) imagecolorallocate(
            $canvas,
            max(0, min(255, $base + mt_rand(-30, 30))),
            max(0, min(255, $base + (int) ($x / 12) % 90 + mt_rand(-30, 30))),
            max(0, min(255, $base + (int) ($y / 12) % 90 + mt_rand(-30, 30)))
        ));
    }
}

$tile = 800;

for ($n = 0; $n < 36; $n++) {
    $sx = (int) (($n % 6) * (($canvasSide - $tile) / 5));
    $sy = (int) ((int) ($n / 6) * (($canvasSide - $tile) / 5));

    $out = imagecreatetruecolor($tile, $tile);
    imagecopy($out, $canvas, 0, 0, $sx, $sy, $tile, $tile);
    imagejpeg($out, sprintf('%s/uploads/im2/lib/file-%02d.jpg', $root, $n + 1), 85);
    imagedestroy($out);
}

imagedestroy($canvas);

$libBytes = 0;

foreach (glob($root.'/uploads/im2/lib/*.jpg') ?: [] as $f) {
    $libBytes += filesize($f);
}

printf("%-30s %5d files %8.1f KB total\n", 'im2/lib', count(glob($root.'/uploads/im2/lib/*.jpg') ?: []), $libBytes / 1024);

foreach (['im2', 'reviews'] as $folder) {
    foreach (glob($root.'/uploads/'.$folder.'/*.jpg') ?: [] as $f) {
        $size = getimagesize($f);
        printf("%-30s %5dx%-5d %8.1f KB\n", $folder.'/'.basename($f), $size[0], $size[1], filesize($f) / 1024);
    }
}
