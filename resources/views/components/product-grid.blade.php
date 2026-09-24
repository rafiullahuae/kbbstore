{{--
    Product grid — the skinnable card from kbb-grid-skins.html.

    One markup shape, 28 skins. Everything that changes between skins lives in
    kbb-grid-skins.css under .kbb-pgrid[data-skin="..."], so the card is written
    once and never branches.

    Usage:
        <x-product-grid :products="$products" />                 default skin
        <x-product-grid :products="$products" skin="luxe" />      forced skin
        <x-product-grid :products="$products" :columns="3" />
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
    $settings = app(\App\Services\SettingsService::class);
    $cols = $columns ?: (int) $settings->get('grid_columns', 4);
    // Phones never take the desktop count; two is the most a 390px screen holds.
    $colsMobile = $columnsMobile ?: max(1, min(2, (int) $settings->get('grid_columns_mobile', 2)));
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

<div class="kbb-pgrid" data-skin="{{ $skin }}"
     style="--kbb-cols:{{ $cols }};--kbb-cols-m:{{ $colsMobile }}">
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
            $kbbDp = $onSale ? \App\Support\Money::decimalsToDistinguish((int) $p->price, $p->effectivePrice()) : null;
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
                <div class="cp">@if ($kbbRangeHtml !== null)<span class="kbb-card-price">{!! $kbbRangeHtml !!}</span>@elseif ($onSale)<span class="kbb-card-reg">{!! \App\Support\Money::format((int) $p->price, $kbbDp) !!}</span> <span class="kbb-card-price">{!! \App\Support\Money::format($p->effectivePrice(), $kbbDp) !!}</span>@else<span class="kbb-card-price">{!! \App\Support\Money::format($p->effectivePrice(), $kbbDp) !!}</span>@endif</div>
                <span class="kbb-card-cart" data-kbb-add="{{ $p->id }}" data-price="{{ number_format($p->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $name }}">{{ __('store.product_card.add_to_cart') }}</span>
            </div>
        </a>
    @endforeach
</div>
