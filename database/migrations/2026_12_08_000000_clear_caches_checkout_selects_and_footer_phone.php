<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the three selects that were rendering as sliders,
 * the notch to the left of the phone logo, the reviews line's wording, and the
 * footer's own shape on a phone.
 *
 * NO NEW ROUTES. Every new key travels inside an existing /admin-api response.
 * What matters is:
 *
 *   FOUR CHANGED BLADES, compiled into storage/framework/views and keyed by
 *   PATH rather than by contents — the freshness check is a filemtime compare
 *   and an unzip's timestamps are not reliably newer than what is on disk.
 *
 * ── THE DEFECT THIS ROUND EXISTS FOR ──────────────────────────────────────
 *
 * checkout-page-screen.blade.php handled `bool` and then returned an
 * `<input type="range">` for EVERYTHING ELSE. So `ph_tone` and `ph_weight`
 * (shipped 2.60.252) and `m_float` (shipped 2.60.253) each drew as
 *
 *     <input type="range" min="undefined" max="undefined" value="muted">
 *
 * — a slider with no scale showing a value it cannot represent — and the input
 * handler stored `Number(el.value)`, which is NaN. Three controls the screen
 * said existed could not be read and could not be saved. SlimFooter's own
 * header meanwhile said "the same four types the checkout screen already
 * draws"; it drew two. The screen now draws `select` and `text`, and the
 * handler branches on the SCHEMA's type rather than on the DOM element's,
 * which is what made a select indistinguishable from a range to begin with.
 *
 * ── THE NOTCH BESIDE THE LOGO ─────────────────────────────────────────────
 *
 * `.co-head .in` is `max-width:<the width control>; margin:0 auto`, so a band
 * narrower than the window centres itself. Measured at 390px with the mobile
 * header width at 280: the band ran 55…335, the logo started at 75 and the
 * page's own "Back to shop" started at 20, while the badge inside overflowed
 * to 370 — so the right edge looked flush and the left did not. On a phone the
 * band now takes the page's own edges by default and a class restores the
 * centring; on a desktop it is the other way round, so both defaults emit no
 * class.
 *
 * ── DEFAULTS THAT CHANGE THE PAGE, EACH ONE ASKED FOR ─────────────────────
 *
 *   Footer alignment      start  → between   ("Spread to both edges")
 *   Footer on a phone     —      → ruled rows ("06 Ruled rows is final")
 *   Footer content width  1040   → 1240      so `between` fits on one line
 *                                            rather than wrapping; measured,
 *                                            the desktop bar goes 82.3px → 55px
 *   Phone mark            mono   → WhatsApp green
 *   Mobile header band    centred → the page's own edges
 *
 * The cart page is untouched: `cart_on` still ships off, and every rule added
 * here is inside `.kbb-checkout` or gated on a class the footer emits only
 * when its own switch is on.
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
            echo "Cleared {$cleared} compiled files; the checkout screen can draw a select\n"
                ."and a text box, the phone header lines up with the page, the reviews line\n"
                ."has its own wording, and the footer has its own shape on a phone.\n";
        }
    }

    public function down(): void {}
};
