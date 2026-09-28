<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Set: a product type, not a folder of products. (Lane SET)
 *
 * ── WHY THERE IS NO `sets` TABLE ───────────────────────────────────────────
 *
 * A Set IS a row in `products`, with `products.type = 'set'`. The owner's
 * requirement is that a set is "published same like other products and
 * display", and every column that sentence needs -- slug, status, is_visible,
 * category_id, description, short_description, image, images, price,
 * sale_price, position, seo -- is a `products` column today. A table of its own
 * would duplicate all of them AND every screen, sitemap entry, search index and
 * API allowlist that reads `products`.
 *
 * It also means NO SCHEMA CHANGE on `cart_items` or `order_items` to sell one:
 * both carry `product_id` foreign keys to `products` already (see
 * 0001_01_01_000000_create_kbb_schema.php:266 and :437).
 *
 * VERIFIED FIRST-HAND before this was written, because it is the assumption the
 * whole shape rests on. Nothing in this application does an exclusive
 * `type === 'simple'` test that a third value would fall out of:
 *
 *   app/Models/Product.php:604          `=== 'variable'` -- a set answers false,
 *                                        which is right: a set has no options.
 *   app/Services/VariantPricing.php:295 `!== 'variable'` -- a set is skipped,
 *                          and :357       which is right: it has its own price.
 *   app/Http/Controllers/Admin/CatalogProductsApiController.php:1375
 *                                        a pass-through `where('products.type',
 *                                        $type)` FILTER, not an exclusion.
 *   app/Http/Controllers/Admin/ProductEditorApiController.php:828
 *                                        `$product->type ??= 'simple'`, and
 *                                        only when `! $product->exists`, so it
 *                                        cannot clobber a saved set.
 *   app/Models/Product.php scopeVisible() has no type clause at all, which is
 *                                        why a set publishes with no change.
 *
 * ── THE PIVOT ──────────────────────────────────────────────────────────────
 *
 * `member_variant_id` is nullable so a set can name the 50ml rather than the
 * product. `quantity` is how many of that member are in the box. `position` is
 * the order the owner arranged them in, which is the order every one of the
 * seven surfaces draws them in.
 *
 * NO UNIQUE INDEX ON (set, member, variant). MySQL and SQLite both treat NULLs
 * as distinct in a unique index, so such an index would refuse a second row for
 * a member chosen twice by product while happily accepting two rows for a
 * member chosen twice with no variant -- a constraint that holds in one of the
 * two shapes is worse than none, because it reads as protection. Duplicates are
 * refused in SetApiController::members(), which can see both halves.
 *
 * ── `order_items.set_contents` ─────────────────────────────────────────────
 *
 * A set's contents WILL change. An order sold last month must still print what
 * was actually in the box, so the member list is SNAPSHOTTED onto the order and
 * never read back through the pivot. That is the rule `order_items` already
 * states one line above where this column lands: "Snapshots, so an order still
 * reads correctly after a product is renamed or deleted", and it already holds
 * one JSON snapshot for exactly this reason (`variant_attributes`).
 *
 * ONE JSON COLUMN, NOT CHILD ROWS. Child `order_items` would be counted by
 * every total, every report, every refund ceiling and every invoice line in this
 * shop, and each of those is a place to get it wrong. A JSON snapshot is read
 * by the things that PRINT a set and invisible to the things that add money up.
 *
 * ── NOTHING ON THE SHOP MOVES ──────────────────────────────────────────────
 *
 * This migration adds one table and one nullable column. No row is written, no
 * setting is added, no default changes. A shop with no sets renders
 * byte-identically -- StorefrontEnglishUnchangedTest is the instrument and it
 * does not move.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_set_items')) {
            Schema::create('product_set_items', function (Blueprint $t) {
                $t->id();

                // The set. Cascade: deleting a set deletes its membership rows,
                // which carry no information of their own.
                $t->foreignId('set_product_id')->constrained('products')->cascadeOnDelete();

                // The member. Cascade for the same reason -- a membership row
                // pointing at a product that is gone is not a set contents, it
                // is a hole. What an ORDER remembers is the snapshot, which is
                // untouched by this.
                $t->foreignId('member_product_id')->constrained('products')->cascadeOnDelete();

                $t->foreignId('member_variant_id')->nullable()
                    ->constrained('product_variants')->nullOnDelete();

                $t->unsignedInteger('quantity')->default(1);
                $t->integer('position')->default(0);

                $t->timestamps();

                // The one query every surface makes: the members of one set, in
                // the owner's order.
                $t->index(['set_product_id', 'position'], 'product_set_items_set_position_index');

                // "Which sets is this product in?" -- asked when a product is
                // about to be deleted, and by the admin screen's member picker.
                $t->index('member_product_id', 'product_set_items_member_index');
            });
        }

        if (! Schema::hasColumn('order_items', 'set_contents')) {
            Schema::table('order_items', function (Blueprint $t) {
                // NULL on every row that exists, and on every row a non-set line
                // writes from now on. Absent and empty both mean "this line is
                // not a set", which is what every reader tests.
                //
                // NO ->after(). MigrationConventionTest forbids it on a new
                // migration, and it is right to: column ORDER is not a contract
                // anywhere in this application — every reader names its columns
                // — while `after` is MySQL-only syntax that SQLite silently
                // ignores, so it makes the test database and the production one
                // disagree about the shape of a table for no gain.
                $t->json('set_contents')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_set_items');

        if (Schema::hasColumn('order_items', 'set_contents')) {
            Schema::table('order_items', function (Blueprint $t) {
                $t->dropColumn('set_contents');
            });
        }
    }
};
