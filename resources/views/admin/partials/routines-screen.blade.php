{{--
    Catalog - Build my routine. (Lane FM)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go, toast() and the design tokens
    this screen borrows. The exact anchor and replacement are in
    docs/FM-ADMIN-APP-BLOCKS.md; this lane may not edit that file.

    Its own file rather than more lines inside a 19,000-line Blade: several
    lanes edit that file at once, and a screen that lives on its own can be
    reviewed, reverted and merged on its own. The cost is that it cannot reach
    app.blade.php's module-scoped constants -- NAV, TITLES and ADMIN_BASE are
    const, not window properties -- so it appends its own sidebar entry to the
    rendered nav and wraps window.go instead. Both are surfaces the console
    already exposes for exactly this. The shape is deliberately the same as
    admin/partials/coupon-usage-screen.blade.php; that is the precedent.

    WHAT THIS SCREEN IS FOR, AND WHY THE UNTAGGED COUNT IS THE FIRST THING ON
    IT. Every row in this catalogue starts with routine_role NULL. A routine
    engine that quietly skips everything it has no role for looks exactly like a
    routine engine that is working -- four steps drawn as four steps -- so the
    number of products nobody has placed is the headline figure here, the list
    below filters to exactly those rows in one click, and every routine that
    cannot fill a step says which step and why.

    NOTHING HERE IS DRAWN THAT CANNOT BE SAVED. ModuleSchema's header lists the
    controls this console has shipped that saved nothing; every control below
    posts to App\Http\Controllers\Admin\RoutinesApiController and the response
    is read back, and tests/Feature/BuildMyRoutineTest.php posts to each of
    those endpoints and reads the row.

    EVERY CLASS IS PREFIXED rtn- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks. app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name -- [data-open], [data-tg], [data-pp] -- and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.

    THE LAYOUT RULE. Nothing here may be wider than its column at 390px: the
    owner reviews on a phone. The admin sets body{overflow:hidden} and scrolls
    inside #content, so document.scrollWidth can never report an over-wide form
    -- every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
.rtn-wrap{display:grid;gap:16px;min-width:0}
.rtn-wrap > *{min-width:0}
.rtn-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.rtn-head{display:flex;flex-wrap:wrap;gap:10px;align-items:baseline;justify-content:space-between}
.rtn-title{font-weight:650;font-size:15px}
.rtn-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.5;margin-top:3px}
.rtn-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(140px,100%),1fr));gap:12px;min-width:0}
.rtn-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:13px 15px}
.rtn-stat span{display:block;color:var(--ink-soft,#6b7280);font-size:11px;font-weight:650;
               text-transform:uppercase;letter-spacing:.05em}
.rtn-stat b{display:block;font-size:22px;line-height:1.25;font-variant-numeric:tabular-nums;margin-top:3px}
.rtn-stat.is-gap b{color:#b4443c}
.rtn-roles{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.rtn-rolecount{border:1px solid var(--border,#e6e6e6);border-radius:999px;padding:4px 11px;font-size:12px;
               color:var(--ink-soft,#6b7280)}
.rtn-rolecount b{color:inherit;font-variant-numeric:tabular-nums}
.rtn-rolecount.is-gap{border-color:#b4443c;color:#b4443c}
.rtn-filters{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}
.rtn-filters input{flex:1 1 170px;min-width:0;padding:8px 10px;font:inherit;
                   border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.rtn-filters select,.rtn-row select{padding:7px 9px;font:inherit;border:1px solid var(--border,#e6e6e6);
                                    border-radius:9px;background:transparent;color:inherit;max-width:100%}
.rtn-list{display:grid;gap:10px;min-width:0}
.rtn-row{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;display:grid;gap:8px;min-width:0}
.rtn-row.is-untagged{border-left:3px solid #b4443c}
.rtn-row > *{min-width:0}
.rtn-name{font-size:13.5px;font-weight:600;overflow-wrap:anywhere}
.rtn-meta{color:var(--ink-soft,#6b7280);font-size:11.5px;overflow-wrap:anywhere}
.rtn-off{color:#b4443c}
.rtn-chips{display:flex;flex-wrap:wrap;gap:6px}
.rtn-chip{border:1px solid var(--border,#e6e6e6);border-radius:999px;padding:4px 10px;font-size:11.5px;
          background:transparent;color:var(--ink-soft,#6b7280);cursor:pointer;font-family:inherit}
.rtn-chip.on{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.rtn-chip[disabled]{opacity:.45;cursor:default}
.rtn-empty{padding:24px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.rtn-pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;font-size:12.5px;margin-top:10px}
.rtn-pager button,.rtn-btn{padding:6px 12px;border:1px solid var(--border,#e6e6e6);border-radius:8px;
                           background:transparent;color:inherit;font:inherit;cursor:pointer}
.rtn-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.rtn-pager button[disabled]{opacity:.4;cursor:default}
.rtn-fields{display:grid;gap:10px;min-width:0}
.rtn-field{display:grid;gap:4px;min-width:0}
.rtn-field label{font-size:11.5px;font-weight:650;color:var(--ink-soft,#6b7280);
                 text-transform:uppercase;letter-spacing:.04em}
.rtn-field input[type=text],.rtn-field input[type=number],.rtn-field select{padding:8px 10px;font:inherit;
        border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;
        max-width:100%;min-width:0}
.rtn-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5}
.rtn-warn{color:#b4443c;font-size:11.5px;line-height:1.5}
.rtn-banner{border:1px solid #b4443c;color:#b4443c;border-radius:var(--r,12px);padding:12px 14px;font-size:13px}
.rtn-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;font-size:12.5px;
          color:var(--ink-soft,#6b7280);line-height:1.55}
@media (max-width:640px){ .rtn-card{padding:13px} }
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'routines';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;          // GET /admin-api/routines
  var products = null;      // GET /admin-api/routine-products
  var query = '';
  var roleFilter = '';
  var page = 1;
  var banner = null;
  var busy = false;
  var seq = 0;
  var pseq = 0;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body){
    var opts = {headers:{'Accept':'application/json'}, credentials:'same-origin'};
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function icon(d){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" ' +
           'stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">' + d + '</svg>';
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* A 404 from any of these endpoints almost always means the package shipped
     without its clear_caches migration having run, so the compiled route table
     does not know these paths. Said plainly rather than rendering an empty
     screen, which reads as "nothing is tagged". */
  function explain(e, fallback){
    return e && e.status === 404
      ? 'The Build-my-routine endpoints are not registered on this server yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  function roleLabel(key){
    var found = (data && data.roles || []).filter(function(r){ return r.key === key; })[0];
    return found ? found.label : key;
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    if (document.querySelector('[data-go="' + SCREEN + '"]')) return;

    // Inside the Catalog group, because tagging a product with the step it
    // fills is catalogue work and the owner is already in that group when
    // they are thinking about products.
    var anchor = document.querySelector('#nav [data-go="product-editor"]')
              || document.querySelector('#nav [data-go="catalog"]');
    if (!anchor) return;

    var b = document.createElement('button');
    b.className = 'nav-item';
    b.dataset.go = SCREEN;
    b.innerHTML = icon('<path d="M4 6h10"/><path d="M4 12h16"/><path d="M4 18h7"/><circle cx="18" cy="6" r="2"/><circle cx="15" cy="18" r="2"/>')
                + '<span>Build my routine</span>';
    b.onclick = function(){ window.go(SCREEN); };
    anchor.parentNode.insertBefore(b, anchor.nextSibling);
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog';
    if (title) title.textContent = 'Build my routine';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    loadAll();
    loadProducts();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function loadAll(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/routines');
      if (mine !== seq) return;
      data = body;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not load the routines screen.');
      data = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function loadProducts(){
    var mine = ++pseq;

    try {
      var qs = '?page=' + page
             + (query ? '&q=' + encodeURIComponent(query) : '')
             + (roleFilter ? '&role=' + encodeURIComponent(roleFilter) : '');
      var body = await api('/routine-products' + qs);
      if (mine !== pseq) return;
      products = body;
    } catch (e) {
      if (mine !== pseq) return;
      banner = explain(e, 'Could not load the product list.');
      products = null;
    } finally {
      if (mine === pseq) render();
    }
  }

  /* ---------------------------------------------------------------- views */
  function statsView(){
    var c = data.coverage;
    var roles = (data.roles || []).map(function(r){
      var n = (c.by_role || {})[r.key] || 0;
      return '<span class="rtn-rolecount' + (n === 0 ? ' is-gap' : '') + '">'
           + esc(r.label) + ' <b>' + n + '</b></span>';
    }).join('');

    return '<div class="rtn-stats">'
      + '<div class="rtn-stat"><span>On the storefront</span><b>' + c.total + '</b></div>'
      + '<div class="rtn-stat"><span>Given a step</span><b>' + c.tagged + '</b></div>'
      + '<div class="rtn-stat' + (c.untagged > 0 ? ' is-gap' : '') + '"><span>Still untagged</span><b>'
        + c.untagged + '</b></div>'
      + '</div>'
      + '<div class="rtn-card" style="margin-top:12px">'
      + '<div class="rtn-title">What each step can draw from</div>'
      + '<div class="rtn-sub">Counted over products a shopper can actually be shown — published, visible and in stock. '
        + 'A step with nothing behind it is drawn on the storefront as “not stocked yet”, never filled with a guess.</div>'
      + '<div class="rtn-roles">' + roles + '</div>'
      + '</div>';
  }

  function productsView(){
    var opts = '<option value="">Every product</option>'
             + '<option value="none"' + (roleFilter === 'none' ? ' selected' : '') + '>Untagged only</option>'
             + (data.roles || []).map(function(r){
                 return '<option value="' + esc(r.key) + '"' + (roleFilter === r.key ? ' selected' : '') + '>'
                      + esc(r.label) + '</option>';
               }).join('');

    var body;

    if (!products) {
      body = '<div class="rtn-empty">Loading…</div>';
    } else if (!products.products.length) {
      body = '<div class="rtn-empty">' + (query || roleFilter ? 'Nothing matches that filter.' : 'No products yet.') + '</div>';
    } else {
      body = '<div class="rtn-list">' + products.products.map(productRow).join('') + '</div>' + pagerView();
    }

    return '<div class="rtn-card">'
      + '<div class="rtn-head"><div><div class="rtn-title">Which step does each product fill?</div>'
      + '<div class="rtn-sub">A product with no step is never offered in a routine. Concerns are optional: '
        + 'leave them all off and the product suits every routine; switch some on and it is only offered in those.</div></div></div>'
      + '<div class="rtn-filters">'
      + '<input id="rtn-q" type="search" placeholder="Search name or SKU" value="' + esc(query) + '" autocomplete="off">'
      + '<select id="rtn-role">' + opts + '</select>'
      + '</div>'
      + body
      + '</div>';
  }

  function productRow(p){
    var roleOpts = '<option value="">— no step —</option>'
      + (data.roles || []).map(function(r){
          return '<option value="' + esc(r.key) + '"' + (p.role === r.key ? ' selected' : '') + '>'
               + esc(r.label) + '</option>';
        }).join('');

    var chips = (data.concerns || []).map(function(c){
      var on = (p.concerns || []).indexOf(c.key) > -1;
      return '<button type="button" class="rtn-chip' + (on ? ' on' : '') + '"'
           + ' data-rtn-concern="' + esc(c.key) + '" data-rtn-for="' + p.id + '"'
           + (p.role ? '' : ' disabled title="Give this product a step first"') + '>'
           + esc(c.label) + '</button>';
    }).join('');

    return '<div class="rtn-row' + (p.role ? '' : ' is-untagged') + '" data-rtn-row="' + p.id + '">'
      + '<div class="rtn-name">' + esc(p.name) + '</div>'
      + '<div class="rtn-meta">' + esc(p.brand || '—')
        + (p.sku ? ' · ' + esc(p.sku) : '')
        + (p.live ? '' : ' · <span class="rtn-off">not on the storefront</span>') + '</div>'
      + '<select data-rtn-rolefor="' + p.id + '">' + roleOpts + '</select>'
      + '<div class="rtn-chips">' + chips + '</div>'
      + '</div>';
  }

  function pagerView(){
    var pages = Math.max(1, Math.ceil(products.total / products.per_page));

    return '<div class="rtn-pager">'
      + '<span>' + products.total + ' product' + (products.total === 1 ? '' : 's') + '</span>'
      + '<button id="rtn-prev"' + (products.page <= 1 ? ' disabled' : '') + '>Previous</button>'
      + '<span>' + products.page + ' / ' + pages + '</span>'
      + '<button id="rtn-next"' + (products.page >= pages ? ' disabled' : '') + '>Next</button>'
      + '</div>';
  }

  function settingsView(){
    var fields = [];

    (data.tabs || []).forEach(function(tab){
      (tab.fields || []).forEach(function(f){ fields.push(f); });
    });

    var html = fields.map(function(f){
      var control;

      if (f.key === 'offer_coupon') {
        // A dropdown of codes that really exist rather than a text box. A typed
        // code that does not exist shows NOTHING on the storefront and nothing
        // anywhere says why, which is the silent-configuration-error shape this
        // project has paid for more than once.
        var chosen = String(f.value == null ? '' : f.value);
        var known = (data.coupons || []).some(function(c){ return c.code === chosen; });

        control = '<select data-rtn-set="' + esc(f.key) + '">'
          + '<option value="">— no offer strip —</option>'
          + (chosen && !known ? '<option value="' + esc(chosen) + '" selected>' + esc(chosen) + ' (no such code)</option>' : '')
          + (data.coupons || []).map(function(c){
              return '<option value="' + esc(c.code) + '"' + (c.code === chosen ? ' selected' : '') + '>'
                   + esc(c.code) + '</option>';
            }).join('')
          + '</select>';
      } else if (f.type === 'select') {
        control = '<select data-rtn-set="' + esc(f.key) + '">'
          + Object.keys(f.options || {}).map(function(k){
              return '<option value="' + esc(k) + '"' + (String(f.value) === k ? ' selected' : '') + '>'
                   + esc(f.options[k]) + '</option>';
            }).join('')
          + '</select>';
      } else {
        control = '<input type="text" data-rtn-set="' + esc(f.key) + '" value="' + esc(f.value) + '">';
      }

      return '<div class="rtn-field"><label>' + esc(f.label) + '</label>' + control
           + (f.help ? '<div class="rtn-help">' + esc(f.help) + '</div>' : '') + '</div>';
    }).join('');

    return '<div class="rtn-card">'
      + '<div class="rtn-head"><div><div class="rtn-title">Settings</div>'
      + '<div class="rtn-sub">The two questions the plan left open. Both are answered here rather than in code, '
        + 'so either answer is a dropdown and not a rebuild.</div></div></div>'
      + '<div class="rtn-fields" style="margin-top:12px">' + html + '</div>'
      + '<div style="margin-top:12px"><button class="rtn-btn is-primary" id="rtn-save-settings">Save settings</button></div>'
      + (data.module_on ? '' : '<div class="rtn-note" style="margin-top:12px">'
          + 'The module is switched <b>off</b>, so /routines is a 404 on the storefront and nothing about the shop '
          + 'changes. Turn it on under Store → Modules → Build my routine when you have tagged enough products.'
          + '</div>')
      + '</div>';
  }

  function routinesView(){
    var custom = data.settings && data.settings.steps_mode === 'custom';

    var rows = (data.routines || []).map(function(r){
      var stepChips = (data.roles || []).map(function(role){
        var on = (r.own_steps || r.steps || []).indexOf(role.key) > -1;
        return '<button type="button" class="rtn-chip' + (on ? ' on' : '') + '"'
             + ' data-rtn-step="' + esc(role.key) + '" data-rtn-of="' + esc(r.concern) + '"'
             + (custom ? '' : ' disabled') + '>' + esc(role.label) + '</button>';
      }).join('');

      var gaps = (r.empty || []).map(roleLabel);

      return '<div class="rtn-row" data-rtn-routine="' + esc(r.concern) + '">'
        + '<div class="rtn-name">' + esc(r.label) + '</div>'
        + '<div class="rtn-fields">'
        + '<div class="rtn-field"><label>Heading on the page</label>'
          + '<input type="text" data-rtn-title="' + esc(r.concern) + '" value="' + esc(r.title || '') + '" placeholder="' + esc(r.label) + ' routine"></div>'
        + '<div class="rtn-field"><label>Sentence under it</label>'
          + '<input type="text" data-rtn-blurb="' + esc(r.concern) + '" value="' + esc(r.blurb || '') + '" placeholder="Leave empty for the default"></div>'
        + '<div class="rtn-field"><label>Steps' + (custom ? '' : ' (fixed — change the setting above to edit)') + '</label>'
          + '<div class="rtn-chips">' + stepChips + '</div></div>'
        + '<div class="rtn-field"><label>Offer strip coupon for this routine</label>'
          + '<select data-rtn-coupon="' + esc(r.concern) + '">'
          + '<option value="">— none —</option>'
          + (data.coupons || []).map(function(c){
              return '<option value="' + esc(c.code) + '"' + (c.code === r.coupon_code ? ' selected' : '') + '>'
                   + esc(c.code) + '</option>';
            }).join('')
          + '</select></div>'
        + '<div class="rtn-field"><label>Order on the list page</label>'
          + '<input type="number" data-rtn-pos="' + esc(r.concern) + '" value="' + (r.position || 0) + '" step="1"></div>'
        + '<div class="rtn-field"><label>Shown to shoppers</label>'
          + '<select data-rtn-on="' + esc(r.concern) + '">'
          + '<option value="1"' + (r.is_enabled ? ' selected' : '') + '>Yes</option>'
          + '<option value="0"' + (r.is_enabled ? '' : ' selected') + '>No — hide this routine</option>'
          + '</select></div>'
        + '</div>'
        + (gaps.length
            ? '<div class="rtn-warn">Nothing stocked for: ' + esc(gaps.join(', '))
              + '. Those steps are shown empty on the storefront.</div>'
            : '')
        + '<div><button class="rtn-btn" data-rtn-save="' + esc(r.concern) + '">Save this routine</button></div>'
        + '</div>';
    }).join('');

    return '<div class="rtn-card">'
      + '<div class="rtn-head"><div><div class="rtn-title">The routines</div>'
      + '<div class="rtn-sub">One per concern, and the concerns are the same eight the skin quiz asks about. '
        + 'Everything here is optional — a routine you have never touched uses the built-in heading and the five fixed steps.</div></div></div>'
      + '<div class="rtn-list" style="margin-top:12px">' + rows + '</div>'
      + '</div>';
  }

  function render(){
    var host = document.querySelector('#view') || document.querySelector('#main') || document.querySelector('.content');
    if (!host) return;

    // Only paint when this screen is the one showing, so a render triggered by
    // a late response cannot overwrite whatever the operator navigated to.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="rtn-wrap">';

    if (banner) html += '<div class="rtn-banner">' + esc(banner) + '</div>';

    if (!data) {
      html += '<div class="rtn-card"><div class="rtn-empty">' + (busy ? 'Loading…' : 'Nothing to show.') + '</div></div>';
    } else {
      html += statsView() + productsView() + routinesView() + settingsView();
    }

    host.innerHTML = html + '</div>';
    bind();
  }

  /* ---------------------------------------------------------------- saves */
  async function tag(id, payload, message){
    try {
      var body = await api('/routine-products/' + id, payload);
      if (data && body.coverage) data.coverage = body.coverage;

      // The row is updated in place from the RESPONSE, not from what was
      // clicked: the server cleans the concern list and normalises the role,
      // and a screen that painted its own guess would drift from the column.
      (products && products.products || []).forEach(function(p){
        if (p.id === body.product.id) { p.role = body.product.role; p.concerns = body.product.concerns; }
      });

      say(message);
      render();
    } catch (e) {
      say(explain(e, 'Could not save that.'));
    }
  }

  function bind(){
    var q = document.querySelector('#rtn-q');
    if (q) {
      q.onchange = function(){ query = q.value.trim(); page = 1; loadProducts(); };
      q.onkeydown = function(ev){ if (ev.key === 'Enter') { query = q.value.trim(); page = 1; loadProducts(); } };
    }

    var rf = document.querySelector('#rtn-role');
    if (rf) rf.onchange = function(){ roleFilter = rf.value; page = 1; loadProducts(); };

    var prev = document.querySelector('#rtn-prev');
    if (prev) prev.onclick = function(){ if (page > 1) { page--; loadProducts(); } };

    var next = document.querySelector('#rtn-next');
    if (next) next.onclick = function(){ page++; loadProducts(); };

    document.querySelectorAll('[data-rtn-rolefor]').forEach(function(sel){
      sel.onchange = function(){
        tag(sel.dataset.rtnRolefor, {role: sel.value || null},
            sel.value ? 'Step set to ' + roleLabel(sel.value) + '.' : 'Step cleared.');
      };
    });

    document.querySelectorAll('[data-rtn-concern]').forEach(function(btn){
      btn.onclick = function(){
        var id = Number(btn.dataset.rtnFor);
        var row = (products && products.products || []).filter(function(p){ return p.id === id; })[0];
        if (!row) return;

        var next = (row.concerns || []).slice();
        var at = next.indexOf(btn.dataset.rtnConcern);
        if (at > -1) next.splice(at, 1); else next.push(btn.dataset.rtnConcern);

        tag(id, {concerns: next}, next.length ? 'Concerns saved.' : 'Concerns cleared — suits every routine.');
      };
    });

    document.querySelectorAll('[data-rtn-step]').forEach(function(btn){
      btn.onclick = function(){ btn.classList.toggle('on'); };
    });

    document.querySelectorAll('[data-rtn-save]').forEach(function(btn){
      btn.onclick = async function(){
        var concern = btn.dataset.rtnSave;
        var steps = [];

        document.querySelectorAll('[data-rtn-of="' + concern + '"]').forEach(function(chip){
          if (chip.classList.contains('on')) steps.push(chip.dataset.rtnStep);
        });

        var payload = {
          title: (document.querySelector('[data-rtn-title="' + concern + '"]') || {}).value || null,
          blurb: (document.querySelector('[data-rtn-blurb="' + concern + '"]') || {}).value || null,
          coupon_code: (document.querySelector('[data-rtn-coupon="' + concern + '"]') || {}).value || null,
          position: Number((document.querySelector('[data-rtn-pos="' + concern + '"]') || {}).value || 0),
          is_enabled: (document.querySelector('[data-rtn-on="' + concern + '"]') || {}).value === '1',
          steps: steps
        };

        try {
          await api('/routines/' + concern, payload);
          say('Routine saved.');
          loadAll();
        } catch (e) {
          say(explain(e, 'Could not save that routine.'));
        }
      };
    });

    var save = document.querySelector('#rtn-save-settings');
    if (save) save.onclick = async function(){
      var values = {};

      document.querySelectorAll('[data-rtn-set]').forEach(function(el){
        values[el.dataset.rtnSet] = el.value;
      });

      try {
        var body = await api('/routines-settings', {settings: values});
        if (data) data.settings = body.settings;
        say('Settings saved.');
        loadAll();
      } catch (e) {
        say(explain(e, 'Could not save the settings.'));
      }
    };
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
