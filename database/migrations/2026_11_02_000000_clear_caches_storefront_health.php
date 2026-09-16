<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the storefront health check — Lane DH.
 *
 * A NEW ROUTE, so the route cache is the point of this file rather than a
 * precaution. GET /admin-api/health does not exist until bootstrap/cache/
 * routes-*.php is gone: the router dispatches against the compiled table, not
 * against routes/web.php, so the package can land complete and the Check now
 * button still 404s. That is the failure this convention exists for.
 *
 * What changed:
 *
 *   routes/health-admin.php           new. GET /admin-api/health, throttled,
 *                                     inside the admin-api group.
 *
 *   app/Http/Controllers/Admin/HealthApiController.php
 *                                     existed and was routed nowhere. Now it
 *                                     restores the container's `request` after
 *                                     its sub-requests (Kernel replaces it and
 *                                     never puts it back), puts the session's
 *                                     previous URL back, and reports the real
 *                                     exception message and app file:line for a
 *                                     failing page — which its `catch` could
 *                                     never do, because Kernel::handle() never
 *                                     rethrows.
 *
 *   app/Support/CapturingExceptionHandler.php
 *                                     new. Decorates the real handler for the
 *                                     length of one probe so the throwable can
 *                                     be read. OPcache is the half that matters
 *                                     for new PHP on a host with no shell — the
 *                                     file a package writes is not the file the
 *                                     server runs until OPcache lets go, the
 *                                     standing reason behind the withdrawn
 *                                     packages 2.60.102-.106 in CLAUDE.md.
 *
 *   app/Support/AdminCapabilities.php system.diagnostics for the new route.
 *
 *   resources/views/admin/app.blade.php
 *                                     the Dashboard health card, Debug &
 *                                     Monitor, the Debug sidebar badge, Shop
 *                                     Filters and Platform → Settings. A stale
 *                                     compiled view is how a package lands and
 *                                     the screens it fixes go on looking
 *                                     unfixed.
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
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
