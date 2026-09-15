<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three columns the product editor needs and the table does not have.
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
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['published_at', 'ingredients', 'how_to_use'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
