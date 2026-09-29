<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabs the owner writes himself, global or on one product. (Lane PT)
 *
 * The owner's words: "the Product Tabs i want that user can create a new tabs
 * for any product, or set as global for all products too."
 *
 * ── ONE TABLE, THREE JOBS, AND WHY THAT IS NOT A SHORTCUT ──────────────────
 *
 * A row in here is one of exactly three things, and which one it is is decided
 * by two nullable columns rather than by a `kind` enum that could disagree with
 * them:
 *
 *   product_id NULL,  source_key NULL   A GLOBAL TAB. Appears on every product.
 *   product_id SET,   source_key NULL   A TAB OF THIS PRODUCT'S OWN.
 *   product_id SET,   source_key SET    AN OVERRIDE: this product's answer to
 *                                       the tab named by source_key -- hidden
 *                                       when is_enabled is 0, re-worded when a
 *                                       title or body is filled in.
 *
 * (product_id NULL with source_key SET is meaningless -- a global row cannot
 * override itself -- and ProductTabsApiController never writes one. It is not
 * refused by a CHECK constraint because SQLite and MySQL disagree about how
 * those are declared and enforced, and App\Support\ProductTabs ignores such a
 * row on the read side, which is the half that actually protects the shop.)
 *
 * `source_key` NAMES A TAB, and the vocabulary is closed:
 *
 *     builtin:description | builtin:ingredients | builtin:how_to_use
 *     global:<id>
 *
 * 64 characters is wide enough for `global:` plus a bigint and nothing else,
 * which is the point of choosing it over 255 -- the column's width says what it
 * holds. App\Support\ProductTabs::SOURCE_KEY_PATTERN is the anchored regex the
 * controller validates against, so nothing outside that vocabulary is ever
 * stored.
 *
 * ── WHY `body` AND NOT `content` OR `html` ─────────────────────────────────
 *
 * Because `body` is already in App\Services\Translation\TranslationStore::
 * LONG_FIELDS. Naming the column `body` is what puts the Arabic half of a tab
 * on the batched, per-page read path that a product's description already uses,
 * instead of in the map that every Arabic request loads in full. A column named
 * `html` would have been correct English and would have put a tab's prose in
 * the 3.2 MB of strings that LONG_FIELDS exists to keep out of memory. The
 * title, which is short, stays in the map and therefore costs no query at all.
 *
 * ── NOTHING ON THE SHOP MOVES ──────────────────────────────────────────────
 *
 * One table, and it is EMPTY. No setting is added, no default changes, no
 * existing column is touched. Until the owner writes a tab in Catalog ->
 * Product tabs, App\Support\ProductTabs returns the same three built-in tabs in
 * the same order that Store\ProductController has always returned, and
 * StorefrontEnglishUnchangedTest is the instrument that says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_tabs')) {
            return;
        }

        Schema::create('product_tabs', function (Blueprint $table) {
            $table->id();

            /*
             * NULL is the global scope. Not a foreign key with a cascade,
             * deliberately: `products` rows are never deleted by this
             * application -- order_items.product_id points at them and
             * routes/product-editor-admin.php says in as many words that there
             * is no delete route -- so a cascade would be a constraint on an
             * act that cannot happen, bought at the price of an index MySQL
             * has to check on every write. Orphan rows are swept by
             * ProductTabs::forProduct(), which only ever asks for one product's
             * id.
             */
            $table->unsignedBigInteger('product_id')->nullable();

            // What this row covers. See the header for the closed vocabulary.
            $table->string('source_key', 64)->nullable();

            /*
             * A HEADING, NOT PROSE. It is printed escaped into a <button> and a
             * <h?>-equivalent by partials/product-tabs.blade.php, and 120 is
             * about twice the longest heading that fits the tab strip before it
             * starts scrolling at 1280. A heading that needs 255 characters is
             * a paragraph and belongs in the body.
             */
            $table->string('title', 120);

            // mediumText, matching `products.description`. The storefront prints
            // it with {!! !!}; App\Support\RichText is what makes that safe, and
            // it runs on the way IN, in the controller, every time.
            $table->mediumText('body')->nullable();

            /*
             * The one ordering scale, shared by built-in tabs, global tabs and
             * a product's own. unsignedSmallInteger because the controller
             * bounds it to 0..9999 and a value outside that is a bug rather
             * than a big shop -- a column that cannot hold 70,000 is a second
             * guard on the same number.
             */
            $table->unsignedSmallInteger('position')->default(0);

            // Off without deleting, which is half of what "full control" means:
            // the owner switches a seasonal tab off in January and back on in
            // November without retyping it.
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            /*
             * The read path asks exactly two questions and this index answers
             * both: "the global tabs" (product_id IS NULL) and "this product's
             * rows" (product_id = ?). position is in the key so the ORDER BY is
             * read off the index rather than sorted in memory.
             */
            $table->index(['product_id', 'position'], 'product_tabs_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tabs');
    }
};
