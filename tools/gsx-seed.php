<?php
// Lane GSX: tools/fg-seed.php's free gifts, plus the owner's case of 27 Nov 2026 -- a gift-only Maybelline mascara
// counted at 0 and marked out of stock, picked in the AED 100 range (FG_SEED=gsx-seed.php sh tools/fg-preview.sh).
require __DIR__.'/fg-seed.php';

use Illuminate\Support\Facades\DB;

$id = DB::table('products')->insertGetId([
    'slug' => 'gsx-maybelline-sky-high-mini', 'name' => 'Maybelline New York - Lash Sensational Sky High Mascara Cosmic Black - 4.5ml', 'type' => 'simple',
    'status' => 'publish', 'is_visible' => false, 'gift_only' => true, 'price' => 2500, 'manage_stock' => true, 'stock' => 0,
    'stock_status' => 'outofstock', 'image' => null, 'created_at' => now(), 'updated_at' => now(),
]);
$range = DB::table('free_gift_ranges')->orderBy('min_fils')->first();
DB::table('free_gift_range_items')->insert(['range_id' => $range->id, 'product_id' => $id, 'quantity' => 1, 'kind' => 'mini', 'sort' => 9, 'created_at' => now(), 'updated_at' => now()]);
app(\App\Services\FreeGifts::class)->compile();
echo "gsx: mascara #{$id} out of stock in range {$range->id}\n";
