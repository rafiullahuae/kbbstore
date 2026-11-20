<?php

// Lane AP-API preview seed: an owner, a few products (two with cost prices),
// one live and one revoked connection key, some API calls and a waiting retry.
// Run through `artisan tinker` by tools/apapi-preview.sh.

\App\Models\AdminUser::firstOrCreate(['email' => 'owner@example.com'], [
    'name' => 'Preview Owner', 'password' => \Illuminate\Support\Facades\Hash::make('preview-password'), 'role' => 'owner',
]);

$brand = \App\Models\Brand::firstOrCreate(['slug' => 'cosrx'], ['name' => 'COSRX']);
$names = ['Advanced Snail 96 Mucin Power Essence', 'Low pH Good Morning Gel Cleanser', 'Beauty of Joseon Relief Sun SPF50+', 'Anua Heartleaf 77% Soothing Toner', 'Round Lab Dokdo Toner'];
foreach ($names as $i => $name) {
    $p = \App\Models\Product::firstOrCreate(['slug' => 'apapi-' . $i], ['name' => $name, 'brand_id' => $brand->id, 'status' => 'publish', 'is_visible' => true,
        'type' => 'simple', 'price' => 6000 + $i * 1500, 'sale_price' => $i === 1 ? 5200 : null, 'stock_status' => 'instock', 'sku' => 'KBB-' . (3100 + $i)]);
    if ($i < 2) {
        \Illuminate\Support\Facades\DB::table('products')->where('id', $p->id)->update(['cost_price' => 3100 + $i * 400]);
    }
}

$tokens = app(\App\Services\Autopilot\ApiTokens::class);
$mac = $tokens->create('Office MacBook', ['orders', 'products'], null);
$old = $tokens->create('Old laptop', ['orders'], null);
$tokens->revoke($old['id']);
\Illuminate\Support\Facades\DB::table('autopilot_tokens')->where('id', $mac['id'])->update(['last_used_at' => now()->subMinutes(3)]);

foreach ([['/autopilot-api/v1/orders/daily', 200, 90], ['/autopilot-api/v1/products', 200, 200], ['/autopilot-api/v1/products', 200, 112], ['/autopilot-api/v1/orders', 200, 57], ['/autopilot-api/v1/ping', 401, 0]] as $k => [$path, $status, $rows]) {
    \Illuminate\Support\Facades\DB::table('autopilot_api_log')->insert(['token_id' => $status === 200 ? $mac['id'] : null, 'path' => $path,
        'status' => $status, 'rows' => $rows, 'created_at' => now()->subMinutes(10 - $k)]);
}

\Illuminate\Support\Facades\DB::table('marketing_capi_retries')->insert(['event' => 'Purchase', 'event_id' => 'purchase-58012',
    'payload' => \Illuminate\Support\Facades\Crypt::encryptString('{}'), 'attempts' => 1, 'next_at' => now()->addMinutes(10), 'first_at' => now()->subMinutes(5)]);

echo "seeded\n";
