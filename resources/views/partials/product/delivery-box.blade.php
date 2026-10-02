{{--
    The delivery box — Lane PW.

      "on product page i want two things further, one is the same yellowish
       delivery box. the image i'm attaching."

    A soft yellow-cream box, full width of the buy column, ABOVE the quantity
    and Add to cart row: the "FAST DELIVERY" picture on the inline-start side,
    two lines of text beside it, centred on it. Controls: Appearance → Product
    page → Trust · Delivery box, and its spacing on Trust · Spacing.

    ▲ THE PICTURE. Until the owner uploads his own, the box draws the mark in
      App\Support\TrustShareIcons::TRUCK — a constant, so `{!! !!}` prints a
      constant and nothing else. His upload is a URL, so it goes through
      SafeUrl::src() (in deliveryImage()) and through `{{ }}` into the src;
      `javascript:` and `//other-host` come back as '' and the drawn mark is
      used instead.

    ▲ THE SECOND LINE'S FIGURE IS THE CHECKOUT'S. {free_from} is
      ShippingService::thresholdHere(), memoised on the Request and asked by
      the layout's composer on this same request, so the box cannot quote a
      threshold the basket does not honour. No free delivery where the shopper
      is, no second line.

    ▲ NOTHING HERE IS ON ITS OWN LINE OUTSIDE THE @if. A directive's newline
      is swallowed and a comment's is not, so this comment is glued to the
      directive after it: switched off, the partial adds not one byte to the
      page.
--}}@php
    $kbbPts = app(\App\Services\ProductTrustShare::class);
@endphp
@if ($kbbPts->on('del_on'))
@include('partials.product.trust-share-assets')
@php
    $kbbPtsImg = $kbbPts->deliveryImage();
    $kbbPtsLine2 = $kbbPts->deliveryLine2();
@endphp
<div class="pts-del" data-pts="del" style="{{ $kbbPts->vars('del') }}"><span class="pts-del-logo">@if ($kbbPtsImg !== '')<img src="{{ $kbbPtsImg }}" alt="" loading="lazy" decoding="async">@else{!! \App\Support\TrustShareIcons::TRUCK !!}@endif</span><span class="pts-del-text"><span>{{ $kbbPts->text('del_line1') }}</span>@if ($kbbPtsLine2 !== '')<span>{{ $kbbPtsLine2 }}</span>@endif</span></div><!--/pts-del-->
@endif
