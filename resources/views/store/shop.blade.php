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
{{-- The imported title header (Lane PT). At column 0, and this comment on the
     same line as the directive, on purpose: a directive's indentation and a
     comment's own line both print even when the condition is false, and a
     category with no header must not gain a byte. --}}@if ($titleHeader ?? null)
@vite('resources/css/kbb/kbb-title-header.css')
@endif
@endpush

{{-- ── THE FILTER SIDEBAR STARTS HIDDEN ───────────────────────── Lane PG ──

     The owner, in as many words: "keep off the left filters hidden by default.
     and user can view the filters by the option." This is a visible change to a
     page that already works, so it is the CLAUDE.md rule-1 exception — a
     default he asked for — and it is called out in the commit rather than
     buried. It reaches /shop AND every category archive, because
     CategoryArchiveController delegates to ShopController::index() and both
     render this one view.

     IT IS A COOKIE, AND NOT A CLASS A SCRIPT ADDS, for two reasons.

     A preference that resets on every navigation is worse than no preference: a
     shopper who opens the filters, ticks a brand and lands on the filtered page
     would find them shut again, every time, on the one page whose whole purpose
     is filtering. The choice has to survive the click that uses it. The two
     buttons below write the cookie beside the class they toggle.

     And it has to be decided ON THE SERVER. A script that adds the class after
     the document loads paints the sidebar and then takes it away, which is a
     layout shift on the largest block of the page. The class is in the <body>
     tag as it is sent.

     `=== 'open'` and not `!== 'hidden'`: hidden is the default, so anything
     that is not the one value meaning "the shopper opened them" — no cookie, a
     stale value, a forged one — lands on the shipped answer. The cookie is
     compared, never printed.

     WRITTEN HERE, above the section that uses it, because @section inside
     @section does not nest: the inner one closes the outer. --}}
{{-- Lane SO: with "Filters · laptop" off (the shipped state, as the owner asked) there is no column to open, so the class is always on and the grid takes the row exactly as it did with the column closed. --}}
@section('body-class', ($filtersD ?? false) && request()->cookie('kbb_filters') === 'open' ? '' : 'filters-hidden')

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
     * and otherwise the grid takes the automatic answer from the one track rule
     * in kbb.css -- which, since Lane PG moved the tile minimum to 220px and
     * the filter rail started hidden, is FIVE at 1280 and six at 1680 rather
     * than the four and five this comment used to name. A click still pins,
     * instantly: resources/js/kbb/shop.js sets the attribute itself and the CSS
     * keyed on it applies without a reload.
     *
     * The button highlight follows the same fact rather than $cols, because a
     * highlighted "4" above a five-column grid is the control lying about the
     * page. Nothing is highlighted until something is chosen, which is the
     * truthful state and not a new control.
     */
    /*
     * The RAW query value against the allowlist, not Facets::columns()' answer.
     * That method falls an unusable `?cols=99` back to '4', so testing ITS result
     * makes a bogus value indistinguishable from a deliberate choice of four —
     * `?cols=99` pinned the grid at four columns, which is neither what the URL
     * asked for nor the automatic answer. A mutation run found it.
     */
    /*
     * -- AND FIVE IS ON THE BUTTON ROW ----------------------------- Lane PG --
     *
     * The row offered 2, 3 and 4 while the grid derives FIVE. Every position on
     * it was a step DOWN from the page the shopper was already looking at, and
     * there was no way back to the default except editing the URL -- a control
     * lying about the page it sits above, which is the defect the note above
     * removed from the HIGHLIGHT and left in the OPTIONS.
     *
     * Widened rather than removed, because the owner asked for five AND uses
     * these buttons; deleting the control would answer half his sentence by
     * deleting the other half. Facets::columns() carries '5' on its allowlist
     * and as its fallback, and kbb-shop.css has the matching pin.
     *
     * THE COMMENT IS HERE AND NOT BESIDE THE BUTTON, for the reason this file
     * records twice already: a Blade comment on its own line down in the markup
     * leaves its indentation and its newline in the rendered page, and
     * StorefrontEnglishUnchangedTest compares BYTES.
     */
    $colsChosen = in_array((string) request()->query('cols'), ['2', '3', '4', '5'], true);

    /*
     * The tile's own skin, so /shop and every category archive draw the SAME
     * card as the homepage rails, the wishlist and a brand page. Appearance →
     * Product styles → Grid skin reaches this listing for the first time.
     */
    $kbbSkin = \App\Support\GridSkins::resolve(null);
@endphp

{{--
    The heading block and the banner are alternatives, not a stack.

    When a banner is on it carries the page's <h1> — a second one underneath
    it would be two competing headings on the same document, and the one a
    crawler picked would be the one the owner did not design. The breadcrumb
    stays above either, because it is navigation and belongs before the title
    whichever way the title is drawn.
--}}
@if ($categoryHeader ?? null)
@include('partials.category-custom-header', ['ch' => $categoryHeader, 'chTotal' => $total])
@else
<div class="wrap">
    <div class="crumb"><b>{{ __('store.breadcrumb.home') }}</b> / @foreach (($crumbParents ?? []) as $cp)<a href="{{ $cp['url'] }}">{{ $cp['name'] }}</a> / @endforeach{{ $crumb }}</div>
    @unless (($banner ?? null) || ($titleHeader ?? null))
        <div class="eyebrow">{{ __('store.shop.eyebrow') }}</div>
        <h1 class="ptitle">{{ $title }}</h1>
{{-- No generic line under a one-brand listing's name (2.60.346). The directives
     sit at column 0 so every other page renders byte for byte as before. --}}@if ($sub !== '')
        <p class="psub">{{ $sub }}</p>
@endif
    @endunless
</div>

<x-kbb-banner :banner="$banner ?? null" />
@if ($titleHeader ?? null)
<x-kbb-title-header :header="$titleHeader" />
@endif
@endif

<div class="wrap shop">
{{-- NOT RENDERED WHEN OFF (Lane SO). The owner: "remove the filter at all ... keep turned off completely on all pages by default." Appearance → Site layout → Product grid → "Filters · laptop" and "Filters button · phone"; with both off (shipped) the page sends no filter column, no drawer and none of its /shop/?cat= / ?brand= / ?price= links. --}}@if (($filtersD ?? false) || ($filtersM ?? false))
    <aside class="filtercol" id="fcol">
        <div class="fpanel">
            <div class="fhead">
                <span class="ftitle">{{ __('store.shop.filters_heading') }}</span>
                <button class="fhide" type="button" onclick="document.body.classList.add('filters-hidden');document.cookie='kbb_filters=hidden;path=/;max-age=31536000;samesite=lax'">{{ __('store.shop.filters_hide') }}</button>
                <button class="fclose" type="button" onclick="document.body.classList.remove('filters-open')">✕</button>
            </div>
            {{-- THE FACET HEADINGS SAY LEVEL 2 (Lane PS). Lighthouse on /collections/sunscreens/ (the 5 Oct PageSpeed work): "Heading elements are not in a sequentially-descending order", naming div#filters > div.fgroup > h4 -- an <h4> straight after the page's <h1>. aria-level corrects the level screen readers announce; the tag and therefore every rule in kbb-shop.css (.fgroup h4) is untouched, so nothing moves. --}}<div id="filters">
                @if ($cats->isNotEmpty())
                <div class="fgroup"><h4 aria-level="2">{{ __('store.shop.facet_category') }}</h4>
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
                <div class="fgroup"><h4 aria-level="2">{{ __('store.shop.facet_brand') }}</h4>
                    @foreach ($brands as $b)
                        <a class="fopt{{ Facets::isOn('brand', $b->slug) ? ' on' : '' }}" href="{{ Facets::url('brand', $b->slug) }}">
                            <span class="cb">{!! $ck !!}</span> {{ $b->t('name') }}
                            <span class="ct">{{ $b->products_count }}</span>
                        </a>
                    @endforeach
                </div>
                @endif

                <div class="fgroup"><h4 aria-level="2">{{ __('store.shop.facet_price') }}</h4>
                    <div class="pchips">
                        @foreach ($buckets as $key => $b)
                            <a class="pchip{{ request('price') === $key ? ' on' : '' }}" href="{{ Facets::url('price', request('price') === $key ? null : $key, false) }}">{{ $b[0] }}</a>
                        @endforeach
                    </div>
                </div>

                <div class="fgroup"><h4 aria-level="2">{{ __('store.shop.facet_offers') }}</h4>
                    <a class="ftog" href="{{ Facets::url('sale', request('sale') === '1' ? null : '1', false) }}">{{ __('store.shop.facet_on_sale_only') }}<span class="tog{{ request('sale') === '1' ? ' on' : '' }}"></span></a>
                    <a class="ftog" href="{{ Facets::url('instock', request('instock') === '1' ? null : '1', false) }}">{{ __('store.shop.facet_in_stock_only') }}<span class="tog{{ request('instock') === '1' ? ' on' : '' }}"></span></a>
                </div>
            </div>
        </div>
    </aside>
    <div class="fscrim" data-kbb-close></div>
@endif
@php $kbbLay = app(\App\Services\SiteLayout::class); $kbbMcols = in_array((string) request()->query('mcols'), ['1', '2'], true) ? (string) request()->query('mcols') : null; @endphp

    <main>
        <div class="gtop{{ $kbbLay->get('filters_m') ? '' : ' kbb-nofilt-m' }}{{ $kbbLay->get('cols_m') ? ' kbb-mcols' : '' }}">
@if ($filtersM ?? false)
            <button class="mobi-filter" type="button" onclick="document.body.classList.add('filters-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M7 12h10M10 18h4"/></svg> {{ __('store.shop.filters_heading') }}@if ($chips)<span class="fcount">{{ count($chips) }}</span>@endif</button>
@endif
@if ($filtersD ?? false)
            <button id="showFilters" type="button" onclick="document.body.classList.remove('filters-hidden');document.cookie='kbb_filters=open;path=/;max-age=31536000;samesite=lax'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M7 12h10M10 18h4"/></svg> {{ __('store.shop.filters_show') }}@if ($chips)<span class="fcount">{{ count($chips) }}</span>@endif</button>@endif{{ '' }}@if (app(\App\Services\SiteLayout::class)->get('show_count')){{ "\n" }}            <span class="gcount">{!! trans_choice('store.shop.product_count', $total, ['formatted' => '<b>' . e($total) . '</b>']) !!}</span>@endif{{ '' }}
            <div class="gright">
                <div class="colsel" id="colsel">
                    <button type="button" data-c="2"@if ($colsChosen && '2' === $cols) class="on"@endif title="{{ trans_choice('store.shop.columns_option', 2) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="5" width="6.5" height="14" rx="1.5"/><rect x="13.5" y="5" width="6.5" height="14" rx="1.5"/></svg></button>
                    <button type="button" data-c="3"@if ($colsChosen && '3' === $cols) class="on"@endif title="{{ trans_choice('store.shop.columns_option', 3) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="5" width="4.5" height="14" rx="1.3"/><rect x="9.75" y="5" width="4.5" height="14" rx="1.3"/><rect x="16.5" y="5" width="4.5" height="14" rx="1.3"/></svg></button>
                    <button type="button" data-c="4"@if ($colsChosen && '4' === $cols) class="on"@endif title="{{ trans_choice('store.shop.columns_option', 4) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="2.5" y="5" width="3.4" height="14" rx="1"/><rect x="7.7" y="5" width="3.4" height="14" rx="1"/><rect x="12.9" y="5" width="3.4" height="14" rx="1"/><rect x="18.1" y="5" width="3.4" height="14" rx="1"/></svg></button>
                    <button type="button" data-c="5"@if ($colsChosen && '5' === $cols) class="on"@endif title="{{ trans_choice('store.shop.columns_option', 5) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="5" width="2.7" height="14" rx=".9"/><rect x="6.3" y="5" width="2.7" height="14" rx=".9"/><rect x="10.6" y="5" width="2.7" height="14" rx=".9"/><rect x="14.9" y="5" width="2.7" height="14" rx=".9"/><rect x="19.2" y="5" width="2.7" height="14" rx=".9"/></svg></button>
                </div>
                @if ($kbbLay->get('cols_m'))<div class="colsel colsel-m" id="colselm"><button type="button" data-m="1"@if ($kbbMcols === '1') class="on"@endif title="{{ trans_choice('store.shop.columns_option', 1) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="12" height="14" rx="1.8"/></svg></button><button type="button" data-m="2"@if ($kbbMcols !== '1') class="on"@endif title="{{ trans_choice('store.shop.columns_option', 2) }}"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="5" width="6.5" height="14" rx="1.5"/><rect x="13.5" y="5" width="6.5" height="14" rx="1.5"/></svg></button></div>@endif

                {{-- A <label>, not a <div>, so "Sort" is the select's accessible
                     name — it had none. On the narrowest phones the word is
                     hidden VISUALLY (kbb-shop.css, .sortlbl) to keep Filters and
                     Sort on one row, and stays the label.        (Lane PI-B) --}}<label class="sortsel" for="sort"><span class="sortlbl">{{ __('store.shop.sort_label') }}</span>
                    <select id="sort" onchange="var u=new window.URL(location.href);u.searchParams.set('orderby',this.value);u.searchParams.delete('paged');location.href=u.toString()">
                        @foreach ($sorts as $val => $lbl)
                            <option value="{{ $val }}" @selected($curorder === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </label>
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

        {{-- `kbb-pgrid` AND `data-skin` ON THE SHOP'S OWN GRID.        Lane PG

             This listing drew a different card from the rest of the shop and
             therefore answered to none of the skins. It draws the same tile as
             the homepage rails now, so it gets the class those tiles are styled
             against — the equal-height flex column, the two-line name clamp and
             whichever of the 28 skins Appearance → Product styles is set to.

             `#grid` keeps its own --kbb-tile-shop and 18px gap (kbb-shop.css),
             and both selectors carry the SAME track declaration in kbb.css, so
             adding the class changes no column arithmetic. --}}
        <div class="grid kbb-pgrid" id="grid" data-skin="{{ $kbbSkin }}"@if ($colsChosen) data-cols="{{ $cols }}"@endif{!! $kbbMcols === '1' ? ' data-mcols="1"' : '' !!}>
            @forelse ($products as $product)
                {{-- The first tile is the Largest Contentful Paint element on
                     this page at 1280 and the first thing in the grid on a
                     phone, so its photograph is the one request worth
                     prioritising. Every other card is lazy.

                     `catLabel` is the ARCHIVE's category, which is exactly what
                     the eyebrow says on the owner's reference: the section this
                     row belongs to. /shop itself has no single category, so it
                     passes null and the tile simply has no eyebrow — which
                     costs no query, where reading each product's own categories
                     would have cost one on this page and on three others. --}}
                <x-product-card :product="$product" :eager="$loop->first" :cat-label="($category ?? null)?->t('name')" />
            @empty
                <div class="empty" style="grid-column:1/-1"><b>{{ __('store.shop.empty_heading') }}</b>{{ __('store.shop.empty_body') }}</div>
            @endforelse
        </div>

        {{-- The pager, shared with the curated listings, and the hook for
             "Load more on scroll". See the partial's header.  (Lane PI-B) --}}@include('partials.listing-pager', [
            'page' => $page,
            'lastPage' => $lastPage,
            'urlFor' => static fn (int $n): string => Facets::pageUrl($n),
            'grid' => '#grid',
        ])
{{-- SEO → Keywords: Popular searches (Lane KW). Off unless the owner turns it on; prints nothing when off. Column 0 so the page stays byte-identical. --}}@include('partials.popular-searches', ['seoEntity' => ($category ?? null) ? 'category:' . $category->id : null])
    </main>
</div>
@endsection
