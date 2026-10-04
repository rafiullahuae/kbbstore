<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the admin menu search.                      (Lane SR)
 *
 * admin/app.blade.php gains @include('admin.partials.admin-search') at the top
 * of the sidebar, and App\Support\AdminSearchIndex is new. A compiled copy of
 * the console is keyed by path and an unzip does not reliably win the
 * filemtime compare, so a stale compiled view would leave the search box out
 * with nothing logged. No route, no table, no setting: the index is built from
 * the screens' own schemas and kept in one file-store key.
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
            echo "The admin menu now has a search box at the top: type to find any screen, tab or setting (Ctrl/Cmd+K or /).\n";
        }
    }

    public function down(): void {}
};
