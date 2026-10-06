{{--
    Store -> SEO Keywords.                                              Lane KW

    "in seo module to search over internet and collect all searched / related
    super ranked keywords and mix n match for our website ... each product will
    have own keywords + product focused + industry focused + skincare focused
    ... must be optimized, very light, secure, not spamming."

    Six tabs over routes/seo-keywords-admin.php: Overview, Sources, Keyword
    bank, Pages, Sync, and Brand name (Lane BR — /admin-api/seo-brand, the
    "Extra Beauty" check and the three brand switches). One request per tab open, per filter change or per page;
    the bank's search box is debounced. Sync is the only loop, and it is a loop
    of AWAITED requests — the next step is sent when the last one answers, it
    stops when the run is done or the owner presses Stop, and there is no timer.

    EVERY STRING FROM THE SERVER GOES THROUGH esc() — keywords came from Google,
    Search Console and shoppers' searches. The Search Console key box is never
    pre-filled and the key is never sent back.

    Pulled into app.blade.php at the end, like the screens beside it: registers
    its sidebar row (Store, after SEO & Meta) and wraps window.go.
--}}
@verbatim
<style>
.skw{display:grid;gap:14px}
.skw-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:16px;min-width:0}
.skw-card h3{margin:0 0 4px;font-size:15px}
.skw-card>p,.skw-note{font-size:12.5px;color:var(--ink-soft,#6b7280);margin:4px 0 0;line-height:1.5}
.skw-tabs{display:flex;gap:6px;overflow-x:auto;padding-bottom:2px}
.skw-tabs button,.skw-seg button{border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);border-radius:999px;padding:7px 13px;font:inherit;font-size:13px;cursor:pointer;color:inherit;white-space:nowrap}
.skw-tabs button.on{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff;font-weight:650}
.skw-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.skw-tile{border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:12px 14px;min-width:0}
.skw-tile .k{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft,#6b7280);font-weight:650}
.skw-tile .v{display:block;font-size:21px;font-weight:700;margin-top:4px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.skw-tile .s{display:block;font-size:12px;color:var(--ink-soft,#6b7280);margin-top:2px}
.skw-bar{height:8px;border-radius:99px;background:var(--surface-2,#f1f2f4);overflow:hidden}
.skw-bar i{display:block;height:100%;background:var(--accent,#15a85a);border-radius:99px}
.skw-cov{display:grid;grid-template-columns:110px minmax(0,1fr) 70px;gap:10px;align-items:center;font-size:13px;margin-top:9px}
.skw-cov b{font-weight:600}.skw-cov span{text-align:end;font-variant-numeric:tabular-nums;color:var(--ink-soft,#6b7280)}
.skw-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
.skw-tools input[type=search],.skw-tools select,.skw-in,.skw-ta{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:8px 11px;font:inherit;font-size:13px;background:var(--surface,#fff);color:inherit;min-width:0}
.skw-tools input[type=search]{flex:1 1 200px}
.skw-in{width:100%}.skw-ta{width:100%;min-height:110px;font-family:ui-monospace,Menlo,monospace;font-size:12px;box-sizing:border-box}
.skw-btn{border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);border-radius:10px;padding:8px 14px;font:inherit;font-size:13px;cursor:pointer;color:inherit;font-weight:600}
.skw-btn.pri{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.skw-btn.warn{color:#b91c1c}
.skw-btn[disabled]{opacity:.5;cursor:default}
.skw-wrap{overflow-x:auto;margin-top:12px}
.skw-t{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}
.skw-t th,.skw-t td{padding:8px 10px;border-bottom:1px solid var(--border,#eee);text-align:start;vertical-align:top}
.skw-t th{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-soft,#6b7280);font-weight:650;white-space:nowrap}
.skw-t td.n{text-align:end;white-space:nowrap}
.skw-t td.term{overflow-wrap:anywhere;min-width:140px}
.skw-b{display:inline-block;font-size:11px;font-weight:650;border-radius:999px;padding:2px 8px;white-space:nowrap}
.skw-b.gsc{background:#dcfce7;color:#166534}.skw-b.autocomplete{background:#dbeafe;color:#1e40af}.skw-b.site{background:#fef3c7;color:#92400e}.skw-b.lexicon{background:#f3f4f6;color:#374151}
.skw-b.own{background:#ede9fe;color:#5b21b6}.skw-b.product{background:#dbeafe;color:#1e40af}.skw-b.industry{background:#fce7f3;color:#9d174d}.skw-b.skincare{background:#dcfce7;color:#166534}
.skw-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.skw-chip{font-size:12px;border:1px solid var(--border,#e6e6e6);border-radius:999px;padding:3px 9px;overflow-wrap:anywhere;max-width:100%}
.skw-chip.pri{border-color:var(--accent,#15a85a);font-weight:650}
.skw-page{border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:12px 14px;margin-top:10px;min-width:0}
.skw-page h4{margin:0;font-size:14px;overflow-wrap:anywhere}
.skw-layer{display:grid;grid-template-columns:78px minmax(0,1fr);gap:8px;align-items:start;margin-top:6px}
.skw-sug{margin-top:10px;background:var(--surface-2,#f7f7f8);border-radius:10px;padding:10px 12px;font-size:12.5px}
.skw-sug .t{color:#1a0dab;font-size:14px;overflow-wrap:anywhere}.skw-sug .d{color:var(--ink-2,#4b5563);margin-top:2px;overflow-wrap:anywhere}
.skw-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}
.skw-sw{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border,#eee);font-size:13px}
.skw-sw:last-child{border-bottom:0}.skw-sw input{margin-top:3px}.skw-sw small{display:block;color:var(--ink-soft,#6b7280);margin-top:2px;line-height:1.45}
.skw-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:9px 12px;font-size:12.5px;margin-top:10px;overflow-wrap:anywhere}
.skw-ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:10px;padding:9px 12px;font-size:12.5px;margin-top:10px}
.skw-pager{display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:12px;font-size:12.5px}
.skw-empty{padding:22px 8px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.skw-steps{margin:8px 0 0;padding-inline-start:20px;font-size:12.5px;line-height:1.6}
.skw details summary{cursor:pointer;font-weight:600;font-size:13px;margin-top:8px}
.skw-types{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin-top:10px}
.skw-types label{display:flex;gap:7px;align-items:center;font-size:13px;border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:8px 10px}
@media (max-width:640px){.skw-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}.skw-card{padding:13px}.skw-tile .v{font-size:18px}.skw-types{grid-template-columns:1fr 1fr}.skw-cov{grid-template-columns:86px minmax(0,1fr) 58px}}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'seokeywords';
  var TABS = [['overview', 'Overview'], ['sources', 'Sources'], ['bank', 'Keyword bank'], ['pages', 'Pages'], ['sync', 'Sync'], ['brand', 'Brand name']];
  var LAYERS = [['own', 'Own'], ['product', 'Product'], ['industry', 'Industry'], ['skincare', 'Skincare']];
  var st = { tab: 'overview', ov: null, err: '', bank: { q: '', source: 'all', locale: 'en', page: 1, data: null },
    pages: { type: 'all', locale: 'en', filter: 'all', q: '', page: 1, data: null, edit: null },
    sync: { types: null, run: null, going: false, stop: false }, brand: null };
  var typing = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function say(msg) { try { if (typeof window.toast === 'function') window.toast(msg); } catch (e) {} }

  async function api(method, path, body) {
    var r = await fetch(base() + '/admin-api/seo-keywords' + path, {
      method: method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });
    var data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (!r.ok) {
      var msg = (data && data.message) || (r.status === 403 ? 'Your role cannot do this here.' : (r.status === 404 ? 'Not found — has the route cache been cleared?' : 'Something went wrong (' + r.status + ').'));
      var err = new Error(msg); err.status = r.status; throw err;
    }
    return data;
  }

  function root() { return document.querySelector('#content [data-skw]'); }

  function frame() {
    var host = document.getElementById('content');
    if (!host) return null;
    if (!root()) {
      host.innerHTML = '<div class="wrap"><div class="page-head"><h2>SEO Keywords</h2>'
        + '<p>Every product, category, brand, collection, page and article gets its own keywords — its own name, what it is, how the K-beauty trade says it and what a shopper is trying to fix — ranked by what people really search. They go where search engines read them and shoppers never see them: the page’s structured data and its keywords tag. Nothing is hidden in the page itself, because hidden text is exactly what Google penalises.</p></div>'
        + '<div class="skw" data-skw></div></div>';
    }
    return root();
  }

  function tabsHtml() {
    return '<div class="skw-tabs" role="tablist">' + TABS.map(function (t) {
      return '<button type="button" role="tab" data-skw-tab="' + t[0] + '" class="' + (st.tab === t[0] ? 'on' : '') + '" aria-selected="' + (st.tab === t[0]) + '">' + t[1] + '</button>';
    }).join('') + '</div>';
  }

  function paint(body) {
    var r = frame(); if (!r) return;
    r.innerHTML = tabsHtml() + (st.err ? '<div class="skw-warn">' + esc(st.err) + '</div>' : '') + body;
  }

  function badge(cls, label) { return '<span class="skw-b ' + esc(cls) + '">' + esc(label) + '</span>'; }
  function when(s) { return s ? esc(String(s).replace('T', ' ').slice(0, 16)) : '—'; }

  /* ---------------------------------------------------------------- Overview */
  function drawOverview() {
    var o = st.ov;
    if (!o) return paint('<div class="skw-card"><div class="skw-empty">Loading…</div></div>');
    var bankTotal = 0, bankAr = 0;
    Object.keys(o.bank || {}).forEach(function (loc) { Object.keys(o.bank[loc]).forEach(function (s) { bankTotal += o.bank[loc][s]; if (loc === 'ar') bankAr += o.bank[loc][s]; }); });
    var tot = 0, done = 0;
    (o.coverage || []).forEach(function (c) { tot += c.total; done += c.done; });
    var pct = tot ? Math.round(100 * done / tot) : 0;
    var last = o.last;

    var html = '<div class="skw-card">'
      + (o.live ? '<div class="skw-ok"><b>Live.</b> Keywords from the last sync are on the shop.</div>' : '<div class="skw-warn"><b>Not live yet.</b> Nothing changes on the shop until you run a sync (Sync tab).</div>')
      + '<div class="skw-tiles" style="margin-top:12px">'
      + '<div class="skw-tile"><span class="k">Keyword bank</span><span class="v">' + num(bankTotal) + '</span><span class="s">' + num(bankAr) + ' Arabic</span></div>'
      + '<div class="skw-tile"><span class="k">Coverage</span><span class="v">' + pct + '%</span><span class="s">' + num(done) + ' of ' + num(tot) + ' pages</span></div>'
      + '<div class="skw-tile"><span class="k">Last sync</span><span class="v" style="font-size:15px">' + (last ? when(last.finished_at || last.started_at) : 'Never') + '</span><span class="s">' + (last ? esc((last.dry ? 'Dry run · ' : '') + last.status) : '—') + '</span></div>'
      + '<div class="skw-tile"><span class="k">Clashes</span><span class="v">' + num(o.clash_count) + '</span><span class="s">pages that wanted a taken keyword</span></div>'
      + '</div></div>';

    html += '<div class="skw-card"><h3>Coverage by kind of page</h3><p>Pages that have their own keywords, in English.</p>'
      + (o.coverage || []).map(function (c) {
          var p = c.total ? Math.round(100 * c.done / c.total) : 0;
          return '<div class="skw-cov"><b>' + esc(c.label) + '</b><div class="skw-bar"><i style="width:' + p + '%"></i></div><span>' + num(c.done) + ' / ' + num(c.total) + '</span></div>';
        }).join('') + '</div>';

    html += '<div class="skw-card"><h3>Clashes</h3><p>Each primary keyword belongs to ONE page, so two pages never compete for the same search. When a page’s best keyword is already another page’s, it takes its next best and is listed here — usually two products with the same name.</p>'
      + ((o.clashes || []).length ? '<div class="skw-wrap"><table class="skw-t"><thead><tr><th>Page</th><th>Lang</th><th>What happened</th></tr></thead><tbody>'
        + o.clashes.map(function (c) { return '<tr><td class="term">' + esc(c.name || (c.entity_type + ' ' + c.entity_id)) + '<div class="skw-note">' + esc(c.entity_type + ' ' + c.entity_id) + '</div></td><td>' + esc(c.locale) + '</td><td class="term">' + esc(c.clash) + '</td></tr>'; }).join('')
        + '</tbody></table></div>' : '<div class="skw-empty">No clashes.</div>') + '</div>';

    html += '<div class="skw-card"><h3>What Google sees, and why it is built this way</h3>'
      + '<p>Each page publishes at most ten keywords, each once, in two places: schema.org <code>keywords</code> inside the page’s structured data, and a <code>&lt;meta name="keywords"&gt;</code> tag (Google ignores that tag; Bing and Yandex still read it — switch it off under Sources if you prefer). Keywords are never written into the page as hidden text: Google treats that as spam. What moves rankings is the visible title and description — the Pages tab suggests a better one for each page, built from its best keyword, and applies it only when you click.</p></div>';

    paint(html);
  }

  /* ---------------------------------------------------------------- Sources */
  function drawSources() {
    var o = st.ov;
    if (!o) return paint('<div class="skw-card"><div class="skw-empty">Loading…</div></div>');
    var op = o.options || {}, sw = o.switches || {}, g = o.gsc || {};
    function box(id, on, title, help) {
      return '<label class="skw-sw"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '><span><b>' + title + '</b><small>' + help + '</small></span></label>';
    }
    var lx = o.lexicon || {};
    function list(rows) { return '<div class="skw-chips">' + (rows || []).map(function (r) { return '<span class="skw-chip">' + esc(Array.isArray(r) ? r.filter(Boolean).join(' · ') : r) + '</span>'; }).join('') + '</div>'; }

    paint('<div class="skw-card"><h3>Where keywords come from</h3><p>Strongest first: Search Console (searches that already show this shop) › Google Autocomplete (what people type) › this shop’s own search box › the K-beauty lexicon below. This shop’s own products, brands and categories are always used.</p>'
      + box('skwAc', op.autocomplete, 'Google Autocomplete', 'Asks Google what people type after phrases built from your catalogue. At most one request a second, at most ' + esc(op.seed_cap) + ' phrases a sync, each remembered for 30 days. <b>Unofficial</b>: Google publishes no contract for it, so if it stops answering, a sync simply carries on without it.')
      + '<div class="skw-sw"><span style="flex:1"><b>Phrases asked per sync</b><small>0–300. Fewer makes a sync quicker.</small></span><input class="skw-in" style="max-width:96px" type="number" min="0" max="300" id="skwCap" value="' + esc(op.seed_cap) + '"></div>'
      + box('skwAr', op.arabic, 'Arabic keywords', 'Builds Arabic keywords for the /ar/ pages. Only used while Arabic is switched on for the shop.')
      + box('skwMeta', sw.meta, 'Keywords tag in each page’s head', 'Prints <code>&lt;meta name="keywords"&gt;</code> with up to ten keywords. Google ignores it; Bing and Yandex read it. Never visible to shoppers.')
      + box('skwPop', sw.popular, 'Popular searches block on category and brand pages', 'A short row of links under the products — each best seller, labelled with its own keyword. Visible and useful to shoppers, which is what Google rewards. Off until you turn it on.')
      + '<div class="skw-row"><button type="button" class="skw-btn pri" data-skw-act="save-src">Save</button></div></div>'

      + '<div class="skw-card"><h3>Google Search Console</h3>'
      + (g.connected ? '<div class="skw-ok">Connected as <b>' + esc(g.email) + '</b>' + (g.property ? ' · ' + esc(g.property) : ' · <b>add the property below</b>') + '</div>' : '<p>Optional, and the best signal there is: the real searches that show your pages, with clicks and position. Without it the module still works from Autocomplete and your own data.</p>')
      + '<ol class="skw-steps"><li>At console.cloud.google.com create (or pick) a project → <b>APIs &amp; Services → Library</b> → enable <b>Google Search Console API</b>.</li>'
      + '<li><b>IAM &amp; Admin → Service accounts → Create</b>. No roles needed. Open it → <b>Keys → Add key → JSON</b>; a .json file downloads.</li>'
      + '<li>In Search Console → your property → <b>Settings → Users and permissions → Add user</b>: paste the service account’s email, permission <b>Restricted</b>.</li>'
      + '<li>Paste the whole .json file below and the property exactly as Search Console names it — <code>sc-domain:extrabeauty.ae</code> or <code>https://extrabeauty.ae/</code>.</li></ol>'
      + '<div style="margin-top:10px"><label class="skw-note" for="skwProp">Property</label><input class="skw-in" id="skwProp" placeholder="sc-domain:extrabeauty.ae" value="' + esc(g.property || '') + '" autocomplete="off"></div>'
      + '<div style="margin-top:10px"><label class="skw-note" for="skwKey">Service-account JSON key ' + (g.connected ? '(leave empty to keep the saved one)' : '') + '</label><textarea class="skw-ta" id="skwKey" spellcheck="false" autocomplete="off" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }"></textarea><div class="skw-note">Stored encrypted on the server. It is never shown again or sent back to any browser.</div></div>'
      + '<div class="skw-row"><button type="button" class="skw-btn pri" data-skw-act="save-gsc">Save</button><button type="button" class="skw-btn" data-skw-act="test-gsc"' + (g.connected ? '' : ' disabled') + '>Test connection</button>' + (g.connected ? '<button type="button" class="skw-btn warn" data-skw-act="forget-gsc">Disconnect</button>' : '') + '</div></div>'

      + '<div class="skw-card"><h3>The K-beauty lexicon</h3><p>Built in, read-only. Used to recognise what a product is and to choose what to ask Google.</p>'
      + '<details><summary>Product types (' + (lx.types || []).length + ')</summary>' + list(lx.types) + '</details>'
      + '<details><summary>Ingredients (' + (lx.ingredients || []).length + ')</summary>' + list(lx.ingredients) + '</details>'
      + '<details><summary>Concerns</summary>' + list(lx.concerns) + '</details>'
      + '<details><summary>Industry phrasing</summary>' + list(lx.industry) + '</details>'
      + '<details><summary>UAE intent</summary>' + list((lx.uae && lx.uae.en || []).concat(lx.uae && lx.uae.ar || [])) + '</details></div>');
  }

  /* ---------------------------------------------------------------- Brand name (Lane BR)
     Its own endpoint, /admin-api/seo-brand, read once when the tab opens. The
     replace button carries the dry-run count it was drawn with; the server
     refuses if the count has moved since. */
  async function brandApi(method, path, body) {
    var r = await fetch(base() + '/admin-api/seo-brand' + path, {
      method: method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });
    var data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (r.status === 409 && data && data.scan) { st.brand = data; }
    if (!r.ok) {
      var err = new Error((data && data.message) || (r.status === 403 ? 'Your role cannot do this here.' : 'Something went wrong (' + r.status + ').')); err.status = r.status; throw err;
    }
    return data;
  }
  async function loadBrand() {
    try { st.brand = await brandApi('GET', ''); st.err = ''; } catch (e) { st.err = e.message; }
  }
  function drawBrand() {
    var b = st.brand;
    if (!b) return paint('<div class="skw-card"><div class="skw-empty">Loading…</div></div>');
    var sc = b.scan || {}, sw = b.switches || {}, u = b.in_use || {};
    function box(id, on, title, help) {
      return '<label class="skw-sw"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '><span><b>' + title + '</b><small>' + help + '</small></span></label>';
    }
    var names = [['Store name', u.store_name], ['SEO site name', u.seo_site_name], ['Organization name', u.org_name], ['Email From name', u.mail_from_name]];
    var html = '<div class="skw-card"><h3>Brand name check</h3>'
      + '<p>Finds the old name “Extra Beauty” (any spelling, and the Arabic) still stored in the shop’s own text — settings, footer and banner copy, menus, pages, email and marketing templates, products (names, descriptions, image alt text), categories, brands, reviews, checkout labels, SEO boxes and keyword sets — and replaces it with <b>' + esc(b.name) + '</b>. Orders, customers, payments and email history are records and are never read. Web addresses and emails (extrabeauty.ae) are left alone: the domain moves at the cutover.</p>'
      + '<div class="skw-wrap"><table class="skw-t"><tbody>' + names.map(function (n) { return '<tr><td>' + esc(n[0]) + '</td><td class="term">' + (n[1] ? esc(n[1]) : '<span class="skw-note">not set — ' + esc(b.name) + ' is used</span>') + '</td></tr>'; }).join('') + '</tbody></table></div>'
      + (b.app_name_stale ? '<div class="skw-warn"><b>APP_NAME in the server’s .env still names the old shop.</b> The shop already ignores it and uses ' + esc(b.name) + '; change it to <code>APP_NAME="' + esc(b.name) + '"</code> over SSH when convenient.</div>' : '')
      + (sc.total ? '<div class="skw-warn"><b>' + num(sc.total) + '</b> place' + (sc.total === 1 ? '' : 's') + ' still say “Extra Beauty”. This is a dry run — nothing has changed yet.</div>'
          + '<div class="skw-wrap"><table class="skw-t"><thead><tr><th>Where</th><th>Now</th><th>After</th></tr></thead><tbody>'
          + (sc.rows || []).map(function (r) { return '<tr><td class="term">' + esc(r.label) + '<div class="skw-note">' + esc(r.table + ' · ' + r.column) + '</div></td><td class="term">' + esc(r.before) + '</td><td class="term">' + esc(r.after) + '</td></tr>'; }).join('')
          + '</tbody></table></div><div class="skw-row"><button type="button" class="skw-btn pri" data-skw-act="brand-replace" data-n="' + esc(sc.total) + '">Replace with K-Beauty Bliss</button><button type="button" class="skw-btn" data-skw-act="brand-check">Check again</button></div>'
        : '<div class="skw-ok"><b>Nothing to replace.</b> The shop’s stored text says ' + esc(b.name) + ' everywhere it names itself.</div><div class="skw-row"><button type="button" class="skw-btn" data-skw-act="brand-check">Check again</button></div>')
      + ((sc.areas || []).length ? '<details><summary>Every area checked (' + (sc.areas || []).length + ') — ' + num((sc.areas || []).reduce(function (t, a) { return t + a.found; }, 0)) + ' to replace</summary><div class="skw-wrap"><table class="skw-t"><thead><tr><th>Area</th><th style="text-align:end">To replace</th><th style="text-align:end">Left on purpose</th></tr></thead><tbody>'
          + sc.areas.map(function (a) { return '<tr><td>' + esc(a.label) + (a.checked ? '' : '<div class="skw-note">not on this server</div>') + '</td><td class="n">' + num(a.found) + '</td><td class="n">' + num(a.left) + '</td></tr>'; }).join('')
          + '</tbody></table></div></details>' : '')
      + ((sc.left || []).length ? '<details><summary>Left on purpose (' + (sc.left || []).length + ')</summary><div class="skw-wrap"><table class="skw-t"><tbody>' + sc.left.map(function (r) { return '<tr><td class="term">' + esc(r.label) + '</td><td class="term">' + esc(r.sample || '—') + '<div class="skw-note">' + esc(r.why) + '</div></td></tr>'; }).join('') + '</tbody></table></div></details>' : '')
      + ((sc.errors || []).length ? '<div class="skw-warn">' + sc.errors.map(esc).join('<br>') + '</div>' : '')
      + '</div>';
    html += '<div class="skw-card"><h3>Brand in search results</h3><p>Each is on. Untick and save to put that piece back as it was.</p>'
      + box('skwBrTitles', sw.titles, 'Brand name in titles', 'When no home title or description is typed under SEO &amp; Meta, the home page’s title is “' + esc(b.home_title && b.home_title[0]) + '” and its description names ' + esc(b.name) + ' once. Arabic pages get the Arabic version. Typed titles always win.')
      + box('skwBrAlt', sw.alternates, 'Brand alternate names', 'Tells Google the shop is also searched as ' + (b.alternates || []).map(esc).join(', ') + ' — in the structured data only (WebSite and Organization <code>alternateName</code>), never as text on the page.')
      + box('skwBrOne', sw.kbeauty_one, 'One k-beauty phrase per page', 'Each page’s keywords carry exactly one natural phrase with “k-beauty”, “k beauty” or “kbeauty” about that page (“anua toner k-beauty uae”), the spelling rotating across pages and never stacked. The home page’s is “k-beauty bliss”. Takes effect at the next sync (Sync tab).')
      + '<div class="skw-row"><button type="button" class="skw-btn pri" data-skw-act="brand-save">Save</button></div></div>';
    paint(html);
  }

  /* ---------------------------------------------------------------- Bank */
  function drawBank() {
    var b = st.bank, d = b.data;
    var html = '<div class="skw-card"><h3>Keyword bank</h3><p>Every phrase a source returned, strongest first. Pages draw their keywords from here.</p>'
      + '<div class="skw-tools"><input type="search" id="skwBankQ" placeholder="Find a keyword…" value="' + esc(b.q) + '" aria-label="Find a keyword">'
      + '<select id="skwBankSrc" aria-label="Source">' + [['all', 'All sources'], ['gsc', 'Search Console'], ['autocomplete', 'Autocomplete'], ['site', 'Site search'], ['lexicon', 'Lexicon']].map(function (o) { return '<option value="' + o[0] + '"' + (b.source === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>'
      + '<select id="skwBankLoc" aria-label="Language"><option value="en"' + (b.locale === 'en' ? ' selected' : '') + '>English</option><option value="ar"' + (b.locale === 'ar' ? ' selected' : '') + '>Arabic</option></select></div>';
    if (!d) html += '<div class="skw-empty">Loading…</div>';
    else if (!d.rows.length) html += '<div class="skw-empty">Nothing yet. Run a sync to fill the bank.</div>';
    else {
      html += '<div class="skw-wrap"><table class="skw-t"><thead><tr><th>Keyword</th><th>Source</th><th style="text-align:end">Score</th><th style="text-align:end">Signal</th></tr></thead><tbody>'
        + d.rows.map(function (r) {
            var sig = r.impressions != null ? num(r.impressions) + ' impr · ' + num(r.clicks) + ' clicks · pos ' + r.position : (r.hits != null ? num(r.hits) + ' searches' : '—');
            return '<tr><td class="term" dir="auto">' + esc(r.term) + '</td><td>' + badge(r.source, { gsc: 'Search Console', autocomplete: 'Autocomplete', site: 'Site search', lexicon: 'Lexicon' }[r.source] || r.source) + '</td><td class="n">' + num(r.score) + '</td><td class="n">' + esc(sig) + '</td></tr>';
          }).join('') + '</tbody></table></div>' + pager('bank', d);
    }
    paint(html + '</div>');
  }

  function pager(which, d) {
    return '<div class="skw-pager"><span>' + num(d.total) + ' · page ' + d.page + ' of ' + d.pages + '</span>'
      + '<button type="button" class="skw-btn" data-skw-page="' + which + ':' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>Previous</button>'
      + '<button type="button" class="skw-btn" data-skw-page="' + which + ':' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>Next</button></div>';
  }

  /* ---------------------------------------------------------------- Pages */
  function drawPages() {
    var p = st.pages, d = p.data, types = (st.ov && st.ov.types) || {};
    var html = '<div class="skw-card"><h3>Pages</h3><p>Each page’s keywords in their four layers, its primary keyword (owned by this page alone), and a suggested title and description you can apply with one click. Editing a page locks it, so a sync leaves your words alone.</p>'
      + '<div class="skw-tools"><input type="search" id="skwPagesQ" placeholder="Pages with this keyword…" value="' + esc(p.q) + '" aria-label="Find">'
      + '<select id="skwPagesType" aria-label="Kind of page"><option value="all">All pages</option>' + Object.keys(types).map(function (k) { return '<option value="' + esc(k) + '"' + (p.type === k ? ' selected' : '') + '>' + esc(types[k]) + '</option>'; }).join('') + '</select>'
      + '<select id="skwPagesFilter" aria-label="Show">' + [['all', 'Everything'], ['clash', 'Clashes'], ['locked', 'Locked'], ['noprimary', 'No primary']].map(function (o) { return '<option value="' + o[0] + '"' + (p.filter === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>'
      + '<select id="skwPagesLoc" aria-label="Language"><option value="en"' + (p.locale === 'en' ? ' selected' : '') + '>English</option><option value="ar"' + (p.locale === 'ar' ? ' selected' : '') + '>Arabic</option></select></div>';
    if (!d) html += '<div class="skw-empty">Loading…</div>';
    else if (!d.rows.length) html += '<div class="skw-empty">No pages yet. Run a sync from the Sync tab.</div>';
    else html += d.rows.map(pageCard).join('') + pager('pages', d);
    paint(html + '</div>');
  }

  function pageCard(r, i) {
    var key = r.type + ':' + r.id + ':' + r.locale;
    var editing = st.pages.edit === key;
    var html = '<div class="skw-page" dir="auto"><div class="skw-row" style="margin-top:0;justify-content:space-between"><h4>' + esc(r.name) + '</h4><span>' + badge('lexicon', r.type) + (r.locked ? ' ' + badge('site', 'Locked') : '') + '</span></div>';
    if (r.clash) html += '<div class="skw-warn">' + esc(r.clash) + '</div>';
    if (editing) {
      html += '<div style="margin-top:8px"><label class="skw-note">Keywords, one per line (up to 10)</label><textarea class="skw-ta" id="skwEdKw">' + esc((r.keywords || []).join('\n')) + '</textarea>'
        + '<label class="skw-note">Primary keyword</label><input class="skw-in" id="skwEdPri" value="' + esc(r.primary || '') + '">'
        + '<label class="skw-sw" style="border:0"><input type="checkbox" id="skwEdLock"' + ' checked' + '><span><b>Lock</b><small>A sync will not change this page while it is locked.</small></span></label>'
        + '<div class="skw-row"><button type="button" class="skw-btn pri" data-skw-act="save-page" data-i="' + i + '">Save</button><button type="button" class="skw-btn" data-skw-act="cancel-edit">Cancel</button></div></div>';
    } else {
      html += '<div class="skw-note" style="margin-top:6px">Primary: ' + (r.primary ? '<span class="skw-chip pri">' + esc(r.primary) + '</span>' : '—') + '</div>';
      LAYERS.forEach(function (l) {
        var list = (r.layers && r.layers[l[0]]) || [];
        if (!list.length) return;
        html += '<div class="skw-layer">' + badge(l[0], l[1]) + '<div class="skw-chips" style="margin-top:0">' + list.map(function (k) { return '<span class="skw-chip' + (k === r.primary ? ' pri' : '') + '">' + esc(k) + '</span>'; }).join('') + '</div></div>';
      });
      if (r.suggest) {
        html += '<div class="skw-sug"><div class="skw-note">Suggested for Google' + (r.applicable ? '' : ' (Arabic and fixed pages are edited in their own screens)') + '</div><div class="t">' + esc(r.suggest.title) + '</div><div class="d">' + esc(r.suggest.desc) + '</div>'
          + (r.applicable ? '<div class="skw-row"><button type="button" class="skw-btn" data-skw-act="apply" data-f="title" data-i="' + i + '">Use title</button><button type="button" class="skw-btn" data-skw-act="apply" data-f="desc" data-i="' + i + '">Use description</button><button type="button" class="skw-btn pri" data-skw-act="apply" data-f="both" data-i="' + i + '">Use both</button></div>' : '') + '</div>';
      }
      html += '<div class="skw-row"><button type="button" class="skw-btn" data-skw-act="edit" data-i="' + i + '">Edit keywords</button></div>';
    }
    return html + '</div>';
  }

  /* ---------------------------------------------------------------- Sync */
  function drawSync() {
    var o = st.ov, s = st.sync, types = (o && o.types) || {};
    if (!s.types) s.types = Object.keys(types);
    var run = s.run || (o && o.running) || null;
    var html = '<div class="skw-card"><h3>Sync keywords</h3><p>Choose what to sync. <b>Dry run</b> shows what would change and writes nothing (it uses the keyword bank as it stands). <b>Run sync</b> refreshes the bank from your sources first, then gives every chosen page its keywords — in small steps, so it never times out; you can leave and Resume. Nothing changes on the shop until a run finishes.</p>'
      + '<div class="skw-types">' + Object.keys(types).map(function (k) { return '<label><input type="checkbox" data-skw-type="' + esc(k) + '"' + (s.types.indexOf(k) > -1 ? ' checked' : '') + '> ' + esc(types[k]) + '</label>'; }).join('') + '</div>'
      + '<div class="skw-row"><button type="button" class="skw-btn" data-skw-act="all-types">Select all</button><span style="flex:1"></span>'
      + '<button type="button" class="skw-btn" data-skw-act="dry"' + (s.going ? ' disabled' : '') + '>Dry run</button>'
      + '<button type="button" class="skw-btn pri" data-skw-act="run"' + (s.going ? ' disabled' : '') + '>Run sync</button></div></div>';

    if (run && run.id) {
      var c = run.counts || {};
      html += '<div class="skw-card"><h3>' + (run.dry ? 'Dry run' : 'Sync') + ' #' + run.id + ' · ' + esc(run.status === 'running' ? (run.phase === 'bank' ? 'collecting keywords' : 'composing pages') : run.status) + '</h3>'
        + '<div class="skw-bar" style="margin-top:10px;height:10px" role="progressbar" aria-valuenow="' + run.percent + '" aria-valuemin="0" aria-valuemax="100"><i style="width:' + run.percent + '%"></i></div>'
        + '<div class="skw-note">' + run.percent + '% · ' + num(c.done) + ' of ' + num(c.total) + ' steps</div>'
        + '<div class="skw-tiles" style="margin-top:12px">'
        + '<div class="skw-tile"><span class="k">New</span><span class="v">' + num(c.created) + '</span></div>'
        + '<div class="skw-tile"><span class="k">Changed</span><span class="v">' + num(c.changed) + '</span></div>'
        + '<div class="skw-tile"><span class="k">Unchanged</span><span class="v">' + num(c.unchanged) + '</span><span class="s">' + num(c.locked) + ' locked</span></div>'
        + '<div class="skw-tile"><span class="k">Bank +</span><span class="v">' + num(c.fetched) + '</span><span class="s">' + num(c.requests) + ' asked · ' + num(c.failed) + ' failed</span></div></div>'
        + (run.error ? '<div class="skw-warn">' + esc(run.error) + '</div>' : '')
        + (run.notes || []).map(function (n) { return '<div class="skw-note">' + esc(n) + '</div>'; }).join('')
        + '<div class="skw-row">' + (s.going ? '<button type="button" class="skw-btn" data-skw-act="stop">Stop</button>' : (run.status === 'running' ? '<button type="button" class="skw-btn pri" data-skw-act="resume">Resume</button>' : '')) + '</div>';
      if (run.dry && (run.diffs || []).length) {
        html += '<div class="skw-wrap"><table class="skw-t"><thead><tr><th>Page</th><th>Now</th><th>After</th></tr></thead><tbody>'
          + run.diffs.map(function (d) {
              return '<tr><td class="term">' + esc(d.name) + '<div class="skw-note">' + esc(d.entity + ' · ' + d.locale) + '</div></td><td class="term">' + (d.before.length ? esc(d.before.join(', ')) : '<span class="skw-note">nothing</span>') + '</td><td class="term"><b>' + esc(d.primary_after || '') + '</b>' + (d.primary_after ? '<br>' : '') + esc(d.after.join(', ')) + '</td></tr>';
            }).join('') + '</tbody></table></div><div class="skw-note">The first ' + run.diffs.length + ' changes.</div>';
      }
      html += '</div>';
    }

    html += '<div class="skw-card"><h3>Undo</h3><p>Puts back every page the last sync changed — keywords and primaries — exactly as they were. Titles you applied by hand are not touched.</p>'
      + '<div class="skw-row"><button type="button" class="skw-btn warn" data-skw-act="undo"' + (o && o.undo && !s.going ? '' : ' disabled') + '>Undo the last sync' + (o && o.undo ? ' (#' + o.undo.id + ')' : '') + '</button></div></div>';
    paint(html);
  }

  async function drive(run) {
    var s = st.sync;
    s.run = run; s.going = true; s.stop = false; drawSync();
    try {
      while (s.run.status === 'running' && !s.stop && document.querySelector('#content [data-skw]')) {
        s.run = await api('POST', '/sync/' + s.run.id + '/step');
        if (st.tab === 'sync') drawSync();
      }
    } catch (e) { st.err = e.message; }
    s.going = false;
    await loadOverview(true);
    if (st.tab === 'sync') drawSync();
    if (s.run && s.run.status === 'done') say(s.run.dry ? 'Dry run finished — nothing was changed.' : 'Keywords synced. They are live on the shop.');
  }

  /* ---------------------------------------------------------------- loading */
  async function loadOverview(quiet) {
    try { st.ov = await api('GET', ''); if (!quiet) st.err = ''; } catch (e) { st.err = e.message; }
  }
  async function loadBank() {
    var b = st.bank;
    try { b.data = await api('GET', '/bank?' + new URLSearchParams({ q: b.q, source: b.source, locale: b.locale, page: String(b.page) })); st.err = ''; } catch (e) { st.err = e.message; b.data = { rows: [], total: 0, page: 1, pages: 1 }; }
  }
  async function loadPages() {
    var p = st.pages;
    try { p.data = await api('GET', '/entities?' + new URLSearchParams({ type: p.type, locale: p.locale, filter: p.filter, q: p.q, page: String(p.page) })); st.err = ''; } catch (e) { st.err = e.message; p.data = { rows: [], total: 0, page: 1, pages: 1 }; }
  }

  async function show(tab) {
    st.tab = tab;
    var draw = { overview: drawOverview, sources: drawSources, bank: drawBank, pages: drawPages, sync: drawSync, brand: drawBrand }[tab] || drawOverview;
    draw();
    if (tab === 'brand') { await loadBrand(); }
    else if (tab === 'bank') { await loadBank(); }
    else if (tab === 'pages') { if (!st.ov) await loadOverview(); await loadPages(); }
    else { await loadOverview(); }
    if (st.tab === tab) draw();
  }

  /* ---------------------------------------------------------------- events */
  document.addEventListener('click', async function (e) {
    var r = root(); if (!r || !r.contains(e.target)) return;
    var t = e.target.closest('[data-skw-tab],[data-skw-act],[data-skw-page]');
    if (!t || t.disabled) return;
    if (t.hasAttribute('data-skw-tab')) return show(t.getAttribute('data-skw-tab'));
    if (t.hasAttribute('data-skw-page')) {
      var pp = t.getAttribute('data-skw-page').split(':');
      if (pp[0] === 'bank') { st.bank.page = +pp[1]; await loadBank(); drawBank(); } else { st.pages.page = +pp[1]; await loadPages(); drawPages(); }
      return;
    }
    var act = t.getAttribute('data-skw-act'), i = +t.getAttribute('data-i'), row = st.pages.data && st.pages.data.rows[i];
    try {
      if (act === 'save-src') {
        st.ov = await api('PUT', '/settings', { autocomplete: q('#skwAc').checked, arabic: q('#skwAr').checked, meta: q('#skwMeta').checked, popular: q('#skwPop').checked, seed_cap: Math.max(0, Math.min(300, parseInt(q('#skwCap').value, 10) || 0)), gsc_property: (st.ov && st.ov.options.gsc_property) || '' });
        say('Saved.'); drawSources();
      } else if (act === 'save-gsc') {
        var key = q('#skwKey').value.trim();
        st.ov = await api('PUT', '/settings', { gsc_property: q('#skwProp').value.trim() });
        if (key) { await api('PUT', '/gsc', { key: key }); }
        q('#skwKey').value = '';
        await loadOverview(); say('Search Console saved.'); drawSources();
      } else if (act === 'test-gsc') {
        t.disabled = true; var res = await api('POST', '/gsc/test'); say(res.message); st.err = res.ok ? '' : res.message; drawSources();
      } else if (act === 'forget-gsc') {
        if (!confirm('Disconnect Search Console? The saved key is deleted.')) return;
        await api('DELETE', '/gsc'); await loadOverview(); drawSources();
      } else if (act === 'edit') { st.pages.edit = row.type + ':' + row.id + ':' + row.locale; drawPages(); }
      else if (act === 'cancel-edit') { st.pages.edit = null; drawPages(); }
      else if (act === 'save-page') {
        var kws = q('#skwEdKw').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean).slice(0, 10);
        var out = await api('PUT', '/entities', { type: row.type, id: row.id, locale: row.locale, keywords: kws, primary: q('#skwEdPri').value.trim() || null, locked: q('#skwEdLock').checked });
        row.keywords = out.keywords; row.primary = out.primary; row.locked = out.locked; row.clash = null;
        row.layers = { own: out.keywords }; st.pages.edit = null; say('Saved.'); drawPages();
      } else if (act === 'apply') {
        var f = t.getAttribute('data-f');
        await api('POST', '/apply', { type: row.type, id: +row.id, fields: f === 'both' ? ['title', 'desc'] : [f] });
        say(f === 'desc' ? 'Description applied.' : (f === 'title' ? 'Title applied.' : 'Title and description applied.'));
      } else if (act === 'brand-check') {
        await loadBrand(); drawBrand();
      } else if (act === 'brand-replace') {
        var n = +t.getAttribute('data-n');
        if (!confirm('Replace “Extra Beauty” with K-Beauty Bliss in ' + n + ' place' + (n === 1 ? '' : 's') + '?')) return;
        t.disabled = true;
        st.brand = await brandApi('POST', '/replace', { expect: n });
        say('Replaced in ' + st.brand.replaced + ' place' + (st.brand.replaced === 1 ? '' : 's') + '.'); drawBrand();
      } else if (act === 'brand-save') {
        st.brand = await brandApi('PUT', '/switches', { titles: q('#skwBrTitles').checked, alternates: q('#skwBrAlt').checked, kbeauty_one: q('#skwBrOne').checked });
        say('Saved.'); drawBrand();
      } else if (act === 'all-types') {
        st.sync.types = Object.keys((st.ov && st.ov.types) || {}); drawSync();
      } else if (act === 'dry' || act === 'run') {
        if (!st.sync.types.length) { st.err = 'Choose at least one kind of page.'; return drawSync(); }
        if (act === 'run' && !confirm('Run the sync? Pages get new keywords when it finishes; you can undo it afterwards.')) return;
        st.err = '';
        drive(await api('POST', '/sync', { types: st.sync.types, dry: act === 'dry' }));
      } else if (act === 'resume') { drive(st.sync.run || st.ov.running); }
      else if (act === 'stop') { st.sync.stop = true; }
      else if (act === 'undo') {
        if (!confirm('Undo the last sync? Every page it changed goes back to how it was.')) return;
        await api('POST', '/undo'); await loadOverview(); st.sync.run = null; say('The last sync was undone.'); drawSync();
      }
    } catch (err) { st.err = err.message; show(st.tab); }
  });

  function q(sel) { return document.querySelector('#content ' + sel); }

  document.addEventListener('change', async function (e) {
    var r = root(); if (!r || !r.contains(e.target)) return;
    var id = e.target.id;
    if (e.target.hasAttribute('data-skw-type')) {
      var k = e.target.getAttribute('data-skw-type'), list = st.sync.types || [];
      st.sync.types = e.target.checked ? list.concat([k]) : list.filter(function (x) { return x !== k; });
      return;
    }
    if (id === 'skwBankSrc') { st.bank.source = e.target.value; st.bank.page = 1; await loadBank(); drawBank(); }
    if (id === 'skwBankLoc') { st.bank.locale = e.target.value; st.bank.page = 1; await loadBank(); drawBank(); }
    if (id === 'skwPagesType') { st.pages.type = e.target.value; st.pages.page = 1; await loadPages(); drawPages(); }
    if (id === 'skwPagesFilter') { st.pages.filter = e.target.value; st.pages.page = 1; await loadPages(); drawPages(); }
    if (id === 'skwPagesLoc') { st.pages.locale = e.target.value; st.pages.page = 1; await loadPages(); drawPages(); }
  });

  document.addEventListener('input', function (e) {
    var id = e.target && e.target.id;
    if (id !== 'skwBankQ' && id !== 'skwPagesQ') return;
    clearTimeout(typing);
    var v = e.target.value.trim();
    typing = setTimeout(async function () {
      if (id === 'skwBankQ') { st.bank.q = v; st.bank.page = 1; await loadBank(); drawBank(); refocus('skwBankQ'); }
      else { st.pages.q = v; st.pages.page = 1; await loadPages(); drawPages(); refocus('skwPagesQ'); }
    }, 300);
  });

  function refocus(id) { var el = document.getElementById(id); if (el) { el.focus(); var n = el.value.length; try { el.setSelectionRange(n, n); } catch (e) {} } }

  /* ---------------------------------------------------------------- nav */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'SEO Keywords',
      icon: '<path d="M4 7h9M4 12h6M4 17h4"/><circle cx="16.5" cy="13.5" r="4.5"/><path d="m20 17 2 2"/>',
      group: 'Store',
      after: ['seo', 'search']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Store';
    if (title) title.textContent = 'SEO Keywords';
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    var host = document.getElementById('content');
    if (host) host.innerHTML = '';
    st.err = '';
    show(st.tab);
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
</script>
@endverbatim
