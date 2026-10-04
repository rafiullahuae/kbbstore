{{--
    THE SIDEBAR SEARCH -- pinned to the top of the left menu.          Lane SR

    THE OWNER, verbatim: "Also I want one super top function to our left menu
    of the backend app. to find anything in the menu, mean anytime, if we start
    write, it should give results with proper links to that specific area /
    page, and highlight it for a second to tell the admin that yes this is the
    area you clicked from the search link" -- and "the code must not put load
    on admin side at all, must be secure, super light, optimized and bugs free."

    WHERE IT SITS. Included INSIDE <aside class="side">, between the brand and
    <nav id="nav">, so the box is drawn by the server where it belongs: no
    script moves it in, and nothing below it shifts when it appears. That spot
    is inside the console's first raw (verbatim) region, which is why the
    include is wrapped in a closing and a reopening verbatim directive in
    app.blade.php. (Those two directive names are not written out in this
    comment on purpose: Blade extracts verbatim blocks BEFORE it strips
    comments, so naming them here would open a raw block inside this file.)

    WHAT IT COSTS.
      * The server: one inline JSON block, App\Support\AdminSearchIndex::json(),
        cached in the file store and keyed on its source files. No endpoint,
        no query.
      * The browser, until the box is first focused: nothing but two listeners
        (the box's own focus, and one keydown on document for Ctrl/Cmd+K and
        "/"). The JSON is not even parsed.
      * On first focus: the JSON is parsed and the index built once, from it
        and from the sidebar rows already drawn (#nav). Every keystroke after
        that searches memory, debounced.
      * After a result is opened: one MutationObserver on #content, alive only
        until the target appears or a fixed deadline passes, then disconnected.
        No polling loop, nothing left running.

    THE SCREENS COME FROM THE SIDEBAR, NOT FROM A LIST OF OUR OWN. A screen is
    searchable only if #nav (or the pinned Console row) draws a row for it, so
    whatever the sidebar hides, search hides; nothing here re-decides access.
    Opening a result clicks THAT ROW, which is the sidebar's own route
    (kbbNavClick -> window.go), with its late-render replay and drawer close.

    SAFE BY CONSTRUCTION. Every label reaches the page through textContent or
    a created text node; nothing in this file assigns markup from data, and
    there is no eval and no network call. The JSON block holds code constants
    only and is printed with every < and > hex-escaped (see json()).

    NO LAYOUT MEASURING. The target is brought into view with scrollIntoView
    and found by its text; nothing reads a size or a position.
    AdminSidebarSearchTest pins all of the above.
--}}
<div class="ksr" id="ksr" role="search">
  <div class="ksr-field">
    <svg class="ksr-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input id="ksrIn" class="ksr-in" type="text" inputmode="search" enterkeyhint="go" placeholder="Search settings, pages…"
           autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="80"
           role="combobox" aria-expanded="false" aria-controls="ksrList" aria-autocomplete="list" aria-label="Search the admin">
    <kbd class="ksr-kbd" id="ksrKbd" aria-hidden="true">Ctrl K</kbd>
    <button class="ksr-x" id="ksrX" type="button" aria-label="Clear search" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
  </div>
  <div class="ksr-pop" id="ksrPop" hidden>
    <div class="ksr-list" id="ksrList" role="listbox" aria-label="Search results"></div>
    <div class="ksr-foot" aria-hidden="true"><span><kbd>↑</kbd><kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> clear</span></div>
  </div>
</div>
<script type="application/json" id="ksrIndex">{!! \App\Support\AdminSearchIndex::json() !!}</script>
@verbatim
<style>
/* ---------- Lane SR · sidebar search ---------- */
.ksr{position:relative;padding:0 12px 8px;z-index:6}
.ksr-field{position:relative;display:flex;align-items:center;gap:8px;height:38px;padding:0 8px 0 11px;
  background:var(--surface);border:1px solid var(--border);border-radius:11px;color:var(--ink-faint);
  box-shadow:0 1px 0 rgba(16,23,41,.03);transition:border-color .15s,box-shadow .15s}
.ksr-field:hover{border-color:#d2d8e6}
.ksr-field:focus-within{border-color:#E0567B;box-shadow:0 0 0 3px rgba(224,86,123,.16);color:#C13E63}
.ksr-ic{width:16px;height:16px;flex:0 0 16px}
.ksr-in{flex:1 1 auto;min-width:0;height:100%;border:0;outline:0;background:transparent;color:var(--ink);
  font:500 13px/1 var(--sans,inherit);padding:0}
.ksr-in::placeholder{color:var(--ink-faint);font-weight:400}
.ksr-kbd,.ksr-foot kbd{font:600 10px/1 var(--sans,inherit);color:var(--ink-soft);background:var(--surface-2);
  border:1px solid var(--border);border-radius:6px;padding:4px 5px;white-space:nowrap}
.ksr-field:focus-within .ksr-kbd,.ksr-in:not(:placeholder-shown) ~ .ksr-kbd{display:none}
.ksr-x{display:grid;place-items:center;width:24px;height:24px;border-radius:7px;color:var(--ink-soft);flex:0 0 24px}
.ksr-x:hover{background:var(--surface-2);color:var(--ink)}
.ksr-x[hidden]{display:none}
.ksr-x svg{width:14px;height:14px}
/* The results float over the content, wider than the 248px sidebar, so a
   breadcrumb reads on one line at 1280px. At phone width the drawer is 248px
   and the panel may run past it over the dimmed page, never past the screen. */
.ksr-pop{position:absolute;left:12px;top:44px;width:min(440px,calc(100vw - 24px));max-height:min(70vh,560px);
  display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:14px;
  box-shadow:0 24px 60px -18px rgba(16,23,41,.35),0 6px 18px -8px rgba(16,23,41,.18);overflow:hidden}
.ksr-pop[hidden]{display:none}
.ksr-list{overflow-y:auto;overscroll-behavior:contain;padding:6px;flex:1 1 auto;min-height:0}
.ksr-it{display:grid;grid-template-columns:auto minmax(0,1fr);column-gap:10px;align-items:start;padding:8px 10px;
  border-radius:10px;cursor:pointer;color:var(--ink)}
.ksr-it[aria-selected="true"]{background:#FFF0F4}
.ksr-it:hover{background:var(--surface-2)}
.ksr-it[aria-selected="true"]:hover{background:#FFE6EE}
.ksr-k{grid-row:1 / span 2;align-self:center;box-sizing:border-box;width:58px;font:700 9px/1 var(--sans,inherit);letter-spacing:.05em;
  text-transform:uppercase;padding:4px 0;border-radius:6px;background:var(--surface-3);color:var(--ink-soft);text-align:center}
.ksr-k.k0{background:#FFF0F4;color:#A82F53}
.ksr-k.k1{background:var(--accent-soft);color:var(--accent-ink)}
.ksr-t{font-size:13.5px;font-weight:600;line-height:1.3;overflow-wrap:anywhere}
.ksr-t mark{background:none;color:#C13E63;font-weight:700}
.ksr-p{font-size:11.5px;color:var(--ink-soft);line-height:1.35;margin-top:2px;overflow-wrap:anywhere}
.ksr-empty{padding:16px 14px;font-size:12.5px;color:var(--ink-soft);line-height:1.55}
.ksr-empty b{color:var(--ink)}
.ksr-foot{display:flex;gap:14px;flex-wrap:wrap;padding:8px 12px;border-top:1px solid var(--border-2);font-size:11px;color:var(--ink-faint)}
.ksr-foot span{display:inline-flex;align-items:center;gap:4px}
.ksr-foot kbd{padding:2px 5px;font-size:10px}
@media (max-width:880px){.ksr-kbd,.ksr-foot{display:none}}
@media (hover:none){.ksr-kbd{display:none}}

/* THE HIGHLIGHT: a soft brand-pink glow that rises and fades in ~1.2s (the
   owner's "for a second") on the row, card or tab the result named. Shadow
   only -- the element's own background, border, radius and size are
   untouched, so nothing reflows. */
.ksr-flash{animation:ksrGlow 1.2s cubic-bezier(.2,.7,.3,1) 1}
.ksr-at{scroll-margin:18px}
@keyframes ksrGlow{
  0%{box-shadow:0 0 0 0 rgba(224,86,123,0),inset 0 0 0 999px rgba(255,240,244,0)}
  15%{box-shadow:0 0 0 3px rgba(224,86,123,.6),0 0 26px 6px rgba(224,86,123,.34),inset 0 0 0 999px rgba(255,240,244,.7)}
  55%{box-shadow:0 0 0 3px rgba(224,86,123,.45),0 0 20px 5px rgba(224,86,123,.22),inset 0 0 0 999px rgba(255,240,244,.5)}
  100%{box-shadow:0 0 0 0 rgba(224,86,123,0),inset 0 0 0 999px rgba(255,240,244,0)}}
/* Reduced motion: no animation at all -- a still outline for the same moment. */
@media (prefers-reduced-motion:reduce){
  .ksr-flash{animation:none;outline:2px solid #E0567B;outline-offset:3px}
  .ksr-field{transition:none}
}
</style>
<script>
/* Lane SR · sidebar search. See the note at the top of this partial. */
(function () {
  'use strict';
  var box = document.getElementById('ksr');
  var input = document.getElementById('ksrIn');
  var pop = document.getElementById('ksrPop');
  var list = document.getElementById('ksrList');
  var clear = document.getElementById('ksrX');
  var kbd = document.getElementById('ksrKbd');
  if (!box || !input || !pop || !list) return;

  var MAX = 30;            // results drawn
  var WAIT_SCREEN = 8000;  // ms a screen may take to fetch and draw
  var WAIT_TARGET = 3000;  // ms a tab's content may take after the click
  var FLASH = 1300;        // ms the highlight class stays on (the glow is 1.2s)
  var KIND = ['Page', 'Tab', 'Setting'];

  /* Small, hand-picked: words an owner types for a label that says it
     differently ("font size" -> "Text size", "menu" -> "Navigation"). */
  var SYN = [
    ['font', 'text', 'type', 'typography'], ['colour', 'color', 'colours', 'colors'],
    ['spacing', 'space', 'gap', 'padding', 'margin'], ['menu', 'navigation', 'nav'],
    ['mobile', 'phone'], ['desktop', 'laptop'], ['picture', 'image', 'photo', 'photos', 'images'],
    ['email', 'emails', 'mail'], ['payment', 'payments', 'pay', 'gateway'], ['shipping', 'delivery'],
    ['coupon', 'coupons', 'discount', 'promo'], ['vat', 'tax'], ['translation', 'arabic', 'language'],
    ['cart', 'basket'], ['corner', 'radius', 'roundness'], ['logo', 'wordmark'], ['whatsapp', 'support'],
    ['smtp', 'server'], ['height', 'tall'], ['width', 'wide'], ['seo', 'google', 'meta'], ['banner', 'hero', 'slider']
  ];
  var SYNMAP = {};
  SYN.forEach(function (g) { g.forEach(function (w) { SYNMAP[w] = g; }); });

  var IDX = null;       // {items, dict}
  var builtFor = -1;    // sidebar row count the index was built against
  var results = [];
  var active = -1;
  var timer = 0;
  var navToken = 0;
  var stats = { ms: 0, items: 0, bytes: 0 };

  var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
  if (kbd) kbd.textContent = mac ? '\u2318K' : 'Ctrl K';

  /* ---- text ---- */
  function norm(s) {
    s = String(s || '');
    if (!/[^\x00-\x7f]/.test(s)) return s.toLowerCase();
    return s.normalize('NFKD')
      .replace(/[\u0300-\u036f\u064b-\u065f\u0670]/g, '')
      .toLowerCase()
      .replace(/[\u0622\u0623\u0625\u0671]/g, '\u0627')
      .replace(/\u0649/g, '\u064a').replace(/\u0629/g, '\u0647');
  }
  function tokens(s) { return norm(s).split(/[^\p{L}\p{N}]+/u).filter(Boolean); }
  function squash(s) { return String(s || '').replace(/\s+/g, ' ').trim(); }
  /* A tab's text with its count badge or status dot taken off: "Bar13",
     "Cleanser 0", "Concern pages 0/8", "Cash on delivery•" -> the label. */
  function tabText(s) { return norm(squash(s)).replace(/[\s\u2022\d\/]+$/, '').trim(); }

  /* ---- the index, built once on first use ---- */
  function navRows() { return document.querySelectorAll('#nav [data-go], .side-pin [data-go]'); }

  function build() {
    var rows = navRows();
    if (IDX && builtFor === rows.length) return;
    var t0 = performance.now();
    var data = {};
    var el = document.getElementById('ksrIndex');
    try { data = JSON.parse(el ? el.textContent : '{}') || {}; } catch (e) { data = {}; }
    stats.bytes = el ? el.textContent.length : 0;

    var items = [], dict = new Map(), seen = new Set(), ids = new Map();
    /* Word ids per distinct string: the group and screen names repeat on
       every row of their screen, so each is tokenised once. */
    function tok(s, w, into) {
      var list = ids.get(s);
      if (!list) {
        list = tokens(s).map(function (t) {
          var id = dict.get(t);
          if (id === undefined) { id = dict.size; dict.set(t, id); }
          return id;
        });
        ids.set(s, list);
      }
      for (var i = 0; i < list.length; i++) into.push(list[i], w);
    }
    function add(kind, screen, tab, label, path, row) {
      var t = [];
      tok(label, 3, t);
      for (var i = path.length - 1, w = 2; i >= 0; i--, w = 1) tok(path[i], w, t);
      items.push({ k: kind, s: screen, tab: tab, l: label, p: path, t: t, n: norm(squash(label)), row: row });
    }

    Array.prototype.forEach.call(rows, function (row) {
      var id = row.getAttribute('data-go');
      if (!id || seen.has(id)) return;
      seen.add(id);
      var span = row.querySelector('span');
      var name = squash(span ? span.textContent : row.textContent);
      var grp = row.closest('.nav-group');
      var group = grp ? (grp.getAttribute('data-sec') || '') : '';
      var base = group && group !== name ? [group] : [];
      add(0, id, '', name, base, row);
      var entry = Object.prototype.hasOwnProperty.call(data, id) ? data[id] : null;
      if (!Array.isArray(entry)) return;
      var tabs = Array.isArray(entry[0]) ? entry[0] : [];
      tabs.forEach(function (tab) { add(1, id, String(tab), String(tab), base.concat(name), row); });
      (Array.isArray(entry[1]) ? entry[1] : []).forEach(function (it) {
        if (!Array.isArray(it)) return;
        var tab = it[0] >= 0 && tabs[it[0]] != null ? String(tabs[it[0]]) : '';
        add(2, id, tab, String(it[1]), base.concat(tab ? [name, tab] : [name]), row);
      });
    });

    var words = new Array(dict.size);
    dict.forEach(function (id, t) { words[id] = t; });
    IDX = { items: items, words: words };
    memo = new Map();
    builtFor = rows.length;
    stats.ms = Math.round((performance.now() - t0) * 100) / 100;
    stats.items = items.length;
  }

  /* ---- matching ---- */
  /* Damerau-Levenshtein, capped: returns max+1 as soon as it is beyond max. */
  function near(a, b, max) {
    var la = a.length, lb = b.length;
    if (Math.abs(la - lb) > max) return max + 1;
    var prev2 = null, prev = [], cur, i, j;
    for (j = 0; j <= lb; j++) prev[j] = j;
    for (i = 1; i <= la; i++) {
      cur = [i];
      var best = i;
      for (j = 1; j <= lb; j++) {
        var c = a[i - 1] === b[j - 1] ? 0 : 1;
        var v = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + c);
        if (prev2 && i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) v = Math.min(v, prev2[j - 2] + 1);
        cur[j] = v;
        if (v < best) best = v;
      }
      if (best > max) return max + 1;
      prev2 = prev; prev = cur;
    }
    return prev[lb];
  }
  /* exact 1, prefix .9, inside .6; and, for the word as typed only (not its
     synonyms), one slip in four letters or two in seven -- checked only
     against words that share its first or second letter, which is where
     the time would otherwise go. */
  function wordScore(q, w, typos) {
    if (w === q) return 1;
    if (w.indexOf(q) === 0) return 0.9;
    if (q.length >= 3 && w.indexOf(q) > 0) return 0.6;
    if (typos && q.length >= 4 && (w.charCodeAt(0) === q.charCodeAt(0) || w.charCodeAt(1) === q.charCodeAt(1))) {
      var max = q.length >= 7 ? 2 : 1;
      if (near(q, w, max) <= max) return 0.55;
      if (w.length > q.length && near(q, w.slice(0, q.length), max) <= max) return 0.5;
    }
    return 0;
  }
  /* For one query word, the score of every index word it matches. Memoised
     per index: typing "card spacing" scores "card" once, not on every key. */
  var memo = new Map();
  function scoreWords(q) {
    var hit = memo.get(q);
    if (hit) return hit;
    var alts = [[q, 1]];
    if (q.length > 3 && q.charAt(q.length - 1) === 's') alts.push([q.slice(0, -1), 0.95]);
    (SYNMAP[q] || []).forEach(function (s) { if (s !== q) alts.push([s, 0.85]); });
    var out = new Map(), words = IDX.words;
    for (var i = 0; i < words.length; i++) {
      var best = 0;
      for (var a = 0; a < alts.length; a++) {
        var s = wordScore(alts[a][0], words[i], a === 0) * alts[a][1];
        if (s > best) best = s;
      }
      if (best > 0) out.set(i, best);
    }
    hit = { q: q, alts: alts.map(function (x) { return x[0]; }), m: out };
    if (memo.size > 200) memo.clear();
    memo.set(q, hit);
    return hit;
  }

  function search(text) {
    var qs = tokens(text).slice(0, 6);
    if (!qs.length) return { list: [], qs: [] };
    var per = qs.map(scoreWords);
    var whole = norm(squash(text));
    var out = [];
    IDX.items.forEach(function (it) {
      var total = 0;
      for (var p = 0; p < per.length; p++) {
        var m = per[p].m, best = 0;
        for (var k = 0; k < it.t.length; k += 2) {
          var s = m.get(it.t[k]);
          if (s && s * it.t[k + 1] > best) best = s * it.t[k + 1];
        }
        if (!best) return;
        total += best;
      }
      if (it.n === whole) total += 4;
      else if (it.n.indexOf(whole) === 0) total += 2;
      else if (whole.length > 2 && it.n.indexOf(whole) > 0) total += 1;
      total += it.k === 0 ? 1.5 : it.k === 1 ? 0.8 : 0;
      total -= it.l.length * 0.004;
      out.push([total, it]);
    });
    out.sort(function (a, b) { return b[0] - a[0]; });
    return { list: out.slice(0, MAX).map(function (x) { return x[1]; }), qs: per };
  }

  /* ---- drawing: text nodes and createElement only ---- */
  function marked(label, per) {
    var frag = document.createDocumentFragment();
    var re = /[\p{L}\p{N}]+/gu, last = 0, m;
    while ((m = re.exec(label))) {
      var w = m[0], n = norm(w), cut = 0;
      for (var i = 0; i < per.length && !cut; i++) {
        for (var a = 0; a < per[i].alts.length; a++) {
          var q = per[i].alts[a];
          if (q && n.indexOf(q) === 0) { cut = n.length === w.length ? q.length : w.length; break; }
        }
      }
      if (!cut) continue;
      if (m.index > last) frag.appendChild(document.createTextNode(label.slice(last, m.index)));
      var mk = document.createElement('mark');
      mk.textContent = w.slice(0, cut);
      frag.appendChild(mk);
      last = m.index + cut;
    }
    if (last < label.length) frag.appendChild(document.createTextNode(label.slice(last)));
    return frag;
  }

  function draw(text) {
    var t0 = performance.now();
    var r = search(text);
    stats.matchMs = Math.round((performance.now() - t0) * 100) / 100;
    results = r.list;
    active = results.length ? 0 : -1;
    list.textContent = '';
    if (!results.length) {
      var e = document.createElement('div');
      e.className = 'ksr-empty';
      e.setAttribute('role', 'status');
      var b = document.createElement('b');
      b.textContent = '\u201c' + squash(text) + '\u201d';
      e.appendChild(document.createTextNode('Nothing in the menu matches '));
      e.appendChild(b);
      e.appendChild(document.createTextNode('. Try a shorter word, or the name on the screen \u2014 \u201clogo\u201d, \u201cdelivery\u201d, \u201cSMTP\u201d.'));
      list.appendChild(e);
    }
    var frag = document.createDocumentFragment();
    results.forEach(function (it, i) {
      var o = document.createElement('div');
      o.className = 'ksr-it';
      o.id = 'ksr-o' + i;
      o.setAttribute('role', 'option');
      o.setAttribute('aria-selected', i === active ? 'true' : 'false');
      o.setAttribute('data-i', String(i));
      var k = document.createElement('span');
      k.className = 'ksr-k k' + it.k;
      k.textContent = KIND[it.k];
      var t = document.createElement('span');
      t.className = 'ksr-t';
      t.appendChild(marked(it.l, r.qs));
      var p = document.createElement('span');
      p.className = 'ksr-p';
      p.textContent = it.p.length ? it.p.join(' \u203a ') : 'Sidebar';
      o.appendChild(k); o.appendChild(t); o.appendChild(p);
      frag.appendChild(o);
    });
    list.appendChild(frag);
    list.scrollTop = 0;
    show(true);
    syncActive(false);
  }

  function show(on) {
    pop.hidden = !on;
    input.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (!on) input.removeAttribute('aria-activedescendant');
  }
  /* `reveal` only on arrow keys: after a fresh draw the first row is already
     at the top, and asking the browser to scroll to it would cost a layout
     pass on every keystroke for nothing. */
  function syncActive(reveal) {
    var opts = list.querySelectorAll('.ksr-it');
    Array.prototype.forEach.call(opts, function (o, i) { o.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
    if (active >= 0 && opts[active]) {
      input.setAttribute('aria-activedescendant', opts[active].id);
      if (reveal) opts[active].scrollIntoView({ block: 'nearest' });
    } else input.removeAttribute('aria-activedescendant');
  }

  function run() {
    timer = 0;
    var v = input.value;
    if (clear) clear.hidden = !v;
    if (!squash(v)) { results = []; list.textContent = ''; show(false); return; }
    build();
    var t0 = performance.now();
    draw(v);
    stats.searchMs = Math.round((performance.now() - t0) * 100) / 100;
  }
  function soon() { if (timer) clearTimeout(timer); timer = setTimeout(run, 60); }

  /* ---- going there ---- */

  function flash(el) {
    if (!el) return;
    /* Removed, then re-added a frame later, so opening the same result twice
       plays the glow twice -- without reading anything back from layout. */
    el.classList.remove('ksr-flash');
    requestAnimationFrame(function () {
      el.classList.add('ksr-flash');
      setTimeout(function () { el.classList.remove('ksr-flash'); }, FLASH);
    });
  }

  /* Resolve with what `find` returns once it returns something, or null at
     the deadline. One observer, always disconnected; a newer navigation
     (another result opened) abandons this one at its next check. */
  function waitFor(find, ms, token) {
    return new Promise(function (done) {
      var root = document.getElementById('content');
      var hit = token === navToken ? find() : null;
      if (hit || !root || token !== navToken) { done(hit || null); return; }
      var finished = false, queued = false, mo, timeout;
      function end(v) {
        if (finished) return;
        finished = true;
        if (mo) mo.disconnect();
        clearTimeout(timeout);
        done(v);
      }
      mo = new MutationObserver(function () {
        if (token !== navToken) { end(null); return; }
        if (queued) return;
        queued = true;
        Promise.resolve().then(function () { queued = false; if (!finished) { var v = find(); if (v) end(v); } });
      });
      mo.observe(root, { childList: true, subtree: true, characterData: true });
      timeout = setTimeout(function () { end(null); }, ms);
    });
  }

  function visible(el) { return typeof el.checkVisibility === 'function' ? el.checkVisibility() : true; }
  function pick(nodes, test) {
    var fallback = null;
    for (var i = 0; i < nodes.length; i++) {
      if (!test(nodes[i])) continue;
      if (visible(nodes[i])) return nodes[i];
      fallback = fallback || nodes[i];
    }
    return fallback;
  }
  function findTab(label) {
    var want = tabText(label);
    var c = document.getElementById('content');
    if (!c || !want) return null;
    /* A tab is a button whose class has a word ENDING in "tab" -- ectab,
       subtab, paytab, sap-tab, mgm-menutab -- or role="tab". Not merely
       containing it: "table" and "tabby" are not tabs. */
    return pick(c.querySelectorAll('button, [role="tab"], a'), function (b) {
      var cls = typeof b.className === 'string' ? b.className : '';
      return (/(^|\s)[\w-]*tab(\s|$)/i.test(cls) || b.getAttribute('role') === 'tab') && tabText(b.textContent) === want;
    });
  }
  function ownText(el) {
    if (el.tagName !== 'LABEL') return squash(el.textContent);
    var s = '';
    for (var n = el.firstChild; n; n = n.nextSibling) if (n.nodeType === 3) s += n.textContent + ' ';
    return squash(s);
  }
  function findLabel(label) {
    var c = document.getElementById('content');
    if (!c) return null;
    var want = squash(label), wantN = norm(want);
    var nodes = c.querySelectorAll('h2, h3, h4, b, strong, label, legend');
    return pick(nodes, function (el) { return !el.closest('button') && ownText(el) === want; })
        || pick(nodes, function (el) { return !el.closest('button') && norm(ownText(el)) === wantN; });
  }
  /* The thing to light up: the smallest box around the label that also holds
     its control (a settings row), or the card a heading heads. */
  function areaFor(el) {
    var c = document.getElementById('content');
    var n = el;
    for (var i = 0; i < 4 && n && n !== c; i++) {
      if (n.classList && n.classList.contains('card')) return n;
      if (n !== el && n.querySelector && n.querySelector('input, select, textarea, [role="switch"], [contenteditable="true"]')) {
        return n.classList && (n.classList.contains('wrap') || n.id === 'content') ? el : n;
      }
      n = n.parentElement;
    }
    var card = el.closest('.card');
    return card && c && c.contains(card) ? card : el;
  }
  /* A settings row is centred; a whole card is brought in by its TOP, since a
     tall card centred would hide its own heading -- the words the result
     named -- above the fold on a phone. `.ksr-at` carries the scroll margin.

     INSTANT, NOT SMOOTH, on purpose. On a phone the Product grid card is six
     thousand pixels down; a smooth scroll takes about a second, which is the
     whole glow, and knowing when it has landed means either reading positions
     (not here) or guessing from scroll events (measured: unreliable on a
     busy first render). The jump is immediate, and the glow is what tells the
     eye where it landed -- which also suits prefers-reduced-motion as is. */
  function bring(el) {
    el.classList.add('ksr-at');
    var block = el.classList.contains('card') ? 'start' : 'center';
    try { el.scrollIntoView({ block: block, inline: 'nearest', behavior: 'auto' }); }
    catch (e) { el.scrollIntoView(); }
    setTimeout(function () { el.classList.remove('ksr-at'); }, FLASH);
  }

  function go(it) {
    var token = ++navToken;
    show(false);
    input.blur();
    var row = null, rows = navRows();
    for (var i = 0; i < rows.length; i++) if (rows[i].getAttribute('data-go') === it.s) { row = rows[i]; break; }
    if (!row || !row.isConnected) return;       // the sidebar no longer offers it
    /* ALREADY ON THAT SCREEN: do not click its row. Every screen redraws from
       scratch on go(), so re-clicking would throw away whatever the admin has
       typed and not saved yet -- searching "logo" from the Header screen would
       wipe the Header screen. The drawer is closed by hand instead, which is
       the one other thing the row's click does. Both halves must agree (the
       row is lit AND the title is that screen's) so that a stale "on" can only
       ever cost a redraw, never strand the admin on the wrong screen. */
    if (row.classList.contains('on') && onScreen(it.s)) {
      var side = document.getElementById('side');
      if (side) side.classList.remove('open');
    } else {
      try { row.click(); } catch (e) { return; }
    }
    if (it.k === 0) {
      flash(row);
      flash(document.getElementById('ptitle'));
      return;
    }
    /* Each step falls back to the one before it: the control if it can be
       found, else the tab it sits under, else the page title -- so a result
       always ends on something lit, never on a silent screen. */
    var fallback = null;
    var chain = it.tab
      ? waitFor(function () { return findTab(it.tab); }, WAIT_SCREEN, token).then(function (tab) {
          if (!tab || token !== navToken) return null;
          if (!isOn(tab)) tab.click();
          fallback = findTab(it.tab) || tab;
          if (it.k === 1) return fallback;
          return waitFor(function () { return findLabel(it.l); }, WAIT_TARGET, token);
        })
      : waitFor(function () { return findLabel(it.l); }, WAIT_SCREEN, token);
    chain.then(function (el) {
      if (token !== navToken) return;
      stats.last = el ? 'target' : fallback ? 'tab' : 'page';
      if (!el) { flash(fallback && fallback.isConnected ? fallback : document.getElementById('ptitle')); return; }
      var area = it.k === 1 ? el : areaFor(el);
      bring(area);
      flash(area);
    }, function () {});
  }
  function onScreen(id) {
    var t = document.getElementById('ptitle');
    var T = typeof TITLES === 'object' && TITLES && Object.prototype.hasOwnProperty.call(TITLES, id) ? TITLES[id] : null;
    return !!(t && T && T[1] === t.textContent);
  }
  function isOn(tab) {
    var cls = typeof tab.className === 'string' ? tab.className : '';
    return /(^|[\s-])on(\s|$)|(^|\s)(active|is-on|is-active)(\s|$)/.test(cls) || tab.getAttribute('aria-selected') === 'true';
  }

  /* ---- events ---- */
  input.addEventListener('focus', function () {
    build();
    if (squash(input.value)) draw(input.value);
  });
  input.addEventListener('input', soon);
  input.addEventListener('keydown', function (e) {
    if (e.isComposing || e.keyCode === 229) return;   // an IME (Arabic, CJK) is still composing
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!results.length) return;
      e.preventDefault();
      if (pop.hidden) { show(true); }
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
      syncActive(true);
    } else if (e.key === 'Enter') {
      if (timer) { clearTimeout(timer); run(); }
      var it = results[active >= 0 ? active : 0];
      if (it) { e.preventDefault(); go(it); }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      if (input.value) { input.value = ''; run(); }
      else { show(false); input.blur(); }
    }
  });
  list.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep focus in the box
  list.addEventListener('click', function (e) {
    var o = e.target.closest ? e.target.closest('.ksr-it') : null;
    if (!o) return;
    var it = results[+o.getAttribute('data-i')];
    if (it) go(it);
  });
  if (clear) clear.addEventListener('click', function () { input.value = ''; run(); input.focus(); });
  box.addEventListener('focusout', function (e) {
    if (!e.relatedTarget || !box.contains(e.relatedTarget)) show(false);
  });

  function typing(t) {
    if (!t || t === document.body) return false;
    var tag = t.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || t.isContentEditable;
  }
  /* Ctrl/Cmd+K from anywhere; "/" when nothing is being typed into. On a
     phone the sidebar is a drawer, so it is opened first. */
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented) return;        // a screen's own shortcut got there first
    var k = (e.key || '').toLowerCase();
    var combo = k === 'k' && (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey;
    var slash = e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !typing(e.target);
    if (!combo && !slash) return;
    e.preventDefault();
    var side = document.getElementById('side');
    if (side && window.matchMedia && window.matchMedia('(max-width: 880px)').matches) side.classList.add('open');
    input.focus();
    input.select();
  });

  /* For the browser checks in tests/browser: the numbers, and a way to open
     every entry without typing it. Nothing here reaches the server. */
  window.kbbAdminSearch = {
    stats: stats,
    items: function () { build(); return IDX.items.map(function (it) { return { k: it.k, s: it.s, tab: it.tab, l: it.l, p: it.p.slice() }; }); },
    open: function (i) { build(); if (IDX.items[i]) go(IDX.items[i]); }
  };
})();
</script>
@endverbatim
