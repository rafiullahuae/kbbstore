
(function () {
  'use strict';

  var SCREEN = 'slimfooter';

  /* The frame each page is drawn in: the shop's own width, and a viewport tall
     enough for the whole footer on a desktop; a phone's own height, scrolled
     inside, on a phone. */
  var FRAME = {
    'site-d': { w: 1280, h: 620 }, 'site-m': { w: 390, h: 760 },
    'bar-d': { w: 1280, h: 380 }, 'bar-m': { w: 390, h: 520 }
  };
  var ICON = {
    d: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg>',
    m: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2"/></svg>'
  };

  var tabs = null, fields = {}, pages = [], shared = {}, saved = {}, values = {};
  var open = null, banner = null, busy = false, seq = 0, state = '', stateKind = '';
  var squeezeKeys = [];  // which controls "Squeeze this page" drives to their minimum
  var draftView = null;  // what kbbDrafts reads as "saved" for one call after a page save
  var pvTimer = null, pvSeq = 0, pvCtl = null, pvNote = '';

  /* UNFINISHED CHANGES (Lane PM). Leaving this screen with edits in `values`
     keeps them in Unfinished in the top bar; they come back on the next visit. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Appearance → Footer',
    values: function () { return tabs ? (draftView || values) : null; },
    set: function (k, v) { if (Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); preview(0); },
    save: function () { save(); }
  });

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body, signal) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
    if (signal) opts.signal = signal;

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status; err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg, kind) { try { window.toast(msg, kind); } catch (e) {} }

  function explain(e, fallback) {
    if (e && e.status === 404) return 'The Footer endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change or preview the footer.';
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  function norm(v) { return v === true ? '1' : (v === false || v == null ? '0' : String(v)); }
  function isDirty(k) { return norm(values[k]) !== norm(saved[k]); }
  function page() { return pages.filter(function (p) { return p.key === open; })[0] || pages[0]; }
  function pageKeys(p) {
    var out = [];
    (p ? p.sections : []).forEach(function (s) { s.keys.forEach(function (k) { if (fields[k]) out.push(k); }); });
    return out;
  }
  function dirtyOn(p) { return pageKeys(p).filter(isDirty); }
  function anyDirty() { return Object.keys(values).some(isDirty); }

  function remember(key) { try { window.localStorage.setItem('kbb.footer.page', key); } catch (e) {} }
  function recalled() { try { return window.localStorage.getItem('kbb.footer.page') || ''; } catch (e) { return ''; } }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Footer',
      icon: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 15h18"/><path d="M7 18h5"/>',
      group: 'Appearance',
      after: ['checkoutpage', 'cartpage', 'dividers']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Footer';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  function onScreen() { return (document.querySelector('#ptitle') || {}).textContent === 'Footer'; }

  /* NO beforeunload PROMPT — the owner asked for that popup to go console-wide
     (partials/unfinished-drafts). An unsaved change here is kept in
     Unfinished in the top bar instead, and the page tab carries a dot. */

  function absorb(body, keepDirty) {
    tabs = body.tabs || [];
    squeezeKeys = body.squeeze || [];
    pages = body.pages || [];
    shared = {};
    (body.shared || []).forEach(function (k) { shared[k] = true; });
    var was = values, wasSaved = saved;
    fields = {}; values = {}; saved = {};
    tabs.forEach(function (t) {
      t.fields.forEach(function (f) {
        fields[f.key] = f;
        var keep = keepDirty && Object.prototype.hasOwnProperty.call(was, f.key) && norm(was[f.key]) !== norm(wasSaved[f.key]);
        saved[f.key] = f.value;
        values[f.key] = keep ? was[f.key] : f.value;
      });
    });
  }

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();

    try {
      var body = await api('/slim-footer');
      if (mine !== seq) return;
      absorb(body, false);
      if (!pages.some(function (p) { return p.key === open; })) {
        var r = recalled();
        open = pages.some(function (p) { return p.key === r; }) ? r : (pages.length ? pages[0].key : null);
      }
    } catch (e) {
      if (mine !== seq) return;
      tabs = null;
      banner = explain(e, 'The Footer settings could not be read.');
    } finally {
      if (mine === seq) {
        busy = false; state = ''; render();
        if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
    }
  }

  /* SAVE WRITES THIS PAGE, AND ONLY WHAT CHANGED ON IT. A shared word changed
     here is on this page, so it goes; a phone-only slider changed on the
     Mobile page stays unsaved until that page is saved. Sending only what
     moved is also what keeps a "follows the desktop" control following. */
  async function save() {
    if (busy || !tabs) return;
    var p = page();
    var keys = dirtyOn(p);
    if (!keys.length) { state = 'Nothing to save on this page.'; stateKind = ''; paintState(); return; }

    var payload = {};
    keys.forEach(function (k) { payload[k] = values[k]; });

    busy = true; state = 'Saving…'; stateKind = ''; paintState();

    try {
      await api('/slim-footer', { settings: payload });
      absorb(await api('/slim-footer'), true);
      /* kbbDrafts compares against what it was told was saved. Told the
         server's values for one call, then handed the live buffer back, so the
         other pages' unsaved changes stay in Unfinished. */
      draftView = {};
      Object.keys(saved).forEach(function (k) { draftView[k] = saved[k]; });
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      draftView = null;
      state = 'Saved ' + keys.length + (keys.length === 1 ? ' change.' : ' changes.'); stateKind = 'is-ok';
      say(p.label + ' saved.');
    } catch (e) {
      state = explain(e, 'That could not be saved.'); stateKind = 'is-bad';
      say(state, 'bad');
    } finally {
      busy = false; render(); preview(0);
    }
  }

  /* ------------------------------------------------------------ drawing */
  function shown(f) {
    return String(values[f.key]) + ((f.options || {}).unit || '');
  }

  /* Every control says whom it reaches: both devices (a word, a colour, a
     link — the same setting on both pages) or this page's device only. */
  function marks(f) {
    var dev = page().device === 'm' ? 'Phone only' : 'Desktop only';
    return (shared[f.key] ? '<span class="sfs-tag" title="applies to desktop and mobile">Desktop + mobile</span>'
      : '<span class="sfs-tag is-dev" title="this page’s device only">' + dev + '</span>')
      + (isDirty(f.key) ? '<span class="sfs-dot" title="Not saved yet"></span>' : '');
  }

  function head(f, id, extra) {
    return '<div class="sfs-fh"><label for="' + id + '">' + esc(f.label) + '</label>' + marks(f) + (extra || '') + '</div>';
  }

  function more(f) {
    return f.help ? '<details class="sfs-more"><summary>ⓘ More</summary><p>' + esc(f.help) + '</p></details>' : '';
  }

  function fieldHTML(f, off) {
    var id = 'sfs-' + f.key;
    var cls = 'sfs-f' + (off ? ' is-off' : '');

    if (f.type === 'bool') {
      return '<div class="' + cls + '"><div class="sfs-sw-row">'
        + '<input type="checkbox" role="switch" class="sfs-sw" id="' + id + '" data-sfs-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<label for="' + id + '">' + esc(f.label) + '</label>' + marks(f)
        + '</div>' + more(f) + '</div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '')
          + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="' + cls + '">' + head(f, id)
        + '<select id="' + id + '" data-sfs-key="' + esc(f.key) + '">' + opts + '</select>' + more(f) + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="' + cls + '">'
        + head(f, id, '<span class="sfs-val" data-sfs-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span>')
        + '<input type="range" id="' + id + '" data-sfs-key="' + esc(f.key) + '"'
        + ' min="' + esc(o.min) + '" max="' + esc(o.max) + '" step="' + esc(o.step) + '" value="' + esc(values[f.key]) + '">'
        + more(f) + '</div>';
    }

    /* A colour is a picker beside the hex it holds; the server stores `#` and
       six hex digits or the default, whatever arrives. */
    if (f.type === 'colour') {
      var hex = /^#[0-9a-fA-F]{6}$/.test(String(values[f.key])) ? String(values[f.key]) : String(f['default']);
      return '<div class="' + cls + '">' + head(f, id)
        + '<div class="sfs-colrow"><input type="color" class="sfs-colour" id="' + id + '" data-sfs-key="' + esc(f.key) + '" value="' + esc(hex.toLowerCase()) + '">'
        + '<span class="sfs-val" data-sfs-val="' + esc(f.key) + '">' + esc(hex.toUpperCase()) + '</span></div>'
        + more(f) + '</div>';
    }

    /* A list is a box with one link per line; the server keeps only lines
       that are safe to print. */
    if (f.type === 'textarea') {
      return '<div class="' + cls + '">' + head(f, id)
        + '<textarea id="' + id + '" data-sfs-key="' + esc(f.key) + '" rows="5" spellcheck="false">'
        + esc(values[f.key]) + '</textarea>' + more(f) + '</div>';
    }

    return '<div class="' + cls + '">' + head(f, id)
      + '<input type="text" id="' + id + '" data-sfs-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '" autocomplete="off">' + more(f) + '</div>';
  }

  /* Phone-only bar controls are read only while the phone has its own shape. */
  function isOff(p, k) {
    return p.key === 'bar-m' && k !== 'mobile_on' && !shared[k] && !values.mobile_on;
  }

  function sectionHTML(p, s, i) {
    var note = '';
    if (p.footer === 'site' && s.keys.indexOf('site_design') !== -1 && String(values.site_design) === 'classic') {
      note = '<div class="sfs-note" style="margin-bottom:10px">The previous footer is on. The controls below style the new design and show once it is back on.</div>';
    }
    if (p.key === 'bar-m' && s.keys.indexOf('mobile_on') !== -1 && !values.mobile_on) {
      note = '<div class="sfs-note" style="margin-bottom:10px">Off: phones use the Desktop page’s shape and sizes. The phone-only controls below wait until this is on.</div>';
    }
    return '<section class="sfs-card sfs-sec" id="sfs-sec-' + i + '"><h3>' + esc(s.title) + '</h3><p>' + esc(s.hint) + '</p>'
      + note + '<div class="sfs-fields">'
      + s.keys.filter(function (k) { return fields[k]; }).map(function (k) { return fieldHTML(fields[k], isOff(p, k)); }).join('')
      + '</div></section>';
  }

  function pagesHTML() {
    return '<div class="sfs-pages" role="tablist" aria-label="Footer pages">' + pages.map(function (p) {
      var on = p.key === open;
      return '<button type="button" class="sfs-ptab" role="tab" data-sfs-page="' + esc(p.key) + '" aria-selected="' + (on ? 'true' : 'false') + '">'
        + ICON[p.device === 'm' ? 'm' : 'd']
        + '<span><b>' + esc(p.label) + (dirtyOn(p).length ? '<i title="Unsaved changes"> •</i>' : '') + '</b>'
        + '<small>' + (p.footer === 'site' ? 'Every shop page' : 'Cart & checkout') + '</small></span></button>';
    }).join('') + '</div>';
  }

  function stateHTML() {
    var n = dirtyOn(page()).length;
    var text = state || (n ? n + ' unsaved ' + (n === 1 ? 'change' : 'changes') + ' on this page' : 'All saved');
    var kind = state ? stateKind : (n ? 'is-dirty' : '');
    return '<span class="sfs-state ' + kind + '" data-sfs-state>' + esc(text) + '</span>';
  }

  function controlsHTML(p) {
    var bar = p.footer === 'bar';
    return '<div class="sfs-card"><div class="sfs-title">' + esc(p.label) + '</div><p class="sfs-sub">' + esc(p.hint) + '</p>'
      + '<div class="sfs-bar" style="margin-top:12px">'
      + '<button class="sfs-btn is-primary" data-sfs-save' + (busy ? ' disabled' : '') + '>Save this page</button>'
      + '<button class="sfs-btn" data-sfs-discard' + (busy ? ' disabled' : '') + '>Discard changes</button>'
      + '<button class="sfs-btn" data-sfs-defaults' + (busy ? ' disabled' : '') + '>Reset this page to defaults</button>'
      + (bar ? '<button class="sfs-btn" data-sfs-squeeze' + (busy ? ' disabled' : '') + '>Squeeze this page</button>' : '')
      + stateHTML() + '</div>'
      + '<p class="sfs-sub" style="margin-top:8px">Reset and Squeeze move only the controls on <b>this page</b>; nothing is stored until you press Save. Controls tagged <b>Desktop + mobile</b> change on both pages.</p>'
      + '<nav class="sfs-jump" aria-label="Sections">' + p.sections.map(function (s, i) {
          return '<a href="#sfs-sec-' + i + '" data-sfs-jump="' + i + '">' + esc(s.title) + '</a>';
        }).join('') + '</nav></div>'
      + p.sections.map(function (s, i) { return sectionHTML(p, s, i); }).join('');
  }

  function whereHTML(p) {
    return p.footer === 'bar'
      ? 'Shows on the checkout: <b>' + (values.co_on ? 'yes' : 'no') + '</b> · on the cart page: <b>' + (values.cart_on ? 'yes' : 'no') + '</b>'
      : 'The real footer, as the shop draws it.';
  }

  function previewHTML(p) {
    var fr = FRAME[p.key] || FRAME['site-d'];
    return '<div class="sfs-card sfs-pv" data-sfs-pv>'
      + '<div class="sfs-pvh"><b>Preview</b><span>' + (p.device === 'm' ? 'Phone · 390px' : 'Desktop · 1280px, scaled to fit') + '</span>'
      + '<em data-sfs-pvstate>' + esc(pvNote) + '</em></div>'
      + '<div class="sfs-stage' + (p.device === 'm' ? ' is-m' : '') + '"><div class="sfs-fit" style="--fw:' + fr.w + ';--fh:' + fr.h + '">'
      + '<iframe title="' + esc(p.label) + ' preview" sandbox="allow-same-origin" data-sfs-frame></iframe>'
      + '</div></div><div class="sfs-pvf" data-sfs-where>' + whereHTML(p) + '</div></div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || !onScreen()) return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="sfs-wrap"><div class="sfs-card"><div class="sfs-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs || !pages.length) {
      host.innerHTML = '<div class="sfs-wrap"><div class="sfs-card">'
        + '<div class="sfs-title">Footer</div>'
        + '<p class="sfs-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="sfs-bar" style="margin-top:12px"><button class="sfs-btn" data-sfs-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var p = page();
    var frame = host.querySelector('[data-sfs-pv]');
    var keep = frame && frame.getAttribute('data-page') === p.key ? frame : null;

    host.innerHTML = '<div class="sfs-wrap">'
      + (banner ? '<div class="sfs-note is-bad">' + esc(banner) + '</div>' : '')
      + pagesHTML()
      + '<div class="sfs-body' + (p.device === 'm' ? ' is-m' : '') + '">'
      + '<div data-sfs-pvslot></div>'
      + '<div class="sfs-ctl">' + controlsHTML(p) + '</div>'
      + '</div></div>';

    /* THE PREVIEW SURVIVES A REDRAW OF THE CONTROLS. Rebuilding the iframe on
       every switch would blank it and ask the server again for nothing. */
    var slot = host.querySelector('[data-sfs-pvslot]');
    if (keep) {
      slot.replaceWith(keep);
      var where = keep.querySelector('[data-sfs-where]');
      if (where) where.innerHTML = whereHTML(p);
    } else {
      var holder = document.createElement('div');
      holder.innerHTML = previewHTML(p);
      var node = holder.firstChild;
      node.setAttribute('data-page', p.key);
      slot.replaceWith(node);
      preview(0);
    }
  }

  function paintState() {
    var el = document.querySelector('[data-sfs-state]');
    if (!el) return;
    var holder = document.createElement('div');
    holder.innerHTML = stateHTML();
    el.replaceWith(holder.firstChild);
    var tab = document.querySelector('[data-sfs-page="' + open + '"] b');
    if (tab) {
      var dot = tab.querySelector('i');
      var n = dirtyOn(page()).length;
      if (n && !dot) { var i = document.createElement('i'); i.title = 'Unsaved changes'; i.textContent = ' •'; tab.appendChild(i); }
      if (!n && dot) dot.remove();
    }
  }

  /* ------------------------------------------------------------ preview */
  /* Only what differs from the saved values travels: the server already has
     the rest, and a slider still following another one keeps following. */
  function unsaved() {
    var out = {};
    Object.keys(values).forEach(function (k) { if (isDirty(k)) out[k] = values[k]; });
    return out;
  }

  /* ONE REQUEST PER PAUSE. Each call cancels the one before it, so a drag
     across a slider asks once, when the hand stops — never on a timer. */
  function preview(delay) {
    if (pvTimer) { clearTimeout(pvTimer); pvTimer = null; }
    pvTimer = setTimeout(function () { pvTimer = null; drawPreview(); }, delay);
  }

  async function drawPreview() {
    var p = page();
    if (!p || !document.querySelector('[data-sfs-frame]')) return;

    var mine = ++pvSeq;
    if (pvCtl) { try { pvCtl.abort(); } catch (e) {} }
    pvCtl = ('AbortController' in window) ? new AbortController() : null;
    setPvNote('Updating…');

    try {
      var body = await api('/slim-footer/preview', { page: p.key, settings: unsaved() }, pvCtl ? pvCtl.signal : undefined);
      if (mine !== pvSeq) return;
      var f = document.querySelector('[data-sfs-frame]');
      if (f) f.srcdoc = String(body.html || '');
      setPvNote(anyDirty() ? 'Showing unsaved changes' : 'Showing what the shop shows');
    } catch (e) {
      if (mine !== pvSeq || (e && e.name === 'AbortError')) return;
      setPvNote(explain(e, 'The preview could not be drawn.'));
    }
  }

  function setPvNote(t) {
    pvNote = t;
    var el = document.querySelector('[data-sfs-pvstate]');
    if (el) el.textContent = t;
  }

  /* --------------------------------------------------------------- events */
  document.addEventListener('input', function (e) {
    var el = e.target.closest ? e.target.closest('[data-sfs-key]') : null;
    if (!el || !tabs) return;

    var key = el.getAttribute('data-sfs-key');
    if (el.type === 'checkbox') values[key] = el.checked;
    else if (el.type === 'range') values[key] = Number(el.value);
    else values[key] = el.value;
    state = '';

    /* A switch or a select can change what belongs on the page, and both are
       single clicks nobody is dragging, so they redraw the controls (the
       preview frame is kept). A range and a text box only update their own
       readout: rebuilding mid-drag drops the pointer, mid-word the caret. */
    if (el.type === 'checkbox' || el.tagName === 'SELECT') { render(); preview(150); return; }

    var out = document.querySelector('[data-sfs-val="' + key + '"]');
    if (out && el.type === 'color') out.textContent = String(el.value).toUpperCase();
    else if (out && fields[key]) out.textContent = shown(fields[key]);
    paintState();
    preview(350);
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('.sfs-wrap')) return;
    var pg = e.target.closest('[data-sfs-page]');
    if (pg) { open = pg.getAttribute('data-sfs-page'); remember(open); state = ''; render(); return; }
    var jump = e.target.closest('[data-sfs-jump]');
    if (jump) {
      e.preventDefault();
      var sec = document.getElementById('sfs-sec-' + jump.getAttribute('data-sfs-jump'));
      if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    if (e.target.closest('[data-sfs-save]')) { save(); return; }
    if (e.target.closest('[data-sfs-reload]')) { load(); return; }
    if (e.target.closest('[data-sfs-discard]')) { preset('saved'); return; }
    if (e.target.closest('[data-sfs-squeeze]')) { preset('min'); return; }
    if (e.target.closest('[data-sfs-defaults]')) { preset('default'); return; }
  });

  /*
   * The three page buttons. THE PAGE YOU ARE LOOKING AT, never the others: a
   * preset that reaches past the page changes numbers nobody can see. Each
   * only writes the controls in front of you; nothing is stored until Save,
   * and Discard puts back what was saved.
   */
  function preset(which) {
    if (!tabs) return;

    var current = page();

    pageKeys(current).forEach(function (k) {
      var f = fields[k];
      if (which === 'saved') { values[k] = saved[k]; return; }
      if (which === 'default') { values[k] = f['default']; return; }
      if (f.type !== 'range') return;
      if (squeezeKeys.indexOf(f.key) === -1) return;
      values[f.key] = Number((f.options || {}).min);
    });

    state = '';
    render();
    preview(0);
    say(which === 'min'
      ? 'Squeezed — ' + current.label + ' only. Nothing is saved until you press Save.'
      : (which === 'saved'
        ? current.label + ' is back to what was saved.'
        : current.label + ' is back to its shipped values. Nothing is saved until you press Save.'));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
