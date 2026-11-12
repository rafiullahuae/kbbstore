<script>
/*
  Catalog -> Best Sellers (2.60.661). The owner, 12 Nov 2026: "allow us to
  adjust the position of any product on this page manually with drag n drop
  function, and with cross icon if any product we want to remove. same like on
  the new arrival backend page".

  The /best-sellers/ list as the shop shows it (the last three months' sellers,
  App\Support\RecentBestSellers): Display draws it, a drag (or the arrows on a
  phone) reorders, the grey cross removes a product (tap again to bring it
  back), Save sends {order, excluded} once, Reset goes back to the plain
  ranking. The New Arrivals grid's own markup, style and events, prefixed bs-.

  Super light: nothing is fetched until Display; one POST per Save. Every
  string is escaped. Paints only while its own [data-bs] root is on show
  (ours()). Wraps window.go for 'bestsellers'.
*/
(function () {
  var SCREEN = 'bestsellers';
  var st = null, items = null, savedEx = {}, savedOrder = '', ex = {}, busy = false, flash = '', flashErr = false;
  var gridErr = '', loadingGrid = false, drawJob = 0;

  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ours() { return !!document.querySelector('#content [data-bs]'); }
  function $(sel) { return document.querySelector('#content [data-bs] ' + sel); }

  async function api(method, path, body) {
    var r = await fetch(base() + '/admin-api/best-sellers' + path, {
      method: method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      body: body ? JSON.stringify(body) : undefined
    });
    var j = null; try { j = await r.json(); } catch (e) {}
    if (!r.ok) {
      var msg = (j && (j.message || j.error)) || ('HTTP ' + r.status);
      if (r.status === 403) msg = 'Your role cannot change Best Sellers. An owner or manager can.';
      if (r.status === 404 && !(j && j.message)) msg = "This screen is not in the server's route table yet. Apply the package and reload.";
      if (r.status === 422 && j && j.errors) msg = Object.keys(j.errors).map(function (k) { return j.errors[k][0]; }).join(' ');
      throw new Error(msg);
    }
    return j;
  }

  function orderKey() { return items ? items.map(function (i) { return i.id; }).join(',') : ''; }
  function pending() {
    if (!items) return 0;
    var n = 0;
    items.forEach(function (i) { if (!!ex[i.id] !== !!savedEx[i.id]) n++; });
    if (orderKey() !== savedOrder) n++;
    return n;
  }
  function adopt(j) {
    st = j; items = j.items || []; ex = {}; savedEx = {};
    items.forEach(function (i) { if (i.excluded) { ex[i.id] = true; savedEx[i.id] = true; } });
    savedOrder = orderKey();
  }

  async function display() {
    if (loadingGrid) return;
    if (pending() && !window.confirm('Reload the list and lose your unsaved changes?')) return;
    loadingGrid = true; gridErr = ''; paintBar();
    try { var j = await api('GET', ''); if (!ours()) return; adopt(j); paintHead(); drawGrid(); }
    catch (e) { gridErr = e.message; if (ours()) drawGrid(); }
    finally { loadingGrid = false; if (ours()) paintBar(); }
  }
  async function saveGrid() {
    if (busy || !items) return;
    busy = true; flash = ''; flashErr = false; paintBar();
    var order = items.map(function (i) { return i.id; });
    var excluded = items.filter(function (i) { return ex[i.id]; }).map(function (i) { return i.id; });
    try {
      var j = await api('POST', '/save', { order: order, excluded: excluded });
      if (!ours()) return;
      adopt(j); drawGrid(); flash = 'Saved ✓';
      setTimeout(function () { if (flash === 'Saved ✓') { flash = ''; if (ours()) paintBar(); } }, 2500);
    } catch (e) { flash = e.message; flashErr = true; }
    finally { busy = false; if (ours()) paintBar(); }
  }
  async function reset() {
    if (busy) return;
    busy = true; paintBar();
    try { var j = await api('POST', '/reset'); if (!ours()) return; adopt(j); drawGrid(); flash = 'Back to the ranking ✓'; flashErr = false; }
    catch (e) { flash = e.message; flashErr = true; }
    finally { busy = false; if (ours()) paintBar(); }
  }

  function cardHTML(i, n) {
    var off = !!ex[i.id];
    return '<li class="bs-card' + (off ? ' off' : '') + '" draggable="true" data-id="' + i.id + '">'
      + '<div class="bs-img">' + (i.image ? '<img src="' + esc(i.image) + '" alt="" width="200" height="200" loading="lazy" decoding="async" draggable="false">' : '')
      + '<span class="bs-n">' + (off ? 'Removed' : n) + '</span>'
      + '<button type="button" class="bs-x" data-bs-x="' + i.id + '" aria-pressed="' + (off ? 'true' : 'false') + '" title="' + (off ? 'Removed — tap to show it again' : 'Remove from Best Sellers') + '" aria-label="' + (off ? 'Show ' : 'Remove ') + esc(i.name) + '">✕</button>'
      + (i.sold_out ? '<span class="pill grey bs-so">Sold out</span>' : '') + '</div>'
      + '<div class="bs-name">' + esc(i.name) + '</div>'
      + '<div class="bs-meta"><span>' + esc(i.units + ' sold') + '</span>'
      + '<span class="bs-mv"><button type="button" data-bs-mv="-1" aria-label="Move up">↑</button><button type="button" data-bs-mv="1" aria-label="Move down">↓</button></span>'
      + '<span class="bs-grip" aria-hidden="true">⠿</span></div></li>';
  }
  function drawGrid() {
    var host = $('[data-bs-grid]'); if (!host) return;
    cancelAnimationFrame(drawJob);
    if (gridErr) { host.innerHTML = '<div class="bs-none bad">' + esc(gridErr) + '</div>'; return; }
    if (!items) { host.innerHTML = '<div class="bs-none">Press <b>Display</b> to see the list exactly as the shop shows it. Tap ✕ to remove a product (tap again to bring it back), drag to reorder, then Save.</div>'; return; }
    if (!items.length) { host.innerHTML = '<div class="bs-none">No product sold in the last three months, so the page shows the shop\'s all-time best sellers and there is nothing to arrange here yet.</div>'; return; }
    host.innerHTML = '<ol class="bs-grid"></ol>';
    var ol = host.firstChild, at = 0, shown = 0;
    (function batch() {
      var end = Math.min(items.length, at + 60), h = '';
      for (; at < end; at++) { if (!ex[items[at].id]) shown++; h += cardHTML(items[at], shown); }
      ol.insertAdjacentHTML('beforeend', h);
      if (at < items.length) drawJob = requestAnimationFrame(batch);
    })();
  }
  function renumber() {
    var ol = $('.bs-grid'); if (!ol) return;
    var n = 0; ol.querySelectorAll('.bs-card').forEach(function (li) { li.querySelector('.bs-n').textContent = li.classList.contains('off') ? 'Removed' : ++n; });
  }
  function move(id, to) {
    var from = items.findIndex(function (i) { return i.id === id; });
    if (from < 0 || to < 0 || to >= items.length || from === to) return;
    var it = items.splice(from, 1)[0]; items.splice(to, 0, it);
    var ol = $('.bs-grid'), li = ol && ol.querySelector('[data-id="' + id + '"]');
    if (li) { var next = items[to + 1], ref = next ? ol.querySelector('[data-id="' + next.id + '"]') : null; ol.insertBefore(li, ref); }
    renumber(); paintBar();
  }
  function toggle(id) {
    ex[id] = !ex[id]; if (!ex[id]) delete ex[id];
    var li = $('.bs-card[data-id="' + id + '"]'); if (!li) return;
    var off = !!ex[id], b = li.querySelector('.bs-x');
    li.classList.toggle('off', off);
    b.setAttribute('aria-pressed', off ? 'true' : 'false');
    b.title = off ? 'Removed — tap to show it again' : 'Remove from Best Sellers';
    renumber(); paintBar();
  }

  function paintBar() {
    var bar = $('[data-bs-bar]'); if (!bar) return;
    var n = pending();
    bar.innerHTML = '<button type="button" class="btn ghost" data-bs-display' + (loadingGrid ? ' disabled' : '') + '>' + (loadingGrid ? 'Loading…' : (items ? 'Reload list' : 'Display')) + '</button>'
      + '<span class="bs-pend' + (n ? ' on' : '') + '">' + (items ? (n ? n + ' unsaved change' + (n === 1 ? '' : 's') : 'No unsaved changes') : '') + '</span>'
      + '<span class="bs-flash' + (flashErr ? ' bad' : '') + '">' + esc(flash) + '</span>'
      + (items ? '<button type="button" class="btn ghost" data-bs-reset data-kbb-sure="Reset Best Sellers to the plain ranking? Your order and removals are cleared."' + (busy ? ' disabled' : '') + '>Reset to the ranking</button>' : '')
      + '<button type="button" class="btn" data-bs-save' + (n && !busy ? '' : ' disabled') + '>' + (busy ? 'Saving…' : 'Save') + '</button>';
  }
  function paintHead() {
    var h = $('[data-bs-sub]'); if (!h || !st) return;
    h.innerHTML = 'The products sold most in the last ' + esc(Math.round(st.days / 30)) + ' months, up to ' + esc(st.max) + ', as <a href="' + esc(st.url) + '" target="_blank" rel="noopener">/best-sellers/</a> shows them.';
  }

  function paint() {
    var host = document.getElementById('content'); if (!host) return;
    host.innerHTML = '<div class="wrap" data-bs><div class="page-head"><h2>Best Sellers</h2>'
      + '<p data-bs-sub>The products sold most in the last three months, as the Best Sellers page shows them. Drag to change the order, tap ✕ to remove one, then Save. A product that becomes a best seller later is added after your order.</p></div>'
      + '<div class="bs-bar" data-bs-bar></div><div data-bs-grid></div></div>' + STYLE;
    paintBar(); drawGrid();
  }

  var STYLE = '<style>'
    + '[data-bs] .bs-set{margin-bottom:14px}'
    + '.bs-top{display:flex;gap:16px;align-items:flex-start;justify-content:space-between}'
    + '.bs-h{font-weight:700;font-size:15px}.bs-sub,.bs-small,.bs-help,.bs-f small{color:var(--ink-soft);font-size:12px;line-height:1.5}'
    + '.bs-sub{margin-top:3px}.bs-sub a{color:inherit;text-decoration:underline}'
    + '[data-bs] .tog{border:0;cursor:pointer;padding:0}'
    + '.bs-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr);gap:14px;margin:16px 0}'
    + '.bs-f{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600;min-width:0}'
    + '.bs-f .inp{max-width:160px}.bs-ck{display:flex;gap:7px;align-items:center;font-weight:600}'
    + '.bs-win{display:flex;gap:8px}.bs-win .inp{max-width:110px}'
    + '.bs-lists{display:grid;grid-template-columns:1fr 1fr;gap:14px}'
    + '.bs-pick{border:1px solid var(--border);border-radius:12px;padding:12px;min-width:0}.bs-ph{font-weight:700;font-size:13px}'
    + '.bs-sbox{position:relative;margin:8px 0}.bs-sbox .inp{width:100%;max-width:none;box-sizing:border-box}'
    + '.bs-res{display:flex;flex-direction:column;gap:2px;margin-top:4px;max-height:220px;overflow:auto}'
    + '.bs-hit{display:flex;justify-content:space-between;gap:8px;text-align:start;background:var(--surface-2);border:0;border-radius:8px;padding:8px 10px;font:inherit;font-size:13px;color:var(--ink);cursor:pointer}'
    + '.bs-hit:hover{background:var(--accent-soft)}.bs-hit:disabled{opacity:.5;cursor:default}.bs-hit small{color:var(--ink-soft);white-space:nowrap}'
    + '.bs-chips{display:flex;flex-wrap:wrap;gap:6px}'
    + '.bs-chip{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:4px 4px 4px 10px;border-radius:99px;background:var(--accent-soft);color:var(--accent-ink);max-width:100%}'
    + '.bs-chip.ex{background:var(--red-soft);color:#b8362d}.bs-chip em{font-weight:400}'
    + '.bs-chip button{border:0;background:transparent;color:inherit;font-size:16px;line-height:1;width:24px;height:24px;border-radius:50%;cursor:pointer}'
    + '.bs-chip button:hover{background:rgba(0,0,0,.08)}'
    + '.bs-help{margin:12px 0}.bs-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}'
    + '.bs-note{font-size:12px;color:var(--accent-ink)}.bs-note.bad,.bs-none.bad,.bs-flash.bad{color:#b8362d}'
    + '.bs-none{color:var(--ink-soft);font-size:13px;padding:6px 0}'
    + '.bs-bar{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 12px;margin:0 0 12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh-s)}'
    + '.bs-pend{font-size:13px;color:var(--ink-soft);flex:1 1 auto}.bs-pend.on{color:#9a6512;font-weight:700}'
    + '.bs-flash{font-size:13px;font-weight:700;color:var(--accent-ink)}'
    + '.bs-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}'
    + '.bs-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:8px;cursor:grab;content-visibility:auto;contain-intrinsic-size:auto 250px;transition:opacity .15s,filter .15s}'
    + '.bs-card.drag{opacity:.35}.bs-card.over{outline:2px dashed var(--accent);outline-offset:2px}'
    + '.bs-card.off{opacity:.55;filter:grayscale(.8)}'
    + '.bs-img{position:relative;aspect-ratio:1;border-radius:9px;overflow:hidden;background:var(--surface-2)}'
    + '.bs-img img{width:100%;height:100%;object-fit:cover;display:block}'
    + '.bs-n{position:absolute;top:6px;inset-inline-start:6px;background:rgba(0,0,0,.6);color:#fff;font-size:11px;font-weight:700;border-radius:99px;padding:2px 7px}'
    + '.bs-x{position:absolute;top:4px;inset-inline-end:4px;width:36px;height:36px;border-radius:50%;border:0;background:#e3493f;color:#fff;font-size:16px;font-weight:800;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.25)}'
    + '.bs-card.off .bs-x{background:#8b93a3}'
    + '.bs-so{position:absolute;bottom:6px;inset-inline-start:6px}'
    + '.bs-name{font-size:12.5px;font-weight:600;line-height:1.35;margin:7px 2px 3px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.7em}'
    + '.bs-meta{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--ink-soft);margin:0 2px}.bs-meta>span:first-child{flex:1 1 auto}'
    + '.bs-grip{font-size:16px;color:var(--ink-faint);cursor:grab}'
    + '.bs-mv{display:none;gap:4px}.bs-mv button{width:34px;height:30px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--ink);font-size:15px;cursor:pointer}'
    + '@media (max-width:700px){.bs-fields,.bs-lists{grid-template-columns:1fr}.bs-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}'
    + '.bs-mv{display:inline-flex}.bs-grip{display:none}.bs-card{cursor:default}.bs-meta{flex-wrap:wrap}}'
    + '</style>';

  document.addEventListener('click', function (e) {
    if (!ours()) return;
    var t = e.target;
    var x = t.closest('[data-bs-x]'); if (x) { toggle(+x.getAttribute('data-bs-x')); return; }
    var mv = t.closest('[data-bs-mv]');
    if (mv) { var li = mv.closest('.bs-card'), id = +li.dataset.id, at = items.findIndex(function (i) { return i.id === id; }); move(id, at + (+mv.getAttribute('data-bs-mv'))); return; }
    if (t.closest('[data-bs-display]')) { display(); return; }
    if (t.closest('[data-bs-save]')) { saveGrid(); return; }
    /* "Reset…" is asked first by the admin's one reset guard (reset-guard.blade.php),
       which re-clicks on Yes -- so this only ever runs after a Yes. */
    if (t.closest('[data-bs-reset]')) { reset(); return; }
  });
  var dragId = 0;
  document.addEventListener('dragstart', function (e) {
    var li = ours() && e.target.closest && e.target.closest('.bs-card'); if (!li) return;
    dragId = +li.dataset.id; li.classList.add('drag'); e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', String(dragId)); } catch (x) {}
  });
  document.addEventListener('dragover', function (e) {
    var li = dragId && e.target.closest && e.target.closest('.bs-card'); if (!li) return;
    e.preventDefault(); var o = $('.bs-card.over'); if (o && o !== li) o.classList.remove('over'); li.classList.add('over');
  });
  document.addEventListener('drop', function (e) {
    var li = dragId && e.target.closest && e.target.closest('.bs-card'); if (!li) return;
    e.preventDefault(); li.classList.remove('over');
    move(dragId, items.findIndex(function (i) { return i.id === +li.dataset.id; }));
  });
  document.addEventListener('dragend', function () {
    if (!dragId) return; dragId = 0;
    document.querySelectorAll('.bs-card.drag,.bs-card.over').forEach(function (c) { c.classList.remove('drag', 'over'); });
  });

  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Catalog → Best Sellers',
    values: function () { return items ? { order: orderKey(), excluded: items.filter(function (i) { return ex[i.id]; }).map(function (i) { return i.id; }).join(',') } : null; },
    set: function (k, v) {
      if (!items) return;
      var ids = String(v || '').split(',').filter(Boolean).map(Number);
      if (k === 'excluded') { ex = {}; ids.forEach(function (id) { ex[id] = true; }); }
      if (k === 'order') { var at = {}; ids.forEach(function (id, n) { at[id] = n; }); items.sort(function (a, b) { return (a.id in at ? at[a.id] : 1e9) - (b.id in at ? at[b.id] : 1e9); }); }
    },
    render: function () { if (ours()) { drawGrid(); paintBar(); } },
    count: function () { return pending(); },
    save: function () { saveGrid(); },
    open: function () { window.go(SCREEN); }
  });

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { items = null; return previousGo.apply(this, arguments); }
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]'); if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog'; if (title) title.textContent = 'Best Sellers';
    var side = document.querySelector('#side'); if (side) side.classList.remove('open');
    st = null; items = null; flash = ''; paint();
  };
})();
</script>
