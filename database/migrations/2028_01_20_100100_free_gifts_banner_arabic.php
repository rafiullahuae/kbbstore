<?php

declare(strict_types=1);

use App\Support\ImageVariants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.659 -- the "Get Free Gifts" slide (2.60.655) in Arabic, and the
 * "SHOP NOW, PAY LATER WITH TABBY OR TAMARA" line moved up in both languages
 * (the owner, 11 Nov 2026: "the tabby tamara line is hiding, make it little up
 * side, in both versions" -- it sat under the slider's progress bar on phones).
 *
 * Four pictures, built by docs/plans/free-gifts-ad/build.py, travel under
 * resources/banners/free-gifts/ and are copied into uploads/banners/ with
 * their resized copies made here, once. NEW file names (-2), so no browser or
 * img-cache keeps the old line. Only the slide still showing the 2.60.655
 * picture is changed -- if the owner has replaced or removed it, nothing is.
 * Safe to run again.
 */
return new class extends Migration
{
    private const OLD = 'uploads/banners/get-free-gifts-desktop.webp';

    private const FILES = [
        'desktop' => ['uploads/banners/get-free-gifts-desktop-2.webp', 3840, 1200],
        'phone' => ['uploads/banners/get-free-gifts-phone-2.webp', 1200, 1540],
        'desktop_ar' => ['uploads/banners/get-free-gifts-desktop-ar-2.webp', 3840, 1200],
        'phone_ar' => ['uploads/banners/get-free-gifts-phone-ar-2.webp', 1200, 1540],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('banner_cards') || ! Schema::hasColumn('banner_cards', 'image_m_ar')) {
            return;
        }

        foreach (self::FILES as $key => [$rel]) {
            $from = resource_path('banners/free-gifts/'.basename($rel));
            $to = public_path($rel);
            if (! is_file($to) && is_file($from)) {
                if (! is_dir(dirname($to))) {
                    @mkdir(dirname($to), 0755, true);
                }
                @copy($from, $to);
            }
            if (is_file($to)) {
                try {
                    ImageVariants::generate('/'.$rel);
                    if (str_starts_with($key, 'desktop')) {
                        ImageVariants::generateWide('/'.$rel);
                    }
                } catch (\Throwable $e) {
                    report($e); // a missing copy means a larger download, never a broken slide
                }
            }
        }

        foreach (self::FILES as [$rel]) {
            if (! is_file(public_path($rel))) {
                return; // never a slide pointing at a picture that is not there
            }
        }

        $f = self::FILES;
        DB::table('banner_cards')->where('image', self::OLD)->update([
            'image' => $f['desktop'][0], 'image_w' => $f['desktop'][1], 'image_h' => $f['desktop'][2],
            'image_m' => $f['phone'][0], 'image_m_w' => $f['phone'][1], 'image_m_h' => $f['phone'][2],
            'image_ar' => $f['desktop_ar'][0], 'image_ar_w' => $f['desktop_ar'][1], 'image_ar_h' => $f['desktop_ar'][2],
            'image_m_ar' => $f['phone_ar'][0], 'image_m_ar_w' => $f['phone_ar'][1], 'image_m_ar_h' => $f['phone_ar'][2],
            'alt' => 'Get free gifts with your order at K-Beauty Bliss',
            'updated_at' => now(),
        ]);

        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob(base_path('bootstrap/cache/routes-*.php')) ?: [] as $file) {
            @unlink($file);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function down(): void {}
};
