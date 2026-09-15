<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled routes and views for the manual-orders screen.
 *
 * Not a schema change. It rides in as a migration because migrations are the
 * only thing that runs on this host after the updater copies files — there is
 * no shell, so `route:clear` is not an option.
 *
 * This release ships both a new route file (routes/manual-orders-admin.php,
 * required from routes/web.php) and a new Blade partial included by
 * resources/views/admin/app.blade.php. Both are compiled:
 *
 *   * bootstrap/cache/routes-*.php holds the serialised route table. A route
 *     added to web.php is invisible until that file is gone, so every path
 *     under /admin-api/manual-orders would 404 while reading perfectly
 *     correctly in the repo — the failure mode that makes this convention
 *     worth following without exception.
 *   * storage/framework/views/*.php holds the compiled admin console. The
 *     sidebar would keep its old shape and the New Order screen simply would
 *     not be there.
 *
 * Its own dated file, as every release needs: a migration is recorded by
 * filename and runs once, so re-shipping an earlier one does nothing.
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
