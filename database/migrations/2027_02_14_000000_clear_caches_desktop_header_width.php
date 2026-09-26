<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the desktop header width.   (Lane H1)
 *
 * NO ROUTE AND NO BLADE CHANGED IN THIS PACKAGE, so the usual reason for one of
 * these does not apply and this migration is here for a different one.
 *
 * TWO SERVICE CLASSES CHANGED — App\Services\HeaderSettings and
 * App\Services\SiteLayout — and one of them changed its CONSTRUCTOR SIGNATURE:
 * HeaderSettings now takes SiteLayout beside SettingsService, because the
 * switch that decides the header's width has to be read where the property is
 * actually written. A PHP process still holding the previous compilation of
 * that file in opcache constructs the old one-argument class against the new
 * caller, and `bootstrap/cache/services.php` is the manifest the container
 * builds from. Both are swept below, and `opcache_reset()` is the line that
 * matters most on a host that has opcache on — which Cloudways does.
 *
 * What changed:
 *
 *   app/Services/HeaderSettings.php   --hd-max is emitted as the SITE WIDTH
 *                                     TOKEN or as the header's own number,
 *                                     decided by the switch. See maxWidthCss().
 *   app/Services/SiteLayout.php       `header_follows` ships ON, and stops
 *                                     emitting a `--hd-max` that could never
 *                                     win.
 *   resources/css/kbb/kbb.css         the header's default width, the three
 *                                     tokens that taper the nav bar, and one
 *                                     deleted media query.
 *   resources/js/kbb/nav-fit.js       comments only — the built bundle is
 *                                     byte-identical and keeps its name,
 *                                     app-CaC60Fnq.js.
 *   public/build/                     the rebuilt stylesheet.
 *
 * ── THE STOREFRONT DOES MOVE, ON DESKTOP, AND IT IS THE THING THE OWNER ─────
 *    ASKED FOR IN AS MANY WORDS
 *
 * "the header need to be matched the width". It was not: Appearance → Site
 * layout → Page width has shipped a switch called "Header follows the site
 * width" since the width package, and it wrote its declaration into `:root`
 * while HeaderSettings wrote the same property into the `style` attribute of
 * the `<header>` element itself, on every request. An inline declaration beats
 * a `:root` one outright, so the switch moved nothing at any width while the
 * screen reported it as on. Measured in Chromium on /shop/ with it saved on:
 * header .wrap 1280 against a page container of 1680 at a 1680px viewport.
 *
 * It is one property with one writer now, and it SHIPS ON. That is a deliberate
 * default change of the same kind as the 1680 in the package before this one,
 * and for the same reason — the owner asked for it in his own words rather than
 * it being a value somebody chose for him. Measured, on /shop/:
 *
 *      viewport   header container   before -> after
 *        1280           1280 -> 1280   (unchanged: the page is the screen here)
 *        1366           1280 -> 1366
 *        1440           1280 -> 1440
 *        1680           1280 -> 1680
 *        1920           1280 -> 1680
 *
 * ONE CLICK PUTS IT BACK. Appearance → Site layout → Page width → "Header
 * follows the site width", off, and the header is 1280px again — or whatever
 * Appearance → Header → Bar → Content width says.
 *
 * Two smaller desktop changes travel with it, both of them "shrink rather than
 * rearrange", which is the rest of the same sentence:
 *
 *   - the nav bar tapers its own padding and gaps against the room it has, in
 *     CSS, so the words keep more of their size on a narrow desktop. At a
 *     1024px viewport the menu is 10.75px type instead of 9.48px; from 1440px
 *     up it renders at exactly the sizes it did before.
 *   - the WhatsApp support block stops disappearing between 901px and 1080px.
 *     It was never dropped for room — at 1024 the header row is one 46px line
 *     with 958px of content in a 980px box.
 *
 * THE PHONE AND THE SMALL TABLET ARE UNTOUCHED, and that is measured rather
 * than asserted: every rendered property of all 24 elements the header is built
 * from, at 320, 390, 414, 600, 768, 820 and 900, on /, /shop/ and /ar/shop/ —
 * 188,055 comparisons, 0 differing rects, 0 differing document widths.
 * docs/H1-DESKTOP-HEADER-WIDTH.md has the tables.
 *
 * NO SETTING ROWS ARE WRITTEN, on purpose and for the same reason as the width
 * package: `layout_header_follows` is absent until somebody saves the screen,
 * and SiteLayout::all() answers the shipped default for an absent key. A shop
 * that has already turned the switch OFF by hand keeps its stored `0` and its
 * 1280px header, which is the correct outcome — this changes what the shop does
 * when it has never been asked, not what it does when it has.
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
            echo "Cleared {$cleared} compiled files; the DESKTOP header is now as wide as\n"
                ."the page (Appearance -> Site layout -> Page width -> \"Header follows the\n"
                ."site width\", which now ships ON), it keeps every item on one row by\n"
                ."shrinking its own padding, and the WhatsApp block no longer vanishes on a\n"
                ."narrow desktop. The phone and small-tablet header are unchanged.\n";
        }
    }

    public function down(): void {}
};
