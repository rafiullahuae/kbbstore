<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Delete every compiled Blade template.
 *
 * Not a schema change — it rides in as a migration because the updater runs
 * migrations AFTER copying files, which is exactly when stale compiled views
 * need to go.
 *
 * Why this exists: the updater clears caches through Artisan inside a
 * try/catch that swallows failures. When route:clear fails silently, Laravel
 * keeps serving a cached route table — so a route added by the update returns
 * 404 and the screen reports that it could not load its settings.
 *
 * Deleting the files directly cannot fail the same way.
 *
 * Deleting the files directly cannot fail the same way.
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

        // Visible in the setup log, so it is obvious this ran.
        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    /** Compiled views, plus the config and route caches for good measure. */
    private function targets(): array
    {
        return [
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
        ];
    }

    public function down(): void
    {
        // Nothing to reverse: these files are regenerated on demand.
    }
};
