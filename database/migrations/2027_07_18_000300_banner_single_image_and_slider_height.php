<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane RC -- the single-image banner, and a slider that never cuts a picture.
 *
 * The owner, on Appearance -> Banners -> (a set): "Also i need here option
 * single image. and for slider images, there should be height control of the
 * overall banner. and image should adjust auto with the screen without cutting
 * etc."
 *
 * ── THREE NEW COLUMNS, ALL NULLABLE, NO ->change() ──────────────────────────
 *
 *   slider_fit   string(16)  `contain` | `cover`; null reads as `contain`
 *   slider_h     smallint    desktop height cap in px; null/0 = Auto
 *   slider_h_m   smallint    phone height cap in px;   null/0 = Auto
 *
 * `single` needs no column: it is a new value of `kind`, which is already a
 * string(16) validated against BannerSet::KINDS.
 *
 * Nullable additions run the same way on SQLite and MySQL; a ->change() would
 * rebuild the table on one and ALTER in place on the other, inside an update
 * package, for nothing. The model's $attributes and its accessors carry the
 * defaults.
 *
 * ── THE ROWS ALREADY ON THE SHOP, MOVED BECAUSE HE ASKED ────────────────────
 *
 * CLAUDE.md rule 1, as reversed on 30 September: what the owner asked for
 * ships ON. So:
 *
 *   slider_fit          (null)  -> contain     "without cutting"
 *   slider_ratio        1920/550 -> auto       "adjust auto with the screen"
 *   slider_ratio_m      500/600  -> auto
 *
 * ONLY the shipped presets move to `auto`. A set holding another shape was
 * given it by somebody on purpose; it keeps that shape, and `contain` already
 * stops it cutting anything. A 1920 x 550 picture under `auto` draws exactly
 * the 1920 : 550 frame it drew before, so on the owner's own art the desktop
 * banner does not move a pixel -- what changes is art that is only roughly
 * that shape, and a slide with no phone picture, which stops being cut to its
 * middle quarter on a phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('banner_sets')) {
            return;
        }

        Schema::table('banner_sets', function (Blueprint $t) {
            if (! Schema::hasColumn('banner_sets', 'slider_fit')) {
                $t->string('slider_fit', 16)->nullable();
            }

            if (! Schema::hasColumn('banner_sets', 'slider_h')) {
                $t->unsignedSmallInteger('slider_h')->nullable();
            }

            if (! Schema::hasColumn('banner_sets', 'slider_h_m')) {
                $t->unsignedSmallInteger('slider_h_m')->nullable();
            }
        });

        $moved = [];

        $moved['slider_fit: -> contain'] = DB::table('banner_sets')
            ->whereNull('slider_fit')->update(['slider_fit' => 'contain']);

        $moved['slider_ratio: 1920/550 -> auto'] = DB::table('banner_sets')
            ->where('slider_ratio', '1920/550')->update(['slider_ratio' => 'auto']);

        $moved['slider_ratio_m: 500/600 -> auto'] = DB::table('banner_sets')
            ->where('slider_ratio_m', '500/600')->update(['slider_ratio_m' => 'auto']);

        if (app()->runningInConsole()) {
            echo "Banners: a 'Single image' type, a height control for sliders, and\n"
                ."pictures that are never cut (whole, at their own shape). Moved:\n";

            foreach ($moved as $what => $rows) {
                echo sprintf("  %-34s %d\n", $what, $rows);
            }

            echo "Appearance -> Banners -> (a set) -> What this set is / Size & fit.\n";
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('banner_sets')) {
            return;
        }

        DB::table('banner_sets')->where('slider_ratio', 'auto')->update(['slider_ratio' => '1920/550']);
        DB::table('banner_sets')->where('slider_ratio_m', 'auto')->update(['slider_ratio_m' => '500/600']);
        DB::table('banner_sets')->where('kind', 'single')->update(['kind' => 'slider']);

        Schema::table('banner_sets', function (Blueprint $t) {
            foreach (['slider_fit', 'slider_h', 'slider_h_m'] as $column) {
                if (Schema::hasColumn('banner_sets', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
