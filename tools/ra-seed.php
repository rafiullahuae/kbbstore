<?php
/*
 * Seed for the Lane RA preview: the storefront admin bar and quick-edit pencil.
 *
 * Lane QC's whole seed (which is Lane PY's: the owner account
 * owner@preview.test / preview-secret-1, /collections/skincare/sunscreens/
 * with a banner, /collections/lip-care/ on the light box, Arabic on), plus:
 *
 *   /brands/ra-glow/          a brand WITH a header picture -> a header to edit
 *   /brands/ra-plain/         a brand with no picture -> no header, pencil by the h1
 *   uploads/ra/new-banner.jpg a 2400 x 600 banner the shots drop into the pop-up
 *   three orders placed today, so the bar's Orders badge has a number
 *   Arabic switched OFF again: the shots are of the English shop.
 */

use App\Models\Brand;
use App\Models\Product;

require __DIR__.'/qc-seed.php';

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();

$banner = function (string $path, array $from, array $to, string $label): void {
    $w = 2400; $h = 600;
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x++) {
        $t = $x / ($w - 1);
        $c = imagecolorallocate($im, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
        imageline($im, $x, 0, $x, $h, $c);
    }
    $soft = imagecolorallocatealpha($im, 255, 255, 255, 70);
    foreach ([[400, 150, 300], [1300, 420, 420], [2000, 180, 360]] as [$cx, $cy, $d]) {
        imagefilledellipse($im, $cx, $cy, $d, $d, $soft);
    }
    imagestring($im, 5, 1100, 40, $label, imagecolorallocate($im, 255, 255, 255));
    @mkdir(dirname($path), 0775, true);
    imagejpeg($im, $path, 88);
};

$banner($root.'/uploads/ra/glow-banner.jpg', [40, 70, 90], [200, 120, 150], 'RA GLOW BRAND BANNER');
$banner($root.'/uploads/ra/new-banner.jpg', [120, 40, 70], [240, 170, 110], 'NEW BANNER DROPPED IN THE POP-UP');

$glow = Brand::updateOrCreate(['slug' => 'ra-glow'], [
    'name' => 'Glow Lab', 'description' => 'Gentle, fragrance-free Korean skincare for sensitive skin.',
    'header_image' => '/uploads/ra/glow-banner.jpg',
]);
$plain = Brand::updateOrCreate(['slug' => 'ra-plain'], [
    'name' => 'Plain Botanics', 'description' => 'Plant-based essentials.',
]);

foreach ([$glow, $plain] as $bi => $b) {
    for ($i = 0; $i < 4; $i++) {
        Product::updateOrCreate(['slug' => 'ra-'.$b->slug.'-'.$i], [
            'name' => $b->name.' Essence No. '.($i + 1), 'wc_id' => 61000 + $bi * 10 + $i,
            'price' => 7900 + $i * 500, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
            'type' => 'simple', 'image' => '/uploads/products/py-0-'.$i.'.jpg', 'brand_id' => $b->id,
        ]);
    }
}

foreach ([1, 2, 3] as $n) {
    try {
        \App\Models\Order::query()->updateOrCreate(['order_number' => 'RA-'.$n], [
            'email' => "ra{$n}@preview.test", 'status' => 'processing', 'total' => 12000, 'subtotal' => 12000,
            'created_at' => now()->subMinutes($n * 7),
        ]);
    } catch (\Throwable $e) {
        echo 'ra seed: order skipped ('.$e->getMessage().")\n";
    }
}

$sv = app(\App\Services\SettingsService::class);
$sv->set(\App\Support\Locale::SETTING_ENABLED, false);
$sv->set(\App\Support\Locale::SETTING_RTL, false);
$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\Illuminate\Support\Facades\Cache::flush();

echo "ra seed: brands /brands/ra-glow/ and /brands/ra-plain/, banner uploads/ra/new-banner.jpg\n";
