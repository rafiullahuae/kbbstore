<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Platform → Cache.
 *
 * NEW ROUTES, so the compiled route table is the point of this file rather than
 * a precaution: the router dispatches against bootstrap/cache/routes-*.php and
 * not against routes/web.php, so this package can land complete and every
 * button on the new screen still answer 404.
 *
 * ▲ AND A NEW MIDDLEWARE REGISTRATION, which is the reason this one matters
 * more than most. AppServiceProvider::boot() now appends
 * App\Http\Middleware\CacheHeaders to the `web` group, and the compiled
 * services.php is what tells Laravel which providers to boot at all. A stale
 * one is a shop where the new screen offers a switch that reaches nothing —
 * the owner turns caching on, the screen says it is on, and the storefront goes
 * on sending exactly what it sent before. That is the shape of failure this
 * project keeps paying for: a control that saves and does nothing.
 *
 * What changed:
 *
 *   routes/cache-admin.php            new. GET/POST /admin-api/cache, POST
 *                                     /admin-api/cache/clear and /probe,
 *                                     throttled, inside the admin-api group.
 *
 *   app/Http/Controllers/Admin/CacheApiController.php
 *                                     new. Reads the live storefront header
 *                                     through the kernel, reports the compiled
 *                                     caches and the cache store, saves the
 *                                     three settings, and runs the clears.
 *
 *   app/Support/CacheSettings.php     new. The three keys, their defaults and
 *                                     ceilings, and the two strings they build.
 *
 *   app/Http/Middleware/CacheHeaders.php
 *                                     existed and was registered NOWHERE. It
 *                                     now reads cache.headers_enabled and
 *                                     returns the response untouched while that
 *                                     is false, which is how it ships.
 *
 *   app/Providers/AppServiceProvider.php
 *                                     appends it to the `web` group, because
 *                                     bootstrap/app.php is on
 *                                     BuildPackage::NEVER_SHIP and the
 *                                     hand-edit written out in
 *                                     docs/FQ-CACHE-HEADERS.md was never made.
 *
 *   app/Support/AdminCapabilities.php cache.manage, owner-only.
 *
 *   resources/views/admin/app.blade.php and
 *   resources/views/admin/partials/cache-screen.blade.php
 *                                     the screen. A stale compiled view is how
 *                                     a package lands and the screen it adds
 *                                     goes on not existing.
 *
 * NO SETTING ROWS ARE WRITTEN HERE, and that is deliberate rather than an
 * omission. CacheSettings::SCHEMA carries the defaults and every reader goes
 * through it, so an absent row and a row holding the default are the same
 * thing. Seeding them would create three rows whose only effect is to make
 * "never touched" indistinguishable from "set back to the default".
 *
 * No schema changed. Nothing here positions a column with an AFTER clause, the
 * thing that made nine earlier migrations silent no-ops on MySQL.
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
            echo "Cleared {$cleared} compiled files; the Cache screen can now read the shop's\n"
                . "real caching state, and the browser-cache switch can reach the middleware.\n";
        }
    }

    public function down(): void {}
};
