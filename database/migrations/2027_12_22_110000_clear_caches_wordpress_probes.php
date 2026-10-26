<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.604: WordPress probes (/wp-json, /xmlrpc.php, /wp-login.php ...) answer
 * 410 Gone from a new global middleware, and 404 pages stop counting in Store ->
 * Analytics. Analytics\Online now calls a NEW Analytics\Tracker method, so a
 * server that kept the old Tracker in opcache beside the new Online would fail
 * the "online now" heartbeat. Compiled views, config, routes and events
 * cleared, opcache reset. Literals only; nothing in the database moves.
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
            echo "WordPress probes: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
