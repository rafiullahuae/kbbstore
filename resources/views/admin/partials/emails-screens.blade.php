{{--
    Emails → Overview · Sending & delivery · Design & branding · Sent mail
                                                         Lane RK, package E1

    The owner: "put all these settings etc under a new parent menu "Emails"".

    FOUR SCREENS, ONE PARTIAL, NO NEW STORE. Every value is a MailSettings key
    and is written through App\Http\Controllers\Admin\EmailsApiController,
    which hands it to MailSettings::save() — the writer Store → Mail has always
    used. The old screen keeps working on the same rows (it moves into this
    menu as "All mail settings" until E2's Customer emails replaces its order-
    status switches).

    SENT MAIL IS THE OLD SCREEN'S OWN LOG, NOT A COPY OF IT. It draws the same
    #mlLog / #mlLogFilter / #mlBacklog containers and calls loadMailLog() and
    loadOutboundBacklog() from app.blade.php, which are global function
    declarations there. One renderer, two doors.

    Pulled into app.blade.php at the end, after its raw block closes, like the
    screens beside it: it wraps window.go for its four ids and registers its
    sidebar rows (a no-op once the integrator has declared them in NAV).

    EVERY STRING FROM THE SERVER IS ESCAPED through esc() before it reaches
    innerHTML — addresses, the test-send's error text and the footer preview
    are operator- or transport-supplied. The Google app password is never in
    any response; the box shows only whether one is stored.
--}}
@verbatim
<style>
.eml{display:grid;gap:0;min-width:0}
.eml > *{min-width:0}
.eml-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.eml-tile{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);border-radius:var(--r,18px);padding:15px 16px;min-width:0}
.eml-tile .k{display:block;font-size:12px;color:var(--ink-soft,#626c80)}
.eml-tile .v{display:block;font-size:19px;font-weight:700;margin-top:4px;color:var(--ink,#101729);overflow-wrap:anywhere}
.eml-pill{display:inline-block;margin-top:8px;font-size:11.5px;font-weight:650;border-radius:999px;padding:3px 9px;background:var(--surface-2,#f2f4fb);color:var(--ink-2,#3c465c)}
.eml-pill.ok{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.eml-pill.bad{background:#fde8e6;color:#a6261c}
.eml-pill.warn{background:#fff3dc;color:#8a5a00}
.eml-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.eml-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);border-radius:var(--r,18px);padding:16px 18px;display:grid;gap:8px;align-content:start;min-width:0}
.eml-card h3{font-size:14px;font-weight:650;margin:0;color:var(--ink,#101729)}
.eml-card p{font-size:12.5px;line-height:1.5;margin:0;color:var(--ink-soft,#626c80)}
.eml-card .btn{justify-self:start}
.eml-list{display:grid;min-width:0}
.eml-row{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr) minmax(0,1.5fr) auto minmax(0,1.6fr);gap:10px;align-items:center;padding:9px 0;font-size:12.5px;border-top:1px solid var(--border-2,#eef0f6);min-width:0}
.eml-row > *{min-width:0;overflow-wrap:anywhere}
.eml-row.head{border-top:0;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-faint,#97a0b2);font-weight:700;padding-top:0}
.eml-row b{font-weight:650;color:var(--ink,#101729)}
.eml-row .soft{color:var(--ink-soft,#626c80)}
.eml-row .eml-pill{margin-top:0}
.eml-two{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:16px;align-items:start}
.eml-two > *{min-width:0;display:grid;gap:16px}
.eml-choice{display:flex;gap:11px;align-items:flex-start;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);padding:11px 13px;cursor:pointer;min-width:0}
.eml-choice + .eml-choice{margin-top:8px}
.eml-choice input{margin:2px 0 0;flex:0 0 auto;accent-color:var(--accent,#15a85a)}
.eml-choice.on{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee)}
.eml-choice .t{display:block;font-size:13px;font-weight:650;color:var(--ink,#101729)}
.eml-choice .d{display:block;font-size:11.5px;line-height:1.45;color:var(--ink-soft,#626c80);margin-top:2px}
.eml-save{display:flex;gap:12px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin-top:16px}
.eml-dirty{font-size:12px;color:var(--ink-soft,#626c80)}
.eml-note{border-radius:var(--r-xs,9px);padding:10px 13px;font-size:12.5px;line-height:1.5;background:#fff3dc;color:#6b4700;margin-bottom:14px}
.eml-note.ok{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.eml-saved{display:inline-block;font-size:11px;font-weight:650;border-radius:999px;padding:2px 8px;background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a);margin-left:6px}
.eml textarea.mlf-input{resize:vertical;min-height:74px;line-height:1.45}
.eml-foot{border-radius:12px;background:#FFF8F5;padding:14px;color:#2A2228;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;min-width:0}
.eml-foot .sup{background:#FFF0F4;border-radius:10px;padding:13px 14px}
.eml-foot .sup b{display:block;font-size:14px}
.eml-foot .ch{display:flex;gap:9px;align-items:center;margin-top:8px;font-size:13px;min-width:0}
.eml-foot .ch span.i{flex:0 0 22px;height:22px;border-radius:11px;color:#fff;font-size:11px;font-weight:700;display:grid;place-items:center;background:#C13E63}
.eml-foot .ch span.i.w{background:#2E9E6B}
.eml-foot .ch a{color:#2A2228;font-weight:600;text-decoration:none;overflow-wrap:anywhere;min-width:0}
.eml-foot .small{border-top:1px solid #F0E4E9;margin-top:14px;padding-top:11px;font-size:11.5px;line-height:1.6;color:#8C828A}
.eml-foot .small b{color:#5E545A}
@media (max-width:900px){
  .eml-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}
  .eml-two{grid-template-columns:minmax(0,1fr)}
}
@media (max-width:640px){
  .eml-tiles,.eml-cards{grid-template-columns:minmax(0,1fr)}
  .eml-row{grid-template-columns:minmax(0,1fr) auto;gap:2px 10px}
  .eml-row.head{display:none}
  .eml-row > .c-to,.eml-row > .c-when,.eml-row > .c-where{grid-column:1 / -1;font-size:12px}
  .eml-row > .c-state{grid-row:1;grid-column:2}
}
</style>

<script>
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

  var state = { screen: null, sending: null, branding: null, overview: null, busy: false };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/emails';
  }

  function when(iso) { try { return new Date(iso).toLocaleString(); } catch (e) { return String(iso || ''); } }

  function toastMsg(text) { try { if (typeof window.toast === 'function') window.toast(text); } catch (e) {} }

  /* One fetch helper. 422 bodies are returned, not thrown: a refused value is
     an answer the screen shows next to the Save button. */
  async function api(path, body) {
    var opts = {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }
    };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(base() + path, opts);
    var data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (r.ok || r.status === 422) return { status: r.status, data: data || {} };
    var err = new Error('emails ' + r.status); err.status = r.status; throw err;
  }

  function why(e) {
    if (e && e.status === 403) return 'Your role cannot open the Emails screens. The shop owner can.';
    if (e && e.status === 404) return 'This screen is not in the server\'s route table yet. Clear the route cache (Platform → Cache) and reload.';
    if (e && e.status === 429) return 'Too many test sends in one minute. Wait a moment and try again.';
    return 'The request did not complete. Try again in a moment.';
  }

  function refusal(d) {
    if (!d) return 'Could not save.';
    if (d.error) return d.error;
    if (d.errors) { var k = Object.keys(d.errors)[0]; if (k) return String(d.errors[k][0] || d.message || 'Could not save.'); }
    return d.message || 'Could not save.';
  }

  function host() { return document.getElementById('content'); }

  function shell(title, lead, inner) {
    return '<div class="wrap eml" data-eml="' + esc(state.screen) + '"><div class="page-head"><h2>' + esc(title) + '</h2><p>' + lead + '</p></div>' + inner + '</div>';
  }

  function loading(title) {
    var h = host(); if (!h) return;
    h.innerHTML = shell(title, 'Loading…', '');
  }

  function failed(title, e) {
    var h = host(); if (!h) return;
    h.innerHTML = shell(title, esc(why(e)), '<div><button class="btn ghost" type="button" data-eml-go="' + esc(state.screen) + '">Retry</button></div>');
  }

  /* ---------------------------------------------------------------- overview */

  var TRANSPORT_NAMES = { server: 'Server mail', gmail: 'Google Workspace', smtp: 'Dedicated SMTP', log: 'Not sending' };

  async function overview() {
    loading('Emails');
    try { state.overview = (await api('/overview')).data; } catch (e) { return failed('Emails', e); }
    if (state.screen !== 'emails') return;
    var d = state.overview, t = d.transport || {}, lt = d.last_test, s7 = d.sent_7d || { sent: 0, failed: 0 };
    var emails = d.emails || [];
    var switchable = emails.filter(function (m) { return m.state === 'on' || m.state === 'off'; });
    var on = switchable.filter(function (m) { return m.state === 'on'; }).length;

    var testPill = !lt ? '<span class="eml-pill warn">No test sent yet</span>'
      : (lt.ok ? '<span class="eml-pill ok">Last test: accepted ' + esc(when(lt.at)) + '</span>'
               : '<span class="eml-pill bad">Last test failed ' + esc(when(lt.at)) + '</span>');
    var fallback = (t.key !== t.active && t.active === 'server')
      ? '<div class="eml-note">' + esc(t.label) + ' is chosen but not finished (still needed: ' + esc((t.missing || []).join(', ')) + '), so email is going out through this server\'s mail until it is. <a href="#" data-eml-go="emails-sending">Finish it</a>.</div>'
      : '';

    var tiles = '<div class="eml-tiles">'
      + '<div class="eml-tile"><span class="k">Sending through</span><span class="v">' + esc(TRANSPORT_NAMES[t.key] || t.label) + '</span>' + testPill + '</div>'
      + '<div class="eml-tile"><span class="k">Sent from</span><span class="v" style="font-size:15px">' + esc(d.from || '—') + '</span><span class="eml-pill">From address</span></div>'
      + '<div class="eml-tile"><span class="k">Sent, last 7 days</span><span class="v">' + esc(String(s7.sent)) + '</span><span class="eml-pill ' + (s7.failed ? 'bad' : 'ok') + '">' + esc(String(s7.failed)) + ' failed</span></div>'
      + '<div class="eml-tile"><span class="k">Emails switched on</span><span class="v">' + on + ' of ' + switchable.length + '</span><span class="eml-pill">+ ' + (emails.length - switchable.length) + ' always on or manual</span></div>'
      + '</div>';

    function card(id, title, text, primary) {
      return '<div class="eml-card"><h3>' + esc(title) + '</h3><p>' + text + '</p><button type="button" class="btn ' + (primary ? '' : 'ghost') + ' small" data-eml-go="' + esc(id) + '">Open</button></div>';
    }
    var cards = '<div class="eml-cards">'
      + card('emails-sending', 'Sending & delivery', 'This server\'s mail or Google Workspace, the From name and address, Reply-To, new-order alerts, and <b>Send a test email</b>. Moved here from Store → Mail.', true)
      + card('emails-branding', 'Design & branding', 'The contact details at the foot of every order email: your email, WhatsApp, and the Dubai and Korea addresses.', true)
      + card('emails-sent', 'Sent mail', 'Every message and what the mail server answered, plus back-in-stock alerts and basket reminders still waiting to go. Moved here from Store → Mail.', false)
      + card('mail', 'All mail settings', 'The full Store → Mail screen, unchanged: order-status email switches, the cancellation and dispatch notes, Instagram and the signature.', false)
      + '</div>';

    var STATE = { on: ['ok', 'On'], off: ['', 'Off'], manual: ['', 'You send it'], always: ['ok', 'Always on'] };
    var rows = '<div class="eml-list"><div class="eml-row head"><span>Email</span><span>To</span><span>Sent when</span><span>State</span><span>Switched in</span></div>'
      + emails.map(function (m) {
          var st = STATE[m.state] || ['', m.state];
          return '<div class="eml-row"><b>' + esc(m.name) + '</b><span class="c-to soft">' + esc(m.to) + '</span><span class="c-when soft">' + esc(m.when) + '</span>'
            + '<span class="c-state"><span class="eml-pill ' + st[0] + '">' + esc(st[1]) + '</span></span><span class="c-where soft">' + esc(m.where) + '</span></div>';
        }).join('') + '</div>';

    host().innerHTML = shell('Emails',
      'Everything the shop sends, in one place: how it is sent, who it comes from, what the footer says, and what went out.',
      fallback + tiles + '<div class="sec-title">Where things are</div>' + cards
      + '<div class="sec-title">What the shop sends</div><div class="mlf-card">' + rows
      + '<p class="mlf-help" style="margin-top:12px">Read-only here for now. Each row says where it is switched today; one screen for all of them (Emails → Customer emails) comes with the next package.</p></div>');
  }

  /* ----------------------------------------------------------------- sending */

  var CHOICE_TEXT = {
    server: 'PHP mail() handed to this server\'s own mail program. Needs nothing filled in. This is what the shop uses unless you change it.',
    gmail: 'Sends through your Google Workspace mailbox: smtp.gmail.com, port 587, STARTTLS. Needs the account address and an app password.',
    smtp: 'Your current setting. It stays exactly as it is until you pick one of the two above; its server details are under Emails → All mail settings.',
    log: 'Your current setting: nothing is delivered, every message is written to the log. Pick one of the two above to send for real.'
  };

  function sendingForm() {
    var d = state.sending, v = d.values || {}, chosen = state.pick || d.transport;
    var notes = '';
    if (!d.configured) {
      notes = '<div class="eml-note">Not finished — still needed: <b>' + esc((d.missing || []).join(', ')) + '</b>. Until then every email goes out through this server\'s mail, so nothing stops sending.</div>';
    }

    var choices = (d.options || []).map(function (o) {
      return '<label class="eml-choice' + (o.key === chosen ? ' on' : '') + '"><input type="radio" name="emlTransport" value="' + esc(o.key) + '"' + (o.key === chosen ? ' checked' : '') + '>'
        + '<span><span class="t">' + esc(o.label) + '</span><span class="d">' + esc(CHOICE_TEXT[o.key] || '') + '</span></span></label>';
    }).join('');

    function field(key, label, help, type) {
      return '<div class="mlf-field"><label class="mlf-label" for="eml_' + key + '">' + esc(label) + '</label>'
        + '<input class="mlf-input" type="' + (type || 'text') + '" id="eml_' + key + '" data-eml-key="' + key + '" value="' + esc(v[key] || '') + '">'
        + (help ? '<div class="mlf-help">' + help + '</div>' : '') + '</div>';
    }

    var pw = d.gmail_password && d.gmail_password.has_value;
    var gmail = '<section class="mlf-sec" data-eml-gmail' + (chosen === 'gmail' ? '' : ' hidden') + '>'
      + '<div class="mlf-sec-h"><div class="mlf-sec-t">Google Workspace</div><div class="mlf-sec-d">The server, port and encryption are fixed: smtp.gmail.com, 587, STARTTLS.</div></div>'
      + '<div class="mlf-grid">'
      + field('mail_gmail_username', 'Google account address', 'The mailbox that signs in, e.g. info@kbeautybliss.com.', 'email')
      + '<div class="mlf-field"><label class="mlf-label" for="eml_mail_gmail_password">Google app password' + (pw ? '<span class="eml-saved">saved</span>' : '') + '</label>'
      + '<input class="mlf-input" type="password" autocomplete="new-password" id="eml_mail_gmail_password" data-eml-key="mail_gmail_password" placeholder="' + (pw ? 'Saved — leave blank to keep it' : 'Not set') + '">'
      + '<div class="mlf-help">Not your normal password: Google Account → Security → 2-Step Verification → App passwords. Stored encrypted; never shown again.</div></div>'
      + '</div></section>';

    var fromHelp = chosen === 'gmail'
      ? 'Must be the Google account above or one of its aliases (Gmail → Settings → Accounts → “Send mail as”). Google rewrites any other From. Blank sends as the Google account.'
      : 'Use a mailbox on the shop\'s own domain. Blank sends as ' + esc(d.effective_from || 'no-reply@ the shop\'s domain') + '.';

    return notes
      + '<section class="mlf-sec"><div class="mlf-sec-h"><div class="mlf-sec-t">How email leaves this store</div><div class="mlf-sec-d">Pick one. Applies to every email the shop sends — order emails, password resets and account emails alike.</div></div><div>' + choices + '</div></section>'
      + gmail
      + '<section class="mlf-sec"><div class="mlf-sec-h"><div class="mlf-sec-t">Who the message comes from</div><div class="mlf-sec-d">The name and address customers see, where their replies go, and where your own new-order alerts arrive.</div></div>'
      + '<div class="mlf-grid">' + field('mail_from_name', 'From name', 'e.g. K Beauty Bliss') + field('mail_from_address', 'From address', fromHelp, 'email') + '</div>'
      + '<div class="mlf-grid">' + field('mail_reply_to', 'Reply-To', 'A mailbox somebody reads.', 'email') + field('mail_merchant_address', 'New-order alerts to', 'Blank uses the From address.', 'email') + '</div></section>'
      + '<div class="eml-save"><span class="eml-dirty" id="emlDirty" style="visibility:hidden">Unsaved changes</span><button class="btn primary" type="button" id="emlSaveSending">Save changes</button></div>';
  }

  function testResult(t) {
    if (!t) return '<p class="mlf-muted">No test has been sent from this shop yet.</p>';
    return '<div class="mlf-result ' + (t.ok ? 'is-ok' : 'is-bad') + '"><b>' + (t.ok ? 'Accepted' : 'Send failed') + '</b>'
      + '<div class="mlf-result-when">' + esc(when(t.at)) + ' → ' + esc(t.to || '') + ' · via ' + esc(TRANSPORT_NAMES[t.transport] || t.transport || '') + '</div>'
      + '<div class="mlf-result-msg">' + esc(t.message || '') + '</div>'
      + (t.error ? '<div class="mlf-result-err">' + esc(t.error) + '</div>' : '') + '</div>';
  }

  function paintSending() {
    var d = state.sending, chosenName = TRANSPORT_NAMES[d.transport] || d.transport;
    host().innerHTML = shell('Sending & delivery',
      'How email leaves the shop and who it comes from. Moved here from Store → Mail; the same settings, so nothing changes until you save.',
      '<div class="eml-two"><div><div class="mlf-card">' + sendingForm() + '</div></div>'
      + '<div><div class="mlf-card"><section class="mlf-sec"><div class="mlf-sec-h"><div class="mlf-sec-t">Send a test email</div>'
      + '<div class="mlf-sec-d">Goes through the <b>saved</b> choice — now <b>' + esc(chosenName) + '</b>. Save first if you changed it. The answer is the mail server\'s own words.</div></div>'
      + '<div class="mlf-field"><label class="mlf-label" for="emlTo">Send a test to</label><input class="mlf-input" type="email" id="emlTo" placeholder="you@example.com"></div>'
      + '<div><button class="btn primary" type="button" id="emlTest">Send test email</button></div>'
      + '<div id="emlTestResult">' + testResult(d.last_test) + '</div></section></div></div></div>');
  }

  async function sending() {
    loading('Sending & delivery');
    state.pick = null;
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

  async function saveSending(btn) {
    var picked = document.querySelector('input[name="emlTransport"]:checked');
    var payload = {
      transport: picked ? picked.value : state.sending.transport,
      settings: collect(['mail_from_name', 'mail_from_address', 'mail_reply_to', 'mail_merchant_address', 'mail_gmail_username', 'mail_gmail_password'])
    };
    btn.disabled = true;
    try {
      var r = await api('/sending', payload);
      if (r.status === 422) { toastMsg('Could not save: ' + refusal(r.data)); if (r.data && r.data.values) { state.sending = r.data; paintSending(); } }
      else { state.sending = r.data; state.pick = null; paintSending(); toastMsg('Saved'); }
    } catch (e) { toastMsg(why(e)); }
    btn.disabled = false;
  }

  async function sendTest(btn) {
    var to = (document.getElementById('emlTo') || {}).value || '';
    var out = document.getElementById('emlTestResult');
    btn.disabled = true; var was = btn.textContent; btn.textContent = 'Sending…';
    try {
      var r = await api('/test', { to: to.trim() });
      if (r.status === 422) { if (out) out.innerHTML = '<p class="mlf-muted">' + esc(refusal(r.data)) + '</p>'; }
      else if (out) out.innerHTML = testResult(r.data);
    } catch (e) { if (out) out.innerHTML = '<p class="mlf-muted">' + esc(why(e)) + '</p>'; }
    btn.disabled = false; btn.textContent = was;
  }

  /* ---------------------------------------------------------------- branding */

  function lines(v) { return String(v || '').split(/\s*\|\s*/).filter(Boolean).join('\n'); }

  function footerPreview(f) {
    var sup = (f.support || []).map(function (c) {
      var icon = c.kind === 'whatsapp' ? 'W' : (c.kind === 'instagram' ? 'I' : '@');
      return '<div class="ch"><span class="i' + (c.kind === 'whatsapp' ? ' w' : '') + '">' + icon + '</span><a>' + esc(c.value) + '</a><span style="color:#8C828A;font-size:12px">· ' + esc(c.label) + '</span></div>';
    }).join('');
    var addr = (f.addresses || []).map(function (a) {
      return '<div style="margin-top:8px"><b>' + (a.place === 'korea' ? 'Korea' : 'Dubai') + '</b><br>' + a.lines.map(esc).join('<br>') + '</div>';
    }).join('');
    return '<div class="eml-foot">'
      + (sup ? '<div class="sup"><b>We are here if you need us</b>' + sup + '</div>' : '')
      + '<div class="small">You are receiving this because an order was placed with ' + esc(f.store || '') + ' using this email address.'
      + (addr || '<div style="margin-top:8px">No address is printed yet.</div>') + '</div></div>';
  }

  function paintBranding() {
    var d = state.branding, v = d.values || {}, fb = (d.fallbacks || {}).mail_address_dubai || [];
    function input(key, label, help, type) {
      return '<div class="mlf-field"><label class="mlf-label" for="eml_' + key + '">' + esc(label) + '</label>'
        + '<input class="mlf-input" type="' + (type || 'text') + '" id="eml_' + key + '" data-eml-key="' + key + '" value="' + esc(v[key] || '') + '"><div class="mlf-help">' + help + '</div></div>';
    }
    function area(key, label, help, placeholder) {
      return '<div class="mlf-field"><label class="mlf-label" for="eml_' + key + '">' + esc(label) + '</label>'
        + '<textarea class="mlf-input" rows="3" id="eml_' + key + '" data-eml-key="' + key + '" placeholder="' + esc(placeholder) + '">' + esc(lines(v[key])) + '</textarea><div class="mlf-help">' + help + '</div></div>';
    }

    host().innerHTML = shell('Design & branding',
      'How every email signs off. This package holds the contact details; the new look for every email (Look A) arrives in the next one.',
      '<div class="eml-two"><div><div class="mlf-card"><section class="mlf-sec"><div class="mlf-sec-h"><div class="mlf-sec-t">Contact details</div>'
      + '<div class="mlf-sec-d">Printed at the foot of every order email. Change them any time; the next email uses the new details.</div></div>'
      + '<div class="mlf-grid">'
      + input('mail_support_email', 'Contact email', 'Where customers write for help. Blank falls back to the Reply-To, then the From address.', 'email')
      + input('mail_support_whatsapp', 'WhatsApp number', 'With the country code, e.g. +971 50 123 4567. Becomes a “Chat on WhatsApp” link. Blank uses the storefront\'s number.')
      + '</div>'
      + area('mail_address_dubai', 'Dubai address', fb.length ? 'One line per line. Blank uses the store address from Store → Business Details (shown greyed).' : 'One line per line. Blank prints no Dubai address — Store → Business Details has no street or city saved.', fb.join('\n'))
      + area('mail_address_korea', 'Korea address', 'One line per line. Blank prints no Korea address.', '')
      + '</section><div class="eml-save"><span class="eml-dirty" id="emlDirty" style="visibility:hidden">Unsaved changes</span><button class="btn primary" type="button" id="emlSaveBranding">Save changes</button></div></div></div>'
      + '<div><div class="mlf-card"><section class="mlf-sec"><div class="mlf-sec-h"><div class="mlf-sec-t">What the footer says</div>'
      + '<div class="mlf-sec-d">The foot of the next order email, as the server will print it. Updates when you save.</div></div>'
      + footerPreview(d.footer || {}) + '</section></div></div></div>');
  }

  async function branding() {
    loading('Design & branding');
    try { state.branding = (await api('/branding')).data; } catch (e) { return failed('Design & branding', e); }
    if (state.screen === 'emails-branding') paintBranding();
  }

  async function saveBranding(btn) {
    btn.disabled = true;
    try {
      var r = await api('/branding', { settings: collect(['mail_support_email', 'mail_support_whatsapp', 'mail_address_dubai', 'mail_address_korea']) });
      if (r.status === 422) toastMsg('Could not save: ' + refusal(r.data));
      else { state.branding = r.data; paintBranding(); toastMsg('Saved'); }
    } catch (e) { toastMsg(why(e)); }
    btn.disabled = false;
  }

  /* ---------------------------------------------------------------- sent mail */

  function sent() {
    host().innerHTML = shell('Sent mail',
      'Every message the shop has tried to send and what the mail server said back. When a customer says an email never arrived, the answer is here. Bodies are never stored.',
      '<div class="mlf-card"><div class="mlf-grid" style="margin-bottom:10px"><div class="mlf-field"><label class="mlf-label" for="mlLogFilter">Show</label>'
      + '<select class="mlf-input" id="mlLogFilter"><option value="failed">Only the ones that failed</option><option value="all">Everything</option><option value="sent">Only the ones that were accepted</option></select></div></div>'
      + '<div id="mlLog"><p class="mlf-muted">Loading…</p></div></div>'
      + '<div class="sec-title">Waiting to go out</div><div class="mlf-card"><div class="mlf-sec-d" style="margin-bottom:10px">Back-in-stock alerts and basket reminders the shop owes somebody.</div>'
      + '<div id="mlBacklog"><p class="mlf-muted">Loading…</p></div></div>');
    /* The old screen's own loaders: one renderer for the delivery record. */
    try { if (typeof loadMailLog === 'function') loadMailLog(); } catch (e) {}
    try { if (typeof loadOutboundBacklog === 'function') loadOutboundBacklog(); } catch (e) {}
  }

  /* ------------------------------------------------------------------ events */

  document.addEventListener('click', function (e) {
    if (!document.querySelector('[data-eml]')) return;
    var t = e.target;
    var go = t.closest && t.closest('[data-eml-go]');
    if (go) { e.preventDefault(); window.go(go.getAttribute('data-eml-go')); return; }
    if (t.id === 'emlSaveSending') return saveSending(t);
    if (t.id === 'emlSaveBranding') return saveBranding(t);
    if (t.id === 'emlTest') return sendTest(t);
  });

  document.addEventListener('change', function (e) {
    if (!document.querySelector('[data-eml]')) return;
    var t = e.target;
    if (t.name === 'emlTransport') {
      state.pick = t.value;
      // Keep what was typed: copy the boxes back before repainting the form.
      var typed = collect(['mail_from_name', 'mail_from_address', 'mail_reply_to', 'mail_merchant_address', 'mail_gmail_username']);
      Object.keys(typed).forEach(function (k) { state.sending.values[k] = typed[k]; });
      paintSending();
      var d = document.getElementById('emlDirty'); if (d) d.style.visibility = 'visible';
      return;
    }
    if (t.id === 'mlLogFilter') { try { if (typeof loadMailLog === 'function') loadMailLog(); } catch (err) {} }
  });

  document.addEventListener('input', function (e) {
    if (!document.querySelector('[data-eml]')) return;
    if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-eml-key')) {
      var d = document.getElementById('emlDirty'); if (d) d.style.visibility = 'visible';
    }
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
</script>
@endverbatim
