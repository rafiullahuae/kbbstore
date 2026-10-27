<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Free gifts: ONE VARIATION of a product with options as a gift (Lane FGV, the
 * owner, 27 October 2026):
 *
 *   "we have variable product in 3 colors, red, pink, black, the pink one we
 *    want to add in gifts, so the system should have ability to give option to
 *    choose the pink variation."
 *
 * One nullable column on the range's gift rows: the chosen variation, NULL on
 * every row that exists today, so nothing a shopper gets moves on applying
 * this. FreeGifts::compile() adds it to a gift only when it is set.
 *
 * The pink and the black of one product are two gifts in one range, so the
 * old unique (range, product) becomes unique (range, product, variant). The
 * new index is made FIRST: it starts with range_id, so MySQL's foreign key on
 * range_id always has an index to stand on while the old one is dropped.
 *
 * Guarded both ways, safe to run twice. Literals and framework calls only.
 */
return new class extends Migration
{
    private const OLD = 'free_gift_range_items_range_id_product_id_unique';

    private const NEW = 'fgri_range_product_variant_unique';

    public function up(): void
    {
        if (! Schema::hasTable('free_gift_range_items')) {
            return;
        }

        if (! Schema::hasColumn('free_gift_range_items', 'variant_id')) {
            Schema::table('free_gift_range_items', function (Blueprint $t) {
                $t->unsignedBigInteger('variant_id')->nullable();
            });
        }

        $names = array_map(static fn (array $i) => (string) $i['name'], Schema::getIndexes('free_gift_range_items'));

        if (! in_array(self::NEW, $names, true)) {
            Schema::table('free_gift_range_items', fn (Blueprint $t) => $t->unique(['range_id', 'product_id', 'variant_id'], self::NEW));
        }

        if (in_array(self::OLD, $names, true)) {
            Schema::table('free_gift_range_items', fn (Blueprint $t) => $t->dropUnique(self::OLD));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('free_gift_range_items') || ! Schema::hasColumn('free_gift_range_items', 'variant_id')) {
            return;
        }

        $names = array_map(static fn (array $i) => (string) $i['name'], Schema::getIndexes('free_gift_range_items'));

        if (in_array(self::NEW, $names, true)) {
            Schema::table('free_gift_range_items', fn (Blueprint $t) => $t->dropUnique(self::NEW));
        }

        Schema::table('free_gift_range_items', fn (Blueprint $t) => $t->dropColumn('variant_id'));
    }
};
