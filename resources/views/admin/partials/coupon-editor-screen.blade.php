{{--
    Store - Manage Coupons.

    THE SCREEN THE SHOP DID NOT HAVE. Every coupon on this site arrived in the
    WooCommerce import. There was no way to make one, change one or take one
    down: the console's only coupon screen is the read-only usage report next
    door, which says in its own header that editing is a different job. This is
    that job, and it is modelled on the WooCommerce coupon editor because that
    is the screen the owner used for years and asked for by name -- the same
    three sections, in the same order, under the same headings wherever the
    behaviour behind them is genuinely the same.

    WHERE IT REFUSES TO COPY WOOCOMMERCE, AND WHY THAT IS THE POINT. A control
    on this form has to be a rule App\Services\CouponService actually applies.
    A restriction the owner sets and the shop quietly ignores is worse than a
    missing one: it is a discount they believe is fenced and is not, and nothing
    anywhere will tell them. So three of WooCommerce's fields are handled
    differently and every one of them says so on the screen itself:

      * "Allow free shipping" is drawn, disabled, showing whatever the import
        left in coupons.free_shipping -- and labelled as not applied. No coupon
        on this shop grants free delivery; free delivery comes from a shipping
        method or the order-value threshold, and CouponService never reads the
        column. The endpoint ignores the field on the way in, so the box cannot
        be made to lie.
      * "Individual use only" is a statement of fact rather than a checkbox.
        carts.coupon_id is a single nullable foreign key, so a basket has only
        ever held one coupon: the restriction is unconditionally in force for
        every code and a box that could be unticked would be fiction.
      * "Limit usage to X items", the brand pickers and "Allow free shipping"
        were all shown-but-inert for a while, because no column or no pricing
        code existed behind them. All three are live now — see
        CouponService::cappedLines(), its brand clauses in eligibleItems(), and
        the free-shipping block in CartService::totals().

    Its own file rather than more lines inside a 14,000-line Blade: several
    lanes edit that file at once, and a screen that lives on its own can be
    reviewed, reverted and merged on its own. The cost is that it cannot reach
    app.blade.php's module-scoped constants -- NAV, TITLES and ADMIN_BASE are
    const, not window properties -- so it appends its own sidebar entry to the
    rendered nav and wraps window.go instead. Both are surfaces the console
    already exposes for exactly this, and admin/partials/coupon-usage-screen
    .blade.php is the precedent this follows line for line.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Manage Coupons. Every rule is prefixed ce- and appears nowhere else in the
   console, so this file can never restyle another screen by accident.

   THE LAYOUT RULE THIS SCREEN IS BUILT ON. A grid or flex item's default
   min-width is `auto`, which means "at least as wide as my content". A card
   holding a wide table therefore refuses to shrink, its inner overflow-x:auto
   never gets the chance to scroll, and the whole column is stretched to the
   table's natural width -- dragging everything laid out beside it off-screen
   with it. That shipped once already on the Coupons usage screen: measured in
   Chromium at 390px, the content box was 677px and the last three columns were
   unreachable. Every grid and flex container below carries min-width:0 AND
   passes it to its children, and the one wide table sits in a scroller that is
   explicitly allowed to be narrower than the table inside it.

   The console measures sideways overflow on #content, not on
   documentElement -- body is overflow-x:hidden, so the document is never wider
   than the viewport however broken a screen is.
--------------------------------------------------------------------------- */
.ce-wrap{display:grid;gap:16px;min-width:0}
.ce-wrap > *{min-width:0}

.ce-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:16px;min-width:0}
.ce-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;min-width:0}
.ce-head > *{min-width:0}
.ce-title{font-weight:650;font-size:15px}
.ce-sub{color:var(--ink-soft,#6b7280);font-size:12.5px}
.ce-link{border:0;background:none;padding:0;font:inherit;color:var(--accent,#15a85a);
         text-decoration:underline;cursor:pointer}

.ce-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:12px;min-width:0}
.ce-stats > *{min-width:0}
.ce-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:12px 14px}
.ce-stat b{display:block;font-size:20px;line-height:1.3;font-variant-numeric:tabular-nums}
.ce-stat span{color:var(--ink-soft,#6b7280);font-size:12px}

/* ---- buttons ---- */
.ce-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;font:inherit;font-size:13px;
        border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;
        cursor:pointer;white-space:nowrap;min-width:0}
.ce-btn:hover{background:rgba(127,127,127,.08)}
.ce-btn[disabled]{opacity:.45;cursor:default}
.ce-btn.is-primary{background:#1f7d52;border-color:#1f7d52;color:#fff;font-weight:600}
.ce-btn.is-primary:hover{background:#1a6a45}
.ce-btn.is-danger{border-color:#b4443c;color:#b4443c}
.ce-btn.is-danger:hover{background:rgba(180,68,60,.09)}
.ce-btn.is-small{padding:5px 10px;font-size:12px}

.ce-link{background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer;
         text-decoration:underline;opacity:.75}

/* ---- filters ---- */
.ce-filters{display:flex;gap:8px;flex-wrap:wrap;min-width:0}
.ce-filters > *{min-width:0}
.ce-filters input[type=search]{flex:1 1 180px;min-width:0}

/* ---- the list table ----
   overflow-x:auto alone is not a scroller. Without min-width:0 it grows to its
   content and scrolls nothing at all. */
.ce-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;max-width:100%}
.ce-table{width:100%;border-collapse:collapse;font-size:13px}
.ce-table th,.ce-table td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--border,#e6e6e6);
                          white-space:nowrap;vertical-align:middle}
.ce-table th{font-weight:600;color:var(--ink-soft,#6b7280);font-size:11.5px;
             text-transform:uppercase;letter-spacing:.04em}
.ce-table tbody tr:hover{background:rgba(127,127,127,.06)}
.ce-num{font-variant-numeric:tabular-nums}
.ce-code{font-weight:650;letter-spacing:.02em}
.ce-rowacts{display:flex;gap:6px;flex-wrap:nowrap}

/* ---- status pills ---- */
.ce-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11.5px;
         border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280);white-space:nowrap}
.ce-pill.is-active{border-color:#1f7d52;color:#1f7d52}
.ce-pill.is-expired{border-color:#b4443c;color:#b4443c}
.ce-pill.is-exhausted{border-color:#b4443c;color:#b4443c}
.ce-pill.is-scheduled{border-color:#b7791f;color:#b7791f}

/* ---- tabs ---- */
.ce-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--border,#e6e6e6);
         margin:0 0 16px;min-width:0}
.ce-tabs > *{min-width:0}
.ce-tab{padding:9px 13px;font:inherit;font-size:13px;border:0;background:transparent;color:var(--ink-soft,#6b7280);
        cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.ce-tab.on{color:inherit;font-weight:650;border-bottom-color:#1f7d52}

/* ---- form fields ----
   auto-fit with a min() floor rather than a fixed minmax: repeat(auto-fit,
   minmax(240px,1fr)) cannot go below 240px per track, so two fields plus the
   gap demand more than a 390px phone has and the row overflows instead of
   stacking. */
.ce-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:14px;min-width:0}
.ce-grid > *{min-width:0}
.ce-field{display:grid;gap:5px;min-width:0}
.ce-field > *{min-width:0}
.ce-label{font-size:12.5px;font-weight:600}
.ce-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5}
.ce-input,.ce-select,.ce-area{width:100%;max-width:100%;min-width:0;box-sizing:border-box;
        padding:8px 10px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);
        border-radius:9px;background:transparent;color:inherit}
.ce-area{min-height:70px;resize:vertical}
.ce-input:disabled,.ce-select:disabled{opacity:.6;cursor:not-allowed}
.ce-wide{grid-column:1/-1;min-width:0}

/* A select's intrinsic width is its widest OPTION, which sets a floor no media
   query can reach. Pinned so the discount-type box cannot widen the form. */
.ce-select{text-overflow:ellipsis}

/* Amount box with its unit attached. flex, so the unit never wraps under the
   input and the input keeps shrinking. */
.ce-unit{display:flex;align-items:stretch;min-width:0}
.ce-unit > input{min-width:0;flex:1 1 auto;border-top-right-radius:0;border-bottom-right-radius:0}
.ce-unit > span{display:flex;align-items:center;padding:0 11px;font-size:12.5px;white-space:nowrap;
        border:1px solid var(--border,#e6e6e6);border-left:0;border-radius:0 9px 9px 0;
        background:rgba(127,127,127,.07);color:var(--ink-soft,#6b7280)}

.ce-check{display:flex;gap:9px;align-items:flex-start;min-width:0}
.ce-check input{margin:2px 0 0;flex:0 0 auto}
.ce-check > div{min-width:0}

/* ---- the "recorded but not applied" note ---- */
.ce-note{border:1px solid var(--border,#e6e6e6);border-left:3px solid #b7791f;border-radius:9px;
         padding:11px 13px;font-size:12px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.ce-note b{color:inherit}
.ce-note.is-plain{border-left-color:var(--ink-soft,#6b7280)}

/* ---- pickers ---- */
.ce-picker{display:grid;gap:7px;min-width:0}
.ce-picker > *{min-width:0}
.ce-chips{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.ce-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;min-width:0;
         padding:4px 6px 4px 10px;border-radius:999px;font-size:12px;
         border:1px solid var(--border,#e6e6e6);background:rgba(127,127,127,.06)}
.ce-chip > span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.ce-chip.is-missing{border-color:#b4443c;color:#b4443c}
.ce-chip button{border:0;background:none;color:inherit;cursor:pointer;font:inherit;
                line-height:1;padding:2px 4px;opacity:.7}
.ce-results{border:1px solid var(--border,#e6e6e6);border-radius:9px;overflow:hidden;min-width:0}
.ce-results button{display:block;width:100%;text-align:left;padding:7px 10px;font:inherit;font-size:12.5px;
                   border:0;border-bottom:1px solid var(--border,#e6e6e6);background:transparent;
                   color:inherit;cursor:pointer;min-width:0;
                   overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ce-results button:last-child{border-bottom:0}
.ce-results button:hover{background:rgba(127,127,127,.08)}
.ce-results em{color:var(--ink-soft,#6b7280);font-style:normal;font-size:11.5px}

/* ---- messages ---- */
.ce-error{border:1px solid #b4443c;color:#b4443c;border-radius:9px;padding:11px 13px;font-size:12.5px;min-width:0}
.ce-fielderr{color:#b4443c;font-size:11.5px}
.ce-empty{padding:26px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.ce-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;min-width:0}
.ce-actions > *{min-width:0}
.ce-spacer{flex:1 1 auto;min-width:0}
.ce-pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;font-size:12.5px;flex-wrap:wrap;min-width:0}
.ce-pager button{padding:5px 10px;border:1px solid var(--border,#e6e6e6);border-radius:8px;
                 background:transparent;color:inherit;font:inherit;cursor:pointer}
.ce-pager button[disabled]{opacity:.4;cursor:default}

@media (max-width:640px){
  .ce-card{padding:13px}
  .ce-table th,.ce-table td{padding:8px}
  .ce-head{align-items:stretch}
  /* The Add Coupon button goes full width rather than sitting in a cramped
     corner: it is the control this whole screen exists to provide. */
  .ce-head .ce-btn.is-primary{width:100%;justify-content:center}
}
</style>

<script>
(function(){
  'use strict';

  /* The console builds its sidebar and its router before this runs. Both are
     const inside that script's own scope, so neither can be read from here --
     the entry is appended to the rendered DOM and the router is wrapped. */

  var SCREEN = 'coupon-editor';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var list = null;        // the list payload
  var draft = null;       // the coupon being edited, as form values
  var editingId = null;   // null while creating
  var meta = {types:{'percent':'Percentage discount','fixed_cart':'Fixed cart discount','fixed_product':'Fixed product discount'}, currency:'AED'};
  var tab = 'general';
  var query = '';
  var status = 'all';
  var page = 1;
  var banner = null;
  var fieldErrors = {};
  var busy = false;
  var saving = false;
  var seq = 0;            // guards against an older response landing last

  /* Picker search results, keyed by the field they belong to, so two pickers
     on the same tab cannot show each other's matches. */
  var results = {};
  var searchTimers = {};

  /* The four product/category rules, declared once. Every one of them is a
     list of ids in a JSON column that CouponService::eligibleItems() reads,
     and all four behave identically apart from which column they land in. */
  var PICKERS = [
    {key:'products',           field:'product_ids',           kind:'product',  label:'Products',
     help:'Only these products are discounted. Anything else in the basket is priced as normal. Leave empty for no restriction.'},
    {key:'excluded_products',  field:'excluded_product_ids',  kind:'product',  label:'Exclude products',
     help:'These products are never discounted by this code.'},
    {key:'categories',         field:'category_ids',          kind:'category', label:'Product categories',
     help:'Only products in these categories are discounted.'},
    {key:'excluded_categories',field:'excluded_category_ids', kind:'category', label:'Exclude categories',
     help:'Products in these categories are never discounted by this code.'},
    {key:'brands',             field:'brand_ids',             kind:'brand',    label:'Product brands',
     help:'Only products from these brands are discounted. Leave empty for no restriction.'},
    {key:'excluded_brands',    field:'excluded_brand_ids',    kind:'brand',    label:'Exclude brands',
     help:'Products from these brands are never discounted by this code.'}
  ];

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, method, payload){
    var opts = {method: method || 'GET', headers:{'Accept':'application/json'}, credentials:'same-origin'};
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (payload !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(payload);
    }

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

  function icon(d){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" ' +
           'stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:0 0 auto">' + d + '</svg>';
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* Laravel's 422 body is {message, errors:{field:[msg,...]}}. Both halves are
     used: the per-field messages sit under the boxes they belong to, and the
     first of them is repeated at the top, because a message under a field on a
     tab the operator is not looking at is a save that failed silently. */
  function applyValidation(body){
    fieldErrors = {};
    var first = null;

    if (body && body.errors) {
      Object.keys(body.errors).forEach(function(k){
        var msg = Array.isArray(body.errors[k]) ? body.errors[k][0] : String(body.errors[k]);
        fieldErrors[k] = msg;
        if (first === null) first = msg;
      });
    }

    banner = first || (body && body.message) || 'That could not be saved.';
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    if (document.querySelector('[data-go="' + SCREEN + '"]')) return;

    /* ONE entry called "Coupons", not two.
       This screen first shipped as a second item, "Manage Coupons", sitting
       under the read-only "Coupons" usage report. The owner applied the
       package, opened "Coupons" -- the name they were looking for -- got the
       old read-only report, and reasonably concluded the editor had not
       shipped. Two sidebar entries whose names do not tell you which one does
       the thing is a worse failure than the missing screen it replaced.
       So this takes over the "Coupons" name and position, and the usage
       report is reached from a link inside it. */
    var usage = document.querySelector('#nav [data-go="coupon-usage"]');
    var anchor = usage
              || document.querySelector('#nav [data-go="order-new"]')
              || document.querySelector('#nav [data-go="orders"]');
    if (!anchor) return;

    var b = document.createElement('button');
    b.className = 'nav-item';
    b.dataset.go = SCREEN;
    b.innerHTML = icon('<path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6z"/><path d="M12 9v6"/><path d="M9 12h6"/>')
                + '<span>Coupons</span>';
    b.onclick = function(){ window.go(SCREEN); };
    anchor.parentNode.insertBefore(b, anchor.nextSibling);

    // The usage report keeps its screen and its route; it just stops being a
    // second thing in the sidebar competing for the same name.
    if (usage && usage.parentNode) usage.parentNode.removeChild(usage);
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Store';
    if (title) title.textContent = 'Coupons';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    draft = null;
    editingId = null;
    banner = null;
    fieldErrors = {};

    render();
    loadList();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function loadList(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/coupons/manage?page=' + page
        + '&status=' + encodeURIComponent(status)
        + (query ? '&q=' + encodeURIComponent(query) : ''));
      if (mine !== seq) return;
      list = body;
      if (body && body.types) meta = {types: body.types, currency: body.currency || 'AED'};
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      /* A 404 here almost always means the route file shipped without the
         clear_caches migration having run, so the compiled route table does not
         know these paths yet. Said plainly rather than rendering an empty
         table, which reads as "no coupons". */
      banner = e.status === 404
        ? 'The coupon endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load coupons.';
      list = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  /* An empty coupon, with every key the form reads already present.
     Built here rather than left to spring into existence field by field: a
     value that is undefined when the form paints and a string after the first
     keystroke is how a control silently switches from uncontrolled to
     controlled and loses what was typed. */
  function blankDraft(){
    return {
      code:'', type:'percent', amount:'', description:'',
      starts_at:'', expires_at:'',
      minimum_amount:'', maximum_amount:'',
      exclude_sale_items:false,
      free_shipping:false, individual_use:false,
      usage_limit:'', usage_limit_per_user:'',
      allowed_emails:'',
      products:[], excluded_products:[], categories:[], excluded_categories:[],
      brands:[], excluded_brands:[],
      limit_usage_to_x_items:'',
      usage_count:0, redemptions:0, deletable:true, status:'active', imported:false
    };
  }

  function startCreate(){
    editingId = null;
    draft = blankDraft();
    tab = 'general';
    banner = null;
    fieldErrors = {};
    results = {};
    render();
  }

  async function startEdit(id){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/coupons/manage/' + id);
      if (mine !== seq) return;

      var c = body.coupon;
      meta = {types: body.types, currency: body.currency || 'AED'};

      draft = {
        code: c.code,
        type: c.type,
        amount: c.amount_input,
        description: c.description || '',
        starts_at: c.starts_at || '',
        expires_at: c.expires_at || '',
        minimum_amount: c.minimum_amount || '',
        maximum_amount: c.maximum_amount || '',
        exclude_sale_items: !!c.exclude_sale_items,
        free_shipping: !!c.free_shipping,
        individual_use: !!c.individual_use,
        usage_limit: c.usage_limit === null ? '' : String(c.usage_limit),
        usage_limit_per_user: c.usage_limit_per_user === null ? '' : String(c.usage_limit_per_user),
        allowed_emails: (c.allowed_emails || []).join('\n'),
        products: c.products || [],
        excluded_products: c.excluded_products || [],
        categories: c.categories || [],
        excluded_categories: c.excluded_categories || [],
        brands: c.brands || [],
        excluded_brands: c.excluded_brands || [],
        limit_usage_to_x_items: c.limit_usage_to_x_items === null ? '' : String(c.limit_usage_to_x_items),
        usage_count: c.usage_count,
        redemptions: c.redemptions,
        deletable: c.deletable,
        status: c.status,
        imported: c.imported
      };

      editingId = id;
      tab = 'general';
      banner = null;
      fieldErrors = {};
      results = {};
    } catch (e) {
      if (mine !== seq) return;
      banner = 'Could not open that coupon.';
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  /* The draft, as the endpoint's columns.

     The amount goes up as the STRING the owner typed. It is converted to the
     column's units server-side, in integer arithmetic on the digits, because
     coupons.amount holds hundredths of a percent for a percentage code and
     fils for a fixed one -- and a float multiply in the browser turns 1.15%
     into 114 hundredths instead of 115. There is one conversion, it is on the
     server, and it is the one the tests pin. */
  function payload(){
    return {
      code: draft.code,
      type: draft.type,
      amount: draft.amount,
      description: draft.description,
      starts_at: draft.starts_at || null,
      expires_at: draft.expires_at || null,
      minimum_amount: draft.minimum_amount || null,
      maximum_amount: draft.maximum_amount || null,
      exclude_sale_items: !!draft.exclude_sale_items,
      free_shipping: !!draft.free_shipping,
      usage_limit: draft.usage_limit === '' ? null : draft.usage_limit,
      usage_limit_per_user: draft.usage_limit_per_user === '' ? null : draft.usage_limit_per_user,
      limit_usage_to_x_items: draft.limit_usage_to_x_items === '' ? null : draft.limit_usage_to_x_items,
      product_ids: draft.products.map(function(p){ return p.id; }),
      excluded_product_ids: draft.excluded_products.map(function(p){ return p.id; }),
      category_ids: draft.categories.map(function(p){ return p.id; }),
      excluded_category_ids: draft.excluded_categories.map(function(p){ return p.id; }),
      brand_ids: draft.brands.map(function(p){ return p.id; }),
      excluded_brand_ids: draft.excluded_brands.map(function(p){ return p.id; }),
      allowed_emails: draft.allowed_emails.split(/[\s,;]+/).filter(function(s){ return s !== ''; })
    };
  }

  async function save(){
    if (saving) return;
    saving = true;
    banner = null;
    fieldErrors = {};
    render();

    try {
      var body = editingId === null
        ? await api('/coupons/manage', 'POST', payload())
        : await api('/coupons/manage/' + editingId, 'PUT', payload());

      say(editingId === null ? 'Coupon created.' : 'Coupon saved.');
      draft = null;
      editingId = null;
      saving = false;
      page = 1;
      render();
      loadList();
      return;
    } catch (e) {
      if (e.status === 422) {
        applyValidation(e.body);
        /* Jump to the tab holding the first complaint. A message under a box on
           a tab that is not showing is a save that failed with no visible
           reason at all. */
        var where = tabFor(Object.keys(fieldErrors)[0]);
        if (where) tab = where;
      } else if (e.status === 404) {
        banner = 'That coupon no longer exists.';
      } else {
        banner = 'Could not save the coupon.';
      }
    }

    saving = false;
    render();
  }

  /* Which tab a given column's message belongs under. */
  function tabFor(field){
    if (!field) return null;
    if (/^(usage_limit|usage_limit_per_user|limit_usage_to_x_items)$/.test(field)) return 'limits';
    if (/^(minimum_amount|maximum_amount|exclude_sale_items|product_ids|excluded_product_ids|category_ids|excluded_category_ids|brand_ids|excluded_brand_ids|allowed_emails)/.test(field)) return 'restrictions';
    return 'general';
  }

  async function removeCoupon(row){
    var warning = row.usage_count > 0
      ? 'Delete ' + row.code + '? Its counter says it was used ' + row.usage_count
        + ' time' + (row.usage_count === 1 ? '' : 's') + ' before the move from WooCommerce. '
        + 'That figure is not recorded anywhere else and will be lost.'
      : 'Delete ' + row.code + '? This cannot be undone.';

    if (!window.confirm(warning)) return;

    try {
      await api('/coupons/manage/' + row.id, 'DELETE');
      say('Coupon deleted.');
      loadList();
    } catch (e) {
      /* 409 is the deliberate refusal: the code has real redemptions and
         deleting it would cascade them away. The endpoint's sentence explains
         what to do instead, so it is shown rather than replaced. */
      banner = (e.body && e.body.error) || 'Could not delete that coupon.';
      render();
    }
  }

  /* -------------------------------------------------------------- pickers */
  async function pickerSearch(key, kind, term){
    if (term.trim() === '') { results[key] = null; render(); return; }

    try {
      var body = await api('/coupons/manage/lookup?kind=' + kind + '&q=' + encodeURIComponent(term));
      results[key] = body.items || [];
    } catch (e) {
      results[key] = [];
    }
    render();
  }

  function pickerAdd(key, item){
    var have = draft[key] || [];
    for (var i = 0; i < have.length; i++) { if (have[i].id === item.id) return; }
    draft[key] = have.concat([item]);
    results[key] = null;
    render();
  }

  function pickerRemove(key, id){
    draft[key] = (draft[key] || []).filter(function(x){ return x.id !== id; });
    render();
  }

  /* ---------------------------------------------------------- list screen */
  function statusPill(row){
    var label = {active:'Active', expired:'Expired', exhausted:'Fully redeemed', scheduled:'Scheduled'}[row.status] || row.status;
    return '<span class="ce-pill is-' + esc(row.status) + '">' + esc(label) + '</span>';
  }

  function usageCell(row){
    if (row.usage_limit === null || row.usage_limit === undefined) {
      return '<span class="ce-num">' + esc(row.usage_count) + '</span> <span class="ce-pill">no limit</span>';
    }
    return '<span class="ce-num">' + esc(row.usage_count) + ' / ' + esc(row.usage_limit) + '</span>';
  }

  function listView(){
    var rows = (list && list.coupons) || [];
    var s = (list && list.summary) || {coupons:0, expired:0, exhausted:0};

    var stats = '<div class="ce-stats">'
      + '<div class="ce-stat"><b class="ce-num">' + esc(s.coupons) + '</b><span>Coupons</span></div>'
      + '<div class="ce-stat"><b class="ce-num">' + esc(s.expired) + '</b><span>Expired</span></div>'
      + '<div class="ce-stat"><b class="ce-num">' + esc(s.exhausted) + '</b><span>Fully redeemed</span></div>'
      + '</div>';

    var body;

    if (busy && !list) {
      body = '<div class="ce-empty">Loading…</div>';
    } else if (!rows.length) {
      body = '<div class="ce-empty">'
        + (query || status !== 'all' ? 'No coupon matches that.' : 'No coupons yet.')
        + '<div style="margin-top:12px"><button class="ce-btn is-primary" id="ce-add-empty">'
        + icon('<path d="M12 5v14"/><path d="M5 12h14"/>') + 'Add Coupon</button></div></div>';
    } else {
      body = '<div class="ce-scroll"><table class="ce-table"><thead><tr>'
        + '<th>Code</th><th>Type</th><th>Amount</th><th>Used</th><th>Expires</th><th>Status</th><th></th>'
        + '</tr></thead><tbody>'
        + rows.map(function(c){
            return '<tr>'
              + '<td><span class="ce-code">' + esc(c.code) + '</span></td>'
              + '<td>' + esc(c.type_label) + '</td>'
              + '<td class="ce-num">' + esc(c.amount_display) + '</td>'
              + '<td>' + usageCell(c) + '</td>'
              + '<td>' + (c.expires_at ? esc(c.expires_at) : '—') + '</td>'
              + '<td>' + statusPill(c) + '</td>'
              + '<td><div class="ce-rowacts">'
                + '<button class="ce-btn is-small" data-edit="' + esc(c.id) + '">Edit</button>'
                /* A Delete button that is only refused when pressed is worse
                   than one that is not offered: `deletable` is false exactly
                   when redemption rows exist, which is the case destroy()
                   refuses. The title says why. */
                + (c.deletable
                    ? '<button class="ce-btn is-small is-danger" data-del="' + esc(c.id) + '">Delete</button>'
                    : '<button class="ce-btn is-small" disabled title="This code has been redeemed ' + esc(c.redemptions) + ' time(s). Deleting it would erase those redemptions from the usage report. Set an expiry date instead.">Delete</button>')
              + '</div></td>'
              + '</tr>';
          }).join('')
        + '</tbody></table></div>'
        + pager(list, function(n){ page = n; loadList(); });
    }

    return stats
      + '<div class="ce-card">'
      + '<div class="ce-head">'
        + '<div><div class="ce-title">All coupons</div>'
        + '<div class="ce-sub">Create a code, change one, or take one down. '
          + '<button type="button" class="ce-link" id="ce-usage">See who has used them</button>.</div></div>'
        + '<button class="ce-btn is-primary" id="ce-add">'
          + icon('<path d="M12 5v14"/><path d="M5 12h14"/>') + 'Add Coupon</button>'
      + '</div>'
      + '<div class="ce-filters" style="margin:14px 0">'
        + '<input class="ce-input" id="ce-q" type="search" placeholder="Search code or description" value="' + esc(query) + '" autocomplete="off">'
        + '<select class="ce-select" id="ce-status" style="flex:0 1 190px">'
          + ['all','active','scheduled','expired','exhausted'].map(function(v){
              var l = {all:'All coupons', active:'Active', scheduled:'Scheduled', expired:'Expired', exhausted:'Fully redeemed'}[v];
              return '<option value="' + v + '"' + (status === v ? ' selected' : '') + '>' + esc(l) + '</option>';
            }).join('')
        + '</select>'
      + '</div>'
      + body
      + '</div>';
  }

  /* -------------------------------------------------------------- editor */
  function field(label, help, control, errKey){
    var err = errKey && fieldErrors[errKey];
    return '<div class="ce-field">'
      + '<label class="ce-label">' + esc(label) + '</label>'
      + control
      + (err ? '<div class="ce-fielderr">' + esc(err) + '</div>' : '')
      + (help ? '<div class="ce-help">' + help + '</div>' : '')
      + '</div>';
  }

  function textInput(key, attrs){
    return '<input class="ce-input" data-bind="' + key + '" value="' + esc(draft[key]) + '" ' + (attrs || '') + '>';
  }

  function generalTab(){
    var isPercent = draft.type === 'percent';

    var typeSelect = '<select class="ce-select" data-bind="type">'
      + Object.keys(meta.types).map(function(v){
          return '<option value="' + esc(v) + '"' + (draft.type === v ? ' selected' : '') + '>' + esc(meta.types[v]) + '</option>';
        }).join('')
      + '</select>';

    var typeHelp = {
      percent: 'A share of the eligible items in the basket. Capped at 100%.',
      fixed_cart: 'One flat amount off the whole basket. Never more than the eligible items are worth.',
      fixed_product: 'This much off <b>each</b> eligible item, multiplied by how many of it are in the basket, and never more than the item itself costs.'
    }[draft.type] || '';

    var amountBox = '<div class="ce-unit">'
      + '<input class="ce-input" data-bind="amount" inputmode="decimal" value="' + esc(draft.amount) + '" placeholder="' + (isPercent ? '10' : '25.00') + '">'
      + '<span>' + esc(isPercent ? '%' : meta.currency) + '</span>'
      + '</div>';

    return '<div class="ce-grid">'
      + '<div class="ce-field ce-wide">'
        + '<label class="ce-label">Coupon code</label>'
        + '<div class="ce-actions">'
          + '<input class="ce-input" data-bind="code" style="flex:1 1 200px" placeholder="SUMMER20" autocapitalize="characters" autocomplete="off" value="' + esc(draft.code) + '">'
          + '<button class="ce-btn" id="ce-gen" type="button">' + icon('<path d="M4 4v6h6"/><path d="M20 20v-6h-6"/><path d="M20 9A8 8 0 0 0 6 6L4 8"/><path d="M4 15a8 8 0 0 0 14 3l2-2"/>') + 'Generate coupon code</button>'
        + '</div>'
        + (fieldErrors.code ? '<div class="ce-fielderr">' + esc(fieldErrors.code) + '</div>' : '')
        + '<div class="ce-help">What the shopper types at the basket. Capitals are ignored — <b>SUMMER20</b> and <b>summer20</b> are the same code, and two coupons cannot share one.</div>'
      + '</div>'

      + field('Discount type', typeHelp, typeSelect, 'type')
      + field('Coupon amount',
              isPercent
                ? 'Up to two decimal places, so 12.5 means twelve and a half percent.'
                : 'In ' + esc(meta.currency) + ', e.g. 25.00.',
              amountBox, 'amount')

      + field('Starts on',
              'The first day the code works. Leave empty to start straight away.',
              textInput('starts_at', 'type="date"'), 'starts_at')
      + field('Expires on',
              'The last day the code works — it keeps working all through this day and stops at midnight. A date in the past is allowed, and the list marks the code Expired.',
              textInput('expires_at', 'type="date"'), 'expires_at')

      + '<div class="ce-field ce-wide">'
        + '<label class="ce-label">Description</label>'
        + '<textarea class="ce-area" data-bind="description">' + esc(draft.description) + '</textarea>'
        + '<div class="ce-help">A note for you. It is never shown to shoppers, and it is searchable from the list.</div>'
      + '</div>'

      /* Drawn, disabled, and explained. See the file docblock: the column
         was unenforceable for a long time and the box was drawn disabled. It
         is enforced now -- CartService::totals() zeroes the delivery line for
         a coupon carrying it -- so the box is live.

         NOTE FOR ANYONE DISABLING IT AGAIN: a disabled input posts nothing,
         and boolean('free_shipping') reads a missing key as false. While this
         box was disabled, an unconditional write would have cleared the flag
         on every save, silently, on the only rows that carry it. The endpoint
         therefore writes it only when the form actually posts the key. Keep
         that guard. */
      + '<div class="ce-wide ce-note">'
        + '<div class="ce-check">'
          + '<input type="checkbox" data-check="free_shipping"' + (draft.free_shipping ? ' checked' : '') + '>'
          + '<div><b>Allow free shipping</b><br>'
          + 'Delivery is not charged on an order using this code. It overrides the shipping rate for the destination, so the customer pays nothing for delivery however much they spend.</div>'
        + '</div>'
      + '</div>'
      + '</div>';
  }

  function pickerBlock(p){
    var chosen = draft[p.key] || [];
    var found = results[p.key];

    var chips = chosen.length
      ? '<div class="ce-chips">' + chosen.map(function(it){
          return '<span class="ce-chip' + (it.missing ? ' is-missing' : '') + '">'
            + '<span title="' + esc(it.label) + '">' + esc(it.label) + '</span>'
            + '<button type="button" data-drop="' + esc(p.key) + '" data-id="' + esc(it.id) + '" aria-label="Remove">&times;</button>'
            + '</span>';
        }).join('') + '</div>'
      : '<div class="ce-help">Nothing selected — no restriction.</div>';

    var found_html = '';
    if (found && found.length) {
      found_html = '<div class="ce-results">' + found.slice(0, 12).map(function(it){
        return '<button type="button" data-pick="' + esc(p.key) + '" data-id="' + esc(it.id) + '">'
          + esc(it.label) + (it.hint ? ' <em>' + esc(it.hint) + '</em>' : '') + '</button>';
      }).join('') + '</div>';
    } else if (found) {
      found_html = '<div class="ce-help">Nothing found.</div>';
    }

    return '<div class="ce-field ce-wide">'
      + '<label class="ce-label">' + esc(p.label) + '</label>'
      + '<div class="ce-picker">'
        + chips
        + '<input class="ce-input" data-search="' + esc(p.key) + '" data-kind="' + esc(p.kind) + '" type="search" placeholder="Search ' + esc({product:'products', category:'categories', brand:'brands'}[p.kind] || p.kind) + ' to add" autocomplete="off">'
        + found_html
      + '</div>'
      + '<div class="ce-help">' + esc(p.help) + '</div>'
      + '</div>';
  }

  function restrictionsTab(){
    var cur = esc(meta.currency);

    return '<div class="ce-grid">'
      + field('Minimum spend',
              'The basket subtotal must be at least this much. Checked against <b>everything in the basket</b>, before any discount — not just the items this code applies to. In ' + cur + '.',
              textInput('minimum_amount', 'inputmode="decimal" placeholder="100.00"'), 'minimum_amount')
      + field('Maximum spend',
              'The basket subtotal must be no more than this. Same subtotal as above. In ' + cur + '.',
              textInput('maximum_amount', 'inputmode="decimal" placeholder="500.00"'), 'maximum_amount')

      + '<div class="ce-wide">'
        + '<div class="ce-check">'
          + '<input type="checkbox" id="ce-excl-sale" data-check="exclude_sale_items"' + (draft.exclude_sale_items ? ' checked' : '') + '>'
          + '<div><label class="ce-label" for="ce-excl-sale">Exclude sale items</label>'
          + '<div class="ce-help">Anything already reduced is left out of the discount. If that leaves nothing in the basket for the code to apply to, the code is refused with "that code does not apply to anything in your basket".</div></div>'
        + '</div>'
      + '</div>'

      /* A statement, not a control. carts.coupon_id is a single nullable
         foreign key: the basket has only ever held one coupon, so the
         restriction is in force for every code and always has been. A tickable
         box would imply the opposite is possible. */
      + '<div class="ce-wide ce-note is-plain">'
        + '<b>Individual use only</b> — already true of every code, so there is nothing to set.<br>'
        + 'A basket on this shop holds one coupon at a time. Applying a second replaces the first; codes are never stacked.'
      + '</div>'

      + PICKERS.map(pickerBlock).join('')

      + '<div class="ce-field ce-wide">'
        + '<label class="ce-label">Allowed emails</label>'
        + '<textarea class="ce-area" data-bind="allowed_emails" placeholder="one address per line">' + esc(draft.allowed_emails) + '</textarea>'
        + (fieldErrors.allowed_emails ? '<div class="ce-fielderr">' + esc(fieldErrors.allowed_emails) + '</div>' : '')
        + '<div class="ce-help">One address per line. Only these shoppers may use the code — capitals are ignored. <b>It is checked once an email address is known</b>: at the basket a guest has not given one yet, so the code applies there and is refused when they enter a different address at checkout. Leave empty for no restriction.</div>'
      + '</div>'
      + '</div>';
  }

  function limitsTab(){
    return '<div class="ce-grid">'
      + field('Usage limit per coupon',
              'How many times the code may be redeemed in total, across everybody. Leave empty for no limit. The count so far is <b>' + esc(draft.usage_count) + '</b>.',
              textInput('usage_limit', 'inputmode="numeric" placeholder="no limit"'), 'usage_limit')
      + field('Usage limit per user',
              'How many times one shopper may redeem it, counted by the email on the order. Leave empty for no limit. <b>Checked once an email address is known</b>, which at the basket a guest has not given yet — so it bites at checkout.',
              textInput('usage_limit_per_user', 'inputmode="numeric" placeholder="no limit"'), 'usage_limit_per_user')

      /* Was an explanatory note while there was no column and no code to
         honour one. Both exist now: coupons.limit_usage_to_x_items, applied in
         CouponService::cappedLines(). */
      + field('Limit usage to X items',
              'The most items one use of this code may discount. Leave empty for no limit. Where it bites, the <b>cheapest</b> matching items are the ones discounted &mdash; that costs the shop least and gives the same answer whatever order things went into the basket.',
              textInput('limit_usage_to_x_items', 'inputmode="numeric" placeholder="no limit"'), 'limit_usage_to_x_items')

      + '<div class="ce-wide ce-note is-plain">'
        + '<b>Both limits are enforced twice.</b><br>'
        + 'Once when the shopper applies the code, and again inside the transaction that writes the order — against a locked row, so two shoppers holding the last use of a code cannot both spend it. Lowering a limit below the count already reached simply stops the code working; it never un-redeems an order.'
      + '</div>'
      + '</div>';
  }

  function editorView(){
    var tabs = [['general','General'],['restrictions','Usage restriction'],['limits','Usage limits']];

    var heading = editingId === null
      ? 'Add coupon'
      : 'Edit ' + draft.code;

    var sub = editingId === null
      ? 'A new code. Nothing is live until you save it.'
      : 'Redeemed ' + draft.usage_count + ' time' + (draft.usage_count === 1 ? '' : 's') + '.'
        + (draft.deletable ? '' : ' This code has real redemptions, so it cannot be deleted — set an expiry date to retire it.');

    return '<div class="ce-card">'
      + '<button class="ce-link" id="ce-back">Back to all coupons</button>'
      + '<div class="ce-head" style="margin:12px 0 16px">'
        + '<div><div class="ce-title">' + esc(heading) + '</div><div class="ce-sub">' + esc(sub) + '</div></div>'
      + '</div>'

      + (banner ? '<div class="ce-error" style="margin-bottom:14px">' + esc(banner) + '</div>' : '')

      + '<div class="ce-tabs">'
        + tabs.map(function(t){
            return '<button class="ce-tab' + (tab === t[0] ? ' on' : '') + '" data-tab="' + t[0] + '">' + esc(t[1]) + '</button>';
          }).join('')
      + '</div>'

      + (tab === 'general' ? generalTab() : (tab === 'restrictions' ? restrictionsTab() : limitsTab()))

      + '<div class="ce-actions" style="margin-top:18px;border-top:1px solid var(--border,#e6e6e6);padding-top:16px">'
        + '<button class="ce-btn is-primary" id="ce-save"' + (saving ? ' disabled' : '') + '>'
          + (saving ? 'Saving…' : (editingId === null ? 'Create coupon' : 'Save changes')) + '</button>'
        + '<button class="ce-btn" id="ce-cancel">Cancel</button>'
        + '<span class="ce-spacer"></span>'
        + (editingId !== null && draft.deletable
            ? '<button class="ce-btn is-danger" id="ce-delete">Delete coupon</button>'
            : '')
      + '</div>'
      + '</div>';
  }

  function pager(payload, onGo){
    if (!payload || payload.pages <= 1) return '';
    var current = payload.page;
    pager.go = onGo;

    return '<div class="ce-pager" style="margin-top:12px">'
      + '<button id="ce-prev"' + (current <= 1 ? ' disabled' : '') + '>Previous</button>'
      + '<span>Page ' + esc(current) + ' of ' + esc(payload.pages) + '</span>'
      + '<button id="ce-next"' + (current >= payload.pages ? ' disabled' : '') + '>Next</button>'
      + '</div>';
  }

  /* ---------------------------------------------------------------- paint */
  function render(){
    var host = document.querySelector('#view') || document.querySelector('#main') || document.querySelector('.content');
    if (!host) return;

    /* Only paint when this screen is the one showing, so a render triggered by
       a late response cannot overwrite whatever the operator navigated to. */
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="ce-wrap">';

    if (banner && !draft) {
      html += '<div class="ce-card ce-error">' + esc(banner) + '</div>';
    }

    html += draft ? editorView() : listView();
    html += '</div>';

    host.innerHTML = html;
    bind();
  }

  /* Re-binding after every paint, because render() replaces the whole subtree.
     The editor reads its values out of `draft` on input rather than off the DOM
     at save time: a tab switch destroys the boxes on the tab being left, so a
     value only held in the DOM would be gone the moment the operator looked at
     another section. */
  function bind(){
    document.querySelectorAll('[data-bind]').forEach(function(el){
      el.oninput = function(){ draft[el.dataset.bind] = el.value; };
      /* The discount type changes the amount box's unit and help text, so it
         repaints; everything else must not, or the caret jumps to the end on
         every keystroke. */
      if (el.dataset.bind === 'type') {
        el.onchange = function(){ draft.type = el.value; render(); };
      }
    });

    document.querySelectorAll('[data-check]').forEach(function(el){
      el.onchange = function(){ draft[el.dataset.check] = el.checked; };
    });

    document.querySelectorAll('[data-tab]').forEach(function(el){
      el.onclick = function(){ tab = el.dataset.tab; render(); };
    });

    document.querySelectorAll('[data-search]').forEach(function(el){
      el.oninput = function(){
        var key = el.dataset.search, kind = el.dataset.kind, value = el.value;
        clearTimeout(searchTimers[key]);
        searchTimers[key] = setTimeout(function(){ pickerSearch(key, kind, value); }, 220);
      };
    });

    document.querySelectorAll('[data-pick]').forEach(function(el){
      el.onclick = function(){
        var key = el.dataset.pick, id = Number(el.dataset.id);
        var pool = results[key] || [];
        for (var i = 0; i < pool.length; i++) {
          if (pool[i].id === id) { pickerAdd(key, pool[i]); return; }
        }
      };
    });

    document.querySelectorAll('[data-drop]').forEach(function(el){
      el.onclick = function(){ pickerRemove(el.dataset.drop, Number(el.dataset.id)); };
    });

    document.querySelectorAll('[data-edit]').forEach(function(el){
      el.onclick = function(){ startEdit(Number(el.dataset.edit)); };
    });

    document.querySelectorAll('[data-del]').forEach(function(el){
      el.onclick = function(){
        var id = Number(el.dataset.del);
        var rows = (list && list.coupons) || [];
        for (var i = 0; i < rows.length; i++) {
          if (rows[i].id === id) { removeCoupon(rows[i]); return; }
        }
      };
    });

    var add = document.querySelector('#ce-add');
    if (add) add.onclick = startCreate;
    var addEmpty = document.querySelector('#ce-add-empty');
    if (addEmpty) addEmpty.onclick = startCreate;

    // The usage report is no longer in the sidebar, so this is the way to it.
    var usage = document.querySelector('#ce-usage');
    if (usage) usage.onclick = function(){ window.go('coupon-usage'); };

    var gen = document.querySelector('#ce-gen');
    if (gen) gen.onclick = function(){
      draft.code = generateCode();
      render();
    };

    var save_btn = document.querySelector('#ce-save');
    if (save_btn) save_btn.onclick = save;

    var cancel = document.querySelector('#ce-cancel');
    if (cancel) cancel.onclick = function(){ draft = null; editingId = null; banner = null; render(); };

    var back = document.querySelector('#ce-back');
    if (back) back.onclick = function(){ draft = null; editingId = null; banner = null; render(); };

    var del = document.querySelector('#ce-delete');
    if (del) del.onclick = function(){
      removeCoupon({id: editingId, code: draft.code, usage_count: draft.usage_count});
      draft = null;
      editingId = null;
    };

    var q = document.querySelector('#ce-q');
    if (q) {
      var timer = null;
      q.oninput = function(){
        clearTimeout(timer);
        var value = q.value;
        timer = setTimeout(function(){ query = value.trim(); page = 1; loadList(); }, 220);
      };
    }

    var st = document.querySelector('#ce-status');
    if (st) st.onchange = function(){ status = st.value; page = 1; loadList(); };

    var prev = document.querySelector('#ce-prev');
    var next = document.querySelector('#ce-next');
    if (prev && list) prev.onclick = function(){ if (list.page > 1) pager.go(list.page - 1); };
    if (next && list) next.onclick = function(){ if (list.page < list.pages) pager.go(list.page + 1); };
  }

  /* A code the owner does not have to invent.

     No 0/O/1/I/L: the code is read off a screen and typed by a shopper, and
     those four are the pairs that get mistyped. crypto.getRandomValues where
     it exists, Math.random where it does not — this is a convenience, not a
     secret, and the duplicate check on the server is what actually guarantees
     the code is free. */
  function generateCode(){
    var alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    var out = '';
    var bytes = null;

    try {
      if (window.crypto && window.crypto.getRandomValues) {
        bytes = new Uint8Array(8);
        window.crypto.getRandomValues(bytes);
      }
    } catch (e) { bytes = null; }

    for (var i = 0; i < 8; i++) {
      var n = bytes ? bytes[i] : Math.floor(Math.random() * 256);
      out += alphabet.charAt(n % alphabet.length);
    }
    return out;
  }

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
