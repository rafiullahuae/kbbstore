<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.602: the "Gifts go fast" line under the free-gifts box changes
 * App\Services\FreeGifts (new urgency()/urgencySlice()) and the Blade that calls
 * them. Without an opcache reset a server can keep serving the OLD FreeGifts
 * class to the NEW box partial, and the cart panel would call a method that
 * does not exist yet. So: compiled views, config, routes and events cleared,
 * opcache reset. Literals only; nothing in the database moves (the line is ON
 * through FreeGifts::LOOK's default, not a write).
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
            echo "Gift urgency line: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
