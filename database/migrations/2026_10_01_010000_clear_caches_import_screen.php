<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Import / Export screen.
 *
 * Two reasons, and both of them have cost this project a package before.
 *
 * NEW ROUTES. routes/import-admin.php adds eleven endpoints under
 * /admin-api/import. A compiled route cache is a PHP file of the routes that
 * existed when it was written, and the router dispatches against that file and
 * not against the source, so every one of them answers 404 until it is gone —
 * with the admin console showing "could not reach the server" and nothing in
 * the log to explain it.
 *
 * NEW AND CHANGED CLASSES. ImportApiController, ImportDriver and
 * ImportWorkspace are new; resources/views/admin/app.blade.php changed. A stale
 * compiled view keeps rendering the old mock import wizard — the one with the
 * fake progress bars that never touched the database — which is the worst
 * possible failure here, because it LOOKS like it worked.
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

        Cache::forget('kbb.modules');
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
