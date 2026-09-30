<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the alignment and FBT round.           (2.60.332)
 *
 * NO ROUTE CHANGED, so this is the `clear_caches_*` convention serving its
 * other half. Two things in this package need it and they need it for
 * different reasons:
 *
 *   THE STYLESHEET IS CONTENT-HASHED, so `kbb-product-<hash>.css` arrives at
 *   the browser fresh on its own. What does NOT arrive fresh is the compiled
 *   Blade that LINKS it: `public/build/manifest.json` is read at render time,
 *   and a compiled view holding the previous hash keeps asking for a file this
 *   package replaced. The page then renders with NO product stylesheet at all
 *   -- not the old one, a 404 -- which is a far worse outcome than the two
 *   misalignments this release fixes.
 *
 *   AND `partials/fbt.blade.php` GAINED ITS FIRST STYLESHEET EVER. That strip
 *   has been unstyled since the move off WordPress, whose plugin supplied its
 *   own CSS that was never ported: rows measured 18, 81, 156 and 210px at
 *   320px, thumbnails floating out, the tick box and the name and the price
 *   running together as one paragraph. A server that kept its compiled copy
 *   shows exactly that, with the package reporting as applied.
 *
 * NO CACHE KEY IS DROPPED HERE, deliberately. This round writes no setting, no
 * module toggle and no column -- it is stylesheet and markup only.
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
            echo "Cleared {$cleared} compiled files. On a product page the photograph now stops\n"
                ."at the same left edge as your logo, and the quantity box is level with Add to\n"
                ."cart. 'Frequently bought together' has proper styling for the first time --\n"
                ."its rows were four different heights. Nothing to switch on.\n";
        }
    }

    public function down(): void {}
};
