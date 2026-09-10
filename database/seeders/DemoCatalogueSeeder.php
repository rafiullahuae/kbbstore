<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Placeholder catalogue so the shop can be seen and tested before the WordPress
 * migration runs. Real brands and categories from the live store, invented
 * products.
 *
 * Every row carries wc_id = null, so the Migrator — which upserts on wc_id —
 * will never mistake these for real records. Remove with:
 *
 *   php artisan db:seed --class=Database\Seeders\DemoCatalogueSeeder --force
 *   (or simply delete rows where wc_id is null)
 */
class DemoCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $categories = ['Cleansers', 'Toners', 'Serums', 'Moisturisers', 'Sunscreens', 'Masks'];
        $brands = ['Beauty of Joseon', 'COSRX', 'Anua', 'Medicube', 'Round Lab', 'Torriden', 'Isntree', 'SKIN1004'];

        foreach ($categories as $i => $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'position' => $i, 'depth' => 0, 'path' => Str::slug($name)]
            );
        }

        foreach ($brands as $i => $name) {
            Brand::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'position' => $i]);
        }

        $names = [
            'Relief Sun Rice + Probiotics SPF50+', 'Glow Deep Serum Rice + Alpha Arbutin',
            'Advanced Snail 96 Mucin Power Essence', 'Low pH Good Morning Gel Cleanser',
            'Heartleaf 77% Soothing Toner', 'Peach Niacinamide 30% Serum',
            'Age-R Booster Pro Device', 'Collagen Night Wrapping Mask',
            'Birch Juice Moisturizing Sunscreen', '1025 Dokdo Toner',
            'Dive-In Low Molecular Hyaluronic Acid Serum', 'Cellmazing Fit Serum',
            'Hyaluronic Acid Watery Sun Gel', 'Green Tea Fresh Toner',
            'Madagascar Centella Ampoule', 'Poremizing Clear Toner',
            'Ginseng Essence Water', 'Rice Probiotics Toner',
            'Zinc Sunscreen SPF50+', 'Barrier Repair Cream',
            'Vitamin C Brightening Serum', 'Gentle Foaming Cleanser',
            'Overnight Sleeping Mask', 'Ceramide Daily Moisturiser',
        ];

        foreach ($names as $i => $name) {
            $brand = Brand::where('slug', Str::slug($brands[$i % count($brands)]))->first();
            $category = Category::where('slug', Str::slug($categories[$i % count($categories)]))->first();

            $price = (int) ((mt_rand(35, 220)) * 100);
            $onSale = $i % 4 === 0;

            $product = Product::firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'wc_id' => null,
                    'name' => $name,
                    'sku' => 'DEMO-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'brand_id' => $brand?->id,
                    'category_id' => $category?->id,
                    'type' => 'simple',
                    'status' => 'publish',
                    'is_visible' => true,
                    'price' => $price,
                    'sale_price' => $onSale ? (int) round($price * 0.7) : null,
                    'stock_status' => $i % 9 === 0 ? 'outofstock' : 'instock',
                    'short_description' => 'Demo product for layout testing. Replaced by the WordPress migration.',
                    'rating' => $i % 5 === 0 ? 0 : round(mt_rand(38, 50) / 10, 1),
                    'review_count' => $i % 5 === 0 ? 0 : mt_rand(4, 1400),
                    'total_sales' => mt_rand(0, 900),
                    'featured' => $i % 6 === 0,
                    'position' => $i,
                ]
            );

            if ($category) {
                $product->categories()->syncWithoutDetaching([$category->id]);
            }
        }

        $this->command?->info('Demo catalogue: ' . count($names) . ' products across '
            . count($brands) . ' brands and ' . count($categories) . ' categories.');
    }
}
