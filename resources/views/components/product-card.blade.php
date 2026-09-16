{{--
    Product card — ported verbatim from kbb_product_card() in functions.php.

    Classes are the theme's: .pc / .ph / .cbody / .cbrand / .cname / .crate /
    .cprice / .addbtn. My earlier version invented .pb / .pbrand / .pname /
    .prate / .pprice, so almost none of the card CSS matched and every grid on
    the site rendered unstyled.
--}}
@props(['product', 'eager' => false])

@php
    // Catalogue → Wishlist. The heart is markup only until the module is on.
    $kbbWishlist = app(\App\Services\SettingsService::class)->moduleEnabled('wishlist', false);

    // Catalogue → Quick view. Registered in ModuleRegistry, default on.
    $kbbQuickView = app(\App\Services\SettingsService::class)->moduleEnabled('quick_view', true);

    $brand  = $product->brand?->name ?? '';
    $name   = $product->name;
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

        if ($product->isOnSale()) {
            $off = $product->discountPercent();
            $label = $off ? '<span class="lbl" style="background:#E23A4E">-' . $off . '% OFF</span>' : '';
        } elseif ($product->featured) {
            $label = '<span class="lbl" style="background:#1b9e77">Bestseller</span>';
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
        : 'background:' . \App\Support\Gradient::for($brand . $name);

    $binit = $img
        ? ''
        : '<span class="binit">' . e(\App\Support\Gradient::initials($brand ?: $name)) . '<br>' . e($brand ?: $name) . '</span>';

    $canAdd = $product->stock_status === 'instock' && $product->type !== 'variable';
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
                 @if ($eager) loading="eager" fetchpriority="high" @else loading="lazy" @endif
                 decoding="async">
        @endif
        {!! $binit !!}
        {!! $label !!}
        @if ($kbbQuickView)
        <button class="qv-btn" type="button" aria-label="Quick view" data-kbb-qv="{{ $product->id }}" onclick="event.stopPropagation()">Quick view</button>
        @endif
        @if ($kbbWishlist)<button class="heart" type="button" aria-label="Save" data-kbb-wish="{{ $product->id }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg></button>@endif
    </div>
    <div class="cbody">
        @if ($brand)<div class="cbrand">{{ $brand }}</div>@endif
        <a class="cname" href="{{ $link }}">{{ $name }}</a>
        @if ($rc > 0)
            <div class="crate"><span class="st">{{ str_repeat('★', max(1, (int) round($rating))) }}</span> {{ number_format($rating, 1) }} · {{ $rc > 999 ? round($rc / 1000, 1) . 'k' : $rc }}</div>
        @else
            <div class="crate" style="visibility:hidden">·</div>
        @endif
        <div class="cprice">
            @if ($product->isOnSale())
                <del>{!! \App\Support\Money::format((int) $product->price) !!}</del> <ins>{!! \App\Support\Money::format($product->effectivePrice()) !!}</ins>
            @else
                {!! \App\Support\Money::format($product->effectivePrice()) !!}
            @endif
        </div>
        @if ($canAdd)
            <a class="addbtn add_to_cart_button ajax_add_to_cart" href="?add-to-cart={{ $product->publicId() }}" data-quantity="1" data-product_id="{{ $product->id }}" data-kbb-add="{{ $product->id }}" data-price="{{ number_format($product->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $product->name }}" rel="nofollow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.2"/><circle cx="18" cy="20" r="1.2"/></svg> Add to cart
            </a>
        @else
            <a class="addbtn" href="{{ $link }}">View product</a>
        @endif
    </div>
</div>
