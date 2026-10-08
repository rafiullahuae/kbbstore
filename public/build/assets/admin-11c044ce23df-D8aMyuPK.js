
(function () {
  'use strict';

  var SCREEN = 'comingsoon';
  var data = null, cfg = null, dirty = false, busy = false, msg = '', msgErr = false, fieldErr = {};
  var tlang = 'en', plang = 'en', dev = 'm', other = false, previewHtml = '', previewNote = '';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }

  async function api(method, path, body) {
    var r = await fetch(root() + '/admin-api/coming-soon' + path, {
      method: method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });
    var json = null;
    try { json = await r.json(); } catch (e) { json = null; }
    if (!r.ok) {
      var err = new Error((json && (json.error || json.message)) || ('Coming Soon page ' + r.status));
      err.status = r.status; err.fields = (json && json.fields) || {};
      throw err;
    }
    return json;
  }

  function take(json) {
    data = json; cfg = clone(json.config); dirty = false; fieldErr = {};
    other = data.hosts.indexOf(cfg.host) < 0;
  }

  function refused(e, what) {
    return e && e.status === 403 ? 'Only the owner can change the Coming Soon page.'
      : (e && e.status === 404 ? 'This screen is not in the server\'s route table yet. Clear the route cache and reload.'
      : (e && e.message) || ('Could not ' + what + '.'));
  }

  async function load() {
    msg = ''; msgErr = false;
    try { take(await api('GET', '')); } catch (e) { data = null; msgErr = true; msg = refused(e, 'load the Coming Soon settings'); }
    paint();
    if (data) preview();
  }

  async function save() {
    if (busy || !data) return;
    busy = true; msg = 'Saving…'; msgErr = false; paintBar();
    try {
      take(await api('POST', '', cfg));
      msg = cfg.on ? 'Saved. It is live now — the address shows the Coming Soon page.' : 'Saved. Every address shows the shop.';
    } catch (e) { msgErr = true; fieldErr = e.fields || {}; msg = refused(e, 'save'); }
    busy = false; paint();
  }

  async function rotate() {
    if (busy || !data) return;
    if (!window.confirm('Make a new preview link? The current link, and every phone that opened it, stops working.')) return;
    busy = true; paintBar();
    try {
      var keep = cfg; take(await api('POST', '/link', {})); cfg = keep; cfg.hours = data.config.hours;
      msg = 'New link made. Copy it again.'; msgErr = false;
    } catch (e) { msgErr = true; msg = refused(e, 'make a new link'); }
    busy = false; paint();
  }

  async function preview() {
    if (!data) return;
    previewNote = 'Drawing…'; paintFrame();
    try {
      var r = await api('POST', '/preview', { content: cfg.content, lang: plang });
      previewHtml = r.html; previewNote = (r.bytes / 1024).toFixed(1) + ' KB page · ' + (dev === 'm' ? '390 px phone' : '1280 px desktop') + ' · ' + (plang === 'ar' ? 'Arabic' : 'English');
    } catch (e) { previewNote = e.status === 422 ? 'Fix the marked fields to see the preview.' : refused(e, 'draw the preview'); fieldErr = e.fields || fieldErr; }
    paintFrame();
  }

  /* ── the screen ── */
  function err(k) { return fieldErr[k] ? '<div class="csx-err">' + esc(fieldErr[k]) + '</div>' : ''; }

  function statusHtml() {
    var s = data.status;
    return '<div class="csx-status ' + (s.on ? 'on' : 'off') + '" data-csx-status><p><b class="pill">' + (s.on ? 'ON' : 'OFF') + '</b>' + esc(s.line) + '</p>'
      + (s.warnings.length ? '<ul>' + s.warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul>' : '')
      + '<small>Locked out? Over SSH in the app folder: <code>' + esc(data.emergency) + '</code></small></div>';
  }

  function switchCard() {
    var hosts = data.hosts.map(function (h) {
      return '<option value="' + esc(h) + '"' + (!other && h === cfg.host ? ' selected' : '') + '>' + esc(h) + (h === data.here ? ' (this address)' : '') + '</option>';
    }).join('') + '<option value="__other"' + (other ? ' selected' : '') + '>Another address…</option>';
    return '<div class="csx-card"><h3>Coming Soon page</h3>'
      + '<p class="csx-help">Visitors on the chosen address see the Coming Soon page instead of the shop. You (signed in) and anyone with the preview link still see the shop. The admin, payments, webhooks and the SSL certificate check always keep working.</p>'
      + '<div class="csx-row"><span class="l">Show it</span><label class="csx-sw"><input type="checkbox" data-csx-k="on"' + (cfg.on ? ' checked' : '') + '> <span>' + (cfg.on ? 'On' : 'Off') + '</span></label></div>'
      + '<div class="csx-row" style="align-items:start"><span class="l">Which address</span><div>'
      + '<div class="csx-radio"><label><input type="radio" name="csx-scope" value="host"' + (cfg.scope === 'host' ? ' checked' : '') + '> Only this address</label>'
      + '<div class="csx-host"><select data-csx-host' + (cfg.scope === 'host' ? '' : ' disabled') + '>' + hosts + '</select>'
      + (other ? '<input type="text" data-csx-k="host" inputmode="url" autocomplete="off" spellcheck="false" placeholder="kbeautybliss.com" value="' + esc(cfg.host) + '"' + (fieldErr.host ? ' class="bad"' : '') + '>' : '') + '</div>'
      + '<p class="csx-help" style="margin:4px 0 0 24px">www.' + esc(cfg.host || 'the address') + ' is included.</p>' + err('host')
      + '<label><input type="radio" name="csx-scope" value="all"' + (cfg.scope === 'all' ? ' checked' : '') + '> Every address</label></div></div></div></div>';
  }

  function linkCard() {
    var l = data.link;
    var hours = data.hour_choices.map(function (h) {
      return '<option value="' + h + '"' + (h === cfg.hours ? ' selected' : '') + '>' + (h < 24 ? h + ' hours' : (h / 24) + (h === 24 ? ' day' : ' days')) + '</option>';
    }).join('');
    return '<div class="csx-card"><h3>Secret preview link</h3>'
      + '<p class="csx-help">For test orders from a phone without signing in. Opening it on ' + esc(l ? l.host : cfg.host) + ' lets that browser see the real shop until the link expires. "New link" stops every earlier link at once.</p>'
      + (l ? '<div class="csx-link"><input type="text" readonly data-csx-linkval value="' + esc(l.url) + '"><button type="button" class="csx-btn" data-csx-copy>Copy</button></div>'
        + '<p class="csx-help">Works until ' + esc(new Date(l.until).toLocaleString()) + '.</p>'
        : '<p class="csx-help"><b>No live link.</b> Turn the page on and save, or press New link.</p>')
      + '<div class="csx-acts"><label style="font-size:12.5px;font-weight:600">Lasts <select data-csx-k="hours" style="width:auto">' + hours + '</select></label>'
      + '<button type="button" class="csx-btn" data-csx-rotate' + (busy ? ' disabled' : '') + '>New link</button></div>'
      + '<p class="csx-help" style="margin-top:8px">Changing how long it lasts makes a new link when you save.</p></div>';
  }

  function field(k, label, area) {
    var path = 'content.' + tlang + '.' + k, v = cfg.content[tlang][k] || '', max = data.limits[k];
    var ph = data.defaults[tlang][k] || '';
    var dir = tlang === 'ar' ? ' dir="rtl" lang="ar"' : '';
    var input = area
      ? '<textarea data-csx-k="' + path + '" maxlength="' + max + '" placeholder="' + esc(ph) + '"' + dir + (fieldErr[tlang + '.' + k] || fieldErr['content.' + tlang + '.' + k] ? ' class="bad"' : '') + '>' + esc(v) + '</textarea>'
      : '<input type="text" data-csx-k="' + path + '" maxlength="' + max + '" placeholder="' + esc(ph) + '" value="' + esc(v) + '"' + dir + '>';
    return '<div class="csx-row" style="align-items:start"><label>' + esc(label) + '</label><div>' + input
      + '<div class="csx-n" data-csx-n="' + path + '">' + v.length + ' / ' + max + (v === '' ? ' · empty uses the grey text' : '') + '</div>' + err('content.' + tlang + '.' + k) + '</div></div>';
  }

  function contentCard() {
    var c = cfg.content;
    return '<div class="csx-card"><h3>What it says</h3>'
      + '<p class="csx-help">English for most visitors, Arabic for /ar/ and for browsers set to Arabic. The shop’s wordmark (Appearance → Header → Logo) sits on top.</p>'
      + '<div class="csx-lang">' + ['en', 'ar'].map(function (l) { return '<button type="button" data-csx-tlang="' + l + '"' + (tlang === l ? ' class="on"' : '') + '>' + (l === 'en' ? 'English' : 'العربية') + '</button>'; }).join('') + '</div>'
      + field('heading', 'Heading', false) + field('message', 'Message', true) + field('small', 'Small line', false)
      + '<div class="csx-row"><span class="l">Background colour</span><div class="csx-bgrow">'
      + '<input type="color" data-csx-k="content.bg" value="' + esc(c.bg || data.default_bg) + '">'
      + '<input type="text" data-csx-k="content.bg" maxlength="7" placeholder="' + esc(data.default_bg) + '" value="' + esc(c.bg) + '"' + (fieldErr['content.bg'] ? ' class="bad"' : '') + '>'
      + '<button type="button" class="csx-btn" data-csx-bgreset' + (c.bg ? '' : ' disabled') + '>Shop pink</button></div></div>' + err('content.bg')
      + '<div class="csx-row"><span class="l">Background picture</span><div class="csx-bgrow">'
      + (c.bg_image ? '<code>' + esc(c.bg_image) + '</code>' : '<span class="csx-help" style="margin:0">None — the colour above</span>')
      + '<button type="button" class="csx-btn" data-csx-pick>Media Library…</button>'
      + (c.bg_image ? '<button type="button" class="csx-btn" data-csx-unpick>Remove</button>' : '') + '</div></div>' + err('content.bg_image')
      + '<div class="csx-row"><span class="l">Contact links</span><div class="csx-acts">'
      + '<label class="csx-sw"><input type="checkbox" data-csx-k="content.show_whatsapp"' + (c.show_whatsapp ? ' checked' : '') + (data.contact.whatsapp ? '' : ' disabled') + '> WhatsApp</label>'
      + '<label class="csx-sw"><input type="checkbox" data-csx-k="content.show_instagram"' + (c.show_instagram ? ' checked' : '') + (data.contact.instagram ? '' : ' disabled') + '> Instagram</label></div></div>'
      + '<p class="csx-help">The WhatsApp number and Instagram page come from the shop’s existing settings.</p></div>';
  }

  function previewPanel() {
    return '<div class="csx-prev"><div class="csx-acts">'
      + '<span class="csx-seg">' + ['m', 'd'].map(function (d) { return '<button type="button" data-csx-dev="' + d + '"' + (dev === d ? ' class="on"' : '') + '>' + (d === 'm' ? 'Phone' : 'Desktop') + '</button>'; }).join(' ') + '</span>'
      + '<span class="csx-seg">' + ['en', 'ar'].map(function (l) { return '<button type="button" data-csx-plang="' + l + '"' + (plang === l ? ' class="on"' : '') + '>' + (l === 'en' ? 'EN' : 'AR') + '</button>'; }).join(' ') + '</span>'
      + '<button type="button" class="csx-btn" data-csx-preview>Preview</button></div>'
      + '<div class="csx-frame ' + dev + '"><iframe title="Coming Soon page preview" sandbox="" data-csx-frame></iframe></div>'
      + '<div class="csx-cap"><span data-csx-cap></span> · <a target="_blank" rel="noopener" href="' + esc(root() + '/admin-api/coming-soon/preview?lang=' + plang) + '">Open the saved page ↗</a></div></div>';
  }

  function paint() {
    var host = document.getElementById('content');
    if (!host) return;
    var root = host.querySelector('[data-csx]');
    if (!root) { host.innerHTML = '<div data-csx></div>'; root = host.querySelector('[data-csx]'); }
    if (!data) { root.innerHTML = msg ? '<div class="csx-card"><p class="csx-msg' + (msgErr ? ' err' : '') + '">' + esc(msg) + '</p></div>' : '<div class="csx-card"><p class="csx-msg">Loading…</p></div>'; return; }
    root.innerHTML = statusHtml() + '<div class="csx"><div class="csx-side">' + switchCard() + linkCard() + contentCard() + '</div>' + previewPanel() + '</div>'
      + '<div class="csx-bar" data-csx-bar></div>';
    paintBar(); paintFrame();
  }

  function paintBar() {
    var bar = document.querySelector('[data-csx-bar]');
    if (!bar) return;
    bar.classList.toggle('dirty', dirty);
    bar.innerHTML = '<button type="button" class="csx-btn pri" data-csx-save' + (busy || !dirty ? ' disabled' : '') + '>Save</button>'
      + '<span class="csx-msg' + (msgErr ? ' err' : '') + '">' + esc(msg || (dirty ? 'Unsaved changes.' : 'Everything is saved.')) + '</span>';
  }

  function paintFrame() {
    var f = document.querySelector('[data-csx-frame]'), cap = document.querySelector('[data-csx-cap]');
    if (f && f.srcdoc !== previewHtml) f.srcdoc = previewHtml;
    if (cap) cap.textContent = previewNote;
  }

  function setPath(path, v) {
    var ks = path.split('.'), o = cfg;
    for (var i = 0; i < ks.length - 1; i++) o = o[ks[i]];
    o[ks[ks.length - 1]] = v;
  }
  function changed(repaint) { dirty = true; msg = ''; msgErr = false; if (repaint) paint(); else paintBar(); }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-csx]') || !data) return;
    var k = t.getAttribute('data-csx-k');
    if (!k || t.type === 'checkbox' || t.tagName === 'SELECT') return;
    var v = t.value;
    if (k === 'content.bg' && t.type === 'color') v = v.toUpperCase();
    setPath(k, v);
    var n = document.querySelector('[data-csx-n="' + k + '"]');
    if (n) n.textContent = v.length + ' / ' + (t.getAttribute('maxlength') || '') + (v === '' ? ' · empty uses the grey text' : '');
    if (k === 'content.bg') document.querySelectorAll('[data-csx-k="content.bg"]').forEach(function (o) { if (o !== t && o.type !== 'color') o.value = v; });
    changed(false);
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-csx]') || !data) return;
    var k = t.getAttribute('data-csx-k');
    if (t.name === 'csx-scope') { cfg.scope = t.value; changed(true); return; }
    if (t.hasAttribute('data-csx-host')) {
      other = t.value === '__other';
      if (!other) cfg.host = t.value;
      changed(true); return;
    }
    if (!k) return;
    if (t.type === 'checkbox') { setPath(k, !!t.checked); changed(true); if (k.indexOf('content.') === 0) preview(); return; }
    if (k === 'hours') { cfg.hours = parseInt(t.value, 10); changed(false); return; }
    if (k.indexOf('content.') === 0) { if (k === 'content.bg') changed(true); preview(); }
  });

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target : null;
    if (!t || !t.closest('[data-csx]') || !data) return;
    var b;
    if ((b = t.closest('[data-csx-save]')) && !b.disabled) save();
    else if ((b = t.closest('[data-csx-rotate]')) && !b.disabled) rotate();
    else if ((b = t.closest('[data-csx-preview]'))) preview();
    else if ((b = t.closest('[data-csx-tlang]'))) { tlang = b.getAttribute('data-csx-tlang'); paint(); }
    else if ((b = t.closest('[data-csx-plang]'))) { plang = b.getAttribute('data-csx-plang'); paint(); preview(); }
    else if ((b = t.closest('[data-csx-dev]'))) { dev = b.getAttribute('data-csx-dev'); paint(); }
    else if ((b = t.closest('[data-csx-bgreset]'))) { cfg.content.bg = ''; changed(true); preview(); }
    else if ((b = t.closest('[data-csx-unpick]'))) { cfg.content.bg_image = ''; changed(true); preview(); }
    else if ((b = t.closest('[data-csx-copy]'))) {
      var input = document.querySelector('[data-csx-linkval]');
      if (!input) return;
      var done = function () { msg = 'Link copied. Open it on your phone.'; msgErr = false; paintBar(); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(input.value).then(done, function () { input.select(); });
      else { input.select(); }
    } else if ((b = t.closest('[data-csx-pick]'))) {
      if (typeof window.kbbPickMedia !== 'function') { msg = 'The Media Library is not available here.'; msgErr = true; paintBar(); return; }
      window.kbbPickMedia({
        title: 'Coming Soon background', folder: 'coming-soon',
        note: 'Pick a picture already in the library, or upload one. A soft, light picture keeps the words readable.',
        onPick: function (urls) { if (urls && urls.length) { cfg.content.bg_image = String(urls[0]); changed(true); preview(); } }
      });
    }
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Coming Soon page',
      icon: '<path d="M6 3h12M6 21h12"/><path d="M7 3c0 5 10 6 10 9s-10 4-10 9"/><path d="M17 3c0 5-10 6-10 9s10 4 10 9"/>',
      group: 'Appearance',
      after: ['sitelayout', 'layout', 'bundles']
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
    if (title) title.textContent = 'Coming Soon page';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var host = document.getElementById('content');
    if (host) host.innerHTML = '';
    data = null; previewHtml = ''; previewNote = '';
    paint();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
