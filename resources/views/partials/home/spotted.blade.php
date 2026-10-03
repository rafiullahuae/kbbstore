{{--
    #KBeautyBliss Spotted — the homepage carousel.                  (Lane HB)

    Master plan row 55, item 4. Included by store/home.blade.php (Lane HA owns
    that file) between Brands and Trending Now with
    @includeIf('partials.home.spotted'), so it brings everything it needs: its
    own settings, its own posts, its own <section>.

    THE OWNER: "with instagram feed make it carousel, but with manual selection
    … button to a new page /kbeautybliss-spotted … super responsive. without any
    bugs, hanging, or broken stuff." Card design B (polaroid, slightly tilted).
    Controls: Appearance → #KBeautyBliss Spotted.

    RENDERS NOTHING when no post is ticked "Homepage" or the section is switched
    off — not an empty <section>, nothing — so applying the package moves no byte
    of the homepage until the owner picks a post.

    The arrows and the swipe are resources/js/kbb/ymal.js (data-ymal …), which
    measures nothing; every size is calc() in kbb.css (.spt-*). Class names and
    the style attribute are literals built by SpottedSettings::section() from a
    select's own options.
--}}
@php
    $sptSvc = app(\App\Services\SpottedSettings::class);
    $spt = $sptSvc->section();
    $sptCards = $spt['show'] ? $sptSvc->homeCards() : [];
@endphp
@if ($sptCards !== [])
@php
    $sptTitle = $spt['title'] !== '' ? $spt['title'] : __('store.spotted.home_heading');
    $sptLabel = $spt['label'] !== '' ? $spt['label'] : __('store.spotted.button');
@endphp
<section class="sec {{ $spt['classes'] }}" style="{{ $spt['style'] }}" data-ymal data-ymal-auto="{{ $spt['auto'] }}" aria-labelledby="spt-h"><div class="wrap">
  <div class="sh spt-head"><div><h2 id="spt-h">{{ $sptTitle }}</h2>
    <p>{{ $spt['sub'] !== '' ? $spt['sub'] : __('store.spotted.home_sub') }}</p></div></div>
  <div class="spt-stage">
    <button type="button" class="spt-arr spt-prev" data-ymal-prev aria-controls="spt-track" aria-label="{{ __('store.spotted.prev') }}" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>
    <ul class="spt-track" id="spt-track" data-ymal-track tabindex="0" aria-label="{{ $sptTitle }}">
@foreach ($sptCards as $card)
      <li class="spt-cell">@include('partials.spotted-card', ['card' => $card])</li>
@endforeach
    </ul>
    <button type="button" class="spt-arr spt-next" data-ymal-next aria-controls="spt-track" aria-label="{{ __('store.spotted.next') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button>
  </div>
@if ($spt['button'])
  <div class="spt-foot"><a class="bndl-all spt-all" href="{{ \App\Support\Url::to(\App\Services\SpottedSettings::URL) }}">{{ $sptLabel }}<i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></i></a></div>
@endif
</div></section>
@endif
