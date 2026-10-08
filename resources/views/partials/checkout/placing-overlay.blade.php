{{--
    THE PLACE-ORDER OVERLAY — press, freeze, "Placing your order…", tick. (Lane PLC)

    ═══════════════════════════════════════════════════════════════════════════
    WHAT THE OWNER ASKED FOR
    ═══════════════════════════════════════════════════════════════════════════

    "when press the PLACE ORDER button, it should freeze the page and come nice
     real time loading bar or filled circle with text, Placing your order... and
     once place, it should come with nice tick animated and colorful icon …, but
     the rest of the background will not be there, it should be little blur
     freezed screen on the background, and once tick appeared, it will show the
     order detail page which we have it already."

    And, once he had been shown how Tabby and Tamara actually work:

    "the place order loading screen will only there if the payment method is COD
     or stripe, apple pay or google pay. and for tabby and tamara it will show if
     possible nicely along with the redirection to the tabby or tamara site …
     and will come back with a succesfull tick star"

    ═══════════════════════════════════════════════════════════════════════════
    THERE IS NO LIST OF GATEWAY NAMES IN THIS FILE, AND THERE MUST NOT BE
    ═══════════════════════════════════════════════════════════════════════════

    Those two sentences describe three journeys, and the server already names
    them. CheckoutController::place() answers a fetch() with an `action` taken
    straight from PaymentStart::$result:

        placed    the order exists and nothing else is needed — cash on delivery
        confirm   the browser finishes the payment here — the card fields, Apple
                  Pay and Google Pay. partials/checkout/stripe-elements owns
                  that leg and drives this overlay through window.KBB.placing
        redirect  the shopper leaves the shop for the provider's own site —
                  Tabby, Tamara

    So this branches on `action`. A `['tabby','tamara']` written into a template
    is a list that is wrong the first time a gateway is added and silent about
    being wrong, and CLAUDE.md records six features on this shop that were wired
    in one place and not another. PaymentGateway::journey() is the same answer
    asked of the gateway at render time, for the pages that need the shape of
    the journey without taking a step along it.

    ═══════════════════════════════════════════════════════════════════════════
    WHY THE SCRIPT AND THE STYLES ARE IN THE VIEW
    ═══════════════════════════════════════════════════════════════════════════

    The reason partials/checkout/express-wallets and stripe-elements both give:
    this host serves BUILT assets and has no Node, so anything added under
    resources/js or resources/css ships INERT until somebody rebuilds the bundle
    off-server. A checkout that freezes and never draws the overlay it froze for
    is the worst version of that, so the code travels in the view that draws it
    and a package is enough on its own.

    ═══════════════════════════════════════════════════════════════════════════
    THE SUBMISSION PATH, AND WHY IT MOVED
    ═══════════════════════════════════════════════════════════════════════════

    resources/js/kbb/checkout.js has always answered [data-place] with
    form.submit() — a native POST and a full navigation. That path CANNOT show a
    tick: the only signal that the server accepted the order is the navigation
    itself, and by the time it happens this document is gone.

    This listener takes the same press in the CAPTURE phase on `document`,
    exactly as stripe-elements already does for the card, and stops it before
    checkout.js's bubble listener sees it. It then posts the SAME form, to the
    SAME action, with the SAME fields — `new FormData(form)` is byte for byte
    what the browser itself would have sent, down to an unticked checkbox being
    absent rather than false — and reads the JSON door place() has answered on
    since the card fields landed.

    REGISTRATION ORDER IS LOAD-BEARING. stripe-elements registers its capture
    listener first (checkout.blade.php includes it first) and calls
    stopPropagation() only when the chosen method is the card. So the card is
    handled there, everything else here, and neither has to know what the other
    is for.

    ═══════════════════════════════════════════════════════════════════════════
    THE FAILURE PATHS ARE THE JOB
    ═══════════════════════════════════════════════════════════════════════════

    An overlay that stays up after a failure is worse than no overlay at all:
    the shopper can neither retry nor see why. Every one of these takes it down,
    re-enables the button and says what happened, in the shopper's language:

      · a refusal from place()          422 {ok:false, error}      → that sentence
      · a validation error              422 {message, errors}      → the first one
      · an expired page                 419                        → refresh and retry
      · any other status                                           → generic
      · the network dropped             fetch rejects              → check connection
      · no answer at all                45s, aborted               → do NOT retry blind
      · a redirect that did not happen  10s after location.assign  → a real link out
      · the Back button                 pageshow[persisted]        → overlay removed

    The 45-second case is the one worth reading twice. The request was sent, so
    the order may well exist; a message inviting a retry would invite the one
    mistake that cannot be undone. It says to check the email first instead.

    ═══════════════════════════════════════════════════════════════════════════
    NO JAVASCRIPT MEASURES LAYOUT HERE, AND NO JAVASCRIPT BUILDS MARKUP
    ═══════════════════════════════════════════════════════════════════════════

    Not one getBoundingClientRect, offsetWidth, offsetHeight, clientWidth,
    clientHeight, getComputedStyle or scrollHeight — CheckoutPlacingOverlayTest
    asserts that by name. A full-screen overlay and a progress ring are the two
    most tempting places on this shop to reach for one and neither needs it:
    position:fixed + inset:0 is the overlay, and the ring is an SVG circle with
    pathLength="100", so its dash geometry is in percent and the browser does
    the arithmetic at paint time.

    There is also no innerHTML. The card is SERVER-RENDERED into the <template>
    below by partials/checkout/placing-card and cloned on each press — which
    also, and not incidentally, restarts every animation on it. Both animations
    that carry information run `forwards`, so a REUSED element comes back
    holding its finished state: a second attempt after a decline would show a
    ring already at 88% and, far worse, a fully drawn tick for an order that has
    not been placed. The usual cure is to force a reflow between removing and
    re-adding a class, and a forced reflow is a layout READ. A fresh clone
    restarts everything by construction.

    The only words this script writes are __() strings through @json, and the
    only value it reads off the page is the chosen gateway's title, taken as
    textContent and written back as textContent — so a merchant who renames a
    gateway to a <script> tag renames it to the literal characters of one.
--}}<!--kbb-placing-->@include('partials.checkout.placing-style')
<style>
/* (Lane PO hotfix) What a press that cannot go ahead says: beside the Place
   order button pressed, and under the field. In the floating bar the line sits
   ABOVE the bar, out of its flow, so the bar does not change height. */
.kbb-place-note{margin:8px 0 0;font-size:12.5px;line-height:1.4;font-weight:600;color:#C8325C;text-align:center}
.kbb-place-note button{all:unset;cursor:pointer;text-decoration:underline;text-underline-offset:2px}
.mpbar .kbb-place-note{position:absolute;inset-inline:12px;bottom:calc(100% + 6px);margin:0;padding:8px 12px;border-radius:10px;background:#fff;box-shadow:0 6px 20px -8px rgba(42,34,40,.35)}
.kbb-ferr{display:block;margin:5px 2px 0;font-size:12px;line-height:1.35;font-weight:600;color:#C8325C}
</style>
<template id="kbbPlacingTpl">@include('partials.checkout.placing-card', [
    'label' => __('store.checkout.placing_label'),
    'title' => '',
    'note' => '',
    'classes' => '',
    'hold' => '',
    'modal' => true,
])</template>
<script>
/*
 * IIFE, `var`, no arrow functions and no async/await — the dialect
 * partials/checkout/express-wallets and stripe-elements are written in, for the
 * same reason: this file is served exactly as it is written, to whatever
 * browser walks in, with no build step in between.
 */
(function () {
  var FORM = document.getElementById('kbbCheckoutForm');
  var TPL = document.getElementById('kbbPlacingTpl');

  if (!FORM || !TPL || !TPL.content || !window.fetch) { return; }

  var TEXT = {
    title:        @json(__('store.checkout.placing_title')),
    note:         @json(__('store.checkout.placing_note')),
    leaving:      @json(__('store.checkout.placing_leaving')),
    leavingNote:  @json(__('store.checkout.placing_leaving_note')),
    done:         @json(__('store.checkout.placing_done')),
    failed:       @json(__('store.checkout.placing_failed')),
    offline:      @json(__('store.checkout.placing_offline')),
    expired:      @json(__('store.checkout.placing_expired')),
    noAnswer:     @json(__('store.checkout.placing_no_answer')),
    stuck:        @json(__('store.checkout.placing_redirect_stuck')),
    stuckLink:    @json(__('store.checkout.placing_redirect_link'))
  };

  /* (Lane PO hotfix) The words for a press that cannot go ahead. */
  var SAY = {
    check:       @json(__('store.checkout.place_check_field')),
    cardLoading: @json(__('store.checkout.card_not_ready')),
    cardMissing: @json(__('store.checkout.place_card_unavailable')),
    missing:     @json(__('store.checkout.field_missing')),
    email:       @json(__('store.checkout.field_bad_email')),
    choose:      @json(__('store.checkout.field_choose')),
    bad:         @json(__('store.checkout.field_bad_value'))
  };

  /* ?kbbdiag=1 only (partials/checkout/diag); a no-op on every other load. */
  function trace(msg) { try { if (window.kbbDiag) { window.kbbDiag('overlay', msg); } } catch (e) {} }

  /* HOW LONG THE DECORATION MAY HOLD THE SHOPPER UP, in one place so it can be
     read in one go. The tick is decoration; the order is not. */
  /* (Lane PO) 900 -> 360. The navigation now STARTS while the tick is
     drawing, and the browser keeps this document painted until the received
     page has its first paint (paint holding) -- so the tick stays on screen
     for this plus the received page's own load (median 841 ms in the
     preview, where php -S serves the page; 360 of it is this constant), and
     none of the rest is spent waiting on purpose. The owner: "check icon come
     and instantly goes to thank you page". */
  var TICK_MS = 360;      // the tick drawing before the received page is asked for
  var REPORT_MS = 4000;   // (Lane PO) the most the card's report to the shop may hold the tick
  var LEAVE_MS = 250;     // "Taking you to Tabby…" before the browser goes (Lane PO: was 700; the page stays painted until the provider answers)
  var STUCK_MS = 10000;   // the redirect plainly did not happen
  var ABORT_MS = 45000;   // no answer at all

  var box = null, titleEl = null, noteEl = null, liveEl = null;
  var busy = false, lastFocus = null, aborter = null, timers = [], timedOut = false;
  /* (Lane PO) The bank's step is under way: see hold(). */
  var bank = false;

  /* ------------------------------------------------------------ the element */

  function build() {
    if (box) { return; }

    box = TPL.content.firstElementChild.cloneNode(true);
    titleEl = box.querySelector('.kbb-placing-title');
    noteEl = box.querySelector('.kbb-placing-note');
    liveEl = box.querySelector('.kbb-placing-sr');
    document.body.appendChild(box);
  }

  /* ------------------------------------------------------- freezing the page */

  /*
   * `inert` on every other child of <body>, remembered so it can be put back.
   *
   * This is the focus trap AND the freeze in one property: an inert subtree
   * cannot be focused, cannot be clicked and is hidden from assistive
   * technology, which is exactly "the rest of the background will not be
   * there". The focusin listener below is the belt for browsers that do not
   * support it — it pulls focus back to the dialog rather than letting Tab walk
   * off into a checkout the shopper must not now change.
   */
  var frozen = [];

  function freeze(on) {
    if (on) {
      frozen = [];
      Array.prototype.forEach.call(document.body.children, function (el) {
        if (el === box) { return; }
        frozen.push([el, el.hasAttribute('inert'), el.getAttribute('aria-hidden')]);
        el.setAttribute('inert', '');
        el.setAttribute('aria-hidden', 'true');
      });
      return;
    }

    frozen.forEach(function (row) {
      if (!row[1]) { row[0].removeAttribute('inert'); }
      if (row[2] === null) { row[0].removeAttribute('aria-hidden'); } else { row[0].setAttribute('aria-hidden', row[2]); }
    });
    frozen = [];
  }

  document.addEventListener('focusin', function (event) {
    /* (Lane PO) Not while the bank's challenge is up: it is Stripe's frame,
       appended to <body> after the freeze, and pulling focus out of it would
       leave a shopper unable to type the code their bank sent. */
    if (!busy || bank || !box || box.contains(event.target)) { return; }
    box.focus();
  });

  /* The order is in flight. Escape must not take the overlay down and leave a
     POST running behind a page that looks idle again. */
  document.addEventListener('keydown', function (event) {
    if (busy && event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); }
  }, true);

  /* --------------------------------------------------------------- the buttons */

  function buttons() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-place]'));
  }

  /*
   * DISABLED ONLY, NEVER RELABELLED, AND PUT BACK THE WAY IT WAS FOUND.
   *
   * partials/checkout/stripe-elements has its own lock() that swaps the label,
   * and two scripts writing one button's text is how a button ends up saying
   * "Working…" for ever. So this touches `disabled` and nothing else.
   *
   * AND IT REMEMBERS THE PREVIOUS VALUE rather than enabling on the way out.
   * The card leg calls lock(true) BEFORE it calls begin() here, and dismisses
   * this overlay while Stripe's 3-D Secure modal is up — so a blind
   * `disabled = false` on the way down would hand the shopper a live Place
   * order button in the middle of a payment their bank is still challenging.
   * That is the double submission this whole overlay exists to prevent,
   * reintroduced by the thing preventing it.
   */
  var wasDisabled = [];

  function disable(on) {
    if (on) {
      wasDisabled = buttons().map(function (b) {
        var before = b.disabled;
        b.disabled = true;
        return [b, before];
      });
      return;
    }

    wasDisabled.forEach(function (row) { row[0].disabled = row[1]; });
    wasDisabled = [];
  }

  /* ------------------------------------------------------------------ saying */

  function say(title, note) {
    build();
    titleEl.textContent = title;
    noteEl.textContent = note || '';
    liveEl.textContent = note ? (title + ' ' + note) : title;
  }

  /*
   * The refusal, in the page's own notice band.
   *
   * Reuses the markup and the inline style checkout.blade.php renders for a
   * server-side error, so a refusal that came back through fetch() looks
   * exactly like one that came back through a form post — and needs not one
   * line of new CSS. role="alert" and focus, because the shopper's eyes are on
   * the button they just pressed and the band is at the top of the form.
   */
  function notice(message) {
    var band = document.getElementById('kbbPlacingNotice');

    if (!band) {
      band = document.createElement('div');
      band.id = 'kbbPlacingNotice';
      band.className = 'co-notices';
      band.setAttribute('style', 'max-width:1040px;margin:0 auto;padding:16px 20px 0');
      var line = document.createElement('div');
      line.className = 'co-note err';
      line.setAttribute('role', 'alert');
      line.setAttribute('tabindex', '-1');
      band.appendChild(line);
      FORM.parentNode.insertBefore(band, FORM);
    }

    var target = band.firstElementChild;
    target.textContent = message;
    /* 'instant', for the reason checkout.js gives beside the same call: kbb.css
       sets html{scroll-behavior:smooth} globally, so an animated scroll is
       still in flight when focus() runs a line later and lands the page
       somewhere else. */
    target.scrollIntoView({ block: 'center', behavior: 'instant' });
    target.focus({ preventScroll: true });

    return target;
  }

  function clearNotice() {
    var band = document.getElementById('kbbPlacingNotice');
    if (band && band.parentNode) { band.parentNode.removeChild(band); }
  }

  /* ------------------------------------------------------------- the lifecycle */

  function later(fn, ms) {
    var id = window.setTimeout(fn, ms);
    timers.push(id);
    return id;
  }

  function clearTimers() {
    timers.forEach(function (id) { window.clearTimeout(id); });
    timers = [];
  }

  function begin(opts) {
    if (busy) { return false; }

    busy = true;
    timedOut = false;
    clearNotice();
    build();
    say((opts && opts.title) || TEXT.title, (opts && opts.note) || TEXT.note);

    lastFocus = document.activeElement;
    disable(true);
    freeze(true);
    box.classList.add('is-up');
    box.focus();

    return true;
  }

  /*
   * DOWN, AND THE ELEMENT GOES WITH IT — see the header for why a reused
   * element would show a drawn tick for an order that has not been placed.
   */
  function down() {
    clearTimers();
    busy = false;
    bank = false;
    if (aborter) { try { aborter.abort(); } catch (e) {} aborter = null; }
    freeze(false);
    disable(false);

    if (box && box.parentNode) { box.parentNode.removeChild(box); }
    box = titleEl = noteEl = liveEl = null;

    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus({ preventScroll: true }); } catch (e) {}
    }
    lastFocus = null;
  }

  function fail(message) {
    down();
    notice(message || TEXT.failed);
  }

  /*
   * The tick, and the bound on it.
   *
   * The navigation is scheduled the instant the tick starts, not when the
   * animation ends, so a slow or janky animation cannot hold a shopper on a
   * page whose work is finished. And the tick is only ever drawn from here —
   * which is only ever reached after the server has said the order stands.
   */
  function confirmed(url, report) {
    /* stripe-elements may call this having taken the lock through begin() long
       before Stripe answered; a caller that did not still gets a frozen page
       under its tick. */
    if (!busy) { begin(); }

    /* ONE TICK, ONE NAVIGATION, whoever calls twice. */
    if (box.classList.contains('is-done')) { return; }

    remember();
    say(TEXT.done, '');
    box.classList.add('is-done');

    if (!url) { return; }

    /* (Lane PO) `report`, when given, is the card leg's POST to the shop,
       running under the tick: the received page opens when the tick has had
       TICK_MS AND the report has answered -- or REPORT_MS has passed, because
       a report that never lands is the webhook's to finish, not the shopper's
       to wait for. Either way, one navigation. */
    var ticked = false, answered = !(report && typeof report.then === 'function'), gone = false;
    function go() {
      if (gone || !ticked || !answered) { return; }
      gone = true;
      window.location.assign(url);
    }
    later(function () { ticked = true; go(); }, TICK_MS);
    if (!answered) {
      later(function () { answered = true; go(); }, REPORT_MS);
      report.then(function () { answered = true; go(); }, function () { answered = true; go(); });
    }
  }

  /*
   * THE BANK'S STEP, WITH THE BOX STILL UP (Lane PO).
   *
   * The card leg used to take this overlay DOWN before Stripe's
   * confirmCardPayment() and put it back UP when Stripe answered, so that a
   * 3-D Secure challenge could never land in something `inert`. On a card with
   * no challenge that was the box closing and opening again a second later,
   * over a page whose button now read "Confirming your payment…" -- and the
   * owner's words for it were "the two times box opening gives confusion to
   * user, user may think that we are placing order twice."
   *
   * The box stays. What made the challenge unsafe is lifted instead: the page
   * is un-frozen (no `inert` anywhere, so a frame Stripe injects can be
   * answered wherever it lands) and the focus trap stands down. Stripe's
   * challenge is a frame appended to <body> at the top z-index, so it opens
   * ABOVE this card; when it closes, the card is still there, still saying
   * "Placing your order…", and turns into the tick in place. The buttons stay
   * disabled -- disable() put them that way and nothing here touches them -- and
   * the card still covers the page, so nothing under it can be pressed.
   */
  function hold() {
    if (!busy) { begin(); }

    bank = true;
    freeze(false);
  }

  /* The details the shopper typed, kept on this device if they asked
     (resources/js/kbb/checkout.js, window.KBB.remember). Only on an order the
     shop has accepted; guarded, because a payment path may not depend on it. */
  function remember() {
    try { if (window.KBB && window.KBB.remember) { window.KBB.remember.save(); } } catch (e) {}
  }

  /* --------------------------------------------------------------- leaving */

  /*
   * HTTPS AND NOTHING ELSE.
   *
   * This address arrives from a payment provider's API and becomes both a
   * navigation and, if the navigation does not happen, an href. `javascript:`
   * and `data:` are why this check exists; plain http: is refused as well,
   * because a provider answering with one is sending a shopper's payment over
   * the clear.
   */
  function safeUrl(url) {
    return (typeof url === 'string' && /^https:\/\//i.test(url)) ? url : null;
  }

  /*
   * The chosen gateway's own title, read off the label the server already
   * rendered and escaped.
   *
   * Cloned so the page is not touched, and the fee — which lives in its own
   * <span class="codfee"> inside the same label — is dropped, because "Taking
   * you to Cash on delivery +AED 15…" is not a sentence.
   */
  function providerName() {
    var chosen = FORM.querySelector('input[name="payment_method"]:checked');
    if (!chosen) { return ''; }

    var label = FORM.querySelector('label[for="payment_method_' + chosen.value + '"]');
    if (!label) { return ''; }

    var copy = label.cloneNode(true);
    Array.prototype.forEach.call(copy.querySelectorAll('.codfee'), function (el) {
      el.parentNode.removeChild(el);
    });

    return copy.textContent.replace(/\s+/g, ' ').trim();
  }

  function leaving(url) {
    var safe = safeUrl(url);
    var name = providerName();

    if (!busy) { begin(); }

    if (!safe) { fail(TEXT.failed); return; }

    remember();
    say(TEXT.leaving.replace(':provider', name), TEXT.leavingNote.replace(':provider', name));

    later(function () { window.location.assign(safe); }, LEAVE_MS);

    /*
     * THE REDIRECT THAT DID NOT HAPPEN. A blocked navigation, an extension, a
     * provider page that refuses to load: the shopper is left looking at a
     * frozen shop with no way forward, which is the failure this whole file is
     * written against. Ten seconds later the overlay comes down and the
     * address is offered as an ordinary link they can press themselves.
     */
    later(function () {
      down();

      var line = notice(TEXT.stuck.replace(':provider', name));
      var out = document.createElement('a');
      out.className = 'kbb-placing-out';
      out.setAttribute('href', safe);
      out.setAttribute('rel', 'noopener');
      out.textContent = TEXT.stuckLink.replace(':provider', name);
      line.appendChild(document.createElement('br'));
      line.appendChild(out);
    }, STUCK_MS);
  }

  /* ------------------------------------------------------------ the request */

  function post() {
    var init = {
      method: 'POST',
      /* Said out loud although it is fetch()'s default: this endpoint finds the
         basket by a cookie, and a request without it is not merely
         unauthenticated — it reads as an empty bag, which is the confusing
         failure rather than the obvious one. */
      credentials: 'same-origin',
      headers: {
        'X-CSRF-TOKEN': (window.KBB && window.KBB.csrf) || '',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json'
      },
      body: new FormData(FORM)
    };
    /* Header only: a body _token is read first by Laravel and a reload can bring
       back a stale one; window.KBB.csrf is this load's. */
    init.body.delete('_token');

    if (window.AbortController) {
      aborter = new AbortController();
      init.signal = aborter.signal;
      /* OUR OWN timer, and `timedOut` is how the catch tells this abort apart
         from the one down() raises when the shopper presses Back. */
      later(function () { timedOut = true; try { aborter.abort(); } catch (e) {} }, ABORT_MS);
    }

    return fetch(FORM.getAttribute('action'), init);
  }

  /** The first sentence out of a Laravel validation body, or null. */
  function firstValidationError(data) {
    if (!data) { return null; }

    if (data.errors) {
      for (var field in data.errors) {
        if (!Object.prototype.hasOwnProperty.call(data.errors, field)) { continue; }
        var list = data.errors[field];
        if (list && list.length) { return String(list[0]); }
      }
    }

    return typeof data.message === 'string' && data.message ? data.message : null;
  }

  function place() {
    if (!begin()) { return; }

    post().then(function (response) {
      return response.json().catch(function () { return null; }).then(function (data) {
        return { status: response.status, ok: response.ok, data: data };
      });
    }).then(function (answer) {
      if (answer.status === 419) { fail(TEXT.expired); return; }

      if (!answer.ok || !answer.data || answer.data.ok !== true) {
        var data = answer.data || {};

        {{-- Lane CO: a sold-out line opens the dialog the owner asked for
             (checkout.js, window.KBB.refused) with the overlay down first; a
             basket that is genuinely gone gets its link under the sentence. --}}
        var refused = window.KBB && window.KBB.refused;
        if (refused && data.code === 'sold_out') { down(); if (refused(data)) { return; } }

        fail(
          (typeof data.error === 'string' && data.error)
          || firstValidationError(data)
          || TEXT.failed
        );
        if (refused) { refused(data, (document.getElementById('kbbPlacingNotice') || {}).firstElementChild); }

        return;
      }

      /*
       * BRANCH ON WHAT THE SERVER SAID, NEVER ON THE GATEWAY'S NAME.
       *
       *   placed    the order stands — tick, then the received page
       *   redirect  they are leaving — say where, then go
       *   confirm   the card leg, which stripe-elements owns. Reaching it HERE
       *             means the chosen method wanted a confirmation this listener
       *             cannot perform (Stripe.js did not load, or a gateway was
       *             switched to the card door mid-session), so it is a refusal
       *             rather than a tick over an unpaid order.
       */
      if (answer.data.action === 'placed' && answer.data.success_url) {
        confirmed(answer.data.success_url);
        return;
      }

      if (answer.data.action === 'redirect') {
        leaving(answer.data.url);
        return;
      }

      fail(TEXT.failed);
    }).catch(function () {
      /* An abort raised by down() — the Back button, or a second failure
         landing after the first — is not reported at all: busy is already false
         and the overlay is already gone. */
      if (!busy) { return; }

      fail(timedOut ? TEXT.noAnswer : TEXT.offline);
    });
  }

  /* ------------------------------------------------------------ the listeners */

  /*
   * CAPTURE PHASE, ON document, AND AFTER stripe-elements.
   *
   * checkout.js answers [data-place] with a delegated BUBBLE listener that ends
   * in form.submit(). A capture listener on document runs before it, so
   * stopPropagation() here is what keeps the old full-page post from also
   * happening. stripe-elements registers its own capture listener first and
   * claims the click only when the card is chosen, so the card never reaches
   * this one.
   */
  document.addEventListener('click', function (event) {
    if (!event.target.closest || !event.target.closest('[data-place]')) { return; }

    event.preventDefault();
    event.stopPropagation();
    trace('saw the press');

    if (busy) { trace('busy: an order is already on its way'); return; }

    /*
     * THE BROWSER'S OWN VALIDATION FIRST, AND THE FIELD TAKEN IN HAND THE SAME
     * WAY checkout.js AND stripe-elements TAKE IT.
     *
     * Before the overlay and not after: freezing the page over a form that
     * cannot be submitted would put a blur between the shopper and the field
     * that is wrong. The scroll-then-focus-then-reportValidity order, and the
     * measurements behind it, are written out in resources/js/kbb/checkout.js —
     * the Place order button is rendered after the fields, so the control
     * needing attention is always far above the one being pressed.
     */
    if (!check(event.target, 'overlay')) { return; }

    /* The card was chosen but its script never started (Stripe.js blocked or
       failed to load): stripe-elements registers nothing, so the press lands
       here. Posting it would open an order this page cannot pay for. */
    var method = FORM.querySelector('input[name="payment_method"]:checked');
    if (method && method.value === 'stripe' && !(window.KBB && window.KBB.cardLeg)) {
      note(event.target, SAY.cardMissing, null);
      trace('card chosen but the card form never started');
      return;
    }

    clearNote();
    trace('placing');
    place();
  }, true);

  /* Enter inside a field submits the form without going near a button. Same
     rule, same phase, same reason. */
  FORM.addEventListener('submit', function (event) {
    event.preventDefault();
    event.stopPropagation();
    if (!busy && check(null, 'overlay-enter')) { clearNote(); place(); }
  }, true);

  /*
   * THE BACK BUTTON.
   *
   * A page restored from the back/forward cache comes back exactly as it was
   * left — overlay up, buttons disabled, the rest of the document inert. That
   * is a shop the shopper can neither use nor explain, reached by the single
   * most ordinary gesture there is. It is taken down on restore.
   */
  window.addEventListener('pageshow', function (event) {
    /* (Lane PO hotfix) down() puts each button back the way it FOUND it, and
       the card leg had disabled them before the overlay went up -- so a page
       restored mid-payment came back with dead buttons. liven() after it. */
    if (event.persisted && busy) { down(); liven(); }
  });

  /* Firefox restores a button's `disabled` across a reload (Chrome does not),
     so a reload in the middle of a press brought back a Place order button
     that never answered again. No Place order button is ever rendered
     disabled by the server, so on a fresh load they are all live. */
  function liven() { if (!busy) { buttons().forEach(function (b) { b.disabled = false; }); } }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', liven); } else { liven(); }
  window.addEventListener('pageshow', function (event) { if (!event.persisted) { liven(); } });

  /* ------------------------------------------------------------------ the API */

  /*
   * PUBLISHED FOR partials/checkout/stripe-elements, which owns the card, Apple
   * Pay and Google Pay leg and calls these at the four moments that matter.
   * That partial tests for the object before every call, so a shop whose
   * overlay is missing — an older package, a view cache that did not clear —
   * still takes a card payment exactly as it did before.
   */
  /* --------------------------------------- a press that cannot go ahead (Lane PO) */

  /*
   * NEVER A SILENT PRESS. Firefox for Android does not reliably draw the
   * browser's validation bubble, so a press on a form with one wrong box
   * focused that box and said nothing at all -- "nothing happening upon
   * click". Every early return of the three Place order handlers (this one,
   * stripe-elements' pay(), checkout.js) now leaves words beside the button
   * that was pressed, and a wrong box gets its own line under it, in the
   * shopper's language. The native bubble is not asked for at all.
   *
   * One copy, published as window.KBB.placeCheck / placeNote, because three
   * copies of "what to say" are three answers a release apart.
   */
  var noteEl = null, noteFor = null;

  /* Not rendered: a hidden input, or inside [hidden] or an inline display:none.
     Read from attributes, never from layout. */
  function shown(el) {
    if (el.type === 'hidden' || el.closest('[hidden]')) { return false; }
    for (var n = el; n && n !== FORM; n = n.parentElement) {
      if (n.style && n.style.display === 'none') { return false; }
    }
    return true;
  }

  function label(el) {
    var l = el.id ? FORM.querySelector('label[for="' + el.id + '"]') : null;
    var copy = l ? l.cloneNode(true) : null;
    if (copy) {
      Array.prototype.forEach.call(copy.querySelectorAll('.required, .optional, abbr'), function (x) { x.parentNode.removeChild(x); });
    }
    var text = copy ? copy.textContent.replace(/[\s\u00a0*]+/g, ' ').trim() : '';
    return text || el.getAttribute('aria-label') || el.name || '';
  }

  function rule(el) {
    var v = el.validity || {};
    if (el.type === 'email' && (v.typeMismatch || v.patternMismatch)) { return SAY.email; }
    if (v.valueMissing) { return (el.tagName === 'SELECT' ? SAY.choose : SAY.missing).replace(':field', label(el)); }
    return el.validationMessage || SAY.bad;
  }

  /*
   * The first box that stops the order. A box that is NOT RENDERED cannot be
   * put right by the shopper, so it never blocks: it loses `required`, and what
   * an autofill typed into it is dropped (a saved password poured into the
   * hidden account-password box, minlength 8, is the case that exists). The
   * server still validates everything it needs.
   */
  function blocking() {
    var list = FORM.querySelectorAll('input, select, textarea');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      if (!el.willValidate || el.validity.valid) { continue; }
      if (!shown(el)) {
        el.required = false;
        if (!el.validity.valid && el.type !== 'radio' && el.type !== 'checkbox') { el.value = ''; }
        trace('released a hidden box: ' + (el.id || el.name));
        continue;
      }
      return el;
    }
    return null;
  }

  function clearNote() {
    if (noteEl && noteEl.parentNode) { noteEl.parentNode.removeChild(noteEl); }
    noteEl = null;
    noteFor = null;
  }

  function note(pressed, text, field) {
    clearNote();
    var b = (pressed && pressed.closest && pressed.closest('[data-place]'))
      || document.querySelector('.kbb-mobile-order [data-place]') || document.querySelector('[data-place]');
    if (!b || !b.parentNode) { return; }

    noteEl = document.createElement('p');
    noteEl.className = 'kbb-place-note';
    noteEl.id = 'kbbPlaceNote';
    noteEl.setAttribute('role', 'alert');
    var words = document.createElement(field ? 'button' : 'span');
    words.textContent = text;
    if (field) {
      words.type = 'button';
      words.addEventListener('click', function () {
        field.scrollIntoView({ block: 'center', behavior: 'instant' });
        field.focus({ preventScroll: true });
      });
    }
    noteEl.appendChild(words);
    noteFor = field || null;
    b.parentNode.insertBefore(noteEl, b.nextSibling);
  }

  function fieldError(el, text) {
    var id = (el.id || el.name) + '-kbberr';
    var err = document.getElementById(id);
    if (!err) {
      err = document.createElement('span');
      err.id = id;
      err.className = 'kbb-ferr';
      err.setAttribute('role', 'alert');
      var host = el.closest('.woocommerce-input-wrapper') || el;
      host.parentNode.insertBefore(err, host.nextSibling);
      var d = el.getAttribute('aria-describedby');
      el.setAttribute('aria-describedby', d ? d + ' ' + id : id);
      var off = function () {
        if (!el.validity.valid) { return; }
        if (err.parentNode) { err.parentNode.removeChild(err); }
        var left = (el.getAttribute('aria-describedby') || '').split(' ').filter(function (x) { return x && x !== id; }).join(' ');
        if (left) { el.setAttribute('aria-describedby', left); } else { el.removeAttribute('aria-describedby'); }
        el.removeAttribute('aria-invalid');
        if (noteFor === el) { clearNote(); }
        el.removeEventListener('input', off);
        el.removeEventListener('change', off);
      };
      el.addEventListener('input', off);
      el.addEventListener('change', off);
    }
    err.textContent = text;
    el.setAttribute('aria-invalid', 'true');
  }

  /* true: go ahead. false: the shopper has been told why, beside the button
     and under the box, and the box is in view with the focus in it. */
  function check(pressed, who) {
    var el = blocking();
    if (!el) { return true; }
    fieldError(el, rule(el));
    note(pressed, SAY.check.replace(':field', label(el)), el);
    el.scrollIntoView({ block: 'center', behavior: 'instant' });
    try { el.focus({ preventScroll: true }); } catch (e) {}
    try { if (window.kbbDiag) { window.kbbDiag(who || 'overlay', 'stopped at ' + (el.id || el.name) + ': ' + el.validationMessage); } } catch (e) {}
    return false;
  }

  window.KBB = window.KBB || {};
  window.KBB.placeCheck = check;
  window.KBB.placeNote = function (pressed, kind) { note(pressed, SAY[kind] || TEXT.failed, null); };
  window.KBB.placeNoteClear = clearNote;
  trace('ready');

  window.KBB.placing = {
    begin: begin,
    hold: hold,
    confirmed: confirmed,
    leaving: leaving,
    fail: fail,
    dismiss: down,
    say: say,
    busy: function () { return busy; }
  };
})();
</script>
<!--/kbb-placing-->
