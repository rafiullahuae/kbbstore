{{--
    The card fields themselves, under the Credit / Debit Card option.

    There is nothing here that takes a card number. #kbb-card-element is an
    empty box; Stripe.js mounts a cross-origin iframe into it, served by
    js.stripe.com, and the shopper types into Stripe's document. No <input>
    on this page ever holds a PAN, and nothing carrying one is posted to this
    server — which is the difference between this and the one version of this
    feature that would be genuinely unsafe.

    It lives inside .payment_box, so kbb-checkout.css's existing
    `:has(input:checked)` rule shows it only while Credit / Debit Card is the
    selected option — the same reveal every other gateway's description gets,
    with no JavaScript deciding visibility.

    The styles are INLINE rather than in resources/css/kbb/kbb-checkout.css,
    deliberately. Anything added to resources/css ships inert until the asset
    bundle is rebuilt, and the one thing that must not arrive half-applied is
    the box the card fields mount into: an unstyled or zero-height container is
    a checkout that cannot take a payment. The same reasoning puts the script
    in partials/checkout/stripe-elements.blade.php rather than in
    resources/js/kbb/.
--}}
<div class="kbb-card" data-kbb-card>
    <style>
        .kbb-checkout .kbb-card{margin-top:11px}
        /* The mount box. Stripe's iframe is inserted as the only child and
           takes its own height; min-height keeps the box from collapsing to
           nothing in the moment before it arrives, which is what makes the
           card option look broken on a slow connection. */
        .kbb-checkout .kbb-card-el{
            min-height:44px;padding:11px 12px;background:#fff;
            border:1px solid #D9E4DD;border-radius:9px;
        }
        .kbb-checkout .kbb-card-el.is-focused{border-color:#2E9E6B;box-shadow:0 0 0 3px rgba(46,158,107,.13)}
        .kbb-checkout .kbb-card-el.is-invalid{border-color:#C8325C}
        /* The message from Stripe, next to the form. Hidden until there is
           something to say, so it takes no height on an untouched checkout. */
        .kbb-checkout .kbb-card-err{
            display:none;margin:8px 0 0;font-size:12.5px;font-weight:700;
            color:#C8325C;line-height:1.45;
        }
        .kbb-checkout .kbb-card-err.on{display:block}
        .kbb-checkout .kbb-card-note{margin:8px 0 0;font-size:11.5px;font-weight:600;color:var(--muted)}
        /* "Return to your basket", beside a failure. Not shown until one
           happens: until then there is nothing to return from. */
        .kbb-checkout .kbb-card-bail{
            display:none;margin:9px 0 0;padding:0;background:none;border:0;
            font:inherit;font-size:12.5px;font-weight:700;color:var(--ink-2);
            text-decoration:underline;cursor:pointer;
        }
        .kbb-checkout .kbb-card-bail.on{display:inline-block}
        .kbb-checkout .kbb-card-bail[disabled]{opacity:.55;cursor:default}
    </style>

    <div id="kbb-card-element" class="kbb-card-el" data-kbb-card-el></div>

    {{-- role="alert" so a decline is announced, not merely drawn. A shopper
         using a screen reader gets the same sentence at the same moment as a
         shopper looking at the box. --}}
    <p class="kbb-card-err" id="kbb-card-error" data-kbb-card-error role="alert" aria-live="assertive"></p>

    <button type="button" class="kbb-card-bail" data-kbb-card-bail>{{ __('store.checkout.card_return_to_basket') }}</button>

    <p class="kbb-card-note">{{ __('store.checkout.card_secure_note') }}</p>
</div>
