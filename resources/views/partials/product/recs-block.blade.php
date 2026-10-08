{{--
  One block at the foot of a product page.                 (Lane RP2; Lane BC)

  Given $kbbB: h (heading id), r (track id), title, eyebrow (or null),
  optionally cls (an extra class, with its leading space) and auto (0 = this
  slider never moves on its own, whatever the setting says),
  products, layout (AlsoLikeSettings::layoutFor(): d / m = slider|grid, nd /
  nm = cards per device, n = the larger). The cards come from $kbbRpCards
  (App\Support\CardFragments, read once for the page in recs.blade.php).

  ── SLIDER OR GRID PER DEVICE, IN CSS ONLY ─────────────────────────────────

  Slider on either device → the carousel markup ([data-ymal], ymal.js), and
  `rp-dg` / `rp-mg` turns it into a grid on the laptop / the phone
  (kbb-product.css, the page's own 901 px breakpoint). Grid on both → the plain
  product grid, no script. Nothing measures anything.

  ── HOW MANY PER DEVICE, IN CSS ONLY ───────────────────────────────────────

  The larger count is rendered once; on the device that shows fewer, a scoped
  rule hides the rest — display:none, so their lazy pictures are never
  fetched. The numbers are integers clamped to 4–24 by layoutFor(), never text.
--}}@php
    $kbbL = $kbbB['layout'];
    $kbbSlider = $kbbL['d'] === 'slider' || $kbbL['m'] === 'slider';
    $kbbC = $alsoLike['config'] ?? [];
    $kbbT = \App\Services\ProductRecs::track($kbbC);
    $kbbAuto = array_key_exists('auto', $kbbB) ? (int) $kbbB['auto'] : (! empty($kbbC['autoplay']) ? max(3, min(15, (int) ($kbbC['autoplay_s'] ?? 5))) : 0);
    $kbbMode = ($kbbB['cls'] ?? '').($kbbL['d'] === 'grid' ? ' rp-dg' : '').($kbbL['m'] === 'grid' ? ' rp-mg' : '');
    $kbbR = $kbbB['r'];
@endphp
@if ($kbbSlider)
  <section class="sec ymal{{ $kbbT['arrM'] }}{{ $kbbMode }} {{ $modules->classFor('related') }}" data-ymal data-ymal-auto="{{ $kbbAuto }}" aria-labelledby="{{ $kbbB['h'] }}" style="--ymal-d:{{ $kbbT['d'] }};--ymal-m:{{ $kbbT['m'] }}">
@if ($kbbB['eyebrow'] !== null)
    <div class="eyebrow">{{ $kbbB['eyebrow'] }}</div>
@endif
    <h2 id="{{ $kbbB['h'] }}">{{ $kbbB['title'] }}</h2>
    <div class="ymal-nav">
      <button type="button" class="ymal-btn" data-ymal-prev aria-controls="{{ $kbbR }}" aria-label="{{ __('store.product.related_prev') }}" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>
      <button type="button" class="ymal-btn" data-ymal-next aria-controls="{{ $kbbR }}" aria-label="{{ __('store.product.related_next') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button>
    </div>
    <div class="rel kbb-pgrid ymal-track" data-skin="{{ \App\Support\GridSkins::resolve(null) }}" id="{{ $kbbR }}" data-ymal-track tabindex="0" role="region" aria-label="{{ $kbbB['title'] }}"@if ($kbbL['nd'] !== $kbbL['nm']) data-ymal-n="{{ $kbbL['nd'] }} {{ $kbbL['nm'] }}"@endif>@foreach ($kbbB['products'] as $item){!! $kbbRpCards[$item->id] ?? \App\Support\CardFragments::render($item) !!}@endforeach</div>
  </section>
@else
  <section class="sec ymal rp-grid{{ $kbbB['cls'] ?? '' }} {{ $modules->classFor('related') }}" aria-labelledby="{{ $kbbB['h'] }}">
@if ($kbbB['eyebrow'] !== null)
    <div class="eyebrow">{{ $kbbB['eyebrow'] }}</div>
@endif
    <h2 id="{{ $kbbB['h'] }}">{{ $kbbB['title'] }}</h2>
    <div class="rel kbb-pgrid" data-skin="{{ \App\Support\GridSkins::resolve(null) }}" id="{{ $kbbR }}">@foreach ($kbbB['products'] as $item){!! $kbbRpCards[$item->id] ?? \App\Support\CardFragments::render($item) !!}@endforeach</div>
  </section>
@endif
@if ($kbbL['nm'] < $kbbL['n'] || $kbbL['nd'] < $kbbL['n'])
<style>@if ($kbbL['nm'] < $kbbL['n'])@media(max-width:900px){#{{ $kbbR }}>:nth-child(n+{{ $kbbL['nm'] + 1 }}){display:none}}@endif @if ($kbbL['nd'] < $kbbL['n'])@media(min-width:901px){#{{ $kbbR }}>:nth-child(n+{{ $kbbL['nd'] + 1 }}){display:none}}@endif</style>
@endif
