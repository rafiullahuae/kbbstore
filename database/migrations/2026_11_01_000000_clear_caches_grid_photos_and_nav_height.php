<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the grid photographs and the nav bar's height.
 *
 * TWO BLADE FILES CHANGED. components/product-card.blade.php now renders each
 * tile's photograph as a real <img> -- lazy below the first, eager and
 * high-priority for the one tile that is the Largest Contentful Paint
 * candidate -- instead of painting it as a CSS background on .ph, and
 * store/shop.blade.php is what tells the first tile it is the first.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND THE SHOP LOOKS UNCHANGED, because the
 * server renders Blade from storage/framework/views. The compiled copy of the
 * card would go on writing a style attribute with the photograph inside it,
 * from a file that is no longer on disk, and every measurement this change was
 * made for would read exactly as it did before. The card is an <x-...>
 * component compiled into each page that uses it -- the shop archive, every
 * category archive, the product page's related grid -- so the views are
 * cleared wholesale rather than by name.
 *
 * THE STYLESHEET CHANGED TOO, AND A CACHE CLEAR IS NOT ENOUGH FOR IT.
 * resources/css/kbb/kbb.css is a Vite entry: it gains .pc .ph-img, which is
 * what holds the new photograph inside its already-reserved frame, and a fixed
 * line-height on .navlink, which is what stops the whole page sliding up when
 * nav-fit.js shrinks the menu after first paint. The server runs the compiled
 * bundle under public/build, so THE PACKAGE THAT CARRIES THIS MIGRATION MUST
 * ALSO CARRY A REBUILT BUNDLE. Ship the Blade without the CSS and the tiles
 * render an unstyled image in a frame that no longer contains it.
 *
 * NO ROUTE CHANGED. The route cache is dropped with the rest because the cost
 * is nil and a half-cleared cache is harder to reason about.
 *
 * NO SCHEMA CHANGE AND NO SEEDED ROW. Nothing here reads or writes a table.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
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
