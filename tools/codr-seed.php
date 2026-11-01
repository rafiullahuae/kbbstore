<?php
/* Lane CODR preview fixture (tools/codr-preview.sh). PREVIEW FIXTURE ONLY.
   One UAE zone, card + COD with fake keys (nothing reaches a provider), a
   product, and three shoppers:
     refuser@example.com / glow-secret-1   signed-in refuser (shipped -> cancelled)
     guest-refuser@example.com, 050 222 3333   guest refuser, no account
     aisha@example.com / glow-secret-1     unaffected customer
   plus a handful of further refusals for the admin list. */
use App\Services\Orders\OrderStatus;

\App\Models\AdminUser::firstOrCreate(['email' => 'owner@example.com'], [
    'name' => 'Rafi Ullah', 'password' => \Illuminate\Support\Facades\Hash::make('preview-password'), 'role' => 'owner',
]);

$p = \App\Models\Product::updateOrCreate(['slug' => 'codr-snail'], [
    'name' => 'COSRX Advanced Snail 96 Mucin Power Essence', 'price' => 6900, 'is_visible' => true,
    'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
]);

if (! \App\Models\ShippingZone::query()->exists()) {
    $uae = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'UAE delivery', 'cost' => 1500, 'enabled' => true, 'position' => 0]);
}

$fake = 'preview-not-a-real-key';
foreach ([
    ['stripe', 'Credit or debit card', 0, ['secret_key' => 'sk_test_'.$fake, 'publishable_key' => 'pk_test_'.$fake]],
    ['cod', 'Cash on delivery', 1, []],
] as [$id, $title, $pos, $config]) {
    \App\Models\PaymentProvider::query()->updateOrCreate(['id' => $id], [
        'title' => $title, 'enabled' => true, 'mode' => 'test', 'position' => $pos, 'config' => $config,
    ]);
}

$pw = \Illuminate\Support\Facades\Hash::make('glow-secret-1');
\App\Models\Customer::updateOrCreate(['email' => 'refuser@example.com'], ['name' => 'Huda Saleh', 'password' => $pw, 'phone' => '0501234567']);
\App\Models\Customer::updateOrCreate(['email' => 'aisha@example.com'], ['name' => 'Aisha Rahman', 'password' => $pw, 'phone' => '0559998877']);

$n = 0;
$refuse = function (string $email, string $phone) use (&$n) {
    $o = \App\Models\Order::create(['order_number' => (string) (57100 + (++$n)), 'email' => $email, 'phone' => $phone,
        'status' => 'processing', 'total' => 8400, 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery']);
    app(OrderStatus::class)->moveTo($o, 'shipped', 'Preview');
    app(OrderStatus::class)->moveTo($o, 'cancelled', 'Preview', 'Customer did not accept the delivery.');
};
$refuse('refuser@example.com', '+971 50 123 4567');
$refuse('guest-refuser@example.com', '050 222 3333');
foreach (['mariam.k', 'fatima.r', 'noor.a', 'sara.h'] as $i => $who) {
    $refuse($who.'@example.com', '05'.(5 + $i % 3).' 4'.$i.'0 12'.$i.'4');
}

$sv = app(\App\Services\SettingsService::class);
$sv->set('cod_fee', 0);
$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();

if (getenv('KBB_PUBLIC_PATH')) {
    file_put_contents(getenv('KBB_PUBLIC_PATH').'/codr-ids.json', json_encode([$p->id]));
}

echo 'lane-codr seeded: ', \Illuminate\Support\Facades\DB::table('cod_refusals')->count(), " refusals\n";
