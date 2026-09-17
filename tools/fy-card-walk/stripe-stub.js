/* A stand-in for js.stripe.com/v3 — Lane FY's, and THREE ELEMENTS not one.

   Stripe's own hosts are blocked by this sandbox's egress proxy, so the real
   script cannot load. This implements the surface the checkout actually uses —
   Stripe(key), elements(), create('cardNumber'|'cardExpiry'|'cardCvc'),
   mount/on, and confirmCardPayment — and records every call so the walk can
   assert what the page asked Stripe to do. Its answers come from
   window.__stripeStub, which each scenario sets.

   WHAT IT DRAWS IS DELIBERATELY SHAPED LIKE THE REAL FIELD. The real element
   inserts a cross-origin iframe that sizes itself; the screenshots this walk
   takes are of OUR boxes, so the stand-in has to occupy the box the way the
   iframe does or the pictures would flatter a layout that has never held
   anything. Each type gets its own placeholder text, which is also what makes a
   screenshot show at a glance that the right element went in the right box. */
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

  var FILLER = {
    card:       '•••• •••• •••• 4242',
    cardNumber: '4242 4242 4242 4242',
    cardExpiry: '04 / 28',
    cardCvc:    '123'
  };

  function Element(type) {
    this.type = type;
    this._handlers = {};
    this.mount = function (target) {
      var el = typeof target === 'string' ? document.querySelector(target) : target;
      record({ call: 'mount', type: type, id: el && el.id });
      if (!el) return;
      /* A visible stand-in for the cross-origin iframe. Styled to the metrics a
         real Stripe field reports, so the box round it is measured against
         something the right size. */
      el.innerHTML = '<span data-stub-card style="display:block;font:14px/1.2 inherit;color:#1F2A24">'
        + (FILLER[type] || type) + '</span>';
    };
    this.on = function (event, handler) { this._handlers[event] = handler; };
    this.unmount = function () {};
    this.destroy = function () {};
  }

  function Elements() {
    this.create = function (type, options) {
      record({ call: 'create', type: type, options: options });
      return new Element(type);
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
          // WHICH element was handed over. With individual Elements it must be
          // the cardNumber one; Stripe finds the other two through the shared
          // elements() instance, and handing it the CVC would be a silent
          // mistake that a boolean "was a card passed" could not see.
          cardType: data && data.payment_method && data.payment_method.card && data.payment_method.card.type,
          hasCard: !!(data && data.payment_method && data.payment_method.card),
          billing: data && data.payment_method && data.payment_method.billing_details,
          returnUrl: data && data.return_url
        });
        var answer = window.__stripeStub || { paymentIntent: { id: 'pi_preview_1', status: 'succeeded' } };
        // A real 3-D Secure challenge takes time and resolves later; the delay
        // is what lets the walk observe the button locked while it is open.
        return new Promise(function (resolve) { setTimeout(function () { resolve(answer); }, answer.__delay || 0); });
      }
    };
  };
})();
