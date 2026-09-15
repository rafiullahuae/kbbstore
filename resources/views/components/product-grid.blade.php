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
    'moreLabel' => 'View all',
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
        @if ($moreUrl)<a class="lnk" href="{{ \App\Support\Url::to($moreUrl) }}">{{ $moreLabel }}</a>@endif
    </div>
@endif

<div class="kbb-pgrid" data-skin="{{ $skin }}"
     style="--kbb-cols:{{ $cols }};--kbb-cols-m:{{ $colsMobile }}">
    @foreach ($products as $p)
        @php
            $brand = $p->brand?->name ?? '';
            $cat = $p->categories->first()?->name;
            $onSale = $p->isOnSale();
            $off = $onSale ? $p->discountPercent() : 0;
            $isNew = $p->created_at && $p->created_at->gt(now()->subDays(30));
            $rating = (int) round((float) $p->rating);
        @endphp
        <a class="kbb-card" href="{{ $p->url() }}">
            <div class="kbb-card-thumb">
                @if ($p->image)
                    <img src="{{ $p->image }}" alt="{{ $p->name }}" loading="lazy" width="400" height="500">
                @else
                    {{-- Same gradient fallback the rest of the site uses, so a
                         product without a photo still fills the frame. --}}
                    <span class="kbb-card-ph" style="background:{{ \App\Support\Gradient::for($brand . $p->name) }}">{{ \App\Support\Gradient::initials($brand ?: $p->name) }}</span>
                @endif
                @if ($isNew)<span class="kbb-badge kbb-badge-new">New</span>@endif
                @if ($off)<span class="kbb-badge kbb-badge-sale">-{{ $off }}%</span>@endif
            </div>
            <div class="cb">
                @if ($cat)<div class="kbb-card-cat">{{ $cat }}</div>@endif
                <div class="cn">@if ($brand)<span class="kbb-card-brand">{{ mb_strtoupper($brand) }}</span> @endif{{ $p->name }}</div>
                @if ($p->review_count)
                    <div class="kbb-card-rate"><span class="kbb-crate">@for ($i = 1; $i <= 5; $i++)<span class="kbb-cstar{{ $i <= $rating ? ' on' : '' }}">★</span>@endfor</span> <span class="kbb-card-rc">({{ $p->review_count }})</span></div>
                @endif
                <div class="cp">@if ($onSale)<span class="kbb-card-reg">{!! \App\Support\Money::format((int) $p->price) !!}</span> @endif<span class="kbb-card-price">{!! \App\Support\Money::format($p->effectivePrice()) !!}</span></div>
                <span class="kbb-card-cart" data-kbb-add="{{ $p->id }}" data-price="{{ number_format($p->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $p->name }}">Add to cart</span>
            </div>
        </a>
    @endforeach
</div>
