<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Payments gateway tabs. (Lane AZ)
 *
 * VIEWS are the sharp edge in this package, not routes. It adds no route at
 * all — Store → Payments already talks to GET and POST /admin-api/payments,
 * both mounted from routes/payments-admin.php inside the admin-api group, and
 * this lane rearranged the screen without touching the payment path.
 *
 * The one changed file, resources/views/admin/app.blade.php, ALREADY EXISTS on
 * the server, and compiled Blade is keyed by path with no content check. An
 * existing file is precisely the case that does not self-correct: the console
 * would keep serving the previously compiled copy, the Payments screen would
 * render as the same long stack of gateway cards it always was, and the
 * package would look inert rather than broken. The owner would reasonably
 * conclude the tabs had never shipped.
 *
 * CONFIG and SERVICES go too, on the standing principle recorded in CLAUDE.md:
 * this host has no shell, OPcache cannot be restarted from one, and packages
 * 2.60.102-.106 are still cited there for what a stale compiled tree does to a
 * live storefront. Routes are cleared as well even though none changed —
 * rebuilding a route cache that was already correct costs one request, and
 * leaving a stale one costs every product page.
 *
 * No schema change. Nothing here positions a column with an AFTER clause, the
 * thing that made nine earlier migrations in this repo silent no-ops on MySQL.
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
