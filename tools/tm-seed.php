<?php

declare(strict_types=1);

/*
 * Lane TM: tools/mac-seed.php's shop plus three Tamara orders shaped like
 * Order #56187 (10 Oct 2026), so the Payment journey can be photographed in
 * the admin order screen and the owner app. Run through tools/tm-preview.sh;
 * never against a real database.
 *
 *   admin      owner@example.com / preview-password
 *   owner app  owner@example.com / PIN 482615
 */
require __DIR__.'/mac-seed.php';

use App\Models\Order;
use App\Services\Payments\PaymentLog;
use Illuminate\Support\Facades\DB;

$addr = ['first_name' => 'Alia', 'last_name' => 'Abdulla', 'line1' => 'Villa 34', 'line2' => '45b St', 'city' => 'Dubai',
    'state' => 'Dubai', 'country' => 'AE', 'phone' => '0508883841'];

$make = function (string $number, string $note, int $minsAgo) use ($addr): Order {
    $o = Order::create(['order_number' => $number, 'email' => 'alia@example.com', 'phone' => '0508883841', 'status' => 'failed',
        'currency' => 'AED', 'subtotal' => 59000, 'shipping_total' => 0, 'total' => 59000, 'payment_method' => 'tamara',
        'payment_method_title' => 'Pay later with Tamara', 'billing_address' => $addr, 'shipping_address' => $addr]);
    $o->forceFill(['created_at' => now()->subMinutes($minsAgo), 'updated_at' => now()->subMinutes($minsAgo)])->save();
    foreach ([['Dr. Althea 345 Relief Cream + Mist Spray Set', 21500], ['Anua PDRN Glass Skin Set', 37500]] as [$n, $p]) {
        $o->items()->create(['name' => $n, 'quantity' => 1, 'unit_price' => $p, 'subtotal' => $p, 'total' => $p]);
    }
    $o->notes()->create(['author' => 'system', 'is_customer_note' => false, 'content' => $note]);

    return $o;
};

// 1. Exactly what #56187 carries today: the old note, nothing else recorded
//    about the payment. The landing source, basket and one email are preview
//    data, so the two new cards have something to show.
$o = $make('56187', 'Status changed from pending to failed. The payment could not be started.', 30);
DB::table('orders')->where('id', $o->id)->update(['src_channel' => 'instagram', 'src_campaign' => null,
    'src_attr' => json_encode(['first' => ['ch' => 'instagram', 's' => 'instagram.com', 'm' => '', 'c' => '', 'k' => '', 'p' => '/', 'd' => 1], 'last' => null, 'days' => 0])]);
$cartId = DB::table('carts')->insertGetId(['token' => (string) \Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active',
    'ct_order_id' => $o->id, 'ct_first_at' => now()->subMinutes(44), 'ct_last_at' => now()->subMinutes(31), 'ct_added' => 2, 'ct_value' => 59000,
    'created_at' => now()->subMinutes(44), 'updated_at' => now()]);
foreach (DB::table('products')->orderBy('id')->limit(2)->pluck('id') as $i => $pid) {
    DB::table('cart_events')->insert(['cart_id' => $cartId, 'type' => 1, 'product_id' => $pid, 'qty' => 1, 'qty_after' => 1, 'unit_price' => 21500, 'created_at' => now()->subMinutes(44 - $i * 6)]);
}
DB::table('mail_deliveries')->insert(['kind' => 'order.reminder_first', 'recipient' => 'alia@example.com', 'subject' => 'Your order #56187 is waiting',
    'transport' => 'smtp', 'status' => 'sent', 'created_at' => now()->subMinutes(1), 'updated_at' => now()->subMinutes(1)]);

// 2. The same failure after this package: the reason is on the note and in the log.
$why = 'Tamara refused the checkout: HTTP 400 — Invalid request; consumer.phone_number: invalid_phone_number.';
$o = $make('56188', 'Status changed from pending to failed. The payment could not be started. ' . $why, 20);
PaymentLog::record('tamara', 'error', 'checkout_refused', $why, ['order' => '56188', 'http_status' => 400, 'reason' => 'checkout_refused'], 'live');

// 3. Reached Tamara, came back without paying.
$o = $make('56189', 'Status changed from pending to failed. The shopper came back without finishing the payment; nothing was charged and their basket was given back.', 10);
PaymentLog::record('tamara', 'info', 'checkout_created', 'Tamara accepted the checkout; the shopper was sent to Tamara\'s page.', ['order' => '56189', 'http_status' => 200, 'reference' => 'tam_0f3a'], 'live');
PaymentLog::record('tamara', 'info', 'returned', 'The shopper came back from Tamara to the not-finished page (the return link said "canceled"; the webhook decides).', ['order' => '56189', 'outcome' => 'pending', 'status' => 'canceled'], 'live');

echo "Lane TM orders 56187, 56188, 56189 seeded.\n";
