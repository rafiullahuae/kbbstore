{{--
    APPLE PAY / GOOGLE PAY ON-PHONE DIAGNOSTIC (Lane WL2). /checkout/?kbbdiag=1 only.

    The owner, 9 October 2026: wallets enabled in Stripe, domains green, the
    Apple file served, a card in Apple Wallet and in Google Wallet — and no
    button on either phone. Past the server's gates the decision is Stripe's,
    and Stripe gives its reason only in the browser console, which a phone does
    not show. This prints it on the page: what the server decided, whether
    Stripe.js arrived, the options the Express Checkout Element was built with,
    the `ready` event's availablePaymentMethods, any `loaderror`, the browser's
    own wallet APIs, and every console.warn / console.error raised while the
    element boots (wrapped only here, and restored afterwards).

    Rendered by an echo in partials/checkout/express-wallets only when the
    query says so, so every other checkout is byte for byte what it was. No
    request, nothing stored, nothing typed by a shopper. The publishable key is
    shown to its first 12 characters and any key-shaped text in a captured
    message is cut the same way.
--}}
<div id="kbbWalletDiag" dir="ltr" style="margin:0 0 12px;padding:10px 12px;border:2px dashed #c2416b;border-radius:10px;background:#fff7f9;color:#2b2328;font:12px/1.45 ui-monospace,Menlo,Consolas,monospace;text-align:start"><b id="kbbWalletDiagH"></b><pre id="kbbWalletDiagLog" style="margin:6px 0 0;white-space:pre-wrap;word-break:break-word"></pre></div>
<script>
(function () {
  var out = document.getElementById('kbbWalletDiagLog'), head = document.getElementById('kbbWalletDiagH');
  var t0 = Date.now(), lines = [], saved = null;
  head.textContent = 'Wallet diagnostic: waiting for Stripe…';

  function cut(s) {
    return String(s).replace(/\b((?:pk|sk|rk)_(?:live|test)_)[A-Za-z0-9]+/g, function (m) { return m.slice(0, 12) + '…'; });
  }
  function j(o) { try { return JSON.stringify(o); } catch (e) { return String(o); } }
  function log(msg) {
    var line = '+' + (Date.now() - t0) + 'ms ' + cut(msg);
    lines.push(line);
    if (lines.length > 60) { lines.shift(); }
    out.textContent = lines.join('\n');
    try { if (window.kbbDiag) { window.kbbDiag('wallets', cut(msg)); } } catch (e) {}
  }

  var server = @json($kbbWalletDiagServer);
  log('server: ' + j(server));
  if (!server.row_drawn) {
    head.textContent = 'Wallet diagnostic: the shop did not draw the wallet row (see "server" below), so Stripe was never asked.';
  }
  log('page: origin ' + location.origin + ' | protocol ' + location.protocol + ' | top frame ' + (window.top === window) + ' | secure ' + window.isSecureContext);
  log('browser: ' + navigator.userAgent);
  log('PaymentRequest: ' + (typeof window.PaymentRequest === 'function' ? 'present' : 'absent'));
  try {
    if (window.ApplePaySession) {
      log('ApplePaySession: present | canMakePayments() = ' + window.ApplePaySession.canMakePayments());
    } else {
      log('ApplePaySession: absent (normal outside Safari)');
    }
  } catch (e) { log('ApplePaySession: threw ' + (e && e.message)); }

  function release() {
    if (!saved) { return; }
    console.warn = saved.warn;
    console.error = saved.error;
    saved = null;
    log('console capture ended');
  }

  function capture() {
    if (saved) { return; }
    saved = { warn: console.warn, error: console.error };
    ['warn', 'error'].forEach(function (k) {
      var orig = saved[k];
      console[k] = function () {
        try {
          log('console.' + k + ': ' + Array.prototype.map.call(arguments, function (a) {
            return a && a.message ? a.message : (typeof a === 'string' ? a : j(a));
          }).join(' ').slice(0, 600));
        } catch (e) {}
        return orig.apply(console, arguments);
      };
    });
    setTimeout(release, 20000);
  }

  window.kbbWalletDiag = {
    stripe: function (waitedMs) {
      var res = (window.performance && performance.getEntriesByType ? performance.getEntriesByType('resource') : [])
        .filter(function (r) { return String(r.name).indexOf('js.stripe.com') !== -1; })[0];
      log('Stripe.js: ' + typeof window.Stripe + ' | waited ' + waitedMs + 'ms' + (res ? ' | download ' + Math.round(res.duration) + 'ms' : ''));
    },
    boot: function (pk, group, ece) {
      capture();
      log('boot: key ' + String(pk || '').slice(0, 12) + ' | elements ' + j(group) + ' | expressCheckout ' + j(ece));
    },
    ready: function (event) {
      log('ready: availablePaymentMethods = ' + j(event ? event.availablePaymentMethods : undefined));
      setTimeout(release, 5000);
    },
    loadError: function (event) {
      var e = (event && event.error) || {};
      log('loaderror: type ' + e.type + ' | message ' + e.message);
      setTimeout(release, 5000);
    },
    shown: function () {
      head.textContent = 'Wallet diagnostic: Stripe offered a wallet on this browser (button shown).';
    },
    gone: function (row, divider, why) {
      /* NOT removed while diagnosing: the row stays, visible, with whatever
         Stripe drew in it, and this box says why there is no button. */
      head.textContent = 'No wallet offered by Stripe on this browser — ' + why;
      log('no wallet: ' + why);
      if (row) {
        row.removeAttribute('aria-hidden');
        row.removeAttribute('data-kbb-express-pending');
        row.style.cssText = 'display:block;min-height:8px;outline:1px dotted #c2416b';
      }
    },
    log: log
  };
})();
</script>
