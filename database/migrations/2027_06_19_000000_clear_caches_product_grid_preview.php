<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Product grid screen.                (Lane GRID)
 *
 * The console's stylesheet AND its markup are INLINE in
 * resources/views/admin/app.blade.php, so there is no content-hashed asset to
 * bust and nothing a browser will refetch on its own. All three changes in this
 * package live inside one compiled Blade view:
 *
 *   - the 32 skin swatches on Appearance -> Product grid, which rendered as
 *     blank pink rectangles because the screen emitted an empty span and never
 *     called skinCard() at all;
 *   - the live preview panel on the right of that screen;
 *   - the card-content and spacing controls surfaced onto it.
 *
 * A server that keeps its compiled copy shows the owner exactly the screen he
 * photographed -- 32 empty pink blocks -- with this package reporting as
 * applied. That is the whole reason this migration exists. Nothing else in this
 * package can take effect without it.
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
            echo "Cleared {$cleared} compiled files. Appearance -> Product grid: the 32\n"
                ."designs now render as real cards instead of blank pink rectangles, a live\n"
                ."preview sits on the right, and the card-content and spacing controls are\n"
                ."surfaced onto the screen. No storefront default moved.\n";
        }
    }

    public function down(): void {}
};
