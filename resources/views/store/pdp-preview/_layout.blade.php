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
    {{--
        ▲ AND THE THIRTY CUSTOM PROPERTIES THE OWNER'S SLIDERS WRITE.   (R5)

        This was MISSING, and round 4's own handover reported it under "found
        and not fixed": the line above loads kbb-product.css, which reads the
        page's spacing and type as `var(--pl-x, <literal>)`, and nothing on
        these five pages ever emitted the block that SETS those properties.

        ▲ HOW MUCH OF THE FIVE THIS REALLY MOVES, MEASURED RATHER THAN CLAIMED:
          ONE RULE. The candidates are drawn in markup of their own — every
          class in ledger/dossier/counter/deck/marquee and in parts/ is `pv-…`
          — so the `.pdp .bb-title` and `.sec h2` rules the variables reach do
          not apply inside them at all. The single shared selector is
          `.dcontent`, the body of an opened detail tab, which the five borrow
          from the shipped stylesheet. So this include makes the drafts agree
          with the shop on the tab-body size and line spacing, and nothing
          else. It is still the right line: the alternative is five drawings
          that disagree with the shop on a number the owner has set, for no
          reason anyone could name from reading the file.

        ▲ AND IT IS NOT WHAT `Appearance → Product page` PREVIEWS. That panel
          frames `/product/{slug}` — the shipped page, the one these variables
          were written for — precisely because these five do not read them.

        The partial emits NOTHING while every value is at its shipped default
        (see its own header), so this line adds no byte to any preview until
        something has actually been moved.
    --}}@include('partials.product-layout-css')
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
