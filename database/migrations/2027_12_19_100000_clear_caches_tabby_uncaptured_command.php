<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.599 adds the `payments:tabby-uncaptured` artisan command. No route and no
 * schema change, but clear the compiled caches and reset opcache so the server
 * runs the new file (and drops any cached command/event list in events.php).
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
            echo "Tabby uncaptured command: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
