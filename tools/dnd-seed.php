<?php

declare(strict_types=1);

/*
 * Lane DND preview seed -- never against a real database. Run by
 *   OHB_SEED=tools/dnd-seed.php sh tools/ohb-preview.sh 11900
 *
 * The pool is the ten products whose HAND-MADE cut-outs ship in
 * resources/cutouts (index.json), installed exactly as the install migration
 * does (ProductCutouts::installShipped()), then saved through the admin's own
 * save() so the stored snapshot is the one the admin writes. Display: Banner
 * only, layout G, five products. An owner to log in as.
 */

use App\Http\Controllers\Admin\OccasionBannerController;
use App\Models\{Brand, Product};
use App\Services\Cutouts\ProductCutouts;
use Illuminate\Http\Request;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

// [photo path, name, brand, real height cm]
$items = [
    ['uploads/products/medicube-medicube-pdrn-pink-collagen-jelly-eye-mask-6-pairs.webp', 'Medicube PDRN Pink Collagen Jelly Eye Mask', 'Medicube', 9],
    ['wp-content/uploads/2024/10/AXIS-Y-Dark-Spot-Correcting-Glow-Serum.webp', 'AXIS-Y Dark Spot Correcting Glow Serum', 'AXIS-Y', 12],
    ['wp-content/uploads/2024/10/Beauty-of-Joseon-Relief-Sun_-Rice-Probiotics-SPF50-PA-50ml-1.webp', 'Beauty of Joseon Relief Sun', 'Beauty of Joseon', 13.5],
    ['wp-content/uploads/2024/10/COSRX-Advanced-Snail-96-Mucin-Power-Essence-–-100ml.webp', 'COSRX Advanced Snail 96 Mucin Power Essence', 'COSRX', 15],
    ['wp-content/uploads/2024/10/Dr.Althea-345-Relief-Cream.webp', 'Dr.Althea 345 Relief Cream', 'Dr.Althea', 14.5],
    ['wp-content/uploads/2024/12/Medicube-Red-Succinic-Acid-Peeling-Pad.webp', 'Medicube Red Succinic Acid Peeling Pad', 'Medicube Pads', 9],
    ['wp-content/uploads/2026/04/Arencia-Vitamin-C-Booster-Shot.webp', 'Arencia Vitamin C Booster Shot', 'Arencia', 12],
    ['wp-content/uploads/2026/09/Anua-PDRN-Hyaluronic-Acid-Hydrating-Capsule-Mist-Mini.webp', 'Anua PDRN Capsule Mist Mini', 'Anua', 7.5],
    ['wp-content/uploads/2026/09/medicube-PDRN-Collagen-Gua-Sha-Neck-Wrinkle-Cream.webp', 'medicube PDRN Gua Sha Neck Cream', 'medicube Neck', 15],
    ['wp-content/uploads/2026/09/medicube-Vanilla-Pistachio-Deodorant-1.webp', 'medicube Vanilla Pistachio Deodorant', 'medicube Body', 10],
];

$pool = [];
foreach ($items as $i => [$path, $name, $brandName, $cm]) {
    $brand = Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($brandName)], ['name' => $brandName]);
    $p = Product::updateOrCreate(['slug' => 'dnd-'.$i], [
        'name' => $name, 'brand_id' => $brand->id, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 6900 + 1000 * $i, 'stock_status' => 'instock', 'image' => '/'.$path, 'total_sales' => 100 - $i,
    ]);
    $pool[] = ['id' => $p->id, 'cm' => $cm, 'white' => false];
}

$done = ProductCutouts::installShipped();
echo 'installed '.count($done).' hand-made cut-outs'.PHP_EOL;

$r = app(OccasionBannerController::class)->save(Request::create('/x', 'POST', ['config' => array_merge(\App\Support\OccasionBanner::defaults('1111'), [
    'display' => getenv('OHB_DISPLAY') ?: 'banner', 'layout' => 'G', 'count' => 5, 'pool' => $pool,
])]))->getData(true);
echo 'dnd seed: '.count($r['config']['pool']).' in pool, '.count(array_filter($r['config']['pool'], fn ($p) => $p['cut'] !== null)).' ready'.PHP_EOL;
