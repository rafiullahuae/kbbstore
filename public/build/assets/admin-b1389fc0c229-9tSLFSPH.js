
(function () {
  if (typeof window.paintPixels !== 'function' || window.paintPixels.__mpx) return;
  var TABS = [['pixels','Pixels'],['meta','Meta'],['google','Google'],['tiktok','TikTok'],['custom','Custom code'],['events','Last events'],['guide','Guide']];
  var WIZ = JSON.parse(document.getElementById('mpx-wizard').textContent || '{}');
  var S = null, CC = null, tab = 'pixels', flash = null;
  var FIELDS = {
    meta_id:['Pixel (dataset) ID','id','e.g. 123456789012345'], ga4_id:['Measurement ID','id','G-XXXXXXXXXX'], tiktok_id:['Pixel ID','id','e.g. C4ABCDEF1234567890'],
    meta_capi_token:['Conversions API access token','secret','EAAG…'], meta_test_code:['Test Events code','text','TEST12345'],
    facebook_domain_verification:['Domain verification (tag or code)','text','<meta name="facebook-domain-verification" …>'],
    ga4_api_secret:['Measurement Protocol API secret','secret','Secret value'], ads_id:['Google Ads conversion ID','text','AW-123456789'],
    ads_label:['Conversion label','text','AbCdEfGh'], ads_ec:['Enhanced conversions (hashed email and phone on the thank-you page)','toggle'],
    google_site_verification:['Site verification (tag or code)','text','<meta name="google-site-verification" …>'],
    consent_mode:['Consent Mode v2','select',[['off','Off (default)'],['eea','EEA, UK and Switzerland: denied by default']]],
    tiktok_token:['Events API access token','secret','Access token'], tiktok_test_code:['Test Events code','text','TEST12345'],
    meta_app_id:['Meta App ID (digits only)','text','e.g. 1234567890123456'], meta_app_secret:['Meta App Secret','secret','App Secret']
  };
  function base(){ return mpBase(); }
  function api(path, body){
    var o = {credentials:'same-origin', headers:{Accept:'application/json'}};
    if (body !== undefined) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.headers['X-XSRF-TOKEN'] = uToken(); o.body = JSON.stringify(body); }
    return fetch(base() + path, o).then(function (r) { return r.json().catch(function(){ return {}; }).then(function (d) { d.__status = r.status; return d; }); });
  }
  /* The route-cache fault (integrator, 2.60.449): a package whose clear_caches
     migration did not run leaves these paths out of the compiled route table,
     and Laravel answers its own {"message": ""}. A controller's own 404 carries
     a message or an error, and that is what gets shown instead. */
  function fault(d){ return d && d.__status === 404 && !d.message && !d.error ? 'The Marketing Pixels endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform \u2192 Cache) and reload.' : ''; }
  function e(s){ return escHtml(s == null ? '' : String(s)); }
  function a(s){ return escAttr(s == null ? '' : String(s)); }
  function val(k){ if (!S) return ''; if (k in S.ids) return S.ids[k]; return S.values[k] == null ? '' : S.values[k]; }

  function field(k){
    var f = FIELDS[k]; if (!f) return '';
    var id = 'mpx-' + k, h = '<div class="mpx-f"><label for="' + id + '">' + e(f[0]) + '</label>';
    if (f[1] === 'toggle') return '<div class="mpx-f"><label><input type="checkbox" id="' + id + '" data-mpxk="' + k + '"' + (val(k) === '1' ? ' checked' : '') + '> ' + e(f[0]) + '</label></div>';
    if (f[1] === 'select') return h + '<select class="inp" id="' + id + '" data-mpxk="' + k + '">' + f[2].map(function (o) { return '<option value="' + a(o[0]) + '"' + (val(k) === o[0] ? ' selected' : '') + '>' + e(o[1]) + '</option>'; }).join('') + '</select></div>';
    if (f[1] === 'secret') {
      var m = S.masked[k] || '';
      return h + '<input type="password" autocomplete="off" class="inp" id="' + id + '" data-mpxk="' + k + '" placeholder="' + a(m ? 'Saved ' + m + ' — paste a new one to replace' : f[2]) + '">'
        + (m ? '<small>Stored encrypted. Only the last four characters are ever shown. <a href="#" data-mpx-clear="' + k + '">Remove</a></small>' : '') + '</div>';
    }
    return h + '<input type="text" class="inp" id="' + id + '" data-mpxk="' + k + '" value="' + a(val(k)) + '" placeholder="' + a(f[2]) + '" maxlength="300"></div>';
  }
  function copyRow(label, url){ return '<div class="mpx-f"><label>' + e(label) + '</label><div class="mpx-copy"><code>' + e(url) + '</code><button class="btn small" type="button" data-mpx-copy="' + a(url) + '">Copy</button></div></div>'; }
  function steps(p){
    return '<ol class="mpx-steps">' + WIZ[p].steps.map(function (s) {
      return '<li><b>' + e(s[0]) + '</b>' + e(s[1]) + (s[2] ? '<br><a class="mpx-open" href="' + a(s[2][1]) + '" target="_blank" rel="noopener noreferrer">' + e(s[2][0]) + ' ↗</a>' : '') + s[3].map(field).join('') + '</li>';
    }).join('') + '</ol>';
  }
  function status(on, label){ return '<span class="mpx-pill ' + (on ? 'on' : 'off') + '">' + e(label) + (on ? ': on' : ': off') + '</span>'; }
  function checks(list){ return '<div class="mpx-row"><button class="btn primary" type="button" data-mpx-save>Save</button>' + list.map(function (c) { return '<button class="btn" type="button" data-mpx-check="' + c[0] + '">' + e(c[1]) + '</button>'; }).join('') + '</div><ul class="mpx-res" id="mpxRes" aria-live="polite"></ul>'; }

  function panel(){
    if (tab === 'guide') return document.getElementById('mpx-guide').innerHTML;
    if (tab === 'custom') return custom();
    if (!S) return '<div class="mpx-card">Loading…</div>';
    if (S.__status && S.__status !== 200) return '<div class="mpx-card">' + (fault(S) ? e(fault(S)) : 'Could not load (' + e(S.__status) + '). ') + (S.__status === 403 ? 'Your role cannot change platform connections.' : '') + '</div>';
    var warn = S.module_on ? '' : '<div class="mpx-warn">The Marketing Pixels module is off, so nothing is sent. Saving an ID switches it on (Store → Modules).</div>';
    var msg = flash ? '<div class="mpx-warn">' + e(flash) + '</div>' : '';
    if (tab === 'events') return events();
    if (tab === 'meta') {
      var pick = (S.meta_oauth.pixels || []).length > 1 ? '<div class="mpx-card"><h3>Pick your pixel</h3>' + S.meta_oauth.pixels.map(function (p) { return '<div class="mpx-row"><button class="btn small" data-mpx-pick="' + a(p.id) + '">Use</button> ' + e(p.name) + ' <code>' + e(p.id) + '</code></div>'; }).join('') + '</div>' : '';
      return warn + msg + pick + '<div class="mpx-card"><h3>' + e(WIZ.meta.title) + '</h3><p class="mpx-muted">' + status(S.server.meta, 'Server events (Conversions API)') + '</p>' + steps('meta') + checks([['meta','Check Meta'],['live','Check live page']]) + '</div>'
        + '<div class="mpx-card"><h3>Catalog feed</h3><p class="mpx-muted">Commerce Manager → Catalog → Data sources → Data feed → Scheduled feed. Its ids match what the pixel sends.</p>' + copyRow('Meta catalog feed', S.feeds.meta) + '</div>'
        + '<div class="mpx-card"><h3>Connect with Facebook (optional, your own app)</h3><p class="mpx-muted">Picks the Pixel ID for you. ' + (S.meta_oauth.source === 'own' ? 'Uses the app below.' : 'Add your Meta app’s ID and secret first.') + ' The token above is still a paste.</p>'
        + field('meta_app_id') + field('meta_app_secret') + copyRow('Add this to Facebook Login for Business → Valid OAuth Redirect URIs', S.meta_oauth.redirect_uri)
        + '<div class="mpx-row"><button class="btn primary" type="button" data-mpx-save>Save app</button><button class="btn" type="button" data-mpx-fb>Connect with Facebook</button></div></div>';
    }
    if (tab === 'google') return warn + msg + '<div class="mpx-card"><h3>' + e(WIZ.google.title) + '</h3><p class="mpx-muted">' + status(S.server.ga4, 'Server purchase (Measurement Protocol)') + '</p>' + steps('google')
      + copyRow('Google Merchant Center feed', S.feeds.google) + checks([['ga4','Check GA4'],['live','Check live page'],['feed','Check feeds']]) + '</div>';
    if (tab === 'tiktok') return warn + msg + '<div class="mpx-card"><h3>' + e(WIZ.tiktok.title) + '</h3><p class="mpx-muted">' + status(S.server.tiktok, 'Server events (Events API)') + '</p>' + steps('tiktok')
      + copyRow('TikTok catalog feed', S.feeds.tiktok) + checks([['tiktok','Check TikTok'],['live','Check live page'],['feed','Check feeds']]) + '</div>';
    return '';
  }
  function events(){
    var rows = (S.events || []).map(function (r) { return '<tr><td>' + e(r.created_at) + '</td><td>' + e(r.platform) + '</td><td>' + e(r.event) + '<div class="mpx-muted mpx-hide">' + e(r.event_id) + '</div></td><td><span class="mpx-pill ' + (r.status === 'sent' ? 'on' : 'off') + '">' + e(r.status) + '</span></td><td>' + e(r.message) + '</td></tr>'; }).join('');
    return '<div class="mpx-card"><h3>Last server events</h3><p class="mpx-muted">Newest first. Sent after the page has gone to the shopper; a slow or refused platform never delays a checkout.</p>'
      + (rows ? '<table class="mpx-tbl"><thead><tr><th>When</th><th>Platform</th><th>Event</th><th>Status</th><th>Platform says</th></tr></thead><tbody>' + rows + '</tbody></table>' : '<p>No server events yet. They start with the first add to cart after a token is saved.</p>')
      + '<div class="mpx-row"><button class="btn" type="button" data-mpx-reload>Refresh</button></div></div>';
  }
  function custom(){
    if (!CC) return '<div class="mpx-card">Loading…</div>';
    if (CC.__status && CC.__status !== 200) return '<div class="mpx-card"><h3>Custom code</h3><p>' + (CC.__status === 403 ? 'Only the shop owner can open this tab: the code runs on every shop page.' : fault(CC) ? e(fault(CC)) : 'Could not load (' + e(CC.__status) + ').') + '</p></div>';
    var box = Object.keys(CC.labels).map(function (k) {
      var s = CC.slots[k];
      var opt = function (map, cur) { return Object.keys(map).map(function (o) { return '<option value="' + a(o) + '"' + (o === cur ? ' selected' : '') + '>' + e(map[o]) + '</option>'; }).join(''); };
      return '<div class="mpx-card mpx-cc" data-cc="' + k + '"><h3>' + e(CC.labels[k]) + '</h3><p class="mpx-muted">' + e(({head:'Printed before </head>.',body:'Printed right after <body>.',footer:'Printed before </body>. The safest place for most tags.'})[k]) + '</p>'
        + '<label><input type="checkbox" data-cco' + (s.on ? ' checked' : '') + '> On</label>'
        + '<div class="mpx-f"><label>Where</label><select class="inp" data-ccw>' + opt(CC.where, s.where) + '</select></div>'
        + '<div class="mpx-f"><label>Load</label><select class="inp" data-ccl>' + opt(CC.load, s.load) + '</select><small>“Immediately” runs as the page opens. The shop still adds async to script files and loads stylesheets without blocking, but heavy code there can slow the page.</small></div>'
        + '<div class="mpx-f" style="max-width:none"><label>Code</label><textarea class="inp" data-ccc spellcheck="false" maxlength="' + CC.max_bytes + '"></textarea><small data-ccn></small></div></div>';
    }).join('');
    var hist = (CC.history || []).map(function (h, i) { return '<tr><td>' + e(h.created_at) + '</td><td>' + e(h.saved_by) + '</td><td>' + e(h.note) + '<div class="mpx-muted">' + e(h.summary) + '</div></td><td>' + (i === 0 ? '<span class="mpx-pill on">live</span>' : '<button class="btn small" data-cc-restore="' + h.id + '">Restore</button>') + '</td></tr>'; }).join('');
    return '<div class="mpx-warn">Code here runs on your shop for every visitor. Paste only code from a vendor you trust. Off or empty prints nothing at all. Up to 20 KB per box.</div><div id="ccWarn"></div>' + box
      + '<div class="mpx-row"><button class="btn primary" type="button" data-cc-save>Save custom code</button></div>'
      + '<div class="mpx-card"><h3>Versions</h3><p class="mpx-muted">Every save is kept, with who saved it. Restore puts a version back.</p>' + (hist ? '<table class="mpx-tbl"><tbody>' + hist + '</tbody></table>' : '<p>No versions yet.</p>') + '</div>';
  }

  function draw(){
    var wrap = document.querySelector('#content .wrap'); if (!wrap) return;
    var strip = wrap.querySelector('.mpx-tabs');
    if (!strip) {
      strip = document.createElement('div'); strip.className = 'mpx-tabs'; strip.setAttribute('role', 'tablist');
      strip.innerHTML = TABS.map(function (t) { return '<button type="button" role="tab" data-mpx-tab="' + t[0] + '">' + e(t[1]) + '</button>'; }).join('');
      var head = wrap.querySelector('.echd'); head ? head.after(strip) : wrap.prepend(strip);
      var p = document.createElement('div'); p.id = 'mpxPanel'; strip.after(p);
    }
    strip.querySelectorAll('[data-mpx-tab]').forEach(function (b) { b.setAttribute('aria-selected', String(b.getAttribute('data-mpx-tab') === tab)); });
    // style.display, not the hidden attribute: the Save bar's own display:flex outranks [hidden].
    Array.prototype.forEach.call(wrap.children, function (c) { if (c !== strip && c.id !== 'mpxPanel' && !c.classList.contains('echd')) c.style.display = tab === 'pixels' ? '' : 'none'; });
    var pn = document.getElementById('mpxPanel'); pn.style.display = tab === 'pixels' ? 'none' : ''; pn.innerHTML = tab === 'pixels' ? '' : panel();
    if (tab === 'custom' && CC && CC.slots) pn.querySelectorAll('[data-cc]').forEach(function (b) { var t = b.querySelector('[data-ccc]'); t.value = CC.slots[b.getAttribute('data-cc')].code; count(b); });
  }
  function count(b){ var t = b.querySelector('[data-ccc]'), n = new Blob([t.value]).size; b.querySelector('[data-ccn]').textContent = (n / 1024).toFixed(1) + ' KB of 20 KB' + (/document\s*\.\s*write/i.test(t.value) ? ' — uses document.write, which will not work after the page loads.' : ''); }
  function load(){ return api('/connect').then(function (d) { S = d; draw(); }); }
  function show(t){ tab = t; draw(); if (t === 'custom' && !CC) api('/custom-code').then(function (d) { CC = d; draw(); }); else if (t !== 'pixels' && t !== 'guide' && t !== 'custom' && !S) load(); }
  function collect(){
    var out = {values:{}, ids:{}};
    document.querySelectorAll('#mpxPanel [data-mpxk]').forEach(function (el) {
      var k = el.getAttribute('data-mpxk'), v = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value.trim();
      if (FIELDS[k][1] === 'secret' && v === '') return;
      (FIELDS[k][1] === 'id' ? out.ids : out.values)[k] = v;
    });
    return out;
  }
  function result(d){
    var ul = document.getElementById('mpxRes'); if (!ul) return;
    if (!d.steps) { ul.innerHTML = '<li class="bad"><b>Could not run the check</b>' + e(fault(d) || d.error || ('HTTP ' + d.__status)) + '</li>'; return; }
    ul.innerHTML = d.steps.map(function (s) { return '<li class="' + (s.ok === true ? 'ok' : s.ok === false ? 'bad' : '') + '"><b>' + (s.ok === true ? '✓ ' : s.ok === false ? '✕ ' : '• ') + e(s.label) + '</b>' + e(s.message) + '</li>'; }).join('');
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-mpx-tab],[data-mpx-save],[data-mpx-check],[data-mpx-copy],[data-mpx-clear],[data-mpx-fb],[data-mpx-pick],[data-mpx-reload],[data-cc-save],[data-cc-restore],[data-mpx-jump]');
    if (!t || !t.closest('#content')) return;
    if (t.hasAttribute('data-mpx-tab')) return show(t.getAttribute('data-mpx-tab'));
    if (t.hasAttribute('data-mpx-jump')) { ev.preventDefault(); var g = document.getElementById('mpx-g-' + t.getAttribute('data-mpx-jump')); if (g) g.scrollIntoView({behavior:'smooth'}); return; }
    if (t.hasAttribute('data-mpx-copy')) { navigator.clipboard && navigator.clipboard.writeText(t.getAttribute('data-mpx-copy')).then(function () { toast('Copied'); }); return; }
    if (t.hasAttribute('data-mpx-reload')) return api('/events').then(function (d) { S.events = d.events || []; draw(); });
    if (t.hasAttribute('data-mpx-save') || t.hasAttribute('data-mpx-clear')) {
      ev.preventDefault();
      var body = collect(); if (t.hasAttribute('data-mpx-clear')) body = {values:{}, ids:{}, clear:[t.getAttribute('data-mpx-clear')]};
      t.disabled = true;
      return api('/connect', body).then(function (d) { t.disabled = false; if (!d.ok) return toast(fault(d) || 'Could not save: ' + (d.error || d.__status), 'bad'); toast('Saved'); flash = null; return load(); });
    }
    if (t.hasAttribute('data-mpx-check')) { t.disabled = true; var ul = document.getElementById('mpxRes'); if (ul) ul.innerHTML = '<li>Checking…</li>'; return api('/check/' + t.getAttribute('data-mpx-check'), {}).then(function (d) { t.disabled = false; result(d); }); }
    if (t.hasAttribute('data-mpx-fb')) return api('/meta/start', {}).then(function (d) {
      if (d.ok && /^https:\/\/www\.facebook\.com\//.test(d.url)) location.href = d.url;
      else toast(fault(d) || d.error || 'Could not start', 'bad');
    });
    if (t.hasAttribute('data-mpx-pick')) return api('/meta/pick', {pixel_id: t.getAttribute('data-mpx-pick')}).then(function (d) { if (!d.ok) return toast(d.error, 'bad'); toast('Pixel connected'); load(); if (window.renderPixels) window.renderPixels(); });
    if (t.hasAttribute('data-cc-save')) {
      var slots = {}; document.querySelectorAll('#mpxPanel [data-cc]').forEach(function (b) { slots[b.getAttribute('data-cc')] = {on: b.querySelector('[data-cco]').checked, where: b.querySelector('[data-ccw]').value, load: b.querySelector('[data-ccl]').value, code: b.querySelector('[data-ccc]').value}; });
      t.disabled = true;
      return api('/custom-code', {slots: slots}).then(function (d) { t.disabled = false; if (!d.ok) return toast(fault(d) || d.error || ('Could not save: ' + d.__status), 'bad'); CC.slots = slots; CC.history = d.history; draw(); var w = Object.values(d.warnings || {}); document.getElementById('ccWarn').innerHTML = w.map(function (x) { return '<div class="mpx-warn">' + e(x) + '</div>'; }).join(''); toast('Custom code saved'); });
    }
    if (t.hasAttribute('data-cc-restore')) { if (!confirm('Put this version back on the shop?')) return; return api('/custom-code/restore/' + t.getAttribute('data-cc-restore'), {}).then(function (d) { if (!d.ok) return toast(d.error, 'bad'); CC.slots = d.slots; CC.history = d.history; draw(); toast('Version restored'); }); }
  });
  document.addEventListener('input', function (ev) { var b = ev.target.closest && ev.target.closest('#mpxPanel [data-cc]'); if (b) count(b); });

  var original = window.paintPixels;
  var wrapped = function () { var r = original.apply(this, arguments); draw(); return r; };
  wrapped.__mpx = true;
  window.paintPixels = wrapped;

  // Back from Facebook: open the Meta tab and say what happened, then tidy the address.
  var q = new URLSearchParams(location.search), back = q.get('mp_meta');
  if (back) {
    // The error's wording comes from the server's one-shot message, never from this address.
    flash = back === 'error' ? 'Facebook did not connect.' : back === 'pick' ? 'Connected. Pick the pixel this shop should use.' : back === 'none' ? 'Connected, but this Facebook user has no pixels on its ad accounts.' : null;
    if (back === 'connected') setTimeout(function () { toast('Pixel connected from Facebook'); }, 300);
    tab = 'meta'; history.replaceState(null, '', location.pathname + location.hash);
    load().then(function () { if (back === 'error' && S && S.meta_oauth && S.meta_oauth.message) { flash = S.meta_oauth.message; draw(); } });
  }
  if (document.querySelector('#content [data-mp]')) draw();
})();
