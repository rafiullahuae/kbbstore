<?php
/*
 * Seed the Lane QB preview: one product page whose photograph is a large WebP,
 * the way the imported catalogue's pictures mostly are, so the share image has
 * a real WebP to turn into a JPEG and the og:image before/after is the real
 * comparison.
 *
 * The photograph is DRAWN, not downloaded: a pink toner bottle on white with a
 * soft shadow and a little sensor noise, 1600x1600, WebP q90 -- about the size
 * and the encoding of what the WooCommerce export carries.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

app(\App\Services\SettingsService::class)->set('store_country', 'AE');

$zone = \App\Models\ShippingZone::firstOrCreate(['name' => 'All UAE'], ['position' => 0]);
\App\Models\ShippingZoneLocation::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
\App\Models\ShippingMethod::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping'], [
    'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'toners'], ['name' => 'Toners']);

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/products', 0775, true);

$shot = function (string $name, array $body, array $cap) use ($root): string {
    $w = 1600;
    $im = imagecreatetruecolor($w, $w);
    imagefilledrectangle($im, 0, 0, $w, $w, imagecolorallocate($im, 255, 255, 255));

    // A soft floor shadow.
    for ($i = 0; $i < 40; $i++) {
        $g = 255 - (int) (24 * (1 - $i / 40));
        imagefilledellipse($im, 800, 1380, 620 - $i * 6, 90 - $i, imagecolorallocate($im, $g, $g, $g));
    }

    // The bottle: a vertical gradient body, rounded shoulders, a cap.
    for ($y = 420; $y < 1370; $y++) {
        $t = ($y - 420) / 950;
        $c = imagecolorallocate($im, (int) ($body[0] - 18 * $t), (int) ($body[1] - 30 * $t), (int) ($body[2] - 24 * $t));
        imageline($im, 560, $y, 1040, $y, $c);
    }
    imagefilledellipse($im, 800, 425, 480, 120, imagecolorallocate($im, ...$body));
    imagefilledrectangle($im, 690, 230, 910, 420, imagecolorallocate($im, ...$cap));
    imagefilledellipse($im, 800, 232, 220, 40, imagecolorallocate($im, min(255, $cap[0] + 30), min(255, $cap[1] + 30), min(255, $cap[2] + 30)));

    // A label panel with three lines of "type".
    imagefilledrectangle($im, 600, 720, 1000, 1110, imagecolorallocate($im, 252, 247, 244));
    $ink = imagecolorallocate($im, 70, 52, 60);
    imagefilledrectangle($im, 650, 780, 950, 812, $ink);
    imagefilledrectangle($im, 680, 850, 920, 866, $ink);
    imagefilledrectangle($im, 700, 900, 900, 912, $ink);
    imagefilledellipse($im, 800, 1010, 120, 120, imagecolorallocate($im, 120, 170, 120));

    // A highlight down one side.
    for ($x = 0; $x < 40; $x++) {
        imageline($im, 600 + $x, 460, 600 + $x, 1340, imagecolorallocatealpha($im, 255, 255, 255, 80 + $x));
    }

    // Sensor noise, so the WebP carries the bytes a real photograph does.
    mt_srand(7);
    for ($n = 0; $n < 260000; $n++) {
        $x = mt_rand(0, $w - 1);
        $y = mt_rand(0, $w - 1);
        $rgb = imagecolorat($im, $x, $y);
        $d = mt_rand(-9, 9);
        $r = max(0, min(255, (($rgb >> 16) & 255) + $d));
        $g = max(0, min(255, (($rgb >> 8) & 255) + $d));
        $b = max(0, min(255, ($rgb & 255) + $d));
        imagesetpixel($im, $x, $y, imagecolorallocate($im, $r, $g, $b));
    }

    imagewebp($im, $root.'/uploads/products/'.$name, 90);
    imagedestroy($im);

    return '/uploads/products/'.$name;
};

$p = \App\Models\Product::updateOrCreate(['slug' => 'qb-heartleaf-toner'], [
    'name' => 'Anua Heartleaf 77% Soothing Toner 250ml – Calming & Hydrating Toner for Sensitive Skin',
    'brand_id' => $brand->id,
    'price' => 8500, 'sale_price' => 6500,
    'image' => $shot('qb-heartleaf-toner.webp', [246, 196, 210], [236, 236, 232]),
    'short_description' => '<p>A gentle daily toner with 77% heartleaf extract that calms redness, balances oil &amp; water, and preps skin for everything after it.</p>',
    'description' => '<p>Built around a single idea: calm first.</p>',
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
    'type' => 'simple', 'manage_stock' => true, 'stock' => 40,
]);
$p->categories()->syncWithoutDetaching([$category->id]);

echo "qb seed: product {$p->id} /product/qb-heartleaf-toner/ image ".filesize($root.'/uploads/products/qb-heartleaf-toner.webp')." bytes\n";
