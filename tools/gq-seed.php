<?php
/*
 * Seed for the Lane GQ preview ("Gives per order ×2"):
 *
 *   GO_LANE=lane-gq GO_SEED=gq-seed.php sh tools/go-preview.sh 9560
 *
 * Lane GO's seed (tools/go-seed.php), then ONE product set to give 2 pcs per
 * order: the AED 300+ range's primary, Relief Sun. Allocation 50, used 4, so
 * the admin card reads "given 4 · left 46 · enough for 23 orders".
 */
use Illuminate\Support\Facades\DB;

require __DIR__ . '/go-seed.php';

$sun = \App\Models\Product::query()->where('slug', 'go-relief-sun')->value('id');
DB::table('gift_offer_items')->where('product_id', $sun)->update(['qty' => 2, 'used' => 4]);
app(\App\Services\GiftOffers\GiftOffers::class)->compile();

echo 'gq: Relief Sun gives x' . DB::table('gift_offer_items')->where('product_id', $sun)->value('qty') . "\n";
