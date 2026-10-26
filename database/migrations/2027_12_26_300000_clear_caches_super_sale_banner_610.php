<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.610: the tabby/tamara logos and gift icon draw in the editor's preview
 * too, the pay-later line sits on its own row under the logos, and the
 * computer gets "Space above the logos" / "Space below the coupon" controls.
 * SuperSaleBanner (a PHP class) changed, so views, config, routes and the
 * event/command cache are cleared and opcache reset -- otherwise PHP keeps the
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
            echo "Super Sale banner: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
