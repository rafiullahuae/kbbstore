<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane FG -- the owner's free-gift bands, seeded as the shipped configuration.
 *
 *   AED 100 - 299     "2 Masks"
 *   AED 300 - 499     "3 Masks + 1 Mini Product"
 *   AED 500 - 999     "3 Masks + 2 Mini Products + 1 Full Size Product"
 *   AED 1,000 - 1,999 "5 Masks + 2 Minis + 2 Full Size Products"
 *   AED 2,000+        "12 Masks + 5 Minis + 3 Full Size Products"
 *
 * A gift is seeded ONLY where its product is found by slug. The owner has not
 * named the sheet mask product, and the minis are not in the shop yet, so those
 * are his to pick in Marketing -> Free gifts -> Ranges & gifts; the switch ships
 * OFF and the Overview tab lists what is missing.
 *
 * Writes nothing when a band already exists (a second run, or a shop that set
 * its own). The two settings rows are written so the storefront reads them off
 * the autoloaded map: a missing row would cost every page a query.
 * Literals and framework calls only.
 */
return new class extends Migration
{
    /** [min AED, max AED|null, English, Arabic, [[slug candidates], quantity], ...] */
    private const RANGES = [
        [100, 299, '2 Masks', 'قناعان', []],
        [300, 499, '3 Masks + 1 Mini Product', '3 أقنعة + منتج صغير واحد', [
            [['haruharu-wonder-black-rice-hyaluronic-toner-mini'], 1],
        ]],
        [500, 999, '3 Masks + 2 Mini Products + 1 Full Size Product', '3 أقنعة + منتجان صغيران + منتج بالحجم الكامل', [
            [['haruharu-wonder-black-rice-hyaluronic-toner-mini'], 1],
            [['isntree-mugwort-calming-clay-mask-mini'], 1],
            [['shiseido-senka-perfect-whip-beauty-face-foam'], 1],
        ]],
        [1000, 1999, '5 Masks + 2 Minis + 2 Full Size Products', '5 أقنعة + منتجان صغيران + منتجان بالحجم الكامل', [
            [['haruharu-wonder-black-rice-hyaluronic-toner-mini'], 1],
            [['isntree-mugwort-calming-clay-mask-mini'], 1],
            [['shiseido-senka-perfect-whip-beauty-face-foam'], 1],
            [['axis-y-the-mini-glow-set', 'axis-y-the-glow-set'], 1],
        ]],
        [2000, null, '12 Masks + 5 Minis + 3 Full Size Products', '12 قناعاً + 5 منتجات صغيرة + 3 منتجات بالحجم الكامل', [
            [['haruharu-wonder-black-rice-hyaluronic-toner-mini'], 1],
            [['isntree-mugwort-calming-clay-mask-mini'], 2],
            [['beplain-mung-bean-milk-peeling-gel-mini'], 2],
            [['shiseido-senka-perfect-whip-beauty-face-foam'], 1],
            [['axis-y-the-mini-glow-set', 'axis-y-the-glow-set'], 1],
            [['haruharu-wonder-rose-pdrn-peptide-glowy-balm-petal-pink'], 1],
        ]],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('free_gift_ranges') || ! Schema::hasTable('settings')) {
            return;
        }

        $now = now();
        $seeded = 0;

        if (! DB::table('free_gift_ranges')->exists()) {
            foreach (self::RANGES as $sort => [$min, $max, $en, $ar, $gifts]) {
                $rangeId = DB::table('free_gift_ranges')->insertGetId([
                    'min_fils' => $min * 100,
                    'max_fils' => $max === null ? null : $max * 100,
                    'label_en' => $en,
                    'label_ar' => $ar,
                    'sort' => $sort,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($gifts as $i => [$slugs, $quantity]) {
                    $productId = DB::table('products')->whereIn('slug', $slugs)->whereNull('deleted_at')
                        ->where('type', '<>', 'variable')->orderBy('id')->value('id');

                    if ($productId === null) {
                        continue;
                    }

                    DB::table('free_gift_range_items')->insertOrIgnore([
                        'range_id' => $rangeId,
                        'product_id' => (int) $productId,
                        'quantity' => $quantity,
                        'sort' => $i,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $seeded++;
                }
            }
        }

        // The switch, OFF: without the mask product it cannot do what it says.
        if (! DB::table('settings')->where('key', 'free_gifts')->exists()) {
            DB::table('settings')->insert([
                'key' => 'free_gifts',
                'value' => json_encode(['on' => false, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']),
                'autoload' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Compiled the same way FreeGifts::compile() compiles, so the first
        // shop request after switching on reads it rather than rebuilding it.
        $ranges = [];
        foreach (DB::table('free_gift_ranges')->where('enabled', true)->orderBy('min_fils')->orderBy('sort')->orderBy('id')->get() as $r) {
            $ranges[] = [
                'id' => (int) $r->id,
                'min' => (int) $r->min_fils,
                'max' => $r->max_fils === null ? null : (int) $r->max_fils,
                'en' => (string) $r->label_en,
                'ar' => (string) $r->label_ar,
                'starts' => $r->starts_at ? substr((string) $r->starts_at, 0, 10) : null,
                'ends' => $r->ends_at ? substr((string) $r->ends_at, 0, 10) : null,
                'gifts' => DB::table('free_gift_range_items')->where('range_id', $r->id)->orderBy('sort')->orderBy('id')->get()
                    ->map(static fn ($i) => [(int) $i->product_id, (int) $i->quantity])->values()->all(),
            ];
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'free_gifts_compiled'],
            ['value' => json_encode(['v' => 1, 'ranges' => $ranges]), 'autoload' => true, 'updated_at' => $now],
        );

        try {
            Cache::forget('kbb.settings');
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Free gifts: 5 spend ranges seeded with {$seeded} gift picks found by slug. The switch is OFF:\n"
                ."pick the sheet mask product (and the minis) in Marketing -> Free gifts -> Ranges & gifts, then switch it on.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->whereIn('key', ['free_gifts', 'free_gifts_compiled'])->delete();
        }
    }
};
