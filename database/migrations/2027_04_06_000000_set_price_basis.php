<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ANCHOR A HAND-TYPED SET PRICE IS MEASURED FROM. (Lane SP2)
 *
 * The owner, having priced a set by hand:
 *
 *   "when i set the price of product set, either total, or discounted
 *    percentage or manual set the actual and sale price. For any case, when i
 *    change the price of any product from that set, the set price will also
 *    reduce that much how much i reduced in that particular product. from the
 *    actual price and also from the sale price if any."
 *
 * ── WHY A HAND-TYPED PRICE NEEDS A COLUMN AND THE TWO DISCOUNT MODES DO NOT ─
 *
 * `discount_percent` and `discount_amount` derive the set's price FROM the
 * parts total on every read, so a member's price drop is already in the answer
 * before anything is asked. There is nothing to anchor because there is no
 * number of the operator's own in the sum.
 *
 * `fixed` is the opposite: the number IS the operator's, and "reduce it by how
 * much the member came down" is a sentence with no second operand. Down FROM
 * WHAT? The only honest answer is the parts total AT THE MOMENT HE TYPED THE
 * PRICE, because that is the total he was looking at when he decided the figure
 * was right. This column records it.
 *
 *     set_price_basis   the parts total, in integer fils, when the operator
 *                       last typed this set's price -- or NULL, meaning "no
 *                       anchor, so nothing moves".
 *
 * The set's live price is then, in fixed mode:
 *
 *     price        - max(0, set_price_basis - parts total now)
 *     sale_price   - max(0, set_price_basis - parts total now)
 *
 * `products.price` and `products.sale_price` go on meaning exactly what they
 * have always meant -- the figures the operator typed -- and the reduction is
 * applied on the way out by App\Support\SetPricing, for the reason the previous
 * migration (2027_04_02_000000_set_pricing_columns) sets out at length: five
 * different writers change a product's price and a stored recalculated figure
 * would have to be chased by all five.
 *
 * ── max(0, ...) IS THE DECISION, NOT A DEFENSIVE CAST ──────────────────────
 *
 * The delta is clamped at zero, so a member getting DEARER never raises the
 * set above the price the operator typed. The whole argument is in
 * App\Support\SetPricing::adjustment(); the short version is that a shop which
 * charges more than anybody typed, on its own, is a worse shop than one that
 * occasionally charges less.
 *
 * ── NOTHING MOVES ──────────────────────────────────────────────────────────
 *
 * NULLABLE, NO DEFAULT, NOTHING BACKFILLED. Every set that exists carries NULL
 * and is therefore priced at `products.price` exactly as it is today; the
 * column is written for the first time when the operator next types a price,
 * changes what is in the box, or presses the button that re-anchors it. So
 * applying this package moves not one price on the shop.
 *
 * ── AND IT IS AN INTEGER, LIKE EVERY OTHER MONEY COLUMN ON THIS TABLE ──────
 *
 * Fils. Signed, like `price` and `set_discount` beside it, so a negative that
 * somehow arrives is readable in the table rather than wrapping to two billion
 * on MySQL. Not in tests/Support/ColumnWidths.php: that fingerprint covers
 * char and varchar columns only, because those are the widths SQLite discards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'set_price_basis')) {
                /*
                 * ▲ NO ->after(). MigrationConventionTest forbids positioning,
                 *   and the sibling migration records why: on MySQL, ALTER ...
                 *   AFTER a column that does not exist is an error, and wrapped
                 *   in a hasColumn guard the failure looks like a clean no-op
                 *   and records as run.
                 */
                $t->integer('set_price_basis')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (Schema::hasColumn('products', 'set_price_basis')) {
                $t->dropColumn('set_price_basis');
            }
        });
    }
};
