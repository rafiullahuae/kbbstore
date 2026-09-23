<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the checkout's order-summary row controls.
 *
 * NO NEW ROUTES THIS TIME. /admin-api/checkout-page already exists — it
 * shipped in 2.60.247 — and the six new fields per surface travel inside its
 * existing response. So the route table is cleared here only because it costs
 * nothing, and the reason this migration exists at all is the other two:
 *
 *   A CHANGED BLADE. resources/views/admin/partials/checkout-page-screen
 *   .blade.php is compiled into storage/framework/views, and a compiled copy
 *   is keyed by PATH, not by contents — the freshness check is a filemtime
 *   compare, and an unzip's timestamps are not reliably newer than what is
 *   already on disk. A stale copy is an Appearance screen still drawing two
 *   tabs while the endpoint answers with four, which looks like the package
 *   never landed.
 *
 *   A REBUILT STYLESHEET. public/build/assets/kbb-checkout-*.css is a new file
 *   with a new hash, named by a rewritten public/build/manifest.json. The
 *   bundle does not need invalidating — the name changed — but the compiled
 *   Blade that prints the <link> does, and that is the same view cache.
 *
 * What changed:
 *
 *   resources/css/kbb/kbb-checkout.css
 *                                   `.ci`, `.cth`, `.cinfo .n`, `.qty` and
 *                                   `.cprice` take seven new tokens, each with
 *                                   the number that rule already had as its
 *                                   var() fallback. The mobile block's
 *                                   `.qty .co-q` override takes the stepper
 *                                   multiplier too — it outranks the base rule
 *                                   below 900px, so without that the Mobile
 *                                   tab's stepper slider would save and move
 *                                   nothing on a phone.
 *
 *   app/Services/CheckoutPage.php   twelve new settings, six per surface, and
 *                                   two more emitters: one for the unitless
 *                                   factors the stylesheet multiplies by, one
 *                                   turning a single bold switch into the name
 *                                   weight and the price weight.
 *
 *   resources/views/admin/partials/checkout-page-screen.blade.php
 *                                   four tabs instead of two, and a preview
 *                                   that now draws three summary lines so the
 *                                   six sliders have something to move.
 *
 * NOTHING IN THE CART PAGE IS TOUCHED. These are `checkoutpage_*` settings and
 * every rule reading them is scoped under `.kbb-checkout`.
 *
 * NO SETTING ROWS ARE WRITTEN. CheckoutPage::SCHEMA carries every default, an
 * absent row and a row holding the default are the same thing, and seeding
 * would make "never touched" indistinguishable from "set back to the default".
 *
 * NO SCHEMA CHANGE. Ten integers and two booleans, in `settings`.
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
            echo "Cleared {$cleared} compiled files; the checkout's order-summary rows now\n"
                ."have their own controls under Appearance -> Checkout page, separately for\n"
                ."desktop and for mobile.\n";
        }
    }

    public function down(): void {}
};
