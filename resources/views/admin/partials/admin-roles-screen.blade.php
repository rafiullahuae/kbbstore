{{--
    Platform → Users & Roles — Members · Roles. Lane RL, plan row 53.

    The owner: "pre defined roles like Store Manager, SEO Manager, Sub Admin,
    Full admin, Inventory Manager etc. with full control of making edits to
    those roles or create a custom new role for any of our member by using the
    default roles and further edit for that specific person and a custom name
    to that role."

    ── WIRING ────────────────────────────────────────────────────────────────
    Pulled into app.blade.php ONCE, after the live-wiring block that defines
    the old window.renderUsers (any line among the other partial includes is
    after it). It REBINDS window.renderUsers — the mechanism page-editor-screen
    uses for renderUserPages — so go('users') and the existing sidebar row,
    TITLES entry and dispatch entry all reach this screen with no other edit,
    and it draws itself when the console was opened at ?go=users (`cur`).
    No kbbAddNavEntry: the row is NAV's already, and two rows for one screen is
    what AdminNavAndIdsTest refuses.

    ── SAFETY ────────────────────────────────────────────────────────────────
    Every string from the server goes through esc() before innerHTML — names,
    titles and descriptions are typed by people. Writes carry X-XSRF-TOKEN from
    the XSRF-TOKEN cookie, as the console's own api() does. The server is the
    authority on every refusal; the disabled ticks here only save a round trip.

    ── NO LAYOUT MEASURING ───────────────────────────────────────────────────
    Two columns collapse to one with a media query; the tab row scrolls inside
    itself at 390. Nothing here asks an element for its size or position.
--}}
@verbatim
<style>
.rl{min-width:0}
.rl *{box-sizing:border-box}
.rl-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.rl-head h2{font-size:20px;font-weight:700;letter-spacing:-.015em;margin:0}
.rl-head p{font-size:13px;color:var(--ink-soft);margin:3px 0 0;max-width:640px}
.rl-tabs{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin:0 0 16px;padding:1px;max-width:100%}
.rl-tabs::-webkit-scrollbar{display:none}
.rl-tab{flex:0 0 auto;font:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:99px;border:1px solid var(--border);background:var(--surface);color:var(--ink-2);cursor:pointer;white-space:nowrap}
.rl-tab.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.rl-tab:focus-visible,.rl button:focus-visible,.rl input:focus-visible,.rl select:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.rl-tab .ct{opacity:.75;font-weight:600}
.rl-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh-s);padding:16px;min-width:0}
.rl-note{font-size:12.5px;color:var(--ink-soft);line-height:1.6}
.rl-err{background:var(--red-soft);color:#b8362d;border-radius:12px;padding:12px 14px;font-size:13px;line-height:1.55;margin-bottom:14px}
.rl-ok{background:var(--accent-soft);color:var(--accent-ink);border-radius:12px;padding:10px 14px;font-size:13px;margin-bottom:14px}
.rl-list{display:grid;gap:10px}
.rl-mem{display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:12px;align-items:center}
.rl-av{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;font-size:13px;font-weight:700;color:#fff;background:var(--accent)}
.rl-av{position:relative}
.rl-av .on{position:absolute;right:-1px;bottom:-1px;width:12px;height:12px;border-radius:50%;background:#15a85a;border:2px solid var(--surface)}
.rl-chip.live{background:var(--accent-soft);color:var(--accent-ink)}
.rl-name{font-size:14px;font-weight:700;color:var(--ink);overflow-wrap:anywhere}
.rl-sub{font-size:12px;color:var(--ink-soft);overflow-wrap:anywhere;margin-top:2px}
.rl-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;align-items:center}
.rl-chip{font-size:11px;font-weight:600;padding:3px 8px;border-radius:99px;background:var(--surface-3);color:var(--ink-soft);white-space:nowrap}
.rl-chip.plus{background:var(--accent-soft);color:var(--accent-ink)}
.rl-chip.minus{background:var(--red-soft);color:#b8362d}
.rl-acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.rl-roles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.rl-role h3{font-size:15px;font-weight:700;margin:0;overflow-wrap:anywhere}
.rl-role p{font-size:12.5px;color:var(--ink-soft);line-height:1.55;margin:6px 0 10px}
.rl-role .rl-acts{justify-content:flex-start;margin-top:12px}
.rl-meta{font-size:12px;color:var(--ink-2);font-weight:600}
.rl-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:14px}
.rl-f{display:block;min-width:0}
.rl-f.wide{grid-column:1/-1}
.rl-l{display:block;font-size:12px;font-weight:600;color:var(--ink-2);margin-bottom:5px}
.rl-in{display:block;width:100%;border:1px solid var(--border);border-radius:10px;padding:9px 11px;font:inherit;font-size:13px;background:var(--surface);color:var(--ink);min-width:0}
.rl-h{display:block;font-size:11.5px;color:var(--ink-faint);margin-top:4px;line-height:1.5}
.rl-sum{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin:4px 0 12px}
.rl-sum b{font-size:14px}
.rl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:start}
.rl-sec{border:1px solid var(--border);border-radius:14px;background:var(--surface);min-width:0}
.rl-sec-h{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--border-2);font-size:13px;font-weight:700}
.rl-sec-h .n{margin-left:auto;font-size:11.5px;color:var(--ink-soft);font-weight:600}
.rl-row{display:flex;align-items:flex-start;gap:10px;padding:8px 14px;font-size:12.5px;color:var(--ink-2);line-height:1.45;cursor:pointer}
.rl-row+.rl-row{border-top:1px solid var(--border-2)}
.rl-row.off{opacity:.55;cursor:not-allowed}
.rl-row[hidden],.rl-sec[hidden]{display:none}
.rl-row input,.rl-sec-h input{width:16px;height:16px;margin:1px 0 0;flex:0 0 auto;accent-color:var(--accent)}
.rl-row .t{flex:1;min-width:0}
.rl-row .k{display:block;font-family:var(--mono);font-size:10.5px;color:var(--ink-faint);margin-top:1px}
.rl-row .why{font-size:10.5px;font-weight:700;padding:2px 6px;border-radius:99px;white-space:nowrap}
.rl-row .why.plus{background:var(--accent-soft);color:var(--accent-ink)}
.rl-row .why.minus{background:var(--red-soft);color:#b8362d}
.rl-lock{display:flex;gap:10px;align-items:center;background:var(--accent-soft);color:var(--accent-ink);border-radius:12px;padding:12px 14px;font-size:13px;font-weight:600;margin-bottom:12px}
.rl-bar{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;margin:14px -16px -16px;padding:12px 16px;position:sticky;bottom:0;background:var(--surface);border-top:1px solid var(--border-2);border-radius:0 0 var(--r) var(--r);z-index:2}
.rl-back{background:none;border:0;padding:0;font:inherit;font-size:12.5px;font-weight:600;color:var(--ink-soft);cursor:pointer;margin-bottom:10px}
.rl-skel{height:64px;border-radius:14px;background:var(--surface-2)}
@media (max-width:760px){
  .rl-roles,.rl-grid,.rl-form{grid-template-columns:minmax(0,1fr)}
  .rl-mem{grid-template-columns:36px minmax(0,1fr)}
  .rl-mem .rl-acts{grid-column:1/-1;justify-content:flex-start}
  .rl-av{width:36px;height:36px}
  .rl-card{padding:14px}
  .rl-bar{margin:14px -14px -14px;padding:12px 14px}
}
</style>

<script>
(function () {
  'use strict';

  var S = { tab: 'members', data: null, members: null, rolesErr: null, membersErr: null, view: 'list', edit: null, filter: '', flash: '' };

  /* ------------------------------------------------------------- utilities */

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function host() { return document.getElementById('content'); }
  function toastMsg(t, bad) { try { if (typeof window.toast === 'function') window.toast(t, bad ? 'bad' : undefined); } catch (e) {} }
  function initials(s) {
    var words = String(s || '').replace(/[^\p{L}\p{N}\s]+/gu, ' ').trim().split(/\s+/).filter(Boolean);
    return words.slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('') || '?';
  }
  function has(list, k) { return list.indexOf(k) !== -1; }

  async function api(method, path, body) {
    var opts = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r;
    try { r = await fetch(root() + '/admin-api/roles' + path, opts); }
    catch (e) { return { status: 0, ok: false, data: {} }; }
    var data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    return { status: r.status, ok: r.ok, data: data || {} };
  }

  /* One sentence for any refusal. The server's own words win; a 404 whose body
     says nothing (no message, no error) is the compiled route table. */
  function why(r) {
    var d = r.data || {};
    if (r.status === 404 && !d.message && !d.error) return 'This screen is not in the server’s compiled route table yet. Clear it from Platform → Cache, then reload.';
    if (d.errors) { var k = Object.keys(d.errors)[0]; if (k !== undefined) { var v = d.errors[k]; return String(Array.isArray(v) ? v[0] : v); } }
    if (d.message) return String(d.message);
    if (r.status === 0) return 'The request did not reach the server. Check the connection and try again.';
    if (r.status === 419) return 'Your session has ended. Sign in again.';
    return 'The server answered ' + r.status + '. Try again in a moment.';
  }

  /* ---------------------------------------------------------------- data */

  function roles() { return (S.data && S.data.roles) || []; }
  function role(id) { return roles().filter(function (r) { return r.id === id; })[0] || null; }
  function sections() { return (S.data && S.data.sections) || []; }
  function me() { return (S.data && S.data.me) || { full: false, capabilities: [] }; }
  function mayGive(k) { return me().full || has(me().capabilities, k); }
  function total() { var n = 0; sections().forEach(function (s) { n += s.caps.length; }); return n; }

  async function load() {
    var a = await Promise.all([api('GET', '/members'), api('GET', '')]);
    S.membersErr = a[0].ok ? null : why(a[0]);
    S.rolesErr = a[1].ok ? null : why(a[1]);
    S.members = a[0].ok ? a[0].data.members : null;
    S.data = a[1].ok ? a[1].data : (a[0].ok ? a[0].data : null);
    if (S.membersErr && !S.rolesErr && S.tab === 'members') S.tab = 'roles';
    paint();
  }

  /* --------------------------------------------------------------- shell */

  function render() {
    S.view = 'list'; S.edit = null; S.flash = ''; S.filter = '';
    var h = host(); if (!h) return;
    h.innerHTML = '<div class="wrap rl" data-rl-screen>' + header() + '<div class="rl-list"><div class="rl-skel"></div><div class="rl-skel"></div><div class="rl-skel"></div></div></div>';
    load();
  }

  function header() {
    return '<div class="rl-head"><div><h2>Users &amp; Roles</h2><p>Who can sign in, which role each person is on, and exactly what every role may do.</p></div>' +
      (S.view === 'list' && S.tab === 'members' && S.members ? '<button type="button" class="btn" data-rl="new-member">+ Add member</button>' : '') +
      (S.view === 'list' && S.tab === 'roles' && !S.rolesErr && S.data ? '<button type="button" class="btn" data-rl="new-role">+ New role</button>' : '') +
      '</div>';
  }

  function tabs() {
    var t = [['members', 'Members', S.members ? S.members.length : null], ['roles', 'Roles', S.data && !S.rolesErr ? roles().length : null]];
    /* Lane MAC: the owner app's tab, drawn by owner-app-access.blade.php. */
    if (window.kbbOwnerAppAdmin) t.push(['ownerapp', 'Owner app', null]);
    return '<div class="rl-tabs" role="tablist" aria-label="Users and roles">' + t.map(function (x) {
      var on = x[0] === S.tab;
      return '<button type="button" role="tab" class="rl-tab' + (on ? ' on' : '') + '" id="rlt_' + x[0] + '" data-rl-tab="' + x[0] + '" aria-controls="rlp" aria-selected="' + on + '" tabindex="' + (on ? '0' : '-1') + '">' +
        esc(x[1]) + (x[2] != null ? ' <span class="ct">· ' + x[2] + '</span>' : '') + '</button>';
    }).join('') + '</div>';
  }

  function paint() {
    var h = host(); if (!h || !h.querySelector('[data-rl-screen]')) return;
    var body;
    if (S.view === 'member') body = memberEditor();
    else if (S.view === 'role') body = roleEditor();
    else body = tabs() + '<div role="tabpanel" id="rlp" aria-labelledby="rlt_' + S.tab + '" tabindex="0">' + (S.tab === 'members' ? membersTab() : S.tab === 'ownerapp' ? '<div data-oa-admin></div>' : rolesTab()) + '</div>';
    h.querySelector('[data-rl-screen]').innerHTML = header() + (S.flash ? '<div class="rl-ok" role="status">' + esc(S.flash) + '</div>' : '') + body;
    S.flash = '';
    var oa = h.querySelector('[data-oa-admin]');
    if (oa && window.kbbOwnerAppAdmin) window.kbbOwnerAppAdmin.mount(oa);
    syncSectionBoxes();
  }

  /* -------------------------------------------------------------- members */

  function membersTab() {
    if (S.membersErr) return '<div class="rl-err" role="alert"><b>The staff list could not be shown.</b><br>' + esc(S.membersErr) + '</div>';
    if (!S.members) return '';
    if (!S.members.length) return '<div class="rl-card rl-note">No accounts came back. You are signed in as one of them, so this is a fault rather than an empty list — reload the page.</div>';
    return '<div class="rl-list">' + S.members.map(function (m) {
      var tw = (m.grants.length ? '<span class="rl-chip plus">+' + m.grants.length + ' added</span>' : '') + (m.revokes.length ? '<span class="rl-chip minus">−' + m.revokes.length + ' removed</span>' : '');
      return '<div class="rl-card rl-mem"><span class="rl-av" aria-hidden="true">' + esc(initials(m.name || m.email)) + (m.online ? '<span class="on"></span>' : '') + '</span>' +
        '<div style="min-width:0"><div class="rl-name">' + esc(m.name || '—') + (m.is_self ? ' <span class="rl-chip">you</span>' : '') + '</div>' +
        '<div class="rl-sub">' + esc(m.email) + '</div>' +
        '<div class="rl-tags"><span class="pill ' + (m.full ? 'green' : 'blue') + '"><span class="d"></span>' + esc(m.role_title || m.role_name || 'No role') + '</span>' +
        (m.role_title && m.role_name ? '<span class="rl-chip">' + esc(m.role_name) + '</span>' : '') + tw +
        (m.online ? '<span class="rl-chip live">● Online now' + (m.editing ? ' · editing ' + (/^[aeiou]/.test(m.editing) ? 'an ' : 'a ') + esc(m.editing) : '') + '</span>' : '') +
        '<span class="rl-chip">' + (m.full ? 'Everything' : m.effective.length + ' of ' + total() + ' permissions') + '</span></div></div>' +
        '<div class="rl-acts"><button type="button" class="btn ghost sm" data-rl="edit-member" data-id="' + m.id + '">Edit access</button>' +
        (m.is_self ? '' : '<button type="button" class="btn ghost sm" data-rl="delete-member" data-id="' + m.id + '">Delete</button>') + '</div></div>';
    }).join('') + '</div>';
  }

  function openMember(id) {
    var m = id ? S.members.filter(function (x) { return x.id === id; })[0] : null;
    var first = roles().filter(function (r) { return r.slug === 'customer-support'; })[0] || roles()[0];
    S.edit = m ? { id: m.id, name: m.name || '', email: m.email, title: m.role_title || '', role_id: m.role_id, grants: m.grants.slice(), revokes: m.revokes.slice(), self: m.is_self, password: '' }
      : { id: null, name: '', email: '', title: '', role_id: first ? first.id : null, grants: [], revokes: [], self: false, password: '' };
    S.view = 'member'; S.filter = '';
    paint();
  }

  function memberBase() { var r = role(S.edit.role_id); return r ? r.capabilities : []; }
  function memberEffective() {
    var base = memberBase();
    return base.concat(S.edit.grants).filter(function (k, i, a) { return a.indexOf(k) === i && !has(S.edit.revokes, k); });
  }

  function memberEditor() {
    var e = S.edit, r = role(e.role_id), full = r && r.locked;
    var opts = roles().map(function (x) { return '<option value="' + x.id + '"' + (x.id === e.role_id ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('');
    var eff = memberEffective();
    return '<button type="button" class="rl-back" data-rl="back">← All members</button>' +
      '<div class="rl-card"><div class="rl-form">' +
      '<label class="rl-f"><span class="rl-l">Name</span><input class="rl-in" data-rl-f="name" maxlength="255" value="' + esc(e.name) + '"></label>' +
      (e.id ? '<label class="rl-f"><span class="rl-l">Email</span><input class="rl-in" value="' + esc(e.email) + '" disabled></label>'
        : '<label class="rl-f"><span class="rl-l">Email</span><input class="rl-in" type="email" data-rl-f="email" maxlength="255" value="' + esc(e.email) + '"></label>') +
      '<label class="rl-f"><span class="rl-l">Role</span><select class="rl-in" data-rl-f="role_id"' + (e.self && !me().full ? ' disabled' : '') + '>' + opts + '</select>' +
      '<span class="rl-h">' + esc(r ? r.description || '' : '') + '</span></label>' +
      '<label class="rl-f"><span class="rl-l">Title for this person <span style="font-weight:500;color:var(--ink-faint)">(optional)</span></span><input class="rl-in" data-rl-f="title" maxlength="60" placeholder="e.g. Head of SEO" value="' + esc(e.title) + '">' +
      '<span class="rl-h">Shown instead of the role name. It changes no permission.</span></label>' +
      '<label class="rl-f wide"><span class="rl-l">' + (e.id ? 'New password <span style="font-weight:500;color:var(--ink-faint)">(leave empty to keep it)</span>' : 'Temporary password') + '</span><input class="rl-in" type="text" autocomplete="new-password" data-rl-f="password" minlength="8" maxlength="255" placeholder="at least 8 characters" value="' + esc(e.password) + '"></label>' +
      '</div>' +
      (full ? '<div class="rl-lock">✓ Full Admin holds every permission, always — including ones added in future updates.</div>'
        : '<div class="rl-sum"><div><b>' + eff.length + ' of ' + total() + ' permissions</b> <span class="rl-note">· ' + memberBase().length + ' from ' + esc(r ? r.name : 'the role') +
          (e.grants.length ? ', <span style="color:var(--accent-ink)">+' + e.grants.length + ' added</span>' : '') + (e.revokes.length ? ', <span style="color:#b8362d">−' + e.revokes.length + ' removed</span>' : '') + ' for this person</span></div>' +
          finder() + '</div>' + grid('member', eff)) +
      '<div class="rl-bar"><button type="button" class="btn ghost" data-rl="back">Cancel</button><button type="button" class="btn" data-rl="save-member">' + (e.id ? 'Save' : 'Create account') + '</button></div></div>';
  }

  async function saveMember() {
    var e = S.edit, body = { name: e.name.trim(), role_id: e.role_id, role_title: e.title.trim() || null, grants: e.grants, revokes: e.revokes };
    if (e.password) { if (e.password.length < 8) { toastMsg('The password needs at least 8 characters', true); return; } body.password = e.password; }
    if (e.self && !me().full) { delete body.role_id; delete body.grants; delete body.revokes; }
    if (!e.id) {
      body.email = e.email.trim();
      if (!body.name || !body.email || !e.password) { toastMsg('Name, email and a temporary password are needed', true); return; }
    }
    var r = await api(e.id ? 'PUT' : 'POST', '/members' + (e.id ? '/' + e.id : ''), body);
    if (!r.ok) { toastMsg(why(r), true); return; }
    S.view = 'list'; S.flash = e.id ? 'Saved — ' + (body.name || e.email) + '’s access is updated.' : 'Account created for ' + body.email + '.';
    await load();
  }

  async function deleteMember(id) {
    var m = S.members.filter(function (x) { return x.id === id; })[0]; if (!m) return;
    if (!window.confirm('Delete the account for ' + (m.name || m.email) + '? They will not be able to sign in again.')) return;
    var r = await api('DELETE', '/members/' + id);
    if (!r.ok) { toastMsg(why(r), true); return; }
    S.flash = 'Account deleted.'; await load();
  }

  /* ---------------------------------------------------------------- roles */

  function rolesTab() {
    if (S.rolesErr) return '<div class="rl-err" role="alert"><b>Roles cannot be changed from your account.</b><br>' + esc(S.rolesErr) + '</div>';
    if (!S.data) return '';
    return '<div class="rl-roles">' + roles().map(function (r) {
      var bySec = sections().map(function (s) {
        var n = s.caps.filter(function (c) { return has(r.capabilities, c.key); }).length;
        return n ? '<span class="rl-chip">' + esc(s.label) + ' ' + n + '/' + s.caps.length + '</span>' : '';
      }).filter(Boolean);
      return '<div class="rl-card rl-role"><div class="rl-tags" style="margin:0 0 6px">' +
        '<span class="rl-chip">' + (r.preset ? 'Predefined' : 'Custom') + '</span>' + (r.edited ? '<span class="rl-chip plus">Edited</span>' : '') + (r.locked ? '<span class="rl-chip plus">Always everything</span>' : '') + '</div>' +
        '<h3>' + esc(r.name) + '</h3><p>' + esc(r.description || '') + '</p>' +
        '<div class="rl-meta">' + r.members + (r.members === 1 ? ' member' : ' members') + ' · ' + (r.locked ? 'all ' + total() : r.capabilities.length + ' of ' + total()) + ' permissions</div>' +
        (r.locked ? '' : '<div class="rl-tags">' + bySec.slice(0, 5).join('') + (bySec.length > 5 ? '<span class="rl-chip">+' + (bySec.length - 5) + ' more</span>' : '') + '</div>') +
        '<div class="rl-acts"><button type="button" class="btn ghost sm" data-rl="edit-role" data-id="' + r.id + '">' + (r.locked ? 'View' : 'Edit') + '</button>' +
        '<button type="button" class="btn ghost sm" data-rl="copy-role" data-id="' + r.id + '">Start a role from this</button>' +
        (r.preset && r.edited ? '<button type="button" class="btn ghost sm" data-rl="restore-role" data-id="' + r.id + '">Restore default</button>' : '') +
        (!r.preset ? '<button type="button" class="btn ghost sm" data-rl="delete-role" data-id="' + r.id + '">Delete</button>' : '') + '</div></div>';
    }).join('') + '</div>';
  }

  function openRole(id, from) {
    var r = id ? role(id) : null, src = from ? role(from) : null;
    if (!r && !src) src = roles().filter(function (x) { return x.slug === 'store-manager'; })[0] || roles()[0];
    S.edit = r ? { id: r.id, name: r.name, description: r.description || '', caps: r.capabilities.slice(), locked: r.locked, from: null, preset: r.preset }
      : { id: null, name: '', description: src ? src.description || '' : '', caps: src ? src.capabilities.slice() : [], locked: false, from: src ? src.id : null, preset: false, typed: false };
    S.view = 'role'; S.filter = '';
    paint();
  }

  function roleEditor() {
    var e = S.edit;
    var fromOpts = roles().map(function (x) { return '<option value="' + x.id + '"' + (x.id === e.from ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('');
    return '<button type="button" class="rl-back" data-rl="back">← All roles</button>' +
      '<div class="rl-card"><div class="rl-form">' +
      '<label class="rl-f"><span class="rl-l">Role name</span><input class="rl-in" data-rl-f="name" maxlength="60" placeholder="e.g. Weekend manager" value="' + esc(e.name) + '"></label>' +
      (e.id ? '<div class="rl-f"><span class="rl-l">Type</span><div class="rl-note" style="padding-top:8px">' + (e.preset ? 'Predefined — “Restore default” puts it back' : 'Custom role') + '</div></div>'
        : '<label class="rl-f"><span class="rl-l">Start from</span><select class="rl-in" data-rl-f="from">' + fromOpts + '</select><span class="rl-h">Copies that role’s ticks. Change any of them below.</span></label>') +
      '<label class="rl-f wide"><span class="rl-l">What this role is for</span><input class="rl-in" data-rl-f="description" maxlength="255" value="' + esc(e.description) + '"></label></div>' +
      (e.locked ? '<div class="rl-lock">✓ Full Admin holds every permission, always. It cannot be narrowed — start a custom role from it instead.</div>'
        : '<div class="rl-sum"><div><b>' + e.caps.length + ' of ' + total() + ' permissions</b></div>' + finder() + '</div>' + grid('role', e.caps)) +
      '<div class="rl-bar"><button type="button" class="btn ghost" data-rl="back">Cancel</button><button type="button" class="btn" data-rl="save-role">' + (e.id ? 'Save role' : 'Create role') + '</button></div></div>';
  }

  async function saveRole() {
    var e = S.edit, name = e.name.trim();
    if (name.length < 2) { toastMsg('Give the role a name', true); return; }
    var body = { name: name, description: e.description.trim() || null };
    if (!e.locked) body.capabilities = e.caps;
    if (!e.id) body.from = e.from;
    var r = await api(e.id ? 'PUT' : 'POST', e.id ? '/' + e.id : '', body);
    if (!r.ok) { toastMsg(why(r), true); return; }
    S.view = 'list'; S.tab = 'roles'; S.flash = e.id ? '“' + name + '” saved. Everyone on it has the new permissions now.' : '“' + name + '” created. Assign it from the Members tab.';
    await load();
  }

  async function roleAction(kind, id) {
    var r = role(id); if (!r) return;
    if (kind === 'delete' && !window.confirm('Delete the role “' + r.name + '”?')) return;
    if (kind === 'restore' && !window.confirm('Put “' + r.name + '” back to its default name and permissions? Everyone on it is affected.')) return;
    var res = await api(kind === 'delete' ? 'DELETE' : 'POST', '/' + id + (kind === 'restore' ? '/restore' : ''));
    if (!res.ok) { toastMsg(why(res), true); return; }
    S.flash = kind === 'delete' ? 'Role deleted.' : 'Restored to default.'; await load();
  }

  /* --------------------------------------------- the permission ticks */

  function finder() {
    return '<input class="rl-in" style="max-width:260px" type="search" data-rl-f="filter" placeholder="Find a permission" aria-label="Find a permission" value="' + esc(S.filter) + '">';
  }

  /* One grid for both editors. In the member editor a tick is the EFFECTIVE
     answer and the badge says where it came from. */
  function grid(kind, on) {
    var q = S.filter.toLowerCase(), frozen = kind === 'member' && S.edit.self && !me().full;
    return '<div class="rl-grid">' + sections().map(function (s) {
      var rows = s.caps.map(function (c) {
        var ticked = has(on, c.key), can = mayGive(c.key) && !frozen;
        var tag = '';
        if (kind === 'member') {
          if (has(S.edit.grants, c.key)) tag = '<span class="why plus">added</span>';
          else if (has(S.edit.revokes, c.key)) tag = '<span class="why minus">removed</span>';
        }
        var hide = q && (c.label + ' ' + c.key).toLowerCase().indexOf(q) === -1;
        return '<label class="rl-row' + (can ? '' : ' off') + '"' + (hide ? ' hidden' : '') + (can ? '' : ' title="' + (frozen ? 'Only a Full Admin can change your own permissions' : 'Your own role does not have this, so you cannot give it') + '"') + '>' +
          '<input type="checkbox" data-rl-cap="' + esc(c.key) + '"' + (ticked ? ' checked' : '') + (can ? '' : ' disabled') + '>' +
          '<span class="t">' + esc(c.label) + '<span class="k">' + esc(c.key) + '</span></span>' + tag + '</label>';
      }).join('');
      var n = s.caps.filter(function (c) { return has(on, c.key); }).length;
      var shown = !q || s.caps.some(function (c) { return (c.label + ' ' + c.key).toLowerCase().indexOf(q) !== -1; });
      return '<div class="rl-sec"' + (shown ? '' : ' hidden') + '><label class="rl-sec-h"><input type="checkbox" data-rl-all="' + esc(s.key) + '" aria-label="All of ' + esc(s.label) + '"' + (n === s.caps.length ? ' checked' : '') + (frozen ? ' disabled' : '') + '>' +
        esc(s.label) + '<span class="n">' + n + '/' + s.caps.length + '</span></label>' + rows + '</div>';
    }).join('') + '</div>';
  }

  /* A section's own box: ticked when all, half when some. A property, not a measurement. */
  function syncSectionBoxes() {
    var h = host(); if (!h) return;
    h.querySelectorAll('[data-rl-all]').forEach(function (box) {
      var sec = box.closest('.rl-sec'), all = sec.querySelectorAll('[data-rl-cap]'), on = sec.querySelectorAll('[data-rl-cap]:checked');
      box.indeterminate = on.length > 0 && on.length < all.length;
    });
  }

  function setCap(key, value) {
    if (!mayGive(key) && value) return;
    if (S.view === 'role') {
      S.edit.caps = S.edit.caps.filter(function (k) { return k !== key; });
      if (value) S.edit.caps.push(key);
      return;
    }
    var inBase = has(memberBase(), key);
    S.edit.grants = S.edit.grants.filter(function (k) { return k !== key; });
    S.edit.revokes = S.edit.revokes.filter(function (k) { return k !== key; });
    if (value && !inBase) S.edit.grants.push(key);
    if (!value && inBase) S.edit.revokes.push(key);
  }

  /* Repaint after a tick without losing the keyboard's place: the same box
     gets focus back. Keys are capability names, which never hold a quote. */
  function repaintKeeping(sel) {
    paint();
    var h = host(), el = h && h.querySelector(sel);
    if (el) el.focus({ preventScroll: true });
  }

  /* -------------------------------------------------------------- events */

  document.addEventListener('click', function (ev) {
    var h = host(); if (!h || !h.querySelector('[data-rl-screen]') || !h.contains(ev.target)) return;
    var tab = ev.target.closest('[data-rl-tab]');
    if (tab) { S.tab = tab.getAttribute('data-rl-tab'); S.view = 'list'; paint(); var b = document.getElementById('rlt_' + S.tab); if (b) b.focus(); return; }
    var a = ev.target.closest('[data-rl]'); if (!a) return;
    var id = parseInt(a.getAttribute('data-id') || '0', 10), act = a.getAttribute('data-rl');
    if (act === 'back') { S.view = 'list'; S.edit = null; paint(); }
    else if (act === 'new-member') openMember(null);
    else if (act === 'edit-member') openMember(id);
    else if (act === 'delete-member') deleteMember(id);
    else if (act === 'save-member') saveMember();
    else if (act === 'new-role') openRole(null, null);
    else if (act === 'copy-role') openRole(null, id);
    else if (act === 'edit-role') openRole(id, null);
    else if (act === 'save-role') saveRole();
    else if (act === 'restore-role') roleAction('restore', id);
    else if (act === 'delete-role') roleAction('delete', id);
  });

  document.addEventListener('keydown', function (ev) {
    var t = ev.target; if (!t || !t.matches || !t.matches('[data-rl-tab]')) return;
    var order = window.kbbOwnerAppAdmin ? ['members', 'roles', 'ownerapp'] : ['members', 'roles'], i = order.indexOf(t.getAttribute('data-rl-tab')), n = -1;
    if (ev.key === 'ArrowRight') n = (i + 1) % order.length;
    else if (ev.key === 'ArrowLeft') n = (i + order.length - 1) % order.length;
    else if (ev.key === 'Home') n = 0; else if (ev.key === 'End') n = order.length - 1;
    if (n < 0) return;
    ev.preventDefault(); S.tab = order[n]; paint(); var b = document.getElementById('rlt_' + S.tab); if (b) b.focus();
  });

  document.addEventListener('change', function (ev) {
    var t = ev.target, h = host(); if (!h || !S.edit || !h.contains(t)) return;
    if (t.matches('[data-rl-cap]')) { var key = t.getAttribute('data-rl-cap'); setCap(key, t.checked); repaintKeeping('[data-rl-cap="' + key + '"]'); return; }
    if (t.matches('[data-rl-all]')) {
      var all = t.getAttribute('data-rl-all'), sec = sections().filter(function (s) { return s.key === all; })[0];
      if (sec) sec.caps.forEach(function (c) { if (mayGive(c.key)) setCap(c.key, t.checked); });
      repaintKeeping('[data-rl-all="' + all + '"]'); return;
    }
    var f = t.getAttribute('data-rl-f');
    if (f === 'role_id') { S.edit.role_id = parseInt(t.value, 10); S.edit.grants = []; S.edit.revokes = []; paint(); }
    else if (f === 'from') {
      /* A new role copies the ticks of the role it starts from, and its words
         too until somebody has typed their own. */
      S.edit.from = parseInt(t.value, 10);
      var src = role(S.edit.from);
      if (src) { S.edit.caps = src.capabilities.slice(); if (!S.edit.typed) S.edit.description = src.description || ''; }
      paint();
    }
  });

  document.addEventListener('input', function (ev) {
    var t = ev.target, h = host(); if (!h || !S.edit || !h.contains(t)) return;
    var f = t.getAttribute('data-rl-f');
    if (f === 'filter') {
      S.filter = t.value;
      var q = S.filter.toLowerCase();
      h.querySelectorAll('.rl-sec').forEach(function (sec) {
        var any = false;
        sec.querySelectorAll('.rl-row').forEach(function (row) { var hit = !q || row.textContent.toLowerCase().indexOf(q) !== -1; row.hidden = !hit; any = any || hit; });
        sec.hidden = !any;
      });
    } else if (f === 'name' || f === 'email' || f === 'title' || f === 'password' || f === 'description') {
      S.edit[f] = t.value;
      if (f === 'description') S.edit.typed = true;
    }
  });

  /* ------------------------------------------------------------- wiring */

  window.renderUsers = render;
  window.kbbAdminRoles = { state: S, reload: load, openMember: openMember, openRole: openRole };
  if (typeof cur !== 'undefined' && cur === 'users') render();
})();
</script>
@endverbatim

{{-- Lane MAC: Users & Roles → Owner app, the third tab above. Included once, here. --}}
@include('admin.partials.owner-app-access')
