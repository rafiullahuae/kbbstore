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
    CSRF via the XSRF cookie like every console screen. NO POLLING LOOP: a
    search is a submit, a run is "post the next step when the last one
    answered" (each step ~1.5 s of work, its answer a few hundred bytes of
    counts -- that IS the live progress bar), and the preview and the score
    backfill are the same finite loop. NO TIMER: the selection bar's count is
    one request at a time, with one more owed if the selection moved while it
    was out. No layout measurement. Every value printed passes through esc().

    Lane IS2: the selection is "all matching F except these" or "these ids"
    and the server resolves it; Start previews first when there is no
    preview; "Rename files + ALT text" is the third button; every greyed
    button says why underneath.

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

  /* THE SELECTION LIVES HERE, NOT ON A PAGE (Lane IS2). Either "all products
     matching `filter`, except these ids" or "these ids", plus per product the
     pictures left ticked. A page only READS it to draw its boxes, so moving
     between pages can neither re-tick nor lose anything, and "all 5,000"
     costs one small object. The server resolves it (POST /selection,
     /preview); the counts in the bar are the server's. */
  var sel = { mode: 'none', ids: {}, filter: null, except: {}, only: {} };
  var selInfo = { products: 0, pictures: null, error: '' };
  var selSeq = 0;
  var selAsking = false;      // a count request is out; another is owed when it lands

  var strategy = 'variations';
  var shared = false;
  var pv = null;              // the preview on screen: {kind, token, at, total, done, counts, items}
  var job = null;             // the run on screen
  var jobBusy = false;
  var stopAsked = false;
  var runLog = [];            // what this tab saw the run do, newest last (60 kept)
  var runFailures = [];
  var liveCheck = null;
  var alt = { template: 'variations', first: '{full}', rest: '{name} by {brand} – view {index}', keep: true, items: null, edits: {}, token: '', at: 0, total: 0 };
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

  function num(n) { return Number(n || 0).toLocaleString('en-US'); }

  function plural(n, one, many) { return num(n) + ' ' + (Number(n) === 1 ? one : many); }

  function clock(ms) {
    var s = Math.max(0, Math.round(ms / 1000));
    var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), r = s % 60;
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (r < 10 ? '0' : '') + r;
  }

  /* ------------------------------------------------------- the selection */
  function selEmpty() { return sel.mode === 'none'; }

  function sameFilter(a, b) {
    return !!a && !!b && a.q === b.q && +a.brand === +b.brand && +a.category === +b.category && (a.filter || '') === (b.filter || '');
  }

  function currentFilter() { return { q: q.q, brand: +q.brand || 0, category: +q.category || 0, filter: q.filter || '' }; }

  function productPicked(pid) {
    if (sel.mode === 'all') return !sel.except[pid];
    return !!sel.ids[pid];
  }

  function isPicked(pid, rel) {
    if (!productPicked(pid)) return false;
    return !sel.only[pid] || !!sel.only[pid][rel];
  }

  function relsOf(pid) { return (plans[pid] ? plans[pid].images : []).filter(function (i) { return i.rel; }).map(function (i) { return i.rel; }); }

  function pickProduct(pid, on) {
    delete sel.only[pid];
    if (sel.mode === 'all') { if (on) delete sel.except[pid]; else sel.except[pid] = true; }
    else {
      if (on) { sel.mode = 'ids'; sel.ids[pid] = true; } else delete sel.ids[pid];
      if (!Object.keys(sel.ids).length) sel.mode = 'none';
    }
  }

  function pickImage(pid, rel, on) {
    var all = relsOf(pid);
    var cur = {};
    if (productPicked(pid)) { (sel.only[pid] ? Object.keys(sel.only[pid]) : all).forEach(function (r) { cur[r] = true; }); }
    if (on) cur[rel] = true; else delete cur[rel];
    var n = Object.keys(cur).length;
    if (!n) { pickProduct(pid, false); return; }
    pickProduct(pid, true);
    if (n < all.length) sel.only[pid] = cur;
  }

  function clearSelection() {
    sel = { mode: 'none', ids: {}, filter: null, except: {}, only: {} };
    selInfo = { products: 0, pictures: null, error: '' };
    selSeq++;
  }

  function selectionPayload() {
    return {
      mode: sel.mode === 'all' ? 'all' : 'ids',
      ids: sel.mode === 'all' ? [] : Object.keys(sel.ids).map(Number),
      filter: sel.mode === 'all' ? sel.filter : null,
      except: sel.mode === 'all' ? Object.keys(sel.except).map(Number) : [],
      only: Object.keys(sel.only).map(function (p) { return { p: +p, rels: Object.keys(sel.only[p]) }; })
    };
  }

  /* Anything about the selection moved: the preview and the ALT list it
     made are spent, and the bar asks the server for the true numbers. No
     timer: one request at a time, and clicks made while it is out are
     answered by ONE more request when it lands -- twenty quick ticks cost
     two requests, not twenty. */
  function selChanged() {
    pv = null; alt.items = null; alt.edits = {};
    var known = sel.mode === 'ids' ? Object.keys(sel.ids).length
      : (found && sameFilter(sel.filter, found.filter) ? Math.max(0, found.total - Object.keys(sel.except).length) : selInfo.products);
    selInfo = { products: known, matching: sel.mode === 'all' && found && sameFilter(sel.filter, found.filter) ? found.total : null, pictures: null, error: '' };
    selSeq++;
    render();
    if (!selEmpty()) askCounts();
  }

  async function askCounts() {
    if (selAsking) return;
    selAsking = true;
    var seq = selSeq;
    var r = await api('/image-seo/selection', { selection: selectionPayload() });
    selAsking = false;
    if (seq !== selSeq) { if (!selEmpty()) askCounts(); return; }   // it moved meanwhile: ask once more
    if (r.status !== 200) selInfo = { products: selInfo.products, matching: selInfo.matching, pictures: null, error: fail(r) };
    else selInfo = { products: r.body.products, matching: r.body.matching, pictures: r.body.pictures, error: '' };
    renderCounts();
  }

  function selBar() {
    if (selEmpty()) return '';
    var n = selInfo.products;
    var pics = selInfo.error ? '' : (selInfo.pictures == null ? '<span style="opacity:.75">counting pictures…</span>' : plural(selInfo.pictures, 'picture', 'pictures'));
    var ex = Object.keys(sel.except).length;
    var what = sel.mode === 'all'
      ? 'All <b>' + num(selInfo.matching != null ? selInfo.matching : n + ex) + '</b> products selected' + (ex ? ' (' + num(ex) + ' excluded) · <b>' + num(n) + '</b> to work on' : '') + ' · ' + pics
      : '<b>' + num(n) + '</b> ' + (n === 1 ? 'product' : 'products') + ' selected · ' + pics;
    return '<div class="isx-bar" data-isx-selbar><span>' + what + (selInfo.error ? esc(selInfo.error) : '') + '</span>'
      + '<span class="isx-row"><button type="button" class="isx-btn is-quiet" data-isx="tab-rename">Rename…</button>'
      + '<button type="button" class="isx-btn is-quiet" data-isx="tab-alt">ALT text…</button>'
      + '<button type="button" class="isx-btn is-quiet" data-isx="clear-sel">Clear</button></span></div>';
  }

  function show(m) { msg = m; }

  /* ------------------------------------------------------------- render */
  function render() {
    var host = document.querySelector('[data-isx-screen]');
    if (!host) return;
    if (!boot) { host.innerHTML = '<div class="isx-card"><p class="isx-sub">' + (msg ? esc(msg.text) : 'Loading Image SEO…') + '</p></div>'; return; }
    // What he is typing in the search form survives a redraw he did not ask
    // for (a count landing, the score backfill moving on).
    var draft = null;
    var form = host.querySelector('[data-isx-form="find"]');
    if (form) {
      draft = {};
      ['q', 'brand', 'category', 'filter', 'sort'].forEach(function (k) { if (form.elements[k]) draft[k] = form.elements[k].value; });
      var focused = document.activeElement && form.contains(document.activeElement) ? document.activeElement.name : '';
      var caret = focused === 'q' ? [form.elements.q.selectionStart, form.elements.q.selectionEnd] : null;
    }
    host.innerHTML = head()
      + '<div class="isx-tabs" role="tablist">'
      + tabBtn('find', 'Find') + tabBtn('rename', 'Rename files') + tabBtn('alt', 'ALT text') + tabBtn('history', 'History')
      + '</div>'
      + selBar()
      + (msg ? '<div class="isx-msg ' + (msg.ok === true ? 'is-ok' : (msg.ok === false ? 'is-bad' : 'is-info')) + '" role="status" style="margin:0 0 12px">' + esc(msg.text) + '</div>' : '')
      + (tab === 'find' ? findTab() : tab === 'rename' ? renameTab() : tab === 'alt' ? altTab() : historyTab());
    var again = draft && host.querySelector('[data-isx-form="find"]');
    if (again) {
      Object.keys(draft).forEach(function (k) { if (again.elements[k]) again.elements[k].value = draft[k]; });
      if (focused && again.elements[focused]) {
        again.elements[focused].focus();
        if (caret) again.elements.q.setSelectionRange(caret[0], caret[1]);
      }
    }
  }

  /* A count arriving redraws the bar and the "N products selected" lines,
     nothing else: no form, list or button under the pointer is replaced. */
  function renderCounts() {
    var bar = document.querySelector('[data-isx-selbar]');
    if (!bar) { render(); return; }
    var holder = document.createElement('div');
    holder.innerHTML = selBar();
    if (holder.firstChild) bar.replaceWith(holder.firstChild);
    document.querySelectorAll('[data-isx-selcount]').forEach(function (e) { e.textContent = plural(selInfo.products, 'product', 'products') + ' selected.'; });
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
      resume = '<div class="isx-msg is-info">Run #' + esc(rj.id) + ' (' + esc(kindName(rj)) + ') stopped at ' + esc(rj.position) + ' of ' + esc(rj.total) + ' products. '
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

    var allOnPage = found.items.every(function (p) { return productPicked(p.id); });
    var allHere = sel.mode === 'all' && sameFilter(sel.filter, found.filter);
    var top = '<div class="isx-row" style="justify-content:space-between"><span class="isx-meta">' + plural(found.total, 'product', 'products') + ' · page ' + esc(found.page) + ' of ' + esc(found.pages) + '</span>'
      + '<span class="isx-row"><button type="button" class="isx-btn is-quiet" data-isx="page-all">' + (allOnPage ? 'Unselect this page' : 'Select this page') + '</button>'
      + (allHere
        ? '<span class="isx-meta">All ' + num(found.total) + ' results are selected</span>'
        : '<button type="button" class="isx-btn is-quiet" data-isx="all-matches">Select all ' + num(found.total) + ' results</button>')
      + '</span></div>';

    return form + '<div class="isx-card">' + top + found.items.map(productCard).join('') + pager() + '</div>';
  }

  function productCard(p) {
    var picked = productPicked(p.id) && !sel.only[p.id];
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
    found.filter = currentFilter();
    found.items.forEach(function (p) { plans[p.id] = p; });
    msg = null;
    render();
  }

  /* --------------------------------------------------------- Rename tab */
  var TEMPLATE_NAMES = { variations: 'Natural variations (recommended)', numbered: 'Name + view', custom: 'My own wording' };

  function renameTab() {
    var none = selEmpty();
    var opts = '<div class="isx-opts">'
      + '<label><input type="radio" name="isx-strategy" value="variations" data-isx="strategy"' + (strategy === 'variations' ? ' checked' : '') + '><span><b>Word-order variations</b> (recommended): medicube-pdrn-eye-patches, pdrn-medicube-eye-patches, eye-patches-pdrn-by-medicube…</span></label>'
      + '<label><input type="radio" name="isx-strategy" value="numbered" data-isx="strategy"' + (strategy === 'numbered' ? ' checked' : '') + '><span><b>Name + view number</b>: medicube-pdrn-eye-patches, medicube-pdrn-eye-patches-2, -3…</span></label>'
      + '<label><input type="checkbox" data-isx="shared"' + (shared ? ' checked' : '') + '><span>Include pictures shared by several products (named after the product being renamed; every product keeps showing it)</span></label>'
      + '</div>';
    var altLine = '<div class="isx-opts" style="margin-top:10px;border-top:1px solid var(--border,#eef0f5);padding-top:10px">'
      + '<span class="isx-sub"><b>Rename files + ALT text</b> also writes ALT text for the same pictures with the ALT text tab\'s choice: <b>' + esc(TEMPLATE_NAMES[alt.template] || alt.template) + '</b>'
      + ' <button type="button" class="isx-btn is-quiet" data-isx="tab-alt" style="padding:3px 9px;font-size:12px">Change</button></span>'
      + '<label><input type="checkbox" data-isx="keep"' + (alt.keep ? ' checked' : '') + '><span>Keep ALT text somebody already wrote</span></label></div>';

    var ready = function (kind) { return pv && pv.kind === kind && pv.done; };
    var off = none || !!busy || jobBusy;
    var startLabel = ready('rename') ? (pv.counts.rename ? 'Start renaming ' + plural(pv.counts.rename, 'file', 'files') : 'Nothing to rename') : 'Preview, then start renaming';
    var comboLabel = ready('combo')
      ? (pv.counts.rename + pv.counts.alt ? 'Start: rename ' + plural(pv.counts.rename, 'file', 'files') + ' + write ' + plural(pv.counts.alt, 'ALT text', 'ALT texts') : 'Nothing to change')
      : 'Rename files + ALT text';
    var startOff = off || (ready('rename') && !pv.counts.rename);
    var comboOff = off || (ready('combo') && !(pv.counts.rename + pv.counts.alt));

    var head = '<div class="isx-card"><b>Rename files</b><p class="isx-sub">' + (none ? 'Select products on the Find tab first.' : '<span data-isx-selcount>' + plural(selInfo.products, 'product', 'products') + ' selected.</span>')
      + ' Nothing changes until you press Start, and Start always shows you what it will do first. Names are lower case, hyphenated, made from the product title; the old address of every file redirects to the new one.</p>' + opts + altLine
      + '<div class="isx-row" style="margin-top:12px"><button type="button" class="isx-btn is-quiet" data-isx="preview"' + (off ? ' disabled' : '') + '>' + (busy === 'preview' && pv && pv.kind === 'rename' ? 'Checking…' : 'Preview changes') + '</button>'
      + '<button type="button" class="isx-btn" data-isx="start"' + (startOff ? ' disabled' : '') + '>' + esc(startLabel) + '</button>'
      + '<button type="button" class="isx-btn" data-isx="combo"' + (comboOff ? ' disabled' : '') + '>' + esc(comboLabel) + '</button></div>'
      + why() + '</div>';
    // A run still going sits on top, where a phone sees it without scrolling.
    var mine = job && job.kind !== 'alt';
    if (mine && job.status !== 'done') return jobCard() + head + previewCard();
    return head + (mine ? jobCard() : '') + previewCard();
  }

  /* Every greyed button says why, on the screen, under the buttons. */
  function why() {
    var t = '';
    if (selEmpty()) t = 'The buttons wake up once products are selected: tick them on the Find tab, or press "Select all … results" there.';
    else if (jobBusy) t = 'A run is going. Stop it, or let it finish, before starting another.';
    else if (busy === 'preview' && pv) t = 'Checking ' + num(pv.at) + ' of ' + num(pv.total || selInfo.products) + ' products…';
    else if (busy) t = 'Working…';
    else if (pv && pv.done && !pv.counts.rename && !(pv.kind === 'combo' && pv.counts.alt)) t = nothingWhy(pv);
    return t ? '<div class="isx-msg is-info" data-isx-why>' + esc(t) + '</div>' : '';
  }

  function reasonsText(c) {
    return Object.keys(c.reasons || {}).sort(function (a, b) { return c.reasons[b] - c.reasons[a]; })
      .map(function (k) { return num(c.reasons[k]) + ' ' + k; }).join(' · ');
  }

  function nothingWhy(p) {
    var c = p.counts;
    var out = 'Nothing to ' + (p.kind === 'combo' ? 'change' : 'rename') + ' in ' + (c.products === 1 ? 'this product' : 'these ' + num(c.products) + ' products') + ': ' + (reasonsText(c) || 'no pictures on this shop') + '.';
    if (c.reasons && c.reasons['shared with other products'] && !shared) out += ' Tick "Include pictures shared by several products" to rename shared ones.';
    if (p.kind === 'combo' && c.kept) out += ' ' + plural(c.kept, 'ALT text was', 'ALT texts were') + ' kept as written; untick "Keep ALT text somebody already wrote" to replace ' + (c.kept === 1 ? 'it' : 'them') + '.';
    return out;
  }

  function previewCard() {
    if (!pv || !pv.counts) return '';
    var c = pv.counts;
    var rows = [];
    (pv.items || []).forEach(function (p) {
      p.images.forEach(function (i) {
        rows.push('<li><b>' + esc(p.name) + '</b><br>' + action(i)
          + (i.action === 'rename' || i.action === 'repoint' ? '<br><code class="isx-code">' + esc(i.rel) + '</code> <span class="isx-arrow" style="color:#15a85a;font-weight:800">→</span> <code class="isx-code">' + esc(i.proposed_rel) + '</code>' : '')
          + (i.alt ? '<br><span class="isx-why">ALT → “' + esc(i.alt) + '”</span>' : '')
          + '</li>');
      });
    });
    var pct = pv.total ? Math.round(pv.at / pv.total * 100) : 0;
    var totals = '<b>' + plural(c.rename, 'file', 'files') + ' to rename</b>'
      + (pv.kind === 'combo' ? ' · <b>' + plural(c.alt, 'ALT text', 'ALT texts') + ' to write</b>' + (c.kept ? ' · ' + num(c.kept) + ' kept as written' : '') : '')
      + ' · ' + num(c.ok) + ' already named · ' + num(c.skip) + ' skipped';
    return '<div class="isx-card" data-isx-preview><b>' + (pv.kind === 'combo' ? 'Preview: rename files + ALT text' : 'Preview: rename files') + '</b> <span class="isx-meta">' + plural(c.products, 'product', 'products') + (pv.done ? '' : ' so far') + '</span>'
      + (pv.done ? '' : '<div class="isx-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><i style="width:' + pct + '%"></i></div>')
      + '<div class="isx-sub" style="margin-top:6px">' + totals + '</div>'
      + (reasonsText(c) ? '<div class="isx-why">Not renamed: ' + esc(reasonsText(c)) + '</div>' : '')
      + (pv.total > (pv.items || []).length && rows.length ? '<div class="isx-msg is-info">Listing the first ' + num((pv.items || []).length) + ' products; the counts and the run cover all ' + num(pv.total) + '.</div>' : '')
      + (rows.length ? '<ul class="isx-log" style="max-height:480px">' + rows.join('') + '</ul>' : '') + '</div>';
  }

  /* The preview, 50 products a request, until the server says done. Each
     answer moves the bar; nothing repeats on a timer. */
  async function runPreview(kind) {
    if (selEmpty()) return false;
    pv = { kind: kind, token: token(), at: 0, total: selInfo.products, done: false, counts: null, items: [] };
    busy = 'preview'; msg = null; render();
    var offset = 0;
    for (var guard = 0; guard < 400; guard++) {
      var r = await api('/image-seo/preview', { token: pv.token, kind: kind, offset: offset, selection: selectionPayload(), strategy: strategy, include_shared: shared,
        template: alt.template, first: alt.first, rest: alt.rest, keep: alt.keep });
      if (!pv || pv.kind !== kind) { busy = ''; render(); return false; }   // the selection moved meanwhile
      if (r.status !== 200) { pv = null; busy = ''; show({ ok: false, text: fail(r) }); render(); return false; }
      if (offset === 0) pv.items = r.body.items; else pv.items = pv.items.concat(r.body.items);
      pv.at = r.body.at; pv.total = r.body.total; pv.counts = r.body.counts; pv.done = r.body.done;
      render();
      if (pv.done || r.body.at <= offset) break;
      offset = r.body.at;
    }
    busy = ''; render();
    return !!(pv && pv.done);
  }

  /* Start, for both kinds: no preview yet, or one for something else, and
     the preview runs first; then one question with the real numbers. */
  async function go(kind) {
    if (busy || jobBusy) return;
    if (!(pv && pv.kind === kind && pv.done)) {
      if (!(await runPreview(kind))) return;
    }
    var c = pv.counts;
    var files = c.rename, alts = kind === 'combo' ? c.alt : 0;
    if (!files && !alts) { render(); return; }
    var question = kind === 'combo'
      ? 'Rename ' + plural(files, 'file', 'files') + ' and write ' + plural(alts, 'ALT text', 'ALT texts') + ' in ' + plural(c.products, 'product', 'products') + '?'
      : 'Rename ' + plural(files, 'file', 'files') + ' in ' + plural(c.products, 'product', 'products') + '?';
    if (!window.confirm(question + ' Every old address will redirect to the new name, and the run can be undone from History.')) return;
    var r = await api('/image-seo/start', { token: pv.token });
    if (r.status === 409 && r.body.need_preview) { pv = null; show({ ok: null, text: 'The preview had expired, so nothing started. Press the button again to preview and start.' }); render(); return; }
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    job = r.body.job; pv = null; liveCheck = null;
    run(false);
  }

  /* -------------------------------------------------------------- a run */
  var KIND_NAMES = { rename: 'Rename files', alt: 'ALT text', combo: 'Rename files + ALT text', undo: 'Undo' };

  function kindName(j) { return j.undo_of ? 'Undo of run #' + j.undo_of : (KIND_NAMES[j.kind] || j.kind); }

  function counts(j) {
    var undo = j.kind === 'undo' || j.undo_of != null;
    var parts = [];
    if (j.kind !== 'alt') parts.push(plural(j.renamed, undo ? 'file restored' : 'file renamed', undo ? 'files restored' : 'files renamed'));
    if (j.kind === 'combo' || j.kind === 'alt' || j.alt_written) {
      // Runs from before Lane IS2 counted ALT text under "renamed".
      parts.push(plural(j.alt_written || (j.kind === 'alt' ? j.renamed : 0), 'ALT text', 'ALT texts') + (undo ? ' restored' : ' written'));
    }
    parts.push(num(j.skipped) + ' skipped', num(j.failed) + ' failed');
    return parts.join(' · ');
  }

  function jobCard() {
    if (!job) return '';
    var pct = job.total ? Math.round(job.position / job.total * 100) : 100;
    var done = job.status === 'done';
    var log = runLog.length ? runLog : (job.log || []);
    var fails = runFailures.length ? runFailures : (job.failures || []);
    var line = function (e) {
      var cls = e.status === 'rolled_back' ? 'is-fail' : (e.status === 'skipped' ? 'is-skip' : (e.status === 'ok' ? 'is-ok' : 'is-rename'));
      return '<li><span class="isx-tag ' + cls + '">' + esc(e.status === 'rolled_back' ? 'failed' : e.status.replace('_', ' ')) + '</span><b>' + esc(e.name) + '</b>'
        + (e.rel ? '<br><code class="isx-code">' + esc(e.rel) + '</code>' + (e.to && e.status !== 'skipped' ? ' → <code class="isx-code">' + esc(e.to) + '</code>' : '') : '')
        + (e.reason ? '<br><span class="isx-why">' + esc(e.reason) + '</span>' : '')
        + (e.refs ? '<span class="isx-why"> · ' + esc(e.refs) + ' reference(s) updated · ' + esc(e.files) + ' file(s) moved</span>' : '') + '</li>';
    };
    var eta = '';
    if (!done && job.position > 0 && job.elapsed_ms > 0) eta = ' · about ' + clock(job.elapsed_ms / job.position * (job.total - job.position)) + ' left';
    var time = done ? 'Finished in ' + clock(job.elapsed_ms) : 'Elapsed ' + clock(job.elapsed_ms) + eta;
    var check = liveCheck ? '<div class="isx-msg ' + (liveCheck.ok ? 'is-ok' : 'is-bad') + '">' + esc(liveCheck.text) + '</div>' : '';
    var buttons = '';
    if (jobBusy) buttons = '<button type="button" class="isx-btn is-quiet" data-isx="stop"' + (stopAsked ? ' disabled' : '') + '>' + (stopAsked ? 'Stopping…' : 'Stop') + '</button>';
    else if (!done) buttons = '<button type="button" class="isx-btn" data-isx="resume" data-isx-job="' + esc(job.id) + '">Resume</button>';
    else if (!job.undo_of && job.kind !== 'undo' && !job.undone) buttons = '<button type="button" class="isx-btn is-quiet" data-isx="undo" data-isx-job="' + esc(job.id) + '">Undo this run</button>';
    return '<div class="isx-card" data-isx-run><b>' + esc(kindName(job)) + ' · run #' + esc(job.id) + '</b> <span class="isx-meta">' + esc(done ? 'finished' : (jobBusy ? 'running' : 'paused')) + '</span>'
      + '<div class="isx-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><i style="width:' + pct + '%"></i></div>'
      + '<div class="isx-sub" data-isx-counts><b>' + num(job.position) + ' / ' + num(job.total) + '</b> products · ' + esc(counts(job)) + '</div>'
      + '<div class="isx-meta" data-isx-time>' + esc(time) + '</div>'
      + check + '<div class="isx-row" style="margin-top:8px">' + buttons + '</div>'
      + (fails.length ? '<div class="isx-msg is-bad"><b>' + plural(fails.length, 'failure', 'failures') + '</b> (nothing was changed for these; the rest went ahead)<ul class="isx-log" style="max-height:200px">' + fails.slice().reverse().map(line).join('') + '</ul></div>' : '')
      + (log.length ? '<details' + (done ? '' : ' open') + ' style="margin-top:8px"><summary>What it did</summary><ul class="isx-log">' + log.slice().reverse().slice(0, 60).map(line).join('') + '</ul></details>' : '') + '</div>';
  }

  /* The run: post a step, draw its answer, post the next. Each step does up
     to ~1.5 s of work and answers a few hundred bytes of counts, so this IS
     the live progress -- there is no second poll and no timer, and nothing
     is left behind when the run ends, is stopped, or the screen is left. */
  async function run(resume) {
    if (!job || jobBusy) return;
    jobBusy = true; stopAsked = false; runLog = []; runFailures = []; render();
    var lastRenamed = null;
    var first = true;
    while (job && job.status !== 'done' && !stopAsked && document.querySelector('[data-isx-screen]')) {
      var r = await api('/image-seo/step', { job: job.id, resume: first && !!resume });
      first = false;
      if (r.status === 202) { show({ ok: null, text: 'Another window is running a step of this run right now; it carries on there. Press Resume here to follow it from this window.' }); break; }
      if (r.status !== 200) { show({ ok: false, text: fail(r) }); break; }
      if (r.body.job && r.body.job.id) job = Object.assign({}, job, r.body.job);
      (r.body.results || []).forEach(function (x) {
        if (x.status === 'renamed' || x.status === 'restored') lastRenamed = x;
        if (x.status === 'rolled_back') runFailures.push(x);
        if (x.status !== 'ok') runLog.push(x);
      });
      if (runLog.length > 60) runLog = runLog.slice(-60);
      if (runFailures.length > 50) runFailures = runFailures.slice(-50);
      render();
      if (job.status === 'stopped') break;   // stopped from another window
    }
    if (stopAsked && job && job.status !== 'done') {
      var s = await api('/image-seo/stop', { job: job.id });
      if (s.body.job) job = Object.assign({}, job, s.body.job);
    }
    jobBusy = false; stopAsked = false;
    if (job && job.status === 'done' && job.kind === 'alt') { alt.items = null; alt.edits = {}; }
    if (lastRenamed && job && job.status === 'done') await check(lastRenamed);
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
    var none = selEmpty();
    var opts = '<div class="isx-opts">'
      + '<label><input type="radio" name="isx-tpl" value="variations" data-isx="tpl"' + (alt.template === 'variations' ? ' checked' : '') + '><span><b>Natural variations</b> (recommended): “Medicube PDRN Eye Patches”, “PDRN Eye Patches by Medicube”, “Medicube PDRN Eye Patches – Eye Care”…</span></label>'
      + '<label><input type="radio" name="isx-tpl" value="numbered" data-isx="tpl"' + (alt.template === 'numbered' ? ' checked' : '') + '><span><b>Name + view</b>: “Medicube PDRN Eye Patches – view 2 of 5”</span></label>'
      + '<label><input type="radio" name="isx-tpl" value="custom" data-isx="tpl"' + (alt.template === 'custom' ? ' checked' : '') + '><span><b>My own wording</b>, with {name} {brand} {full} {category} {index} {total}</span></label>'
      + (alt.template === 'custom' ? '<div class="isx-form" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)"><div class="isx-field"><label for="isx-first">First picture</label><input id="isx-first" class="isx-in" maxlength="200" data-isx-in="first" value="' + esc(alt.first) + '"></div>'
        + '<div class="isx-field"><label for="isx-rest">Other pictures</label><input id="isx-rest" class="isx-in" maxlength="200" data-isx-in="rest" value="' + esc(alt.rest) + '"></div></div>' : '')
      + '<label><input type="checkbox" data-isx="keep"' + (alt.keep ? ' checked' : '') + '><span>Keep alt text somebody already wrote</span></label></div>';
    var head = '<div class="isx-card"><b>ALT text</b><p class="isx-sub">' + (none ? 'Select products on the Find tab first.' : '<span data-isx-selcount>' + plural(selInfo.products, 'product', 'products') + ' selected.</span>')
      + ' Alt text is what Google Images reads and what a screen reader says. 5–125 characters, describes the picture, not the same on every picture. You can edit every line before applying.'
      + ' <b>English only:</b> this shop stores one alt per picture, and it is shown on the Arabic shop too; a picture with no alt written keeps its automatic Arabic one there.</p>' + opts
      + '<div class="isx-row" style="margin-top:10px"><button type="button" class="isx-btn is-quiet" data-isx="alt-preview"' + (none || busy || jobBusy ? ' disabled' : '') + '>' + (busy === 'alt-preview' ? 'Preparing… ' + num(alt.at) + ' of ' + num(alt.total) : 'Preview alt text') + '</button>'
      + '<button type="button" class="isx-btn" data-isx="alt-apply"' + (!alt.items || jobBusy || busy ? ' disabled' : '') + '>Apply alt text</button></div>'
      + altWhy() + '</div>';
    var mine = job && job.kind === 'alt';
    if (mine && job.status !== 'done') return jobCard() + head + altList();
    return head + (mine ? jobCard() : '') + altList();
  }

  function altWhy() {
    var t = '';
    if (selEmpty()) t = 'The buttons wake up once products are selected on the Find tab.';
    else if (jobBusy) t = 'A run is going. Stop it, or let it finish, first.';
    else if (busy === 'alt-preview') t = 'Preparing ' + num(alt.at) + ' of ' + num(alt.total) + ' products…';
    else if (!alt.items) t = 'Apply wakes up after "Preview alt text": every line can be edited before it is written.';
    return t ? '<div class="isx-msg is-info">' + esc(t) + '</div>' : '';
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
    if (selEmpty()) return;
    busy = 'alt-preview'; alt.items = null; alt.edits = {}; alt.at = 0; alt.total = selInfo.products; render();
    var items = [];
    var offset = 0;
    for (var guard = 0; guard < 400; guard++) {
      var r = await api('/image-seo/alt-preview', { selection: selectionPayload(), offset: offset, template: alt.template, first: alt.first, rest: alt.rest, only_missing: alt.keep });
      if (r.status !== 200) { busy = ''; show({ ok: false, text: fail(r) }); render(); return; }
      items = items.concat(r.body.items);
      alt.at = r.body.next; alt.total = r.body.total;
      render();
      if (r.body.done || r.body.next <= offset) break;
      offset = r.body.next;
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
      var can = j.status === 'done' && !j.undo_of && (j.kind === 'rename' || j.kind === 'alt' || j.kind === 'combo');
      return '<tr><td>#' + esc(j.id) + '</td><td>' + esc(kindName(j)) + '</td><td>' + esc(j.created_at) + (j.by ? '<br><span class="isx-meta">' + esc(j.by) + '</span>' : '') + '</td>'
        + '<td>' + esc(counts(j)) + '<br><span class="isx-meta">' + esc(j.position) + '/' + esc(j.total) + ' products · ' + esc(j.status) + (j.elapsed_ms ? ' · ' + clock(j.elapsed_ms) : '') + '</span></td>'
        + '<td class="isx-row"><button type="button" class="isx-btn is-quiet" data-isx="open-job" data-isx-job="' + esc(j.id) + '">Log</button>'
        + (j.status !== 'done' ? '<button type="button" class="isx-btn is-quiet" data-isx="resume" data-isx-job="' + esc(j.id) + '">Resume</button>' : '')
        + (can ? '<button type="button" class="isx-btn is-quiet" data-isx="undo" data-isx-job="' + esc(j.id) + '">Undo</button>' : '') + '</td></tr>';
    }).join('');
    return jobCard() + '<div class="isx-card"><b>Runs</b>' + (rows ? '<table class="isx-jobs"><thead><tr><th>Run</th><th>What</th><th>When</th><th>Result</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>' : '<p class="isx-sub">No runs yet.</p>') + '</div>';
  }

  async function openJob(id) {
    var r = await api('/image-seo/job?id=' + encodeURIComponent(id));
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    job = r.body.job; liveCheck = null; runLog = []; runFailures = []; render();
  }

  async function undo(id) {
    if (!window.confirm('Undo run #' + id + '? Every picture it renamed gets its old name back (and its old address works again), and every ALT text it wrote is put back.')) return;
    var r = await api('/image-seo/undo', { job: +id, confirm: 'UNDO' });
    if (r.status !== 200) { show({ ok: false, text: fail(r) }); render(); return; }
    job = r.body.job; liveCheck = null;
    run(false);
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
    // A run this screen was driving when the page was reloaded carries on;
    // a stopped or long-idle one waits for Resume (the banner above).
    if (boot.running && !jobBusy) { job = boot.running; tab = job.kind === 'alt' ? 'alt' : 'rename'; run(false); }
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
    var next = { q: f.elements.q.value.trim(), brand: +f.elements.brand.value || 0, category: +f.elements.category.value || 0, filter: f.elements.filter.value };
    // "All matching F" is tied to F: a different search would quietly mean
    // a different selection, so it is cleared -- after asking.
    if (sel.mode === 'all' && !sameFilter(next, sel.filter)) {
      if (!window.confirm('A new search clears the selection of all ' + num(selInfo.products) + ' products matching the current one. Search anyway?')) {
        f.elements.q.value = q.q; f.elements.brand.value = q.brand; f.elements.category.value = q.category; f.elements.filter.value = q.filter;
        return;
      }
      clearSelection(); selChanged();
    }
    q.q = next.q; q.brand = next.brand; q.category = next.category; q.filter = next.filter;
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
      if (pv && pv.kind === 'combo') pv = null;
    }
  });

  document.addEventListener('change', function (e) {
    if (!within(e)) return;
    var t = e.target;
    var a = t.getAttribute('data-isx');
    if (a === 'pick-product') { pickProduct(t.getAttribute('data-isx-p'), t.checked); selChanged(); }
    else if (a === 'pick-image') { pickImage(t.getAttribute('data-isx-p'), t.getAttribute('data-isx-rel'), t.checked); selChanged(); }
    else if (a === 'strategy') { strategy = t.value; pv = null; render(); }
    else if (a === 'shared') { shared = t.checked; pv = null; render(); }
    else if (a === 'tpl') { alt.template = t.value; alt.items = null; if (pv && pv.kind === 'combo') pv = null; render(); }
    else if (a === 'keep') { alt.keep = t.checked; alt.items = null; if (pv && pv.kind === 'combo') pv = null; render(); }
  });

  document.addEventListener('click', function (e) {
    if (!within(e)) return;
    var b = e.target.closest('button[data-isx]');
    if (!b || b.disabled) return;
    var a = b.getAttribute('data-isx');
    var id = b.getAttribute('data-isx-job');
    if (a.indexOf('tab-') === 0) { tab = a.slice(4); render(); return; }
    if (a === 'clear-sel') { clearSelection(); selChanged(); return; }
    if (a === 'prev' && found) { find(found.page - 1); return; }
    if (a === 'next' && found) { find(found.page + 1); return; }
    if (a === 'page-all' && found) {
      var all = found.items.every(function (p) { return productPicked(p.id); });
      found.items.forEach(function (p) { pickProduct(p.id, !all); });
      selChanged(); return;
    }
    if (a === 'all-matches') { allMatches(); return; }
    if (a === 'preview') { runPreview('rename'); return; }
    if (a === 'start') { go('rename'); return; }
    if (a === 'combo') { go('combo'); return; }
    if (a === 'stop') { stopAsked = true; b.disabled = true; b.textContent = 'Stopping…'; return; }
    if (a === 'resume' && id) { openJob(id).then(function () { run(true); }); return; }
    if (a === 'undo' && id) { undo(id); return; }
    if (a === 'open-job' && id) { openJob(id); return; }
    if (a === 'alt-preview') { altPreview(); return; }
    if (a === 'alt-apply') { altApply(); return; }
    if (a === 'score') { score(); return; }
  });

  /* "Select all N results": every product matching the search on screen,
     on every page, as one rule the server resolves. */
  function allMatches() {
    if (!found) return;
    var elsewhere = sel.mode === 'ids' && Object.keys(sel.ids).some(function (id) { return !found.items.some(function (p) { return String(p.id) === String(id); }); });
    if (elsewhere && !window.confirm('Replace the ' + num(Object.keys(sel.ids).length) + ' products you ticked with all ' + num(found.total) + ' results of this search?')) return;
    sel = { mode: 'all', ids: {}, filter: found.filter, except: {}, only: {} };
    selChanged();
  }

  function addNavEntry() {
    if (!window.kbbAddNavEntry) return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Image SEO',
      icon: '<rect x="3" y="3" width="14" height="14" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m17 12-4-4-8 8"/><circle cx="17.5" cy="17.5" r="3"/><path d="m22 22-2.3-2.3"/>',
      group: 'Catalog',
      after: ['product-editor', 'catalog']
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
