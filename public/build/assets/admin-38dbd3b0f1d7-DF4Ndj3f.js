
(function () {
  'use strict';

  var SCREEN = 'inquiries';
  var tab = 'inbox', filter = 'all', page = 1;
  var list = null, sel = null, listMsg = '', listErr = false;
  var set = null, cfg = null, dirty = false, busy = false, setMsg = '', setErr = false, fieldErr = {};

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }

  async function api(method, path, body) {
    var r = await fetch(base() + '/admin-api/inquiries' + path, {
      method: method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });
    var json = null;
    try { json = await r.json(); } catch (e) { json = null; }
    if (!r.ok) {
      var err = new Error((json && json.error) || ('Inquiries ' + r.status));
      err.status = r.status; err.fields = (json && json.fields) || {};
      throw err;
    }
    return json;
  }
  function why(e, what) {
    if (e && e.status === 403) return 'Your role cannot ' + what + '. An owner or manager can.';
    if (e && e.status === 404 && !e.fields) return 'This screen is not in the server\'s route table yet. Clear the route cache and reload.';
    return (e && e.message) || ('Could not ' + what + '.');
  }
  function when(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    return isNaN(d) ? '' : d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  /* ── inbox ─────────────────────────────────────────────────────────── */
  async function loadList() {
    listMsg = ''; listErr = false;
    try {
      list = await api('GET', '?filter=' + filter + '&page=' + page);
      if (sel && !list.rows.some(function (r) { return r.id === sel; })) sel = null;
    } catch (e) { list = null; listErr = true; listMsg = why(e, 'read inquiries'); }
    paint();
  }
  function row(id) { return list ? list.rows.filter(function (r) { return r.id === id; })[0] : null; }

  async function markRead(id, read) {
    var r = row(id); if (!r) return;
    try {
      var j = await api('POST', '/' + id + '/read', { read: read });
      r.read = read; list.unread = j.unread;
    } catch (e) { listErr = true; listMsg = why(e, 'mark inquiries read'); }
    paint();
  }
  async function remove(id) {
    if (!window.confirm('Delete this inquiry? This cannot be undone.')) return;
    try {
      var j = await api('DELETE', '/' + id);
      list.rows = list.rows.filter(function (r) { return r.id !== id; });
      list.unread = j.unread; sel = null; listMsg = 'Deleted.'; listErr = false;
    } catch (e) { listErr = true; listMsg = why(e, 'delete inquiries'); }
    paint();
  }

  function listHTML() {
    if (!list) return '<div class="ctx-card"><div class="ctx-help' + (listErr ? ' ctx-err' : '') + '">' + esc(listMsg || 'Loading…') + '</div></div>';
    var rows = list.rows.map(function (r) {
      return '<button type="button" class="ctx-row' + (r.read ? '' : ' unread') + (sel === r.id ? ' on' : '') + '" data-ctx-open="' + r.id + '">'
        + (r.read ? '<i class="ctx-dot" aria-hidden="true"></i>' : '<i class="ctx-dot" role="img" aria-label="Unread"></i>') + '<b>' + esc(r.name) + '</b><time>' + esc(when(r.at)) + '</time>'
        + '<span>' + (r.topic ? esc(r.topic) + ' · ' : '') + esc(r.message.slice(0, 140)) + '</span></button>';
    }).join('');
    var pager = (page > 1 || list.more) ? '<div class="ctx-pager"><button type="button" class="btn ghost sm" data-ctx-page="-1"' + (page > 1 ? '' : ' disabled') + '>Newer</button><span>Page ' + page + '</span><button type="button" class="btn ghost sm" data-ctx-page="1"' + (list.more ? '' : ' disabled') + '>Older</button></div>' : '';
    return '<div class="ctx-card ctx-list">' + (rows || '<div class="ctx-empty">' + (filter === 'unread' ? 'Nothing unread.' : 'No inquiries yet. Messages sent from the contact page’s form appear here.') + '</div>') + pager + '</div>';
  }

  function detailHTML() {
    var r = sel ? row(sel) : null;
    var msg = listMsg ? '<p class="ctx-help' + (listErr ? ' ctx-err' : '') + '" role="status">' + esc(listMsg) + '</p>' : '';
    if (!r) return '<div class="ctx-card ctx-detail">' + msg + '<p class="ctx-help">Choose an inquiry to read it. Opening one marks it read.</p></div>';
    var subject = 'Re: ' + (r.topic || 'your message');
    return '<div class="ctx-card ctx-detail">' + msg
      + '<h3>' + esc(r.name) + '</h3>'
      + '<dl class="ctx-meta"><dt>Email</dt><dd><a href="mailto:' + esc(encodeURI(r.email)) + '?subject=' + esc(encodeURIComponent(subject)) + '">' + esc(r.email) + '</a></dd>'
      + (r.phone ? '<dt>WhatsApp</dt><dd>' + esc(r.phone) + '</dd>' : '')
      + (r.topic ? '<dt>Topic</dt><dd>' + esc(r.topic) + '</dd>' : '')
      + '<dt>Received</dt><dd>' + esc(when(r.at)) + ' · ' + (r.locale === 'ar' ? 'Arabic page' : 'English page') + '</dd>'
      + '<dt>Email alert</dt><dd>' + (r.mailed ? 'Sent' : 'Not sent — see Emails → Sent mail') + '</dd></dl>'
      + '<div class="ctx-msg">' + esc(r.message) + '</div>'
      + '<div class="ctx-acts"><a class="btn" href="mailto:' + esc(encodeURI(r.email)) + '?subject=' + esc(encodeURIComponent(subject)) + '">Reply by email</a>'
      + '<button type="button" class="btn ghost" data-ctx-unread="' + r.id + '">' + (r.read ? 'Mark as unread' : 'Mark as read') + '</button>'
      + '<button type="button" class="btn ghost danger" data-ctx-del="' + r.id + '">Delete</button></div></div>';
  }

  /* ── contact page settings ────────────────────────────────────────── */
  async function loadSet() {
    setMsg = ''; setErr = false;
    try { take(await api('GET', '/settings')); } catch (e) { set = null; setErr = true; setMsg = why(e, 'change the contact page'); }
    paint();
  }
  function take(j) { set = j; cfg = JSON.parse(JSON.stringify(j.config)); cfg.topicsText = cfg.topics.join('\n'); dirty = false; fieldErr = {}; }
  function problems() {
    var out = [];
    var r = (cfg.recipient || '').trim();
    if (r !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(r)) out.push('recipient');
    var t = cfg.topicsText.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
    if (!t.length || t.length > set.limits.topics || t.some(function (x) { return x.length > set.limits.topic; })) out.push('topics');
    return out;
  }
  async function save() {
    if (busy || !set || problems().length) return;
    busy = true; setMsg = 'Saving…'; setErr = false; paintBar();
    var body = {}; Object.keys(cfg).forEach(function (k) { if (k !== 'topicsText') body[k] = cfg[k]; });
    body.topics = cfg.topicsText.split('\n');
    try { take(await api('POST', '/settings', { config: body })); setMsg = 'Saved. The contact page shows it now.'; }
    catch (e) { setErr = true; fieldErr = e.fields || {}; setMsg = why(e, 'change the contact page'); }
    busy = false; paint();
  }
  function sw(k, title, note) {
    return '<label class="ctx-sw"><input type="checkbox" data-ctx-k="' + k + '"' + (cfg[k] ? ' checked' : '') + '><span><b>' + esc(title) + '</b><small>' + esc(note) + '</small></span></label>';
  }
  function setHTML() {
    if (!set) return '<div class="ctx-card"><div class="ctx-help' + (setErr ? ' ctx-err' : '') + '">' + esc(setMsg || 'Loading…') + '</div></div>';
    var v = set.values, bad = problems();
    var shown = function (val) { return val ? 'Shows ' + val + '.' : 'No address set, so this card is hidden whatever this switch says.'; };
    return '<div class="ctx-set">'
      + '<div class="ctx-card"><h4>Cards</h4><p class="ctx-help">The numbers and the address come from Settings → Business → How customers reach you — change them there and every page, these cards included, follows.</p>'
      + sw('wa', 'WhatsApp card', shown(v.wa))
      + sw('ig', 'Instagram card', v.ig ? 'Shows ' + v.ig + ' and opens a direct message. The profile is the footer’s (Instagram URL).' : 'No Instagram profile set, so this card is hidden whatever this switch says.')
      + sw('email', 'Email card', shown(v.email))
      + sw('phone', 'Phone card', (v.phone ? 'Would show ' + v.phone + '. ' : '') + 'Off by default: the shop supports customers on WhatsApp, Instagram and email, not by phone.')
      + sw('socials', 'Social media icons', 'The footer’s own profiles (Instagram, TikTok, Facebook, YouTube) — each shows once it has an address.')
      + sw('hours', 'Opening hours', 'Shown when Settings → Business → Opening hours holds valid hours.') + '</div>'
      + '<div class="ctx-card"><h4>Inquiry form</h4>'
      + sw('form', 'Show the inquiry form', 'Name, email, WhatsApp number (optional), topic and message. Messages arrive here and by email.')
      + '<div class="ctx-field" style="margin-top:10px"><label for="ctx-rcpt">Send inquiries to</label><input id="ctx-rcpt" type="email" data-ctx-k="recipient" value="' + esc(cfg.recipient) + '" placeholder="' + esc(set.fallback || 'no address set') + '"' + (bad.indexOf('recipient') >= 0 || fieldErr.recipient ? ' class="bad"' : '') + '>'
      + '<span class="ctx-help">Blank sends to ' + (set.fallback ? esc(set.fallback) + (set.fallbackFrom === 'merchant' ? ' — the shop’s new-order alert address (Emails)' : ' — the support email (Settings → Business)') : 'nobody: set a new-order alert address under Emails, or type one here') + '. Every inquiry is kept here either way.</span></div>'
      + '<div class="ctx-field" style="margin-top:12px"><label for="ctx-topics">Topics, one per line</label><textarea id="ctx-topics" data-ctx-k="topicsText"' + (bad.indexOf('topics') >= 0 || fieldErr.topics ? ' class="bad"' : '') + '>' + esc(cfg.topicsText) + '</textarea>'
      + '<span class="ctx-help">Up to ' + set.limits.topics + ' topics of ' + set.limits.topic + ' characters. The four shipped topics show in Arabic on the Arabic page; a topic you type shows as typed.</span></div></div>'
      + '<div class="ctx-bar" data-ctx-bar></div></div>';
  }
  function paintBar() {
    var host = document.querySelector('[data-ctx-bar]');
    if (!host || !set) return;
    var bad = problems().length;
    host.classList.toggle('dirty', dirty);
    host.innerHTML = '<button type="button" class="btn" data-ctx-save' + (busy || !dirty || bad ? ' disabled' : '') + '>Save</button>'
      + '<button type="button" class="btn ghost" data-ctx-reset' + (busy ? ' disabled' : '') + '>Reset to defaults</button>'
      + '<span class="ctx-status' + (setErr || bad ? ' err' : '') + '" role="status" aria-live="polite">'
      + esc(setMsg || (bad ? 'Fix the field marked in red to save.' : (dirty ? 'Unsaved changes.' : ''))) + '</span>';
  }

  /* ── frame ─────────────────────────────────────────────────────────── */
  function paint() {
    var host = document.getElementById('content');
    if (!host) return;
    if (!host.querySelector('[data-ctx]')) {
      host.innerHTML = '<div class="wrap"><div class="page-head"><h2>Inquiries</h2>'
        + '<p>Messages sent from the contact page’s form, newest first, and the contact page’s own settings.</p></div><div data-ctx></div></div>';
    }
    var root = host.querySelector('[data-ctx]');
    var unread = list ? list.unread : 0;
    var top = '<div class="ctx-top"><div class="ctx-seg" role="tablist">'
      + '<button type="button" role="tab" aria-selected="' + (tab === 'inbox') + '" class="' + (tab === 'inbox' ? 'on' : '') + '" data-ctx-tab="inbox">Inbox' + (unread ? ' <span class="ctx-n">' + unread + '</span>' : '') + '</button>'
      + '<button type="button" role="tab" aria-selected="' + (tab === 'settings') + '" class="' + (tab === 'settings' ? 'on' : '') + '" data-ctx-tab="settings">Contact page</button></div>'
      + (tab === 'inbox' ? '<div class="ctx-seg">' + [['all', 'All'], ['unread', 'Unread']].map(function (o) {
        return '<button type="button" class="' + (filter === o[0] ? 'on' : '') + '" data-ctx-filter="' + o[0] + '">' + o[1] + '</button>';
      }).join('') + '</div>' : '') + '</div>';
    root.innerHTML = top + (tab === 'inbox'
      ? '<div class="ctx-inbox">' + listHTML() + detailHTML() + '</div>'
      : setHTML());
    if (tab === 'settings') paintBar();
  }

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-ctx-tab],[data-ctx-filter],[data-ctx-open],[data-ctx-unread],[data-ctx-del],[data-ctx-page],[data-ctx-save],[data-ctx-reset]') : null;
    if (!t || !t.closest('[data-ctx]')) return;
    if (t.dataset.ctxTab) { tab = t.dataset.ctxTab; paint(); if (tab === 'settings' && !set) loadSet(); return; }
    if (t.dataset.ctxFilter) { filter = t.dataset.ctxFilter; page = 1; sel = null; loadList(); return; }
    if (t.dataset.ctxPage) { page = Math.max(1, page + Number(t.dataset.ctxPage)); sel = null; loadList(); return; }
    if (t.dataset.ctxOpen) {
      sel = Number(t.dataset.ctxOpen); listMsg = '';
      var r = row(sel);
      if (r && !r.read) { markRead(sel, true); } else { paint(); }
      return;
    }
    if (t.dataset.ctxUnread) { var x = row(Number(t.dataset.ctxUnread)); if (x) markRead(x.id, !x.read); return; }
    if (t.dataset.ctxDel) { remove(Number(t.dataset.ctxDel)); return; }
    if (t.hasAttribute('data-ctx-save')) { save(); return; }
    if (t.hasAttribute('data-ctx-reset') && set) {
      cfg = JSON.parse(JSON.stringify(set.defaults)); cfg.topicsText = cfg.topics.join('\n');
      dirty = true; setMsg = 'Defaults restored here — press Save to put them on the shop.'; setErr = false; paint();
    }
  });

  function edit(e) {
    var t = e.target;
    if (!t || !t.dataset || !t.dataset.ctxK || !cfg || !t.closest('[data-ctx]')) return;
    cfg[t.dataset.ctxK] = t.type === 'checkbox' ? t.checked : t.value;
    dirty = true; setMsg = ''; setErr = false;
    t.classList.toggle('bad', problems().indexOf(t.dataset.ctxK === 'topicsText' ? 'topics' : t.dataset.ctxK) >= 0);
    paintBar();
  }
  document.addEventListener('input', edit);
  document.addEventListener('change', edit);

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Inquiries',
      icon: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1Z"/>',
      group: 'Store',
      after: ['quiz-leads', 'customers']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Store';
    if (title) title.textContent = 'Inquiries';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var host = document.getElementById('content');
    if (host) host.innerHTML = '';
    list = null; sel = null; set = null; tab = 'inbox';
    paint();
    loadList();
    return undefined;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
