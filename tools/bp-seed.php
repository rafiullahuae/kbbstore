<?php
/* Seed the Lane BP preview — round 7 of the cards banner.
 *
 * FOUR SETS, because this round's four visible changes cannot be photographed
 * on one: a background is either none, a colour or a picture, and a title is
 * either below the card or over it.
 *
 *   1  Autumn edit       what shipped: no background, title below. The set the
 *                        homepage points at to begin with, and the one the
 *                        "nothing moved" shots are taken of.
 *   2  Clearance         a colour behind the row, and the button in the shop's
 *                        green rather than its pink.
 *   3  Over the picture   title_pos = over, with the cards alternating a PALE
 *                        photograph and a DARK one — the scrim has to hold both
 *                        or the variant is unusable, and a screenshot of it
 *                        over one kind of picture proves nothing.
 *   4  Picture behind     a photograph behind the whole row.
 *
 * THE PICTURES ARE WRITTEN HERE RATHER THAN COMMITTED, for the reason
 * tools/bn-seed.php gives: six binaries in the repo to prove a layout is six
 * binaries somebody has to review. GD draws them into the preview's own web
 * root and they go through MediaRegistrar exactly as an uploaded one does.
 */

\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

$dir = public_path('uploads/banners');
@mkdir($dir, 0775, true);

/** Draw one 900x1200 card picture and register it. */
$picture = function (string $name, array $rgb, bool $dark = false) use ($dir): string {
    $file = $dir.'/'.$name.'.png';
    $img = imagecreatetruecolor(900, 1200);
    [$r, $g, $b] = $rgb;
    imagefilledrectangle($img, 0, 0, 900, 1200, imagecolorallocate($img, $r, $g, $b));
    $veil = $dark ? imagecolorallocatealpha($img, 255, 255, 255, 110) : imagecolorallocatealpha($img, 255, 255, 255, 92);
    imagefilledellipse($img, 300, 340, 620, 620, $veil);
    imagefilledellipse($img, 700, 980, 420, 420, $veil);
    /* A band of detail across the bottom third, which is where the overlay
       title lands: a flat swatch would make the scrim look better than it is. */
    for ($i = 0; $i < 26; $i++) {
        $shade = $dark
            ? imagecolorallocate($img, min(255, $r + $i * 3), min(255, $g + $i * 3), min(255, $b + $i * 3))
            : imagecolorallocate($img, max(0, $r - $i * 2), max(0, $g - $i * 2), max(0, $b - $i * 2));
        imagefilledrectangle($img, $i * 36, 860 + ($i % 5) * 40, $i * 36 + 30, 1200, $shade);
    }
    imagepng($img, $file);
    imagedestroy($img);

    $path = 'uploads/banners/'.basename($file);
    \App\Support\MediaRegistrar::record($path);

    return $path;
};

$make = function (array $attributes, array $cards) use ($picture): \App\Models\BannerSet {
    $set = \App\Models\BannerSet::create($attributes);

    foreach ($cards as $i => [$heading, $body, $label, $name, $rgb, $dark]) {
        \App\Models\BannerCard::create([
            'banner_set_id' => $set->id,
            'image' => $picture($name, $rgb, $dark),
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

    return $set->refresh();
};

$pale = [0xE8, 0x91, 0x9F];
$copy = [
    ['Glass skin set', 'Cleanse, essence, serum', 'Shop set', 'bp-1', [0xE8, 0x91, 0x9F], false],
    ['Barrier repair', 'For skin that stings', 'Shop now', 'bp-2', [0xF4, 0xC4, 0xB8], false],
    ['SPF every day', 'Weightless, no white cast', 'See all', 'bp-3', [0xC9, 0xA7, 0xD6], false],
    ['Overnight masks', 'Wake up plump', 'Shop masks', 'bp-4', [0xA8, 0xD5, 0xCB], false],
    ['Gentle actives', 'Retinal, slowly', 'Explore', 'bp-5', [0xF2, 0xD7, 0x8E], false],
    ['Under AED 99', 'Small sizes, real formulas', 'Browse', 'bp-6', [0xB9, 0xC9, 0xEE], false],
];

/* 1 — exactly what shipped, plus the dots and the arrows switched on so both
   can be photographed. Every appearance column is at its shipped value. */
$shipped = $make([
    'name' => 'Autumn edit', 'slug' => 'autumn-edit', 'status' => 'publish', 'position' => 0,
    'show_dots' => true, 'show_arrows' => true,
], $copy);

/* 2 — a colour behind the row, and the shop's green on the button. */
$colour = $make([
    'name' => 'Clearance', 'slug' => 'clearance', 'status' => 'publish', 'position' => 1,
    'show_dots' => true, 'show_arrows' => true,
    'bg_mode' => 'color', 'bg_color' => '#fff0f4',
    'btn_bg' => '#2e9e6b', 'btn_text' => '#ffffff', 'btn_hover' => '#1f7a4f',
], $copy);

/* 3 — the title ON the picture, over a PALE photograph and a DARK one
   alternately. The scrim is the whole question and one kind of picture would
   answer half of it. */
$over = $make([
    'name' => 'Over the picture', 'slug' => 'over-the-picture', 'status' => 'publish', 'position' => 2,
    'show_dots' => true, 'title_pos' => 'over',
], [
    ['Very pale photograph', 'White on almost white', 'Shop', 'bp-light-1', [0xF7, 0xF4, 0xF2], false],
    ['Very dark photograph', 'White on almost black', 'Shop', 'bp-dark-1', [0x22, 0x1C, 0x22], true],
    ['Pale again', 'The same scrim', 'Shop', 'bp-light-2', [0xFA, 0xF0, 0xE8], false],
    ['Dark again', 'The same scrim', 'Shop', 'bp-dark-2', [0x1A, 0x22, 0x2E], true],
    ['Mid tone', 'Neither extreme', 'Shop', 'bp-mid-1', [0x9A, 0x7E, 0x8C], false],
    ['Mid tone again', 'Neither extreme', 'Shop', 'bp-mid-2', [0x7E, 0x9A, 0x8C], false],
]);

/* 4 — a photograph behind the whole row. */
$imageBg = $make([
    'name' => 'Picture behind', 'slug' => 'picture-behind', 'status' => 'publish', 'position' => 3,
    'show_dots' => true,
    'bg_mode' => 'image', 'bg_image' => 'uploads/banners/bp-bg.png',
], $copy);

/* The background picture itself: a wide, soft wash. */
$bg = imagecreatetruecolor(1600, 500);
for ($x = 0; $x < 1600; $x++) {
    $t = $x / 1600;
    imagefilledrectangle($bg, $x, 0, $x + 1, 500, imagecolorallocate(
        $bg,
        (int) (0x2A + $t * 0x50),
        (int) (0x22 + $t * 0x20),
        (int) (0x28 + $t * 0x40),
    ));
}
imagepng($bg, $dir.'/bp-bg.png');
imagedestroy($bg);
\App\Support\MediaRegistrar::record('uploads/banners/bp-bg.png');

/* Media::urlFor() builds an image URL from the `site_url` SETTING, falling back
   to config('app.url') — which in this throwaway copy is http://localhost, so
   every picture 404s and the screenshots show alt strings. */
$settings = app(\App\Services\SettingsService::class);
$settings->set('site_url', getenv('BP_SITE_URL') ?: 'http://127.0.0.1:8974');

/* Switched ON and pointed at the SHIPPED set. The shipped state is the opposite
   of this — CardsBannerShipsOffTest is where that is proved; a preview that
   shipped off would photograph nothing. */
$settings->setModule('cards_banner', true);
$settings->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $shipped->id);

echo "seeded owner + sets: shipped={$shipped->id} colour={$colour->id} over={$over->id} image={$imageBg->id}\n";
