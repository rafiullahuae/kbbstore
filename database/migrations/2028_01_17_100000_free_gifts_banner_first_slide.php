<?php

declare(strict_types=1);

use App\Support\ImageVariants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.655 -- the owner, 10 November 2026: "give me the patch for this banner,
 * add this banner by default on 1st position in slider. and also don't
 * compromise on the speed etc."
 *
 * The "Get Free Gifts" banner (docs/plans/free-gifts-ad/: the owner's reference
 * fonts, the website's pink button) becomes slide 1 of the homepage slider --
 * the banner set Appearance -> Banners -> "Which banner shows on the homepage"
 * names. Every slide already there moves down one place and is otherwise
 * untouched.
 *
 * THE PICTURES travel in the package under resources/banners/free-gifts/
 * (a package may not write into public/ itself) and are copied here into the
 * web root as uploads/banners/get-free-gifts-{desktop,phone}.webp:
 *   desktop 3840 x 1200 (a 1920 x 600 slider box at 2x; the slider crops from
 *           the centre, so a smaller screen loses only plain pink at the sides)
 *   phone   1200 x 1540 (the 390 x 500 phone box at ~3x)
 * SPEED: their resized copies are made HERE, once (200/400/800 and the banner
 * widths 1280..2400) -- so no shopper is ever handed the original: a 1280
 * screen downloads ~19 KB, a 1920 one ~34 KB, a phone ~14-37 KB.
 *
 * SAFE TO RUN AGAIN: a slide with this picture already in the set is left
 * alone; an existing file is not overwritten. No banner set chosen, no table:
 * nothing is written. Literals only, apart from the guarded image work.
 */
return new class extends Migration
{
    private const DESKTOP = 'uploads/banners/get-free-gifts-desktop.webp';

    private const PHONE = 'uploads/banners/get-free-gifts-phone.webp';

    private const SOURCES = [
        self::DESKTOP => 'banners/free-gifts/get-free-gifts-desktop.webp',
        self::PHONE => 'banners/free-gifts/get-free-gifts-phone.webp',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('banner_cards') || ! Schema::hasTable('module_settings')) {
            return;
        }

        $raw = DB::table('module_settings')->where('module', 'cards_banner')->where('key', 'set')->value('value');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $setId = is_scalar($decoded) ? (string) $decoded : (is_string($raw) ? trim($raw, "\" \t") : '');
        if ($setId === '' || ! ctype_digit($setId) || ! DB::table('banner_sets')->where('id', (int) $setId)->exists()) {
            return;
        }
        $setId = (int) $setId;

        // The pictures, into the web root (once), and their resized copies.
        foreach (self::SOURCES as $rel => $src) {
            $from = resource_path($src);
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
                    if ($rel === self::DESKTOP) {
                        ImageVariants::generateWide('/'.$rel);
                    }
                } catch (\Throwable $e) {
                    report($e); // a missing copy means a larger download, never a broken slide
                }
            }
        }
        if (! is_file(public_path(self::DESKTOP))) {
            return; // never a slide pointing at a picture that is not there
        }

        if (DB::table('banner_cards')->where('banner_set_id', $setId)->where('image', self::DESKTOP)->exists()) {
            return;
        }

        $hasPhone = Schema::hasColumn('banner_cards', 'image_m') && is_file(public_path(self::PHONE));
        $first = (int) (DB::table('banner_cards')->where('banner_set_id', $setId)->min('position') ?? 0);

        DB::transaction(function () use ($setId, $hasPhone, $first): void {
            // Everything already in the slider moves down one place, in order.
            if ($first < 1) {
                DB::table('banner_cards')->where('banner_set_id', $setId)->increment('position');
                $first++;
            }
            $row = [
                'banner_set_id' => $setId,
                'image' => self::DESKTOP,
                'image_w' => 3840,
                'image_h' => 1200,
                'alt' => 'Get free gifts with your order at K-Beauty Bliss',
                'heading' => '',
                'body' => '',
                'button_label' => '',
                'button_url' => '/super-sale/',
                'position' => $first - 1,
                'status' => 'publish',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasPhone) {
                $row += ['image_m' => self::PHONE, 'image_m_w' => 1200, 'image_m_h' => 1540];
            }
            DB::table('banner_cards')->insert($row);
        });

        // The slider is read through the settings/page caches: clear them so
        // the next homepage shows the new first slide.
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $f) {
            @unlink($f);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('banner_cards')) {
            DB::table('banner_cards')->where('image', self::DESKTOP)->delete();
        }
    }
};
