{{--
    The 13 October layout's few moving parts (2.60.495). Inline, pushed with
    the checkout's own scripts: no new file, no new request, no timer, nothing
    that measures layout. It owns NO part of the order: it never posts, never
    computes a figure and never touches a field's value. Every price it shows
    was printed by the server; every element it moves keeps its id.

    1. THE PAY BUTTON'S WORDS. "Pay now", or "Complete Order" for Cash on
       delivery. Plain text, because stripe-elements keeps a button's
       textContent while a card is working and writes it back afterwards
       (data-kbb-label is kept in step for exactly that). Re-applied after
       checkout.js swaps an order block in, which brings the server's first
       words back.
    2. THE BOX AT THE FOOT OF THE PAGE. Its arrow opens the heading, a copy of
       the summary's lines and the coupon field above the totals (.co-osbox,
       outside the swapped slot); the copy is refreshed when checkout.js
       re-renders the summary's lines.
    3. ONE COUPON FIELD, TWO PLACES. The field is moved, never copied, between
       the top summary and the box at the foot of the page -- to whichever was
       opened last. checkout.js finds it by id and by `.coupon .crow`, so it
       works wherever it is.
    4. THE FREE-DELIVERY LINE AFTER A QUANTITY CHANGE. checkout.js re-draws the
       delivery options on a quantity change but not the line beside the
       heading; this hides it while the delivery the basket is priced on is
       free, and brings back the words the page drew when it is not.
--}}
<script>
(function () {
  'use strict';
  var PAGE = document.querySelector('.kbb-checkout');
  var FORM = document.getElementById('kbbCheckoutForm');
  if (!PAGE || !FORM) { return; }
  var WORDS = @json($kbbLoWords);
  var NOTE_FREE = @json((bool) $kbbCoPage->lo('dl_note_free'));

  /* 1 ── the pay button ────────────────────────────────────────────────── */
  function method() {
    var r = FORM.querySelector('input[name="payment_method"]:checked');
    return r ? r.value : '';
  }
  function words() {
    if (!WORDS) { return; }
    var text = method() === 'cod' ? WORDS.cod : WORDS.pay;
    document.querySelectorAll('[data-place]').forEach(function (b) {
      if (b.disabled) { return; } // a card is working: its own words stand
      if (b.textContent !== text) { b.textContent = text; }
      if (b.dataset.kbbLabel) { b.dataset.kbbLabel = text; }
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'payment_method') { words(); }
  });

  /* 2 + 3 ── the two summaries and the one coupon field ──────────────── */
  var box = document.querySelector('.kbb-mobile-order');
  var osbox = box ? box.querySelector('.co-osbox') : null;
  var topHost = document.querySelector('#kbbSummary .co-sumcoupon');
  var footHost = osbox ? osbox.querySelector('.co-oscoupon') : null;
  var lines = document.querySelector('#kbbSummary .co-items');
  var copyHost = osbox ? osbox.querySelector('.co-ositems') : null;
  var summary = document.getElementById('kbbSummary');

  function coupon() { return FORM.querySelector('.coupon'); }
  function moveCoupon(to) {
    var c = coupon();
    if (c && to && c.parentNode !== to) { to.appendChild(c); }
  }
  function copyLines() {
    if (!copyHost || !lines) { return; }
    var c = lines.cloneNode(true);
    c.removeAttribute('id');
    c.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
    copyHost.replaceChildren(c);
  }
  function setFoot(open) {
    if (!box) { return; }
    box.classList.toggle('co-open', open);
    box.querySelectorAll('.co-ta, .co-osh').forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    if (open) { copyLines(); moveCoupon(footHost); }
    else { moveCoupon(topHost); }
  }
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target : null;
    if (!t) { return; }
    if (box && (t.closest('.kbb-mobile-order .co-ta') || t.closest('.kbb-mobile-order .co-osh'))) {
      e.preventDefault();
      setFoot(!box.classList.contains('co-open'));
      return;
    }
    // The top summary opening takes the field back up.
    if (t.closest('#kbbSumRow')) {
      setTimeout(function () {
        if (summary && summary.classList.contains('cosr-open')) { moveCoupon(topHost); }
      }, 0);
    }
  });

  /* 4 ── the free-delivery line ───────────────────────────────────────── */
  var note = document.getElementById('kbbDeliveryNote');
  var slot = document.getElementById('kbbDeliverySlot');
  var country = document.getElementById('billing_country');
  var kept = note && note.innerHTML.trim() !== '' ? { html: note.innerHTML, at: country ? country.value : '' } : null;
  function noteFollow() {
    if (!note || !NOTE_FREE) { return; }
    var chosen = slot ? slot.querySelector('input[name="shipping_method"]:checked') : null;
    var free = !!chosen && parseInt(chosen.getAttribute('data-kbb-cost') || '1', 10) <= 0;
    if (note.innerHTML.trim() !== '') {
      kept = { html: note.innerHTML, at: country ? country.value : '' };
    } else if (!free && kept && country && kept.at === country.value) {
      note.innerHTML = kept.html;
    }
    note.hidden = free || note.innerHTML.trim() === '';
  }

  /* One observer per region checkout.js re-renders: an event, not a poll. */
  if ('MutationObserver' in window) {
    document.querySelectorAll('.kbb-order-slot').forEach(function (s) {
      new MutationObserver(words).observe(s, { childList: true });
    });
    if (lines) { new MutationObserver(function () { if (box && box.classList.contains('co-open')) { copyLines(); } }).observe(lines, { childList: true }); }
    if (slot) { new MutationObserver(function () { setTimeout(noteFollow, 0); }).observe(slot, { childList: true }); }
    // A re-drawn payment list (country or total change) may move the choice.
    var pay = document.getElementById('payment');
    if (pay) { new MutationObserver(words).observe(pay, { childList: true }); }
  }
  words();
})();
</script>
