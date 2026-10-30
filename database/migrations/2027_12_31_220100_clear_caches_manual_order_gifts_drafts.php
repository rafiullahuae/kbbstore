<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.630 (New Order): free gifts on manual orders and Save as draft --
 * four new routes under admin-api/manual-orders/drafts, and the builder and
 * controller changed. Views, config, routes and the event cache are cleared
 * and opcache reset. Literals only. Nothing in the database moves.
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
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "New Order gifts and drafts: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
