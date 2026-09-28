<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the cards banner.
 *
 * Phase 22, Lane BN.
 *
 * A CHANGED ROUTE TABLE. `routes/web.php` gains
 * `require __DIR__.'/banners-admin.php';` inside the admin-api group, and the
 * router dispatches against `bootstrap/cache/routes-*.php` rather than against
 * the source. Without this clear, every one of the twelve
 * `/admin-api/banners…` endpoints 404s on the server and Appearance → Banners
 * is a finished-looking screen that cannot load its own list — the worst shape
 * of failure, because the owner concludes the feature is broken rather than
 * unapplied.
 *
 * AND A CHANGED COMPILED VIEW, which matters as much here and is easy to
 * forget. Three templates change:
 *
 *   resources/views/admin/partials/banners-screen.blade.php   the new screen
 *   resources/views/partials/home/cards-banner.blade.php      the new row
 *   resources/views/store/home.blade.php                      where it is drawn
 *
 * Blade serves `storage/framework/views` in preference to the template, so a
 * server that keeps the old compiled bundle has the routes and no screen to
 * reach them, and a homepage that cannot draw the row however the module is set.
 *
 * NOTHING MOVES ON THE SHOP WHEN THIS RUNS. `cards_banner` ships OFF in
 * ModuleRegistry, `module_settings.set` ships at '' (None), and both tables are
 * created empty by the two migrations beside this one. The homepage's new block
 * draws its <section> INSIDE its @if, so an unconfigured shop emits the same
 * bytes it emitted before — CardsBannerShipsOffTest renders the page with a
 * published set and cards in the table and asserts exactly that, and
 * StorefrontEnglishUnchangedTest compares the whole storefront against the tree
 * this branched from.
 *
 * ONE NEW SETTING, AND IT ARRIVES AT "NOTHING". `module_settings.set` has no
 * shipped value at all: Banners::all() answers the schema default, which is the
 * '' option spelled "None — nothing shows on the homepage". Applying the package
 * therefore moves no slider and chooses no banner.
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
            echo "Cleared {$cleared} compiled files; Appearance -> Banners -> Cards banner is now\n"
                ."reachable. Nothing on the shop moved: the section ships off, no set is chosen,\n"
                ."and both tables are empty. Build a set, add cards, publish it, pick it on that\n"
                ."screen and switch the section on to see it.\n";
        }
    }

    public function down(): void {}
};
