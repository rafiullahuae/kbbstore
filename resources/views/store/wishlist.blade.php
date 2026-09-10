@extends('layouts.store')
@php use App\Support\Url; @endphp

@section('title', 'Wishlist · K-Beauty Bliss')

@push('styles')
    @vite('resources/css/kbb/kbb-grid-skins.css')
@endpush

@section('content')
<div class="kbb-home">
<section class="sec"><div class="wrap">
    <nav class="crumb"><a href="{{ Url::to('/') }}">Home</a> / <span>Wishlist</span></nav>

    <div class="sh">
        <div>
            <h2>Wishlist @if ($products->isNotEmpty())<span class="cnt">{{ $products->count() }} saved</span>@endif</h2>
            <p>Everything you have saved, newest first.</p>
        </div>
        @if ($products->isNotEmpty())<a class="lnk" href="{{ Url::to('/shop/') }}">Keep browsing</a>@endif
    </div>

    @if ($products->isEmpty())
        <div class="wl-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg>
            <h3>Nothing saved yet</h3>
            <p>Tap the heart on any product to keep it here for later.</p>
            <a class="btn btn-p" href="{{ Url::to('/shop/') }}">Start browsing</a>
        </div>
    @else
        @include('partials.home.grid', [
            'items' => $products,
            'skin' => $settings->get('grid_skin', 'classic'),
            'catLabel' => 'Saved',
        ])
    @endif
</div></section>
</div>
@endsection
