<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane GU2: the "Gifts go fast" line on the homepage free-gifts section and
 * its size / weight / speed controls change App\Services\FreeGifts (new
 * urgencyWords(), LOOK u_* keys, HOME['urgency'], home()'s payload) and the
 * admin controller, and the Blade that reads them. Without an opcache reset a
 * server can serve the OLD FreeGifts to the NEW partials (an undefined index
 * 'u_px' on every page with a gift box). So: compiled views, config, routes
 * and events cleared, opcache reset. Literals only; nothing in the database
 * moves (both defaults live in FreeGifts' constants, not a write).
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
            echo "Gift urgency line (homepage): cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
