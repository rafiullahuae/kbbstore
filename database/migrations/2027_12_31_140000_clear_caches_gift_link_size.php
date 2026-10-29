<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.621 (Lane GBX): "View all" / "Hide" / "Gifts Criteria" text size, phone
 * and computer (Gift box popups card, 10-18px, 10 by default, was 11.5) --
 * box views and PHP changed. Views, config, routes and the event cache
 * are cleared and opcache reset, otherwise PHP keeps the old bytecode and
 * the box keeps its old 11.5px links. Literals only: no application class is
 * loaded. Nothing in the database moves: the new keys read as their defaults.
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
            echo "Gift box link size: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
