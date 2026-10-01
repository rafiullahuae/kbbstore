<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for 2.60.339 -- "remove everything not from WordPress".
 *
 * resources/views/admin/cleanup.blade.php now lists six more buckets and says
 * truthfully what it never touches. Compiled Blade is keyed by path, so a stale
 * copy would draw the old four-bucket screen -- and the old promise that it
 * never touches an order or a customer -- over a service that now offers both.
 * OPCACHE for App\Services\Maintenance\PreMigrationCleanup, whose BUCKETS the
 * controller validates against: a worker holding the old class would silently
 * drop every new bucket name the screen sends.
 *
 * Same body as 2027_07_02_000000_clear_caches_import_parts.php.
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
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
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

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
