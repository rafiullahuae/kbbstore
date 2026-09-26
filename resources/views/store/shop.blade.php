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

@section('title', __('store.shop.page_title', ['title' => $title]))

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

    /*
     * -- DID THE SHOPPER ASK FOR A COLUMN COUNT? ----------------- Lane W1 --
     *
     * `$cols` is Facets::columns(), which answers '4' whether or not `?cols` is
     * in the URL -- so "four across" was the shop's only setting AND its
     * default, and the two were indistinguishable. `data-cols` on the grid is a
     * PIN (see kbb-shop.css), so emitting it unconditionally pinned every
     * visitor at four columns at every screen size, including 1680 and 2560.
     * That is exactly the thing the owner asked to have removed: "on 1680px the
     * grid products will show 1 column extra".
     *
     * So the attribute is emitted only when the shopper has actually chosen,
     * and otherwise the grid takes the automatic answer from --kbb-track, which
     * gives four at 1280 (unchanged) and five at 1680. A click still pins,
     * instantly: resources/js/kbb/shop.js sets the attribute itself and the CSS
     * keyed on it applies without a reload.
     *
     * The button highlight follows the same fact rather than $cols, because a
     * highlighted "4" above a five-column grid is the control lying about the
     * page. Nothing is highlighted until something is chosen, which is the
     * truthful state and not a new control.
     */
    $colsChosen = request()->query('cols') !== null && in_array((string) $cols, ['2', '3', '4'], true);
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
    <div class="crumb"><b>{{ __('store.breadcrumb.home') }}</b> / {{ $crumb }}</div>
    @unless ($banner ?? null)
        <div class="eyebrow">{{ __('store.shop.eyebrow') }}</div>
        <h1 class="ptitle">{{ $title }}</h1>
        <p class="psub">{{ $sub }}</p>
    @endunless
</div>

<x-kbb-banner :banner="$banner ?? null" />

<div class="wrap shop">
    <aside class="filtercol" id="fcol">
        <div class="fpanel">
            <div class="fhead">
                <span class="ftitle">{{ __('store.shop.filters_heading') }}</span>
                <button class="fhide" type="button" onclick="document.body.classList.add('filters-hidden')">{{ __('store.shop.filters_hide') }}</button>
                <button class="fclose" type="button" onclick="document.body.classList.remove('filters-open')">✕</button>
            </div>
            <div id="filters">
                @if ($cats->isNotEmpty())
                <div class="fgroup"><h4>{{ __('store.shop.facet_category') }}</h4>
                    @foreach ($cats as $c)
                        <a class="fopt{{ Facets::isOn('cat', $c->slug) ? ' on' : '' }}" href="{{ Facets::url('cat', $c->slug) }}">
                            <span class="cb">{!! $ck !!}</span>
                            <span class="catdot" style="background:var(--pink)"></span> {{ $c->t('name') }}
                            <span class="ct">{{ $c->products_count }}</span>
                        </a>
                    @endforeach
                </div>
                @endif

                @if ($brands->isNotEmpty())
                <div class="fgroup"><h4>{{ __('store.shop.facet_brand') }}</h4>
                    @foreach ($brands as $b)
                        <a class="fopt{{ Facets::isOn('brand', $b->slug) ? ' on' : '' }}" href="{{ Facets::url('brand', $b->slug) }}">
                            <span class="cb">{!! $ck !!}</span> {{ $b->t('name') }}
                            <span class="ct">{{ $b->products_count }}</span>
                        </a>
                    @endforeach
                </div>
                @endif

                <div class="fgroup"><h4>{{ __('store.shop.facet_price') }}</h4>
                    <div class="pchips">
                        @foreach ($buckets as $key => $b)
                            <a class="pchip{{ request('price') === $key ? ' on' : '' }}" href="{{ Facets::url('price', request('price') === $key ? null : $key, false) }}">{{ $b[0] }}</a>
                        @endforeach
                    </div>
                </div>

                <div class="fgroup"><h4>{{ __('store.shop.facet_offers') }}</h4>
                    <a class="ftog" href="{{ Facets::url('sale', request('sale') === '1' ? null : '1', false) }}">{{ __('store.shop.facet_on_sale_only') }}<span class="tog{{ request('sale') === '1' ? ' on' : '' }}"></span></a>
                    <a class="ftog" href="{{ Facets::url('instock', request('instock') === '1' ? null : '1', false) }}">{{ __('store.shop.facet_in_stock_only') }}<span class="tog{{ request('instock') === '1' ? ' on' : '' }}"></span></a>
                </div>
            </div>
        </div>
    </aside>

    <main>
        <div class="gtop">
            <button class="mobi-filter" type="button" onclick="document.body.classList.add('filters-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M7 12h10M10 18h4"/></svg> {{ __('store.shop.filters_heading') }}</button>
            <button id="showFilters" type="button" onclick="document.body.classList.remove('filters-hidden')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M7 12h10M10 18h4"/></svg> {{ __('store.shop.filters_show') }}</button>
            <span class="gcount">{!! trans_choice('store.shop.product_count', $total, ['formatted' => '<b>' . e($total) . '</b>']) !!}</span>
            <div class="gright">
                <div class="colsel" id="colsel">
                    <button type="button" data-c="2"@if ($colsChosen && '2' === $cols) class="on"@endif title="{{ trans_choice('store.shop.columns_option', 2) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="5" width="6.5" height="14" rx="1.5"/><rect x="13.5" y="5" width="6.5" height="14" rx="1.5"/></svg></button>
                    <button type="button" data-c="3"@if ($colsChosen && '3' === $cols) class="on"@endif title="{{ trans_choice('store.shop.columns_option', 3) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="5" width="4.5" height="14" rx="1.3"/><rect x="9.75" y="5" width="4.5" height="14" rx="1.3"/><rect x="16.5" y="5" width="4.5" height="14" rx="1.3"/></svg></button>
                    <button type="button" data-c="4"@if ($colsChosen && '4' === $cols) class="on"@endif title="{{ trans_choice('store.shop.columns_option', 4) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="2.5" y="5" width="3.4" height="14" rx="1"/><rect x="7.7" y="5" width="3.4" height="14" rx="1"/><rect x="12.9" y="5" width="3.4" height="14" rx="1"/><rect x="18.1" y="5" width="3.4" height="14" rx="1"/></svg></button>
                </div>
                <div class="sortsel">{{ __('store.shop.sort_label') }}
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
                <a class="achip clear" href="{{ $clearUrl }}">{{ __('store.shop.clear_all') }}</a>
            </div>
        @endif

        <div class="grid" id="grid"@if ($colsChosen) data-cols="{{ $cols }}"@endif>
            @forelse ($products as $product)
                {{-- The first tile is the Largest Contentful Paint element on
                     this page at 1280 and the first thing in the grid on a
                     phone, so its photograph is the one request worth
                     prioritising. Every other card is lazy. --}}
                <x-product-card :product="$product" :eager="$loop->first" />
            @empty
                <div class="empty" style="grid-column:1/-1"><b>{{ __('store.shop.empty_heading') }}</b>{{ __('store.shop.empty_body') }}</div>
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
