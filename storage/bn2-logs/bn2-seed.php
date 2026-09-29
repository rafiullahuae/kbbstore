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
foreach ($palette as $i => [$label, $a, $b]) {
    $im = imagecreatetruecolor($W, $H);
    for ($x = 0; $x < $W; $x++) {
        $t = $x / ($W - 1);
        $c = imagecolorallocate($im,
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t));
        imageline($im, $x, 0, $x, $H, $c);
    }
    $ink = imagecolorallocate($im, 32, 24, 30);
    $white = imagecolorallocate($im, 255, 255, 255);
    // A big numeral so a slide change is unmistakable in a screenshot.
    for ($s = 0; $s < 26; $s++) {
        imagestring($im, 5, 70, 300 + $s * 2, (string) ($i + 1), $ink);
        imagestring($im, 5, 72, 300 + $s * 2, (string) ($i + 1), $ink);
    }
    imagestring($im, 5, 70, 640, strtoupper($label), $white);
    imagestring($im, 5, 70, 664, 'PICTURE '.($i + 1).' OF 5', $ink);
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
