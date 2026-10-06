{{--
    Platform → Users & Roles → Owner app (Lane MAC).

    The third tab of the Users & Roles screen, drawn by admin-roles-screen's own
    tab row: that partial includes this one ONCE, at its end, and calls
    window.kbbOwnerAppAdmin.mount(panel) when the tab is chosen. Nothing here
    touches app.blade.php, the sidebar or the dispatch table.

    What the owner does here: switch the phone app on for a member, give them
    a PIN (6–8 digits; it is hashed on the server and never shown again), see
    and sign out their phones, read the sign-in log, set the idle lock, the
    low-stock line and when the app shows loading bars, and copy — or replace — the app's secret address,
    with a random one (New address) or one he types (Custom address, Lane OA3).

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
.oaa-set .oaa-sw{display:inline-flex;align-items:center;min-height:36px;color:inherit}
.oaa-devs{margin-top:10px}
.oaa-mt{margin:6px 0 0}
.oaa-team{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin:6px 0 0}
.oaa-team .oaa-h{margin:0}
.oaa-team small{font-weight:500;color:var(--ink-2)}
.oaa-add{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:10px 12px}
.oaa-add label{display:grid;gap:5px;font-size:12px;font-weight:600;color:var(--ink-2);min-width:0}
.oaa-add .rl-in{width:100%;box-sizing:border-box}
.oaa-set .rl-in.oaa-host{width:260px;max-width:100%}
.oaa-cust{margin-top:12px;display:grid;gap:6px;min-width:0}
.oaa-cust label{font-size:12px;font-weight:600;color:var(--ink-2)}
.oaa-cust-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;min-width:0}
.oaa-pre{font-family:var(--mono);font-size:12.5px;color:var(--ink-2);overflow-wrap:anywhere;min-width:0}
.oaa-cust-row .rl-in{flex:1 1 200px;min-width:0;max-width:340px;font-family:var(--mono)}
.oaa-err{margin:0;color:#b8362d;font-size:12.5px;font-weight:600}
.oaa-warn{margin:0;color:#8a5a00;font-size:12.5px}
.oaa-warn:empty{display:none}
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
    var r; try { r = await fetch(root() + (path.charAt(0) === '!' ? '/admin-api' + path.slice(1) : '/admin-api/owner-app' + path), o); } catch (e) { return { ok: false, status: 0, data: {} }; }
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
      (D.path_from_env ? '' : '<button type="button" class="btn ghost sm" data-oa="address">New address</button>') + '</div>' + custom() +
      (D.push_ready ? '' : '<p class="rl-note">Push notifications are not available on this server (openssl has no P-256); the app works without them.</p>') + '</div>' +
      '<div class="rl-card"><p class="oaa-h">Settings</p><div class="oaa-set"><label>Lock after (hours unused)<input class="rl-in" type="number" min="1" max="168" data-set="idle_hours" value="' + esc(D.settings.idle_hours) + '"></label>' +
      '<label>Low stock at (units)<input class="rl-in" type="number" min="0" max="999" data-set="low_stock" value="' + esc(D.settings.low_stock) + '"></label>' +
      '<label>Show loading bars after (minutes)<input class="rl-in" type="number" min="5" max="240" data-set="stale_minutes" value="' + esc(D.settings.stale_minutes) + '" aria-describedby="oaa-stale-h"></label>' +
      '<label class="oaa-sw"><input type="checkbox" data-set="ask_push" aria-describedby="oaa-ask-h"' + (D.settings.ask_push ? ' checked' : '') + '> Ask for notifications when the app opens</label><button type="button" class="btn sm" data-oa="settings">Save</button></div>' +
      '<p class="rl-note" id="oaa-stale-h">Opening the app within this many minutes of its last sync refreshes silently; after longer, it shows grey loading bars while it syncs everything.</p>' +
      '<p class="rl-note" id="oaa-ask-h">Ask for notifications: on a phone where the app is installed and notifications are neither allowed nor blocked yet, unlocking it offers “Allow notifications”. “Not now” asks again some days later; a phone that blocked them is never asked.</p></div>' +
      team() + m +
      '<div class="rl-card"><p class="oaa-h">Recent sign-ins</p>' + log + '</div></div>';
    flash = '';
    security();
  }

  /*
   * ADD A PERSON FROM HERE (owner, 6 Oct: "there i can not see the users list,
   * neither i can assign or create new user access ... there should come all
   * users list, and add new too"). Every back-office account is already listed
   * below with its own switch; this adds the missing half. It is the two
   * endpoints that already exist, in order, each with its own capability and
   * its own refusal: POST /admin-api/roles/members (users.manage) makes the
   * account, then PUT /admin-api/owner-app/members/{id} (ownerapp.manage)
   * gives it a PIN and switches the app on. The roles list is fetched once,
   * when the form is first opened, never on load.
   */
  var adding = false, ROLES = null, addVals = {};
  function team() {
    var on = D.members.filter(function (x) { return x.enabled; }).length;
    var head = '<div class="oaa-team"><p class="oaa-h">Team <small>· ' + D.members.length + (D.members.length === 1 ? ' person' : ' people') + ', ' + on + ' on the app</small></p>' +
      (adding ? '' : '<button type="button" class="btn sm" data-oa="add">+ Add person</button>') + '</div>';
    if (!adding) return head + '<p class="rl-note">Everyone with a back-office account is listed here. Tick <b>Owner app access</b> and set a PIN to let them in. Passwords and roles: <b>Platform → Users &amp; Roles</b>.</p>';
    var v = function (k) { return esc(addVals[k] || ''); };
    var roles = ROLES === null ? '<option value="">Loading…</option>' : '<option value="">Choose a role</option>' + ROLES.map(function (r) {
      return '<option value="' + esc(r.id) + '"' + (String(addVals.role_id || '') === String(r.id) ? ' selected' : '') + '>' + esc(r.name) + '</option>';
    }).join('');
    return head + '<div class="rl-card" data-oa-addform><p class="oaa-h">Add a person</p>' +
      '<p class="rl-note">Makes their back-office account and switches the owner app on for them. They sign in on the phone with this email and PIN.</p>' +
      '<div class="oaa-add">' +
      '<label for="oaa-add-name">Name<input id="oaa-add-name" class="rl-in" type="text" maxlength="255" autocomplete="off" data-add="name" value="' + v('name') + '"></label>' +
      '<label for="oaa-add-email">Email<input id="oaa-add-email" class="rl-in" type="email" maxlength="255" autocomplete="off" data-add="email" value="' + v('email') + '"></label>' +
      '<label for="oaa-add-pass">Back-office password<input id="oaa-add-pass" class="rl-in" type="password" minlength="8" maxlength="255" autocomplete="new-password" data-add="password" placeholder="8 characters or more"></label>' +
      '<label for="oaa-add-role">Role<select id="oaa-add-role" class="rl-in" data-add="role_id">' + roles + '</select></label>' +
      '<label for="oaa-add-pin">Owner app PIN<input id="oaa-add-pin" class="rl-in" type="password" inputmode="numeric" maxlength="8" autocomplete="new-password" data-add="pin" placeholder="6–8 digits"></label>' +
      '</div><div class="oaa-row"><button type="button" class="btn sm" data-oa="addsave">Add and switch the app on</button><button type="button" class="btn ghost sm" data-oa="addcancel">Cancel</button></div></div>';
  }
  async function openAdd() {
    adding = true; paint();
    if (ROLES !== null) return;
    var r = await api('GET', '!/roles');
    if (!r.ok) { adding = false; paint(); toastMsg(r.status === 403 ? 'Only someone who manages Users & Roles can add a person.' : why(r), true); return; }
    // Full Admin last; the form opens on "Choose a role", never on a default.
    ROLES = (r.data.roles || []).slice().sort(function (a, b) { return (a.locked ? 1 : 0) - (b.locked ? 1 : 0); });
    if (adding) paint();
  }
  var addBusy = false;
  async function saveAdd() {
    if (addBusy) return;
    var f = {}; host.querySelectorAll('[data-add]').forEach(function (i) { f[i.getAttribute('data-add')] = i.value.trim(); });
    if (!/^\d{6,8}$/.test(f.pin)) { toastMsg('The owner app PIN is 6 to 8 digits.', true); return; }
    if (!f.role_id) { toastMsg('Choose a role.', true); return; }
    addBusy = true;
    try {
      var r = await api('POST', '!/roles/members', { name: f.name, email: f.email, password: f.password, role_id: parseInt(f.role_id, 10) });
      if (!r.ok) { toastMsg(r.status === 403 ? 'Only someone who manages Users & Roles can add a person.' : why(r), true); return; }
      var p = await api('PUT', '/members/' + r.data.id, { enabled: true, pin: f.pin });
      adding = false; addVals = {};
      flash = p.ok ? (f.name || f.email) + ' added, with the owner app on. Send them the link above.'
        : (f.name || f.email) + ' was added, but the app is not on yet: ' + why(p);
      await load();
    } finally { addBusy = false; }
  }

  /*
   * Lane OA3: Users & Roles → Owner app → the address card → Custom address.
   * The owner types the address himself. Every rule is the server's (one
   * request, on Save); the browser only warns about a weak one as it is typed,
   * from the word list the screen already loaded — no request per keystroke.
   */
  var custErr = '', custVal = '';
  function custom() {
    var C = D.custom;
    if (D.path_from_env || !C || !C.full) return '';
    var prefix = String(D.url).replace(/[^\/]+\/$/, '');
    return '<div class="oaa-cust"><label for="oaa-cust-in">Custom address</label>' +
      '<div class="oaa-cust-row"><span class="oaa-pre">' + esc(prefix) + '</span>' +
      '<input id="oaa-cust-in" class="rl-in" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="' + C.max + '" placeholder="e.g. rafi_store-2027" data-cust value="' + esc(custVal) + '" aria-describedby="oaa-cust-h"' + (custErr ? ' aria-invalid="true"' : '') + '>' +
      '<button type="button" class="btn sm" data-oa="custom">Use this address</button></div>' +
      (custErr ? '<p class="oaa-err" role="alert">' + esc(custErr) + '</p>' : '') +
      '<p class="oaa-warn" data-cust-hint role="status">' + esc(weak(custVal)) + '</p>' +
      '<p class="rl-note" id="oaa-cust-h">' + C.min + '–' + C.max + ' characters: lowercase letters, digits, - and _, with at least one underscore. Shop pages and articles can never have an underscore in their address, so nothing you publish later can take over this link. Saving signs every phone out, as New address does.</p></div>';
  }
  function weak(v) {
    var C = D && D.custom; v = String(v || '').trim().toLowerCase().replace(/^\/+|\/+$/g, '');
    if (!C || !v) return '';
    var w = [], words = C.words.slice();
    D.members.forEach(function (m) { String(m.name || '').toLowerCase().split(/[^a-z]+/).forEach(function (t) { if (t.length >= 2) words.push(t); }); });
    if (v.length < C.strong) w.push('it is short — ' + C.strong + ' or more characters is much harder to guess');
    var parts = v.split(/[^a-z]+/).filter(Boolean);
    if (parts.length && parts.every(function (p) { return words.indexOf(p) !== -1; })) w.push('it is made of ordinary words, names and numbers, which scanners try first');
    return w.length ? 'Weak: ' + w.join(', and ') + '.' : '';
  }
  async function saveCustom() {
    var i = host.querySelector('[data-cust]'), v = i ? String(i.value || '').trim().toLowerCase() : '';
    custVal = v;
    var to = String(D.url).replace(/[^\/]+\/$/, '') + v + '/';
    if (v && !window.confirm('Move the owner app to ' + to + ' ?\n\nThe current link stops working at once. Every phone is signed out and its notifications stop; each person signs in again at the new link.')) return;
    var r = await api('POST', '/address', { path: v });
    if (!r.ok) { custErr = why(r); paint(); var j = host.querySelector('[data-cust]'); if (j) j.focus(); return; }
    custErr = ''; custVal = '';
    D = r.data; flash = 'Address changed to ' + D.url + ' — send the new link to your team.' + (D.hint ? ' ' + D.hint : ''); paint();
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
    // The examples name the domain the admin is open on, not a domain written
    // in here (Lane DM): the shop moves domains, the hint goes with it.
    var site = String(location.hostname || 'example.com').replace(/^www\./, '');
    card.innerHTML = '<p class="oaa-h">Security</p><div class="oaa-set">' +
      '<label>Own host (optional)<input class="rl-in oaa-host" type="text" inputmode="url" autocomplete="off" spellcheck="false" maxlength="253" placeholder="owner.' + esc(site) + '" data-sec="host" value="' + esc(S.host) + '"></label>' +
      '<label>Lock-screen notification text<select class="rl-in" data-sec="push_text">' + opts + '</select></label>' +
      '<button type="button" class="btn sm" data-oas="save">Save</button></div>' +
      '<p class="rl-note oaa-mt">Serve the app only from its own subdomain, e.g. owner.' + esc(site) + ' — the strongest isolation; needs the subdomain pointed at this server first. Changing it signs every phone out. Leave it empty to keep the app at the address above.</p>' +
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
    if (a === 'custom') { saveCustom(); return; }
    if (a === 'add') { openAdd(); return; }
    if (a === 'addcancel') { adding = false; addVals = {}; paint(); return; }
    if (a === 'addsave') { saveAdd(); return; }
    if (a === 'address') {
      if (!window.confirm('Make a new secret address? The current link stops working at once and every phone signs in again at the new one.')) return;
      r = await api('POST', '/address');
      if (!r.ok) { toastMsg(why(r), true); return; }
      D = r.data; flash = 'New address made. Send the new link to your team.'; paint(); return;
    }
    if (a === 'settings') {
      var body = {}; host.querySelectorAll('[data-set]').forEach(function (i) { body[i.getAttribute('data-set')] = i.type === 'checkbox' ? i.checked : parseInt(i.value, 10); });
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
  document.addEventListener('input', function (e) {
    // Name, email and role survive a repaint; the password and PIN never sit in a variable.
    if (host && host.contains(e.target) && e.target.matches('[data-add="name"],[data-add="email"],[data-add="role_id"]')) addVals[e.target.getAttribute('data-add')] = e.target.value;
    if (!host || !host.contains(e.target) || !e.target.matches('[data-cust]')) return;
    var h = host.querySelector('[data-cust-hint]'); if (h) h.textContent = weak(e.target.value);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && host && host.contains(e.target) && e.target.matches('[data-cust]')) { e.preventDefault(); saveCustom(); }
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
@include('admin.partials.owner-app-customise')   {{-- Owner app → Customise app (Lane OA4) --}}
