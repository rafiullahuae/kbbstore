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
         border-radius:var(--r,12px);padding:13px 15px;box-shadow:var(--sh-s,none)}
/* Caption above the figure, not under it. The eye reads the small label first
   and then has something to hang the number on; the other way round it reads
   three loose numbers and has to go back for each one. */
.ce-stat b{display:block;font-size:22px;line-height:1.25;font-variant-numeric:tabular-nums;margin-top:3px}
.ce-stat span{display:block;color:var(--ink-soft,#6b7280);font-size:11px;font-weight:650;
              text-transform:uppercase;letter-spacing:.05em}

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
.ce-table tbody td{padding-top:11px;padding-bottom:11px}
.ce-num{font-variant-numeric:tabular-nums}
.ce-code{font-weight:650;letter-spacing:.02em}

/* The description rides under its code rather than taking a column of its own:
   one thing to read per row, and the table keeps five columns on a laptop. */
.ce-desc{display:block;max-width:34ch;margin-top:2px;font-size:11.5px;font-weight:400;
         letter-spacing:0;color:var(--ink-soft,#6b7280);
         overflow:hidden;text-overflow:ellipsis}

/* How much of the code is spent, at a glance. An uncapped code gets no bar at
   all rather than an empty one: "unlimited" and "untouched" must not look
   alike. */
.ce-meter{display:grid;gap:5px;min-width:0}
.ce-meter-b{display:block;position:relative;height:5px;border-radius:999px;background:rgba(127,127,127,.18);
            min-width:78px;overflow:hidden}
.ce-meter-b i{position:absolute;inset:0 auto 0 0;border-radius:999px;background:#1f7d52}
.ce-meter-b.is-warn i{background:#b7791f}
.ce-meter-b.is-done i{background:#b4443c}

.ce-acts{text-align:right}
.ce-rowacts{display:flex;gap:6px;flex-wrap:nowrap;justify-content:flex-end}

/* The header, then a hairline, then the controls that filter what is under it.
   Before this the search box floated between the title and the table belonging
   to neither. */
.ce-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;min-width:0;
            margin:14px 0 16px;padding-top:14px;border-top:1px solid var(--border,#e6e6e6)}
.ce-toolbar > *{min-width:0}

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
/* Help is secondary and it stays secondary: smaller, lighter, and capped at a
   readable measure so it can never spread into a full-width paragraph that the
   eye has to cross before it finds the next label. Anything longer than a line
   or two belongs in the section description or a note, not under a box. */
.ce-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.45;max-width:62ch}

/* ---- sections ----
   The screen is a form somebody fills in, not a list of everything the system
   knows. Each band says what it decides (the heading), when you would touch it
   (one line under that), and then shows its fields -- with a hairline between
   bands so the groups are visible without a box around each one. */
.ce-sec{display:grid;gap:13px;min-width:0}
.ce-sec > *{min-width:0}
.ce-sec + .ce-sec{margin-top:22px;padding-top:20px;border-top:1px solid var(--border,#e6e6e6)}
.ce-sec-h{display:grid;gap:3px;min-width:0}
.ce-sec-t{font-size:13.5px;font-weight:650}
.ce-sec-d{font-size:12px;line-height:1.5;color:var(--ink-soft,#6b7280);max-width:78ch}

/* A switch and its explanation, as one quiet row. It used to be a bordered
   callout sitting between two form fields, which made an ordinary checkbox
   look like a warning. */
.ce-opt{display:flex;gap:10px;align-items:flex-start;min-width:0;padding:11px 13px;
        border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);
        background:rgba(127,127,127,.03)}
.ce-opt > *{min-width:0}
.ce-opt input{margin:2px 0 0;flex:0 0 auto}
.ce-opt-t{display:block;font-size:12.5px;font-weight:600;cursor:pointer}
.ce-opt .ce-help{margin-top:3px}
.ce-input,.ce-select,.ce-area{width:100%;max-width:100%;min-width:0;box-sizing:border-box;
        padding:8px 10px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);
        border-radius:9px;background:transparent;color:inherit}
.ce-area{min-height:70px;resize:vertical}
.ce-input:disabled,.ce-select:disabled{opacity:.6;cursor:not-allowed}

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


/* ---- the "recorded but not applied" note ---- */
.ce-note{border:1px solid var(--border,#e6e6e6);border-left:3px solid #b7791f;border-radius:9px;
         padding:11px 13px;font-size:12px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.ce-note b{color:inherit}
.ce-note.is-plain{border-left-color:var(--ink-soft,#6b7280)}

/* ---- pickers ----
   Six of these sit on one tab. Drawn as six full-width blocks each with its own
   bold label and its own paragraph of help, they read as six unrelated screens
   stacked on top of each other. They are one decision -- what the code applies
   to -- asked three times, so they are laid out that way: a quiet caption per
   subject (Products, Categories, Brands) and under it the include box beside
   the exclude box, tinted rather than outlined so no one of them is as loud as
   a section heading. */
.ce-picks{display:grid;gap:16px;min-width:0}
.ce-picks > *{min-width:0}
.ce-pickgrp{display:grid;gap:8px;min-width:0}
.ce-pickgrp > *{min-width:0}
.ce-pickgrp-t{font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.06em;
              color:var(--ink-soft,#6b7280)}
.ce-pickpair{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(250px,100%),1fr));
             gap:12px;min-width:0}
.ce-pickpair > *{min-width:0}
.ce-pick{display:grid;gap:7px;min-width:0;padding:11px 12px;
         border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);
         background:rgba(127,127,127,.025)}
.ce-pick > *{min-width:0}
.ce-pick-l{font-size:12px;font-weight:600}
/* The empty state says what empty MEANS, because on a restriction field empty
   is not "unfinished", it is "no restriction" -- the opposite reading. */
.ce-pick-e{font-size:11.5px;color:var(--ink-soft,#6b7280);padding:1px 0}

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
  /* Tighter bands on a phone: the hairline is doing the separating, so the
     space around it does not have to be as generous as on a laptop. */
  .ce-sec + .ce-sec{margin-top:18px;padding-top:16px}
  .ce-picks{gap:14px}
  /* The tab strip is the only thing on this screen that may scroll sideways
     on its own -- three tab names do not fit 390px, and wrapping them onto two
     rows hides which one is selected. */
  .ce-tabs{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
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

    var amountBox = '<div class="ce-unit">'
      + '<input class="ce-input" data-bind="amount" inputmode="decimal" value="' + esc(draft.amount) + '" placeholder="' + (isPercent ? '10' : '25.00') + '">'
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
              textInput('minimum_amount', 'inputmode="decimal" placeholder="100.00"'), 'minimum_amount'),
        field('Maximum spend', 'Empty for no maximum.',
              textInput('maximum_amount', 'inputmode="decimal" placeholder="500.00"'), 'maximum_amount'))
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
