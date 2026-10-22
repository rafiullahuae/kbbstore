<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane FG4 -- gift types and per-range criteria (the owner, 22 October: "tag
 * each product Face Mask, Mini Product and Full Size Product ... only for us
 * as internal understanding ... the system should count as per the set
 * criteria, like 2 mini, 3 masks and 1 full size").
 *
 *   free_gift_range_items.kind   mask | mini | full | NULL (untagged), one per
 *                                gift row: the box it sits in on the admin.
 *   products.gift_kind           the same, remembered per product, so a
 *                                product is pre-tagged the next time it is
 *                                added to any range.
 *   free_gift_ranges.need_mask / need_mini / need_full
 *                                the range's criteria, 0-50 each; NULL until
 *                                set (no criteria, nothing counted).
 *
 * INTERNAL ONLY. FreeGifts::compile() does not read any of these, so the
 * storefront, the engine and every order are byte-identical.
 *
 * Backfill, only where still unset: a gift row (and its product's memory)
 * by its product name -- "mini"/"pouch" is mini, else "mask"/"sheet" is
 * mask, else untagged for the owner to set in one click. Mini wins over mask
 * because the owner's own seeded "3 Masks + 2 Mini Products + 1 Full Size"
 * range counts "Mugwort Calming Clay Mask MINI" as one of its two minis.
 * Criteria are seeded for the owner's five ranges where the amounts match
 * and all three counts are still unset.
 *
 * Guarded both ways, safe to run twice. Literals and framework calls only.
 */
return new class extends Migration
{
    /** [min AED, max AED|null, masks, minis, full size] -- the owner's five ranges. */
    private const NEEDS = [
        [100, 299, 2, 0, 0],
        [300, 499, 3, 1, 0],
        [500, 999, 3, 2, 1],
        [1000, 1999, 5, 2, 2],
        [2000, null, 12, 5, 3],
    ];

    public function up(): void
    {
        if (Schema::hasTable('free_gift_range_items') && ! Schema::hasColumn('free_gift_range_items', 'kind')) {
            Schema::table('free_gift_range_items', function (Blueprint $t) {
                $t->string('kind', 8)->nullable();
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'gift_kind')) {
            Schema::table('products', function (Blueprint $t) {
                $t->string('gift_kind', 8)->nullable();
            });
        }

        if (Schema::hasTable('free_gift_ranges') && ! Schema::hasColumn('free_gift_ranges', 'need_mask')) {
            Schema::table('free_gift_ranges', function (Blueprint $t) {
                $t->unsignedSmallInteger('need_mask')->nullable();
                $t->unsignedSmallInteger('need_mini')->nullable();
                $t->unsignedSmallInteger('need_full')->nullable();
            });
        }

        if (! Schema::hasTable('free_gift_range_items') || ! Schema::hasTable('free_gift_ranges')) {
            return;
        }

        // Gift rows still untagged, with their product's name: one query.
        $rows = DB::table('free_gift_range_items')
            ->join('products', 'products.id', '=', 'free_gift_range_items.product_id')
            ->whereNull('free_gift_range_items.kind')
            ->get(['free_gift_range_items.id as id', 'products.id as product_id', 'products.name as name', 'products.gift_kind as remembered']);

        $byKind = [];
        $products = [];
        foreach ($rows as $r) {
            $kind = $r->remembered !== null && in_array($r->remembered, ['mask', 'mini', 'full'], true)
                ? (string) $r->remembered
                : self::guess((string) $r->name);

            if ($kind === null) {
                continue;
            }

            $byKind[$kind][] = (int) $r->id;

            if ($r->remembered === null) {
                $products[$kind][] = (int) $r->product_id;
            }
        }

        foreach ($byKind as $kind => $ids) {
            DB::table('free_gift_range_items')->whereIn('id', $ids)->whereNull('kind')->update(['kind' => $kind]);
        }

        foreach ($products as $kind => $ids) {
            DB::table('products')->whereIn('id', array_values(array_unique($ids)))->whereNull('gift_kind')->update(['gift_kind' => $kind]);
        }

        foreach (self::NEEDS as [$min, $max, $mask, $mini, $full]) {
            DB::table('free_gift_ranges')
                ->where('min_fils', $min * 100)
                ->when($max === null, fn ($q) => $q->whereNull('max_fils'), fn ($q) => $q->where('max_fils', $max * 100))
                ->whereNull('need_mask')->whereNull('need_mini')->whereNull('need_full')
                ->update(['need_mask' => $mask, 'need_mini' => $mini, 'need_full' => $full]);
        }
    }

    /** The name heuristic: mini first (a "Clay Mask Mini" is a mini), then mask. */
    private static function guess(string $name): ?string
    {
        $n = mb_strtolower($name);

        if (str_contains($n, 'mini') || str_contains($n, 'pouch')) {
            return 'mini';
        }

        if (str_contains($n, 'mask') || str_contains($n, 'sheet')) {
            return 'mask';
        }

        return null;
    }

    public function down(): void
    {
        if (Schema::hasTable('free_gift_ranges') && Schema::hasColumn('free_gift_ranges', 'need_mask')) {
            Schema::table('free_gift_ranges', fn (Blueprint $t) => $t->dropColumn(['need_mask', 'need_mini', 'need_full']));
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'gift_kind')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('gift_kind'));
        }

        if (Schema::hasTable('free_gift_range_items') && Schema::hasColumn('free_gift_range_items', 'kind')) {
            Schema::table('free_gift_range_items', fn (Blueprint $t) => $t->dropColumn('kind'));
        }
    }
};
