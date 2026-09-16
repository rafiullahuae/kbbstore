<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the shared sidebar-entry helper.
 *
 * Four Blade files changed — admin/app.blade.php, which now declares
 * kbbAddNavEntry(), and the three screen partials that used to build their own
 * sidebar row by hand (product-editor-screen, brands-editor-screen,
 * category-tree-screen). The compiled view cache is keyed on the source path,
 * not its contents, so without this the console would keep rendering the old
 * compiled copies: the partials would still be hand-rolling their rows and
 * app.blade.php would still have no helper for them to call. The symptom would
 * be nothing at all — exactly the failure mode the helper exists to end.
 *
 * No route changed and no schema changed. The route cache is cleared with the
 * rest anyway, because the cost is nil and a half-cleared cache is the harder
 * thing to reason about later.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets
 * go of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * Nothing is read through Setting::map() by this change, so unlike the badge
 * migration this one has no cache key to forget. Nothing here positions a
 * column with an AFTER clause, the thing that made nine earlier migrations
 * silent no-ops on MySQL.
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
