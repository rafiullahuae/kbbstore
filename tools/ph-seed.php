<?php
/*
 * Seed the Lane PH preview: Lane SS's Super Sale catalogue and banner
 * placeholders, plus one PLACEHOLDER header picture in the Media Library so the
 * "Edit header" panel has something to choose. Preview database only; nothing
 * here reaches a package.
 */
require __DIR__.'/ss-seed.php';

use App\Models\Media;

@mkdir(public_path('uploads/ph'), 0755, true);
foreach (['wide' => [1600, 500], 'tall' => [900, 600]] as $name => [$w, $h]) {
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $c = imagecolorallocate($im, 250 - (int) (30 * $y / $h), 214 - (int) (70 * $y / $h), 226 - (int) (40 * $y / $h));
        imageline($im, 0, $y, $w, $y, $c);
    }
    $ink = imagecolorallocate($im, 120, 30, 70);
    imagestring($im, 5, 30, 30, 'PLACEHOLDER HEADER PICTURE ('.$name.') '.$w.' x '.$h, $ink);
    $rel = 'uploads/ph/header-'.$name.'.png';
    imagepng($im, public_path($rel));
    imagedestroy($im);
    Media::query()->updateOrCreate(['path' => $rel], ['filename' => basename($rel), 'mime' => 'image/png', 'width' => $w, 'height' => $h, 'alt' => '']);
}
echo "ph placeholders done\n";
