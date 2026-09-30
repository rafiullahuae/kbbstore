<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE HOMEPAGE'S PICTURE BANNER LOSES ITS CORNERS AND ITS SHADOW.  (Lane BG)
 *
 * The owner, on the homepage, in full — this migration is the third clause:
 *
 *   "on homepage i want to remove the sections backgrounds by default, and if
 *    i need it for any section, i can put it myself., rest, any section i can
 *    make full width upto 1920x, give this option and it must be auto adjusted
 *    to the screen sizes below 1920px width, and by default, make the main
 *    images banner full width, and remove the corner radius etc."
 *
 * and, when he was asked whether things like this should be switches to find:
 *
 *   "whatever i said, keep applying on the site, don't let me know that change
 *    from the backend, i have the options on backend, i want to apply such
 *    things directly to the site to save time."
 *
 * ── WHY A MIGRATION AND NOT ONLY A CHANGED DEFAULT ──────────────────────────
 *
 * The same reason `banner_ships_as_image_slider` gives, which this file is
 * modelled on: `banner_sets.card_radius` has a DATABASE default of 18 and
 * `shadow` of `soft`, so every row on the shop carries those values in the
 * column and the model's `$attributes` is never consulted for them. A changed
 * default alone would have moved nothing at all and would have looked applied.
 * Three places together leave no row holding the old shape —
 * BannerSet::$attributes (rows made from here on), BannerSet::shadowCss()'s
 * fallback (a row holding a token the enum does not carry) and this (the rows
 * already there).
 *
 * ── THE TWO WRITES ──────────────────────────────────────────────────────────
 *
 *   1. banner_sets.card_radius  18     -> 0       "remove the corner radius"
 *   2. banner_sets.shadow       'soft' -> 'none'  the "etc." — a drop shadow
 *                                                 is the second thing that
 *                                                 stops a picture reading as
 *                                                 part of the page rather than
 *                                                 a card sitting on it
 *
 * WHAT IS NOT HERE, because it needs no row written: the banner's WIDTH. That
 * is `homepage_sections.cards_banner.width`, which ships as `bleed` from
 * HomepageSections::WIDTH_DEFAULTS — and a section setting falls back to its
 * default whenever the saved payload has no such key, which is every payload
 * ever written, because the key did not exist until this release.
 * HomepageSections::castRow() reads `$row[$name] ?? $field['default']`, so the
 * banner is full-bleed on a shop that has saved a homepage arrangement and on
 * one that has not, with nothing written either way.
 *
 * ── SCOPED TWICE, AND BOTH SCOPES MATTER ────────────────────────────────────
 *
 * TO THE OLD DEFAULT: an owner who has already chosen 24px, or `lift`, chose
 * it, and overwriting that would be this migration deciding it knows better
 * than the screen he typed into. Only the rows holding the shipped values are
 * moved, which on this shop is all of them.
 *
 * TO `kind = 'slider'`: both columns are shared with the `cards` banner, and
 * he asked about "the main images banner". A cards set is a ROW OF CARDS and a
 * card with no radius and no shadow is a different thing from a banner with
 * none — so a shop that still has one keeps it exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = [];

        $moved['card_radius: 18 -> 0'] = DB::table('banner_sets')
            ->where('kind', 'slider')->where('card_radius', 18)
            ->update(['card_radius' => 0]);

        $moved["shadow: 'soft' -> 'none'"] = DB::table('banner_sets')
            ->where('kind', 'slider')->where('shadow', 'soft')
            ->update(['shadow' => 'none']);

        if (app()->runningInConsole()) {
            echo "The homepage picture banner now runs edge to edge, with no corner radius\n"
                ."and no shadow. Moved:\n";

            foreach ($moved as $what => $rows) {
                echo sprintf("  %-28s %d\n", $what, $rows);
            }

            echo "Put either back at Appearance -> Banners -> (a set) -> Card radius / Shadow.\n"
                ."The width is Appearance -> Homepage -> Banners -> Section width.\n";
        }
    }

    /**
     * Back to 18px and the soft shadow.
     *
     * NOT A PERFECT INVERSE, AND IT SAYS SO — the same lossiness
     * `banner_ships_as_image_slider` records for the same reason: a slider the
     * owner had already set to 0 and `none` by hand before this ran is
     * indistinguishable afterwards from one this moved, so down() gives it the
     * shipped values rather than the ones it had. Recording the old value would
     * mean two columns added to make a rollback tidier than the forward path.
     */
    public function down(): void
    {
        DB::table('banner_sets')->where('kind', 'slider')->where('card_radius', 0)
            ->update(['card_radius' => 18]);

        DB::table('banner_sets')->where('kind', 'slider')->where('shadow', 'none')
            ->update(['shadow' => 'soft']);
    }
};
