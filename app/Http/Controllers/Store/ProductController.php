<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Services\DemoContent;
use App\Services\ProductSections;
use App\Services\SettingsService;
use App\Support\ReviewSettings;
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

        // Store -> Reviews -> Review Settings. Both defaults below are the
        // literals this method used to hard-code ('newest' was ->latest(), 200
        // was ->limit(200)), so an untouched store reads exactly as before.
        $reviewSort = (string) ReviewSettings::get($this->settings, 'sr_sort');
        $reviewLimit = (int) ReviewSettings::get($this->settings, 'sr_max_reviews');

        $reviews = Review::query()
            /*
             * `reply` is here because the shop now prints it. The admin has
             * always offered a reply box, stored what was typed and put it in
             * both exports — and the product page never selected the column,
             * so no reply has ever been seen by a shopper. The admin's own
             * placeholder said "Shown publicly under the review", which made
             * it a promise rather than an oversight.
             */
            ->select('id', 'author_name', 'rating', 'title', 'content', 'verified', 'created_at', 'images', 'helpful', 'reply')
            ->where('product_id', $product->id)
            ->approved()
            // Demo-seeded rows are invented people with invented testimony and
            // a "Verified" tick. They stay in the table so the admin can find
            // and remove them; they do not appear on a public product page.
            ->real();

        // Applied through the shared helper so the admin screen's option list
        // and the page's ORDER BY can never drift apart.
        ReviewSettings::applySort($reviews, $reviewSort);

        $reviews = $reviews
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
            // needs to handle today, and it is now the default of
            // `sr_max_reviews` rather than a literal — the owner can lower it
            // on a slow host or raise it, within the schema's 4..500 clamp.
            ->limit($reviewLimit)
            ->get();

        /*
         * NO DEMO REVIEWS ON A PRODUCT PAGE, in either half of it.
         *
         * WHAT USED TO HAPPEN. When the product had no reviews of its own and
         * the Demo Content switch was on, this substituted
         * DemoContent::productReviews(): six invented customers, five of them
         * flagged `verified`, under a summary reading "4.9" and "3,204
         * reviews". The structured data was already protected from it —
         * $realSummary was kept back for exactly that reason, and the comment
         * here explained that publishing such an aggregateRating is the
         * textbook trigger for a structured-data manual action.
         *
         * WHY THAT WAS NOT ENOUGH. The reasoning stopped one step short. The
         * argument against telling Google about 3,204 reviews that do not
         * exist is not that Google is a special audience; it is that the
         * number is false. A shopper reading "4.9 · 3,204 reviews" above six
         * named people who never bought anything is the person the claim
         * actually misleads, and inventing customer reviews is unlawful in the
         * UAE, the EU and the UK whether or not a crawler sees them. Marking
         * them would not help: a shopper who has to be told which of the
         * reviews on the page are real has been shown fabricated ones.
         *
         * So the page now shows the product's real reviews or an honest empty
         * state. $summary is the real summary, full stop; $realSummary remains
         * as the name seoCtx below already closes over, and the two are now
         * the same thing by construction rather than by care.
         */
        $realSummary = $summary;

        return view('store.product', [
            'product' => $product,
            'gallery' => $this->gallery($product),
            'summary' => $summary,
            'reviews' => $reviews,
            'related' => $this->related($product),
            'settings' => $this->settings,
            'cutoff' => $this->cutoff($request),
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
            'seoCtx' => (function () use ($product, $realSummary) {
                // SeoSettings::map(), not Setting::map(): the latter memoises in
                // a process-level static as well as in the cache, so the first
                // render in a long-lived process pins site_url for every render
                // after it. That is what left the breadcrumb trail below
                // root-relative on a site whose canonical was absolute.
                $base = rtrim(\App\Services\Seo\SeoSettings::get('site_url', ''), '/');
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
                        // The exact price as a decimal string, straight off the
                        // integer fils. Money::toAed() returns a float and the
                        // renderer then ran number_format() on it — two float
                        // hops for the one number a crawler compares against
                        // the price printed on the page.
                        'price' => \App\Support\Money::decimalString($product->effectivePrice()),
                        'price_minor' => $product->effectivePrice(),
                        'currency' => \App\Support\Money::currency(),
                        // The real column, so 'onbackorder' can say BackOrder
                        // rather than being flattened into OutOfStock.
                        'stock_status' => $product->stock_status,
                        // Only while a sale is actually running. Outside that
                        // window the advertised price is the ordinary one and
                        // has no known end date, and sale_ends_at would be a
                        // date in the past — which Google reads as an expired
                        // offer and drops the price for.
                        'sale_ends_at' => $product->isOnSale() ? $product->sale_ends_at?->toDateString() : null,
                        // The gallery, so Google gets more than the featured
                        // shot and can pick an aspect ratio per layout.
                        'images' => $this->schemaImages($product, $override),
                        // $realSummary, never the demo fixture.
                        'rating' => $realSummary['average'] ?: null,
                        'reviews' => $realSummary['total'] ?: null,
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

    /**
     * The images the structured data may claim, in the order Google should see
     * them.
     *
     * Deliberately NOT gallery(): that one tops the strip up with labelled
     * placeholders when demo content is on, and a placeholder is not a
     * photograph of this product. Only the real featured image and the real
     * gallery rows go out, with a per-product og_image override winning the
     * first position when one has been set.
     *
     * @param  array<string, mixed>  $override  the products.seo json column
     * @return list<string>
     */
    private function schemaImages(Product $product, array $override): array
    {
        $images = array_merge(
            [$override['og_image'] ?? null, $product->image],
            is_array($product->images) ? $product->images : []
        );

        $clean = [];

        foreach ($images as $image) {
            if (! is_string($image)) {
                continue;
            }

            $image = trim($image);

            if ($image !== '' && ! in_array($image, $clean, true)) {
                $clean[] = $image;
            }
        }

        return $clean;
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
            // The same restriction the review list above applies, for the same
            // reason and in the same place: this average and total are printed
            // on the page AND handed to Seo as the schema.org aggregateRating.
            // A summary computed over rows the list does not show would put a
            // star rating in Google's results that no visitor can find.
            ->real()
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
            // Four out of a category is a LIMIT over a key that ties across
            // most of this catalogue, so without `id` the four "You may also
            // like" cards change between two renders of the same product page
            // with nothing behind the change.
            ->orderByDesc('total_sales')
            ->orderByDesc('id')
            ->limit(4)
            ->get();
    }

    /**
     * The dispatch countdown — TWO CLAIMS, AND ONLY ONE OF THEM TRAVELS.
     *
     * This rendered "Order within 4h 12m for delivery by Tue, 30 Jun" to every
     * visitor on earth, and it is two different statements wearing one
     * sentence:
     *
     *   WHEN THE PARCEL LEAVES — the countdown and `ship` below. It is
     *   `dispatch_cutoff_hour` and the Friday rule, and both of those describe
     *   the shop's OWN working week: orders placed before the cutoff go out the
     *   same working day, and nothing goes out on the UAE weekend. That is a
     *   fact about the warehouse and it is true whatever the destination is, so
     *   every shopper keeps it.
     *
     *   WHEN THE PARCEL ARRIVES — `date`, which is that dispatch date plus
     *   `dispatch_days`. `dispatch_days` is ONE GLOBAL NUMBER and its default of
     *   2 describes delivery inside the UAE. Added to an order bound for Riyadh
     *   it is a transit time nobody has measured, printed in bold as a date, to
     *   a shopper who is being charged the Gulf rate on the very next screen.
     *   That is the same wrong promise App\Support\DeliveryLine removed from the
     *   checkout, App\Mail\OrderStatusChanged from the dispatch email and Lane
     *   CO from the home page.
     *
     * SO THE ARRIVAL DATE IS OFFERED TO THE ONE COUNTRY `dispatch_days`
     * DESCRIBES, and that is `store_country` rather than a hard-coded 'AE' — a
     * shop that moves takes its transit time with it. Everywhere else `date` is
     * null and the view says when the parcel ships and stops talking.
     *
     * NOTHING IS INVENTED TO FILL THE GAP. Per-country dispatch days were the
     * obvious alternative and were rejected: no Saudi or Kuwaiti transit time
     * has ever been measured, so the table would ship empty and behave exactly
     * as this does, while adding a SECOND per-country delivery screen beside
     * `delivery_texts` — the duplication this lane removed from the trust chip
     * in the same pass.
     *
     * AND A DELIVERY LINE IS NOT A TRANSIT TIME. "Show the arrival date wherever
     * the owner has written a `delivery_texts` row" looks like the careful rule
     * and is not: a row reading "Delivered across Saudi Arabia" records a
     * sentence, not a number of days, so borrowing `dispatch_days` on the
     * strength of it would invent precisely the number this refuses to invent.
     * ProductPagePromisesTest pins that.
     *
     * COSTS NO QUERY. ShopperCountry reads the request, the session and one
     * header, and falls back to `store_country`, which SettingsService serves
     * from the snapshot this page has already taken. StorefrontQueryBudgetTest
     * holds the product page to 13 and this does not move it.
     */
    private function cutoff(Request $request): ?array
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

        $home = strtoupper(trim((string) $this->settings->get('store_country', 'AE')));
        $here = \App\Support\ShopperCountry::for($request)->code;

        $eta = null;

        if ($here === $home) {
            $eta = $ship->copy()->addDays((int) $this->settings->get('dispatch_days', 2));

            if ($eta->isFriday()) {
                $eta->addDay();
            }
        }

        // Carbon 3 returns a float from diffInMinutes(); intdiv() takes ints
        // only, so this must be cast before use.
        $mins = (int) max(0, $now->diffInMinutes($next));

        return [
            'remaining' => intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm',
            'ship' => $ship->format('D, j M'),
            'date' => $eta?->format('D, j M'),
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
