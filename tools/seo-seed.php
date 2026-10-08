<?php

declare(strict_types=1);

/*
 * Lane SEO: the measurement shop. The demo catalogue (db:seed) plus SEO_N extra
 * products shaped like the WooCommerce import -- pictures at
 * /wp-content/uploads/YYYY/MM/<name>.jpg, a gallery of three, a brand, a nested
 * category, a GTIN on every other one, variants on every fifth -- so the feed,
 * the sitemap and the product page are measured on the shapes the live
 * catalogue has. Never against a real database.
 *
 *   SEO_N=40 php artisan tinker --execute="require 'tools/seo-seed.php';"
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

$n = max(0, (int) (getenv('SEO_N') ?: 40));
$root = rtrim((string) public_path(), '/');

$brand = Brand::query()->firstOrCreate(['slug' => 'seo-lab'], ['name' => 'SEO Lab']);
$parent = Category::query()->firstOrCreate(['slug' => 'seo-skincare'], ['name' => 'Skincare']);
$child = Category::query()->firstOrCreate(['slug' => 'seo-toners'], ['name' => 'Toners', 'parent_id' => $parent->id]);

$draw = static function (string $relative) use ($root): void {
    $file = $root.'/'.$relative;
    if (is_file($file)) {
        return;
    }
    @mkdir(dirname($file), 0775, true);
    $img = imagecreatetruecolor(60, 60);
    imagefilledrectangle($img, 0, 0, 60, 60, imagecolorallocate($img, 200, 120, 150));
    imagejpeg($img, $file, 70);
    imagedestroy($img);
};

for ($i = 1; $i <= $n; $i++) {
    $slug = 'seo-product-'.$i;
    $main = 'wp-content/uploads/2021/05/seo-product-'.$i.'.jpg';
    $gallery = [$main, 'wp-content/uploads/2021/05/seo-product-'.$i.'-2.jpg', 'wp-content/uploads/2021/05/seo-product-'.$i.'-3.jpg'];
    foreach ($gallery as $g) {
        $draw($g);
    }

    $p = Product::query()->updateOrCreate(['slug' => $slug], [
        'name' => 'Seo Lab Toner '.$i,
        'status' => 'publish',
        'is_visible' => true,
        'type' => $i % 5 === 0 ? 'variable' : 'simple',
        'price' => 9900 + $i,
        'sale_price' => $i % 3 === 0 ? 7900 : null,
        'stock_status' => $i % 7 === 0 ? 'outofstock' : 'instock',
        'short_description' => 'A gentle hydrating toner, number '.$i.'.',
        'description' => '<p>A gentle hydrating toner with <b>centella</b>, number '.$i.'.</p>',
        'image' => '/'.$main,
        'images' => array_map(static fn ($g) => '/'.$g, $gallery),
        'sku' => 'SEO-'.$i,
        'gtin' => $i % 2 === 0 ? '8809598450431' : null,
        'brand_id' => $brand->id,
        'category_id' => $child->id,
    ]);
    $p->categories()->syncWithoutDetaching([$child->id]);

    if ($i % 5 === 0 && ! $p->variants()->exists()) {
        foreach ([['30ml', 9900], ['100ml', 15900]] as $k => [$label, $price]) {
            ProductVariant::query()->create([
                'product_id' => $p->id, 'sku' => 'SEO-'.$i.'-'.$label, 'price' => $price,
                'stock_status' => 'instock', 'position' => $k,
            ]);
        }
    }
}

DB::table('settings')->updateOrInsert(['key' => 'site_url'], ['value' => (string) config('app.url')]);
\App\Models\Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();
echo "seeded $n SEO products\n";
