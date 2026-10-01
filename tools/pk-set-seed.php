<?php
/*
 * Seed for the Lane PK preview.                                     (Lane PK)
 *
 *   "Glow Trio Set"     a SET priced the way the owner's screenshot shows it:
 *                       "An amount off the total", Discount 131, members that
 *                       cost AED 1,310 bought separately -- so the tiles read
 *                       1310.00 / 1179.00 / 131.00, and the row holds what the
 *                       server leaves under a rule: price 1179 (derived), sale
 *                       price EMPTY. That empty sale price is the defect.
 *   "Glow Duo Set"      the same box under "A percentage off the total", 10%.
 *   its three members   simple, published, AED 750 + 360 + 200
 *   "Coming Soon Toner" a DRAFT, for the editor's "Not live yet"
 *
 * Money is integer fils. Re-runnable: every row is found by slug.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'glowlab'], ['name' => 'GlowLab']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'gift-sets'], ['name' => 'Gift sets']);

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/products', 0775, true);

$pic = function (string $name, string $hex) use ($root): string {
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, $r, $g, $b));
    ob_start();
    imagepng($im);
    file_put_contents($root.'/uploads/products/'.$name, (string) ob_get_clean());

    return '/uploads/products/'.$name;
};

$simple = function (string $name, string $slug, int $fils, string $image, string $status = 'publish') use ($brand, $category) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'sale_price' => null, 'brand_id' => $brand->id,
        'category_id' => $category->id, 'image' => $image, 'status' => $status, 'is_visible' => true,
        'stock_status' => 'instock', 'type' => 'simple', 'manage_stock' => true, 'stock' => 20,
    ]);
    $p->categories()->sync([$category->id]);

    return $p;
};

$device = $simple('GlowLab LED Booster Device', 'pk-led-booster', 75000, $pic('pk-device.png', 'E0567B'));
$cream = $simple('GlowLab Collagen Capsule Cream 50ml', 'pk-capsule-cream', 36000, $pic('pk-cream.png', 'C94F7C'));
$serum = $simple('GlowLab Peptide Serum 30ml', 'pk-peptide-serum', 20000, $pic('pk-serum.png', 'F2A1B8'));
$draft = $simple('Coming Soon Toner', 'pk-coming-soon-toner', 9900, $pic('pk-toner.png', 'B8E0D2'), 'draft');

$set = function (string $name, string $slug, string $mode, int $discount, string $hex) use ($brand, $category, $pic, $device, $cream, $serum) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'type' => 'set', 'brand_id' => $brand->id, 'category_id' => $category->id,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'manage_stock' => false,
        'image' => $pic($slug.'.png', $hex), 'sale_price' => null, 'sale_starts_at' => null, 'sale_ends_at' => null,
        'set_price_mode' => $mode, 'set_discount' => $discount, 'set_price_basis' => null, 'price' => 0,
    ]);
    $p->categories()->sync([$category->id]);
    \App\Models\ProductSetItem::where('set_product_id', $p->id)->delete();
    foreach ([$device, $cream, $serum] as $i => $m) {
        \App\Models\ProductSetItem::create(['set_product_id' => $p->id, 'member_product_id' => $m->id,
            'member_variant_id' => null, 'quantity' => 1, 'position' => $i]);
    }

    // What the editor's save leaves under a rule: the derived figure in
    // `price`, and NO sale price (ProductEditorApiController::applySetPricing).
    \App\Support\SetPricing::forget((int) $p->id);
    $p->unsetRelation('setItems');
    $p->price = \App\Support\SetPricing::derived($p) ?? 0;
    $p->save();

    return $p;
};

$trio = $set('Glow Trio Set', 'pk-glow-trio-set', 'discount_amount', 13100, 'F7C6D4');
$duo = $set('Glow Duo Set', 'pk-glow-duo-set', 'discount_percent', 1000, 'EFA3BC');

\Illuminate\Support\Facades\Cache::flush();

echo "pk seed: trio #{$trio->id} (price {$trio->price}), duo #{$duo->id} (price {$duo->price}), draft #{$draft->id}, device #{$device->id}\n";
