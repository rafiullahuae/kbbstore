{{--
    THE CHOOSER. Five designs × the real catalogue.                   (Lane PDP)

    Not a design: a list. It is here so the owner can open any candidate on any
    product without being handed twenty-five URLs, and it is deleted along with
    the four candidates he does not pick.

    ── WHY THIS ONE FILE IS UNDER views/admin/ AND THE FIVE DESIGNS ARE NOT ───

    `StorefrontStringsAreKeyedTest` walks every Blade under resources/views
    except `admin/` and reports any bare English sentence. It reported the five
    lines of prose below, and it was right to: this file was sitting in
    `views/store/`, which is where SHOPPER-FACING templates live.

    ▲ THE FIX IS NOT AN EXCLUSION AND NOT FIVE NEW TRANSLATION KEYS.
      An exclusion is a promise that the words behind it are not words a shopper
      reads — true here, but it is a promise made in somebody else's test file.
      And keying them would put five throwaway sentences into the table the
      owner is paying a human translator to fill, for a page that is deleted the
      day he picks a design. (Lane SPL made the same argument about its own
      preview banners and reached the same answer.)

      This is an ADMIN screen: it is behind `auth:admin` and `catalog.view`, no
      shopper can reach it, and `views/admin/` is where the back office's
      templates live. Moving it is the correction; the English is then in the
      one place this project has deliberately deferred.

    ▲ AND THE FIVE DESIGNS DELIBERATELY STAY UNDER views/store/ AND STAY
      SCANNED. They are drafts of the shopper's product page — the chosen one's
      markup moves into resources/views/store/product.blade.php — so a literal
      English sentence in one of them is a defect that should be caught now,
      while it is cheap, rather than on the day it is merged into the shop. All
      five pass the scanner today: everything they print is either a key the
      shop already has or a value off the product.
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
