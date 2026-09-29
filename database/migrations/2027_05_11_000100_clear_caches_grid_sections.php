<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the reusable product-grid section.
 *
 * Phase 23, Lane GS.
 *
 * A CHANGED ROUTE TABLE. `routes/web.php` gains
 * `require __DIR__.'/grid-sections-admin.php';` inside the admin-api group, and
 * the router dispatches against `bootstrap/cache/routes-*.php` rather than
 * against the source. Without this clear, every one of the ten
 * `/admin-api/grid-sections…` endpoints 404s on the server and Appearance →
 * Grid sections is a finished-looking screen that cannot load its own list —
 * the worst shape of failure, because the owner concludes the feature is broken
 * rather than unapplied.
 *
 * AND A CHANGED COMPILED VIEW, which matters as much here and is easy to
 * forget. Three templates change:
 *
 *   resources/views/admin/partials/grid-sections-screen.blade.php   the screen
 *   resources/views/partials/home/grid-section.blade.php            one instance
 *   resources/views/store/home.blade.php                            the loop
 *
 * Blade serves `storage/framework/views` in preference to the template, so a
 * server that keeps the old compiled bundle has the routes and no screen to
 * reach them, and a homepage that cannot draw an instance however it is built.
 *
 * NOTHING MOVES ON THE SHOP WHEN THIS RUNS. `grid_sections` is created empty by
 * the migration beside this one, so `GridSections::registryRows()` returns `[]`,
 * `HomepageSections::registry()` is its const byte for byte, `forHome()` returns
 * `[]` and the homepage's new block emits no element and pushes no style.
 * GridSectionShipsOffTest renders the page with instances IN the table and
 * asserts the same bytes for the unbuilt case; StorefrontEnglishUnchangedTest
 * compares the whole storefront against the tree this branched from.
 *
 * THE TWO SECTIONS THE OWNER NAMED ARE NOT CREATED HERE. "Big savings bundles"
 * and "BEST SELLERS" are one-click presets on the screen — his content, one
 * button later — because rule 1 says a new setting ships at the value the page
 * already renders and two new bands on a live front page is the opposite of
 * that.
 *
 * IT ALSO EVICTS THIS FEATURE'S OWN TWO CACHE KEYS, which is not theoretical.
 * `kbb.home.gridsections.registry` and `kbb.home.gridsections.home` live for
 * ten minutes, and an update applied while a shop is being browsed can leave a
 * cached `[]` in front of a table that has just gained rows. Clearing them here
 * costs an empty shop nothing and saves a confused owner ten minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Not through Cache::forget(): this runs inside `php artisan migrate`
         * on a host whose cache store may be a file driver the web user owns
         * and this process does not. The service is asked politely and the
         * failure is not allowed to fail the migration — this is a CACHE
         * EVICTION, the rows are already correct, and the worst case is the ten
         * minutes the entry had left anyway. It leaves no attribute on any
         * model and can throw nothing onward, which is the distinction
         * CLAUDE.md's updater landmine turns on.
         */
        try {
            \App\Services\GridSections::flush();
        } catch (\Throwable) {
            // Nothing to do and nothing to record: the entries expire on their own.
        }

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
            echo "Cleared {$cleared} compiled files; Appearance -> Grid sections is now\n"
                ."reachable. Nothing on the shop moved: no grid has been built yet, so the\n"
                ."homepage renders exactly what it rendered before. Add one there -- the two\n"
                ."presets are 'Big savings bundles' and 'BEST SELLERS' -- publish it, and order\n"
                ."it against the other sections on Appearance -> Homepage.\n";
        }
    }

    public function down(): void {}
};
