<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the bundle price block.            (2.60.331)
 *
 * NO ROUTE CHANGED, so this is the `clear_caches_*` convention serving its
 * other half: Blade renders `storage/framework/views` in preference to the
 * template it was compiled from, and `resources/views/store/product.blade.php`
 * changed shape -- every bundle row and every variation row now carries
 * `data-was` and `data-off`, which is where the corrected figures come from.
 *
 * ▲ THE FAILURE MODE IF THIS DOES NOT RUN IS THE ONE THAT LOOKS LIKE A FIX
 *   THAT DID NOT WORK, which is why it is not optional here.
 *
 *   This package ships BOTH halves of one change and they are cached by
 *   DIFFERENT mechanisms: the template through the compiled-view cache, and
 *   pdp.js through a content-hashed filename the browser fetches fresh. So the
 *   new script arrives whatever happens, and on a server with a stale compiled
 *   view it reads `data-was`/`data-off` attributes that the old template never
 *   emitted. `setPrice()` is handed two empty strings, takes its `if (!el ||
 *   !html) return` path for them, and the block goes on printing the PRODUCT's
 *   -25% beside a tier discounted 6%.
 *
 *   That is the defect this release exists to close, still on screen, with the
 *   package showing as applied and the corrected script demonstrably present in
 *   the page source. Nothing about it says "stale cache", and it would send
 *   somebody reading pdp.js -- which is correct -- rather than clearing a
 *   directory.
 *
 * NO CACHE KEY IS DROPPED HERE, and that is deliberate rather than forgotten.
 * This round writes no setting, no module toggle and no column: the figures are
 * computed per request by BundleService, which this round did not touch, and
 * printed straight into the markup.
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
            echo "Cleared {$cleared} compiled files. On a product with bundles or sizes, the\n"
                ."big price now shows the discount of the row you actually pressed. Pressing\n"
                ."the 2-pack used to leave the single unit's '-25%' on screen beside a bundle\n"
                ."discounted 6%. Nothing to switch on: the page corrects itself.\n";
        }
    }

    public function down(): void {}
};
