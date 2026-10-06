<?php
/* Seed the Lane CD preview (copied from Lane CK's): the checkout with the
   desktop totals card and the floating labels on and off, a 10% coupon and a
   COD fee. Five products (the owner's screenshot had five lines), the squeezed cart
   layout the live shop runs, a Dubai zone ahead of the UAE one so the emirate
   visibly prices the delivery, COD, and a returning customer whose address was
   saved through the cart's popup (apartment in line1, area in line2, emirate
   in city, state empty). Idempotent. PREVIEW FIXTURE ONLY. */
\App\Models\AdminUser::firstOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

foreach ([
    ['Medicube - Vanilla Deodrant and Body Mist Duo', 'ck-vanilla-duo', 195],
    ['Anua - PDRN Glass Skin Set', 'ck-anua-pdrn', 375],
    ['Arencia - Vitamin C Booster Trio', 'ck-arencia-trio', 279],
    ['Medicube - PDRN Glow Booster Set (Pink Edition)', 'ck-medicube-pink', 850],
    ['medicube - Vanilla & Pistachio Deodorant', 'ck-pistachio', 99],
] as [$name, $slug, $price]) {
    \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price * 100, 'is_visible' => true,
        'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

if (! \App\Models\ShippingZone::query()->exists()) {
    $dubai = \App\Models\ShippingZone::create(['name' => 'Dubai', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $dubai->id, 'type' => 'state', 'code' => 'AE:Dubai']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $dubai->id, 'type' => 'flat_rate', 'title' => 'Dubai delivery', 'cost' => 1000, 'enabled' => true, 'position' => 0]);
    $uae = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 1]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'UAE delivery', 'cost' => 2500, 'enabled' => true, 'position' => 0]);
}

\App\Models\PaymentProvider::query()->updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

$c = \App\Models\Customer::firstOrCreate(['email' => 'aisha@preview.test'], [
    'name' => 'Aisha Khan', 'first_name' => 'Aisha', 'last_name' => 'Khan',
    'phone' => '0501234567', 'password' => 'preview-secret-1',
]);
if (! $c->addresses()->exists()) {
    $c->addresses()->create(['type' => 'shipping', 'is_default' => true, 'label' => 'home',
        'line1' => 'Building 1-10, G-04', 'line2' => 'Al Quoz Industrial Area 2', 'city' => 'Dubai', 'country' => 'AE']);
}

app(\App\Services\CartPage::class)->save(['layout' => 'squeeze']);

$sv = app(\App\Services\SettingsService::class);
$sv->set('checkoutpage_sum_totals', getenv('CD_TOTALS') !== '0');
$sv->set('checkoutpage_float_labels', getenv('CD_FLOAT') !== '0');
$sv->set('cod_fee', 1000);
// The inline-validation module, so the error state has a picture.
$sv->setModule('inline_validation', true);
// The cart's discount-code field, which the floating label also covers.
$sv->setModule('cart_coupon_field', true);
\App\Models\Coupon::firstOrCreate(['code' => 'CDTEN'], ['type' => 'percent', 'amount' => 1000]);
$sv->set(\App\Support\Locale::SETTING_ENABLED, getenv('CD_AR') === '1');
$sv->set(\App\Support\Locale::SETTING_RTL, getenv('CD_AR') === '1');
$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();

echo 'lane-cd seeded: totals=', getenv('CD_TOTALS') !== '0' ? 'on' : 'off', ' float=', getenv('CD_FLOAT') !== '0' ? 'on' : 'off', ' ar=', getenv('CD_AR') === '1' ? 'on' : 'off', "\n";

/* The ids the shot script adds to the basket, written into the preview's own
   webroot so the script never guesses. */
if (getenv('KBB_PUBLIC_PATH')) {
    file_put_contents(getenv('KBB_PUBLIC_PATH') . '/cd-ids.json', json_encode(
        \App\Models\Product::query()->where('slug', 'like', 'ck-%')->orderBy('id')->pluck('id')->all()
    ));
}
