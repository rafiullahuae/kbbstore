{{--
    ONE INSTANCE of the reusable homepage product grid. (Lane GS — Phase 23)

    The owner: "DO ONE thing. prepare a proper grid section with all controls
    and it can be use anywhere, and can be edit that specific grid section. so
    this case we can re-use this grid section anywhere multiple times with
    different products etc selection."

    This file draws ONE of them. store/home.blade.php loops it over
    GridSections::forHome(); the service's header carries the query argument and
    the whole stylesheet, and the admin screen is
    admin/partials/grid-sections-screen.blade.php.

        $section   an App\Models\GridSection row
        $items     the products GridSections::forHome() resolved for it
        $sections  the page's HomepageSections reader, for classFor()

    ── WHAT THIS FILE DOES NOT DO ────────────────────────────────────────────

    IT DOES NOT DRAW A CARD, and it may not. `<x-product-card>` is the shop's
    one product tile and `resources/css/kbb/kbb-grid-skins.css` is its 28
    templates — Lane PG2's files, both of them. This section renders
    `.kbb-pgrid` and inherits whatever skin is current; the per-instance "Card
    template" control PICKS one of the existing skins by name and never defines
    one. partials/home/grid.blade.php's own header records what the last split
    copy of that tile cost: four bugs, each fixed in one copy and not the other.

    IT CARRIES NO SCRIPT. Not a `<script>` block, not an inline handler, and no
    element-measuring API — CLAUDE.md rule 4, and a carousel is the most
    tempting place in this codebase to break it. The row is `scroll-snap` over
    one `calc()`; its affordance is the peek of the next card, which is the
    affordance `.kbb-home .rail` on this same page has used since it was
    written. There are therefore no arrows, and that is a decision rather than
    an omission: a previous/next button has to know how far to scroll, and every
    way of knowing that is a measurement. GridSectionShapeTest asserts the
    absence by name, against the same list CardsBannerSectionShapeTest uses.

    ── THE TWO COUNTS ARE ONE QUERY ──────────────────────────────────────────

    "5 columns on desktop and in mobile 6 products" is two numbers over one
    selection. GridSections::forHome() fetches max(count, mobile_count) ONCE and
    the surplus is hidden at the other width by a class — `gs-d-only` on a tile
    the phone must not show, `gs-m-only` on one the desktop must not. A second
    query for the second number would double this section's cost for a
    difference that is presentational, and a `:nth-child()` rule cannot take the
    number because a custom property may not appear in a selector.

    The hidden tiles ARE in the document at both widths. That is the trade and
    it is the right way round here: the rows are already fetched and hydrated,
    the extra markup is a few hundred bytes, and the alternative is either a
    second query or JavaScript.

    ── ARABIC ────────────────────────────────────────────────────────────────

    Nothing here is direction-specific. The heading row is `flex` with
    `justify-content:space-between`, the carousel is `flex` with
    `overflow-x:auto`, and both reverse themselves inside `dir="rtl"` because
    that is what the inline axis IS — the row lays out right-to-left and the
    scroll starts at the right, with no `[dir]` selector anywhere in this
    section's stylesheet. The gutters are `padding-inline` / `margin-inline`
    for the same reason.

    ── WHO GATES IT, AND WHY NOT THIS FILE ───────────────────────────────────

    The instance's Desktop/Mobile switches are applied by the LOOP in
    store/home.blade.php, which drops an instance hidden on both before this
    partial is reached. It is not done here for one measured reason: the shared
    stylesheet is pushed from the loop, above it, so that six instances push it
    once — and a gate inside the partial would leave that push firing for a
    section that renders nothing. `GridSectionShipsOffTest`'s "leaves no wrapper
    behind" case is what caught it.

    The single-sided switches are still this file's: `classFor()` puts `d-off`
    or `m-off` on the `<section>`, exactly as it does for the seventeen shipped
    sections.

    The words are the OWNER'S — his heading, his sub-heading, his eyebrow, his
    button text — so they are printed as typed and escaped, never keyed: a
    second English source for a value the owner types is one of the two silently
    wrong, which is the rule InterfaceStrings states for the cards banner's own
    headings. The one string this section supplies ITSELF is the fallback on the
    button, and that is `store.product_grid.view_all` — the key the shop
    ALREADY has for the "View all" under a product grid, reused rather than
    duplicated. ArabicInterfaceDraftsTest refuses a second key carrying the same
    Arabic, and it is right to: two keys for one sentence is two things for a
    translator to keep in step.
--}}
@php
    $gsKey = $section->sectionKey();
    $gsSkin = \App\Services\GridSections::skinFor($section);
    $gsHref = \App\Services\GridSections::viewAllHref($section);
    $gsHeading = trim((string) $section->heading);
    $gsSub = trim((string) $section->subheading);
    $gsShowHead = $section->show_heading && ($gsHeading !== '' || $gsSub !== '');

    // Clamped again here, and not because the cast is doubted. This is the last
    // point before the numbers become CSS, and a row hand-edited in the
    // database has never been through ModuleSchema::cast() at all.
    $gsD = max(2, min(6, (int) $section->desktop_cols));
    $gsM = max(1, min(3, (int) $section->mobile_cols));
    $gsDCount = max(1, min(48, (int) $section->count));
    $gsMCount = max(1, min(48, (int) $section->mobile_count));

    $gsClass = 'kbb-pgrid gs-grid'
        . ($section->desktop_layout === 'carousel' ? ' gs-car-d' : '')
        . ($section->mobile_layout === 'carousel' ? ' gs-car-m' : '');

    $gsLabel = trim((string) $section->view_all_label);
    $gsCardLabel = trim((string) $section->card_label);
@endphp
<section class="sec kbb-gsec {{ $sections->classFor($gsKey) }}" style="padding-top:0"><div class="wrap">
  @if ($gsShowHead)
  <div class="gs-head"><div>
    @if ($gsHeading !== '')<h2>{{ $gsHeading }}</h2>@endif
    @if ($gsSub !== '')<p>{{ $gsSub }}</p>@endif
  </div></div>
  @endif
  {{-- `--gs-d` and `--gs-m` are the only per-instance numbers that reach CSS,
       they are integers clamped twice, and they arrive through `{{ }}` in an
       attribute rather than inside the stylesheet — which is what keeps every
       byte of GridSections::css() a constant (rule 5). --}}
  <div class="{{ $gsClass }}" data-skin="{{ $gsSkin }}" style="--gs-d:{{ $gsD }};--gs-m:{{ $gsM }}">
    @foreach ($items as $gsI => $gsProduct)
      <div class="gs-cell{{ $gsI >= $gsDCount ? ' gs-m-only' : '' }}{{ $gsI >= $gsMCount ? ' gs-d-only' : '' }}"><x-product-card :product="$gsProduct" :cat-label="$gsCardLabel !== '' ? $gsCardLabel : null" :rank="$section->show_rank ? $gsI + 1 : null" /></div>
    @endforeach
  </div>
  @if ($gsHref !== '')
  {{-- The href has already been through App\Support\SafeUrl::href() with ''
       as its refusal, so reaching this line means the scheme was one this shop
       will follow. A refused address draws NO BUTTON rather than a button
       pointing at `#`: rule 5, and the same distinction SafeUrl's own header
       draws between a link and a picture. --}}
  <div class="gs-foot"><a class="gs-all" href="{{ $gsHref }}">{{ $gsLabel !== '' ? $gsLabel : __('store.product_grid.view_all') }}</a></div>
  @endif
</div></section>
