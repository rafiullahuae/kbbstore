
/*
  Catalog -> Labels (Lane LBL). The owner, 15 Nov 2026, approving
  docs/plans/product-labels/: "okay labels are good. but make sure no
  compromise on the speed etc. and must be super working on blazing speed
  smoothly."

  The list (name, badge, product count, shows on, when, priority, on/off,
  edit / duplicate / delete), the editor (texts EN/AR, shape A-D, colours,
  where, dates, priority, on/off, a live preview) and the product picker:
  Pick products / By categories / Both together, an exclude list, a live
  total, keyboard.

  THE PICKER SEARCHES IN THE BROWSER. When the editor opens it loads ONE
  compact index of every product (GET labels/index, kept in localStorage and
  revalidated with its ETag, so a repeat visit downloads nothing); after that
  a keystroke is a loop over memory -- no request per keystroke, no timer.
  Every word must match the name, brand, SKU, Arabic name or a category, in
  any order, with the shop's Arabic normalisation (ArabicSearch::normalise)
  and its synonym groups (SearchTerms, ArabicSearch::TERMS / BRANDS), which
  arrive in the same index. 50 rows are drawn at a time. Save sends rules
  (picked ids, category ids, include sub-categories, excluded ids) once.

  Paints only while its own [data-lbl] root is on show (ours()). Every
  string is escaped. Wraps window.go for 'catlabels'; "Automatic badges"
  opens the existing Growth & Marketing -> Product Labels ('labels').
*/
(function () {
  var SCREEN = 'catlabels', PAGE = 50, CHIPMAX = 40;
  var COLOURS = ['#C13E63', '#E0567B', '#E23A4E', '#F08A5D', '#BE8E2E', '#1B9E77', '#2E7D6B', '#3F6FE0', '#7B6CF0', '#2A2228'];
  var TEXTS = ['#FFFFFF', '#2A2228', '#FFF3C4'];
  var SHAPES = [['A', 'Pill'], ['B', 'Ribbon'], ['C', 'Corner sash'], ['D', 'Soft tag']];
  var labels = null, today = '', listErr = '', view = 'list', cur = null, busy = false, flash = '', flashErr = false, delArm = 0, delTimer = 0;
  var P = null, ID = {}, CAT = {}, CATS = [], catDirect = {}, catAll = {}, SYN = [], SYNMAP = {}, TMAP = {}, idxErr = '', idxLoading = false;
  var shown = PAGE, hi = 0, found = [], showSel = false, chipsAll = false, openCats = {}, pvOpen = false, lastMs = 0;

  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function hex(s) { return /^#[0-9A-Fa-f]{6}$/.test(s || '') ? String(s).toUpperCase() : '#C13E63'; }
  function ours() { return !!document.querySelector('#content [data-lbl]'); }
  function $(sel) { return document.querySelector('#content [data-lbl] ' + sel); }

  async function api(method, path, body) {
    var r = await fetch(base() + '/admin-api/labels' + path, {
      method: method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      body: body ? JSON.stringify(body) : undefined
    });
    var j = null; try { j = await r.json(); } catch (e) {}
    if (!r.ok) {
      var msg = (j && (j.message || j.error)) || ('HTTP ' + r.status);
      if (r.status === 403) msg = 'Your role cannot change labels. An owner or manager can.';
      if (r.status === 404 && !(j && j.message)) msg = "This screen is not in the server's route table yet. Apply the package and reload.";
      if (r.status === 422 && j && j.errors) msg = Object.keys(j.errors).map(function (k) { return j.errors[k][0]; }).join(' ');
      throw new Error(msg);
    }
    return j;
  }

  /* ---------- the search index ---------- */
  /* ArabicSearch::normalise(), line for line: no tashkeel or tatweel, the
     alef/ta-marbuta/ya forms folded, Arabic-Indic digits to 0-9, lower case,
     punctuation to spaces. */
  var FOLD = { 'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ة': 'ه', 'ى': 'ي', 'ؤ': 'و', 'ئ': 'ي' };
  function norm(s) {
    s = String(s || '').replace(/[ً-ٰٟـ]/g, '')
      .replace(/[أإآٱةىؤئ]/g, function (c) { return FOLD[c]; })
      .replace(/[٠-٩]/g, function (c) { return String(c.charCodeAt(0) - 0x0660); })
      .replace(/[۰-۹]/g, function (c) { return String(c.charCodeAt(0) - 0x06F0); })
      .toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ');
    return s.replace(/\s+/g, ' ').trim();
  }
  function adopt(j) {
    P = []; ID = {}; CAT = {}; CATS = []; catDirect = {}; catAll = {};
    var brandAr = j.b || {};
    (j.c || []).forEach(function (c) { var o = { id: c[0], name: c[1], ar: c[2], parent: c[3], kids: [] }; CAT[o.id] = o; CATS.push(o); catDirect[o.id] = []; });
    CATS.forEach(function (c) { if (c.parent && CAT[c.parent]) CAT[c.parent].kids.push(c.id); else c.parent = 0; });
    (j.p || []).forEach(function (r) {
      var cats = (r[5] || []).filter(function (c) { return CAT[c]; });
      var catText = cats.map(function (c) { return CAT[c].name + ' ' + CAT[c].ar; }).join(' ');
      var bAr = (brandAr[String(r[2]).toLowerCase()] || []).join(' ');
      var p = { id: r[0], n: r[1], b: r[2], s: r[3], a: r[4], c: cats, pr: r[6], th: r[7], v: r[8] };
      p.hn = ' ' + norm(p.n + ' ' + p.b + ' ' + bAr);
      p.h = ' ' + norm(p.n + ' ' + p.b + ' ' + bAr + ' ' + p.s + ' ' + p.a + ' ' + catText);
      P.push(p); ID[p.id] = p;
      cats.forEach(function (c) { catDirect[c].push(p.id); });
    });
    function all(id) {
      if (catAll[id]) return catAll[id];
      var s = {}; catDirect[id].forEach(function (x) { s[x] = 1; });
      CAT[id].kids.forEach(function (k) { all(k).forEach(function (x) { s[x] = 1; }); });
      return (catAll[id] = Object.keys(s).map(Number));
    }
    CATS.forEach(function (c) { all(c.id); });
    SYN = (j.g || []).map(function (g) { return g.map(norm); });
    SYNMAP = {}; SYN.forEach(function (g) { g.forEach(function (w) { SYNMAP[w] = (SYNMAP[w] || []).concat(g); }); });
    TMAP = {}; Object.keys(j.t || {}).forEach(function (k) { TMAP[norm(k)] = norm(j.t[k]); });
  }
  async function loadIndex() {
    if (P || idxLoading) return;
    idxLoading = true; idxErr = '';
    var et = '', j = null;
    try { et = localStorage.getItem('kbb.lbl.idx.etag') || ''; } catch (e) {}
    try {
      var url = base() + '/admin-api/labels/index';
      var r = await fetch(url, { credentials: 'same-origin', headers: et ? { Accept: 'application/json', 'If-None-Match': et } : { Accept: 'application/json' } });
      if (r.status === 304) { try { j = JSON.parse(localStorage.getItem('kbb.lbl.idx') || 'null'); } catch (e) { j = null; } }
      if (!j && r.status === 304) r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!j) {
        if (!r.ok) throw new Error(r.status === 403 ? 'Your role cannot open labels.' : 'HTTP ' + r.status);
        var text = await r.text(); j = JSON.parse(text);
        try { localStorage.setItem('kbb.lbl.idx', text); localStorage.setItem('kbb.lbl.idx.etag', r.headers.get('ETag') || ''); } catch (e) { try { localStorage.removeItem('kbb.lbl.idx'); localStorage.removeItem('kbb.lbl.idx.etag'); } catch (x) {} }
      }
      adopt(j);
    } catch (e) { idxErr = e.message || 'The product list could not load.'; }
    finally { idxLoading = false; }
    if (ours() && view === 'edit') drawPicker(false);
  }

  /* every word must match, in any order */
  function alts(tok) {
    var a = [tok];
    if (tok.length > 4 && /[a-z]s$/.test(tok) && !/ss$/.test(tok)) a.push(tok.slice(0, -1));
    if (/^ال/.test(tok) && tok.length > 3) a.push(tok.slice(2));
    a.slice().forEach(function (w) { if (TMAP[w]) { a.push(TMAP[w]); if (SYNMAP[TMAP[w]]) a = a.concat(SYNMAP[TMAP[w]]); } });
    if (SYNMAP[tok]) a = a.concat(SYNMAP[tok]);
    else if (tok.length >= 4) SYN.forEach(function (g) { g.forEach(function (w) { if (w.indexOf(tok) === 0 && w.indexOf(' ') < 0) a = a.concat(g); }); });
    return a;
  }
  function tokens(nq) {
    var words = nq.split(' '), out = [];
    for (var i = 0; i < words.length; i++) {
      var two = i + 1 < words.length ? words[i] + ' ' + words[i + 1] : '';
      if (two && (TMAP[two] || SYNMAP[two])) { out.push(alts(two)); i++; continue; }
      out.push(alts(words[i]));
    }
    return out;
  }
  function search(q, pool) {
    var nq = norm(q);
    if (!nq) return pool.slice();
    var toks = tokens(nq), out = [];
    for (var i = 0; i < pool.length; i++) {
      var p = pool[i], score = 0, ok = true;
      for (var t = 0; t < toks.length; t++) {
        var al = toks[t], best = 0;
        for (var k = 0; k < al.length; k++) {
          var w = al[k], s = 0;
          if (p.hn.indexOf(' ' + w) >= 0) s = k === 0 ? 4 : 3;
          else if (p.h.indexOf(' ' + w) >= 0) s = k === 0 ? 3 : 2;
          else if (p.h.indexOf(w) >= 0) s = 1;
          if (s > best) best = s;
        }
        if (!best) { ok = false; break; }
        score += best;
      }
      if (ok) { if (p.hn.indexOf(nq) >= 0) score += 5; out.push([score, p]); }
    }
    out.sort(function (a, b) { return b[0] - a[0] || (a[1].n < b[1].n ? -1 : a[1].n > b[1].n ? 1 : 0); });
    return out.map(function (x) { return x[1]; });
  }
  function hl(text, q) {
    var s = esc(text);
    norm(q).split(' ').filter(function (t) { return t.length > 1 && /^[a-z0-9]+$/.test(t); }).forEach(function (t) {
      s = s.replace(new RegExp('(' + t + ')(?![^<]*>)', 'ig'), '<mark>$1</mark>');
    });
    return s;
  }

  /* ---------- labels ---------- */
  function blank() {
    return { id: 0, name: '', name_ar: '', text: '', text_ar: '', bg: '#7B6CF0', fg: '#FFFFFF', shape: 'A', placement: 'both', mode: 'products',
      starts_on: '', ends_on: '', priority: (labels ? labels.length : 0) + 1, active: true, picks: [], categories: [], with_children: true, excluded: [] };
  }
  function txt(l, ar) { return ar && l.text_ar ? l.text_ar : (l.text || l.name || 'Label'); }
  function mix(h, t) { var n = parseInt(hex(h).slice(1), 16), f = function (c) { return Math.round(c + (255 - c) * t); }; return '#' + [f(n >> 16), f((n >> 8) & 255), f(n & 255)].map(function (c) { return c.toString(16).padStart(2, '0'); }).join('').toUpperCase(); }
  function dark(h, t) { var n = parseInt(hex(h).slice(1), 16), f = function (c) { return Math.round(c * (1 - t)); }; return '#' + [f(n >> 16), f((n >> 8) & 255), f(n & 255)].map(function (c) { return c.toString(16).padStart(2, '0'); }).join('').toUpperCase(); }
  /* The shop's own markup (App\Services\CustomLabels::badge / stackHtml). */
  function badge(l, ar, shape) {
    var sh = shape || l.shape, bg = hex(l.bg), fg = hex(l.fg);
    var st = sh === 'D' ? 'background:' + mix(bg, .88) + ';color:' + dark(bg, .18) + ';border-color:' + mix(bg, .6) : 'background:' + bg + ';color:' + fg;
    return '<span class="plb plb-' + sh + '" style="' + st + '">' + esc(txt(l, ar)) + '</span>';
  }
  function stack(l, ar, extra) {
    if (l.shape === 'C') return '<span class="plb-sash" style="background:' + hex(l.bg) + ';color:' + hex(l.fg) + '">' + esc(txt(l, ar)) + '</span>';
    return '<span class="plb-s' + (extra ? ' ' + extra : '') + '">' + badge(l, ar) + '</span>';
  }
  function whenOf(l) {
    if (l.starts_on && l.starts_on > today) return ['blue', 'Starts ' + fmtD(l.starts_on)];
    if (l.ends_on && l.ends_on < today) return ['grey', 'Ended ' + fmtD(l.ends_on)];
    if (l.ends_on) return ['green', 'Until ' + fmtD(l.ends_on)];
    return ['green', 'Always'];
  }
  function fmtD(d) { var m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], p = String(d).split('-'); return (+p[2]) + ' ' + m[+p[1] - 1] + ' ' + p[0]; }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  async function loadList() {
    listErr = '';
    try { var j = await api('GET', ''); labels = j.labels || []; today = j.today || ''; }
    catch (e) { listErr = e.message; }
    if (ours() && view === 'list') drawList();
  }
  function adoptState(j) { labels = j.labels || labels; today = j.today || today; }

  /* ---------- 1. the list ---------- */
  function drawList() {
    var host = $('[data-lbl-body]'); if (!host) return;
    var n = labels ? labels.length : 0;
    var head = '<div class="lb-head"><div><h2>Labels</h2><p>Badges you create and put on the products you choose. A product can carry up to two; the lower priority number shows first.</p></div>'
      + '<button type="button" class="btn" data-a="new"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>New label</button></div>'
      + '<div class="lb-tabs"><button type="button" class="on">Your labels <span class="lb-n">' + n + '</span></button><button type="button" data-a="auto">Automatic badges →</button></div>';
    if (listErr) { host.innerHTML = head + '<div class="lb-empty bad">' + esc(listErr) + '</div>'; return; }
    if (!labels) { host.innerHTML = head + '<div class="lb-empty">Loading…</div>'; return; }
    var rows = labels.map(function (l) {
      var w = whenOf(l), hp = [];
      if (l.mode !== 'categories' && l.picked) hp.push(l.picked + ' picked');
      if (l.mode !== 'products' && l.cats) hp.push(plural(l.cats, 'category', 'categories'));
      var how = (hp.join(' + ') || 'none yet') + (l.excluded ? ' − ' + l.excluded + ' excluded' : '');
      var where = (l.placement !== 'page' ? '<span class="pill grey">Cards</span>' : '') + (l.placement !== 'cards' ? '<span class="pill grey">Page</span>' : '');
      var del = delArm === l.id;
      return '<div class="lb-row" data-id="' + l.id + '">'
        + '<div class="lb-c-badge">' + badge(l, false, l.shape === 'C' ? 'A' : l.shape) + '</div>'
        + '<div class="lb-c-name"><b>' + esc(l.name) + '</b><small dir="rtl">' + esc(l.name_ar) + '</small></div>'
        + '<div class="lb-c-cnt"><b>' + Number(l.count).toLocaleString() + '</b><small>' + esc(how) + '</small></div>'
        + '<div class="lb-c-where">' + where + '</div>'
        + '<div class="lb-c-when"><span class="pill ' + w[0] + '"><span class="d"></span>' + esc(w[1]) + '</span></div>'
        + '<div class="lb-c-prio"><span class="lb-prio"><button type="button" data-a="up" aria-label="Higher priority"' + (l.priority <= 1 ? ' disabled' : '') + '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m6 15 6-6 6 6"/></svg></button><b>' + l.priority + '</b><button type="button" data-a="down" aria-label="Lower priority"' + (l.priority >= 99 ? ' disabled' : '') + '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m6 9 6 6 6-6"/></svg></button></span></div>'
        + '<div class="lb-c-on"><button type="button" class="tog' + (l.active ? ' on' : '') + '" data-a="on" role="switch" aria-checked="' + (l.active ? 'true' : 'false') + '" aria-label="' + esc(l.name) + ' on"></button></div>'
        + '<div class="lb-c-act"><button type="button" class="btn ghost sm" data-a="edit">Edit</button>'
        + '<button type="button" class="lb-ib" data-a="dup" title="Duplicate" aria-label="Duplicate ' + esc(l.name) + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg></button>'
        + '<button type="button" class="lb-ib del' + (del ? ' arm' : '') + '" data-a="del" title="' + (del ? 'Click again to delete' : 'Delete') + '" aria-label="Delete ' + esc(l.name) + '">' + (del ? 'Delete?' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>') + '</button></div></div>';
    }).join('');
    host.innerHTML = head + (flash ? '<div class="lb-flash' + (flashErr ? ' bad' : '') + '">' + esc(flash) + '</div>' : '')
      + '<div class="lb-table"><div class="lb-row lb-hd"><div>Badge</div><div>Label</div><div>Products</div><div>Shows on</div><div>When</div><div>Priority</div><div>On</div><div></div></div>'
      + (rows || '<div class="lb-empty">No labels yet — "New label" makes the first one.</div>') + '</div>'
      + '<p class="lb-foot">Counts are live: a product added to a chosen category later gets the label automatically. The automatic sale, sold-out, new and bestseller badges stay under Growth &amp; Marketing → Product Labels.</p>';
  }
  async function rowAction(a, id) {
    var l = labels && labels.find(function (x) { return x.id === id; }); if (!l || busy) return;
    if (a === 'edit') { openEditor(id); return; }
    if (a === 'del' && delArm !== id) {
      delArm = id; clearTimeout(delTimer); delTimer = setTimeout(function () { delArm = 0; if (ours() && view === 'list') drawList(); }, 4000);
      drawList(); return;
    }
    busy = true; flash = ''; flashErr = false;
    try {
      var j;
      if (a === 'on') j = await api('POST', '/' + id + '/toggle', { active: !l.active });
      else if (a === 'up') j = await api('POST', '/' + id + '/priority', { priority: Math.max(1, l.priority - 1) });
      else if (a === 'down') j = await api('POST', '/' + id + '/priority', { priority: Math.min(99, l.priority + 1) });
      else if (a === 'dup') { j = await api('POST', '/' + id + '/duplicate'); flash = 'Copied — the copy is off until you switch it on.'; }
      else if (a === 'del') { clearTimeout(delTimer); delArm = 0; j = await api('DELETE', '/' + id); flash = 'Deleted “' + l.name + '”.'; }
      if (j) adoptState(j);
    } catch (e) { flash = e.message; flashErr = true; }
    finally { busy = false; if (ours() && view === 'list') drawList(); }
  }

  /* ---------- 2. the editor ---------- */
  async function openEditor(id) {
    view = 'edit'; flash = ''; flashErr = false; pvOpen = false; showSel = false; chipsAll = false; shown = PAGE; hi = 0;
    cur = blank(); paint();
    if (id) {
      try { var j = await api('GET', '/' + id); if (!ours() || view !== 'edit') return; cur = j.label; }
      catch (e) { flash = e.message; flashErr = true; }
      if (ours()) paint();
    }
    loadIndex();
  }
  function editorHtml() {
    return '<div class="lb-crumb"><button type="button" class="lb-back" data-a="back">← Labels</button></div>'
      + '<h2 class="lb-title" data-lbl-title></h2>'
      + '<div class="lb-ed"><div class="lb-card-box">'
      + '<div class="lb-sec">Name &amp; text</div><div class="lb-two">'
      + '<label class="lb-f">Name (English, admin only)<input class="inp" id="lbName" maxlength="80" autocomplete="off"></label>'
      + '<label class="lb-f">Name (Arabic)<input class="inp" id="lbNameAr" maxlength="80" dir="rtl" autocomplete="off"></label>'
      + '<label class="lb-f">Text on the badge · English<input class="inp" id="lbText" maxlength="22" autocomplete="off"><small>Up to 22 characters so it fits a phone card.</small></label>'
      + '<label class="lb-f">Text on the badge · Arabic<input class="inp" id="lbTextAr" maxlength="22" dir="rtl" autocomplete="off"><small>Shown on /ar pages. Empty = the English text.</small></label></div>'
      + '<div class="lb-sec">Style</div><div class="lb-f">Shape<div class="lb-shapes" data-lbl-shapes></div></div>'
      + '<div class="lb-two"><div class="lb-f">Badge colour<div class="lb-sw" data-lbl-bg></div></div><div class="lb-f">Text colour<div class="lb-sw" data-lbl-fg></div><div class="lb-cr" data-lbl-cr></div></div></div>'
      + '<div class="lb-sec">Where and when</div><div class="lb-f">Shows on<div class="lb-seg" data-lbl-where><button type="button" data-v="cards">Product cards</button><button type="button" data-v="page">Product page</button><button type="button" data-v="both">Both</button></div></div>'
      + '<div class="lb-two"><label class="lb-f">Starts (optional)<input class="inp" type="date" id="lbStart"></label><label class="lb-f">Ends (optional)<input class="inp" type="date" id="lbEnd"></label>'
      + '<label class="lb-f">Priority<input class="inp" type="number" min="1" max="99" id="lbPrio"><small>1 shows first. Two labels with the same number: the newer one first.</small></label>'
      + '<div class="lb-f">Status<div class="lb-stat"><button type="button" class="tog" data-a="active" role="switch" aria-label="Label on"></button><span data-lbl-when></span></div><small>Dates are store time (Business Details → Time zone), first and last day included.</small></div></div>'
      + '</div><div class="lb-card-box lb-prev"><div class="lb-sec">Live preview</div><div class="lb-pv" data-lbl-pv></div></div></div>'
      + '<div class="lb-card-box lb-pick"><div class="lb-pk-top"><div class="lb-seg" data-lbl-mode><button type="button" data-v="products">Pick products</button><button type="button" data-v="categories">By categories</button><button type="button" data-v="both">Both together</button></div></div>'
      + '<div class="lb-pk" data-lbl-pk>'
      + '<div class="lb-panel lb-pk-prods"><div class="lb-ph"><b>Products</b><span class="sp"></span><div class="lb-seg" data-lbl-show><button type="button" data-v="all" class="on">All</button><button type="button" data-v="sel">Picked only</button></div></div>'
      + '<div class="lb-sbox"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="inp" id="lbQ" placeholder="Name, brand, SKU or Arabic — any words, any order" autocomplete="off" spellcheck="false" aria-label="Search products"></div>'
      + '<div class="lb-kb"><span><kbd>↑</kbd> <kbd>↓</kbd> move</span><span><kbd>Enter</kbd> pick / unpick</span><span><kbd>Shift</kbd>+<kbd>Enter</kbd> pick all found</span><span><kbd>Esc</kbd> clear</span></div>'
      + '<div class="lb-rmeta"><span data-lbl-count></span><span class="lb-speed" data-lbl-ms></span><span class="sp"></span><button type="button" data-a="pickall">Pick all found</button><button type="button" data-a="unpickall">Unpick found</button></div>'
      + '<div class="lb-rlist" data-lbl-list role="listbox" aria-label="Products"></div>'
      + '<div class="lb-ph lb-pickh"><b>Picked</b><span class="lb-n" data-lbl-pn>0</span><span class="sp"></span><button type="button" class="btn ghost sm" data-a="clear">Clear</button></div><div class="lb-chips" data-lbl-chips></div></div>'
      + '<div class="lb-panel lb-pk-cats"><div class="lb-ph"><b>Categories</b><span class="sp"></span><button type="button" class="lb-tsw" data-a="sub"><span class="tog" data-lbl-sub></span>Include sub-categories</button></div>'
      + '<div class="lb-sbox"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="inp" id="lbCq" placeholder="Filter categories" autocomplete="off" aria-label="Filter categories"></div>'
      + '<div class="lb-tree" data-lbl-tree></div></div></div>'
      + '<div class="lb-panel lb-exw"><div class="lb-ph"><b>Exclude these products</b><span class="lb-n red" data-lbl-xn>0</span><span class="lb-mut">— for "all of a category except these"</span></div>'
      + '<div class="lb-sbox"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="inp" id="lbXq" placeholder="Search the products this label covers" autocomplete="off" aria-label="Search products to exclude"><div class="lb-exdd" data-lbl-xdd></div></div>'
      + '<div class="lb-chips" data-lbl-xchips></div></div>'
      + '<div class="lb-sum"><span class="big" data-lbl-total>0</span><span class="lab">products will show <span data-lbl-sname></span></span><span class="sp"></span>'
      + '<button type="button" class="btn ghost sm" data-a="pv">See them</button><button type="button" class="btn ghost sm" data-a="save">Save label</button><span class="br" data-lbl-break></span></div>'
      + '<div class="lb-panel lb-pvp" data-lbl-pvp hidden><div class="lb-pvgrid" data-lbl-pvgrid></div></div>'
      + '<div class="lb-flash" data-lbl-flash></div></div>';
  }
  function fillFields() {
    var set = function (id, v) { var el = $('#' + id); if (el) el.value = v == null ? '' : v; };
    set('lbName', cur.name); set('lbNameAr', cur.name_ar); set('lbText', cur.text); set('lbTextAr', cur.text_ar);
    set('lbStart', cur.starts_on); set('lbEnd', cur.ends_on); set('lbPrio', cur.priority);
  }
  function lum(h) { var n = parseInt(hex(h).slice(1), 16); return [n >> 16, (n >> 8) & 255, n & 255].map(function (c) { c /= 255; return c <= .03928 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4); }).reduce(function (a, c, i) { return a + c * [.2126, .7152, .0722][i]; }, 0); }
  function contrast(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }
  function sample() {
    var p = null;
    if (P) { for (var i = 0; i < cur.picks.length && !p; i++) if (ID[cur.picks[i]]) p = ID[cur.picks[i]]; if (!p) p = P.find(function (x) { return x.th; }) || P[0]; }
    return p || { n: 'Relief Sun: Rice + Probiotics SPF50+ 50ml', b: 'Beauty of Joseon', a: 'واقي شمس ريليف: أرز + بروبيوتيك', pr: 159, th: '' };
  }
  function cardHtml(p, ar) {
    var showCard = cur.placement !== 'page';
    return '<div class="lb-scard"' + (ar ? ' dir="rtl"' : '') + '><div class="lb-sthumb">' + (p.th ? '<img src="' + esc(p.th) + '" alt="" width="200" height="200" loading="lazy" decoding="async">' : '<span class="lb-ini">' + esc((p.b || p.n).slice(0, 2).toUpperCase()) + '</span>')
      + (showCard ? stack(cur, ar) : '') + '</div><div class="lb-scb"><small>' + esc(p.b) + '</small><b>' + esc(ar && p.a ? p.a : p.n) + '</b><span class="lb-spr">AED ' + Number(p.pr || 0).toFixed(2) + '</span><span class="lb-scart">' + (ar ? 'أضف إلى السلة' : 'Add to cart') + '</span></div></div>';
  }
  function drawEditor(keepSw) {
    var t = $('[data-lbl-title]'); if (!t) return;
    t.textContent = cur.id ? 'Edit label · ' + (cur.name || cur.text) : 'New label';
    $('[data-lbl-shapes]').innerHTML = SHAPES.map(function (s) {
      var demo = s[0] === 'C' ? '<span class="plb-sash" style="background:' + hex(cur.bg) + ';color:' + hex(cur.fg) + '">' + esc(txt(cur)) + '</span>' : '<span class="plb-s">' + badge(cur, false, s[0]) + '</span>';
      return '<button type="button" class="lb-shape' + (cur.shape === s[0] ? ' on' : '') + '" data-s="' + s[0] + '" aria-pressed="' + (cur.shape === s[0]) + '"><span class="lb-shw">' + demo + '</span><small>' + s[0] + ' · ' + s[1] + '</small></button>';
    }).join('');
    if (keepSw) {
      [].forEach.call(document.querySelectorAll('#content [data-lbl] .lb-swatch'), function (b) { b.classList.toggle('on', (b.getAttribute('data-bg') || b.getAttribute('data-fg')) === hex(b.hasAttribute('data-bg') ? cur.bg : cur.fg)); });
    } else {
    $('[data-lbl-bg]').innerHTML = COLOURS.map(function (c) { return '<button type="button" class="lb-swatch' + (hex(cur.bg) === c ? ' on' : '') + '" data-bg="' + c + '" style="background:' + c + '" aria-label="Badge colour ' + c + '"></button>'; }).join('') + '<input type="color" id="lbBgPick" value="' + hex(cur.bg).toLowerCase() + '" aria-label="Any badge colour">';
    $('[data-lbl-fg]').innerHTML = TEXTS.map(function (c) { return '<button type="button" class="lb-swatch' + (hex(cur.fg) === c ? ' on' : '') + '" data-fg="' + c + '" style="background:' + c + '" aria-label="Text colour ' + c + '"></button>'; }).join('') + '<input type="color" id="lbFgPick" value="' + hex(cur.fg).toLowerCase() + '" aria-label="Any text colour">';
    }
    var cr = contrast(cur.bg, cur.fg);
    $('[data-lbl-cr]').innerHTML = cur.shape === 'D' ? '<span class="mut">Soft tag uses the badge colour for its text.</span>' : cr >= 4.5 ? '<span class="ok">✓ Easy to read (' + cr.toFixed(1) + ':1)</span>' : cr >= 3 ? '<span class="amb">Readable at badge size (' + cr.toFixed(1) + ':1)</span>' : '<span class="bad">Hard to read (' + cr.toFixed(1) + ':1) — pick another text colour</span>';
    [].forEach.call(document.querySelectorAll('#content [data-lbl-where] button'), function (b) { b.classList.toggle('on', b.dataset.v === cur.placement); });
    $('[data-a="active"]').classList.toggle('on', !!cur.active);
    $('[data-a="active"]').setAttribute('aria-checked', cur.active ? 'true' : 'false');
    var w = whenOf(cur); $('[data-lbl-when]').innerHTML = (cur.active ? 'On' : 'Off') + ' · <span class="pill ' + w[0] + '">' + esc(w[1]) + '</span>';
    var p = sample();
    $('[data-lbl-pv]').innerHTML = '<div class="lb-pv2">' + cardHtml(p, false) + cardHtml(p, true) + '</div>'
      + '<div class="lb-pvt">Product page title row: ' + (cur.placement === 'cards' ? '<i>not shown (cards only)</i>' : '<span class="plb-t">' + badge(cur, false) + '</span>') + '</div>'
      + '<small class="lb-mut">Left: a product card. Right: the same label on /ar. The product page shows it on the main photo and beside the title.</small>';
    var sn = $('[data-lbl-sname]'); if (sn) sn.textContent = '“' + (cur.text || cur.name || 'this label') + '”';
  }

  /* ---------- 3. the picker ---------- */
  function drawPicker(reset) {
    if (!$('[data-lbl-pk]')) return;
    [].forEach.call(document.querySelectorAll('#content [data-lbl-mode] button'), function (b) { b.classList.toggle('on', b.dataset.v === cur.mode); });
    $('[data-lbl-pk]').className = 'lb-pk' + (cur.mode === 'products' ? ' only-p' : cur.mode === 'categories' ? ' only-c' : '');
    $('[data-lbl-sub]').classList.toggle('on', !!cur.with_children);
    if (reset) { $('#lbQ').value = ''; $('#lbXq').value = ''; shown = PAGE; hi = 0; }
    if (!P) {
      var msg = idxErr ? '<div class="lb-empty bad">' + esc(idxErr) + ' <button type="button" class="btn ghost sm" data-a="retry">Try again</button></div>' : '<div class="lb-empty">Loading the products…</div>';
      $('[data-lbl-list]').innerHTML = msg; $('[data-lbl-tree]').innerHTML = msg; return;
    }
    runSearch(); drawTree(); drawChips(); drawSum();
  }
  function runSearch() {
    var t0 = performance.now();
    var pool = showSel ? cur.picks.map(function (id) { return ID[id]; }).filter(Boolean) : P;
    found = search($('#lbQ').value, pool);
    lastMs = performance.now() - t0;
    $('[data-lbl-ms]').textContent = lastMs < 1 ? '<1 ms' : lastMs.toFixed(1) + ' ms';
    if (hi >= found.length) hi = 0;
    drawResults();
  }
  function catsOf(useSub) {
    var s = {};
    cur.categories.forEach(function (c) { ((useSub ? catAll : catDirect)[c] || []).forEach(function (i) { s[i] = 1; }); });
    return s;
  }
  function drawResults() {
    var q = $('#lbQ').value, picked = {}, inCat = cur.mode === 'both' ? catsOf(cur.with_children) : null;
    cur.picks.forEach(function (id) { picked[id] = 1; });
    $('[data-lbl-count]').innerHTML = '<b>' + found.length.toLocaleString() + '</b> ' + (q ? 'found' : (showSel ? 'picked' : 'products'));
    var rows = found.slice(0, shown).map(function (p, k) {
      var cats = p.c.slice(0, 2).map(function (c) { return CAT[c].name; }).join(', ');
      return '<div class="lb-r' + (k === hi ? ' hi' : '') + '" role="option" aria-selected="' + (!!picked[p.id]) + '" data-id="' + p.id + '" data-k="' + k + '">'
        + '<input type="checkbox" tabindex="-1"' + (picked[p.id] ? ' checked' : '') + ' aria-label="Pick ' + esc(p.n) + '">'
        + (p.th ? '<img class="lb-th" src="' + esc(p.th) + '" alt="" width="40" height="40" loading="lazy" decoding="async">' : '<span class="lb-th">' + esc((p.b || p.n).slice(0, 2).toUpperCase()) + '</span>')
        + '<div class="lb-rm"><div class="nm">' + hl(p.n, q) + (inCat && inCat[p.id] ? '<span class="lb-inl">in a ticked category</span>' : '') + (p.v ? '' : '<span class="lb-inl grey">hidden in the shop</span>') + '</div>'
        + '<div class="mt">' + esc(p.b) + (p.s ? ' · <span class="mono">' + hl(p.s, q) + '</span>' : '') + (cats ? ' · ' + esc(cats) : '') + '</div>' + (p.a ? '<div class="ar" dir="rtl">' + esc(p.a) + '</div>' : '') + '</div>'
        + '<div class="pr">AED ' + Number(p.pr || 0).toFixed(2) + '</div></div>';
    }).join('');
    var rest = found.length - shown;
    $('[data-lbl-list]').innerHTML = rows + (rest > 0 ? '<button type="button" class="lb-more" data-a="more">Show ' + Math.min(PAGE, rest) + ' more · ' + rest.toLocaleString() + ' left</button>' : '')
      + (!found.length ? '<div class="lb-empty">' + (q ? 'Nothing matches “' + esc(q) + '”. Every word has to match somewhere — name, brand, SKU, Arabic or category.' : 'Nothing picked yet.') + '</div>' : '');
  }
  function toggle(id) { var k = cur.picks.indexOf(id); if (k >= 0) cur.picks.splice(k, 1); else cur.picks.push(id); }
  function pickAll(on) {
    var have = {}; cur.picks.forEach(function (id) { have[id] = 1; });
    if (on) found.forEach(function (p) { if (!have[p.id]) { cur.picks.push(p.id); have[p.id] = 1; } });
    else { var f = {}; found.forEach(function (p) { f[p.id] = 1; }); cur.picks = cur.picks.filter(function (id) { return !f[id]; }); }
    if (on && cur.mode === 'categories') cur.mode = 'both';
    drawPicker(false);
  }
  function moveHi() {
    var rows = document.querySelectorAll('#content [data-lbl-list] .lb-r');
    [].forEach.call(rows, function (r) { r.classList.toggle('hi', +r.dataset.k === hi); });
    if (rows[hi]) rows[hi].scrollIntoView({ block: 'nearest' });
  }
  function drawChips() {
    $('[data-lbl-pn]').textContent = cur.picks.length;
    var list = cur.picks.map(function (id) { return ID[id]; }).filter(Boolean), more = chipsAll ? 0 : Math.max(0, list.length - CHIPMAX);
    $('[data-lbl-chips]').innerHTML = (list.length ? list.slice(0, chipsAll ? list.length : CHIPMAX).map(function (p) { return '<span class="lb-chip"><span>' + esc(p.n) + '</span><button type="button" data-rm="' + p.id + '" aria-label="Remove ' + esc(p.n) + '">✕</button></span>'; }).join('') : '<span class="lb-mut">Nothing picked yet — tick products in the list above.</span>')
      + (more ? '<button type="button" class="lb-chip" data-a="chipsall">+' + more + ' more</button>' : '');
    var xl = cur.excluded.map(function (id) { return ID[id]; }).filter(Boolean);
    $('[data-lbl-xn]').textContent = xl.length;
    $('[data-lbl-xchips]').innerHTML = xl.length ? xl.map(function (p) { return '<span class="lb-chip ex"><span>' + esc(p.n) + '</span><button type="button" data-xrm="' + p.id + '" aria-label="Keep ' + esc(p.n) + '">✕</button></span>'; }).join('') : '<span class="lb-mut">None. Search above to leave a product out even though its category is ticked.</span>';
  }
  function ancestors(id) { var out = [], c = CAT[id]; while (c && c.parent) { out.push(c.parent); c = CAT[c.parent]; } return out; }
  function drawTree() {
    var host = $('[data-lbl-tree]'); if (!host || !P) return;
    var cq = norm($('#lbCq').value), ticked = {}, html = '';
    cur.categories.forEach(function (c) { ticked[c] = 1; });
    function inherited(id) { return cur.with_children && ancestors(id).some(function (a) { return ticked[a]; }); }
    function matches(c) { return !cq || norm(c.name + ' ' + c.ar).indexOf(cq) >= 0 || c.kids.some(function (k) { return matches(CAT[k]); }); }
    function walk(id, depth) {
      var c = CAT[id]; if (!matches(c)) return;
      var inc = inherited(id), on = ticked[id] || inc, open = cq || openCats[id];
      var n = (cur.with_children ? catAll : catDirect)[id].length;
      html += '<div class="lb-tn' + (open ? ' open' : '') + (inc && !ticked[id] ? ' inc' : '') + '" style="padding-inline-start:' + (8 + depth * 20) + 'px" data-c="' + id + '">'
        + (c.kids.length ? '<button type="button" class="cv" data-t="' + id + '" aria-label="Open ' + esc(c.name) + '" aria-expanded="' + (!!open) + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="m9 6 6 6-6 6"/></svg></button>' : '<span class="cv"></span>')
        + '<input type="checkbox"' + (on ? ' checked' : '') + (inc && !ticked[id] ? ' disabled' : '') + ' aria-label="' + esc(c.name) + '">'
        + '<span class="cn2">' + esc(c.name) + (c.ar ? '<small dir="rtl">' + esc(c.ar) + '</small>' : '') + '</span><span class="ct">' + n.toLocaleString() + '</span></div>';
      if (open) c.kids.forEach(function (k) { walk(k, depth + 1); });
    }
    CATS.filter(function (c) { return !c.parent; }).forEach(function (c) { walk(c.id, 0); });
    host.innerHTML = html || '<div class="lb-empty">No category called that.</div>';
  }
  function covered(withExcl) {
    var set = {};
    if (cur.mode !== 'categories') cur.picks.forEach(function (id) { if (ID[id]) set[id] = 1; });
    if (cur.mode !== 'products') { var c = catsOf(cur.with_children); for (var k in c) set[k] = 1; }
    if (withExcl) cur.excluded.forEach(function (id) { delete set[id]; });
    return set;
  }
  function drawSum() {
    if (!P) return;
    var all = covered(false), set = covered(true), total = 0, ex = 0, cats = cur.mode === 'products' ? {} : catsOf(cur.with_children), fromCats = 0;
    for (var k in set) if (ID[k] && ID[k].v) total++;
    cur.excluded.forEach(function (id) { if (all[id]) ex++; });
    for (var c in cats) fromCats++;
    var picks = cur.mode === 'categories' ? 0 : cur.picks.filter(function (id) { return ID[id]; }).length, size = 0;
    for (var a in all) size++;
    var overlap = cur.mode === 'both' ? picks + fromCats - size : 0, parts = [];
    if (cur.mode !== 'categories') parts.push(picks + ' picked');
    if (cur.mode !== 'products') parts.push(fromCats.toLocaleString() + ' from ' + plural(cur.categories.length, 'category', 'categories') + (cur.with_children ? ' (with sub-categories)' : ''));
    var s = parts.join(' + ');
    if (overlap > 0) s += ' − ' + overlap + ' counted twice';
    if (ex) s += ' − ' + ex + ' excluded';
    if (size - ex > total) s += ' · ' + (size - ex - total) + ' hidden in the shop';
    $('[data-lbl-total]').textContent = total.toLocaleString();
    $('[data-lbl-break]').textContent = s;
    if (pvOpen) drawPv();
  }
  function drawPv() {
    var set = covered(true), list = [];
    for (var k in set) { if (list.length >= 24) break; if (ID[k]) list.push(ID[k]); }
    $('[data-lbl-pvgrid]').innerHTML = list.map(function (p) { return '<div>' + (p.th ? '<img src="' + esc(p.th) + '" alt="" width="64" height="64" loading="lazy" decoding="async">' : '<span class="lb-th">' + esc((p.b || p.n).slice(0, 2).toUpperCase()) + '</span>') + esc(p.n) + '</div>'; }).join('') || '<div class="lb-mut">No products yet.</div>';
  }
  function exSearch() {
    var dd = $('[data-lbl-xdd]'), q = $('#lbXq').value;
    if (!norm(q) || !P) { dd.classList.remove('on'); return; }
    var set = covered(false), pool = [];
    for (var k in set) if (ID[k]) pool.push(ID[k]);
    var res = search(q, pool).filter(function (p) { return cur.excluded.indexOf(p.id) < 0; }).slice(0, 12);
    dd.innerHTML = res.length ? res.map(function (p) { return '<div class="lb-r" data-x="' + p.id + '">' + (p.th ? '<img class="lb-th" src="' + esc(p.th) + '" alt="" width="40" height="40" loading="lazy">' : '<span class="lb-th">' + esc((p.b || p.n).slice(0, 2).toUpperCase()) + '</span>') + '<div class="lb-rm"><div class="nm">' + hl(p.n, q) + '</div><div class="mt">' + esc(p.b) + (p.s ? ' · ' + esc(p.s) : '') + '</div></div></div>'; }).join('') : '<div class="lb-empty">None of the products this label covers match.</div>';
    dd.classList.add('on');
  }
  function setFlash(m, bad) { flash = m; flashErr = !!bad; var f = $('[data-lbl-flash]'); if (f) { f.textContent = m; f.classList.toggle('bad', !!bad); } }
  async function save() {
    if (busy) return;
    cur.name = String(cur.name || '').trim(); cur.text = String(cur.text || '').trim();
    if (!cur.name) cur.name = cur.text;
    if (!cur.text) { setFlash('Write the text on the badge first.', true); var el = $('#lbText'); if (el) el.focus(); return; }
    busy = true; setFlash('Saving…');
    var body = { name: cur.name, name_ar: cur.name_ar || '', text: cur.text, text_ar: cur.text_ar || '', bg: hex(cur.bg), fg: hex(cur.fg), shape: cur.shape, placement: cur.placement, mode: cur.mode,
      starts_on: cur.starts_on || null, ends_on: cur.ends_on || null, priority: Math.max(1, Math.min(99, +cur.priority || 1)), active: !!cur.active,
      picks: cur.picks, categories: cur.categories, with_children: !!cur.with_children, excluded: cur.excluded };
    try {
      var j = cur.id ? await api('PUT', '/' + cur.id, body) : await api('POST', '', body);
      adoptState(j); if (!cur.id && j.id) cur.id = j.id;
      if (!ours()) return;
      setFlash('Saved ✓ — the shop shows it now.');
      drawEditor();
    } catch (e) { setFlash(e.message, true); }
    finally { busy = false; }
  }

  /* ---------- painting and events ---------- */
  function paint() {
    var host = document.getElementById('content'); if (!host) return;
    host.innerHTML = '<div class="wrap" data-lbl><div data-lbl-body>' + (view === 'edit' ? editorHtml() : '') + '</div></div>' + STYLE;
    if (view === 'edit') { fillFields(); drawEditor(); drawPicker(true); if (flash) setFlash(flash, flashErr); }
    else drawList();
  }

  document.addEventListener('click', function (e) {
    if (!ours()) return;
    var t = e.target, b = t.closest('[data-a]'), a = b ? b.getAttribute('data-a') : '';
    if (view === 'list') {
      if (a === 'new') { openEditor(0); return; }
      if (a === 'auto') { window.go('labels'); return; }
      var row = t.closest('.lb-row[data-id]');
      if (row && a) rowAction(a, +row.getAttribute('data-id'));
      return;
    }
    if (a === 'back') { view = 'list'; cur = null; flash = ''; paint(); loadList(); return; }
    if (a === 'save') { save(); return; }
    if (a === 'retry') { idxErr = ''; loadIndex(); drawPicker(false); return; }
    if (a === 'active') { cur.active = !cur.active; drawEditor(); return; }
    if (a === 'sub') { e.preventDefault(); cur.with_children = !cur.with_children; drawPicker(false); return; }
    if (a === 'more') { shown += PAGE; drawResults(); return; }
    if (a === 'pickall') { pickAll(true); return; }
    if (a === 'unpickall') { pickAll(false); return; }
    if (a === 'clear') { cur.picks = []; drawPicker(false); return; }
    if (a === 'chipsall') { chipsAll = true; drawChips(); return; }
    if (a === 'pv') { pvOpen = !pvOpen; $('[data-lbl-pvp]').hidden = !pvOpen; b.textContent = pvOpen ? 'Hide them' : 'See them'; if (pvOpen) drawPv(); return; }
    var s = t.closest('[data-s]'); if (s) { cur.shape = s.getAttribute('data-s'); drawEditor(); return; }
    var c = t.closest('[data-bg]'); if (c) { cur.bg = c.getAttribute('data-bg'); drawEditor(); return; }
    c = t.closest('[data-fg]'); if (c) { cur.fg = c.getAttribute('data-fg'); drawEditor(); return; }
    var w = t.closest('[data-lbl-where] [data-v]'); if (w) { cur.placement = w.getAttribute('data-v'); drawEditor(); return; }
    var m = t.closest('[data-lbl-mode] [data-v]'); if (m) { cur.mode = m.getAttribute('data-v'); drawPicker(false); return; }
    var sv = t.closest('[data-lbl-show] [data-v]');
    if (sv) { showSel = sv.getAttribute('data-v') === 'sel'; [].forEach.call(sv.parentNode.children, function (x) { x.classList.toggle('on', x === sv); }); shown = PAGE; hi = 0; runSearch(); return; }
    var rm = t.closest('[data-rm]'); if (rm) { toggle(+rm.getAttribute('data-rm')); drawResults(); drawChips(); drawSum(); return; }
    var xr = t.closest('[data-xrm]'); if (xr) { var xid = +xr.getAttribute('data-xrm'); cur.excluded = cur.excluded.filter(function (id) { return id !== xid; }); drawResults(); drawChips(); drawSum(); return; }
    var xa = t.closest('[data-x]'); if (xa) { cur.excluded.push(+xa.getAttribute('data-x')); $('#lbXq').value = ''; $('[data-lbl-xdd]').classList.remove('on'); drawChips(); drawSum(); return; }
    var tg = t.closest('[data-t]'); if (tg) { var tid = +tg.getAttribute('data-t'); openCats[tid] = !openCats[tid]; drawTree(); return; }
    var tn = t.closest('.lb-tn');
    if (tn) {
      var cb = tn.querySelector('input'); if (cb.disabled) { e.preventDefault(); return; }
      var cid = +tn.getAttribute('data-c'), k = cur.categories.indexOf(cid);
      if (k >= 0) cur.categories.splice(k, 1);
      else { cur.categories.push(cid); if (cur.with_children) cur.categories = cur.categories.filter(function (x) { return ancestors(x).indexOf(cid) < 0; }); }
      if (cur.mode === 'products') cur.mode = 'both';
      drawPicker(false); return;
    }
    var r = t.closest('[data-lbl-list] .lb-r');
    if (r) { hi = +r.getAttribute('data-k'); toggle(+r.getAttribute('data-id')); if (cur.mode === 'categories') cur.mode = 'both'; drawPicker(false); return; }
    if (!t.closest('.lb-exw')) { var dd = $('[data-lbl-xdd]'); if (dd) dd.classList.remove('on'); }
  });
  document.addEventListener('input', function (e) {
    if (!ours() || view !== 'edit' || !cur) return;
    var t = e.target, id = t.id;
    if (id === 'lbQ') { shown = PAGE; hi = 0; if (P) runSearch(); return; }
    if (id === 'lbCq') { drawTree(); return; }
    if (id === 'lbXq') { exSearch(); return; }
    if (id === 'lbBgPick') cur.bg = t.value.toUpperCase();
    else if (id === 'lbFgPick') cur.fg = t.value.toUpperCase();
    else if (id === 'lbName') cur.name = t.value;
    else if (id === 'lbNameAr') cur.name_ar = t.value;
    else if (id === 'lbText') cur.text = t.value;
    else if (id === 'lbTextAr') cur.text_ar = t.value;
    else if (id === 'lbStart') cur.starts_on = t.value;
    else if (id === 'lbEnd') cur.ends_on = t.value;
    else if (id === 'lbPrio') cur.priority = Math.max(1, Math.min(99, +t.value || 1));
    else return;
    drawEditor(id === 'lbBgPick' || id === 'lbFgPick');
  });
  document.addEventListener('keydown', function (e) {
    if (!ours() || view !== 'edit' || !P || e.target.id !== 'lbQ') return;
    var max = Math.min(shown, found.length) - 1;
    if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(max, hi + 1); moveHi(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(0, hi - 1); moveHi(); }
    else if (e.key === 'Enter' && e.shiftKey) { e.preventDefault(); pickAll(true); }
    else if (e.key === 'Enter') { e.preventDefault(); if (found[hi]) { toggle(found[hi].id); if (cur.mode === 'categories') cur.mode = 'both'; drawResults(); drawChips(); drawSum(); } }
    else if (e.key === 'Escape') { e.target.value = ''; shown = PAGE; hi = 0; runSearch(); }
  });

  /* The shop's badge stylesheet, the same string as App\Services\CustomLabels::CSS
     (ProductLabelsCustomTest holds the two together), so the preview is the shop. */
  var SHOPCSS = '.plb-s{position:absolute;top:9px;inset-inline-start:9px;display:flex;flex-direction:column;align-items:flex-start;gap:5px;z-index:3;max-width:calc(100% - 56px);pointer-events:none}.plb-s.plb-e{max-width:calc(100% - 78px)}.plb-s.plb-r{top:38px}.plb-s.plb-as{top:46px}.plb-s.plb-r.plb-as{top:72px}.plb{display:inline-flex;align-items:center;gap:5px;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;box-sizing:border-box}.plb-A,.plb-B,.plb-C{font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}.plb-A{padding:4px 9px;border-radius:20px;box-shadow:0 2px 8px rgba(0,0,0,.18)}.plb-B{padding:5px 18px 5px 10px;margin-inline-start:-9px;clip-path:polygon(0 0,100% 0,calc(100% - 7px) 50%,100% 100%,0 100%)}[dir=rtl] .plb-B{padding:5px 10px 5px 18px;clip-path:polygon(0 0,100% 0,100% 100%,0 100%,7px 50%)}.plb-C{font-size:9px;padding:3px 8px;border-radius:5px}.plb-D{font-size:10.5px;font-weight:600;padding:3px 9px 3px 7px;border-radius:7px;border:1px solid}.plb-D::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}.plb-sash{position:absolute;top:15px;left:-34px;width:130px;transform:rotate(-45deg);text-align:center;font-size:9px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:4px 18px;z-index:3;box-shadow:0 2px 6px rgba(0,0,0,.18);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:none;box-sizing:border-box}[dir=rtl] .plb-sash{left:auto;right:-34px;transform:rotate(45deg)}.gmain .plb-s{top:14px;inset-inline-start:14px;gap:6px;max-width:calc(100% - 76px)}.gmain .plb-s.plb-as{top:58px}.gmain .plb-A,.gmain .plb-B,.gmain .plb-C{font-size:11px;padding:5px 11px}.gmain .plb-B{padding:6px 22px 6px 14px;margin-inline-start:-14px}.gmain .plb-D{font-size:12px;padding:4px 10px 4px 8px}.gmain .plb-sash{font-size:10.5px;top:22px;left:-38px;width:160px;padding:5px 22px}[dir=rtl] .gmain .plb-sash{left:auto;right:-38px}.gmain .plb-s .lbl{position:static}.kbb-card-thumb>.lbl.plb-x{left:auto;right:auto;inset-inline-start:auto;inset-inline-end:9px}.plb-t{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 10px}.plb-t .plb{margin:0;clip-path:none;border-radius:20px;padding:4px 10px}.plb-t .plb-D{border-radius:7px}';

  var STYLE = '<style>' + SHOPCSS
    + '[data-lbl]{font-size:13px}[data-lbl] .tog{border:0;cursor:pointer;padding:0}[data-lbl] button{font:inherit;cursor:pointer}'
    + '.lb-head{display:flex;gap:16px;align-items:flex-start;justify-content:space-between;margin-bottom:14px}.lb-head h2,.lb-title{font-size:20px;font-weight:700;letter-spacing:-.015em;margin:0}.lb-head p{color:var(--ink-soft);margin:3px 0 0;max-width:600px}'
    + '.lb-tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:14px;overflow-x:auto}.lb-tabs button{background:none;border:0;border-bottom:2px solid transparent;padding:9px 12px;font-weight:600;color:var(--ink-soft);white-space:nowrap}.lb-tabs button.on{color:var(--accent-ink);border-bottom-color:var(--accent)}'
    + '.lb-n{display:inline-block;font-size:11px;font-weight:700;background:var(--surface-3);color:var(--ink-soft);border-radius:99px;padding:1px 8px;margin-inline-start:4px}.lb-n.red{background:var(--red-soft);color:#b8362d}'
    + '.lb-table{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}'
    + '.lb-row{display:grid;grid-template-columns:116px minmax(110px,1.2fr) minmax(110px,1fr) 110px 130px 96px 50px 170px;gap:8px;align-items:center;padding:11px 12px;border-bottom:1px solid var(--border-2)}.lb-row:last-child{border-bottom:0}'
    + '.lb-hd{font-size:11px;font-weight:600;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.04em;padding:10px 12px}'
    + '.lb-c-badge{position:relative;min-width:0}.lb-c-badge .plb{max-width:110px}.lb-c-name,.lb-c-cnt{min-width:0}.lb-c-name b,.lb-c-cnt b{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.lb-c-name small,.lb-c-cnt small{display:block;color:var(--ink-soft);font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.lb-c-name small{text-align:start}'
    + '.lb-c-where{display:flex;gap:4px;flex-wrap:wrap}.lb-prio{display:inline-flex;align-items:center;gap:4px}.lb-prio b{min-width:20px;text-align:center}'
    + '.lb-prio button{width:26px;height:24px;border:1px solid var(--border);border-radius:6px;display:grid;place-items:center;color:var(--ink-soft);background:var(--surface)}.lb-prio button:disabled{opacity:.4;cursor:default}.lb-prio svg{width:11px;height:11px}'
    + '.lb-c-act{display:flex;gap:5px;justify-content:flex-end}.lb-ib{min-width:32px;height:32px;border:1px solid var(--border);border-radius:9px;display:grid;place-items:center;color:var(--ink-soft);background:var(--surface);padding:0 6px}.lb-ib svg{width:15px;height:15px}.lb-ib.del:hover,.lb-ib.arm{color:#b8362d;border-color:#f3cfcb;background:var(--red-soft);font-size:12px;font-weight:700}'
    + '.lb-empty{padding:26px;text-align:center;color:var(--ink-soft)}.lb-empty.bad,.lb-flash.bad,.bad{color:#b8362d}.lb-flash{font-weight:700;color:var(--accent-ink);margin:8px 0;min-height:1em}.lb-foot{color:var(--ink-soft);font-size:12px;margin-top:10px}.lb-mut{color:var(--ink-soft);font-size:12px}'
    + '.lb-crumb{margin-bottom:4px}.lb-back{background:none;border:0;color:var(--ink-soft);font-weight:600;padding:4px 0}.lb-title{margin-bottom:14px}'
    + '.lb-ed{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:16px;align-items:start}'
    + '.lb-card-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:16px 18px;min-width:0}.lb-prev{position:sticky;top:12px}.lb-pick{margin-top:16px}'
    + '.lb-sec{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-soft);margin:6px 0 10px}.lb-sec:not(:first-child){margin-top:18px}'
    + '.lb-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.lb-f{display:flex;flex-direction:column;gap:6px;font-weight:600;min-width:0}.lb-f .inp{width:100%;box-sizing:border-box}.lb-f small{font-weight:400;color:var(--ink-soft);font-size:11.5px}'
    + '.lb-shapes{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.lb-shape{border:1px solid var(--border);border-radius:12px;padding:10px 8px 9px;background:var(--surface);display:flex;flex-direction:column;align-items:center;gap:8px;min-width:0}'
    + '.lb-shape.on{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}.lb-shw{position:relative;width:100%;height:54px;border-radius:9px;background:linear-gradient(135deg,#fde7ef,#f3eefc);overflow:hidden}.lb-shw .plb-s{top:15px;inset-inline-start:12px;max-width:calc(100% - 16px)}.lb-shw .plb-sash{top:12px;left:-40px}.lb-shape small{font-size:11.5px;font-weight:600;color:var(--ink-2)}'
    + '.lb-sw{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.lb-swatch{width:28px;height:28px;border-radius:8px;border:2px solid #fff;box-shadow:0 0 0 1px var(--border);padding:0}.lb-swatch.on{box-shadow:0 0 0 2px var(--ink)}.lb-sw input[type=color]{width:36px;height:30px;border:1px solid var(--border);border-radius:8px;padding:2px;background:var(--surface)}'
    + '.lb-cr{font-size:11.5px;font-weight:600}.lb-cr .ok{color:var(--accent-ink)}.lb-cr .amb{color:#9a6512}.lb-cr .mut{color:var(--ink-soft)}'
    + '.lb-seg{display:inline-flex;background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:3px;gap:2px;flex-wrap:wrap}.lb-seg button{background:none;border:0;border-radius:7px;padding:6px 12px;font-weight:600;color:var(--ink-soft);font-size:12.5px}.lb-seg button.on{background:var(--surface);color:var(--accent-ink);box-shadow:var(--sh-s)}'
    + '.lb-stat{display:flex;align-items:center;gap:8px;min-height:38px}'
    + '.lb-pv2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.lb-scard{border:1px solid rgba(42,34,40,.08);border-radius:14px;overflow:hidden;background:#fff;color:#2A2228;min-width:0}'
    + '.lb-sthumb{position:relative;aspect-ratio:1;overflow:hidden;background:linear-gradient(135deg,#fde7ef,#f3eefc);display:grid;place-items:center}.lb-sthumb img{width:100%;height:100%;object-fit:cover}.lb-ini{font-size:22px;font-weight:800;color:rgba(42,34,40,.35)}'
    + '.lb-scb{padding:9px;display:flex;flex-direction:column;gap:3px;min-width:0}.lb-scb small{font-size:10px;font-weight:700;letter-spacing:.1em;color:#756C74;text-transform:uppercase}.lb-scb b{font-size:12.5px;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.lb-spr{font-weight:700;color:#C13E63;font-size:12.5px}.lb-scart{display:block;text-align:center;background:#C13E63;color:#fff;border-radius:9px;padding:7px;font-weight:600;font-size:12px;margin-top:4px}'
    + '.lb-pvt{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--ink-soft);font-weight:600;margin:12px 0 6px}.lb-pvt .plb-t{margin:0}'
    + '.lb-pk-top{margin-bottom:14px}.lb-pk{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:14px;align-items:start}.lb-pk.only-p,.lb-pk.only-c{grid-template-columns:minmax(0,1fr)}.lb-pk.only-p .lb-pk-cats,.lb-pk.only-c .lb-pk-prods{display:none}'
    + '.lb-panel{border:1px solid var(--border);border-radius:14px;background:var(--surface);min-width:0}.lb-exw{margin-top:14px}.lb-ph{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid var(--border-2);flex-wrap:wrap}.lb-ph .sp,.lb-rmeta .sp,.lb-sum .sp{flex:1}.lb-pickh{border-top:1px solid var(--border-2)}'
    + '.lb-sbox{position:relative;padding:12px 14px 8px}.lb-sbox svg{position:absolute;inset-inline-start:26px;top:24px;width:17px;height:17px;color:var(--ink-faint);pointer-events:none}.lb-sbox .inp{width:100%;box-sizing:border-box;padding-inline-start:38px;font-size:14px;height:44px}'
    + '.lb-kb{font-size:11px;color:var(--ink-faint);padding:0 14px 8px;display:flex;gap:10px;flex-wrap:wrap}.lb-kb kbd{font-family:var(--mono);font-size:10px;border:1px solid var(--border);border-bottom-width:2px;border-radius:5px;padding:0 4px;background:var(--surface-2);color:var(--ink-2)}'
    + '.lb-rmeta{display:flex;align-items:center;gap:8px;padding:6px 14px 8px;font-size:12px;color:var(--ink-soft);flex-wrap:wrap}.lb-rmeta button{background:none;border:0;font-weight:600;color:var(--accent-ink);font-size:12px;padding:4px 2px}'
    + '.lb-speed{font-family:var(--mono);font-size:11px;color:var(--accent-ink);background:var(--accent-soft);border-radius:6px;padding:1px 6px}'
    + '.lb-rlist{max-height:440px;overflow-y:auto;border-top:1px solid var(--border-2);overscroll-behavior:contain}'
    + '.lb-r{display:grid;grid-template-columns:20px 40px minmax(0,1fr) auto;gap:10px;align-items:center;padding:8px 14px;border-bottom:1px solid var(--border-2);cursor:pointer}.lb-r:hover{background:var(--surface-2)}.lb-r.hi{background:var(--accent-soft)}'
    + '.lb-r input{width:16px;height:16px;accent-color:var(--accent);pointer-events:none}.lb-th{width:40px;height:40px;border-radius:9px;object-fit:cover;display:grid;place-items:center;font-size:10px;font-weight:800;color:rgba(42,34,40,.45);background:linear-gradient(135deg,#fde7ef,#eef1f9)}'
    + '.lb-rm{min-width:0}.lb-r .nm{font-size:13px;font-weight:600;line-height:1.3;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}.lb-r .mt{font-size:11.5px;color:var(--ink-soft);margin-top:2px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.lb-r .ar{font-size:11.5px;color:var(--ink-faint);text-align:right;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}'
    + '.lb-r .pr{font-size:12px;font-weight:700;white-space:nowrap}.lb-r mark{background:#fff3b0;color:inherit;border-radius:3px;padding:0 1px}.lb-inl{font-size:10px;font-weight:700;color:var(--accent-ink);background:var(--accent-soft);border-radius:99px;padding:1px 7px;margin-inline-start:6px;white-space:nowrap}.lb-inl.grey{color:var(--ink-soft);background:var(--surface-3)}'
    + '.lb-more{display:block;width:100%;padding:11px;font-weight:600;color:var(--accent-ink);background:none;border:0}'
    + '.lb-chips{display:flex;flex-wrap:wrap;gap:6px;padding:12px 14px}.lb-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;font-size:12px;font-weight:600;color:var(--ink-2);background:var(--surface-2);border:1px solid var(--border);border-radius:99px;padding:4px 6px 4px 11px}'
    + '.lb-chip span{overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:240px}.lb-chip button{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;color:var(--ink-soft);background:none;border:0;flex-shrink:0}.lb-chip button:hover{background:var(--red-soft);color:#b8362d}.lb-chip.ex{background:var(--red-soft);border-color:#f3cfcb;color:#8a2a22}'
    + '.lb-tree{max-height:440px;overflow-y:auto;padding:6px 6px 10px;overscroll-behavior:contain}.lb-tn{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer}.lb-tn:hover{background:var(--surface-2)}'
    + '.lb-tn .cv{width:22px;height:22px;display:grid;place-items:center;color:var(--ink-soft);flex-shrink:0;background:none;border:0;padding:0}.lb-tn .cv svg{width:12px;height:12px;transition:transform .15s}.lb-tn.open .cv svg{transform:rotate(90deg)}[dir=rtl] .lb-tn .cv svg{transform:scaleX(-1)}'
    + '.lb-tn input{width:16px;height:16px;accent-color:var(--accent);flex-shrink:0;pointer-events:none}.lb-tn .cn2{flex:1;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.lb-tn .cn2 small{display:inline-block;color:var(--ink-faint);font-size:11px;margin:0 8px}'
    + '.lb-tn .ct{font-size:11px;font-weight:700;color:var(--ink-soft);background:var(--surface-3);border-radius:99px;padding:1px 8px}.lb-tn.inc{color:var(--ink-soft)}.lb-tn.inc .cn2::after{content:" · included";font-size:11px;color:var(--accent-ink);font-weight:600}'
    + '.lb-tsw{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ink-2);font-weight:600;background:none;border:0;padding:0}'
    + '.lb-exw .lb-sbox{position:relative}.lb-exdd{position:absolute;left:14px;right:14px;top:58px;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--sh);z-index:5;max-height:300px;overflow-y:auto;display:none}.lb-exdd.on{display:block}.lb-exdd .lb-r{grid-template-columns:40px minmax(0,1fr)}'
    + '.lb-sum{margin-top:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 16px;border-radius:14px;background:linear-gradient(120deg,#0f8f4b,#15a85a);color:#fff;position:sticky;bottom:8px;z-index:4}.lb-sum .big{font-size:26px;font-weight:800;letter-spacing:-.02em;line-height:1}.lb-sum .lab{font-weight:600}.lb-sum .br{font-size:12px;opacity:.9;flex-basis:100%}'
    + '.lb-pvp{margin-top:10px}.lb-pvgrid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;padding:12px 14px}.lb-pvgrid div{font-size:11px;line-height:1.25;color:var(--ink-2);min-width:0;overflow-wrap:anywhere}.lb-pvgrid img,.lb-pvgrid .lb-th{width:100%;height:auto;aspect-ratio:1;border-radius:9px;margin-bottom:5px;object-fit:cover}'
    + '@media (max-width:1100px){.lb-row{grid-template-columns:110px minmax(0,1fr) minmax(0,1fr) 120px;row-gap:8px}.lb-hd{display:none}.lb-c-where,.lb-c-when,.lb-c-prio,.lb-c-on{grid-row:2}.lb-c-act{grid-column:1/-1;justify-content:flex-start}}'
    + '@media (max-width:900px){.lb-ed,.lb-pk{grid-template-columns:minmax(0,1fr)}.lb-prev{position:static}}'
    + '@media (max-width:640px){.lb-head{flex-direction:column}.lb-two,.lb-pv2{grid-template-columns:minmax(0,1fr)}.lb-pv2 .lb-scard{max-width:240px}.lb-shapes{grid-template-columns:repeat(2,minmax(0,1fr))}.lb-pvgrid{grid-template-columns:repeat(3,minmax(0,1fr))}'
    + '.lb-row{grid-template-columns:minmax(0,1fr) auto;gap:8px 10px}.lb-c-badge{grid-column:1/-1}.lb-c-name{grid-column:1}.lb-c-cnt{grid-column:2;text-align:end}.lb-c-where,.lb-c-when,.lb-c-prio,.lb-c-on{grid-row:auto}.lb-c-where{grid-column:1}.lb-c-when{grid-column:2}.lb-c-prio{grid-column:1}.lb-c-on{grid-column:2;justify-self:end}.lb-kb{display:none}.lb-r{grid-template-columns:20px 40px minmax(0,1fr)}.lb-r .pr{grid-column:3}.lb-sum{position:static}.lb-sum .big{font-size:22px}}'
    + '</style>';

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN, label: 'Labels', group: 'Catalog', after: ['bestsellers', 'newarrivals', 'concernsync', 'imageseo', 'product-editor', 'catalog'],
      icon: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>'
    });
  }
  if (window.kbbAddNavEntry) addNavEntry();

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { return previousGo.apply(this, arguments); }
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]'); if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog'; if (title) title.textContent = 'Labels';
    var side = document.querySelector('#side'); if (side) side.classList.remove('open');
    view = 'list'; cur = null; flash = ''; delArm = 0; paint(); loadList();
  };
})();
