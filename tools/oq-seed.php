<?php

declare(strict_types=1);

/*
 * Lane OQ: on top of tools/mac-seed.php + tools/emirate-seed.php, put
 * "Medicube Zero Pore Pad 2.0" on more orders at several quantities, so the
 * product search on Store -> Orders has something to find and the order
 * screens show a qty-3 line beside a qty-1 line and a 2-piece free gift. Run
 * through tools/oq-preview.sh; never against a real database.
 */

use Illuminate\Support\Facades\DB;

$medi = DB::table('order_items')->where('name', 'Medicube Zero Pore Pad 2.0')->first();
$cosrx = DB::table('order_items')->where('name', 'like', 'COSRX%')->first();

$line = function (int $order, object $from, int $qty, bool $gift = false, ?array $variant = null): void {
    DB::table('order_items')->insert([
        'order_id' => $order, 'product_id' => $from->product_id, 'name' => $from->name, 'sku' => $from->sku, 'quantity' => $qty,
        'unit_price' => $gift ? 0 : $from->unit_price, 'subtotal' => $gift ? 0 : $from->unit_price * $qty, 'total' => $gift ? 0 : $from->unit_price * $qty,
        'is_gift' => $gift, 'variant_attributes' => $variant ? json_encode($variant) : null, 'created_at' => now(), 'updated_at' => now(),
    ]);
};

// The newest order by placed date opens first on both screens: a qty-3 line,
// a qty-1 line and a 2-piece free gift.
$newest = (int) DB::table('orders')->orderByDesc('created_at')->value('id');
DB::table('order_items')->where('order_id', $newest)->delete();
$line($newest, $medi, 3, false, ['Size' => '70 pads']);
$line($newest, $cosrx, 1);
$line($newest, $cosrx, 2, true);
DB::table('orders')->where('id', $newest)->update(['free_gift_count' => 2]);

// The second newest is a COMPLETED order (read-only lines): qty 3 beside qty 1.
$second = (int) DB::table('orders')->where('id', '!=', $newest)->orderByDesc('created_at')->value('id');
DB::table('order_items')->where('order_id', $second)->delete();
$line($second, $medi, 3, false, ['Size' => '70 pads']);
$line($second, $cosrx, 1);
DB::table('orders')->where('id', $second)->update(['status' => 'completed']);

// Eleven more orders take the pads, one to four at a time.
$others = DB::table('orders')->where('id', '!=', $newest)->orderByDesc('created_at')->limit(30)->pluck('id')->all();
foreach (array_slice($others, 2, 11) as $i => $id) {
    if (! DB::table('order_items')->where('order_id', $id)->where('name', $medi->name)->exists()) {
        $line($id, $medi, 1 + ($i % 4), false, ['Size' => '70 pads']);
    }
}

echo 'Lane OQ seed: newest order '.$newest.', completed qty-3 order '.$second.'; Medicube on '.DB::table('order_items')->where('name', $medi->name)->distinct()->count('order_id')." orders.\n";
