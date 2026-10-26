{{--
    Catalog -> Translate & categorise.                                  Lane LT

    The newest products (newest N, or added in the last X days): which lack
    Arabic in which field and which are in no skin-concern collection; the
    estimate BEFORE anything is spent; Translate (Arabic drafts, HTML checked
    tag-for-tag, resumable, Stop); Review side by side with inline edits and
    one "Approve all" for exactly the scoped products; Skin concerns with a
    reason per suggestion, Apply only adds.

    Light: one GET on open, one per Scan, one POST per run step while a run is
    on (the loop ends when nothing is left, on an error, or on Stop), one per
    review page, Save, Approve, Suggest page, Apply. Nothing per keystroke, no
    timer. Every printed value passes through esc(); Arabic is shown in
    textareas, never as HTML.

    ours() guards every late answer: this screen's [data-rlz] wrapper must
    still be in #content (and `away` false), as concern-sync-screen does.

    Endpoints: routes/recent-localise-admin.php, capability `catalog.localise`.
--}}
@verbatim
<style>
.rlz{display:grid;gap:14px}
.rlz-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:16px}
.rlz-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.rlz-row label{display:flex;gap:6px;align-items:center;font-size:13px}
.rlz input[type=number],.rlz select{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:7px 10px;font:inherit;font-size:13px;background:var(--surface,#fff);color:inherit;width:auto}
.rlz input[type=number]{width:86px}
.rlz-btn{border:1px solid var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;border-radius:10px;padding:9px 16px;font:inherit;font-size:13.5px;font-weight:650;cursor:pointer}
.rlz-btn.ghost{background:var(--surface,#fff);color:inherit;border-color:var(--border,#d9d9d9);font-weight:600}
.rlz-btn.warn{background:#b91c1c;border-color:#b91c1c}
.rlz-btn[disabled]{opacity:.5;cursor:default}
.rlz-note{font-size:12px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:8px 0 0}
.rlz-msg{font-size:13px;border-radius:10px;padding:9px 12px;background:#ecfdf3;color:#065f46}
.rlz-msg.err{background:#fef2f2;color:#991b1b}.rlz-msg.info{background:#eff6ff;color:#1e3a8a}
.rlz-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-top:12px}
.rlz-stat{border:1px solid var(--border,#eee);border-radius:10px;padding:8px 10px}
.rlz-stat b{display:block;font-size:18px;font-variant-numeric:tabular-nums}
.rlz-stat span{font-size:11.5px;color:var(--ink-soft,#6b7280)}
.rlz-est{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-top:12px;padding:12px;border-radius:12px;background:#faf5ff;border:1px solid #e9d5ff;font-size:13px}
.rlz-bar{height:8px;border-radius:99px;background:#eee;overflow:hidden;margin-top:8px}
.rlz-bar i{display:block;height:100%;background:var(--accent,#15a85a)}
.rlz-tabs{display:flex;gap:6px;flex-wrap:wrap}
.rlz-tab{border:1px solid var(--border,#ddd);border-radius:999px;padding:7px 14px;font:inherit;font-size:13px;background:var(--surface,#fff);color:inherit;cursor:pointer}
.rlz-tab.on{background:#111827;color:#fff;border-color:#111827}
.rlz-list{display:grid;gap:8px}
.rlz-p{display:grid;grid-template-columns:44px minmax(0,1fr);gap:10px;border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:10px;background:var(--surface,#fff)}
.rlz-p img,.rlz-p .ph{width:44px;height:44px;border-radius:8px;object-fit:cover;background:#f3f4f6;display:block}
.rlz-p h4{margin:0;font-size:13.5px;line-height:1.35;overflow-wrap:anywhere}
.rlz-meta{font-size:11.5px;color:var(--ink-soft,#6b7280);margin-top:2px;overflow-wrap:anywhere}
.rlz-pills{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.rlz-pill{font-size:11px;border-radius:999px;padding:2px 8px;border:1px solid transparent}
.rlz-pill.missing{background:#fef2f2;color:#991b1b;border-color:#fecaca}.rlz-pill.draft{background:#fffbeb;color:#92400e;border-color:#fde68a}
.rlz-pill.stale{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.rlz-pill.published{background:#ecfdf3;color:#065f46;border-color:#a7f3d0}
.rlz-pill.cn{background:#f3e8ff;color:#6b21a8}
.rlz-rv{border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:12px;background:var(--surface,#fff)}
.rlz-rv h4{margin:0 0 8px;font-size:14px}
.rlz-f{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px;padding:10px 0;border-top:1px solid var(--border,#f0f0f0)}
.rlz-f .lbl{grid-column:1/-1;display:flex;gap:8px;align-items:center;font-size:12px;font-weight:650}
.rlz-en{font-size:12.5px;line-height:1.55;white-space:pre-wrap;overflow-wrap:anywhere;max-height:220px;overflow:auto;background:#f9fafb;border-radius:8px;padding:8px}
.rlz-f textarea{width:100%;min-height:90px;max-height:320px;border:1px solid var(--border,#e6e6e6);border-radius:8px;padding:8px;font:inherit;font-size:13px;line-height:1.6;box-sizing:border-box;resize:vertical}
.rlz-f.name textarea{min-height:44px}
.rlz-save{margin-top:6px}
.rlz-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.rlz-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border,#e0e0e0);border-radius:999px;padding:5px 10px;font-size:12px;cursor:pointer}
.rlz-chip.sug{border-color:#86efac;background:#f0fdf4}.rlz-chip.have{background:#f3f4f6;color:#6b7280;cursor:default}
.rlz-why{margin:7px 0 0;padding:0;list-style:none;font-size:12px;line-height:1.45;color:var(--ink-soft,#4b5563)}
.rlz-src{font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;border-radius:999px;padding:2px 8px;margin-left:6px}
.rlz-src.claude{background:#f3e8ff;color:#6b21a8}.rlz-src.rules{background:#fef3c7;color:#92400e}
.rlz-foot{position:sticky;bottom:0;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:12px 16px;box-shadow:0 -4px 14px rgba(0,0,0,.05);font-size:13px}
.rlz-rej{font-size:12px;margin:8px 0 0;padding-left:18px}
@media (max-width:640px){.rlz-card{padding:13px}.rlz-f{grid-template-columns:1fr}.rlz-btn{flex:1 1 auto}}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'recentlocalise';
  var LABEL = 'Translate & categorise';
  var GROUP = 'Catalog';
  var FIELD = { name: 'Name', short_description: 'Short description', description: 'Description', ingredients: 'Ingredients', how_to_use: 'How to use' };
  var ABBR = { name: 'Name', short_description: 'Short', description: 'Desc', ingredients: 'Ingr.', how_to_use: 'How to use' };
  var st = null, scan = null, tab = 'list', msg = null, busy = '', away = false;
  var run = null;            // {done, remaining, rejected[], stop}
  var rv = null, rvPage = 0; // review page
  var sug = null, picks = {}, sugNext = 0, rulesOnly = false;
  var scope = { mode: 'count', count: 100, days: 14 };
  try { var saved = JSON.parse(localStorage.getItem('kbb.rlz.scope') || 'null'); if (saved && saved.mode) scope = saved; } catch (e) {}

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function host() { return document.getElementById('content'); }
  function ours() { if (away) return false; var h = host(); return !!h && !!h.querySelector('[data-rlz]'); }
  function qs() { return scope.mode === 'days' ? 'count=500&days=' + encodeURIComponent(scope.days) : 'count=' + encodeURIComponent(scope.count); }
  function scopeBody() { return scope.mode === 'days' ? { count: 500, days: Number(scope.days) } : { count: Number(scope.count) }; }
  function plain(html) {
    try { return (new DOMParser().parseFromString('<body>' + String(html || '').replace(/<\/(p|li|h\d|div)>|<br\s*\/?>/gi, '$&\n') + '</body>', 'text/html').body.textContent || '').replace(/\n{3,}/g, '\n\n').trim(); }
    catch (e) { return String(html || ''); }
  }

  async function api(method, path, body) {
    var r = await fetch(base() + '/admin-api/recent-localise' + path, {
      method: method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });
    var j = null;
    try { j = await r.json(); } catch (e) { j = null; }
    if (!r.ok) { var err = new Error((j && j.message) || ('HTTP ' + r.status)); err.status = r.status; throw err; }
    return j;
  }
  function why(e) {
    if (e && e.status === 403) return 'Your role cannot use this screen. An owner or manager can.';
    if (e && e.status === 404) return 'This screen is not in the server’s route table yet. Clear the route cache and reload.';
    if (e && e.status === 429) return 'Too many presses in a minute. Wait a moment and try again.';
    return (e && e.message) || 'Something went wrong. Try again.';
  }

  async function load() {
    try { var j = await api('GET', ''); if (!ours()) return; st = j; }
    catch (e) { if (!ours()) return; msg = { t: 'err', s: why(e) }; }
    paint();
    if (st) doScan();
  }

  async function doScan() {
    busy = 'scan'; paint();
    try {
      var j = await api('GET', '/scan?' + qs());
      if (!ours()) return;
      scan = j; rv = null; rvPage = 0; sug = null; picks = {}; sugNext = 0;
    } catch (e) { if (!ours()) return; msg = { t: 'err', s: why(e) }; }
    busy = ''; paint();
  }

  async function translate() {
    var e = scan.estimate;
    if (!window.confirm('Send ' + num(e.fields) + ' fields (' + num(e.characters) + ' characters) of ' + num(scan.ids.length) + ' products to the Claude API (' + (st.models[e.model] || e.model) + ')?\n\nEstimated cost: about USD ' + e.usd.toFixed(2) + '.\nEverything lands as DRAFTS; nothing shows on the shop until you approve it.')) return;
    run = { done: 0, remaining: e.fields, total: e.fields, rejected: [], stop: false };
    busy = 'run'; msg = null; paint();
    while (run && !run.stop) {
      var j;
      try { j = await api('POST', '/run', scopeBody()); }
      catch (err) { if (!ours()) return; msg = { t: 'err', s: why(err) + ' The run stopped; press Translate again to resume.' }; break; }
      if (!ours()) return;
      run.done += j.translated; run.remaining = j.remaining; run.rejected = run.rejected.concat(j.rejected);
      if (j.error) { msg = { t: 'err', s: 'Claude could not answer (' + j.error + '). The run stopped; press Translate again to resume.' }; break; }
      paint();
      if (!j.remaining || (!j.translated && !j.rejected.length)) break;
    }
    if (!ours()) return;
    var stopped = run && run.stop;
    busy = '';
    if (!msg) msg = { t: 'ok', s: (stopped ? 'Stopped. ' : 'Done. ') + num(run.done) + ' Arabic draft' + (run.done === 1 ? '' : 's') + ' written' + (run.rejected.length ? ', ' + num(run.rejected.length) + ' rejected by the checks (listed below)' : '') + '. Review them, then Approve all.' };
    var keep = run; await doScan(); run = keep; paint();
  }

  async function retry() {
    try { await api('POST', '/retry'); if (!ours()) return; run = null; await doScan(); } catch (e) { if (!ours()) return; msg = { t: 'err', s: why(e) }; paint(); }
  }

  async function review(page) {
    rvPage = page; busy = 'review'; paint();
    try {
      var ids = scan.ids.slice(page * 10, page * 10 + 10);
      var j = ids.length ? await api('POST', '/review', { ids: ids }) : { products: [] };
      if (!ours()) return;
      rv = j.products;
    } catch (e) { if (!ours()) return; msg = { t: 'err', s: why(e) }; }
    busy = ''; paint();
  }

  async function saveField(pid, field, btn) {
    var ta = host().querySelector('textarea[data-rlz-ta="' + pid + ':' + field + '"]');
    if (!ta) return;
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      var j = await api('POST', '/save', { product_id: Number(pid), field: field, value: ta.value });
      if (!ours()) return;
      (rv || []).forEach(function (p) { if (String(p.id) === String(pid)) p.fields.forEach(function (f) { if (f.field === field) { f.ar = j.value; f.state = j.status === 'published' ? 'published' : 'draft'; f.source = 'manual'; } }); });
      btn.textContent = 'Saved'; ta.removeAttribute('data-dirty');
    } catch (e) { if (!ours()) return; btn.disabled = false; btn.textContent = 'Save'; msg = { t: 'err', s: why(e) }; paint(); }
  }

  async function approveAll() {
    var n = scan.ids.length;
    if (!window.confirm('Publish every Arabic DRAFT of these ' + num(n) + ' products on the shop?\n\nTranslations already published are not touched. A draft whose English changed since it was translated is skipped.')) return;
    busy = 'approve'; msg = null; paint();
    try {
      var j = await api('POST', '/approve', { product_ids: scan.ids });
      if (!ours()) return;
      msg = { t: 'ok', s: 'Published ' + num(j.published) + ' Arabic translation' + (j.published === 1 ? '' : 's') + ' on ' + num(j.products) + ' product' + (j.products === 1 ? '' : 's') + '.' + (j.stale ? ' Skipped ' + num(j.stale) + ' whose English changed since — translate those again.' : '') };
      busy = ''; await doScan(); if (tab === 'review') await review(0); return;
    } catch (e) { if (!ours()) return; msg = { t: 'err', s: why(e) }; }
    busy = ''; paint();
  }

  async function suggestNext() {
    var ids = scan.ids.slice(sugNext, sugNext + 40);
    if (!ids.length) return;
    busy = 'suggest'; msg = null; paint();
    try {
      var j = await api('POST', '/concerns/suggest', { product_ids: ids, rules_only: rulesOnly });
      if (!ours()) return;
      sug = sug ? { concerns: j.concerns, note: j.note, products: sug.products.concat(j.products) } : j;
      sugNext += ids.length;
      j.products.forEach(function (p) { if (!picks[p.id]) { picks[p.id] = {}; p.suggestions.forEach(function (s) { picks[p.id][s.slug] = true; }); } });
      if (j.note) msg = { t: 'info', s: j.note };
    } catch (e) { if (!ours()) return; msg = { t: 'err', s: why(e) }; }
    busy = ''; paint();
  }

  function ticked(only) {
    var links = [];
    ((sug && sug.products) || []).forEach(function (p) {
      if (only && String(p.id) !== String(only)) return;
      var on = Object.keys(picks[p.id] || {}).filter(function (k) { return picks[p.id][k]; });
      if (on.length) links.push({ product_id: p.id, concerns: on });
    });
    return links;
  }

  async function applyConcerns(only) {
    var links = ticked(only);
    if (!links.length) return;
    busy = 'apply'; msg = null; paint();
    try {
      var j = await api('POST', '/concerns/apply', { links: links });
      if (!ours()) return;
      links.forEach(function (l) {
        sug.products.forEach(function (p) { if (p.id === l.product_id) { p.concerns = p.concerns.concat(l.concerns); p.suggestions = []; } });
        picks[l.product_id] = {};
      });
      msg = { t: 'ok', s: 'Added ' + num(j.added) + ' membership' + (j.added === 1 ? '' : 's') + ' on ' + num(links.length) + ' product' + (links.length === 1 ? '' : 's') + '. Nothing was removed.' };
    } catch (e) { if (!ours()) return; msg = { t: 'err', s: why(e) }; }
    busy = ''; paint();
  }

  function names() { var n = {}; ((st && st.concerns) || []).forEach(function (c) { n[c.slug] = c.name; }); return n; }

  function thumb(p) { return p.image ? '<img src="' + esc(p.image) + '" alt="" width="44" height="44" loading="lazy" decoding="async">' : '<span class="ph" aria-hidden="true"></span>'; }

  function listView() {
    var cn = names();
    return '<div class="rlz-list">' + scan.products.map(function (p) {
      var pills = Object.keys(p.fields).filter(function (f) { return p.fields[f] !== 'none'; }).map(function (f) {
        return '<span class="rlz-pill ' + esc(p.fields[f]) + '">' + esc(ABBR[f] || f) + ': ' + esc(p.fields[f]) + '</span>';
      }).join('');
      var cons = p.concerns.length ? p.concerns.map(function (s) { return '<span class="rlz-pill cn">' + esc(cn[s] || s) + '</span>'; }).join('') : '<span class="rlz-pill missing">No skin concern</span>';
      return '<div class="rlz-p">' + thumb(p) + '<div><h4>' + esc(p.name) + '</h4><div class="rlz-meta">' + (p.brand ? esc(p.brand) + ' · ' : '') + 'added ' + esc(p.added || '—') + ' · #' + esc(p.id) + '</div>'
        + '<div class="rlz-pills">' + pills + '</div><div class="rlz-pills">' + cons + '</div></div></div>';
    }).join('') + '</div>';
  }

  function reviewView() {
    if (!rv) return '<div class="rlz-card">' + (busy === 'review' ? 'Loading…' : '<button type="button" class="rlz-btn ghost" data-rlz-rv="0">Open the review</button>') + '</div>';
    var pages = Math.ceil(scan.ids.length / 10);
    var nav = '<div class="rlz-row"><button type="button" class="rlz-btn ghost" data-rlz-rv="' + (rvPage - 1) + '"' + (rvPage < 1 || busy ? ' disabled' : '') + '>← Previous 10</button>'
      + '<span class="rlz-meta">Products ' + num(rvPage * 10 + 1) + '–' + num(Math.min(scan.ids.length, rvPage * 10 + 10)) + ' of ' + num(scan.ids.length) + '</span>'
      + '<button type="button" class="rlz-btn ghost" data-rlz-rv="' + (rvPage + 1) + '"' + (rvPage + 1 >= pages || busy ? ' disabled' : '') + '>Next 10 →</button></div>';
    var body = rv.map(function (p) {
      var name = '';
      p.fields.forEach(function (f) { if (f.field === 'name') name = f.en; });
      return '<div class="rlz-rv"><h4>' + esc(plain(name)) + ' <span class="rlz-meta">#' + esc(p.id) + '</span></h4>' + p.fields.map(function (f) {
        return '<div class="rlz-f ' + esc(f.field) + '"><div class="lbl">' + esc(FIELD[f.field] || f.field) + ' <span class="rlz-pill ' + esc(f.state) + '">' + esc(f.state) + (f.source ? ' · ' + esc(f.source) : '') + '</span></div>'
          + '<div class="rlz-en" dir="ltr">' + esc(f.html ? plain(f.en) : f.en) + '</div>'
          + '<div><textarea dir="rtl" lang="ar" data-rlz-ta="' + esc(p.id) + ':' + esc(f.field) + '" placeholder="لا توجد ترجمة بعد">' + esc(f.ar || '') + '</textarea>'
          + '<div class="rlz-save"><button type="button" class="rlz-btn ghost" data-rlz-save="' + esc(p.id) + ':' + esc(f.field) + '" disabled>Save</button>' + (f.html ? ' <span class="rlz-meta">HTML: keep the tags as they are</span>' : '') + '</div></div></div>';
      }).join('') + '</div>';
    }).join('');
    return nav + '<div class="rlz-list">' + body + '</div>' + nav;
  }

  function concernsView() {
    var cn = names();
    var head = '<div class="rlz-card"><div class="rlz-row"><button type="button" class="rlz-btn" data-rlz-sug' + (busy || sugNext >= scan.ids.length ? ' disabled' : '') + '>'
      + (busy === 'suggest' ? 'Researching…' : (sugNext ? 'Suggest for the next ' + num(Math.min(40, scan.ids.length - sugNext)) : 'Suggest for ' + num(Math.min(40, scan.ids.length)) + ' products')) + '</button>'
      + (st.has_key ? '<label class="rlz-meta"><input type="checkbox" id="rlzRules"' + (rulesOnly ? ' checked' : '') + '> Rules only (no API)</label>' : '')
      + '<span class="rlz-meta">' + num(sugNext) + ' of ' + num(scan.ids.length) + ' researched</span></div>'
      + '<p class="rlz-note">Claude reads each product’s ingredients, description, type and brand and gives one reason per collection. Grey chips are collections the product is already in — never removed. Apply only adds.</p></div>';
    if (!sug) return head;
    var rows = sug.products.map(function (p) {
      var have = {}; p.concerns.forEach(function (s) { have[s] = true; });
      var s2 = {}; p.suggestions.forEach(function (s) { s2[s.slug] = s.reason; });
      var chips = (sug.concerns || []).map(function (c) {
        if (have[c.slug]) return '<span class="rlz-chip have">✓ ' + esc(c.name) + '</span>';
        return '<label class="rlz-chip' + (s2[c.slug] !== undefined ? ' sug' : '') + '"><input type="checkbox" data-rlz-pick="' + esc(p.id) + '" value="' + esc(c.slug) + '"' + ((picks[p.id] || {})[c.slug] ? ' checked' : '') + '> ' + esc(c.name) + '</label>';
      }).join('');
      var reasons = p.suggestions.length ? '<ul class="rlz-why">' + p.suggestions.map(function (s) { return '<li><b>' + esc(cn[s.slug] || s.slug) + ':</b> ' + esc(s.reason) + '</li>'; }).join('') + '</ul>' : '<div class="rlz-meta">No new collection suggested.</div>';
      return '<div class="rlz-p"><span class="ph" aria-hidden="true"></span><div><h4>' + esc(p.name) + '<span class="rlz-src ' + (p.source === 'claude' ? 'claude">Claude' : 'rules">rule-based') + '</span></h4>'
        + '<div class="rlz-meta">' + (p.brand ? esc(p.brand) + ' · ' : '') + esc(p.type || 'no category') + '</div><div class="rlz-chips">' + chips + '</div>' + reasons
        + (ticked(p.id).length ? '<div class="rlz-save"><button type="button" class="rlz-btn ghost" data-rlz-apply1="' + esc(p.id) + '"' + (busy ? ' disabled' : '') + '>Apply for this product</button></div>' : '') + '</div></div>';
    }).join('');
    var n = ticked(), count = n.reduce(function (a, l) { return a + l.concerns.length; }, 0);
    return head + '<div class="rlz-list">' + rows + '</div><div class="rlz-foot"><span><b>' + num(count) + '</b> membership' + (count === 1 ? '' : 's') + ' ticked on <b>' + num(n.length) + '</b> product' + (n.length === 1 ? '' : 's') + '</span>'
      + '<button type="button" class="rlz-btn" data-rlz-applyall' + (busy || !count ? ' disabled' : '') + '>' + (busy === 'apply' ? 'Applying…' : 'Apply all ticked') + '</button></div>';
  }

  function paint() {
    if (!ours()) return;
    var root = host().querySelector('[data-rlz]');
    if (!st) { root.innerHTML = msg ? '<div class="rlz-msg err">' + esc(msg.s) + '</div>' : '<div class="rlz-card">Loading…</div>'; return; }

    var html = '<div class="rlz-card"><div class="rlz-row">'
      + '<label><input type="radio" name="rlzMode" value="count"' + (scope.mode === 'count' ? ' checked' : '') + '> Newest</label><input type="number" id="rlzCount" min="1" max="' + esc(st.defaults.max) + '" value="' + esc(scope.count) + '"> <span class="rlz-meta">products</span>'
      + '<label style="margin-left:8px"><input type="radio" name="rlzMode" value="days"' + (scope.mode === 'days' ? ' checked' : '') + '> Added in the last</label><input type="number" id="rlzDays" min="1" max="3650" value="' + esc(scope.days) + '"> <span class="rlz-meta">days</span>'
      + '<button type="button" class="rlz-btn ghost" data-rlz-scan' + (busy ? ' disabled' : '') + '>' + (busy === 'scan' ? 'Scanning…' : 'Scan') + '</button></div>'
      + '<p class="rlz-note">Newest first, by the date each product was added — the same order as the shop’s New Arrivals. Published products only. '
      + (st.has_key ? 'Claude API key saved (' + esc(st.models[st.model] || st.model) + ').' : '<b>No Claude API key saved</b> — add it in Catalog → Shop by Concern → Claude API key &amp; model.') + '</p></div>';

    if (msg) html += '<div class="rlz-msg ' + (msg.t === 'err' ? 'err' : (msg.t === 'info' ? 'info' : '')) + '" role="status">' + esc(msg.s) + '</div>';

    if (scan) {
      var t = scan.totals, e = scan.estimate;
      html += '<div class="rlz-card"><b>' + num(t.products) + ' products in scope</b><div class="rlz-stats">'
        + Object.keys(t.missing).map(function (f) { return '<div class="rlz-stat"><b>' + num(t.missing[f]) + '</b><span>' + esc(FIELD[f]) + ' without Arabic</span></div>'; }).join('')
        + '<div class="rlz-stat"><b>' + num(t.draft) + '</b><span>drafts waiting for approval</span></div>'
        + '<div class="rlz-stat"><b>' + num(t.published) + '</b><span>Arabic fields live</span></div>'
        + '<div class="rlz-stat"><b>' + num(t.no_concern) + '</b><span>in no skin concern</span></div></div>';
      html += '<div class="rlz-est"><div><b>Estimate:</b> ' + num(e.fields) + ' fields · ' + num(e.characters) + ' characters · ' + num(e.requests) + ' requests · ~' + num(e.input_tokens) + ' in / ~' + num(e.output_tokens) + ' out tokens · <b>about USD ' + Number(e.usd).toFixed(2) + '</b> on ' + esc(st.models[e.model] || e.model)
        + (e.too_long ? '<br><span class="rlz-meta">' + num(e.too_long) + ' field(s) too long to send — type those in the review.</span>' : '')
        + (scan.rejected ? '<br><span class="rlz-meta">' + num(scan.rejected) + ' field(s) were rejected by the checks earlier and are skipped. <a href="#" data-rlz-retry>Retry them</a></span>' : '') + '</div>'
        + (busy === 'run' ? '<button type="button" class="rlz-btn warn" data-rlz-stop>Stop</button>'
          : '<button type="button" class="rlz-btn" data-rlz-run' + (busy || !e.fields || !st.has_key ? ' disabled' : '') + '>Translate ' + num(e.fields) + ' fields</button>') + '</div>';
      if (run) {
        var pct = run.total ? Math.min(100, Math.round(100 * (run.total - run.remaining) / run.total)) : 100;
        html += '<div class="rlz-bar" aria-hidden="true"><i style="width:' + pct + '%"></i></div><p class="rlz-note">' + num(run.done) + ' drafted · ' + num(run.remaining) + ' remaining' + (busy === 'run' ? ' · working…' : '') + '</p>';
        if (run.rejected.length) html += '<ul class="rlz-rej">' + run.rejected.map(function (r) { return '<li>#' + esc(r.product_id) + ' ' + esc(r.name) + ' — ' + esc(FIELD[r.field] || r.field) + ': ' + esc(r.why) + '</li>'; }).join('') + '</ul>';
      }
      html += '<p class="rlz-note">Claude is told: Gulf-friendly Modern Standard Arabic for women shoppers; brand and INCI names stay in Latin; numbers and units unchanged; HTML tags untouched. Every answer is checked tag-for-tag and number-for-number; a failing one is not saved. Only the text is sent — no price, stock, SKU or customer data.</p></div>';

      html += '<div class="rlz-tabs">' + [['list', 'Products'], ['review', 'Review Arabic'], ['concerns', 'Skin concerns']].map(function (x) {
        return '<button type="button" class="rlz-tab' + (tab === x[0] ? ' on' : '') + '" data-rlz-tab="' + x[0] + '">' + x[1] + '</button>';
      }).join('') + '</div>';

      if (tab === 'list') html += listView();
      else if (tab === 'review') html += reviewView();
      else html += concernsView();

      if (tab !== 'concerns') html += '<div class="rlz-foot"><span><b>' + num(t.draft) + '</b> Arabic draft' + (t.draft === 1 ? '' : 's') + ' across these ' + num(t.products) + ' products</span>'
        + '<button type="button" class="rlz-btn" data-rlz-approve' + (busy || !t.draft ? ' disabled' : '') + '>' + (busy === 'approve' ? 'Publishing…' : 'Approve all for these ' + num(t.products) + ' products') + '</button></div>';
    }

    root.innerHTML = html;
  }

  document.addEventListener('click', function (e) {
    if (!ours()) return;
    var b = e.target.closest && e.target.closest('[data-rlz-scan],[data-rlz-run],[data-rlz-stop],[data-rlz-retry],[data-rlz-tab],[data-rlz-rv],[data-rlz-save],[data-rlz-approve],[data-rlz-sug],[data-rlz-apply1],[data-rlz-applyall]');
    if (!b || b.disabled) return;
    if (b.hasAttribute('data-rlz-retry')) { e.preventDefault(); retry(); }
    else if (b.hasAttribute('data-rlz-scan')) {
      var c = host().querySelector('#rlzCount'), d = host().querySelector('#rlzDays'), m = host().querySelector('input[name=rlzMode]:checked');
      scope = { mode: m ? m.value : 'count', count: Math.max(1, Math.min(st.defaults.max, parseInt(c.value, 10) || 100)), days: Math.max(1, parseInt(d.value, 10) || 14) };
      try { localStorage.setItem('kbb.rlz.scope', JSON.stringify(scope)); } catch (x) {}
      msg = null; run = null; doScan();
    }
    else if (b.hasAttribute('data-rlz-run')) translate();
    else if (b.hasAttribute('data-rlz-stop')) { if (run) run.stop = true; b.disabled = true; b.textContent = 'Stopping after this step…'; }
    else if (b.hasAttribute('data-rlz-tab')) { tab = b.getAttribute('data-rlz-tab'); if (tab === 'review' && !rv) review(0); else paint(); }
    else if (b.hasAttribute('data-rlz-rv')) review(Number(b.getAttribute('data-rlz-rv')));
    else if (b.hasAttribute('data-rlz-save')) { var k = b.getAttribute('data-rlz-save').split(':'); saveField(k[0], k[1], b); }
    else if (b.hasAttribute('data-rlz-approve')) approveAll();
    else if (b.hasAttribute('data-rlz-sug')) suggestNext();
    else if (b.hasAttribute('data-rlz-apply1')) applyConcerns(b.getAttribute('data-rlz-apply1'));
    else if (b.hasAttribute('data-rlz-applyall')) applyConcerns(null);
  });

  document.addEventListener('input', function (e) {
    if (!ours() || !e.target || !e.target.getAttribute) return;
    var k = e.target.getAttribute('data-rlz-ta');
    if (!k || e.target.hasAttribute('data-dirty')) return;
    e.target.setAttribute('data-dirty', '1');
    var btn = host().querySelector('[data-rlz-save="' + k + '"]');
    if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
  });

  document.addEventListener('change', function (e) {
    if (!ours() || !e.target) return;
    if (e.target.id === 'rlzRules') { rulesOnly = !!e.target.checked; return; }
    var id = e.target.getAttribute && e.target.getAttribute('data-rlz-pick');
    if (!id) return;
    picks[id] = picks[id] || {};
    picks[id][e.target.value] = !!e.target.checked;
    paint();
  });

  function addNavEntry() {
    if (!window.kbbAddNavEntry) return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Translate & categorise',
      icon: '<path d="M4 5h8"/><path d="M8 3v2"/><path d="M5.5 5c.8 3 3 5 6 6"/><path d="M10.5 5c-.8 3-3 5-6 6"/><path d="M13 21l4-9 4 9"/><path d="M14.5 18h5"/>',
      group: 'Catalog',
      after: ['product-editor', 'catalog']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { away = true; if (run) run.stop = true; return previousGo.apply(this, arguments); }
    away = false;
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = LABEL;
    if (side) side.classList.remove('open');
    var h = host();
    if (!h) return undefined;
    h.innerHTML = '<div class="wrap"><div class="page-head"><h2>' + LABEL + '</h2>'
      + '<p>The newest products: put them into Arabic properly — HTML descriptions included — review side by side, approve in one go, and file each into the right skin-concern collections.</p></div>'
      + '<div class="rlz" data-rlz></div></div>';
    st = null; scan = null; run = null; rv = null; sug = null; picks = {}; sugNext = 0; msg = null; busy = ''; tab = 'list';
    paint();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
</script>
@endverbatim
