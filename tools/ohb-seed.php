<?php

declare(strict_types=1);

/*
 * Lane OHB preview seed (tools/ohb-preview.sh) -- never against a real database.
 *
 * Nine real products from nine brands, their photographs flattened onto WHITE
 * exactly as the shop's product photos are (the approved previews' sources in
 * docs/plans/home-hero/products and docs/plans/gift-strip/products), so the
 * real ProductCutouts::make() runs on them. A picture slider shaped like the
 * live one (kbbs-1 on kbeautybliss.com, 6 Nov: 2560 x 1371, up to 600 on a
 * computer, exactly 500 on a phone, square corners). An owner to log in as.
 *
 * The occasion banner is configured through the controller's own save() and
 * cutout(), so the snapshot in the setting is the one the admin would write.
 * OHB_DISPLAY / OHB_LAYOUT / OHB_TEMPLATE pick the state (default both / G / 1111).
 */

use App\Http\Controllers\Admin\OccasionBannerController;
use App\Models\{BannerCard, BannerSet, Brand, Product};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$web = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($web.'/uploads/ohb', 0755, true);

// [file, name, brand, real height cm, white product]
$items = [
    ['home-hero/products/anua-pdrn-hyaluronic-acid-hydrating-capsule-mist-mini.png', 'Anua PDRN Hyaluronic Capsule Mist Mini', 'Anua', 7.5, false],
    ['home-hero/products/dr-althea-345-relief-cream.png', 'Dr.Althea 345 Relief Cream', 'Dr.Althea', 14.5, false],
    ['home-hero/products/cosrx-snail-96-essence.png', 'COSRX Advanced Snail 96 Mucin Essence', 'COSRX', 15, false],
    ['home-hero/products/beauty-of-joseon-relief-sun.png', 'Beauty of Joseon Relief Sun Rice + Probiotics', 'Beauty of Joseon', 13.5, false],
    ['home-hero/products/medicube-vanilla-pistachio-deodorant.png', 'Medicube Vanilla Pistachio Deodorant', 'Medicube', 10, false],
    ['gift-strip/products/arencia.png', 'Arencia Fresh Green Mochi Cleanser', 'Arencia', 9, false],
    ['gift-strip/products/axisy.png', 'AXIS-Y Dark Spot Correcting Glow Serum', 'AXIS-Y', 12, false],
    ['gift-strip/products/peel-pad.png', 'Toner Peel Pads', 'Medi-Peel', 6, false],
    ['gift-strip/products/guasha.png', 'Gua Sha Stone', 'KBB Tools', 8, true],
];

$ids = [];
foreach ($items as $i => [$file, $name, $brandName, $cm, $white]) {
    $src = imagecreatefrompng(base_path('docs/plans/'.$file));
    $w = imagesx($src);
    $h = imagesy($src);
    $side = (int) (max($w, $h) * 1.15);
    $im = imagecreatetruecolor($side, $side);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagealphablending($im, true);
    imagecopy($im, $src, (int) (($side - $w) / 2), (int) (($side - $h) / 2), 0, 0, $w, $h);
    $slug = 'ohb-'.$i.'-'.\Illuminate\Support\Str::slug($name);
    imagejpeg($im, $web.'/uploads/ohb/'.$slug.'.jpg', 90);
    imagedestroy($im);
    imagedestroy($src);

    $brand = Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($brandName)], ['name' => $brandName]);
    $p = Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'brand_id' => $brand->id, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 6900 + 1000 * $i, 'stock_status' => 'instock', 'image' => '/uploads/ohb/'.$slug.'.jpg', 'total_sales' => 100 - $i,
    ]);
    $ids[] = ['id' => $p->id, 'cm' => $cm, 'white' => $white];
}

// The picture slider, shaped like the live one.
@mkdir($web.'/uploads/banners', 0755, true);
BannerCard::query()->delete();
BannerSet::query()->delete();
$set = BannerSet::create(['name' => 'Hero', 'slug' => 'ohb-hero', 'status' => 'publish', 'position' => 0, 'kind' => 'slider',
    'slider_ratio' => 'auto', 'slider_h' => 600, 'slider_h_m' => 500, 'slider_hmode_m' => 'exact', 'show_dots' => true, 'show_arrows' => false,
    'slider_style' => 'inset', 'autoplay' => true, 'speed_ms' => 6900, 'card_radius' => 0]);
foreach ([[[255, 214, 232], [233, 213, 255], 'Spring glow'], [[214, 240, 255], [255, 236, 214], 'New arrivals']] as $n => [$a, $b, $alt]) {
    $im = imagecreatetruecolor(2560, 1371);
    for ($x = 0; $x < 2560; $x += 8) {
        $t = $x / 2560;
        $c = imagecolorallocate($im, (int) ($a[0] + ($b[0] - $a[0]) * $t), (int) ($a[1] + ($b[1] - $a[1]) * $t), (int) ($a[2] + ($b[2] - $a[2]) * $t));
        imagefilledrectangle($im, $x, 0, $x + 7, 1370, $c);
    }
    $ink = imagecolorallocate($im, 90, 40, 70);
    imagestring($im, 5, 200, 640, 'PICTURE SLIDE '.($n + 1).' - '.$alt, $ink);
    imagejpeg($im, $web.'/uploads/banners/ohb-slide-'.$n.'.jpg', 60);
    imagedestroy($im);
    BannerCard::create(['banner_set_id' => $set->id, 'image' => 'uploads/banners/ohb-slide-'.$n.'.jpg', 'image_w' => 2560, 'image_h' => 1371,
        'alt' => $alt, 'button_url' => '/shop/', 'position' => $n + 1, 'status' => 'publish']);
}
app(\App\Services\SettingsService::class)->set('module_settings', null);
DB::table('module_toggles')->updateOrInsert(['module' => 'cards_banner'], ['enabled' => true]);
DB::table('module_settings')->updateOrInsert(['module' => 'cards_banner', 'key' => 'set'], ['value' => (string) $set->id]);

// The Arabic shop on /ar, mirrored, as live.
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, '1');

// The occasion banner, through the admin's own endpoints.
$c = app(OccasionBannerController::class);
$display = getenv('OHB_DISPLAY') ?: 'both';
$c->save(Request::create('/x', 'POST', ['config' => array_merge(\App\Support\OccasionBanner::defaults(getenv('OHB_TEMPLATE') ?: '1111'), [
    'display' => $display, 'layout' => getenv('OHB_LAYOUT') ?: 'G', 'pool' => $ids,
])]));
$t0 = microtime(true);
foreach ($ids as $row) {
    $r = $c->cutout(Request::create('/x', 'POST', ['id' => $row['id'], 'white' => $row['white']]))->getData(true);
    echo 'cut '.$row['id'].' '.($r['ok'] ? 'ok '.($r['ms'] ?? 0).'ms' : 'FAILED '.($r['message'] ?? '')).PHP_EOL;
}
echo 'ohb seed: '.count($ids).' products, display '.$display.', cut-outs in '.round(microtime(true) - $t0, 1).'s'.PHP_EOL;
