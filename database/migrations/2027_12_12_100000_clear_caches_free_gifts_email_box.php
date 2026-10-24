<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Free gifts: "New gifts added daily", the opened gift box on the thank-you
 * page and in the order and basket emails, full size first in the small
 * photos (2.60.591): service, view, email and console changes, so compiled
 * views are cleared and opcache reset. Literals
 * only: no application class is loaded. Nothing in the database moves here.
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
            echo "Free gifts views: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
