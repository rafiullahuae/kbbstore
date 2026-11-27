<?php
// Lane GSX2: the owner's range of 27 Nov 2026 -- criteria 2 minis; minis Mary&May-like (untracked) and the Maybelline
// (gift-only, shop stock 0 / out), a backup mini, masks; FG_SEED=gsx2-seed.php sh tools/fg-preview.sh
require __DIR__.'/gsx-seed.php';

use Illuminate\Support\Facades\DB;

$r = DB::table('free_gift_ranges')->orderBy('min_fils')->first();
DB::table('free_gift_range_items')->where('range_id', $r->id)->delete();
$p = fn ($slug) => DB::table('products')->where('slug', $slug)->value('id');
DB::table('products')->where('slug', 'fg-black-rice-hyaluronic-toner-mini')->update(['manage_stock' => false, 'stock' => null]);
$rows = [
    [$p('fg-tea-tree-sheet-mask'), 3, 'mask', 'primary'],
    [$p('fg-black-rice-hyaluronic-toner-mini'), 1, 'mini', 'primary'],
    [$p('gsx-maybelline-sky-high-mini'), 1, 'mini', 'primary'],
    [$p('fg-mugwort-calming-clay-mask-mini'), 1, 'mini', 'backup'],
    [$p('fg-the-glow-set'), 1, 'full', 'primary'],
];
foreach ($rows as $i => [$id, $q, $k, $role]) {
    DB::table('free_gift_range_items')->insert(['range_id' => $r->id, 'product_id' => $id, 'quantity' => $q, 'kind' => $k, 'role' => $role, 'sort' => $i, 'created_at' => now(), 'updated_at' => now()]);
}
DB::table('free_gift_ranges')->where('id', $r->id)->update(['need_mask' => 3, 'need_mini' => 2, 'need_full' => 1]);
app(\App\Services\FreeGifts::class)->compile();
echo "gsx2: range {$r->id} needs 3 masks / 2 minis / 1 full\n";
