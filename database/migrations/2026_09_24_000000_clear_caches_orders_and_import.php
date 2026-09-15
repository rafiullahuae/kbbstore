<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Orders screen and the import foundation.
 *
 * This package adds routes (routes/orders-admin.php, mounted in web.php), so
 * a compiled route cache would answer 404 for the whole Orders screen. It also
 * changes admin/app.blade.php — the Orders section, the top-bar wrap on narrow
 * screens, and hiding the fixed build badge there — so a stale compiled view
 * keeps the console scrolling sideways on a phone. And it changes
 * CustomersApiController (LIKE escaping) and Customer, whose previous compiled
 * copies OPcache would happily keep serving.
 *
 * Every package that changes a PHP class ships one of these, not only the ones
 * that add routes. 2.60.121 is why.
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
