<?php
/*
 * Seed for the Lane QC preview: Lane PY's whole seed (tools/py-seed.php --
 * the busy and light banners, the option-sheet categories, Arabic on), plus
 * two categories whose phone and laptop choices DIFFER, for the split shots:
 *
 *   /collections/qc-split-pic/   a banner: phone A + 2 at the start,
 *                                laptop B + 3 centred
 *   /collections/qc-split-box/   no banner: the same split on the light box
 *
 * Both are real per-category, per-device overrides (`header_style`), saved in
 * the shape Catalog -> Categories -> Edit -> Category header saves.
 *
 * And a 2400 x 600 banner file at uploads/qc/banner-2400x600.jpg -- the size
 * the editor recommends -- that the shots upload through the editor's
 * "Upload banner" button.
 */

use App\Models\Category;

require __DIR__.'/py-seed.php';

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();

$split = [
    'box_phone' => 'blush', 'treatment_phone' => 'fade', 'align_phone' => 'start',
    'box_desktop' => 'cream', 'treatment_desktop' => 'frost', 'align_desktop' => 'center',
];

$short = 'Lightweight Korean sunscreens with high UV protection, made for everyday wear under the UAE sun.';

foreach (['qc-split-pic' => '/uploads/py/sunscreens-banner.jpg', 'qc-split-box' => null] as $slug => $image) {
    $c = Category::updateOrCreate(['slug' => $slug], [
        'name' => 'Sunscreens', 'parent_id' => null, 'description' => $short,
        'header_image' => $image, 'header_style' => $split,
    ]);
    $c->forceFill(['path' => $slug, 'depth' => 0])->save();
}

/* A banner at the recommended size: a busy picture with a product row in the
   MIDDLE half (what a phone keeps) and a calm left third (where Start words sit). */
$w = 2400; $h = 600;
$im = imagecreatetruecolor($w, $h);
for ($x = 0; $x < $w; $x++) {
    $t = $x / ($w - 1);
    imageline($im, $x, 0, $x, $h, imagecolorallocate($im, (int) (240 - 40 * $t), (int) (196 - 70 * $t), (int) (180 - 20 * $t)));
}
$ink = imagecolorallocate($im, 92, 40, 58);
$cap = imagecolorallocate($im, 250, 240, 236);
foreach ([960, 1110, 1260, 1410] as $i => $x) {
    $bh = 300 - $i * 30;
    imagefilledrectangle($im, $x, $h - 80 - $bh, $x + 100, $h - 80, $ink);
    imagefilledrectangle($im, $x + 25, $h - 120 - $bh, $x + 75, $h - 80 - $bh, $cap);
}
foreach ([[1700, 160, 220], [2100, 420, 260], [300, 480, 180]] as [$cx, $cy, $d]) {
    imagefilledellipse($im, $cx, $cy, $d, $d, imagecolorallocatealpha($im, 255, 255, 255, 70));
}
imagestring($im, 5, 1120, 40, 'MIDDLE HALF: WHAT A PHONE KEEPS', $ink);
@mkdir($root.'/uploads/qc', 0775, true);
imagejpeg($im, $root.'/uploads/qc/banner-2400x600.jpg', 88);

echo 'qc seed: split categories /collections/qc-split-pic/ and /collections/qc-split-box/, banner uploads/qc/banner-2400x600.jpg'."\n";
