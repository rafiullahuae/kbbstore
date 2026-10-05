<?php
/*
 * Seed the Lane CH preview (the category page's "Edit header" panel): Lane RF's
 * catalogue, an owner account, and a "Sunscreens" category holding every
 * product, with a header picture -- plus a phone picture and a promo banner in
 * the Media Library for the panel to pick. Pictures are drawn here with GD, so
 * the screenshots never show a broken image.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
require __DIR__.'/rf-seed.php';

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$draw = function (string $rel, int $w, int $h, array $from, array $to, string $label) {
    @mkdir(dirname(public_path($rel)), 0755, true);
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x++) {
        $t = $x / max(1, $w - 1);
        $c = imagecolorallocate($im, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
        imageline($im, $x, 0, $x, $h, $c);
    }
    $white = imagecolorallocatealpha($im, 255, 255, 255, 60);
    for ($i = 0; $i < 6; $i++) {
        imagefilledellipse($im, (int) ($w * (0.12 + $i * 0.16)), (int) ($h * ($i % 2 ? 0.3 : 0.7)), (int) ($h * 0.5), (int) ($h * 0.5), $white);
    }
    imagestring($im, 5, 16, 12, $label, imagecolorallocate($im, 255, 255, 255));
    imagejpeg($im, public_path($rel), 85);
    imagedestroy($im);

    \App\Models\Media::updateOrCreate(['path' => $rel], ['filename' => basename($rel), 'mime' => 'image/jpeg', 'size' => filesize(public_path($rel)), 'width' => $w, 'height' => $h, 'alt' => '']);

    return \App\Models\Media::urlFor($rel);
};

$hdr = $draw('uploads/categories/ch-sun-header.jpg', 2400, 600, [236, 164, 120], [190, 70, 120], 'Sunscreens header 2400x600');
$draw('uploads/categories/ch-sun-phone.jpg', 1200, 800, [120, 170, 220], [60, 90, 170], 'Sunscreens phone 1200x800');
$draw('uploads/categories/ch-sun-banner.jpg', 2400, 500, [250, 210, 120], [230, 110, 70], 'Summer SPF banner 2400x500');

$cat = \App\Models\Category::query()->firstOrCreate(['slug' => 'sunscreens'], [
    'name' => 'Sunscreens', 'parent_id' => null,
    'description' => '<p>Light Korean sunscreens for everyday wear in the UAE sun.</p>',
]);
$cat->forceFill(['path' => 'sunscreens', 'depth' => 0, 'header_image' => $hdr])->save();
$cat->products()->syncWithoutDetaching(\App\Models\Product::query()->pluck('id')->all());

echo "ch seed done: category {$cat->id}\n";
