<?php

declare(strict_types=1);

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * Seeds the real kbeautybliss.com navigation — same items, same order, real
 * brand and category links, pulled directly from the live site — and
 * assigns it to both the desktop header and mobile menu immediately. Not a
 * suggestion the admin has to go find and turn on: the site currently shows
 * the built-in placeholder fallback, and this replaces it with real content
 * the moment this update finishes applying, the same one the admin already
 * reviewed and approved via preview before this was built.
 *
 * Guarded by name, not just by Laravel's own once-per-migration tracking:
 * if a menu with this exact name already exists (this ran once before, or
 * someone built one by hand with the same name), this does nothing rather
 * than create a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = 'K-Beauty Bliss Menu';

        if (Menu::where('name', $name)->exists()) {
            return;
        }

        // Steals both slots from whatever currently holds them — the same
        // exclusivity rule Menu settings itself enforces. A menu that's
        // meant to actually show has to really be the one showing.
        Menu::where('show_desktop', true)->update(['show_desktop' => false]);
        Menu::where('show_mobile', true)->update(['show_mobile' => false]);

        $slug = Str::slug($name);
        $n = 2;
        while (Menu::where('slug', $slug)->exists()) {
            $slug = Str::slug($name) . '-' . $n++;
        }

        $menu = Menu::create([
            'name' => $name, 'slug' => $slug,
            'show_desktop' => true, 'show_mobile' => true, 'show_footer' => false,
        ]);

        $pos = 0;
        $top = fn (array $attrs) => MenuItem::create(array_merge([
            'menu_id' => $menu->id, 'parent_id' => null, 'position' => $pos++,
        ], $attrs));
        $child = function (int $parentId, int &$p, array $attrs) use ($menu) {
            return MenuItem::create(array_merge([
                'menu_id' => $menu->id, 'parent_id' => $parentId, 'position' => $p++,
            ], $attrs));
        };

        $trending = $top(['label' => 'Trending Brands']);
        $p = 0;
        foreach ([
            'Anua' => 'anua', 'Axis-Y' => 'axis-y', 'Beauty of Joseon' => 'beauty-of-joseon',
            'BIODANCE' => 'biodance', 'Celimax' => 'celimax', 'COSRX' => 'cosrx',
            'Dr.Althea' => 'dr-althea', 'EQQUALBERRY' => 'eqqualberry', 'Goodal' => 'goodal',
            "I'm from" => 'im-from', 'LANEIGE' => 'laneige', 'MEDICUBE' => 'medicube',
            'numbuzin' => 'numbuzin', 'Shiseido' => 'shiseido', 'SKIN 1004' => 'skin-1004',
            'SOME BY MI' => 'some-by-mi', 'VT Cosmetics' => 'vt-cosmetics',
        ] as $label => $brandSlug) {
            $child($trending->id, $p, ['label' => $label, 'url' => '/shop/?filter_brands=' . $brandSlug]);
        }

        $top(['label' => 'All Brands', 'url' => '/korean-skincare-brands/']);

        $skincare = $top(['label' => 'Skincare']);
        $p = 0;
        foreach ([
            'Cleansing Oils' => '/cleansing-oils/', 'Face Washes' => '/face-washes/',
            'Exfoliators' => '/exfoliators/', 'Toners' => '/toners/',
            'Face Serums' => '/face-serums/', 'Eye Care' => '/eye-care/',
            'Face Masks' => '/face-masks/', 'Moisturizers' => '/moisturizers/',
            'Lip Care' => '/lip-care/', 'Sunscreens' => '/sunscreens/',
        ] as $label => $url) {
            $child($skincare->id, $p, ['label' => $label, 'url' => $url]);
        }

        $top(['label' => 'Super Sale', 'url' => '/super-sale/', 'highlight_color' => '#E23A4E']);
        $top(['label' => 'Skincare Sets', 'url' => '/skincare-sets/']);
        $top(['label' => 'Hair Care', 'url' => '/hair-care/']);
        $top(['label' => 'Beauty Devices', 'url' => '/beauty-devices/']);
        $top(['label' => 'Everything Under 54 AED', 'url' => '/everything-under-54-aed/']);
        $top(['label' => 'Blog', 'url' => '/skincare-guide/']);
        $top(['label' => 'Sign In', 'url' => '/my-account/', 'visibility' => 'guest']);
        $top(['label' => 'Wishlist', 'url' => '/my-wishlist/']);
    }

    public function down(): void
    {
        Menu::where('name', 'K-Beauty Bliss Menu')->delete();
    }
};
