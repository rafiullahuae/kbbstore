<?php

declare(strict_types=1);

use App\Support\ProductRating;
use Database\Seeders\DemoReviewsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put real reviews behind the demo catalogue's star ratings, and retire the
 * invented ones that are already on the server.
 *
 * WHY A MIGRATION AND NOT JUST A SEEDER. This host has no shell. CLAUDE.md is
 * explicit: code reaches the server as a signed zip applied through Store →
 * Core Updates, and the updater runs migrations. A change confined to
 * DatabaseSeeder — which nobody here can invoke — fixes a fresh install and
 * leaves the live site exactly as the owner found it. The placeholder catalogue
 * itself shipped this way, in 2026_08_27_100000_seed_demo_catalogue.php; this is
 * the matching half for its reviews.
 *
 * WHAT WAS WRONG ON THE SERVER. DemoCatalogueSeeder wrote `products.rating` and
 * `products.review_count` from mt_rand() and created no rows in `reviews` at
 * all. Those two columns are what the shop cards print and what `?sort=rating`,
 * `?sort=popular` and the `top_rated` shortcode order by; the product page
 * computes its own summary from the `reviews` table instead. So a card
 * advertised "4.9 · 3,204 reviews" over a page that had none. Fixing the seeder
 * cannot help rows already written — firstOrCreate never updates — so the
 * invented pair has to be overwritten here.
 *
 * TWO STEPS, AND ONLY ONE OF THEM IS CONDITIONAL.
 *
 *  1. SEED THE REVIEWS — only when demo content is switched on.
 *
 *     These rows ARE demo content: the same four the homepage has been drawing
 *     out of App\Services\DemoContent::reviews(), plus a pool behind them. A
 *     store that has turned demo content OFF has said it does not want invented
 *     content on the storefront, and an update package that answered by writing
 *     invented reviews into its `reviews` table — where they appear on public
 *     product pages and feed the schema.org aggregateRating published to Google
 *     — would be doing real damage in the name of a preview aid. So the switch
 *     the owner already has is honoured.
 *
 *     LIMITATION, STATED PLAINLY: turning demo content on LATER does not
 *     retro-run this migration. The rows arrive with the next package that
 *     seeds them, or with DatabaseSeeder on a fresh install.
 *
 *  2. RETIRE THE INVENTED RATINGS — always.
 *
 *     A figure with no review behind it is wrong whether or not demo content is
 *     on, and it is wrong in the place a shopper is most likely to believe it.
 *     ProductRating::refresh() writes 0/0 for a product with no approved
 *     reviews, which is what removes them. Run after the seeding step so a
 *     product that just received reviews is scored from them rather than zeroed.
 *
 * WHY THIS MAY ZERO PRODUCTS WHERE 2026_10_04_000000_normalise_review_statuses
 * DELIBERATELY WOULD NOT. That migration recomputes only products that HAVE
 * review rows, and says why: a product with none may carry a legitimate rating
 * imported from WooCommerce, which keeps its own rating meta, and zeroing it
 * would be a migration destroying data it does not own. That caution is right,
 * and it does not reach here, because every statement below is scoped to
 * `wc_id IS NULL`. A row with no wc_id was never imported from anywhere — it is
 * a placeholder this repo's own seeder invented — so there is no imported figure
 * to protect, and no real product is touched.
 *
 * NO SCHEMA CHANGE, and in particular no ->after() (see
 * tests/Feature/MigrationConventionTest.php). Everything `reviews` needs has
 * been on the table since 0001_01_01_000000_create_kbb_schema.php.
 */
return new class extends Migration
{
    /** Products per round trip while recomputing. A shared host is not a report server. */
    private const CHUNK = 200;

    public function up(): void
    {
        if (! Schema::hasTable('reviews') || ! Schema::hasTable('products')) {
            return;
        }

        if ($this->demoContentIsOn()) {
            /*
             * Idempotent on (source = 'demo', product_id, author_name), so a
             * package re-applied — which is how 2.60.102-.106 went wrong — adds
             * nothing and overwrites nothing.
             */
            (new DemoReviewsSeeder())->run();
        }

        $this->retireInventedRatings();

        /*
         * Both caches, because both read what just changed.
         *
         * HomeController::flushCache() covers 'kbb.home.reviews' — the shop-wide
         * review wall, which now has real rows to draw — and 'kbb.home.rails',
         * which prints the star line on every card from the pair this migration
         * just rewrote. Without it the homepage shows the old invented numbers
         * for up to fifteen minutes after the package lands, which is
         * indistinguishable from the fix not working.
         *
         * ShopController::flushSidebarCache() is the same reasoning the demo
         * catalogue migration used for its brand and category counts.
         */
        \App\Http\Controllers\Store\HomeController::flushCache();
        \App\Http\Controllers\Store\ShopController::flushSidebarCache();
    }

    /**
     * Roll back the DEMO REVIEWS ONLY, then put the columns back in step.
     *
     * Scoped to `source = 'demo'`, which is the single statement the seeder's
     * header promises. A real review — a shopper's, or one imported from
     * WooCommerce — carries a different source and survives this untouched.
     */
    public function down(): void
    {
        if (! Schema::hasTable('reviews') || ! Schema::hasTable('products')) {
            return;
        }

        $affected = DB::table('reviews')
            ->where('source', DemoReviewsSeeder::SOURCE)
            ->pluck('product_id')
            ->all();

        DB::table('reviews')
            ->where('source', DemoReviewsSeeder::SOURCE)
            ->delete();

        ProductRating::refresh($affected);

        \App\Http\Controllers\Store\HomeController::flushCache();
    }

    /**
     * Is the storefront's demo content switch on?
     *
     * Read straight off the table rather than through SettingsService. That
     * service holds a forever-cache AND a per-process static memo, and a
     * migration runs in a long-lived console process alongside every other
     * migration — exactly the case CLAUDE.md names as a trap. The raw row is
     * the only reading that cannot be stale here.
     *
     * Absent or unparseable means OFF, which is also the default
     * App\Services\DemoContent::enabled() applies.
     */
    private function demoContentIsOn(): bool
    {
        if (! Schema::hasTable('settings')) {
            return false;
        }

        $raw = DB::table('settings')->where('key', 'demo_content')->value('value');

        if ($raw === null) {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Recompute `rating` and `review_count` from approved rows for every
     * placeholder product — the ones with no wc_id.
     *
     * Chunked by id with an explicit order. The updates this issues do not
     * change the ids it is paging over, so nothing is skipped or seen twice.
     */
    private function retireInventedRatings(): void
    {
        DB::table('products')
            ->select('id')
            ->whereNull('wc_id')
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($rows) {
                ProductRating::refresh($rows->pluck('id')->all());
            });
    }
};
