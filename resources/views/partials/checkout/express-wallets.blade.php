{{--
    Apple Pay and Google Pay on /checkout/ — Stripe's Express Checkout Element.

    ═══════════════════════════════════════════════════════════════════════════
    WHAT THIS REPLACED
    ═══════════════════════════════════════════════════════════════════════════

    Two buttons that did nothing:

        <div class="express" aria-hidden="true">
          <button type="button" class="xbtn xapple" tabindex="-1"> Apple&nbsp;Pay</button>
          <button type="button" class="xbtn xgoogle" tabindex="-1">…Pay</button>
        </div>

    They were drawn on every checkout, on every browser, with no listener behind
    them and no gateway behind that — `aria-hidden` and `tabindex="-1"` kept a
    screen reader and a keyboard away from them, which is a fair description of
    what they were: a picture of a feature. This is the feature.

    ═══════════════════════════════════════════════════════════════════════════
    IT RIDES THE CARD GATEWAY. THERE IS NO SECOND PAYMENT PIPELINE
    ═══════════════════════════════════════════════════════════════════════════

    Apple Pay and Google Pay are CARD wallets: the PaymentMethod Stripe returns
    from either sheet has `type: card` and carries `card.wallet.type`. So the
    money moves over the same PaymentIntent a typed card moves over — the one
    StripeGateway::openIntent() opens with `payment_method_types: ['card']`,
    which already admits both and needed no widening. `orders.payment_method`
    stays `stripe`, and the capturer, the refunder, the voider, the ledger and
    the reconciler are untouched: a wallet order is refundable by exactly the
    same button as a card order, on day one.

    ═══════════════════════════════════════════════════════════════════════════
    THE ONE BEHAVIOUR THAT MATTERS MOST: NEVER A DEAD BUTTON
    ═══════════════════════════════════════════════════════════════════════════

    A shopper offered Apple Pay on a browser that cannot do Apple Pay is worse
    off than one who was never offered it — they press it, nothing happens, and
    they have learned that this shop's checkout is broken. So the row is drawn
    `hidden`, and the ONLY thing that reveals it is Stripe telling us, in the
    element's `ready` event, that this browser has a wallet with a card in it.
    If `availablePaymentMethods` comes back empty, or the element fails to load,
    or Stripe.js never arrives, the row and its "or pay with" divider are
    REMOVED FROM THE DOCUMENT rather than left hidden — there is then no gap, no
    stray divider, and nothing to press.

    Four gates have already passed on the server before this partial renders at
    all (App\Services\Payments\Wallets): the card gateway is switched on, its
    secret key is present, its publishable key is present, and the merchant has
    switched that wallet on in Store → Payments. The browser gate above is the
    fifth and it is the only one that can be asked from here.

    ═══════════════════════════════════════════════════════════════════════════
    WHY THE SCRIPT IS IN THE VIEW
    ═══════════════════════════════════════════════════════════════════════════

    The same reason partials/checkout/stripe-elements gives at length: this host
    serves BUILT assets and has no Node, so anything added under resources/js
    ships inert until somebody rebuilds the bundle off-server. A checkout that
    cannot take a payment is an outage, so the code that takes it travels in the
    view that draws it, where a package is enough on its own.

    js.stripe.com IS NOT LOADED HERE. partials/checkout/stripe-elements loads it
    later in the same document, under exactly the condition that makes this
    partial render — Stripe on offer with a publishable key — so a second
    <script src> would be a second copy of the same file. This waits for it
    instead, and if it never arrives it removes the row. See waitForStripe().
--}}
@php
    /*
     * Everything this row needs, decided on the server.
     *
     * $gateways is the checkout's own list of what may be paid with, so
     * `stripe` being in it means the card gateway passed every gate
     * GatewayRegistry applies to THIS basket — configured, enabled, and
     * available for this total and country. The wallet switches sit on top of
     * that, and App\Services\Payments\Wallets is the one place they are read;
     * the footer, the basket and the product page ask the same object.
     */
    $kbbWallets = app(\App\Services\Payments\Wallets::class);
    $kbbStripe = app(\App\Services\Payments\GatewayRegistry::class)->find('stripe');
    $kbbStripeOnOffer = $kbbStripe instanceof \App\Services\Payments\Gateways\StripeGateway
        && in_array('stripe', array_column($gateways ?? [], 'id'), true);

    $kbbApplePay = $kbbStripeOnOffer && $kbbWallets->applePay();
    $kbbGooglePay = $kbbStripeOnOffer && $kbbWallets->googlePay();

    /*
     * The figure the sheet opens with, from the server, in integer fils.
     *
     * The same arithmetic place() uses to write `orders.total`: this basket's
     * total for the country the page was rendered for, plus the gift fee from
     * settings, plus the gateway's own surcharge. It is refreshed from
     * /checkout/wallet/amount whenever anything that can move it changes, and
     * it is checked against the order's real total before a penny is taken.
     *
     * A zero or negative total means there is nothing to pay for, and Stripe
     * refuses an Element with such an amount — so the row is not rendered at
     * all rather than rendered and then failing to load.
     */
    $kbbSettings ??= app(\App\Services\SettingsService::class);
    $kbbBasketTotal = (int) ($totals['total'] ?? 0);

    // CheckoutController::giftFee(), read the same way it reads it: the form
    // posts WHETHER they want wrapping and settings hold what it costs. A price
    // from the request would be a price the browser chose.
    $kbbGiftFee = ($kbbSettings->get('gift_enabled', '1') && session('kbb_gift'))
        ? (int) $kbbSettings->get('gift_fee', '1500')
        : 0;

    $kbbWalletAmount = $kbbBasketTotal
        + $kbbGiftFee
        + ($kbbStripeOnOffer ? $kbbStripe->feeFils($kbbBasketTotal) : 0);
@endphp
@if (($kbbApplePay || $kbbGooglePay) && $kbbWalletAmount > 0)
{{-- HIDDEN UNTIL STRIPE SAYS A WALLET IS THERE. Both nodes carry the hook the
     script removes them by, so a browser with no wallet is left with the
     payment list exactly as it was before this round. --}}
<div class="express" style="display:block" data-kbb-express hidden>
    <div data-kbb-express-mount></div>
    <p class="pay-note" data-kbb-express-error role="status" aria-live="polite" hidden></p>
</div>
<div class="ordiv" data-kbb-express-divider hidden>{{ __('store.checkout.or_pay_with') }}</div>
<script>
(function () {
  'use strict';

  var FORM = document.getElementById('kbbCheckoutForm');
  var ROW = document.querySelector('[data-kbb-express]');
  var DIVIDER = document.querySelector('[data-kbb-express-divider]');

  if (!FORM || !ROW) return;

  var PK          = @json($kbbStripe->publishableKey());
  var APPLE       = @json($kbbApplePay);
  var GOOGLE      = @json($kbbGooglePay);
  var AMOUNT_URL  = @json(\App\Support\Url::to('/checkout/wallet/amount'));
  var PLACE_URL   = @json(\App\Support\Url::to('/checkout/place'));
  var PAID_URL    = @json(\App\Support\Url::to('/checkout/card/paid'));
  var ABANDON_URL = @json(\App\Support\Url::to('/checkout/card/abandon'));

  var TEXT = {
    details: @json(__('store.checkout.wallet_details_first')),
    moved:   @json(__('store.checkout.wallet_total_moved')),
    failed:  @json(__('store.checkout.wallet_failed'))
  };

  /* The server's figure, in integer fils. Never parsed out of anything the
     page displays — a formatted "AED 220.00" turned back into a number is a
     float on a money path, and this project keeps money integral end to end. */
  var amount = @json($kbbWalletAmount);

  var stripe = null;
  var elements = null;
  var busy = false;

  /* ------------------------------------------------------------- the row */

  function gone() {
    /* REMOVED, not hidden. A hidden row leaves a gap in the grid and a
       divider announcing a thing that is not there; and `hidden` can be
       overridden by a stylesheet, while a node that is not in the document
       cannot be shown by anything. */
    if (ROW && ROW.parentNode) ROW.parentNode.removeChild(ROW);
    if (DIVIDER && DIVIDER.parentNode) DIVIDER.parentNode.removeChild(DIVIDER);
  }

  function show() {
    ROW.hidden = false;
    if (DIVIDER) DIVIDER.hidden = false;
  }

  function say(message) {
    var box = ROW.querySelector('[data-kbb-express-error]');
    if (!box) return;
    box.textContent = message || TEXT.failed;
    box.hidden = false;
  }

  function quiet() {
    var box = ROW.querySelector('[data-kbb-express-error]');
    if (!box) return;
    box.textContent = '';
    box.hidden = true;
  }

  /* ---------------------------------------------------------- the plumbing */

  async function post(url, body) {
    var response = await fetch(url, {
      /* Same-origin said out loud, for the reason stripe-elements gives: every
         one of these endpoints identifies the shopper by cookie alone, so a
         request without them does not fail as unauthenticated — it reads as an
         empty basket, which is the confusing failure rather than the obvious
         one. */
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (window.KBB && window.KBB.csrf) || '',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json'
      },
      body: JSON.stringify(body || {})
    });

    var parsed = null;
    try { parsed = await response.json(); } catch (e) { parsed = null; }

    return { ok: response.ok, status: response.status, body: parsed };
  }

  function field(name) {
    var el = FORM.querySelector('[name="' + name + '"]');
    return el ? el.value : '';
  }

  /*
   * The whole form, as place() expects it, with two deliberate overrides.
   *
   *   payment_method — forced to `stripe` whatever radio happens to be
   *     selected. The wallet IS the card gateway; a shopper who left Cash on
   *     delivery selected and then tapped Apple Pay is asking to pay by card,
   *     and place() would otherwise place a COD order and take no money.
   *
   *   save_card — cleared. "Save this card for future purchases" is a tick
   *     beside the typed-card fields and belongs to them. What a wallet hands
   *     over is a device token for one payment, and keeping it on file is a
   *     larger promise than anything on this page made.
   */
  function payload() {
    var data = new FormData(FORM);
    var out = {};
    data.forEach(function (value, key) { out[key] = value; });
    out.payment_method = 'stripe';
    out.save_card = '';
    return out;
  }

  /* ------------------------------------------------------------ the amount */

  /*
   * Ask the server what this basket costs, and tell the Element.
   *
   * Debounced, and only ever called for a shopper who actually has a wallet —
   * the Element is not created until `ready` says so, and this is not wired up
   * until then either. A quantity tap, a coupon, a country change and the gift
   * tick can each move the total, and they do it through four different
   * endpoints; asking the one server-side figure after any of them is one
   * mechanism instead of four.
   *
   * A failure is silent ON PURPOSE. The sheet keeps the figure it had, and the
   * comparison in confirm() below is what stops a wrong one being charged —
   * so a dropped request costs a retry at worst and never a wrong amount.
   */
  var pending = null;

  function refreshAmount() {
    if (pending) { clearTimeout(pending); }

    pending = setTimeout(async function () {
      pending = null;

      try {
        var answer = await post(AMOUNT_URL, {
          country: field('billing_country'),
          state: field('billing_state')
        });

        if (!answer.ok || !answer.body || answer.body.ok !== true) return;

        var next = parseInt(answer.body.amount, 10);

        if (!isFinite(next) || next <= 0 || next === amount) return;

        amount = next;
        if (elements) elements.update({ amount: amount });
      } catch (e) { /* keep the figure we have; confirm() checks it */ }
    }, 350);
  }

  /* --------------------------------------------------------------- the flow */

  function formReady() {
    var invalid = FORM.querySelector(':invalid');

    if (!invalid) return true;

    /* The same handling the card path uses: the express row is at the TOP of
       the payment step, so whatever needs attention is far above it and
       reportValidity() alone neither scrolls to it nor focuses it at a phone
       viewport. */
    invalid.scrollIntoView({ block: 'center', behavior: 'instant' });
    invalid.focus({ preventScroll: true });
    FORM.reportValidity();
    say(TEXT.details);

    return false;
  }

  async function release(order) {
    /* Give the basket back: cancel the intent at Stripe, return the stock and
       the coupon, put the cart to `active`. Best effort and never thrown —
       the shopper is already being told what happened, and an order left
       `pending` is recovered by the same sweep that recovers an abandoned card
       attempt. */
    try { await post(ABANDON_URL, { order: order }); } catch (e) {}
  }

  function boot() {
    stripe = window.Stripe(PK);

    /*
     * A SECOND elements() GROUP, deliberately, and it does not disturb the
     * first. partials/checkout/stripe-elements creates its three card fields
     * from a plain stripe.elements() with no mode; the Express Checkout
     * Element needs a DEFERRED group that knows the amount and currency up
     * front. Two groups on one page is Stripe's own arrangement for exactly
     * this, and it means not one byte of the card form changed this round.
     *
     * `paymentMethodTypes: ['card']` MATCHES THE INTENT. StripeGateway opens
     * every intent with `payment_method_types: ['card']`, and a group that
     * assumed automatic payment methods would disagree with it at confirm
     * time. If one of the two ever changes, both change.
     */
    elements = stripe.elements({
      mode: 'payment',
      amount: amount,
      currency: 'aed',
      paymentMethodTypes: ['card']
    });

    var ece = elements.create('expressCheckout', {
      /*
       * 'never' is not the same as leaving one out. It tells Stripe not to
       * offer that wallet even where the browser has it, which is what makes
       * the merchant's switch in Store → Payments actually govern the button
       * rather than merely govern the logo beside it.
       *
       * Link, PayPal and Amazon Pay are all 'never'. None of them is a card
       * wallet, none would be accepted by an intent restricted to `card`, and
       * none is a payment this shop has agreed to take.
       */
      paymentMethods: {
        applePay: APPLE ? 'auto' : 'never',
        googlePay: GOOGLE ? 'auto' : 'never',
        link: 'never',
        paypal: 'never',
        amazonPay: 'never'
      },
      buttonType: { applePay: 'buy', googlePay: 'buy' },
      /* One row, both buttons, and no "more" chevron. `overflow: 'never'`
         matters here: the row sits directly above the payment options, and a
         collapsed overflow menu there reads as a third payment method. */
      layout: { maxColumns: 2, maxRows: 1, overflow: 'never' }
    });

    ece.on('ready', function (event) {
      var available = event && event.availablePaymentMethods;

      /* THE GATE. No wallet on this browser, no row on this page. */
      if (!available || (!available.applePay && !available.googlePay)) {
        gone();
        return;
      }

      show();
      wireRefresh();
    });

    /* Stripe could not draw it — a blocked domain, a wallet that disappeared,
       a network that went away. Same answer as no wallet at all. */
    ece.on('loaderror', function () { gone(); });

    /*
     * PRESSED, BEFORE THE SHEET OPENS.
     *
     * event.resolve() is what opens it, so NOT calling it is how the sheet is
     * refused. A form with a missing email or address cannot become an order,
     * so opening a payment sheet over it would ask the shopper to authorise a
     * payment that place() is about to turn down — the sheet must not open at
     * all, and the field that needs filling in is scrolled to and focused
     * instead.
     */
    ece.on('click', function (event) {
      quiet();

      if (!formReady()) return;

      event.resolve();
    });

    /*
     * AUTHORISED IN THE SHEET. Nothing has been charged yet.
     *
     * Order of operations, and each step is where it is for a reason:
     *
     *   1. one at a time — `busy` is a courtesy, and the real guards are on the
     *      server. Placing an order marks the cart `converted` and CartService
     *      only ever resolves an `active` one, so a second POST arrives with no
     *      basket and cannot mint a second order; and StripeGateway::start()
     *      reuses an intent the order already has, with Stripe's own
     *      Idempotency-Key underneath it.
     *   2. elements.submit() — Stripe's own validation of the group.
     *   3. place() — the order and the intent, written from the form as it
     *      reads now, with the amount computed server-side.
     *   4. THE COMPARISON. If the order's real total is not the figure the
     *      sheet showed, nothing is confirmed: the order is released, the
     *      basket comes back and the shopper is told the total moved. This is
     *      the last line of defence behind refreshAmount(), and it is the one
     *      that makes it impossible to charge a figure nobody agreed to.
     *   5. confirmPayment with `redirect: 'if_required'` — 3-D Secure on a
     *      wallet is rare but real, and Stripe runs it over this page.
     *   6. tell the shop, then go. If that report never arrives the webhook
     *      marks the order paid anyway, so a failure there must not keep the
     *      shopper off their order-received page for a payment that worked.
     */
    ece.on('confirm', async function (event) {
      if (busy) { event.paymentFailed({ reason: 'fail' }); return; }

      busy = true;
      quiet();

      var order = null;

      try {
        var submitted = await elements.submit();

        if (submitted && submitted.error) {
          event.paymentFailed({ reason: 'fail' });
          say(submitted.error.message || TEXT.failed);
          busy = false;
          return;
        }

        var placed = await post(PLACE_URL, payload());

        if (!placed.ok || !placed.body || placed.body.ok !== true
            || placed.body.action !== 'confirm' || !placed.body.client_secret) {
          event.paymentFailed({ reason: 'fail' });
          say((placed.body && placed.body.error) || TEXT.failed);
          busy = false;
          return;
        }

        order = placed.body.order;

        /* 4 — the amounts must agree, to the fil. */
        if (parseInt(placed.body.amount, 10) !== amount) {
          event.paymentFailed({ reason: 'fail' });
          await release(order);
          say(TEXT.moved);
          refreshAmount();
          busy = false;
          return;
        }

        var result = await stripe.confirmPayment({
          elements: elements,
          clientSecret: placed.body.client_secret,
          confirmParams: { return_url: placed.body.return_url },
          redirect: 'if_required'
        });

        if (result.error) {
          /*
           * A DECLINED WALLET IS RELEASED RATHER THAN RETRIED, and that is the
           * one place this differs from the card path on purpose.
           *
           * A declined card leaves the intent in `requires_payment_method`,
           * alive and retryable, and the card form deliberately keeps the
           * order so a second card reuses it. A wallet has no second card on
           * this page: what the sheet handed over was a single-use token, and
           * the next attempt is a fresh authorisation in a fresh sheet. Keeping
           * the order would hold this basket's stock and its coupon against an
           * intent nothing can now confirm, and would leave a second script on
           * this page (the card form's) unaware that an order is already open.
           *
           * So the basket comes back, the shopper reads Stripe's own sentence
           * about why, and the card fields below are still there if they would
           * rather type one.
           */
          event.paymentFailed({ reason: 'fail' });
          await release(order);
          say(result.error.message || TEXT.failed);
          refreshAmount();
          busy = false;
          return;
        }

        var intent = result.paymentIntent;

        if (!intent || (intent.status !== 'succeeded' && intent.status !== 'processing')) {
          event.paymentFailed({ reason: 'fail' });
          await release(order);
          say(TEXT.failed);
          busy = false;
          return;
        }

        try { await post(PAID_URL, { order: order }); } catch (e) { /* the webhook has it */ }

        window.location.assign(placed.body.success_url);
      } catch (e) {
        /*
         * CONTAINED. Whatever went wrong, the shopper must end up with their
         * basket and a sentence, never with a half-placed order and a spinner.
         * If an order was created before the failure it is released here, which
         * is the same call the shopper's own "return to your basket" makes.
         */
        event.paymentFailed({ reason: 'fail' });
        if (order) { await release(order); }
        say(TEXT.failed);
        busy = false;
      }
    });

    ece.mount(ROW.querySelector('[data-kbb-express-mount]'));
  }

  /*
   * Anything that can move the total, and nothing that cannot.
   *
   * `change` rather than `input`, so correcting one character of an address
   * does not ask the server five times. #payment is watched as well because
   * the quantity steppers, the coupon box and the one-tap Browsed add all
   * re-render it from the server — that mutation is the signal that a total
   * the shopper did not type has moved.
   */
  function wireRefresh() {
    FORM.addEventListener('change', function () { refreshAmount(); });

    var payment = document.getElementById('payment');

    if (payment && window.MutationObserver) {
      new MutationObserver(function () { refreshAmount(); })
        .observe(payment, { childList: true, subtree: true });
    }

    /* One read at the start, so a page restored from the back/forward cache
       with a basket that moved in another tab does not open a sheet on a
       figure from before. */
    refreshAmount();
  }

  /*
   * partials/checkout/stripe-elements loads js.stripe.com, and it sits LOWER in
   * this document — so Stripe is not defined while this script is parsed. By
   * DOMContentLoaded every parser-inserted script has run, which is the
   * ordinary case and costs nothing.
   *
   * The poll behind it is for the case where that partial has moved, or where
   * the script is slow: twenty attempts at 250ms is five seconds, after which
   * the row is removed rather than left as a button that cannot work. A dead
   * button is the one outcome this file exists to prevent, and "Stripe never
   * loaded" is simply another way to arrive at it.
   */
  function waitForStripe(attempts) {
    if (typeof window.Stripe === 'function') {
      try { boot(); } catch (e) { gone(); }
      return;
    }

    if (attempts <= 0) { gone(); return; }

    setTimeout(function () { waitForStripe(attempts - 1); }, 250);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { waitForStripe(20); });
  } else {
    waitForStripe(20);
  }
})();
</script>
@endif
