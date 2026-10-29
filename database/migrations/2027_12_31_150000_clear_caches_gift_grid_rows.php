<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.622 (gift box): the Gifts Criteria grid fills its first row before
 * its second, on phone and computer, and the Gifts Criteria link keeps no
 * outline after a tap or click. Box views only. Views, config, routes and
 * the event cache are cleared and opcache reset so the shop serves the new
 * views at once. Literals only: no application class is loaded. Nothing in
 * the database moves.
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
            echo "Gift grid rows: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
