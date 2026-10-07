
(function () {
  'use strict';
  var SCREEN = 'domainswitch';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  var st = null;          // GET /domain-switch
  var rd = null;          // readiness, accumulated across "check the rest"
  var rdBusy = false;
  var pics = null;        // GET /domain-switch/pictures
  var msgs = {};          // step -> {ok, text}
  var busy = '';          // the action in flight
  var banner = '';
  var seq = 0;

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
    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
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
    if (status === 404) return 'This screen\'s endpoints are not in the server\'s route table yet. Go to Platform → Cache, press Clear everything, and reload.';
    if (status === 403) return (body && body.message) || 'Only the owner can use this screen.';
    if (status === 419) return 'Your session expired. Reload the page and sign in again.';
    return (body && (body.message || body.reason)) || 'Something went wrong (' + status + '). Nothing was changed. Reload and try again.';
  }

  /* ------------------------------------------------------------- pieces */
  var WORDS = { done: '✓ Done', todo: '● To do', problem: '✕ Needs attention', yours: '● Your click' };

  function badge(level) { return '<span class="dw-badge is-' + esc(level) + '">' + esc(WORDS[level] || level) + '</span>'; }

  function copy(value) {
    return '<span class="dw-copy"><code>' + esc(value) + '</code><button type="button" data-copy="' + esc(value) + '">Copy</button></span>';
  }

  function msg(step) {
    var m = msgs[step];
    if (!m) return '';
    return '<div class="dw-msg ' + (m.ok === true ? 'is-ok' : (m.ok === false ? 'is-bad' : 'is-info')) + '" role="status">' + esc(m.text) + '</div>';
  }

  function btn(action, label, opts) {
    opts = opts || {};
    var off = busy !== '' || opts.disabled;
    return '<button type="button" class="dw-btn' + (opts.quiet ? ' is-quiet' : '') + '" data-dw="' + esc(action) + '"' + (off ? ' disabled' : '') + '>'
      + esc(busy === action ? 'Working…' : label) + '</button>';
  }

  function yours(who, html) { return '<div class="dw-yours"><b class="dw-who">' + esc(who) + '</b>' + html + '</div>'; }

  function step(n, level, title, why, body) {
    return '<li class="dw-step is-' + esc(level) + '" id="dw-step-' + n + '"><div class="dw-num" aria-hidden="true">' + n + '</div>'
      + '<div class="dw-body"><div class="dw-title">' + esc(title) + ' ' + badge(level) + '</div>'
      + '<p class="dw-why">' + why + '</p>' + body + msg(n) + '</div></li>';
  }

  function when(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    return isNaN(d.getTime()) ? '' : ' (checked ' + d.toLocaleString() + ')';
  }

  /* -------------------------------------------------------------- steps */
  function s1() {
    var level = !rd ? 'todo' : (rd.risk > 0 ? 'problem' : (rd.complete ? 'done' : 'todo'));
    var body = '';
    if (!rd) {
      body += '<div class="dw-msg is-info">' + (rdBusy ? 'Checking the whole shop…' : 'Not checked yet.') + '</div>';
    } else {
      body += '<div class="dw-row"><b>RISK ' + esc(rd.risk) + '</b><span>·</span><b>TODO ' + esc(rd.todo) + '</b><span style="color:var(--ink-soft,#6b7280);font-size:13px">'
        + esc('Checked ' + rd.tables_checked + ' of ' + rd.tables_total + ' parts of the shop in ' + rd.seconds + ' s') + '</span></div>';
      body += '<p class="dw-why">' + (rd.risk === 0 && rd.complete
        ? 'Nothing found that breaks on the switch. The TODO lines are the steps below.'
        : (rd.risk > 0 ? 'RISK lines would break on the switch, or keep depending on ' + esc(st ? st.old : 'the old domain') + '. Fix them first — each has a button.' : 'Part of the shop is still to be checked.')) + '</p>';
      var open = rd.findings.filter(function (f) { return f.level === 'risk' || f.level === 'todo'; });
      var rest = rd.findings.filter(function (f) { return !(f.level === 'risk' || f.level === 'todo'); });
      if (open.length) body += '<ul class="dw-find">' + open.map(finding).join('') + '</ul>';
      if (rest.length) body += '<details><summary>' + esc(rest.length + ' thing(s) already right') + '</summary><ul class="dw-find">' + rest.map(finding).join('') + '</ul></details>';
    }
    body += '<div class="dw-row">' + btn('readiness', rd ? 'Check again' : 'Check now', { quiet: !!rd, disabled: rdBusy })
      + (rd && !rd.complete ? btn('readiness_more', 'Check the rest', { disabled: rdBusy }) : '') + '</div>';
    return step(1, level, 'Readiness check', 'Looks through the whole shop for anything that still depends on ' + esc(st.old) + ' or would break on ' + esc(st.new) + '. It only reads; it changes nothing.', body);
  }

  function finding(f) {
    var fix = '';
    if (f.fix && f.fix.step) fix = '<button type="button" class="dw-btn is-quiet" data-goto="' + esc(f.fix.step) + '">' + esc(f.fix.label) + '</button>';
    else if (f.fix && f.fix.screen) fix = '<button type="button" class="dw-btn is-quiet" data-screen="' + esc(f.fix.screen) + '">' + esc(f.fix.label) + '</button>';
    else if (f.fix && f.fix.check) fix = '<button type="button" class="dw-btn is-quiet" data-dw="readiness">' + esc(f.fix.label) + '</button>';
    return '<li><span class="dw-l is-' + esc(f.level) + '">' + esc(String(f.level).toUpperCase()) + '</span>' + esc(f.title)
      + (f.detail ? '<small>' + esc(f.detail) + '</small>' : '')
      + (f.samples && f.samples.length ? '<small>' + f.samples.map(esc).join('<br>') + '</small>' : '') + fix + '</li>';
  }

  function s2() {
    var now = st.now, level = st.steps.names;
    var body = '<dl class="dw-kv"><dt>Main address now</dt><dd>' + esc(now.main_address || '(not set)') + '</dd>'
      + '<dt>Old addresses now</dt><dd>' + esc(now.old_addresses.length ? now.old_addresses.join(', ') : '(none)') + '</dd>'
      + '<dt>Forwarding now</dt><dd>' + (now.forwarding ? 'on' : 'off') + '</dd></dl>';
    if (st.steps.remove === 'done') {
      body += '<div class="dw-msg is-ok">' + esc(st.old) + ' was removed in step 11, so it is no longer an old address. Nothing to do here.</div>';
    } else {
      body += '<div class="dw-row"><label for="dw-domain" style="font-size:13.5px;font-weight:600">New main address</label>'
        + '<input class="dw-input" id="dw-domain" type="text" inputmode="url" autocomplete="off" spellcheck="false" value="' + esc(st.proposed.main_address) + '"></div>'
        + '<p class="dw-why">Old addresses will be: <b>' + esc(st.proposed.old_addresses.join(', ')) + '</b> (and their www). '
        + (level === 'done' ? 'Forwarding is left as it is.' : 'Forwarding stays <b>off</b>.') + '</p>'
        + '<div class="dw-row">' + btn('set_names', level === 'done' ? 'Save again' : 'Save the new name', { quiet: level === 'done' }) + '</div>';
    }
    return step(2, level, 'Tell the shop its new name', 'The shop learns that ' + esc(st.new) + ' is its main address and ' + esc(st.old) + ' its old one. Nothing changes for shoppers yet.', body);
  }

  function s3() {
    var c = st.checks.old_dns;
    var body = yours('Your clicks in Cloudways', '<ol>'
      + '<li>Applications → your app → <b>Domain Management</b> → add ' + copy(st.copy.domains[0]) + ' and ' + copy(st.copy.domains[1]) + '. Keep ' + esc(st.old) + ' for now.</li>'
      + '<li>Servers → your server: the <b>Public IP</b> should be ' + copy(st.server_ip) + '.</li></ol>');
    body += '<div class="dw-row">' + btn('check_old_dns', 'Check where ' + st.old + ' points', { quiet: true }) + '</div>';
    if (c && !msgs[3]) body += '<div class="dw-msg ' + (c.level === 'done' ? 'is-ok' : 'is-bad') + '">' + esc(c.message + when(c.checked_at)) + '</div>';
    return step(3, st.steps.cloudways, 'Add the new domain in Cloudways', 'So the server answers on ' + esc(st.new) + '. Only you can do this, in Cloudways.', body);
  }

  function s4() {
    var c = st.checks.dns, rows = st.dns_table;
    var t = '<table class="dw-table"><colgroup><col class="t"><col class="n"><col><col class="x" style="width:84px"></colgroup>'
      + '<thead><tr><th>Type</th><th>Name</th><th>Value</th><th></th></tr></thead><tbody>'
      + rows.map(function (r) { return '<tr><td><b>' + esc(r.type) + '</b></td><td>' + esc(r.name) + '</td><td>' + copy(r.value) + '</td><td>' + esc(r.extra) + '</td></tr>'; }).join('')
      + '</tbody></table>';
    var body = yours('Your clicks at Internet.bs', '<ol><li>Internet.bs → ' + esc(st.new) + ' → <b>DNS Management</b>: create these records.' + t
      + '<b>Do not create an AAAA record.</b></li><li>Under <b>Nameservers</b>, switch to Internet.bs’s own DNS nameservers.</li>'
      + '<li>Wait. It takes from a few minutes to 48 hours. Press <b>Check DNS now</b> whenever you like.</li></ol>'
      + '<p class="dw-why">To undo before step 11: set the A record back to ' + copy(st.previous_ip) + '.</p>');
    body += '<div class="dw-row">' + btn('check_dns', 'Check DNS now') + '</div>';
    if (c) {
      var rec = c.records || {};
      var line = function (label, vals, good) {
        return '<dt>' + esc(label) + '</dt><dd>' + esc(vals && vals.length ? vals.join(', ') : 'none') + ' ' + (good ? '✓' : '●') + '</dd>';
      };
      var a = rec.A || [];
      body += '<dl class="dw-kv">'
        + line('A', a, a.length > 0 && a.every(function (ip) { return (c.expected || []).indexOf(ip) >= 0; }))
        + line('AAAA', rec.AAAA, !(rec.AAAA || []).length)
        + line('www', rec.www, (rec.www || []).length > 0)
        + line('MX', rec.MX, (rec.MX || []).some(function (m) { return /smtp\.google\.com$/.test(m); }))
        + line('SPF', rec.TXT, (rec.TXT || []).some(function (t) { return t.indexOf('include:_spf.google.com') >= 0; }))
        + line('Nameservers', rec.NS, (rec.NS || []).length > 0) + '</dl>';
      if (!msgs[4]) body += '<div class="dw-msg ' + (c.level === 'done' ? 'is-ok' : 'is-bad') + '">' + esc(c.message + when(c.checked_at)) + '</div>';
    } else if (st.request.on_target) {
      body += '<div class="dw-msg is-ok">You are on ' + esc(st.request.host) + ' right now, so its DNS already points here.</div>';
    }
    return step(4, st.steps.dns, 'Point ' + st.new + ' at this server (Internet.bs)', 'The internet’s address book has to send ' + esc(st.new) + ' to this server. Only you can do this, at Internet.bs.', body);
  }

  function s5() {
    var c = st.checks.tls;
    var body = yours('Your click in Cloudways', 'Applications → your app → <b>SSL Certificate</b> → Let’s Encrypt → enter all four names: ' + copy(st.copy.certificate)
      + '<br>' + esc(st.old) + ' stays on the certificate while it forwards (step 10).');
    body += '<div class="dw-row">' + btn('check_tls', 'Check certificate') + '</div>';
    if (c && !msgs[5]) body += '<div class="dw-msg ' + (c.level === 'done' ? 'is-ok' : 'is-bad') + '">' + esc(c.message + when(c.checked_at)) + '</div>';
    else if (!c && st.request.on_target && !msgs[5]) body += '<div class="dw-msg is-ok">This page reached you over https on ' + esc(st.request.host) + ', so the certificate works.</div>';
    return step(5, st.steps.tls, 'Certificate for ' + st.new, 'The padlock. Without it, browsers refuse the new address.', body);
  }

  function s6() {
    var level = st.steps['switch'], body = '';
    body += '<dl class="dw-kv"><dt>Shop’s address now</dt><dd>' + esc(st.now.app_url || '(not set)') + '</dd><dt>Site URL now</dt><dd>' + esc(st.now.site_url || '(empty)') + '</dd></dl>';
    if (!st.can['switch'] && level !== 'done') {
      var there = st.copy.admin_on_target + window.location.pathname;
      body += '<div class="dw-msg is-info">This button only works on the new address. When steps 3–5 are ✓, open <a href="' + esc(there) + '" rel="noopener">' + esc(there) + '</a>, sign in, and press it there.</div>';
    }
    body += '<div class="dw-row">' + btn('switch_address', 'Use ' + st.proposed.app_url + ' from now on', { disabled: !st.can['switch'], quiet: level === 'done' }) + '</div>';
    body += yours('Then your clicks in Cloudways', '<ol><li>Domain Management → make ' + copy(st.new) + ' the <b>primary domain</b>.</li><li>Your app → Application Settings → Varnish → <b>Purge</b>.</li></ol>');
    return step(6, level, 'Switch the shop’s address', 'From now on emails, payment notices, Google and every link use https://' + esc(st.target) + '. The shop’s caches are cleared at the same time.', body);
  }

  function s7() {
    var p = st.steps.payments, levels = [p.stripe.level, p.tabby.level, p.tamara.level];
    var level = levels.indexOf('problem') >= 0 ? 'problem' : (levels.every(function (l) { return l === 'done'; }) ? 'done' : 'todo');
    var row = function (key, label, action) {
      return '<div class="dw-row">' + btn(key, action, { disabled: !st.can.payments, quiet: p[key].level === 'done' }) + ' ' + badge(p[key].level)
        + '<span style="font-size:13px">' + esc(label) + '</span></div>'
        + (p[key].message && !msgs['7' + key] ? '<div class="dw-msg ' + (p[key].level === 'done' ? 'is-ok' : 'is-bad') + '">' + esc(p[key].message) + '</div>' : '')
        + (msgs['7' + key] ? '<div class="dw-msg ' + (msgs['7' + key].ok ? 'is-ok' : 'is-bad') + '">' + esc(msgs['7' + key].text) + '</div>' : '');
    };
    var body = (st.can.payments ? '' : '<div class="dw-msg is-info">Do step 6 first: the providers are told the shop’s own address.</div>')
      + row('stripe', 'Stripe', 'Set up Stripe webhook') + row('tabby', 'Tabby', 'Register / re-sync Tabby') + row('tamara', 'Tamara', 'Remove then register Tamara')
      + yours('Your clicks', '<ol><li>Stripe dashboard → Settings → <b>Payment method domains</b> → add ' + copy(st.new) + '.</li>'
        + '<li>Meta app (Instagram) → Valid OAuth Redirect URIs → add ' + copy(st.copy.instagram) + '</li></ol>');
    return step(7, level, 'Payments and Instagram', 'Stripe, Tabby and Tamara send payment notices to the shop’s address; each button tells them the new one and removes the old one.', body);
  }

  function s8() {
    var level = !pics ? 'todo' : (pics.remote === 0 && pics.missing === 0 ? 'done' : 'todo');
    var body = '';
    if (!pics) body += '<div class="dw-msg is-info">Counting pictures…</div>';
    else {
      body += '<dl class="dw-kv"><dt>On this server</dt><dd>' + esc(pics.present) + '</dd>'
        + '<dt>Still loading from another site</dt><dd>' + esc(pics.remote) + (pics.hosts.length ? ' (' + esc(pics.hosts.join(', ')) + ')' : '') + '</dd>'
        + '<dt>Named but not on this server</dt><dd>' + esc(pics.missing) + '</dd>'
        + (pics.failed ? '<dt>Could not be fetched</dt><dd>' + esc(pics.failed) + '</dd>' : '') + '</dl>';
      body += '<div class="dw-row">' + btn('fetch_pictures', 'Fetch missing pictures from WordPress', { disabled: pics.remaining === 0 })
        + '<a class="dw-btn is-quiet" href="' + esc(BASE.replace(/\/[^\/]*$/, '') + '/admin-api/urls-media/progress-page') + '" target="_blank" rel="noopener">Live progress page</a></div>'
        + '<p class="dw-why">Each press fetches one small batch (about 10 pictures) and can be pressed again until nothing is left. Do this while WordPress is still online.</p>';
      if (pics.missing > 0) body += yours('Your click (only if the number above is not 0)', 'Upload <b>wp-content/uploads</b> from your WordPress backup to Cloudways at ' + copy('public_html/wp-content/uploads/') + ' This keeps old picture links working.');
    }
    return step(8, level, 'Old pictures', 'Every product, brand and article picture must be on this server before WordPress goes away.', body);
  }

  function s9() {
    var t = 'https://' + st.target;
    var link = function (href, text) { return '<a href="' + esc(href) + '" target="_blank" rel="noopener">' + esc(text) + '</a>'; };
    var body = yours('Your checks', '<ul>'
      + '<li>Place test orders on ' + link(t + '/shop', t + '/shop') + ': card, cash on delivery, Tabby and Tamara.</li>'
      + '<li>The order emails show ' + esc(st.target) + ' links.</li>'
      + '<li>Password reset works: ' + link(t + '/my-account/', t + '/my-account/') + ' → Lost your password?</li>'
      + '<li>The Arabic shop works: ' + link(t + '/ar/', t + '/ar/') + '</li>'
      + '<li>Instagram connects: <button type="button" class="dw-btn is-quiet" data-screen="instagram">Open Content → Instagram</button></li>'
      + '<li>The phone app installs from ' + link(t + '/', t) + '</li></ul>');
    return step(9, 'yours', 'Test orders', 'Nothing here is automatic. Check these yourself before step 10.', body);
  }

  function s10() {
    var level = st.steps.forward;
    var body = (st.can.forward ? '' : '<div class="dw-msg is-info">Do step 6 first.</div>')
      + '<div class="dw-row">' + btn('forward_on', 'Forward ' + st.old + ' to ' + st.new, { disabled: !st.can.forward || level === 'done', quiet: level === 'done' }) + '</div>'
      + '<p class="dw-why">Leave it on for 2–4 weeks, so customers with old links and the old phone app move across.</p>';
    return step(10, level, 'Forward ' + st.old, 'Every old ' + esc(st.old) + ' link, bookmark and installed app then lands on ' + esc(st.new) + '.', body);
  }

  function s11() {
    var level = st.steps.remove;
    var ready = rd && rd.complete && rd.risk === 0;
    var body = '<div class="dw-msg ' + (ready ? 'is-ok' : 'is-info') + '">' + (ready ? 'The readiness check (step 1) shows RISK 0.' : 'Allowed only when the readiness check (step 1) shows RISK 0. The shop checks again itself when you press the button.') + '</div>'
      + '<div class="dw-row">' + btn('remove_old', 'Remove ' + st.old + ' completely', { disabled: !st.can.remove || level === 'done', quiet: level === 'done' }) + '</div>'
      + '<p class="dw-why">Clears the old addresses' + (st.now.owner_app_host ? ', and the owner app’s own address if it is on ' + esc(st.old) : '') + '.</p>'
      + yours('Then your clicks', '<ol><li>Cloudways → Domain Management → remove ' + copy(st.old) + ' and ' + copy('www.' + st.old) + '.</li>'
        + '<li>Cloudways → SSL Certificate → Let’s Encrypt again with only ' + copy(st.copy.certificate_after) + ', then Varnish → <b>Purge</b>.</li>'
        + '<li>At ' + esc(st.old) + '’s registrar, remove its A record.</li>'
        + '<li>Stripe dashboard → Payment method domains → remove ' + copy(st.old) + '.</li>'
        + '<li>When the nameservers of ' + esc(st.new) + ' have shown Internet.bs for 2 days (step 4’s check), delete the domain and site from Hostinger and cancel the plan.</li></ol>');
    return step(11, level, 'Remove ' + st.old + ' completely', 'The last step, 2–4 weeks after step 10. After this the shop no longer depends on ' + esc(st.old) + ' at all.', body);
  }

  /* -------------------------------------------------------------- render */
  function render() {
    var host = document.getElementById('content');
    if (!host || !host.querySelector('[data-dw-screen]')) return;
    var root = host.querySelector('[data-dw-screen]');
    if (banner) { root.innerHTML = '<div class="dw-msg is-bad">' + esc(banner) + '</div>'; return; }
    if (!st) { root.innerHTML = '<div class="dw-msg is-info">Loading…</div>'; return; }
    var focus = document.activeElement && document.activeElement.id === 'dw-domain';
    var typed = root.querySelector('#dw-domain') ? root.querySelector('#dw-domain').value : null;
    root.innerHTML = '<div class="dw-head"><h2>Move the shop to ' + esc(st.new) + '</h2>'
      + '<p>Do the steps in order. Each one checks itself every time you open this page. Buttons do the work inside the shop; the grey boxes are clicks only you can do, in Cloudways or at Internet.bs, with the exact values to copy.</p>'
      + '<div class="dw-legend"><span>✓ done</span><span>● to do</span><span>✕ needs attention</span></div></div>'
      + '<ol class="dw-steps">' + [s1(), s2(), s3(), s4(), s5(), s6(), s7(), s8(), s9(), s10(), s11()].join('') + '</ol>';
    var input = root.querySelector('#dw-domain');
    if (input && typed !== null) { input.value = typed; if (focus) input.focus(); }
  }

  async function load() {
    var mine = ++seq;
    var r = await api('/domain-switch');
    if (mine !== seq) return;
    if (r.status !== 200) { banner = fail(r.status, r.body); render(); return; }
    banner = ''; st = r.body; render();
    readiness(0);
    api('/domain-switch/pictures').then(function (p) { if (mine === seq && p.status === 200) { pics = p.body; render(); } });
  }

  async function readiness(offset) {
    rdBusy = true; if (!offset) rd = null; render();
    var r = await api('/domain-switch/readiness' + (offset ? '?offset=' + encodeURIComponent(offset) : ''));
    rdBusy = false;
    if (r.status !== 200) { msgs[1] = { ok: false, text: fail(r.status, r.body) }; render(); return; }
    var b = r.body;
    if (offset && rd) {
      rd.findings = rd.findings.concat(b.findings); rd.risk += b.risk; rd.todo += b.todo;
      rd.complete = b.complete; rd.next_offset = b.next_offset; rd.tables_checked += b.tables_checked; rd.seconds = Math.round((rd.seconds + b.seconds) * 100) / 100;
    } else rd = b;
    delete msgs[1];
    render();
  }

  var STEP_OF = { check_old_dns: 3, check_dns: 4, check_tls: 5, set_names: 2, switch_address: 6, stripe: '7stripe', tabby: '7tabby', tamara: '7tamara', fetch_pictures: 8, forward_on: 10, remove_old: 11 };

  async function run(action) {
    if (action === 'readiness') { readiness(0); return; }
    if (action === 'readiness_more') { readiness(rd ? rd.next_offset : 0); return; }
    var body = { action: action };
    if (action === 'set_names') body.domain = (document.getElementById('dw-domain') || {}).value || '';
    if (action === 'switch_address') {
      if (!window.confirm('The shop will call itself ' + st.proposed.app_url + ' from now on, in every email and link. Continue?')) return;
      body.confirm = st.proposed.confirm || '';
    }
    if (action === 'remove_old') {
      if (!window.confirm('Remove ' + st.old + ' from the shop completely? Do this only 2–4 weeks after step 10, when the test orders have passed.')) return;
      body.confirm = 'REMOVE';
    }
    busy = action; render();
    var r;
    try { r = await api('/domain-switch/run', body); } catch (e) { r = { status: 0, body: { message: 'The shop could not be reached. Check your connection and try again.' } }; }
    busy = '';
    var ok = r.status >= 200 && r.status < 300 && r.body.ok !== false;
    msgs[STEP_OF[action]] = { ok: ok, text: ok ? (r.body.message || 'Done.') : fail(r.status, r.body) };
    if (r.body.state) st = r.body.state;
    if (r.body.pictures) pics = r.body.pictures;
    render();
    if (ok && (action === 'set_names' || action === 'switch_address' || action === 'remove_old' || action === 'forward_on')) readiness(0);
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
      if (ok) done(); else { button.textContent = 'Select & copy'; try { window.toast('Select the text beside the button and press Ctrl+C (Cmd+C).'); } catch (e) {} }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done, fallback);
    else fallback();
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-dw-screen]')) return;
    var b = t.closest('[data-copy]');
    if (b) { copyText(b.getAttribute('data-copy'), b); return; }
    b = t.closest('[data-dw]');
    if (b && !b.disabled) { run(b.getAttribute('data-dw')); return; }
    b = t.closest('[data-goto]');
    if (b) { var el = document.getElementById('dw-step-' + b.getAttribute('data-goto')); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    b = t.closest('[data-screen]');
    if (b && window.go) window.go(b.getAttribute('data-screen'));
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Domain switch',
      icon: '<path d="M4 7h13l-3-3"/><path d="M20 17H7l3 3"/><circle cx="19" cy="7" r="1.6"/><circle cx="5" cy="17" r="1.6"/>',
      group: 'Platform',
      after: ['siteaddr']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Platform"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = 'Platform';
    if (title) title.textContent = 'Domain switch';
    if (side) side.classList.remove('open');
    var host = document.getElementById('content');
    if (!host) return undefined;
    host.innerHTML = '<div class="wrap dw" data-dw-screen></div>';
    st = null; rd = null; pics = null; msgs = {}; busy = ''; banner = '';
    render();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
