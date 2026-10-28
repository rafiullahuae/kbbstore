<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.615: owner app -> Free gifts, Off / Testing / Live locked behind the
 * app PIN (OwnerAppAuth::confirmPin, GiftsController::mode). PHP classes
 * changed, so views, config, routes and the event/command cache are cleared
 * and opcache reset -- otherwise PHP keeps the old bytecode and the mode would
 * still switch without the PIN. Literals only: no application class is
 * loaded. Nothing in the database moves.
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
            echo "Gifts mode PIN lock: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
