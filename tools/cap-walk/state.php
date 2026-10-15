<?php
/* Lane CAP preview: what the capture wrote, as JSON. Never ships (tools/). */
use Illuminate\Support\Facades\DB;
echo json_encode([
    'customers' => DB::table('customers')->orderBy('id')->get(['id', 'email', 'name', 'phone', 'origin', 'password'])->map(fn ($c) => (array) $c + ['password' => $c->password !== null])->all(),
    'carts' => DB::table('carts')->whereNotNull('ct_email')->orderBy('id')->get(['id', 'status', 'customer_id', 'ct_email', 'ct_email_customer_id', 'ct_email_n'])->all(),
    'orders' => DB::table('orders')->orderBy('id')->get(['id', 'order_number', 'email', 'customer_id', 'payment_method'])->all(),
    'recoveries' => DB::table('cart_recoveries')->orderBy('id')->get(['cart_id', 'email', 'source', 'stage', 'cancelled_at', 'cancel_reason'])->all(),
], JSON_PRETTY_PRINT)."\n";
