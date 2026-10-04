{{--
  "You may also like" — the carousel at the foot of a product page.  (Lane PS)

  The owner: "You may also like should be a slider on each product page, I need
  it carousel by suggesting products from the same brand and category mixed."

  WHAT CHOOSES THE CARDS is App\Services\AlsoLikeRail (two queries, cold or
  warm), and WHAT HE CONTROLS is App\Services\AlsoLikeSettings — Appearance →
  Product page → You may also like — plus each product's own picks in Catalog →
  Products → (edit) → You may also like.

  ── THE CARDS ARE THE SHOP'S CARDS ─────────────────────────────────────────

  <x-product-card>, inside `.rel.kbb-pgrid[data-skin]` exactly as the grid
  was, so the 28 skins, the badges, the set prices (SetPricing::prime() ran
  over these rows) and Appearance → Product styles all reach them unchanged.
  Only the TRACK changed: one row that scrolls, instead of rows that wrap.

  ── NO JAVASCRIPT DECIDES ANY SIZE ─────────────────────────────────────────

  A card is `(100% − gaps) ÷ cards-in-view` wide, in CSS (kbb-product.css,
  `.ymal-track`), from two custom properties printed here — the phone one may
  be fractional, 2.3 by default (Lane PX) — each held to its own option list. The page paints
  at its final size before any script runs, so nothing shifts — rule 4.
  resources/js/kbb/ymal.js only scrolls, and reads no element geometry to
  decide a size.

  ── RTL ────────────────────────────────────────────────────────────────────

  Everything is logical: scroll-snap, `inset-inline-*`, `padding-inline`. The
  arrows say "previous"/"next" rather than left/right, their chevrons mirror
  under [dir=rtl], and ymal.js flips the scroll sign by the track's own
  computed `direction`.
--}}
@php
    $ymal = $alsoLike ?? ['products' => collect(), 'config' => [], 'wording' => ['title' => '', 'eyebrow' => '']];
    $ymalCards = $ymal['products'];
    $ymalC = $ymal['config'];
@endphp
@if ($ymalCards->isNotEmpty())
@php
    // Integers, from a select that only stores its own options (ModuleSchema).
    $ymalD = max(1, min(6, (int) ($ymalC['per_desktop'] ?? 5)));
    /* (Lane PX) A phone count may be fractional now (2.3 is the default). It
       is printed into a style attribute, so it is checked for MEMBERSHIP of
       the schema's own options rather than cast — anything else is 2.3. */
    $ymalM = (string) ($ymalC['per_phone'] ?? '2.3');
    $ymalM = in_array($ymalM, array_map('strval', array_keys(\App\Services\AlsoLikeSettings::SCHEMA['per_phone'][4])), true) ? $ymalM : '2.3';
    $ymalArrM = ! empty($ymalC['arrows_m']) ? ' ymal-arr-m' : '';
    $ymalAuto = ! empty($ymalC['autoplay']) ? max(3, min(15, (int) ($ymalC['autoplay_s'] ?? 5))) : 0;
@endphp
  <section class="sec ymal{{ $ymalArrM }} {{ $modules->classFor('related') }}" data-ymal data-ymal-auto="{{ $ymalAuto }}" aria-labelledby="ymal-h" style="--ymal-d:{{ $ymalD }};--ymal-m:{{ $ymalM }}">
    <div class="eyebrow">{{ $ymal['wording']['eyebrow'] }}</div>
    <h2 id="ymal-h">{{ $ymal['wording']['title'] }}</h2>
    <div class="ymal-nav">
      <button type="button" class="ymal-btn" data-ymal-prev aria-controls="related" aria-label="{{ __('store.product.related_prev') }}" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>
      <button type="button" class="ymal-btn" data-ymal-next aria-controls="related" aria-label="{{ __('store.product.related_next') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button>
    </div>
    <div class="rel kbb-pgrid ymal-track" data-skin="{{ \App\Support\GridSkins::resolve(null) }}" id="related" data-ymal-track tabindex="0" role="region" aria-label="{{ $ymal['wording']['title'] }}">@foreach ($ymalCards as $item)<x-product-card :product="$item" />@endforeach</div>
  </section>
  @endif
