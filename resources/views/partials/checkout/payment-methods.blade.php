{{--
    The payment options list — the inside of #payment, factored out of
    checkout.blade.php so the one-tap Browsed add re-renders the exact markup
    the page itself rendered, rather than a second copy that could drift.

    Why this has to move at all when the bag changes: PayShipRules enforces a
    Cash-on-delivery order-value window, and the window is measured against the
    order TOTAL — which shipping, the free-delivery threshold and the new line
    all feed. So adding one product can take Cash on delivery off the table (or
    put it back). A summary that updates while this list stands still leaves the
    shopper holding a method place() will refuse at the last step, which is the
    exact confusion one-tap adding is meant to remove.

    Inputs: $gateways, $codHidden.
    Optional: $selectedMethod — the id to keep checked when it is still offered;
              $payNotice      — said out loud when the selection had to move.
--}}
@php
    // One rule for which radio is checked, so the page and the refresh cannot
    // disagree: the asked-for method when it is still on offer, otherwise the
    // first one. Never "nothing checked" — a payment section with no selection
    // posts no payment_method at all and fails validation on Place order.
    $payIds = array_column($gateways, 'id');
    $payChecked = in_array($selectedMethod ?? null, $payIds, true)
        ? ($selectedMethod ?? null)
        : ($payIds[0] ?? null);
@endphp
@if (!empty($payNotice))<p class="pay-note pay-moved" role="status" aria-live="polite">{{ $payNotice }}</p>@endif
@if ($gateways === [] && empty($payNotice))
{{-- A CHECKOUT WITH NOTHING TO PAY WITH SAYS SO.

     PaymentProviderSeeder installs all four gateways switched off, so a shop
     that has not yet been through Store → Payments renders this step as an
     empty list under a live Place order button. Pressing it answered "The
     payment method field is required." — a validation message about a field
     that was never on the page, which reads as the shopper's mistake. Walked
     end to end in Chromium: zero radios, that sentence, no way forward.

     The wording is the one fragments() already uses when the last method is
     withdrawn by a quantity change, so the page says the same thing however
     the shopper arrives at it. --}}
<p class="pay-note pay-empty" role="status" aria-live="polite">No payment method is available for this order total. Please contact us and we will take your order directly.</p>
@endif
            <ul class="wc_payment_methods payment_methods methods">
            @if (!empty($codHidden))<p class="pay-note">{{ $codHidden }}</p>@endif
            @foreach ($gateways as $g)
            <li class="wc_payment_method payment_method_{{ $g['id'] }}">
    <input id="payment_method_{{ $g['id'] }}" type="radio" class="input-radio" name="payment_method" value="{{ $g['id'] }}" @checked($g['id'] === $payChecked) data-order_button_text="" />

    <label for="payment_method_{{ $g['id'] }}">
        {{ $g['title'] }}
        {{-- The fee, right on the option — not only in the paragraph below,
             which is easy to miss until after it has already been chosen. --}}
        @if (!empty($g['fee_html']))<span class="codfee">{!! $g['fee_html'] !!}</span>@endif
    </label>
            @if ($g['description'])
            {{-- Shown purely by :has() on .kbb-checkout below, matching how
                 the selected-option highlight on this same list already
                 works — no JS, and correct for whichever option is checked
                 rather than only ever the first one. --}}
            <div class="payment_box payment_method_{{ $g['id'] }}">
            <p>{!! $g['description'] !!}</p>
        </div>
            @endif
    </li>
            @endforeach
        </ul>
