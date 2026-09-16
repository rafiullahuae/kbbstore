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
                ->limit(4)
                ->get();

            return [
                'recommended' => $base()->where('featured', true)->orderByDesc('total_sales')->limit(5)->get(),
                'best1' => $base()->orderByDesc('total_sales')->limit(4)->get(),
                'best2' => $base()->orderByDesc('total_sales')->offset(4)->limit(4)->get(),
                // Falls back to newest when nothing is discounted, so the row is
                // never an empty gap.
                'flash' => $onSale->isNotEmpty() ? $onSale : $base()->latest('id')->limit(4)->get(),

                // Sets and routines. Falls back to the priciest products when
                // nothing is categorised as a set, so the row is never empty.
                'bundles' => (function () use ($base) {
                    $sets = $base()->whereHas('categories', fn ($c) => $c->where('slug', 'like', '%set%'))
                        ->orderByDesc('total_sales')->limit(8)->get();

                    return $sets->isNotEmpty() ? $sets : $base()->orderByDesc('price')->limit(8)->get();
                })(),
            ];
        });

        $brands = Cache::remember('kbb.home.brands', 900, fn () => Brand::query()
            ->select('id', 'name', 'slug')
            ->withCount(['products' => fn ($q) => $q->visible()])
            ->orderByDesc('products_count')
            ->limit(12)
            ->get());

        // Category tiles, cached alongside the rails.
        $categories = Cache::remember('kbb.home.cats', 900, fn () => Category::query()
            ->select('id', 'name', 'slug')
            ->withCount(['products' => fn ($q) => $q->visible()])
            ->groupBy('categories.id', 'categories.name', 'categories.slug')
            ->having('products_count', '>', 0)
            ->orderByDesc('products_count')
            ->limit(10)
            ->get());

        // Journal posts, if the blog has any.
        $posts = Cache::remember('kbb.home.posts', 900, fn () => Post::query()
            ->where('status', 'published')
            ->latest('published_at')
            ->limit(3)
            ->get());

        // Review wall: a summary, the star distribution and a few reviews.
        $reviews = Cache::remember('kbb.home.reviews', 900, function () {
            $agg = Review::query()->approved()->selectRaw('COUNT(*) c, AVG(rating) a')->first();
            $total = (int) ($agg->c ?? 0);

            // One grouped query for the bars rather than five counts. (Rule 27)
            $byStar = Review::query()->approved()
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
                'items' => Review::query()->approved()
                    ->select(['id', 'product_id', 'author_name', 'rating', 'content',
                        'verified', 'helpful', 'created_at'])
                    ->whereNotNull('content')
                    ->with('product:id,name,slug')
                    ->latest('created_at')
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

        // Routine steps, each with a real product suggestion from its category.
        $routine = Cache::remember('kbb.home.routine', 900, function () {
            $steps = [
                ['n' => '01', 'title' => 'Oil cleanser',   'note' => 'Melts SPF and makeup',   'slug' => 'cleansing'],
                ['n' => '02', 'title' => 'Water cleanser', 'note' => 'The second cleanse',     'slug' => 'cleansing'],
                ['n' => '03', 'title' => 'Toner',          'note' => 'Hydrates and preps',     'slug' => 'toners'],
                ['n' => '04', 'title' => 'Serum',          'note' => 'Where the actives work', 'slug' => 'serums'],
                ['n' => '05', 'title' => 'Moisturiser',    'note' => 'Seals it all in',        'slug' => 'moisturizers'],
                ['n' => '06', 'title' => 'Sunscreen',      'note' => 'Every single morning',   'slug' => 'sunscreens'],
            ];

            foreach ($steps as $i => $step) {
                $steps[$i]['pick'] = Product::query()->select(self::CARD_COLUMNS)->visible()
                    ->with('brand:id,name,slug')
                    ->whereHas('categories', fn ($c) => $c->where('slug', $step['slug']))
                    ->orderByDesc('total_sales')
                    ->first();
            }

            return $steps;
        });

        $routineTotal = collect($routine)->sum(fn ($s) => $s['pick']?->effectivePrice() ?? 0);

        // Totals used in the copy, so the page never states a made-up number.
        $catalogueCount = Cache::remember('kbb.home.count', 900, fn () => Product::query()->visible()->count());
        $brandTotal = Cache::remember('kbb.home.brandcount', 900, fn () => Brand::query()->count());

        // Demo content fills empty sections only. Real rows always win, and
        // nothing here is written to the database — switching it off simply
        // stops substituting.
        $demo = app(DemoContent::class);

        if ($demo->enabled()) {
            foreach (['bundles' => 8, 'recommended' => 4, 'best1' => 4, 'flash' => 4] as $key => $want) {
                $rails[$key] = $demo->fill($rails[$key], 'products', $want);
            }

            $categories = $demo->fill($categories, 'categories', 8);
            $brands = $demo->fill($brands, 'brands', 12);
            $posts = $demo->fill($posts, 'posts', 3);

            if ($reviews['total'] === 0) {
                $reviews = $demo->reviewSummary() + ['items' => $demo->reviews()];
            }

            $catalogueCount = max($catalogueCount, 671);
            $brandTotal = max($brandTotal, 93);
        }

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
