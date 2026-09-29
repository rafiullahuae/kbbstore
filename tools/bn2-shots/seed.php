<?php
// Lane BN2 preview seeder. Writes five banner pictures and one slider set.
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\SettingsService;

$dir = __DIR__.'/../../public-web-root/uploads/banners';
@mkdir($dir, 0775, true);

$palette = [
    ['Rose water toner', [232, 145, 159], [250, 232, 236]],
    ['Ceramide barrier', [122, 160, 190], [226, 240, 248]],
    ['Heartleaf calm',   [128, 176, 132], [230, 244, 231]],
    ['Vitamin glow',     [228, 176, 96],  [252, 242, 222]],
    ['Night repair',     [104, 92, 128],  [232, 228, 242]],
];

$W = 1600; $H = 900;
$font = '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf';
foreach ($palette as $i => [$label, $a, $b]) {
    $im = imagecreatetruecolor($W, $H);
    // A diagonal two-tone wash with a dark corner, so white chrome over the
    // picture is judged against something like a photograph rather than
    // against a pale flat.
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x += 8) {
            $t = min(1.0, max(0.0, ($x / $W) * 0.72 + ($y / $H) * 0.28));
            $v = 1.0 - 0.42 * (1.0 - $t) * (1.0 - $t);
            $c = imagecolorallocate($im,
                (int) round(($a[0] + ($b[0] - $a[0]) * $t) * $v),
                (int) round(($a[1] + ($b[1] - $a[1]) * $t) * $v),
                (int) round(($a[2] + ($b[2] - $a[2]) * $t) * $v));
            imagefilledrectangle($im, $x, $y, $x + 7, $y, $c);
        }
    }
    $white = imagecolorallocatealpha($im, 255, 255, 255, 30);
    $ink = imagecolorallocatealpha($im, 24, 16, 22, 40);
    if (is_file($font)) {
        imagettftext($im, 300, 0, 96, 470, $white, $font, (string) ($i + 1));
        imagettftext($im, 46, 0, 100, 600, $white, $font, strtoupper($label));
        imagettftext($im, 26, 0, 102, 660, $ink, $font, 'PICTURE '.($i + 1).' OF 5');
    }
    imagejpeg($im, $dir.'/bn2-'.($i + 1).'.jpg', 88);
    imagedestroy($im);
}

BannerCard::query()->delete();
BannerSet::query()->delete();

$set = BannerSet::create([
    'name' => 'Autumn pictures',
    'slug' => 'bn2-autumn-pictures',
    'status' => 'publish',
    'position' => 0,
    'kind' => 'slider',
    'slider_style' => $argv[1] ?? 'inset',
    'slider_ratio' => '16/9',
    'slider_ratio_m' => '4/3',
    'autoplay' => true,
    'speed_ms' => 4000,
    'show_arrows' => true,
    'show_dots' => true,
    'card_radius' => 18,
    'shadow' => 'soft',
]);

foreach ($palette as $i => [$label]) {
    BannerCard::create([
        'banner_set_id' => $set->id,
        'image' => 'uploads/banners/bn2-'.($i + 1).'.jpg',
        'alt' => $label,
        'button_url' => '/shop/',
        'image_w' => $W,
        'image_h' => $H,
        'position' => $i + 1,
        'status' => 'publish',
    ]);
}

$settings = app(SettingsService::class);
$settings->setModule('cards_banner', true);
$settings->setModuleSetting('cards_banner', 'set', (string) $set->id);

echo "set {$set->id} style {$set->slider_style}\n";
