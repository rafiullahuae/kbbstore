<?php
/*
 * Seed for the Lane GO preview (tools/go-preview.sh). Re-runnable.
 *
 *   an owner          owner@preview.test / preview-secret-1
 *   six products      with drawn bottle photos (GD), Beauty of Joseon et al.
 *   free gifts        one live range (AED 100+, 2 masks), so the green box shows
 *   gift offers       AED 100-299 Dokdo Toner (primary) + Rice Toner (backup);
 *                     AED 300+   Relief Sun (primary) + Snail Essence (backup)
 *                     the popup ON -- in THIS preview database only
 *   Arabic switched on, COD and a UAE delivery zone, so checkout renders
 */
use Illuminate\Support\Facades\DB;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$settings = app(\App\Services\SettingsService::class);
$settings->set('store_name', 'K-Beauty Bliss');
$settings->set(\App\Support\Locale::SETTING_ENABLED, '1');

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/go', 0775, true);

$pic = function (string $file, string $body, string $cap, string $label) use ($root): string {
    $c = fn ($im, $hex) => imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, $c($im, 'FFF6F2'));
    imagefilledrectangle($im, 250, 70, 350, 150, $c($im, $cap));
    imagefilledellipse($im, 300, 170, 200, 60, $c($im, $body));
    imagefilledrectangle($im, 200, 170, 400, 520, $c($im, $body));
    imagefilledellipse($im, 300, 520, 200, 60, $c($im, $body));
    imagefilledrectangle($im, 215, 300, 385, 390, $c($im, 'FFFFFF'));
    imagestring($im, 5, 300 - strlen($label) * 4, 335, $label, $c($im, '2A2228'));
    ob_start();
    imagepng($im);
    file_put_contents($root.'/uploads/go/'.$file, (string) ob_get_clean());

    return '/uploads/go/'.$file;
};

$brand = fn (string $name) => \App\Models\Brand::updateOrCreate(['slug' => \Illuminate\Support\Str::slug($name)], ['name' => $name]);
$cat = \App\Models\Category::updateOrCreate(['slug' => 'skincare'], ['name' => 'Skincare']);

$make = function (string $name, string $slug, int $fils, string $brandName, string $img, array $o = []) use ($brand, $cat) {
    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], array_merge([
        'name' => $name, 'price' => $fils, 'sale_price' => null, 'brand_id' => $brand($brandName)->id, 'category_id' => $cat->id,
        'image' => $img, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
        'manage_stock' => true, 'stock' => 40,
    ], $o));
    $p->categories()->sync([$cat->id]);

    return $p;
};

$serum = $make('Glow Deep Serum : Rice + Alpha-Arbutin 30ml', 'go-glow-serum', 18500, 'Beauty of Joseon', $pic('serum.png', 'F2C7A5', 'B88A2E', 'SERUM'));
$cream = $make('Dynasty Cream 50ml', 'go-dynasty-cream', 21900, 'Beauty of Joseon', $pic('cream.png', 'F6E3EC', 'A2456E', 'CREAM'));
$dokdo = $make('Round Lab 1025 Dokdo Toner 200ml', 'go-dokdo-toner', 8900, 'Round Lab', $pic('dokdo.png', 'CFE6F5', '2F6E9C', 'TONER'));
$rice = $make('Rice Toner 150ml', 'go-rice-toner', 7400, "I'm From", $pic('rice.png', 'FFF1C9', 'B88A2E', 'RICE'));
$sun = $make('Relief Sun : Rice + Probiotics SPF50+ 50ml', 'go-relief-sun', 6500, 'Beauty of Joseon', $pic('sun.png', 'FFF1C9', 'C9A36A', 'SPF50+'));
$snail = $make('Advanced Snail 96 Mucin Power Essence 100ml', 'go-snail-essence', 8900, 'COSRX', $pic('snail.png', 'F6E3EC', 'A12D4F', 'SNAIL 96'));
$mask = $make('Tea Tree Sheet Mask', 'go-tea-tree-mask', 900, 'Mediheal', $pic('mask.png', 'DDF2E3', '2E8F62', 'MASK'), ['gift_kind' => 'mask', 'gift_only' => true]);

// Free gifts: one live range, so the green box sits above the gift offer row.
DB::table('free_gift_ranges')->delete();
DB::table('free_gift_range_items')->delete();
$fg = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => null, 'label_en' => '2 Masks', 'label_ar' => 'قناعان', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
DB::table('free_gift_range_items')->insert(['range_id' => $fg, 'product_id' => $mask->id, 'quantity' => 2, 'sort' => 0]);
$settings->set(\App\Services\FreeGifts::KEY, ['mode' => 'live']);
app(\App\Services\FreeGifts::class)->compile();

// Gift offers: two ranges, a primary and a backup each.
DB::table('gift_offer_items')->delete();
DB::table('gift_offer_ranges')->delete();
foreach ([[10000, 29900, [[$dokdo, 'primary', 50], [$rice, 'backup', 30]]], [30000, null, [[$sun, 'primary', 50], [$snail, 'backup', 25]]]] as $i => [$min, $max, $items]) {
    $rid = DB::table('gift_offer_ranges')->insertGetId(['min_fils' => $min, 'max_fils' => $max, 'enabled' => true, 'sort' => $i, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($items as $j => [$p, $role, $alloc]) {
        DB::table('gift_offer_items')->insert(['range_id' => $rid, 'product_id' => $p->id, 'role' => $role, 'sort' => $j, 'allocation' => $alloc, 'used' => $j === 0 ? 3 : 0, 'created_at' => now(), 'updated_at' => now()]);
    }
}
$settings->set(\App\Services\GiftOffers\GiftOffers::KEY, ['mode' => 'live', 'places' => ['popup' => true]]);
app(\App\Services\GiftOffers\GiftOffers::class)->compile();

// Checkout: cash on delivery and a UAE zone.
\App\Models\PaymentProvider::updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
if (! \App\Models\ShippingZone::query()->where('name', 'UAE')->exists()) {
    $zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
}

// A pending order, so the pending reminders can be previewed.
if (! \App\Models\Order::query()->where('order_number', '10427')->exists()) {
    $o = \App\Models\Order::create(['order_number' => '10427', 'email' => 'layla@example.test', 'status' => 'pending', 'total' => 42400, 'subtotal' => 40400,
        'shipping_total' => 2000, 'currency' => 'AED', 'payment_method' => 'stripe', 'payment_method_title' => 'Card', 'locale' => 'en',
        'billing_address' => ['first_name' => 'Layla', 'last_name' => 'Hassan', 'country' => 'AE', 'city' => 'Dubai'],
        'shipping_address' => ['first_name' => 'Layla', 'last_name' => 'Hassan', 'country' => 'AE', 'city' => 'Dubai', 'line1' => 'Marina Heights'],
        'free_gift_count' => 2]);
    foreach ([$serum, $cream] as $p) {
        \App\Models\OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'name' => $p->name, 'brand' => 'Beauty of Joseon', 'quantity' => 1, 'unit_price' => $p->price, 'subtotal' => $p->price, 'total' => $p->price]);
    }
    \App\Models\OrderItem::create(['order_id' => $o->id, 'product_id' => $mask->id, 'name' => $mask->name, 'brand' => 'Mediheal', 'quantity' => 2, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
}

echo "seeded: products".\App\Models\Product::count().", offer ranges ".DB::table('gift_offer_ranges')->count()."\n";
