{{--
    THE PRODUCT TILE. There is exactly one, and this is it.            Lane PG

    ── WHAT THIS REPLACED, AND WHY THIS SHAPE WON ──────────────────────────────

    This shop had FIVE product grids and TWO different cards:

        .kbb-pgrid via <x-product-grid>        the skinned card  (brand page,
                                                                  [kbb_products])
        .kbb-pgrid via partials/home/grid      the skinned card  (home rails,
                                                                  a category,
                                                                  the wishlist)
        .grid on /shop via <x-product-card>    THIS FILE's old card (the shop
                                               listing AND every category
                                               archive -- CategoryArchiveController
                                               delegates to ShopController::index())
        .rel on a product page                 THIS FILE's old card
        .brw-grid on the brand landing         not a product grid at all: it
                                               lists BRANDS, so it is left alone

    The owner picked the SKINNED card's look (docs/OWNER-GRID-REFERENCE.webp):
    photo, category eyebrow, brand, name, rating, price with a strikethrough,
    full-width Add to cart, NEW and -% badges. So the markup below is the
    skinned card's -- `.kbb-card` / `.kbb-card-thumb` / `.cb` / `.cn` / `.cp`,
    the classes 28 skins in kbb-grid-skins.css are written against.

    IT LIVES IN THIS FILE, not in components/product-grid.blade.php, because
    THREE of the four product grids already call `<x-product-card>` -- including
    the related rail in store/product.blade.php, which belongs to another lane.
    Putting the one card here converts that rail without editing a file this
    lane does not own, and leaves ONE template to change next time instead of
    two that must be kept in step. components/product-grid.blade.php and
    partials/home/grid.blade.php are now loops around this tag.

    ── WHAT CAME ACROSS FROM THE OLD CARD, DELIBERATELY ────────────────────────

    The skinned card never had these and /shop did. Dropping them to "match the
    screenshot" would have been a regression in four working things, so they are
    here:

        the wishlist heart        Catalogue -> Wishlist, off by default
        the quick-view button     Catalogue -> Quick view, ON by default
        Product Labels            Growth & Marketing -> Product Labels, off by
                                  default; when ON it takes the badge over
                                  entirely, including deciding there is none.
                                  ProductLabelsRenderTest reads `.lbl` off
                                  /shop, and still does.
        the no-JS Add to cart     a real `?add-to-cart=` href, not a <span>

    ── WHY THE ROOT IS A <div> AND NOT AN <a> ──────────────────────────────────

    The skinned card wrapped the whole tile in `<a class="kbb-card" href=…>`.
    That cannot hold the four things above: `<a>` inside `<a>` is the ONE
    nesting the HTML parser actively breaks apart, so the no-JS Add to cart
    would have been hoisted out of the card by the browser, and `<button>`
    inside `<a>` is invalid interactive content.

    So the root is a `<div>`, the photograph and the name are real links, and
    the REST of the tile is made clickable by a stretched pseudo-element on the
    name (`.cn::after{position:absolute;inset:0}`) -- no JavaScript, no
    measurement, and the same "click anywhere on the tile" behaviour. The
    controls sit above it on z-index. See kbb.css, "THE STRETCHED LINK".

    ── WHAT THIS COMPONENT NEEDS ALREADY LOADED ────────────────────────────────

        brand   `$product->brand?->t('name')` and the placeholder's seed

    AND NOTHING ELSE, which is the reason the category eyebrow arrives as the
    `catLabel` PROP rather than being read off `$product->categories` here.
    Reading the relation would put `categories` in this component's contract and
    therefore in every caller's eager load -- one extra query on /shop, on every
    category archive, on the product page and on /routines, none of which pay it
    today. StorefrontQueryBudgetTest is a budget, not a suggestion.

    The two callers that DO have `categories` loaded (the skinned grid) resolve
    the label themselves and pass it in; the pages that know their own category
    pass that; /shop passes null.

    `brand` has to arrive loaded, AND IT STILL HAS TO NOW THAT THE BRAND LINE
    IS OFF BY DEFAULT (Lane CARD). The obvious follow-on to hiding that line is
    to stop loading the relation and take the query budget down with it, and it
    is wrong twice over. The relation is read by two things that are not the
    brand line: `$seed` below, which App\Support\Gradient::for() hashes to
    colour the placeholder of a product with no photograph, and
    Gradient::initials($brand ?: $name), which is the letters drawn on it. And
    the line is a SETTING rather than a deletion, so a shop that switches it back
    on would lazy-load one query per card — under Model::preventLazyLoading()
    that is not a slow page, it is an exception. StorefrontQueryBudgetTest is
    unchanged by this lane for exactly that reason: measured, the budget does
    not move, because nothing stopped being read.

    A `loadMissing` here would be WORSE than the lazy load it replaces: this
    component is handed ONE model, so it would be one query per card either way,
    written where no caller could eager-load it away. So a caller's query says:

        ->with('brand:id,name,slug')

    Every caller is named in tests/Feature/ComponentLoadContractTest.php, which
    renders each of them with `Model::preventLazyLoading()` on; a new caller
    reddens that file until it is named there too.
--}}
@props([
    'product',
    'eager' => false,
    // The small upper-case line above the name. The owner's reference calls it
    // SKINCARE SETS on a bundles rail -- it is the SECTION's label there, not
    // the product's own category, which is why this is a caller's string.
    'catLabel' => null,
    // `#1`, `#2`… on the bestsellers rail. Takes the top-start badge slot.
    'rank' => null,
])

@php
    // Catalogue → Wishlist. The heart is markup only until the module is on.
    $kbbWishlist = app(\App\Services\SettingsService::class)->moduleEnabled('wishlist', false);

    // Catalogue → Quick view. Registered in ModuleRegistry, default on.
    $kbbQuickView = app(\App\Services\SettingsService::class)->moduleEnabled('quick_view', true);

    /*
     * ── WHAT THE CARD SHOWS, DECIDED HERE AND NOT IN CSS ───────── Lane CARD ──
     *
     * The owner: "i want to hide the brand name, category name by default. only
     * name, rating (if any), pricing and cart buttons."
     *
     * Three of Appearance → Product styles → Card content's switches are read
     * here, and the other four are still the `.pc-no*` body classes they always
     * were. THAT IS NOT INCONSISTENCY, IT IS THE ONLY MECHANISM THAT REACHES
     * THE WHOLE SHOP. Measured on this branch: the seven `.pc-no*` rules exist
     * ONLY in resources/css/kbb/kbb-grid-skins.css, and only three storefront
     * pages @vite that sheet (home, wishlist, collection). /shop, every
     * category archive, every brand page and the product page's related rail
     * are styled from the SECOND, SHORTER copy of the skins inside kbb.css,
     * which stops before those rules — so "Brand name: off" hid the brand on
     * the homepage and left it on /shop. A default that only works on three
     * pages is exactly the "setting that will be reported as broken" this lane
     * was told to avoid, and CSS cannot fix it without unpicking ~180
     * duplicated rules (see kbb.css's own note, and docs/PG2-SHOWCASE-CARD.md
     * §6, which records the same duplication biting the colour controls).
     *
     * A markup gate has none of that: it is one decision, in PHP, on every page
     * that draws a tile and under every one of the 32 skins.
     *
     * THESE THREE AND NOT ALL SEVEN, deliberately. Brand, category and rating
     * are the card's VERTICAL ANATOMY: each is a row that is either there or
     * not, and the stylesheet reserves a slot for each so that a card with a
     * rating and a card without are the same height. The reservation and the
     * markup have to agree about what exists, which is why these three moved
     * and the four that only change a line's CONTENT (the was-price, the two
     * badges, the button) did not.
     *
     * ONE all(), NOT THREE get() CALLS. ProductStyles::get() runs all() —
     * every key in the schema — once for each key it is asked for, and this
     * component renders once per tile.
     */
    $kbbShows = app(\App\Services\ProductStyles::class)->all();

    // t(), not the column. On English these two ARE the column — t() returns
    // early for the default locale — so an English card reads the same words it
    // always did. On /ar they are the Arabic the owner typed, and blank still
    // means untranslated, which falls back to the English name rather than to a
    // gap where a product name should be.
    $brand = $product->brand?->t('name') ?? '';
    $name = $product->t('name');

    // THE TILE'S COLOUR IS SEEDED FROM THE ENGLISH, DELIBERATELY. Gradient::for
    // hashes the string it is given, so seeding it with the translated name
    // would give a product one colour on /shop and a different one on /ar/shop
    // — the same shop repainted, for a value nobody reads.
    $seed = ($product->brand?->name ?? '') . $product->name;
    $link = $product->url();
    // A picture the migration could not bring across draws the placeholder
    // below, not a broken image. Lane PX; see App\Support\LostPictures.
    $img = \App\Support\LostPictures::usable($product->image);
    $rc = (int) $product->review_count;
    $rating = (int) round((float) $product->rating);

    /*
     * THE BADGES.
     *
     * Catalogue → Product Labels takes the badge over ENTIRELY when it is on —
     * including deciding there should not be one. That is the plugin's
     * behaviour: its filter returns an empty string rather than falling back to
     * the theme's badge, so turning the module on and matching nothing means no
     * badge. Unchanged from the card this replaces, and ProductLabelsRenderTest
     * reads exactly this `.lbl` span off /shop.
     *
     * With the module OFF — which is how it ships — the tile draws the pair the
     * owner's reference shows: a NEW pill at the top start and a -N% pill at the
     * top end, both of them `.kbb-badge`.
     *
     * `$kbbOff >= 1` rather than isOnSale(), and the two are not the same thing:
     * a markdown that rounds to nothing took the sale branch on the old card,
     * emitted nothing, and a featured product marked down from AED 100.00 to
     * AED 99.80 lost its Bestseller badge to a sale badge that was never drawn.
     * ProductLabels::for() falls THROUGH to the next rule in exactly this case.
     */
    $kbbLabel = app(\App\Services\ProductLabels::class)->for($product);
    $kbbLabelsOn = app(\App\Services\SettingsService::class)->moduleEnabled('product_labels', false);
    $kbbOff = $product->isOnSale() ? $product->discountPercent() : 0;

    // The top-START pill, as one decided string. A rank beats everything: it is
    // the rail's own numbering, and how new a product is is not what that row is
    // about.
    $kbbStart = null;

    if ($rank !== null) {
        $kbbStart = '<span class="kbb-badge kbb-badge-new">#' . (int) $rank . '</span>';
    } elseif ($kbbLabel !== null) {
        $kbbStart = '<span class="lbl" style="background:' . e($kbbLabel['colour']) . '">' . e($kbbLabel['text']) . '</span>';
    } elseif (! $kbbLabelsOn) {
        if ($product->created_at && $product->created_at->gt(now()->subDays(30))) {
            $kbbStart = '<span class="kbb-badge kbb-badge-new">' . e(__('store.product_card.badge_new')) . '</span>';
        } elseif ($product->featured && $kbbOff < 1) {
            /*
             * BESTSELLER DEFERS TO A REAL MARKDOWN, and NEW does not.
             *
             * That is the precedence ProductLabels::for() applies and the old
             * card applied: a featured product with a live sale shows the sale.
             * `$kbbOff < 1` and not `! isOnSale()` — a markdown that rounds to
             * nothing draws no -N% pill, so deferring to it would leave the
             * tile with no badge at all, which is the defect
             * PriceDisplayTruthTest was written for.
             *
             * NEW sits beside the -N% instead of under it, because they are
             * different corners and the owner's reference shows both on one
             * tile.
             */
            $kbbStart = '<span class="kbb-badge kbb-badge-best">' . e(__('store.product_card.label_bestseller')) . '</span>';
        }
    }

    // The top-END pill. Only the theme draws it: when Product Labels is on the
    // module owns the badge, and a second one beside it would be the theme
    // arguing with the setting.
    $kbbEnd = (! $kbbLabelsOn && $kbbOff >= 1)
        ? '<span class="kbb-badge kbb-badge-sale">' . e(\App\Support\Bidi::number('-' . $kbbOff . '%')) . '</span>'
        : null;

    /*
     * CAN THIS TILE PUT IT IN THE BASKET ON ITS OWN?
     *
     * Product::isDirectlyBuyable() is one expression read by this tile AND by
     * the refusal in CartService::add(), so the button a shopper sees and the
     * door the request goes through cannot give different answers. A VARIABLE
     * parent is bought by its variation and carries no price of its own:
     * `products.price` is NULL, effectivePrice() casts that to 0, and the
     * skinned tile used to take a basket line at AED 0 that the checkout would
     * then collect.
     */
    $kbbCanAdd = $product->isDirectlyBuyable();

    /*
     * A VARIABLE PRODUCT'S PRICE IS ON ITS VARIATIONS.
     *
     * App\Services\VariantPricing reads the range where this tile reads: ONE
     * grouped query per request, taken only when a tile like this is actually on
     * the page, and null for every other product — so a catalogue of simple
     * products costs nothing for it.
     *
     * COMPUTED HERE, IN THE BLOCK THAT EMITS NOTHING, AND NOT BESIDE THE MARKUP
     * THAT USES IT. A `@php` line of its own down in the body adds its own
     * indentation and newline to the rendered page, and
     * StorefrontEnglishUnchangedTest compares BYTES.
     */
    $kbbRange = app(\App\Services\VariantPricing::class)->range($product);

    /*
     * THE WHOLE PRICE CELL, BUILT HERE AND NOT IN THE MARKUP, for two reasons
     * that both cost a red suite before this shape was found: the byte-for-byte
     * one above, and that a nested `@else@if … @endif @endif` inside one line
     * does not compile at all — "syntax error, unexpected token endif", which
     * reaches the browser as a 500 on every page carrying a grid.
     *
     * BOTH FIGURES AT ONE PRECISION, AND A PRECISION THAT SEPARATES THEM.
     * Money::format() rounds to whole dirhams, so a markdown from AED 100.00 to
     * AED 99.80 printed a struck-through price identical to the one beside it,
     * which reads as a sale that took nothing off. Money::decimalsToDistinguish()
     * returns the store's usual 0 whenever the rounded figures already differ
     * and the currency's own precision only for the pair that would collide.
     *
     * $kbbWas is compareAtPrice(), not `(int) $product->price`: a variable
     * parent keeps its money on its variations and that column is NULL, so a
     * marked-down variable product would quote `AED 0` as the regular price.
     */
    if ($kbbRange !== null) {
        $kbbRangeDp = \App\Support\Money::decimalsToDistinguish($kbbRange[0], $kbbRange[1]);

        // Every option at the same money is a single price, not a range of one.
        // Seo::aggregateOffer() declines to publish a lowPrice equal to its
        // highPrice for the same reason.
        $kbbPriceHtml = '<span class="kbb-card-price">' . (\App\Services\VariantPricing::isSpread($kbbRange)
            ? __('store.product_card.price_range', [
                'low' => \App\Support\Money::format($kbbRange[0], $kbbRangeDp),
                'high' => \App\Support\Money::format($kbbRange[1], $kbbRangeDp),
            ])
            : \App\Support\Money::format($kbbRange[0])) . '</span>';
    } elseif ($product->isOnSale()) {
        $kbbWas = (int) $product->compareAtPrice();
        $kbbDp = \App\Support\Money::decimalsToDistinguish($kbbWas, $product->effectivePrice());

        $kbbPriceHtml = '<span class="kbb-card-reg">' . \App\Support\Money::format($kbbWas, $kbbDp) . '</span> '
            . '<span class="kbb-card-price">' . \App\Support\Money::format($product->effectivePrice(), $kbbDp) . '</span>';
    } else {
        $kbbPriceHtml = '<span class="kbb-card-price">' . \App\Support\Money::format($product->effectivePrice()) . '</span>';
    }

    /*
     * A 1000x1000 photograph painted into a frame that is never wider than
     * ~240 CSS pixels at five columns. '' when no phone-sized copy is on disk,
     * and that is the important case: with `w` descriptors the browser picks a
     * candidate and never looks at src, so one 404 is a blank tile with nothing
     * to fall back to. See App\Support\ImageVariants.
     */
    $kbbSrcset = $img ? \App\Support\ImageVariants::srcsetFor($img) : '';
@endphp

{{-- `kbb-tile` AS WELL AS `kbb-card`, and the second class is load-bearing.

     `.kbb-card` is not unique to a product: partials/checkout/stripe-card.blade.php
     uses the same class for the card-payment box, and kbb.css — which every
     page loads, checkout included — already carries unconditional `.kbb-card`
     rules. So the tile's own rules (the equal-height flex column, the two-line
     name clamp, the stretched link) hang off `.kbb-tile`, which nothing else
     has, instead of off a container class. That is what lets this ONE tile work
     inside `.kbb-pgrid`, inside `#grid`, inside the product page's `.rel` and
     on its own in a routine step, without four selector lists to keep in step
     and without reaching into the checkout. --}}
<div class="kbb-card kbb-tile">
    <div class="kbb-card-thumb">
        {{-- THE PHOTOGRAPH IS A LINK, AND IT IS OUT OF THE TAB ORDER. The name
             below is the tile's real link and carries the words; a second tab
             stop to the same place on every tile is forty extra stops on a
             /shop page. It keeps its alt, so it is still a described image for
             a screen reader and still indexable by Google Images. --}}
        @if ($img)
            {{-- NO width/height ATTRIBUTES, and that is not an omission.
                 `.kbb-card-thumb` is the CSS-sized box — `aspect-ratio:1` — and
                 the <img> is `position:absolute;inset:0` inside it, so it is out
                 of flow: layout never asks the file how big it is, which is why
                 the frame is reserved before a byte of the photograph arrives
                 and why srcset cannot move anything either. An intrinsic ratio
                 stated here could only disagree with the frame.

                 `eager` is passed by the page that knows this card is the first
                 one in its grid, which is the LCP candidate at both widths.
                 Everything else is lazy: a browser still fetches a lazy image
                 that is already inside the viewport, so the cards beside this
                 one are not delayed — what lazy buys is the rest of the page,
                 which is most of it. --}}
            <a class="kbb-card-shot" href="{{ $link }}" tabindex="-1"><img class="kbb-card-img" src="{{ $img }}" alt="{{ $product->altFor($img) }}"
                 @if ($kbbSrcset !== '') srcset="{{ $kbbSrcset }}" sizes="{{ \App\Support\ImageVariants::tileSizesAttribute() }}" @endif
                 @if ($eager) loading="eager" fetchpriority="high" @else loading="lazy" @endif
                 decoding="async"></a>
        @else
            {{-- Same gradient fallback the rest of the site uses, so a product
                 without a photograph still fills the frame. --}}
            <a class="kbb-card-shot" href="{{ $link }}" tabindex="-1"><span class="kbb-card-ph" style="background:{{ \App\Support\Gradient::for($seed) }}">{{ \App\Support\Gradient::initials($brand ?: $name) }}</span></a>
        @endif
        @if ($kbbStart !== null){!! $kbbStart !!}@endif
        @if ($kbbEnd !== null){!! $kbbEnd !!}@endif
        @if ($kbbQuickView)<button class="qv-btn" type="button" aria-label="{{ __('store.product_card.quick_view') }}" data-kbb-qv="{{ $product->id }}">{{ __('store.product_card.quick_view') }}</button>@endif
        @if ($kbbWishlist)<button class="heart" type="button" aria-label="{{ __('store.product_card.save_label') }}" data-kbb-wish="{{ $product->id }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg></button>@endif
    </div>
    <div class="cb">
        @if ($catLabel && $kbbShows['show_category'])<div class="kbb-card-cat">{{ $catLabel }}</div>@endif
        {{-- THE NAME IS THE STRETCHED LINK. Its ::after covers the whole tile,
             so a click on the card's white space follows it. The clamp that
             holds the name to two lines whatever it says — so one long title
             cannot make its row taller than every other row — is in kbb.css,
             and it is a `calc()` reservation rather than anything measured. --}}
        <a class="cn" href="{{ $link }}">@if ($brand && $kbbShows['show_brand'])<span class="kbb-card-brand">{{ mb_strtoupper($brand) }}</span>@endif<span class="kbb-card-nm">{{ $name }}</span></a>
        @if ($rc > 0 && $kbbShows['show_rating'])
            {{-- NO REVIEWS, NO BAR. The old skinned tile drew five empty stars
                 and a `(0)` for every unreviewed product, which reads as "rated
                 badly" rather than "not rated yet" — it is on every tile of the
                 owner's own reference screenshot. So there is still no markup
                 here for a product nobody has reviewed.

                 ── AND THE SPACE IS RESERVED ANYWAY ─────────────── Lane CARD ──

                 "i need all equal height in desktop and mobile both." `.cp`
                 carrying margin-top:auto lines the BUTTONS up across one row
                 and says nothing about the row above it, because `height:100%`
                 equalises a grid item against its own row only. Measured on the
                 shipped card, twelve products, showcase skin: at 1280 the three
                 rows came back 468.91 / 442.91 / 468.89 and at 390 the six rows
                 421.84 ×3, 395.84 ×2, 421.84 — the short ones being the rows
                 whose every product happened to be unreviewed, 26px shorter,
                 which is this row's 18px line plus its 8px margin exactly.

                 The showcase family therefore reserves a fixed grid TRACK for
                 this row — `--sc-rate-slot` in kbb-grid-skins.css — so the card
                 is the same height with the row and without it. An empty <div>
                 is not how it is reserved: the space is held by the layout, so
                 an unreviewed product still emits nothing at all. --}}
            <div class="kbb-card-rate"><span class="kbb-crate">@for ($s = 1; $s <= 5; $s++)<span class="kbb-cstar{{ $s <= $rating ? ' on' : '' }}">★</span>@endfor</span> <span class="kbb-card-rc">({{ $rc }})</span></div>
        @endif
        <div class="cp">{!! $kbbPriceHtml !!}</div>
        @if ($kbbCanAdd)
            {{-- A REAL href, so the tile still works with JavaScript off — this
                 is the one thing the skinned card's <span> gave up. cart.js
                 calls preventDefault() on [data-kbb-add] and opens the drawer
                 instead; the WooCommerce classes are the theme's and are what
                 the imported markup expects. --}}
            <a class="kbb-card-cart add_to_cart_button ajax_add_to_cart" href="?add-to-cart={{ $product->publicId() }}" data-quantity="1" data-product_id="{{ $product->id }}" data-kbb-add="{{ $product->id }}" data-price="{{ number_format($product->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $name }}" rel="nofollow">{{ __('store.product_card.add_to_cart') }}</a>
        @else
            {{-- No data-kbb-add and no data-price: this product is bought by its
                 variation, so the tile sends the shopper to the page where the
                 option is chosen rather than binding an add nothing can price. --}}
            <a class="kbb-card-cart" href="{{ $link }}">{{ __('store.product_card.view_product') }}</a>
        @endif
    </div>
</div>
