<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for 2.60.340 -- the clean-up gets a button.
 *
 * resources/views/admin/app.blade.php now draws "Before you import -- clean up
 * this shop" at the top of Store Import / Export. The page it opens had shipped
 * in 2.60.336 with no way in, and the owner, sent to find it, could not.
 * Compiled Blade is keyed by path, so without this the console keeps drawing
 * the screen without the card -- the exact thing he reported.
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
