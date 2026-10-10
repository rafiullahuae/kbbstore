
(function () {
  'use strict';

  var SCREEN = 'mkt-health';
  var GROUP = 'Growth & Marketing';
  var TITLE = 'Bounces & unsubscribes';
  var TABS = [['bounced', 'Bounced'], ['unsubscribed', 'Unsubscribed'], ['complaints', 'Spam complaints'], ['watching', 'Watching'], ['setup', 'Setup'], ['dns', 'Deliverability']];
  var LISTS = { bounced: 1, unsubscribed: 1, complaints: 1, watching: 1 };

  var st = { tab: 'bounced', ov: null, list: null, q: '', page: 1, busy: false, banner: null, msg: null, seq: 0, form: null, gap: null };

  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function base() { return root() + '/admin-api/email-health'; }
  function say(m) { try { window.toast(m); } catch (e) {} }
  function el(tag, attrs, kids) {
    var x = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'text') x.textContent = v; else if (k === 'class') x.className = v;
      else if (k === 'value') x.value = v;
      else if (k.indexOf('on') === 0) x.addEventListener(k.slice(2), v); else x.setAttribute(k, v === true ? '' : String(v));
    });
    (kids || []).forEach(function (c) { if (c != null && c !== false) x.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return x;
  }
  function num(n) { return Number(n || 0).toLocaleString('en'); }
  function when(iso) { if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? iso : d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }

  async function api(method, path, body) {
    var opts = { method: method, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(base() + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('email-health ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }
  function explain(e, fallback) {
    if (e && e.status === 404 && !(e.body && (e.body.error || e.body.message))) return 'These endpoints are not in this server\'s compiled route table yet. Apply the package (it clears the route cache) and reload.';
    if (e && e.status === 403) return 'Your role cannot do this. The owner can.';
    if (e && e.status === 429) return 'Too many tries in a minute. Wait a moment.';
    if (e && e.body && e.body.errors) { var k = Object.keys(e.body.errors)[0]; return e.body.errors[k][0]; }
    return (e && e.body && (e.body.error || e.body.message)) ? (e.body.error || e.body.message) : fallback;
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-go') === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = TITLE;
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    open(st.tab);
    return undefined;
  };

  async function run(fn, fallback) {
    var mine = ++st.seq;
    st.busy = true; st.banner = null; render();
    try { await fn(mine); } catch (e) { if (mine === st.seq) st.banner = explain(e, fallback); }
    if (mine === st.seq) { st.busy = false; render(); }
  }

  function open(tab) {
    st.tab = tab; st.msg = null;
    run(async function (mine) {
      var ov = await api('GET', '/overview');
      if (mine !== st.seq) return;
      st.ov = ov;
      if (!st.form) resetForm();
      if (LISTS[tab]) await loadList(mine);
    }, 'Bounces & unsubscribes could not be loaded.');
  }
  async function loadList(mine) {
    var l = await api('GET', '/list?' + new URLSearchParams({ tab: st.tab, q: st.q, page: String(st.page) }).toString());
    if (mine === st.seq) st.list = l;
  }
  function resetForm() {
    var m = st.ov.mailbox;
    st.form = { enabled: !!m.enabled, auto_remove: !!st.ov.auto_remove, username: m.own_username || '', label: m.label || 'KBB Bounces', password: '', forget_password: false };
    st.gap = st.ov.pace.gap;
  }

  /* ----------------------------------------------------------- render */

  function card(title, sub, kids, cls) {
    return el('section', { class: 'ebh-card' + (cls ? ' ' + cls : '') }, [title ? el('h3', { class: 'ebh-h', text: title }) : null, sub ? el('p', { class: 'ebh-sub', text: sub }) : null].concat(kids || []));
  }
  function sw(on, label, onclick) {
    return el('button', { type: 'button', class: 'ebh-sw', role: 'switch', 'aria-checked': String(!!on), onclick: onclick, disabled: onclick ? null : true }, [el('i'), el('span', { text: label })]);
  }
  function copyRow(text) {
    return el('div', { class: 'ebh-code' }, [el('code', { text: text }), el('button', { type: 'button', class: 'ebh-btn sm', text: 'Copy', onclick: function () {
      try { navigator.clipboard.writeText(text).then(function () { say('Copied.'); }); } catch (e) { say('Select the text and copy it.'); }
    } })]);
  }

  function render() {
    var c = document.querySelector('#content');
    if (!c) return;
    c.textContent = '';
    var wrap = el('div', { class: 'ebh', 'data-screen': SCREEN });
    c.appendChild(wrap);
    var counts = st.ov ? st.ov.counts : {};
    wrap.appendChild(el('div', { class: 'ebh-tabs', role: 'tablist', 'aria-label': TITLE }, TABS.map(function (t) {
      var n = LISTS[t[0]] && st.ov ? counts[t[0]] : null;
      return el('button', { type: 'button', class: 'ebh-tab', role: 'tab', 'aria-selected': String(st.tab === t[0]), 'data-tab': t[0], onclick: function () { st.q = ''; st.page = 1; st.list = null; open(t[0]); } },
        [t[1], n != null ? el('span', { class: 'ct', text: num(n) }) : null]);
    })));
    if (st.banner) wrap.appendChild(el('div', { class: 'ebh-note', role: 'alert', text: st.banner }));
    if (st.msg) wrap.appendChild(el('div', { class: 'ebh-info ' + (st.msg.ok ? 'ebh-good' : 'ebh-warn'), role: 'status', text: st.msg.text }));
    if (!st.ov) { wrap.appendChild(el('div', { class: 'ebh-card ebh-empty', text: st.busy ? 'Loading…' : 'Nothing to show.' })); return; }

    wrap.appendChild(statusStrip());
    if (LISTS[st.tab]) wrap.appendChild(listView());
    else if (st.tab === 'setup') setupView(wrap);
    else dnsView(wrap);
  }

  function statusStrip() {
    var o = st.ov, m = o.mailbox, p = o.pace, last = o.last_run;
    var reading = m.enabled
      ? (last && last.at ? (last.ok ? 'Reading bounces from the "' + m.label + '" label — last checked ' + when(last.at) + '.' : 'Reading bounces is ON but the last check failed: ' + (last.why || 'unknown') + '.') : 'Reading bounces is ON; the first check runs within five minutes of the cron line.')
      : 'Reading bounces is OFF — bounces still land in your inbox. Open Setup (3 steps, about 5 minutes).';
    var pace = 'Sending pace: one email every ' + (p.gap > 0 ? (p.gap - p.jitter) + '–' + (p.gap + p.jitter) + ' seconds' : 'step (no pause)') + ', at most ' + num(p.per_minute) + ' a minute and ' + num(p.per_day) + ' a day (' + num(p.used_today) + ' used today).';
    var kids = [el('div', { text: reading }), el('div', { text: pace })];
    if (p.backoff_wait > 0) kids.push(el('div', { text: 'Google asked the shop to slow down — campaigns resume by themselves in about ' + Math.ceil(p.backoff_wait / 60) + ' minute(s). Google said: ' + (p.backoff_why || '—') }));
    var good = m.enabled && last && last.ok && !(p.backoff_wait > 0);
    return el('div', { class: 'ebh-info ' + (good ? 'ebh-good' : 'ebh-warn') }, kids);
  }

  var HELP = {
    bounced: 'Addresses that bounced for good (address does not exist, domain gone) — or soft-bounced 3 times in 30 days. Removed from every marketing list automatically and never emailed again until you press Restore. Order emails are not affected.',
    unsubscribed: 'People who asked to stop: the unsubscribe link, the Gmail "Unsubscribe" button, the newsletter, or the basket and stock reminders. Never emailed marketing again. There is no Restore — only they can sign up again.',
    complaints: 'People who pressed "Report spam" where their provider tells us (Yahoo, Outlook and others; Gmail reports only totals, in Google Postmaster Tools). Never emailed again.',
    watching: 'Temporary bounces (mailbox full, server busy) in the last 30 days. Nothing is removed yet — the third within 30 days moves the address to Bounced.'
  };

  function listView() {
    var l = st.list, tab = st.tab, can = st.ov.can;
    var search = el('input', { class: 'ebh-in ebh-search', type: 'search', placeholder: 'Search an email address', value: st.q, 'aria-label': 'Search an email address', onkeydown: function (e) { if (e.key === 'Enter') { st.q = e.target.value.trim(); st.page = 1; open(tab); } } });
    var bar = el('div', { class: 'ebh-row' }, [search, el('button', { type: 'button', class: 'ebh-btn', text: 'Search', onclick: function () { st.q = search.value.trim(); st.page = 1; open(tab); } }),
      tab === 'bounced' && can.export ? el('a', { class: 'ebh-btn', href: base() + '/bounced/export', download: '', text: 'Export CSV' }) : null]);
    var kids = [bar];
    if (!l || l.tab !== tab) { kids.push(el('div', { class: 'ebh-empty', text: 'Loading…' })); return card(null, null, [el('p', { class: 'ebh-sub', text: HELP[tab] })].concat(kids)); }
    if (!l.rows.length) kids.push(el('div', { class: 'ebh-empty', text: st.q ? 'No address matches "' + st.q + '".' : 'Nobody here — good.' }));
    else kids.push(el('ul', { class: 'ebh-list' }, l.rows.map(function (r) { return item(tab, r, can); })));
    if (l.pages > 1) kids.push(el('div', { class: 'ebh-pager' }, [
      el('span', { text: num(l.total) + ' addresses · page ' + l.page + ' of ' + l.pages }),
      el('span', { class: 'ebh-row' }, [
        el('button', { type: 'button', class: 'ebh-btn sm', text: '← Previous', disabled: l.page <= 1, onclick: function () { st.page = l.page - 1; open(tab); } }),
        el('button', { type: 'button', class: 'ebh-btn sm', text: 'Next →', disabled: l.page >= l.pages, onclick: function () { st.page = l.page + 1; open(tab); } })])]));
    return card(null, null, [el('p', { class: 'ebh-sub', text: HELP[tab] })].concat(kids));
  }

  function item(tab, r, can) {
    var meta = [el('span', { text: when(r.at) })], why = null, act = null;
    if (tab === 'bounced' || tab === 'complaints') {
      if (r.code) meta.push(el('span', { class: 'ebh-pill ' + (tab === 'bounced' ? 'hard' : 'soft'), text: r.code }));
      if (r.campaign) meta.push(el('span', { text: 'Campaign: ' + r.campaign }));
      meta.push(el('span', { text: num(r.hard) + ' hard · ' + num(r.soft) + ' soft' }));
      why = r.detail ? el('div', { class: 'ebh-why', text: r.detail }) : null;
      /* The admin's own "Are you sure?" box (reset-guard: Yes / No over a
         blurred page — the owner's ask of 1 October) asks first; this
         handler runs only on Yes. data-kbb-sure gives it the question. */
      if (tab === 'bounced' && can.restore) act = el('button', { type: 'button', class: 'ebh-btn sm', text: 'Restore', 'data-kbb-sure': 'Put ' + r.email + ' back on the marketing list? It will be emailed again from the next campaign. Only do this if you know the address works now — mailing a dead address again hurts the shop\'s sending reputation.', onclick: function () { restore(r.email); } });
    } else if (tab === 'unsubscribed') {
      meta.push(el('span', { class: 'ebh-pill', text: r.list === 'marketing' ? 'Marketing emails' : (r.list === 'newsletter' ? 'Newsletter' : 'Basket & stock reminders') }));
    } else {
      meta.push(el('span', { class: 'ebh-pill soft', text: r.soft + ' of ' + r.of }));
      if (r.code) meta.push(el('span', { text: 'Last: ' + r.code }));
    }
    return el('li', { class: 'ebh-item' }, [el('div', null, [el('div', { class: 'ebh-email', text: r.email }), el('div', { class: 'ebh-meta' }, meta), why]), act]);
  }

  function restore(email) {
    run(async function () {
      var r = await api('POST', '/bounced/restore', { email: email, confirm: true });
      st.msg = { ok: true, text: r.message };
      st.ov = await api('GET', '/overview');
      await loadList(st.seq);
    }, 'Not restored.');
  }

  function setupView(wrap) {
    var o = st.ov, m = o.mailbox, f = st.form, can = o.can.mailbox;
    var account = m.username || 'your Google Workspace address';
    wrap.appendChild(card('Step 1 · An app password', null, [
      el('ol', { class: 'ebh-steps' }, [
        el('li', { text: m.mail_account && m.uses_mail_account
          ? 'Nothing to do: the shop already sends as ' + m.mail_account + ' with an app password (Store → Mail → Google Workspace), and that same app password reads bounces.'
          : 'In the Google account that sends (' + account + '): myaccount.google.com → Security → 2-Step Verification (turn on if off) → App passwords → name it "KBB bounces" → Create. Copy the 16 letters into step 3.' }),
        el('li', { text: 'Make sure IMAP is allowed: Gmail → ⚙ Settings → See all settings → Forwarding and POP/IMAP → IMAP access: Enable IMAP → Save. (If you use Google Workspace and the option is missing, Google Admin → Apps → Google Workspace → Gmail → End User Access → POP and IMAP access → on.)' })
      ])
    ]));
    wrap.appendChild(card('Step 2 · A Gmail filter, so bounces never reach your inbox', 'Signed in to Gmail as ' + account + ':', [
      el('ol', { class: 'ebh-steps' }, [
        el('li', { text: 'Click the ☰ sliders icon at the right end of the Gmail search box ("Show search options").' }),
        el('li', null, ['In "Has the words" paste exactly:', copyRow(m.filter_query)]),
        el('li', { text: 'Click "Create filter". Tick: Skip the Inbox (Archive it) · Mark as read · Apply the label → New label… → ' + m.label + ' · Never send it to Spam. Also tick "Also apply filter to matching conversations" to tidy the old ones.' }),
        el('li', { text: 'Click "Create filter". Done — bounce emails now go quietly into the "' + m.label + '" label, and the shop files them every five minutes into "' + m.processed_label + '".' })
      ]),
      el('p', { class: 'ebh-sub', text: 'The filter matches only messages FROM a mail system (mailer-daemon, postmaster) and the unsubscribe address ' + m.unsubscribe_address + '. Customer emails are never caught by it. The shop reads only that one label and never opens your inbox.' })
    ]));

    var fields = [
      sw(f.enabled, 'Read bounces from Gmail every 5 minutes', can ? function () { f.enabled = !f.enabled; render(); } : null),
      sw(f.auto_remove, 'Remove bounced addresses from every marketing list automatically', can ? function () { f.auto_remove = !f.auto_remove; render(); } : null),
      el('label', { class: 'ebh-f' }, [el('span', { text: 'Gmail account to read' }),
        el('input', { class: 'ebh-in', type: 'email', value: f.username, placeholder: m.mail_account ? m.mail_account + ' (from Store → Mail)' : 'info@yourdomain.com', disabled: !can, oninput: function (e) { f.username = e.target.value; }, autocomplete: 'off' }),
        el('small', { text: 'Leave blank to use the account the shop already sends with.' })]),
      el('label', { class: 'ebh-f' }, [el('span', { text: 'App password (16 letters)' }),
        el('input', { class: 'ebh-in', type: 'password', value: f.password, placeholder: m.own_password_set ? '•••• saved — type to replace' : (m.password_set ? '•••• using Store → Mail\'s' : 'xxxx xxxx xxxx xxxx'), disabled: !can, oninput: function (e) { f.password = e.target.value; }, autocomplete: 'new-password' }),
        el('small', { text: 'Stored encrypted. Never shown again. Blank keeps what is saved.' })]),
      el('label', { class: 'ebh-f' }, [el('span', { text: 'Gmail label' }),
        el('input', { class: 'ebh-in', type: 'text', value: f.label, disabled: !can, oninput: function (e) { f.label = e.target.value; }, maxlength: '60' }),
        el('small', { text: 'Must match the label in step 2. Server: ' + m.host + ' — fixed.' })])
    ];
    var acts = can ? el('div', { class: 'ebh-row', style: 'margin-top:14px' }, [
      el('button', { type: 'button', class: 'ebh-btn pri', text: 'Save', disabled: st.busy, onclick: saveMailbox }),
      el('button', { type: 'button', class: 'ebh-btn', text: 'Test connection', disabled: st.busy, onclick: function () { action('/mailbox/test', 'Could not test.'); } }),
      el('button', { type: 'button', class: 'ebh-btn', text: 'Read now', disabled: st.busy, onclick: function () { action('/mailbox/run', 'Could not read.'); } }),
      m.own_password_set ? el('button', { type: 'button', class: 'ebh-btn', text: 'Forget saved app password', disabled: st.busy, onclick: function () { f.forget_password = true; saveMailbox(); } }) : null
    ]) : el('p', { class: 'ebh-sub', text: '🔒 Only the owner can change the bounce mailbox.' });
    wrap.appendChild(card('Step 3 · Switch it on', 'Then press Test connection. It signs in, opens the label and changes nothing.', fields.concat([acts])));

    var p = o.pace;
    var gapIn = el('input', { class: 'ebh-in', type: 'number', min: '0', max: '120', value: st.gap, disabled: !o.can.pace, oninput: function (e) { st.gap = e.target.value; }, style: 'max-width:120px' });
    wrap.appendChild(card('Sending pace', 'Google Workspace allows 2,000 emails a day per account, and the order emails use the same allowance. The shop sends marketing emails one at a time with a pause between them, so they arrive as a steady trickle rather than a burst — a burst is what spam filters and Google\'s own "unusual activity" lock look for. If Google answers "slow down", sending pauses by itself (5 minutes, then 10, 20…) and carries on.', [
      el('label', { class: 'ebh-f' }, [el('span', { text: 'Seconds between emails' }), gapIn,
        el('small', { text: 'Recommended 10 (each email waits 8–12 seconds, a little different every time) → about ' + num(Math.floor(60 / Math.max(1, p.gap || 10))) + ' a minute, 1,500 in about 4 hours. 0 turns the pause off. The daily limit (now ' + num(p.per_day) + ') is under Marketing Emails → Campaigns → Sending limits.' })]),
      o.can.pace ? el('div', { class: 'ebh-row', style: 'margin-top:12px' }, [el('button', { type: 'button', class: 'ebh-btn pri', text: 'Save pace', disabled: st.busy, onclick: savePace })]) : el('p', { class: 'ebh-sub', text: '🔒 Only Owner and Manager can change the pace.' })
    ]));
  }

  function saveMailbox() {
    var f = st.form;
    run(async function () {
      var r = await api('POST', '/mailbox', { enabled: f.enabled, auto_remove: f.auto_remove, username: f.username.trim(), label: f.label.trim(), password: f.password, forget_password: f.forget_password });
      st.ov.mailbox = r.mailbox; st.ov.auto_remove = r.auto_remove; f.password = ''; f.forget_password = false;
      st.msg = { ok: true, text: 'Saved.' };
    }, 'Not saved.');
  }
  function savePace() {
    run(async function () {
      var r = await api('POST', '/pace', { gap: parseInt(st.gap, 10) || 0 });
      st.ov.pace.gap = r.gap; st.ov.pace.per_minute = r.per_minute; st.gap = r.gap;
      st.msg = { ok: true, text: 'Pace saved: ' + (r.gap > 0 ? 'one email every ' + r.gap + ' seconds (±2).' : 'no pause between emails.') };
    }, 'Not saved.');
  }
  function action(path, fallback) {
    run(async function () {
      var r = await api('POST', path);
      if (path === '/mailbox/test') st.msg = { ok: !!r.ok, text: r.message };
      else st.msg = { ok: !!r.ok, text: r.ok ? 'Read ' + num(r.read) + ' report(s): ' + num(r.hard) + ' hard, ' + num(r.soft) + ' soft, ' + num(r.delay) + ' delayed, ' + num(r.complaint) + ' complaint(s), ' + num(r.unsubscribe) + ' unsubscribe(s), ' + num(r.ignored) + ' not bounces.' + (r.left ? ' ' + num(r.left) + ' more next time.' : '') : (r.why || 'Could not read.') };
      st.ov = await api('GET', '/overview');
    }, fallback);
  }

  function dnsView(wrap) {
    var d = st.ov.deliverability;
    var rows = d && d.records && d.records.length ? d.records.map(function (r) {
      return el('div', { class: 'ebh-item' }, [el('div', null, [
        el('div', { class: 'ebh-row' }, [el('b', { text: r.record }), el('span', { class: 'ebh-pill ' + r.status, text: r.status === 'ok' ? 'Pass' : (r.status === 'missing' ? 'Missing' : (r.status === 'problem' ? 'Problem' : 'Not checked')) })]),
        el('div', { class: 'ebh-meta' }, [el('span', { text: r.host })]),
        r.found ? el('div', { class: 'ebh-why', text: 'Found: ' + r.found }) : null,
        r.hint ? el('div', { class: 'ebh-why', text: r.hint }) : null
      ])]);
    }) : [el('div', { class: 'ebh-empty', text: 'Not checked yet. Press Check now.' })];
    wrap.appendChild(card('Will inboxes trust your emails?', 'Gmail and Yahoo require every bulk sender to have SPF, DKIM and DMARC on the From domain' + (d && d.domain ? ' (' + d.domain + ')' : '') + '. This looks them up in public DNS — the same thing any mail server does — and shows the exact record to add where one is missing. Add records at your domain\'s DNS host.', [
      el('div', { class: 'ebh-row', style: 'margin-top:12px' }, [el('button', { type: 'button', class: 'ebh-btn pri', text: 'Check now', disabled: st.busy, onclick: function () {
        run(async function () { var r = await api('POST', '/deliverability/check'); st.ov.deliverability = r.deliverability; }, 'Could not check.');
      } }), d && d.at ? el('span', { class: 'ebh-sub', text: 'Last checked ' + when(d.at) }) : null]),
      el('div', { class: 'ebh-dns' }, rows)
    ]));
    wrap.appendChild(card('Every marketing email already carries', null, [el('ul', { class: 'ebh-steps' }, [
      el('li', { text: 'One-click unsubscribe (List-Unsubscribe + List-Unsubscribe-Post, RFC 8058) — Gmail shows its own "Unsubscribe" button.' }),
      el('li', { text: 'An unsubscribe link and your postal address in the footer, and a plain-text copy.' }),
      el('li', { text: 'The same From address every time, and a Message-ID on your own domain.' }),
      el('li', { text: 'A Feedback-ID, so Google Postmaster Tools (postmaster.google.com) can show your spam rate per campaign. Keep it under 0.1%; Google starts filtering at 0.3%.' })
    ])]));
  }

  /* The sidebar row is AdminNav's (server-drawn); this returns it, adding nothing. */
  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Bounces & unsubscribes',
      icon: '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/><path d="M15 15l6 6"/><path d="M21 15l-6 6"/>',
      group: 'Growth & Marketing',
      after: ['mkt-email']
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry); else addNavEntry();
})();
