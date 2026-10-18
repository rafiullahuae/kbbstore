<?php
/*
 * Lane CPU -- a SYNTHETIC stand-in for the live snapshot tools/arc-seed.php reads
 * (storage/arc-logs/{live-products,harvest,api-posts}.json), shaped like the
 * live catalogue of 15 October 2026: 761 products, 95 brands, 48 categories,
 * 11 blog posts. The real snapshot is not in the repo and the live host is not
 * reachable from the lane machine, so the counts are live and the words are not.
 * PREVIEW ONLY.   php tools/cpu-snapshot.php <app root>
 */
$base = $argv[1];
$dir = $base.'/storage/arc-logs';
@mkdir($dir, 0777, true);
$trending = ['Anua', 'Arencia', 'Axis - Y', 'Beauty of Joseon', 'BIODANCE', 'Celimax', 'Centellian24', 'Dr.Althea', 'Dr.Melaxin', 'EQQUALBERRY', 'Medicube', 'MOEV', 'numbuzin', 'SKIN 1004', 'ma:nyo', 'VT Cosmetics'];
$brands = [];
foreach ($trending as $n) {
    $brands[trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($n)), '-')] = ['name' => $n, 'description' => $n.' -- Korean skincare brand.'];
}
for ($i = count($brands); $i < 95; $i++) {
    $brands['brand-'.$i] = ['name' => 'Brand'.$i, 'description' => 'Brand '.$i.' description for the brand page.'];
}
$cats = ['skincare', 'toners', 'face-serums', 'moisturizers', 'sunscreens', 'face-masks', 'cleansers', 'lip-care', 'hair-care', 'skincare-sets',
    'eye-care', 'body-care', 'beauty-devices', 'exfoliators', 'super-sale', 'under-aed-54', 'acne-blemish-care', 'anti-aging', 'hydration-glow',
    'pigmentation', 'pore-care', 'redness-sensitive-skin', 'oily-skin'];
for ($i = count($cats); $i < 48; $i++) {
    $cats[] = 'category-'.$i;
}
$categories = [];
foreach ($cats as $c) {
    $categories[$c] = ['name' => ucwords(str_replace('-', ' ', $c)), 'description' => 'All '.$c.' in one place.'];
}
$kinds = ['Toner', 'Serum', 'Cream', 'Sun Serum SPF50', 'Sheet Mask', 'Cleansing Foam', 'Lip Balm', 'Shampoo', 'Set', 'Eye Cream', 'Body Lotion', 'Exfoliating Pad'];
$bnames = array_column($brands, 'name');
$products = [];
for ($i = 0; $i < 761; $i++) {
    $b = $bnames[$i % count($bnames)];
    $name = $b.' '.$kinds[$i % count($kinds)].' No.'.$i.' 50ml';
    $price = 4500 + ($i * 37 % 12000);
    $products[] = ['slug' => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), 'name' => $name, 'price' => $price,
        'sale_price' => $i % 4 === 0 ? (int) ($price * 0.8) : null, 'short_description' => 'A gentle daily '.strtolower($kinds[$i % count($kinds)]).' for every skin type.',
        'stock_status' => $i % 17 === 0 ? 'outofstock' : 'instock', 'rating' => 4.5, 'review_count' => $i % 30];
}
$posts = [];
for ($i = 0; $i < 11; $i++) {
    $posts[] = ['slug' => 'korean-skincare-guide-'.$i, 'title' => 'Korean skincare guide part '.$i, 'excerpt' => 'Everything about step '.$i.' of the routine.'];
}
file_put_contents($dir.'/live-products.json', json_encode($products));
file_put_contents($dir.'/harvest.json', json_encode(['brands' => $brands, 'categories' => $categories]));
file_put_contents($dir.'/api-posts.json', json_encode($posts));
echo "cpu-snapshot: ", count($products), " products, ", count($brands), " brands, ", count($categories), " categories, ", count($posts), " posts\n";
