{{--
    The checkout's Shipping address section with the picker row OFF — which is
    how it ships (Lane CK, 6 October). The owner: "turn off the address row
    completely, from cart and checkout pages, and bring the manual fields under
    address section on checkout page."

    THESE ARE THE FIELDS THE PICKER REPLACED, as they stood before it
    (f94df805), named and id'd exactly as place() and checkout.js have always
    read them: billing_address_1, billing_state, billing_city, billing_country.
    place()'s rules are untouched, so the order carries the same address array
    in the same shape whichever mode the shop is in. Appearance -> Checkout
    page -> Fields & attention -> "Address picker row on cart and checkout"
    brings partials/checkout-address.blade.php back instead.

    FILLED FOR A RETURNING CUSTOMER. $prefill carries the signed-in customer's
    saved address — or the one on their latest order when they have none — as
    CheckoutController::typedAddress() builds it; old() wins over both, so a
    rejected submission comes back as it was typed.
--}}
                        <x-checkout.field name="billing_address_1" :label="__('store.checkout.field_address')" required icon="pin"
                            validate="validate-required" priority="50"
                            :placeholder="__('store.checkout.field_address_placeholder')"
                            autocomplete="section-billing billing address-line1"
                            :value="old('billing_address_1', $prefill['line1'] ?? '')" />

                        <div class="row2">
                            <x-checkout.field name="billing_state" :label="__('store.checkout.field_state')" required icon="map"
                                validate="validate-required validate-state" priority="80"
                                autocomplete="section-billing billing address-level1"
                                :value="old('billing_state', $prefill['state'] ?? '')" />

                            <x-checkout.field name="billing_city" :label="__('store.checkout.field_city')" required icon="city"
                                validate="validate-required" priority="70"
                                :placeholder="__('store.checkout.field_city_placeholder')"
                                autocomplete="section-billing billing address-level2"
                                :value="old('billing_city', $prefill['city'] ?? '')" />
                        </div>
                        <x-checkout.field name="billing_country" :label="__('store.checkout.field_country')" type="select" required icon="globe"
                            validate="validate-required" priority="40"
                            autocomplete="section-billing billing country">
                            @if ($countryDetected ?? false)
                                <x-slot:badge><span class="xd-detected">{{ __('store.checkout.country_detected') }}</span></x-slot:badge>
                            @endif
                            @foreach ($countries as $code => $name)<option value="{{ $code }}" @selected(old('billing_country', $prefill['country'] ?? $defaultCountry) === $code)>{{ $name }}</option>@endforeach
                        </x-checkout.field>
{{-- INLINE, not @push('scripts'). Blade buckets a stack's pushes by render
     depth, so a push from this partial would land in the same bucket as the
     slim footer's and move that script ahead of the checkout's own -- a
     reorder of somebody else's bytes for nothing. Both elements are above
     this line, so the script can run where it stands. --}}<script>
/* THE EMIRATE PRICES THE DELIVERY, so changing it re-prices the page.
   ShippingService::zoneFor() matches `COUNTRY:emirate` first, and place()
   charges on what is posted. checkout.js already re-fetches the rates, the
   free-shipping bar and the totals on a `change` of #billing_country, and that
   request carries #billing_state with it — so the emirate hands its change to
   the country rather than opening a second pricing path. `change`, not
   `input`: one request when the shopper leaves the box, none per keystroke. */
(function () {
  var state = document.getElementById('billing_state');
  var country = document.getElementById('billing_country');
  if (!state || !country) { return; }
  state.addEventListener('change', function () {
    country.dispatchEvent(new Event('change', { bubbles: true }));
  });
})();
</script>
@push('styles')
<style>
/* One rhythm down the four boxes (Lane CK). The checkout's .form-row:last-child
   rule takes City's bottom margin away because City closes .row2, which left
   Country's label touching City's box; and on a phone, where .row2 stacks,
   Emirate kept its 10px on top of the grid's 11px gap. Measured at 390px
   before this: 21px, then 0px. Scoped to these two ids, so no other field on
   the page moves. */
.kbb-checkout #billing_city_field{margin-bottom:10px}
@media(max-width:480px){.kbb-checkout #billing_state_field{margin-bottom:0}}
</style>
@endpush
