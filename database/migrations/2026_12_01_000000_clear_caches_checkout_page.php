<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the checkout page's width fix and its new
 * Appearance screen.
 *
 * THREE KINDS OF STALENESS, and this package carries all three.
 *
 *   A NEW ROUTE FILE. routes/checkout-page-admin.php is required from
 *   routes/web.php, and the router dispatches against
 *   bootstrap/cache/routes-*.php rather than against the source. Without this,
 *   the package lands complete and Appearance -> Checkout page draws a screen
 *   whose every request answers 404 — which the screen itself says out loud
 *   rather than drawing an empty panel, but which is still a dead screen.
 *
 *   A CHANGED BLADE. resources/views/store/checkout.blade.php and
 *   resources/views/admin/app.blade.php are compiled into
 *   storage/framework/views, and a compiled copy is keyed by PATH, not by
 *   contents. The freshness check is a filemtime compare, and an unzip's
 *   timestamps are not reliably newer than what is already on disk. A stale
 *   compiled checkout is a page that ignores every slider on the new screen.
 *
 *   A REBUILT STYLESHEET. public/build/assets/kbb-checkout-*.css is a NEW
 *   FILE with a new hash, named by a rewritten public/build/manifest.json.
 *   Nothing here has to invalidate it — the name changed — but the compiled
 *   Blade that prints the <link> does, which is the same view cache above.
 *
 * What changed:
 *
 *   resources/css/kbb/kbb-checkout.css
 *                                   `.co-grid`'s tracks are minmax(0,1fr)
 *                                   instead of 1fr, which is the width fix:
 *                                   `1fr` is `minmax(auto,1fr)` and that auto
 *                                   minimum is the track's min-content, so the
 *                                   track was free to outgrow the 390px grid
 *                                   box and did — measured at 651px, with the
 *                                   summary and every section stretched out
 *                                   with it and the page scrolling sideways.
 *                                   Also: fifteen spacing numbers became
 *                                   var()s with those same numbers as their
 *                                   fallbacks.
 *
 *   app/Services/CheckoutPage.php   the schema, and the two halves of the
 *                                   custom-property indirection. Emits nothing
 *                                   at all while every control is at its
 *                                   default.
 *
 *   app/Http/Controllers/Admin/CheckoutPageApiController.php
 *   routes/checkout-page-admin.php
 *   app/Support/AdminCapabilities.php
 *                                   the two endpoints, and
 *                                   `checkoutpage.manage` — owner, manager and
 *                                   editor, its own capability so narrowing it
 *                                   and narrowing cartpage.manage stay
 *                                   separate decisions.
 *
 *   resources/views/admin/partials/checkout-page-screen.blade.php
 *   resources/views/admin/app.blade.php
 *                                   the screen, and the one include that
 *                                   mounts it. It registers its own sidebar
 *                                   row inside Appearance and wraps window.go,
 *                                   so that include is the whole of the change
 *                                   to the console.
 *
 *   resources/views/store/checkout.blade.php
 *                                   the class list and the style attribute on
 *                                   <section class="kbb-checkout">. Both are
 *                                   empty strings until a control moves.
 *
 * NO SETTING ROWS ARE WRITTEN. CheckoutPage::SCHEMA carries every default, an
 * absent row and a row holding the default are the same thing, and seeding
 * would make "never touched" indistinguishable from "set back to the default".
 *
 * NO SCHEMA CHANGE. Fifteen integers and a boolean, in `settings`, which has
 * held keys like these since it existed.
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
            echo "Cleared {$cleared} compiled files; the mobile checkout no longer scrolls\n"
                ."sideways, and Appearance -> Checkout page can set the spacing on the\n"
                ."desktop and mobile layouts separately.\n";
        }
    }

    public function down(): void {}
};
