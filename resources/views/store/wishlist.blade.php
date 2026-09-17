@extends('layouts.store')
@php use App\Support\Url; @endphp

{{--
    THE WORKED EXAMPLE FOR PHASE 2 (Lane EP).

    This page is converted end to end and the other ~95 Blade files are not, on
    purpose: converting them before the shape was settled would have meant
    converting them twice. This is what the conversion looks like, and it is the
    whole of it.

    THE RULE: a literal a shopper reads becomes __('store.<page>.<thing>').
    The English stays the source of truth, in
    App\Services\Translation\InterfaceStrings, where a package can ship it. The
    Arabic is typed in the admin and lives in the database, where the owner can
    correct it without a release.

    WHAT IS NOT CONVERTED, AND WHY EACH ONE STAYS:

      - the SVG heart. A picture has no language.
      - `grid_skin`, `classic`, `'items'` and every other key passed to an
        @include. Those are identifiers the code reads, not words a shopper
        reads, and translating one breaks the page rather than the sentence.
      - the count itself. `:count saved` puts the NUMBER in the translation
        rather than concatenating around it, which is what lets Arabic put it
        where Arabic puts it. Concatenation is the thing that cannot be
        translated.

    Nothing here is Arabic-only. With Arabic switched off — which is how this
    ships — every __() below resolves to exactly the English that was hard-coded
    here before, because that English is what InterfaceStrings holds.
--}}

@section('title', __('store.wishlist.page_title'))

@push('styles')
    @vite('resources/css/kbb/kbb-grid-skins.css')
@endpush

@section('content')
<div class="kbb-home">
<section class="sec"><div class="wrap">
    <nav class="crumb"><a href="{{ Url::to('/') }}">{{ __('store.wishlist.breadcrumb_home') }}</a> / <span>{{ __('store.wishlist.breadcrumb_current') }}</span></nav>

    <div class="sh">
        <div>
            <h2>{{ __('store.wishlist.title') }} @if ($products->isNotEmpty())<span class="cnt">{{ __('store.wishlist.saved_count', ['count' => $products->count()]) }}</span>@endif</h2>
            <p>{{ __('store.wishlist.subtitle') }}</p>
        </div>
        @if ($products->isNotEmpty())<a class="lnk" href="{{ Url::to('/shop/') }}">{{ __('store.wishlist.keep_browsing') }}</a>@endif
    </div>

    @if ($products->isEmpty())
        <div class="wl-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg>
            <h3>{{ __('store.wishlist.empty_title') }}</h3>
            <p>{{ __('store.wishlist.empty_body') }}</p>
            <a class="btn btn-p" href="{{ Url::to('/shop/') }}">{{ __('store.wishlist.empty_cta') }}</a>
        </div>
    @else
        @include('partials.home.grid', [
            'items' => $products,
            'skin' => $settings->get('grid_skin', 'classic'),
            'catLabel' => __('store.wishlist.grid_label'),
        ])
    @endif
</div></section>
</div>
@endsection
