{{--
    The shop's 404 page.                                               (Lane NF)

    Rendered by App\Support\NotFoundPage::respond() with a real 404 status, for
    a shopper's page request only; everything it prints comes from
    NotFoundPage::viewData(), which has validated it. Every setting reaches the
    page through {{ }}; the only raw output is the constant sheet, the
    constant icons and the illustration partial (constant markup, its two
    words escaped inside it). The illustration is inline SVG with a
    viewBox, so its box is known before anything paints: no layout shift.
    Safety → 404 page in the admin sets all of it.
--}}
@extends('layouts.store')

@section('title', $nf['labels']['title'])
@section('body-class', 'nf-body')

@push('styles')
<style id="kbb-404">{!! \App\Support\NotFoundPage::css() !!}</style>
@if ($nf['trend'])
    @vite('resources/css/kbb/kbb-grid-skins.css')
@endif
@endpush

@section('content')
<div class="{{ $nf['class'] }}" style="{{ $nf['style'] }}" data-nf="{{ $nf['design'] }}">
<section class="nf-hero">
{!! \App\Support\NotFoundPage::art($nf['design'], $nf['ar']) !!}
<div class="nf-copy">
<p class="nf-k">{{ $nf['labels']['kicker'] }}</p>
<h1 class="nf-h">{{ $nf['text']['h'] }}@if ($nf['text']['em'] !== '') <em>{{ $nf['text']['em'] }}</em>@endif</h1>
@if ($nf['text']['s'] !== '')
<p class="nf-s">{{ $nf['text']['s'] }}</p>
@endif
@if ($nf['home'])
<a class="nf-cta" href="{{ $nf['home']['href'] }}"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/></svg>{{ $nf['home']['label'] }}</a>
@endif
@if ($nf['search'])
<form class="nf-sf" action="{{ \App\Support\Url::to('/shop/') }}" method="get" role="search"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg><input type="search" name="s" placeholder="{{ $nf['labels']['search'] }}" aria-label="{{ $nf['labels']['search'] }}" autocomplete="off"><button type="submit">{{ $nf['labels']['go'] }}</button></form>
@endif
@if ($nf['links'] !== [])
<nav class="nf-links" aria-label="{{ $nf['labels']['links'] }}">
@foreach ($nf['links'] as $l)
<a class="nf-{{ $l['key'] }}" href="{{ $l['href'] }}"@if ($l['key'] === 'wa') target="_blank" rel="noopener"@endif>{!! \App\Support\NotFoundPage::ICONS[$l['key']] !!}{{ $l['label'] }}</a>
@endforeach
</nav>
@endif
</div>
</section>
@if ($nf['trend'])
<section class="nf-trend" aria-label="{{ $nf['trend']['title'] }}">
<div class="nf-trend-h"><h2>{{ $nf['trend']['title'] }}</h2><a href="{{ $nf['trend']['href'] }}">{{ $nf['labels']['see_all'] }}</a></div>
@include('partials.home.grid', ['items' => $nf['trend']['items'], 'skin' => \App\Support\GridSkins::resolve(null)])
</section>
@endif
</div>
@endsection
