{{--
    ═══════════════════════════════════════════════════════════════════════════
    B · DOSSIER — "one object holds the whole decision."              (Lane PDP)
    ═══════════════════════════════════════════════════════════════════════════

    THE IDEA. The opposite move to Ledger. Everything a shopper needs in order
    to decide — brand, name, price, rating, blurb, bundle bars, quantity, Add to
    cart — goes into ONE white card with a shadow under it, and that card starts
    34px UP the photograph, overlapping it, so it reads as a sheet laid on top
    of the picture rather than as the next thing down the page. Nothing else on
    the page is a card: the tabs, the authenticity line, the delivery line and
    the payment marks all sit on the open page beneath it, which is what makes
    the card mean something.

    WHAT IT ANSWERS. The same finding as Ledger and from the other end. Eleven
    equal blocks become two objects: the thing you are buying, and the notes
    about it.

    ON A DESKTOP THE CARD FOLLOWS YOU. `position:sticky` at the header's height,
    so the decision stays on screen while the gallery scrolls past it — the
    behaviour the shipped page currently gives to the GALLERY, handed to the
    half of the page that has a button in it.

    WHAT IT GIVES UP. It is the most conventional of the five: a shopper has
    seen this card on fifty shops. And a card with this much in it is tall — on
    a phone the blurb, the bundle bars and the button are all inside one
    bordered object, which is a lot of white to look at before the picture
    resumes.

    THE TABS. A pill row that PINS under the site header as you read, so the
    other tabs are reachable from anywhere inside a long Ingredients list
    without scrolling back. The open pill is solid ink and white text; the rest
    are cream at half opacity. "There is more to the right" is said by the
    PEEK: the track stops 40px short of the edge, so the next pill is always
    half-visible and the row can never look finished when it is not.
--}}
@extends('store.pdp-preview._layout')

@section('pv')
<div class="pv-2col">
    <div class="pv-gal">@include('partials.product-gallery')</div>

    <div class="pv-card-buy">
        @include('store.pdp-preview.parts.facts')
        @include('store.pdp-preview.parts.rating')
        @include('store.pdp-preview.parts.blurb')
        @include('store.pdp-preview.parts.options')
        @include('store.pdp-preview.parts.buyrow')
    </div>
</div>

@include('store.pdp-preview.parts.tabs', ['mode' => 'pill'])
@include('store.pdp-preview.parts.assurances')
@endsection
