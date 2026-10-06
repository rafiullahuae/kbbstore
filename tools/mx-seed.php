<?php
/*
 * Seed the Lane MX preview (Store -> Mega Menu -> Add items): an owner, a
 * category tree, forty brands, the content pages and a few posts, and the
 * kbeautybliss.com demo menu (15 top-level items, so the board scrolls
 * sideways) with one mega item, "K-Beauty", holding two empty columns for the
 * shots to fill. Written into the PREVIEW's database only.
 */
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$tree = [
    'Skincare' => ['Cleansers', 'Toners', 'Essences', 'Face Serums', 'Moisturizers', 'Sunscreens', 'Eye Care', 'Face Masks'],
    'Makeup' => ['Cushion Foundations', 'Lip Tints', 'BB & CC Creams'],
    'Hair Care' => ['Shampoos', 'Hair Treatments'],
    'Body Care' => ['Body Lotions', 'Hand Creams'],
    'Skincare Sets' => [],
    'Beauty Devices' => [],
];
$pos = 0;
foreach ($tree as $parent => $children) {
    $ps = \Illuminate\Support\Str::slug($parent);
    $p = Category::query()->updateOrCreate(['slug' => $ps], ['name' => $parent, 'parent_id' => null, 'path' => $ps, 'depth' => 0, 'position' => $pos++]);
    foreach ($children as $i => $child) {
        $cs = \Illuminate\Support\Str::slug($child);
        Category::query()->updateOrCreate(['slug' => $cs], ['name' => $child, 'parent_id' => $p->id, 'path' => $ps.'/'.$cs, 'depth' => 1, 'position' => $i]);
    }
}

foreach (['Anua', 'Axis-Y', 'Beauty of Joseon', 'BIODANCE', 'Celimax', 'COSRX', 'Dr.Althea', 'EQQUALBERRY', 'Goodal', "I'm from", 'LANEIGE', 'MEDICUBE',
    'numbuzin', 'Shiseido', 'SKIN1004', 'SOME BY MI', 'VT Cosmetics', 'Round Lab', 'Torriden', 'Isntree', 'Innisfree', 'Mixsoon', 'Haruharu Wonder',
    'Purito', 'Klairs', 'Missha', 'Etude', 'Skinfood', 'Heimish', 'Ma:nyo', 'Abib', 'Aestura', 'Illiyoon', 'Mediheal', 'Banila Co', 'Holika Holika',
    'Tocobo', 'Rom&nd', 'Peripera', 'Clio'] as $n) {
    Brand::query()->updateOrCreate(['slug' => \Illuminate\Support\Str::slug($n)], ['name' => $n]);
}

foreach (['about' => 'About us', 'faqs' => 'FAQs', 'contact-us' => 'Contact us', 'delivery' => 'Delivery', 'privacy-policy' => 'Privacy policy', 'terms-and-conditions' => 'Terms &amp; Conditions'] as $slug => $title) {
    Page::query()->updateOrCreate(['slug' => $slug], ['title' => $title, 'status' => 'published']);
}

foreach (['The 10-step Korean routine, simplified', 'Sunscreen in the UAE heat', 'Toners vs essences', 'Double cleansing 101', 'Glass skin, honestly', 'Retinol for beginners'] as $i => $t) {
    Post::query()->updateOrCreate(['slug' => \Illuminate\Support\Str::slug($t)], ['title' => $t, 'status' => 'published', 'published_at' => now()->subDays($i)]);
}
Post::query()->updateOrCreate(['slug' => 'draft-not-listed'], ['title' => 'A draft (not listed)', 'status' => 'draft']);

app(\App\Http\Controllers\Admin\MegaMenuApiController::class)->loadDemo(request());
$menu = Menu::query()->where('show_desktop', true)->firstOrFail();
MenuItem::query()->where('menu_id', $menu->id)->whereNull('parent_id')->increment('position');
$kb = MenuItem::query()->create(['menu_id' => $menu->id, 'label' => 'K-Beauty', 'url' => '/shop/', 'position' => 0, 'columns' => 2]);
MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $kb->id, 'label' => 'Shop by category', 'position' => 0]);
MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $kb->id, 'label' => 'Top brands', 'position' => 1]);
app(\App\Services\NavigationService::class)->flush();

echo "mx seed done: menu {$menu->id}, ".Category::count().' categories, '.Brand::count()." brands\n";
