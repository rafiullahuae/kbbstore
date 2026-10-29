<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.623 (gift box): the Gifts Criteria popup opens without the slide, a
 * range that scrolls shows 3.2 gifts across, and a tap on a photo in the box
 * opens the gift list as View all does. Box views only. Compiled views,
 * config, routes and the event cache are cleared and opcache reset so the
 * shop serves the new views at once. Literals only. Nothing in the database
 * moves.
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
            echo "Gift box photos and popup: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
