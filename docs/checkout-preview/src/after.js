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
    cart: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>',
    down: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
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
    if (t.closest('#kbb_apply_coupon')) { e.preventDefault(); tell({ coupon: true }); return; }
    if (t.closest('.pv-cart')) { e.preventDefault(); tell({ go: 'cart' }); return; }
    if (t.closest('a.cobtn')) { e.preventDefault(); tell({ go: 'checkout' }); return; }
    var sum = t.closest('#kbbSumRow');
    if (sum) { var a = $('#kbbSummary'); a.classList.toggle('open'); a.classList.toggle('cosr-open'); return; }
    var ar = t.closest('.pv-arrow');
    if (ar) { ar.closest('.cotot').classList.toggle('pv-open'); return; }
    if (t.closest('a[href="#"]')) e.preventDefault();
  });

  /* ---------------- CART ---------------- */
  if (S.page === 'cart') {
    var sum = $('.kbb-cartpage .sum'), band = $('.cpg-totband', sum), btn = $('a.cobtn');
    if (!S.after) {
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
    btn.innerHTML = o.btn === 'A' ? ICON.lock(16) + '<span>SECURE CHECKOUT</span>' : o.btn === 'B' ? '<span>SECURE CHECKOUT</span><span aria-hidden="true">›</span>' : 'SECURE CHECKOUT';
    mk(band, '1'); mk(btn, '1c'); if (disc) mk($('.pv-chip', band), '2');
    return;
  }

  /* ---------------- CHECKOUT ---------------- */
  var sec = $$('#customer_details > .sec');
  var aside = $('#kbbSummary'), row = $('#kbbSumRow'), cbox = $('.kbb-checkout .coupon');
  var mob = $('.kbb-mobile-order');
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
  row.classList.add('pv-green');
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
    $$('.cosr-tot > span', row).forEach(function (s) { s.insertAdjacentHTML('afterbegin', '<span class="pv-strike">' + sub + '</span>'); });
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
  } else { if (q) h2.appendChild(q); if (si) h2.appendChild(si); }
  var email = $('#billing_email_field', contact), acct = $('#create_account_field', contact), ret = $('#kbbReturning', contact);
  ship.insertBefore(email, $('#billing_first_name_field', ship));
  if (o.hint) email.insertAdjacentElement('afterend', h('<p class="pv-hint">We send your order confirmation here.</p>'));
  var rem = $('#kbb_remember_field', ship);
  var country = $('#billing_country_field', ship);
  country.insertAdjacentElement('afterend', acct);
  if (ret) acct.insertAdjacentElement('afterend', ret);
  if (rem) rem.remove();
  contact.remove();
  $$('#customer_details > .sec').forEach(function (s, i) { var n = $('h2 .n', s); if (n) n.textContent = i + 1; });
  mk(h2, '4', 2); mk(email, '4b'); mk(acct, '4f');

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

  // 7 bottom block: no pictures, Total only + arrow + savings
  var th = $('.kbb-thumbs-slot', mob); if (th) th.remove();
  var mt = $('.cotot', mob);
  mob.classList.add('pv-only'); if (!o.vat) mob.classList.add('pv-novat');
  var box = h('<div class="pv-sumbox"></div>');
  box.appendChild(items.cloneNode(true));
  mt.insertBefore(box, mt.firstChild);
  $$('.sumrow.tot', mt).forEach(function (r) {
    r.lastElementChild.insertAdjacentHTML('afterend', '<button type="button" class="pv-arrow" aria-label="Show order summary">' + ICON.down + '</button>');
    r.style.alignItems = 'center';
    var v = r.children[1]; v.style.marginInlineStart = 'auto';
  });
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
