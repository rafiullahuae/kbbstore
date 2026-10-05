
(function () {
  'use strict';

  var D = null, host = null, flash = '';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function when(iso) { return iso ? new Date(iso).toLocaleString([], { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—'; }
  function toastMsg(t, bad) { try { if (typeof window.toast === 'function') window.toast(t, bad ? 'bad' : undefined); } catch (e) {} }

  async function api(method, path, body) {
    var o = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    var r; try { r = await fetch(root() + '/admin-api/owner-app' + path, o); } catch (e) { return { ok: false, status: 0, data: {} }; }
    var d = {}; try { d = await r.json(); } catch (e) { d = {}; }
    return { ok: r.ok && d.ok !== false, status: r.status, data: d };
  }
  function why(r) {
    var d = r.data || {};
    if (r.status === 404 && !d.message) return 'This tab is not in the server’s compiled route table yet. Clear it from Platform → Cache, then reload.';
    if (d.errors) { var k = Object.keys(d.errors)[0]; if (k) return String(d.errors[k][0] || d.errors[k]); }
    return d.message || (r.status === 403 ? 'Only a Full Admin manages the owner app.' : 'The server answered ' + r.status + '.');
  }

  async function load() {
    var r = await api('GET', '');
    if (!r.ok) { host.innerHTML = '<div class="rl-err" role="alert">' + esc(why(r)) + '</div>'; return; }
    D = r.data; paint();
  }

  var REASON = { ok: 'Signed in', bad_pin: 'Wrong PIN', locked: 'Locked out', revoked: 'Device signed out', unknown: 'Unknown email', disabled: 'No access', ip_limited: 'Too many tries', no_device: 'Not enrolled' };

  function paint() {
    if (!host || !D) return;
    var m = D.members.map(function (x) {
      var devs = x.devices.map(function (d) {
        return '<div class="oaa-dev' + (d.revoked_at ? ' off' : '') + '"><div><b>' + esc(d.name) + '</b>' + (d.push ? ' <span class="rl-chip live">Notifications on</span>' : '') +
          '<div>Last used ' + esc(when(d.last_seen_at)) + (d.ip ? ' · ' + esc(d.ip) : '') + (d.revoked_at ? ' · signed out ' + esc(when(d.revoked_at)) : '') + '</div></div>' +
          (d.revoked_at ? '' : '<button type="button" class="btn ghost sm" data-oa="revoke" data-id="' + d.id + '">Sign out</button>') + '</div>';
      }).join('');
      return '<div class="rl-card"><div class="oaa-mem"><div><div class="rl-name">' + esc(x.name || x.email) + '</div><div class="rl-sub">' + esc(x.email) + ' · ' + esc(x.role) + '</div>' +
        '<div class="rl-tags">' + (x.enabled ? '<span class="rl-chip live">App on</span>' : '<span class="rl-chip">App off</span>') +
        (x.has_pin ? '<span class="rl-chip">PIN set ' + esc(when(x.pin_set_at)) + '</span>' : '<span class="rl-chip minus">No PIN</span>') +
        (x.admin_locked ? '<span class="rl-chip minus">Locked — needs a Full Admin</span>' : x.locked_until ? '<span class="rl-chip minus">Locked until ' + esc(when(x.locked_until)) + '</span>' : x.locked ? '<span class="rl-chip minus">Sign-in paused</span>' : '') + '</div></div>' +
        '<label class="oaa-sw"><input type="checkbox" data-oa="enable" data-id="' + x.admin_user_id + '"' + (x.enabled ? ' checked' : '') + '> Owner app access</label></div>' +
        '<div class="oaa-row"><input class="rl-in" type="password" inputmode="numeric" autocomplete="new-password" maxlength="8" placeholder="New PIN (6–8 digits)" data-pin="' + x.admin_user_id + '" aria-label="New PIN for ' + esc(x.name || x.email) + '">' +
        '<button type="button" class="btn sm" data-oa="pin" data-id="' + x.admin_user_id + '">' + (x.has_pin ? 'Change PIN' : 'Set PIN') + '</button>' +
        (x.locked || x.locked_until ? '<button type="button" class="btn ghost sm" data-oa="unlock" data-id="' + x.admin_user_id + '">Unlock now</button>' : '') + '</div>' +
        (devs ? '<div class="oaa-devs">' + devs + '</div>' : '') + '</div>';
    }).join('');

    var log = D.logins.length ? '<table class="oaa-log"><tbody>' + D.logins.map(function (l) {
      return '<tr><td>' + esc(when(l.at)) + '</td><td>' + esc(l.who || '—') + '</td><td>' + esc(l.device || '—') + '</td><td class="' + (l.success ? '' : 'bad') + '">' + esc((l.kind === 'enrol' ? 'New phone · ' : '') + (REASON[l.reason] || l.reason)) + '</td><td>' + esc(l.ip) + '</td></tr>';
    }).join('') + '</tbody></table>' : '<p class="rl-note">No sign-ins yet.</p>';

    host.innerHTML = '<div class="oaa">' + (flash ? '<div class="rl-ok" role="status">' + esc(flash) + '</div>' : '') +
      '<div class="rl-card"><p class="oaa-h">The app’s secret address</p><p class="rl-note">Open it on the phone, sign in with the email of the admin account and the PIN set below. Only people you switch on here can use it. It is never linked from the shop and search engines are told not to index it.</p>' +
      '<div class="oaa-url"><code data-url>' + esc(D.url) + '</code><button type="button" class="btn sm" data-oa="copy">Copy link</button>' +
      (D.path_from_env ? '' : '<button type="button" class="btn ghost sm" data-oa="address">New address</button>') + '</div>' +
      (D.push_ready ? '' : '<p class="rl-note">Push notifications are not available on this server (openssl has no P-256); the app works without them.</p>') + '</div>' +
      '<div class="rl-card"><p class="oaa-h">Settings</p><div class="oaa-set"><label>Lock after (hours unused)<input class="rl-in" type="number" min="1" max="168" data-set="idle_hours" value="' + esc(D.settings.idle_hours) + '"></label>' +
      '<label>Low stock at (units)<input class="rl-in" type="number" min="0" max="999" data-set="low_stock" value="' + esc(D.settings.low_stock) + '"></label>' +
      '<label>Show loading bars after (minutes)<input class="rl-in" type="number" min="5" max="240" data-set="stale_minutes" value="' + esc(D.settings.stale_minutes) + '" aria-describedby="oaa-stale-h"></label><button type="button" class="btn sm" data-oa="settings">Save</button></div>' +
      '<p class="rl-note" id="oaa-stale-h">Opening the app within this many minutes of its last sync refreshes silently; after longer, it shows grey loading bars while it syncs everything.</p></div>' +
      '<p class="oaa-h oaa-mt">Members</p>' + m +
      '<div class="rl-card"><p class="oaa-h">Recent sign-ins</p>' + log + '</div></div>';
    flash = '';
    security();
  }

  /*
   * Lane SEC: Users & Roles → Owner app → Security. Its own card, appended
   * after the rest is painted, with its own data-oas buttons and listener.
   */
  function security() {
    var box = host && host.querySelector('.oaa'), S = D && D.security;
    if (!box || !S) return;
    var card = document.createElement('div');
    card.className = 'rl-card';
    var opts = Object.keys(S.push_text_options || {}).map(function (k) {
      return '<option value="' + esc(k) + '"' + (k === S.push_text ? ' selected' : '') + '>' + esc(S.push_text_options[k]) + '</option>';
    }).join('');
    card.innerHTML = '<p class="oaa-h">Security</p><div class="oaa-set">' +
      '<label>Own host (optional)<input class="rl-in oaa-host" type="text" inputmode="url" autocomplete="off" spellcheck="false" maxlength="253" placeholder="owner.extrabeauty.ae" data-sec="host" value="' + esc(S.host) + '"></label>' +
      '<label>Lock-screen notification text<select class="rl-in" data-sec="push_text">' + opts + '</select></label>' +
      '<button type="button" class="btn sm" data-oas="save">Save</button></div>' +
      '<p class="rl-note oaa-mt">Serve the app only from its own subdomain, e.g. owner.extrabeauty.ae — the strongest isolation; needs the subdomain pointed at this server first. Changing it signs every phone out. Leave it empty to keep the app at the address above.</p>' +
      '<p class="rl-note oaa-mt">Generic notifications say only “New order” or “Low stock” on the lock screen — no customer name, amount or product.</p>';
    box.appendChild(card);
  }

  async function saveSecurity() {
    var body = {};
    host.querySelectorAll('[data-sec]').forEach(function (i) { body[i.getAttribute('data-sec')] = String(i.value || '').trim(); });
    if (body.host !== (D.security.host || '') && !window.confirm(body.host ? 'Serve the owner app only from ' + body.host + '? Every phone is signed out and signs in again there.' : 'Serve the owner app from the shop’s own address again? Every phone is signed out.')) return;
    var r = await api('PUT', '/security', body);
    if (!r.ok) { toastMsg(why(r), true); return; }
    D = r.data; flash = 'Security settings saved.'; paint();
  }

  async function act(b) {
    var a = b.getAttribute('data-oa'), id = b.getAttribute('data-id'), r;
    if (a === 'copy') { try { await navigator.clipboard.writeText(D.url); toastMsg('Link copied'); } catch (e) { toastMsg('Select the address and copy it', true); } return; }
    if (a === 'address') {
      if (!window.confirm('Make a new secret address? The current link stops working at once and every phone signs in again at the new one.')) return;
      r = await api('POST', '/address');
      if (!r.ok) { toastMsg(why(r), true); return; }
      D = r.data; flash = 'New address made. Send the new link to your team.'; paint(); return;
    }
    if (a === 'settings') {
      var body = {}; host.querySelectorAll('[data-set]').forEach(function (i) { body[i.getAttribute('data-set')] = parseInt(i.value, 10); });
      r = await api('PUT', '/settings', body);
      if (!r.ok) { toastMsg(why(r), true); return; }
      flash = 'Settings saved.'; await load(); return;
    }
    if (a === 'revoke') {
      if (!window.confirm('Sign this phone out? It will need the email and PIN again.')) return;
      r = await api('POST', '/devices/' + id + '/revoke');
      if (!r.ok) { toastMsg(why(r), true); return; }
      flash = 'Phone signed out.'; await load(); return;
    }
    if (a === 'pin') {
      var input = host.querySelector('[data-pin="' + id + '"]'), pin = input ? input.value : '';
      if (input) input.value = '';
      r = await api('PUT', '/members/' + id, { pin: pin });
      if (!r.ok) { toastMsg(why(r), true); return; }
      flash = 'PIN saved. Their open sessions were ended; they unlock with the new PIN.'; await load(); return;
    }
    if (a === 'unlock') { r = await api('PUT', '/members/' + id, { unlock: true }); if (!r.ok) { toastMsg(why(r), true); return; } flash = 'Unlocked.'; await load(); }
  }

  async function toggle(i) {
    var r = await api('PUT', '/members/' + i.getAttribute('data-id'), { enabled: i.checked });
    if (!r.ok) { i.checked = !i.checked; toastMsg(why(r), true); return; }
    flash = i.checked ? 'Owner app switched on.' : 'Owner app switched off; their phones are locked out and notifications stop.';
    await load();
  }

  document.addEventListener('click', function (e) {
    if (!host || !host.contains(e.target)) return;
    var b = e.target.closest('button[data-oa]'); if (b) act(b);
    if (e.target.closest('button[data-oas]')) saveSecurity();
  });
  document.addEventListener('change', function (e) {
    if (!host || !host.contains(e.target)) return;
    if (e.target.matches('input[data-oa="enable"]')) toggle(e.target);
  });

  window.kbbOwnerAppAdmin = {
    mount: function (el) { host = el; if (D) paint(); else host.innerHTML = '<div class="rl-list"><div class="rl-skel"></div><div class="rl-skel"></div></div>'; load(); },
  };
})();
