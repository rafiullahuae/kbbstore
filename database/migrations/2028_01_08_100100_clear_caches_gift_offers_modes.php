<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.640: Marketing -> Gift offers gets Off / Testing / Live and its own
 * Testing list (three new admin routes in routes/gift-offers-admin.php:
 * mode, testers, place) and "Free gift" in the emails' subject. Views,
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
            echo "Gift offers modes: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
