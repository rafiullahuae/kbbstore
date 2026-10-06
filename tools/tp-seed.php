<?php
/* Seed the Lane TP preview: a basket's worth of products, a UAE zone and COD
   so the cart and checkout render their totals. The content pages come from
   the migrations themselves (the owner's pasted text). Idempotent. PREVIEW
   FIXTURE ONLY. */
foreach ([
    ['Anua - Heartleaf 77% Soothing Toner', 'tp-anua-toner', 89],
    ['Medicube - PDRN Pink Collagen Capsule Cream', 'tp-medicube-cream', 149],
] as [$name, $slug, $price]) {
    \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price * 100, 'is_visible' => true,
        'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

if (! \App\Models\ShippingZone::query()->exists()) {
    $uae = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'UAE delivery', 'cost' => 1500, 'enabled' => true, 'position' => 0]);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'free_shipping', 'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1]);
}

\App\Models\PaymentProvider::query()->updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
app(\App\Services\CartPage::class)->save(['layout' => getenv('TP_LAYOUT') ?: 'squeeze']);
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();

if (getenv('KBB_PUBLIC_PATH')) {
    file_put_contents(getenv('KBB_PUBLIC_PATH') . '/tp-ids.json', json_encode(
        \App\Models\Product::query()->where('slug', 'like', 'tp-%')->orderBy('id')->pluck('id')->all()
    ));
}
echo "lane-tp seeded\n";
