<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SECOND KIND OF BANNER SET: pictures only, with arrows and thin bars.
 *
 * Phase 22 round 8, Lane BN2. The owner: "I want another banner type with only
 * images slider with proper beautiful left right arrows. and thin bars the
 * bottom of the banner to control all sliders."
 *
 * ── FOUR COLUMNS, AND `kind` IS THE ONE THAT MATTERS ────────────────────────
 *
 * `kind` ships at `cards`, which is what EVERY ROW IN THIS TABLE ALREADY IS —
 * there is no other kind today, so every existing set keeps drawing through
 * `resources/views/partials/home/cards-banner.blade.php` and applying this
 * package moves no pixel on the shop. CLAUDE.md rule 1, and it is checked
 * rather than asserted: SliderBannerTest renders a set at the shipped values
 * and compares it byte for byte against the same set rendered with these
 * columns dropped.
 *
 * The other three are read ONLY when `kind = 'slider'`, and no row is a slider
 * until somebody makes one. Their defaults are therefore free — there is no
 * "what the page already draws" for a type that has never drawn anything — and
 * they are chosen to be the sane first draw of a new slider rather than to
 * match anything: a 16:9 frame on a desktop, 4:3 on a phone, the first of the
 * four treatments.
 *
 * ── WHY THE SLIDER GETS ITS OWN TWO RATIO COLUMNS ───────────────────────────
 *
 * `banner_sets.ratio` exists and holds the CARD shape, whose default is `3/4`
 * — a tall portrait card, which is right for a row of six cards and absurd for
 * a full-width banner. Reusing it would have meant either changing that default
 * (rule 1, on a control the owner has already tuned) or shipping every new
 * slider as a portrait sliver. Two columns of its own also buy the thing a
 * single ratio cannot: a banner that is 16:9 on a desktop and 4:3 on a phone,
 * which is what a wide hero has to do to stay legible on a 390px screen.
 *
 * ── AND `speed_ms`, `show_arrows`, `show_dots`, `card_radius`, `shadow`,
 *    `ratio`… ARE NOT DUPLICATED ─────────────────────────────────────────────
 *
 * Everything the two kinds genuinely share reads the SAME column: the autoplay
 * switch, the dwell, the corner radius, the shadow, the section background, the
 * published flag, the order. A second set of columns meaning the same thing is
 * how the two halves of a screen come to disagree about what "on" means.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('banner_sets')) {
            return;
        }

        Schema::table('banner_sets', function (Blueprint $t) {
            /*
             * One of BannerSet::KINDS. `cards` is every row that exists, and
             * BannerSet::kind() returns `cards` for anything it does not
             * recognise — so a row edited straight in the database to a kind
             * nobody issued draws the section it has always drawn rather than
             * an empty box.
             */
            if (! Schema::hasColumn('banner_sets', 'kind')) {
                $t->string('kind', 16)->default('cards');
            }

            /* One of BannerSet::SLIDER_STYLES — where the arrows sit and how
               the bars read. Four treatments, all of them the same markup. */
            if (! Schema::hasColumn('banner_sets', 'slider_style')) {
                $t->string('slider_style', 16)->default('inset');
            }

            /* One of BannerSet::SLIDER_RATIOS, from 1024px up. */
            if (! Schema::hasColumn('banner_sets', 'slider_ratio')) {
                $t->string('slider_ratio', 16)->default('16/9');
            }

            /* The same enum, below 768px. A 21:9 hero is 44px tall on a phone;
               this is the column that stops that being the only answer. */
            if (! Schema::hasColumn('banner_sets', 'slider_ratio_m')) {
                $t->string('slider_ratio_m', 16)->default('4/3');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('banner_sets')) {
            return;
        }

        Schema::table('banner_sets', function (Blueprint $t) {
            foreach (['kind', 'slider_style', 'slider_ratio', 'slider_ratio_m'] as $column) {
                if (Schema::hasColumn('banner_sets', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
