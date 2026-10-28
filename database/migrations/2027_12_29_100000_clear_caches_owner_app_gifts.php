<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane OAG: Free gifts in the owner app (routes/owner-app.php gains
 * /api/gifts*), and both screens guard ranges-and-gifts saves with a revision
 * (409 on a stale save). OwnerApp\GiftsController, FreeGiftsController,
 * FreeGifts, OwnerAppUiGate, AppController and the Free gifts Blade partial
 * changed, so views, config, routes and the event/command cache are cleared
 * and opcache reset -- otherwise the new routes do not exist and PHP keeps the
 * old bytecode. Literals only: no application class is loaded. Nothing in the
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
            echo "Free gifts (owner app): cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
