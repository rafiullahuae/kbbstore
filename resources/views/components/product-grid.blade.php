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
    // An id for the grid, so a pager can name it as the place a batch of
    // "Load more on scroll" lands (the brand page, Lane PR). A constant from
    // the caller, never a setting. Ignored when a caller's pin needs the id.
    'gridId' => null,
    // (Lane PS) The first tile is the page's LCP picture when nothing is drawn
    // above the grid; the caller that knows says so. False everywhere else.
    'eagerFirst' => false,
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
     * These reach `grid-template-columns:repeat(N,…)` in the style element below,
     * so an
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
<div class="kbb-pgrid" data-skin="{{ $skin }}"@if ($pinCols || $pinMobile) id="{{ $pinId }}"@elseif ($gridId) id="{{ $gridId }}"@endif>
    @foreach ($products as $p)
        {{-- THE CARD IS <x-product-card> NOW, AND THIS FILE NO LONGER DRAWS ONE.

             It used to carry a complete second copy of the tile -- the same one
             /shop drew through <x-product-card>, with different class names, a
             different rating rule and a different set of bugs fixed in each.
             That is the state Lane PG was given to end. The markup, the badges,
             the price cell and the buyability test all live in
             components/product-card.blade.php; this file decides the GRID.

             `catLabel` is resolved HERE and passed in, and that is the whole
             reason the eyebrow is a prop rather than something the card reads.
             This component's callers eager-load `categories` (see the contract
             above); /shop's do not, and making the card read the relation would
             have put one more query on /shop, on every category archive, on the
             product page and on /routines. --}}
        <x-product-card :product="$p" :eager="$eagerFirst && $loop->first" :cat-label="$p->categories->first()?->t('name')" />
    @endforeach
</div>
