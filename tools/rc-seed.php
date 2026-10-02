<?php
/* Lane RC preview seed: an owner, a few products, and ONE published picture
   banner on the homepage carrying the owner's real banner shapes --
   1920 x 550 desktop art and 500 x 600 phone art on every slide.

   EVERY PICTURE HAS A RULED BORDER AND FOUR CORNER MARKS, which is the whole
   point of the fixture: a picture cut by `object-fit: cover` loses a border
   edge or a corner mark, so a screenshot shows at a glance whether anything
   was cropped. A flat swatch looks the same cut or whole.

   RC_KIND picks the kind (slider by default; `single` on the after tree),
   RC_NO_PHONE=1 seeds slides with no phone picture, and RC_SITE_URL is the
   preview's origin, because Media::urlFor() builds image URLs from the
   `site_url` setting. */

\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

for ($i = 1; $i <= 8; $i++) {
    \App\Models\Product::create([
        'name' => 'Lane RC Heartleaf Toner '.$i.' 250ml', 'slug' => 'rc-toner-'.$i,
        'sku' => 'RC-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'status' => 'publish', 'price' => 8900 + $i * 100, 'stock' => 20 + $i,
    ]);
}

$dir = public_path('uploads/banners');
@mkdir($dir, 0775, true);

$draw = static function (string $file, int $w, int $h, array $rgb, string $label): void {
    $img = imagecreatetruecolor($w, $h);
    [$r, $g, $b] = $rgb;
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, $r, $g, $b));
    imagefilledellipse($img, (int) ($w * .3), (int) ($h * .4), (int) ($w * .5), (int) ($h * 1.3), imagecolorallocatealpha($img, 255, 255, 255, 96));
    $ink = imagecolorallocate($img, 60, 30, 40);
    $mark = imagecolorallocate($img, 20, 90, 200);
    imagesetthickness($img, 8);
    imagerectangle($img, 4, 4, $w - 5, $h - 5, $ink);
    $m = (int) round(min($w, $h) * .14);
    foreach ([[0, 0], [$w - $m, 0], [0, $h - $m], [$w - $m, $h - $m]] as [$x, $y]) {
        imagefilledrectangle($img, $x, $y, $x + $m, $y + $m, $mark);
    }
    imagestring($img, 5, (int) ($w * .1), (int) ($h / 2 - 8), strtoupper($label).'  '.$w.'x'.$h, $ink);
    imagepng($img, $file);
    imagedestroy($img);
};

$palette = [[0xF4, 0xB6, 0xC6], [0xA8, 0xD5, 0xCB], [0xF2, 0xD7, 0x8E]];
$alt = ['Experience the Skincare Revolution', 'Barrier repair, gently', 'Every-day SPF'];
$noPhone = (bool) getenv('RC_NO_PHONE');

$set = \App\Models\BannerSet::create([
    'name' => 'Homepage banner', 'slug' => 'homepage-banner', 'status' => 'publish', 'position' => 0,
    'kind' => getenv('RC_KIND') ?: 'slider',
]);

foreach ($alt as $i => $label) {
    $d = 'uploads/banners/rc-desk-'.($i + 1).'.png';
    $p = 'uploads/banners/rc-phone-'.($i + 1).'.png';
    $draw(public_path($d), 1920, 550, $palette[$i], $label);
    $draw(public_path($p), 500, 600, $palette[$i], 'phone '.($i + 1));
    \App\Support\MediaRegistrar::record($d);
    \App\Support\MediaRegistrar::record($p);
    \App\Models\BannerCard::create([
        'banner_set_id' => $set->id, 'image' => $d, 'alt' => $label,
        'heading' => '', 'body' => '', 'button_label' => '', 'button_url' => '/shop/',
        'image_w' => 1920, 'image_h' => 550,
        'image_m' => $noPhone ? '' : $p,
        'image_m_w' => $noPhone ? null : 500, 'image_m_h' => $noPhone ? null : 600,
        'position' => $i + 1, 'status' => 'publish',
    ]);
}

$settings = app(\App\Services\SettingsService::class);
$settings->set('site_url', getenv('RC_SITE_URL') ?: 'http://127.0.0.1:9940');
$settings->setModule('cards_banner', true);
$settings->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $set->id);

echo "rc seed: set {$set->id} kind {$set->kind} with 3 slides\n";
