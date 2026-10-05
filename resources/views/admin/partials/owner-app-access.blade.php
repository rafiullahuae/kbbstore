{{--
    Platform → Users & Roles → Owner app (Lane MAC).

    The third tab of the Users & Roles screen, drawn by admin-roles-screen's own
    tab row: that partial includes this one ONCE, at its end, and calls
    window.kbbOwnerAppAdmin.mount(panel) when the tab is chosen. Nothing here
    touches app.blade.php, the sidebar or the dispatch table.

    What the owner does here: switch the phone app on for a member, give them
    a PIN (4–8 digits; it is hashed on the server and never shown again), see
    and sign out their phones, read the sign-in log, set the idle lock and the
    low-stock line, and copy — or replace — the app's secret address.

    SAFETY. Every server string goes through esc() before innerHTML. Writes carry
    X-XSRF-TOKEN like the rest of the console. The PIN field is cleared the
    moment it is sent. NO LAYOUT MEASURING.
--}}
@verbatim
<style>
.oaa{display:grid;gap:12px;min-width:0}
.oaa-url{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.oaa-url code{font-family:var(--mono);font-size:12.5px;background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:8px 10px;overflow-wrap:anywhere;flex:1;min-width:0}
.oaa-h{font-size:14px;font-weight:700;margin:0 0 4px}
.oaa-mem{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px 14px;align-items:start}
.oaa-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}
.oaa-row .rl-in{width:180px}
.oaa-sw{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer}
.oaa-sw input{width:18px;height:18px;accent-color:var(--accent)}
.oaa-dev{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:8px 0;border-top:1px solid var(--border-2);font-size:12.5px;color:var(--ink-2)}
.oaa-dev.off{opacity:.55}
.oaa-dev b{color:var(--ink);font-size:13px}
.oaa-log{width:100%;border-collapse:collapse;font-size:12.5px}
.oaa-log td{padding:6px 8px;border-top:1px solid var(--border-2);vertical-align:top;overflow-wrap:anywhere}
.oaa-log .bad{color:#b8362d;font-weight:600}
.oaa-set{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.oaa-set label{display:grid;gap:5px;font-size:12px;font-weight:600;color:var(--ink-2)}
.oaa-set .rl-in{width:120px}
.oaa-devs{margin-top:10px}
.oaa-mt{margin:6px 0 0}
@media (max-width:760px){.oaa-mem{grid-template-columns:minmax(0,1fr)}}
</style>
<script>
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
        (x.locked_until ? '<span class="rl-chip minus">Locked until ' + esc(when(x.locked_until)) + '</span>' : '') + '</div></div>' +
        '<label class="oaa-sw"><input type="checkbox" data-oa="enable" data-id="' + x.admin_user_id + '"' + (x.enabled ? ' checked' : '') + '> Owner app access</label></div>' +
        '<div class="oaa-row"><input class="rl-in" type="password" inputmode="numeric" autocomplete="new-password" maxlength="8" placeholder="New PIN (4–8 digits)" data-pin="' + x.admin_user_id + '" aria-label="New PIN for ' + esc(x.name || x.email) + '">' +
        '<button type="button" class="btn sm" data-oa="pin" data-id="' + x.admin_user_id + '">' + (x.has_pin ? 'Change PIN' : 'Set PIN') + '</button>' +
        (x.locked_until ? '<button type="button" class="btn ghost sm" data-oa="unlock" data-id="' + x.admin_user_id + '">Unlock now</button>' : '') + '</div>' +
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
      '<label>Low stock at (units)<input class="rl-in" type="number" min="0" max="999" data-set="low_stock" value="' + esc(D.settings.low_stock) + '"></label><button type="button" class="btn sm" data-oa="settings">Save</button></div></div>' +
      '<p class="oaa-h oaa-mt">Members</p>' + m +
      '<div class="rl-card"><p class="oaa-h">Recent sign-ins</p>' + log + '</div></div>';
    flash = '';
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
  });
  document.addEventListener('change', function (e) {
    if (!host || !host.contains(e.target)) return;
    if (e.target.matches('input[data-oa="enable"]')) toggle(e.target);
  });

  window.kbbOwnerAppAdmin = {
    mount: function (el) { host = el; if (D) paint(); else host.innerHTML = '<div class="rl-list"><div class="rl-skel"></div><div class="rl-skel"></div></div>'; load(); },
  };
})();
</script>
@endverbatim
