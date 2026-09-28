<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a Set is priced. (Lane SP)
 *
 * The owner, having built his first set:
 *
 *   "I want that when i add the products, the total price give option with the
 *    button, to use this price, and give option that how much discount on
 *    total, and it will auto set the price. also if i reduce the price of any
 *    product, it will also take effect on this set price, and will reduce the
 *    same reduced price from the total here."
 *
 * ── WHY TWO COLUMNS AND NOT A RECALCULATED `products.price` ────────────────
 *
 * The last sentence is the one that decides the shape. A stored absolute price
 * cannot follow a member's price down: something would have to notice the
 * member changed and go and rewrite every set containing it — from the product
 * editor, from Catalog → Products' inline price cell, from a bulk price action,
 * from the importer and from a hand-edited row. Five writers, one of which will
 * be missed, and the failure is silent and commercial: a set still charging
 * last month's total.
 *
 * So the set stores the RULE instead of the ANSWER, and the answer is derived
 * from the members' current prices every time it is read. There is nothing to
 * synchronise, so there is nothing to drift.
 *
 *   set_price_mode   NULL or 'fixed'      the absolute `products.price`, which
 *                                         is exactly what every existing set
 *                                         has and what this shop does today
 *                    'discount_percent'   N% off the live parts total
 *                    'discount_amount'    a fixed sum off the live parts total
 *
 *   set_discount     basis points (percent × 100) in the first discount mode,
 *                    integer fils in the second. INTEGER EITHER WAY: money is
 *                    integer fils on this path and a percentage is applied to
 *                    fils and rounded ONCE — see App\Support\SetPricing.
 *
 * ── NOTHING MOVES ──────────────────────────────────────────────────────────
 *
 * Both columns are NULLABLE WITH NO DEFAULT and nothing is backfilled. A NULL
 * `set_price_mode` reads as 'fixed', which is the branch
 * Product::effectivePrice() already took, so every set that exists and every
 * product that is not a set is byte-identical after this runs.
 *
 * ── AND WHY THEY ARE ON `products` ─────────────────────────────────────────
 *
 * Because a set IS a `products` row — the shape Lane SET argued for at length
 * and the reason a set needs no second table for its slug, status, category,
 * description, images, SEO row or category pivot. Two nullable columns on a
 * table that already carries `price`, `sale_price`, `sale_starts_at` and
 * `sale_ends_at` is where the rest of this product's pricing lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'set_price_mode')) {
                /*
                 * Short and nullable. NULL reads as 'fixed', and it is what
                 * every row written before this migration carries.
                 *
                 * ▲ NO ->after(). Column order in a table is cosmetic, and
                 *   MigrationConventionTest forbids positioning for a reason
                 *   this shop has already paid for: on MySQL, ALTER ... AFTER a
                 *   column that does not exist is an error, so one missing
                 *   anchor takes out the rest of a chain -- and wrapped in a
                 *   hasColumn guard the failure looks like a clean no-op and
                 *   records as run. SQLite ignores AFTER, so the suite stays
                 *   green while checkout cannot take an order.
                 */
                $t->string('set_price_mode', 24)->nullable();
            }

            if (! Schema::hasColumn('products', 'set_discount')) {
                /*
                 * INTEGER, and signed like `products.price` beside it rather
                 * than unsigned. The value is never negative in practice --
                 * SetApiController clamps it and SetPricing clamps it again on
                 * the way out -- and a signed column is what every other money
                 * column on this table is, so a negative that somehow arrives
                 * is READABLE in the table rather than wrapping to two billion
                 * on MySQL. A wrapped discount is a set priced at nothing.
                 */
                $t->integer('set_discount')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            foreach (['set_discount', 'set_price_mode'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
