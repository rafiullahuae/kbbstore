<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Category and brand page banners (Lane BW) — schema.
 *
 * WHY A COLUMN OF ITS OWN RATHER THAN A CORNER OF `seo`.
 *
 * Both tables already carry a `json seo` column, and reusing it was the
 * tempting option: no migration, no new cast, one bag of settings. It is the
 * wrong bag, for three reasons that are all already true in this repo:
 *
 *   1. `seo` has a declared meaning — "the search-appearance overrides of a
 *      thing" — shared verbatim with `products.seo`, and the docblock on
 *      2026_10_05_000000_add_category_seo_and_redirects says so. A banner is
 *      presentation, not search appearance. The two are edited at different
 *      times and read by different code.
 *
 *   2. The Yoast import lands a whole bag of keys on `products.seo`, and the
 *      note on that migration is explicit that a category "will want the same
 *      ones". An import that writes the SEO bag wholesale would take the
 *      banner with it. A separate column cannot be clobbered by an importer
 *      that does not know it exists.
 *
 *   3. CategoriesApiController::validated() already rebuilds `seo` from
 *      exactly two keys and drops everything else — array_filter over title
 *      and description, then `$data['seo'] = $seo === [] ? null : $seo`.
 *      Storing the banner in `seo` would mean every save from the category
 *      screen silently deleted it. That is not hypothetical; it is the
 *      current code path.
 *
 * So: `banner`, json, nullable, on both tables. Null means "no banner has ever
 * been configured", which reads the same as disabled — the default is OFF and
 * it is off by absence, not by a stored flag a migration has to backfill
 * across ninety-three brands.
 *
 * Shape (App\Support\PageBanner is the single reader and writer):
 *
 *     {"enabled":true,"style":"full","image":"/media/x.jpg","image_alt":"…",
 *      "heading":"…","subheading":"…","tone":"light","overlay":55,
 *      "tint":"#E0567B"}
 *
 * No AFTER clause. tests/Feature/MigrationConventionTest pins that, and the
 * nine grandfathered offenders in this repo are why.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'brands'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'banner')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->json('banner')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['categories', 'brands'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'banner')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('banner'));
            }
        }
    }
};
