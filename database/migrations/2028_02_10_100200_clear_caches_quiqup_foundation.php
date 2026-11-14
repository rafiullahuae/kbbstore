<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Quiqup P1 adds routes (routes/quiqup-admin.php, routes/quiqup-webhooks.php).
 * Without clearing the compiled route cache the live host knows none of them:
 * Store -> Quiqup renders and every request 404s, and Quiqup's webhook
 * deliveries 404 -- which Quiqup counts as failures and, at 50, pauses the
 * subscription without telling anyone. Same body as every clear_caches_*.
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
            echo "Cleared {$cleared} compiled files; Store -> Quiqup and the Quiqup webhook address are now routed.\n";
        }
    }

    public function down(): void {}
};
