<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the pre-migration cleanup screen.        (Lane IE)
 *
 * routes/cleanup-admin.php adds two routes —
 *
 *     GET  /admin-api/cleanup/preview
 *     POST /admin-api/cleanup/purge
 *
 * — and routes/web.php is compiled on the server, so neither exists until
 * bootstrap/cache/routes-*.php is gone. CLAUDE.md makes the pairing of a route
 * and a clear_caches migration a convention for exactly this reason.
 *
 * NOTE FOR WHOEVER BUILDS THE PACKAGE: `update.json`'s `migrations` key is the
 * ONLY thing that decides whether migrations run at all — UpdateRunner's
 * hasMigrations() never looks at the files. A package that carries this and
 * does not declare it ships two dead routes. `php artisan kbb:package` gets it
 * right; a hand-rolled builder is what shipped eight migrations that never ran.
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
            echo "Cleared {$cleared} compiled files. Store -> Import now has a cleanup that\n"
                ."SHOWS what it would delete before it deletes anything: the seeded demo\n"
                ."products, the seeded demo reviews, old applied update packages and the\n"
                ."log files. Nothing is deleted by opening it, a product carrying a\n"
                ."WooCommerce id is never touched, and a demo product that has been sold\n"
                ."is kept. NOTHING ON THE SHOP HAS CHANGED until you press the button.\n";
        }
    }

    public function down(): void {}
};
