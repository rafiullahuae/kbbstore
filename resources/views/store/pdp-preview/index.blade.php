{{--
    THE CHOOSER. Five designs × the real catalogue.                   (Lane PDP)

    Not a design: a list. It is here so the owner can open any candidate on any
    product without being handed twenty-five URLs, and it is deleted along with
    the four candidates he does not pick.
--}}
@extends('layouts.store')

@section('title', 'Product page — five designs')

@push('head')
    <meta name="robots" content="noindex,nofollow">
@endpush

@push('styles')
    @include('store.pdp-preview._css')
@endpush

@section('content')
<div class="wrap pv-index">
    <h1>Product page — five designs</h1>
    <p>Each one is a whole page, drawn on the real catalogue, at both widths.
       Open the same product in all five and flick between them with the bar at
       the top of each drawing. Nothing here is switched on, and nothing here is
       reachable from the shop.</p>

    @foreach ($candidates as $key => $meta)
        <h2>{{ $meta['name'] }}</h2>
        <p>{{ $meta['idea'] }}</p>
        <ul>
            @foreach ($products as $product)
                <li><a href="{{ url(request()->path().'/'.$key.'/'.rawurlencode($product->slug)) }}">{{ $product->name }}</a></li>
            @endforeach
        </ul>
    @endforeach
</div>
@endsection
