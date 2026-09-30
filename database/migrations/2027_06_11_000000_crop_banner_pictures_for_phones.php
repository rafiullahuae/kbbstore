<?php

declare(strict_types=1);

use App\Models\BannerSet;
use App\Support\ImageVariants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the phone-shaped crop of every banner picture that has no phone picture.
 *                                                                  (Lane SEC)
 *
 * ── WHY THIS MIGRATION IS THE WHOLE POINT, NOT THE TIDY-UP ──────────────────
 *
 * The phone picture field is NEW. Every slide on the shop falls back on day
 * one, on the page PageSpeed measures hardest, right after a lane took mobile
 * from 76 to 86. A fallback that only gets good once the owner runs a batch, or
 * uploads again, is not a fallback — it is a promise.
 *
 * Crops are written when a card is SAVED (BannerApiController::cropPhoneCopies)
 * and that covers every card from here on. This covers the ones that already
 * exist, which is the only set that matters today.
 *
 * ── WHAT IT COSTS, MEASURED ─────────────────────────────────────────────────
 *
 * Per picture, GD, this machine — decode once, then one resample and encode per
 * width:
 *
 *   1920 x 550   (153 KB)   43 ms
 *   3000 x 900   (367 KB)   63 ms
 *   4000 x 1200  (624 KB)  159 ms
 *
 * A shop has three to five banner pictures, not a catalogue, so this is well
 * under a second inside an update the owner is already waiting on. It is
 * bounded by the number of rows in `banner_cards`, which nothing can make
 * large: the console builds sets by hand.
 *
 * ── AND WHAT IT BUYS ────────────────────────────────────────────────────────
 *
 * Measured on a photographic 1920 x 550 JPEG in the shipped 500 x 600 phone
 * frame, per slide:
 *
 *   before, with the flat sizes     4.3 KB    badly soft (0.25 device px/css px)
 *   before, with the cover sizes  152.6 KB    sharp, three quarters discarded
 *   after, the native crop         38.6 KB    sharp, IDENTICAL pixels
 *
 * The crop is the rectangle `object-fit: cover` was going to show — the same
 * band, extracted on the server instead of in the browser — so nothing about
 * the page changes except what crosses the wire.
 *
 * ── IT CANNOT FAIL AN UPDATE ────────────────────────────────────────────────
 *
 * Every call is wrapped. A shop with no GD, an SVG banner, a read-only cache
 * directory or a path that no longer exists produces no crop and no exception,
 * and the storefront falls back to the whole picture exactly as it does today.
 * The updater is the one thing on this shop that cannot be allowed to throw —
 * UpdateRunner's own history says why — so this reports and carries on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $made = $cards = $skipped = 0;
        $failed = [];

        /*
         * ONLY SLIDER SETS, and only cards with no phone picture of their own.
         * A cards banner draws one shape at every width and has nothing to crop
         * for; a slide that already has a phone picture will never draw the
         * crop, so writing one would be bytes on disk nobody reads.
         */
        $rows = DB::table('banner_cards')
            ->join('banner_sets', 'banner_sets.id', '=', 'banner_cards.banner_set_id')
            ->where('banner_sets.kind', 'slider')
            ->where('banner_cards.image', '<>', '')
            ->where(function ($q) {
                $q->whereNull('banner_cards.image_m')->orWhere('banner_cards.image_m', '');
            })
            ->select([
                'banner_cards.image as image',
                'banner_sets.slider_ratio_m as ratio_m',
            ])
            ->get();

        /*
         * The shape is resolved through the MODEL rather than from the column,
         * so an unknown or empty `slider_ratio_m` falls back to the shipped
         * preset exactly as the storefront's frame does. A crop written at a
         * shape the page never asks for is a file nothing reads, and the two
         * disagreeing is precisely how that happens.
         */
        foreach ($rows as $row) {
            $cards++;

            try {
                $set = new BannerSet;
                $set->forceFill(['slider_ratio_m' => $row->ratio_m]);

                $result = ImageVariants::generateCrop(
                    ImageVariants::rootRelative((string) $row->image),
                    $set->sliderRatioMobileToken(),
                    $set->sliderRatioMobileValue(),
                );

                $made += (int) $result['made'];
                $skipped += (int) $result['skipped'];

                if ($result['reason'] !== null) {
                    $failed[$result['reason']] = ($failed[$result['reason']] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                report($e);
                $failed['an error, reported to the log'] = ($failed['an error, reported to the log'] ?? 0) + 1;
            }
        }

        if (app()->runningInConsole()) {
            echo "Banner pictures cropped for phones: {$cards} looked at, {$made} copies made,\n"
                ."{$skipped} already there.\n";

            foreach ($failed as $reason => $count) {
                echo "  {$count} skipped: {$reason}\n";
            }

            echo "Phones now download the part of the banner they were going to see, instead\n"
                ."of the whole wide picture. Nothing on the shop looks different -- it is the\n"
                ."same crop, made earlier. Upload a 500 x 600 picture per slide under\n"
                ."Appearance -> Banners and they will show that instead.\n";
        }
    }

    /**
     * The crops go, and nothing else.
     *
     * ImageVariants::forget() walks the crop directories now, so an original
     * being deleted or replaced takes its crops with it; this is the other
     * direction, for a rollback. The ORIGINALS are never touched — they are the
     * owner's photographs and a rollback that deleted them would be a far worse
     * failure than a directory of unread copies.
     */
    public function down(): void
    {
        foreach (glob(public_path(ImageVariants::DIR).'/c*', GLOB_ONLYDIR) ?: [] as $dir) {
            self::removeTree($dir);
        }
    }

    private static function removeTree(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
};
