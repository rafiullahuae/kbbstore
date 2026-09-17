<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled caches, because this package adds routes and changes the
 * container's bindings (Lane EP).
 *
 * CLAUDE.md states the rule and the reason: the server has no shell, so nothing
 * can run `php artisan route:clear` there. A route added in a file that
 * routes/web.php requires does not exist until the serialised route table under
 * bootstrap/cache is gone.
 *
 * NINE NEW ROUTES, all in routes/translations-admin.php:
 *
 *   GET  /admin-api/translations/settings
 *   POST /admin-api/translations/settings
 *   GET  /admin-api/translations/progress
 *   GET  /admin-api/translations/estimate
 *   GET  /admin-api/translations
 *   POST /admin-api/translations
 *   POST /admin-api/translations/publish
 *   POST /admin-api/translations/machine/field
 *   POST /admin-api/translations/machine/run
 *
 * `config.php` goes because AppServiceProvider now registers two container
 * bindings at boot — the translation loader and the machine-translation
 * provider — and a compiled container that predates them would resolve the
 * framework's own FileLoader instead, which is a shop where every translation
 * the owner typed is invisible and nothing anywhere says why.
 *
 * THE COMPILED VIEWS GO TOO, and not merely for tidiness. This package changes
 * layouts/store.blade.php, store/wishlist.blade.php and
 * emails/order-status.blade.php, and Blade compiles to files named by a hash of
 * the view's PATH, not its contents. A stale compiled view is served happily
 * forever — so the wishlist page would keep rendering the old hard-coded
 * English after the strings had moved into the database.
 *
 * NO SCHEMA CHANGE HERE. The two migrations beside this one carry those, and
 * neither uses ->after() — see tests/Feature/MigrationConventionTest.php.
 *
 * NOTHING IS SEEDED, AND THAT IS THE POINT. Arabic and right-to-left are both
 * off after this runs, because SettingsService::get() returns its default when
 * the row is ABSENT and no row is written here. Applying this package changes
 * nothing a shopper can see. The same shape `legal_notice` and `brands`
 * shipped in.
 *
 * Best-effort throughout, as the other clear_caches migrations are: a file that
 * cannot be unlinked mid-update must not fail the package and strand the site
 * half-updated. Anything left behind is a stale cache, which is a visible bug;
 * a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the Translation admin routes are now reachable.\n";
            echo "Arabic and right-to-left both ship OFF — Translation → Settings turns them on.\n";
        }
    }

    public function down(): void {}
};
