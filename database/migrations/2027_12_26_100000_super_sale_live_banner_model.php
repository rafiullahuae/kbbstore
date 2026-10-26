<?php

declare(strict_types=1);

use App\Support\SuperSaleBanner;
use Illuminate\Database\Migrations\Migration;

/*
 * Lane SSB: the live Super Sale banner's picture.
 *
 * The owner's own transparent cutout of the lady (26 October):
 * resources/banners/kbb-super-sale-model-mobile.webp, 381 x 500 WebP with
 * alpha, 42 KB. The phone shows it, and so does the computer in "Single
 * image" mode, its default. This copies it into the web root's
 * uploads/appearance/ (beside the Super Sale pictures, as 2027_11_12_100000
 * did for the Arabic banners), records it in the Media Library so the
 * banner's editors can pick it again after he tries his own, and makes its
 * img-cache copies -- WebP stays WebP with its alpha (ImageVariants::write())
 * -- so the page offers a srcset rather than the original.
 *
 * Touches no setting: the banner's picture slots stay empty, which means
 * "the shipped cutout". Any failure leaves the banner without a picture (the
 * slot is simply not drawn -- SuperSaleBanner::modelUrl()), never a broken
 * icon.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ship(SuperSaleBanner::MODEL_FILE);
    }

    private function ship(string $file): void
    {
        try {
            $rel = 'uploads/appearance/'.$file;
            $dest = rtrim(public_path(), '/').'/'.$rel;
            $dir = dirname($dest);

            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return;
            }

            if (! is_file($dest)) {
                $tmp = $dest.'.'.getmypid().'.part';

                if (! @copy(resource_path('banners/'.$file), $tmp) || ! @rename($tmp, $dest)) {
                    @unlink($tmp);

                    return;
                }

                @chmod($dest, 0644);
            }

            if (class_exists(\App\Support\MediaRegistrar::class)) {
                \App\Support\MediaRegistrar::record($rel, $file, 'image/webp');
            }

            // WebP stays WebP and keeps its alpha (ImageVariants::write()).
            if (\App\Support\ImageVariants::available()) {
                \App\Support\ImageVariants::generate('/'.$rel);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
