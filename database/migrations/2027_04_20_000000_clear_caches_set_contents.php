<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code caches, for the set contents designs and the bundle strip.
 * (Lane SF)
 *
 * THREE REASONS, and the first is the one CLAUDE.md names:
 *
 *   THE ROUTE TABLE. routes/set-contents-admin.php adds three admin endpoints
 *   (GET and POST /admin-api/set-contents, POST /admin-api/set-contents/-
 *   preview). A route added by a package does nothing at all until the
 *   compiled route table is gone: bootstrap/cache/routes-*.php is what the
 *   application reads, and it was compiled before that file existed. Without
 *   this, Appearance -> Set contents loads and every button on it answers 404
 *   — which is the failure the screen's own error banner names, and it names
 *   it because the banner is cheaper than a support conversation.
 *
 *   THE COMPILED VIEWS. This release adds five Blade files
 *   (partials/set-contents/{grid,list,cards,stack,footing}.blade.php,
 *   admin/partials/set-contents-preview.blade.php and
 *   admin/partials/set-contents-screen.blade.php) and EDITS two that are
 *   already on the server: partials/set-contents-panel.blade.php and
 *   admin/partials/product-editor-screen.blade.php.
 *   storage/framework/views keys a compiled view by the path of its source and
 *   decides staleness on file times, and an unzip's timestamps are not
 *   reliably newer than what is already on disk. Re-shipping the sources does
 *   nothing on its own; deleting the compiled copies is what makes them take
 *   effect.
 *
 *   OPCACHE, one layer down: App\Services\BundleService,
 *   App\Support\AdminCapabilities, App\Support\ImageVariants and
 *   resources/views/admin/app.blade.php's own compiled copy are all files the
 *   server has already compiled, and the first three change in this release.
 *
 * ── WHAT MOVES ON THE SHOP, AND IT IS ONE THING THE OWNER ASKED FOR ────────
 *
 * NO SETTING ROW IS WRITTEN. `set_panel_design` is deliberately NOT seeded:
 * absent, App\Support\SetPanelDesign::current() answers `grid`, which is
 * literally the panel this application already drew — the compact card grid
 * Lane SP shipped. A set page renders the same block it rendered yesterday
 * until somebody opens Appearance -> Set contents and picks another. That is
 * CLAUDE.md rule 1: a new setting ships at the value the page already has.
 *
 * THE ONE VISIBLE CHANGE IS THE ONE THAT WAS ASKED FOR, and it is called out
 * here rather than buried: a SET's product page no longer offers the
 * quantity-bundle strip.
 *
 *     "on desktop set product page, there will be no bulk quantity purchase
 *      strips."
 *
 * BundleService::forProduct() answers an empty list for a product of type
 * `set`, so the "2-pack bundle / 3-pack bundle" rows are gone from a set's
 * page and from nowhere else. An ORDINARY product's page is byte-for-byte what
 * it was — StorefrontEnglishUnchangedTest renders one and is green — and the
 * PRICING path is untouched: unitFor(), totalFor() and discountFor() are
 * unchanged, so no basket that already exists is repriced by applying this.
 *
 * ▲ THE `migrations` FLAG IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT
 *   ALL — UpdateRunner::hasMigrations() never looks at the files. Build the
 *   package with `php artisan kbb:package <version> --since=<ref>`, never a
 *   hand-rolled script. Five packages built by one in an afternoon shipped
 *   eight migrations that were copied to the live server and never ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files. A set's product page no longer offers\n"
                ."the quantity-bundle strip -- \"2-pack bundle\", \"3-pack bundle\" -- which\n"
                ."is the one visible change in this release. Ordinary product pages keep\n"
                ."theirs, and no basket is repriced.\n"
                ."\n"
                ."There are now FOUR designs for the block that names what is in a set,\n"
                ."and you choose between them at Appearance -> Set contents, where each\n"
                ."one is drawn from one of your own sets at phone width and at desktop\n"
                ."width, in English and in Arabic. Nothing changes until you pick: the\n"
                ."setting ships as \"Compact grid\", which is the block your set pages\n"
                ."already draw.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
