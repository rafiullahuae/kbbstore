{{--
    "then Authenticity line, delivery line , and payment icons." (Lane PDP)

    THREE THINGS, EACH SAID ONCE, IN HIS ORDER. The measured complaint against
    the shipped page was three delivery promises in two places and a payment row
    repeating Tabby and Tamara that the trust card had just named; so this is
    one authenticity line, one delivery line, and one row of payment marks, and
    nothing above them repeats any of it.

    ▲ EVERY SENTENCE IS THE OWNER'S OWN, ALREADY. Nothing here is a literal.
      App\Support\TrustClaims::text() answers the authenticity line the owner
      typed (its own key, not the checkout's — a shared box would make clearing
      this one silently clear the one beside Place order), and
      App\Support\DeliveryLine::here() answers the delivery line for the country
      the shopper is standing in, through App\Support\ShopperCountry. Neither
      issues a query and both answer for a request with no session and no geo
      signal at all.

    ▲ AN EMPTY ANSWER IS A REAL ANSWER and means draw no line. That is why each
      is a conditional rather than an empty string: a blank row with an icon
      beside it is worse than no row. A preview that invented "Fast UAE
      delivery" to make the drawing look finished would be showing him a promise
      this application does not hold — which is the exact defect Lane CF removed
      from the checkout and App\Mail\OrderStatusChanged removed from the
      dispatch email.

    ▲ THE PAYMENT MARKS ARE ASKED FOR, NOT TYPED. App\Support\PaymentChips::row()
      gates Apple Pay and Google Pay on App\Services\Payments\Wallets, which is
      the one place that knows whether this shop can take either. A drawing that
      listed a wallet this shop cannot take is a drawing of a shop that does not
      exist.
--}}
@php
    $pvAuthentic = \App\Support\TrustClaims::text($settings, 'product_authentic_text');
    $pvDelivery = \App\Support\DeliveryLine::here();
@endphp

<div class="pv-assure">
    @if ($pvAuthentic !== null)
        <div class="pv-as"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.5 8 8 11 4.5-3 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/></svg><span>{{ $pvAuthentic }}</span></div>
    @endif
    @if ($pvDelivery !== '')
        <div class="pv-as"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7h13v10H3z"/><path d="M16 10h4l1 3v4h-5z"/><circle cx="7" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/></svg><span>{{ $pvDelivery }}</span></div>
    @endif
    <div class="pv-pay">@foreach (\App\Support\PaymentChips::row('product') as $pvChip)<span>{{ $pvChip }}</span>@endforeach<span>{{ __('store.footer.pay_cod') }}</span></div>
</div>
