<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Store → Customers rebuild.
 *
 * Two reasons, and both fail silently rather than erroring, which is why this
 * migration ships with the package rather than being remembered later:
 *
 *   - ROUTES. This package adds seven routes (routes/customers-admin.php,
 *     required into web.php's admin-api group). The production host serves a
 *     compiled route table out of bootstrap/cache/routes-*.php; until that file
 *     is gone, every one of them is a 404 and the new screen shows "Could not
 *     load customers" on a server whose code is completely correct. This is the
 *     convention CLAUDE.md records for exactly this reason.
 *
 *   - VIEWS. resources/views/admin/app.blade.php changed — the whole Customers
 *     screen. Compiled Blade is keyed by source path and mtime, which is
 *     normally enough, but the admin console is one enormous compiled file and
 *     a stale copy means the console keeps rendering the old six-column table
 *     with the blank Emirate column. Nothing errors; the package simply looks
 *     like it did not ship.
 *
 * OPcache too: CustomersApiController is new, and a worker still holding a
 * compiled copy of the previous class map resolves nothing for it.
 *
 * No schema change. This package deliberately adds no column — see the report
 * on the import mapping for the columns that are missing and whose lane they
 * belong to.
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
