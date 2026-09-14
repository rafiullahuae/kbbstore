<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Services\DemoContent;
use App\Services\ProductSections;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The product page.
 *
 * Rule 27 shows up in three places: the review summary is one grouped query
 * rather than every row counted in PHP, related products use a narrow SELECT,
 * and recently-viewed is a cookie of ids so it costs no query on a page view.
 */
class ProductController extends Controller
{
    private const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'type',
    ];

    public function __construct(private SettingsService $settings) {}

    public function show(Request $request, string $slug): View
    {
        $product = Product::query()
            ->visible()
            ->with([
                'brand:id,name,slug',
                'categories:id,name,slug,path',
                'variants' => fn ($q) => $q->orderBy('position'),
                'variants.attributeValues:id,attribute_id,name,slug',
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        $this->rememberViewed($request, $product->id);

        $summary = $this->reviewSummary($product->id);

        $reviews = Review::query()
            ->select('id', 'author_name', 'rating', 'title', 'content', 'verified', 'created_at', 'images', 'helpful')
            ->where('product_id', $product->id)
            ->approved()
            ->latest()
            // Not truly unlimited — reviews.blade.php's "Load more" button is
            // a client-side reveal of rows already sent, not an AJAX fetch,
            // so whatever isn't in this query is permanently unreachable no
            // matter how many times it's clicked. Confirmed directly: a
            // product with 50 real reviews only ever showed 20, the "50
            // reviews" summary count sitting right above it visibly larger
            // than what a shopper could actually reach. 200 is a deliberate,
            // generous ceiling for a real catalogue, not the old accidental
            // one — a product genuinely exceeding it is an edge case worth
            // revisiting with real pagination, not the common case this
            // needs to handle today.
            ->limit(200)
            ->get();

        // Demo reviews only when the product genuinely has none. A product with
        // real reviews is never padded — that would misrepresent it.
        $demo = app(DemoContent::class);

        if ($demo->enabled() && $reviews->isEmpty()) {
            $fixture = $demo->productReviews();
            $reviews = $fixture['items'];
            $summary = $fixture['summary'];
        }

        return view('store.product', [
            'product' => $product,
            'gallery' => $this->gallery($product),
            'summary' => $summary,
            'reviews' => $reviews,
            'related' => $this->related($product),
            'settings' => $this->settings,
            'cutoff' => $this->cutoff(),
            'bundles' => app(\App\Services\BundleService::class)->forProduct($product),
            'tabs' => $this->tabs($product),
            'modules' => app(ProductSections::class),
            // Inlined rather than looked up in the build manifest: a missing
            // entry throws, and that took this page down for hours.
            'reviewsCss' => $this->reviewsCss(),
            'bundle' => $this->bundle($product),
            'vatLine' => $this->vatLine(),
            // $summary is built at the top of this method but was never
            // imported here, so the two review lines below referenced a
            // variable that does not exist inside the closure. PHP 8 raises
            // a warning, Laravel promotes it to an ErrorException, and every
            // product page returned 500.
            'seoCtx' => (function () use ($product, $summary) {
                $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');
                // Per-product SEO overrides — the `seo` json column already
                // existed on this table, commented "Yoast import target"
                // from the original schema, but nothing had ever read from
                // it: not the Yoast importer (not built yet), not this
                // controller. Wiring it in now means the storage is
                // immediately useful the moment anything writes to it —
                // by hand, by a future importer, or by the eventual
                // per-product editor — rather than sitting inert until an
                // editor UI exists to justify reading it.
                $override = is_array($product->seo) ? $product->seo : [];

                $ctx = [
                    'type' => 'product',
                    'description' => $override['desc'] ?? $product->short_description ?? null,
                    'image' => $override['og_image'] ?? $product->image,
                    'url' => !empty($override['canonical']) ? $override['canonical'] : ($base . $product->url()),
                    'breadcrumb' => $this->breadcrumbTrail($product),
                    'noindex' => !empty($override['noindex']),
                    'product' => [
                        'name' => $product->name,
                        'brand' => $product->brand?->name,
                        'sku' => $product->sku,
                        'price_aed' => \App\Support\Money::toAed($product->effectivePrice()),
                        'stock' => $product->stock_status === 'instock' ? 1 : 0,
                        'sale_ends_at' => $product->sale_ends_at?->toDateString(),
                        'rating' => $summary['average'] ?: null,
                        'reviews' => $summary['total'] ?: null,
                    ],
                ];

                if (!empty($override['title'])) {
                    $ctx['title'] = $override['title'];
                    $ctx['title_is_final'] = true;
                }

                return $ctx;
            })(),
        ]);
    }

    /** Featured image first, then the gallery, de-duplicated. */
    /**
     * The gallery: a main shot plus a labelled thumbnail strip.
     *
     * Real images come first. When a product has fewer than a strip's worth and
     * demo content is on, labelled placeholders top it up so the strip can be
     * seen — never replacing a real image.
     */
    /**
     * Home → Shop → [Category, if the product has one] → Product name.
     * `categories` is already eager-loaded on the product query above, so
     * this costs nothing extra — the first assigned category is used
     * rather than every one, since a breadcrumb showing multiple parallel
     * parents doesn't map to how BreadcrumbList is meant to be read.
     */
    private function breadcrumbTrail(Product $product): array
    {
        $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');
        $trail = [
            ['name' => 'Home', 'url' => $base . '/'],
            ['name' => 'Shop', 'url' => $base . '/shop/'],
        ];

        $category = $product->categories->first();

        if ($category) {
            $trail[] = ['name' => $category->name, 'url' => $base . $category->url()];
        }

        $trail[] = ['name' => $product->name, 'url' => $base . $product->url()];

        return $trail;
    }

    private function gallery(Product $product): array
    {
        $images = array_values(array_unique(array_filter(array_merge(
            [$product->image],
            is_array($product->images) ? $product->images : []
        ))));

        $labels = ['Front', 'Texture', 'Ingredients', 'On skin', 'Box', 'Video'];
        $shots = [];

        foreach ($images as $i => $url) {
            $shots[] = [
                'image' => $url,
                'label' => $labels[$i] ?? 'View ' . ($i + 1),
                'video' => false,
            ];
        }

        $demo = app(\App\Services\DemoContent::class);

        if ($demo->enabled() && count($shots) < 6) {
            foreach ($labels as $i => $label) {
                if (count($shots) >= 6) {
                    break;
                }

                // Skip a label a real image already occupies.
                if (collect($shots)->contains('label', $label)) {
                    continue;
                }

                $shots[] = [
                    'image' => null,
                    'label' => $label,
                    'video' => 'Video' === $label,
                ];
            }
        }

        // Never hand back an empty gallery: the main frame needs something.
        return $shots ?: [['image' => null, 'label' => 'Front', 'video' => false]];
    }

    /**
     * Counts per star plus the average, in one grouped query.
     *
     * Loading every review to count them in PHP is the obvious approach and the
     * wrong one — a popular product here can carry hundreds of rows.
     */
    private function reviewSummary(int $productId): array
    {
        $rows = Review::query()
            ->selectRaw('rating, COUNT(*) as n')
            ->where('product_id', $productId)
            ->approved()
            ->groupBy('rating')
            ->pluck('n', 'rating');

        $total = (int) $rows->sum();
        $weighted = 0;
        $bars = [];

        foreach ([5, 4, 3, 2, 1] as $star) {
            $n = (int) ($rows[$star] ?? 0);
            $weighted += $star * $n;
            $bars[$star] = ['n' => $n, 'pct' => $total ? (int) round($n / $total * 100) : 0];
        }

        return ['total' => $total, 'average' => $total ? round($weighted / $total, 1) : 0.0, 'bars' => $bars];
    }

    private function related(Product $product)
    {
        $categoryIds = $product->categories->pluck('id');

        return Product::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->where('id', '!=', $product->id)
            ->when(
                $categoryIds->isNotEmpty(),
                fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds))
            )
            ->with('brand:id,name,slug')
            ->orderByDesc('total_sales')
            ->limit(4)
            ->get();
    }

    /**
     * "Order within 4h 12m for delivery by Tue, 30 Jun".
     *
     * Orders placed before the cutoff ship the same working day. Friday is the
     * UAE weekend, so a Thursday-evening order lands on Sunday.
     */
    private function cutoff(): ?array
    {
        if (! $this->settings->moduleEnabled('dispatch_cutoff', true)) {
            return null;
        }

        $hour = (int) $this->settings->get('dispatch_cutoff_hour', 15);
        $now = now();
        $today = $now->copy()->setTime($hour, 0);

        // Past today's cutoff, the next one is tomorrow.
        $next = $now->lt($today) ? $today : $today->addDay();
        $ship = $next->copy();

        // Skip Friday.
        if ($ship->isFriday()) {
            $ship->addDay();
        }

        $eta = $ship->copy()->addDays((int) $this->settings->get('dispatch_days', 2));

        if ($eta->isFriday()) {
            $eta->addDay();
        }

        // Carbon 3 returns a float from diffInMinutes(); intdiv() takes ints
        // only, so this must be cast before use.
        $mins = (int) max(0, $now->diffInMinutes($next));

        return [
            'remaining' => intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm',
            'date' => $eta->format('D, j M'),
        ];
    }

    /**
     * The detail tabs.
     *
     * Description and Ingredients come from the product; anything configured
     * under product_tabs is appended. A tab with no content is dropped rather
     * than rendered empty, so the bar never shows a dead heading.
     */
    private function tabs($product): array
    {
        $tabs = [
            ['title' => 'Description', 'body' => (string) ($product->description ?: $product->short_description)],
            ['title' => 'Ingredients', 'body' => (string) ($product->ingredients ?? '')],
            ['title' => 'How to use', 'body' => (string) ($product->how_to_use ?? '')],
        ];

        foreach ((array) $this->settings->get('product_tabs', []) as $custom) {
            $tabs[] = [
                'title' => (string) ($custom['title'] ?? ''),
                'body' => (string) ($custom['body'] ?? ''),
            ];
        }

        $tabs = array_values(array_filter(
            $tabs,
            fn ($t) => $t['title'] !== '' && trim(strip_tags($t['body'])) !== ''
        ));

        // With demo content on, top the tabs up so the bar can be seen before
        // the real copy exists. Real tabs always come first.
        $demo = app(DemoContent::class);

        if ($demo->enabled() && count($tabs) < 2) {
            foreach ($demo->tabs() as $extra) {
                if (! collect($tabs)->contains('title', $extra['title'])) {
                    $tabs[] = $extra;
                }
            }
        }

        // Never leave the section with nothing at all.
        if ($tabs === []) {
            $tabs = [['title' => 'Description', 'body' => '<p>No description available.</p>']];
        }

        return $tabs;
    }

    /** The review stylesheet, read once and cached. */
    private function reviewsCss(): string
    {
        $path = resource_path('css/kbb/sorina-reviews.css');

        if (! is_file($path)) {
            return '';
        }

        return \Illuminate\Support\Facades\Cache::remember(
            'kbb.reviews.css.' . (string) @filemtime($path),
            3600,
            static fn () => (string) @file_get_contents($path)
        );
    }

    /**
     * Frequently Bought Together: the product itself plus its best companions.
     *
     * Cached per product — the set only changes when the catalogue does, and
     * this runs on the most-visited page type on the site. (Rule 27)
     */
    private function bundle(Product $product)
    {
        if (! $this->settings->moduleEnabled('frequently_bought', false)) {
            return collect();
        }

        $count = max(1, (int) $this->settings->get('fbt_count', 3));

        $mates = \Illuminate\Support\Facades\Cache::remember(
            "kbb.fbt.{$product->id}.{$count}",
            900,
            fn () => $this->related($product)->take($count)->values()
        );

        return collect([$product])->concat($mates);
    }

    /**
     * A cookie of ids — no table, no query, no write on a page view. The
     * Recently Viewed module reads this when it lands.
     */
    private function rememberViewed(Request $request, int $productId): void
    {
        $seen = array_filter(array_map('intval', explode(',', (string) $request->cookie('kbb_viewed', ''))));
        $seen = array_slice(array_values(array_unique(array_merge([$productId], $seen))), 0, 12);

        cookie()->queue('kbb_viewed', implode(',', $seen), 60 * 24 * 30);
    }

    /** Display only — never added to a total (D-64). */
    private function vatLine(): ?string
    {
        if (! $this->settings->get('vat_enabled', true)) {
            return null;
        }

        $rate = rtrim(rtrim(number_format((float) $this->settings->get('vat_rate', 5), 2, '.', ''), '0'), '.');

        return "Inclusive of {$rate}% VAT · Authentic, sourced direct";
    }
}
