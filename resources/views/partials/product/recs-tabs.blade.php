{{--
  Brand and category as ONE block with two tabs — the default.  (Lane RP; Lane BC)

  "More from {brand}" | "More {category}" (App\Services\ProductRecs: the
  category is the most specific one, and leaves out what the brand tab shows).
  Both lists are drawn, so both are crawlable links and the URL never changes.
  The first panel is open; a closed one is `hidden` — display:none, so its lazy
  pictures are not fetched until it is opened.

  Which opens first is `tab_first`. "Where the shopper came from" prints
  data-rp-hint, and ymal.js then opens the panel whose `data-rp-paths` holds
  the listing path shop.js left in sessionStorage; "always the brand / the
  category" prints no hint and that panel first.

  Slider or grid and how many per device: $recs['tabs']['layout'], exactly as
  recs-block does it — `rp-dg` / `rp-mg` and a scoped nth-child rule. The
  cards come from $kbbRpCards (recs.blade.php).
--}}@php
    $kbbTb = $recs['tabs'];
    $kbbL = $kbbTb['layout'];
    $kbbSlider = $kbbL['d'] === 'slider' || $kbbL['m'] === 'slider';
    $kbbC = $alsoLike['config'] ?? [];
    $kbbT = \App\Services\ProductRecs::track($kbbC);
    $kbbAuto = ! empty($kbbC['autoplay']) ? max(3, min(15, (int) ($kbbC['autoplay_s'] ?? 5))) : 0;
    $kbbMode = ($kbbL['d'] === 'grid' ? ' rp-dg' : '').($kbbL['m'] === 'grid' ? ' rp-mg' : '');
@endphp
  <section class="sec ymal ymal-tabs{{ $kbbSlider ? $kbbT['arrM'] : ' rp-grid' }}{{ $kbbMode }} {{ $modules->classFor('related') }}" data-rp-tabs{{ $kbbTb['hint'] ? ' data-rp-hint' : '' }} aria-labelledby="ymal-h"@if ($kbbSlider) style="--ymal-d:{{ $kbbT['d'] }};--ymal-m:{{ $kbbT['m'] }}"@endif>
    <div class="eyebrow">{{ $kbbTb['eyebrow'] }}</div>
    <h2 id="ymal-h">{{ $kbbTb['title'] }}</h2>
    <div class="rp-tabs" role="tablist" aria-label="{{ __('store.product.recs_tabs_label') }}">@foreach ($kbbTb['panels'] as $kbbRpI => $kbbRpP)<button type="button" class="rp-tab" role="tab" id="rp-t-{{ $kbbRpP['key'] }}" aria-controls="rp-p-{{ $kbbRpP['key'] }}" aria-selected="{{ $kbbRpI === 0 ? 'true' : 'false' }}"@if ($kbbRpI > 0) tabindex="-1"@endif data-rp-tab>{{ $kbbRpP['title'] }}</button>@endforeach</div>
@foreach ($kbbTb['panels'] as $kbbRpI => $kbbRpP)
@if ($kbbSlider)
    <div class="rp-panel" id="rp-p-{{ $kbbRpP['key'] }}" role="tabpanel" aria-labelledby="rp-t-{{ $kbbRpP['key'] }}" data-ymal data-ymal-auto="{{ $kbbAuto }}" data-rp-paths="{{ implode(' ', $kbbRpP['paths']) }}"@if ($kbbRpI > 0) hidden @endif>
      <div class="ymal-nav">
        <button type="button" class="ymal-btn" data-ymal-prev aria-controls="rp-r-{{ $kbbRpP['key'] }}" aria-label="{{ __('store.product.related_prev') }}" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>
        <button type="button" class="ymal-btn" data-ymal-next aria-controls="rp-r-{{ $kbbRpP['key'] }}" aria-label="{{ __('store.product.related_next') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button>
      </div>
      <div class="rel kbb-pgrid ymal-track" data-skin="{{ \App\Support\GridSkins::resolve(null) }}" id="rp-r-{{ $kbbRpP['key'] }}" data-ymal-track tabindex="0" role="region" aria-label="{{ $kbbRpP['title'] }}"@if ($kbbL['nd'] !== $kbbL['nm']) data-ymal-n="{{ $kbbL['nd'] }} {{ $kbbL['nm'] }}"@endif>@foreach ($kbbRpP['products'] as $item){!! $kbbRpCards[$item->id] ?? \App\Support\CardFragments::render($item) !!}@endforeach</div>
    </div>
@else
    <div class="rp-panel" id="rp-p-{{ $kbbRpP['key'] }}" role="tabpanel" aria-labelledby="rp-t-{{ $kbbRpP['key'] }}" data-rp-paths="{{ implode(' ', $kbbRpP['paths']) }}"@if ($kbbRpI > 0) hidden @endif>
      <div class="rel kbb-pgrid" data-skin="{{ \App\Support\GridSkins::resolve(null) }}" id="rp-r-{{ $kbbRpP['key'] }}">@foreach ($kbbRpP['products'] as $item){!! $kbbRpCards[$item->id] ?? \App\Support\CardFragments::render($item) !!}@endforeach</div>
    </div>
@endif
@endforeach
  </section>
@if ($kbbL['nm'] < $kbbL['n'] || $kbbL['nd'] < $kbbL['n'])
<style>@if ($kbbL['nm'] < $kbbL['n'])@media(max-width:900px){#rp-r-brand>:nth-child(n+{{ $kbbL['nm'] + 1 }}),#rp-r-category>:nth-child(n+{{ $kbbL['nm'] + 1 }}){display:none}}@endif @if ($kbbL['nd'] < $kbbL['n'])@media(min-width:901px){#rp-r-brand>:nth-child(n+{{ $kbbL['nd'] + 1 }}),#rp-r-category>:nth-child(n+{{ $kbbL['nd'] + 1 }}){display:none}}@endif</style>
@endif
