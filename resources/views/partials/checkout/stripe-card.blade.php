{{--
    The card fields themselves, under the Credit / Debit Card option.

    There is nothing here that takes a card number. Each of the three boxes
    below is EMPTY; Stripe.js mounts a cross-origin iframe into it, served by
    js.stripe.com, and the shopper types into Stripe's document. No <input>
    on this page ever holds a PAN, an expiry or a CVC, and nothing carrying one
    is posted to this server — which is the difference between this and the one
    version of this feature that would be genuinely unsafe.

    ── WHY THREE BOXES AND NOT ONE ────────────────────────────────────────────

    This used to be a single Stripe `card` Element: one bordered strip with the
    number, the expiry and the CVC sharing it, laid out by Stripe and labelled
    by nothing but Stripe's own placeholders. The owner asked for the layout his
    reference shows — the card number on its own row, expiry and security code
    side by side beneath it, each with its own label above its own box.

    That layout is only possible with Stripe's INDIVIDUAL Elements
    (`cardNumber`, `cardExpiry`, `cardCvc`), which is what
    partials/checkout/stripe-elements.blade.php now creates. Each one is still a
    Stripe-hosted iframe with exactly the same security properties as the
    combined element: the split is in whose document draws the BOX, not in whose
    document holds the VALUE. Nothing about where a card number goes has
    changed, and there is still no <input> of ours anywhere near one.

    The labels are <span>, not <label for="…">. There is no element on this page
    for a `for` to point at — the control is inside Stripe's iframe and carries
    Stripe's own accessible name ("Credit or debit card number"), which a screen
    reader reads from there. A `for` aimed at the empty mount div would associate
    nothing, and a bare <label> with no control is meaningless markup. Each box
    is a group named by its own label instead, so the relationship is stated
    rather than left to be inferred from position.

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
@php
    /*
     * WHO IS OFFERED "save this card", decided here and enforced again in
     * CheckoutController::place().
     *
     * A saved card is only worth anything to somebody who can come back and be
     * recognised, which means an account they can sign into.
     *
     *   - A signed-in customer has one, so the row is drawn and ticked or not
     *     as they please.
     *   - A guest will have one a moment from now IF they tick "create an
     *     account" in this same checkout — that box is two steps up this page —
     *     so the row is rendered for them and revealed by the script when they
     *     do, and hidden and cleared again if they change their mind.
     *   - A guest who is not creating an account has nothing to attach a card
     *     to and never will. The row is rendered for them too, hidden, and the
     *     server refuses a `save_card` posted without an account either way.
     *
     * The one case that must never happen is a card attached to an account this
     * shopper does not control: place() writes an order against an EXISTING
     * customer row when a guest types the email address of one, so "this order
     * has a customer" is not the same question as "this shopper has an
     * account". The server answers the second one; see place().
     */
    $cardCustomer = auth('customer')->user();
@endphp
<div class="kbb-card" data-kbb-card>
    <style>
        .kbb-checkout .kbb-card{margin-top:11px}
        /*
         * EVERY MARGIN BELOW SITS ON TOP OF A 9px GRID GAP, and that is worth
         * knowing before any of them is changed. kbb-checkout.css line 246 says
         * `.kbb-checkout .payment_box .kbb-card{display:grid;gap:9px}` — 0,3,0,
         * which nothing here outranks — so the box this partial draws is a grid
         * and its children are already 9px apart. The numbers here are the
         * remainder that brings each gap to the 12px the rows inside use.
         */
        /* The one line above the fields. It replaced a three-sentence
           paragraph that described where the card was typed and what would
           happen after Place order; the owner asked for one short reassurance
           with a lock on it, and the fields below now say the rest themselves.

           `p.kbb-card-secure`, not the bare class: `.kbb-checkout .payment_box p`
           is 0,2,1 and sets a margin of its own. Same tie-and-win on source
           order as the save row below, and the same measurement behind it. */
        .kbb-checkout p.kbb-card-secure{
            display:flex;align-items:center;gap:7px;margin:0 0 3px;
            font-size:12px;font-weight:700;color:var(--ink-2);line-height:1.4;
        }
        .kbb-checkout .kbb-card-secure svg{width:14px;height:14px;flex-shrink:0;color:#2E9E6B}
        /* The rows: number on its own, then expiry and security code side by
           side. One grid, so the two lower boxes stay equal at every width.
           They stack below 380px, where two boxes plus the gap leave each one
           narrower than the value it has to show. */
        .kbb-checkout .kbb-card-rows{display:grid;gap:12px}
        .kbb-checkout .kbb-card-pair{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        @media (max-width:379px){.kbb-checkout .kbb-card-pair{grid-template-columns:1fr}}
        .kbb-checkout .kbb-card-lb{
            display:block;margin:0 0 5px;font-size:10.5px;font-weight:800;
            letter-spacing:.06em;text-transform:uppercase;color:var(--ink-2);
        }
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
            display:none;margin:10px 0 0;font-size:12.5px;font-weight:700;
            color:#C8325C;line-height:1.45;
        }
        .kbb-checkout .kbb-card-err.on{display:block}
        /* "Save this card". Metrics taken from .kbb-wa and .kbb-gift-opt rather
           than guessed, so every tick on this checkout sits on the same optical
           line. The box is the browser's own, coloured with accent-color, which
           is what draws the tick in it when it is checked.

           `label.kbb-card-save`, NOT `.kbb-card-save`, and for the reason the
           account and gift rows already carry in store/checkout: this box lives
           inside .payment_box, and kbb-checkout.css line 248 says
           `.kbb-checkout .payment_box label{font-size:10.5px;display:block}` —
           0,2,1, which beats a bare 0,2,0 outright. Written as a bare class
           first, this rule lost every declaration in it: the row rendered
           `display:block` with no gap and the label at 10.5px, measured in
           Chromium. Qualified, it ties at 0,2,1 and wins on source order,
           because this <style> is in the body and that sheet is in the <head>. */
        .kbb-checkout label.kbb-card-save{
            display:flex;align-items:center;gap:9px;margin:3px 0 0;
            font-size:12px;font-weight:500;color:var(--ink-2);line-height:1.45;cursor:pointer;
        }
        .kbb-checkout label.kbb-card-save input[type="checkbox"]{
            width:17px;height:17px;margin:0;accent-color:var(--pink);flex-shrink:0;cursor:pointer;
        }
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

    <p class="kbb-card-secure">
        {{-- The padlock is drawn here rather than fetched: an <img> would be a
             second request for fourteen pixels and there is no icon font on
             this page. Same shackle-and-body shape as the one in the checkout
             header, so the two read as one mark. --}}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        {{ __('store.checkout.card_secure_line') }}
    </p>

    <div class="kbb-card-rows">
        <div>
            <span class="kbb-card-lb" id="kbb-card-number-label">{{ __('store.checkout.card_number_label') }}</span>
            <div id="kbb-card-number" class="kbb-card-el" data-kbb-card-el="number"
                 role="group" aria-labelledby="kbb-card-number-label"></div>
        </div>

        <div class="kbb-card-pair">
            <div>
                <span class="kbb-card-lb" id="kbb-card-expiry-label">{{ __('store.checkout.card_expiry_label') }}</span>
                <div id="kbb-card-expiry" class="kbb-card-el" data-kbb-card-el="expiry"
                     role="group" aria-labelledby="kbb-card-expiry-label"></div>
            </div>
            <div>
                <span class="kbb-card-lb" id="kbb-card-cvc-label">{{ __('store.checkout.card_cvc_label') }}</span>
                <div id="kbb-card-cvc" class="kbb-card-el" data-kbb-card-el="cvc"
                     role="group" aria-labelledby="kbb-card-cvc-label"></div>
            </div>
        </div>
    </div>

    {{--
        SAVE THIS CARD.

        Ticking it puts `setup_future_usage` on the PaymentIntent and attaches
        the card to a Stripe Customer belonging to this shopper's account, so
        the card is genuinely saved rather than the box merely being drawn.

        What it does NOT do yet is offer that card back at the next checkout.
        That is the follow-up, and it is the reason the row is withheld from
        anybody who could not be offered one even once it exists: a guest with
        no account cannot be recognised on their next visit, so for them this
        tick could never mean anything at all.
    --}}
    <label class="kbb-card-save" data-kbb-card-save-row @if ($cardCustomer === null)hidden @endif>
        <input type="checkbox" name="save_card" id="kbb_save_card" value="1" data-kbb-card-save @checked(old('save_card'))>
        <span>{{ __('store.checkout.card_save') }}</span>
    </label>

    {{-- role="alert" so a decline is announced, not merely drawn. A shopper
         using a screen reader gets the same sentence at the same moment as a
         shopper looking at the box. --}}
    <p class="kbb-card-err" id="kbb-card-error" data-kbb-card-error role="alert" aria-live="assertive"></p>

    <button type="button" class="kbb-card-bail" data-kbb-card-bail>{{ __('store.checkout.card_return_to_basket') }}</button>
</div>
