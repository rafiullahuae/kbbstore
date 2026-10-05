<?php
/*
 * Seed the Lane MO preview: the owner's own menu as his screenshot shows it
 * (docs/mo-owner/menu-editor-before.png) — twelve top-level items, Brands with
 * 17 and Skincare with 10 underneath — plus the preview admin. The sub-menu
 * names inside Brands and Skincare are examples, as in the layout previews.
 * Preview database only; nothing here reaches a package.
 */
use App\Models\{AdminUser, Menu, MenuItem};

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

MenuItem::query()->delete();
Menu::query()->delete();
$menu = Menu::create(['name' => 'Main menu', 'slug' => 'main-menu', 'show_desktop' => true, 'show_mobile' => true]);

$slug = fn (string $s) => '/'.trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s)), '-').'/';
$tops = [
    ['Blog', '/blog/'], ['Everything Under 54 AED', '/everything-under-54-aed/'], ['Beauty Devices', '/collections/beauty-devices/'],
    ['Super Sale', '/super-sale/', '#E0567B'], ['Skincare Sets', '/collections/skincare-sets/'], ['Hair Care', '/collections/hair-care/'],
    ['Lip Care', '/collections/lip-care/'], ['Toners', '/collections/toners/'], ['Moisturizers', '/collections/moisturizers/'],
    ['Sunscreens', '/collections/sunscreens/'], ['Brands', '/brands/'], ['Skincare', '/collections/skincare/'],
];
$subs = [
    'Brands' => ['Popular' => ['Anua', 'COSRX', 'Beauty of Joseon', 'Medicube', 'SKIN1004', 'Mixsoon'], 'A – L' => ['Abib', 'Axis-Y', 'Dr. Althea', 'Isntree', 'Laneige'], 'M – Z' => ['Round Lab', 'Some By Mi', 'Torriden', 'VT']],
    'Skincare' => ['Cleanse' => ['Cleansers', 'Cleansing Oils', 'Exfoliators'], 'Treat' => ['Serums', 'Ampoules', 'Essences'], 'Moisturise' => ['Moisturizers', 'Eye Care'], 'Masks' => ['Sheet Masks', 'Wash-off Masks']],
];
foreach ($tops as $i => $t) {
    $top = MenuItem::create(['menu_id' => $menu->id, 'label' => $t[0], 'url' => $t[1], 'highlight_color' => $t[2] ?? null, 'position' => $i, 'visibility' => 'always']);
    $j = 0;
    foreach ($subs[$t[0]] ?? [] as $title => $links) {
        $sub = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $top->id, 'label' => $title, 'url' => null, 'position' => $j++, 'visibility' => 'always']);
        foreach ($links as $k => $l) {
            MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $sub->id, 'label' => $l, 'url' => $slug($l), 'position' => $k, 'visibility' => 'always']);
        }
    }
}
echo 'mo seed: '.MenuItem::count()." menu items\n";
