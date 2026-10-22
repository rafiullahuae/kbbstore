<?php

declare(strict_types=1);

/*
 * Lane FG2: tools/fg-seed.php's shop and the owner's five ranges with their
 * gifts, then the state the redesigned admin screen is photographed in: three
 * gifts low or out of stock, and a month of paid orders that carried gifts (so
 * Overview's numbers are real). Switch OFF, so "Things to check" shows.
 * Run through `FG_SEED=fg2-seed.php FG_ON=0 sh tools/fg-preview.sh`; never
 * against a real database.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/fg-seed.php';

$id = static fn (string $name) => (int) DB::table('products')->where('name', $name)->value('id');

// Low and out of stock, as the owner will meet them.
DB::table('products')->where('id', $id('The Glow Set'))->update(['stock' => 8]);
DB::table('products')->where('id', $id('Black Rice Hyaluronic Toner Mini'))->update(['stock' => 15]);
DB::table('products')->where('id', $id('Rose PDRN Peptide Glowy Balm Petal Pink'))->update(['stock' => 0, 'stock_status' => 'outofstock']);

// A month of orders with gifts: the lines exactly as checkout writes them.
$lines = [
    [['Tea Tree Sheet Mask', 2]],
    [['Tea Tree Sheet Mask', 3], ['Black Rice Hyaluronic Toner Mini', 1]],
    [['Tea Tree Sheet Mask', 3], ['Black Rice Hyaluronic Toner Mini', 1], ['Mugwort Calming Clay Mask Mini', 1], ['Senka Perfect Whip Fresh Beauty Face Foam', 1]],
];
foreach (range(1, 14) as $n) {
    $gifts = $lines[$n % 3];
    $oid = DB::table('orders')->insertGetId([
        'order_number' => 'FG2-'.(56200 + $n), 'email' => 'shopper'.$n.'@example.com', 'status' => $n % 5 === 0 ? 'completed' : 'processing',
        'currency' => 'AED', 'subtotal' => 35000, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'fee_total' => 0, 'total' => 35000,
        'payment_method' => 'cod', 'free_gift_count' => array_sum(array_column($gifts, 1)),
        'created_at' => now()->subDays($n * 2), 'updated_at' => now()->subDays($n * 2),
    ]);
    foreach ($gifts as [$name, $qty]) {
        DB::table('order_items')->insert(['order_id' => $oid, 'product_id' => $id($name), 'name' => $name, 'quantity' => $qty, 'unit_price' => 0,
            'subtotal' => 0, 'total' => 0, 'is_gift' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}

app(\App\Services\FreeGifts::class)->compile();
\Illuminate\Support\Facades\Cache::flush();
echo "fg2: stock set, ".DB::table('orders')->where('order_number', 'like', 'FG2-%')->count()." orders with gifts\n";
