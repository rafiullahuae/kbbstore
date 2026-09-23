<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the band above the checkout header, the
 * back-to-cart size controls, and the floating Place order bar.
 *
 * NO NEW ROUTES. The twelve new keys travel inside the existing
 * /admin-api/checkout-page response, and the route table is cleared here only
 * because it costs nothing. What matters is:
 *
 *   ONE CHANGED BLADE, compiled into storage/framework/views and keyed by PATH
 *   rather than by contents — the freshness check is a filemtime compare, and
 *   an unzip's timestamps are not reliably newer than what is on disk. A stale
 *   copy here is a checkout that never renders the floating bar, on a shop
 *   whose Appearance screen says it should.
 *
 * What changed:
 *
 *   resources/css/kbb/kbb-checkout.css
 *   public/build/assets/kbb-checkout-*.css
 *                                   THE BAND ABOVE THE HEADER. kbb.css carries
 *                                   a bare `section{padding:52px 0}` and
 *                                   .kbb-checkout IS a <section>, so every
 *                                   checkout inherited 52px of page background
 *                                   above the secure-checkout bar and 52px of
 *                                   nothing below the last block. Measured in
 *                                   Chromium, .co-head's own top read 52 at
 *                                   1280 AND at 390. The section now declares
 *                                   its own padding, which a class wins over an
 *                                   element selector without !important.
 *
 *                                   "Go back to cart" is sized from two shares
 *                                   rather than five sets of numbers, so each
 *                                   of its five looks scales the padding pair
 *                                   it was drawn with.
 *
 *   app/Services/CheckoutPage.php
 *   resources/views/store/checkout.blade.php
 *                                   Twelve new controls, and one resolved mode
 *                                   for the phone-only Place order bar. The bar
 *                                   waits for the in-page button to leave the
 *                                   viewport and goes the moment it returns,
 *                                   answered by IntersectionObserver rather
 *                                   than by a scroll handler reading offsets —
 *                                   the button MOVES as an address is chosen or
 *                                   a coupon applied, so a remembered position
 *                                   is stale immediately.
 *
 * TWO DEFAULTS DO CHANGE THE PAGE, BOTH BECAUSE THE OWNER ASKED:
 *
 *   1. "Space above the header" and "Space below the page" ship at 0, so
 *      applying this package REMOVES the 52px band rather than waiting for a
 *      slider. Setting either to 52 puts it back exactly.
 *
 *   2. The floating Place order bar ships at "only once the in-page button
 *      scrolls away", so a phone checkout that drew no bar now draws one.
 *      Appearance → Checkout page → Mobile · Layout → "Floating Place order
 *      button" → Never turns it off. `mobile_sticky_bar` on Store → Ecommerce
 *      still gives an always-there bar to a shop that already chose one; it
 *      cannot override a choice made on the new control.
 *
 * Every other new control ships at the value the page already had.
 *
 * NO SETTING ROWS ARE WRITTEN, AND NO SCHEMA CHANGE.
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
            echo "Cleared {$cleared} compiled files; the 52px band above the checkout\n"
                ."header is gone, \"Go back to cart\" has size controls, and the phone's\n"
                ."Place order bar appears only once the in-page button scrolls away.\n";
        }
    }

    public function down(): void {}
};
