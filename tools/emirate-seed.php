<?php

declare(strict_types=1);

/*
 * Lane EM: orders spread over the emirates, on top of tools/mac-seed.php's shop,
 * in every address shape the shop really holds -- checkout (emirate in state),
 * cart popup (emirate in city), WooCommerce (area as state, emirate as city;
 * ISO codes), Arabic, Al Ain, abroad, and none. Their ship_emirate is left NULL,
 * as the checkout leaves it, so the first look at Store -> Orders reads them.
 * Run through tools/emirate-preview.sh; never against a real database.
 *
 *   admin:     owner@preview.test / preview-secret-1
 *   owner app: owner@example.com / PIN 482615 (mac-seed)
 */

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$shapes = [
    // [weight, address]
    [9, ['line1' => 'Marina Gate 2', 'city' => 'Dubai Marina', 'state' => 'Dubai']],
    [5, ['line1' => 'Villa 14', 'city' => 'dubai']],
    [4, ['line1' => 'Bldg 7', 'state' => 'Al Barsha', 'city' => 'Dubai']],
    [2, ['line1' => 'Tower 3', 'state' => 'AE-DU', 'city' => 'Business Bay']],
    [6, ['line1' => 'Khalifa City A', 'city' => 'Khalifa City', 'state' => 'Abu Dhabi']],
    [3, ['line1' => 'Al Jimi', 'city' => 'Al Ain', 'state' => 'Abu Dhabi']],
    [1, ['line1' => 'حي المويجعي', 'city' => 'العين']],
    [5, ['line1' => 'Al Nahda Tower B', 'city' => 'Al Nahda', 'state' => 'Sharjah']],
    [2, ['line1' => 'Al Majaz 2', 'state' => 'SHJ']],
    [3, ['line1' => 'Al Nuaimiya', 'city' => 'Ajman', 'state' => 'Ajman']],
    [1, ['line1' => 'Al Salamah', 'state' => 'Umm Al Quwain']],
    [2, ['line1' => 'Al Nakheel', 'city' => 'Ras Al Khaimah', 'state' => 'Ras al Khaimah']],
    [1, ['line1' => 'Fujairah Corniche', 'state' => 'الفجيرة']],
    [1, ['line1' => 'Olaya Street', 'city' => 'Riyadh', 'state' => 'Riyadh', 'country' => 'SA']],
    [1, ['line1' => 'West Bay', 'city' => 'Doha', 'country' => 'QA']],
];

$bag = [];
foreach ($shapes as [$w, $a]) {
    for ($i = 0; $i < $w; $i++) {
        $bag[] = $a;
    }
}

$names = ['Aisha Rahman', 'Layla Hassan', 'Sara Ahmed', 'Hind Al Mansoori', 'Rania Saeed', 'Noura Khalid', 'Maryam Yousef', 'Dana Ibrahim', 'Huda Salem', 'Reem Faisal'];
$statuses = ['processing', 'completed', 'completed', 'shipped', 'processing', 'pending', 'cancelled', 'completed', 'onhold', 'failed'];
$pays = [['tabby', 'Tabby'], ['tamara', 'Tamara'], ['cod', 'Cash on delivery'], ['stripe', 'Card']];

for ($i = 0; $i < 96; $i++) {
    $a = $bag[($i * 7) % count($bag)];
    [$first, $last] = explode(' ', $names[$i % count($names)], 2);
    $address = ['first_name' => $first, 'last_name' => $last] + $a + ['country' => 'AE', 'phone' => '+971 50 600 '.str_pad((string) $i, 4, '0', STR_PAD_LEFT)];
    $at = now()->subHours(30 + $i * 9);
    $total = 9000 + ($i * 1373) % 40000;
    $status = $statuses[$i % count($statuses)];
    $pay = $pays[$i % count($pays)];

    DB::table('orders')->insert([
        'order_number' => (string) (30000 + $i), 'email' => strtolower($first).$i.'@example.com', 'phone' => $address['phone'],
        'status' => $status, 'billing_address' => json_encode($address), 'shipping_address' => json_encode($address),
        'subtotal' => $total, 'total' => $total, 'currency' => 'AED', 'payment_method' => $pay[0], 'payment_method_title' => $pay[1],
        'paid_at' => in_array($status, ['processing', 'completed', 'shipped', 'onhold'], true) ? $at : null,
        'created_at' => $at, 'updated_at' => $at,
    ]);
}

// One order with no address at all, as an old import can carry.
DB::table('orders')->insert(['order_number' => '29999', 'email' => 'noaddress@example.com', 'status' => 'completed', 'subtotal' => 5000, 'total' => 5000,
    'currency' => 'AED', 'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)]);

echo 'Lane EM seed: '.DB::table('orders')->count().' orders, '.DB::table('orders')->whereNull('ship_emirate')->count()." with the emirate not read yet.\n";
