<?php

declare(strict_types=1);

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

/**
 * Fixes the top-level structure of the menu the 2.60.19 migration created.
 *
 * That version put Trending Brands and All Brands at the top level as their
 * own items, and nested Sunscreens/Moisturizers/Toners/Lip Care only inside
 * Skincare. The real site's top level is different: Brands as a single
 * top-level item with Trending/All nested inside it, and Sunscreens,
 * Moisturizers, Toners, and Lip Care each also getting their own top-level
 * shortcut alongside their place in the Skincare dropdown — confirmed
 * directly against the live site's own navigation.
 *
 * This also fixes a real, live bug: a separate, pre-existing fallback
 * (MenuDemo::fill(), used when Store → Modules → "Demo content" is on)
 * tops up a real menu with the published kbeautybliss.com structure
 * whenever a top-level label doesn't already match. Trending Brands and
 * All Brands as standalone top-level items didn't match anything in that
 * fallback's expected "Brands" label, so it kept appending its own Brands
 * entry (and everything else it thought was missing) alongside the real
 * one — the double items seen on the live mobile menu. Matching the real
 * top-level labels here removes the mismatch at its source, without
 * changing the shared fallback itself.
 *
 * Deletes and rebuilds the menu from scratch rather than patching it in
 * place — simpler and safer than surgically moving 38 existing rows
 * around, and low-risk here specifically because this menu is generated
 * content, not something an admin has hand-edited yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = 'K-Beauty Bliss Menu';
        $existing = Menu::where('name', $name)->first();

        // Only touches a menu it recognizes by this exact name — if it's
        // been renamed, or was never created (a fresh install running this
        // migration for the first time already gets the corrected version
        // from 2.60.19+ directly), this does nothing rather than guess.
        if (! $existing) {
            return;
        }

        $wasDesktop = (bool) $existing->show_desktop;
        $wasMobile = (bool) $existing->show_mobile;
        $wasFooter = (bool) $existing->show_footer;
        $slug = $existing->slug;

        $existing->delete();

        $menu = Menu::create([
            'name' => $name, 'slug' => $slug,
            'show_desktop' => $wasDesktop, 'show_mobile' => $wasMobile, 'show_footer' => $wasFooter,
        ]);

        $pos = 0;
        $top = fn (array $attrs) => MenuItem::create(array_merge([
            'menu_id' => $menu->id, 'parent_id' => null, 'position' => $pos++,
        ], $attrs));
        $child = fn (int $parentId, int &$p, array $attrs) => MenuItem::create(array_merge([
            'menu_id' => $menu->id, 'parent_id' => $parentId, 'position' => $p++,
        ], $attrs));

        // A simple flat dropdown, not a mega panel — a two-column panel
        // (Trending Brands / All Brands) sounded reasonable but rendered
        // with one column empty and looked broken. Confirmed by actually
        // rendering it before deciding: this flat version looks right.
        $brands = $top(['label' => 'Brands', 'url' => '/korean-skincare-brands/']);
        $p = 0;
        foreach ([
            'Anua' => 'anua', 'Axis-Y' => 'axis-y', 'Beauty of Joseon' => 'beauty-of-joseon',
            'BIODANCE' => 'biodance', 'Celimax' => 'celimax', 'COSRX' => 'cosrx',
            'Dr.Althea' => 'dr-althea', 'EQQUALBERRY' => 'eqqualberry', 'Goodal' => 'goodal',
            "I'm from" => 'im-from', 'LANEIGE' => 'laneige', 'MEDICUBE' => 'medicube',
            'numbuzin' => 'numbuzin', 'Shiseido' => 'shiseido', 'SKIN 1004' => 'skin-1004',
            'SOME BY MI' => 'some-by-mi', 'VT Cosmetics' => 'vt-cosmetics',
        ] as $label => $brandSlug) {
            $child($brands->id, $p, ['label' => $label, 'url' => '/shop/?filter_brands=' . $brandSlug]);
        }

        $skincare = $top(['label' => 'Skincare', 'url' => '/skincare/']);
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

        $top(['label' => 'Sunscreens', 'url' => '/sunscreens/']);
        $top(['label' => 'Moisturizers', 'url' => '/moisturizers/']);
        $top(['label' => 'Toners', 'url' => '/toners/']);
        $top(['label' => 'Lip Care', 'url' => '/lip-care/']);
        $top(['label' => 'Hair Care', 'url' => '/hair-care/']);
        $top(['label' => 'Skincare Sets', 'url' => '/skincare-sets/']);
        $top(['label' => 'Super Sale', 'url' => '/super-sale/', 'highlight_color' => '#E23A4E']);
        $top(['label' => 'Beauty Devices', 'url' => '/beauty-devices/']);
        $top(['label' => 'Everything Under 54 AED', 'url' => '/everything-under-54-aed/']);
        $top(['label' => 'Blog', 'url' => '/skincare-guide/']);
    }

    public function down(): void
    {
        // Deliberately not reversible back to the old, incorrect structure
        // — there's nothing worth going back to.
    }
};
