<?php
/*
 * Seed the Lane FP preview (category Filters drawer on a phone, press feedback).
 *
 * The owner's own shelf list from his screenshot (docs/fp-owner/filters-mobile.png),
 * long enough that the drawer scrolls, with "Hydration &amp; Glow" stored the way
 * WordPress stores a term name -- `&` as `&amp;` -- which is what the importer
 * copied onto his shop. Brand "Rom&amp;nd" is the same shape in the brand list.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 *   php artisan tinker --execute="require 'tools/fp-seed.php';"   (from fp-preview.sh)
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$names = ['Pigmentation', 'Hydration &amp; Glow', 'Anti-aging', 'Medicube', 'Pore Care', 'Under AED 54',
    'Beauty Devices', 'Shampoos', 'Scalp Treatments', 'Conditioners', 'J-Beauty', 'Hair Tools',
    'Cleansers', 'Uncategorized', 'Best Sellers', 'Makeup', 'Skincare', 'Sunscreens'];

$products = Product::query()->orderBy('id')->get();
$brand = Brand::updateOrCreate(['slug' => 'romand'], ['name' => 'Rom&amp;nd']);

foreach ($names as $i => $name) {
    $slug = \Illuminate\Support\Str::slug(html_entity_decode($name));
    $cat = Category::updateOrCreate(['slug' => $slug], ['name' => $name, 'depth' => 0, 'position' => $i, 'path' => $slug]);
    $ids = $products->slice($i % max(1, $products->count()), 3 + ($i % 4))->pluck('id')->all();
    $cat->products()->syncWithoutDetaching($ids);
}

$products->take(5)->each(fn ($p) => $p->update(['brand_id' => $brand->id]));

/* The shop's rows were imported BEFORE the fix, so the repair runs over them
   exactly as it will on his server: the migration, once more, after the seed.
   Absent on main, which is the "before" picture. */
$fix = base_path('database/migrations/2027_08_19_100000_decode_imported_term_names.php');
if (is_file($fix)) {
    (require $fix)->up();
}

\App\Http\Controllers\Store\ShopController::flushSidebarCache();
echo "fp seed done: ".count($names)." categories, ".$products->count()." products\n";
