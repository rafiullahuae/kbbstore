{{--
    THE FRAME ALL FIVE DRAWINGS HANG IN.                              (Lane PDP)

    The real storefront layout, the real header, the real footer, the real
    product stylesheet — and then this lane's sheet on top of it. A candidate
    photographed inside a bare HTML page would be a drawing of a page this shop
    does not serve, and the header's height is exactly what decides whether a
    pinned tab row looks right.

    ▲ noindex, AND IT IS BELT AND BRACES. These URLs live under
      `admin-api/catalog/`, behind `auth:admin` and `catalog.view`, so a crawler
      cannot reach one to begin with. The meta costs nothing and means that if
      the route is ever moved by mistake, the mistake is not also an indexing
      incident.

    ▲ THE SWITCHER BAR IS NOT PART OF ANY DESIGN. It is how the owner moves
      between the five without going back to the chooser, and it is the first
      thing deleted along with four of the candidates. It is drawn OUTSIDE
      `.wrap` so it can be full width, and it is `position:sticky` so it does
      not scroll away while he is comparing — the one place on these pages where
      something follows you that is not being proposed.
--}}
@extends('layouts.store')

@section('title', $pvName.' — '.$name)

@push('head')
    <meta name="robots" content="noindex,nofollow">
@endpush

@push('styles')
    @vite('resources/css/kbb/kbb-product.css')
    @include('store.pdp-preview._css')
@endpush

@section('content')
<div class="pv pv-{{ $pvLetter }}">
    <div class="pv-switch">
        <b>{{ $pvName }}</b>
        @foreach ($pvAll as $pvKey => $pvMeta)
            <a class="{{ $pvKey === $pvCandidate ? 'on' : '' }}" href="{{ $pvLinks[$pvKey] }}">{{ $pvMeta['name'] }}</a>
        @endforeach
        <span class="pv-idea">{{ $pvIdea }}</span>
    </div>
    <div class="wrap">
        @yield('pv')
    </div>
</div>
@endsection
