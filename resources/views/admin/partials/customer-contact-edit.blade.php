{{--
    Store → Customers → (a customer) → Edit email / phone. (Lane CE)
    Also opened from Store → Orders → (an order) → Customer → "Edit email / phone".

    THE OWNER'S ASK: "we have mostly cases that our customers didn't input the
    correct emails ... give me facility to edit the customer's email / phone,
    and will have option to send order details again to customer upon checkbox
    confirmation. ... the customer input the wrong domain, yahoo.co instead of
    yahoo.com, i need to correct it."

    WHAT THIS FILE IS. One dialog, window.kbbCustomerContact.open({id, onDone}):
      - the email and phone, with a typo hint under the email that is computed
        HERE, from the constant lists below, with no request per keystroke;
      - "Also correct it on their N orders" (ticked), N counted in the browser
        from the order ids the dialog's one GET returned;
      - "Send the order confirmation again to <new email>" (unticked) with a
        pick-list of their placed orders, most recent first, and a
        confirmation step before anything is sent;
      - the history of corrections, each with "Put back".

    THE HINT IS ONLY A HINT (the owner: "hints are fine, but must be
    accurate"). Domain only; only toward a short allowlist of real providers;
    never for a domain that is itself real (the keep list); one suggestion or
    none; it never edits the field -- a tap on the suggestion does, and "Keep
    as typed" hides it for that value. CustomerContactTypoHintTest runs THIS
    function, cut out of this file between its markers, through node.

    Everything the server returns is printed through esc(). Routes are all
    `customers.edit` (owner, manager); anyone else is told so from the 403.
--}}
<style>
.kxe-bg{position:fixed;inset:0;background:rgba(16,23,41,.5);z-index:120;display:none;align-items:flex-start;justify-content:center;padding:24px 16px;overflow:auto}
.kxe-bg.on{display:flex}
.kxe{background:var(--surface);border-radius:var(--r);box-shadow:var(--sh-l);width:100%;max-width:560px;margin:auto 0}
.kxe-h{display:flex;align-items:center;gap:11px;padding:16px 20px;border-bottom:1px solid var(--border)}
.kxe-h b{font-size:15px;font-weight:700}
.kxe-h .x{margin-left:auto;width:30px;height:30px;border-radius:8px;display:grid;place-items:center;color:var(--ink-soft)}
.kxe-b{padding:18px 20px}
.kxe-lbl{display:block;font-size:11.5px;font-weight:600;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.04em;margin:0 0 6px}
.kxe input[type=email],.kxe input[type=tel],.kxe select{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;padding:9px 11px;font:inherit;font-size:13px;background:var(--surface);color:var(--ink)}
.kxe-row{margin-bottom:14px}
.kxe-hint{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;margin-top:8px;font-size:12.5px;color:var(--ink-2);padding:8px 10px;border-radius:10px;background:var(--amber-soft,#fff6e0)}
.kxe-hint button{font:inherit;font-size:12.5px;padding:3px 9px;border-radius:8px}
.kxe-hint .use{background:var(--accent,#2f9e6d);color:#fff;font-weight:600}
.kxe-hint .keep{color:var(--ink-soft);text-decoration:underline}
.kxe-warn{font-size:12px;color:var(--ink-2);background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:9px 11px;margin-top:8px;line-height:1.5}
.kxe-chk{display:flex;gap:9px;align-items:flex-start;font-size:13px;color:var(--ink);padding:10px 0;border-top:1px solid var(--border)}
.kxe-chk input{margin-top:2px;flex-shrink:0}
.kxe-chk small{display:block;color:var(--ink-soft);font-size:11.5px;margin-top:2px;line-height:1.45}
.kxe-chk.off{opacity:.55}
.kxe-err{font-size:12.5px;color:var(--red);margin-top:10px}
.kxe-ok{font-size:13px;color:var(--ink);line-height:1.6}
.kxe-ok li{margin:2px 0}
.kxe-confirm{border:1px solid var(--border);border-radius:12px;padding:12px 14px;background:var(--surface-2);font-size:13px;line-height:1.55;margin-top:6px;word-break:break-word}
.kxe-hist{margin-top:14px;border-top:1px solid var(--border);padding-top:10px}
.kxe-hist table{width:100%;font-size:12px}
.kxe-hist td{padding:6px 4px;border-top:1px solid var(--border-2,var(--border));vertical-align:top;word-break:break-word}
.kxe-hist button{font:inherit;font-size:11.5px;color:var(--accent-ink,var(--accent));text-decoration:underline}
.kxe-f{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;align-items:center;padding:14px 20px;border-top:1px solid var(--border)}
</style>

<div class="kxe-bg" id="kxeBg" role="dialog" aria-modal="true" aria-labelledby="kxeTitle"><div class="kxe" id="kxe"></div></div>

<script>
(function () {
  'use strict';

  /*kxe-suggest-start*/
  /* Real mail providers common in the UAE / GCC: the ONLY places a hint points. */
  var KXE_PROVIDERS = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'me.com', 'msn.com', 'aol.com',
    'yahoo.co.uk', 'hotmail.co.uk', 'emirates.net.ae', 'eim.ae', 'ymail.com', 'rocketmail.com', 'protonmail.com', 'proton.me',
    'mail.ru', 'yandex.ru', 'gmx.com'];
  /* Real domains that sit near a provider and must never be "corrected":
     the providers' own country variants, and real neighbours (mail.com is a
     provider one letter from gmail.com). */
  var KXE_KEEP = ['yahoo.co.in', 'yahoo.co.jp', 'yahoo.co.id', 'yahoo.co.nz', 'yahoo.co.za', 'yahoo.co.kr', 'yahoo.co.th',
    'yahoo.com.au', 'yahoo.com.sg', 'yahoo.com.ph', 'yahoo.com.my', 'yahoo.com.br', 'yahoo.com.hk', 'yahoo.com.tw', 'yahoo.com.ar', 'yahoo.com.mx', 'yahoo.com.vn',
    'yahoo.fr', 'yahoo.de', 'yahoo.es', 'yahoo.it', 'yahoo.ca', 'yahoo.in', 'yahoo.ie', 'yahoo.gr', 'yahoo.se', 'yahoo.dk', 'yahoo.no', 'yahoo.pl',
    'hotmail.fr', 'hotmail.de', 'hotmail.it', 'hotmail.es', 'hotmail.be', 'hotmail.nl', 'hotmail.ca', 'hotmail.se', 'hotmail.no', 'hotmail.dk', 'hotmail.gr', 'hotmail.ch', 'hotmail.co.jp', 'hotmail.co.za', 'hotmail.com.au', 'hotmail.com.br', 'hotmail.com.tr',
    'outlook.sa', 'outlook.fr', 'outlook.de', 'outlook.es', 'outlook.it', 'outlook.be', 'outlook.ie', 'outlook.in', 'outlook.jp', 'outlook.kr', 'outlook.co.id', 'outlook.co.il', 'outlook.co.th', 'outlook.com.au', 'outlook.com.br', 'outlook.com.tr', 'outlook.com.vn', 'outlook.com.ar', 'outlook.ph', 'outlook.sg', 'outlook.my', 'outlook.at', 'outlook.cz', 'outlook.dk', 'outlook.pt', 'outlook.cl', 'outlook.hu', 'outlook.lv', 'outlook.sk',
    'live.co.uk', 'live.fr', 'live.de', 'live.it', 'live.nl', 'live.be', 'live.ca', 'live.se', 'live.no', 'live.dk', 'live.ie', 'live.at', 'live.cl', 'live.in', 'live.com.au', 'live.com.mx', 'live.com.ar', 'live.com.pt', 'live.co.za',
    'gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'gmx.fr', 'gmx.us', 'gmx.co.uk', 'googlemail.com', 'mail.com', 'email.com', 'yandex.com', 'yandex.ua', 'yandex.kz', 'yandex.by', 'inbox.ru', 'list.ru', 'bk.ru',
    'aim.com', 'mac.com', 'wahoo.com'];
  /* The mistyped top-level parts the owner listed. Fixed ONLY when the rest is a provider. */
  var KXE_TLD_TYPOS = ['con', 'cmo', 'comm', 'om', 'co'];

  /* Optimal-string-alignment distance: one swap ("gmial") is one edit. */
  function kxeDistance(a, b) {
    var d = [], i, j;
    for (i = 0; i <= a.length; i++) { d[i] = [i]; }
    for (j = 0; j <= b.length; j++) { d[0][j] = j; }
    for (i = 1; i <= a.length; i++) {
      for (j = 1; j <= b.length; j++) {
        var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
        d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
        if (i > 1 && j > 1 && a.charAt(i - 1) === b.charAt(j - 2) && a.charAt(i - 2) === b.charAt(j - 1)) {
          d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
        }
      }
    }
    return d[a.length][b.length];
  }

  /* The full corrected address, or null. Never touches the part before @. */
  function kxeSuggest(email) {
    var s = String(email == null ? '' : email).trim();
    var at = s.lastIndexOf('@');
    if (at < 1) return null;
    var local = s.slice(0, at);
    var domain = s.slice(at + 1).toLowerCase();
    /* ASCII domains only: an IDN, a space, or anything odd gets no opinion. */
    if (!/^[a-z0-9-]+(\.[a-z0-9-]+)+$/.test(domain)) return null;
    if (KXE_PROVIDERS.indexOf(domain) !== -1 || KXE_KEEP.indexOf(domain) !== -1) return null;

    var found = [];
    var dot = domain.indexOf('.');
    var name = domain.slice(0, dot), suffix = domain.slice(dot + 1);

    /* 1. A mistyped ending on a provider's own name: gmail.con, yahoo.co. */
    if (KXE_TLD_TYPOS.indexOf(suffix) !== -1) {
      KXE_PROVIDERS.forEach(function (p) {
        if (p === name + '.com') found.push(p);
      });
      return found.length === 1 ? local + '@' + found[0] : null;
    }

    /* 2. One slip in a provider's name, with the ending exactly right:
          gmial.com, hotmial.co.uk. Names under five letters (live, me, msn,
          aol, gmx, eim, mail) have too many real neighbours for one edit to
          mean a typo, so they are only ever reached by rule 1. Two providers
          equally close is no suggestion at all. */
    var best = 2, bestList = [];
    KXE_PROVIDERS.forEach(function (p) {
      var pd = p.indexOf('.');
      var pName = p.slice(0, pd), pSuffix = p.slice(pd + 1);
      if (pSuffix !== suffix || pName.length < 5) return;
      var dist = kxeDistance(name, pName);
      if (dist > 1) return;
      if (dist < best) { best = dist; bestList = [p]; }
      else if (dist === best) { bestList.push(p); }
    });
    return bestList.length === 1 ? local + '@' + bestList[0] : null;
  }
  /*kxe-suggest-end*/

  var S = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }

  async function call(path, body) {
    var opts = { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var r = await fetch(base() + '/admin-api/customers/' + path, opts);
    var data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (!r.ok) {
      var first = data && data.errors ? Object.keys(data.errors).map(function (k) { return data.errors[k][0]; })[0] : null;
      var err = new Error(first || (data && data.message) || ('The server answered ' + r.status + '.'));
      err.status = r.status; err.body = data;
      throw err;
    }
    return data;
  }

  function why(e) {
    if (e && e.status === 403) return 'Only an owner or a manager can correct a customer’s email or phone. Ask the shop owner, or to change your role in Settings → Users.';
    if (e && e.status === 419) return 'The admin session expired. Reload the page and try again.';
    if (e && e.status === 429) return 'Too many attempts in a minute. Wait a moment and try again.';
    var silent = !(e && e.body && (e.body.message || e.body.errors));
    if (e && e.status === 404 && silent) return 'These endpoints are not in this server’s compiled route table yet. Clear the route cache (Platform → Cache) and reload.';
    return (e && e.message) || 'Something went wrong.';
  }

  function date(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T') + (/[zZ+]/.test(String(s).slice(10)) ? '' : 'Z'));
    return isNaN(d) ? String(s) : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function bg() { return document.getElementById('kxeBg'); }
  function box() { return document.getElementById('kxe'); }
  function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }

  function close() {
    var b = bg();
    if (b) b.classList.remove('on');
    var done = S && S.changed && S.onDone;
    S = null;
    if (done) done();
  }

  async function open(opts) {
    opts = opts || {};
    S = { id: +opts.id, onDone: opts.onDone || null, d: null, keep: '', step: 'edit', error: '', changed: false };
    bg().classList.add('on');
    box().innerHTML = '<div class="kxe-b">Loading…</div>';
    try {
      S.d = await call(S.id + '/contact');
      S.email = S.d.customer.email; S.phone = S.d.customer.phone || '';
    } catch (e) {
      box().innerHTML = head() + '<div class="kxe-b"><p class="kxe-err">' + esc(why(e)) + '</p></div>' +
        '<div class="kxe-f"><button class="btn ghost" data-kxe="close">Close</button></div>';
      wire();
      return;
    }
    paint();
  }

  function head() {
    return '<div class="kxe-h"><b id="kxeTitle">Edit email / phone</b><button class="x" data-kxe="close" aria-label="Close">✕</button></div>';
  }

  /* The orders a save would touch, counted from the ids the GET returned. */
  function followCount() {
    var d = S.d, seen = {}, n = 0;
    var emailMoves = S.email.trim().toLowerCase() !== d.customer.email.toLowerCase();
    var phoneMoves = S.phone.trim().replace(/\s+/g, ' ') !== (d.customer.phone || '');
    if (emailMoves) d.orders_with_email.forEach(function (id) { if (!seen[id]) { seen[id] = 1; n++; } });
    if (phoneMoves && d.customer.phone) d.orders_with_phone.forEach(function (id) { if (!seen[id]) { seen[id] = 1; n++; } });
    return n;
  }

  function paint() {
    var d = S.d, c = d.customer;
    var newEmail = S.email.trim();
    var emailMoves = newEmail.toLowerCase() !== c.email.toLowerCase();
    var n = followCount();
    var follow = S.follow !== false;
    var pick = d.resendable;
    var target = emailMoves ? newEmail : c.email;

    if (S.step === 'confirm') {
      var o = pick.filter(function (x) { return String(x.id) === String(S.orderId); })[0];
      box().innerHTML = head() + '<div class="kxe-b">' +
        '<b style="font-size:13.5px">Send this email now?</b>' +
        '<div class="kxe-confirm">The order confirmation for <b>' + esc(o ? o.order_number : '') + '</b> will be emailed to <b>' + esc(target) + '</b> as soon as you press Save and send.' +
        (emailMoves ? '<br>The email will first be corrected from <s>' + esc(c.email) + '</s> to <b>' + esc(newEmail) + '</b>' + (follow && n ? ' on the customer and on ' + n + ' order' + (n === 1 ? '' : 's') : ' on the customer') + '.' : '') +
        '</div>' +
        (S.error ? '<p class="kxe-err">' + esc(S.error) + '</p>' : '') +
        '</div><div class="kxe-f"><button class="btn ghost" data-kxe="back">Back</button><button class="btn" data-kxe="send" id="kxeSend">Save and send</button></div>';
      wire();
      return;
    }

    if (S.step === 'done') {
      box().innerHTML = head() + '<div class="kxe-b"><ul class="kxe-ok">' + S.lines.map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>' +
        (S.error ? '<p class="kxe-err">' + esc(S.error) + '</p>' : '') +
        '</div><div class="kxe-f"><button class="btn" data-kxe="close">Done</button></div>';
      wire();
      return;
    }

    box().innerHTML = head() + '<div class="kxe-b">' +
      '<div class="kxe-row"><label class="kxe-lbl" for="kxeEmail">Email</label>' +
        '<input type="email" id="kxeEmail" maxlength="160" autocomplete="off" spellcheck="false" value="' + esc(S.email) + '">' +
        '<div id="kxeHintSlot">' + hintHTML() + '</div>' +
        (c.account_type === 'account'
          ? '<div class="kxe-warn"><b>This customer signs in with this email.</b> Changing it changes their sign-in address: they will sign in with the new one, it will show as not verified until they confirm it, and any password-reset link sent to the old address stops working. They are not signed out.</div>'
          : '') +
      '</div>' +
      '<div class="kxe-row"><label class="kxe-lbl" for="kxePhone">Phone</label>' +
        '<input type="tel" id="kxePhone" maxlength="40" autocomplete="off" value="' + esc(S.phone) + '"></div>' +
      '<div id="kxeChecks">' + (S.checksWas = checksHTML()) + '</div>' +
      (S.error ? '<p class="kxe-err" id="kxeErr">' + esc(S.error) + '</p>' : '') +
      history() +
      '</div><div class="kxe-f"><button class="btn ghost" data-kxe="close">Cancel</button><button class="btn" data-kxe="save" id="kxeSave">Save</button></div>';
    wire();
  }

  /* THE HINT WAITS FOR THE FIELD TO BE LEFT. The owner: "the dialogue box
     should appear only once user left the email field. during write no any
     disturbance." So nothing appears while typing; leaving the field (blur)
     checks what was typed and shows the hint only then. Typing again hides
     it until the field is left again. */
  function hintHTML() {
    if (!S.hintReady) return '';
    var typed = S.email.trim();
    var hint = kxeSuggest(typed);
    if (!hint || hint === S.keep) return '';
    return '<div class="kxe-hint" id="kxeHint"><span>Did you mean <b>' + esc(hint) + '</b>?</span>' +
      '<button type="button" class="use" data-kxe="use" data-v="' + esc(hint) + '">Use it</button>' +
      '<button type="button" class="keep" data-kxe="keep" data-v="' + esc(typed) + '">Keep as typed</button></div>';
  }

  /* The resend goes to the address ON THE ORDER, so it is offered only when the
     order will carry the new address after this save. */
  function checksHTML() {
    var d = S.d, c = d.customer, pick = d.resendable;
    var newEmail = S.email.trim();
    var emailMoves = newEmail.toLowerCase() !== c.email.toLowerCase();
    var target = emailMoves ? newEmail : c.email;
    var n = followCount();
    var follow = S.follow !== false;
    var resendable = pick.length > 0 && (!emailMoves || follow);
    var resendOn = !!S.resend && resendable;
    return '<label class="kxe-chk' + (n ? '' : ' off') + '"><input type="checkbox" id="kxeFollow"' + (follow ? ' checked' : '') + (n ? '' : ' disabled') + '>' +
        '<span>' + (n ? 'Also correct it on their ' + n + ' order' + (n === 1 ? '' : 's') : 'Also correct it on their orders') +
        '<small>' + (n ? 'Only where the order carries the old value. Totals, status and payment are not touched; each order gets a note.' : 'No order carries the value you are changing.') + '</small></span></label>' +
      '<label class="kxe-chk' + (resendable ? '' : ' off') + '"><input type="checkbox" id="kxeResend"' + (resendOn ? ' checked' : '') + (resendable ? '' : ' disabled') + '>' +
        '<span>Send the order confirmation again to <b>' + esc(target) + '</b>' +
        '<small>' + (pick.length ? (resendable ? 'You will be asked to confirm before anything is sent.' : 'Tick "Also correct it on their orders" first: the email goes to the address on the order.') : 'No placed order to send a confirmation for.') + '</small>' +
        (resendOn ? '<select id="kxeOrder" style="margin-top:8px" aria-label="Order to send">' + pick.map(function (o) {
          return '<option value="' + o.id + '"' + (String(o.id) === String(S.orderId) ? ' selected' : '') + '>' + esc(o.order_number) + ' \u00b7 ' + esc(o.status) + ' \u00b7 ' + esc(date(o.placed_at)) + '</option>';
        }).join('') + '</select>' : '') +
      '</span></label>';
  }

  /* Typing redraws ONLY the hint and the two checkboxes -- never the field
     being typed in, so the caret and an IME composition are left alone. */
  function refresh() {
    var h = document.getElementById('kxeHintSlot'), k = document.getElementById('kxeChecks');
    // Only what changed is redrawn: leaving the email field just as the
    // pointer goes down on a checkbox must not replace that checkbox under
    // it, or the click is lost.
    var hh = hintHTML(), kk = checksHTML();
    if (h && h.innerHTML !== hh) h.innerHTML = hh;
    if (k && S.checksWas !== kk) { k.innerHTML = kk; S.checksWas = kk; }
  }

  function history() {
    var h = S.d.history || [];
    if (!h.length) return '';
    return '<div class="kxe-hist"><span class="kxe-lbl">History</span><table>' + h.map(function (r) {
      if (r.kind === 'resend') {
        return '<tr><td>' + esc(date(r.at)) + '</td><td>Confirmation re-sent to ' + esc(r.new_email) + '</td><td>' + esc(r.by || '') + '</td><td></td></tr>';
      }
      var parts = [];
      if ((r.old_email || '') !== (r.new_email || '')) parts.push(esc(r.old_email) + ' → ' + esc(r.new_email));
      if ((r.old_phone || '') !== (r.new_phone || '')) parts.push(esc(r.old_phone || '(none)') + ' → ' + esc(r.new_phone || '(none)'));
      return '<tr><td>' + esc(date(r.at)) + '</td><td>' + parts.join('<br>') + (r.orders_updated ? '<br><small>' + r.orders_updated + ' order' + (r.orders_updated === 1 ? '' : 's') + '</small>' : '') + '</td><td>' + esc(r.by || '') + '</td>' +
        '<td><button type="button" data-kxe="putback" data-e="' + esc(r.old_email || '') + '" data-p="' + esc(r.old_phone || '') + '">Put back</button></td></tr>';
    }).join('') + '</table></div>';
  }

  function grab() {
    var e = document.getElementById('kxeEmail'), p = document.getElementById('kxePhone');
    var f = document.getElementById('kxeFollow'), r = document.getElementById('kxeResend'), o = document.getElementById('kxeOrder');
    if (e) S.email = e.value;
    if (p) S.phone = p.value;
    if (f && !f.disabled) S.follow = f.checked;
    if (r) S.resend = r.checked;
    if (o) S.orderId = o.value;
    if (S.resend && !S.orderId && S.d.resendable.length) S.orderId = String(S.d.resendable[0].id);
  }

  function wire() {
    var b = box();
    b.onclick = function (ev) {
      var t = ev.target.closest('[data-kxe]');
      if (!t) return;
      var act = t.getAttribute('data-kxe');
      if (act === 'close') { close(); return; }
      grab();
      if (act === 'use') { S.email = t.getAttribute('data-v'); var el = document.getElementById('kxeEmail'); if (el) { el.value = S.email; el.focus(); } refresh(); }
      else if (act === 'keep') { S.keep = kxeSuggest(t.getAttribute('data-v')); refresh(); focusEmail(); }
      else if (act === 'putback') { S.email = t.getAttribute('data-e') || S.email; S.phone = t.getAttribute('data-p'); S.error = ''; paint(); }
      else if (act === 'save') { S.error = ''; if (S.resend && !document.getElementById('kxeResend').disabled) { S.step = 'confirm'; paint(); } else { save(false); } }
      else if (act === 'back') { S.step = 'edit'; S.error = ''; paint(); }
      else if (act === 'send') { save(true); }
    };
    b.onchange = function (ev) {
      if (ev.target.id === 'kxeFollow' || ev.target.id === 'kxeResend' || ev.target.id === 'kxeOrder') { grab(); refresh(); }
    };
    /* While typing nothing is redrawn: a hint already showing is taken away
       (it described the old text), and that is all. */
    b.oninput = function (ev) {
      if (ev.target.id !== 'kxeEmail' && ev.target.id !== 'kxePhone') return;
      grab();
      if (S.hintReady && ev.target.id === 'kxeEmail') {
        S.hintReady = false;
        var h = document.getElementById('kxeHintSlot');
        if (h) h.innerHTML = '';
      }
    };
    /* Leaving a field: check what was typed, show the hint if any, and bring
       the order count and checkboxes up to date. Local work, no request. */
    // wire() runs on every repaint: attach this one listener once per box.
    if (b.kxeLeaveWired) return;
    b.kxeLeaveWired = true;
    b.addEventListener('focusout', function (ev) {
      if (ev.target.id !== 'kxeEmail' && ev.target.id !== 'kxePhone') return;
      grab();
      if (ev.target.id === 'kxeEmail') S.hintReady = true;
      refresh();
    });
  }

  function focusEmail() { var el = document.getElementById('kxeEmail'); if (el) el.focus(); }

  async function save(withSend) {
    var btn = document.getElementById(withSend ? 'kxeSend' : 'kxeSave');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    var lines = [];
    var follow = S.follow !== false;
    try {
      var out = await call(S.id + '/contact', { email: S.email, phone: S.phone, orders_follow: follow, was_email: S.d.customer.email });
      S.changed = S.changed || !!out.changed;
      lines.push(out.message || 'Saved.');
      if (out.sign_in_changed) lines.push('Their sign-in address is now ' + out.customer.email + '.');
      S.d = out;
    } catch (e) {
      S.error = why(e); S.step = 'edit'; paint();
      return;
    }

    if (withSend) {
      try {
        var r = await call(S.id + '/resend-confirmation', { order_id: +S.orderId, confirm: true });
        lines.push(r.message + ' A note was added to the order.');
      } catch (e2) {
        S.error = 'Saved, but the confirmation was not sent: ' + why(e2);
      }
    }
    S.lines = lines; S.step = 'done'; S.changed = true;
    paint();
  }

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && S && bg() && bg().classList.contains('on')) close();
  });

  window.kbbCustomerContact = { open: open, suggest: kxeSuggest };
})();
</script>
