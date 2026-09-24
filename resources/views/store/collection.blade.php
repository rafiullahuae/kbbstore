@extends('layouts.store')
@php use App\Support\Url; @endphp

@section('title', __('store.collection.page_title', ['title' => $title]))

@push('styles')
    @vite('resources/css/kbb/kbb-grid-skins.css')
@endpush

@section('content')
<div class="kbb-home">
<section class="sec"><div class="wrap">
    <nav class="crumb"><a href="{{ Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <span>{{ $title }}</span></nav>

    <div class="sh">
        <div>
            {{-- <h1>, not <h2>: this is the page's own heading and /new-in,
                 /best-sellers, /super-sale and /everything-under-54-aed each
                 rendered no <h1> at all. There is exactly one .sh block on this
                 view, so this cannot produce a second. The size is unchanged —
                 .kbb-home .sh :is(h1,h2) in kbb.css matches both tags. --}}
            <h1>{{ $title }} <span class="cnt">{{ trans_choice('store.collection.product_count', $products->total(), ['formatted' => number_format($products->total())]) }}</span></h1>
            <p>{{ $intro }}</p>
        </div>
        <a class="lnk" href="{{ Url::to('/shop/') }}">{{ __('store.collection.all_products') }}</a>
    </div>

    @if ($products->isEmpty())
        <p class="empty">{!! __('store.collection.empty', ['link' => '<a href="' . e(Url::to('/shop/')) . '">' . e(__('store.collection.empty_link')) . '</a>']) !!}</p>
    @else
        @include('partials.home.grid', [
            'items' => $products,
            'skin' => $settings->get('grid_skin', 'classic'),
            {{-- The small line above each product's name. It is the page title
                 for the four curated listings, whose titles are two words
                 ("New In", "Super Sale") and read well there.

                 A concern page's title is a SENTENCE aimed at a search result
                 ("Korean skincare for acne-prone skin"), which wraps to two
                 lines on every card and repeats the heading twenty-four times
                 down the page. So a caller may pass a shorter one. Nothing
                 passes it today except CollectionController::concern(), and
                 the four listings render byte-for-byte what they rendered
                 before this line existed. --}}
            'catLabel' => $cardLabel ?? $title,
        ])

        <div class="pager">{!! $products->links() !!}</div>
    @endif
</div></section>
</div>
@endsection
