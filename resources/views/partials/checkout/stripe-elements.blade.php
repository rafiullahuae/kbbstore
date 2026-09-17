{{--
    Stripe Elements on our own checkout.

    ── WHY THIS IS A BLADE PARTIAL AND NOT resources/js/kbb/ ──────────────────

    The storefront serves BUILT assets: @vite resolves through a manifest to
    hashed files, and this host has no Node, so anything added under
    resources/js/ ships INERT until somebody rebuilds the bundle off-server and
    uploads it. Every other script on this page can survive that gap — a
    quantity stepper that is not wired up yet is an inconvenience. This one
    cannot: without it there are no card fields, the Place order button posts an
    ordinary form, and place() fails the order and tells the shopper to switch
    JavaScript on. A checkout that cannot take a payment is an outage, so the
    code that mounts the fields travels in the view that draws them, where a
    package is enough on its own.

    ── WHAT IS AND IS NOT ON THIS PAGE ────────────────────────────────────────

    js.stripe.com/v3 is the only script this adds, and it is loaded from
    Stripe's own domain because that is the requirement rather than the
    convenience: self-hosting it would put the code that touches the card
    number inside our origin, which is the whole thing Elements exists to
    avoid.

    #kbb-card-element gets a cross-origin IFRAME. The card number, expiry and
    CVC live in Stripe's document, not ours. Nothing in this file reads them,
    nothing can, and no value from them is ever posted to this server — only
    the client secret of one PaymentIntent goes out, and only back to Stripe.
--}}
@php
    $stripeGateway = app(\App\Services\Payments\GatewayRegistry::class)->find('stripe');
    $stripeKey = $stripeGateway instanceof \App\Services\Payments\Gateways\StripeGateway
        && in_array('stripe', array_column($gateways ?? [], 'id'), true)
            ? $stripeGateway->publishableKey()
            : '';
@endphp
@if ($stripeKey !== '')
<script src="https://js.stripe.com/v3"></script>
<script>
(function () {
  'use strict';

  var FORM = document.getElementById('kbbCheckoutForm');
  if (!FORM || typeof Stripe !== 'function') return;

  var PLACE_URL   = @json(\App\Support\Url::to('/checkout/place'));
  var PAID_URL    = @json(\App\Support\Url::to('/checkout/card/paid'));
  var ABANDON_URL = @json(\App\Support\Url::to('/checkout/card/abandon'));
  var CART_URL    = @json(\App\Support\Url::to('/cart/'));

  var TEXT = {
    notReady: @json(__('store.checkout.card_not_ready')),
    generic:  @json(__('store.checkout.card_generic_error')),
    working:  @json(__('store.checkout.card_working')),
    place:    @json(__('store.checkout.place_order'))
  };

  var stripe = Stripe(@json($stripeKey));
  var elements = stripe.elements();
  var card = null;
  var mountedIn = null;

  /* The order this browser has open at Stripe, once place() has created one.
     Kept so a second failure reuses it rather than placing another order, and
     so "return to your basket" knows what it is cancelling. */
  var openOrder = null;
  var busy = false;

  /* ------------------------------------------------------------------ mount */

  /*
   * The element is created ONCE and re-mounted, never re-created.
   *
   * #payment is replaced wholesale by fragments() whenever the bag or the
   * coupon changes (checkout.js: `payment.innerHTML = data.paymentHtml`), which
   * takes the mount box and the iframe inside it with it. A fresh
   * elements.create('card') each time would leak an Element per quantity tap
   * and lose whatever the shopper had already typed; re-mounting the same one
   * into the new box keeps its state, which is what Stripe's mount() is for.
   */
  function sync() {
    var box = document.querySelector('[data-kbb-card-el]');

    if (!box) { mountedIn = null; return; }
    if (box === mountedIn) return;

    if (!card) {
      card = elements.create('card', {
        hidePostalCode: true,
        style: {
          base: {
            fontSize: '14px',
            fontFamily: 'inherit',
            color: '#1F2A24',
            '::placeholder': { color: '#9AA8A0' }
          },
          invalid: { color: '#C8325C', iconColor: '#C8325C' }
        }
      });

      /* Stripe's own validation, as the shopper types: a card number one digit
         short says so before Place order is ever pressed. The same box is used
         for the decline afterwards, so there is one place on this page that
         says what is wrong with the card. */
      card.on('change', function (event) {
        box.classList.toggle('is-invalid', !!event.error);
        if (event.error) { showError(event.error.message); } else { clearError(); }
      });
      card.on('focus', function () { box.classList.add('is-focused'); });
      card.on('blur', function () { box.classList.remove('is-focused'); });
    }

    card.mount(box);
    mountedIn = box;
  }

  sync();

  /* Re-mount after a fragment swap. The observer is on #payment's parent
     because #payment itself is the node whose children are replaced. */
  var payment = document.getElementById('payment');
  if (payment && window.MutationObserver) {
    new MutationObserver(function () { sync(); }).observe(payment, { childList: true, subtree: true });
  }

  /* ----------------------------------------------------------------- saying */

  function errorBox()  { return document.querySelector('[data-kbb-card-error]'); }
  function bailButton(){ return document.querySelector('[data-kbb-card-bail]'); }

  function showError(message) {
    var el = errorBox();
    if (!el) return;
    el.textContent = message || TEXT.generic;
    el.classList.add('on');
  }

  function clearError() {
    var el = errorBox();
    if (!el) return;
    el.textContent = '';
    el.classList.remove('on');
  }

  /* The way back to the basket appears only once there is a payment to
     abandon — before an order exists there is nothing to cancel, and a control
     that says "cancel this payment" when none has started is a control that
     will be pressed by mistake. */
  function offerBail() {
    var b = bailButton();
    if (b && openOrder) b.classList.add('on');
  }

  function buttons() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-place]'));
  }

  function lock(on) {
    busy = on;
    buttons().forEach(function (b) {
      b.disabled = on;
      if (on) {
        if (!b.dataset.kbbLabel) b.dataset.kbbLabel = b.textContent;
        b.textContent = TEXT.working;
      } else if (b.dataset.kbbLabel) {
        b.textContent = b.dataset.kbbLabel;
      }
    });
  }

  function chosen() {
    var r = FORM.querySelector('input[name="payment_method"]:checked');
    return r ? r.value : '';
  }

  /* --------------------------------------------------------------- the flow */

  /*
   * ONE ATTEMPT AT A TIME, and `busy` is the whole of it on this side.
   *
   * It is not the guard that matters, though, and it must not be mistaken for
   * one: a disabled button is a courtesy, and anything that can be pressed
   * twice can be posted twice by something that is not this page. The real
   * guards are on the server and there are two, each covering what the other
   * cannot. Placing an order marks the cart `converted`, and CartService only
   * ever resolves an `active` one, so a second POST arrives with no cart and
   * cannot mint a second order. And StripeGateway::start() reuses an intent
   * the order already has rather than opening a second one, with Stripe's own
   * Idempotency-Key underneath it for the request that reached Stripe and
   * whose answer never came back.
   */
  async function pay() {
    if (busy) return;

    /* The browser's own validation first, and the field taken in hand the same
       way checkout.js does it — the Place order button is rendered after the
       fields, so the control that needs attention is always far above the one
       being pressed, and reportValidity() alone neither scrolls to it nor
       focuses it at a phone viewport. */
    var invalid = FORM.querySelector(':invalid');
    if (invalid) {
      invalid.scrollIntoView({ block: 'center', behavior: 'instant' });
      invalid.focus({ preventScroll: true });
      FORM.reportValidity();
      return;
    }
    if (!FORM.reportValidity()) return;

    if (!card) { showError(TEXT.notReady); return; }

    clearError();
    lock(true);

    try {
      var handle = openOrder;

      /* An order and an intent, unless this is a retry after a decline — in
         which case the order from the first attempt is still `pending` and its
         intent is still confirmable, which is exactly Stripe's model for a
         second card. Placing another order per declined card would burn a
         number, claim the stock twice and spend the coupon twice. */
      if (!handle) {
        var placed = await post(PLACE_URL, formPayload());

        if (!placed.ok || !placed.body || placed.body.ok !== true) {
          showError((placed.body && placed.body.error) || TEXT.generic);
          lock(false);
          return;
        }

        /* place() answers for every gateway, so honour what it actually said
           rather than assuming. A shop that switches Stripe off mid-session
           still has a working checkout. */
        if (placed.body.action === 'redirect' && placed.body.url) { window.location.assign(placed.body.url); return; }
        if (placed.body.action === 'placed' && placed.body.success_url) { window.location.assign(placed.body.success_url); return; }
        if (placed.body.action !== 'confirm' || !placed.body.client_secret) { showError(TEXT.generic); lock(false); return; }

        handle = openOrder = placed.body;
      }

      /*
       * The card, to Stripe, from Stripe's own iframe. This call is where 3-D
       * SECURE HAPPENS: if the issuer asks for authentication — and most UAE
       * cards do — Stripe opens the bank's challenge in a modal over this page
       * and resolves when it is answered. That is the bank's step, it is
       * mandatory, and it is not a redirect to Stripe. `return_url` is carried
       * for the minority of issuers whose challenge is a full navigation
       * instead; those come back to the order-received page, and the webhook is
       * what marks that order paid.
       */
      var result = await stripe.confirmCardPayment(handle.client_secret, {
        payment_method: {
          card: card,
          billing_details: billingDetails()
        },
        return_url: handle.return_url
      });

      if (result.error) {
        /*
         * STRIPE'S OWN SENTENCE, not one of ours. "Your card was declined",
         * "Your card has expired", "We are unable to authenticate your payment
         * method" — Stripe writes these for shoppers, keeps them current and
         * localises them, and a decline reason paraphrased by us is a decline
         * reason that goes stale. The basket and every field the shopper typed
         * are untouched; the order is still `pending` and has NOT been placed,
         * and the same intent takes another card.
         */
        showError(result.error.message || TEXT.generic);
        offerBail();
        lock(false);
        return;
      }

      var intent = result.paymentIntent;

      if (!intent || (intent.status !== 'succeeded' && intent.status !== 'processing')) {
        showError(TEXT.generic);
        offerBail();
        lock(false);
        return;
      }

      /*
       * Tell the shop, then go. The server verifies this against Stripe before
       * it believes a word of it, and if this request never arrives the webhook
       * marks the order paid anyway — so a failure here is not allowed to stop
       * the shopper reaching their order-received page for a payment that has
       * genuinely gone through.
       */
      try { await post(PAID_URL, { order: handle.order }); } catch (e) { /* the webhook has it */ }

      window.location.assign(handle.success_url);
    } catch (e) {
      showError(TEXT.generic);
      offerBail();
      lock(false);
    }
  }

  /* --------------------------------------------------------- giving up well */

  async function bail() {
    if (busy || !openOrder) return;

    var b = bailButton();
    if (b) b.disabled = true;
    busy = true;

    try {
      var answer = await post(ABANDON_URL, { order: openOrder.order });

      if (answer.ok && answer.body && answer.body.ok === true) {
        /* Back to the checkout with the basket restored, rather than to the
           cart page: they were half way through paying and everything they
           typed is still in these fields. */
        window.location.assign((answer.body && answer.body.url) || CART_URL);
        return;
      }

      showError((answer.body && answer.body.error) || TEXT.generic);
    } catch (e) {
      showError(TEXT.generic);
    }

    if (b) b.disabled = false;
    busy = false;
  }

  /* -------------------------------------------------------------- plumbing */

  function formPayload() {
    var data = new FormData(FORM);
    var out = {};
    data.forEach(function (value, key) { out[key] = value; });
    return out;
  }

  function billingDetails() {
    function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
    var name = (val('billing_first_name') + ' ' + val('billing_last_name')).trim();
    var details = { email: val('billing_email') };
    if (name) details.name = name;
    return details;
  }

  async function post(url, body) {
    var response = await fetch(url, {
      method: 'POST',
      /*
       * SAID OUT LOUD, although same-origin is fetch()'s default.
       *
       * Every one of these endpoints is identified by the shopper's cookies
       * and nothing else — the cart by CartService::COOKIE, and the two card
       * reports by `kbb_last_order` in the session. A request that arrives
       * without them is not merely unauthenticated, it reads as an empty
       * basket, which is the confusing failure rather than the obvious one.
       */
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (window.KBB && window.KBB.csrf) || '',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json'
      },
      body: JSON.stringify(body)
    });

    var parsed = null;
    try { parsed = await response.json(); } catch (e) { parsed = null; }

    return { ok: response.ok, status: response.status, body: parsed };
  }

  /*
   * CAPTURE PHASE, on document, and that is load bearing.
   *
   * checkout.js handles Place order with a delegated listener on document in
   * the BUBBLE phase, and it finishes with form.submit() — which does not fire
   * a submit event, so a submit listener here would never see it. A capture
   * listener on document runs before any bubble listener on the same node, so
   * this sees the click first and stopPropagation() keeps the ordinary
   * form-posting path from also running.
   *
   * Only for the card. Every other gateway falls straight through to the
   * handler that has always dealt with it.
   */
  document.addEventListener('click', function (event) {
    if (!event.target.closest) return;

    if (event.target.closest('[data-kbb-card-bail]')) {
      event.preventDefault();
      event.stopPropagation();
      bail();
      return;
    }

    if (!event.target.closest('[data-place]')) return;
    if (chosen() !== 'stripe') return;

    event.preventDefault();
    event.stopPropagation();
    pay();
  }, true);

  /* Enter inside a field submits the form without going near a button. Same
     rule, same phase, same reason. */
  FORM.addEventListener('submit', function (event) {
    if (chosen() !== 'stripe') return;
    event.preventDefault();
    event.stopPropagation();
    pay();
  }, true);

  /*
   * Switching AWAY from the card after a failed attempt.
   *
   * There is a real order sitting `pending` at that point with a live intent
   * against it. Leaving it there and placing a second order by another method
   * would hold this basket's stock twice and spend the coupon twice, and the
   * first order's intent would still be confirmable by a stale tab. So the
   * order is released exactly as the "return to your basket" control releases
   * it — the difference between the two is only where the shopper ends up.
   */
  document.addEventListener('change', function (event) {
    var radio = event.target.closest && event.target.closest('input[name="payment_method"]');
    if (!radio || radio.value === 'stripe' || !openOrder || busy) return;

    var order = openOrder.order;
    openOrder = null;
    clearError();
    var b = bailButton();
    if (b) b.classList.remove('on');

    post(ABANDON_URL, { order: order }).catch(function () {});
  });
})();
</script>
@endif
