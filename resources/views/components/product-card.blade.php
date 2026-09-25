{{--
    Product card — ported verbatim from kbb_product_card() in functions.php.

    Classes are the theme's: .pc / .ph / .cbody / .cbrand / .cname / .crate /
    .cprice / .addbtn. My earlier version invented .pb / .pbrand / .pname /
    .prate / .pprice, so almost none of the card CSS matched and every grid on
    the site rendered unstyled.

    -- WHAT THIS COMPONENT NEEDS ALREADY LOADED ----------------------------

        brand   `$product->brand?->t('name')` and the placeholder's seed

    AND NOTHING ELSE, which is worth stating rather than leaving to be
    rediscovered: this card does NOT read `categories` -- the skinned grid in
    components/product-grid.blade.php does -- and it does not touch `variants`
    either. App\Services\VariantPricing answers the range for a variable
    parent from one grouped query per request, taken only when a tile like this
    is on the page, so a catalogue of simple products costs nothing for it.

    `brand` has to arrive loaded. A `loadMissing` here would be WORSE than the
    lazy load it replaces: this component is handed ONE model, so it would be
    one query per card either way, written where no caller could eager-load it
    away. So a caller's query says:

        ->with('brand:id,name,slug')

    THREE CALLERS TODAY: store/shop.blade.php, the related rail in
    store/product.blade.php, and store/routines.blade.php. All three are named
    in tests/Feature/ComponentLoadContractTest.php, which renders each of them
    with `Model::preventLazyLoading()` on; a fourth caller reddens that file
    until it is named there too.
--}}
@props(['product', 'eager' => false])

@php
    // Catalogue → Wishlist. The heart is markup only until the module is on.
    $kbbWishlist = app(\App\Services\SettingsService::class)->moduleEnabled('wishlist', false);

    // Catalogue → Quick view. Registered in ModuleRegistry, default on.
    $kbbQuickView = app(\App\Services\SettingsService::class)->moduleEnabled('quick_view', true);

    // t(), not the column. On English these two ARE the column — t() returns
    // early for the default locale — so an English card is the same bytes it
    // was. On /ar they are the Arabic the owner typed, and blank still means
    // untranslated, which falls back to the English name rather than to a gap
    // where a product name should be.
    $brand  = $product->brand?->t('name') ?? '';
    $name   = $product->t('name');

    // THE TILE'S COLOUR IS SEEDED FROM THE ENGLISH, DELIBERATELY. Gradient::for
    // hashes the string it is given, so seeding it with the translated name
    // would give a product one colour on /shop and a different one on /ar/shop
    // — the same shop repainted, for a value nobody reads. Nothing below is
    // printed from this; the initials and the words in the placeholder come
    // from $brand and $name above and are translated.
    $seed   = ($product->brand?->name ?? '') . $product->name;
    $link   = $product->url();
    $rating = (float) $product->rating;
    $rc     = (int) $product->review_count;
    $img    = $product->image;

    // Catalogue → Product Labels takes over the badge entirely when it is on —
    // including deciding there should not be one. That is the plugin's behaviour:
    // its filter returns an empty string rather than falling back to the theme's
    // badge, so turning the module on and matching nothing means no badge.
    $kbbLabel = app(\App\Services\ProductLabels::class)->for($product);

    if ($kbbLabel !== null) {
        $label = '<span class="lbl" style="background:' . e($kbbLabel['colour']) . '">'
               . e($kbbLabel['text']) . '</span>';
    } elseif (app(\App\Services\SettingsService::class)->moduleEnabled('product_labels', false)) {
        $label = '';
    } else {
        // The theme's own, unchanged, for as long as the module is off.
        $label = '';

        // `$off >= 1` rather than `isOnSale()` then a guard INSIDE the branch,
        // which is what this said. The two are not the same thing: a markdown
        // that rounds to nothing took the sale branch, emitted the empty string
        // and then stopped, so a featured product marked down from AED 100.00
        // to AED 99.80 lost its Bestseller badge to a sale badge that was never
        // drawn. ProductLabels::for() falls THROUGH to the next rule in exactly
        // this case and says so in its own comment; the theme's copy now does
        // the same, so turning the module on and off cannot change which badge
        // a card carries.
        $off = $product->isOnSale() ? $product->discountPercent() : 0;

        if ($off >= 1) {
            $label = '<span class="lbl" style="background:#E23A4E">'
                   . '<bdi>' . e(__('store.product_card.label_off', ['percent' => $off])) . '</bdi>'
                   . '</span>';
        } elseif ($product->featured) {
            $label = '<span class="lbl" style="background:#1b9e77">' . e(__('store.product_card.label_bestseller')) . '</span>';
        }
    }

    // The photograph is a real <img> now, not a CSS background on .ph.
    //
    // It was a white background with the photograph painted over it by the
    // background shorthand, centred and contained. The same three things were
    // wrong with it that were already fixed on the product page (see
    // partials/product-gallery.blade.php, which this now matches):
    //
    //  * loading="lazy" has no background-image equivalent, so every photograph
    //    in the grid was fetched the moment the page opened. Measured on /shop
    //    against a 671-product catalogue: 21 photographs, 4.4MB, of which four
    //    are above the fold on a 390px phone. The other seventeen were paid for
    //    by a shopper who may never scroll to them.
    //  * the preload scanner cannot see a URL that exists only inside a style
    //    attribute, so the largest element in the grid was undiscoverable until
    //    the stylesheet had been parsed.
    //  * a crawler treats a background as decoration, so no product photograph
    //    in any grid on this site could be indexed by Google Images, and a
    //    screen reader was told nothing at all.
    //
    // .ph keeps its own background for the no-photograph case and stays a
    // CSS-sized box -- a fixed 180-pixel frame -- which is what reserves the
    // space; the <img> is absolutely positioned inside it and therefore cannot
    // move anything, whatever order the bytes arrive in. Containing the image
    // inside that frame is the exact equivalent of the shorthand it replaces,
    // so the framing is unchanged: letterboxed, never cropped.
    $phStyle = $img
        ? 'background:#fff'
        : 'background:' . \App\Support\Gradient::for($seed);

    $binit = $img
        ? ''
        : '<span class="binit">' . e(\App\Support\Gradient::initials($brand ?: $name)) . '<br>' . e($brand ?: $name) . '</span>';

    // Product::isDirectlyBuyable() rather than the two tests spelt out here,
    // which is what this line was. Same answer, but it is now the SAME
    // EXPRESSION the skinned grid reads and the same predicate
    // CartService::add() refuses on, so the button a shopper sees and the door
    // the request goes through cannot disagree. The grid had no copy of this
    // rule at all and sold variable products for AED 0.
    $canAdd = $product->isDirectlyBuyable();

    /*
     * A VARIABLE PRODUCT'S PRICE IS ON ITS VARIATIONS, AND THIS TILE PRINTED
     * AED 0 FOR IT.
     *
     * `products.price` is NULL on a variable parent — WooCommerce keeps the
     * figures on the variations, which is the same fact $canAdd above is about
     * — and Product::effectivePrice() ends `return (int) $this->price`, which
     * makes that 0. So every tile for a variable product advertised AED 0,
     * while the product page beside it published a correct AggregateOffer
     * built from those same variations.
     *
     * App\Services\VariantPricing reads the range where this tile reads: ONE
     * grouped query per request, taken only when a tile like this is actually
     * on the page, and null for every other product — so a catalogue of simple
     * products renders byte for byte what it rendered before, at no extra
     * query.
     *
     * THE ZERO IS NOT FIXED, ONLY THE LIE. effectivePrice() still answers 0 for
     * these rows, so the price SORT and the price FACET still file them first
     * and cheapest. Correcting that means backfilling the column or changing
     * the importer, and both are somebody else's file; this is the half that
     * could be done without deciding it.
     *
     * COMPUTED HERE, IN THE BLOCK THAT EMITS NOTHING, AND NOT BESIDE THE MARKUP
     * THAT USES IT. A `@php` line of its own down in the body adds its own
     * indentation and newline to the rendered page, and
     * StorefrontEnglishUnchangedTest compares BYTES: it caught exactly that,
     * on /shop, on a category archive and on the product page's related rail,
     * for a tile whose visible content had not changed at all.
     */
    $kbbRange = app(\App\Services\VariantPricing::class)->range($product);

    /*
     * THE WHOLE PRICE STRING, BUILT HERE. Both ends at ONE precision, and the
     * precision that separates them -- the same rule as the sale pair below,
     * and for the same reason: two figures the reader is invited to compare
     * must not round into the same string. Every option at the same money is a
     * single price, not a range of one; Seo::aggregateOffer() declines to
     * publish a lowPrice equal to its highPrice for that reason.
     */
    $kbbRangeHtml = null;

    if ($kbbRange !== null) {
        $kbbRangeDp = \App\Support\Money::decimalsToDistinguish($kbbRange[0], $kbbRange[1]);

        $kbbRangeHtml = \App\Services\VariantPricing::isSpread($kbbRange)
            ? __('store.product_card.price_range', [
                'low' => \App\Support\Money::format($kbbRange[0], $kbbRangeDp),
                'high' => \App\Support\Money::format($kbbRange[1], $kbbRangeDp),
            ])
            : \App\Support\Money::format($kbbRange[0]);
    }

    // A 1000x1000 photograph painted into a frame that is never wider than 399
    // CSS pixels. What the tile can offer instead is whatever phone-sized copy
    // of that photograph is on disk right now -- see App\Support\ImageVariants
    // for why the copies are made when an image is uploaded rather than when a
    // page asks for one, and why the answer is read off the filesystem.
    //
    // '' WHEN THERE IS NO COPY, AND THAT IS THE IMPORTANT CASE. Most of this
    // catalogue predates the copies, and some of it will never have any -- a
    // photograph hosted on another domain, an SVG, an original already smaller
    // than 400px. A srcset listing a file that is not there is worse than no
    // srcset at all: with `w` descriptors the browser picks a candidate and
    // never looks at src, so one 404 is a blank tile with nothing to fall back
    // to. So the attribute is emitted only when a real file backs every
    // candidate in it, and a tile with nothing to offer renders exactly the
    // markup it renders today.
    $srcset = $img ? \App\Support\ImageVariants::srcsetFor($img) : '';
@endphp

<div class="pc">
    <div class="ph" style="{{ $phStyle }}" onclick="location.href='{{ $link }}'">
        @if ($img)
            {{-- `eager` is passed by the page that knows this card is the first
                 one in its grid, which is the LCP candidate at both widths.
                 Everything else is lazy: a browser still fetches a lazy image
                 that is already inside the viewport, so the cards beside this
                 one are not delayed -- what lazy buys is the rest of the page,
                 which is most of it. Related products and every other grid get
                 the default, because none of them is ever the LCP. --}}
            <img class="ph-img" src="{{ $img }}" alt="{{ $product->altFor($img) }}"
                 @if ($srcset !== '') srcset="{{ $srcset }}" sizes="{{ \App\Support\ImageVariants::sizesAttribute() }}" @endif
                 @if ($eager) loading="eager" fetchpriority="high" @else loading="lazy" @endif
                 decoding="async">
            {{-- No width/height here, unchanged from the conversion that made
                 this an <img>: .ph is height:180px at a fractional grid width,
                 so there is no intrinsic ratio that would be true, and the
                 image is position:absolute;inset:0 and therefore out of flow.
                 The CSS box reserves the space; layout never asks the file how
                 big it is, which is why srcset cannot move anything either. --}}
        @endif
        {!! $binit !!}
        {!! $label !!}
        @if ($kbbQuickView)
        <button class="qv-btn" type="button" aria-label="{{ __('store.product_card.quick_view') }}" data-kbb-qv="{{ $product->id }}" onclick="event.stopPropagation()">{{ __('store.product_card.quick_view') }}</button>
        @endif
        @if ($kbbWishlist)<button class="heart" type="button" aria-label="{{ __('store.product_card.save_label') }}" data-kbb-wish="{{ $product->id }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg></button>@endif
    </div>
    <div class="cbody">
        @if ($brand)<div class="cbrand">{{ $brand }}</div>@endif
        <a class="cname" href="{{ $link }}">{{ $name }}</a>
        @if ($rc > 0)
            <div class="crate"><span class="st">{{ str_repeat('★', max(1, (int) round($rating))) }}</span> {{ number_format($rating, 1) }} · {{ $rc > 999 ? __('store.product_card.count_thousands', ['count' => round($rc / 1000, 1)]) : $rc }}</div>
        @else
            <div class="crate" style="visibility:hidden">·</div>
        @endif
        {{-- BOTH FIGURES AT ONE PRECISION, AND A PRECISION THAT SEPARATES THEM.
             Money::format() rounds to whole dirhams here, so a markdown from
             AED 100.00 to AED 99.80 printed `<del>AED 100</del> <ins>AED 100</ins>`
             — a struck-through price identical to the one beside it, which
             reads as a sale that took nothing off. Money::decimalsToDistinguish()
             returns the store's usual 0 whenever the rounded figures already
             differ (so every honest sale renders byte-for-byte as before) and
             the currency's own precision only for the pair that would collide.
             Both calls take the SAME width, or the two numbers would be quoted
             on different scales, which is the same lie in a new shape. --}}
        <div class="cprice">
            @if ($kbbRangeHtml !== null)
                {!! $kbbRangeHtml !!}
            @elseif ($product->isOnSale())
                @php $kbbSaleDp = \App\Support\Money::decimalsToDistinguish((int) $product->price, $product->effectivePrice()); @endphp
                <del>{!! \App\Support\Money::format((int) $product->price, $kbbSaleDp) !!}</del> <ins>{!! \App\Support\Money::format($product->effectivePrice(), $kbbSaleDp) !!}</ins>
            @else
                {!! \App\Support\Money::format($product->effectivePrice()) !!}
            @endif
        </div>
        @if ($canAdd)
            <a class="addbtn add_to_cart_button ajax_add_to_cart" href="?add-to-cart={{ $product->publicId() }}" data-quantity="1" data-product_id="{{ $product->id }}" data-kbb-add="{{ $product->id }}" data-price="{{ number_format($product->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $name }}" rel="nofollow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.2"/><circle cx="18" cy="20" r="1.2"/></svg> {{ __('store.product_card.add_to_cart') }}
            </a>
        @else
            <a class="addbtn" href="{{ $link }}">{{ __('store.product_card.view_product') }}</a>
        @endif
    </div>
</div>
