<?php
// Lane CC: Lane LH's homepage seed, plus one guest basket with a fixed token so
// Lighthouse can open /cart/ and /checkout/ with a cookie (cc-cookie.php).
require base_path('tools/lh-seed.php');
$p = \App\Models\Product::query()->where('status', 'publish')->where('stock_status', 'instock')->orderBy('id')->first()
    ?? \App\Models\Product::query()->orderBy('id')->first();
$c = \App\Models\Cart::query()->create(['token' => '0c0c0c0c-0c0c-4c0c-8c0c-0c0c0c0c0c0c', 'status' => 'active', 'last_activity_at' => now()]);
\App\Models\CartItem::query()->create(['cart_id' => $c->id, 'product_id' => $p->id, 'quantity' => 1, 'unit_price' => (int) ($p->sale_price ?: $p->price ?: 1000)]);
echo "cc cart seeded with product {$p->id}\n";
