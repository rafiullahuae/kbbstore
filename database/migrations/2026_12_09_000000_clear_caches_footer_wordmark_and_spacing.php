<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the footer wearing the header's own wordmark.
 *
 * NO NEW ROUTES. One new key travels inside the existing /admin-api/slim-footer
 * response. What matters is ONE CHANGED BLADE, compiled into
 * storage/framework/views and keyed by PATH rather than by contents — the
 * freshness check is a filemtime compare and an unzip's timestamps are not
 * reliably newer than what is on disk.
 *
 * ── WHAT CHANGED, AND WHY IT IS NOT A SECOND LOGO SETTING ─────────────────
 *
 * The bar drew `brand`, a flat text box shipping "K-BEAUTY BLISS" in capitals.
 * The owner asked twice for the header's own logo instead. A second colour box
 * and a second wordmark box here would have answered the letter of that and
 * missed the point: two copies drift, and the one that drifts is whichever
 * nobody looks at.
 *
 * So `brand_style` defaults to `wordmark` and the bar READS Appearance →
 * Header's own Wordmark, Accent word and two colours, live, on every render.
 * Renaming the shop stays one edit in one place. `text` is the old flat box,
 * for a shop that wants a different footer wordmark.
 *
 * THE TWO COLOURS ARE COMPARED AGAINST THE HEADER'S DEFAULTS, NOT THIS
 * SCREEN'S, so while the header still says what it shipped saying nothing is
 * emitted at all and the stylesheet's own fallbacks are those same two
 * colours — a shop that has touched neither screen still renders a footer with
 * no style attribute. They are safe inside a declaration because
 * HeaderSettings::cast() answers a `colour` row with a six-digit hex or the
 * shipped default, and the footer reads through that class rather than off the
 * settings table so there is one validation and not two to fall out of step.
 *
 * A <span> AND NOT AN <i> for the accent half, which is also what the header
 * writes: the byline is an <i> in the same block and `.sf-bar .sf-brand i`
 * gives it a 6px inline-start margin — which on the accent half would have
 * opened a gap in the middle of the logo.
 *
 * ONE DEFAULT CHANGES THE PAGE, and it is the one that was asked for: the
 * checkout footer now draws "K-Beauty" in the header's ink and "Bliss" in the
 * header's accent, mixed case, rather than flat capitals. The capitals switch
 * is left alone and deliberately does not apply to the wordmark — the header's
 * logo is mixed case by design and "K-BEAUTYBLISS" is not the same logo.
 *
 * ── AND THE SPACING CONTROLS THAT WERE MISSING ───────────────────────────
 *
 * `space_above` is a MARGIN and not padding: padding would be inside the bar
 * and would carry the tone with it, so a white bar on a cream page would grow
 * a white stripe above itself rather than a gap. It is written after the
 * `margin:0` that resets kbb.css's bare `footer{padding:52px 0 26px}` rather
 * than folded into it, so the next reader can tell which half was the landmine
 * and which is the control.
 *
 * The ruled rows had NO padding control at all: the shape derived it from the
 * block gap as `calc(var(--sf-gap) * .5)`, so the only way to open the rows was
 * to open every gap in the bar at the same time. `row_pad` defaults to exactly
 * what that expression produced at the shipped gap (18 * .5 = 9, and 7 on a
 * phone), and `row_h` is a floor that applies to EVERY row including the first
 * -- which has no top border and so never matched the `* + *` rule the padding
 * lives on. Measured before and after: the bar is 55px on a desktop and 187.8px
 * on a phone either way.
 *
 * "Squeeze the bar" and "Back to defaults" join the screen, and the key list
 * travels in the /admin-api/slim-footer response rather than being written out
 * again in the screen's JavaScript. Both presets WRITE THE SLIDERS and store
 * nothing until Save, so Reload undoes either -- a stored "squeezed" mode would
 * leave every slider showing a number the bar was not using.
 *
 * The cart page is untouched: `cart_on` still ships off.
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
            echo "Cleared {$cleared} compiled files; the checkout footer now wears the site\n"
                ."header's own wordmark and colours, read live from Appearance -> Header.\n";
        }
    }

    public function down(): void {}
};
