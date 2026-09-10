@extends('layouts.store')
@php use App\Support\Url; @endphp

@section('title', $title . ' · K-Beauty Bliss')

@push('styles')
    @vite('resources/css/kbb/kbb-grid-skins.css')
@endpush

@section('content')
<div class="kbb-home">
<section class="sec"><div class="wrap">
    <nav class="crumb"><a href="{{ Url::to('/') }}">Home</a> / <span>{{ $title }}</span></nav>

    <div class="sh">
        <div>
            <h2>{{ $title }} <span class="cnt">{{ number_format($products->total()) }} products</span></h2>
            <p>{{ $intro }}</p>
        </div>
        <a class="lnk" href="{{ Url::to('/shop/') }}">All products</a>
    </div>

    @if ($products->isEmpty())
        <p class="empty">Nothing here just yet. <a href="{{ Url::to('/shop/') }}">Browse the full range</a>.</p>
    @else
        @include('partials.home.grid', [
            'items' => $products,
            'skin' => $settings->get('grid_skin', 'classic'),
            'catLabel' => $title,
        ])

        <div class="pager">{!! $products->links() !!}</div>
    @endif
</div></section>
</div>
@endsection
