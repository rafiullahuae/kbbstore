<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seven appearance columns the owner asked for after using the screen.
 *
 * Phase 22 round 7, Lane BP. His words: "give controls for background color
 * pick, no background or image background of the section ... also give control
 * controls for button colors etc. and give option to make title on the banner
 * or below the banner nicely."
 *
 * ── EVERY DEFAULT IS WHAT THE PAGE ALREADY DRAWS ────────────────────────────
 *
 * CLAUDE.md rule 1, and it is the whole shape of this migration: `bg_mode` is
 * `none` (the section has no background today), `title_pos` is `below` (the
 * band is under the picture today), and the four colour columns are the EMPTY
 * STRING rather than a hex.
 *
 * The empty string is load-bearing and is not laziness. The button renders
 * `background:var(--pink,#E8919F)` — the shop's own accent, which is `#E0567B`
 * in kbb.css and can differ per surface. Shipping `#E8919F` as the default
 * would have repainted every existing button with the FALLBACK colour, which is
 * not the colour the shop draws; shipping `#E0567B` would have frozen a token
 * the theme is free to move. '' means "the shop's own", the template emits no
 * custom property at all for it, and applying this package changes no pixel.
 *
 * ── AND THEY ARE COLUMNS, NOT MODULE SETTINGS ───────────────────────────────
 *
 * Same argument app/Services/Banners.php's header makes for the other fifteen:
 * ModuleSchema holds ONE value per key for the whole shop and these are per
 * set. A shop with an autumn set on a cream background and a clearance set on
 * black is the case the owner is describing.
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
             * One of BannerSet::BG_MODES. `none` is today's behaviour: the row
             * sits on whatever the homepage's own section band is, which is
             * what every existing set does and must keep doing.
             */
            if (! Schema::hasColumn('banner_sets', 'bg_mode')) {
                $t->string('bg_mode', 16)->default('none');
            }

            /*
             * A hex, validated with App\Support\Color::isValidHex() on write AND
             * again in the service before it is printed, and stored '' when the
             * mode is not `color`. Never anything else: nothing an operator
             * types reaches the <style> element, which is the rule the row's own
             * header sets out for the four enum columns.
             */
            if (! Schema::hasColumn('banner_sets', 'bg_color')) {
                $t->string('bg_color', 9)->default('');
            }

            /*
             * A stored MEDIA PATH, the same shape `banner_cards.image` holds and
             * written through the same MediaRegistrar allowlist — never a URL.
             * The service percent-encodes it before it becomes a CSS `url()`.
             */
            if (! Schema::hasColumn('banner_sets', 'bg_image')) {
                $t->string('bg_image', 400)->default('');
            }

            /* The button, per SET and not per card: a row whose buttons are
             * four different colours is not what was asked for. */
            if (! Schema::hasColumn('banner_sets', 'btn_bg')) {
                $t->string('btn_bg', 9)->default('');
            }

            if (! Schema::hasColumn('banner_sets', 'btn_text')) {
                $t->string('btn_text', 9)->default('');
            }

            /*
             * The hover colour. '' keeps today's `filter:brightness(.94)`,
             * which is a RELATIVE darkening and therefore right for any accent;
             * a stored hex replaces it with that flat colour instead. The two
             * cannot both apply — the template switches between them on a class
             * — or a custom hover would be darkened on top of itself.
             */
            if (! Schema::hasColumn('banner_sets', 'btn_hover')) {
                $t->string('btn_hover', 9)->default('');
            }

            /* One of BannerSet::TITLE_POSITIONS. `below` is today's band. */
            if (! Schema::hasColumn('banner_sets', 'title_pos')) {
                $t->string('title_pos', 16)->default('below');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('banner_sets')) {
            return;
        }

        Schema::table('banner_sets', function (Blueprint $t) {
            foreach (['bg_mode', 'bg_color', 'bg_image', 'btn_bg', 'btn_text', 'btn_hover', 'title_pos'] as $column) {
                if (Schema::hasColumn('banner_sets', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
