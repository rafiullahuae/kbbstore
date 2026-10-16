<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane SC: Catalog -> Shop by Concern adds four admin routes
 * (routes/concern-sync-admin.php), so the compiled route table and views are
 * cleared, as every package that adds a route does. Literals only.
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
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($file, true);
                }
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Shop by Concern sync. Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
