<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.475 changes App\Mail\OrderStatusChanged and two Blade views (the slim
 * footer and the marketing product cards). No route and no schema. The
 * compiled caches are cleared anyway, as every package that changes code a
 * page or an email is drawn from does: a compiled cache on the live host is
 * how a shipped change comes to be served from yesterday's copy.
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

    public function down(): void
    {
    }
};
