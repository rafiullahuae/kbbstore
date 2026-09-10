<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo reviews, so the review section can be seen working before the real
 * WooCommerce reviews are migrated.
 *
 * Idempotent: reviews are keyed on author + product, so re-running adds nothing.
 * Every row is written with source = 'demo', so the migrator can remove them
 * all in one statement before importing the real WooCommerce reviews.
 */
class DemoReviewsSeeder extends Seeder
{
    private const AUTHORS = [
        ['Aisha M.', 5, 'Third bottle already', 'No white cast at all and it sits perfectly under makeup. I have repurchased twice.'],
        ['Fatima K.', 5, 'Skin feels calmer', 'Lightweight, no pilling, and my skin feels genuinely calmer after a few weeks of use.'],
        ['Noor S.', 4, 'Love it, wish it were bigger', 'Formula is lovely. Only wish the tube were larger for the price.'],
        ['Layla H.', 5, 'Survives Dubai summer', 'Does not turn greasy in the heat, which is rare. Genuinely impressed.'],
        ['Mariam A.', 5, 'Glass skin, finally', 'Two weeks in and the texture on my cheeks has smoothed right out.'],
        ['Hessa R.', 4, 'Good but slow', 'Works, just took longer than I expected to see a difference. Worth staying with.'],
        ['Sara T.', 5, 'Holy grail', 'I have tried a lot of essences and keep coming back to this one.'],
        ['Amira B.', 3, 'Fine, not amazing', 'Pleasant enough but I did not notice a dramatic change. Might suit drier skin better.'],
        ['Reem J.', 5, 'Fast delivery too', 'Arrived in two days and sealed. Product is the real thing.'],
        ['Dana Q.', 5, 'Bought for my sister as well', 'She liked mine so much she asked for her own. That says it all.'],
    ];

    public function run(): void
    {
        $products = Product::query()->visible()->limit(12)->get(['id']);

        if ($products->isEmpty()) {
            $this->command?->warn('No visible products — skipping demo reviews.');

            return;
        }

        $made = 0;

        foreach ($products as $i => $product) {
            // Three to six per product, varied so the distribution bars differ.
            $take = 3 + ($i % 4);

            foreach (array_slice(self::AUTHORS, $i % 5, $take) as $n => [$name, $rating, $title, $body]) {
                $exists = Review::where('product_id', $product->id)
                    ->where('author_name', $name)
                    ->exists();

                if ($exists) {
                    continue;
                }

                Review::create([
                    'product_id' => $product->id,
                    'author_name' => $name,
                    'author_email' => strtolower(str_replace([' ', '.'], '', $name)) . '@example.com',
                    'title' => $title,
                    'content' => $body,
                    'rating' => $rating,
                    'status' => 'approved',
                    'verified' => $n % 3 !== 2,
                    'helpful' => ($i * 3 + $n * 7) % 24,
                    'images' => [],
                    // The schema has no is_demo flag; `source` already exists
                    // for exactly this distinction, so the migrator can delete
                    // where source = 'demo' before importing the real reviews.
                    'source' => 'demo',
                    'created_at' => now()->subDays(($i * 5 + $n * 3) % 90),
                    'updated_at' => now()->subDays(($i * 5 + $n * 3) % 90),
                ]);

                $made++;
            }

            $this->refreshAggregate($product->id);
        }

        $this->command?->info("Demo reviews created: {$made}");
    }

    /**
     * Keep the product's cached rating and count in step.
     * One grouped query rather than loading the rows. (Rule 27)
     */
    private function refreshAggregate(int $productId): void
    {
        $agg = DB::table('reviews')
            ->where('product_id', $productId)
            ->where('status', 'approved')
            ->selectRaw('COUNT(*) c, AVG(rating) a')
            ->first();

        Product::where('id', $productId)->update([
            'review_count' => (int) ($agg->c ?? 0),
            'rating' => round((float) ($agg->a ?? 0), 2),
        ]);
    }
}
