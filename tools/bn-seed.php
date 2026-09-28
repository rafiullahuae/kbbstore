<?php
/* Seed the Lane BN preview: an owner to sign in as, and one published cards
   banner with six cards and real pictures on disk.

   THE PICTURES ARE WRITTEN HERE RATHER THAN COMMITTED. A screenshot of a
   carousel with no images in it proves nothing, and six binaries in the repo to
   prove a layout is six binaries somebody has to review. GD draws them, into
   the preview's own web root, and they go through MediaRegistrar exactly as an
   uploaded one does — so the preview also demonstrates the Media Library
   registration and the width/height the LCP image needs. */

\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

$dir = public_path('uploads/banners');
@mkdir($dir, 0775, true);

/* The shop's own palette, so the pictures read as this shop's rather than as
   test cards. Portrait 900x1200, which is the 3/4 the set ships at. */
$palette = [
    [0xE8, 0x91, 0x9F], [0xF4, 0xC4, 0xB8], [0xC9, 0xA7, 0xD6],
    [0xA8, 0xD5, 0xCB], [0xF2, 0xD7, 0x8E], [0xB9, 0xC9, 0xEE],
];

$copy = [
    ['Glass skin set', 'Cleanse, essence, serum', 'Shop set'],
    ['Barrier repair', 'For skin that stings', 'Shop now'],
    ['SPF every day', 'Weightless, no white cast', 'See all'],
    ['Overnight masks', 'Wake up plump', 'Shop masks'],
    ['Gentle actives', 'Retinal, slowly', 'Explore'],
    ['Under AED 99', 'Small sizes, real formulas', 'Browse'],
];

$set = \App\Models\BannerSet::create([
    'name' => 'Autumn edit',
    'slug' => 'autumn-edit',
    'status' => 'publish',
    'position' => 0,
]);

foreach ($copy as $i => [$heading, $body, $label]) {
    $file = $dir.'/bn-preview-'.($i + 1).'.png';

    $img = imagecreatetruecolor(900, 1200);
    [$r, $g, $b] = $palette[$i];
    imagefilledrectangle($img, 0, 0, 900, 1200, imagecolorallocate($img, $r, $g, $b));
    // A soft highlight, so object-fit:cover has something to crop and the card
    // is visibly a photograph's box rather than a flat swatch.
    imagefilledellipse($img, 300, 340, 620, 620, imagecolorallocatealpha($img, 255, 255, 255, 96));
    imagefilledellipse($img, 700, 980, 420, 420, imagecolorallocatealpha($img, 255, 255, 255, 112));
    imagepng($img, $file);
    imagedestroy($img);

    $path = 'uploads/banners/'.basename($file);

    \App\Support\MediaRegistrar::record($path);

    \App\Models\BannerCard::create([
        'banner_set_id' => $set->id,
        'image' => $path,
        'alt' => $heading,
        'heading' => $heading,
        'body' => $body,
        'button_label' => $label,
        'button_url' => '/shop/',
        'image_w' => 900,
        'image_h' => 1200,
        'position' => $i + 1,
        'status' => 'publish',
    ]);
}

/* Switched ON and pointed at the set, because the shots have to show the row.
   The SHIPPED state is the opposite of this and CardsBannerShipsOffTest is
   where that is proved; a preview that shipped off would photograph nothing. */
/* Media::urlFor() builds an image URL from the `site_url` SETTING, falling back
   to config('app.url') — which in this throwaway copy is http://localhost, so
   every picture 404s and the screenshots show six alt strings. Pointed at the
   preview's own origin here, which is what the harness exports. */
$settings = app(\App\Services\SettingsService::class);
$settings->set('site_url', getenv('BN_SITE_URL') ?: 'http://127.0.0.1:8973');

$settings->setModule('cards_banner', true);
$settings->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $set->id);

echo "seeded owner + set {$set->id} with 6 cards\n";
