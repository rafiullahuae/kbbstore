/* A stand-in for js.stripe.com/v3.
   Stripe's own hosts are blocked by this sandbox's egress proxy, so the real
   script cannot load. This implements the surface the checkout actually uses —
   Stripe(key), elements(), create('card'), mount/on, and confirmCardPayment —
   and records every call so the test can assert what the page asked Stripe to
   do. Its answers are driven by window.__stripeStub, which each scenario sets. */
(function () {
  /* Kept in sessionStorage as well as on window: the success path NAVIGATES to
     the order-received page, which throws the window away, and the assertions
     about what the page asked Stripe to do have to survive that. */
  window.__stripeCalls = [];
  function record(entry) {
    window.__stripeCalls.push(entry);
    try {
      var all = JSON.parse(sessionStorage.getItem('__stripeCalls') || '[]');
      all.push(entry);
      sessionStorage.setItem('__stripeCalls', JSON.stringify(all));
    } catch (e) {}
  }

  function CardElement() {
    this._handlers = {};
    this.mount = function (target) {
      var el = typeof target === 'string' ? document.querySelector(target) : target;
      record({ call: 'mount', id: el && el.id });
      if (!el) return;
      // A visible stand-in for the cross-origin iframe, so the test can see
      // that something was mounted where the real fields would go.
      el.innerHTML = '<span data-stub-card>•••• •••• •••• 4242</span>';
    };
    this.on = function (event, handler) { this._handlers[event] = handler; };
    this.unmount = function () {};
    this.destroy = function () {};
  }

  function Elements() {
    this.create = function (type, options) {
      record({ call: 'create', type: type, options: options });
      return new CardElement();
    };
  }

  window.Stripe = function (key) {
    record({ call: 'Stripe', key: key, args: arguments.length });
    return {
      elements: function () { record({ call: 'elements' }); return new Elements(); },
      confirmCardPayment: function (clientSecret, data) {
        record({
          call: 'confirmCardPayment',
          clientSecret: clientSecret,
          hasCard: !!(data && data.payment_method && data.payment_method.card),
          billing: data && data.payment_method && data.payment_method.billing_details,
          returnUrl: data && data.return_url
        });
        var answer = window.__stripeStub || { paymentIntent: { id: 'pi_preview_1', status: 'succeeded' } };
        // A real 3-D Secure challenge takes time and resolves later; the delay
        // is what lets the test observe the button locked while it is open.
        return new Promise(function (resolve) { setTimeout(function () { resolve(answer); }, answer.__delay || 0); });
      }
    };
  };
})();
