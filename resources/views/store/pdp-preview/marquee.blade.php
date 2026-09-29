{{--
    ═══════════════════════════════════════════════════════════════════════════
    E · MARQUEE — "a page of full-width bands, and one of them is dark."
                                                                      (Lane PDP)
    ═══════════════════════════════════════════════════════════════════════════

    THE IDEA. Where Ledger takes every edge away and Dossier puts one card in,
    Marquee makes the page out of STRIPES: each group runs the full width of the
    screen, edge to edge, and is told apart from its neighbours by its ground —
    white for the photograph and the name, cream for the bundle bars, white for
    the buy row, INK for the tabs, cream again for the assurances. On a phone
    you are reading a column of bands, not a column of paragraphs, and you can
    tell which band you are in from the colour alone while scrolling fast.

    THE TABS ARE THE DARKEST THING ON THE SHOP. A full-bleed ink band with the
    open tab in solid white and the rest at 45% white — the highest contrast
    between open and closed of the five, which is the most literal reading of
    "opened prominent, and other slightly faded". The panel opens as a white
    sheet directly under the band, so the band reads as the sheet's header.

    "THERE IS MORE TO THE RIGHT" IS SAID BY A PROPORTIONAL HAIRLINE. Two pixels
    of pink under the band, one nth of the band wide, sitting at k nths along
    it. It says both things at once — how many tabs there are, and which one you
    are on — in a rule that costs no width at all, and it is placed by
    arithmetic on the index and the count, never by measuring. `inset-inline-
    start`, so on /ar it runs the other way with no [dir] rule.

    ON A DESKTOP THE PAGE TURNS TWICE. The band stands up: it becomes a 210px
    VERTICAL rail of tab titles down the inline-start edge of the panel, inside
    one bordered object, pinned at the header's height — the shape a long
    reference document wants and the only one of the five whose tab strip is not
    horizontal at 1280. And the gallery turns with it: the thumbnails leave the
    strip under the photograph and stand up in a 72px column beside it, so the
    photograph gets the full remaining width.

    WHAT IT GIVES UP. A dark band across a beauty shop is a strong statement and
    it is the one thing here that could date. The stripes also make the page feel
    longer than it is, because every seam is drawn rather than implied.
--}}
@extends('store.pdp-preview._layout')

@section('pv')
<div class="pv-2col">
    <div class="pv-gal">@include('partials.product-gallery')</div>

    <div class="pv-body-in">
        <div class="pv-strip pv-strip-plain">
            @include('store.pdp-preview.parts.facts')
            @include('store.pdp-preview.parts.rating')
            @include('store.pdp-preview.parts.blurb')
        </div>
        <div class="pv-strip pv-strip-tint">
            @include('store.pdp-preview.parts.options')
        </div>
        <div class="pv-strip pv-strip-plain">
            @include('store.pdp-preview.parts.buyrow')
        </div>
    </div>
</div>

@include('store.pdp-preview.parts.tabs', ['mode' => 'band'])

<div class="pv-strip pv-strip-tint pv-strip-last">
    @include('store.pdp-preview.parts.assurances')
</div>
@endsection
