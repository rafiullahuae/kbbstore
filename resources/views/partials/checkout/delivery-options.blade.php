{{--
    The delivery options list — rates plus, when Extended set one, an arrival
    estimate. Factored out of checkout.blade.php so the AJAX refresh that runs
    when the country changes renders the exact same markup the page itself
    does, rather than a second copy that could drift from it.

    Inputs: $rates, $chosenRate. $deliveryEta is optional — absent when called
    from the AJAX endpoint for a zone country, which has no estimate concept.
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
