<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane FGV: a free gift can be ONE VARIATION of a product with options ("the
 * pink one"). FreeGifts, FreeGiftsController, the checkout's gift lines, the
 * order screen and the order email (PHP classes) and the Free gifts screen
 * (a Blade partial) changed, so views, config, routes and the event/command
 * cache are cleared and opcache reset -- otherwise PHP keeps the old bytecode.
 * Literals only: no application class is loaded. Nothing in the database moves.
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
            echo "Free gifts (variations): cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
