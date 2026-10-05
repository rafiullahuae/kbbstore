{{--
    Platform → Users & Roles → Owner app → Customise app (Lane OA4).

    The owner: "i would like to control everything from the main admin for the
    owner app, like fonts, sizes, etc etc and any function / screen turn on/off".

    Included ONCE, by one line at the end of owner-app-access.blade.php. It adds
    a sub-tab row above that tab's panel — "Access & security" (everything that
    was there) and "Customise app" (this) — by wrapping
    window.kbbOwnerAppAdmin.mount; the access panel's own code is untouched.

    One GET when the sub-tab is first opened, one PUT on Save. The live phone
    preview is an srcdoc frame holding a STATIC mock of My store and an orders
    row, styled by the app's own stylesheet with the same classes and custom
    property the app puts on <html> — so what it shows is what the phone does.
    Nothing is requested per change; nothing measures layout.

    SAFETY. Every value is escaped before innerHTML, in the panel and in the
    preview. Writes carry X-XSRF-TOKEN. The server re-validates everything
    (OwnerAppUi::put): selects, clamps, the accent's #rrggbb form and its 4.5:1
    contrast under white text.
--}}
@verbatim
<style>
.oac-tabs{display:flex;gap:6px;margin:0 0 12px;flex-wrap:wrap}
.oac-tabs button{border:1px solid var(--border);background:var(--surface);color:var(--ink-2);border-radius:999px;padding:7px 14px;font:inherit;font-size:13px;font-weight:600;cursor:pointer}
.oac-tabs button.on{background:var(--accent);border-color:var(--accent);color:#fff}
.oac{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:14px;align-items:start;min-width:0}
.oac-ctl{display:grid;gap:12px;min-width:0}
.oac-pv{position:sticky;top:12px;display:grid;gap:8px;justify-items:center}
.oac-h{font-size:14px;font-weight:700;margin:0 0 10px}
.oac-g{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px 14px}
.oac-g label,.oac-f{display:grid;gap:5px;font-size:12px;font-weight:600;color:var(--ink-2);min-width:0}
.oac-sw{display:flex;gap:8px;align-items:center;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;min-width:0}
.oac-sw input{width:18px;height:18px;accent-color:var(--accent);flex:none}
.oac-sws{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:9px 14px}
.oac-acc{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.oac-sq{width:30px;height:30px;border-radius:50%;border:2px solid transparent;cursor:pointer;padding:0;box-shadow:inset 0 0 0 2px #fff}
.oac-sq.on{border-color:var(--ink)}
.oac-acc input[type=color]{width:36px;height:32px;border:1px solid var(--border);border-radius:8px;padding:2px;background:var(--surface)}
.oac-acc .rl-in{width:110px;font-family:var(--mono)}
.oac-ct{font-size:12px;margin:8px 0 0;color:var(--ink-2)}
.oac-ct.bad{color:#b8362d;font-weight:600}
.oac-sec{display:grid;gap:6px}
.oac-sec div{display:grid;grid-template-columns:minmax(0,1fr) 56px 34px 34px;gap:6px;align-items:center;padding:6px 8px;border:1px solid var(--border-2,var(--border));border-radius:10px}
.oac-sec .rl-in{padding:6px 8px;text-align:center}
.oac-sec button{height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);cursor:pointer;font-size:14px;color:var(--ink)}
.oac-sec button:disabled{opacity:.35;cursor:default}
.oac-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.oac-bar .rl-note{margin:0}
.oac-pvt{display:flex;gap:4px}
.oac-pvt button{border:1px solid var(--border);background:var(--surface);border-radius:999px;padding:5px 12px;font:inherit;font-size:12px;font-weight:600;cursor:pointer;color:var(--ink-2)}
.oac-pvt button.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.oac-phone{box-sizing:border-box;width:287px;height:574px;border-radius:30px;border:7px solid #1d1d22;overflow:hidden;background:#fff;box-shadow:0 18px 40px -22px rgba(0,0,0,.45)}
.oac-phone iframe{display:block;width:390px;height:800px;border:0;transform:scale(.7);transform-origin:0 0}
.oac-mt{margin-top:12px}
.oac-lnk{background:none;border:0;padding:0;font:inherit;color:var(--accent);font-weight:600;cursor:pointer;text-decoration:underline}
@media (max-width:1000px){.oac{grid-template-columns:minmax(0,1fr)}.oac-pv{position:static;order:-1}}
</style>
<script>
(function () {
  'use strict';
  var base = window.kbbOwnerAppAdmin;
  if (!base || base.oa4) return;

  var D = null, st = null, tab = 'access', pv = 'store', saved = '', bar = null, access = null, panel = null, frame = null;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function toastMsg(t, bad) { try { if (typeof window.toast === 'function') window.toast(t, bad ? 'bad' : undefined); } catch (e) {} }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  async function api(method, body) {
    var o = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    var r; try { r = await fetch(root() + '/admin-api/owner-app/ui', o); } catch (e) { return { ok: false, status: 0, data: {} }; }
    var d = {}; try { d = await r.json(); } catch (e) { d = {}; }
    return { ok: r.ok && d.ok !== false, status: r.status, data: d };
  }
  function why(r) {
    var d = r.data || {};
    if (r.status === 404 && !d.message) return 'This card is not in the server’s compiled route table yet. Clear it from Platform → Cache, then reload.';
    return d.message || (r.status === 403 ? 'Only a Full Admin customises the owner app.' : 'The server answered ' + r.status + '.');
  }

  /* WCAG contrast of white text on #rrggbb — the same sum the server checks. */
  function contrast(hex) {
    var l = 0;
    [[1, 0.2126], [3, 0.7152], [5, 0.0722]].forEach(function (p) {
      var c = parseInt(hex.substr(p[0], 2), 16) / 255;
      l += p[1] * (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    });
    return 1.05 / (l + 0.05);
  }
  var hexOk = function (h) { return /^#[0-9a-fA-F]{6}$/.test(h || ''); };

  /* ------------------------------------------------------------ mounting */

  var orig = base.mount;
  base.mount = function (el) {
    orig.call(base, el);
    access = el;
    bar = document.createElement('div');
    bar.className = 'oac-tabs';
    bar.setAttribute('role', 'tablist');
    bar.setAttribute('aria-label', 'Owner app');
    el.parentNode.insertBefore(bar, el);
    panel = document.createElement('div');
    panel.setAttribute('data-oa-ui', '');
    el.parentNode.insertBefore(panel, el.nextSibling);
    frame = null;
    show();
  };
  base.oa4 = true;

  function show() {
    bar.innerHTML = [['access', 'Access & security'], ['ui', 'Customise app']].map(function (t) {
      return '<button type="button" role="tab" data-oac-tab="' + t[0] + '" aria-selected="' + (tab === t[0]) + '"' + (tab === t[0] ? ' class="on"' : '') + '>' + esc(t[1]) + '</button>';
    }).join('');
    access.hidden = tab !== 'access';
    panel.hidden = tab !== 'ui';
    if (tab !== 'ui') return;
    if (!D) { panel.innerHTML = '<div class="rl-list"><div class="rl-skel"></div><div class="rl-skel"></div></div>'; load(); return; }
    paint();
  }

  async function load() {
    var r = await api('GET');
    if (!r.ok) { panel.innerHTML = '<div class="rl-err" role="alert">' + esc(why(r)) + '</div>'; return; }
    D = r.data; st = clone(D.ui); saved = JSON.stringify(st); frame = null;
    if (tab === 'ui') paint();
  }

  /* -------------------------------------------------------------- the card */

  var LABEL = {
    font: { jakarta: 'Plus Jakarta Sans (default)', system: 'System font (fastest — skips the 27 KB font download)' },
    text: { s: 'Small (14px)', m: 'Medium (15px, default)', l: 'Large (16px)' },
    title: { s: 'Small', m: 'Medium (default)', l: 'Large' },
    figure: { s: 'Small (36px)', m: 'Medium (44px, default)', l: 'Large (52px)' },
    density: { comfortable: 'Comfortable (default)', compact: 'Compact' },
    corners: { soft: 'Soft (default)', medium: 'Medium', square: 'Square' },
    header: { compact: 'Compact (default)', standard: 'Standard (large logo and title)' },
  };
  function sel(k, label) {
    return '<label>' + esc(label) + '<select class="rl-in" data-k="' + k + '">' + D.options.choices[k].map(function (v) {
      return '<option value="' + esc(v) + '"' + (st[k] === v ? ' selected' : '') + '>' + esc(LABEL[k][v] || v) + '</option>';
    }).join('') + '</select></label>';
  }
  function sw(group, k, label) {
    return '<label class="oac-sw"><input type="checkbox" data-g="' + group + '" data-gk="' + esc(k) + '"' + (st[group][k] ? ' checked' : '') + '> ' + esc(label) + '</label>';
  }

  /* The controls; the preview frame beside them is built once per mount and redrawn in place. */
  function controls() {
    var o = D.options, ratio = hexOk(st.accent) ? contrast(st.accent) : 0, bad = ratio < o.min_contrast;
    var secs = st.sections.map(function (k, i) {
      return '<div><label class="oac-sw"><input type="checkbox" data-sec-on="' + esc(k) + '"' + (st.sections_off.indexOf(k) === -1 ? ' checked' : '') + '> ' + esc(o.sections[k]) + '</label>' +
        '<input class="rl-in" type="number" min="1" max="' + st.sections.length + '" value="' + (i + 1) + '" data-sec-pos="' + esc(k) + '" aria-label="Position of ' + esc(o.sections[k]) + '">' +
        '<button type="button" data-sec-mv="-1" data-sk="' + esc(k) + '" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
        '<button type="button" data-sec-mv="1" data-sk="' + esc(k) + '" aria-label="Move down"' + (i === st.sections.length - 1 ? ' disabled' : '') + '>↓</button></div>';
    }).join('');

    var ctl = '<div class="oac-ctl">' +
      '<div class="rl-card"><p class="oac-h">Branding</p><div class="oac-g">' +
        '<label>Store name in the header<input class="rl-in" data-k="store_name" maxlength="60" value="' + esc(st.store_name) + '"></label>' +
        '<label>Header initials (1–3 letters)<input class="rl-in" data-k="initials" maxlength="3" value="' + esc(st.initials) + '"></label></div>' +
        '<div class="oac-f oac-mt">Accent colour<div class="oac-acc">' + Object.keys(o.presets).map(function (h) {
          return '<button type="button" class="oac-sq' + (st.accent.toUpperCase() === h ? ' on' : '') + '" data-acc="' + esc(h) + '" title="' + esc(o.presets[h]) + '" aria-label="' + esc(o.presets[h]) + '"></button>';
        }).join('') +
        '<input type="color" data-acc-pick value="' + esc(hexOk(st.accent) ? st.accent.toLowerCase() : '#a8475c') + '" aria-label="Custom accent colour">' +
        '<input class="rl-in" data-acc-hex maxlength="7" spellcheck="false" value="' + esc(st.accent) + '" aria-label="Accent colour hex"></div></div>' +
        '<p class="oac-ct' + (bad ? ' bad' : '') + '" data-ct>' + (hexOk(st.accent) ? 'White text on ' + esc(st.accent.toUpperCase()) + ' reads at ' + ratio.toFixed(1) + ':1' + (bad ? ' — below 4.5:1, so Save refuses it. Choose a darker colour.' : ' ✓') : 'Enter a colour like #A8475C.') + '</p></div>' +

      '<div class="rl-card"><p class="oac-h">Type</p><div class="oac-g">' + sel('font', 'Font') + sel('text', 'Text size') + sel('title', 'Title size') + sel('figure', 'Dashboard big numbers') + '</div></div>' +
      '<div class="rl-card"><p class="oac-h">Layout</p><div class="oac-g">' + sel('density', 'Density') + sel('corners', 'Corner roundness') + sel('header', 'My store header') + '</div></div>' +

      '<div class="rl-card"><p class="oac-h">Screens</p><div class="oac-sws">' + Object.keys(o.screens).map(function (k) { return sw('screens', k, o.screens[k]); }).join('') +
        '<label class="oac-sw"><input type="checkbox" checked disabled> More (always on: lock and sign-out)</label></div>' +
        '<p class="rl-note">A screen switched off leaves the bottom bar and the tablet rail, and the server refuses its data for everyone. Each member’s role still applies on top.</p>' +
        '<p class="oac-h oac-mt">My store sections</p><div class="oac-sec">' + secs + '</div></div>' +

      '<div class="rl-card"><p class="oac-h">Functions</p><div class="oac-sws">' + Object.keys(o.functions).map(function (k) { return sw('functions', k, o.functions[k]); }).join('') + '</div>' +
        '<div class="oac-g oac-mt"><label>Live check every (seconds, ' + o.live[0] + '–' + o.live[1] + ')<input class="rl-in" type="number" min="' + o.live[0] + '" max="' + o.live[1] + '" data-k="live_seconds" value="' + esc(st.live_seconds) + '"' + (st.functions.live ? '' : ' disabled') + '></label></div>' +
        '<p class="rl-note">An action switched off is hidden in the app and refused by the server. The live check only runs while the app is open on screen. ' +
        'Show loading bars after: <b>' + esc(D.stale_minutes) + ' min</b> and the lock-screen notification text are under <button type="button" class="oac-lnk" data-oac-tab="access">Access &amp; security</button>.</p></div>' +

      '<div class="rl-card oac-bar"><button type="button" class="btn" data-oac="save">Save</button><button type="button" class="btn ghost" data-oac="reset">Reset to defaults</button>' +
        '<p class="rl-note" data-oac-state>' + (JSON.stringify(st) === saved ? 'Saved. Phones pick changes up on their next sync.' : 'Unsaved changes.') + '</p></div></div>';
    return ctl;
  }

  function paint() {
    var old = frame && panel.querySelector('.oac-ctl');
    if (old) {
      old.outerHTML = controls();
    } else {
      panel.innerHTML = '<div class="oac">' + controls() + pvBox() + '</div>';
      frame = panel.querySelector('[data-oac-frame]');
      frame.addEventListener('load', preview);
      frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="' + esc(D.css) + '"></head><body>' + SPRITE + '<div class="app" id="pv"></div></body></html>';
    }
    // Swatch colours through the CSSOM: no style attribute in the markup.
    Array.prototype.forEach.call(panel.querySelectorAll('[data-acc]'), function (b) { b.style.background = b.getAttribute('data-acc'); });
    preview();
  }

  function pvBox() {
    return '<div class="oac-pv"><div class="oac-pvt" role="group" aria-label="Preview">' +
      '<button type="button" data-pv="store"' + (pv === 'store' ? ' class="on"' : '') + '>My store</button><button type="button" data-pv="orders"' + (pv === 'orders' ? ' class="on"' : '') + '>Orders</button></div>' +
      '<div class="oac-phone"><iframe title="Owner app preview" tabindex="-1" data-oac-frame></iframe></div><p class="rl-note">Live preview · not saved until you press Save</p></div>';
  }

  function dirty() {
    var p = panel && panel.querySelector('[data-oac-state]');
    if (p) p.textContent = JSON.stringify(st) === saved ? 'Saved. Phones pick changes up on their next sync.' : 'Unsaved changes.';
  }

  /* ------------------------------------------------------------ preview */

  var SPRITE = '<svg class="sprite" aria-hidden="true">' + [
    ['chart', 'M4 4v16h16M8 15l3.5-4 3 3L20 7'], ['receipt', 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3'],
    ['box', 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8'], ['grid', 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z'],
    ['refresh', 'M20 11a8 8 0 10-2.3 5.7M20 4v7h-7'], ['bell', 'M6 9a6 6 0 0112 0c0 6 3 8 3 8H3s3-2 3-8M10 21h4'],
    ['alert', 'M12 3l10 18H2zM12 10v5M12 18h.01'], ['clock', 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2'],
    ['chev', 'M9 6l6 6-6 6'], ['check', 'M5 12.5l4.5 4.5L19 7.5'], ['up-r', 'M4 17l6-6 4 4 7-7M15 8h6v6'],
    ['down', 'M6 9l6 6 6-6'], ['search', 'M11 18a7 7 0 100-14 7 7 0 000 14zM20 20l-4-4'], ['filter', 'M4 6h16M7 12h10M10 18h4'], ['stack', 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5'],
  ].map(function (s) { return '<symbol id="i-' + s[0] + '" viewBox="0 0 24 24"><path d="' + s[1] + '"/></symbol>'; }).join('') + '</svg>';
  function ic(n, c) { return '<svg class="i ' + (c || '') + '" aria-hidden="true"><use href="#i-' + n + '"/></svg>'; }

  /* The classes core.js applyUi() puts on the phone's <html>, from the same settings. */
  function classes(u) {
    var on = [];
    if (u.font === 'system') on.push('oa-sys');
    if (u.text !== 'm') on.push('oa-tx-' + u.text);
    if (u.title !== 'm') on.push('oa-tt-' + u.title);
    if (u.figure !== 'm') on.push('oa-fg-' + u.figure);
    if (u.density === 'compact') on.push('oa-compact');
    if (u.corners === 'medium') on.push('oa-rd-m'); else if (u.corners === 'square') on.push('oa-rd-sq');
    if (u.header === 'standard') on.push('oa-hd-std');
    if (!u.functions.fullscreen) on.push('oa-nofs');
    if (!u.functions.sync) on.push('oa-nosync');
    if (!u.functions.contact) on.push('oa-nocontact');
    if (!u.functions.bulk) on.push('oa-nobulk');
    if (hexOk(u.accent) && u.accent.toUpperCase() !== D.defaults.accent) on.push('oa-acc');
    return on;
  }

  function arrange(p) {
    var keys = st.sections.filter(function (k) { return st.sections_off.indexOf(k) === -1; }), out = '';
    var tile = function (k) { return k === 'avg' || k === 'returning'; };
    for (var i = 0; i < keys.length; i++) {
      if (!tile(keys[i])) { out += p[keys[i]]; continue; }
      var pair = tile(keys[i + 1]);
      out += '<div class="tiles' + (pair ? '' : ' solo') + '">' + p[keys[i]] + (pair ? p[keys[++i]] : '') + '</div>';
    }
    return out;
  }

  function av(n, h) { return '<i class="av" data-h="' + h + '" aria-hidden="true">' + esc(n) + '</i>'; }
  function storeMock() {
    var vals = [2140, 3380, 1960, 4020, 2870, 3610, 4280], W = 320, bw = 30, gap = (W - bw * 7) / 6, days = ['Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun', 'Today'];
    var rects = vals.map(function (v, i) {
      var h = Math.max(6, v / 4280 * 104), x = +(i * (bw + gap)).toFixed(1), y = (118 - h).toFixed(1);
      return '<rect x="' + x + '" y="' + y + '" width="' + bw + '" height="' + h.toFixed(1) + '" rx="10" fill="' + (i === 6 ? '#fff' : 'rgba(255,255,255,.28)') + '"/>' + (i === 6 ? '<text x="' + (x + 15) + '" y="' + (110 - h).toFixed(1) + '" text-anchor="middle" font-size="11" font-weight="700" fill="#fff">4,280</text>' : '');
    }).join('');
    var head = '<div class="lt lt-dash"><div class="lt-row"><div><p class="kick">Monday, 5 October</p><h2>' + esc(st.store_name) + '</h2></div><div class="hdr-acts">' +
      '<button type="button" class="ib sync">' + ic('refresh') + '</button>' + (st.screens.notifications ? '<a class="ib">' + ic('bell') + '<i class="dot"></i></a>' : '') +
      '<i class="logo k-sm sm" aria-hidden="true">' + esc(st.initials) + '</i></div></div></div>';
    var body = arrange({
      hero: '<section class="card hero"><div class="hero-top">' + (st.functions.range ? '<button type="button" class="rng">Today' + ic('down', 's') + '</button>' : '<p class="kick">Today</p>') +
        (st.functions.gross_net ? '<div class="seg"><button class="on">Total</button><button>Gross</button><button>Net</button></div>' : '') + '</div>' +
        '<div class="fig"><small>AED</small><b>4,280</b></div><div class="delta-row"><span class="delta">' + ic('up-r') + '18% vs last Monday</span></div>' +
        '<div class="mets"><div><b>23</b><span>Paid orders</span></div><div><b>214</b><span>Product views</span></div><div><b>3.4%</b><span>Conversion</span></div></div>' +
        '<div class="chart"><svg viewBox="0 0 ' + W + ' 132">' + rects + '</svg><div class="axis bars">' + days.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div></div>' +
        '<div class="upd"><span class="live">Live · updated 10:19 AM</span><span>Last 7 days</span></div></section>',
      needs: '<section class="card att"><h4 class="sh">Needs you <span class="muted">2</span></h4><a class="row"><span class="tone t-warn">' + ic('clock') + '</span><div class="rm"><b>4 orders waiting</b><small>Processing, oldest 3 hours</small></div>' + ic('chev', 'chev s') + '</a>' +
        '<a class="row"><span class="tone t-bad">' + ic('alert') + '</span><div class="rm"><b>2 products out of stock</b><small>Hidden from search until restocked</small></div>' + ic('chev', 'chev s') + '</a></section>',
      avg: '<div class="card"><small>Avg. order · October</small><b>AED 186</b></div>',
      returning: '<div class="card"><small>Returning customers</small><b>38%</b></div>',
      top: '<section class="card tp"><h4 class="sh">' + (st.functions.top_period ? 'Top performers<div class="seg sm"><button>7 days</button><button class="on">This month</button></div>' : 'Top performers · October') + '</h4>' + [['Relief Sun SPF50+', 41, 1], ['Snail Mucin Essence', 33, .8], ['Heartleaf Toner', 27, .66]].map(function (t, i) {
        return '<a class="row"><span class="rk">' + (i + 1) + '</span><i class="th blank k-sm" aria-hidden="true"></i><div class="rm"><b>' + t[0] + '</b><small>Net sales AED ' + (t[1] * 62) + '</small><div class="bar"><i data-sx="' + t[2] + '"></i></div></div><div class="re"><b>' + t[1] + '</b><small class="muted">sold</small></div></a>';
      }).join('') + '</section>',
    });
    return '<div class="view single">' + head + '<div class="body">' + body + '</div></div>';
  }
  function ordersMock() {
    var rows = [['1042', 'Layla Hassan', 'AED 312', 'proc', 'Processing', 12, 'LH'], ['1041', 'Omar Siddiqui', 'AED 96', 'pend', 'Pending payment', 200, 'OS'], ['1040', 'Sara Khan', 'AED 455', 'done', 'Completed', 300, 'SK']];
    return '<div class="view single"><header class="top"><div class="tt"><h3>Orders</h3><p>Updated 10:19 AM · 48 orders</p></div><button type="button" class="ib sync plain">' + ic('refresh') + '</button></header>' +
      '<div class="body"><label class="search">' + ic('search', 's') + '<input type="search" placeholder="Search orders, names, products"></label>' +
      '<div class="chips"><button class="on">All<span>48</span></button><button>Processing<span>4</span></button><button>Pending<span>2</span></button><button>' + ic('filter', 's') + ' Filters</button></div>' +
      '<div class="og"><div class="gh"><span>Today</span><button type="button" data-act="selall">Select all</button></div><div class="list">' + rows.map(function (o) {
        return '<div class="row ord"><button type="button" class="sel">' + av(o[6], o[5]) + ic('check') + '</button><a class="rm"><b>#' + o[0] + ' <span>' + o[1] + '</span></b><small>5 Oct · 10:0' + o[0].slice(-1) + ' AM · 2 items</small></a>' +
          '<a class="re"><b>' + o[2] + '</b><span class="pill" data-s="' + o[3] + '">' + o[4] + '</span></a></div>';
      }).join('') + '</div></div></div></div>';
  }

  function preview() {
    var doc = frame && frame.contentDocument, app = doc && doc.getElementById('pv');
    if (!app) return;
    var h = doc.documentElement, want = classes(st);
    h.className = want.join(' ');
    if (want.indexOf('oa-acc') !== -1) h.style.setProperty('--acc', st.accent); else h.style.removeProperty('--acc');
    var tabs = [['', 'My store', 'chart', 'store'], ['orders', 'Orders', 'receipt', 'orders'], ['products', 'Products', 'box', 'products'], ['more', 'More', 'grid', null]].filter(function (t) { return !t[3] || st.screens[t[3]]; });
    app.innerHTML = '<div class="main">' + (pv === 'orders' ? ordersMock() : storeMock()) + '</div><nav class="nav">' + tabs.map(function (t) {
      return '<a class="' + ((pv === 'orders' ? 'orders' : '') === t[0] ? 'on' : '') + '"><em class="nw">' + ic(t[2]) + '</em><span>' + t[1] + '</span></a>';
    }).join('') + '</nav>';
    Array.prototype.forEach.call(app.querySelectorAll('[data-h]'), function (x) { x.style.setProperty('--h', x.getAttribute('data-h')); });
    Array.prototype.forEach.call(app.querySelectorAll('[data-sx]'), function (x) { x.style.transform = 'scaleX(' + x.getAttribute('data-sx') + ')'; });
  }

  /* -------------------------------------------------------------- events */

  function setAccent(h, from) {
    st.accent = h;
    var ok = hexOk(h), r = ok ? contrast(h) : 0, bad = r < D.options.min_contrast, ct = panel.querySelector('[data-ct]');
    if (ct) { ct.className = 'oac-ct' + (bad ? ' bad' : ''); ct.textContent = ok ? 'White text on ' + h.toUpperCase() + ' reads at ' + r.toFixed(1) + ':1' + (bad ? ' — below 4.5:1, so Save refuses it. Choose a darker colour.' : ' ✓') : 'Enter a colour like #A8475C.'; }
    if (from !== 'hex') { var x = panel.querySelector('[data-acc-hex]'); if (x) x.value = h.toUpperCase(); }
    if (from !== 'pick' && ok) { var p = panel.querySelector('[data-acc-pick]'); if (p) p.value = h.toLowerCase(); }
    Array.prototype.forEach.call(panel.querySelectorAll('[data-acc]'), function (b) { b.classList.toggle('on', ok && b.getAttribute('data-acc') === h.toUpperCase()); });
  }

  function move(k, to) {
    var a = st.sections.filter(function (x) { return x !== k; });
    to = Math.max(0, Math.min(a.length, to));
    a.splice(to, 0, k);
    st.sections = a;
    st.sections_off = st.sections.filter(function (x) { return st.sections_off.indexOf(x) !== -1; });
    paint();
  }

  async function save(btn) {
    if (!hexOk(st.accent) || contrast(st.accent) < D.options.min_contrast) { toastMsg('Choose a darker accent: white text must read at 4.5:1 or better.', true); return; }
    btn.disabled = true;
    var r = await api('PUT', st);
    btn.disabled = false;
    if (!r.ok) { toastMsg(why(r), true); return; }
    D = r.data; st = clone(D.ui); saved = JSON.stringify(st);
    toastMsg('Saved — phones pick it up on their next sync');
    paint();
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (bar && bar.contains(t) || panel && panel.contains(t)) {
      var tb = t.closest('[data-oac-tab]');
      if (tb) { tab = tb.getAttribute('data-oac-tab'); show(); return; }
    }
    if (!panel || !st || !panel.contains(t)) return;
    var b;
    if ((b = t.closest('[data-pv]'))) { pv = b.getAttribute('data-pv'); Array.prototype.forEach.call(panel.querySelectorAll('[data-pv]'), function (x) { x.classList.toggle('on', x === b); }); preview(); return; }
    if ((b = t.closest('[data-acc]'))) { setAccent(b.getAttribute('data-acc')); preview(); dirty(); return; }
    if ((b = t.closest('[data-sec-mv]'))) { var k = b.getAttribute('data-sk'); move(k, st.sections.indexOf(k) + +b.getAttribute('data-sec-mv')); dirty(); return; }
    if ((b = t.closest('[data-oac="save"]'))) { save(b); return; }
    if (t.closest('[data-oac="reset"]')) { st = clone(D.defaults); paint(); toastMsg('Defaults filled in — press Save to apply them'); }
  });

  function onInput(e) {
    var t = e.target;
    if (!panel || !st || !panel.contains(t)) return;
    var k = t.getAttribute('data-k');
    if (k) st[k] = k === 'live_seconds' ? parseInt(t.value, 10) || 0 : t.value;
    else if (t.hasAttribute('data-g')) {
      st[t.getAttribute('data-g')][t.getAttribute('data-gk')] = t.checked;
      if (t.getAttribute('data-gk') === 'live') { var ls = panel.querySelector('[data-k="live_seconds"]'); if (ls) ls.disabled = !t.checked; }
    } else if (t.hasAttribute('data-sec-on')) {
      var s = t.getAttribute('data-sec-on');
      st.sections_off = st.sections.filter(function (x) { return x === s ? !t.checked : st.sections_off.indexOf(x) !== -1; });
    } else if (t.hasAttribute('data-acc-pick')) setAccent(t.value.toUpperCase(), 'pick');
    else if (t.hasAttribute('data-acc-hex')) { var v = t.value.trim(); setAccent(v.charAt(0) === '#' ? v : '#' + v, 'hex'); }
    else if (t.hasAttribute('data-sec-pos')) { if (e.type === 'change') move(t.getAttribute('data-sec-pos'), (parseInt(t.value, 10) || 1) - 1); dirty(); return; }
    else return;
    preview();
    dirty();
  }
  document.addEventListener('input', onInput);
  document.addEventListener('change', onInput);
})();
</script>
@endverbatim
