{{--
    Product card — ported verbatim from kbb_product_card() in functions.php.

    Classes are the theme's: .pc / .ph / .cbody / .cbrand / .cname / .crate /
    .cprice / .addbtn. My earlier version invented .pb / .pbrand / .pname /
    .prate / .pprice, so almost none of the card CSS matched and every grid on
    the site rendered unstyled.
--}}
@props(['product'])

@php
    // Catalogue → Wishlist. The heart is markup only until the module is on.
    $kbbWishlist = app(\App\Services\SettingsService::class)->moduleEnabled('wishlist', false);

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

    $phStyle = $img
        ? "background:#fff url('" . e($img) . "') center/contain no-repeat"
        : 'background:' . \App\Support\Gradient::for($brand . $name);

    $binit = $img
        ? ''
        : '<span class="binit">' . e(\App\Support\Gradient::initials($brand ?: $name)) . '<br>' . e($brand ?: $name) . '</span>';

    $canAdd = $product->stock_status === 'instock' && $product->type !== 'variable';
@endphp

<div class="pc">
    <div class="ph" style="{{ $phStyle }}" onclick="location.href='{{ $link }}'">
        {!! $binit !!}
        {!! $label !!}
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
            <a class="addbtn add_to_cart_button ajax_add_to_cart" href="?add-to-cart={{ $product->publicId() }}" data-quantity="1" data-product_id="{{ $product->id }}" data-kbb-add="{{ $product->id }}" rel="nofollow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.2"/><circle cx="18" cy="20" r="1.2"/></svg> Add to cart
            </a>
        @else
            <a class="addbtn" href="{{ $link }}">View product</a>
        @endif
    </div>
</div>
