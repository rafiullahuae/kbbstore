<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane GO: Marketing -> Gift offers adds routes (routes/gift-offers.php for
 * the shop's claim, clock and popup addresses, routes/gift-offers-admin.php
 * for the screen) and changes views. Views, config, routes and the event
 * cache are cleared and opcache reset. Literals only. Nothing in the
 * database moves.
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
            echo "Gift offers: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
