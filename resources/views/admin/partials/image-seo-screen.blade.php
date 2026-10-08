{{--
    Catalog → Image SEO (Lane IR). The owner: "we mostly didn't rename the
    products images as per the product title for our seo ... search any
    product, any brand, any category ... look what's with the product title
    name and what's not, and upon final selection, the system will rename the
    products images files with the product name, with a single word variation
    ... and also have proper separate tab for ALT tag."

    Four tabs over one selection:
      Find          search by name / SKU / brand / category, filter and sort;
                    every picture with its URL, a score out of 10 in front of
                    it (the owner's "6/10"), what cost points, the proposed name.
      Rename files  preview exactly what changes, Start, live progress, Stop,
                    Resume; then a live check that an old address redirects.
      ALT text      templates, every alt editable before Apply.
      History       every run, its log, Undo.

    Endpoints: routes/image-seo-admin.php, `media.image_seo` (owner, manager),
    CSRF via the XSRF cookie like every console screen. NO TIMER AND NO
    POLLING: a search is a submit, a run is "post the next step when the last
    one answered", and the score backfill is the same finite loop. No layout
    measurement. Every value printed passes through esc().

    Wired by tools/ir-wire.php from docs/ir-wiring.json (title, deep-link set
    and this include); the sidebar row is App\Support\AdminNav's.

    EVERY CLASS IS PREFIXED isx- AND EVERY CLICKED ATTRIBUTE data-isx-: the
    console binds delegated listeners to bare attribute names.
--}}
@verbatim
<style>
.isx{max-width:1180px;margin:0 auto;min-width:0}
.isx *{box-sizing:border-box}
.isx-card{border:1px solid var(--border,#e6e9f2);border-radius:14px;background:var(--card,#fff);padding:16px;margin-bottom:14px;min-width:0}
.isx-head h2{margin:0 0 6px;font-size:20px}
.isx-sub{margin:0;color:var(--ink-soft,#626c80);font-size:13.5px;line-height:1.5}
.isx-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 14px}
.isx-tab{font:inherit;font-size:13.5px;font-weight:650;border:1px solid var(--border,#d4d8e3);background:var(--surface,#fff);color:inherit;
         border-radius:999px;padding:7px 14px;cursor:pointer}
.isx-tab.on{background:var(--ink,#101729);color:#fff;border-color:var(--ink,#101729)}
.isx-btn{font:inherit;font-size:13.5px;font-weight:650;border-radius:10px;padding:8px 14px;cursor:pointer;border:1px solid transparent;
         background:var(--accent,#15a85a);color:#fff;max-width:100%}
.isx-btn.is-quiet{background:transparent;color:inherit;border-color:var(--border,#d4d8e3)}
.isx-btn.is-danger{background:#b91c1c}
.isx-btn[disabled]{opacity:.5;cursor:not-allowed}
.isx-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;min-width:0}
.isx-form{display:grid;grid-template-columns:minmax(0,2fr) repeat(4,minmax(0,1fr)) auto;gap:8px;align-items:end}
.isx-field{display:grid;gap:4px;min-width:0}
.isx-field label{font-size:11.5px;font-weight:650;color:var(--ink-soft,#626c80);text-transform:uppercase;letter-spacing:.04em}
.isx-in,.isx-sel{font:inherit;font-size:14px;padding:8px 10px;border:1px solid var(--border,#d4d8e3);border-radius:9px;background:var(--surface,#fff);color:inherit;min-width:0;width:100%}
.isx-bar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;justify-content:space-between;
         background:var(--ink,#101729);color:#fff;border-radius:12px;padding:9px 14px;margin-bottom:12px;font-size:13.5px}
.isx-bar .isx-btn.is-quiet{color:#fff;border-color:rgba(255,255,255,.35);padding:5px 10px;font-size:12.5px}
.isx-prod{border:1px solid var(--border,#e6e9f2);border-radius:12px;margin-top:10px;min-width:0;overflow:hidden}
.isx-ph{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;padding:10px 12px;background:var(--surface-2,#f6f7fb)}
.isx-ph b{font-size:14.5px}
.isx-meta{font-size:12.5px;color:var(--ink-soft,#626c80)}
.isx-sum{margin-left:auto;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.isx-img{display:grid;grid-template-columns:22px 56px minmax(0,1fr);gap:10px;padding:10px 12px;border-top:1px solid var(--border,#eef0f5);align-items:start;min-width:0}
.isx-img img{width:56px;height:56px;object-fit:cover;border-radius:8px;background:var(--surface-2,#f2f4fb);display:block}
.isx-img .isx-body{min-width:0;display:grid;gap:3px}
.isx-url{display:flex;gap:8px;align-items:flex-start;min-width:0}
.isx-url code,.isx-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;overflow-wrap:anywhere;word-break:break-all;min-width:0}
.isx-score{flex:none;font-weight:800;font-size:12px;border-radius:7px;padding:2px 7px;color:#fff;white-space:nowrap}
.isx-score.is-good{background:#15803d}.isx-score.is-mid{background:#b45309}.isx-score.is-low{background:#b91c1c}
.isx-why{font-size:12px;color:var(--ink-soft,#626c80);line-height:1.45;overflow-wrap:anywhere}
.isx-new{font-size:12.5px;line-height:1.45;overflow-wrap:anywhere}
.isx-new .isx-arrow{color:var(--accent,#15a85a);font-weight:800}
.isx-tag{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:1px 8px;margin-right:4px;white-space:nowrap}
.isx-tag.is-rename{background:rgba(21,168,90,.14);color:#0b6e3a}
.isx-tag.is-ok{background:rgba(21,128,61,.12);color:#15803d}
.isx-tag.is-skip{background:rgba(0,0,0,.07);color:var(--ink-soft,#626c80)}
.isx-tag.is-fail{background:rgba(185,28,28,.12);color:#b91c1c}
.isx-pager{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center;margin-top:14px;font-size:13px}
.isx-empty{padding:26px;text-align:center;color:var(--ink-soft,#626c80)}
.isx-msg{border-radius:10px;padding:9px 12px;font-size:13.5px;line-height:1.5;margin-top:10px;overflow-wrap:anywhere}
.isx-msg.is-ok{background:rgba(22,163,74,.1);color:#166534}
.isx-msg.is-bad{background:rgba(220,38,38,.09);color:#991b1b}
.isx-msg.is-info{background:rgba(0,0,0,.045)}
.isx-progress{height:10px;border-radius:999px;background:rgba(0,0,0,.08);overflow:hidden;margin:10px 0 6px}
.isx-progress i{display:block;height:100%;background:var(--accent,#15a85a);width:0}
.isx-log{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:4px;max-height:360px;overflow:auto;font-size:12.5px}
.isx-log li{border:1px solid var(--border,#eef0f5);border-radius:8px;padding:6px 9px;overflow-wrap:anywhere;min-width:0}
.isx-rubric{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
.isx-rubric td{border-top:1px solid var(--border,#eef0f5);padding:5px 6px;vertical-align:top}
.isx-rubric td:first-child{width:52px;font-weight:800;white-space:nowrap}
.isx details summary{cursor:pointer;font-weight:650;font-size:13.5px}
.isx-alt{display:grid;grid-template-columns:56px minmax(0,1fr);gap:10px;padding:10px 12px;border-top:1px solid var(--border,#eef0f5);min-width:0}
.isx-alt img{width:56px;height:56px;object-fit:cover;border-radius:8px;display:block}
.isx-alt .isx-in{font-size:13.5px}
.isx-count{font-size:11.5px;color:var(--ink-soft,#626c80)}
.isx-opts{display:grid;gap:8px;margin-top:6px}
.isx-opts label{display:flex;gap:8px;align-items:flex-start;font-size:13.5px;line-height:1.45}
.isx-jobs{width:100%;border-collapse:collapse;font-size:13px}
.isx-jobs td,.isx-jobs th{border-top:1px solid var(--border,#eef0f5);padding:7px 6px;text-align:left;vertical-align:top}
@media (max-width:900px){.isx-form{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.isx-form .isx-field:first-child{grid-column:1 / -1}}
@media (max-width:640px){
  .isx-card{padding:12px}
  .isx-img{grid-template-columns:22px 48px minmax(0,1fr);gap:8px;padding:9px 10px}
  .isx-img img{width:48px;height:48px}
  .isx-sum{margin-left:0}
  .isx-jobs thead{display:none}
  .isx-jobs,.isx-jobs tbody{display:block}
  .isx-jobs tr{display:flex;flex-wrap:wrap;gap:4px 10px;padding:8px 0;border-top:1px solid var(--border,#eef0f5)}
  .isx-jobs td{border:0;padding:0}
}
</style>
<script>
(function () {
  'use strict';
  var SCREEN = 'imageseo';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  var boot = null;            // GET /image-seo
  var tab = 'find';
  var q = { q: '', brand: 0, category: 0, filter: '', sort: 'name', page: 1 };
  var found = null;           // last /find answer
  var plans = {};             // product id -> last plan seen
  var sel = {};               // product id -> true (all) | {rel: true}
  var strategy = 'variations';
  var shared = false;
  var preview = null;         // rename preview items
  var previewToken = '';
  var job = null;             // the run on screen
  var jobBusy = false;
  var stopAsked = false;
  var liveCheck = null;
  var alt = { template: 'variations', first: '{full}', rest: '{name} by {brand} – view {index}', keep: true, items: null, edits: {}, token: '' };
  var msg = null;
  var scoring = null;         // {left, running}
  var busy = '';

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function adminApi() { return BASE.replace(/\/[^\/]*$/, '') + '/admin-api'; }

  async function api(path, body, method) {
    var opts = { headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.method = method || 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var r;
    try { r = await fetch(adminApi() + path, opts); } catch (e) { return { status: 0, body: { message: 'The shop could not be reached. Check your connection and try again.' } }; }
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    return { status: r.status, body: payload || {} };
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fail(r) {
    if (r.status === 404 && !(r.body && r.body.message)) return 'This screen\'s endpoints are not in the server\'s route table yet. Go to Platform → Cache, press Clear everything, and reload.';
    if (r.status === 403) return 'Your account may not use Image SEO. Ask the owner for the "Image SEO" permission.';
    if (r.status === 419) return 'Your session expired. Reload the page and sign in again.';
    if (r.status === 422 && r.body && r.body.errors) { var k = Object.keys(r.body.errors)[0]; return String(r.body.errors[k][0] || r.body.message); }
    return (r.body && r.body.message) || 'Something went wrong (' + r.status + '). Nothing was changed.';
  }

  function token() {
    var a = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  /* ------------------------------------------------------------ pieces */
  function band(ten) { return ten >= 8 ? 'is-good' : (ten >= 5 ? 'is-mid' : 'is-low'); }

  function scoreBadge(ten, reasons) {
    return '<span class="isx-score ' + band(ten) + '" title="' + esc(reasons || 'Nothing to improve') + '">' + (ten >= 8 ? '✓ ' : '') + esc(ten) + '/10</span>';
  }

  function ten(score) { return Math.floor(Math.max(0, Math.min(100, score)) / 10 + 0.5); }

  function selCount() {
    var products = 0, pictures = 0;
    Object.keys(sel).forEach(function (id) {
      products++;
      if (sel[id] === true) { pictures += plans[id] ? plans[id].images.filter(function (i) { return i.rel; }).length : 0; }
      else pictures += Object.keys(sel[id]).length;
    });
    return { products: products, pictures: pictures };
  }

  function selectionPayload() {
    return Object.keys(sel).map(function (id) {
      return sel[id] === true ? { p: +id } : { p: +id, only: Object.keys(sel[id]) };
    });
  }

  function isPicked(pid, rel) {
    var s = sel[pid];
    if (!s) return false;
    return s === true || !!s[rel];
  }

  function show(m) { msg = m; }

  /* ------------------------------------------------------------- render */
  function render() {
    var host = document.querySelector('[data-isx-screen]');
    if (!host) return;
    if (!boot) { host.innerHTML = '<div class="isx-card"><p class="isx-sub">' + (msg ? esc(msg.text) : 'Loading Image SEO…') + '</p></div>'; return; }
    var c = selCount();
    host.innerHTML = head()
      + '<div class="isx-tabs" role="tablist">'
      + tabBtn('find', 'Find') + tabBtn('rename', 'Rename files') + tabBtn('alt', 'ALT text') + tabBtn('history', 'History')
      + '</div>'
      + (c.products ? '<div class="isx-bar"><span><b>' + esc(c.products) + '</b> product(s) selected · ' + esc(c.pictures) + ' picture(s)</span>'
        + '<span class="isx-row"><button type="button" class="isx-btn is-quiet" data-isx="tab-rename">Rename…</button>'
        + '<button type="button" class="isx-btn is-quiet" data-isx="tab-alt">ALT text…</button>'
        + '<button type="button" class="isx-btn is-quiet" data-isx="clear-sel">Clear</button></span></div>' : '')
      + (msg ? '<div class="isx-msg ' + (msg.ok === true ? 'is-ok' : (msg.ok === false ? 'is-bad' : 'is-info')) + '" role="status" style="margin:0 0 12px">' + esc(msg.text) + '</div>' : '')
      + (tab === 'find' ? findTab() : tab === 'rename' ? renameTab() : tab === 'alt' ? altTab() : historyTab());
  }

  function tabBtn(id, label) {
    return '<button type="button" role="tab" aria-selected="' + (tab === id) + '" class="isx-tab' + (tab === id ? ' on' : '') + '" data-isx="tab-' + id + '">' + esc(label) + '</button>';
  }

  function head() {
    var r = boot.rubric.map(function (x) { return '<tr><td>' + esc(x.points) + '</td><td>' + esc(x.group) + '</td><td>' + esc(x.rule) + '</td></tr>'; }).join('');
    var s = '';
    if (scoring && scoring.running) s = '<div class="isx-msg is-info">Scoring the library… ' + esc(scoring.left) + ' picture(s) left.</div>';
    else if (boot.unscored > 0) s = '<div class="isx-msg is-info">' + esc(boot.unscored) + ' library picture(s) have no score yet. <button type="button" class="isx-btn is-quiet" data-isx="score">Score them now</button></div>';
    var resume = '';
    var rj = boot.resumable;
    if (rj && (!job || job.id !== rj.id)) {
      resume = '<div class="isx-msg is-info">Run #' + esc(rj.id) + ' (' + esc(rj.kind) + ') stopped at ' + esc(rj.position) + ' of ' + esc(rj.total) + ' products. '
        + '<button type="button" class="isx-btn is-quiet" data-isx="resume" data-isx-job="' + esc(rj.id) + '">Resume</button></div>';
    }
    return '<div class="isx-card isx-head"><h2>Image SEO</h2>'
      + '<p class="isx-sub">Find product pictures whose file name or alt text does not describe the product, rename the files to the product name (each picture a different word order) and write alt text in bulk. Every old address keeps working: it redirects to the new name.</p>'
      + s + resume
      + '<details style="margin-top:10px"><summary>How the score works</summary>'
      + '<p class="isx-sub" style="margin-top:6px">Each picture scores out of 10 (100 points). <b>✓ at 8/10 or more.</b> In the Media Library the green ✓ also needs the file to have been renamed here. The line under each URL says what cost points.</p>'
      + '<table class="isx-rubric"><tbody>' + r + '</tbody></table>'
      + '<p class="isx-sub" style="margin-top:8px">What Google says matters, in order: alt text, the words around the picture and the page it is on, then the file name. Rename once and keep names stable; old names redirect so pictures already in Google Images are kept.</p>'
      + '</details></div>';
  }

  /* ----------------------------------------------------------- Find tab */
  function opt(v, label, cur) { return '<option value="' + esc(v) + '"' + (String(v) === String(cur) ? ' selected' : '') + '>' + esc(label) + '</option>'; }

  function findTab() {
    var brands = '<option value="0">All brands</option>' + boot.brands.map(function (b) { return opt(b.id, b.name, q.brand); }).join('');
    var cats = '<option value="0">All categories</option>' + boot.categories.map(function (b) { return opt(b.id, b.name, q.category); }).join('');
    var form = '<form class="isx-card" data-isx-form="find"><div class="isx-form">'
      + '<div class="isx-field"><label for="isx-q">Product, brand or SKU</label><input id="isx-q" class="isx-in" name="q" maxlength="120" value="' + esc(q.q) + '" placeholder="e.g. Medicube PDRN"></div>'
      + '<div class="isx-field"><label for="isx-brand">Brand</label><select id="isx-brand" class="isx-sel" name="brand">' + brands + '</select></div>'
      + '<div class="isx-field"><label for="isx-cat">Category</label><select id="isx-cat" class="isx-sel" name="category">' + cats + '</select></div>'
      + '<div class="isx-field"><label for="isx-filter">Show</label><select id="isx-filter" class="isx-sel" name="filter">'
      + opt('', 'Everything', q.filter) + opt('not_renamed', 'Not renamed yet', q.filter) + opt('low', 'Score below 8/10', q.filter) + opt('missing_alt', 'Missing alt text', q.filter) + '</select></div>'
      + '<div class="isx-field"><label for="isx-sort">Order</label><select id="isx-sort" class="isx-sel" name="sort">'
      + opt('name', 'Name A–Z', q.sort) + opt('attention', 'Needs attention first', q.sort) + opt('newest', 'Newest first', q.sort) + '</select></div>'
      + '<div class="isx-field"><label>&nbsp;</label><button class="isx-btn" type="submit"' + (busy === 'find' ? ' disabled' : '') + '>' + (busy === 'find' ? 'Searching…' : 'Search') + '</button></div>'
      + '</div></form>';

    if (!found) return form + '<div class="isx-card isx-empty">' + (busy === 'find' ? 'Searching…' : 'Search to see products and their pictures.') + '</div>';
    if (!found.items.length) return form + '<div class="isx-card isx-empty">No products match.</div>';

    var allOnPage = found.items.every(function (p) { return sel[p.id] === true; });
    var top = '<div class="isx-row" style="justify-content:space-between"><span class="isx-meta">' + esc(found.total) + ' product(s) · page ' + esc(found.page) + ' of ' + esc(found.pages) + '</span>'
      + '<span class="isx-row"><button type="button" class="isx-btn is-quiet" data-isx="page-all">' + (allOnPage ? 'Unselect this page' : 'Select this page') + '</button>'
      + '<button type="button" class="isx-btn is-quiet" data-isx="all-matches"' + (busy === 'ids' ? ' disabled' : '') + '>Select all ' + esc(found.total) + ' matches</button></span></div>';

    return form + '<div class="isx-card">' + top + found.items.map(productCard).join('') + pager() + '</div>';
  }

  function productCard(p) {
    var picked = sel[p.id] === true;
    var low = p.lowest == null ? '' : '<span class="isx-meta">lowest</span>' + scoreBadge(ten(p.lowest), '') + '<span class="isx-meta">average ' + esc(ten(p.average)) + '/10</span>';
    return '<div class="isx-prod"><div class="isx-ph">'
      + '<input type="checkbox" aria-label="Select every picture of ' + esc(p.name) + '" data-isx="pick-product" data-isx-p="' + esc(p.id) + '"' + (picked ? ' checked' : '') + '>'
      + '<b>' + esc(p.name) + '</b><span class="isx-meta">' + esc([p.brand, p.sku ? 'SKU ' + p.sku : '', p.category, p.status !== 'publish' ? p.status : ''].filter(Boolean).join(' · ')) + '</span>'
      + '<span class="isx-sum">' + (p.images.length ? low + '<span class="isx-meta">· ' + esc(p.to_rename) + ' to rename</span>' : '<span class="isx-meta">no pictures</span>') + '</span></div>'
      + p.images.map(function (i) { return imageRow(p, i); }).join('') + '</div>';
  }

  function action(i) {
    if (i.action === 'rename') return '<span class="isx-tag is-rename">will rename</span>';
    if (i.action === 'repoint') return '<span class="isx-tag is-rename">will fix address</span>';
    if (i.action === 'ok') return '<span class="isx-tag is-ok">already named</span>';
    return '<span class="isx-tag is-skip">skipped</span>' + esc(i.reason || '');
  }

  function imageRow(p, i) {
    var can = !!i.rel;
    var after = i.score_after != null && i.score_after !== i.score ? ' <span class="isx-meta">→ ' + esc(ten(i.score_after)) + '/10 after</span>' : '';
    return '<div class="isx-img">'
      + '<input type="checkbox" aria-label="Select this picture"' + (can ? '' : ' disabled') + ' data-isx="pick-image" data-isx-p="' + esc(p.id) + '" data-isx-rel="' + esc(i.rel || '') + '"' + (can && isPicked(p.id, i.rel) ? ' checked' : '') + '>'
      + '<img src="' + esc(i.thumb) + '" alt="" loading="lazy" decoding="async" width="56" height="56">'
      + '<div class="isx-body">'
      + '<div class="isx-url">' + scoreBadge(i.ten, i.reasons) + '<code>' + esc(i.url) + '</code></div>'
      + (i.reasons ? '<div class="isx-why">' + esc(i.reasons) + '</div>' : '<div class="isx-why">Nothing to improve.</div>')
      + '<div class="isx-new">' + action(i) + (i.proposed && i.action !== 'ok' ? ' <span class="isx-arrow">→</span> <code class="isx-code">' + esc(i.proposed) + '</code>' + after : '') + '</div>'
      + '<div class="isx-why">Alt: ' + esc(i.alt) + (i.alt_written ? '' : ' <i>(automatic)</i>') + (i.shared > 1 ? ' · shared by ' + esc(i.shared) + ' products' : '') + ' · ' + esc(i.role) + '</div>'
      + '</div></div>';
  }

  function pager() {
    if (!found || found.pages <= 1) return '';
    return '<div class="isx-pager"><button type="button" class="isx-btn is-quiet" data-isx="prev"' + (found.page <= 1 ? ' disabled' : '') + '>Previous</button>'
      + '<span>Page ' + esc(found.page) + ' of ' + esc(found.pages) + '</span>'
      + '<button type="button" class="isx-btn is-quiet" data-isx="next"' + (found.page >= found.pages ? ' disabled' : '') + '>Next</button></div>';
  }

  async function find(page) {
    q.page = page || 1;
    busy = 'find'; render();
    var qs = Object.keys(q).filter(function (k) { return q[k] !== '' && q[k] !== 0; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(q[k]); });
    qs.push('strategy=' + encodeURIComponent(strategy));
    if (shared) qs.push('shared=1');
    var r = await api('/image-seo/find?' + qs.join('&'));
    busy = '';
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    found = r.body;
    found.items.forEach(function (p) { plans[p.id] = p; });
    msg = null;
    render();
  }

  /* --------------------------------------------------------- Rename tab */
  function renameTab() {
    var c = selCount();
    var opts = '<div class="isx-opts">'
      + '<label><input type="radio" name="isx-strategy" value="variations" data-isx="strategy"' + (strategy === 'variations' ? ' checked' : '') + '><span><b>Word-order variations</b> (recommended): medicube-pdrn-eye-patches, pdrn-medicube-eye-patches, eye-patches-pdrn-by-medicube…</span></label>'
      + '<label><input type="radio" name="isx-strategy" value="numbered" data-isx="strategy"' + (strategy === 'numbered' ? ' checked' : '') + '><span><b>Name + view number</b>: medicube-pdrn-eye-patches, medicube-pdrn-eye-patches-2, -3…</span></label>'
      + '<label><input type="checkbox" data-isx="shared"' + (shared ? ' checked' : '') + '><span>Include pictures shared by several products (named after the product being renamed; every product keeps showing it)</span></label>'
      + '</div>';
    var head = '<div class="isx-card"><b>Rename files</b><p class="isx-sub">' + (c.products ? esc(c.products) + ' product(s) selected.' : 'Select products on the Find tab first.')
      + ' Nothing changes until you press Start. Names are lower case, hyphenated, made from the product title; the old address of every file redirects to the new one.</p>' + opts
      + '<div class="isx-row" style="margin-top:10px"><button type="button" class="isx-btn is-quiet" data-isx="preview"' + (!c.products || busy || jobBusy ? ' disabled' : '') + '>' + (busy === 'preview' ? 'Preparing…' : 'Preview changes') + '</button>'
      + '<button type="button" class="isx-btn" data-isx="start"' + (!preview || !preview.rename || jobBusy || busy ? ' disabled' : '') + '>Start renaming' + (preview ? ' ' + esc(preview.rename) + ' file(s)' : '') + '</button></div></div>';
    return head + (job && job.kind !== 'alt' ? jobCard() : '') + previewCard();
  }

  function previewCard() {
    if (!preview) return '';
    var rows = [];
    preview.items.forEach(function (p) {
      p.images.forEach(function (i) {
        if (i.reason === 'not selected') return;
        rows.push('<li><b>' + esc(p.name) + '</b><br>' + action(i)
          + (i.action === 'rename' || i.action === 'repoint' ? '<br><code class="isx-code">' + esc(i.rel) + '</code> <span class="isx-arrow" style="color:#15a85a;font-weight:800">→</span> <code class="isx-code">' + esc(i.proposed_rel) + '</code>' : '')
          + '</li>');
      });
    });
    return '<div class="isx-card"><b>Preview</b> <span class="isx-meta">' + esc(preview.rename) + ' to rename · ' + esc(preview.ok) + ' already named · ' + esc(preview.skip) + ' skipped</span>'
      + (preview.partial ? '<div class="isx-msg is-info">Showing the first ' + esc(preview.items.length) + ' products; the run covers all ' + esc(selCount().products) + '.</div>' : '')
      + '<ul class="isx-log" style="max-height:480px">' + rows.join('') + '</ul></div>';
  }

  async function doPreview() {
    var all = selectionPayload();
    if (!all.length) return;
    busy = 'preview'; preview = null; render();
    var r = await api('/image-seo/preview', { products: all.slice(0, 50), strategy: strategy, include_shared: shared });
    busy = '';
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    var n = { rename: 0, ok: 0, skip: 0 };
    r.body.items.forEach(function (p) {
      plans[p.id] = p;
      p.images.forEach(function (i) {
        if (i.reason === 'not selected') return;
        if (i.action === 'rename' || i.action === 'repoint') n.rename++; else if (i.action === 'ok') n.ok++; else n.skip++;
      });
    });
    preview = { items: r.body.items, rename: n.rename, ok: n.ok, skip: n.skip, partial: all.length > 50 };
    // More than 50 products: the count of the rest is the run's to report.
    if (all.length > 50 && !n.rename) preview.rename = 1;
    previewToken = token();
    msg = null;
    render();
  }

  async function startRename() {
    if (!preview) return;
    if (!window.confirm('Rename the selected pictures now? Every old address will redirect to the new name, and the run can be undone from History.')) return;
    var r = await api('/image-seo/start', { token: previewToken, products: selectionPayload(), strategy: strategy, include_shared: shared });
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    job = r.body.job; liveCheck = null;
    run();
  }

  /* -------------------------------------------------------------- a run */
  function jobCard() {
    if (!job) return '';
    var pct = job.total ? Math.round(job.position / job.total * 100) : 100;
    var kind = job.kind === 'alt' ? (job.undo_of ? 'Undo of run #' + job.undo_of : 'Alt text') : job.kind === 'undo' ? 'Undo of run #' + job.undo_of : 'Rename';
    var log = (job.log || []).slice().reverse().slice(0, 60).map(function (e) {
      var cls = e.status === 'rolled_back' ? 'is-fail' : (e.status === 'skipped' ? 'is-skip' : (e.status === 'ok' ? 'is-ok' : 'is-rename'));
      return '<li><span class="isx-tag ' + cls + '">' + esc(e.status.replace('_', ' ')) + '</span><b>' + esc(e.name) + '</b>'
        + (e.rel ? '<br><code class="isx-code">' + esc(e.rel) + '</code>' + (e.to && e.status !== 'skipped' ? ' → <code class="isx-code">' + esc(e.to) + '</code>' : '') : '')
        + (e.reason ? '<br><span class="isx-why">' + esc(e.reason) + '</span>' : '')
        + (e.refs ? '<span class="isx-why"> · ' + esc(e.refs) + ' reference(s) updated · ' + esc(e.files) + ' file(s) moved</span>' : '') + '</li>';
    }).join('');
    var check = liveCheck ? '<div class="isx-msg ' + (liveCheck.ok ? 'is-ok' : 'is-bad') + '">' + esc(liveCheck.text) + '</div>' : '';
    var buttons = '';
    if (jobBusy) buttons = '<button type="button" class="isx-btn is-quiet" data-isx="stop">Stop after this step</button>';
    else if (job.status !== 'done') buttons = '<button type="button" class="isx-btn" data-isx="resume" data-isx-job="' + esc(job.id) + '">Resume</button>';
    else if ((job.kind === 'rename' || (job.kind === 'alt' && !job.undo_of)) && !job.undone) buttons = '<button type="button" class="isx-btn is-quiet" data-isx="undo" data-isx-job="' + esc(job.id) + '">Undo this run</button>';
    return '<div class="isx-card"><b>' + esc(kind) + ' · run #' + esc(job.id) + '</b> <span class="isx-meta">' + esc(job.status === 'done' ? 'finished' : (jobBusy ? 'running' : 'paused')) + '</span>'
      + '<div class="isx-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><i style="width:' + pct + '%"></i></div>'
      + '<div class="isx-meta">' + esc(job.position) + ' of ' + esc(job.total) + ' products · ' + esc(job.renamed) + ' done · ' + esc(job.skipped) + ' skipped · ' + esc(job.failed) + ' rolled back</div>'
      + check + '<div class="isx-row" style="margin-top:8px">' + buttons + '</div>'
      + (log ? '<ul class="isx-log">' + log + '</ul>' : '') + '</div>';
  }

  async function run() {
    if (!job || jobBusy) return;
    jobBusy = true; stopAsked = false; render();
    var lastRenamed = null;
    while (job && job.status !== 'done' && !stopAsked) {
      var r = await api('/image-seo/step', { job: job.id });
      if (r.status === 202) { show({ ok: null, text: 'Another window is running a step of Image SEO right now. Press Resume in a moment.' }); break; }
      if (r.status !== 200) { show({ ok: false, text: fail(r) }); break; }
      if (r.body.job && r.body.job.id) job = r.body.job;
      (r.body.results || []).forEach(function (x) { if (x.status === 'renamed' || x.status === 'restored') lastRenamed = x; });
      render();
    }
    if (stopAsked && job && job.status !== 'done') {
      var s = await api('/image-seo/stop', { job: job.id });
      if (s.body.job) job = s.body.job;
    }
    jobBusy = false;
    // A finished run spends its preview and its alt list: what they showed is
    // now the shop's state, and Start again would only return the same run.
    if (job && job.status === 'done') {
      if (job.kind === 'alt') { alt.items = null; alt.edits = {}; } else { preview = null; }
    }
    if (lastRenamed) await check(lastRenamed);
    reboot();
  }

  /* The owner's "sense everything": ask the live site, through whatever sits
     in front of it, whether the old address now redirects. One pair of
     requests per run, never a loop. */
  async function check(x) {
    var root = adminApi().replace(/\/admin-api$/, '');
    var oldUrl = root + '/' + x.rel.split('/').map(encodeURIComponent).join('/');
    var newUrl = root + '/' + x.to.split('/').map(encodeURIComponent).join('/');
    try {
      var a = await fetch(oldUrl, { redirect: 'manual', cache: 'no-store', credentials: 'omit' });
      var b = await fetch(newUrl, { method: 'HEAD', cache: 'no-store', credentials: 'omit' });
      var redirected = a.type === 'opaqueredirect' || (a.status >= 300 && a.status < 400);
      liveCheck = redirected && b.ok
        ? { ok: true, text: '✓ Checked live: ' + x.rel.split('/').pop() + ' redirects, and ' + x.to.split('/').pop() + ' loads.' }
        : { ok: false, text: 'Live check: the old address answered ' + (redirected ? 'a redirect' : a.status) + ' and the new one ' + b.status + '. If the old one says 404, the web server answers missing pictures itself — see docs/IMAGE-SEO-GUIDE.md, "If an old address says 404".' };
    } catch (e) {
      liveCheck = { ok: false, text: 'The live check could not run from this browser. The renames themselves were verified on the server.' };
    }
    render();
  }

  /* ------------------------------------------------------------ ALT tab */
  function altTab() {
    var c = selCount();
    var opts = '<div class="isx-opts">'
      + '<label><input type="radio" name="isx-tpl" value="variations" data-isx="tpl"' + (alt.template === 'variations' ? ' checked' : '') + '><span><b>Natural variations</b> (recommended): “Medicube PDRN Eye Patches”, “PDRN Eye Patches by Medicube”, “Medicube PDRN Eye Patches – Eye Care”…</span></label>'
      + '<label><input type="radio" name="isx-tpl" value="numbered" data-isx="tpl"' + (alt.template === 'numbered' ? ' checked' : '') + '><span><b>Name + view</b>: “Medicube PDRN Eye Patches – view 2 of 5”</span></label>'
      + '<label><input type="radio" name="isx-tpl" value="custom" data-isx="tpl"' + (alt.template === 'custom' ? ' checked' : '') + '><span><b>My own wording</b>, with {name} {brand} {full} {category} {index} {total}</span></label>'
      + (alt.template === 'custom' ? '<div class="isx-form" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)"><div class="isx-field"><label for="isx-first">First picture</label><input id="isx-first" class="isx-in" maxlength="200" data-isx-in="first" value="' + esc(alt.first) + '"></div>'
        + '<div class="isx-field"><label for="isx-rest">Other pictures</label><input id="isx-rest" class="isx-in" maxlength="200" data-isx-in="rest" value="' + esc(alt.rest) + '"></div></div>' : '')
      + '<label><input type="checkbox" data-isx="keep"' + (alt.keep ? ' checked' : '') + '><span>Keep alt text somebody already wrote</span></label></div>';
    var head = '<div class="isx-card"><b>ALT text</b><p class="isx-sub">' + (c.products ? esc(c.products) + ' product(s) selected.' : 'Select products on the Find tab first.')
      + ' Alt text is what Google Images reads and what a screen reader says. 5–125 characters, describes the picture, not the same on every picture. You can edit every line before applying.'
      + ' <b>English only:</b> this shop stores one alt per picture, and it is shown on the Arabic shop too; a picture with no alt written keeps its automatic Arabic one there.</p>' + opts
      + '<div class="isx-row" style="margin-top:10px"><button type="button" class="isx-btn is-quiet" data-isx="alt-preview"' + (!c.products || busy || jobBusy ? ' disabled' : '') + '>' + (busy === 'alt-preview' ? 'Preparing…' : 'Preview alt text') + '</button>'
      + '<button type="button" class="isx-btn" data-isx="alt-apply"' + (!alt.items || jobBusy || busy ? ' disabled' : '') + '>Apply alt text</button></div></div>';
    return head + (job && job.kind === 'alt' ? jobCard() : '') + altList();
  }

  function altKey(p, url) { return p + '|' + url; }

  function altList() {
    if (!alt.items) return '';
    var withPictures = alt.items.filter(function (p) { return p.images.length; });
    if (!withPictures.length) return '<div class="isx-card isx-empty">None of the selected products has a picture.</div>';
    return '<div class="isx-card">' + withPictures.map(function (p) {
      return '<div class="isx-prod"><div class="isx-ph"><b>' + esc(p.name) + '</b><span class="isx-meta">' + esc([p.brand, p.category].filter(Boolean).join(' · ')) + '</span></div>'
        + p.images.map(function (i) {
          var k = altKey(p.id, i.url);
          var v = alt.edits[k] != null ? alt.edits[k] : i.proposed;
          return '<div class="isx-alt"><img src="' + esc(i.thumb) + '" alt="" loading="lazy" decoding="async" width="56" height="56"><div class="isx-body" style="display:grid;gap:4px;min-width:0">'
            + '<div class="isx-url">' + scoreBadge(i.ten, i.reasons) + '<span class="isx-why">now: ' + esc(i.alt) + (i.alt_written ? '' : ' (automatic)') + '</span></div>'
            + '<input class="isx-in" maxlength="125" aria-label="Alt text for ' + esc(i.filename) + '" data-isx-alt="' + esc(k) + '" value="' + esc(v) + '"' + (i.keep ? ' disabled' : '') + '>'
            + '<span class="isx-count">' + (i.keep ? 'kept as written' : esc(v.length) + '/125 · ' + esc(i.ten_after) + '/10 after' + (i.reasons_after ? ' · ' + esc(i.reasons_after) : '')) + '</span>'
            + '</div></div>';
        }).join('') + '</div>';
    }).join('') + '</div>';
  }

  async function altPreview() {
    var ids = Object.keys(sel).map(Number);
    if (!ids.length) return;
    busy = 'alt-preview'; alt.items = null; alt.edits = {}; render();
    var items = [];
    for (var i = 0; i < ids.length; i += 50) {
      var r = await api('/image-seo/alt-preview', { products: ids.slice(i, i + 50), template: alt.template, first: alt.first, rest: alt.rest, only_missing: alt.keep });
      if (r.status !== 200) { busy = ''; show({ ok: false, text: fail(r) }); render(); return; }
      items = items.concat(r.body.items);
    }
    busy = ''; alt.items = items; alt.token = token(); msg = null; render();
  }

  async function altApply() {
    if (!alt.items) return;
    var out = [];
    var bad = 0;
    alt.items.forEach(function (p) {
      var alts = {};
      p.images.forEach(function (i) {
        if (i.keep) return;
        var k = altKey(p.id, i.url);
        var v = String(alt.edits[k] != null ? alt.edits[k] : i.proposed).trim();
        if (v.length < 5 || v.length > 125) { bad++; return; }
        if (i.alt_written && v === i.alt) return;
        alts[i.url] = v;
      });
      if (Object.keys(alts).length) out.push({ p: p.id, alts: alts });
    });
    if (!out.length) { show({ ok: null, text: bad ? bad + ' alt text(s) are not 5–125 characters; nothing else to change.' : 'Nothing to change.' }); render(); return; }
    if (!window.confirm('Write alt text for ' + out.length + ' product(s)? It can be undone from History.')) return;
    var r = await api('/image-seo/alt-start', { token: alt.token, items: out });
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    job = r.body.job; liveCheck = null;
    if (bad) show({ ok: null, text: bad + ' alt text(s) were left out: each must be 5–125 characters.' });
    run();
  }

  /* -------------------------------------------------------- History tab */
  function historyTab() {
    var rows = (boot.jobs || []).map(function (j) {
      var kind = j.kind === 'undo' || j.undo_of ? 'Undo of #' + j.undo_of : (j.kind === 'alt' ? 'Alt text' : 'Rename');
      var can = j.status === 'done' && !j.undo_of && (j.kind === 'rename' || j.kind === 'alt');
      return '<tr><td>#' + esc(j.id) + '</td><td>' + esc(kind) + '</td><td>' + esc(j.created_at) + (j.by ? '<br><span class="isx-meta">' + esc(j.by) + '</span>' : '') + '</td>'
        + '<td>' + esc(j.renamed) + ' done · ' + esc(j.skipped) + ' skipped · ' + esc(j.failed) + ' rolled back<br><span class="isx-meta">' + esc(j.position) + '/' + esc(j.total) + ' products · ' + esc(j.status) + '</span></td>'
        + '<td class="isx-row"><button type="button" class="isx-btn is-quiet" data-isx="open-job" data-isx-job="' + esc(j.id) + '">Log</button>'
        + (j.status !== 'done' ? '<button type="button" class="isx-btn is-quiet" data-isx="resume" data-isx-job="' + esc(j.id) + '">Resume</button>' : '')
        + (can ? '<button type="button" class="isx-btn is-quiet" data-isx="undo" data-isx-job="' + esc(j.id) + '">Undo</button>' : '') + '</td></tr>';
    }).join('');
    return jobCard() + '<div class="isx-card"><b>Runs</b>' + (rows ? '<table class="isx-jobs"><thead><tr><th>Run</th><th>What</th><th>When</th><th>Result</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>' : '<p class="isx-sub">No runs yet.</p>') + '</div>';
  }

  async function openJob(id) {
    var r = await api('/image-seo/job?id=' + encodeURIComponent(id));
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    job = r.body.job; liveCheck = null; render();
  }

  async function undo(id) {
    if (!window.confirm('Undo run #' + id + '? Every picture it renamed gets its old name back (and its old address works again), or every alt text it wrote is put back.')) return;
    var r = await api('/image-seo/undo', { job: +id, confirm: 'UNDO' });
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    job = r.body.job; liveCheck = null;
    run();
  }

  /* --------------------------------------------------- score backfill */
  async function score() {
    if (scoring && scoring.running) return;
    scoring = { running: true, left: boot.unscored };
    render();
    var after = 0;
    for (var guard = 0; guard < 500; guard++) {
      var r = await api('/image-seo/score', { after: after });
      if (r.status !== 200) { show({ ok: false, text: fail(r) }); break; }
      scoring.left = r.body.left; after = r.body.next; render();
      if (r.body.done) break;
    }
    scoring = { running: false, left: 0 };
    boot.unscored = 0;
    render();
  }

  async function reboot() {
    var r = await api('/image-seo');
    if (r.status === 200) boot = r.body;
    if (found) await find(q.page); else render();
  }

  async function load() {
    var r = await api('/image-seo');
    if (r.status !== 200) { boot = null; show({ ok: false, text: fail(r) }); render(); return; }
    boot = r.body;
    render();
    if (boot.unscored > 0) score();
  }

  /* ------------------------------------------------------------ events */
  function within(e) { return e.target && e.target.closest && e.target.closest('[data-isx-screen]'); }

  document.addEventListener('submit', function (e) {
    if (!within(e)) return;
    var f = e.target.closest('[data-isx-form="find"]');
    if (!f) return;
    e.preventDefault();
    q.q = f.elements.q.value.trim();
    q.brand = +f.elements.brand.value || 0;
    q.category = +f.elements.category.value || 0;
    q.filter = f.elements.filter.value;
    q.sort = f.elements.sort.value;
    find(1);
  });

  document.addEventListener('input', function (e) {
    if (!within(e)) return;
    var t = e.target;
    if (t.hasAttribute('data-isx-alt')) {
      alt.edits[t.getAttribute('data-isx-alt')] = t.value;
      var count = t.nextElementSibling;
      if (count) count.textContent = t.value.length + '/125' + (t.value.trim().length < 5 ? ' · too short' : '');
    } else if (t.hasAttribute('data-isx-in')) {
      alt[t.getAttribute('data-isx-in')] = t.value;
    }
  });

  document.addEventListener('change', function (e) {
    if (!within(e)) return;
    var t = e.target;
    var a = t.getAttribute('data-isx');
    if (a === 'pick-product') {
      var pid = t.getAttribute('data-isx-p');
      if (t.checked) sel[pid] = true; else delete sel[pid];
      preview = null; render();
    } else if (a === 'pick-image') {
      var p = t.getAttribute('data-isx-p'), rel = t.getAttribute('data-isx-rel');
      var cur = sel[p];
      if (cur === true) { cur = {}; (plans[p] ? plans[p].images : []).forEach(function (i) { if (i.rel) cur[i.rel] = true; }); }
      cur = cur || {};
      if (t.checked) cur[rel] = true; else delete cur[rel];
      if (Object.keys(cur).length) sel[p] = cur; else delete sel[p];
      preview = null; render();
    } else if (a === 'strategy') { strategy = t.value; preview = null; render(); }
    else if (a === 'shared') { shared = t.checked; preview = null; render(); }
    else if (a === 'tpl') { alt.template = t.value; alt.items = null; render(); }
    else if (a === 'keep') { alt.keep = t.checked; alt.items = null; render(); }
  });

  document.addEventListener('click', function (e) {
    if (!within(e)) return;
    var b = e.target.closest('button[data-isx]');
    if (!b || b.disabled) return;
    var a = b.getAttribute('data-isx');
    var id = b.getAttribute('data-isx-job');
    if (a.indexOf('tab-') === 0) { tab = a.slice(4); render(); return; }
    if (a === 'clear-sel') { sel = {}; preview = null; alt.items = null; render(); return; }
    if (a === 'prev' && found) { find(found.page - 1); return; }
    if (a === 'next' && found) { find(found.page + 1); return; }
    if (a === 'page-all' && found) {
      var all = found.items.every(function (p) { return sel[p.id] === true; });
      found.items.forEach(function (p) { if (all) delete sel[p.id]; else sel[p.id] = true; });
      preview = null; render(); return;
    }
    if (a === 'all-matches') { allMatches(); return; }
    if (a === 'preview') { doPreview(); return; }
    if (a === 'start') { startRename(); return; }
    if (a === 'stop') { stopAsked = true; b.disabled = true; b.textContent = 'Stopping…'; return; }
    if (a === 'resume' && id) { openJob(id).then(run); return; }
    if (a === 'undo' && id) { undo(id); return; }
    if (a === 'open-job' && id) { openJob(id); return; }
    if (a === 'alt-preview') { altPreview(); return; }
    if (a === 'alt-apply') { altApply(); return; }
    if (a === 'score') { score(); return; }
  });

  async function allMatches() {
    busy = 'ids'; render();
    var r = await api('/image-seo/ids', { q: q.q, brand: q.brand, category: q.category, filter: q.filter || null, sort: q.sort });
    busy = '';
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    r.body.ids.forEach(function (id) { if (!sel[id]) sel[id] = true; });
    show({ ok: null, text: r.body.ids.length + ' product(s) selected' + (r.body.capped ? ' (the first 5,000).' : '.') });
    preview = null; render();
  }

  function addNavEntry() {
    if (!window.kbbAddNavEntry) return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Image SEO',
      icon: '<rect x="3" y="3" width="14" height="14" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m17 12-4-4-8 8"/><circle cx="17.5" cy="17.5" r="3"/><path d="m22 22-2.3-2.3"/>',
      group: 'Catalog',
      after: ['pagination', 'catalog']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = 'Catalog';
    if (title) title.textContent = 'Image SEO';
    if (side) side.classList.remove('open');
    var host = document.getElementById('content');
    if (!host) return undefined;
    host.innerHTML = '<div class="wrap isx" data-isx-screen></div>';
    boot = null; msg = null;
    render();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
</script>
@endverbatim
