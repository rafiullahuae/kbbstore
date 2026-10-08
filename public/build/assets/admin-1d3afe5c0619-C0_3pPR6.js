
(function () {
  'use strict';
  var SCREEN = 'domainswitch';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  var st = null;        // GET /domain-switch (settings + st.installer)
  var open = null;      // the step number shown open
  var msgs = {};        // step key -> {ok, text}
  var busy = '';        // what is in flight
  var banner = '';
  var rw = null;        // the old-links preview
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
    var r;
    try { r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts); }
    catch (e) { return { status: 0, body: { message: 'The shop could not be reached. Check your connection and try again.' } }; }
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
    if (status === 429) return 'Too many presses in a minute. Wait a moment, then try again.';
    return (body && (body.message || body.reason)) || 'Something went wrong (' + status + '). Nothing was changed. Reload and try again.';
  }

  /* ------------------------------------------------------------ pieces */
  function copy(value) {
    return '<span class="dwi-copy"><code>' + esc(value) + '</code><button type="button" data-copy="' + esc(value) + '">Copy</button></span>';
  }
  function val(label, value) { return '<div class="dwi-val"><span>' + esc(label) + '</span>' + copy(value) + '</div>'; }
  function vals(list) { return '<div class="dwi-vals">' + list.join('') + '</div>'; }
  function what(html) { return '<p class="dwi-what">' + html + '</p>'; }
  function note(html) { return '<p class="dwi-note">' + html + '</p>'; }
  function b(s) { return '<b>' + esc(s) + '</b>'; }

  function btn(action, label, o) {
    o = o || {};
    var off = busy !== '' || o.disabled;
    return '<button type="button" class="dwi-btn' + (o.quiet ? ' is-quiet' : '') + '" data-dw="' + esc(action) + '"' + (off ? ' disabled' : '') + '>'
      + esc(busy === action ? 'Working…' : label) + '</button>';
  }
  function stepBtn(doWhat, key, label, cls) {
    var id = doWhat + ':' + key;
    return '<button type="button" class="dwi-btn' + (cls ? ' ' + cls : '') + '" data-dwi-step="' + esc(key) + '" data-dwi-do="' + esc(doWhat) + '"' + (busy !== '' ? ' disabled' : '') + '>'
      + esc(busy === id ? (doWhat === 'verify' ? 'Checking…' : 'Saving…') : label) + '</button>';
  }
  function when(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    return isNaN(d.getTime()) ? '' : d.toLocaleString();
  }
  function stepBy(key) { return st.installer.steps.filter(function (s) { return s.key === key; })[0]; }
  function num(key) { var s = stepBy(key); return s ? s.n : 0; }
  function go(key, label) { return '<button type="button" class="dwi-link" data-dwi-open="' + esc(num(key)) + '">' + esc(label || ('step ' + num(key))) + '</button>'; }

  var LEVEL = { green: 'All good', amber: 'Not yet', red: 'Needs fixing' };

  function result(s) {
    var m = msgs[s.key];
    var out = '';
    if (m) out += '<div class="dwi-res ' + (m.ok === true ? 'is-green' : (m.ok === false ? 'is-red' : 'is-info')) + '" role="status">' + esc(m.text) + '</div>';
    if (s.level && s.verified_at) {
      out += '<div class="dwi-res is-' + esc(s.level) + '" data-level="' + esc(s.level) + '"><b>' + esc(LEVEL[s.level]) + '</b>' + esc(s.message)
        + (s.fix ? '<span class="dwi-fix">→ ' + esc(s.fix) + '</span>' : '')
        + '<small>Last verified ' + esc(when(s.verified_at)) + '</small></div>';
    } else if (s.kind === 'verify') {
      out += note('Not verified yet.');
    } else if (s.status === 'done' && s.updated_at) {
      out += note('Marked as done ' + esc(when(s.updated_at)) + '.');
    }
    return out;
  }

  /* The payments check, open lines only: what is green needs no reading. */
  function payList(list) {
    if (!list || !list.length) return '';
    var green = 0, rows = [];
    list.forEach(function (p) {
      p.checks.forEach(function (c) {
        if (c.level === 'green') { green++; return; }
        rows.push('<li><span class="dwi-dot is-' + esc(c.level) + '"></span><span><b>' + esc(p.title + ' · ' + c.title) + ':</b> ' + esc(c.detail)
          + (c.fix ? ' <i>→ ' + esc(c.fix) + '</i>' : '') + '</span></li>');
      });
    });
    return '<ul class="dwi-list">' + rows.join('') + '<li><span class="dwi-dot is-green"></span><span>' + esc(green) + ' other payment check(s) are green.</span></li></ul>';
  }

  /* ------------------------------------------------ the words of each step */
  var BODY = {
    start: function (s) {
      var d = s.data || {}, h = '';
      h += what('First, a backup: ' + b('Cloudways → Servers → your server → Backups → Take backup now') + '. If WordPress still takes orders, run one last import of orders and customers (Store → Import / Export).');
      h += what('Then press ' + b('Verify') + '. The shop finds this server’s address (where ' + esc(st.old) + ' points today), looks through itself for anything that would break on ' + esc(st.new) + ', and asks Stripe, Tabby and Tamara if they are ready. It changes nothing.');
      if (d.findings && d.findings.length) {
        h += '<ul class="dwi-list">' + d.findings.map(function (f) {
          var fix = '';
          if (f.fix && f.fix.step === s.n) fix = ' ' + btn('fetch_pictures', 'Fetch them from WordPress', { quiet: true });
          else if (f.fix && f.fix.step) fix = ' <button type="button" class="dwi-link" data-dwi-open="' + esc(f.fix.step) + '">' + esc(f.fix.label) + '</button>';
          else if (f.fix && f.fix.screen) fix = ' <button type="button" class="dwi-link" data-screen="' + esc(f.fix.screen) + '">' + esc(f.fix.label) + '</button>';
          return '<li><span class="dwi-dot is-' + esc(f.level) + '"></span><span>' + esc(f.title) + (f.detail ? ' — ' + esc(f.detail) : '') + fix + '</span></li>';
        }).join('') + '</ul>';
      }
      return h + payList(d.payments);
    },
    name: function () {
      return what('The shop learns that ' + b(st.new) + ' is its main address and ' + b(st.old) + ' its old one. Forwarding stays off. Nothing changes for shoppers: ' + esc(st.old) + ' works exactly as before.')
        + '<div class="dwi-row"><label for="dwi-domain" style="font-size:13.5px;font-weight:600">New address</label>'
        + '<input class="dwi-input" id="dwi-domain" type="text" inputmode="url" autocomplete="off" spellcheck="false" value="' + esc(st.proposed.main_address) + '">'
        + btn('set_names', 'Save the new name') + '</div>';
    },
    cs_on: function () {
      var h = what('Before the DNS change, so anyone who reaches ' + b(st.new) + ' sees “Something new is coming” while you set up and test. ' + esc(st.old) + ' is not touched. Signed in, you see the real shop there.');
      h += '<div class="dwi-row">' + btn('coming_soon_on', 'Turn it on for ' + st.new) + '<button type="button" class="dwi-link" data-screen="comingsoon">Change its words: Appearance → Coming Soon page</button></div>';
      if (st.coming_soon_link) h += vals([val('Preview link for your phone', st.coming_soon_link.url)]) + note('Works until ' + esc(when(st.coming_soon_link.until)) + '. Appearance → Coming Soon page → New link makes another.');
      return h;
    },
    cloudways: function () {
      return what(b('Cloudways → Applications → your app → Domain Management') + ': add both names below as additional domains. Keep ' + esc(st.old) + '.')
        + vals([val('Domain', st.copy.domains[0]), val('Domain', st.copy.domains[1])])
        + note('The shop cannot look inside Cloudways, so press “Mark as done” when both are added. The certificate check in step ' + esc(num('ssl')) + ' proves it later.');
    },
    dns_records: function () {
      var rows = st.dns_table.map(function (r) {
        return '<tr><td><b>' + esc(r.type) + '</b></td><td>' + esc(r.name) + '</td><td>' + (r.value ? copy(r.value) : '<i>press Verify in ' + go('start') + ' first</i>') + '</td><td>' + esc(r.extra) + '</td></tr>';
      }).join('');
      return what(b('Internet.bs → ' + st.new + ' → DNS Management') + ': create these four records. Then under ' + b('Nameservers') + ' choose Internet.bs’s own DNS nameservers. ' + b('Do not create an AAAA record.'))
        + '<table class="dwi-table"><colgroup><col class="t"><col class="n"><col><col class="x"></colgroup><thead><tr><th>Type</th><th>Name</th><th>Value</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>'
        + note(st.server_ip ? 'The A value is this server’s address, learned from where ' + esc(st.old) + ' points today. Cloudways → Servers → your server → Public IP shows the same.'
          : 'The shop learns this server’s address from where ' + esc(st.old) + ' points today: press Verify in ' + go('start') + ' first, or read the Public IP in Cloudways → Servers → your server.')
        + note('To undo before step ' + esc(num('switch')) + ': set the A record back to ' + copy(st.previous_ip) + '.');
    },
    dns_wait: function () {
      return what('Nothing to do but wait: the change spreads round the world in a few minutes to 48 hours. Press ' + b('Verify') + ' now and then. It asks the internet where ' + esc(st.new) + ' and www.' + esc(st.new) + ' point and compares that with this server' + (st.server_ip ? ' (' + esc(st.server_ip) + ')' : '') + '.');
    },
    ssl: function () {
      return what(b('Cloudways → Applications → your app → SSL Certificate → Let’s Encrypt') + ': enter all four names below, then wait two minutes. ' + esc(st.old) + ' stays on the certificate while it forwards.')
        + vals([val('Names', st.copy.certificate)]);
    },
    switch: function (s) {
      var inst = st.installer;
      var h = what('From now on emails, payment notices, Google and every link use ' + b('https://' + st.target) + '. ' + esc(st.old) + ' keeps working.');
      h += '<div class="dwi-vals"><div class="dwi-val"><span>Shop’s address now</span><code>' + esc(st.now.app_url || '(not set)') + '</code></div><div class="dwi-val"><span>Site URL now</span><code>' + esc(st.now.site_url || '(empty)') + '</code></div></div>';
      if (!st.request.on_target && s.status !== 'done') {
        var there = st.copy.admin_on_target + window.location.pathname;
        h += '<div class="dwi-res is-info">This button only works on the new address, so the shop can never be pointed at an address that does not reach it. Open <a href="' + esc(there) + '" rel="noopener">' + esc(there) + '</a>, sign in, and come back to step ' + esc(s.n) + '.</div>';
      }
      if (!(inst.gate.dns && inst.gate.ssl) && s.status !== 'done') {
        h += '<div class="dwi-res is-amber"><b>Waiting for steps ' + esc(num('dns_wait')) + ' and ' + esc(num('ssl')) + '</b>'
          + 'The shop will not switch until DNS (step ' + esc(num('dns_wait')) + ') and the certificate (step ' + esc(num('ssl')) + ') have both verified green. '
          + 'If you are sure, type CONFIRM below: customers whose DNS has not caught up would not reach the shop.'
          + '<span class="dwi-row" style="margin-top:6px"><input class="dwi-input" id="dwi-override" type="text" autocomplete="off" spellcheck="false" placeholder="Type CONFIRM" style="width:160px"></span></div>';
      }
      return h + '<div class="dwi-row">' + btn('switch_address', 'Make ' + st.new + ' the main address', { disabled: !st.can['switch'] }) + '</div>';
    },
    caches: function () {
      return what('So nobody is shown a copy saved before the switch: press the button (the shop’s own caches), then in Cloudways make ' + b(st.new) + ' the ' + b('primary domain') + ' (Domain Management), then ' + b('Application Settings → Varnish → Purge') + '.')
        + '<div class="dwi-row">' + btn('clear_caches', 'Clear the shop’s caches') + '</div>'
        + note('Verify opens https://' + esc(st.old) + '/ and checks that the page it gets names ' + esc(st.new) + '.');
    },
    links: function () {
      var h = what('Product descriptions, articles, pages, menus, banners and email templates that still link to ' + esc(st.old) + ' are pointed at ' + esc(st.new) + '. Orders, customers and payment settings are never touched, and Undo puts everything back.');
      h += '<div class="dwi-row">' + btn('rw_preview', rw ? 'Look again' : 'Show what would change', { quiet: !!rw }) + '</div>';
      if (rw) {
        if (rw.links === 0) h += '<div class="dwi-res is-green">No link in the shop’s text points at ' + esc(rw.old.join(', ')) + '.</div>';
        else {
          h += '<div class="dwi-res is-info"><b>' + esc(rw.links) + ' link(s) in ' + esc(rw.rows) + ' place(s)</b>would change to https://' + esc(rw.new) + '/… — the same page on the new address.</div>'
            + '<table class="dwi-table dwi-rw"><colgroup><col><col class="n"></colgroup><thead><tr><th>Where</th><th>Links</th></tr></thead><tbody>'
            + rw.places.map(function (pl) {
              var sm = (pl.samples || []).map(function (x) { return '<div><small>' + esc(x.where) + '</small><br><del><code>' + esc(x.before) + '</code></del><br><ins><code>' + esc(x.after) + '</code></ins></div>'; }).join('');
              return '<tr><td><b>' + esc(pl.label) + '</b>' + sm + '</td><td>' + esc(pl.links) + '</td></tr>';
            }).join('') + '</tbody></table>';
          h += '<div class="dwi-row">' + btn('rewrite_content', 'Change these ' + rw.links + ' links', { disabled: !rw.can_apply }) + '</div>';
          if (!rw.can_apply) h += note('Do step ' + esc(num('switch')) + ' first: until then ' + esc(rw.new) + ' still opens the old WordPress shop.');
        }
        if (rw.last && !rw.last.undone) h += '<div class="dwi-row">' + btn('undo_rewrite', 'Undo the change of ' + when(String(rw.last.at).replace(' ', 'T')), { quiet: true }) + '</div>';
      }
      return h;
    },
    payments: function (s) {
      var p = st.steps.payments, can = st.can.payments;
      var h = what('Stripe, Tabby and Tamara send payment notices to the shop’s address. Each button tells one of them the new address and removes the old one. Then ' + b('Stripe dashboard → Settings → Payment method domains → Add') + ' ' + copy(st.new) + ' for Apple Pay and Google Pay.');
      h += '<div class="dwi-row">' + btn('stripe', 'Set up Stripe webhook automatically', { disabled: !can, quiet: p.stripe.level === 'done' })
        + btn('tabby', 'Register Tabby', { disabled: !can, quiet: p.tabby.level === 'done' })
        + btn('tamara', 'Register Tamara', { disabled: !can, quiet: p.tamara.level === 'done' }) + '</div>';
      if (!can) h += note('These buttons wait for step ' + esc(num('switch')) + ': the providers are told the shop’s own address.');
      h += note('Verify asks each provider with the keys the shop holds; it changes nothing. Still on sandbox keys? Press “Skip for now” and come back when the live keys are in.');
      return h + payList((s.data || {}).payments);
    },
    callbacks: function () {
      return what('The shop cannot read these dashboards, so press “Mark as done” when you have added the new addresses:')
        + '<ul class="dwi-list"><li><span class="dwi-dot"></span><span>' + b('Meta for developers → your app → Instagram → Business login settings → OAuth redirect URIs') + ': add the Instagram address below. Keep the old one until a reconnect has worked.</span></li>'
        + '<li><span class="dwi-dot"></span><span>Only if you use “Connect with Stripe”: the Connect settings’ redirect URIs: add the Stripe Connect address below.</span></li>'
        + '<li><span class="dwi-dot"></span><span>' + b('Meta Events Manager → your pixel → Settings → Traffic permissions') + ': if an allow list is on, add ' + esc(st.new) + '.</span></li></ul>'
        + vals([val('Instagram', st.copy.instagram), val('Stripe Connect', 'https://' + st.target + '/admin-api/payments/stripe/connect/callback')]);
    },
    tests: function () {
      var t = 'https://' + st.target, link = function (p) { return '<a href="' + esc(t + p) + '" target="_blank" rel="noopener">' + esc(t + p) + '</a>'; };
      var h = what('Signed in, or on your phone with the preview link, place test orders on ' + link('/shop/') + ': card, cash on delivery, Tabby and Tamara. Then check: the order emails show ' + esc(st.target) + ' links; password reset works (' + link('/my-account/') + '); the Arabic shop works (' + link('/ar/') + '); the phone app installs.');
      if (st.coming_soon_link) h += vals([val('Preview link', st.coming_soon_link.url)]);
      return h + note('The shop cannot judge a test order for you, so press “Mark as done” when all of them passed.');
    },
    cs_off: function () {
      return what('Only after every test order passed. Then everyone sees the shop on ' + b(st.new) + '.')
        + '<div class="dwi-row">' + btn('coming_soon_off', 'Turn the Coming Soon page off') + '</div>';
    },
    forward: function () {
      var cs = st.coming_soon && st.coming_soon.on;
      var h = what('Every old ' + esc(st.old) + ' link, bookmark and installed app then lands on ' + b(st.new) + '. Leave it on for 2–4 weeks.');
      if (cs) h += '<div class="dwi-res is-amber"><b>Not yet</b>The Coming Soon page is still on. Forwarding now would send every ' + esc(st.old) + ' customer to the Coming Soon page instead of the shop. Turn it off in ' + go('cs_off') + ' first.</div>';
      return h + '<div class="dwi-row">' + btn('forward_on', 'Forward ' + st.old + ' to ' + st.new, { disabled: cs || !st.can.forward }) + '</div>';
    },
    google: function (s) {
      var sm = 'https://' + st.target + '/sitemap.xml', ix = (s.data || {}).indexnow;
      return what(b('Google Search Console → the ' + st.new + ' property → Sitemaps') + ': remove the old WordPress sitemaps and add the one below. If ' + esc(st.old) + ' is a property there too: ' + b('Settings → Change of address → ' + st.new) + '. Then tell Bing and the other IndexNow engines.')
        + vals([val('Sitemap', sm)])
        + '<div class="dwi-row">' + btn('indexnow', 'Ping IndexNow (Bing and others)', { quiet: !!(ix && ix.ok) }) + '</div>'
        + (ix ? note('IndexNow ' + (ix.ok ? 'accepted' : 'did not accept') + ' the last ping, ' + esc(when(ix.at)) + '.') : '')
        + note('Verify checks what the shop gives Google (the sitemap’s address, nothing hiding it). Search Console itself cannot be checked from here.');
    },
    done: function () {
      var inst = st.installer, h = '<div class="dwi-sum">';
      var left = inst.steps.filter(function (x) { return x.status === null && x.key !== 'done'; });
      h += '<h4>Skipped — do later</h4>' + (inst.skipped.length ? '<ul>' + inst.skipped.map(function (x) {
        var why = x.message.length > 160 ? x.message.slice(0, 157) + '…' : x.message;
        return '<li>Step ' + esc(x.n) + ': ' + esc(x.title) + (why ? ' — ' + esc(why) : '') + ' <button type="button" class="dwi-link" data-dwi-step="' + esc(x.key) + '" data-dwi-do="undo">Return to it</button></li>';
      }).join('') + '</ul>' : note('Nothing skipped.'));
      if (left.length) h += '<h4>Not done yet</h4><ul>' + left.map(function (x) { return '<li>' + go(x.key, 'Step ' + x.n + ': ' + x.title) + '</li>'; }).join('') + '</ul>';
      /* Pictures never copied off Hostinger (Lane PX): admin.partials.old-pictures-panel draws here. */
      h += '<h4>Pictures still on the old server</h4><div data-oldpics></div>';
      h += '<h4>In 2–4 weeks, when nobody uses ' + esc(st.old) + ' any more</h4><ul>'
        + '<li>Press the button below: it runs the readiness check itself and refuses while anything still depends on ' + esc(st.old) + '.</li>'
        + '<li>Cloudways → Domain Management → remove ' + copy(st.old) + ' and ' + copy('www.' + st.old) + '.</li>'
        + '<li>Cloudways → SSL Certificate → Let’s Encrypt again with only ' + copy(st.copy.certificate_after) + ', then Varnish → Purge.</li>'
        + '<li>At ' + esc(st.old) + '’s registrar, remove its A record. Stripe dashboard → Payment method domains → remove ' + esc(st.old) + '.</li>'
        + '<li>When ' + esc(st.new) + '’s nameservers have shown Internet.bs for 2 days, delete the domain and site from Hostinger and cancel the plan — <b>but not before “Pictures still on the old server” above shows 0 missing.</b></li></ul>';
      return h + '<div class="dwi-row">' + btn('remove_old', 'Remove ' + st.old + ' completely', { quiet: true, disabled: !st.can.remove || st.steps.remove === 'done' }) + '</div></div>';
    }
  };

  /* ------------------------------------------------------------- render */
  function chip(s) {
    if (s.status === 'done') return '<span class="dwi-chip is-done">✓ Done</span>';
    if (s.status === 'skipped') return '<span class="dwi-chip is-skipped">Skipped — do later</span>';
    if (s.level === 'red') return '<span class="dwi-chip is-red">✕ Needs fixing</span>';
    return '<span class="dwi-chip is-todo">● To do</span>';
  }

  function stepHtml(s) {
    var inst = st.installer, isOpen = open === s.n;
    var cls = 'dwi-step' + (s.status === 'done' ? ' is-done' : '') + (s.status === 'skipped' ? ' is-skipped' : '') + (inst.current === s.n ? ' is-current' : '');
    var h = '<li class="' + cls + '" id="dwi-step-' + esc(s.n) + '" data-key="' + esc(s.key) + '"><button type="button" class="dwi-head" data-dwi-open="' + esc(s.n) + '" aria-expanded="' + (isOpen ? 'true' : 'false') + '">'
      + '<span class="dwi-num" aria-hidden="true">' + (s.status === 'done' ? '✓' : esc(s.n)) + '</span><span class="dwi-title">' + esc('Step ' + s.n + '. ' + s.title) + '</span>' + chip(s) + '</button>';
    if (!isOpen) return h + '</li>';
    h += '<div class="dwi-body">' + (BODY[s.key] ? BODY[s.key](s) : '') + result(s) + '<div class="dwi-row">';
    if (s.status === 'skipped') h += stepBtn('undo', s.key, 'Return to it');
    else if (s.kind === 'verify') h += stepBtn('verify', s.key, s.verified_at ? 'Verify again' : 'Verify', s.status === 'done' ? 'is-quiet' : '');
    else if (s.status !== 'done') h += stepBtn('done', s.key, 'Mark as done', 'is-ok');
    else h += stepBtn('undo', s.key, 'Not done yet', 'is-quiet');
    if (s.status !== 'skipped' && s.status !== 'done' && !s.critical && s.key !== 'done') h += stepBtn('skip', s.key, 'Skip for now', 'is-quiet');
    if ((s.status === 'done' || s.status === 'skipped') && s.n < inst.total) h += '<button type="button" class="dwi-btn is-quiet" data-dwi-open="' + esc(s.n + 1) + '">Next: step ' + esc(s.n + 1) + ' →</button>';
    h += '</div>';
    if (s.critical && s.status !== 'done') h += note(esc(s.why_no_skip));
    return h + '</div></li>';
  }

  function render() {
    var host = document.getElementById('content');
    if (!host || !host.querySelector('[data-dw-screen]')) return;
    var root = host.querySelector('[data-dw-screen]');
    if (banner) { root.innerHTML = '<div class="dwi-res is-red">' + esc(banner) + '</div>'; return; }
    if (!st) { root.innerHTML = '<div class="dwi-res is-info">Loading…</div>'; return; }
    var inst = st.installer;
    var typed = root.querySelector('#dwi-domain') ? root.querySelector('#dwi-domain').value : null;
    var typedOverride = root.querySelector('#dwi-override') ? root.querySelector('#dwi-override').value : null;
    if (open === null) open = inst.current || inst.total;
    var finished = inst.done + inst.skipped.length;
    root.innerHTML = '<h2>Move the shop to ' + esc(st.new) + '</h2>'
      + '<p class="dwi-lead">' + esc(inst.total) + ' steps, in order. Each one says what to do, then checks it for you. A step you cannot do yet can be skipped and done later; the last step lists them.</p>'
      + '<div class="dwi-sos" role="note"><b>If anything goes wrong:</b> Cloudways → Servers → Launch SSH Terminal, in the app folder run ' + copy(st.emergency)
      + ' and the shop shows again on the next request. ' + esc(st.old) + ' keeps working throughout: to go back after step ' + esc(num('switch')) + ', open ' + esc(st.old_admin) + ' with your admin path, go to Platform → Site address, press “Use this address from now on”, and set Main address back to ' + esc(st.old) + '.</div>'
      + '<div class="dwi-bar"><div class="dwi-bar-top">' + (inst.finished ? 'All ' + esc(inst.total) + ' steps done or skipped' : 'Step ' + esc(inst.current) + ' of ' + esc(inst.total))
      + '<span>' + esc(inst.done) + ' done · ' + esc(inst.skipped.length) + ' skipped</span></div>'
      + '<div class="dwi-track" role="progressbar" aria-valuemin="0" aria-valuemax="' + esc(inst.total) + '" aria-valuenow="' + esc(finished) + '"><div class="dwi-fill" style="width:' + Math.round(finished / inst.total * 100) + '%"></div></div></div>'
      + '<ol class="dwi-steps">' + inst.steps.map(stepHtml).join('') + '</ol>'
      + '<div class="dwi-foot"><span>Progress is saved on the server, so it is the same on your phone.</span><button type="button" class="dwi-link" data-dwi-reset>Reset progress</button></div>';
    if (window.kbbOldPictures) window.kbbOldPictures.mountAll(root);
    var input = root.querySelector('#dwi-domain');
    if (input && typed !== null) input.value = typed;
    var ov = root.querySelector('#dwi-override');
    if (ov && typedOverride !== null) ov.value = typedOverride;
  }

  function take(body) {
    var next = body && body.state ? body.state : (body && body.installer ? body : null);
    if (next) st = next;
  }

  async function load() {
    var mine = ++seq;
    var r = await api('/domain-switch');
    if (mine !== seq) return;
    if (r.status !== 200) { banner = fail(r.status, r.body); render(); return; }
    banner = ''; st = r.body; render();
  }

  var CONFIRMS = {
    set_names: function () { return 'Save ' + ((document.getElementById('dwi-domain') || {}).value || st.new) + ' as the shop’s new name? Nothing changes for shoppers.'; },
    coming_soon_on: function () { return 'Show the Coming Soon page on ' + st.new + '? ' + st.old + ' keeps showing the shop.'; },
    coming_soon_off: function () { return 'Turn the Coming Soon page off? Everyone will see the shop on ' + st.new + '.'; },
    switch_address: function () { return 'The shop will call itself ' + st.proposed.app_url + ' from now on, in every email and link. Continue?'; },
    clear_caches: function () { return 'Clear the shop’s caches? Pages are rebuilt on their next visit.'; },
    forward_on: function () { return 'Send every ' + st.old + ' visitor to ' + st.new + '?'; },
    stripe: function () { return 'Tell Stripe the new address and remove the old webhook?'; },
    tabby: function () { return 'Tell Tabby the new address and remove the old webhook?'; },
    tamara: function () { return 'Remove Tamara’s old registration and register the new address?'; },
    remove_old: function () { return 'Remove ' + st.old + ' from the shop completely? Only 2–4 weeks after forwarding started.'; }
  };

  var STEP_OF = { set_names: 'name', coming_soon_on: 'cs_on', switch_address: 'switch', clear_caches: 'caches', rewrite_content: 'links', undo_rewrite: 'links',
    stripe: 'payments', tabby: 'payments', tamara: 'payments', fetch_pictures: 'start', coming_soon_off: 'cs_off', forward_on: 'forward', indexnow: 'google', remove_old: 'done' };

  async function run(action) {
    if (action === 'rw_preview') {
      busy = action; render();
      var g = await api('/domain-switch/rewrite');
      busy = '';
      if (g.status === 200) rw = g.body; else msgs.links = { ok: false, text: fail(g.status, g.body) };
      render(); return;
    }
    var body = { action: action };
    if (CONFIRMS[action] && !window.confirm(CONFIRMS[action]())) return;
    if (action === 'set_names') body.domain = (document.getElementById('dwi-domain') || {}).value || '';
    if (action === 'switch_address') {
      body.confirm = st.proposed.confirm || '';
      body.override = ((document.getElementById('dwi-override') || {}).value || '').trim();
    }
    if (action === 'remove_old') body.confirm = 'REMOVE';
    if (action === 'rewrite_content') {
      if (!rw || !window.confirm('Change ' + rw.links + ' link(s) to https://' + rw.new + '? Undo is offered afterwards.')) return;
      body.confirm = String(rw.links);
    }
    var key = STEP_OF[action] || 'start';
    busy = action; render();
    var r = await api('/domain-switch/run', body);
    busy = '';
    var ok = r.status >= 200 && r.status < 300 && r.body.ok !== false;
    msgs[key] = { ok: ok, text: ok ? (r.body.message || 'Done.') : fail(r.status, r.body) };
    take(r.body);
    if (ok && (action === 'rewrite_content' || action === 'undo_rewrite')) { var again = await api('/domain-switch/rewrite'); if (again.status === 200) rw = again.body; }
    render();
  }

  async function step(key, doWhat) {
    var body = { step: key, 'do': doWhat };
    if (doWhat === 'reset') {
      if (!window.confirm('Reset the progress of every step? Only the ticks on this page are forgotten; the shop itself is not changed.')) return;
      body = { 'do': 'reset', confirm: 'RESET' };
    }
    busy = doWhat + ':' + key; delete msgs[key]; render();
    var r = await api('/domain-switch/step', body);
    busy = '';
    var ok = r.status >= 200 && r.status < 300 && r.body.ok !== false;
    if (!ok || doWhat === 'skip' || doWhat === 'reset') msgs[key || 'start'] = { ok: ok ? null : false, text: ok ? r.body.message : fail(r.status, r.body) };
    take(r.body);
    if (ok && doWhat === 'reset') open = null;
    else if (ok && doWhat === 'undo') open = num(key);
    else if (ok && (doWhat === 'done' || doWhat === 'skip')) open = st.installer.current || st.installer.total;
    render();
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
    var el = t.closest('[data-copy]');
    if (el) { copyText(el.getAttribute('data-copy'), el); return; }
    el = t.closest('[data-dwi-step]');
    if (el && !el.disabled) { step(el.getAttribute('data-dwi-step'), el.getAttribute('data-dwi-do')); return; }
    if (t.closest('[data-dwi-reset]')) { step('', 'reset'); return; }
    el = t.closest('[data-dw]');
    if (el && !el.disabled) { run(el.getAttribute('data-dw')); return; }
    el = t.closest('[data-dwi-open]');
    if (el) {
      var n = +el.getAttribute('data-dwi-open'), head = el.classList.contains('dwi-head');
      open = (head && open === n) ? -1 : n;
      render();
      var li = document.getElementById('dwi-step-' + n);
      if (li && !head) li.scrollIntoView({ block: 'start' });
      return;
    }
    el = t.closest('[data-screen]');
    if (el && window.go) window.go(el.getAttribute('data-screen'));
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
    host.innerHTML = '<div class="wrap dwi" data-dw-screen></div>';
    st = null; rw = null; msgs = {}; busy = ''; banner = ''; open = null;
    render();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
