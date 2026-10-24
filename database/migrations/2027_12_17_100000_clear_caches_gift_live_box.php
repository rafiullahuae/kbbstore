<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * The live free gifts box and its photo row (2.60.596): a new shop route
 * (GET /api/cart/gifts), so the route cache is cleared with compiled views and
 * config, and opcache reset. Literals only: no application class is loaded.
 * Nothing in the database moves here; Photo row ships at B, as asked.
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
