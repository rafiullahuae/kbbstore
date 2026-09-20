<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for Settings → Site address.
 *
 * TWO NEW ROUTES SHIP WITH THIS PACKAGE:
 *
 *     GET  /admin-api/site-address        the current settings, and the check
 *     POST /admin-api/site-address        save them
 *
 * The route table is compiled on the server and the host has no shell, so a
 * route added by an update package does not exist until
 * `bootstrap/cache/routes-*.php` is gone. CLAUDE.md makes the pairing a
 * convention.
 *
 * ▲ AND THERE IS A SECOND REASON HERE, WHICH IS THE REAL ONE.
 *
 * This package also registers a new global middleware —
 * App\Http\Middleware\CanonicalHost, from AppServiceProvider::boot(). A
 * compiled `bootstrap/cache/services.php` left in place beside the new provider
 * code is a shop where the provider that registers the middleware may not be
 * the one that runs, so the middleware silently does not exist.
 *
 * That failure is quiet in the dangerous direction. Nothing 500s and nothing
 * looks broken: the owner fills in the canonical host, switches forwarding on,
 * sees the screen save successfully — and the old domain keeps serving a second
 * copy of the shop, and the staging site keeps being indexed, while the setting
 * that was supposed to stop both reads as on. The one thing this feature exists
 * to prevent would be happening on a site whose settings screen says it is not.
 *
 * `config.php`, `routes-*.php` and `packages.php` go for the reason every
 * clear_caches migration here gives, and the compiled views because the
 * Settings screen ships Blade with this package.
 *
 * THE SETTINGS DEFAULTS ARE IN THEIR OWN MIGRATION and land before this one —
 * 2026_11_26_000000_add_site_address_settings.php. A screen that reads a key
 * that does not exist yet is a 500 on first open.
 *
 * Best-effort, like every clear_caches migration here: a file that cannot be
 * unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the shop can now be told which address is\n";
            echo "its real one, and a private install can be kept out of every search index.\n";
        }
    }

    public function down(): void {}
};
