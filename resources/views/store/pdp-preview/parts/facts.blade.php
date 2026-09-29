{{--
    THE TOP OF THE BUY COLUMN, IN THE ORDER HE NAMED IT.              (Lane PDP)

        "...then small brand name with link, then product name, and right side
         cut price and actual price beautifully present."

    brand (linked, small) → name, with the price pair at the inline-END of that
    same row.

    ▲ WHY THE NAME AND THE PRICE SHARE A ROW AT ALL. The shipped page stacks
      brand / title / capsule / rating / price down the column — eleven blocks
      at one rhythm, which is finding 2 of docs/PP-PRODUCT-PAGE-PROPOSALS.md and
      the reason he says nothing on that page tells you which block is the
      decision. Putting the two together makes one object out of "what this is"
      and "what it costs", which is the object a shopper is actually weighing.

    ▲ TWO TRACKS, AND THE PRICE TRACK IS SIZED TO ITS CONTENT. `minmax(0,1fr)`
      on the title track is what keeps a long product name from pushing the
      price off the inline edge — without the `0` minimum a grid track refuses
      to shrink below its content and the row overflows, which at 390px is a
      horizontal scrollbar on the whole document.

    ▲ THE STRUCK FIGURE SITS ABOVE THE LIVE ONE, not beside it. Beside it, on a
      390px phone with a 40-character K-beauty product name, is where the pair
      wraps and the two prices end up on different lines anyway — at which point
      they have wrapped badly rather than been designed.

    ▲ THE BRAND IS A LINK, AND THE HREF IS THE MODEL'S. Brand::url() is what the
      shop's own tiles use; a product with no brand prints no row rather than an
      empty link. Nothing here builds a URL out of a setting, so there is no
      scheme to check — App\Support\SafeUrl is for the settings that do.

    $withMoney lets candidate C lift the price out of this row and into a band
    of its own; it is a literal from a candidate template, never a request
    value.
--}}
@php $pvWithMoney = $withMoney ?? true; @endphp

@if ($brand)
    <div class="pv-brand">
        @if ($product->brand?->url())
            <a href="{{ $product->brand->url() }}">{{ $brand }}</a>
        @else
            {{ $brand }}
        @endif
    </div>
@endif

<div class="pv-head">
    <h1 class="pv-title">{{ $name }}</h1>
    @if ($pvWithMoney)@include('store.pdp-preview.parts.money')@endif
</div>

@if ($vatLine)<div class="pv-vat">{{ $vatLine }}</div>@endif
