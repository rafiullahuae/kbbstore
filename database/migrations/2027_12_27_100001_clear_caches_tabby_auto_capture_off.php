<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.611: Tabby auto-capture back off. TabbyGateway (a PHP class) changed --
 * only the wording of the "Capture automatically" switch -- so views, config,
 * routes and the event/command cache are cleared and opcache reset, otherwise
 * PHP keeps the old bytecode. Literals only: no application class is loaded.
 * Nothing in the database moves here (tabby_auto_capture_off does that).
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
            echo "Tabby auto-capture off: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
