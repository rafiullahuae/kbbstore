<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categories & Brands merchandising screen (Lane AQ) — schema.
 *
 * Two things this catalogue could not express, both of which the owner needs
 * the moment categories become the main merchandising tool:
 *
 * 1. SEO PER CATEGORY AND PER BRAND.
 *
 *    `categories` and `brands` carry slug, name, description and an image and
 *    nothing else. Every category archive therefore takes its <title> from the
 *    bare category name and its meta description from one sentence built in
 *    ShopController::seoDescription(). Those are the highest-intent pages on a
 *    K-beauty store — "korean sunscreen uae" lands on a category, not on the
 *    homepage — and they had no editable title or description at all.
 *
 *    Stored as a `json` column named `seo`, deliberately identical to
 *    `products.seo` (line 134 of create_kbb_schema) so there is one shape in
 *    this app for "the SEO overrides of a thing" rather than two. NOT a set of
 *    flat columns: the Yoast import already lands a bag of keys on products,
 *    and a category will want the same ones.
 *
 * 2. REDIRECTS FOR CATEGORY PATHS THAT MOVED.
 *
 *    /product-category/{nested/path}/ is an indexed URL with inbound links.
 *    Renaming a slug or re-parenting a category rewrites `categories.path`, and
 *    the old URL then resolves to nothing.
 *
 *    "Resolves to nothing" is worse than it sounds, and this is the finding
 *    that motivated the table. The archive route does
 *    `Category::where('slug', basename($path))->first()` and hands the result
 *    to ShopController::index(), which treats a null category as "no category
 *    filter". So an unknown category path does not 404 — it returns 200 with
 *    the entire catalogue under the heading "Shop all". Every dead category URL
 *    is a soft 404 serving duplicate content, and so is every misspelling of a
 *    live one. Asserted in CategoryPathContractTest.
 *
 *    `category_redirects` is the record of where a path used to point, so the
 *    old URL can answer 301 instead. `from_path` is unique — a path points at
 *    one place — and the row is keyed to the destination category by id, not by
 *    path, so a category that moves twice does not leave a chain to follow.
 *
 * No AFTER clause anywhere below. An ALTER naming a column that does not exist
 * yet is an error on MySQL, and the hasColumn() guards around nine earlier
 * migrations in this repo made exactly that error look clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categories') && ! Schema::hasColumn('categories', 'seo')) {
            Schema::table('categories', function (Blueprint $t) {
                $t->json('seo')->nullable();
            });
        }

        if (Schema::hasTable('brands') && ! Schema::hasColumn('brands', 'seo')) {
            Schema::table('brands', function (Blueprint $t) {
                $t->json('seo')->nullable();
            });
        }

        if (! Schema::hasTable('category_redirects')) {
            Schema::create('category_redirects', function (Blueprint $t) {
                $t->id();

                // The path as it appeared inside /product-category/…/, with no
                // leading or trailing slash: "skincare/face-cleansers".
                // Unique because a given old URL has exactly one destination;
                // recording a second would make the answer depend on row order.
                $t->string('from_path')->unique();

                // Where it goes now. nullOnDelete rather than cascade: if the
                // destination category is itself deleted later, the redirect
                // row survives with a null target and the resolver answers 404
                // for it — which is the correct answer, and better than the row
                // vanishing and the URL going back to serving "Shop all".
                $t->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

                // What created it, for when someone asks why a URL moved.
                // 'slug' (the slug was edited), 'move' (re-parented),
                // 'merge' (merged into another category), 'delete'.
                $t->string('reason', 32)->default('slug');

                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_redirects');

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'seo')) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('seo'));
        }

        if (Schema::hasTable('brands') && Schema::hasColumn('brands', 'seo')) {
            Schema::table('brands', fn (Blueprint $t) => $t->dropColumn('seo'));
        }
    }
};
