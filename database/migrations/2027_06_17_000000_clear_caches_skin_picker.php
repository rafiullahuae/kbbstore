<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the grid-style picker fix.             (2.60.333)
 *
 * The console's stylesheet is INLINE in resources/views/admin/app.blade.php,
 * so there is no content-hashed file to bust and nothing the browser will
 * refetch on its own: the fix lives entirely inside a compiled Blade view. A
 * server that keeps its compiled copy shows the owner exactly the console he
 * photographed -- the grid-style panel opening off the settings panel and
 * disappearing under the sidebar -- with this package reporting as applied.
 *
 * That is the whole reason this migration exists. Nothing else in this package
 * can take effect without it.
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
            echo "Cleared {$cleared} compiled files. Appearance -> Homepage: the 'Grid style'\n"
                ."picker now opens to the RIGHT of the button and stays inside the panel.\n"
                ."It was opening leftwards, off the panel and under the sidebar, so the\n"
                ."designs could not be reached at all.\n";
        }
    }

    public function down(): void {}
};
