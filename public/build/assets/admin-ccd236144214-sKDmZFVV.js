
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
  /* `group` and `mode` are presentation only: they pair each include list with
     its exclude list under one caption, so the six read as three questions
     rather than six screens. `key`, `field` and `kind` are unchanged and are
     what the payload and the lookup endpoint use. */
  var PICKERS = [
    {key:'products',           field:'product_ids',           kind:'product',  label:'Products',
     group:'Products', mode:'only', empty:'Any product qualifies.',
     help:'Only these products are discounted. Anything else in the basket is priced as normal. Leave empty for no restriction.'},
    {key:'excluded_products',  field:'excluded_product_ids',  kind:'product',  label:'Exclude products',
     group:'Products', mode:'never', empty:'Nothing is excluded.',
     help:'These products are never discounted by this code.'},
    {key:'categories',         field:'category_ids',          kind:'category', label:'Product categories',
     group:'Categories', mode:'only', empty:'Any category qualifies.',
     help:'Only products in these categories are discounted.'},
    {key:'excluded_categories',field:'excluded_category_ids', kind:'category', label:'Exclude categories',
     group:'Categories', mode:'never', empty:'Nothing is excluded.',
     help:'Products in these categories are never discounted by this code.'},
    {key:'brands',             field:'brand_ids',             kind:'brand',    label:'Product brands',
     group:'Brands', mode:'only', empty:'Any brand qualifies.',
     help:'Only products from these brands are discounted. Leave empty for no restriction.'},
    {key:'excluded_brands',    field:'excluded_brand_ids',    kind:'brand',    label:'Exclude brands',
     group:'Brands', mode:'never', empty:'Nothing is excluded.',
     help:'Products from these brands are never discounted by this code.'}
  ];

  /* The caption over each pair, in the order the pairs are drawn. Taken from
     PICKERS itself so the two can never drift apart. */
  function pickerGroups(){
    var order = [];
    var byGroup = {};

    PICKERS.forEach(function(p){
      if (!byGroup[p.group]) { byGroup[p.group] = []; order.push(p.group); }
      byGroup[p.group].push(p);
    });

    return order.map(function(name){ return {name: name, items: byGroup[name]}; });
  }

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
    /* ONE entry called "Coupons", not two.
       This screen first shipped as a second item, "Manage Coupons", sitting
       under the read-only "Coupons" usage report. The owner applied the
       package, opened "Coupons" -- the name they were looking for -- got the
       old read-only report, and reasonably concluded the editor had not
       shipped. Two sidebar entries whose names do not tell you which one does
       the thing is a worse failure than the missing screen it replaced.
       So this takes over the "Coupons" name and position, and the usage
       report is reached from a link inside it. */
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Coupons',
      icon:   '<path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6z"/><path d="M12 9v6"/><path d="M9 12h6"/>',
      group:  'Store',
      after:  ['order-new', 'orders']
    });

    /* Kept by hand, deliberately. The helper adds a row; retiring somebody
       else's row is a different job and only this screen needs it. The usage
       report keeps its screen and its route — it just stops being a second
       sidebar entry competing for the same name. */
    var usage = document.querySelector('#nav [data-go="coupon-usage"]');
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
    /* No bar at all for an uncapped code. A full bar would read as "all used
       up" and an empty one as "never used"; neither is what no limit means. */
    if (row.usage_limit === null || row.usage_limit === undefined) {
      return '<span class="ce-num">' + esc(row.usage_count) + '</span> <span class="ce-pill">no limit</span>';
    }

    var limit = Number(row.usage_limit) || 0;
    var pct = limit > 0 ? Math.min(100, Math.round((Number(row.usage_count) / limit) * 100)) : 0;
    var tone = pct >= 100 ? ' is-done' : (pct >= 80 ? ' is-warn' : '');

    return '<div class="ce-meter">'
      + '<span class="ce-num">' + esc(row.usage_count) + ' / ' + esc(row.usage_limit) + '</span>'
      + '<span class="ce-meter-b' + tone + '"><i style="width:' + pct + '%"></i></span>'
      + '</div>';
  }

  function statTile(label, value){
    return '<div class="ce-stat"><span>' + esc(label) + '</span>'
      + '<b class="ce-num">' + esc(value) + '</b></div>';
  }

  function listView(){
    var rows = (list && list.coupons) || [];
    var s = (list && list.summary) || {coupons:0, expired:0, exhausted:0};

    var stats = '<div class="ce-stats">'
      + statTile('Coupons', s.coupons)
      + statTile('Expired', s.expired)
      + statTile('Fully redeemed', s.exhausted)
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
      /* The description rides under its code instead of taking a seventh
         column: one thing to read per row, and the table still fits a laptop
         without the scroller having to be used. */
      body = '<div class="ce-scroll"><table class="ce-table"><thead><tr>'
        + '<th>Code</th><th>Discount</th><th>Used</th><th>Expires</th><th>Status</th><th class="ce-acts"></th>'
        + '</tr></thead><tbody>'
        + rows.map(function(c){
            return '<tr>'
              + '<td><span class="ce-code">' + esc(c.code) + '</span>'
                + (c.description ? '<span class="ce-desc">' + esc(c.description) + '</span>' : '') + '</td>'
              + '<td><span class="ce-num">' + esc(c.amount_display) + '</span>'
                + '<span class="ce-desc">' + esc(c.type_label) + '</span></td>'
              + '<td>' + usageCell(c) + '</td>'
              + '<td>' + (c.expires_at ? esc(c.expires_at) : '—') + '</td>'
              + '<td>' + statusPill(c) + '</td>'
              + '<td class="ce-acts"><div class="ce-rowacts">'
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
      /* Header, hairline, then the controls that filter what is under it. The
         search box used to float between the title and the table, belonging to
         neither. */
      + '<div class="ce-filters ce-toolbar">'
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

  /* A titled band. The heading says what the group decides, the line under it
     says when you would touch it, and only then come the fields -- so the eye
     lands on a decision rather than on the first of eleven boxes.

     Every long explanation this screen used to hang under an individual input
     lives in one of these descriptions or in a note at the foot of the band.
     Help under a box is now one short line, because a paragraph there is a
     wall the eye has to cross before it can find the next label. */
  function section(title, description, body){
    return '<section class="ce-sec">'
      + '<div class="ce-sec-h">'
        + '<div class="ce-sec-t">' + esc(title) + '</div>'
        + (description ? '<div class="ce-sec-d">' + description + '</div>' : '')
      + '</div>'
      + body
      + '</section>';
  }

  /* Fields that belong together, sharing one row and therefore one width.
     .ce-grid is auto-fit, so two children are two equal tracks on a laptop and
     two stacked full-width boxes on a phone -- and a row never mixes a field
     from one subject with a field from another, which is how "Starts on" ended
     up beside "Coupon amount". */
  function row(){
    var cells = [];
    for (var i = 0; i < arguments.length; i++) {
      if (arguments[i]) cells.push(arguments[i]);
    }
    return '<div class="ce-grid">' + cells.join('') + '</div>';
  }

  function field(label, help, control, errKey){
    var err = errKey && fieldErrors[errKey];
    return '<div class="ce-field">'
      + '<label class="ce-label">' + esc(label) + '</label>'
      + control
      + (err ? '<div class="ce-fielderr">' + esc(err) + '</div>' : '')
      + (help ? '<div class="ce-help">' + help + '</div>' : '')
      + '</div>';
  }

  /* A checkbox and its sentence, as one quiet row.

     `bind` is the whole attribute, spelled out at the call site rather than
     assembled from a key here. CouponEditorTest greps this file for the
     literal data-check="free_shipping" to prove the owner can still set it;
     an attribute built at runtime would not be there to find, and the test
     would report a field missing that is in fact on the screen. */
  function option(bind, checked, title, help, id){
    return '<div class="ce-opt">'
      + '<input type="checkbox" id="' + esc(id) + '" ' + bind + (checked ? ' checked' : '') + '>'
      + '<div>'
        + '<label class="ce-opt-t" for="' + esc(id) + '">' + esc(title) + '</label>'
        + '<div class="ce-help">' + help + '</div>'
      + '</div>'
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

    /* WHOLE DIRHAMS ON THE FIXED TYPES ONLY — Lane FA.
       coupons.amount is hundredths of a PERCENT for a percentage code and fils
       for both fixed ones, which is this screen's oldest trap. The policy is
       about money, so it binds the second reading and must leave the first
       alone: 10.5% is a rate and a decimal in it is legitimate. So the keypad
       and the placeholder follow isPercent, exactly as the server's own rule
       does (CouponAdminApiController::validated). */
    var amountBox = '<div class="ce-unit">'
      + '<input class="ce-input" data-bind="amount" inputmode="' + (isPercent ? 'decimal' : 'numeric') + '" value="' + esc(draft.amount) + '" placeholder="' + (isPercent ? '10' : '25') + '">'
      + '<span>' + esc(isPercent ? '%' : meta.currency) + '</span>'
      + '</div>';

    var codeField = '<div class="ce-field">'
      + '<label class="ce-label">Coupon code</label>'
      + '<div class="ce-actions">'
        + '<input class="ce-input" data-bind="code" style="flex:1 1 200px" placeholder="SUMMER20" autocapitalize="characters" autocomplete="off" value="' + esc(draft.code) + '">'
        + '<button class="ce-btn" id="ce-gen" type="button">' + icon('<path d="M4 4v6h6"/><path d="M20 20v-6h-6"/><path d="M20 9A8 8 0 0 0 6 6L4 8"/><path d="M4 15a8 8 0 0 0 14 3l2-2"/>') + 'Generate coupon code</button>'
      + '</div>'
      + (fieldErrors.code ? '<div class="ce-fielderr">' + esc(fieldErrors.code) + '</div>' : '')
      + '<div class="ce-help">Capitals are ignored, and no two coupons may share a code.</div>'
      + '</div>';

    var descField = '<div class="ce-field">'
      + '<label class="ce-label">Description</label>'
      + '<textarea class="ce-area" data-bind="description">' + esc(draft.description) + '</textarea>'
      + '<div class="ce-help">Searchable from the list.</div>'
      + '</div>';

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
    var freeShipping = option('data-check="free_shipping"', draft.free_shipping,
      'Allow free shipping',
      'Delivery is not charged on an order using this code, whatever the rate for the destination would have been.',
      'ce-free-ship');

    return section('The code',
        'What the shopper types at the basket — and a note to yourself about why it exists. '
          + 'The note is never shown to shoppers.',
        codeField + descField)

      + section('The discount',
        'How much comes off, and the window in which the code works. Leave both dates empty for a code '
          + 'that starts straight away and never expires; an expiry in the past is allowed and the list marks the code Expired.',
        row(field('Discount type', typeHelp, typeSelect, 'type'),
            field('Coupon amount',
                  isPercent
                    ? 'Two decimals allowed — 12.5 is twelve and a half percent.'
                    : 'In ' + esc(meta.currency) + ', e.g. 25.00.',
                  amountBox, 'amount'))
        + row(field('Starts on', 'Empty starts it straight away.',
                    textInput('starts_at', 'type="date"'), 'starts_at'),
              field('Expires on', 'Works all through this day, then stops at midnight.',
                    textInput('expires_at', 'type="date"'), 'expires_at'))
        + freeShipping);
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
      /* Empty means "no restriction", not "unfinished" -- the opposite of what
         a blank box usually says -- so each picker spells out what its own
         empty state lets through. */
      : '<div class="ce-pick-e">' + esc(p.empty) + '</div>';

    var found_html = '';
    if (found && found.length) {
      /* `hint` is the endpoint's own "Brand · SKU" line and it stays visible:
         the product lookup matches brand names as well as names and SKUs, so
         the hint is often the only thing on the row explaining why a search
         for a brand returned this product. */
      found_html = '<div class="ce-results">' + found.slice(0, 12).map(function(it){
        return '<button type="button" data-pick="' + esc(p.key) + '" data-id="' + esc(it.id) + '">'
          + esc(it.label) + (it.hint ? ' <em>' + esc(it.hint) + '</em>' : '') + '</button>';
      }).join('') + '</div>';
    } else if (found) {
      found_html = '<div class="ce-pick-e">Nothing found.</div>';
    }

    var placeholder = {
      product: 'Search by name, brand or SKU',
      category: 'Search categories',
      brand: 'Search brands'
    }[p.kind] || 'Search';

    return '<div class="ce-pick">'
      + '<div class="ce-pick-l">' + esc(p.mode === 'only' ? 'Only these' : 'Never these') + '</div>'
      + '<div class="ce-picker">'
        + chips
        + '<input class="ce-input" data-search="' + esc(p.key) + '" data-kind="' + esc(p.kind) + '" type="search" placeholder="' + esc(placeholder) + '" autocomplete="off">'
        + found_html
      + '</div>'
      + '</div>';
  }

  /* One subject -- products, categories or brands -- with its include list
     beside its exclude list under a single quiet caption. */
  function pickerGroupBlock(group){
    return '<div class="ce-pickgrp">'
      + '<div class="ce-pickgrp-t">' + esc(group.name) + '</div>'
      + '<div class="ce-pickpair">' + group.items.map(pickerBlock).join('') + '</div>'
      + '</div>';
  }

  function restrictionsTab(){
    var cur = esc(meta.currency);

    var spend = row(
        field('Minimum spend', 'Empty for no minimum.',
              /* Basket thresholds are money on every coupon type, so both take
                 whole dirhams whatever `type` says — Lane FA. */
              textInput('minimum_amount', 'inputmode="numeric" placeholder="100"'), 'minimum_amount'),
        field('Maximum spend', 'Empty for no maximum.',
              textInput('maximum_amount', 'inputmode="numeric" placeholder="500"'), 'maximum_amount'))
      + option('data-check="exclude_sale_items"', draft.exclude_sale_items,
          'Exclude sale items',
          'Anything already reduced is left out of the discount. If that leaves nothing for the code to apply to, the code is refused.',
          'ce-excl-sale');

    var applies = '<div class="ce-picks">'
      + pickerGroups().map(pickerGroupBlock).join('')
      + '</div>';

    var who = '<div class="ce-field">'
        + '<label class="ce-label">Allowed emails</label>'
        + '<textarea class="ce-area" data-bind="allowed_emails" placeholder="one address per line">' + esc(draft.allowed_emails) + '</textarea>'
        + (fieldErrors.allowed_emails ? '<div class="ce-fielderr">' + esc(fieldErrors.allowed_emails) + '</div>' : '')
        + '<div class="ce-help">One address per line. Empty for no restriction.</div>'
      + '</div>'

      /* A statement, not a control. carts.coupon_id is a single nullable
         foreign key: the basket has only ever held one coupon, so the
         restriction is in force for every code and always has been. A tickable
         box would imply the opposite is possible. It sits at the foot of the
         band as a footnote rather than between two inputs, where it read as a
         warning about the field above it. */
      + '<div class="ce-note is-plain">'
        + '<b>Individual use only</b> — already true of every code, so there is nothing to set.<br>'
        + 'A basket on this shop holds one coupon at a time. Applying a second replaces the first; codes are never stacked.'
      + '</div>';

    return section('Spend limits',
        'How big the basket has to be. Both are checked against the <b>whole</b> subtotal before any discount — '
          + 'not only the items this code applies to. Amounts in ' + cur + '.',
        spend)

      + section('What it applies to',
        'Narrow the discount to certain products, categories or brands. An empty list is no restriction at all, '
          + 'and an exclusion always beats an inclusion.',
        applies)

      + section('Who can use it',
        'By default, anyone holding the code. A list here is checked once an email address is known — a guest at '
          + 'the basket has not given one yet, so the code applies there and is refused at checkout.',
        who);
  }

  function limitsTab(){
    /* The three caps sit in one row because they are one decision asked three
       ways -- how many times, by whom, over how many items -- and the long
       explanation that used to hang under each of them is now the band's
       description and the note beneath it. */
    var caps = row(
        field('Usage limit per coupon',
              'Across everybody. Redeemed <b>' + esc(draft.usage_count) + '</b> times so far.',
              textInput('usage_limit', 'inputmode="numeric" placeholder="no limit"'), 'usage_limit'),
        field('Usage limit per user',
              'Counted by the email on the order, so it bites at checkout.',
              textInput('usage_limit_per_user', 'inputmode="numeric" placeholder="no limit"'), 'usage_limit_per_user'),

        /* Was an explanatory note while there was no column and no code to
           honour one. Both exist now: coupons.limit_usage_to_x_items, applied
           in CouponService::cappedLines(). */
        field('Limit usage to X items',
              'The most items one use may discount.',
              textInput('limit_usage_to_x_items', 'inputmode="numeric" placeholder="no limit"'), 'limit_usage_to_x_items'));

    var note = '<div class="ce-note is-plain">'
        + '<b>Both usage limits are enforced twice.</b><br>'
        + 'Once when the shopper applies the code, and again inside the transaction that writes the order — against a locked row, so two shoppers holding the last use of a code cannot both spend it. '
        + 'Lowering a limit below the count already reached simply stops the code working; it never un-redeems an order. '
        + 'Where the item cap bites, the <b>cheapest</b> matching items are the ones discounted — that costs the shop least and gives the same answer whatever order things went into the basket.'
      + '</div>';

    return section('How often it can be used',
      'Leave a box empty for no limit. Every one of these is a ceiling, not a target: raising one later lets the code carry on, lowering one stops it.',
      caps + note);
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

      /* The owner asked, of the tabbed screens, what the tabs are FOR — a strip
         of three names says nothing about why they are one screen. The most
         important sentence is the first: people hesitate to leave a tab in case
         they lose what they typed, and here they do not. `ectabs-hint` is the
         console's existing caption class, used by the Ecommerce and Delivery
         screens for exactly this. */
      + '<p class="ectabs-hint">Three tabs, one coupon: nothing is saved until you press Save, '
        + 'whichever tab you are looking at. <b>General</b> is the code itself, what it takes off '
        + 'and when it expires; <b>Usage restriction</b> is what it may be spent on &mdash; a minimum '
        + 'spend, particular products or categories; <b>Usage limits</b> is how many times it may be '
        + 'redeemed in total and per customer. A rule set on a tab you are not looking at still applies.</p>'

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
