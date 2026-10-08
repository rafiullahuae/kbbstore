<?php
/* Lane TY's preview: the owner's order #56181 shape. Several products, two of
   them named with "&" (one stored HTML-encoded, the way the WooCommerce import
   wrote names before 2.60.434 decoded them), a set, a "Buy these together"
   pair, a deleted product, free delivery to the UAE, and Stripe in test mode
   with fake keys (the fake API is tools/co-card-walk/preview-index.php). */
use App\Models\{Brand, Product, ProductSetItem, PaymentProvider, ShippingZone, ShippingZoneLocation, ShippingMethod};

$brand = fn (string $n) => Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($n)], ['name' => $n]);
$mk = function (string $slug, string $name, int $price, ?string $b = null, array $extra = []) use ($brand) {
    return Product::updateOrCreate(['slug' => $slug], array_merge([
        'name' => $name, 'status' => 'publish', 'is_visible' => true, 'price' => $price,
        'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 50,
        'brand_id' => $b ? $brand($b)->id : null,
    ], $extra));
};

$ids = [];
$ids['nida'] = $mk('ty-nida-cream', 'NIDA Cream', 6900, 'NIDA')->id;
$ids['arencia1'] = $mk('ty-arencia-rice', 'Arencia Fresh Green Rice Mochi Cleanser', 9900, 'Arencia')->id;
$ids['arencia2'] = $mk('ty-arencia-holy', 'Arencia Holy Hyssop Serum &amp; Toner', 11900, 'Arencia')->id;
$ids['rohto'] = $mk('ty-rohto', 'Rohto Mentholatum Melano CC Essence', 4500, 'Rohto Mentholatum')->id;
$ids['shiseido'] = $mk('ty-shiseido', 'Shiseido Senka Perfect Whip', 3500, 'Shiseido')->id;
$ids['medicube'] = $mk('ty-medicube', 'Medicube Zero Pore Pad 2.0', 8900, 'Medicube')->id;
$ids['tocobo'] = $mk('ty-tocobo', 'TOCOBO Cotton Soft Sun Stick', 7500, 'TOCOBO')->id;
$ids['jumiso'] = $mk('ty-jumiso', 'JUMISO All Day Vitamin Brightening & Balancing Facial Serum', 9000, 'JUMISO')->id;
$ids['skin1004'] = $mk('ty-skin1004', 'SKIN1004 Madagascar Centella Ampoule', 8500, 'SKIN 1004')->id;
$set = $mk('ty-glow-set', 'Glow Starter Set', 19900, 'SKIN 1004', ['type' => 'set', 'manage_stock' => false]);
$ids['set'] = $set->id;
ProductSetItem::updateOrCreate(['set_product_id' => $set->id, 'member_product_id' => $ids['skin1004']], ['quantity' => 1, 'position' => 0]);
ProductSetItem::updateOrCreate(['set_product_id' => $set->id, 'member_product_id' => $ids['tocobo']], ['quantity' => 1, 'position' => 1]);

$zone = ShippingZone::firstOrCreate(['name' => 'UAE'], ['position' => 0]);
ShippingZoneLocation::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
ShippingMethod::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping'],
    ['title' => 'Free delivery', 'cost' => 0, 'enabled' => true, 'position' => 0]);

PaymentProvider::query()->delete();
$row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
$row->config = [
    'publishable_key' => 'pk_'.'test_typreview', 'secret_key' => 'sk_'.'test_typreview',
    'webhook_signing_secret' => 'wh'.'sec_typreview', 'webhook_secret' => 'whsec-url-typreview-000000000',
];
$row->save();
PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 1]);

file_put_contents(getenv('CO_STATE').'/ids.json', json_encode($ids));
echo 'ty seeded '.json_encode($ids)."\n";
