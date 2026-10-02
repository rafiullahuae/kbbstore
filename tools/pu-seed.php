<?php
/*
 * Seed the Lane PU preview: Store -> Orders -> an order, with orders shaped the
 * way the WooCommerce (HPOS) import writes them -- see OrderImporter::
 * addressSnapshot(): first_name / last_name / company / line1 / line2 / city /
 * state / postcode / country / phone, and payment_method, payment_method_title,
 * transaction_id and date_paid copied across as they were.
 *
 * #33415 is the owner's own report: Cash on delivery, Processing.
 * MONEY IS INTEGER FILS.
 */
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

\App\Models\AdminUser::create(['name' => 'Preview Owner', 'email' => 'owner@preview.test', 'password' => 'preview-secret-1', 'role' => 'owner']);
\App\Models\AdminUser::create(['name' => 'Sara Support', 'email' => 'support@preview.test', 'password' => 'preview-secret-1', 'role' => 'support']);

foreach ([['cod', 'Cash on delivery'], ['tabby', 'Pay in 4 with Tabby'], ['tamara', 'Tamara'], ['stripe', 'Credit or debit card']] as $i => [$id, $title]) {
    \App\Models\PaymentProvider::updateOrCreate(['id' => $id], ['title' => $title, 'enabled' => $id === 'cod', 'mode' => 'test', 'position' => $i]);
}

$aisha = Customer::create(['email' => 'aisha.khan@example.com', 'name' => 'Aisha Khan', 'first_name' => 'Aisha', 'last_name' => 'Khan', 'phone' => '+971501234567']);
Customer::create(['email' => 'omar@example.com', 'name' => 'Omar Haddad', 'first_name' => 'Omar', 'last_name' => 'Haddad', 'phone' => '+971559876543']);

$products = Product::query()->limit(4)->get();

$addr = fn (string $first, string $last, string $line1, string $city, string $state) => [
    'first_name' => $first, 'last_name' => $last, 'line1' => $line1, 'line2' => 'Apartment 1204',
    'city' => $city, 'state' => $state, 'country' => 'AE', 'phone' => '+971501234567',
];

$make = function (string $number, array $attrs, int $items = 2) use ($products, $aisha, $addr) {
    $total = 0;
    $order = Order::create(array_merge([
        'wc_order_id' => (int) $number, 'order_number' => $number, 'customer_id' => $aisha->id,
        'email' => 'aisha.khan@example.com', 'phone' => '+971501234567', 'currency' => 'AED',
        'billing_address' => $addr('Aisha', 'Khan', 'Marina Gate 2, Al Marsa Street', 'Dubai', 'Dubai'),
        'shipping_address' => $addr('Aisha', 'Khan', 'Marina Gate 2, Al Marsa Street', 'Dubai', 'Dubai'),
        'shipping_method' => 'Delivery Charges', 'origin' => 'woocommerce-import',
        'shipping_total' => 2000, 'subtotal' => 0, 'total' => 0,
    ], $attrs));
    foreach ($products->take($items)->values() as $i => $p) {
        $unit = 8900 + $i * 2500;
        $qty = $i + 1;
        OrderItem::create(['order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name, 'brand' => 'COSRX',
            'quantity' => $qty, 'unit_price' => $unit, 'subtotal' => $unit * $qty, 'total' => $unit * $qty]);
        $total += $unit * $qty;
    }
    $order->forceFill(['subtotal' => $total, 'total' => $total + 2000])->save();

    return $order;
};

$when = now()->subDays(3);
// The report: imported, Cash on delivery, Processing. WooCommerce stamps
// date_paid on a COD order when it reaches Processing, so the import carries it.
$make('33415', ['status' => 'processing', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    'paid_at' => $when, 'created_at' => $when, 'updated_at' => $when]);
$make('33401', ['status' => 'completed', 'payment_method' => 'tabby_installments', 'payment_method_title' => 'Tabby - Pay in 4',
    'transaction_id' => '6f3b2a1c-9d4e-4b7a-8c21-5e0f9a7d3b11', 'paid_at' => now()->subDays(20), 'created_at' => now()->subDays(20), 'completed_at' => now()->subDays(17)], 3);
$make('33388', ['status' => 'completed', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    'paid_at' => now()->subDays(40), 'completed_at' => now()->subDays(38), 'created_at' => now()->subDays(41)], 1);
$make('33420', ['status' => 'pending', 'payment_method' => 'ziina', 'payment_method_title' => 'Ziina',
    'created_at' => now()->subHours(5)], 2);
$stripe = $make('33422', ['status' => 'processing', 'payment_method' => 'stripe', 'payment_method_title' => 'Credit or debit card',
    'transaction_id' => 'pi_3Q8xYzLkd8a1B2c30Xy9Abc', 'paid_at' => now()->subDays(1), 'created_at' => now()->subDays(1)], 2);
\App\Models\Refund::create(['order_id' => $stripe->id, 'amount' => 2500, 'reason' => 'Damaged lid', 'refunded_by' => 'Preview Owner', 'status' => 'succeeded', 'provider_ref' => 're_3Q8xYz']);
$make('33425', ['status' => 'processing', 'payment_method' => 'tabby', 'payment_method_title' => 'Pay in 4 with Tabby',
    'transaction_id' => 'b1e7c9d2-tabby-auth', 'paid_at' => now()->subDays(2), 'created_at' => now()->subDays(2)], 1);
$make('33430', ['status' => 'failed', 'payment_method' => 'tamara', 'payment_method_title' => 'Tamara', 'created_at' => now()->subHours(2)], 1);
// A guest, matched by email the way the Customer history card matches one.
$make('33410', ['status' => 'processing', 'customer_id' => null, 'email' => 'guest.buyer@example.com', 'payment_method' => 'cod',
    'payment_method_title' => 'Cash on delivery', 'created_at' => now()->subDays(6),
    'billing_address' => $addr('Layla', 'Guest', 'Al Wahda Street', 'Sharjah', 'Sharjah'),
    'shipping_address' => $addr('Layla', 'Guest', 'Al Wahda Street', 'Sharjah', 'Sharjah')], 1);
$make('33302', ['status' => 'completed', 'customer_id' => null, 'email' => 'guest.buyer@example.com', 'payment_method' => 'cod',
    'payment_method_title' => 'Cash on delivery', 'created_at' => now()->subDays(60), 'completed_at' => now()->subDays(58)], 2);
// Enough history on Aisha to need a second page.
for ($n = 1; $n <= 9; $n++) {
    $make((string) (33000 + $n * 7), ['status' => $n % 4 === 0 ? 'cancelled' : 'completed', 'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery', 'created_at' => now()->subDays(60 + $n * 9), 'completed_at' => now()->subDays(58 + $n * 9)], 1 + $n % 3);
}

echo 'seeded ', Order::count(), " orders\n";
