<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cards banner — a named set of cards, and the controls that belong to IT.
 *
 * Phase 22, Lane BN. The owner: "we can turn on off card banners, and inside
 * each banner section we can create multiple cards and the whole section will
 * have full control options to choose which banner will show on homepage, how
 * many cards, scroll speed, animation etc."
 *
 * ── WHY THE CONTROLS ARE COLUMNS HERE AND NOT KEYS IN `module_settings` ─────
 *
 * Because they are PER SET, and the module framework stores one value per key
 * for the whole shop. `ModuleSchema`'s SCHEMA is a flat `key => field` map read
 * through `SettingsService::moduleSetting()`, which is a single snapshot of one
 * table — there is no room in it for "speed, but only for the Ramadan set".
 * Putting them there would mean either one speed for every set the owner ever
 * builds, or a key per set invented at save time, which is a schema nothing can
 * validate and exactly the shape rule 5 forbids.
 *
 * So the module framework keeps the TWO things that really are shop-wide — the
 * section's on/off (`module_toggles`, key `cards_banner`) and which set the
 * homepage draws (`module_settings`, key `set`) — and everything the owner
 * called "inside each banner section" is a column on the set it belongs to.
 *
 * ── EVERY DEFAULT IS THE SHOP AS IT ALREADY LOOKS ───────────────────────────
 *
 * Rule 1. A freshly created set is a row of cards drawn with the shop's own
 * radius, the shop's own gap and no furniture: `show_arrows` and `show_dots`
 * are FALSE, because the owner asked for them as options and an option that
 * ships on is a default nobody chose. `pause_on_hover` is TRUE because a rail
 * that will not stop is the accessibility complaint this feature would
 * otherwise ship with.
 *
 * NOTHING HERE MOVES THE SHOP. The table is created empty and the module is
 * off, so the homepage renders the same bytes it rendered before this ran —
 * CardsBannerShipsOffTest asserts exactly that with a published set in the
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('banner_sets')) {
            return;
        }

        Schema::create('banner_sets', function (Blueprint $t) {
            $t->id();

            $t->string('name', 190);

            /*
             * The handle the owner can point a shortcode or a URL at later.
             * UNIQUE, because a duplicate slug is a set that can be addressed
             * and cannot be told apart; the controller suffixes rather than
             * failing, so renaming two sets to the same words is not an error
             * the owner has to solve.
             */
            $t->string('slug', 190)->unique();

            /*
             * `draft` is a set the owner is still building. The homepage will
             * not draw one — Banners::forHome() filters on it — so a
             * half-finished set cannot reach the shop by being selected early.
             */
            $t->string('status', 16)->default('publish');

            $t->unsignedInteger('position')->default(0);

            /* ── the controls the owner asked to live "inside each section" ── */

            $t->boolean('autoplay')->default(true);

            /*
             * MILLISECONDS PER CARD, not per loop, and that is the whole reason
             * this column reads the way it does.
             *
             * The animation is one `translateX(-50%)` over a doubled track, so
             * the CSS duration is the time to travel the WHOLE list. Stored as
             * a loop time, a set with four cards and a set with thirty would
             * scroll at wildly different speeds under the same number, and the
             * owner would have to re-tune the speed every time he added a card.
             * Stored per card, `Banners::cssVariables()` multiplies by the card
             * count and the visual speed is the same in both.
             */
            $t->unsignedInteger('speed_ms')->default(4000);

            /* One of BannerSet::ANIMATIONS. Validated on write, never trusted. */
            $t->string('animation', 24)->default('slide');

            /* Full cards visible at the widest breakpoint. */
            $t->unsignedTinyInteger('per_view')->default(4);

            /*
             * How much of the NEXT card shows, in percent. An integer because
             * the owner moves it with a slider and 38 is a number a person can
             * read; the template divides by 100 and writes the decimal into the
             * custom property the track's calc() reads.
             */
            $t->unsignedTinyInteger('peek')->default(38);

            $t->unsignedTinyInteger('gap')->default(16);
            $t->unsignedTinyInteger('card_radius')->default(18);

            $t->boolean('show_arrows')->default(false);
            $t->boolean('show_dots')->default(false);
            $t->boolean('pause_on_hover')->default(true);

            /*
             * One of BannerSet::RATIOS, and the reason every card in a row is
             * the same size. The owner: "need same sizes of the cards". The
             * card's height comes from this and from nothing else — not from
             * how much text is in it, not from the shape of the picture.
             */
            $t->string('ratio', 16)->default('3/4');

            /* "with full control to turn on off bottom text etc." */
            $t->boolean('show_text')->default(true);
            $t->boolean('show_button')->default(true);

            /* One of BannerSet::SHADOWS. */
            $t->string('shadow', 16)->default('soft');

            $t->timestamps();

            $t->index(['status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_sets');
    }
};
