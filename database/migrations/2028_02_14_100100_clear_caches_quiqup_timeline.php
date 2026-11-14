<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Quiqup P3 adds the delivery timeline refresh routes in
 * routes/quiqup-timeline.php (/track-my-order/live, /my-account/orders/{id}/live).
 * Without clearing the compiled route cache the live host knows none of
 * them: the timeline draws, but its refresh 404s. Same body as every
 * clear_caches_*.
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
            echo "Cleared {$cleared} compiled files; the delivery timeline refresh is now routed.\n";
        }
    }

    public function down(): void {}
};
