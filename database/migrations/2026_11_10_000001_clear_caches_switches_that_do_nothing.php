<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled Blade views, because this package changes templates
 * (Lane EM).
 *
 * CLAUDE.md states the rule and the reason: the server has no shell, so nothing
 * can run `php artisan view:clear` there. Blade compiles to files named by a
 * hash of the view's PATH, not its contents, so a stale compiled view is served
 * happily forever — which here would mean the two gates this package adds never
 * take effect and the Mega Menu switch goes on doing nothing, the exact defect
 * the package exists to fix.
 *
 * TWO CHANGED TEMPLATES:
 *
 *   resources/views/partials/nav-bar.blade.php          the dropdown gate
 *   resources/views/partials/mobile-menu-item.blade.php the phone-menu gate
 *
 * NO NEW ROUTES in this package, so the route cache is not the reason — but it
 * goes with the rest anyway, because dropping the lot is cheaper than
 * enumerating which and a stale route table has never been the safer half of
 * that trade.
 *
 * `config.php` goes because ModuleRegistry's defaults are read through code
 * that boots from it, and 2026_11_10_000000 beside this one rewrites the stored
 * toggle it is compared against.
 *
 * NO SCHEMA CHANGE HERE, and no ->after() — see
 * tests/Feature/MigrationConventionTest.php for why that matters on this host.
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
            echo "Cleared {$cleared} compiled files; the Mega Menu switch now reaches the storefront.\n";
        }
    }

    public function down(): void {}
};
