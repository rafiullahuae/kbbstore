
(function () {
  'use strict';

  var SCREENS = {
    'emails': 'Overview',
    'emails-sending': 'Sending & delivery',
    'emails-branding': 'Design & branding',
    'emails-sent': 'Sent mail'
  };
  var GROUP = 'Emails';
  var ICONS = {
    'emails': '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'emails-sending': '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
    'emails-branding': '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.4A5.6 5.6 0 0 0 22 9.8C22 5.5 17.5 2 12 2z"/>',
    'emails-sent': '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'
  };

  var state = { screen: null, sending: null, branding: null, overview: null, pick: null, dirty: false, device: 'phone', chip: 'all', log: null };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function base() { return root() + '/admin-api/emails'; }

  function when(iso) { try { return new Date(iso).toLocaleString(); } catch (e) { return String(iso || ''); } }
  function clock(iso) { try { return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }
  function shortWhen(iso) {
    var d = new Date(iso); if (isNaN(d)) return String(iso || '');
    var now = new Date(), y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === now.toDateString()) return clock(iso);
    if (d.toDateString() === y.toDateString()) return 'Yesterday';
    return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
  }

  function toastMsg(text) { try { if (typeof window.toast === 'function') window.toast(text); } catch (e) {} }

  /* One fetch helper. 422 bodies are returned, not thrown: a refused value is
     an answer the screen shows next to the button that asked. */
  async function api(path, body, absolute) {
    var opts = {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }
    };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(absolute ? root() + path : base() + path, opts);
    var data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (r.ok || r.status === 422) return { status: r.status, data: data || {} };
    var err = new Error('emails ' + r.status); err.status = r.status; throw err;
  }

  function why(e) {
    if (e && e.status === 403) return 'Your role cannot open the Emails screens. The shop owner can.';
    if (e && e.status === 404) return 'This screen is not in the server\'s route table yet. Clear the route cache (Platform → Cache) and reload.';
    if (e && e.status === 429) return 'Too many presses in one minute. Wait a moment and try again.';
    return 'The request did not complete. Try again in a moment.';
  }

  function refusal(d) {
    if (!d) return 'Could not save.';
    if (d.error) return d.error;
    if (d.errors) { var k = Object.keys(d.errors)[0]; if (k) return String(d.errors[k][0] || d.message || 'Could not save.'); }
    return d.message || 'Could not save.';
  }

  function host() { return document.getElementById('content'); }

  /* Lane EK: every Emails screen in tabs, drawn by the ONE shared component. */
  function tabbed(group, list, fallback, label) {
    var ids = list.map(function (t) { return t[0]; });
    var T = window.kbbTabs;
    var cur = T ? T.pick(group, ids, fallback) : fallback;
    return {
      bar: T ? T.bar(group, list.map(function (t) { return [t[0], t[1], t[3]]; }), cur, label) : '',
      panels: list.map(function (t) { return '<div' + (T ? T.panel(group, t[0], cur) : (t[0] === cur ? '' : ' hidden')) + '>' + t[2] + '</div>'; }).join(''),
      cur: cur
    };
  }

  function shell(title, lead, inner) {
    return '<div class="wrap eml" data-eml="' + esc(state.screen) + '"><div class="page-head"><h2>' + esc(title) + '</h2><p>' + lead + '</p></div>' + inner + '</div>';
  }

  function loading(title) { var h = host(); if (h) h.innerHTML = shell(title, 'Loading…', ''); }

  function failed(title, e) {
    var h = host(); if (!h) return;
    h.innerHTML = shell(title, esc(why(e)), '<div><button class="btn ghost" type="button" data-eml-go="' + esc(state.screen) + '">Retry</button></div>');
  }

  function markDirty() {
    state.dirty = true;
    document.querySelectorAll('[data-eml-dirty]').forEach(function (d) { d.style.visibility = 'visible'; });
  }

  var TRANSPORT_NAMES = { server: 'Server mail', gmail: 'Google Workspace', smtp: 'Dedicated SMTP', log: 'Not sending' };

  /* ---------------------------------------------------------------- overview */

  async function overview() {
    loading('Emails');
    try { state.overview = (await api('/overview')).data; } catch (e) { return failed('Emails', e); }
    if (state.screen !== 'emails') return;
    var d = state.overview, t = d.transport || {}, lt = d.last_test, s7 = d.sent_7d || { sent: 0, failed: 0 };
    var emails = d.emails || [];
    var switchable = emails.filter(function (m) { return m.state === 'on' || m.state === 'off'; });
    var on = switchable.filter(function (m) { return m.state === 'on'; }).length;
    var recs = (d.dns && d.dns.records) || [];
    var toCheck = recs.length ? recs.filter(function (r) { return r.status !== 'ok'; }).length : 3;

    var testPill = !lt ? '<span class="eml-pill warn">No test sent yet</span>'
      : (lt.ok ? '<span class="eml-pill ok">Last test: delivered ' + esc(clock(lt.at)) + '</span>'
               : '<span class="eml-pill bad">Last test failed ' + esc(clock(lt.at)) + '</span>');
    var fallback = (t.key !== t.active && t.active === 'server')
      ? '<div class="eml-note">' + esc(t.label) + ' is chosen but not finished (still needed: ' + esc((t.missing || []).join(', ')) + '), so email is going out through this server\'s mail until it is. <a href="#" data-eml-go="emails-sending">Finish it</a>.</div>'
      : '';

    var tiles = '<div class="eml-tiles">'
      + '<div class="eml-tile"><span class="k">Sending through</span><span class="v">' + esc(TRANSPORT_NAMES[t.key] || t.label) + '</span>' + testPill + '</div>'
      + '<div class="eml-tile"><span class="k">Domain check · ' + esc(d.domain || '—') + '</span><span class="v">' + (toCheck ? toCheck + ' to check' : 'All set') + '</span>'
      + '<span class="eml-pill ' + (toCheck ? 'warn' : 'ok') + '">' + (recs.length ? 'Checked ' + esc(shortWhen(d.dns.at)) : 'SPF · DKIM · DMARC') + '</span></div>'
      + '<div class="eml-tile"><span class="k">Sent, last 7 days</span><span class="v">' + esc(String(s7.sent)) + '</span><span class="eml-pill ' + (s7.failed ? 'bad' : 'ok') + '">' + esc(String(s7.failed)) + ' failed</span></div>'
      + '<div class="eml-tile"><span class="k">Customer emails switched on</span><span class="v">' + on + ' of ' + switchable.length + '</span><span class="eml-pill">' + (switchable.length - on) + ' off</span></div>'
      + '</div>';

    function card(id, title, text, primary) {
      return '<div class="eml-card"><h3>' + esc(title) + '</h3><p>' + text + '</p><button type="button" class="btn ' + (primary ? '' : 'ghost') + ' small" data-eml-go="' + esc(id) + '">Open</button></div>';
    }
    var cards = '<div class="eml-cards">'
      + card('emails-sending', 'Sending & delivery', 'Server mail or Google Workspace, From and Reply-To, new-order alerts, the domain check (SPF / DKIM / DMARC) and Send a test. <b>Moved here from Store → Mail.</b>', true)
      + card('emails-customer', 'Customer emails', 'One row per email and per order status — Processing, On hold, Shipped, Delivered, Cancelled, Refunded, Payment failed… On/off, edit the words and the order of the sections, preview, send a test.', true)
      + card('emails-branding', 'Design & branding', 'Logo, colours, footer, social links and the business address — once, for every email.', false)
      + card('emails-sent', 'Sent mail', 'Every message and what the mail server answered, plus back-in-stock and basket reminders still waiting to go. <b>Moved here from Store → Mail.</b>', false)
      + '</div>';

    var STATE = { on: ['ok', 'On'], off: ['', 'Off'], manual: ['', 'You send it'], always: ['ok', 'Always on'] };
    var rows = '<div class="eml-list"><div class="eml-row head"><span>Email</span><span>To</span><span>Sent when</span><span>State</span><span>Switched in</span></div>'
      + emails.map(function (m) {
          var st = STATE[m.state] || ['', m.state];
          return '<div class="eml-row"><b>' + esc(m.name) + '</b><span class="c-to soft">' + esc(m.to) + '</span><span class="c-when soft">' + esc(m.when) + '</span>'
            + '<span class="c-state"><span class="eml-pill ' + st[0] + '">' + esc(st[1]) + '</span></span><span class="c-where soft">' + esc(m.where) + '</span></div>';
        }).join('') + '</div>';

    var ov = tabbed('eml-overview', [
      ['glance', 'At a glance', fallback + tiles],
      ['where', 'Where things are', cards
        + '<div class="sec-title">Marketing email lives in Growth &amp; Marketing</div>'
        + '<div class="eml-card"><p>Campaigns, the drag-and-drop builder, ready templates and customer groups are under <b>Growth &amp; Marketing → Marketing Emails</b>, so a newsletter can never be confused with an order email — and so marketing permissions stay separate from store settings.</p></div>'],
      ['all', 'Every email', '<div class="eml-card"><details open><summary>' + emails.length + ' emails, and whether each is on (read-only here)</summary>' + rows + '</details></div>', emails.length]
    ], 'glance', 'Emails overview');
    host().innerHTML = shell('Emails',
      'Everything the shop sends, in one place: how it is sent, what customers receive at every order status, how it looks, and what went out.',
      ov.bar + ov.panels);
  }


  /* ----------------------------------------------------------------- sending */

  var CHOICE_TEXT = {
    server: 'Sends from the website’s own server. Nothing to fill in.',
    gmail: 'Sends through your Google business mailbox. Usually lands in the inbox more reliably.',
    smtp: 'Your current setting. It stays as it is until you pick one of the two above; its server details are under Emails → All mail settings.',
    log: 'Your current setting: nothing is delivered, every message is written to the log.'
  };
  var CHOICE_TITLE = { server: 'This server’s mail', gmail: 'Google Workspace (Gmail SMTP)', smtp: 'A dedicated SMTP server', log: 'Nothing — written to the log' };

  function field(v, key, label, help, type) {
    return '<div class="mlf-field"><label class="mlf-label" for="eml_' + key + '">' + esc(label) + '</label>'
      + '<input class="mlf-input" type="' + (type || 'text') + '" id="eml_' + key + '" data-eml-key="' + key + '" value="' + esc(v[key] || '') + '">'
      + (help ? '<div class="mlf-help">' + help + '</div>' : '') + '</div>';
  }

  function dnsRows(dns) {
    var recs = (dns && dns.records) || [];
    var PILL = { ok: ['ok', 'Found'], missing: ['bad', 'Missing'], problem: ['warn', 'Check it'], unknown: ['', 'Not checked'] };
    var SUB = { SPF: 'Who may send for the domain', DKIM: 'Signature proving the mail is yours', DMARC: 'What receivers do with failures' };
    var google = state.sending && state.sending.transport === 'gmail';
    var domain = (state.sending && state.sending.domain) || 'your-domain';
    var suggest = {
      SPF: google ? 'v=spf1 include:_spf.google.com ~all' : 'v=spf1 ip4:<this server’s IP> ~all',
      DKIM: google ? 'google._domainkey → from Google Admin → Gmail → Authenticate email' : '<selector>._domainkey → from your mail provider',
      DMARC: '_dmarc → v=DMARC1; p=none; rua=mailto:info@kbeautybliss.com'
    };
    var list = recs.length ? recs : ['SPF', 'DKIM', 'DMARC'].map(function (r) { return { record: r, status: 'unknown', found: null, hint: '', host: '' }; });
    return '<table class="eml-dns"><thead><tr><th>Record</th><th>Status</th></tr></thead><tbody>'
      + list.map(function (r) {
          var p = recs.length ? (PILL[r.status] || ['', r.status]) : ['', 'Not checked'];
          var shown = r.found || suggest[r.record] || '';
          return '<tr><td><b>' + esc(r.record) + '</b><span class="sub">' + esc(SUB[r.record] || '') + (r.host ? ' · ' + esc(r.host) : '') + '</span>'
            + '<code>' + esc(shown) + '</code>' + (r.hint ? '<span class="sub" style="margin-top:6px">' + esc(r.hint) + '</span>' : '') + '</td>'
            + '<td><span class="eml-pill ' + p[0] + '">' + esc(p[1]) + '</span></td></tr>';
        }).join('') + '</tbody></table>'
      + '<div class="eml-testrow" style="margin-top:12px"><button class="btn ghost small" type="button" id="emlDns">Check now</button>'
      + '<span class="mlf-help">' + (dns && dns.at ? 'Last checked ' + esc(when(dns.at)) + ' for ' + esc(dns.domain || domain) + '.' : 'Looks up ' + esc(domain) + ' in public DNS.') + '</span></div>';
  }

  function testPill(t) {
    if (!t) return '';
    return '<span class="eml-pill ' + (t.ok ? 'ok' : 'bad') + '">' + (t.ok ? 'Accepted by the mail server · ' : 'Refused · ') + esc(clock(t.at)) + '</span>';
  }

  function testDetail(t) {
    if (!t || t.ok) return t ? '<div class="mlf-help" style="margin-top:8px">' + esc(t.message || '') + '</div>' : '';
    return '<div class="mlf-result is-bad" style="margin-top:10px"><b>Send failed</b><div class="mlf-result-when">' + esc(when(t.at)) + ' → ' + esc(t.to || '') + ' · via ' + esc(TRANSPORT_NAMES[t.transport] || t.transport || '') + '</div>'
      + '<div class="mlf-result-msg">' + esc(t.message || '') + '</div>' + (t.error ? '<div class="mlf-result-err">' + esc(t.error) + '</div>' : '') + '</div>';
  }

  function paintSending() {
    var d = state.sending, v = d.values || {}, chosen = state.pick || d.transport;
    var pw = d.gmail_password && d.gmail_password.has_value;
    var notes = d.configured ? '' : '<div class="eml-note">Not finished — still needed: <b>' + esc((d.missing || []).join(', ')) + '</b>. Until then every email goes out through this server\'s mail, so nothing stops sending.</div>';

    var choices = '<div class="eml-choices">' + (d.options || []).map(function (o) {
      var inUse = o.key === d.transport;
      return '<label class="eml-choice' + (o.key === chosen ? ' on' : '') + '"><input type="radio" name="emlTransport" value="' + esc(o.key) + '"' + (o.key === chosen ? ' checked' : '') + '>'
        + '<span class="t"><span class="dot"></span>' + esc(CHOICE_TITLE[o.key] || o.label) + '</span>'
        + (inUse ? '<br><span class="eml-pill ok">In use</span>' : '')
        + '<span class="d">' + esc(CHOICE_TEXT[o.key] || '') + '</span></label>';
    }).join('') + '</div>';

    /* Only when Google is the choice (.mlf-sec-style grids would beat [hidden]). */
    var gmail = chosen !== 'gmail' ? '' : '<div class="eml-dash" data-eml-gmail><div class="cap">Google Workspace settings <span>· shown when that option is picked</span></div>'
      + '<div class="mlf-grid">'
      + '<div class="mlf-field"><label class="mlf-label" for="emlHost">SMTP host</label><input class="mlf-input" id="emlHost" type="text" value="smtp.gmail.com" readonly></div>'
      + '<div class="mlf-field"><label class="mlf-label" for="emlPort">Port &amp; security</label><select class="mlf-input" id="emlPort" disabled><option>587 · TLS</option></select></div>'
      + '</div><div class="mlf-grid">'
      + field(v, 'mail_gmail_username', 'Google account (username)', '', 'email')
      + '<div class="mlf-field"><label class="mlf-label" for="eml_mail_gmail_password">App password' + (pw ? '<span class="eml-saved">saved</span>' : '') + '</label>'
      + '<input class="mlf-input" type="password" autocomplete="new-password" id="eml_mail_gmail_password" data-eml-key="mail_gmail_password" placeholder="' + (pw ? 'Saved — leave blank to keep it' : 'Not set') + '">'
      + '<div class="mlf-help">Google Account → Security → 2-Step Verification → App passwords. Not your normal password. Stored encrypted.</div></div>'
      + '</div><div class="eml-foot-note">Google allows about 2,000 messages a day per Workspace user.</div></div>';

    var fromHelp = 'With Google, this must be the Google account above or one of its aliases.';

    var sel = state.which || 'plain';
    var samples = d.samples || { plain: 'A plain test message' };
    var lastTo = (d.last_test && d.last_test.to) || '';

    var saveRow = function (id) { return '<div class="eml-save"><span class="eml-dirty" data-eml-dirty style="visibility:' + (state.dirty ? 'visible' : 'hidden') + '">Unsaved changes</span><button class="btn primary" type="button" id="' + id + '">Save changes</button></div>'; };
    var testCard = '<div class="eml-card"><div><h3 class="eml-h">Send a test</h3><p class="eml-d">Sends through whichever option is picked, and shows what the mail server answered.</p></div>'
      + '<div class="mlf-field"><label class="mlf-label" for="emlTo">Send a test to</label><input class="mlf-input" type="email" id="emlTo" placeholder="you@example.com" value="' + esc(state.to || lastTo) + '"></div>'
      + '<div class="mlf-field"><label class="mlf-label" for="emlWhich">Which email</label><select class="mlf-input" id="emlWhich">'
      + Object.keys(samples).map(function (k) { return '<option value="' + esc(k) + '"' + (k === sel ? ' selected' : '') + ((k !== 'plain' && !d.has_order) ? ' disabled' : '') + '>' + esc(samples[k]) + '</option>'; }).join('')
      + '</select></div>'
      + '<div class="eml-testrow"><button class="btn primary" type="button" id="emlTest">Send test</button><span id="emlTestPill">' + testPill(d.last_test) + '</span></div>'
      + '<div id="emlTestResult">' + testDetail(d.last_test) + '</div></div>';
    var sd = tabbed('eml-sending', [
      ['method', 'Send using', '<div class="eml-card"><div><h3 class="eml-h">Send using</h3><p class="eml-d">Pick one. You can switch at any time; press Send test after switching.</p></div>' + choices + gmail + saveRow('emlSaveSending') + '</div>'],
      ['from', 'Who it is from', '<div class="eml-card"><div class="mlf-grid">' + field(v, 'mail_from_name', 'From name', '') + field(v, 'mail_from_address', 'From address', fromHelp, 'email') + '</div>'
        + '<div class="mlf-grid">' + field(v, 'mail_reply_to', 'Replies go to', '', 'email') + field(v, 'mail_merchant_address', 'New-order alerts to', '', 'email') + '</div>'
        + saveRow('emlSaveSending2') + '</div>'],
      ['test', 'Send a test', testCard],
      ['dns', 'Domain check', '<div class="eml-card"><div><h3 class="eml-h">Will inboxes trust the domain?</h3><p class="eml-d">A read-only DNS check for the From domain. It never changes anything.</p></div><div id="emlDnsBox">' + dnsRows(d.dns) + '</div></div>']
    ], 'method', 'Sending & delivery');
    host().innerHTML = shell('Sending & delivery',
      'How email leaves the shop and who it is from. Two ways, both already yours.',
      notes + sd.bar + sd.panels);
  }


  async function sending() {
    loading('Sending & delivery');
    state.pick = null; state.dirty = false;
    try { state.sending = (await api('/sending')).data; } catch (e) { return failed('Sending & delivery', e); }
    if (state.screen === 'emails-sending') paintSending();
  }

  function collect(keys) {
    var out = {};
    keys.forEach(function (k) {
      var el = document.getElementById('eml_' + k);
      if (!el) return;
      if (el.type === 'password' && el.value === '') return;   // blank = keep the stored one
      out[k] = el.value;
    });
    return out;
  }

  var SENDING_KEYS = ['mail_from_name', 'mail_from_address', 'mail_reply_to', 'mail_merchant_address', 'mail_gmail_username', 'mail_gmail_password'];

  async function saveSending(btn, quiet) {
    var picked = document.querySelector('input[name="emlTransport"]:checked');
    var payload = { transport: picked ? picked.value : state.sending.transport, settings: collect(SENDING_KEYS) };
    if (btn) btn.disabled = true;
    var ok = false;
    try {
      var r = await api('/sending', payload);
      if (r.status === 422) { toastMsg('Could not save: ' + refusal(r.data)); }
      else { state.sending = r.data; state.pick = null; state.dirty = false; ok = true; if (!quiet) { paintSending(); toastMsg('Saved'); } }
    } catch (e) { toastMsg(why(e)); }
    if (btn) btn.disabled = false;
    return ok;
  }

  async function sendTest(btn, to, which, out) {
    btn.disabled = true; var was = btn.textContent; btn.textContent = 'Sending…';
    try {
      var r = await api('/test', { to: to, which: which });
      if (r.status === 422) { if (out) out.innerHTML = '<p class="mlf-muted" style="margin-top:8px">' + esc(refusal(r.data)) + '</p>'; }
      else return r.data;
    } catch (e) { if (out) out.innerHTML = '<p class="mlf-muted" style="margin-top:8px">' + esc(why(e)) + '</p>'; }
    finally { btn.disabled = false; btn.textContent = was; }
    return null;
  }

  async function testFromSending(btn) {
    state.to = ((document.getElementById('emlTo') || {}).value || '').trim();
    state.which = (document.getElementById('emlWhich') || {}).value || 'plain';
    /* "Sends through whichever option is picked": an unsaved choice is saved
       first, so the test answers for what is on the screen. */
    if (state.dirty || (state.pick && state.pick !== state.sending.transport)) {
      if (!(await saveSending(null, true))) return;
      paintSending();
      btn = document.getElementById('emlTest');
    }
    var t = await sendTest(btn, state.to, state.which, document.getElementById('emlTestResult'));
    if (t) {
      state.sending.last_test = t;
      var p = document.getElementById('emlTestPill'); if (p) p.innerHTML = testPill(t);
      var o = document.getElementById('emlTestResult'); if (o) o.innerHTML = testDetail(t);
    }
  }

  async function checkDns(btn) {
    btn.disabled = true; var was = btn.textContent; btn.textContent = 'Checking…';
    try {
      var r = await api('/dns', {});
      if (state.sending) state.sending.dns = r.data.last;
      var box = document.getElementById('emlDnsBox'); if (box) box.innerHTML = dnsRows(r.data.last);
    } catch (e) { toastMsg(why(e)); btn.disabled = false; btn.textContent = was; }
  }

  /* ---------------------------------------------------------------- branding */

  var STACKS = {
    outfit: "'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
    system: "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
    georgia: "Georgia,'Times New Roman',Times,serif"
  };

  function lines(v) { return String(v || '').split(/\s*\|\s*/).filter(Boolean).join('\n'); }

  function frameUrl() { return base() + '/preview?v=' + (state.frameRev || 0); }

  function paintBranding() {
    var d = state.branding, v = d.values || {};
    var lk = d.look || {}, fonts = d.fonts || {};

    function input(key, label, help, type) {
      return '<div class="mlf-field"><label class="mlf-label" for="eml_' + key + '">' + esc(label) + '</label>'
        + '<input class="mlf-input" type="' + (type || 'text') + '" id="eml_' + key + '" data-eml-key="' + key + '" value="' + esc(v[key] || '') + '">'
        + (help ? '<div class="mlf-help">' + help + '</div>' : '') + '</div>';
    }
    function area(key, label, help, placeholder) {
      return '<div class="mlf-field"><label class="mlf-label" for="eml_' + key + '">' + esc(label) + '</label>'
        + '<textarea class="mlf-input" rows="3" id="eml_' + key + '" data-eml-key="' + key + '" placeholder="' + esc(placeholder) + '">' + esc(lines(v[key])) + '</textarea>'
        + (help ? '<div class="mlf-help">' + help + '</div>' : '') + '</div>';
    }
    function swatch(key, label) {
      return '<label class="eml-sw"><input type="color" id="eml_' + key + '" data-eml-key="' + key + '" value="' + esc(lk[key] || '#000000') + '" aria-label="' + esc(label) + ' colour">' + esc(label) + '</label>';
    }
    function fontSelect(key, label) {
      return '<div class="mlf-field"><label class="mlf-label" for="eml_' + key + '">' + esc(label) + '</label><select class="mlf-input" id="eml_' + key + '" data-eml-key="' + key + '">'
        + Object.keys(fonts).map(function (f) { return '<option value="' + esc(f) + '"' + (f === lk[key] ? ' selected' : '') + '>' + esc(fonts[f]) + '</option>'; }).join('')
        + '</select></div>';
    }

    var logo = lk.email_logo || '';
    var look = '<div class="eml-card"><div><h3 class="eml-h">Look</h3><p class="eml-d">Blush editorial (A) — your choice of 3 October, used by every email.</p></div>'
      + '<div class="mlf-field"><span class="mlf-label">Logo</span>'
      + (logo ? '<div class="eml-logo"><img src="' + esc(logo.charAt(0) === '/' ? root() + logo : logo) + '" alt="Email logo"><button class="btn ghost small" type="button" id="emlLogoClear">Remove</button></div>' : '')
      + '<button type="button" class="eml-upload" id="emlLogo">⬆ ' + (logo ? 'Change' : 'Upload PNG or JPG') + ' · at least 340×80</button>'
      + '<input type="hidden" id="eml_email_logo" data-eml-key="email_logo" value="' + esc(logo) + '">'
      + '<div class="mlf-help">Blank: the “K-Beauty Bliss” wordmark prints in text, which shows even when pictures are blocked.</div></div>'
      + '</div>';
    var colours = '<div class="eml-card"><div><h3 class="eml-h">Colours &amp; fonts</h3><p class="eml-d">Used by every email; the preview tab shows them once saved.</p></div>'
      + '<div class="mlf-field"><span class="mlf-label">Colours</span><div class="eml-swatches">'
      + swatch('email_accent', 'Accent') + swatch('email_button', 'Buttons') + swatch('email_background', 'Background') + swatch('email_text', 'Text') + '</div></div>'
      + '<div class="mlf-grid">' + fontSelect('email_font_heading', 'Heading font') + fontSelect('email_font_body', 'Body font') + '</div>'
      + '<div class="mlf-help">Fonts ship as the shop’s own Outfit. Gmail does not load web fonts and shows a close system font instead.</div>'
      + '</div>';

    var foot = '<div class="eml-card"><div><h3 class="eml-h">Footer of every email</h3></div>'
      + area('mail_address_dubai', 'Dubai address', 'One line per line. Left blank, the Dubai address is not printed at all.', '')
      + area('mail_address_korea', 'Korea address', 'One line per line. Left blank, the Korea address is not printed at all.', '')
      + '<div class="mlf-grid">' + input('mail_support_whatsapp', 'WhatsApp', '') + input('mail_support_email', 'Email', '', 'email') + '</div>'
      + input('mail_support_instagram', 'Instagram', '')
      + input('mail_signature', 'Signature', '')
      + '</div>';
    // One Save for all four tabs: it saves every field, as it always has.
    var saveRow = '<div class="eml-save"><span class="eml-dirty" data-eml-dirty style="visibility:' + (state.dirty ? 'visible' : 'hidden') + '">Unsaved changes</span>'
      + '<button class="btn ghost" type="button" id="emlBrandTest">Send test</button><button class="btn primary" type="button" id="emlSaveBranding">Save</button></div>'
      + '<div id="emlBrandTestResult"></div>';

    var prev = '<div class="eml-prev-h"><h3 class="eml-h">Preview</h3><div class="eml-seg" role="group" aria-label="Preview width">'
      + '<button type="button" data-eml-device="desktop" class="' + (state.device === 'desktop' ? 'on' : '') + '">Desktop</button>'
      + '<button type="button" data-eml-device="phone" class="' + (state.device === 'phone' ? 'on' : '') + '">Phone</button></div></div>'
      + '<iframe class="eml-frame ' + esc(state.device) + '" title="Order confirmation preview" sandbox="" src="' + esc(frameUrl()) + '"></iframe>'
      + '<p class="mlf-help" style="text-align:center;margin-top:8px">The real order email, filled with your latest order. It shows what is saved; press Save to see a change.</p>';

    host().innerHTML = shell('Design & branding',
      'Set once; every customer email and every campaign uses it. Change any of it at any time.',
      (function () {
        var br = tabbed('eml-branding', [['look', 'Look', look], ['colours', 'Colours & fonts', colours], ['footer', 'Footer of every email', foot], ['preview', 'Preview', '<div class="eml-card">' + prev + '</div>']], 'look', 'Design & branding');
        return br.bar + br.panels + saveRow;
      })());
  }

  async function branding() {
    loading('Design & branding');
    state.dirty = false;
    try { state.branding = (await api('/branding')).data; } catch (e) { return failed('Design & branding', e); }
    if (state.screen === 'emails-branding') paintBranding();
  }

  var BRANDING_KEYS = ['mail_support_email', 'mail_support_whatsapp', 'mail_address_dubai', 'mail_address_korea', 'mail_support_instagram', 'mail_signature',
    'email_font_heading', 'email_font_body', 'email_accent', 'email_button', 'email_background', 'email_text', 'email_logo'];

  async function saveBranding(btn) {
    btn.disabled = true;
    try {
      var r = await api('/branding', { settings: collect(BRANDING_KEYS) });
      if (r.status === 422) toastMsg('Could not save: ' + refusal(r.data));
      else { state.branding = r.data; state.dirty = false; state.frameRev = (state.frameRev || 0) + 1; paintBranding(); toastMsg('Saved'); }
    } catch (e) { toastMsg(why(e)); }
    btn.disabled = false;
  }

  async function brandTest(btn) {
    var out = document.getElementById('emlBrandTestResult');
    var to = (state.branding && state.branding.last_to) || '';
    if (!to) {
      if (out) out.innerHTML = '<p class="mlf-help" style="margin-top:8px">Send a first test from <a href="#" data-eml-go="emails-sending">Sending &amp; delivery</a>; this button then sends the order email to the same address.</p>';
      return;
    }
    var t = await sendTest(btn, to, 'order_confirmation', out);
    if (t && out) out.innerHTML = '<div style="margin-top:8px">' + testPill(t) + ' <span class="mlf-help">Order confirmation → ' + esc(to) + '</span></div>' + (t.ok ? '' : testDetail(t));
  }

  /* ---------------------------------------------------------------- sent mail */

  /* No Campaigns tab (Lane EK): the owner, 4 Oct — the Emails module is
     transactional only, "NO marketing emails". Campaign sends are reported
     under Growth & Marketing → Marketing Emails → Reports and left out here. */
  var CHIPS = [['all', 'Sent'], ['failed', 'Failed'], ['orders', 'Orders'], ['account', 'Account'], ['waiting', 'Waiting to go out']];
  var KIND_NAMES = {
    'order.confirmation': 'Order confirmed', 'order.merchant': 'New order alert', 'order.status': 'Order status', 'order.refunded': 'Refund sent', 'order.invoice': 'Invoice',
    'account.password_reset': 'Password reset', 'account.verify_email': 'Verify email', 'test': 'Test message',
    'stock.back': 'Back in stock', 'cart.recovery': 'Basket reminder', 'quiz.plan': 'Skin quiz plan', 'newsletter.confirm': 'Newsletter confirm'
  };

  function inChip(e, chip) {
    var k = String(e.kind || '');
    if (k.indexOf('campaign.') === 0) return false;
    if (chip === 'failed') return e.status !== 'sent';
    if (chip === 'orders') return k.indexOf('order.') === 0 || k.indexOf('test.order') === 0 || k === 'test.new_order_alert';
    if (chip === 'account') return k.indexOf('account.') === 0 || k.indexOf('customer') === 0;
    if (chip === 'campaigns') return k.indexOf('campaign.') === 0;
    return true;
  }

  function paintLog() {
    var box = document.getElementById('emlLogBox'); if (!box) return;
    if (state.chip === 'waiting') {
      box.innerHTML = '<div class="eml-card"><div id="mlBacklog"><p class="mlf-muted">Loading…</p></div><div id="mlLog" hidden></div></div>';
      try { if (typeof loadOutboundBacklog === 'function') loadOutboundBacklog(); } catch (e) {}
      return;
    }
    var rows = ((state.log && state.log.entries) || []).filter(function (e) { return inChip(e, state.chip); });
    if (!state.log) { box.innerHTML = '<div class="eml-logcard"><div class="eml-empty">Loading…</div></div>'; return; }
    if (!rows.length) {
      var empty = { failed: 'Nothing has failed. That is the answer you want here.', campaigns: 'No campaign has been sent yet. Campaigns arrive with the marketing package.' }[state.chip] || 'Nothing recorded yet.';
      box.innerHTML = '<div class="eml-logcard"><div class="eml-empty">' + esc(empty) + '</div></div>';
      return;
    }
    box.innerHTML = '<div class="eml-logcard"><table class="eml-log"><thead><tr><th>When</th><th>Email</th><th>To</th><th>Result</th></tr></thead><tbody>'
      + rows.map(function (e) {
          var ok = e.status === 'sent';
          var name = e.subject || KIND_NAMES[e.kind] || e.kind || '';
          return '<tr><td>' + esc(shortWhen(e.at)) + '</td><td class="e">' + esc(name) + '</td><td>' + esc(e.recipient || '') + '</td>'
            + '<td><span class="eml-pill ' + (ok ? 'ok' : 'bad') + '">' + (ok ? 'Accepted' : 'Refused') + '</span>'
            + (ok ? '' : '<div class="mlf-help" style="margin-top:6px">' + esc(e.error || 'No reason was recorded.') + '</div>') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
  }

  async function sent() {
    state.log = null;
    host().innerHTML = shell('Sent mail',
      'Every message the shop tried to send and what the mail server said. Bodies are never stored.',
      (function () {
        var ids = CHIPS.map(function (c) { return c[0]; });
        if (window.kbbTabs) state.chip = window.kbbTabs.pick('eml-sent', ids, ids.indexOf(state.chip) === -1 ? 'all' : state.chip);
        if (ids.indexOf(state.chip) === -1) state.chip = 'all';
        return (window.kbbTabs ? window.kbbTabs.bar('eml-sent', CHIPS, state.chip, 'Sent mail') : '')
          + '<div id="emlLogBox" role="tabpanel" tabindex="0" aria-label="Messages"></div>';
      })()
      + '<p class="mlf-help" style="margin-top:10px">“Accepted” means the mail server took it; whether it reached the inbox or spam is decided later by the receiver.</p>');
    paintLog();
    try {
      var r = await api('/admin-api/mail/log?status=all&limit=200', null, true);
      state.log = r.data;
    } catch (e) { state.log = { entries: [] }; toastMsg(why(e)); }
    if (state.screen === 'emails-sent') paintLog();
  }

  /* ------------------------------------------------------------------ events */

  document.addEventListener('click', function (e) {
    if (!document.querySelector('[data-eml]')) return;
    var t = e.target;
    var go = t.closest && t.closest('[data-eml-go]');
    if (go) { e.preventDefault(); window.go(go.getAttribute('data-eml-go')); return; }
    var chip = t.closest && t.closest('[data-eml-chip]');
    if (chip) {
      state.chip = chip.getAttribute('data-eml-chip');
      document.querySelectorAll('[data-eml-chip]').forEach(function (b) { b.classList.toggle('on', b === chip); });
      paintLog(); return;
    }
    var dev = t.closest && t.closest('[data-eml-device]');
    if (dev) {
      state.device = dev.getAttribute('data-eml-device');
      document.querySelectorAll('[data-eml-device]').forEach(function (b) { b.classList.toggle('on', b === dev); });
      var f = document.querySelector('.eml-frame'); if (f) f.className = 'eml-frame ' + state.device;
      return;
    }
    if (t.id === 'emlSaveSending' || t.id === 'emlSaveSending2') return saveSending(t);
    if (t.id === 'emlTest') return testFromSending(t);
    if (t.id === 'emlDns') return checkDns(t);
    if (t.id === 'emlSaveBranding') return saveBranding(t);
    if (t.id === 'emlBrandTest') return brandTest(t);
    if (t.id === 'emlLogoClear') {
      var hid = document.getElementById('eml_email_logo'); if (hid) hid.value = '';
      markDirty(); var img = t.closest('.eml-logo'); if (img) img.remove(); return;
    }
    if (t.id === 'emlLogo') {
      if (typeof window.kbbPickMedia !== 'function') { toastMsg('The media picker is not loaded on this page.'); return; }
      window.kbbPickMedia({
        title: 'Email logo', note: 'PNG or JPG, at least 340×80. Shown at the top of every email.', upload: false,
        onPick: function (urls) {
          var u = (urls && urls[0]) || '';
          var hid = document.getElementById('eml_email_logo'); if (hid && u) { hid.value = u; markDirty(); t.textContent = '⬆ Picked — press Save'; }
        }
      });
    }
  });

  document.addEventListener('kbb:tab', function (e) {
    if (!e.detail || e.detail.group !== 'eml-sent') return;
    state.chip = e.detail.id;
    paintLog();
  });

  document.addEventListener('change', function (e) {
    if (!document.querySelector('[data-eml]')) return;
    var t = e.target;
    if (t.name === 'emlTransport') {
      state.pick = t.value;
      // Keep what was typed: copy the boxes back before repainting the form.
      var typed = collect(SENDING_KEYS.filter(function (k) { return k !== 'mail_gmail_password'; }));
      Object.keys(typed).forEach(function (k) { state.sending.values[k] = typed[k]; });
      state.to = ((document.getElementById('emlTo') || {}).value || '').trim();
      state.which = (document.getElementById('emlWhich') || {}).value || state.which;
      state.dirty = true;
      paintSending();
      return;
    }
    if (t.hasAttribute && t.hasAttribute('data-eml-key')) markDirty();
  });

  document.addEventListener('input', function (e) {
    if (!document.querySelector('[data-eml]')) return;
    if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-eml-key')) markDirty();
  });

  /* ------------------------------------------------------------------ wiring */

  function addNavEntries() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    var after = null;
    Object.keys(SCREENS).forEach(function (id) {
      window.kbbAddNavEntry({ screen: id, label: SCREENS[id], icon: ICONS[id], group: GROUP, after: after ? [after] : [] });
      after = id;
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (!Object.prototype.hasOwnProperty.call(SCREENS, id)) return previousGo.apply(this, arguments);

    state.screen = id;
    try { cur = id; } catch (e) {}   // the console's own "current screen", for its deep-link replay
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === id); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = SCREENS[id];
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    var h = host();
    if (h) { h.innerHTML = ''; h.scrollTop = 0; }

    if (id === 'emails') overview();
    else if (id === 'emails-sending') sending();
    else if (id === 'emails-branding') branding();
    else sent();
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntries);
  else addNavEntries();
})();
