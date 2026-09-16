{{--
    Store - New Order.

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go, toast() and the design tokens
    this screen borrows.

    Its own file rather than 400 more lines inside a 7,400-line Blade: several
    lanes edit that file at once, and a screen that lives on its own can be
    reviewed, reverted and merged on its own. The cost is that it cannot reach
    app.blade.php's module-scoped constants -- NAV, TITLES and ADMIN_BASE are
    const, not window properties -- so it appends its own sidebar entry to the
    rendered nav and wraps window.go instead. Both are surfaces the console
    already exposes for exactly this.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them:
    the comment's own terminator ends up inside the raw block, the comment is
    never stripped, and the whole docblock is served to the browser as visible
    text at the top of the screen. That is not hypothetical; it happened here,
    and app.blade.php line 10938 has the same word in a JS comment, harmless
    only because it is already inside a raw block.

    The whole body is wrapped in one so that the {{ }} inside JavaScript
    template literals is not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   New Order screen. Every rule is prefixed mo- and appears nowhere else in the
   console, so this file can never restyle another screen by accident.

   Layout rule that matters most: nothing here is allowed to be wider than its
   column at 390px. The owner reviews on a phone, and a back-office screen you
   have to pan sideways to read is a screen that does not get used. So every
   grid collapses, every table sits in its own scroller, and no element carries
   a min-width larger than the narrowest content box (390 - 48px of .content
   padding = 342px).
--------------------------------------------------------------------------- */
.mo-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:16px;align-items:start}
.mo-col{min-width:0}
.mo-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);
         box-shadow:var(--sh-s);padding:18px;margin-bottom:14px}
.mo-card>h3{font-size:13.5px;font-weight:700;letter-spacing:-.01em;margin-bottom:3px}
.mo-card>h3+p{font-size:11.5px;color:var(--ink-soft);margin-bottom:13px;line-height:1.5}
.mo-step{display:inline-grid;place-items:center;width:19px;height:19px;border-radius:50%;
         background:var(--accent-soft);color:var(--accent-ink);font-size:10.5px;font-weight:700;margin-right:7px}

/* Search box + results. The results list is capped and scrolls rather than
   pushing the totals off the bottom of a phone screen. */
.mo-search{position:relative}
.mo-search input{width:100%;border:1px solid var(--border);border-radius:10px;
                 padding:10px 12px;font-size:13px;font-family:inherit;background:var(--surface);color:var(--ink)}
.mo-search input:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
/* The frame only. The rows inside it are the shared picker's (.kpp-hit, in
   admin/partials/product-picker.blade.php), so the two order screens cannot
   drift into two different-looking lists again. */
.mo-results{margin-top:8px;border:1px solid var(--border);border-radius:10px;overflow:hidden auto;max-height:260px}
.mo-results:empty{display:none}

/* The chosen customer. */
.mo-chosen{display:flex;gap:11px;align-items:flex-start;background:var(--accent-soft);
           border-radius:10px;padding:11px 12px;flex-wrap:wrap}
.mo-chosen div{flex:1;min-width:0}
.mo-chosen b{display:block;font-size:13px;overflow-wrap:anywhere}
.mo-chosen span{display:block;font-size:11.5px;color:var(--accent-ink);opacity:.85;overflow-wrap:anywhere}

/* Two-up field rows that become one-up on a narrow screen. */
.mo-two{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}
.mo-fld{margin-bottom:13px;min-width:0}
.mo-fld label{display:block;font-size:11.5px;font-weight:600;color:var(--ink-2);margin-bottom:5px}
.mo-fld input,.mo-fld select,.mo-fld textarea{
  width:100%;max-width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;
  padding:9px 11px;font-size:13px;font-family:inherit;background:var(--surface);color:var(--ink)}
.mo-fld textarea{resize:vertical;min-height:64px}
.mo-fld input:focus,.mo-fld select:focus,.mo-fld textarea:focus{
  outline:0;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.mo-fld small{display:block;font-size:10.5px;color:var(--ink-soft);margin-top:4px;line-height:1.45}
.mo-fld.bad input,.mo-fld.bad select{border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft)}
.mo-err{display:block;font-size:10.5px;color:var(--red);margin-top:4px;font-weight:600}

/* Line items. A table would be unreadable at 390px, so the lines are rows that
   reflow instead — the quantity and total drop under the name rather than the
   whole screen scrolling sideways. */
.mo-lines{border:1px solid var(--border);border-radius:10px;overflow:hidden}
.mo-line{display:flex;gap:10px;align-items:center;padding:10px 11px;border-bottom:1px solid var(--border-2);flex-wrap:wrap}
.mo-line:last-child{border-bottom:0}
.mo-line-th{width:34px;height:34px;border-radius:8px;flex:none;overflow:hidden;display:grid;
            place-items:center;font-size:10px;font-weight:800;color:#fff}
.mo-line-th img{width:100%;height:100%;object-fit:cover;display:block}
.mo-line-main{flex:1 1 150px;min-width:0}
.mo-line-main b{display:block;font-size:12.5px;font-weight:600;line-height:1.25;overflow-wrap:anywhere}
.mo-line-main span{display:block;font-size:10.5px;color:var(--ink-soft);overflow-wrap:anywhere}
.mo-qty{display:flex;align-items:center;gap:0;border:1px solid var(--border);border-radius:8px;overflow:hidden;flex:none}
.mo-qty button{width:28px;height:30px;font-size:15px;line-height:1;color:var(--ink-soft);background:var(--surface-2)}
.mo-qty button:hover{color:var(--ink);background:var(--surface-3)}
.mo-qty input{width:40px;height:30px;text-align:center;border:0;font-size:12.5px;font-weight:600;
              font-family:inherit;background:var(--surface);color:var(--ink)}
.mo-line-tot{flex:none;font-size:12.5px;font-weight:700;min-width:74px;text-align:right}
.mo-line-x{flex:none;width:26px;height:26px;border-radius:7px;color:var(--ink-faint);display:grid;place-items:center}
.mo-line-x:hover{background:var(--red-soft);color:var(--red)}
.mo-empty{padding:26px 14px;text-align:center;font-size:12px;color:var(--ink-soft)}

/* Totals. */
.mo-tot{display:flex;flex-direction:column;gap:7px;font-size:12.5px}
.mo-tot-r{display:flex;justify-content:space-between;gap:10px;align-items:baseline}
.mo-tot-r span:first-child{color:var(--ink-soft)}
.mo-tot-r b{font-variant-numeric:tabular-nums;white-space:nowrap}
.mo-tot-r.grand{border-top:1px solid var(--border);padding-top:9px;margin-top:3px;font-size:15px;font-weight:700}
.mo-tot-r.disc b{color:var(--accent-ink)}

.mo-note{display:flex;gap:9px;align-items:flex-start;background:var(--surface-2);border:1px solid var(--border-2);
         border-radius:9px;padding:10px 11px;font-size:11.5px;color:var(--ink-2);line-height:1.5}
.mo-note.warn{background:var(--amber-soft);border-color:#f3dcb6;color:#8a5a14}
.mo-note.bad{background:var(--red-soft);border-color:#f2cdc9;color:#b8362d}
.mo-note b{font-weight:700}

.mo-check{display:flex;gap:9px;align-items:flex-start;font-size:12.5px;line-height:1.45;cursor:pointer}
.mo-check input{margin:2px 0 0;width:15px;height:15px;flex:none;accent-color:var(--accent)}
.mo-check.off{opacity:.62;cursor:not-allowed}
.mo-check small{display:block;font-size:10.5px;color:var(--ink-soft);margin-top:3px}

.mo-actions{display:flex;gap:9px;flex-wrap:wrap}
.mo-actions .btn{flex:1 1 auto}

/* The confirmation, after the order is created. */
.mo-done{max-width:620px}
.mo-done-h{display:flex;gap:12px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.mo-done-h .mo-tick{width:38px;height:38px;border-radius:50%;background:var(--accent-soft);
                    color:var(--accent-ink);display:grid;place-items:center;flex:none}
.mo-done-h div{min-width:0}
.mo-done-h b{display:block;font-size:17px;letter-spacing:-.015em}
.mo-done-h span{display:block;font-size:12px;color:var(--ink-soft)}
.mo-done table{width:100%;border-collapse:collapse;font-size:12.5px}
.mo-done th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;
            color:var(--ink-soft);padding:0 8px 7px 0;font-weight:700}
.mo-done td{padding:7px 8px 7px 0;border-top:1px solid var(--border-2);vertical-align:top;overflow-wrap:anywhere}
.mo-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}

@media (max-width:1080px){
  .mo-grid{grid-template-columns:minmax(0,1fr)}
}
@media (max-width:520px){
  .mo-two{grid-template-columns:1fr}
  .mo-card{padding:15px}
  .mo-line-tot{min-width:0;text-align:left}
  .mo-actions .btn{flex:1 1 100%}
}
</style>

<script>
(function(){
  'use strict';

  /* The console builds its sidebar and its router before this runs. Both are
     const inside that script's own scope, so neither can be read from here —
     the entry is appended to the rendered DOM and the router is wrapped. */

  var SCREEN = 'order-new';
  var BASE = window.location.pathname.replace(/\/+$/, '');   // e.g. /admin

  /* ---------------------------------------------------------------- state */
  var V = null;              // vocabularies from /bootstrap
  var customer = null;       // the chosen existing customer
  var newCustomer = false;   // inline-create mode
  var lines = [];            // [{product_id, variant_id, name, sku, qty, unit_price}]
  var priced = null;         // last successful quote
  var errors = {};           // field -> message
  var banner = null;         // {kind, text}
  var created = null;        // the order, once placed
  var busy = false;
  var quoteSeq = 0;          // guards against an older quote landing last

  var form = {
    line1:'', city:'', state:'', country:'', phone:'',
    nc_name:'', nc_email:'', nc_phone:'',
    coupon:'', applied_coupon:'', shipping_override:'', shipping_method_id:null,
    payment_method:'', status:'', channel:'whatsapp',
    customer_note:'', whatsapp_optin:false, send_confirmation:false
  };

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, opts){
    opts = opts || {};
    opts.headers = Object.assign({'Accept':'application/json'}, opts.headers || {});
    if (opts.method && opts.method !== 'GET') {
      opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      opts.headers['Content-Type'] = 'application/json';
    }
    opts.credentials = 'same-origin';
    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  /* Fils -> a display string. Integer arithmetic only: dividing by 100 in
     JavaScript reintroduces exactly the float the server side refuses to use,
     and a total that reads 114 on screen and 115 on the invoice is the bug
     this whole feature has to not have. */
  function aed(fils){
    var n = Math.round(Number(fils) || 0);
    var sign = n < 0 ? '-' : '';
    n = Math.abs(n);
    var whole = String(Math.floor(n / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    var frac = String(n % 100);
    return sign + 'AED ' + whole + '.' + (frac.length < 2 ? '0' + frac : frac);
  }

  function icon(d){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" ' +
           'stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">' + d + '</svg>';
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    if (document.querySelector('[data-go="' + SCREEN + '"]')) return;

    var orders = document.querySelector('#nav [data-go="orders"]');
    if (!orders) return;

    var b = document.createElement('button');
    b.className = 'nav-item';
    b.dataset.go = SCREEN;
    b.innerHTML = icon('<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/>' +
                       '<circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/>')
                + '<span>New Order</span>';
    b.onclick = function(){ window.go(SCREEN); };
    orders.parentNode.insertBefore(b, orders.nextSibling);
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    // The console's own go() would fall through to the dashboard for an id it
    // does not know, so the chrome is set here instead of delegating.
    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Store';
    if (title) title.textContent = 'New Order';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    boot();
    return undefined;
  };

  async function boot(){
    if (V) return;
    try {
      V = await api('/manual-orders/bootstrap');
    } catch (e) {
      banner = {kind:'bad', text:'Could not load the order form. Reload the console and try again.'};
      render();
      return;
    }
    form.country = form.country || V.default_country || 'AE';
    form.status = form.status || V.default_status;
    var enabled = (V.payment_methods || []).filter(function(m){ return m.enabled; });
    form.payment_method = form.payment_method || (enabled[0] || V.payment_methods[0] || {}).id || '';
    render();
  }

  /* ---------------------------------------------------------------- quote */
  function payload(){
    var body = {
      items: lines.map(function(l){
        return {product_id:l.product_id, variant_id:l.variant_id || null, quantity:l.qty};
      }),
      address: {
        line1: form.line1, city: form.city, state: form.state,
        country: form.country, phone: form.phone || null
      },
      coupon_code: form.applied_coupon || null,
      shipping_method_id: form.shipping_method_id,
      shipping_override: form.shipping_override || null,
      payment_method: form.payment_method,
      status: form.status,
      channel: form.channel,
      customer_note: form.customer_note || null,
      whatsapp_optin: !!form.whatsapp_optin,
      send_confirmation: !!form.send_confirmation
    };
    if (newCustomer) {
      body.new_customer = {name:form.nc_name, email:form.nc_email, phone:form.nc_phone || null};
    } else if (customer) {
      body.customer_id = customer.id;
    }
    return body;
  }

  /* Enough filled in that a quote could succeed. Asking the server to price an
     order with no destination just to be told so would flash an error at
     someone who is still typing. */
  function quotable(){
    return lines.length > 0 && form.line1 && form.city && form.state
        && form.country && form.payment_method
        && (customer || (newCustomer && form.nc_name && form.nc_email));
  }

  async function requote(){
    if (!quotable()) { priced = null; renderTotals(); return; }

    var seq = ++quoteSeq;
    try {
      var res = await api('/manual-orders/quote', {method:'POST', body:JSON.stringify(payload())});
      if (seq !== quoteSeq) return;   // a newer quote already answered
      priced = res;
      banner = null;
    } catch (e) {
      if (seq !== quoteSeq) return;
      priced = null;
      banner = (e.body && e.body.error)
        ? {kind:'warn', text:e.body.error}
        : {kind:'warn', text:'Could not price this order.'};
    }
    renderTotals();
    renderBanner();
  }

  /* --------------------------------------------------------------- search */

  /*
   * Both searches on this screen are the shared picker. The customer one is
   * here for the same reason as the product one and not because it was asked
   * for: it sat in the same card, was wiped by the same re-render, and would
   * have been the next thing reported. Customers have no photograph, so the
   * picker draws its usual initials square for them — which is how the console
   * already draws a customer everywhere else.
   */
  var custPicker = null;

  function customerPicker(){
    if (custPicker) return custPicker;
    if (typeof window.kbbProductPicker !== 'function') return null;

    custPicker = window.kbbProductPicker({
      input: '#moCustSearch',
      results: '#moCustResults',
      listId: 'moCustList',
      emptyText: 'No customer matches that. Add them below.',
      search: async function(term){
        var d = await api('/manual-orders/customers?q=' + encodeURIComponent(term));
        return d.customers || [];
      },
      rowMeta: function(c){ return c.email + (c.phone ? ' · ' + c.phone : ''); },
      rowSide: function(c){ return String(c.orders_count); },
      onPick: pickCustomer
    });

    return custPicker;
  }

  function pickCustomer(c){
    if (!c) return;
    customer = c;
    newCustomer = false;
    if (c.address) {
      form.line1 = c.address.line1 || form.line1;
      form.city = c.address.city || form.city;
      form.state = c.address.state || form.state;
      form.country = c.address.country || form.country;
      form.phone = c.address.phone || c.phone || form.phone;
    } else if (c.phone) {
      form.phone = form.phone || c.phone;
    }
    errors = {};
    render();
    requote();
  }

  /*
   * The product type-ahead, which is admin/partials/product-picker.blade.php.
   *
   * It is built ONCE and re-attached after every render(), rather than being
   * rebuilt with the screen. That is the whole fix for "it appears and
   * disappears instantly": this screen paints itself and THEN awaits
   * /manual-orders/bootstrap, so the operator is typing into a form that is
   * about to be replaced wholesale by boot()'s render(). The picker keeps the
   * query, the suggestions and the caret outside the DOM the screen throws
   * away, and attach() puts them back. Images, keyboard selection and Escape
   * all live in that file, shared with the order detail screen.
   */
  var picker = null;

  function productPicker(){
    if (picker) return picker;
    if (typeof window.kbbProductPicker !== 'function') return null;

    picker = window.kbbProductPicker({
      input: '#moProdSearch',
      results: '#moProdResults',
      listId: 'moProdList',
      openDisplay: 'block',
      search: async function(term){
        var d = await api('/manual-orders/products?q=' + encodeURIComponent(term));
        return d.products || [];
      },
      rowMeta: function(p){
        return (p.sku || 'no SKU') + (p.brand ? ' · ' + p.brand : '') +
               (p.stock_status === 'instock' ? '' : ' · out of stock');
      },
      rowSide: function(p){ return aed(p.price_fils); },
      onPick: addLine
    });

    return picker;
  }

  function addLine(p){
    if (!p) return;
    var existing = lines.filter(function(l){ return l.product_id === p.id && !l.variant_id; })[0];
    if (existing) {
      existing.qty = Math.min(99, existing.qty + 1);
    } else {
      lines.push({product_id:p.id, variant_id:null, name:p.name, sku:p.sku,
                  image:p.image || null, unit_price:p.price_fils, qty:1});
    }
    // The picker clears itself before it calls this, so there is no box to
    // empty here — render() rebuilds it and attach() repaints it from state.
    render();
    requote();
  }

  function setQty(i, qty){
    qty = Math.max(1, Math.min(99, parseInt(qty, 10) || 1));
    lines[i].qty = qty;
    renderLines();
    requote();
  }

  /* --------------------------------------------------------------- submit */
  function validate(){
    errors = {};
    if (!customer && !newCustomer) errors.customer = 'Choose a customer, or add a new one.';
    if (newCustomer) {
      if (!form.nc_name.trim()) errors.nc_name = 'A name is needed.';
      if (!form.nc_email.trim()) errors.nc_email = 'An email is needed — it is how the customer record is matched.';
      else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.nc_email.trim())) errors.nc_email = 'That does not look like an email address.';
    }
    if (!lines.length) errors.items = 'Add at least one product.';
    if (!form.line1.trim()) errors.line1 = 'A delivery address is needed.';
    if (!form.city.trim()) errors.city = 'Which city?';
    if (!form.state.trim()) errors.state = 'Which emirate or region?';
    if (!form.country) errors.country = 'Choose a country.';
    if (!form.payment_method) errors.payment_method = 'Choose how they are paying.';
    if (form.shipping_override && !/^\d*(\.\d{1,2})?$/.test(form.shipping_override.trim())) {
      errors.shipping_override = 'A plain amount, at most two decimals.';
    }
    return Object.keys(errors).length === 0;
  }

  async function submit(){
    if (busy) return;
    if (!validate()) {
      banner = {kind:'bad', text:'Some details are still needed — they are marked below.'};
      render();
      var bad = document.querySelector('.mo-fld.bad');
      if (bad) bad.scrollIntoView({block:'center', behavior:'smooth'});
      return;
    }

    busy = true;
    setBusy(true);

    try {
      var res = await api('/manual-orders', {method:'POST', body:JSON.stringify(payload())});
      created = res;
      busy = false;
      render();
      say('Order ' + res.order.order_number + ' created');
    } catch (e) {
      busy = false;
      setBusy(false);
      if (e.status === 422 && e.body && e.body.errors) {
        // Server-side validation. Mapped back onto the fields rather than
        // dumped in a banner, so the operator can see which box to fix.
        errors = {};
        Object.keys(e.body.errors).forEach(function(k){
          errors[k.replace(/^new_customer\./, 'nc_').replace(/^address\./, '')] = e.body.errors[k][0];
        });
        banner = {kind:'bad', text:'The order was not saved — see the marked fields.'};
      } else {
        banner = {kind:'bad', text:(e.body && e.body.error) || 'The order could not be saved.'};
      }
      render();
    }
  }

  /** Disable the Create button while a save is in flight, without re-rendering. */
  function setBusy(on){
    var btn = document.querySelector('#moSubmit');
    if (!btn) return;
    btn.disabled = on;
    btn.innerHTML = icon('<path d="M20 6 9 17l-5-5"/>') + (on ? 'Creating…' : 'Create order');
  }

  function reset(){
    customer = null; newCustomer = false; lines = []; priced = null;
    errors = {}; banner = null; created = null;
    form.line1 = form.city = form.state = form.phone = '';
    form.coupon = form.applied_coupon = form.shipping_override = '';
    form.customer_note = ''; form.shipping_method_id = null;
    form.whatsapp_optin = false; form.send_confirmation = false;
    form.country = (V && V.default_country) || 'AE';
    form.status = (V && V.default_status) || 'processing';
    render();
  }

  /* --------------------------------------------------------------- render */
  function fld(key, label, html, hint){
    var bad = errors[key];
    return '<div class="mo-fld' + (bad ? ' bad' : '') + '" data-fld="' + key + '">' +
      '<label for="mo_' + key + '">' + esc(label) + '</label>' + html +
      (bad ? '<span class="mo-err">' + esc(bad) + '</span>'
           : (hint ? '<small>' + hint + '</small>' : '')) + '</div>';
  }

  function textInput(key, placeholder, value){
    return '<input id="mo_' + key + '" data-bind="' + key + '" type="text" autocomplete="off" ' +
           'placeholder="' + esc(placeholder || '') + '" value="' + esc(value || '') + '">';
  }

  function selectInput(key, options, value){
    return '<select id="mo_' + key + '" data-bind="' + key + '">' + options.map(function(o){
      return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') +
             '>' + esc(o[1]) + '</option>';
    }).join('') + '</select>';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    if (created) { host.innerHTML = doneHTML(); host.scrollTop = 0; wireDone(); return; }

    host.innerHTML =
      '<div class="wrap" id="moScreen" data-mo-state="' + stateName() + '">' +
        '<div class="page-head">' +
          '<h2>New Order</h2>' +
          '<p>For an order agreed on WhatsApp, Instagram or the phone. Prices, delivery and ' +
          'any coupon are worked out by the same code the website checkout uses, so the ' +
          'customer pays what they were quoted.</p>' +
        '</div>' +
        '<div id="moBanner"></div>' +
        '<div class="mo-grid">' +
          '<div class="mo-col">' + customerCard() + itemsCard() + '</div>' +
          '<div class="mo-col">' + deliveryCard() + totalsCard() + '</div>' +
        '</div>' +
      '</div>';

    host.scrollTop = 0;
    renderBanner();
    wire();
  }

  function stateName(){
    if (!lines.length && !customer && !newCustomer) return 'empty';
    if (Object.keys(errors).length) return 'errors';
    if (newCustomer) return 'new-customer';
    if (priced && priced.totals && priced.totals.coupon_code) return 'coupon';
    if (lines.length) return 'lines';
    return 'customer';
  }

  function renderBanner(){
    var host = document.querySelector('#moBanner');
    if (!host) return;
    host.innerHTML = banner
      ? '<div class="mo-note ' + esc(banner.kind) + '" style="margin-bottom:14px">' +
        icon('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>') +
        '<div>' + esc(banner.text) + '</div></div>'
      : '';
  }

  function customerCard(){
    var body;

    if (customer) {
      body =
        '<div class="mo-chosen">' +
          '<div><b>' + esc(customer.name) + '</b>' +
          '<span>' + esc(customer.email) + (customer.phone ? ' · ' + esc(customer.phone) : '') + '</span>' +
          '<span>' + customer.orders_count + ' previous order' + (customer.orders_count === 1 ? '' : 's') +
          ' · ' + aed(customer.total_spent_fils) + ' spent</span></div>' +
          '<button type="button" class="btn ghost sm" id="moCustClear">Change</button>' +
        '</div>';
    } else if (newCustomer) {
      body =
        fld('nc_name', 'Full name', textInput('nc_name', 'Layla Al Mansoori', form.nc_name)) +
        fld('nc_email', 'Email', textInput('nc_email', 'layla@example.ae', form.nc_email),
            'Customers are matched on their email, so an existing shopper keeps one order history instead of two.') +
        fld('nc_phone', 'Phone', textInput('nc_phone', '+971 50 000 0000', form.nc_phone)) +
        '<button type="button" class="btn ghost sm" id="moCustBack">Search existing customers instead</button>';
    } else {
      body =
        '<div class="mo-search">' +
          '<input id="moCustSearch" type="search" autocomplete="off" ' +
          'placeholder="Search by name, email or phone">' +
        '</div>' +
        '<div class="mo-results" id="moCustResults"></div>' +
        (errors.customer ? '<span class="mo-err" style="margin-top:8px">' + esc(errors.customer) + '</span>' : '') +
        '<button type="button" class="btn ghost sm" id="moCustNew" style="margin-top:11px">' +
          icon('<path d="M12 5v14M5 12h14"/>') + 'Add a new customer' +
        '</button>';
    }

    return '<div class="mo-card"><h3><i class="mo-step">1</i>Customer</h3>' +
           '<p>Pick someone from the list, or enter them here — either way the order is attached ' +
           'to a real customer record.</p>' + body + '</div>';
  }

  function itemsCard(){
    return '<div class="mo-card"><h3><i class="mo-step">2</i>Items</h3>' +
      '<p>Search the live catalogue by name or SKU. Unit prices come from the catalogue, ' +
      'including any sale or quantity-bundle rate.</p>' +
      '<div class="mo-search"><input id="moProdSearch" type="search" autocomplete="off" ' +
      'placeholder="Search products by name or SKU"></div>' +
      '<div class="mo-results" id="moProdResults"></div>' +
      (errors.items ? '<span class="mo-err" style="margin:8px 0 0">' + esc(errors.items) + '</span>' : '') +
      '<div style="margin-top:12px">' + linesHTML() + '</div></div>';
  }

  /* The same square the suggestion rows use, so a line the operator has just
     added looks like the row they clicked. `image` is the product's own URL,
     carried on the line since it was picked; a product with none gets the
     console's tinted initials rather than a broken-image icon. */
  function lineThumb(l){
    var label = String(l.name || '?');
    var tint = window.kbbProductPickerTint ? window.kbbProductPickerTint(label) : '#E0567B';
    var mark = label.trim().split(/\s+/).map(function(w){ return w[0] || ''; })
                 .join('').slice(0, 2).toUpperCase() || '?';

    return '<span class="mo-line-th" style="background:' + esc(tint) + '" data-mark="' + esc(mark) + '">' +
      (l.image ? '<img src="' + esc(l.image) + '" alt="" loading="lazy">' : esc(mark)) +
      '</span>';
  }

  function linesHTML(){
    if (!lines.length) {
      return '<div class="mo-lines"><div class="mo-empty">Nothing added yet.</div></div>';
    }
    return '<div class="mo-lines">' + lines.map(function(l, i){
      return '<div class="mo-line">' +
        lineThumb(l) +
        '<span class="mo-line-main"><b>' + esc(l.name) + '</b>' +
        '<span>' + esc(l.sku || 'no SKU') + ' · ' + aed(l.unit_price) + ' each</span></span>' +
        '<span class="mo-qty">' +
          '<button type="button" data-qminus="' + i + '" aria-label="One fewer">&minus;</button>' +
          '<input type="text" inputmode="numeric" data-qty="' + i + '" value="' + l.qty + '" aria-label="Quantity">' +
          '<button type="button" data-qplus="' + i + '" aria-label="One more">+</button>' +
        '</span>' +
        '<span class="mo-line-tot">' + aed(lineTotal(i)) + '</span>' +
        '<button type="button" class="mo-line-x" data-qdrop="' + i + '" aria-label="Remove">' +
          icon('<path d="M18 6 6 18M6 6l12 12"/>') + '</button>' +
      '</div>';
    }).join('') + '</div>';
  }

  /* The server is the authority on a line total once a quote has come back —
     it is the only side that knows the bundle tier. Until then this shows the
     catalogue price so the row is not blank. */
  function lineTotal(i){
    if (priced && priced.lines && priced.lines[i]) return priced.lines[i].line_total;
    return lines[i].unit_price * lines[i].qty;
  }

  /* Line totals move when a quote lands — a bundle tier can change the unit
     price of a line the operator never touched — so the whole block is
     replaced rather than only the row that was clicked. */
  function renderLines(){
    var wrap = document.querySelector('.mo-lines');
    if (!wrap) return;
    var replacement = document.createElement('div');
    replacement.innerHTML = linesHTML();
    wrap.replaceWith(replacement.firstChild);
    wireLines();
  }

  function deliveryCard(){
    var countries = V ? Object.keys(V.countries).map(function(k){ return [k, V.countries[k]]; }) : [];
    var emirates = V ? V.emirates : [];

    return '<div class="mo-card"><h3><i class="mo-step">3</i>Delivery &amp; payment</h3>' +
      '<p>The destination decides which delivery rates apply — the same zones the storefront uses.</p>' +

      fld('line1', 'Address', textInput('line1', 'Flat / villa, street, area', form.line1)) +
      '<div class="mo-two">' +
        fld('city', 'City', textInput('city', 'Dubai', form.city)) +
        fld('state', 'Emirate / region',
            '<input id="mo_state" data-bind="state" type="text" list="moEmirates" autocomplete="off" ' +
            'placeholder="Dubai" value="' + esc(form.state) + '">' +
            '<datalist id="moEmirates">' + emirates.map(function(e){
              return '<option value="' + esc(e) + '"></option>'; }).join('') + '</datalist>') +
      '</div>' +
      '<div class="mo-two">' +
        fld('country', 'Country', selectInput('country', countries, form.country)) +
        fld('phone', 'Phone', textInput('phone', '+971 50 000 0000', form.phone)) +
      '</div>' +

      fld('shipping_override', 'Delivery charge (AED)',
          textInput('shipping_override', 'Leave blank for the normal rate', form.shipping_override),
          'Overrides the zone rate for this order only — for a courier fee agreed in the chat. ' +
          'Read digit by digit, so 1.15 is exactly 115 fils.') +

      '<div class="mo-two">' +
        fld('payment_method', 'Payment method',
            selectInput('payment_method', (V ? V.payment_methods : []).map(function(m){
              return [m.id, m.title + (m.enabled ? '' : ' (off on the storefront)')];
            }), form.payment_method)) +
        fld('status', 'Order status',
            selectInput('status', (V ? V.statuses : []).map(function(s){ return [s, s]; }), form.status)) +
      '</div>' +

      fld('channel', 'Came in via',
          selectInput('channel', (V ? V.channels : []).map(function(c){ return [c, c]; }), form.channel),
          'Stored on the order, so the WhatsApp and Instagram orders can be told apart later.') +

      fld('customer_note', 'Note for the packer',
          '<textarea id="mo_customer_note" data-bind="customer_note" ' +
          'placeholder="Gift wrap, leave with security, ...">' + esc(form.customer_note) + '</textarea>') +
      '</div>';
  }

  function totalsRowsHTML(){
    var t = priced && priced.totals;

    return t
      ? '<div class="mo-tot">' +
          '<div class="mo-tot-r"><span>Subtotal · ' + t.item_count + ' item' + (t.item_count === 1 ? '' : 's') +
          '</span><b>' + aed(t.subtotal_fils) + '</b></div>' +
          (t.discount_fils ? '<div class="mo-tot-r disc"><span>Discount' +
            (t.coupon_code ? ' · ' + esc(t.coupon_code) : '') + '</span><b>&minus;' +
            aed(t.discount_fils) + '</b></div>' : '') +
          '<div class="mo-tot-r"><span>Delivery</span><b>' +
            (t.shipping_fils ? aed(t.shipping_fils) : 'Free') + '</b></div>' +
          (t.fee_fils ? '<div class="mo-tot-r"><span>Cash-on-delivery fee</span><b>' +
            aed(t.fee_fils) + '</b></div>' : '') +
          '<div class="mo-tot-r grand"><span>Total</span><b>' + aed(t.total_fils) + '</b></div>' +
          (t.vat ? '<div class="mo-tot-r" style="font-size:11px"><span>' + esc(t.vat.label) +
            '</span><span style="color:var(--ink-soft)">' + esc(t.vat.formatted) + '</span></div>' : '') +
        '</div>'
      : '<div class="mo-empty" style="padding:18px 0">Add a customer, items and a delivery ' +
        'address and the total appears here.</div>';
  }

  function couponRowHTML(){
    return form.applied_coupon
      ? '<div class="mo-note" style="margin-bottom:11px"><div><b>' + esc(form.applied_coupon) + '</b> applied. ' +
        '<button type="button" id="moCouponClear" style="color:var(--red);font-weight:700;text-decoration:underline">Remove</button></div></div>'
      : '<div class="mo-two" style="grid-template-columns:1fr auto;gap:8px;align-items:end">' +
          '<div class="mo-fld" style="margin-bottom:11px"><label for="mo_coupon">Coupon code</label>' +
          '<input id="mo_coupon" data-bind="coupon" type="text" autocomplete="off" placeholder="WELCOME10" value="' +
          esc(form.coupon) + '"></div>' +
          '<div class="mo-fld" style="margin-bottom:11px">' +
          '<button type="button" class="btn ghost sm" id="moCouponApply">Apply</button></div>' +
        '</div>';
  }

  function totalsCard(){
    var t = priced && priced.totals;
    var emailCap = (V && V.email) || {available:false, reason:''};

    return '<div class="mo-card"><h3><i class="mo-step">4</i>Total</h3>' +
      '<p>Worked out server-side from the catalogue, the delivery zones and the coupon rules.</p>' +
      '<div id="moCouponRow">' + couponRowHTML() + '</div>' +
      '<div id="moTotals">' + totalsRowsHTML() + '</div>' +

      '<div style="margin:14px 0 12px">' +
        '<label class="mo-check' + (emailCap.available ? '' : ' off') + '">' +
          '<input type="checkbox" data-bind="send_confirmation"' +
            (form.send_confirmation ? ' checked' : '') +
            (emailCap.available ? '' : ' disabled') + '>' +
          '<span>Email the customer a confirmation' +
          '<small>' + (emailCap.available
            ? 'Off by default: a phone or DM order has usually been confirmed in the conversation already.'
            : esc(emailCap.reason)) + '</small></span>' +
        '</label>' +
      '</div>' +

      '<div style="margin-bottom:13px">' +
        '<label class="mo-check"><input type="checkbox" data-bind="whatsapp_optin"' +
        (form.whatsapp_optin ? ' checked' : '') + '>' +
        '<span>Happy to receive WhatsApp updates</span></label>' +
      '</div>' +

      '<div class="mo-note warn" style="margin-bottom:13px">' +
        icon('<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>' +
             '<path d="M12 9v4M12 17h.01"/>') +
        '<div><b>Stock is not adjusted.</b> Nothing in this build moves stock when an order is ' +
        'placed — a website order does not either. Adjust it in Catalog → Inventory.</div>' +
      '</div>' +

      '<div class="mo-actions">' +
        '<button type="button" class="btn" id="moSubmit"' + (busy ? ' disabled' : '') + '>' +
          icon('<path d="M20 6 9 17l-5-5"/>') + (busy ? 'Creating…' : 'Create order') + '</button>' +
        '<button type="button" class="btn ghost" id="moReset">Clear</button>' +
      '</div></div>';
  }

  /*
   * The figures only, never the card around them.
   *
   * A quote fires on every change to a line, the destination or the payment
   * method, and it lands asynchronously. Re-rendering the whole card on each
   * one would replace the Create order button underneath whatever the operator
   * is currently doing — press it while a quote is in flight and the click
   * lands on a detached node and silently does nothing. It also throws away
   * focus and the caret in the coupon box mid-keystroke.
   *
   * #moTotals holds no handlers, so swapping its contents needs no rewiring
   * and cannot detach anything the operator is touching.
   */
  function renderTotals(){
    var host = document.querySelector('#moTotals');
    if (!host) return;

    host.innerHTML = totalsRowsHTML();
    renderLines();
  }

  /** The coupon row, which DOES carry handlers, rebound after it is replaced. */
  function renderCouponRow(){
    var host = document.querySelector('#moCouponRow');
    if (!host) return;

    host.innerHTML = couponRowHTML();
    bindInputs(host);
    wireCoupon();
  }

  function doneHTML(){
    var o = created.order;
    var a = o.shipping_address || {};

    return '<div class="wrap" id="moScreen" data-mo-state="created">' +
      '<div class="mo-card mo-done">' +
        '<div class="mo-done-h">' +
          '<span class="mo-tick">' + icon('<path d="M20 6 9 17l-5-5"/>') + '</span>' +
          '<div><b>Order ' + esc(o.order_number) + '</b>' +
          '<span>' + esc(o.status) + ' · ' + esc(o.origin) + ' · ' + aed(o.total_fils) + '</span></div>' +
        '</div>' +

        '<div class="mo-scroll"><table><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Line</th></tr></thead><tbody>' +
        o.items.map(function(i){
          return '<tr><td><b>' + esc(i.name) + '</b><br><span style="color:var(--ink-soft);font-size:11px">' +
            esc(i.sku || '') + '</span></td><td>' + i.quantity + '</td><td>' +
            aed(i.unit_price_fils) + '</td><td><b>' + aed(i.line_total_fils) + '</b></td></tr>';
        }).join('') + '</tbody></table></div>' +

        '<div class="mo-tot" style="margin-top:14px">' +
          '<div class="mo-tot-r"><span>Subtotal</span><b>' + aed(o.subtotal_fils) + '</b></div>' +
          (o.discount_fils ? '<div class="mo-tot-r disc"><span>Discount' +
            (o.coupon_code ? ' · ' + esc(o.coupon_code) : '') + '</span><b>&minus;' +
            aed(o.discount_fils) + '</b></div>' : '') +
          '<div class="mo-tot-r"><span>Delivery' + (o.shipping_method ? ' · ' + esc(o.shipping_method) : '') +
            '</span><b>' + (o.shipping_fils ? aed(o.shipping_fils) : 'Free') + '</b></div>' +
          (o.fee_fils ? '<div class="mo-tot-r"><span>Cash-on-delivery fee</span><b>' +
            aed(o.fee_fils) + '</b></div>' : '') +
          '<div class="mo-tot-r grand"><span>Total</span><b>' + aed(o.total_fils) + '</b></div>' +
        '</div>' +

        '<div class="mo-note" style="margin-top:14px"><div>' +
          '<b>Deliver to</b> ' + esc([a.first_name, a.last_name].filter(Boolean).join(' ')) + ' · ' +
          esc([a.line1, a.city, a.state, a.country].filter(Boolean).join(', ')) +
          (a.phone ? ' · ' + esc(a.phone) : '') + '<br>' +
          '<b>Paying by</b> ' + esc(o.payment_method_title || o.payment_method) +
        '</div></div>' +

        '<div class="mo-note' + (created.email.sent ? '' : ' warn') + '" style="margin-top:10px"><div>' +
          '<b>Confirmation email:</b> ' + esc(created.email.reason) + '</div></div>' +

        '<div class="mo-note warn" style="margin-top:10px"><div>' +
          '<b>Stock:</b> ' + esc(created.stock.reason) + '</div></div>' +

        '<div class="mo-actions" style="margin-top:16px">' +
          '<button type="button" class="btn" id="moAnother">Create another order</button>' +
          '<a class="btn ghost" id="moPacking" href="' +
            BASE.replace(/\/[^\/]*$/, '') + '/admin-api/manual-orders/' + o.id + '/packing-list.csv">' +
            'Packing list (CSV)</a>' +
          '<button type="button" class="btn ghost" id="moToOrders">All orders</button>' +
        '</div>' +
      '</div></div>';
  }

  /* ----------------------------------------------------------------- wire */
  function bindInputs(root){
    (root || document).querySelectorAll('[data-bind]').forEach(function(el){
      var key = el.dataset.bind;
      if (el.type === 'checkbox') {
        el.onchange = function(){ form[key] = el.checked; };
        return;
      }
      el.oninput = function(){ form[key] = el.value; };
      el.onchange = function(){
        form[key] = el.value;
        // These four change the price, so the quote is refreshed on commit
        // rather than on every keystroke.
        if (['country','state','payment_method','shipping_override'].indexOf(key) >= 0) requote();
      };
      if (['line1','city'].indexOf(key) >= 0) {
        el.onblur = function(){ requote(); };
      }
    });
  }

  function wireLines(){
    document.querySelectorAll('[data-qminus]').forEach(function(b){
      b.onclick = function(){ setQty(+b.dataset.qminus, lines[+b.dataset.qminus].qty - 1); };
    });
    document.querySelectorAll('[data-qplus]').forEach(function(b){
      b.onclick = function(){ setQty(+b.dataset.qplus, lines[+b.dataset.qplus].qty + 1); };
    });
    document.querySelectorAll('[data-qty]').forEach(function(i){
      i.onchange = function(){ setQty(+i.dataset.qty, i.value); };
    });
    document.querySelectorAll('[data-qdrop]').forEach(function(b){
      b.onclick = function(){
        lines.splice(+b.dataset.qdrop, 1);
        priced = null;
        render();
        requote();
      };
    });
    // A URL that no longer resolves falls back to the initials rather than
    // drawing the browser's broken-image icon on the order being keyed in.
    document.querySelectorAll('.mo-line-th img').forEach(function(img){
      img.onerror = function(){
        var holder = img.parentNode;
        if (holder) holder.textContent = holder.dataset.mark || '';
      };
    });
  }

  function wireCoupon(){
    var apply = document.querySelector('#moCouponApply');
    if (apply) apply.onclick = function(){
      var code = (form.coupon || '').trim();
      if (!code) { say('Type a coupon code first'); return; }
      form.applied_coupon = code;
      requote().then(function(){
        // The quote is what decides whether the code was any good, so the
        // applied state is only kept when it came back priced.
        if (!priced) form.applied_coupon = '';
        renderCouponRow();
      });
    };

    var clear = document.querySelector('#moCouponClear');
    if (clear) clear.onclick = function(){
      form.applied_coupon = ''; form.coupon = '';
      requote().then(renderCouponRow);
    };
  }

  function wireTotals(){
    bindInputs(document.querySelector('#moScreen'));
    wireCoupon();

    var submitBtn = document.querySelector('#moSubmit');
    if (submitBtn) submitBtn.onclick = submit;

    var resetBtn = document.querySelector('#moReset');
    if (resetBtn) resetBtn.onclick = reset;
  }

  function wire(){
    bindInputs(document.querySelector('#moScreen'));
    wireLines();
    wireTotals();

    // Re-bound, never rebuilt: the query and the open suggestions belong to the
    // pickers, not to this DOM.
    var cp = customerPicker();
    if (cp) cp.attach();

    var p = productPicker();
    if (p) p.attach();

    var nu = document.querySelector('#moCustNew');
    if (nu) nu.onclick = function(){ newCustomer = true; customer = null; errors = {}; render(); };

    var back = document.querySelector('#moCustBack');
    if (back) back.onclick = function(){ newCustomer = false; errors = {}; render(); };

    var clearCust = document.querySelector('#moCustClear');
    if (clearCust) clearCust.onclick = function(){ customer = null; priced = null; render(); };
  }

  function wireDone(){
    var again = document.querySelector('#moAnother');
    if (again) again.onclick = reset;

    var all = document.querySelector('#moToOrders');
    if (all) all.onclick = function(){ window.go('orders'); };
  }

  /* ----------------------------------------------------------------- boot */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
