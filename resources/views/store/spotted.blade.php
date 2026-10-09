@extends('layouts.store')
{{--
    /kbeautybliss-spotted/ — the owner's hand-picked Instagram grid.  (Lane HB)

    Master plan row 55, item 4: "a button to a new page /kbeautybliss-spotted
    with a manually-selected Instagram grid", and the SEO list for the same row:
    one H1, crawlable <a href> on every card, alt and width/height on every
    picture, BreadcrumbList JSON-LD. The controller builds the title, the
    description, the canonical and the breadcrumb (App\Support\Seo renders
    them); this view draws the H1, the introduction and the grid of polaroid
    cards — the same partial the homepage carousel uses.

    (Lane IGR) Above the grid, the Instagram embeds pasted at Content →
    Instagram embeds — printed raw because it is a rendered view of IGE's own
    section, where every value was escaped or built from constants.

    Controls: Appearance → #KBeautyBliss Spotted → Spotted page.
--}}
@section('title', $seoTitle)

@section('content')
<div class="kbb-home">
<section class="sec spt spt-lilac spt-page-sec"><div class="wrap">
  <nav class="crumb spt-crumb" aria-label="{{ __('store.spotted.breadcrumb_label') }}"><a href="{{ \App\Support\Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <span>{{ $h1 }}</span></nav>
  <div class="sh spt-head"><div><h1>{{ $h1 }}</h1>
    <p>{{ $intro }}</p></div></div>
{{-- (Lane IGR) The Instagram embeds, from Lane IGE's renderer (it carries
     its own small stylesheet, no script, and lazy frames). '' when off or empty. --}}
{!! $embeds !!}
@if ($cards === [] && $embeds === '')
  <p class="spt-empty">{{ __('store.spotted.empty') }}</p>
@elseif ($cards !== [])
  <ul class="spt-grid {{ $page['classes'] }}" style="{{ $page['style'] }}">
@foreach ($cards as $i => $card)
    <li class="spt-cell">@include('partials.spotted-card', ['card' => $card, 'lazy' => $i >= 4])</li>
@endforeach
  </ul>
@endif
</div></section>
</div>
@endsection
