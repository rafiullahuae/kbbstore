<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane GBX: Marketing -> Free gifts -> Overview -> Gift box popups -- a new
 * admin route (POST /admin-api/free-gifts/extras, routes/free-gifts-admin.php),
 * new box views and PHP classes. Views, config, routes and the event cache
 * are cleared and opcache reset, otherwise the compiled route table would not
 * have the new route and PHP would keep the old bytecode. Literals only: no
 * application class is loaded. Nothing in the database moves -- the popups
 * ship OFF (FreeGifts::EXTRAS) until the owner switches them.
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
            echo "Gift box popups: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
