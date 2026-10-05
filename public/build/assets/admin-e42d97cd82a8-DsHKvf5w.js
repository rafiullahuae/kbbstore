
(function () {
  'use strict';

  /* ▲ NO ELEMENT MAY CARRY THE ID kbbDrafts. A browser makes every id a
     property of window, so the first version's wrapper div, given that id,
     WAS window.kbbDrafts: the check below took it for the registry, returned,
     and every screen's track() threw "is not a function" — measured in
     Chromium, twenty-eight errors on load and nothing tracked at all. */
  var HOME = document.getElementById('kbbUnfinished');
  if (!HOME || window.kbbDrafts) return;

  var BTN = document.getElementById('kbbDraftsBtn');
  var N = document.getElementById('kbbDraftsN');
  var PANEL = document.getElementById('kbbDraftsPanel');
  var LIST = document.getElementById('kbbDraftsList');
  var BAR = document.getElementById('kbbDraftBar');
  var BAR_HEAD = document.getElementById('kbbDraftBarHead');
  var BAR_TEXT = document.getElementById('kbbDraftBarText');
  var BAR_A = document.getElementById('kbbDraftBarA');
  var BAR_B = document.getElementById('kbbDraftBarB');

  var MAX_AGE = 14 * 24 * 3600 * 1000;
  var MAX_ENTRY = 256 * 1024;   // a product with a long description is the largest; past this it is not kept
  /* Never written to the browser, whatever screen offers it. */
  var SECRET = /(pass(word|wd)?|secret|token|api[_-]?key|private[_-]?key|(^|[._-])key$|salt|hash|cvv|otp)/i;

  var adapters = {};   // id -> adapter
  var base = {};       // id -> {key, values}: the server's values when last loaded or saved
  var live = {};       // id -> true while that buffer is the one on screen
  var offered = {};    // key -> true while a stale draft waits for his choice
  var barKey = null;   // the key the bar is talking about
  var timer = null;

  /* ------------------------------------------------------------ storage */
  function storeKey() {
    var path = String(window.location.pathname || '').replace(/\/+$/, '');
    return 'kbb.drafts.v1.' + (HOME.getAttribute('data-u') || '0') + '.' + path;
  }

  function read() {
    var raw = null, all = null;
    try { raw = window.localStorage.getItem(storeKey()); } catch (e) { return {}; }
    try { all = raw ? JSON.parse(raw) : {}; } catch (e) { all = {}; }
    if (!all || typeof all !== 'object' || Array.isArray(all)) all = {};

    var now = Date.now(), out = {};
    Object.keys(all).forEach(function (k) {
      var d = all[k];
      if (!d || typeof d !== 'object' || !d.to || typeof d.to !== 'object' || !d.from) return;
      if (!(now - Number(d.at || 0) < MAX_AGE)) return;   // older than 14 days: dropped
      out[k] = d;
    });
    return out;
  }

  /* Blocked or full: the screen still works, it just cannot keep this. */
  function write(all) {
    var body = Object.keys(all).length ? JSON.stringify(all) : null;
    if (body) { try { window.localStorage.setItem(storeKey(), body); } catch (e) {} return; }
    try { window.localStorage.removeItem(storeKey()); } catch (e) {}
  }

  /* ------------------------------------------------------------ helpers */
  function norm(v) {
    if (v === true) return '1';
    if (v === false) return '0';
    if (v == null) return '';
    if (typeof v === 'object') { try { return JSON.stringify(v); } catch (e) { return ''; } }
    return String(v);
  }

  function copy(map) {
    var out = {};
    Object.keys(map || {}).forEach(function (k) {
      if (SECRET.test(k)) return;
      var v = map[k];
      out[k] = (v && typeof v === 'object') ? JSON.parse(JSON.stringify(v)) : v;
    });
    return out;
  }

  function call(fn, fallback) {
    try { return fn(); } catch (e) { return fallback; }
  }

  function keyOf(a) {
    if (!a.entity) return a.id;
    var e = call(a.entity, null);
    return (e === null || e === undefined || e === '') ? null : a.id + ':' + String(e);
  }

  function labelOf(a) {
    var l = typeof a.label === 'function' ? call(a.label, '') : a.label;
    return String(l || a.screen || a.id);
  }

  function activeScreen() {
    var on = document.querySelector('.side .nav-item.on');
    if (on && on.getAttribute('data-go')) return on.getAttribute('data-go');
    try { return typeof cur === 'string' ? cur : ''; } catch (e) { return ''; }
  }

  function present(a) {
    if (a.present) return !!call(a.present, false);
    return activeScreen() === a.screen;
  }

  function ago(at) {
    var m = Math.max(0, Math.round((Date.now() - Number(at || 0)) / 60000));
    if (m < 1) return 'just now';
    if (m < 60) return m + ' min ago';
    var h = Math.round(m / 60);
    if (h < 24) return h + ' h ago';
    var d = Math.round(h / 24);
    return d + (d === 1 ? ' day ago' : ' days ago');
  }

  function plural(n, one) { return n + ' ' + one + (n === 1 ? '' : 's'); }

  /* What differs between the buffer and the values it was loaded with. */
  function diff(a) {
    var b = base[a.id];
    var k = keyOf(a);
    if (!b || !k || b.key !== k) return null;
    var cur = call(a.values, null);
    if (!cur) return null;

    var to = {}, from = {}, n = 0;
    Object.keys(cur).forEach(function (f) {
      if (SECRET.test(f)) return;
      if (norm(cur[f]) === norm(b.values[f])) return;
      to[f] = cur[f];
      from[f] = b.values[f] === undefined ? null : b.values[f];
      n++;
    });
    return {key: k, to: copy(to), from: copy(from), n: n};
  }

  /* --------------------------------------------------------- the record */
  function flush(id) {
    var a = adapters[id];
    if (!a || !live[id]) return;
    if (!present(a)) { live[id] = false; return; }

    var d = diff(a);
    if (!d) return;

    var all = read();
    if (d.n > 0) {
      var was = all[d.key] || {};
      var entry = {
        key: d.key, id: a.id, entity: a.entity ? String(call(a.entity, '')) : '',
        label: labelOf(a), screen: a.screen, sub: a.sub || null,
        count: a.count ? (Number(call(a.count, d.n)) || d.n) : d.n,
        at: Date.now(), since: was.since || Date.now(),
        to: d.to, from: d.from
      };
      var size = 0;
      try { size = JSON.stringify(entry).length; } catch (e) { size = MAX_ENTRY + 1; }
      if (size > MAX_ENTRY) return;
      all[d.key] = entry;
      /* He edited the saved version instead of choosing: what he typed now is
         the draft, and the question about the older one is moot. */
      if (offered[d.key] && barKey === d.key) hideBar();
      offered[d.key] = false;
    } else {
      if (offered[d.key]) return;   // a stale draft he has not decided about yet
      if (!all[d.key]) return;
      delete all[d.key];
      if (barKey === d.key) hideBar();
    }

    write(all);
    paint();
  }

  function flushAll() {
    if (timer) { clearTimeout(timer); timer = null; }
    Object.keys(live).forEach(function (id) { if (live[id]) flush(id); });
  }

  function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(flushAll, 250);
  }

  /* Put a stored draft's values into the screen's buffer and repaint it. */
  function apply(a, map) {
    var cur = call(a.values, null) || {};
    Object.keys(map || {}).forEach(function (k) {
      if (!Object.prototype.hasOwnProperty.call(cur, k)) return;   // a field that no longer exists
      call(function () { a.set(k, map[k]); });
    });
    call(function () { a.render(); });
    schedule();
  }

  /* ------------------------------------------------------- screen calls */
  function track(a) {
    if (!a || !a.id || typeof a.values !== 'function' || typeof a.set !== 'function' || typeof a.render !== 'function') return;
    adapters[a.id] = a;
  }

  function ready(id) {
    var a = adapters[id];
    if (!a) return;
    var k = keyOf(a);
    if (!k) return;
    var cur = call(a.values, null);
    if (!cur) return;

    base[id] = {key: k, values: copy(cur)};
    live[id] = true;

    var entry = read()[k];
    if (!entry) { if (barKey === k) hideBar(); return; }

    var stale = Object.keys(entry.from).some(function (f) {
      return Object.prototype.hasOwnProperty.call(base[id].values, f) && norm(entry.from[f]) !== norm(base[id].values[f]);
    });

    if (stale) {
      offered[k] = true;
      showBar(k, 'stale', entry);
    } else {
      apply(a, entry.to);
      showBar(k, 'back', entry);
    }
  }

  /* The screen came back on show WITHOUT reloading (the product editor keeps
     its product in memory across a visit elsewhere). Watching resumes against
     the values it was loaded with, if it is still the same row — never
     against what is on screen now, which may already hold his typing. */
  function resume(id) {
    var a = adapters[id];
    if (!a || !base[id]) return;
    if (base[id].key !== keyOf(a)) return;
    live[id] = true;
    schedule();
  }

  function forget(k) {
    var all = read();
    offered[k] = false;
    if (all[k]) { delete all[k]; write(all); }
    if (barKey === k) hideBar();
    paint();
  }

  function saved(id) {
    var a = adapters[id];
    if (!a) return;
    var k = keyOf(a);
    var cur = call(a.values, null);
    if (k && cur) base[id] = {key: k, values: copy(cur)};
    if (k) forget(k);
  }

  function discarded(id) {
    var a = adapters[id];
    if (!a) return;
    var k = keyOf(a);
    base[id] = null;
    live[id] = false;
    if (k) forget(k);
  }

  /* A row that no longer exists on the server (a deleted banner set). */
  function drop(id, entity) {
    forget(id + (entity === undefined || entity === null ? '' : ':' + String(entity)));
  }

  /* ------------------------------------------------------- owner actions */
  function open(k) {
    /* Whatever is on screen now is kept first: an adapter's open() may switch
       its row before go() runs, and after that the old row cannot be read. */
    flushAll();
    var entry = read()[k];
    closePanel(false);
    if (!entry) return;
    var a = adapters[entry.id];

    if (a && a.open) { call(function () { a.open(entry.entity, entry); }); return; }
    if (typeof window.go === 'function') window.go(entry.screen, entry.sub || undefined);
  }

  function discard(k) {
    var entry = read()[k];
    forget(k);
    if (!entry) return;

    var a = adapters[entry.id];
    var b = a && base[a.id];
    if (!a || !b || !live[a.id] || b.key !== k || keyOf(a) !== k) return;

    /* Discarded while it is on screen: the buffer goes back to what is saved. */
    var back = {};
    Object.keys(entry.to).forEach(function (f) {
      if (Object.prototype.hasOwnProperty.call(b.values, f)) back[f] = b.values[f];
    });
    apply(a, back);
    if (a.clean) call(function () { a.clean(); });
  }

  /* --------------------------------------------------------------- bar */
  function hideBar() {
    barKey = null;
    BAR.hidden = true;
  }

  function showBar(k, mode, entry) {
    barKey = k;
    BAR.setAttribute('data-mode', mode);
    var a = adapters[entry.id];
    var n = Number(entry.count) || Object.keys(entry.to).length;

    if (mode === 'stale') {
      BAR_HEAD.textContent = 'You left ' + plural(n, 'unfinished change') + ' here ' + ago(entry.at) + '.';
      BAR_TEXT.textContent = 'This page has been saved again since then — in another tab, or by someone else — '
        + 'so your changes are not on screen. Use them to put your version over what is saved now, or discard them.';
      BAR_A.textContent = 'Use my changes';
      BAR_A.hidden = false;
    } else {
      BAR_HEAD.textContent = 'Your unfinished changes are back — Save or Discard.';
      BAR_TEXT.textContent = plural(n, 'change') + ' to ' + entry.label + ', from ' + ago(entry.at) + '. Nothing has been saved yet.';
      BAR_A.textContent = 'Save';
      BAR_A.hidden = !(a && a.save);
    }

    BAR_B.setAttribute('data-kbb-sure', 'Throw away ' + plural(n, 'unfinished change') + ' to “' + entry.label
      + '”? They are not kept anywhere else, so this cannot be undone.');
    BAR.hidden = false;
  }

  BAR_A.addEventListener('click', function () {
    var k = barKey;
    var entry = k && read()[k];
    if (!entry) { hideBar(); return; }
    var a = adapters[entry.id];
    if (!a) return;

    if (BAR.getAttribute('data-mode') === 'stale') {
      offered[k] = false;
      apply(a, entry.to);
      showBar(k, 'back', entry);
      return;
    }
    if (a.save) call(function () { a.save(); });
  });

  BAR_B.addEventListener('click', function () {
    if (barKey) discard(barKey);
    hideBar();
  });

  /* ------------------------------------------------------- top-bar list */
  function paint() {
    var all = read();
    var keys = Object.keys(all).sort(function (x, y) { return Number(all[y].at) - Number(all[x].at); });

    N.textContent = 'Unfinished (' + keys.length + ')';
    HOME.hidden = keys.length === 0;
    if (!keys.length) { closePanel(false); }

    while (LIST.firstChild) LIST.removeChild(LIST.firstChild);
    keys.forEach(function (k) {
      var d = all[k];
      var n = Number(d.count) || Object.keys(d.to).length;

      var li = document.createElement('li');
      li.className = 'kbbdr-row';

      var what = document.createElement('div');
      what.className = 'kbbdr-what';
      var b = document.createElement('b');
      b.textContent = String(d.label || d.screen || '');
      var s = document.createElement('span');
      s.textContent = plural(n, 'change') + ' · ' + ago(d.at);
      what.appendChild(b);
      what.appendChild(s);

      var go = document.createElement('button');
      go.type = 'button';
      go.className = 'kbbdr-open';
      go.textContent = 'Open';
      go.setAttribute('data-kbbdr-open', k);
      go.setAttribute('aria-label', 'Open ' + String(d.label || '') + ' and put the changes back');

      var x = document.createElement('button');
      x.type = 'button';
      x.textContent = 'Discard';
      x.setAttribute('data-kbbdr-discard', k);
      x.setAttribute('aria-label', 'Discard the unfinished changes to ' + String(d.label || ''));
      x.setAttribute('data-kbb-sure', 'Throw away ' + plural(n, 'unfinished change') + ' to “' + String(d.label || '')
        + '”? They are not kept anywhere else, so this cannot be undone.');

      li.appendChild(what);
      li.appendChild(go);
      li.appendChild(x);
      LIST.appendChild(li);
    });
  }

  function openPanel() {
    paint();
    if (HOME.hidden) return;
    PANEL.hidden = false;
    BTN.setAttribute('aria-expanded', 'true');
    var top = HOME.closest('.top');
    if (top) top.classList.add('kbbdr-open');
    var first = LIST.querySelector('button');
    if (first) first.focus();
  }

  function closePanel(focusButton) {
    if (PANEL.hidden) return;
    PANEL.hidden = true;
    BTN.setAttribute('aria-expanded', 'false');
    var top = HOME.closest('.top');
    if (top) top.classList.remove('kbbdr-open');
    if (focusButton && !HOME.hidden) BTN.focus();
  }

  BTN.addEventListener('click', function () {
    if (PANEL.hidden) openPanel(); else closePanel(true);
  });

  LIST.addEventListener('click', function (e) {
    var o = e.target.closest && e.target.closest('[data-kbbdr-open]');
    if (o) { open(o.getAttribute('data-kbbdr-open')); return; }

    var x = e.target.closest && e.target.closest('[data-kbbdr-discard]');
    if (x) {
      discard(x.getAttribute('data-kbbdr-discard'));
      paint();
      if (!HOME.hidden && !PANEL.hidden) { var f = LIST.querySelector('button'); if (f) f.focus(); }
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || PANEL.hidden || e.defaultPrevented) return;
    e.preventDefault();
    closePanel(true);
  });

  document.addEventListener('click', function (e) {
    if (PANEL.hidden) return;
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('#kbbUnfinished') || t.closest('#kbbSureBg')) return;
    closePanel(false);
  });

  /* ---------------------------------------------------- change watching */
  /* Every screen's controls fire one of these, and its own handler has run by
     the time this one does (they were registered first). One debounced flush
     covers a slider drag without a write per pixel. */
  ['input', 'change', 'click', 'keydown', 'drop'].forEach(function (type) {
    document.addEventListener(type, schedule);
  });

  /* The tab closing or the page refreshing: written at once, and NO prompt —
     the draft is already kept, so there is nothing to warn about. */
  window.addEventListener('pagehide', flushAll);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flushAll();
  });

  /* Another tab of the console saved or discarded something. */
  window.addEventListener('storage', function (e) {
    if (e.key === storeKey()) paint();
  });

  /* LEAVING A SCREEN keeps what was typed, and asks nothing. Installed at
     DOMContentLoaded so it wraps the FINAL window.go — every partial below
     this one wraps go as well, and a wrapper that handles its own screen does
     not call the one it replaced, so only the outermost sees every move. */
  function installGo() {
    var previous = window.go;
    if (typeof previous !== 'function' || previous.kbbDrafts) return;
    var wrapped = function () {
      flushAll();
      Object.keys(live).forEach(function (id) { live[id] = false; });
      hideBar();
      closePanel(false);
      return previous.apply(this, arguments);
    };
    wrapped.kbbDrafts = true;
    window.go = wrapped;
  }

  /* ------------------------------------------------------------- mount */
  (function mount() {
    var top = document.querySelector('.main .top');
    var purge = document.getElementById('purgeBtn');
    if (top) {
      if (purge && purge.parentNode === top) top.insertBefore(HOME, purge);
      else top.appendChild(HOME);
    }
    var content = document.getElementById('content');
    if (content && content.parentNode) content.parentNode.insertBefore(BAR, content);
  })();

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installGo);
  else installGo();

  window.kbbDrafts = {
    track: track, ready: ready, resume: resume, saved: saved, discarded: discarded, drop: drop,
    flush: function (id) { if (id) flush(id); else flushAll(); },
    open: open, discard: discard, list: function () { return read(); }
  };

  paint();
})();

/* ===========================================================================
   THE SCREENS app.blade.php DRAWS ITSELF.

   Their buffers are top-level `let`s of the console's main script (HD, PS, AP,
   SS, MM, NL, MH, DV, PP and the reorder state), which every later classic
   script can read by name, so their adapters live here rather than as thirty
   scattered edits in a file every lane touches. app.blade.php carries only the
   two calls nothing else can make: ready('<id>') after a screen's first paint
   from the server, and saved('<id>') on its save's success line.
   UnfinishedDraftsTest pins both halves for every id below, and that each
   function an adapter calls still exists in app.blade.php under that name.
   =========================================================================== */
(function () {
  'use strict';
  var D = window.kbbDrafts;
  if (!D) return;

  function q(s) { return document.querySelector(s); }
  function onNav(id) { var on = q('.side .nav-item.on'); return !!on && on.getAttribute('data-go') === id; }

  function fieldsOf(b) {
    var out = [];
    if (!b) return out;
    (b.tabs || []).forEach(function (t) { (t.fields || []).forEach(function (f) { out.push(f); }); });
    (b.fields || []).forEach(function (f) { out.push(f); });
    return out;
  }

  function marker(sel, on) {
    var d = q(sel);
    if (!d) return;
    d.classList.remove('ok');
    d.textContent = 'Unsaved changes';
    d.style.visibility = on ? 'visible' : 'hidden';
  }

  /* One adapter for every { tabs:[{fields:[{key,value}]}] } settings screen. */
  function schema(o) {
    D.track({
      id: o.id, screen: o.id, label: o.label,
      values: function () {
        var b = o.buf();
        if (!b) return null;
        var out = {};
        fieldsOf(b).forEach(function (f) { if (f && f.key) out[f.key] = f.value; });
        if (o.more) o.more.values(b, out);
        return out;
      },
      set: function (k, v) {
        var b = o.buf();
        if (!b) return;
        if (o.more && o.more.set(b, k, v)) return;
        fieldsOf(b).forEach(function (f) { if (f.key === k) f.value = v; });
      },
      render: function () { o.paint(); marker(o.dirty, true); },
      clean: function () { marker(o.dirty, false); },
      save: function () { var s = q(o.save); if (s) s.click(); }
    });
  }

  schema({ id: 'header', label: 'Appearance → Header', dirty: '#hdDirty', save: '#hdSave',
    buf: function () { return typeof HD === 'undefined' ? null : HD; },
    paint: function () { paintHeader(); } });

  schema({ id: 'prodstyles', label: 'Appearance → Product styles', dirty: '#psDirty', save: '#psSave',
    buf: function () { return typeof PS === 'undefined' ? null : PS; },
    paint: function () { paintProdStyles(); } });

  schema({ id: 'acctpanel', label: 'Appearance → Login / Register panel', dirty: '#apDirty', save: '#apSave',
    buf: function () { return typeof AP === 'undefined' ? null : AP; },
    paint: function () { apPaint(); } });

  schema({ id: 'mobilemenu', label: 'Appearance → Mobile menu', dirty: '#mmDirty', save: '#mmSave',
    buf: function () { return typeof MM === 'undefined' ? null : MM; },
    paint: function () { paintMobileMenu(); } });

  schema({ id: 'newsletter', label: 'Growth & Marketing → Newsletter', dirty: '#nlDirty', save: '#nlSave',
    buf: function () { return typeof NL === 'undefined' ? null : NL; },
    paint: function () { paintNewsletter(); } });

  schema({ id: 'mobilehdr', label: 'Appearance → Mobile Header', dirty: '#mhDirty', save: '#mhSave',
    buf: function () { return typeof MH === 'undefined' ? null : MH; },
    paint: function () { paintMobileHdr(); } });

  schema({ id: 'dividers', label: 'Appearance → Section dividers', dirty: '#dvDirty', save: '#dvSave',
    buf: function () { return typeof DV === 'undefined' ? null : DV; },
    paint: function () { paintDividers(); } });

  /* Site search also carries "Set shown first, by brand", one choice per brand. */
  schema({ id: 'search', label: 'Store → Site Search', dirty: '#ssDirty', save: '#ssSave',
    buf: function () { return typeof SS === 'undefined' ? null : SS; },
    paint: function () { paintSiteSearch(); },
    more: {
      values: function (b, out) {
        (b.sets_by_brand || []).forEach(function (r) { out['set_for_brand.' + r.brand_id] = +r.chosen || 0; });
      },
      set: function (b, k, v) {
        if (k.indexOf('set_for_brand.') !== 0) return false;
        var id = k.slice('set_for_brand.'.length);
        (b.sets_by_brand || []).forEach(function (r) { if (String(r.brand_id) === id) r.chosen = +v || 0; });
        return true;
      }
    } });

  /* Appearance → Product page: the module switches AND the layout tabs, which
     save as two halves — so a restored value marks the half it belongs to. */
  D.track({
    id: 'productpage', screen: 'productpage', label: 'Appearance → Product page',
    values: function () {
      if (typeof PP === 'undefined' || !PP) return null;
      var out = {};
      ppFields().forEach(function (f) { out[f.key] = f.value; });
      // The "You may also like" tab, its own half (Lane PS).
      if (typeof pyaFields === 'function') pyaFields().forEach(function (f) { out['ymal.' + f.key] = f.value; });
      (PP.sections || []).forEach(function (s) {
        out['section.' + s.key + '.desktop'] = !!s.desktop;
        out['section.' + s.key + '.mobile'] = !!s.mobile;
      });
      return out;
    },
    set: function (k, v) {
      var m = /^section\.(.+)\.(desktop|mobile)$/.exec(k);
      if (m) {
        (PP.sections || []).forEach(function (s) { if (s.key === m[1]) s[m[2]] = (v === true || v === 1 || v === '1'); });
        PPDIRTY.sections = true;
        return;
      }
      if (k.indexOf('ymal.') === 0) {
        if (typeof pyaFields === 'function') pyaFields().forEach(function (f) { if ('ymal.' + f.key === k) f.value = v; });
        PPDIRTY.also = true;
        return;
      }
      ppFields().forEach(function (f) { if (f.key === k) f.value = v; });
      PPDIRTY.layout = true;
    },
    render: function () { paintProductPage(); },
    clean: function () { PPDIRTY = {sections: false, layout: false, also: false}; marker('#ppDirty', false); },
    save: function () { var s = q('#ppSave'); if (s) s.click(); }
  });

  /* Appearance → Homepage: the order of the sections and, per section, its two
     device switches and the three pickers beside them. */
  var HP_PROPS = ['desktop', 'mobile', 'skin', 'background', 'width'];
  function hpBase() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/homepage'; }

  D.track({
    id: 'homepage', screen: 'homepage', label: 'Appearance → Homepage',
    values: function () {
      if (typeof HP === 'undefined' || !HP || !Array.isArray(HP.sections)) return null;
      var out = {order: HP.sections.map(function (s) { return s.key; }).join(',')};
      HP.sections.forEach(function (s) {
        HP_PROPS.forEach(function (p) { out['section.' + s.key + '.' + p] = s[p] === undefined ? null : s[p]; });
      });
      return out;
    },
    set: function (k, v) {
      if (k === 'order') {
        var byKey = {};
        HP.sections.forEach(function (s) { byKey[s.key] = s; });
        var out = [];
        String(v || '').split(',').forEach(function (key) { if (byKey[key]) { out.push(byKey[key]); delete byKey[key]; } });
        HP.sections.forEach(function (s) { if (byKey[s.key]) out.push(s); });
        HP.sections = out;
        return;
      }
      var m = /^section\.(.+)\.(desktop|mobile|skin|background|width)$/.exec(k);
      if (!m) return;
      HP.sections.forEach(function (s) {
        if (s.key !== m[1]) return;
        s[m[2]] = (m[2] === 'desktop' || m[2] === 'mobile') ? (v === true || v === 1 || v === '1') : v;
      });
    },
    render: function () { paintHomepage(hpBase()); marker('#hpDirty', true); },
    clean: function () { marker('#hpDirty', false); },
    save: function () { var s = q('#hpSave'); if (s) s.click(); }
  });

  /* Catalog → Reorder: one page of one category or brand, as an order of ids. */
  function reorderScopeLabel() {
    var name = '';
    (reorderScopes || []).forEach(function (c) {
      if (+c.id === +reorderScopeId) name = c.name;
      (c.children || []).forEach(function (k) { if (+k.id === +reorderScopeId) name = k.name; });
    });
    return name;
  }
  function reorderSavedIds() { return (reorderData && reorderData.products || []).map(function (p) { return p.id; }); }

  D.track({
    id: 'reorder', screen: 'catalog', sub: 'reorder',
    label: function () {
      var pages = reorderData && reorderData.last_page > 1 ? ' · page ' + reorderPage : '';
      return 'Catalog → Reorder · ' + (reorderScopeLabel() || (reorderType === 'brand' ? 'brand' : 'category')) + pages;
    },
    entity: function () {
      if (!reorderData || reorderScopeId == null) return null;
      return [reorderType, reorderScopeId, reorderPage, reorderPerPage, encodeURIComponent(reorderSearch || '')].join('|');
    },
    present: function () { return onNav('catalog') && typeof catTab !== 'undefined' && catTab === 'reorder'; },
    values: function () {
      if (!reorderLocal || !reorderData) return null;
      return {order: reorderLocal.map(function (p) { return p.id; }).join(',')};
    },
    count: function () {
      var was = reorderSavedIds();
      return reorderLocal.filter(function (p, i) { return was[i] !== p.id; }).length;
    },
    set: function (k, v) {
      if (k !== 'order' || !reorderLocal) return;
      var byId = {};
      reorderLocal.forEach(function (p) { byId[p.id] = p; });
      var out = [];
      String(v).split(',').forEach(function (id) { if (byId[id]) { out.push(byId[id]); delete byId[id]; } });
      reorderLocal.forEach(function (p) { if (byId[p.id]) out.push(p); });
      reorderLocal = out;
      reorderDirty = out.map(function (p) { return p.id; }).join(',') !== reorderSavedIds().join(',');
    },
    render: function () { reorderPaint(); },
    save: function () { reorderSave(); },
    open: function (entity) {
      var p = String(entity || '').split('|');
      reorderType = p[0] === 'brand' ? 'brand' : 'category';
      reorderScopes = null;
      reorderScopeId = +p[1] || null;
      reorderPage = +p[2] || 1;
      reorderPerPage = +p[3] || reorderPerPage;
      reorderSearch = decodeURIComponent(p[4] || '');
      window.go('catalog', 'reorder');
      /* go()'s sub-tab does not survive the console's route interceptor (its
         fallthrough calls `_go(id)` and drops the second argument), so the
         Reorder tab is chosen here exactly as a click on it would. */
      if (typeof catTab !== 'undefined' && catTab !== 'reorder') {
        var tab = q('#content .subtab[data-t="reorder"]');
        if (tab) tab.click(); else { catTab = 'reorder'; renderCatalog(); }
      }
    }
  });
})();
