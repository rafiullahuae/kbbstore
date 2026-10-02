{{--
    Store → Customers → Send account invite. (Lane PQ)

    THE OWNER'S ASK: "for all guests orders, i want an option to send their
    account logins with temporary password with reset link etc, but manually i
    need to select all guests press on send email i will need to see the email
    template and can be edited."

    WHAT THIS FILE IS. The dialog the Customers screen opens from its bulk bar
    (rows ticked, or "select all N matching this view") and from one customer's
    page. It shows who would get an invite and who would be skipped and why,
    the subject and body as editable text with the placeholders explained
    beside them, the REAL email for the first recipient (rendered server-side by
    the Mailable that sends it, shown in a sandboxed iframe), "Save as
    template", and "Send", which confirms with the count and then drives the
    send one batch at a time with a progress bar and every failure's reason.

    It draws its own overlay rather than using #modal: that box is 560px wide,
    and an editor beside a preview needs about twice that. One column below
    960px, two above, by a media query — nothing here measures layout.

    RESUMABLE. Each recipient is a row on the server. Closing the tab stops the
    sending; the Customers screen then shows "An invite send stopped at X of Y"
    with Resume and Cancel, from GET /runs/current.

    Everything the server returns is printed through esc(). The preview HTML is
    the one exception that is not escaped — it is an email, rendered by the
    server with every value escaped there — and it goes into an iframe's srcdoc
    with an EMPTY sandbox attribute, so even a hostile string could not run
    script or reach this console.

    The routes are all `customers.invite` (owner, manager). A support account
    sees the button and is told, from the 403, that it cannot send.
--}}
<style>
.kbi-bg{position:fixed;inset:0;background:rgba(16,23,41,.5);z-index:120;display:none;align-items:flex-start;justify-content:center;padding:24px 16px;overflow:auto}
.kbi-bg.on{display:flex}
.kbi{background:var(--surface);border-radius:var(--r);box-shadow:var(--sh-l);width:100%;max-width:1120px;margin:auto 0}
.kbi-h{display:flex;align-items:center;gap:11px;padding:16px 20px;border-bottom:1px solid var(--border)}
.kbi-h b{font-size:15px;font-weight:700}
.kbi-h .x{margin-left:auto;width:30px;height:30px;border-radius:8px;display:grid;place-items:center;color:var(--ink-soft)}
.kbi-b{padding:18px 20px}
.kbi-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:18px}
@media (min-width:960px){.kbi-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}
.kbi-sum{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;font-size:12.5px;color:var(--ink-2);padding:12px 14px;border:1px solid var(--border);border-radius:12px;margin-bottom:16px;background:var(--surface-2)}
.kbi-sum b{font-size:13px;color:var(--ink)}
.kbi-lbl{display:block;font-size:11.5px;font-weight:600;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.04em;margin:0 0 6px}
.kbi input[type=text],.kbi input[type=number],.kbi textarea{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;padding:9px 11px;font:inherit;font-size:13px;background:var(--surface);color:var(--ink)}
.kbi textarea{min-height:300px;resize:vertical;line-height:1.55}
.kbi-ph{display:grid;grid-template-columns:auto minmax(0,1fr);gap:6px 10px;font-size:12px;color:var(--ink-2);margin-top:12px;align-items:baseline}
.kbi-ph button{font-family:var(--mono,monospace);font-size:11.5px;padding:3px 7px;border-radius:7px;background:var(--surface-3);color:var(--ink);white-space:nowrap;text-align:left}
.kbi-ph .req{color:var(--accent-ink);font-weight:600}
.kbi-err{font-size:12.5px;color:var(--red);margin-top:10px}
.kbi-prev{border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#fff}
.kbi-prev-h{font-size:12px;color:var(--ink-soft);padding:10px 12px;border-bottom:1px solid var(--border);background:var(--surface-2);line-height:1.5;word-break:break-word}
.kbi-prev-h b{color:var(--ink)}
.kbi-prev iframe{display:block;width:100%;height:560px;border:0;background:#fff}
.kbi-f{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;align-items:center;padding:14px 20px;border-top:1px solid var(--border)}
.kbi-f .grow{flex:1;min-width:160px;font-size:12px;color:var(--ink-soft)}
.kbi-bar{height:10px;border-radius:99px;background:var(--surface-3);overflow:hidden;margin:12px 0}
.kbi-bar i{display:block;height:100%;background:var(--accent,#2f9e6d);border-radius:99px;transition:width .3s}
.kbi-fails{max-height:240px;overflow:auto;border:1px solid var(--border);border-radius:10px;margin-top:12px}
.kbi-fails table{width:100%;font-size:12px}
.kbi-fails td{padding:7px 10px;border-top:1px solid var(--border);vertical-align:top;word-break:break-word}
.kbi-banner{margin-bottom:14px}
</style>

<div class="kbi-bg" id="kbiBg" role="dialog" aria-modal="true" aria-labelledby="kbiTitle"><div class="kbi" id="kbi"></div></div>

<script>
(function () {
  'use strict';

  var ROOT = '/admin-api/customers/invites';
  var S = null;          // the open dialog's state
  var current = null;    // the unfinished run, for the Customers screen banner
  var typing = null, seq = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function plural(n, one, many) { return num(n) + ' ' + (n === 1 ? one : many); }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }

  async function call(path, body) {
    var opts = { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var r = await fetch(base() + ROOT + path, opts);
    var data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (!r.ok) {
      var err = new Error((data && data.message) || ('The server answered ' + r.status + '.'));
      err.status = r.status; err.body = data;
      throw err;
    }
    return data;
  }

  function why(e) {
    if (e && e.status === 403) return 'Only an owner or a manager can send account invites. Ask the shop owner to send them, or to change your role in Settings → Users.';
    if (e && e.status === 419) return 'The admin session expired. Reload the page and try again.';
    /* A 404 with NO message and NO error is Laravel saying the path is not in
       the route table at all: the package's routes are not live yet. A 404
       from the controller itself carries its own message ("No such send."). */
    var silent = !(e && e.body && (e.body.message || e.body.error));
    if (e && e.status === 404 && silent) return 'The account-invite endpoints are not in this server\u2019s compiled route table yet. Clear the route cache (Platform \u2192 Cache) and reload.';
    return (e && e.message) || 'Something went wrong.';
  }

  function toast(msg, kind) { if (window.toast) window.toast(esc(msg), kind); }

  function show(html) {
    document.getElementById('kbi').innerHTML = html;
    document.getElementById('kbiBg').classList.add('on');
  }
  function hide() {
    document.getElementById('kbiBg').classList.remove('on');
    if (S && S.pump) S.stop = true;
    var done = S && S.changed;
    var cb = S && S.onClose;
    S = null;
    if (done && cb) cb();
  }

  function selectionBody() {
    return S.allMatching ? { all_matching: true, filters: S.filters || {} } : { ids: S.ids };
  }

  /* ------------------------------------------------------------- open */

  async function open(opts) {
    S = {
      ids: opts.ids || [], allMatching: !!opts.allMatching, filters: opts.filters || {},
      total: opts.total || (opts.ids || []).length, onClose: opts.onDone || null,
      prep: null, subject: '', body: '', expiry: 7, includeRecent: false, preview: null,
      problems: {}, view: 'edit', run: null, err: '', changed: false, stop: false
    };

    show(head('Send account invite') + '<div class="kbi-b"><p style="font-size:13px;color:var(--ink-soft)">Counting who can be sent an invite…</p></div>');

    try {
      S.prep = await call('/prepare', selectionBody());
    } catch (e) {
      show(head('Send account invite') + '<div class="kbi-b"><p class="kbi-err">' + esc(why(e)) + '</p></div>' +
        '<div class="kbi-f"><button class="btn ghost" data-kbi="close">Close</button></div>');
      bind();
      return;
    }

    if (S.prep.unfinished_run) {
      S.run = S.prep.unfinished_run;
      S.view = 'progress';
      S.resumable = true;
      paint();
      return;
    }

    S.subject = S.prep.template.subject;
    S.body = S.prep.template.body;
    S.expiry = S.prep.template.expiry_days;
    paint();
    refreshPreview(true);
  }

  function head(title) {
    return '<div class="kbi-h"><b id="kbiTitle">' + esc(title) + '</b><button class="x" data-kbi="close" aria-label="Close">✕</button></div>';
  }

  function eligible() {
    if (!S.prep) return 0;
    return S.prep.eligible + (S.includeRecent ? S.prep.recent : 0);
  }

  function summary() {
    var p = S.prep;
    var bits = ['<b>' + plural(p.selected, 'customer', 'customers') + ' selected</b>',
      '<span><b>' + num(eligible()) + '</b> will be sent an invite</span>'];
    if (p.has_password) bits.push('<span>' + plural(p.has_password, 'already has', 'already have') + ' a password — skipped</span>');
    if (p.invalid_email) bits.push('<span>' + num(p.invalid_email) + ' with no usable email address — skipped</span>');
    if (p.missing) bits.push('<span>' + num(p.missing) + ' in the trash — skipped</span>');
    if (p.recent) {
      bits.push('<label style="cursor:pointer"><input type="checkbox" id="kbiRecent" style="vertical-align:-2px;margin-right:6px"' + (S.includeRecent ? ' checked' : '') + '> ' +
        num(p.recent) + ' invited in the last ' + p.recent_minutes + ' minutes — ' + (S.includeRecent ? '<b>will be sent again</b>' : 'skipped (tick to send again)') + '</label>');
    }
    return '<div class="kbi-sum">' + bits.join('<span style="color:var(--ink-faint)">·</span>') + '</div>';
  }

  function legend() {
    var ph = S.prep.placeholders || {};
    return '<div class="kbi-ph">' + Object.keys(ph).map(function (k) {
      return '<button type="button" data-kbiins="' + esc(k) + '" title="Insert at the cursor">{' + esc(k) + '}</button>' +
        '<span' + (k === 'set_password_link' ? ' class="req"' : '') + '>' + esc(ph[k]) + '</span>';
    }).join('') + '</div>';
  }

  function previewPane() {
    var p = S.preview;
    var who = S.prep.preview_customer;
    return '<div><span class="kbi-lbl">Preview' + (who ? ' — for ' + esc(who.name) : '') + '</span>' +
      '<div class="kbi-prev"><div class="kbi-prev-h">' +
        '<div>To: <b>' + esc(p ? p.to : (who ? who.email : '')) + '</b></div>' +
        '<div>Subject: <b id="kbiPrevSubject">' + esc(p ? p.subject : '') + '</b></div>' +
        '<div style="margin-top:4px">The button in the preview does not work. Each customer gets their own link when the email is sent.</div>' +
      '</div>' +
      '<iframe id="kbiFrame" sandbox="" title="Email preview" srcdoc="' + esc(p ? '<!doctype html><meta charset=utf-8><body style=\'margin:16px\'>' + p.html : '') + '"></iframe>' +
      '</div></div>';
  }

  function problemsList() {
    var keys = Object.keys(S.problems || {});
    if (!keys.length) return '';
    return '<div class="kbi-err">' + keys.map(function (k) { return esc(S.problems[k]); }).join('<br>') + '</div>';
  }

  function paint() {
    if (!S) return;
    if (S.view === 'progress') { paintProgress(); return; }

    var n = eligible();
    var blocked = !!Object.keys(S.problems || {}).length;

    var footer = S.view === 'confirm'
      ? '<div class="kbi-f"><span class="grow" style="color:var(--ink-2);font-size:13px">Send the invite to <b>' + plural(n, 'customer', 'customers') + '</b> now? Each gets a link that expires in ' + plural(S.expiry, 'day', 'days') + '. This emails them straight away.</span>' +
          '<button class="btn ghost" data-kbi="back">Back</button>' +
          '<button class="btn" data-kbi="go">Yes, send ' + plural(n, 'email', 'emails') + '</button></div>'
      : '<div class="kbi-f"><span class="grow">' + (S.err ? '<span style="color:var(--red)">' + esc(S.err) + '</span>' : 'Sent in batches from this tab, with progress. If you close it, Resume carries on from where it stopped.') + '</span>' +
          '<button class="btn ghost" data-kbi="close">Cancel</button>' +
          '<button class="btn" data-kbi="send"' + (n < 1 || blocked ? ' disabled' : '') + '>Send to ' + plural(n, 'customer', 'customers') + '…</button></div>';

    show(head('Send account invite') +
      '<div class="kbi-b">' + summary() +
      '<div class="kbi-grid"><div>' +
        '<label class="kbi-lbl" for="kbiSubject">Subject</label>' +
        '<input type="text" id="kbiSubject" maxlength="200" value="' + esc(S.subject) + '">' +
        '<label class="kbi-lbl" for="kbiBody" style="margin-top:14px">Email</label>' +
        '<textarea id="kbiBody" spellcheck="true">' + esc(S.body) + '</textarea>' +
        problemsList() +
        '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:12px">' +
          '<label class="kbi-lbl" for="kbiExpiry" style="margin:0">Link expires after</label>' +
          '<input type="number" id="kbiExpiry" min="1" max="30" step="1" value="' + esc(S.expiry) + '" style="width:76px"> <span style="font-size:12.5px;color:var(--ink-2)">days (1–30)</span>' +
        '</div>' +
        '<span class="kbi-lbl" style="margin-top:16px">Placeholders — click one to insert it</span>' + legend() +
        '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">' +
          '<button class="btn ghost sm" data-kbi="save"' + (blocked ? ' disabled' : '') + '>Save as template</button>' +
          '<button class="btn ghost sm" data-kbi="defaults">Restore the default wording</button>' +
        '</div>' +
        '<p style="font-size:11.5px;color:var(--ink-soft);margin-top:10px">No password is emailed. Each customer gets a one-time link to choose their own: it works once, expires, and stops working if a newer invite is sent.</p>' +
      '</div>' + previewPane() + '</div></div>' + footer);

    bind();
  }

  function bind() {
    var root = document.getElementById('kbi');
    root.querySelectorAll('[data-kbi]').forEach(function (b) {
      b.onclick = function () { act(b.getAttribute('data-kbi')); };
    });
    root.querySelectorAll('[data-kbiins]').forEach(function (b) {
      b.onclick = function () { insert('{' + b.getAttribute('data-kbiins') + '}'); };
    });
    var subject = document.getElementById('kbiSubject');
    var body = document.getElementById('kbiBody');
    var expiry = document.getElementById('kbiExpiry');
    var recent = document.getElementById('kbiRecent');
    if (subject) subject.oninput = function () { S.subject = subject.value; refreshPreview(false); };
    if (body) body.oninput = function () { S.body = body.value; refreshPreview(false); };
    if (expiry) expiry.oninput = function () { S.expiry = Math.max(1, Math.min(30, parseInt(expiry.value, 10) || 7)); refreshPreview(false); };
    if (recent) recent.onchange = function () { S.includeRecent = recent.checked; keepEditing(); };
  }

  /* Repaint without losing the caret: only the parts that changed. */
  function keepEditing() {
    var body = document.getElementById('kbiBody');
    var pos = body ? [body.selectionStart, body.selectionEnd, body === document.activeElement] : null;
    paint();
    var again = document.getElementById('kbiBody');
    if (pos && again && pos[2]) { again.focus(); again.setSelectionRange(pos[0], pos[1]); }
  }

  function insert(text) {
    var body = document.getElementById('kbiBody');
    if (!body) return;
    var a = body.selectionStart, z = body.selectionEnd;
    body.value = body.value.slice(0, a) + text + body.value.slice(z);
    body.focus();
    body.setSelectionRange(a + text.length, a + text.length);
    S.body = body.value;
    refreshPreview(false);
  }

  function refreshPreview(now) {
    clearTimeout(typing);
    typing = setTimeout(async function () {
      if (!S) return;
      var mine = ++seq;
      try {
        var who = S.prep.preview_customer;
        var out = await call('/preview', { customer_id: who ? who.id : null, subject: S.subject, body: S.body, expiry_days: S.expiry });
        if (!S || mine !== seq) return;
        var hadProblems = Object.keys(S.problems || {}).length;
        S.preview = out;
        S.problems = out.problems || {};
        var frame = document.getElementById('kbiFrame');
        var subj = document.getElementById('kbiPrevSubject');
        if (frame && subj && hadProblems === Object.keys(S.problems).length) {
          frame.setAttribute('srcdoc', '<!doctype html><meta charset=utf-8><body style=\'margin:16px\'>' + out.html);
          subj.textContent = out.subject;
        } else {
          keepEditing();
        }
      } catch (e) {
        if (S) { S.err = why(e); keepEditing(); }
      }
    }, now ? 0 : 450);
  }

  async function act(what) {
    if (what === 'close') { hide(); return; }
    if (what === 'back') { S.view = 'edit'; paint(); return; }
    if (what === 'defaults') {
      S.subject = S.prep.defaults.subject; S.body = S.prep.defaults.body; S.expiry = S.prep.defaults.expiry_days;
      paint(); refreshPreview(true); return;
    }
    if (what === 'save') {
      try {
        await call('/template', { subject: S.subject, body: S.body, expiry_days: S.expiry });
        toast('Saved as the invite template');
      } catch (e) {
        if (e.body && e.body.errors) { S.problems = e.body.errors; keepEditing(); }
        toast(why(e), 'bad');
      }
      return;
    }
    if (what === 'send') { S.view = 'confirm'; S.err = ''; paint(); return; }
    if (what === 'go') { await start(); return; }
    if (what === 'resume') { S.view = 'progress'; pump(); return; }
    if (what === 'pause') { S.stop = true; return; }
    if (what === 'cancelrun') {
      if (!confirm('Stop this send? Nobody else will be emailed. Those already sent keep their link.')) return;
      S.stop = true;
      try { S.run = (await call('/runs/' + S.run.id + '/cancel', {})).run; } catch (e) { S.err = why(e); }
      S.changed = true; current = null; paintProgress(); return;
    }
  }

  async function start() {
    var body = Object.assign(selectionBody(), {
      subject: S.subject, body: S.body, expiry_days: S.expiry,
      include_recent: S.includeRecent, expected: eligible()
    });
    try {
      var out = await call('/send', body);
      S.run = out.run; S.view = 'progress'; S.changed = true;
      pump();
    } catch (e) {
      if (e.status === 409 && e.body && e.body.needs_confirmation) {
        S.prep = await call('/prepare', selectionBody());
        S.view = 'edit'; S.err = e.body.message; paint();
        return;
      }
      if (e.status === 409 && e.body && e.body.unfinished_run) {
        S.run = e.body.unfinished_run; S.view = 'progress'; S.resumable = true; paint(); return;
      }
      if (e.body && e.body.errors) S.problems = e.body.errors;
      S.view = 'edit'; S.err = why(e); paint();
    }
  }

  /* One step at a time until done, paused, or three failures in a row. */
  async function pump() {
    var st = S;
    if (!st || !st.run || st.pump) return;
    st.pump = true; st.stop = false; st.resumable = false; st.err = '';
    var misses = 0;
    paintProgress();
    while (!st.stop && st.run && !st.run.done) {
      try {
        st.run = (await call('/runs/' + st.run.id + '/step', {})).run;
        misses = 0;
      } catch (e) {
        misses++;
        if (misses >= 3) { st.err = why(e) + ' Sending paused — press Resume to carry on.'; break; }
        await new Promise(function (r) { setTimeout(r, 1500 * misses); });
      }
      if (S === st) paintProgress();
    }
    st.pump = false;
    current = st.run && !st.run.done ? st.run : null;
    if (S === st) { if (!st.run.done) st.resumable = true; paintProgress(); }
    else paintBanner();
  }

  function paintProgress() {
    if (!S || !S.run) return;
    var r = S.run;
    var finished = r.sent + r.failed + r.skipped_during;
    var pct = r.total ? Math.round(finished * 100 / r.total) : 100;
    var state = r.done
      ? (r.status === 'cancelled' ? 'Stopped. ' : 'Done. ') + plural(r.sent, 'invite', 'invites') + ' sent' + (r.failed ? ', ' + num(r.failed) + ' failed' : '') + '.'
      : (S.pump ? 'Sending… keep this tab open. If you close it, press Resume on Store → Customers to carry on.' : 'Paused at ' + num(finished) + ' of ' + num(r.total) + '.');

    var skippedUpFront = [];
    if (r.skipped_password) skippedUpFront.push(num(r.skipped_password) + ' already had a password');
    if (r.skipped_email) skippedUpFront.push(num(r.skipped_email) + ' had no usable email');
    if (r.skipped_recent) skippedUpFront.push(plural(r.skipped_recent, 'was', 'were') + ' invited minutes earlier');

    var fails = (r.failures || []);

    show(head('Sending account invites') +
      '<div class="kbi-b">' +
        '<div style="font-size:14px;font-weight:600">' + num(r.sent) + ' of ' + num(r.total) + ' sent' +
          (r.failed ? ' · <span style="color:var(--red)">' + num(r.failed) + ' failed</span>' : '') +
          (r.skipped_during ? ' · ' + num(r.skipped_during) + ' skipped' : '') + '</div>' +
        '<div class="kbi-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><i style="width:' + pct + '%"></i></div>' +
        '<p style="font-size:12.5px;color:var(--ink-2)">' + esc(state) + '</p>' +
        (skippedUpFront.length ? '<p style="font-size:12px;color:var(--ink-soft);margin-top:6px">Skipped before sending: ' + esc(skippedUpFront.join(' · ')) + '.</p>' : '') +
        (S.err ? '<p class="kbi-err">' + esc(S.err) + '</p>' : '') +
        (fails.length ? '<div class="kbi-fails"><table><tbody>' + fails.map(function (f) {
          return '<tr><td style="width:38%"><b>' + esc(f.label) + '</b><div style="color:var(--ink-soft)">' + esc(f.email) + '</div></td>' +
            '<td><span class="pill ' + (f.status === 'failed' ? 'red' : 'grey') + '">' + (f.status === 'failed' ? 'Failed' : 'Skipped') + '</span> ' + esc(f.reason) + '</td></tr>';
        }).join('') + '</tbody></table></div>' : '') +
      '</div>' +
      '<div class="kbi-f"><span class="grow"></span>' +
        (!r.done && S.pump ? '<button class="btn ghost" data-kbi="pause">Pause</button>' : '') +
        (!r.done && !S.pump ? '<button class="btn ghost" data-kbi="cancelrun" style="color:var(--red)">Cancel the rest</button><button class="btn" data-kbi="resume">Resume</button>' : '') +
        (r.done || !S.pump ? '<button class="btn ghost" data-kbi="close">Close</button>' : '') +
      '</div>');
    bind();
  }

  /* --------------------------------------------- the Customers screen banner */

  async function refreshBanner() {
    try { current = (await call('/runs/current')).run; } catch (e) { current = null; }
    paintBanner();
  }

  function paintBanner() {
    var el = document.getElementById('cuInviteBanner');
    if (!el) return;
    if (!current || current.done) { el.innerHTML = ''; return; }
    var finished = current.sent + current.failed + current.skipped_during;
    el.innerHTML = '<div class="card pad kbi-banner" style="border-color:#f0dcae;background:var(--amber-soft);display:flex;flex-wrap:wrap;gap:10px;align-items:center">' +
      '<span style="font-size:12.5px;flex:1;min-width:220px"><b>An account-invite send stopped at ' + num(finished) + ' of ' + num(current.total) + '.</b> ' +
      'Nobody is emailed twice: Resume carries on with the ' + plural(current.pending, 'customer', 'customers') + ' still waiting.</span>' +
      '<button class="btn sm" id="kbiBannerResume">Resume sending</button></div>';
    document.getElementById('kbiBannerResume').onclick = function () {
      S = { run: current, view: 'progress', onClose: window.renderCustomers || null, changed: true, stop: false };
      pump();
    };
  }

  document.getElementById('kbiBg').addEventListener('click', function (e) { if (e.target === this && S && !S.pump) hide(); });

  window.kbbCustomerInvite = { open: open, refreshBanner: refreshBanner, paintBanner: paintBanner };

  // Opened straight onto #customers: that screen painted before this script
  // existed, so ask for the banner now.
  if (document.getElementById('cuInviteBanner')) refreshBanner();
})();
</script>
