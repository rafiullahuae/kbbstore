<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane OHB: Appearance -> Occasion banner (five admin routes in
 * routes/occasion-banner-admin.php) and a new homepage partial. Views,
 * config, routes and the event cache are cleared and opcache reset.
 * Literals only. Nothing in the database moves.
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
            echo "Occasion banner: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
