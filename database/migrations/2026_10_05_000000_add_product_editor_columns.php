<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Five columns the product editor needs and the table does not have.
 *
 * gtin — THE PRODUCT IDENTIFIER GOOGLE ACTUALLY WANTS.
 *
 * `products` has `sku`, and `sku` is free text this shop invents for itself:
 * two retailers selling the same toner have two different SKUs, so it cannot
 * tell a merchant listing that this is the same item anybody else is selling. A
 * GTIN — the number under the barcode — can. There was nowhere on this table to
 * put one.
 *
 * Stored as a string of up to 14 characters, never an integer: a GTIN-13 fits
 * in a 64-bit int but leading zeros are significant (a UPC-A widened to
 * GTIN-14 is `00012345678905`), and an integer column silently eats them.
 * Nullable, because most of this catalogue will not have one on day one, and
 * validated by App\Support\Gtin — which checks the mod-10 check digit rather
 * than counting digits, since catching a mistyped or transposed digit is the
 * entire reason the field is worth having.
 *
 * NOTHING HERE EMITS IT. app/Support/Seo.php belongs to the storefront SEO
 * lane; this package makes the column and the field exist so that lane has
 * something to publish.
 *
 * image_alts — ALT TEXT, PER IMAGE.
 *
 * A json map of image URL to alt text, covering the main image and every
 * gallery shot. See the note on Product::altFor() for why this is a separate
 * column keyed by URL rather than a change to the shape of `images`: that
 * column is a flat list of URL strings read by Store\ProductController::
 * gallery(), by Product::toApi() and by the WooCommerce importer, and turning
 * its entries into objects would break all three at once for a field that only
 * one of them needs.
 *
 * published_at — SCHEDULE TO PUBLISH.
 *
 * Nullable, and NULL is the important value: it means "no scheduled date",
 * which is how every row already in this table reads, so adding the column
 * changes nothing about what is currently on the storefront. A future value is
 * the only thing that ever hides a product, and only while it is still future.
 * See App\Support\ProductVisibility for why this is a timestamp compared at
 * read time rather than a job — there is no cron on this host to run one.
 *
 * Indexed because Product::scopeVisible() now tests it on every storefront
 * query: the shop, every category page, search, the home page rails and the
 * cart's own re-check. An unindexed nullable datetime in that position is a
 * full scan on the busiest query in the application.
 *
 * ingredients, how_to_use — A BUG FIX THAT LOOKS LIKE A FEATURE.
 *
 * Store\ProductController::tabs() has always built three tabs, and two of them
 * read `$product->ingredients` and `$product->how_to_use`. Neither column has
 * ever existed. Eloquent returns null for a missing attribute rather than
 * raising, tabs() then filters out any tab whose body is empty, and the result
 * is that the Ingredients and How-to-use tabs could not render for any product
 * in the catalogue, ever — silently, with no error anywhere.
 *
 * The choice was to delete the dead tabs or to make them real. They are made
 * real. For a K-beauty shop the ingredient list is not decoration: INCI is what
 * customers with sensitised skin search for and what they ask support about,
 * and "how to use" is the question an unfamiliar product category generates
 * most. The storefront rendering is already written and already correct; what
 * was missing was somewhere to put the words. Both are TEXT and both are
 * sanitised through App\Support\RichText on the way in, exactly like the
 * description, because the same Blade renders all three with {!! !!}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'published_at')) {
                $table->timestamp('published_at')->nullable()->index();
            }

            if (! Schema::hasColumn('products', 'ingredients')) {
                $table->text('ingredients')->nullable();
            }

            if (! Schema::hasColumn('products', 'how_to_use')) {
                $table->text('how_to_use')->nullable();
            }

            if (! Schema::hasColumn('products', 'gtin')) {
                // Indexed: a barcode is the natural key an operator or a feed
                // looks a product up by, and it is the one identifier that is
                // the same here as it is in anybody else's catalogue.
                $table->string('gtin', 14)->nullable()->index();
            }

            if (! Schema::hasColumn('products', 'image_alts')) {
                $table->text('image_alts')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['published_at', 'ingredients', 'how_to_use', 'gtin', 'image_alts'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
