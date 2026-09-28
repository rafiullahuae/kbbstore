<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code caches, for the set's buy column. (Lane SF)
 *
 * TWO REASONS, and no route among them — this release adds no endpoint:
 *
 *   THE COMPILED VIEWS. It adds resources/views/partials/set-contents-row.-
 *   blade.php and REWRITES two Blade files that are already on the server:
 *   partials/set-contents-panel.blade.php (a grid in a section of its own,
 *   now a list in the buy column) and store/product.blade.php (the @include
 *   moved from the foot of the page into the buy form). It also edits
 *   admin/partials/product-editor-screen.blade.php, for one line of help text
 *   in the Brand panel.
 *
 *   storage/framework/views keys a compiled view by the path of its source and
 *   decides staleness on file times, and an unzip's timestamps are not
 *   reliably newer than what is already on disk. Re-shipping the sources does
 *   nothing on its own; deleting the compiled copies is what makes them take
 *   effect. THIS ONE MATTERS MORE THAN USUAL: the panel's markup changed
 *   completely, so a stale compiled copy means a set page drawing the old grid
 *   from the old place while the new CSS is nowhere — the shop would look
 *   broken rather than unchanged.
 *
 *   OPCACHE, one layer down: App\Services\BundleService and
 *   App\Services\Translation\InterfaceStrings are both files the server has
 *   already compiled, and both change in this release.
 *
 * ── WHAT MOVES ON THE SHOP, AND IT IS WHAT WAS ASKED FOR ──────────────────
 *
 * NO SETTING ROW IS WRITTEN AND NO COLUMN IS ADDED. Everything below is
 * visible and every bit of it is in the owner's own words, with a marked-up
 * screenshot behind it:
 *
 *   "the Set product will not have bundle purchase, instead of that section,
 *    bring the What's inside there, and make it nice list, not grid! also the
 *    mobile screen will adjust that list nicely and display."
 *
 * So, on a SET's product page and nowhere else:
 *
 *   - the quantity-bundle strip ("1 unit / 2-pack bundle / 3-pack bundle") is
 *     gone. BundleService::forProduct() answers an empty list for a product of
 *     type `set`;
 *   - what is in the box is drawn IN THE SPACE THAT FREED — inside the buy
 *     column, between the price and the stock line — instead of in a section
 *     near the foot of the page;
 *   - it is a LIST, one member per row, and no longer a grid of square tiles;
 *   - a box of more than six members shows five and folds the rest into an
 *     HTML <details>, so Add to cart stays on a phone screen.
 *
 * AN ORDINARY PRODUCT'S PAGE IS UNCHANGED. It keeps its bundle strip and it
 * has no set list; StorefrontEnglishUnchangedTest renders one and is green.
 * THE PRICING PATH IS UNTOUCHED: unitFor(), totalFor() and discountFor() are
 * exactly as they were, so applying this reprices no basket that exists.
 *
 * ▲ THE `migrations` FLAG IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT
 *   ALL — UpdateRunner::hasMigrations() never looks at the files. Build the
 *   package with `php artisan kbb:package <version> --since=<ref>`, never a
 *   hand-rolled script. Five packages built by one in an afternoon shipped
 *   eight migrations that were copied to the live server and never ran.
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
            echo "Cleared {$cleared} compiled files. On a SET's product page the bulk-quantity\n"
                ."strip is gone, and what is in the box now sits in the space it left --\n"
                ."in the buy column, between the price and Add to cart, as a LIST rather\n"
                ."than a grid of tiles, with its own sizes on a phone. A box of more than\n"
                ."six products shows five and folds the rest behind \"Show N more products\",\n"
                ."so the Add to cart button stays on the screen.\n"
                ."\n"
                ."Ordinary product pages are untouched -- they keep their bundle strip --\n"
                ."and no basket is repriced.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
