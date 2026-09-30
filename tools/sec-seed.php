<?php
/* Seed the Lane SEC preview: an owner to sign in as, and enough of a shop for
   all FIVE navigations the download gate covers to have something to point at.

   The pictures this produces are of a console whose SESSION HAS DIED, which is
   the state the gate exists for -- so what matters here is only that each
   button is real and enabled:

     * the Orders list, so "Export" and the bulk "Print" select are live;
     * one order with items, so its detail card draws the four document
       buttons (Invoice, Packing slip, Delivery note, Dispatch label) -- the
       four addresses that are built by Admin\InvoiceController and never
       written in the console at all;
     * a customer, so Customers -> Export is live;
     * a product, so Catalog -> Products -> Export is live and the Catalog
       list has rows to lose.

   Integer fils throughout; no float is constructed here. */

\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

for ($i = 1; $i <= 6; $i++) {
    \App\Models\Product::create([
        'name' => 'Lane SEC Heartleaf Toner ' . $i . ' 250ml',
        'slug' => 'lanesec-' . $i,
        'sku' => 'SEC-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'status' => 'publish',
        'price' => 8900 + $i * 100,
        'stock' => 20 + $i,
    ]);
}

foreach ([
    ['SEC-1001', 'processing'],
    ['SEC-1002', 'completed'],
    ['SEC-1003', 'on-hold'],
] as [$number, $status]) {
    $order = \App\Models\Order::create([
        'order_number' => $number,
        'email' => 'buyer@preview.test', 'phone' => '+971500000000',
        'status' => $status, 'currency' => 'AED',
        'subtotal' => 30000, 'discount_total' => 0, 'shipping_total' => 1500,
        'tax_total' => 0, 'fee_total' => 0, 'total' => 31500,
        'payment_method' => 'cod',
        /* One JSON column, not first_name/city columns: this schema keeps the
           whole address in `billing_address`. The Orders list reads city and
           phone off it, so an order with none draws em-dashes in two columns
           and the pictures would be of that instead of the shop. */
        'billing_address' => [
            'first_name' => 'Preview', 'last_name' => 'Buyer',
            'address_1' => '12 Jumeirah Beach Road', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'postcode' => '00000',
            'phone' => '+971500000000', 'email' => 'buyer@preview.test',
        ],
    ]);

    $order->items()->create([
        'name' => 'Lane SEC Heartleaf Toner 1 250ml', 'sku' => 'SEC-0001',
        'quantity' => 2, 'unit_price' => 15000, 'subtotal' => 30000, 'total' => 30000,
    ]);

    echo $number, ' id=', $order->getKey(), "\n";
}

\App\Models\Customer::create([
    'name' => 'Preview Shopper', 'email' => 'shopper@preview.test',
    'first_name' => 'Preview', 'last_name' => 'Shopper',
    'phone' => '+971500000001', 'orders_count' => 3, 'total_spent' => 94500,
]);

echo "seeded\n";
