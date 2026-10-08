<?php

/*
 * Lane MN (2.60.441) preview seed: the desktop menu's "current page" marking.
 * Run through `artisan tinker --execute` by tools/nc-preview.sh, on top of
 * DemoCatalogueSeeder.
 *
 * Gives every page type something to be "inside":
 *   Skincare (/collections/skincare/) > Serums > Vitamin C serums, and one demo
 *   product filed under all three, so a product page lights Skincare through
 *   its most specific category.
 *   Brands (/brands/) with a 3-column panel of brand links (mega).
 *   Blog (/blog/) and one published article; Delivery (/delivery/) a page.
 *   New In (/shop/?orderby=date) next to Shop (/shop/), the query-string pair.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$skin = Category::updateOrCreate(['slug' => 'skincare'], ['name' => 'Skincare', 'parent_id' => null, 'depth' => 0, 'path' => 'skincare', 'position' => 0]);
$serums = Category::where('slug', 'serums')->first();
$serums->update(['parent_id' => $skin->id, 'depth' => 1, 'path' => 'skincare/serums']);
$vc = Category::updateOrCreate(['slug' => 'vitamin-c-serums'], ['name' => 'Vitamin C serums', 'parent_id' => $serums->id, 'depth' => 2, 'path' => 'skincare/serums/vitamin-c-serums', 'position' => 0]);

$p = Product::where('slug', 'vitamin-c-brightening-serum')->first();
$p->update(['category_id' => $vc->id]);
$p->categories()->sync([$skin->id, $serums->id, $vc->id]);

Page::updateOrCreate(['slug' => 'delivery'], ['title' => 'Shipping & Delivery', 'content' => '<p>Same-day delivery in Dubai.</p>', 'status' => 'published']);
app(\App\Services\SettingsService::class)->set('language_ar_enabled', true);
app(\App\Services\SettingsService::class)->set('language_rtl_enabled', true);
Post::updateOrCreate(['slug' => 'nc-morning-routine'], ['title' => 'A five-step morning routine', 'body' => '<p>Cleanse, tone, serum, moisturise, sunscreen.</p>', 'excerpt' => 'Five steps.', 'status' => 'published', 'published_at' => now()->subDay()]);

DB::table('menu_items')->delete();
DB::table('menus')->delete();

$menu = Menu::create(['name' => 'Main', 'slug' => 'nc-main', 'show_desktop' => true, 'show_mobile' => true, 'show_footer' => false]);
$pos = 0;
$add = function (string $label, string $url, ?int $parent = null, array $extra = []) use ($menu, &$pos) {
    return MenuItem::create(array_merge([
        'menu_id' => $menu->id, 'parent_id' => $parent, 'label' => $label, 'url' => $url,
        'target_type' => 'custom', 'visibility' => 'always', 'new_tab' => false, 'position' => $pos++,
    ], $extra));
};

$add('Home', '/');
$add('Shop', '/shop/');
$add('New In', '/shop/?orderby=date', null, ['badge' => 'NEW']);
$sk = $add('Skincare', '/collections/skincare/');
foreach (['Cleansers' => 'cleansers', 'Toners' => 'toners', 'Serums' => 'skincare/serums', 'Vitamin C serums' => 'skincare/serums/vitamin-c-serums', 'Moisturisers' => 'moisturisers', 'Sunscreens' => 'sunscreens'] as $label => $path) {
    $add($label, '/collections/'.$path.'/', $sk->id);
}
$add('Masks', '/collections/masks/');
$br = $add('Brands', '/brands/', null, ['columns' => 3]);
foreach (Brand::orderBy('id')->get() as $b) {
    $add($b->name, '/brands/'.$b->slug.'/', $br->id);
}
$add('Blog', '/blog/');
$add('Delivery', '/delivery/');

Cache::flush();

echo "nc seed ok\n";
