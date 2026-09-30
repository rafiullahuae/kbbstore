<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SECOND PICTURE PER SLIDE, FOR PHONES.                          (Lane SEC)
 *
 * The owner: "for desktop the size should be 1920 x 550 and in mobile 500 x
 * 600". Two sizes is two pictures, and he described the phone one as a thing
 * that exists. A slide had ONE image field, which made that our gap rather than
 * his ambiguity.
 *
 * ── WHAT THE GAP COST, MEASURED BEFORE THIS RAN ─────────────────────────────
 *
 * With one picture serving both frames, a 1920 x 550 upload in the shipped
 * 500 x 600 phone frame is cropped to about 24% of its width — `object-fit:
 * cover` on a frame taller than it is wide — and Chromium chose the 400w copy
 * for a box whose covered width is 1533 CSS pixels, so it was soft as well as
 * cropped. storage/sec-logs/shots/many-390.png is the picture of it, and
 * wrong-390.png is the control: a portrait source in the same portrait frame
 * is sharp, which is what says the frame was never the problem.
 *
 * ── THREE COLUMNS, MIRRORING THE THREE THAT EXIST ───────────────────────────
 *
 *   image_m      the stored root-relative path, exactly as `image` is —
 *                `uploads/banners/x.webp`, never a URL. The same rule and the
 *                same reason: a URL here is a picture the Media Library cannot
 *                account for and a scheme this shop has not checked.
 *   image_m_w    NULL is "we do not know", never 0 — the same convention
 *   image_m_h    `image_w`/`image_h` were created under.
 *
 * DEFAULT '' AND NOT NULL for the path, so "no phone picture" is one value and
 * not two. `drawable()` already reads `image` that way and the storefront's
 * filter is `image <> ''`; a nullable path would mean every reader testing for
 * both.
 *
 * ── AND NOTHING MOVES ON THE SHOP WHEN THIS RUNS ────────────────────────────
 *
 * Every existing row gets `image_m = ''`, which is "no phone picture", which is
 * the fallback path — the same single picture in both frames the shop draws
 * today. The fallback is no longer the blurry one (slider-banner.blade.php now
 * asks for the width that covering the phone frame actually needs, computed
 * from `image_w`/`image_h`), and that is a change to what is DOWNLOADED rather
 * than to what is drawn: same picture, same crop, sharp instead of soft.
 * BannerPhonePictureTest measures both halves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banner_cards', function (Blueprint $t) {
            if (! Schema::hasColumn('banner_cards', 'image_m')) {
                $t->string('image_m', 400)->default('');
            }

            if (! Schema::hasColumn('banner_cards', 'image_m_w')) {
                $t->unsignedInteger('image_m_w')->nullable();
            }

            if (! Schema::hasColumn('banner_cards', 'image_m_h')) {
                $t->unsignedInteger('image_m_h')->nullable();
            }
        });

        if (app()->runningInConsole()) {
            echo "Each picture in a banner slider can now carry a SECOND picture for phones.\n"
                ."Appearance -> Banners -> open a set -> 'Choose the phone picture' on the\n"
                ."row, next to the picture you already have. Desktop stays 1920 x 550 and\n"
                ."the phone one is 500 x 600. Nothing on the shop moved: a slide with no\n"
                ."phone picture draws the one it already had, in both frames, as before.\n";
        }
    }

    /**
     * The three columns go, and with them any phone picture chosen.
     *
     * The FILES are left alone deliberately — they are in the Media Library,
     * which is the one place that accounts for an upload, and a migration that
     * deleted a shopkeeper's photographs to tidy up a rollback would be a far
     * worse failure than three dead columns.
     */
    public function down(): void
    {
        Schema::table('banner_cards', function (Blueprint $t) {
            foreach (['image_m', 'image_m_w', 'image_m_h'] as $column) {
                if (Schema::hasColumn('banner_cards', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
