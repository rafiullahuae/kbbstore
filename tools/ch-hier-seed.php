<?php
/*
 * Seed the Lane CH (hierarchy) preview: an owner account and a FLAT category
 * set, the way the import left the live shop -- every category at the top
 * level. tools/ch-hier-fixture.json is "kbeautybliss.com"'s tree, which the
 * preview's front controller serves through Http::fake. "Gift Sets" is here
 * but not on the source; "Masks & Packs" and "Hair Care" are there but not
 * here; "Cushions" matches by NAME because its slug differs.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

// The demo placeholders every install seeds; the live shop has the real set.
DB::table('category_product')->delete();
Category::query()->delete();

$pos = 0;
$cats = [];
foreach ([
    'skincare' => 'Skincare', 'cleansers' => 'Cleansers', 'oil-cleansers' => 'Oil Cleansers',
    'foam-cleansers' => 'Foam Cleansers', 'toners' => 'Toners & Mists', 'serums-ampoules' => 'Serums & Ampoules',
    'moisturizers' => 'Moisturizers', 'sun-care' => 'Sun Care', 'makeup' => 'Makeup', 'lips' => 'Lips',
    'cushions' => 'Cushions', 'hair-body' => 'Hair & Body', 'body-care' => 'Body Care', 'gift-sets' => 'Gift Sets',
] as $slug => $name) {
    $c = Category::query()->create(['slug' => $slug, 'name' => $name, 'path' => $slug, 'depth' => 0, 'position' => $pos++]);
    $cats[$slug] = $c;
}
// What 2027_09_01_100000_add_category_short_url does to a flat live shop.
DB::table('categories')->update(['short_url' => true]);

$brand = Brand::query()->firstOrCreate(['slug' => 'glow-lab'], ['name' => 'Glow Lab']);
foreach (['oil-cleansers' => 'Cleansing Oil', 'toners' => 'Toner', 'skincare' => 'Essence'] as $slug => $word) {
    for ($i = 1; $i <= 4; $i++) {
        $p = Product::query()->updateOrCreate(['slug' => "ch-{$slug}-{$i}"], [
            'name' => "Glow Lab {$word} No. {$i}", 'brand_id' => $brand->id,
            'price' => 4500 + $i * 100, 'status' => 'publish', 'is_visible' => 1,
            'stock_status' => 'instock', 'type' => 'simple',
        ]);
        $p->categories()->syncWithoutDetaching([$cats[$slug]->id, $cats['skincare']->id]);
    }
}

echo 'ch hierarchy seed done: '.count($cats)." flat categories\n";

// The visible trail is OFF by default on the shop (Appearance → Header →
// Breadcrumbs); switched on in this preview only, so the pictures can show it.
// The BreadcrumbList JSON-LD carries the parents either way.
app(\App\Services\HeaderSettings::class)->save(['bc_desktop' => true, 'bc_mobile' => true]);
