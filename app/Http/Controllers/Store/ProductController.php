<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Services\DemoContent;
use App\Services\ProductSections;
use App\Services\SettingsService;
use App\Support\ProductTabs;
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
    /*
     * PUBLIC since Lane PS, read by App\Services\AlsoLikeRail: the "You may
     * also like" carousel selects these columns in its union, so the cards on
     * the rail and the ones Frequently Bought Together draws from related()
     * below are one list of columns rather than two that can drift.
     */
    public const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'type',
        /*
         * ▲ AND THE THREE A SET'S PRICE CANNOT BE READ WITHOUT. (Lane SG)
         *
         * App\Support\SetPricing::COLUMNS carries the argument in full. In
         * short: SetPricing::mode() and ::basis() read these off getAttributes()
         * and fall back to "no rule, no anchor" for an absent column, so a
         * narrow select does not fail -- it prices the set at the number in
         * `products.price`, which is a stale snapshot for a rule-priced set and
         * the pre-reduction figure for an anchored one. This grid showed one
         * price and the set's own page showed another.
         */
        ...\App\Support\SetPricing::COLUMNS,
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

        /*
         * A SET'S MEMBERS, IN ONE BATCH, AND NOTHING AT ALL OTHERWISE. (Lane SP)
         *
         * partials/set-contents-panel.blade.php names what is in a set on the
         * set's own product page, and it reads App\Support\SetContents -- the
         * one description of a set's contents in this application.
         *
         * SetEagerLoad::on() LOOKS FIRST: handed a product that is not a set --
         * which is every product in this catalogue but the sets -- it returns
         * without touching the database, so the product-page budget in
         * StorefrontQueryBudgetTest does not move by one query for a feature
         * this shop is not using. Handed a set it costs THREE, batched, whether
         * the box holds three members or thirty. SetProductPageTest measures
         * that flatness rather than asserting it.
         *
         * Here rather than in the partial because a query belongs in the
         * controller, and because Api\ProductController already sets this
         * precedent on the rows it is about to publish.
         */
        \App\Support\SetEagerLoad::on([$product]);

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

        $alsoLike = app(\App\Services\AlsoLikeRail::class)->forProduct($product);

        return view('store.product', [
            'product' => $product,
            'gallery' => $this->gallery($product),
            'summary' => $summary,
            'reviews' => $reviews,
            /*
             * "You may also like" — a carousel, mixed from the same brand and
             * the same category, with the owner's controls and per-product
             * picks. (Lane PS) App\Services\AlsoLikeRail chooses. The old
             * related() it replaced went in Lane RB, with the Frequently
             * Bought Together block that was its last caller.
             */
            'alsoLike' => $alsoLike,
            /*
             * The SAME collection under the name the template used for these
             * cards until the carousel. Nothing in resources/views reads it
             * now; StorefrontEnglishUnchangedTest renders the pre-conversion
             * templates against this controller, and they do — so it is what
             * lets that walk show the cards byte-identical inside a new
             * wrapper, rather than an error page. Costs no query: it is the
             * rail's own result.
             */
            'related' => $alsoLike['products'],
            'settings' => $this->settings,
            'cutoff' => $this->cutoff($request),
            'bundles' => app(\App\Services\BundleService::class)->forProduct($product),
            'tabs' => $this->tabs($product),
            'modules' => app(ProductSections::class),
            // Inlined rather than looked up in the build manifest: a missing
            // entry throws, and that took this page down for hours.
            'reviewsCss' => $this->reviewsCss(),
            /*
             * "Buy these together" (Lane RB) — the section that replaced
             * Frequently Bought Together in the same slot. App\Services\
             * BuyTogether chooses; it asks the database nothing while the
             * section is off.
             *
             * `bundle` is the old block's variable, kept EMPTY rather than
             * removed: StorefrontEnglishUnchangedTest renders the
             * pre-conversion templates against this controller, and the old
             * partial still names it. An empty collection is what it always
             * received with its module off, so the walk draws nothing there.
             */
            'buyTogether' => app(\App\Services\BuyTogether::class)->forProduct($product),
            'bundle' => collect(),
            'vatLine' => $this->vatLine($request),
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

                // The picture a share and a crawler get: the SEO override, the
                // product's main picture, or -- when it has none -- the first
                // picture of its own gallery (2.60.350: a product with only
                // gallery pictures shared with no picture at all, and its share
                // sheet drew an empty card). Never a demo shot: those are not in
                // `images`.
                $kbbFirstShot = is_array($product->images) ? collect($product->images)->first(fn ($i) => is_string($i) && trim($i) !== '') : null;
                $kbbShareSource = $override['og_image'] ?? ($product->image ?: $kbbFirstShot);

                $ctx = [
                    'type' => 'product',
                    // The chain itself lives in App\Support\ProductSeo now, so the
                    // admin's snippet preview can ask what this page will publish
                    // instead of inventing a sentence. Same order, same result.
                    'description' => \App\Support\ProductSeo::rawDescription($product),
                    'image' => $kbbShareSource,
                    // Lane QB: the JPEG copy og:image publishes once it exists
                    // (made after this response the first time it is missing),
                    // or null for "publish the original as before". See
                    // App\Support\ShareImage for why the original lost its picture.
                    'share_image' => \App\Support\ShareImage::forPage(
                        \App\Support\ImageVariants::rootRelative((string) ($kbbShareSource ?? ''))
                    ),
                    'url' => !empty($override['canonical']) ? $override['canonical'] : ($base . $product->url()),
                    'breadcrumb' => $this->breadcrumbTrail($product),
                    'noindex' => !empty($override['noindex']),
                    'product' => [
                        /*
                         * THE STRUCTURED DATA IS TRANSLATED TOO, and this is
                         * not cosmetic. hreflang tells Google that the Arabic
                         * URL is a page in its own right; a rich result whose
                         * name is in English on an Arabic page advertises the
                         * page as untranslated in exactly the place a shopper
                         * decides whether to click.
                         *
                         * `sku` is deliberately NOT translated and cannot be —
                         * it is not on the allowlist. See HasTranslations.
                         */
                        'name' => $product->t('name'),
                        'brand' => $product->brand?->t('name'),
                        'sku' => $product->sku,
                        /*
                         * The barcode, which the plan recorded as having no
                         * column to come from. It has one --
                         * 2026_10_05_add_product_editor_columns added
                         * `products.gtin` and the product editor writes it.
                         * Passed raw; App\Support\Seo re-checks the mod-10
                         * check digit before publishing, because this column
                         * is also reachable by the importer and by hand and a
                         * wrong GTIN attaches this shop's price to somebody
                         * else's product.
                         */
                        'gtin' => $product->gtin,
                        /*
                         * ── A SET, DESCRIBED TO GOOGLE (Lane SP) ────────────
                         *
                         * Two keys, both ABSENT on an ordinary product, so the
                         * structured data every product page in this shop has
                         * been publishing for months is byte-identical.
                         *
                         * `set` is the member list — names and quantities, off
                         * App\Support\SetContents, the one description of a
                         * set's contents. App\Support\Seo turns it into
                         * `additionalType: ProductCollection` and an
                         * `includesObject` list; the long note there says why
                         * the node stays a Product.
                         *
                         * `keywords` is the product's own tags, which the
                         * owner asked for on the Sets screen and which are the
                         * same `product_tag` pivot an ordinary product uses. It
                         * is loaded ONLY for a set — one query on a set's page
                         * and none on anybody else's.
                         */
                        'set' => $product->isSet()
                            ? \App\Support\SetContents::fromProduct($product)['members']
                            : null,
                        'keywords' => $product->isSet()
                            ? $product->tags()->orderBy('name')->pluck('name')->all()
                            : null,
                        /*
                         * EVERY OPTION'S OWN PRICE AND STOCK.
                         *
                         * The page prints a price on every `.variant` row and
                         * published one number for all of them. Seo turns two
                         * or more distinct prices into an AggregateOffer and
                         * leaves a single-priced product exactly as it was.
                         *
                         * `effectivePrice()` on the VARIANT, not the product:
                         * ProductVariant::effectivePrice() is the method that
                         * knows a variant's sale_price only applies inside the
                         * parent's sale window, and it is the same call the
                         * blade template makes two lines above where it prints
                         * the figure. The decimal string and the integer both
                         * go over, for the reason CollectionSchema's header
                         * gives at length: priceString() reads a STRING as
                         * already-formatted and a NUMBER as major units, so
                         * handing it the integer 12600 under `price` publishes
                         * 12,600 AED.
                         *
                         * The relation is already eager-loaded for the options
                         * list, so this costs no query.
                         */
                        'variants' => $product->variants
                            ->map(fn ($variant) => [
                                'price' => \App\Support\Money::decimalString($variant->effectivePrice()),
                                'price_minor' => $variant->effectivePrice(),
                                'sku' => $variant->sku,
                                /*
                                 * The real column, never inStock()'s boolean.
                                 * Seo::availability() has to tell 'outofstock'
                                 * and 'onbackorder' apart to publish what the
                                 * owner decided, and a bool has already thrown
                                 * that away -- the same note CollectionSchema
                                 * and the product block above both carry.
                                 */
                                'stock_status' => $variant->stock_status,
                            ])
                            ->all(),
                        // The exact price as a decimal string, straight off the
                        // integer fils. Money::toAed() returns a float and the
                        // renderer then ran number_format() on it — two float
                        // hops for the one number a crawler compares against
                        // the price printed on the page.
                        'price' => \App\Support\Money::decimalString($product->effectivePrice()),
                        'price_minor' => $product->effectivePrice(),
                        'currency' => \App\Support\Money::currency(),
                        // The real column, not a synthesised in-stock flag,
                        // because the flag cannot tell 'outofstock' and
                        // 'onbackorder' apart and Seo::availability() needs to
                        // see which it is. It publishes both as OutOfStock by
                        // the owner's decision — the long note on that method
                        // explains why, and why the shop is not free to answer
                        // BackOrder while every other part of the page refuses
                        // to sell the thing.
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

                /*
                 * THE TITLE AS TEXT, NOT AS THE ESCAPED SECTION. (Lane PW)
                 *
                 * The layout's fallback reads `@section('title', …)`, and the
                 * short form of @section ESCAPES its value — so a product named
                 * `Lift & Glow "Serum"` reached Seo::render() as
                 * `Lift &amp; Glow &quot;Serum&quot;`, which render() escaped
                 * again: <title>, og:title and twitter:title all published
                 * `&amp;amp;`, and a WhatsApp or Facebook preview card read
                 * "Lift &amp; Glow". Handing the same string over unescaped is
                 * the whole fix; render() is the one place it is escaped. For
                 * a name with no & < > " ' in it this is byte-identical to what
                 * the layout computed. ProductTrustShareTest pins it.
                 */
                $ctx['title'] = \App\Support\ProductTitle::head($product->brand?->t('name') ?? '', $product->t('name'));

                if (!empty($override['title'])) {
                    $ctx['title'] = $override['title'];
                    $ctx['title_is_final'] = true;
                    /*
                     * What `%%title%%` means inside that override. In Yoast it
                     * is the post title — for a product, the product's own
                     * name — and an imported title is overwhelmingly likely to
                     * contain it: `%%title%% %%sep%% %%sitename%%` is Yoast's
                     * shipped default. App\Support\Seo cannot work it out from
                     * $ctx['title'], which is the template itself. Translated,
                     * for the same reason the structured data above is: an
                     * Arabic page whose tab reads the English name advertises
                     * itself as untranslated in the one place a shopper
                     * decides whether to click.
                     */
                    $ctx['title_token'] = $product->t('name');
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
        // The same two keys the visible breadcrumb prints, so the crumb a
        // shopper reads and the crumb Google reads say the same words in the
        // same language. Their English defaults are 'Home' and 'Shop', which is
        // what these literals were.
        $trail = [
            ['name' => __('store.breadcrumb.home'), 'url' => $base . '/'],
            ['name' => __('store.breadcrumb.shop'), 'url' => $base . '/shop/'],
        ];

        $category = $product->categories->first();

        if ($category) {
            $trail[] = ['name' => $category->t('name'), 'url' => $base . $category->url()];
        }

        $trail[] = ['name' => $product->t('name'), 'url' => $base . $product->url()];

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

        /*
         * A PICTURE THE MIGRATION COULD NOT BRING ACROSS IS NO PICTURE (Lane PX).
         * Left in, it was drawn as the browser's broken-image icon in a white
         * frame the day the old site went off; taken out, a product with none
         * left gets the placeholder shot below, which is what this page already
         * draws for a product with no photograph. See App\Support\LostPictures.
         */
        $images = array_values(array_filter(
            $images,
            static fn ($url): bool => ! \App\Support\LostPictures::isLost(is_string($url) ? $url : null),
        ));

        /*
         * ── THE DEMO CATALOGUE'S OWN SHOTS ───────────────────────── Lane GAL
         *
         *   "also i can not see the product gallery thumnails, add some demo
         *    thumnails so i can see in action."
         *
         * He could not see them because there were none to see.
         * DemoCatalogueSeeder writes neither `image` nor `images` on any of its
         * 24 rows, so `$images` came out EMPTY here, one shot was returned by
         * the fallback at the bottom, and partials/product-gallery.blade.php
         * draws the `.gthumbs` strip only `@if ($shotCount > 1)`. The padding
         * below this loop would have filled it — but only `when
         * DemoContent::enabled()`, and that setting ships off.
         *
         * WHY HERE AND NOT IN `products`.images, which is where it was built
         * first: App\Support\DemoProductShots' header carries the measurement.
         * `images` is catalogue data that the importer's re-pointer, MediaAudit,
         * MediaUsageWriter and the image-variants backlog all walk, and filling
         * it put 120 placeholder pictures into all four — 23 test cases across
         * seven files that have nothing to do with galleries went red saying so.
         *
         * ONLY WHEN THE ROW HAS NOTHING OF ITS OWN. A demo product somebody has
         * since given a photograph keeps exactly the gallery that photograph
         * makes; this cannot push a real shot down the strip or displace one.
         * And the three marks isDemo() requires mean the WordPress import turns
         * this off by itself: an imported row carries a real `wc_id`.
         *
         * READ-ONLY and cheap: five `is_file()` calls on a page that already
         * pays two per shot for ImageVariants, no query, and nothing drawn at
         * request time. The pictures are made once by
         * 2027_06_20_000000_draw_demo_gallery_shots.
         */
        if ($images === [] && \App\Support\DemoProductShots::isDemo($product)) {
            $images = \App\Support\DemoProductShots::urlsFor((string) $product->slug);
        }

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
     * ── WHAT MOVED, AND WHAT DID NOT ───────────────────────────────────────
     *
     * The list itself is now App\Support\ProductTabs::forProduct(), because
     * the owner can author tabs of his own -- global ones that appear on every
     * product and ones that exist on a single product -- and because a product
     * may hide or re-word a global tab. That is a decision about what a tab IS,
     * and it belongs in one place rather than in a controller method. That
     * file's header is the whole design, including what these tabs were before
     * it existed and why the three built-ins stayed built in.
     *
     * The three built-in tabs still read the same two kinds of text they always
     * did, and ProductTabs::builtins() carries the note that used to be here:
     * the HEADINGS are interface strings, keyed, the same three words on every
     * product page; the BODIES are this product's own prose, read with t()
     * against its row. description, ingredients and how_to_use are all in
     * TranslationStore::LONG_FIELDS, so they are fetched by this page in ONE
     * query rather than carried in the map on every page of the site, and the
     * first of the three reads pays for the other two. Blank still means
     * untranslated: t() falls back to the English column.
     *
     * WHAT STAYED HERE, and why. The demo top-up and the never-empty fallback
     * are both about the SHOP being empty rather than about what a tab is, and
     * both are unchanged -- same order, same condition, same literals.
     */
    private function tabs($product): array
    {
        $tabs = ProductTabs::forProduct(
            $product,
            (array) $this->settings->get('product_tabs', [])
        );

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
     * A cookie of ids — no table, no query, no write on a page view. The
     * Recently Viewed module reads this when it lands.
     */
    private function rememberViewed(Request $request, int $productId): void
    {
        $seen = array_filter(array_map('intval', explode(',', (string) $request->cookie('kbb_viewed', ''))));
        $seen = array_slice(array_values(array_unique(array_merge([$productId], $seen))), 0, 12);

        cookie()->queue('kbb_viewed', implode(',', $seen), 60 * 24 * 30);
    }

    /**
     * The tax sentence under the price, for THIS shopper's destination.
     *
     * ── TWO DEFECTS IN ONE LINE, BOTH LATENT ────────────────────────────────
     *
     * This method used to return, verbatim:
     *
     *     "Inclusive of {rate}% VAT · Authentic, sourced direct"
     *
     * with the rate read straight out of `vat_rate`.
     *
     * 1. THE BASIS WAS ASSERTED, NOT READ. "Inclusive of" was a literal. It was
     *    printed whatever `vat_basis` said and whatever `vat_country_rates` /
     *    `vat_country_bases` said, so the sentence was true only of the shipped
     *    configuration. The day the owner does the thing he asked for in his own
     *    words — "for uae the vat i can set inclusive, for Saudi i can set
     *    exclusive" — every product page in the shop starts telling Saudi
     *    shoppers the tax is already in a price the checkout is about to add it
     *    to. The rate was wrong for them too: `vat_rate` is the DEFAULT rate,
     *    not Saudi Arabia's.
     *
     *    App\Support\VatDisplay::shelfNote() now answers it, from the same rule
     *    the checkout charges, for the country App\Support\ShopperCountry
     *    resolves. The product page's structured data was already honest about
     *    the basis (MachineFacingClaimsTest); the sentence a human reads now
     *    agrees with it instead of contradicting it.
     *
     * 2. "Authentic, sourced direct" WAS A TRUST CLAIM WITH NO HOME. 2.60.193
     *    gave every claim about this business one owner-editable box in
     *    App\Support\TrustClaims, where an empty box removes the claim AND its
     *    element. This was the spelling that got left out — glued to a tax line,
     *    on the very page whose trust row already prints `product_authentic_text`
     *    a few hundred pixels below.
     *
     *    So the removal half of that feature did not work here: an owner who
     *    cleared the authenticity chip on Business Details → Claims watched the
     *    chip disappear and the same claim go on being printed under the price,
     *    in a string only a signed package could reach. It is gone from here.
     *    The claim itself is NOT gone from the page — it keeps its one home in
     *    the trust row, where it can now actually be withdrawn.
     *
     *    It is deliberately NOT given a seventh key of its own. A second
     *    authenticity box on one page is a second place to say one thing, which
     *    is the defect TrustClaims exists to end, and TrustClaims::CLAIMS argues
     *    the case for one key per PLACEMENT — this was never a placement, it was
     *    a duplicate.
     */
    private function vatLine(Request $request): ?string
    {
        return app(\App\Support\VatDisplay::class)
            ->shelfNote(\App\Support\ShopperCountry::for($request)->code);
    }
}
