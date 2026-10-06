<?php
/* Lane PY (payment boxes) preview fixture: a basket's worth of products, one
   UAE zone, and all four gateways on with fake keys (nothing here can reach a
   provider), in the order the owner's checkout shows them. Tabby carries the
   title from his screenshot, "Tabby Installments". PREVIEW FIXTURE ONLY. */
\App\Models\AdminUser::firstOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

foreach ([
    ['Anua - PDRN Glass Skin Set', 'pay-anua-pdrn', 375],
    ['Arencia - Vitamin C Booster Trio', 'pay-arencia-trio', 279],
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
}

$fake = 'preview-not-a-real-key';
foreach ([
    ['tabby', 'Tabby Installments', 0, ['public_key' => 'pk_test_'.$fake, 'secret_key' => 'sk_test_'.$fake]],
    ['tamara', 'Pay later with Tamara', 1, ['api_token' => $fake, 'notification_token' => $fake]],
    ['stripe', 'Credit or debit card', 2, ['secret_key' => 'sk_test_'.$fake, 'publishable_key' => 'pk_test_'.$fake]],
    ['cod', 'Cash on delivery', 3, []],
] as [$id, $title, $pos, $config]) {
    \App\Models\PaymentProvider::query()->updateOrCreate(['id' => $id], [
        'title' => $title, 'enabled' => true, 'mode' => 'test', 'position' => $pos, 'config' => $config,
    ]);
}

$sv = app(\App\Services\SettingsService::class);
$sv->set('cod_fee', 0);
$sv->set(\App\Support\Locale::SETTING_ENABLED, getenv('PAY_AR') === '1');
$sv->set(\App\Support\Locale::SETTING_RTL, getenv('PAY_AR') === '1');
$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();

if (getenv('KBB_PUBLIC_PATH')) {
    file_put_contents(getenv('KBB_PUBLIC_PATH').'/pay-ids.json', json_encode(
        \App\Models\Product::query()->where('slug', 'like', 'pay-%')->orderBy('id')->pluck('id')->all()
    ));
}

echo 'lane-pay seeded: ar=', getenv('PAY_AR') === '1' ? 'on' : 'off', "\n";
