<?php

declare(strict_types=1);

use App\Support\SuperSaleBanner;
use Illuminate\Database\Migrations\Migration;

/*
 * Lane SSB: the live Super Sale banner's model picture.
 *
 * The owner has not supplied a new cutout, so the banner shows the lady from
 * the Super Sale banner the page already used: cut out of the shipped
 * resources/banners/kbeautybliss-2026-super-sale-ar.webp, 288 x 464, 16 KB.
 * This copies her into the web root's uploads/appearance/ (beside the Super
 * Sale pictures, as 2027_11_12_100000 did for the Arabic banners), records her
 * in the Media Library so Pages -> Page banners -> Super Sale banner can pick
 * her again after he tries his own, and makes her img-cache copies so the
 * page offers a srcset rather than the original.
 *
 * Touches no setting: the banner's own "model picture" stays empty, which
 * means "this lady". Any failure leaves the banner without a picture -- the
 * arch is simply not drawn (SuperSaleBanner::modelUrl()), never a broken icon.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $dest = rtrim(public_path(), '/').'/'.SuperSaleBanner::MODEL_REL;
            $dir = dirname($dest);

            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return;
            }

            if (! is_file($dest)) {
                $tmp = $dest.'.'.getmypid().'.part';

                if (! @copy(resource_path('banners/'.SuperSaleBanner::MODEL_FILE), $tmp) || ! @rename($tmp, $dest)) {
                    @unlink($tmp);

                    return;
                }

                @chmod($dest, 0644);
            }

            if (class_exists(\App\Support\MediaRegistrar::class)) {
                \App\Support\MediaRegistrar::record(SuperSaleBanner::MODEL_REL, SuperSaleBanner::MODEL_FILE, 'image/webp');
            }

            if (\App\Support\ImageVariants::available()) {
                \App\Support\ImageVariants::generate('/'.SuperSaleBanner::MODEL_REL);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
