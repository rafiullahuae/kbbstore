<?php

declare(strict_types=1);

/*
 * Lane QK10: tools/mac-seed.php's shop plus fourteen tracked carts, so the
 * console's Cart Tracking (now a top-level sidebar row) and the owner app's
 * Cart tracking screen can be photographed. Run through tools/qk10-preview.sh;
 * never against a real database.
 *
 *   admin      owner@example.com / preview-password
 *   owner app  owner@example.com / PIN 482615
 */
require __DIR__.'/mac-seed.php';

use Illuminate\Support\Facades\DB;

$products = DB::table('products')->orderBy('id')->limit(6)->get(['id', 'price']);
$customers = DB::table('customers')->orderBy('id')->limit(4)->get(['id', 'email']);
$orders = DB::table('orders')->orderByDesc('id')->limit(3)->get(['id', 'customer_id', 'total']);
$countries = ['AE', 'AE', 'SA', 'AE', 'QA', 'AE', 'OM', 'AE', 'KW', 'AE', 'AE', 'BH', 'AE', 'SA'];

for ($i = 0; $i < 14; $i++) {
    $id = DB::table('carts')->insertGetId(['token' => (string) \Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'created_at' => now()->subMinutes(9 + $i * 47), 'updated_at' => now()]);
    $value = 0;
    $added = 0;
    foreach ([$products[$i % 6], $products[($i + 2) % 6]] as $j => $p) {
        if ($j === 1 && $i % 3 === 1) {
            continue;
        }
        $q = 1 + ($i + $j) % 2;
        DB::table('cart_items')->insert(['cart_id' => $id, 'product_id' => $p->id, 'quantity' => $q, 'unit_price' => $p->price, 'created_at' => now(), 'updated_at' => now()]);
        $value += $q * (int) $p->price;
        $added += $q;
    }
    $set = ['ct_ip' => '94.200.'.(10 + $i).'.9', 'ct_net' => '94.200.'.(10 + $i).'.0/24', 'ct_country' => $countries[$i],
        'ct_ua' => 'Mozilla/5.0 (iPhone)', 'ct_bot_score' => $i === 5 ? 90 : 0, 'ct_bot_flags' => 0, 'ct_value' => $value, 'ct_added' => $added,
        'ct_removed' => $i % 4 === 2 ? 1 : 0, 'ct_first_at' => now()->subMinutes(30 + $i * 47), 'ct_last_at' => now()->subMinutes(4 + $i * 47)];
    if ($i < 4 && isset($customers[$i])) {
        $set['customer_id'] = $customers[$i]->id;
    }
    if (in_array($i, [1, 6, 9], true) && $orders->isNotEmpty()) {
        $o = $orders->shift();
        $set['ct_order_id'] = $o->id;
        $set['customer_id'] = $o->customer_id;
    }
    DB::table('carts')->where('id', $id)->update($set);
}

echo "qk10: 14 tracked carts\n";
