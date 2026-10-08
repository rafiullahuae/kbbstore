<?php

declare(strict_types=1);

use App\Models\BannerSet;
use App\Support\ImageVariants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "EXACTLY THIS HEIGHT" -- Lane HB3.
 *
 * The owner: "for the main site banner, on mobile the overall height of the
 * banner is not working, i set 600px height, but it's showing horizontal type
 * size."
 *
 * The banner height was only ever a CAP (BannerSet::sliderHeight()): next to an
 * Auto shape and a wide picture, the frame is the picture's own shape, far
 * under 600px, and the cap never applies. Two columns say how each height
 * works -- `max` (the cap, as before) or `exact` (the frame IS that tall) --
 * and both ship at `max`, so nothing moves...
 *
 * ...EXCEPT THE ONE THING HE ASKED FOR. A set that already has a phone height
 * (slider_h_m > 0) is one where somebody typed a number and expected a
 * height, so its phone mode becomes `exact`. A set at Auto (0) is untouched,
 * and no desktop mode moves anywhere.
 *
 * The phone crops are written here too, for those sets' pictures, so the first
 * phone visit after the package applies is served the 430 x N crop and never
 * the whole desktop picture. A crop that cannot be written (no image library,
 * a missing file) is skipped: the page still draws, and the next Save on the
 * Banners screen writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banner_sets', function (Blueprint $t) {
            if (! Schema::hasColumn('banner_sets', 'slider_hmode')) {
                $t->string('slider_hmode', 8)->default('max');
            }

            if (! Schema::hasColumn('banner_sets', 'slider_hmode_m')) {
                $t->string('slider_hmode_m', 8)->default('max');
            }
        });

        $ids = DB::table('banner_sets')->where('slider_h_m', '>', 0)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        DB::table('banner_sets')->whereIn('id', $ids)->update(['slider_hmode_m' => 'exact']);

        $made = 0;

        foreach (BannerSet::query()->whereIn('id', $ids)->get() as $set) {
            if (! $set->isSlider() || ! $set->sliderCropsPhone()) {
                continue;
            }

            foreach ($set->cards()->where('image', '<>', '')->get() as $card) {
                try {
                    $made += ImageVariants::generateCrop(
                        ImageVariants::rootRelative((string) $card->image),
                        $set->sliderRatioMobileToken(),
                        $set->sliderRatioMobileValue(),
                    )['made'];
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        if (app()->runningInConsole()) {
            echo 'Banner phone height is now EXACT on '.count($ids)." set(s) that had one (the owner asked).\n"
                ."Appearance -> Banners -> open the set -> Size & fit -> 'How the phone height works'.\n"
                .$made." phone crop file(s) written.\n";
        }
    }

    public function down(): void
    {
        Schema::table('banner_sets', function (Blueprint $t) {
            foreach (['slider_hmode', 'slider_hmode_m'] as $column) {
                if (Schema::hasColumn('banner_sets', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
