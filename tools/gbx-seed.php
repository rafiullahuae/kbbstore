<?php

declare(strict_types=1);

/*
 * Lane GBX: tools/fgv-seed.php's shop (full size, minis, masks and one
 * variation in every range, gifts in TESTING for 127.0.0.1) with the homepage
 * gift section on, so the box, the checkout summary and the homepage rows can
 * be photographed in the full size -> mini -> mask order.
 *   FG_SEED=gbx-seed.php sh tools/fg-preview.sh 12340 -- never against a real database.
 */

require __DIR__.'/fgv-seed.php';

use Illuminate\Support\Facades\DB;

// The tint is a mini, not a mask: move it (and one count) to Mini products.
$tint = (int) DB::table('products')->where('slug', 'fgv-glow-lip-tint')->value('id');
foreach (DB::table('free_gift_range_items')->where('product_id', $tint)->where('kind', 'mask')->pluck('range_id') as $rid) {
    DB::table('free_gift_range_items')->where('range_id', $rid)->where('product_id', $tint)->update(['kind' => 'mini']);
    DB::table('free_gift_ranges')->where('id', $rid)->whereNotNull('need_mask')->update(['need_mask' => DB::raw('need_mask - 1'), 'need_mini' => DB::raw('coalesce(need_mini, 0) + 1')]);
}
DB::table('products')->where('id', $tint)->update(['gift_kind' => 'mini']);

$fg = app(\App\Services\FreeGifts::class);
$config = $fg->freshConfig();
$config['home'] = ['on' => true] + ($config['home'] ?? []);
$fg->saveConfig($config);
$fg->compile();
\Illuminate\Support\Facades\Cache::flush();
echo "gbx: homepage gift section on\n";
