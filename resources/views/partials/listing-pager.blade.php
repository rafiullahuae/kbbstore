{{--
    The listing pager: ‹ 1 2 3 › for /shop/, every category, the four curated
    listings and the concern pages.                              (Lane PI-B)

    ── WHY THIS EXISTS: THE ARROWS WERE GIANT ─────────────────────────────────

    store/collection.blade.php printed `$products->links()`, which is Laravel's
    own TAILWIND pager. This shop has no Tailwind, so none of its classes did
    anything: `sm:hidden` and `hidden` hid nothing, so the phone pager
    ("« Previous / Next »") AND the desktop one ("Showing 1 to 24 of 51
    results" and the numbers) both drew, and its two chevrons are
    `<svg class="w-5 h-5" viewBox="0 0 20 20">` with no width or height — an
    SVG with neither sizes to its container. Measured in Chromium on
    /super-sale: each chevron 170×170px at 390 and at 1280, the pager 419px
    tall. The same class of defect the admin icons had.

    So: one pager, ours, used by both views, and its arrows are not drawings
    at all. They are ‹ and ›, the characters /shop has always used — text,
    sized by the link's font, so there is no SVG left to lose its size, and
    Bidi-mirrored, so on /ar the browser turns them round with no CSS
    (DirectionalGlyphsTest pins that they stay these two characters). The
    /shop pager was the other half of the bug — bare 5×18px text links on
    desktop, because its only CSS was inside a phone media query — and it now
    gets the same rules at every width: 40px boxes (44px on a phone), the
    arrows at 20px.

    ── LOADING MORE ───────────────────────────────────────────────────────────

    `data-load` is Appearance → Site layout → Loading more products: one of
    the select's own options (SiteLayout::loadMode() can return nothing else),
    escaped all the same. With "scroll", resources/js/kbb/listing-load.js
    takes this element over: it hides the numbers and fetches the rel="next"
    link's page as a batch when the pager scrolls near. Without JavaScript —
    or if a fetch fails — these are ordinary links and the shop pages exactly
    as it always has. `data-grid` is a constant from the including view.

    @param int      $page
    @param int      $lastPage
    @param \Closure $urlFor   int $page => string URL
    @param string   $grid     CSS selector of the grid the batches append to
--}}@php $kbbLayout = app(\App\Services\SiteLayout::class); $kbbLoad = $kbbLayout->loadMode(); @endphp
@if ($lastPage > 1)
<nav class="kbb-pager" aria-label="{{ __('store.shop.pages_label') }}" data-load="{{ $kbbLoad }}" data-grid="{{ $grid }}"@if ($kbbLoad === 'scroll') data-batch="{{ $kbbLayout->batchSize() }}"@endif>
@if ($page > 1)<a class="page-numbers prev" rel="prev" href="{{ $urlFor($page - 1) }}" aria-label="{{ __('store.shop.page_prev') }}">‹</a>@endif
@foreach (range(max(1, $page - 1), min($lastPage, $page + 1)) as $n)
@if ($n === $page)<span class="page-numbers current" aria-current="page">{{ $n }}</span>@else<a class="page-numbers" href="{{ $urlFor($n) }}">{{ $n }}</a>@endif
@endforeach
@if ($page < $lastPage)<a class="page-numbers next" rel="next" href="{{ $urlFor($page + 1) }}" aria-label="{{ __('store.shop.page_next') }}">›</a>@endif
<span class="kbb-pager-status" role="status" aria-live="polite" data-loading="{{ __('store.shop.loading_more') }}"></span>
</nav>
@endif
