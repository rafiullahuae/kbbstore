{{--
    Appearance -> Coming Soon page.                                    (Lane CS)

    The owner: "when i connect the domain to my server ip ... the domain
    kbeautybliss.com should not show the site, it should show a beautiful
    coming soon ... give options on back backend under Appearance > Coming
    Soon page ... to enable and disable along with the domain chosen option."

    One GET when the screen opens (settings, status line, the address list and
    the live preview link). The preview frame is drawn by the SERVER from the
    unsaved fields -- the same renderer the shop uses, so the frame cannot
    drift from the page -- once per press of "Preview" or per committed edit
    (`change`, never a keystroke). One POST on Save, one on "New link".
    No timer, nothing measured; the frame is sized by CSS.

    Every value the owner types reaches this page through esc() or .value; the
    frame is <iframe sandbox> with srcdoc, so nothing in it can script.

    Endpoints: routes/coming-soon-admin.php, `comingsoon.manage`. Wired by
    tools/cs-wire.php (docs/cs-wiring.json); registers its own sidebar row
    through kbbAddNavEntry() and wraps window.go.
--}}
@verbatim
<style>
.csx{display:grid;grid-template-columns:minmax(0,1fr) 520px;gap:18px;align-items:start}
.csx-side{display:grid;gap:14px;min-width:0}
.csx-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:16px;min-width:0}
.csx-card h3{font-size:15px;font-weight:700;margin:0 0 6px}
.csx-help{font-size:12.5px;color:var(--ink-soft,#6b7280);margin:0 0 10px;line-height:1.5}
.csx-status{display:grid;gap:8px;border-radius:14px;padding:14px 16px;margin:0 0 14px;border:1px solid}
.csx-status.on{background:#fff7ed;border-color:#fdba74;color:#7c2d12}
.csx-status.off{background:#f0fdf4;border-color:#86efac;color:#14532d}
.csx-status b.pill{display:inline-block;font-size:11px;letter-spacing:.06em;border-radius:999px;padding:2px 9px;margin-inline-end:8px;color:#fff;background:#16a34a}
.csx-status.on b.pill{background:#ea580c}
.csx-status p{margin:0;font-size:14px;font-weight:600;line-height:1.45}
.csx-status ul{margin:0;padding-inline-start:18px;font-size:12.5px;font-weight:500}
.csx-status small{font-size:12px;opacity:.85}
.csx-status code,.csx-card code{font-size:12px;background:rgba(0,0,0,.06);border-radius:6px;padding:1px 6px}
.csx-row{display:grid;grid-template-columns:150px minmax(0,1fr);gap:8px 12px;align-items:center;margin:0 0 10px}
.csx-row>label,.csx-row>span.l{font-size:12.5px;font-weight:600}
.csx input[type=text],.csx textarea,.csx select{font:inherit;font-size:13px;width:100%;box-sizing:border-box;border:1px solid var(--border,#e6e6e6);border-radius:9px;padding:7px 9px;background:var(--surface,#fff);color:inherit;min-width:0}
.csx textarea{min-height:64px;resize:vertical}
.csx .bad{border-color:#dc2626!important;background:#fef2f2}
.csx-err{font-size:11.5px;color:#b91c1c;margin-top:3px}
.csx-n{font-size:11px;color:var(--ink-soft,#6b7280);text-align:end;margin-top:2px}
.csx-sw{display:inline-flex;gap:10px;align-items:center;font-size:13.5px;font-weight:650;cursor:pointer}
.csx-sw input{width:44px;height:24px;appearance:none;-webkit-appearance:none;border-radius:999px;background:#cbd5e1;position:relative;cursor:pointer;flex:none;margin:0}
.csx-sw input::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .15s}
.csx-sw input:checked{background:#ea580c}
.csx-sw input:checked::after{transform:translateX(20px)}
[dir=rtl] .csx-sw input:checked::after{transform:translateX(-20px)}
.csx-radio{display:grid;gap:8px;margin:0 0 6px}
.csx-radio label{display:flex;gap:8px;align-items:center;font-size:13px;font-weight:600}
.csx-host{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px;margin:2px 0 0 24px}
.csx-lang{display:inline-flex;gap:4px;margin:0 0 10px}
.csx-lang button,.csx-seg button{font:inherit;font-size:12px;font-weight:650;border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);border-radius:999px;padding:4px 12px;cursor:pointer;color:inherit}
.csx-lang button.on,.csx-seg button.on{background:#111827;color:#fff;border-color:#111827}
.csx-btn{font:inherit;font-size:13px;font-weight:650;border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);border-radius:9px;padding:8px 12px;cursor:pointer;color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.csx-btn.pri{background:#111827;color:#fff;border-color:#111827}
.csx-btn[disabled]{opacity:.5;cursor:default}
.csx-link{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;margin:0 0 8px}
.csx-link input{font-family:ui-monospace,SFMono-Regular,Menlo,monospace!important;font-size:12px!important}
.csx-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.csx-bgrow{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.csx-bgrow input[type=color]{width:40px;height:34px;padding:2px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:#fff;cursor:pointer}
.csx-bgrow input[type=text]{width:110px}
.csx-prev{position:sticky;top:12px;display:grid;gap:8px;min-width:0}
.csx-prev .csx-acts{justify-content:space-between;flex-wrap:nowrap}
.csx-cap a{color:inherit;font-weight:650}
.csx-frame{background:#f1f5f9;border:1px solid var(--border,#e6e6e6);border-radius:14px;overflow:hidden;margin:0 auto}
.csx-frame iframe{border:0;display:block;transform-origin:0 0;background:#fff}
.csx-frame.d{--w:1280;--h:800;--s:.4}
.csx-frame.m{--w:390;--h:780;--s:.72}
.csx-frame{width:calc(var(--w) * var(--s) * 1px);height:calc(var(--h) * var(--s) * 1px)}
.csx-frame iframe{width:calc(var(--w) * 1px);height:calc(var(--h) * 1px);transform:scale(var(--s))}
.csx-cap{font-size:12px;color:var(--ink-soft,#6b7280);text-align:center}
.csx-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:12px 16px;margin-top:14px}
.csx-bar.dirty{position:sticky;bottom:0;box-shadow:0 -8px 24px -12px rgba(0,0,0,.25);z-index:2}
.csx-msg{font-size:12.5px;color:var(--ink-soft,#6b7280)}
.csx-msg.err{color:#b91c1c}
@media (max-width:1280px){.csx{grid-template-columns:minmax(0,1fr) 400px}.csx-frame.d{--s:.31}.csx-frame.m{--s:.62}}
@media (max-width:1060px){.csx{grid-template-columns:minmax(0,1fr)}.csx-prev{position:static;order:-1}.csx-frame.d{--s:.5}.csx-frame.m{--s:.66}}
@media (max-width:720px){.csx-row{grid-template-columns:1fr}.csx-host{grid-template-columns:1fr;margin-inline-start:0}.csx-frame.d{--s:.26}.csx-frame.m{--s:.6}.csx-card{padding:13px}}
</style>

<script>
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
</script>
@endverbatim
