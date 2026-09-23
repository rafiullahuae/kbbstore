<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the slim footer and this round's summary controls.
 *
 * A NEW ROUTE FILE. routes/slim-footer-admin.php is required from
 * routes/web.php, and the router dispatches against bootstrap/cache/routes-*.php
 * rather than against the source. Without this, Appearance → Footer draws a
 * screen whose every request answers 404 — which the screen says out loud
 * rather than drawing an empty panel, but which is still a dead screen.
 *
 * FIVE CHANGED BLADES, compiled into storage/framework/views and keyed by PATH
 * rather than by contents: the freshness check is a filemtime compare, and an
 * unzip's timestamps are not reliably newer than what is already on disk. A
 * stale copy here is a checkout with no footer on it at all.
 *
 * A REBUILT STYLESHEET — a new file with a new hash named by a rewritten
 * manifest. The bundle needs no invalidating; the compiled Blade that prints
 * its <link> does, which is that same view cache.
 *
 * What changed:
 *
 *   app/Services/SlimFooter.php
 *   resources/views/partials/slim-footer.blade.php
 *   app/Http/Controllers/Admin/SlimFooterApiController.php
 *   routes/slim-footer-admin.php
 *   app/Support/AdminCapabilities.php
 *   resources/views/admin/partials/slim-footer-screen.blade.php
 *                                   the bar: three shapes, three tones, every
 *                                   word a setting, and a switch per page.
 *                                   `co_on` ships ON and `cart_on` ships OFF,
 *                                   so the CART PAGE IS UNCHANGED by this
 *                                   package and the checkout gains the bar.
 *
 *                                   It is not the site footer. The checkout
 *                                   declares `bare` and the cart declares
 *                                   `no-footer`; partials/footer.blade.php has
 *                                   never rendered on either and still does
 *                                   not.
 *
 *   resources/css/kbb/kbb-checkout.css
 *                                   `.co-items` takes a top padding, because
 *                                   the quantity badge is pinned 7px above its
 *                                   thumbnail and on the first line reached
 *                                   above the list, where a phone's
 *                                   overflow:hidden cut it -- the reported
 *                                   "first is cuting from top side". The
 *                                   remove button and the two summary tabs
 *                                   take sizes of their own. And the phone's
 *                                   16px field floor became a switch: it was a
 *                                   hard clamp under a slider whose minimum
 *                                   was already 100%, so the control could not
 *                                   move at all.
 *
 *   app/Services/CheckoutPage.php   twelve more settings per pair, plus that
 *                                   switch, all on the tabs they belong to.
 *
 * ONE DEFAULT CHANGES THE PAGE: `items_pt` ships at 8px, which is the fix for
 * the clipped badge. 0 restores exactly what shipped.
 *
 * NO SETTING ROWS ARE WRITTEN. Both services carry every default, and an
 * absent row and a row holding the default are the same thing.
 *
 * NO SCHEMA CHANGE.
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
            echo "Cleared {$cleared} compiled files; the checkout has a slim footer under\n"
                ."Appearance -> Footer, the summary's first line is no longer clipped, and its\n"
                ."remove button and tab strip have sizes of their own.\n";
        }
    }

    public function down(): void {}
};
