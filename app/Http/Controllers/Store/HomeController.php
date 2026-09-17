<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Review;
use App\Models\Product;
use App\Services\DemoContent;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Home page.
 *
 * Replaces the original Laravel home page, which fetched /api/products from the
 * browser — a root-relative path that 404s under a subdirectory — and kept its
 * cart in a JavaScript array. That array is why the header badge incremented
 * while the server never saw an item.
 *
 * Everything here is server-rendered from the same models the rest of the
 * storefront uses, so the cart is the real one.
 */
class HomeController extends Controller
{
    private const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'type', 'total_sales',
    ];

    public function __construct(private SettingsService $settings) {}

    public function __invoke(): View
    {
        // Four rails, one query each, cached together: this is the most-hit URL
        // on the site and none of it changes per visitor.
        $rails = Cache::remember('kbb.home.rails', 600, function () {
            $base = fn () => Product::query()->select(self::CARD_COLUMNS)->visible()->with('brand:id,name,slug');

            $onSale = $base()
                ->whereNotNull('sale_price')
                ->whereColumn('sale_price', '<', 'price')
                ->orderByDesc('total_sales')
                ->orderByDesc('id')
                ->limit(4)
                ->get();

            /*
             * THE BEST-SELLER RAILS ARE ONE QUERY AND ONE SPLIT, NOT TWO
             * QUERIES AND AN OFFSET.
             *
             * This was:
             *
             *     'best1' => ...orderByDesc('total_sales')->limit(4)
             *     'best2' => ...orderByDesc('total_sales')->offset(4)->limit(4)
             *
             * -- two separate queries meant to partition one list, over a sort
             * key that ties constantly. `total_sales` is a counter and most of
             * this catalogue shares a handful of values; `review_count`, the
             * other candidate, is 0 nearly everywhere. Where rank 4 and rank 5
             * tie, LIMIT 4 and LIMIT 4 OFFSET 4 are each free to pick either
             * row, and nothing carries the first query's choice into the
             * second. The same product lands in both rails, and the one it
             * displaced lands in neither.
             *
             * That is not a theoretical freedom. `products_total_sales_index`
             * (2026_10_11_000000_clear_caches_storefront_speed) gives the
             * planner a choice between walking that index backwards and
             * sorting, and over a tied group those two return the tied rows in
             * OPPOSITE orders -- measured on this project's own SQLite, and
             * the same choice exists on MySQL between an index scan and a
             * filesort. Which one it picks is a costing decision, and the cost
             * of `LIMIT 4` is not the cost of `LIMIT 4 OFFSET 4`.
             *
             * Fetching eight once and slicing in PHP makes the duplicate
             * STRUCTURALLY IMPOSSIBLE rather than merely unlikely: one query
             * returns one list, and two halves of one list cannot overlap
             * however the database broke the ties inside it. It is also one
             * query instead of two on the most-hit URL on the site.
             *
             * `orderByDesc('id')` is still appended, because the split is not
             * the only thing that wants a stable answer: this block is cached
             * for ten minutes and re-run on every eviction, and a rail whose
             * membership changes on each rebuild with no data behind the
             * change is the same defect one layer up.
             */
            $best = $base()->orderByDesc('total_sales')->orderByDesc('id')->limit(8)->get();

            return [
                'recommended' => $base()->where('featured', true)
                    ->orderByDesc('total_sales')->orderByDesc('id')->limit(5)->get(),
                'best1' => $best->take(4)->values(),
                'best2' => $best->slice(4)->values(),
                // Falls back to newest when nothing is discounted, so the row is
                // never an empty gap.
                'flash' => $onSale->isNotEmpty() ? $onSale : $base()->latest('id')->limit(4)->get(),

                // Sets and routines. Falls back to the priciest products when
                // nothing is categorised as a set, so the row is never empty.
                'bundles' => (function () use ($base) {
                    $sets = $base()->whereHas('categories', fn ($c) => $c->where('slug', 'like', '%set%'))
                        ->orderByDesc('total_sales')->orderByDesc('id')->limit(8)->get();

                    return $sets->isNotEmpty()
                        ? $sets
                        : $base()->orderByDesc('price')->orderByDesc('id')->limit(8)->get();
                })(),
            ];
        });

        $brands = Cache::remember('kbb.home.brands', 900, fn () => Brand::query()
            ->select('id', 'name', 'slug')
            ->withCount(['products' => fn ($q) => $q->visible()])
            // Then by name: `products_count` ties across the long tail of
            // brands carrying one or two products, and this is a LIMIT, so a
            // tie at the twelfth place decides who is on the homepage at all.
            ->orderByDesc('products_count')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(12)
            ->get());

        // Category tiles, cached alongside the rails.
        $categories = Cache::remember('kbb.home.cats', 900, fn () => Category::query()
            ->select('id', 'name', 'slug')
            ->withCount(['products' => fn ($q) => $q->visible()])
            ->groupBy('categories.id', 'categories.name', 'categories.slug')
            ->having('products_count', '>', 0)
            // Same as the brand strip: a tie on the count at the tenth place
            // decides which tile the homepage shows.
            ->orderByDesc('products_count')
            ->orderBy('name')
            ->orderBy('categories.id')
            ->limit(10)
            ->get());

        // Journal posts, if the blog has any.
        $posts = Cache::remember('kbb.home.posts', 900, fn () => Post::query()
            ->where('status', 'published')
            // Posts imported together share a `published_at` to the second, so
            // this is a LIMIT over a tie unless `id` finishes the order.
            ->latest('published_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get());

        // Review wall: a summary, the star distribution and a few reviews.
        $reviews = Cache::remember('kbb.home.reviews', 900, function () {
            $agg = Review::query()->approved()->real()->selectRaw('COUNT(*) c, AVG(rating) a')->first();
            $total = (int) ($agg->c ?? 0);

            // One grouped query for the bars rather than five counts. (Rule 27)
            $byStar = Review::query()->approved()->real()
                ->selectRaw('rating, COUNT(*) c')
                ->groupBy('rating')
                ->pluck('c', 'rating');

            $bars = [];

            foreach ([5, 4, 3, 2, 1] as $star) {
                $bars[$star] = $total ? (int) round(((int) ($byStar[$star] ?? 0)) / $total * 100) : 0;
            }

            return [
                /*
                 * THE COLUMNS THE CARD RENDERS, NAMED.
                 *
                 * This was a bare ->get(), so every row arrived carrying
                 * `author_email` and `ip`. CLAUDE.md names those two columns
                 * specifically and ApiSecurityTest exists because each of them
                 * leaked in production; nothing on this page prints either, so
                 * the widest thing the wall could do with them was hand them to
                 * a template and hope. An explicit list also means a column
                 * added to `reviews` later is private here until someone
                 * decides otherwise — the same rule Product::toApi() follows.
                 *
                 * `id` and `product_id` are in the list because they are load
                 * bearing rather than printed: product_id is what the eager
                 * load on the line below matches on, and dropping it leaves
                 * every card without its product name.
                 *
                 * Store\ProductController's review list has named its columns
                 * this way since it was written. This is the same list, minus
                 * the three the home card has no markup for.
                 */
                'items' => Review::query()->approved()->real()
                    ->select(['id', 'product_id', 'author_name', 'rating', 'content',
                        'verified', 'helpful', 'created_at'])
                    ->whereNotNull('content')
                    ->with('product:id,name,slug')
                    // ReviewWall does the same, for the same reason: without
                    // `id` this LIMIT is taken over a tie on created_at.
                    ->latest('created_at')
                    ->orderByDesc('id')
                    ->limit(4)
                    ->get(),
                'total' => $total,
                'average' => round((float) ($agg->a ?? 0), 1),
                'bars' => $bars,
            ];
        });

        // Hero banners. Editable later through the Banners module; these are the
        // defaults so the slider is never empty on a fresh install.
        $banners = (array) ($this->settings->get('home_banners') ?: [
            ['kicker' => 'Medicube · limited-time offer', 'heading' => 'Age-R Booster Pro<br>with a free gift set',
             'text' => 'The device everyone is asking about, bundled with the PDRN glow set.',
             'button' => 'Shop Medicube', 'url' => '/shop/',
             'gradient' => 'linear-gradient(118deg,#F7C6D4,#E0567B 58%,#A82F53)',
             'panel' => 'linear-gradient(150deg,#FFE6EE,#EFA8BE)'],
            ['kicker' => '93 brands · sourced direct', 'heading' => 'The authentic<br>K-beauty store',
             'text' => 'Every product original, every order checked. Freebies with every parcel.',
             'button' => 'Shop all brands', 'url' => '/brands/',
             'gradient' => 'linear-gradient(118deg,#CDE6DA,#3E8F6E 58%,#2A6A50)',
             'panel' => 'linear-gradient(150deg,#E4F3EC,#9FCBB6)'],
            ['kicker' => 'Tabby · Tamara · COD', 'heading' => 'Pay later,<br>delivered in 1–3 days',
             'text' => 'Split any order into four. Free delivery across the UAE over AED 199.',
             'button' => 'Shop the Super Sale', 'url' => '/shop/?on_sale=1',
             'gradient' => 'linear-gradient(118deg,#FFE1A8,#E8A33D 58%,#C07F1E)',
             'panel' => 'linear-gradient(150deg,#FFF2D9,#EFCE8A)'],
        ]);

        /*
         * Routine steps, each with a real product suggestion from its category.
         *
         * EACH STEP NAMES CANDIDATE SLUGS, NOT ONE SLUG, and the first that
         * exists wins. Three of the six steps hard-coded a slug no category has
         * ever carried — verified on a migrated database, not inferred:
         *
         *   step 01 / 02  'cleansing'     -> /product-category/cleansing/    404
         *   step 05       'moisturizers'  -> /product-category/moisturizers/ 404
         *
         * Both halves of the step were broken by it, and the second half hid
         * the first. The link 404'd, AND `whereHas` matched nothing, so those
         * steps rendered with no product and no price — which looks like an
         * empty catalogue rather than a wrong slug, so nobody went looking.
         *
         * A candidate list rather than a corrected single slug because this is
         * evaluated per request, not baked in: the shop's real taxonomy arrives
         * by WordPress import carrying the live site's slugs
         * (`cleansing-oils`, `face-serums`, `moisturizers`), while a shop that
         * has not imported yet has only DemoCatalogueSeeder's six placeholders
         * (`cleansers`, `serums`, `moisturisers`). The live slug is listed
         * first, so the import silently upgrades each step the moment it lands
         * and nobody has to come back and change this.
         *
         * A step whose categories all turn out to be absent links to /shop/
         * rather than to a category archive that 404s.
         */
        $routine = Cache::remember('kbb.home.routine', 900, function () {
            $steps = [
                ['n' => '01', 'title' => 'Oil cleanser',   'note' => 'Melts SPF and makeup',   'slugs' => ['cleansing-oils', 'cleansers', 'cleansing']],
                ['n' => '02', 'title' => 'Water cleanser', 'note' => 'The second cleanse',     'slugs' => ['face-washes', 'cleansers', 'cleansing']],
                ['n' => '03', 'title' => 'Toner',          'note' => 'Hydrates and preps',     'slugs' => ['toners']],
                ['n' => '04', 'title' => 'Serum',          'note' => 'Where the actives work', 'slugs' => ['face-serums', 'serums']],
                ['n' => '05', 'title' => 'Moisturiser',    'note' => 'Seals it all in',        'slugs' => ['moisturizers', 'moisturisers']],
                ['n' => '06', 'title' => 'Sunscreen',      'note' => 'Every single morning',   'slugs' => ['sunscreens']],
            ];

            // One query for every slug any step might want, rather than one per
            // candidate: six steps with three candidates each would otherwise
            // be up to eighteen lookups on the busiest page on the site.
            $live = Category::query()
                ->whereIn('slug', array_unique(array_merge(...array_column($steps, 'slugs'))))
                ->pluck('slug')
                ->all();

            foreach ($steps as $i => $step) {
                $slug = null;

                foreach ($step['slugs'] as $candidate) {
                    if (in_array($candidate, $live, true)) {
                        $slug = $candidate;
                        break;
                    }
                }

                $steps[$i]['slug'] = $slug;
                unset($steps[$i]['slugs']);

                $steps[$i]['pick'] = $slug === null ? null : Product::query()->select(self::CARD_COLUMNS)->visible()
                    ->with('brand:id,name,slug')
                    ->whereHas('categories', fn ($c) => $c->where('slug', $slug))
                    // first() is LIMIT 1, so the whole step is decided by how
                    // a tie on total_sales happens to break.
                    ->orderByDesc('total_sales')
                    ->orderByDesc('id')
                    ->first();
            }

            return $steps;
        });

        /*
         * The link is derived OUTSIDE the cache, from the slug inside it. That
         * is what makes this change safe to deploy: an entry written by the
         * previous version of this method holds `slug` and no `url`, and
         * reading it here gives a working array rather than an undefined-key
         * error on the busiest page on the site for the rest of its 15-minute
         * life. The accompanying clear_caches migration forgets the key
         * outright, so that window is normally zero.
         *
         * URL Contract U-03. A step whose category is absent points at the shop
         * rather than at an archive that is known to 404.
         */
        foreach ($routine as $i => $step) {
            $slug = $step['slug'] ?? null;
            $routine[$i]['url'] = $slug === null ? '/shop/' : '/product-category/' . $slug . '/';
        }

        $routineTotal = collect($routine)->sum(fn ($s) => $s['pick']?->effectivePrice() ?? 0);

        // Totals used in the copy, so the page never states a made-up number.
        $catalogueCount = Cache::remember('kbb.home.count', 900, fn () => Product::query()->visible()->count());
        $brandTotal = Cache::remember('kbb.home.brandcount', 900, fn () => Brand::query()->count());

        /*
         * Demo content fills empty SECTIONS so the layout can be seen before
         * the catalogue is written. It does not supply a single FIGURE.
         *
         * WHAT WAS HERE, AND WHY IT IS GONE. Three of the statements in this
         * block invented numbers and printed them to shoppers and to crawlers:
         *
         *   - `$demo->reviewSummary()` returned 12,481 reviews at 4.8 stars.
         *     home.blade.php prints that as "12.5k+ verified reviews" in the
         *     trust strip and as a full star-distribution wall headed "12,481
         *     verified reviews from real orders." Not one of them existed.
         *     Alongside it `$demo->reviews()` supplied four invented customers,
         *     each carrying `verified = true` — the same defect, and the same
         *     four people, that 2.60.192 has just finished removing from
         *     /reviews.
         *   - `max($catalogueCount, 671)` and `max($brandTotal, 93)` overstated
         *     the size of the shop itself, in the "products stocked" and
         *     "Korean brands" counters and in the shop-filter summary.
         *
         * The review wall now simply shows what the `reviews` table holds. Its
         * section is already wrapped in `@if ($reviews['total'] > 0)`, so a
         * shop with no reviews renders no wall at all rather than a zeroed one
         * — an honest empty state, not a broken layout. The counters print the
         * real COUNT(*) they were always computed from.
         *
         * The remaining substitutions are stand-in CARDS, not claims: they
         * carry no rating, no review count and no product tally (see
         * DemoContent), and every one of them links to /shop/.
         */
        $demo = app(DemoContent::class);

        if ($demo->enabled()) {
            foreach (['bundles' => 8, 'recommended' => 4, 'best1' => 4, 'flash' => 4] as $key => $want) {
                $rails[$key] = $demo->fill($rails[$key], 'products', $want);
            }

            $categories = $demo->fill($categories, 'categories', 8);
            $brands = $demo->fill($brands, 'brands', 12);
            $posts = $demo->fill($posts, 'posts', 3);
        }

        /*
         * ONE QUERY FOR THE JOURNAL STRIP'S LONG PROSE, NOT ONE PER TILE.
         *
         * The tiles print t('excerpt') ?: t('body'), and `body` is one of
         * TranslationStore::LONG_FIELDS — deliberately not carried in the map
         * that every Arabic page loads. Without this, three articles with no
         * excerpt would be three queries; with it they are one, and on an
         * English page it is none at all because primeTranslations() returns
         * immediately for the default locale.
         *
         * Called after the demo top-up, so the stand-in rows are covered too.
         */
        \App\Models\Post::primeTranslations($posts);

        return view('store.home', [
            // Section visibility, order and grid skins.
            'sections' => app(HomepageSections::class),
            'settings' => $this->settings,
            'rails' => $rails,
            'brands' => $brands,
            'categories' => $categories,
            'posts' => $posts,
            'reviews' => $reviews,
            'banners' => $banners,
            'routine' => $routine,
            'routineTotal' => $routineTotal,
            'catalogueCount' => $catalogueCount,
            'brandTotal' => $brandTotal,
            'demoOn' => $demo->enabled(),
        ]);
    }

    /** Call after a catalogue change so the rails do not sit stale for 10 minutes. */
    public static function flushCache(): void
    {
        foreach (['kbb.home.cats', 'kbb.home.posts', 'kbb.home.reviews',
                  'kbb.home.routine', 'kbb.home.count', 'kbb.home.brandcount'] as $key) {
            Cache::forget($key);
        }

        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');
    }
}
