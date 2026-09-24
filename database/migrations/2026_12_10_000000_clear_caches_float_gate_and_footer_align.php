<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the floating bar's readiness gate, the phone
 * header's side padding, and the footer lining up with the page.
 *
 * NO NEW ROUTES. Three new keys travel inside existing /admin-api responses.
 * TWO CHANGED BLADES, compiled into storage/framework/views and keyed by PATH
 * rather than by contents — the freshness check is a filemtime compare and an
 * unzip's timestamps are not reliably newer than what is on disk.
 *
 * ── THE FLOATING BAR ONLY EXISTS WHEN THE ORDER CAN BE PLACED ─────────────
 *
 * "with missing fields, or selection or missing payment selection, the floating
 * place order bar will not show." A bar offering Place order on a form that
 * cannot be placed is a button that exists to be refused, and on a phone it
 * sits over the field that is wrong.
 *
 * It asks `form.querySelector(':invalid')` — the SAME test the button itself
 * uses, so the two cannot disagree about what complete means — plus three
 * things constraint validation cannot see. The address is the reason: it lands
 * in HIDDEN inputs, and a hidden input is barred from constraint validation, so
 * it is never :invalid however empty it is. Driven in Chromium at 390px:
 *
 *   load, empty form, button off screen   hidden
 *   name + phone + email filled           hidden   (no address yet)
 *   address chosen                        SHOWN
 *   scrolled to the in-page button        hidden
 *   email cleared, back at the top        hidden
 *
 * ── THE NOTCH BESIDE THE PHONE LOGO, SECOND CAUSE ────────────────────────
 *
 * Removing the centring was half of it and the report came back unchanged, so
 * this was SWEPT rather than reasoned about: 4 header widths x 3 header
 * paddings x 3 page paddings, asserting the logo's left equals the page's.
 * Before, several combinations misaligned by up to 40px; after, 0 of 36.
 *
 * The second cause was `--cop-headpadx`, a control separate from the page's
 * `--cop-padx` that happens to default to the same 20. Set the header's to 40
 * and the page's to 8 and the logo sits 32px inside every other block, with the
 * badge still reaching the right edge because an auto margin puts it there —
 * which is exactly the asymmetry in the photograph. In "lined up with the page"
 * mode the header now takes the PAGE's padding: two numbers that must agree are
 * one number, and the header's own slider is hidden while it is not read.
 *
 * ── AND THE FOOTER ───────────────────────────────────────────────────────
 *
 * The bar was 1240 wide and the checkout page is 1040, so its first word
 * started 100px left of everything above it. `width_mode` defaults to `page`
 * and takes `--cop-d-max`, which is INHERITED rather than copied — the checkout
 * emits it on `.kbb-checkout` and the bar renders inside it — so moving the
 * page width moves the footer on the same render. Measured at 1440: `.sf-brand`
 * left 220, the page's content left 220.
 *
 * The two policy links move under the wordmark. Done in the MARKUP, and the
 * failed attempt is recorded in the partial: flexbox cannot put one sibling
 * inside another's column, and `order` plus flex-basis:100% drops them onto a
 * third row rather than the brand's second. Moving the block moves the tab
 * order with the painting, which is the right way round.
 *
 * THREE DEFAULTS CHANGE THE PAGE, ALL THREE ASKED FOR: the floating bar is
 * withheld until the order is placeable; the footer lines up with the page; the
 * links sit under the wordmark. The cart page is untouched — `cart_on` still
 * ships off, and there `--cop-d-max` is absent so the footer falls back to the
 * same 1040.
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
            echo "Cleared {$cleared} compiled files; the floating Place order bar waits for a\n"
                ."placeable order, the phone header takes the page's own side padding, and the\n"
                ."footer lines up with the page with its links under the wordmark.\n";
        }
    }

    public function down(): void {}
};
