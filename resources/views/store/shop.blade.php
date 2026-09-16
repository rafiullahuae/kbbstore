{{--
    Shop / category / brand / search archive — ported verbatim from
    kbb-theme/archive-product.php.

    Filter sidebar, checkbox options with counts, price chips, offer toggles,
    column selector, sort, active chips, grid and pagination — same classes,
    same element order, same SVGs.

    On query parameters: the theme uses cat / brand / price / sale / instock /
    paged, while the live WooCommerce site uses filter_brands / orderby / page.
    Both are accepted, and the theme's are emitted, so the design matches while
    every indexed URL still resolves. (Rule 16 + the URL Contract.)
--}}
@extends('layouts.store')
@php use App\Support\Facets; use App\Support\Url; @endphp

@section('title', $title . ' · K-Beauty Bliss')

@push('styles')
    @vite('resources/css/kbb/kbb-shop.css')
    {{-- Only when there is a banner to draw. A category with no banner
         configured — which is every category until the owner turns one on —
         downloads nothing extra for a feature it is not using. --}}
    @if ($banner ?? null)
        @vite('resources/css/kbb/kbb-banner.css')
    @endif
@endpush

@section('content')
@php
    $ck = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 5 5L20 7"/></svg>';
@endphp

{{--
    The heading block and the banner are alternatives, not a stack.

    When a banner is on it carries the page's <h1> — a second one underneath
    it would be two competing headings on the same document, and the one a
    crawler picked would be the one the owner did not design. The breadcrumb
    stays above either, because it is navigation and belongs before the title
    whichever way the title is drawn.
--}}
<div class="wrap">
    <div class="crumb"><b>Home</b> / {{ $crumb }}</div>
    @unless ($banner ?? null)
        <div class="eyebrow">K-Beauty · Skincare</div>
        <h1 class="ptitle">{{ $title }}</h1>
        <p class="psub">{{ $sub }}</p>
    @endunless
</div>

<x-kbb-banner :banner="$banner ?? null" />

<div class="wrap shop">
    <aside class="filtercol" id="fcol">
        <div class="fpanel">
            <div class="fhead">
                <span class="ftitle">Filters</span>
                <button class="fhide" type="button" onclick="document.body.classList.add('filters-hidden')">Hide</button>
                <button class="fclose" type="button" onclick="document.body.classList.remove('filters-open')">✕</button>
            </div>
            <div id="filters">
                @if ($cats->isNotEmpty())
                <div class="fgroup"><h4>Category</h4>
                    @foreach ($cats as $c)
                        <a class="fopt{{ Facets::isOn('cat', $c->slug) ? ' on' : '' }}" href="{{ Facets::url('cat', $c->slug) }}">
                            <span class="cb">{!! $ck !!}</span>
                            <span class="catdot" style="background:var(--pink)"></span> {{ $c->name }}
                            <span class="ct">{{ $c->products_count }}</span>
                        </a>
                    @endforeach
                </div>
                @endif

                @if ($brands->isNotEmpty())
                <div class="fgroup"><h4>Brand</h4>
                    @foreach ($brands as $b)
                        <a class="fopt{{ Facets::isOn('brand', $b->slug) ? ' on' : '' }}" href="{{ Facets::url('brand', $b->slug) }}">
                            <span class="cb">{!! $ck !!}</span> {{ $b->name }}
                            <span class="ct">{{ $b->products_count }}</span>
                        </a>
                    @endforeach
                </div>
                @endif

                <div class="fgroup"><h4>Price</h4>
                    <div class="pchips">
                        @foreach ($buckets as $key => $b)
                            <a class="pchip{{ request('price') === $key ? ' on' : '' }}" href="{{ Facets::url('price', request('price') === $key ? null : $key, false) }}">{{ $b[0] }}</a>
                        @endforeach
                    </div>
                </div>

                <div class="fgroup"><h4>Offers</h4>
                    <a class="ftog" href="{{ Facets::url('sale', request('sale') === '1' ? null : '1', false) }}">On sale only<span class="tog{{ request('sale') === '1' ? ' on' : '' }}"></span></a>
                    <a class="ftog" href="{{ Facets::url('instock', request('instock') === '1' ? null : '1', false) }}">In stock only<span class="tog{{ request('instock') === '1' ? ' on' : '' }}"></span></a>
                </div>
            </div>
        </div>
    </aside>

    <main>
        <div class="gtop">
            <button class="mobi-filter" type="button" onclick="document.body.classList.add('filters-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M7 12h10M10 18h4"/></svg> Filters</button>
            <button id="showFilters" type="button" onclick="document.body.classList.remove('filters-hidden')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M7 12h10M10 18h4"/></svg> Show filters</button>
            <span class="gcount"><b>{{ $total }}</b> product{{ 1 === $total ? '' : 's' }}</span>
            <div class="gright">
                <div class="colsel" id="colsel">
                    <button type="button" data-c="2"@if ('2' === $cols) class="on"@endif title="2 columns"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="5" width="6.5" height="14" rx="1.5"/><rect x="13.5" y="5" width="6.5" height="14" rx="1.5"/></svg></button>
                    <button type="button" data-c="3"@if ('3' === $cols) class="on"@endif title="3 columns"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="5" width="4.5" height="14" rx="1.3"/><rect x="9.75" y="5" width="4.5" height="14" rx="1.3"/><rect x="16.5" y="5" width="4.5" height="14" rx="1.3"/></svg></button>
                    <button type="button" data-c="4"@if ('4' === $cols) class="on"@endif title="4 columns"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="2.5" y="5" width="3.4" height="14" rx="1"/><rect x="7.7" y="5" width="3.4" height="14" rx="1"/><rect x="12.9" y="5" width="3.4" height="14" rx="1"/><rect x="18.1" y="5" width="3.4" height="14" rx="1"/></svg></button>
                </div>
                <div class="sortsel">Sort
                    <select id="sort" onchange="var u=new URL(location.href);u.searchParams.set('orderby',this.value);u.searchParams.delete('paged');location.href=u.toString()">
                        @foreach ($sorts as $val => $lbl)
                            <option value="{{ $val }}" @selected($curorder === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        @if ($chips)
            <div class="achips">
                @foreach ($chips as $c)
                    @php $toggle = ! in_array($c['key'], ['price', 'sale', 'instock', 'max_price'], true); @endphp
                    <span class="achip">{{ $c['label'] }}<a href="{{ Facets::url($c['key'], $toggle ? $c['value'] : null, $toggle) }}" style="display:grid;place-items:center">✕</a></span>
                @endforeach
                <a class="achip clear" href="{{ $clearUrl }}">Clear all</a>
            </div>
        @endif

        <div class="grid" id="grid" data-cols="{{ $cols }}">
            @forelse ($products as $product)
                <x-product-card :product="$product" />
            @empty
                <div class="empty" style="grid-column:1/-1"><b>No products match those filters</b>Try removing a filter or clearing all.</div>
            @endforelse
        </div>

        @if ($lastPage > 1)
            <div class="sec-cta" style="margin-top:30px">
                @if ($page > 1)<a class="page-numbers" href="{{ Facets::pageUrl($page - 1) }}">‹</a>@endif
                @foreach (range(max(1, $page - 1), min($lastPage, $page + 1)) as $n)
                    @if ($n === $page)
                        <span class="page-numbers current">{{ $n }}</span>
                    @else
                        <a class="page-numbers" href="{{ Facets::pageUrl($n) }}">{{ $n }}</a>
                    @endif
                @endforeach
                @if ($page < $lastPage)<a class="page-numbers" href="{{ Facets::pageUrl($page + 1) }}">›</a>@endif
            </div>
        @endif
    </main>
</div>
@endsection
