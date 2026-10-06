<?php
/*
 * Lane EB preview seed. The catalogue from rf-seed, plus one product, one
 * category and one brand that say "Extra Beauty" where the live shop still
 * does after Lane BR's rename: product name, descriptions, image alt text,
 * a review, the category's and the brand's name and description. The BEFORE
 * shots are taken with this; the AFTER shots once the Lane EB migration has
 * been run against the same database (tools/eb-apply.php). Preview only.
 */
require __DIR__.'/rf-seed.php';

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$donor = Product::query()->whereNotNull('image')->orderBy('id')->first();
$cat = Category::updateOrCreate(['slug' => 'extra-beauty-picks'], ['name' => 'Extra Beauty Picks', 'depth' => 0, 'position' => 9, 'path' => 'extra-beauty-picks',
    'description' => '<p>Hand-picked by the Extra Beauty team: the serums and toners our customers reorder most.</p>']);
$brand = Brand::updateOrCreate(['slug' => 'extra-beauty-lab'], ['name' => 'Extra Beauty Lab',
    'description' => '<p>Extra Beauty Lab is the house label of Extra Beauty, made in Korea for UAE skin.</p>']);
$p = Product::updateOrCreate(['slug' => 'extra-beauty-glow-serum'], [
    'name' => 'Extra Beauty Glow Serum 30ml', 'status' => 'publish', 'type' => 'simple', 'price' => 8900, 'stock_status' => 'instock',
    'brand_id' => $brand->id, 'image' => $donor?->image,
    'short_description' => '<p>The Extra Beauty bestseller — a niacinamide glow serum.</p>',
    'description' => '<p>Formulated for Extra Beauty in Seoul. Questions? Write to info@extrabeauty.ae — invoices are issued by Extra Beauty Trading LLC.</p>',
    'image_alts' => $donor?->image ? [$donor->image => 'Extra Beauty Glow Serum bottle'] : null,
]);
$p->categories()->syncWithoutDetaching([$cat->id]);
foreach (Product::query()->where('id', '!=', $p->id)->orderBy('id')->limit(5)->pluck('id') as $id) {
    DB::table('category_product')->insertOrIgnore(['category_id' => $cat->id, 'product_id' => $id]);
}
DB::table('reviews')->insert(['product_id' => $p->id, 'author_name' => 'Mariam', 'title' => 'Love Extra Beauty', 'rating' => 5, 'status' => 'approved',
    'content' => 'Ordered from Extra Beauty twice, fast delivery.', 'reply' => 'Thank you Mariam — Extra Beauty', 'created_at' => now(), 'updated_at' => now()]);

\App\Models\Setting::flushMap();
file_put_contents(__DIR__.'/../storage/framework/testing/eb-preview/urls.json', json_encode([
    'product' => parse_url($p->url(), PHP_URL_PATH), 'category' => parse_url($cat->url(), PHP_URL_PATH), 'brand' => parse_url($brand->url(), PHP_URL_PATH),
]));
echo "eb seed done\n";
