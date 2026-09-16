{{--
    The delivery options list — rates plus, when Extended set one, an arrival
    estimate. Factored out of checkout.blade.php so the AJAX refresh that runs
    when the country changes renders the exact same markup the page itself
    does, rather than a second copy that could drift from it.

    Inputs: $rates, $chosenRate, $deliveryEta.

    $deliveryEta IS NOW PASSED BY BOTH CALLERS, and the note that used to stand
    here — "absent when called from the AJAX endpoint for a zone country, which
    has no estimate concept" — was the bug. The endpoint answers for every
    country in the shopper's dropdown, Extended ones included, so a shopper who
    changed country watched "Arrives in 5-7 days" vanish and never come back.
    CheckoutController::deliveryEta() is the one answer both callers ask for.

    It is still guarded rather than assumed: null and empty both mean "there is
    nothing true to say about arrival here", which is the ordinary state of a
    zone country and of a shop with Extended delivery switched off. An arrival
    estimate is a DURATION and is not the delivery line — that is a whole
    sentence, written on the Delivery lines tab, and it is rendered by
    partials/checkout/delivery-line.blade.php under Place order.
--}}
@forelse ($rates as $i => $rate)
    <ul id="shipping_method" class="woocommerce-shipping-methods">
        <li>
            <input type="radio" name="shipping_method" data-index="{{ $i }}" id="shipping_method_{{ $i }}" value="{{ $rate['id'] }}" class="shipping_method" @checked($rate['id'] === $chosenRate)>
            <label for="shipping_method_{{ $i }}">{{ $rate['title'] }}@if ($rate['cost'] > 0): {!! \App\Support\Money::format((int) $rate['cost']) !!}@endif</label>
        </li>
    </ul>
@empty
    <div class="kbb-delivery-loading">Loading delivery options…</div>
@endforelse
@if (!empty($deliveryEta))
    <p class="xd-eta">Arrives in {{ $deliveryEta }}</p>
@endif
