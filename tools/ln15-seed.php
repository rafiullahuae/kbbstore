<?php
/*
 * Seed the Lane LN15 preview (tools/ln15-preview.sh): the fourteen products
 * still English on /ar on 15 November 2026 with their live English (tests/
 * Fixtures/new-products-15-nov/english.json, blank lines as the editor stores
 * them), medicube AGE-R Booster Pro with its own "Warranty" tab, three older
 * imported products whose headings sat 6 / 11px apart
 * (storage/ln15-logs/tabs.json when it is there), the brands with the Arabic
 * names the live /ar/brands/ page prints (eos has none: the migration adds it),
 * Arabic switched on, and then THIS LANE'S MIGRATION run against them, as
 * applying the package would.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

$app = base_path();
$en = json_decode((string) file_get_contents($app.'/tests/Fixtures/new-products-15-nov/english.json'), true);
$stored = fn (?string $h) => $h === null ? null : str_replace(['<div class="kbb-bl"></div>', '<p class="kbb-bl"></p>'], ['<div><br></div>', '<p><br></p>'], $h);

$brands = [
    'vt' => ['VT Cosmetics', 'vt-cosmetics', 'في تي كوزمتكس'], 'anua' => ['Anua', 'anua', 'أنوا'], 'eos' => ['eos', 'eos', null],
    'biodance' => ['Biodance', 'biodance', 'بيودانس'], 'numbuzin' => ['numbuzin', 'numbuzin', 'نومبوزين'],
    'cosrx' => ['COSRX', 'cosrx', 'كوسركس'], 'tocobo' => ['TOCOBO', 'tocobo', 'توكوبو'], 'isntree' => ['Isntree', 'isntree', 'إيزنتري'],
];
$brand = [];
foreach ($brands as $k => [$name, $slug, $ar]) {
    $brand[$k] = Brand::updateOrCreate(['slug' => $slug], ['name' => $name]);
    if ($ar !== null) {
        DB::table('translations')->updateOrInsert(['locale' => 'ar', 'group' => 'brands', 'item_id' => $brand[$k]->id, 'field' => 'name'],
            ['value' => $ar, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    }
}
$cat = Category::query()->where('slug', 'skincare-ln15')->first() ?? Category::create(['slug' => 'skincare-ln15', 'name' => 'Skincare']);

$all = $en;
$live = $app.'/storage/ln15-logs/tabs.json';
if (is_file($live)) {
    $tabs = json_decode((string) file_get_contents($live), true);
    foreach (['cosrx-low-ph-good-morning-gel-cleanser-for-sensitive-skin-150ml', 'tocobo-bio-watery-sun-cream-spf50-pa', 'isntree-hyaluronic-acid-watery-sun-gel'] as $slug) {
        if (isset($tabs[$slug])) {
            $all[$slug] = ['name' => html_entity_decode($tabs[$slug]['en_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'short_description' => $tabs[$slug]['en_short'], 'description' => $tabs[$slug]['en'][0]['body']];
        }
    }
}

foreach ($all as $slug => $p) {
    $key = explode('-', $slug)[0];
    $row = Product::updateOrCreate(['slug' => $slug], [
        'name' => $p['name'], 'brand_id' => ($brand[$key] ?? $brand['vt'])->id, 'price' => 8900, 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock', 'type' => 'simple', 'manage_stock' => false, 'category_id' => $cat->id,
        'short_description' => $stored($p['short_description']), 'description' => $stored($p['description']),
    ]);
    $row->categories()->syncWithoutDetaching([$cat->id]);
}

$ager = Product::updateOrCreate(['slug' => 'medicube-age-r-booster-pro'], [
    'name' => 'medicube - AGE-R Booster Pro', 'brand_id' => $brand['vt']->id, 'price' => 99900, 'status' => 'publish', 'is_visible' => true,
    'stock_status' => 'instock', 'type' => 'simple', 'manage_stock' => false, 'category_id' => $cat->id, 'description' => '<p>A 6-in-1 device.</p>',
]);
$warranty = json_decode((string) file_get_contents($app.'/tests/Fixtures/new-products-15-nov/english-tabs.json'), true)['medicube-age-r-booster-pro']['Warranty'];
DB::table('product_tabs')->insert(['product_id' => $ager->id, 'title' => 'Warranty', 'body' => $warranty, 'position' => 10, 'is_enabled' => true,
    'created_at' => now(), 'updated_at' => now()]);

\App\Models\Setting::query()->updateOrCreate(['key' => 'language_ar_enabled'], ['value' => '1', 'autoload' => true]);

(require $app.'/database/migrations/2028_02_15_100000_arabic_new_products_15_november.php')->up();
\App\Models\Setting::flushMap();
\App\Services\Translation\TranslationStore::flush();
echo 'seeded '.count($all)." products\n";
