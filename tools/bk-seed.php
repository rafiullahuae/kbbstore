<?php
/* Lane BK preview: two products, a UAE flat rate, Tabby and Stripe switched on
   with PLACEHOLDER credentials (never sent anywhere -- tools/bk-fake-providers.php
   answers every provider call inside the preview), Arabic on and mirrored, and
   the shipped Arabic drafts approved so /ar shows the Arabic the owner would
   approve. PREVIEW FIXTURE ONLY. */
foreach ([['bk-glass-skin-serum', 'Glass Skin Refining Serum', 189], ['bk-rice-toner', 'Rice Water Bright Toner', 95]] as [$slug, $name, $price]) {
    \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price * 100, 'status' => 'publish', 'stock_status' => 'instock',
        'type' => 'simple', 'is_visible' => true, 'manage_stock' => true, 'stock' => 12,
    ]);
}

$zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
\App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
\App\Models\ShippingMethod::create([
    'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
    'cost' => 2000, 'enabled' => true, 'position' => 0,
]);

\App\Models\PaymentProvider::query()->delete();
$tabby = \App\Models\PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
$tabby->config = ['public_key' => 'pk_'.'test_preview', 'secret_key' => 'sk_'.'test_preview', 'merchant_code' => 'AE'];
$tabby->save();
$stripe = \App\Models\PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
$stripe->config = ['publishable_key' => 'pk_'.'test_preview', 'secret_key' => 'sk_'.'test_preview'];
$stripe->save();

$sv = app(\App\Services\SettingsService::class);
$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);
$sv->flush();

foreach (\App\Models\Translation::query()->where('locale', 'ar')->where('status', \App\Models\Translation::STATUS_DRAFT)->cursor() as $row) {
    $row->status = \App\Models\Translation::STATUS_PUBLISHED;
    $row->save();
}
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();
echo "bk preview seeded\n";
