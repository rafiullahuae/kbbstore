<?php
/* Lane CO2's preview: the CO seed (tools/co-card-walk/seed.php), plus a
   photograph on every product with its img-cache copies made, a set whose
   member sells out, and Arabic switched on with the shipped drafts approved
   so /ar shows the dialog's Arabic. Pictures go to public/uploads/co2-preview
   and public/img-cache (both git-ignored); delete them afterwards. */
require __DIR__.'/../co-card-walk/seed.php';

use App\Models\{Product, ProductSetItem};

$picture = function (string $slug, array $from, array $to): string {
    $img = imagecreatetruecolor(800, 800);
    for ($y = 0; $y < 800; $y++) {
        $t = $y / 799;
        $c = imagecolorallocate($img, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
        imageline($img, 0, $y, 799, $y, $c);
    }
    $white = imagecolorallocatealpha($img, 255, 255, 255, 40);
    imagefilledellipse($img, 400, 430, 300, 420, $white);
    @mkdir(public_path('uploads/co2-preview'), 0777, true);
    imagejpeg($img, public_path("uploads/co2-preview/$slug.jpg"), 85);
    $url = "/uploads/co2-preview/$slug.jpg";
    \App\Support\ImageVariants::generate($url);

    return $url;
};

Product::where('slug', 'co-glow-serum')->update(['image' => $picture('serum', [252, 224, 232], [198, 57, 95])]);
Product::where('slug', 'co-fwee-jelly-pot')->update(['image' => $picture('jelly', [255, 230, 184], [224, 146, 47])]);

$toner = Product::updateOrCreate(['slug' => 'co2-heartleaf-toner'], [
    'name' => 'Heartleaf 77% Soothing Toner', 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
    'price' => 8900, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 5,
    'image' => $picture('toner', [205, 231, 214], [62, 142, 98]),
]);
$set = Product::updateOrCreate(['slug' => 'co2-glow-starter-set'], [
    'name' => 'Glow Starter Set', 'type' => 'set', 'status' => 'publish', 'is_visible' => true,
    'price' => 19900, 'stock_status' => 'instock', 'manage_stock' => false,
    'image' => $picture('set', [217, 210, 242], [123, 108, 240]),
]);
ProductSetItem::updateOrCreate(['set_product_id' => $set->id, 'member_product_id' => $toner->id], ['quantity' => 1, 'position' => 0]);

$sv = app(\App\Services\SettingsService::class);
$sv->set(\App\Services\StockSetRule::KEY, \App\Services\StockSetRule::MODE_MEMBERS);
$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);
require __DIR__.'/../ar-publish-drafts.php';

echo "co2: toner #{$toner->id}, set #{$set->id}\n";
