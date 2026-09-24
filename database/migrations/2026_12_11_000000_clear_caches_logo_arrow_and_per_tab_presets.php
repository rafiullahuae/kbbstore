<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the third cause of the notch beside the logo, the
 * back-to-top arrow, and the presets that reached past the screen.
 *
 * NO NEW ROUTES AND NO NEW SETTINGS. Three changed blades and one stylesheet.
 * The blades are compiled into storage/framework/views and keyed by PATH rather
 * than by contents — the freshness check is a filemtime compare, and an unzip's
 * timestamps are not reliably newer than what is on disk.
 *
 * ── THE NOTCH, THIRD AND LAST CAUSE ──────────────────────────────────────
 *
 * The owner found this one himself, in DevTools. `kbb.css:1612` carries a BARE
 * `.logo` rule inside its own `@media(max-width:900px)`:
 *
 *     .logo{font-size:18px;flex:1;text-align:center}
 *
 * It is the SITE header's mobile layout — the wordmark centring itself between
 * the burger and the cart icons — and the checkout's logo carries the same
 * class. The font-size was already beaten by --cop-headlogo; flex and
 * text-align nobody had touched.
 *
 * AND IT IS WHY TWO ROUNDS OF MEASURING SAID "FIXED". `flex:1` stretches the
 * ELEMENT; `text-align:center` moves the GLYPHS inside it. So the box's left
 * edge stays correct and only the letters move. Measured at 390px before: the
 * .logo box at left 20, the same 20 as the page's own content, and the first
 * letter at 47.2. A getBoundingClientRect() — which is what the 36-combination
 * sweep read — reports 20 and calls it aligned. A Range over the contents
 * reports 47.2 and does not. After: glyphs at 20 at 390px and at 140 at 1280px,
 * both equal to the page's own left edge.
 *
 * ── THE BACK-TO-TOP ARROW ────────────────────────────────────────────────
 *
 * Off centre, and that was a regression shipped in 2.60.255: the `rows` shape
 * gave every direct child of `.sf-in` a min-height and `display:flex`, plus a
 * top border and row padding to every child after the first — and `.sf-top` is
 * a direct child. That overrode the button's own `place-items:center` and put
 * 7px above it. Measured at 390 before: button centre x 355 y 2312.6, glyph
 * centre x 348.5 y 2316.1. After: the two are the same point. The arrow is a
 * control at the end of the bar, not a row of content, and never wanted either
 * rule.
 *
 * It now also waits until there is something to go back to: a 1px sentinel at
 * the top of the document, watched by an IntersectionObserver rather than a
 * scroll handler reading scrollY on every frame. Its RESTING state is hidden
 * and the class is what shows it, so a page whose script never runs draws no
 * arrow rather than a dead one — and its width and border collapse with it, or
 * an invisible 30px box would still push the rest of a one-line bar along.
 *
 * ── THE PRESETS NOW BELONG TO THE TAB YOU ARE LOOKING AT ─────────────────
 *
 * The owner's report, exactly: "when i click squeezed, it applies on all tabs
 * all checkout page settings, which is not correct." He is right. A preset that
 * reaches past the screen changes numbers nobody can see, so the only way to
 * learn what it did is to visit nine tabs. Both presets, on the checkout screen
 * and on the footer screen, are now scoped to the open tab, and both buttons say
 * so. Driven in Chromium: on Mobile · Product rows, Squeeze took m_row_gap
 * 11 → 2 and m_tab_pad 9 → 2 while d_pad_x stayed 20 and d_max stayed 1040.
 *
 * NO DEFAULT CHANGES THE PAGE except the arrow, which is now absent at the top
 * of the page where it previously sat doing nothing. The cart page is untouched.
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
            echo "Cleared {$cleared} compiled files; the phone checkout logo sits on the page's\n"
                ."own left edge, the back-to-top arrow is centred and waits until you have\n"
                ."scrolled, and Squeeze now moves only the tab you are looking at.\n";
        }
    }

    public function down(): void {}
};
