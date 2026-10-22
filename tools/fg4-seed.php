<?php

declare(strict_types=1);

/*
 * Lane FG4: tools/fg2-seed.php's shop (the owner's five ranges, realistic gift
 * products, a month of orders), then the gift-type migration run again over
 * the picked gifts -- exactly what applying the package does to a live shop:
 * masks and minis tagged by name, the owner's five criteria seeded. Then the
 * state the screen is photographed in: the two full-size sets tagged as the
 * owner would in one click each, the Rose balm left untagged, and one mini
 * taken out of AED 500 - 999 so that range is one mini short.
 * FG_SEED=fg4-seed.php FG_ON=0 sh tools/fg-preview.sh 10860 -- never against a real database.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/fg2-seed.php';

(require __DIR__.'/../database/migrations/2027_11_24_100000_free_gift_kinds.php')->up();

$id = static fn (string $name) => (int) DB::table('products')->where('name', $name)->value('id');
$full = [$id('The Glow Set'), $id('Senka Perfect Whip Fresh Beauty Face Foam')];
DB::table('free_gift_range_items')->whereIn('product_id', $full)->update(['kind' => 'full']);
DB::table('products')->whereIn('id', $full)->update(['gift_kind' => 'full']);

$r500 = (int) DB::table('free_gift_ranges')->where('min_fils', 50000)->value('id');
DB::table('free_gift_range_items')->where('range_id', $r500)->where('product_id', $id('Mugwort Calming Clay Mask Mini'))->delete();

// A few catalogue products the picker can find, one remembered as a mini.
DB::table('products')->where('id', $id('Mung Bean Milk Peeling Gel Mini'))->update(['gift_kind' => 'mini']);

app(\App\Services\FreeGifts::class)->compile();
\Illuminate\Support\Facades\Cache::flush();
echo "fg4: kinds ".json_encode(DB::table('free_gift_range_items')->selectRaw('kind, count(*) c')->groupBy('kind')->pluck('c', 'kind'))."\n";
