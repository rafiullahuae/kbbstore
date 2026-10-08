<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE WORDS ON A SLIDER PICTURE -- Lane HB.
 *
 * The owner: "each slide/image will have a beautiful overlayed text box with a
 * button", then, choosing styles A and D from the previews: "give full control
 * of hide show any element ... the button i need small ... give control to
 * reduce the button size by drag, across all banners together".
 *
 * banner_cards already has `heading`, `body`, `button_label` and `button_url`
 * (the cards banner's), so they are reused for the box. What is added:
 *
 *   box_on            the per-picture "show words on this picture" switch.
 *                     FALSE ON EVERY ROW THIS MIGRATION FINDS, and that is the
 *                     point of it: a set converted from the cards banner kept
 *                     its cards' headings in those columns (the slider never
 *                     drew them -- banners-screen.blade.php hides the inputs),
 *                     so drawing the box wherever a heading exists would put
 *                     old words on the live homepage the moment this applied.
 *   eyebrow           the small line above the heading
 *   sticker           the round sticker's centre word   (style D)
 *   sticker_ring      the words round its edge           (style D)
 *   box_pos           start | end -- logical, so Arabic mirrors it
 *   *_ar              the Arabic of every text field. An empty one falls back
 *                     to the English, which is what every banner field does on
 *                     /ar today (none of them has an Arabic column) and what
 *                     HasTranslations does for catalogue content.
 *
 * banner_sets.text_box holds the set-wide controls (style, glow, show/hide,
 * button style and the ten sizes) as one JSON document, normalised and
 * clamped by App\Support\BannerTextBox on the way in AND on the way out.
 *
 * NOTHING ON THE SHOP MOVES WHEN THIS RUNS: every box_on is false, so every
 * slide draws exactly the bytes it drew before. The style ships live -- the
 * first picture the owner writes words on shows them, no switch to find.
 */
return new class extends Migration
{
    private const CARD_TEXT = [
        'eyebrow' => 120, 'sticker' => 24, 'sticker_ring' => 40,
        'eyebrow_ar' => 120, 'heading_ar' => 190, 'body_ar' => 255,
        'button_label_ar' => 80, 'sticker_ar' => 24, 'sticker_ring_ar' => 40,
    ];

    public function up(): void
    {
        Schema::table('banner_cards', function (Blueprint $t) {
            if (! Schema::hasColumn('banner_cards', 'box_on')) {
                $t->boolean('box_on')->default(false);
            }

            foreach (self::CARD_TEXT as $column => $length) {
                if (! Schema::hasColumn('banner_cards', $column)) {
                    $t->string($column, $length)->default('');
                }
            }

            if (! Schema::hasColumn('banner_cards', 'box_pos')) {
                $t->string('box_pos', 8)->default('start');
            }
        });

        Schema::table('banner_sets', function (Blueprint $t) {
            if (! Schema::hasColumn('banner_sets', 'text_box')) {
                $t->text('text_box')->nullable();
            }
        });

        if (app()->runningInConsole()) {
            echo "Banner slider pictures can now carry words in a text box (heading, short\n"
                ."text, a button). Appearance -> Banners -> open the homepage set -> 'Text box'\n"
                ."for the style and sizes, and 'Words on this picture' on each picture.\n"
                ."Nothing on the shop moved: no picture shows words until some are written.\n";
        }
    }

    public function down(): void
    {
        Schema::table('banner_cards', function (Blueprint $t) {
            foreach (array_merge(['box_on', 'box_pos'], array_keys(self::CARD_TEXT)) as $column) {
                if (Schema::hasColumn('banner_cards', $column)) {
                    $t->dropColumn($column);
                }
            }
        });

        Schema::table('banner_sets', function (Blueprint $t) {
            if (Schema::hasColumn('banner_sets', 'text_box')) {
                $t->dropColumn('text_box');
            }
        });
    }
};
