/* Runs inside the preview frame, over the real captured cart / checkout.
   window.__S = { page, after, coupon, marks, o: {options} }. Preview only:
   nothing here ships to the shop; the real build follows the brief's rules. */
(function () {
  var S = window.__S, o = S.o, d = document;
  var $ = function (s, r) { return (r || d).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };
  var tell = function (m) { try { parent.postMessage(Object.assign({ pv: 1 }, m), '*'); } catch (e) {} };
  var h = function (html) { var t = d.createElement('template'); t.innerHTML = html.trim(); return t.content.firstChild; };
  var ICON = {
    tag: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>',
    lock: function (s) { return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'; },
    cart: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.8 4h2.4l2.5 10.8a1.7 1.7 0 0 0 1.7 1.3h7.9a1.7 1.7 0 0 0 1.6-1.3L20.9 8H6"></path><circle cx="10" cy="20" r="1.4"></circle><circle cx="17.4" cy="20" r="1.4"></circle></svg>',
    down: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    apple: '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/></svg>',
    back: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>'
  };
  var marks = [];
  function mk(el, label, right) {
    if (!el) return;
    marks.push([label, el]);
    if (!S.marks) return;
    if (getComputedStyle(el).position === 'static') el.style.position = 'relative';
    el.appendChild(h('<span class="pv-mk' + (right === 1 ? ' r' : right === 2 ? ' m' : '') + '">' + label + '</span>'));
  }
  window.__focus = function (label) {
    var hit = marks.filter(function (m) { return m[0] === label || m[0].indexOf(label) === 0; })[0];
    if (!hit) return false;
    var el = hit[1];
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.add('pv-flash');
    setTimeout(function () { el.classList.remove('pv-flash'); }, 1600);
    return true;
  };
  var amt = function (el) { return el ? el.innerHTML : ''; };
  var chip = function (code, offHtml) {
    return '<div class="pv-chip s-' + o.chip + '">' + ICON.tag + '<span>' + (o.chip === 'C' ? 'Coupon ' : '') + '<span class="pv-code">' + code + '</span></span>' +
      '<span class="pv-off">− ' + offHtml + '</span>' + (o.chipX ? '<button type="button" class="pv-x" data-pv-uncoupon aria-label="Remove coupon">×</button>' : '') + '</div>';
  };

  d.addEventListener('change', function (e) { if (e.target.name === 'payment_method') tell({ pay: e.target.value }); });
  if (S.pay) { var pr = d.getElementById('payment_method_' + S.pay); if (pr) pr.checked = true; }
  d.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : null;
    if (!t) return;
    if (t.closest('[data-pv-uncoupon]')) { e.preventDefault(); tell({ coupon: false }); return; }
    if (t.closest('#kbb_apply_coupon, [data-pv-apply]')) { e.preventDefault(); tell({ coupon: true }); return; }
    if (t.closest('.pv-cart')) { e.preventDefault(); tell({ go: 'cart' }); return; }
    if (t.closest('a.cobtn')) { e.preventDefault(); tell({ go: 'checkout' }); return; }
    var sum = t.closest('#kbbSumRow');
    if (sum) { var a = $('#kbbSummary'); a.classList.toggle('open'); a.classList.toggle('cosr-open'); return; }
    var ar = t.closest('.pv-arrow, .pv-osh');
    if (ar) { ar.closest('.cotot').classList.toggle('pv-open'); return; }
    if (t.closest('a[href="#"]')) e.preventDefault();
  });

  /* ---------------- CART ---------------- */
  if (S.page === 'cart') {
    var sum = $('.kbb-cartpage .sum'), band = $('.cpg-totband', sum), btn = $('a.cobtn');
    var back = $('.kbb-cartpage .backlink');
    if (!S.after) {
      mk(back, '10');
      mk(band, '1'); mk(btn, '1c');
      mk($('.appliedcoupon', sum) || $('.srow.disc', sum), '2');
      return;
    }
    var ovRow = $('.srow', sum), disc = $('.srow.disc', sum);
    var spans = ovRow.children;
    var label = spans[0].innerHTML, vals = spans[1];
    var was = $('.cpg-was', vals), now = vals.lastElementChild;
    var after = $('b', band);
    var shown = (S.coupon && o.ovAfter) ? amt(after) : amt(now);
    var code = disc ? disc.firstElementChild.textContent.trim() : '';
    var off = disc ? amt($('.woocommerce-Price-amount', disc)) : '';
    band.className = 'cpg-totband pv-tb';
    band.innerHTML = (disc ? chip(code, off) : '') +
      '<div class="pv-ov"><span>' + label + '</span><span class="tr">' + (was ? '<span class="cpg-was">' + was.innerHTML + '</span>' : '') + '<b>' + shown + '</b></span></div>' +
      '<div class="pv-ship">Shipping calculated at checkout</div>';
    ovRow.remove(); if (disc) disc.remove();
    var ap = $('.appliedcoupon', sum); if (ap) ap.remove();
    btn.classList.add('pv-black');
    var bt = o.btnCase === 'normal' ? 'Secure checkout' : 'SECURE CHECKOUT';
    if (o.btnCase !== 'normal') btn.classList.add('pv-caps');
    btn.style.fontSize = (+o.btnSize || 15) + 'px';
    var ic = Math.round((+o.btnSize || 15) * 1.05);
    btn.innerHTML = o.btn === 'A' ? ICON.lock(ic) + '<span>' + bt + '</span>' : o.btn === 'B' ? '<span>' + bt + '</span><span aria-hidden="true">›</span>' : bt;
    if (back) {
      if (o.cont === 'on') { back.setAttribute('title', 'Goes to ' + (o.contUrl || '/')); mk(back, '10'); }
      else { var hh = $('.kbb-cartpage h1'); back.remove(); mk(hh, '10'); }
    }
    mk(band, '1'); mk(btn, '1c'); if (disc) mk($('.pv-chip', band), '2');
    return;
  }

  /* ---------------- CHECKOUT ---------------- */
  var sec = $$('#customer_details > .sec');
  var aside = $('#kbbSummary'), row = $('#kbbSumRow'), cbox = $('.kbb-checkout .coupon');
  var mob = $('.kbb-mobile-order');
  var form = $('#customer_details');
  if (form && !S.after) form.insertAdjacentHTML('beforebegin', '<div class="pv-xc"><p class="co-xc-h">Express checkout</p><div class="pv-wallets"><span class="pv-w">Apple Pay</span><span class="pv-w">Google Pay</span></div><p class="pv-xnote">Apple Pay / Google Pay: as today, unchanged (shows only on phones that support it)</p><div class="ordiv co-xc-or">OR</div></div>');
  if (!S.after) {
    mk($('.co-head .secure'), '3a'); mk($('.co-titlebar'), '3b'); mk(row, '3c'); mk(cbox, '3d');
    mk(sec[0] && sec[0].firstElementChild, '4'); mk($('#kbb_remember_field'), '4e');
    mk($('#kbbDeliveryNote'), '5'); mk($('.kbb-card-secure'), '6');
    mk($('.kbb-thumbs-slot', mob), '7'); mk($('[data-place]', mob), '7f');
    mk($('.trust', mob), '8');
    return;
  }

  // 3a header: cart icon in place of "Secure checkout"
  var badge = $('.co-head .secure');
  var count = $$('.co-items .qb', aside).reduce(function (n, q) { return n + (parseInt(q.textContent, 10) || 0); }, 0);
  var cartA = h('<a class="pv-cart" href="#" aria-label="Your bag">' + ICON.cart + (o.badge ? '<span class="pv-badge">' + count + '</span>' : '') + '</a>');
  badge.replaceWith(cartA);
  // 3b title and line go; optional small back link
  var tbar = $('.co-titlebar');
  if (o.back) tbar.replaceWith(h('<a class="pv-back pv-cart-back" href="#">' + ICON.back + 'Back to bag</a>')); else tbar.remove();
  // 3c green summary row
  row.classList.add('pv-green'); aside.classList.add('pv-sg'); aside.parentElement.classList.add('pv-grid');
  // 3d coupon field into the summary, the box goes
  var crow = $('.crow', cbox), items = $('.co-items', aside);
  crow.className = 'pv-cfield';
  var discRow = $('.js-coupons .disc', aside);
  var code = discRow ? discRow.firstElementChild.textContent.trim() : '';
  var off = discRow ? amt($('.woocommerce-Price-amount', discRow)) : '';
  items.parentNode.insertBefore(crow, items.nextSibling);
  if (discRow) crow.insertAdjacentHTML('afterend', chip(code, off));
  cbox.remove();
  // 3e applied: struck old total on the row, discount row, total savings
  if (discRow) {
    var sub = amt($('.js-subtotal', aside));
    $$('.cosr-tot > span', row).forEach(function (s) { s.classList.add('pv-stack'); s.insertAdjacentHTML('afterbegin', '<span class="pv-strike">' + sub + '</span>'); });
    $$('.js-coupons .disc').forEach(function (r) {
      r.classList.add('pv-disc');
      r.firstElementChild.innerHTML = 'Order discount<span class="pv-dcode">' + code + '</span>';
    });
    $$('.cotot').forEach(function (t) {
      var last = $$('.sumrow.tot', t).pop();
      last.insertAdjacentHTML('afterend', '<div class="pv-savings">' + ICON.tag + 'Total savings <b>' + off + '</b></div>');
    });
  }
  mk(cartA, '3a'); mk(aside, '3b'); mk(row, '3c', 1); mk(crow, '3d'); if (discRow) mk($('.pv-chip', aside), '3e');

  // 4 Contact goes; email first; sign-in to the heading; rename; remember tick goes; account line under Country
  var contact = sec[0], ship = sec[1];
  var h2 = $('h2', ship);
  var q = $('.co-signin-q', contact), si = $('.co-signin', contact);
  h2.childNodes.forEach(function (n) { if (n.nodeType === 3 && /Shipping/.test(n.textContent)) n.textContent = ' Delivery Information'; });
  if (o.clear) h2.appendChild(h('<a class="pv-clear" href="#">Not you? Clear</a>'));
  if (o.si === 'below' && (q || si)) {
    var line = h('<p class="pv-si"></p>');
    if (q) line.appendChild(q); if (si) line.appendChild(si);
    h2.insertAdjacentElement('afterend', line);
  } else {
    var stack = h('<span class="pv-sistack"></span>');
    if (q) stack.appendChild(q); if (si) stack.appendChild(si);
    h2.appendChild(stack); h2.classList.add('pv-h2one');
  }
  var email = $('#billing_email_field', contact), acct = $('#create_account_field', contact), ret = $('#kbbReturning', contact);
  ship.insertBefore(email, $('#billing_first_name_field', ship));
  if (o.hint) email.insertAdjacentElement('afterend', h('<p class="pv-hint">We send your order confirmation here.</p>'));
  var rem = $('#kbb_remember_field', ship);
  var country = $('#billing_country_field', ship);
  var r2 = $('.row2', ship), a2 = $('#billing_address_2_field', ship);
  if (r2 && a2) {
    r2.parentNode.insertBefore(a2, r2);
    var st8 = $('#billing_state_field', r2);
    if (o.rows === 'two') { r2.parentNode.insertBefore(st8, r2); r2.parentNode.insertBefore(country, r2); r2.remove(); r2 = null; }
    else { r2.appendChild(country); r2.classList.add('pv-r2'); }
  }
  ship.classList.add('pv-flds');
  var ae = $('#billing_country option[value="AE"]'); if (ae) ae.textContent = 'U.A.E';
  (r2 || country).insertAdjacentElement('afterend', acct);
  if (ret) acct.insertAdjacentElement('afterend', ret);
  if (rem) rem.remove();
  contact.remove();
  $$('#customer_details > .sec').forEach(function (s, i) { var n = $('h2 .n', s); if (n) n.textContent = i + 1; });
  $$('#customer_details > .sec').forEach(function (s) { s.classList.add('pv-sec'); var vis = Array.prototype.filter.call(s.children, function (k) { return !k.hidden && k.id !== 'kbbReturning'; }); if (vis.length) vis[vis.length - 1].classList.add('pv-last'); });
  d.documentElement.style.setProperty('--pv-gap', (+o.gap || 16) + 'px');
  d.documentElement.style.setProperty('--pv-step', (+o.step || 18) + 'px');
  mk(h2, '4', 2); mk(email, '4b'); mk(r2 || $('#billing_state_field', ship), '4g'); mk(acct, '4f');

  // 5 the free-delivery line hides when this bag already gets free delivery
  var dn = $('#kbbDeliveryNote');
  var free = /free/i.test(($('#shipping_method input:checked + label .co-dl-p') || {}).textContent || '');
  if (dn && free) dn.hidden = true;
  var del = $$('#customer_details > .sec')[1];
  if (o.dname && del) del.querySelector('h2').childNodes.forEach(function (n) { if (n.nodeType === 3 && /Delivery/.test(n.textContent)) n.textContent = ' Delivery method'; });
  mk(del && $('h2', del), '5', 2);

  // 6 secure line leaves the card box, joins the Payment heading
  var cs = $('.kbb-card-secure'); if (cs) cs.remove();
  var ph = $('.sec.pay > h2');
  ph.childNodes.forEach(function (n) {
    if (n.nodeType === 3 && /Payment/.test(n.textContent)) {
      n.replaceWith(h('<span class="pv-h2t"><span>Payment</span><small>' + ICON.lock(12) + 'All transactions are secure &amp; encrypted</small></span>'));
    }
  });
  mk(ph, '6', 2);

  // 9 Apple Pay as payment method no. 2 (Google Pay not offered)
  var card = $('.payment_method_stripe');
  if (card) {
    card.insertAdjacentHTML('afterend', '<li class="wc_payment_method payment_method_applepay pay-brand"><input id="payment_method_applepay" type="radio" class="input-radio" name="payment_method" value="applepay"><label for="payment_method_applepay"> Apple Pay <span class="pv-apm">' + ICON.apple + 'Pay</span></label><div class="payment_box payment_method_applepay"><p>Pay with Face ID or Touch ID. Shown only on iPhone, iPad and Mac with Apple Pay.</p></div></li>');
    mk($('.payment_method_applepay'), '9', 1);
  }
  $$('[data-place]').forEach(function (b) { b.insertAdjacentHTML('afterend', '<button type="button" class="pv-apbtn" aria-label="Pay with Apple Pay">' + ICON.apple.replace(/12/g, '18') + 'Pay</button>'); });
  if (S.pay === 'applepay') { var apr = d.getElementById('payment_method_applepay'); if (apr) apr.checked = true; }

  // 7 bottom block: no pictures, Total only + arrow + savings
  var th = $('.kbb-thumbs-slot', mob); if (th) th.remove();
  var mt = $('.cotot', mob);
  mob.classList.add('pv-only'); if (!o.vat) mob.classList.add('pv-novat');
  var box = h('<div class="pv-sumbox"></div>');
  box.appendChild(items.cloneNode(true));
  var cf = crow.cloneNode(true);
  $$('[id]', cf).forEach(function (n) { n.removeAttribute('id'); });
  $$('label[for]', cf).forEach(function (n) { n.removeAttribute('for'); });
  var ab = $('.apply', cf); if (ab) ab.setAttribute('data-pv-apply', '');
  box.appendChild(cf);
  if (discRow) box.insertAdjacentHTML('beforeend', chip(code, off));
  mt.insertBefore(box, mt.firstChild);
  mt.insertBefore(h('<button type="button" class="pv-osh" aria-label="Show or hide the order summary"><span>Order summary</span>' + ICON.down + '</button>'), mt.firstChild);
  $$('.sumrow.tot', mt).forEach(function (r) {
    var v = r.children[1]; v.style.marginInlineStart = 'auto';
    // Right column: the amount with its arrow, and the savings line right under the amount
    var rc = h('<span class="pv-rc"><span class="pv-rl"></span></span>');
    r.insertBefore(rc, v); rc.firstChild.appendChild(v);
    v.insertAdjacentHTML('afterend', '<button type="button" class="pv-arrow" aria-label="Show order summary">' + ICON.down + '</button>');
    var sv = $('.cotot > .pv-savings', mob); if (sv) rc.appendChild(sv.cloneNode(true));
    // VAT line sits right under the word "Total"
    if (o.vat) r.firstElementChild.insertAdjacentHTML('beforeend', '<small class="pv-vat">5% VAT inclusive</small>');
    r.firstElementChild.classList.add('pv-tl');
  });
  $$('.cotot > .pv-savings', mob).forEach(function (x) { x.remove(); });
  mob.classList.add('pv-novat');
  mk(mt, '7');

  // 7f button wording by method; 8 trust wording
  var SSL = { A: 'Secure payment', B: 'Safe checkout', C: 'Protected payment', D: 'Encrypted & secure', E: 'Your data is safe' }[o.ssl];
  $$('.trust > span:first-child').forEach(function (s) {
    s.childNodes.forEach(function (n) { if (n.nodeType === 3 && /SSL/.test(n.textContent)) n.textContent = ' ' + SSL; });
  });
  function label() {
    var m = ($('input[name=payment_method]:checked') || {}).value;
    var cod = m === 'cod';
    var t = cod ? 'Complete Order' : 'Pay now';
    if (o.payAmt) {
      var tot = $(cod ? '.js-total-fee' : '.js-total', mt);
      t += ' · ' + (tot ? tot.textContent.replace(/\s+/g, ' ').trim() : '');
    }
    $$('[data-place]').forEach(function (b) { b.textContent = t; });
  }
  d.addEventListener('change', function (e) { if (e.target.name === 'payment_method') label(); });
  label();
  mk($('[data-place]', mob), '7f'); mk($('.trust', mob), '8');
})();
