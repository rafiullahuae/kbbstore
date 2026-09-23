<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the checkout's header, text sizes, address cue and
 * authenticity tick.
 *
 * NO NEW ROUTES. /admin-api/checkout-page shipped in 2.60.247 and the new
 * fields travel inside its existing response. The route table is cleared here
 * because it costs nothing; the two that matter are:
 *
 *   FOUR CHANGED BLADES. store/checkout.blade.php,
 *   partials/checkout-address.blade.php, partials/checkout/reassurance and
 *   partials/address-sheet are compiled into storage/framework/views, and a
 *   compiled copy is keyed by PATH, not by contents — the freshness check is a
 *   filemtime compare and an unzip's timestamps are not reliably newer than
 *   what is on disk. A stale copy here is the address cue never appearing, the
 *   contact fields still in the old order, and the tick with no classes for the
 *   stylesheet to animate.
 *
 *   A REBUILT STYLESHEET. public/build/assets/kbb-checkout-*.css is a new file
 *   with a new hash named by a rewritten manifest. The bundle needs no
 *   invalidating — the name changed — but the compiled Blade printing the
 *   <link> does, which is that same view cache.
 *
 * What changed, and what each one was asked for:
 *
 *   partials/checkout-address.blade.php
 *                                   the cue on the "choose your delivery
 *                                   address" row: the overlapped home and
 *                                   office marks, an arrow travelling towards
 *                                   the button, and a halo pulsing out of it.
 *                                   CSS only — no script runs and nothing is
 *                                   measured — and all of it inside a
 *                                   prefers-reduced-motion guard. It is on the
 *                                   EMPTY row alone; the chosen row has never
 *                                   had it and cannot get it.
 *
 *   partials/address-sheet.blade.php
 *                                   one line: the button is MOVED into the
 *                                   chosen row rather than rebuilt, so it
 *                                   arrives still carrying the halo class.
 *                                   Removed on the way, or the cue would go on
 *                                   demanding attention for a job already done.
 *
 *   store/checkout.blade.php        Contact is now name, then PHONE and email
 *                                   side by side. A move and not a rewrite:
 *                                   both fields keep their names, validation
 *                                   priorities and autocomplete tokens.
 *
 *   partials/checkout/reassurance.blade.php
 *                                   two class names on the shield's paths, so
 *                                   the stylesheet can draw the tick.
 *
 *   App\Services\Translation\InterfaceStrings
 *                                   the order-updates opt-in now reads "Send
 *                                   me order updates and new offers — on
 *                                   WhatsApp and by email." The box writes the
 *                                   customer's and the order's `whatsapp_optin`
 *                                   column, which is why the sentence names
 *                                   both channels rather than email alone —
 *                                   the note on the key has the argument.
 *
 *   resources/css/kbb/kbb-checkout.css
 *                                   twenty-two more tokens, each with the
 *                                   number that rule already had as its
 *                                   fallback: the header's padding, width,
 *                                   logo and badge; six type factors; the two
 *                                   animation timings. The scroll offset that
 *                                   stops a focused field hiding under the
 *                                   sticky bar is now worked out from the
 *                                   header's own numbers instead of the 96px it
 *                                   was written with. The phone's 16px field
 *                                   floor became a max(), so the size slider
 *                                   can raise it and cannot lower it past the
 *                                   point where iOS zooms the page and will not
 *                                   zoom back.
 *
 *   app/Services/CheckoutPage.php   thirty-one more settings across five new
 *                                   tabs, and two more emitters: one turning a
 *                                   SPEED into the DURATION the stylesheet
 *                                   needs, one turning each switch into an OFF
 *                                   class so that everything-on puts no class
 *                                   on the page at all.
 *
 * NOTHING IN THE CART PAGE IS TOUCHED. No cart file is in this package.
 *
 * NO SETTING ROWS ARE WRITTEN. CheckoutPage::SCHEMA carries every default, an
 * absent row and a row holding the default are the same thing, and seeding
 * would make "never touched" indistinguishable from "set back to the default".
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
            echo "Cleared {$cleared} compiled files; the address row now points at its own\n"
                ."button, the contact fields read name, phone, email, the authenticity tick\n"
                ."draws itself, and the header and every text size have controls.\n";
        }
    }

    public function down(): void {}
};
