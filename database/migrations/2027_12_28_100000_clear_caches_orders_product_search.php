<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane OQ: Store → Orders finds an order by the PRODUCT in it (name, SKU or
 * option), shows the line that matched and the product in the view, and the
 * order screen draws a quantity over 1 in a red circle. OrdersApiController,
 * the owner app OrdersController and App\Support\OrderProductSearch (PHP) and
 * the admin console (a Blade view) changed, so views, config, routes and the event/command
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
            echo "Orders product search: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
