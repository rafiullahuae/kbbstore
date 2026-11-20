
(function () {
  'use strict';
  var SCREEN = 'adsautopilot';
  var BASE = window.location.pathname.replace(/\/+$/, '');
  var st = null, note = null, busy = false, fresh = null;
  var costs = null, cq = '', cmissing = false, cpage = 1, cdirty = {};

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var opts = { headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api/ads-autopilot' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    return { status: r.status, body: payload || {} };
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fail(status, body) {
    if (status === 404 && !(body && body.message)) return 'This screen\'s endpoints are not in the server\'s route table yet. Go to Platform → Cache, press Clear everything, and reload.';
    if (status === 403) return 'Only the owner (Full Admin) can open Ads Autopilot.';
    if (status === 419) return 'Your session expired. Reload the page and sign in again.';
    return (body && body.message) || 'Something went wrong (' + status + '). Nothing was changed.';
  }

  function when(v) {
    if (!v) return '—';
    var d = new Date(String(v).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(String(v)) ? '' : 'Z'));
    return isNaN(d.getTime()) ? String(v) : d.toLocaleString();
  }

  function toggle(key, on) {
    return '<span class="aap-tg" role="group"><button type="button" data-aap-m="' + key + '" data-v="1" class="' + (on ? 'on' : '') + '"' + (busy ? ' disabled' : '') + '>On</button>'
      + '<button type="button" data-aap-m="' + key + '" data-v="0" class="' + (on ? '' : 'on off') + '"' + (busy ? ' disabled' : '') + '>Off</button></span>';
  }

  function keysCard() {
    var html = '<div class="aap-card"><h3>Connection keys</h3><p>A key lets the KBB Ads Autopilot app on your Mac read this shop\'s orders, refunds and products — read only, never customer names, emails, phones or addresses. Paste it in the app: <b>Settings → Connections → Shop</b>.</p>';
    if (fresh) {
      html += '<div class="aap-new" role="status"><b>Your new key — copy it now. It is shown only this once.</b><code data-aap-token>' + esc(fresh) + '</code>'
        + '<button type="button" class="aap-btn" data-copy="' + esc(fresh) + '">Copy key</button> <button type="button" class="aap-btn" data-aap-hide>I have copied it</button></div>';
    }
    html += '<div class="aap-row"><input type="text" maxlength="80" placeholder="Name, e.g. Office MacBook" data-aap-name aria-label="Key name"></div><div class="aap-row">';
    Object.keys(st.scopes).forEach(function (k) {
      html += '<label class="aap-chk"><input type="checkbox" data-aap-scope value="' + esc(k) + '" checked> ' + esc(st.scopes[k]) + '</label>';
    });
    html += '<button type="button" class="aap-btn pri" data-aap-create' + (busy ? ' disabled' : '') + '>Create connection key</button></div>';
    if (!st.keys.length) return html + '<p>No keys yet.</p></div>';
    html += '<table class="aap-tbl"><thead><tr><th>Name</th><th>Key</th><th>Reads</th><th>Last used</th><th>Created</th><th></th></tr></thead><tbody>';
    st.keys.forEach(function (k) {
      html += '<tr><td data-l="Name">' + esc(k.name) + '</td><td data-l="Key"><code>' + esc(k.hint) + '</code></td><td data-l="Reads">' + esc(k.scopes.join(', ')) + '</td>'
        + '<td data-l="Last used">' + esc(when(k.last_used_at)) + '</td><td data-l="Created">' + esc(when(k.created_at)) + '</td><td>'
        + (k.revoked_at ? '<span class="aap-pill no">Revoked</span>' : '<button type="button" class="aap-btn bad" data-aap-revoke="' + esc(k.id) + '"' + (busy ? ' disabled' : '') + '>Revoke</button>') + '</td></tr>';
    });
    return html + '</tbody></table></div>';
  }

  function urlCard() {
    return '<div class="aap-card aap-url"><h3>API address</h3><p>The app finds this by itself for kbeautybliss.com. For reference:</p><code>' + esc(st.api_url) + '</code>'
      + '<p>Endpoints: <code style="display:inline;padding:2px 6px">/orders</code> <code style="display:inline;padding:2px 6px">/orders/daily</code> <code style="display:inline;padding:2px 6px">/refunds</code> <code style="display:inline;padding:2px 6px">/products</code> <code style="display:inline;padding:2px 6px">/ping</code>, with the key as <code style="display:inline;padding:2px 6px">Authorization: Bearer …</code>.</p></div>';
  }

  function measureCard() {
    var m = st.measurement, opts = '';
    m.graph_versions.forEach(function (v) { opts += '<option value="' + esc(v) + '"' + (v === m.graph_version ? ' selected' : '') + '>' + esc(v) + (v === m.graph_default ? ' (default)' : '') + '</option>'; });
    return '<div class="aap-card"><h3>Measurement (Meta)</h3><p>Meta server events (Conversions API): <span class="aap-pill ' + (m.meta_server_events ? 'ok">On' : 'no">Off — set them up in Marketing Pixels') + '</span>'
      + (m.retry_queue.waiting ? ' · <b>' + esc(m.retry_queue.waiting) + '</b> event(s) waiting to be re-sent' : '') + '</p>'
      + '<div class="aap-sw"><div><b>Tell Meta about refunds and cancellations</b><small>Sends <code>OrderRefunded</code> and <code>OrderCancelled</code> as custom events for orders Meta counted. Meta does not lower its Purchase numbers for them; the Autopilot uses the shop\'s own net revenue.</small></div>' + toggle('refund_events', m.refund_events) + '</div>'
      + '<div class="aap-sw"><div><b>Retry events that failed</b><small>A Purchase, refund or cancellation that could not reach Meta is tried again (5 min to 12 h later), never after 47 hours, so it is never counted twice.</small></div>' + toggle('capi_retry', m.capi_retry) + '</div>'
      + '<div class="aap-sw"><div><b>Count “On hold” orders as a purchase</b><small>On: Meta hears of the sale when an order is put On hold. Off: when it moves on to Processing, Shipped or Completed. Cash on delivery orders go straight to Processing, so this mostly affects orders you hold by hand.</small></div>' + toggle('purchase_onhold', m.purchase_onhold) + '</div>'
      + '<div class="aap-sw"><div><b>Graph API version</b><small>The Meta API version the shop calls. v27.0 was checked against Meta\'s changelogs: nothing the shop uses changed.</small></div><select data-aap-version aria-label="Graph API version"' + (busy ? ' disabled' : '') + '>' + opts + '</select></div>'
      + '<p><b>Consent:</b> the shop has no cookie or consent banner, so there is no consent choice to apply to server events. Adding one is a separate decision.</p></div>';
  }

  function callsCard() {
    if (!st.calls.length) return '<div class="aap-card"><h3>Recent calls</h3><p>The app has not called yet.</p></div>';
    var html = '<div class="aap-card"><h3>Recent calls</h3><table class="aap-tbl"><thead><tr><th>When</th><th>Key</th><th>Path</th><th>Answer</th><th>Rows</th></tr></thead><tbody>';
    st.calls.forEach(function (c) {
      html += '<tr><td data-l="When">' + esc(when(c.at)) + '</td><td data-l="Key">' + esc(c.key || '— (no valid key)') + '</td><td data-l="Path">' + esc(c.path) + '</td><td data-l="Answer"><span class="aap-pill ' + (c.status < 300 ? 'ok' : 'no') + '">' + esc(c.status) + '</span></td><td data-l="Rows">' + esc(c.rows) + '</td></tr>';
    });
    return html + '</tbody></table></div>';
  }

  function costsCard() {
    var html = '<div class="aap-card"><h3>Cost prices</h3><p>What you pay for each product, in AED. Optional: the Autopilot uses it to judge ads on profit, not only sales. Never shown on the shop.</p>'
      + '<div class="aap-row"><input type="text" placeholder="Search name or SKU" data-aap-cq value="' + esc(cq) + '" aria-label="Search products"><label class="aap-chk"><input type="checkbox" data-aap-cmissing' + (cmissing ? ' checked' : '') + '> Only without a cost</label><button type="button" class="aap-btn" data-aap-csearch>Search</button></div>';
    if (!costs) return html + '<p>Loading…</p></div>';
    html += '<p>' + esc(costs.with_cost) + ' product(s) have a cost price.</p>';
    if (!costs.rows.length) return html + '<p>No products match.</p></div>';
    html += '<table class="aap-tbl"><thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Cost (AED)</th></tr></thead><tbody>';
    costs.rows.forEach(function (r) {
      var v = cdirty[r.id] !== undefined ? cdirty[r.id] : (r.cost == null ? '' : r.cost);
      html += '<tr><td data-l="Product">' + esc(r.name) + '</td><td data-l="SKU">' + esc(r.sku || '—') + '</td><td data-l="Price">' + esc(r.sale_price != null ? r.sale_price + ' (sale; ' + r.price + ')' : (r.price == null ? '—' : r.price)) + '</td>'
        + '<td data-l="Cost (AED)"><input inputmode="decimal" data-aap-cost="' + esc(r.id) + '" value="' + esc(v) + '" aria-label="Cost price for ' + esc(r.name) + '"></td></tr>';
    });
    var pages = Math.max(1, Math.ceil(costs.total / costs.per_page));
    html += '</tbody></table><div class="aap-pager"><button type="button" class="aap-btn pri" data-aap-csave' + (busy || !Object.keys(cdirty).length ? ' disabled' : '') + '>Save cost prices</button>'
      + '<button type="button" class="aap-btn" data-aap-cpage="' + (cpage - 1) + '"' + (cpage <= 1 ? ' disabled' : '') + '>‹</button><span>Page ' + esc(cpage) + ' of ' + esc(pages) + '</span>'
      + '<button type="button" class="aap-btn" data-aap-cpage="' + (cpage + 1) + '"' + (cpage >= pages ? ' disabled' : '') + '>›</button></div>';
    return html + '</div>';
  }

  function render() {
    var host = document.querySelector('[data-aap]');
    if (!host) return;
    var html = '<div class="aap-head"><h2>Ads Autopilot</h2><p>Connect the KBB Ads Autopilot app on your Mac to this shop, so it judges Meta ads on the shop\'s real revenue — after refunds, cancellations and cost — instead of Meta\'s own numbers.</p></div>';
    if (note) html += '<div class="aap-msg' + (note.ok ? '' : ' is-bad') + '" role="status">' + esc(note.text) + '</div>';
    if (!st) { host.innerHTML = html + '<div class="aap-card"><p>Loading…</p></div>'; return; }
    host.innerHTML = html + keysCard() + measureCard() + costsCard() + callsCard() + urlCard();
  }

  function take(r, okText) {
    busy = false;
    if (r.status === 200 && r.body.ok) {
      if (r.body.keys) st = r.body;
      note = okText ? { ok: true, text: okText } : null;
      return true;
    }
    note = { ok: false, text: fail(r.status, r.body) };
    return false;
  }

  async function load() {
    var r = await api('');
    if (!take(r, null)) { st = null; render(); return; }
    render();
    loadCosts();
  }

  async function loadCosts() {
    var r = await api('/costs?page=' + cpage + '&q=' + encodeURIComponent(cq) + (cmissing ? '&missing=1' : ''));
    if (r.status === 200 && r.body.ok) costs = r.body; else note = { ok: false, text: fail(r.status, r.body) };
    render();
  }

  async function create(host) {
    var name = (host.querySelector('[data-aap-name]') || {}).value || '';
    var scopes = Array.prototype.map.call(host.querySelectorAll('[data-aap-scope]:checked'), function (c) { return c.value; });
    busy = true; render();
    var r = await api('/keys', { name: name, scopes: scopes });
    if (take(r, 'Key created. Copy it now — it will not be shown again.')) fresh = r.body.token;
    render();
  }

  async function revoke(id) {
    if (!window.confirm('Revoke this key? The app using it stops reading the shop at once.')) return;
    busy = true; render();
    var r = await api('/keys/' + encodeURIComponent(id) + '/revoke', {});
    take(r, 'Key revoked.'); render();
  }

  async function measure(body) {
    busy = true; render();
    var r = await api('/measurement', body);
    take(r, 'Saved.'); render();
  }

  async function saveCosts() {
    var rows = Object.keys(cdirty).map(function (id) { return { id: +id, cost: cdirty[id] }; });
    if (!rows.length) return;
    busy = true; render();
    var r = await api('/costs', { rows: rows });
    busy = false;
    if (r.status === 200 && r.body.ok) { cdirty = {}; note = { ok: true, text: 'Saved ' + r.body.saved + ' cost price(s).' }; loadCosts(); }
    else { note = { ok: false, text: fail(r.status, r.body) }; render(); }
  }

  function copyText(value, button) {
    var done = function () { button.textContent = 'Copied ✓'; };
    var fallback = function () {
      var ta = document.createElement('textarea');
      ta.value = value; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      if (ok) done(); else button.textContent = 'Select & copy';
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done, fallback);
    else fallback();
  }

  document.addEventListener('click', function (e) {
    var t = e.target, host = t && t.closest ? t.closest('[data-aap]') : null;
    if (!host) return;
    var b;
    if ((b = t.closest('[data-copy]'))) { copyText(b.getAttribute('data-copy'), b); return; }
    if (t.closest('[data-aap-hide]')) { fresh = null; note = null; render(); return; }
    if ((b = t.closest('[data-aap-create]')) && !b.disabled) { create(host); return; }
    if ((b = t.closest('[data-aap-revoke]')) && !b.disabled) { revoke(b.getAttribute('data-aap-revoke')); return; }
    if ((b = t.closest('[data-aap-m]')) && !b.disabled) { var o = {}; o[b.getAttribute('data-aap-m')] = b.getAttribute('data-v') === '1'; measure(o); return; }
    if (t.closest('[data-aap-csearch]')) { cq = (host.querySelector('[data-aap-cq]') || {}).value || ''; cmissing = !!(host.querySelector('[data-aap-cmissing]') || {}).checked; cpage = 1; cdirty = {}; loadCosts(); return; }
    if ((b = t.closest('[data-aap-cpage]')) && !b.disabled) { cpage = Math.max(1, +b.getAttribute('data-aap-cpage')); cdirty = {}; loadCosts(); return; }
    if ((b = t.closest('[data-aap-csave]')) && !b.disabled) { saveCosts(); }
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-aap]')) return;
    if (t.matches('[data-aap-version]')) measure({ graph_version: t.value });
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.matches || !t.matches('[data-aap-cost]')) return;
    cdirty[t.getAttribute('data-aap-cost')] = t.value.trim();
    var s = document.querySelector('[data-aap-csave]');
    if (s && !busy) s.disabled = false;
  });

  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Ads Autopilot',
      icon: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/><path d="M4.5 4.5 7 7M19.5 4.5 17 7"/>',
      group: 'Growth & Marketing',
      after: ['pixels']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Growth & Marketing"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = 'Growth & Marketing';
    if (title) title.textContent = 'Ads Autopilot';
    if (side) side.classList.remove('open');
    var host = document.getElementById('content');
    if (!host) return undefined;
    host.innerHTML = '<div class="wrap aap" data-aap></div>';
    st = null; note = null; busy = false; fresh = null; costs = null; cdirty = {}; cpage = 1;
    render();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
