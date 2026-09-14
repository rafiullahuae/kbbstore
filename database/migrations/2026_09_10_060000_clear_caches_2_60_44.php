<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled views and cached routes for 2.60.44.
 *
 * This release changes several route paths and adds new ones (brand
 * scoping, save-page, the reworked move endpoint), so the cached route
 * table has to go or they won't resolve until it expires naturally.
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
        ];
    }

    public function down(): void
    {
        // Nothing to reverse: these files are regenerated on demand.
    }
};
