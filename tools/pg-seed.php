<?php
/*
 * Seed the Lane PG preview (Catalog -> Pagination): an owner account, two
 * categories of 30 products each -- "Serums" following the global setting, so
 * it pages, and "Toners" with pagination OFF, so it shows all 30 -- a brand,
 * and a dozen more categories and brands for the screen's picker to search.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\ListingPagination;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$cat = function (string $slug, string $name): Category {
    $c = Category::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'parent_id' => null]);
    $c->forceFill(['path' => $slug, 'depth' => 0])->save();

    return $c;
};

$brand = Brand::query()->firstOrCreate(['slug' => 'glow-lab'], ['name' => 'Glow Lab']);
$serums = $cat('serums', 'Serums');
$toners = $cat('toners', 'Toners');

foreach (['Cleansers', 'Moisturisers', 'Sunscreens', 'Masks', 'Eye Care', 'Lip Care', 'Essences', 'Ampoules', 'Mists', 'Exfoliators'] as $n) {
    $cat(strtolower(str_replace(' ', '-', $n)), $n);
}
foreach (['Cosrx', 'Anua', 'Beauty of Joseon', 'Round Lab', 'Torriden', 'Skin1004', 'Isntree', 'Medicube', 'Laneige', 'Innisfree'] as $n) {
    Brand::query()->firstOrCreate(['slug' => strtolower(str_replace(' ', '-', $n))], ['name' => $n]);
}

foreach ([[$serums, 'Serum'], [$toners, 'Toner']] as [$c, $word]) {
    for ($i = 1; $i <= 30; $i++) {
        $p = Product::query()->updateOrCreate(['slug' => strtolower($word)."-{$i}"], [
            'name' => "Glow Lab {$word} No. {$i}", 'brand_id' => $brand->id,
            'price' => 4500 + $i * 100, 'status' => 'publish', 'is_visible' => 1,
            'stock_status' => 'instock', 'type' => 'simple',
        ]);
        $p->categories()->syncWithoutDetaching([$c->id]);
    }
}

// Toners: pagination off. Serums and everything else follow the global switch.
ListingPagination::save(true, ['category' => [(string) $toners->id => 'off']]);

echo "pg seed done: serums {$serums->id}, toners {$toners->id}\n";
