<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled views and cached routes for 2.60.48.
 *
 * Every migration shipped since 2.60.41 -- including every one of these
 * cache-clearing markers -- silently never ran. The update system only
 * runs a package's migrations if its own manifest explicitly says
 * "migrations": true, and that flag was never actually set when any of
 * those packages were built. This is the first one built with that fixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ($this->targets() as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    private function targets(): array
    {
        return [
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ];
    }

    public function down(): void {}
};
