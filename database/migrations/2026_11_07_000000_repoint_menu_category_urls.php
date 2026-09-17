<?php

declare(strict_types=1);

use App\Models\Category;
use App\Support\LegacyCategoryUrls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repoint the navigation's WooCommerce-era flat category URLs at the addresses
 * this application actually serves — Lane DS.
 *
 * ── WHAT A SHOPPER GOT BEFORE THIS ──────────────────────────────────────────
 *
 * The menu rows seeded by 2026_09_09_070000_fix_kbeautybliss_menu_structure
 * carry the live WordPress site's own category addresses: /toners/,
 * /sunscreens/, /cleansing-oils/ and eleven more, flat at the site root. This
 * application serves category archives at /product-category/{path}/ — URL
 * Contract U-03 — and has never served them anywhere else.
 *
 * Nothing 404'd cleanly. routes/kbb-brands-blog.php ends in `/{slug}/`, the
 * single-segment catch-all that looks up a BLOG POST, and `toners` is not a
 * reserved slug, so every one of those fourteen menu items resolved to
 * PageController@post, searched the `posts` table for an article called
 * "toners", found none and 404'd. Verified against a migrated database, not
 * inferred: fourteen distinct URLs in the rendered header, every one of them
 * 404 via Store\PageController@post.
 *
 * That is fourteen of the shop's most prominent links — the whole Skincare
 * dropdown plus six top-level shortcuts — landing on the not-found page.
 *
 * ── WHY THIS IS A DATA MIGRATION AND NOT A BLADE EDIT ───────────────────────
 *
 * The URLs are rows in `menu_items`, not text in a template. Correcting the
 * seeding migrations alone would fix nothing on any shop that has already run
 * them, which is every shop. The seeders are corrected too (MenuDemo,
 * MegaMenuApiController::seed) so the defect cannot come back through a
 * re-seed, but this file is what repairs the shop as it stands.
 *
 * ── THE THREE PROPERTIES THIS FILE IS WRITTEN FOR ───────────────────────────
 *
 * IDEMPOTENT. It recognises rows by their exact legacy URL. After it runs,
 * those rows hold /product-category/… paths, which are not legacy URLs, so a
 * second run matches nothing and changes nothing. Re-applying the package, or
 * running `migrate` twice, is a no-op rather than a second rewrite.
 *
 * IT DOES NOT CLOBBER A HAND-EDITED URL. The same property does this work. If
 * the owner has opened Mega Menu and pointed "Toners" somewhere of their own
 * choosing, that row no longer holds `/toners/` and this file does not touch
 * it. Only a row still carrying the exact address the seeder wrote is
 * rewritten — the rows that are provably still the machine's own guess.
 *
 * IT LEAVES ROWS IT DOES NOT RECOGNISE ALONE. Brand filters
 * (/shop/?filter_brands=…), the collections (/super-sale/,
 * /everything-under-54-aed/), the brand directory and the blog are all flat
 * root URLs as well, and all of them answer 200 today because they have their
 * own registered routes. They are not in LegacyCategoryUrls::PATHS and nothing
 * here goes near them.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ────────────────────────────────────────
 *
 * It does not invent categories, and it does not remap a slug to whichever
 * category happens to exist at apply time. LegacyCategoryUrls carries the full
 * reasoning; the short version is that the only categories on a shop that has
 * not yet run the WordPress import are the six placeholders from
 * DemoCatalogueSeeder, so resolving against the table at apply time would bake
 * a placeholder into the menu and the real category would then arrive and be
 * ignored. The slug is preserved, the shape is corrected, and the link starts
 * working by itself when the category lands.
 *
 * Instead the categories the shop does not currently have are PRINTED at apply
 * time, for the owner to decide about — create the category, or repoint the
 * menu item in Mega Menu. That is a merchandising decision and not one a
 * migration should make on its own.
 *
 * No schema changes. Nothing here uses an AFTER clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('menu_items')) {
            return;
        }

        $rewritten = 0;
        $byUrl = [];

        foreach (LegacyCategoryUrls::PATHS as $legacy) {
            $target = LegacyCategoryUrls::toCategoryPath($legacy);

            // Matched on the exact stored string. A row the owner has edited
            // does not hold this value any more and is therefore skipped.
            $n = DB::table('menu_items')->where('url', $legacy)->update(['url' => $target]);

            if ($n > 0) {
                $rewritten += $n;
                $byUrl[$legacy] = ['to' => $target, 'rows' => $n];
            }
        }

        // The menu tree is cached for five minutes per slot; without this the
        // header would go on serving the old URLs after the update finishes.
        try {
            app(\App\Services\NavigationService::class)->flush();
        } catch (\Throwable) {
            // Never block the data fix on a cache driver problem.
        }

        if (! app()->runningInConsole()) {
            return;
        }

        if ($rewritten === 0) {
            echo "Menu category URLs: nothing to repoint (already corrected, or edited by hand).\n";
        } else {
            echo "Menu category URLs: repointed {$rewritten} item(s) to /product-category/…\n";

            foreach ($byUrl as $from => $info) {
                echo "  {$from} -> {$info['to']}  ({$info['rows']} item(s))\n";
            }
        }

        $this->reportMissingCategories(array_keys($byUrl));
    }

    /**
     * Name the menu items whose category this shop does not have, so the owner
     * sees it in the update log rather than discovering it as a 404.
     *
     * Reported, not repaired. Which category "Eye Care" should point at when
     * the shop has no eye-care category is a merchandising decision.
     *
     * @param list<string> $touched
     */
    private function reportMissingCategories(array $touched): void
    {
        if ($touched === []) {
            return;
        }

        try {
            $have = Category::query()->pluck('slug')->all();
        } catch (\Throwable) {
            return;
        }

        $missing = [];

        foreach ($touched as $legacy) {
            $slug = LegacyCategoryUrls::slugOf($legacy);

            if (! in_array($slug, $have, true)) {
                $missing[] = $slug;
            }
        }

        if ($missing === []) {
            return;
        }

        echo "\n  These menu items now point at a category this shop does not have yet:\n";

        foreach ($missing as $slug) {
            echo "    /product-category/{$slug}/\n";
        }

        echo "  Each will answer 404 until the category exists, and will start\n";
        echo "  working on its own once the WordPress import brings it in. If a\n";
        echo "  category is never coming, repoint or remove that item in\n";
        echo "  Store -> Mega Menu.\n";
    }

    /**
     * Reversible, and only for rows this migration would itself have written.
     * A row the owner has since edited away from the /product-category/ form is
     * not restored to an address that never worked.
     */
    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('menu_items')) {
            return;
        }

        foreach (LegacyCategoryUrls::PATHS as $legacy) {
            DB::table('menu_items')
                ->where('url', LegacyCategoryUrls::toCategoryPath($legacy))
                ->update(['url' => $legacy]);
        }

        try {
            app(\App\Services\NavigationService::class)->flush();
        } catch (\Throwable) {
        }
    }
};
