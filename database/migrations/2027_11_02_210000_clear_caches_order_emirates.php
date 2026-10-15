<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane EM: Store -> Orders gains the Emirate column and the "Orders by
 * emirate" strip, the owner app its emirate chips. Compiled views and caches
 * cleared, and the changed controllers dropped from opcache, as every screen
 * change has been. Literals only -- nothing this package adds is called here, because
 * the updater can still be running the old classes (see 2.60.513).
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
            base_path('bootstrap/cache/events.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($file, true);
                }
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_invalidate')) {
            foreach ([
                'resources/views/admin/app.blade.php',
                'app/Http/Controllers/Admin/OrdersApiController.php',
                'app/Http/Controllers/Admin/AdminOrderController.php',
                'app/Http/Controllers/OwnerApp/OrdersController.php',
            ] as $relative) {
                @opcache_invalidate(base_path($relative), true);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Order emirates. Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
