<?php
/* Give the running preview REAL banner pictures at the shipped desktop size,
   so the homepage renders the image banner rather than falling back to the
   hero. 1920x550 is the default this release moves slider_ratio to. */

$dir = public_path('uploads/banners');
@mkdir($dir, 0775, true);

$palette = [[0xE8,0x91,0x9F],[0xA8,0xD5,0xCB],[0xF2,0xD7,0x8E]];
$alt = ['Autumn glass-skin edit', 'Barrier repair, gently', 'Every-day SPF'];

$set = \App\Models\BannerSet::create([
    'name' => 'Homepage banner', 'slug' => 'homepage-banner',
    'status' => 'publish', 'position' => 0,
]);

foreach ($alt as $i => $label) {
    $file = $dir.'/int330-banner-'.($i + 1).'.png';
    $img = imagecreatetruecolor(1920, 550);
    [$r, $g, $b] = $palette[$i];
    imagefilledrectangle($img, 0, 0, 1920, 550, imagecolorallocate($img, $r, $g, $b));
    /* Something for object-fit:cover to crop, so the phone crop is visible as a
       crop rather than as a flat swatch. */
    imagefilledellipse($img, 520, 200, 900, 760, imagecolorallocatealpha($img, 255,255,255, 96));
    imagefilledellipse($img, 1500, 430, 620, 620, imagecolorallocatealpha($img, 255,255,255, 112));
    $ink = imagecolorallocate($img, 60, 30, 40);
    imagestring($img, 5, 90, 250, strtoupper($label), $ink);
    imagepng($img, $file);
    imagedestroy($img);

    $path = 'uploads/banners/'.basename($file);
    \App\Support\MediaRegistrar::record($path);

    \App\Models\BannerCard::create([
        'banner_set_id' => $set->id, 'image' => $path, 'alt' => $label,
        'heading' => '', 'body' => '', 'button_label' => '', 'button_url' => '/shop/',
        'image_w' => 1920, 'image_h' => 550, 'position' => $i + 1, 'status' => 'publish',
    ]);
}
echo "seeded set {$set->id} with 3 pictures\n";
