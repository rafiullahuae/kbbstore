{{--
    The checkout's Shipping address with the Emirate as a LIST (Lane AD).
    Appearance -> Checkout page -> Fields & attention -> "Emirate / state as a
    list", on as the owner asked: "bring the EMIRATES field above country ...
    if country is UAE, all 7 emirates list should be there, if oman and so on".
    Off renders partials/checkout/address-fields exactly as it was.

    ORDER: Address, City / area, Emirate, Country. The Emirate sits directly
    above Country -- on a phone in the one column, on a laptop as the right
    half of the row whose left half is City -- so the list the shopper reads
    is the one for the country right under it.

    SAME NAMES, IDS AND AUTOCOMPLETE TOKENS as the typed fields, so place(),
    checkout.js, express wallets and inline validation read them unchanged. The
    value posted is the English name ("Dubai"); the text shown is Arabic and
    English, as the owner's screenshot of the old site has it. A country with
    no list (anything Extended delivery adds beyond the Gulf) keeps the typed
    box, labelled "State / region".

    $statePick is the controller's: old() first, else the saved state, else the
    saved city, matched to the list ("dubai", "DXB", "AE-DU" are Dubai). The
    page is priced on the same value, so the rate drawn is the rate charged.
--}}
@php
    $kbbStCountry = strtoupper((string) old('billing_country', $prefill['country'] ?? $defaultCountry));
    $kbbStHas = \App\Support\AddressRegions::has($kbbStCountry);
    $kbbStLabelText = __(\App\Support\AddressRegions::labelKey($kbbStCountry));
@endphp
                        <x-checkout.field name="billing_address_1" :label="__('store.checkout.field_address')" required icon="pin"
                            validate="validate-required" priority="50"
                            :placeholder="__('store.checkout.field_address_placeholder')"
                            autocomplete="section-billing billing address-line1"
                            :value="old('billing_address_1', $prefill['line1'] ?? '')" />

                        <div class="row2">
                            <x-checkout.field name="billing_city" :label="__('store.checkout.field_city')" required icon="city"
                                validate="validate-required" priority="70"
                                :placeholder="__('store.checkout.field_city_placeholder')"
                                autocomplete="section-billing billing address-level2"
                                :value="old('billing_city', $prefill['city'] ?? '')" />

@if ($kbbStHas)
                            <x-checkout.field name="billing_state" :label="$kbbStLabelText" type="select" required icon="map"
                                validate="validate-required validate-state" priority="80"
                                autocomplete="section-billing billing address-level1">
                                <option value="" disabled @selected($statePick === null)>{{ __('store.checkout.field_state_select') }}</option>@foreach (\App\Support\AddressRegions::options($kbbStCountry) as $kbbStValue => $kbbStText)<option value="{{ $kbbStValue }}" @selected($statePick === $kbbStValue)>{{ $kbbStText }}</option>@endforeach
                            </x-checkout.field>
@else
                            <x-checkout.field name="billing_state" :label="$kbbStLabelText" required icon="map"
                                validate="validate-required validate-state" priority="80"
                                autocomplete="section-billing billing address-level1"
                                :value="old('billing_state', $prefill['state'] ?? '')" />
@endif
                        </div>
                        <x-checkout.field name="billing_country" :label="__('store.checkout.field_country')" type="select" required icon="globe"
                            validate="validate-required" priority="40"
                            autocomplete="section-billing billing country">
                            @if ($countryDetected ?? false)
                                <x-slot:badge><span class="xd-detected">{{ __('store.checkout.country_detected') }}</span></x-slot:badge>
                            @endif
                            @foreach ($countries as $code => $name)<option value="{{ $code }}" @selected(old('billing_country', $prefill['country'] ?? $defaultCountry) === $code)>{{ $name }}</option>@endforeach
                        </x-checkout.field>
@include('partials.address-state-list', [
    'kbbStCountries' => array_keys($countries),
    'kbbStFor' => $kbbStCountry,
    'kbbStCountry' => 'billing_country',
    'kbbStState' => 'billing_state',
    'kbbStLabel' => 'label[for="billing_state"]',
])
<script>
/* THE EMIRATE PRICES THE DELIVERY -- the same hand-off as the typed fields'
   (partials/checkout/address-fields): a change of emirate is passed to the
   country, whose handler in checkout.js re-fetches the rates with the emirate
   in the request. Listened for on the document rather than on the element,
   because the country switch above may replace the element with a typed box
   and back. One request per choice, none per keystroke. */
document.addEventListener('change', function (e) {
  if (!e.target || e.target.id !== 'billing_state') { return; }
  var country = document.getElementById('billing_country');
  if (country) { country.dispatchEvent(new Event('change', { bubbles: true })); }
});
</script>
@push('styles')
<style>
/* One rhythm down the four boxes, as Lane CK set it for the typed fields, with
   the two halves of .row2 swapped: Emirate now closes the row, so it is the one
   .form-row:last-child would leave touching Country; on a phone City is the
   one that would stack its 10px onto the grid's 11px gap. */
.kbb-checkout #billing_state_field{margin-bottom:10px}
@media(max-width:480px){.kbb-checkout #billing_city_field{margin-bottom:0}}
/* The chevron Country already wears (kbb-checkout.css), on the same side in
   both directions: padding on the inline end, the picture moved under RTL. */
#billing_state_field select.input-text{
  appearance:none;-webkit-appearance:none;
  background-image:url("data:image/svg+xml;utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238A7F86' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 12px center;background-size:15px;
  padding-inline-end:36px;text-overflow:ellipsis}
[dir="rtl"] #billing_state_field select.input-text{background-position:left 12px center}
/* Floating labels: while nothing is chosen the label IS the placeholder, so
   the "Select" line is not drawn under it until the box is opened. The list's
   own lines keep their colour. */
.kbb-checkout .fld #billing_state:has(option[value=""]:checked):not(:focus){color:transparent}
#billing_state option{color:var(--ink,#2b2226)}
#billing_state option[value=""]{color:var(--muted,#8a7f86)}
</style>
@endpush
