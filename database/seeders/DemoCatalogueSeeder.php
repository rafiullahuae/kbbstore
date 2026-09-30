<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\WholeDirhams;
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
    /**
     * ── THE THREE COLUMNS THE DETAIL TABS ARE BUILT FROM ────── Lane PDP2 R4 ──
     *
     * The owner: *"also put some demo tabs on the product page, so i can see in
     * action."*
     *
     * He never had. App\Support\ProductTabs builds the tab row from
     * `description`, `ingredients` and `how_to_use`, drops any entry whose body
     * is empty, and falls Description back to the short description — so a
     * seeder that set ONLY short_description gave every demo product exactly
     * one tab and a strip with nothing to switch between. Demo content is off
     * by default, so ProductTabs' DemoContent top-up never ran either.
     *
     * The words live in App\Support\DemoProductDetails because the migration
     * beside this seeder writes the same three columns onto a shop that is
     * ALREADY seeded — editing here alone would change nothing the owner can
     * see. One source, two readers.
     *
     * ▲ AND THE COLUMNS ARE PROBED, WHICH IS NOT DEFENSIVE TIDYING.
     *
     * This seeder is run from a MIGRATION — 2026_08_27_100000_seed_demo_
     * catalogue — and `ingredients` and `how_to_use` are added five weeks later
     * in migration order, by 2026_10_05_000000. On a fresh install this method
     * therefore runs at a moment when two of the three columns DO NOT EXIST,
     * and writing them unguarded is not a graceful degradation, it is
     * `SQLSTATE[HY000]: table products has no column named ingredients` and a
     * migrate that stops there. Measured: three cases of
     * StorefrontEnglishUnchangedTest went red on exactly that, on the first run
     * after this was written without the probe.
     *
     * A fresh install is not left short because of it: the two columns arrive
     * empty in October and 2027_06_15_000000_backfill_demo_product_details
     * fills them with these same strings later in the same `migrate`. What the
     * probe buys is that `db:seed --class=DemoCatalogueSeeder` on a
     * fully-migrated shop writes all three at once.
     *
     * @return array<string, string>
     */
    private function details(string $name): array
    {
        return array_filter(
            \App\Support\DemoProductDetails::for($name),
            static fn (string $column): bool => \Illuminate\Support\Facades\Schema::hasColumn('products', $column),
            ARRAY_FILTER_USE_KEY
        );
    }

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
                    /*
                     * WHOLE DIRHAMS, because the rule that says so lives in a
                     * controller and no seeder goes through one. 70% of a whole
                     * dirham is not a whole dirham: five of the 24 products this
                     * seeds ship with a sale price carrying fils, on every
                     * install including production, and the shop then prints a
                     * price it does not charge. WholeDirhams::toward() rounds
                     * down, which is the direction a discount off a shelf price
                     * should go — see the class header.
                     */
                    'sale_price' => $onSale ? WholeDirhams::toward((int) round($price * 0.7)) : null,
                    'stock_status' => $i % 9 === 0 ? 'outofstock' : 'instock',
                    'short_description' => \App\Support\DemoProductDetails::SEEDED_SHORT_DESCRIPTION,
                    ...$this->details($name),
                    /*
                     * ZERO, NOT AN INVENTED FIGURE — and the reason is the one
                     * bug the owner actually reported.
                     *
                     * These two columns used to be seeded with
                     * `mt_rand(38, 50) / 10` stars over `mt_rand(4, 1400)`
                     * reviews while this seeder created NOT ONE ROW in
                     * `reviews`. The shop card printed that pair (it is what
                     * ShopController sorts `?sort=rating` and `?sort=popular`
                     * by, and what the `top_rated` shortcode selects on), and
                     * the product page printed a summary computed from the
                     * `reviews` table — so the card said "4.9 · 3,204 reviews"
                     * and the page under it said nothing. Neither number was
                     * wrong about its own source; there was no shared source.
                     *
                     * The rows are the truth now. DemoReviewsSeeder writes real
                     * reviews and App\Support\ProductRating computes this pair
                     * from the APPROVED ones, which is the same question every
                     * storefront reader asks. A product with no reviews shows
                     * no score — honest, and the column default anyway.
                     *
                     * Do not reintroduce a figure here. Anything written at
                     * this point is, by definition, not derived from a review.
                     */
                    'rating' => 0,
                    'review_count' => 0,
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
