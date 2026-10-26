{{--
    CATALOG → NEW ARRIVALS (Lane NA). App\Services\NewArrivals keeps the New
    Arrivals category to a rule: the newest products added in the last N
    months (or any age), at most the limit, newest on top, minus "never show",
    plus "always show". This screen is that rule, and a Display grid of the list
    exactly as the shop opens it, where a tap on ✕ excludes a product (tap again
    to bring it back) and a drag (or ↑ ↓ on a phone) reorders. One Save sends
    every pending change in one request.

    Super light: one GET when opened (the settings), the grid only when Display
    is pressed, the search one debounced request, no polling, no timer, no new
    dependency. Every string is escaped. Wraps window.go for 'newarrivals'.
    Unsaved crosses and moves are never lost and never asked about: they go to
    "Unfinished" in the top bar (kbbDrafts), as every console screen's do. It
    paints only while its own [data-na] root is on show (ours()), so a late
    answer never paints over another screen.
--}}
<script>
(function () {
  var SCREEN = 'newarrivals';
  var st = null, err = '', items = null, savedEx = {}, savedOrder = '', ex = {}, busy = false, flash = '', flashErr = false;
  var reopen = false, gridErr = '', loadingGrid = false, searchSeq = 0, searchTimer = 0, drawJob = 0;

  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ours() { return !!document.querySelector('#content [data-na]'); }
  function $(sel) { return document.querySelector('#content [data-na] ' + sel); }

  async function api(method, path, body) {
    var r = await fetch(base() + '/admin-api/new-arrivals' + path, {
      method: method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      body: body ? JSON.stringify(body) : undefined
    });
    var j = null; try { j = await r.json(); } catch (e) {}
    if (!r.ok) {
      var msg = (j && (j.message || j.error)) || ('HTTP ' + r.status);
      if (r.status === 403) msg = 'Your role cannot change New Arrivals. An owner or manager can.';
      if (r.status === 404 && !(j && j.message)) msg = "This screen is not in the server's route table yet. Apply the package and reload.";
      if (r.status === 422 && j && j.errors) msg = Object.keys(j.errors).map(function (k) { return j.errors[k][0]; }).join(' ');
      throw new Error(msg);
    }
    return j;
  }

  // ── pending changes ───────────────────────────────────────────────────────
  function orderKey() { return items ? items.map(function (i) { return i.id; }).join(',') : ''; }
  function pending() {
    if (!items) return 0;
    var n = 0;
    items.forEach(function (i) { if (!!ex[i.id] !== !!savedEx[i.id]) n++; });
    if (orderKey() !== savedOrder) n++;
    return n;
  }
  function dirty() { return pending() > 0; }
  function adoptGrid(list) {
    items = list || []; ex = {}; savedEx = {};
    items.forEach(function (i) { if (i.excluded) { ex[i.id] = true; savedEx[i.id] = true; } });
    savedOrder = orderKey();
  }

  // ── loading ───────────────────────────────────────────────────────────────
  async function load() {
    st = null; err = ''; items = null; flash = ''; paint();
    try { st = await api('GET', ''); } catch (e) { err = e.message; }
    if (!ours()) return;
    paint();
    // Opened from "Unfinished" in the top bar: draw the grid so the kept
    // changes have somewhere to go back into.
    if (reopen && st && st.category) { reopen = false; display(true); }
  }
  async function display(skipAsk) {
    if (loadingGrid) return;
    if (!skipAsk && dirty() && window.kbbConfirm && !(await window.kbbConfirm('Load the list again? Your unsaved changes on it are dropped.'))) return;
    loadingGrid = true; gridErr = ''; paintBar();
    var t0 = performance.now();
    try {
      var j = await api('GET', '/grid');
      if (!ours()) return;
      adoptGrid(j.items);
      drawGrid();
      if (window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      window.kbbNaFirstPaint = Math.round(performance.now() - t0);
    } catch (e) { gridErr = e.message; if (ours()) drawGrid(); }
    finally { loadingGrid = false; if (ours()) paintBar(); }
  }
  async function saveGrid() {
    if (busy || !dirty()) return;
    busy = true; flash = ''; paintBar();
    var order = items.map(function (i) { return i.id; });
    var excluded = items.filter(function (i) { return ex[i.id]; }).map(function (i) { return i.id; });
    try {
      var j = await api('POST', '/save', { order: order, excluded: excluded });
      st = j; flash = 'Saved ✓'; flashErr = false;
      // Saved: what is on screen is now the saved state.
      savedEx = {}; Object.keys(ex).forEach(function (k) { if (ex[k]) savedEx[k] = true; });
      savedOrder = orderKey();
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      if (ours()) { paintSettingsCount(); paintBar(); }
      setTimeout(function () { if (flash === 'Saved ✓') { flash = ''; if (ours()) paintBar(); } }, 2500);
    } catch (e) { flash = 'Not saved: ' + e.message + ' Your changes are still here.'; flashErr = true; }
    finally { busy = false; if (ours()) paintBar(); }
  }
  async function saveSettings() {
    if (busy) return;
    var f = readForm(); if (!f) return;
    busy = true; setNote('Saving…', false);
    try {
      st = await api('POST', '/settings', f);
      if (!ours()) return;
      paint(); setNote('Saved ✓ — the list on the shop is updated.', false);
    } catch (e) { setNote('Not saved: ' + e.message, true); }
    finally { busy = false; }
  }
  async function reset() {
    if (busy) return;
    if (window.kbbConfirm && !(await window.kbbConfirm('Put New Arrivals back to newest first? The order you set is thrown away.'))) return;
    busy = true; setNote('Resetting…', false);
    try {
      st = await api('POST', '/reset');
      if (!ours()) return;
      setNote('Done — newest first.', false); paintSettingsCount();
      if (items) { busy = false; items = null; display(); }
    } catch (e) { setNote('Not done: ' + e.message, true); }
    finally { busy = false; }
  }
  function setNote(t, bad) { var n = $('[data-na-note]'); if (n) { n.textContent = t; n.className = 'na-note' + (bad ? ' bad' : ''); } }

  // ── settings card ─────────────────────────────────────────────────────────
  var lists = { include: [], exclude: [] };
  function readForm() {
    var limit = parseInt($('[name=na-limit]').value, 10), n = parseInt($('[name=na-n]').value, 10);
    var unit = $('[name=na-unit]').value, max = (st.units[unit] || {}).max || 1;
    if (!(limit >= st.limits.min && limit <= st.limits.max)) { setNote('How many products: a number from ' + st.limits.min + ' to ' + st.limits.max + '.', true); return null; }
    var wOn = $('[name=na-won]').checked;
    if (wOn && !(n >= 1 && n <= max)) { setNote('Only products added in the last: 1 to ' + max + ' ' + st.units[unit].label + '.', true); return null; }
    return {
      on: $('[data-na-on]').classList.contains('on'), limit: limit, window_on: wOn, window_n: wOn ? n : (st.rule.window_n || 3), window_unit: unit,
      include: lists.include.map(function (p) { return p.id; }), exclude: lists.exclude.map(function (p) { return p.id; })
    };
  }
  function chips(kind) {
    var l = lists[kind];
    if (!l.length) return '<div class="na-none">' + (kind === 'include' ? 'None. Search above to keep an older product in New Arrivals.' : 'None. Tap ✕ on the grid below, or search above.') + '</div>';
    return l.map(function (p) {
      return '<span class="na-chip' + (kind === 'exclude' ? ' ex' : '') + '">' + esc(p.name)
        + (p.status !== 'publish' ? ' <em>(' + esc(p.status === 'trash' ? 'in trash' : p.status) + ')</em>' : '')
        + '<button type="button" aria-label="Remove ' + esc(p.name) + '" data-na-unpick="' + kind + ':' + p.id + '">×</button></span>';
    }).join('');
  }
  function paintChips() { ['include', 'exclude'].forEach(function (k) { var h = $('[data-na-chips="' + k + '"]'); if (h) h.innerHTML = chips(k); }); }
  function paintSettingsCount() { var c = $('[data-na-count]'); if (c && st) c.textContent = st.count; }

  function settingsHTML() {
    var r = st.rule, unitOpts = Object.keys(st.units).map(function (u) { return '<option value="' + u + '"' + (r.window_unit === u ? ' selected' : '') + '>' + esc(st.units[u].label) + '</option>'; }).join('');
    return '<div class="card pad na-set">'
      + '<div class="na-top"><div><div class="na-h">Keep New Arrivals automatically</div>'
      + '<div class="na-sub">New products go in by themselves, newest on top. <b data-na-count>' + esc(st.count) + '</b> products in <a href="' + esc(st.category.url) + '" target="_blank" rel="noopener">' + esc(st.category.name) + '</a> now. /new-in/ opens this list.</div></div>'
      + '<button type="button" class="tog' + (r.on ? ' on' : '') + '" role="switch" aria-checked="' + (r.on ? 'true' : 'false') + '" aria-label="Keep New Arrivals automatically" data-na-on></button></div>'
      + '<div class="na-fields">'
      + '<label class="na-f"><span>How many products, at most</span><input class="inp" type="number" inputmode="numeric" name="na-limit" min="' + st.limits.min + '" max="' + st.limits.max + '" value="' + esc(r.limit) + '"></label>'
      + '<div class="na-f"><label class="na-ck"><input type="checkbox" name="na-won"' + (r.window_on ? ' checked' : '') + '> <span>Only products added in the last</span></label>'
      + '<div class="na-win"><input class="inp" type="number" inputmode="numeric" name="na-n" min="1" value="' + esc(r.window_n) + '"' + (r.window_on ? '' : ' disabled') + '><select class="inp" name="na-unit"' + (r.window_on ? '' : ' disabled') + '>' + unitOpts + '</select></div>'
      + '<small>Untick for no age limit.</small></div>'
      + '</div>'
      + '<div class="na-lists">'
      + pickHTML('include', 'Always show', 'Older products to keep in New Arrivals even though they are past the age limit. They still count toward “How many” and sit by their own date.')
      + pickHTML('exclude', 'Never show', 'These never go into New Arrivals. If a product is on both lists, “Never show” wins.')
      + '</div>'
      + '<p class="na-help">While this is on, New Arrivals is filled only by this rule: ticking “New Arrivals” on a product by hand does nothing. Switching it off puts back the products the category had before it was switched on.</p>'
      + '<div class="na-row"><button type="button" class="btn" data-na-save-set>Save settings</button>'
      + '<button type="button" class="btn ghost" data-na-reset data-kbb-asks>Reset to newest first</button><span class="na-note" data-na-note></span></div>'
      + '</div>';
  }
  function pickHTML(kind, title, help) {
    return '<div class="na-pick"><div class="na-ph">' + title + '</div><div class="na-small">' + help + '</div>'
      + '<div class="na-sbox"><input class="inp" type="search" placeholder="Search products by name or SKU" autocomplete="off" data-na-q="' + kind + '"><div class="na-res" data-na-res="' + kind + '"></div></div>'
      + '<div class="na-chips" data-na-chips="' + kind + '"></div></div>';
  }
  function search(kind, q) {
    clearTimeout(searchTimer);
    var res = $('[data-na-res="' + kind + '"]'); if (!res) return;
    if (q.trim().length < 2) { res.innerHTML = ''; return; }
    searchTimer = setTimeout(async function () {
      var seq = ++searchSeq;
      try {
        var j = await api('GET', '/search?q=' + encodeURIComponent(q.trim()));
        if (seq !== searchSeq || !ours()) return;
        var have = {}; lists[kind].forEach(function (p) { have[p.id] = 1; });
        res.innerHTML = j.items.length ? j.items.map(function (p) {
          return '<button type="button" class="na-hit" data-na-pick="' + kind + ':' + p.id + '"' + (have[p.id] ? ' disabled' : '') + '>'
            + '<span>' + esc(p.name) + (p.status !== 'publish' ? ' <em>(' + esc(p.status) + ')</em>' : '') + '</span><small>' + esc(p.date || '') + (have[p.id] ? ' · added' : ' · Add') + '</small></button>';
        }).join('') : '<div class="na-none">No product matches.</div>';
        res._items = j.items;
      } catch (e) { if (seq === searchSeq && ours()) res.innerHTML = '<div class="na-none bad">' + esc(e.message) + '</div>'; }
    }, 300);
  }

  // ── grid ──────────────────────────────────────────────────────────────────
  function cardHTML(i, n) {
    var off = !!ex[i.id];
    return '<li class="na-card' + (off ? ' off' : '') + '" draggable="true" data-id="' + i.id + '">'
      + '<div class="na-img">' + (i.image ? '<img src="' + esc(i.image) + '" alt="" width="200" height="200" loading="lazy" decoding="async" draggable="false">' : '')
      + '<span class="na-n">' + (off ? 'Hidden' : n) + '</span>'
      + '<button type="button" class="na-x" data-na-x="' + i.id + '" aria-pressed="' + (off ? 'true' : 'false') + '" title="' + (off ? 'Excluded — tap to show it again' : 'Exclude from New Arrivals') + '" aria-label="' + (off ? 'Show ' : 'Exclude ') + esc(i.name) + '">✕</button>'
      + (i.sold_out ? '<span class="pill grey na-so">Sold out</span>' : '') + '</div>'
      + '<div class="na-name">' + esc(i.name) + '</div>'
      + '<div class="na-meta"><span>' + esc(i.date ? 'Added ' + i.date : 'No date') + '</span>'
      + '<span class="na-mv"><button type="button" data-na-mv="-1" aria-label="Move up">↑</button><button type="button" data-na-mv="1" aria-label="Move down">↓</button></span>'
      + '<span class="na-grip" aria-hidden="true">⠿</span></div></li>';
  }
  function drawGrid() {
    var host = $('[data-na-grid]'); if (!host) return;
    cancelAnimationFrame(drawJob);
    if (gridErr) { host.innerHTML = '<div class="na-none bad">' + esc(gridErr) + '</div>'; return; }
    if (!items) { host.innerHTML = '<div class="na-none">Press <b>Display</b> to see the list exactly as the shop shows it. Tap ✕ to exclude a product (tap again to bring it back), drag to reorder, then Save.</div>'; return; }
    if (!items.length) { host.innerHTML = '<div class="na-none">New Arrivals is empty: no published product was added inside the age limit.</div>'; return; }
    host.innerHTML = '<ol class="na-grid"></ol>';
    var ol = host.firstChild, at = 0, shown = 0;
    // 60 cards now, 60 more each frame: the first rows paint at once.
    (function batch() {
      var end = Math.min(items.length, at + 60), h = '';
      for (; at < end; at++) { if (!ex[items[at].id]) shown++; h += cardHTML(items[at], shown); }
      ol.insertAdjacentHTML('beforeend', h);
      if (at < items.length) drawJob = requestAnimationFrame(batch);
    })();
  }
  function renumber() {
    var ol = $('.na-grid'); if (!ol) return;
    // The number is the place on the shop: a hidden product has none.
    var n = 0; ol.querySelectorAll('.na-card').forEach(function (li) { li.querySelector('.na-n').textContent = li.classList.contains('off') ? 'Hidden' : ++n; });
  }
  function move(id, to) {   // to = new index
    var from = items.findIndex(function (i) { return i.id === id; });
    if (from < 0 || to < 0 || to >= items.length || from === to) return;
    var it = items.splice(from, 1)[0]; items.splice(to, 0, it);
    var ol = $('.na-grid'), li = ol && ol.querySelector('[data-id="' + id + '"]');
    if (li) { var next = items[to + 1], ref = next ? ol.querySelector('[data-id="' + next.id + '"]') : null; ol.insertBefore(li, ref); }
    renumber(); paintBar();
  }
  function toggle(id) {
    ex[id] = !ex[id]; if (!ex[id]) delete ex[id];
    var li = $('.na-card[data-id="' + id + '"]'); if (!li) return;
    var off = !!ex[id], b = li.querySelector('.na-x');
    li.classList.toggle('off', off);
    b.setAttribute('aria-pressed', off ? 'true' : 'false');
    b.title = off ? 'Excluded — tap to show it again' : 'Exclude from New Arrivals';
    renumber(); paintBar();
  }

  // ── bar ───────────────────────────────────────────────────────────────────
  function paintBar() {
    var bar = $('[data-na-bar]'); if (!bar) return;
    var n = pending();
    bar.innerHTML = '<button type="button" class="btn ghost" data-na-display' + (loadingGrid ? ' disabled' : '') + '>' + (loadingGrid ? 'Loading…' : (items ? 'Reload list' : 'Display')) + '</button>'
      + '<span class="na-pend' + (n ? ' on' : '') + '">' + (items ? (n ? n + ' unsaved change' + (n === 1 ? '' : 's') : 'No unsaved changes') : '') + '</span>'
      + '<span class="na-flash' + (flashErr ? ' bad' : '') + '">' + esc(flash) + '</span>'
      + '<button type="button" class="btn" data-na-save' + (n && !busy ? '' : ' disabled') + '>' + (busy ? 'Saving…' : 'Save') + '</button>';
  }

  function paint() {
    var host = document.getElementById('content'); if (!host) return;
    host.innerHTML = '<div class="wrap" data-na><div class="page-head"><h2>New Arrivals</h2>'
      + '<p>Your newest products, kept in New Arrivals automatically. Choose how many and how recent, keep or hide single products, and set the order.</p></div>'
      + '<div data-na-set></div><div class="na-bar" data-na-bar></div><div data-na-grid></div></div>' + STYLE;
    var set = $('[data-na-set]');
    if (err) { set.innerHTML = '<div class="card pad na-none bad">' + esc(err) + '</div>'; return; }
    if (!st) { set.innerHTML = '<div class="card pad na-none">Loading…</div>'; return; }
    if (!st.category) { set.innerHTML = '<div class="card pad na-none">There is no New Arrivals category yet. Create one in Catalog → Categories with the address <b>new-arrivals</b>, then come back.</div>'; return; }
    lists.include = st.include.slice(); lists.exclude = st.exclude.slice();
    set.innerHTML = settingsHTML(); paintChips(); paintBar(); drawGrid();
  }

  var STYLE = '<style>'
    + '[data-na] .na-set{margin-bottom:14px}'
    + '.na-top{display:flex;gap:16px;align-items:flex-start;justify-content:space-between}'
    + '.na-h{font-weight:700;font-size:15px}.na-sub,.na-small,.na-help,.na-f small{color:var(--ink-soft);font-size:12px;line-height:1.5}'
    + '.na-sub{margin-top:3px}.na-sub a{color:inherit;text-decoration:underline}'
    + '[data-na] .tog{border:0;cursor:pointer;padding:0}'
    + '.na-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr);gap:14px;margin:16px 0}'
    + '.na-f{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600;min-width:0}'
    + '.na-f .inp{max-width:160px}.na-ck{display:flex;gap:7px;align-items:center;font-weight:600}'
    + '.na-win{display:flex;gap:8px}.na-win .inp{max-width:110px}'
    + '.na-lists{display:grid;grid-template-columns:1fr 1fr;gap:14px}'
    + '.na-pick{border:1px solid var(--border);border-radius:12px;padding:12px;min-width:0}.na-ph{font-weight:700;font-size:13px}'
    + '.na-sbox{position:relative;margin:8px 0}.na-sbox .inp{width:100%;max-width:none;box-sizing:border-box}'
    + '.na-res{display:flex;flex-direction:column;gap:2px;margin-top:4px;max-height:220px;overflow:auto}'
    + '.na-hit{display:flex;justify-content:space-between;gap:8px;text-align:start;background:var(--surface-2);border:0;border-radius:8px;padding:8px 10px;font:inherit;font-size:13px;color:var(--ink);cursor:pointer}'
    + '.na-hit:hover{background:var(--accent-soft)}.na-hit:disabled{opacity:.5;cursor:default}.na-hit small{color:var(--ink-soft);white-space:nowrap}'
    + '.na-chips{display:flex;flex-wrap:wrap;gap:6px}'
    + '.na-chip{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:4px 4px 4px 10px;border-radius:99px;background:var(--accent-soft);color:var(--accent-ink);max-width:100%}'
    + '.na-chip.ex{background:var(--red-soft);color:#b8362d}.na-chip em{font-weight:400}'
    + '.na-chip button{border:0;background:transparent;color:inherit;font-size:16px;line-height:1;width:24px;height:24px;border-radius:50%;cursor:pointer}'
    + '.na-chip button:hover{background:rgba(0,0,0,.08)}'
    + '.na-help{margin:12px 0}.na-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}'
    + '.na-note{font-size:12px;color:var(--accent-ink)}.na-note.bad,.na-none.bad,.na-flash.bad{color:#b8362d}'
    + '.na-none{color:var(--ink-soft);font-size:13px;padding:6px 0}'
    + '.na-bar{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 12px;margin:0 0 12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh-s)}'
    + '.na-pend{font-size:13px;color:var(--ink-soft);flex:1 1 auto}.na-pend.on{color:#9a6512;font-weight:700}'
    + '.na-flash{font-size:13px;font-weight:700;color:var(--accent-ink)}'
    + '.na-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}'
    + '.na-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:8px;cursor:grab;content-visibility:auto;contain-intrinsic-size:auto 250px;transition:opacity .15s,filter .15s}'
    + '.na-card.drag{opacity:.35}.na-card.over{outline:2px dashed var(--accent);outline-offset:2px}'
    + '.na-card.off{opacity:.55;filter:grayscale(.8)}'
    + '.na-img{position:relative;aspect-ratio:1;border-radius:9px;overflow:hidden;background:var(--surface-2)}'
    + '.na-img img{width:100%;height:100%;object-fit:cover;display:block}'
    + '.na-n{position:absolute;top:6px;inset-inline-start:6px;background:rgba(0,0,0,.6);color:#fff;font-size:11px;font-weight:700;border-radius:99px;padding:2px 7px}'
    + '.na-x{position:absolute;top:4px;inset-inline-end:4px;width:36px;height:36px;border-radius:50%;border:0;background:#e3493f;color:#fff;font-size:16px;font-weight:800;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.25)}'
    + '.na-card.off .na-x{background:#8b93a3}'
    + '.na-so{position:absolute;bottom:6px;inset-inline-start:6px}'
    + '.na-name{font-size:12.5px;font-weight:600;line-height:1.35;margin:7px 2px 3px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.7em}'
    + '.na-meta{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--ink-soft);margin:0 2px}.na-meta>span:first-child{flex:1 1 auto}'
    + '.na-grip{font-size:16px;color:var(--ink-faint);cursor:grab}'
    + '.na-mv{display:none;gap:4px}.na-mv button{width:34px;height:30px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--ink);font-size:15px;cursor:pointer}'
    + '@media (max-width:700px){.na-fields,.na-lists{grid-template-columns:1fr}.na-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}'
    + '.na-mv{display:inline-flex}.na-grip{display:none}.na-card{cursor:default}.na-meta{flex-wrap:wrap}}'
    + '</style>';

  // ── events (delegated; only while ours) ───────────────────────────────────
  document.addEventListener('click', function (e) {
    if (!ours()) return;
    var t = e.target;
    var x = t.closest('[data-na-x]'); if (x) { toggle(+x.getAttribute('data-na-x')); return; }
    var mv = t.closest('[data-na-mv]');
    if (mv) { var li = mv.closest('.na-card'), id = +li.dataset.id, at = items.findIndex(function (i) { return i.id === id; }); move(id, at + (+mv.getAttribute('data-na-mv'))); return; }
    if (t.closest('[data-na-display]')) { display(); return; }
    if (t.closest('[data-na-save]')) { saveGrid(); return; }
    if (t.closest('[data-na-save-set]')) { saveSettings(); return; }
    if (t.closest('[data-na-reset]')) { reset(); return; }
    var on = t.closest('[data-na-on]');
    if (on) { var v = !on.classList.contains('on'); on.classList.toggle('on', v); on.setAttribute('aria-checked', v ? 'true' : 'false'); return; }
    var pk = t.closest('[data-na-pick]');
    if (pk) {
      var a = pk.getAttribute('data-na-pick').split(':'), kind = a[0], pid = +a[1], res = pk.closest('[data-na-res]');
      var p = (res._items || []).find(function (i) { return i.id === pid; });
      if (p && !lists[kind].some(function (q) { return q.id === pid; })) { lists[kind].push(p); paintChips(); pk.disabled = true; setNote('Not saved yet — press Save settings.', false); }
      return;
    }
    var up = t.closest('[data-na-unpick]');
    if (up) { var b = up.getAttribute('data-na-unpick').split(':'); lists[b[0]] = lists[b[0]].filter(function (q) { return q.id !== +b[1]; }); paintChips(); setNote('Not saved yet — press Save settings.', false); }
  });
  document.addEventListener('input', function (e) {
    if (!ours()) return;
    var q = e.target.closest('[data-na-q]'); if (q) search(q.getAttribute('data-na-q'), q.value);
  });
  document.addEventListener('change', function (e) {
    if (!ours() || e.target.name !== 'na-won') return;
    var on = e.target.checked; $('[name=na-n]').disabled = !on; $('[name=na-unit]').disabled = !on;
  });
  // Drag to reorder (desktop). The DOM node moves; nothing is redrawn.
  var dragId = 0;
  document.addEventListener('dragstart', function (e) {
    var li = ours() && e.target.closest && e.target.closest('.na-card'); if (!li) return;
    dragId = +li.dataset.id; li.classList.add('drag'); e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', String(dragId)); } catch (x) {}
  });
  document.addEventListener('dragover', function (e) {
    var li = dragId && e.target.closest && e.target.closest('.na-card'); if (!li) return;
    e.preventDefault(); var o = $('.na-card.over'); if (o && o !== li) o.classList.remove('over'); li.classList.add('over');
  });
  document.addEventListener('drop', function (e) {
    var li = dragId && e.target.closest && e.target.closest('.na-card'); if (!li) return;
    e.preventDefault(); li.classList.remove('over');
    var to = items.findIndex(function (i) { return i.id === +li.dataset.id; });
    move(dragId, to);
  });
  document.addEventListener('dragend', function () {
    if (!dragId) return; dragId = 0;
    document.querySelectorAll('.na-card.drag,.na-card.over').forEach(function (c) { c.classList.remove('drag', 'over'); });
  });
  /* UNFINISHED CHANGES (Lane PM's registry): leaving with unsaved crosses or
     moves asks nothing -- they are kept under "Unfinished" in the top bar and
     go back into the grid when the owner opens the row. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Catalog → New Arrivals',
    values: function () { return items ? { order: orderKey(), excluded: items.filter(function (i) { return ex[i.id]; }).map(function (i) { return i.id; }).join(',') } : null; },
    set: function (k, v) {
      if (!items) return;
      var ids = String(v || '').split(',').filter(Boolean).map(Number);
      if (k === 'excluded') { ex = {}; ids.forEach(function (id) { ex[id] = true; }); }
      if (k === 'order') {
        var at = {}; ids.forEach(function (id, n) { at[id] = n; });
        items.sort(function (a, b) { return (a.id in at ? at[a.id] : 1e9) - (b.id in at ? at[b.id] : 1e9); });
      }
    },
    render: function () { if (ours()) { drawGrid(); paintBar(); } },
    count: function () { return pending(); },
    save: function () { saveGrid(); },
    open: function () { reopen = true; window.go(SCREEN); }
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN, label: 'New Arrivals', group: 'Catalog', after: ['concernsync', 'imageseo', 'product-editor', 'catalog'],
      icon: '<path d="M12 3l2.2 4.6 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5L4.8 8.3l5-.7z"/>'
    });
  }
  if (window.kbbAddNavEntry) addNavEntry();

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { items = null; return previousGo.apply(this, arguments); }
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]'); if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog'; if (title) title.textContent = 'New Arrivals';
    var side = document.querySelector('#side'); if (side) side.classList.remove('open');
    load();
  };
})();
</script>
