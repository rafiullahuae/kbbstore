{{-- Ported from kbb_delivery_line_html(). Country-aware copy under the button.

     HIDDEN, NOT OMITTED, WHEN THERE IS NOTHING TO SAY.

     The country selector does not reload this page. checkout.js posts to
     /api/checkout/rates and then writes the answer over `.kbb-delivery-line`,
     hiding the element when the new country has no line and showing it when it
     has one. That only works on an element that is on the page: while this
     partial rendered nothing at all for an empty string, a shopper who ARRIVED
     as Saudi Arabia — no line, so no element — and then switched to the UAE was
     handed the UAE promise by the endpoint and it had nowhere to go. The line
     never appeared, however many times they changed country.

     This is the same defect, and the same repair, as the gift-wrapping row in
     partials/checkout/order-block.blade.php, whose comment puts it best: an
     element that is not there cannot be unhidden.

     The `@if` that remains is the two switches that mean "this shop does not
     use a delivery line at all". When either is off there is no element and
     nothing for the refresh to reveal, which is correct — the feature is off,
     not empty. --}}
@if ($settings->moduleEnabled('delivery_line', true) && $settings->get('delivery_line_enabled', true))
<div class="kbb-delivery-line"@if (! $deliveryText) hidden @endif><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5h11v10H2z"/><path d="M13 8h4.5l3.5 3.5V15h-8z"/><circle cx="6" cy="18" r="1.7"/><circle cx="17" cy="18" r="1.7"/></svg><span>{{ $deliveryText }}</span></div>
@endif
