{{--
    Product grid — the skinnable card from kbb-grid-skins.html.

    One markup shape, 28 skins. Everything that changes between skins lives in
    kbb-grid-skins.css under .kbb-pgrid[data-skin="..."], so the card is written
    once and never branches.

    Usage:
        <x-product-grid :products="$products" />                 default skin
        <x-product-grid :products="$products" skin="luxe" />      forced skin
        <x-product-grid :products="$products" :columns="3" />

    -- WHAT THIS COMPONENT NEEDS ALREADY LOADED ----------------------------

        brand        `$p->brand?->t('name')` and the placeholder's seed
        categories   `$p->categories->first()?->t('name')`, the eyebrow line

    BOTH HAVE TO ARRIVE LOADED. This component does not fetch them and must
    not: it is handed a collection, and a `loadMissing` here would hide the
    caller's query from the caller. A caller that forgets gets no error and no
    wrong pixel -- it gets ONE `brands` read and ONE `category_product` read
    PER TILE. Measured on the brand landing page at 1, 2, 5 and 10 tiles:
    5/5/5/5 statements as shipped, 5/6/9/14 without `categories:id,name`,
    5/7/13/23 without either.

    So a caller's query says, character for character:

        ->with(['brand:id,name,slug', 'categories:id,name'])

    TWO CALLERS TODAY, and the second is not a `<x-product-grid>` tag:
    store/brands.blade.php, and App\Support\Shortcodes::products(), which
    renders this file as a view for `[kbb_products]`. Both are named in
    tests/Feature/ComponentLoadContractTest.php, which renders each of them
    with `Model::preventLazyLoading()` on; a third caller reddens that file
    until it is named there too.
--}}
@props([
    'products',
    'skin' => null,
    'columns' => null,
    'columnsMobile' => null,
    'heading' => null,
    'subheading' => null,
    'moreUrl' => null,
    'moreLabel' => null,
])

@php
    $skin = \App\Support\GridSkins::resolve($skin);
    /*
     * -- THE COLUMN COUNT IS NOT SET HERE ANY MORE --------------- Lane W1 --
     *
     * This block used to read `grid_columns` and `grid_columns_mobile` and emit
     * them as --kbb-cols / --kbb-cols-m on the grid below. Both were dropped,
     * and the reason is that they governed ONE of the five product grids on
     * this shop:
     *
     *   .kbb-pgrid via THIS component   read them          (brand page, and
     *                                                       [kbb_products])
     *   .kbb-pgrid via partials/home/grid   did NOT        (homepage rails,
     *                                                       a category,
     *                                                       the wishlist)
     *   .grid on /shop                  did NOT            (its own data-cols)
     *   .rel on a product page          did NOT            (its own repeat(4))
     *   .brw-grid on the brand landing  did NOT            (its own --brw-min)
     *
     * and the tablet count, `grid_columns_tablet`, governed NONE of them: the
     * only writer of --kbb-cols-t is ProductStyles::cssVariables(), which is
     * called from resources/views/admin/app.blade.php and nowhere else, so that
     * slider has never moved a pixel of the shop. One setting that reaches one
     * grid of five is not a setting worth preserving byte-for-byte; it is the
     * inconsistency the owner asked to be given real control over.
     *
     * The count comes from --kbb-track in kbb.css now, which derives it from
     * the row the grid actually has, and from Appearance -> Site layout, which
     * reaches all five. See docs/W1-SITE-WIDTH.md.
     *
     * `columns` AND `columnsMobile` ARE STILL HONOURED, because they are a
     * CALLER's instruction and not a stored setting: `[kbb_products columns="3"]`
     * is somebody writing 3 in a page. A caller's count is a pin, so it is
     * emitted as a grid-template-columns override rather than as a custom
     * property -- kbb.css records why a pin may not be a custom property here.
     */
    /*
     * A CALLER'S COUNT IS BOUNDED AND A NON-POSITIVE ONE IS NOT A COUNT.
     *
     * These reach `grid-template-columns:repeat(N,…)` in a <style> element, so an
     * unbounded N is a shortcode that can emit `repeat(999999,minmax(0,1fr))` and
     * stop the page laying out. `max(1, min(8, …))` was the first draft and it
     * turned `columns="-4"` into a pin of ONE full-width column, which is neither
     * what the page asked for nor the automatic answer — so anything below 1 is
     * no pin at all and the grid decides for itself.
     */
    $pinCols = ($columns !== null && (int) $columns >= 1) ? min(8, (int) $columns) : null;
    $pinMobile = ($columnsMobile !== null && (int) $columnsMobile >= 1) ? min(2, (int) $columnsMobile) : null;
@endphp

@if ($heading || $moreUrl)
    <div class="kbb-gridhead">
        <div>
            @if ($heading)<h2>{{ $heading }}</h2>@endif
            @if ($subheading)<p>{{ $subheading }}</p>@endif
        </div>
        @if ($moreUrl)<a class="lnk" href="{{ \App\Support\Url::to($moreUrl) }}">{{ $moreLabel ?? __('store.product_grid.view_all') }}</a>@endif
    </div>
@endif

@if ($pinCols || $pinMobile)
    {{-- A caller's pin. Scoped by an id so one grid on the page can be pinned
         without pinning the rest, and split at 900px the way every other pin in
         this system is: a desktop count has no business on a phone. --}}
    @php
        $pinId = 'kbbg'.substr(sha1((string) ($pinCols ?? '').'-'.($pinMobile ?? '')), 0, 8);
    @endphp
    <style>@if ($pinCols)@media(min-width:901px){#{{ $pinId }}{grid-template-columns:repeat({{ $pinCols }},minmax(0,1fr))}}@endif @if ($pinMobile)@media(max-width:900px){#{{ $pinId }}{grid-template-columns:repeat({{ $pinMobile }},minmax(0,1fr))}}@endif</style>
@endif
<div class="kbb-pgrid" data-skin="{{ $skin }}"@if ($pinCols || $pinMobile) id="{{ $pinId }}"@endif>
    @foreach ($products as $p)
        @php
            // See product-card.blade.php: t() is the column on English and the
            // owner's Arabic on /ar, and $seed stays English so a product keeps
            // one colour in both languages.
            $brand = $p->brand?->t('name') ?? '';
            $cat = $p->categories->first()?->t('name');
            $name = $p->t('name');
            $seed = ($p->brand?->name ?? '') . $p->name;
            $onSale = $p->isOnSale();
            $off = $onSale ? $p->discountPercent() : 0;
            // See Money::decimalsToDistinguish(): 0 for every markdown the
            // rounded display can already tell apart, the currency's precision
            // for the pair that would otherwise print the same string twice.
            // $kbbWas, not `(int) $p->price`: a variable parent keeps its money
            // on its variations and that column is NULL, so a marked-down
            // variable product would quote `AED 0` as the regular price the
            // moment isOnSale() started answering true for one. See
            // Product::compareAtPrice() -- for anything with a price of its own
            // it is that same `(int) $p->price` and this line is unchanged.
            $kbbWas = (int) $p->compareAtPrice();
            $kbbDp = $onSale ? \App\Support\Money::decimalsToDistinguish($kbbWas, $p->effectivePrice()) : null;
            $isNew = $p->created_at && $p->created_at->gt(now()->subDays(30));
            $rating = (int) round((float) $p->rating);
            // See components/product-card.blade.php: a variable parent carries
            // no price of its own, effectivePrice() answers 0 for it, and this
            // skinned tile printed AED 0 exactly as the other one did. Null for
            // every product that is not one, which is almost all of them.
            $kbbRange = app(\App\Services\VariantPricing::class)->range($p);

            /*
             * THE WHOLE PRICE CELL, BUILT HERE AND NOT IN THE MARKUP, for two
             * reasons that both cost a red suite before this shape was found.
             *
             * A Blade directive on a line of its own contributes its
             * indentation and its newline to the rendered page, and
             * StorefrontEnglishUnchangedTest compares BYTES: an `@php` line and
             * a `{{-- --}}` line beside the markup moved /shop, a category
             * archive and the product page's related rail without changing one
             * visible character on any of them.
             *
             * And a nested `@else@if ... @endif @endif` inside one line does
             * not compile at all -- "syntax error, unexpected token endif",
             * which reaches the browser as a 500 on every brand page. One
             * value, decided here, leaves the markup a flat @if/@elseif/@else.
             */
            $kbbRangeHtml = null;

            if ($kbbRange !== null) {
                // Both ends at ONE precision, and the precision that separates
                // them -- the same rule as the sale pair beside it, and for the
                // same reason: two figures the reader is invited to compare
                // must not round into the same string.
                $kbbRangeDp = \App\Support\Money::decimalsToDistinguish($kbbRange[0], $kbbRange[1]);

                $kbbRangeHtml = \App\Services\VariantPricing::isSpread($kbbRange)
                    ? __('store.product_card.price_range', [
                        'low' => \App\Support\Money::format($kbbRange[0], $kbbRangeDp),
                        'high' => \App\Support\Money::format($kbbRange[1], $kbbRangeDp),
                    ])
                    // Every option the same money is a single price, not a
                    // range of one. Seo::aggregateOffer() declines to publish a
                    // lowPrice equal to its highPrice for the same reason.
                    : \App\Support\Money::format($kbbRange[0]);
            }

            /*
             * CAN THIS TILE PUT IT IN THE BASKET ON ITS OWN?
             *
             * THIS TILE NEVER ASKED, AND THAT IS THE DEFECT. It drew an
             * `Add to cart` on every product, including a VARIABLE one, which
             * is bought by its variation and carries no price of its own:
             * CartService::add() priced the parent
             * `$variant?->effectivePrice() ?? $product->effectivePrice()`,
             * a NULL `price` column casts to 0, and the basket took a line at
             * AED 0 that the checkout would then collect.
             * components/product-card.blade.php has had this test since it was
             * written -- as `$canAdd` -- and this template simply did not.
             *
             * Product::isDirectlyBuyable() is that one expression, now read by
             * both tiles AND by the refusal in CartService::add(), so the
             * button a shopper sees and the door the request goes through
             * cannot give different answers.
             */
            $kbbCanAdd = $p->isDirectlyBuyable();

            /*
             * AND THE BUTTON IS BRANCHED INSIDE ITS ONE LINE, never across
             * several. A Blade directive on a line of its own contributes its
             * indentation and its newline to the rendered page, and
             * StorefrontEnglishUnchangedTest compares BYTES -- that is how the
             * same mistake moved /shop, a category archive and the related rail
             * last round without changing one visible character. A product that
             * CAN be added renders exactly the bytes it rendered before.
             *
             * WHAT THE OTHER ANSWER IS. The whole tile is already wrapped in
             * `<a class="kbb-card" href="{product url}">`, so a span carrying no
             * data-kbb-add simply follows that link to the page where the
             * option is chosen. No new markup, no new CSS -- resources/css/kbb
             * belongs to another lane -- and no new string: `View product` is
             * the word components/product-card.blade.php already uses for this
             * exact case.
             *
             * data-kbb-add AND data-price GO WITH IT, deliberately. data-price
             * was the AED 0; data-kbb-add is what cart.js binds and what
             * MarketingPixels' capture listener counts an add-to-cart on, and
             * no add happens here any more.
             */
        @endphp
        <a class="kbb-card" href="{{ $p->url() }}">
            <div class="kbb-card-thumb">
                @if ($p->image)
                    @php $pgSrcset = \App\Support\ImageVariants::srcsetFor($p->image); @endphp
                    <img src="{{ $p->image }}" alt="{{ $name }}" loading="lazy" width="400" height="500"
                         @if ($pgSrcset !== '') srcset="{{ $pgSrcset }}" sizes="{{ \App\Support\ImageVariants::skinGridSizesAttribute() }}" @endif>
                @else
                    {{-- Same gradient fallback the rest of the site uses, so a
                         product without a photo still fills the frame. --}}
                    <span class="kbb-card-ph" style="background:{{ \App\Support\Gradient::for($seed) }}">{{ \App\Support\Gradient::initials($brand ?: $name) }}</span>
                @endif
                @if ($isNew)<span class="kbb-badge kbb-badge-new">{{ __('store.product_card.badge_new') }}</span>@endif
                @if ($off)<span class="kbb-badge kbb-badge-sale">{{ \App\Support\Bidi::number('-' . $off . '%') }}</span>@endif
            </div>
            <div class="cb">
                @if ($cat)<div class="kbb-card-cat">{{ $cat }}</div>@endif
                <div class="cn">@if ($brand)<span class="kbb-card-brand">{{ mb_strtoupper($brand) }}</span> @endif{{ $name }}</div>
                @if ($p->review_count)
                    <div class="kbb-card-rate"><span class="kbb-crate">@for ($i = 1; $i <= 5; $i++)<span class="kbb-cstar{{ $i <= $rating ? ' on' : '' }}">★</span>@endfor</span> <span class="kbb-card-rc">({{ $p->review_count }})</span></div>
                @endif
                <div class="cp">@if ($kbbRangeHtml !== null)<span class="kbb-card-price">{!! $kbbRangeHtml !!}</span>@elseif ($onSale)<span class="kbb-card-reg">{!! \App\Support\Money::format($kbbWas, $kbbDp) !!}</span> <span class="kbb-card-price">{!! \App\Support\Money::format($p->effectivePrice(), $kbbDp) !!}</span>@else<span class="kbb-card-price">{!! \App\Support\Money::format($p->effectivePrice(), $kbbDp) !!}</span>@endif</div>
                <span class="kbb-card-cart"@if ($kbbCanAdd) data-kbb-add="{{ $p->id }}" data-price="{{ number_format($p->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $name }}"@endif>@if ($kbbCanAdd){{ __('store.product_card.add_to_cart') }}@else{{ __('store.product_card.view_product') }}@endif</span>
            </div>
        </a>
    @endforeach
</div>
