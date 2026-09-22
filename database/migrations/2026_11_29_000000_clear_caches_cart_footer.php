<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled views for the cart page's footer switch.
 *
 * WHAT IS STALE IF THIS DOES NOT RUN. The package changes two Blade files and
 * nothing else that the running site would notice:
 *
 *   resources/views/store/cart.blade.php     declares the `no-footer` section
 *   resources/views/layouts/store.blade.php  reads it beside 'bare'
 *
 * Compiled Blade is keyed by the view's PATH, never by its contents, and its
 * freshness check is a filemtime comparison. An update package is an unzip, so
 * the timestamps it lands are whatever the archive carried and are not reliably
 * newer than the compiled copy the running site wrote. A stale copy of a file
 * that already exists is exactly the case that does not self-correct — and it
 * is how 2.60.102-.106 shipped inert.
 *
 * AND IT WOULD FAIL IN THE WORST WAY: HALF APPLIED. The two files are compiled
 * separately, so a host that picks up the new cart page but keeps the old
 * layout gets a page that declares a section nobody reads — the footer stays,
 * and the switch on the admin screen does nothing. A host that picks up the new
 * layout and keeps the old cart page gets the opposite: a layout asking a
 * question no template answers, so every page including the cart keeps its
 * footer. Either way the owner applies the package, looks at the cart, sees the
 * footer, and reasonably concludes the package did not ship.
 *
 * NO SCHEMA CHANGE AND NO ROUTE CHANGE. `cartpage_footer_on` lives in the
 * `settings` key-value table and is written by the existing Appearance → Cart
 * page endpoint, so there is no column to add and no endpoint to register. The
 * route and config caches are cleared anyway because it costs nothing, and
 * because `Setting::map()` memoises — a config cache written before this key
 * existed is the other way an update like this survives into the next request.
 *
 * Best-effort, like every clear_caches migration in this set: a file that
 * cannot be unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an
 * outage.
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
            echo "Cleared {$cleared} compiled files; the cart page now ships with no\n";
            echo "footer, and Appearance -> Cart page -> Layout can put it back.\n";
        }
    }

    public function down(): void {}
};
