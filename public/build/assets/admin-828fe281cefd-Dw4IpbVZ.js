
(function () {
  'use strict';

  var SCREEN = 'security';

  /* UNFINISHED CHANGES (Lane PM). Leaving this screen with edits in `values`
     used to throw them away without a word. They are kept in Unfinished in
     the top bar instead (partials/unfinished-drafts.blade.php), and come back
     into `values` when the screen is next opened. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Store → Security',
    values: function () { return tabs ? values : null; },
    set: function (k, v) { if (Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); },
    save: function () { save(); }
  });

  var tabs = null, report = null, values = {}, open = null, banner = null, busy = false, seq = 0;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status; err.body = payload;
      throw err;
    }
    return payload;
  }

  /* EVERYTHING from the server goes through this on its way into the document.
     A summary names a setting key and an email, `before`/`after` are values the
     owner typed, and `actor` is whatever somebody put in a login box — not one
     of them is a constant, so not one of them may be printed raw. */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  function explain(e, fallback) {
    if (e && e.status === 404) {
      return 'The Security endpoints are not in this server\'s compiled route table yet. '
        + 'Clear the route cache and reload.';
    }
    if (e && e.status === 403) {
      return 'Your role cannot do that. This screen is the owner\'s, and running the integrity check '
        + 'is a second capability of its own on top of reading it.';
    }
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Security',
      icon: '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="m9 12 2 2 4-4"/>',
      group: 'Store',
      after: ['payments', 'modules', 'analytics']
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
    if (title) title.textContent = 'Security';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();

    try {
      var body = await api('/security');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      report = body.report || null;
      values = {};
      tabs.forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The security report could not be read.');
    } finally {
      if (mine === seq) {
        busy = false; render();
        if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
    }
  }

  async function save() {
    if (busy) return;
    busy = true; render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });

    try {
      var body = await api('/security', { settings: payload });
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      report = body.report || report;
      say('Security settings saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false; render();
    }
  }

  /* ------------------------------------------------------------- the fields */
  function shown(f) {
    return String(values[f.key]) + ((f.options || {}).unit || '');
  }

  function fieldHTML(f) {
    var id = 'sx-' + f.key;
    var help = f.help ? '<p class="sx-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="sx-f"><div class="sx-check">'
        + '<input type="checkbox" id="' + id + '" data-sx-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '')
          + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="sx-f"><div class="sx-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + id + '" data-sx-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="sx-f"><div class="sx-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="sx-val" data-sx-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
        + '<input type="range" id="' + id + '" data-sx-key="' + esc(f.key) + '"'
        + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
        + help + '</div>';
    }

    return '<div class="sx-f"><div class="sx-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-sx-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '" autocomplete="off">' + help + '</div>';
  }

  /* -------------------------------------------------------------- the lists */
  function pill(row) {
    if (row.severity === 'alert') return '<span class="sx-pill is-alert">needs a look</span>';
    if (row.severity === 'notice') return '<span class="sx-pill is-notice">notice</span>';
    return '';
  }

  function rowHTML(row) {
    var meta = [];
    /* "TRIED AS", NOT "BY", on a failed sign-in. The actor on those rows is
       whatever was typed into the email box — it names nobody, and printing it
       as "by" would read as an accusation against the owner of that address,
       who is very often the person reading this screen. */
    var tried = row.event === 'signin.failed' || row.event === 'signin.blocked';
    /* AND NO ACTOR AT ALL ON AN INTEGRITY FINDING. The row carries none — the
       server writes it with no actor, no address and no request path — but this
       branch is here so that a future row that somehow did carry one still
       could not print it. Whoever changed a shipped file, the check cannot know
       who it was, and the one name it could reach for is the owner who happened
       to open this screen: the single person it can prove is innocent. */
    var unattributed = row.event.indexOf('integrity.') === 0;
    /* A CSP VIOLATION HAS NO ACTOR EITHER, and for a sharper reason than an
       integrity finding does: a stranger's browser wrote it. The server sends
       no actor on these rows -- SecurityModule's `no_actor` -- but the address
       and the page it happened on ARE the evidence and are kept, so this is not
       the same flag as `unattributed` above and must not reuse it. */
    var violation = row.event === 'csp.violation';
    if (row.actor && !unattributed && !violation) {
      meta.push((tried ? 'tried as <b>' : 'by <b>') + esc(row.actor) + '</b>'
        + (!tried && row.role ? ' (' + esc(row.role) + ')' : ''));
    }
    if (row.ip && !unattributed) meta.push('from <b>' + esc(row.ip) + '</b>');
    if (row.path && !unattributed) meta.push(esc(row.method ? row.method + ' ' + row.path : row.path));
    if (unattributed) meta.push('no record of who — this was not done through the admin');
    if (violation) meta.push('reported by a browser — nobody was signed in');
    if (row.hits > 1) {
      meta.push('<b>' + esc(row.hits) + '</b> '
        + (unattributed ? 'checks have found it this way, last at ' : 'times, last at ')
        + esc(row.last_at));
    }

    var diff = '';
    if (row.before !== null || row.after !== null) {
      /* "was"/"now" is a setting changing. A violation's two values are not a
         before and an after -- they are what the policy would have stopped and
         the 40 characters the browser quoted back -- and labelling them "was"
         and "now" would read as though the shop had changed something. */
      var wasLabel = violation ? 'blocked' : 'was';
      var nowLabel = violation ? 'sample' : 'now';
      diff = '<div class="sx-diff">'
        + (row.before !== null ? '<div><i>' + esc(wasLabel) + '</i><code>' + esc(row.before) + '</code></div>' : '')
        + (row.after !== null ? '<div><i>' + esc(nowLabel) + '</i><code>' + esc(row.after) + '</code></div>' : '')
        + '</div>';
    }

    return '<div class="sx-row">'
      + '<div class="sx-when">' + esc(row.at) + '</div>'
      + '<div class="sx-what">'
      + '<div class="sx-sum">' + esc(row.summary) + ' ' + pill(row) + '</div>'
      + (meta.length ? '<div class="sx-meta">' + meta.join(' · ') + '</div>' : '')
      + diff
      + '</div></div>';
  }

  function listHTML(title, note, rows, empty, off) {
    return '<div class="sx-card">'
      + '<div class="sx-head"><div class="sx-title">' + esc(title) + '</div>'
      + '<span class="sx-meta">' + esc(rows.length) + ' shown</span></div>'
      + '<p class="sx-sub">' + esc(note) + '</p>'
      + (off ? '<div class="sx-off">' + esc(off) + '</div>' : '')
      + (rows.length
          ? '<div class="sx-rows">' + rows.map(rowHTML).join('') + '</div>'
          : '<div class="sx-empty">' + esc(empty) + '</div>')
      + '</div>';
  }

  /* ---------------------------------------------------------- the integrity card
     Phase 18 item 3, report-only. The card says four things and in this order,
     because that is the order somebody reading it needs them:

       1. what the check is measuring, in one sentence;
       2. when it last ran and over how much;
       3. WHAT IT CANNOT SEE -- printed here, beside the findings, and not
          tucked into a help text under a switch. The owner's ask was "no bot
          can inject code anywhere", and a screen that let him believe this
          covered a file somebody ADDED would be worse than no screen;
       4. the findings themselves, each with the hash the package declared and
          the hash the server holds now. */
  function integrityHTML() {
    var g = report.integrity, rows = report.integrity_rows || [];

    var state = '';
    if (!g.on) {
      state = '<div class="sx-off">Integrity checking is switched off on the "File integrity" tab below, '
        + 'so nothing new will appear here.</div>';
    } else if (!g.ran_at) {
      state = '<div class="sx-off">This has not run yet. Press "Check now" — it runs by itself when you '
        + 'open this screen and the last check is more than ' + esc(g.every_hours) + ' hours old.</div>';
    } else {
      state = '<div class="sx-state">'
        + '<span>last checked <b>' + esc(g.ran_at) + '</b></span>'
        + '<span><b>' + esc(g.checked) + '</b> of <b>' + esc(g.expected) + '</b> files checked</span>'
        + '<span>from <b>' + esc(g.releases) + '</b> of <b>' + esc(g.applied) + '</b> installed '
        + (g.applied === 1 ? 'package' : 'packages') + '</span>'
        + (g.skipped ? '<span><b>' + esc(g.skipped) + '</b> too large to hash</span>' : '')
        + '<span>took <b>' + esc(g.took_ms) + '</b>ms</span>'
        + '</div>'
        + (g.truncated
            ? '<div class="sx-off">There were more files than one check may walk, so this covered the first '
              + esc(g.checked) + '. The rest are unchecked rather than clean.</div>'
            : '')
        + (g.expected === 0
            ? '<div class="sx-off">There is nothing to check against: no package has been applied to this '
              + 'server, or the copies of the ones that were are no longer in storage. Every package is '
              + 'kept as a zip when it applies — the same ones with a Download button on Store \u2192 Core '
              + 'Updates \u2014 and the hashes are read back out of those, so this fills in the moment a '
              + 'package applies.</div>'
            : (g.applied > g.releases
                ? '<div class="sx-off"><b>' + esc(g.applied - g.releases) + '</b> of the <b>' + esc(g.applied)
                  + '</b> packages applied to this server left no copy behind, so the files they installed '
                  + 'are not covered above. Packages have been archived on apply since 2.60.41; anything '
                  + 'older than that, and the original shop itself, was never installed as a package at '
                  + 'all.</div>'
                : '')
              + (g.findings === 0
                ? '<div class="sx-clean">Every file a package installed still hashes to what that package '
                  + 'declared. Nothing was changed on disk to reach that answer.</div>'
                : ''));
    }

    return '<div class="sx-card">'
      + '<div class="sx-head"><div class="sx-title">Integrity of the files packages installed</div>'
      + '<span class="sx-meta">' + esc(rows.length) + ' shown</span></div>'
      + '<p class="sx-sub">Every update package declares a SHA-256 for each file it installs, and the shop '
      + 'keeps those. This hashes the same files on the server and reports any that no longer match, or that '
      + 'are gone. It is the one question this host cannot answer any other way — there is no shell on it, '
      + 'so nothing else here can diff, list or hash anything.</p>'
      + state
      + '<div class="sx-actions">'
      + '<button class="sx-btn" data-sx-check' + (busy || !g.on ? ' disabled' : '') + '>'
      + (busy ? 'Checking…' : 'Check now') + '</button>'
      + '</div>'
      + '<div class="sx-note" style="margin-top:12px">'
      + '<b>What this cannot see.</b> It speaks only about files an update package installed. The original '
      + 'shop was not installed as a package, so most of the tree has no hash to compare against; uploads, '
      + '<code>storage/</code> and anything created on the server are outside it. <b>It cannot see a file that '
      + 'was ADDED</b> — there is no declared hash to miss, so a dropped-in script is invisible to this '
      + 'check, and that is worth knowing before you trust it. And a change you made on purpose reads exactly '
      + 'like one you did not, because from here they are the same event.'
      + '<br><br><b>It reports and stops there.</b> Nothing is restored, quarantined or deleted. Putting a '
      + 'file back from the package that installed it is genuinely possible — the package is still on this '
      + 'server — but it is a write, and it would also silently undo a deliberate edit. Whether that should '
      + 'happen automatically is your decision, and the "File integrity" tab below is where it appears once '
      + 'you have taken it.'
      + '</div>'
      + (rows.length
          ? '<div class="sx-rows" style="margin-top:12px">' + rows.map(rowHTML).join('') + '</div>'
          : '')
      + '</div>';
  }

  /* ----------------------------------------------------- the policy card
     Phase 18 item 5, report-only. The card says four things, in this order:

       1. what the policy IS and that it refuses nothing;
       2. whether it is being sent at all -- a policy shown on a screen that is
          not on any page is the most misleading thing this screen could print;
       3. the policy itself, in full, because the owner cannot read a response
          header on a host with no shell and this is the only place he can see
          what his shop is telling browsers;
       4. the violations, each with the directive, the thing, and the page. */
  function cspHTML() {
    var c = report.csp || {}, rows = report.csp_rows || [];

    var state = c.on
      ? '<div class="sx-state">'
        + '<span>sent as <b>' + esc(c.header) + '</b></span>'
        + '<span><b>' + esc(c.kept) + '</b> of <b>' + esc(c.max_rows) + '</b> violation rows kept</span>'
        + '<span>repeats collapse for <b>' + esc(c.window) + '</b>s</span>'
        + '</div>'
        /* SHED REPORTS, said plainly and not left out. A page view can make a
           browser post more reports than the endpoint accepts in a minute, and
           the ones it turns away are lost. Without this line the list below
           reads as complete, and an absent violation reads as "does not
           happen" when it means "was not seen". */
        + (c.shed > 0
            ? '<div class="sx-off"><b>' + esc(c.shed) + '</b> more reports were turned away by the '
              + 'endpoint\u2019s own limit rather than recorded. That is the shop protecting itself and '
              + 'not something going wrong \u2014 but it means the list below is a sample, not a count. '
              + 'One view of a busy page can post more than a hundred of these.</div>'
            : '')
      : '<div class="sx-off">The policy is not being sent. No page carries it, no browser is '
        + 'checking anything against it, and nothing new will appear below until you turn it on '
        + 'under "Content security policy" in the settings at the foot of this screen.</div>';

    return '<div class="sx-card">'
      + '<div class="sx-head"><div class="sx-title">Content security policy</div>'
      + '<span class="sx-meta">' + esc(rows.length) + ' shown</span></div>'
      + '<p class="sx-sub">A list of the places this shop is allowed to load scripts, styles, fonts '
      + 'and images from. It is sent <b>report-only</b>: a browser that meets something outside the '
      + 'list loads it anyway and posts a short note back here. It is the most effective thing there '
      + 'is against injected script actually running — and the most likely to break a working page, '
      + 'which is exactly why it reports first.</p>'
      + state
      + '<div class="sx-note" style="margin-top:12px">'
      + '<b>It cannot be switched to blocking from this screen, or from any other.</b> The header '
      + 'that blocks has a different name, and that name is not anywhere in this shop\u2019s code — '
      + 'so there is no setting, and no value in any setting, that turns this into a page that '
      + 'refuses. Turning it on is a decision for after you have read the list below, and it is a '
      + 'code change when it comes.'
      + '<br><br><b>What it costs while it is on.</b> Every page view also costs your visitors\u2019 '
      + 'browsers a few short posts back to this shop, one per thing the policy would have stopped. '
      + 'This shop\u2019s own pages carry a lot of script written directly into the page, which the '
      + 'policy counts, so that is not a small number today. Leave it on for a few days, read what '
      + 'comes back, then turn it off again.'
      + '</div>'
      + '<div class="sx-note" style="margin-top:12px"><b>What the browsers are being told</b>'
      + '<br><code style="display:block;margin-top:6px;word-break:break-all;line-height:1.7">'
      + esc(c.policy) + '</code>'
      + '<br>Violations are posted to <code>' + esc(c.report_uri) + '</code>.</div>'
      + (rows.length
          ? '<div class="sx-rows" style="margin-top:12px">' + rows.map(rowHTML).join('') + '</div>'
          : '<div class="sx-empty" style="margin-top:12px">'
            + esc(c.on ? 'Nothing has been reported yet.' : 'Nothing has been reported, and nothing will be while the policy is off.')
            + '</div>')
      + '</div>';
  }

  function verdictHTML() {
    var v = report.verdict, c = report.counts;

    return '<div class="sx-card sx-verdict is-' + esc(v.tone) + '">'
      + '<div class="sx-vline">' + esc(v.line) + '</div>'
      + '<p class="sx-sub">' + esc(v.detail) + '</p>'
      + '<div class="sx-counts">'
      + '<div class="sx-count"><b>' + esc(c.changed) + '</b><span>administrative changes</span></div>'
      + '<div class="sx-count"><b>' + esc(c.failed) + '</b><span>failed sign-ins</span></div>'
      + '<div class="sx-count"><b>' + esc(c.tripped) + '</b><span>requests refused as too many</span></div>'
      + '<div class="sx-count"><b>' + esc(report.integrity.findings) + '</b><span>files that do not match their package</span></div>'
      + '<div class="sx-count"><b>' + esc(c.violations) + '</b><span>content-policy violations reported</span></div>'
      + '<div class="sx-count"><b>' + esc(c.total) + '</b><span>rows kept in all</span></div>'
      + '</div>'
      + '<p class="sx-help" style="margin-top:10px">The counts above cover the last '
      + esc(report.window_hours) + ' hours, except the files, which are what the last check found on disk, '
      + 'and the last, which is everything still kept. Rows are deleted once they are '
      + esc(report.keep_days) + ' days old, and the newest ' + esc(report.max_rows) + ' are kept whatever '
      + 'their age — that ceiling is enforced as rows are written, so it holds even if nobody opens this '
      + 'screen for a year.</p>'
      + '</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Security') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="sx-wrap"><div class="sx-card"><div class="sx-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs || !report) {
      host.innerHTML = '<div class="sx-wrap"><div class="sx-card">'
        + '<div class="sx-title">Security</div>'
        + '<p class="sx-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="sx-actions"><button class="sx-btn" data-sx-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="sx-tab" data-sx-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];
    var rec = report.recording;

    host.innerHTML = '<div class="sx-wrap">'
      + (banner ? '<div class="sx-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + verdictHTML()
      /* The long form of the one clause the verdict carries. The verdict says
         "it blocks nothing"; this says what that buys and where blocking does
         belong, without repeating those three words directly under them. */
      + integrityHTML()

      + cspHTML()

      + '<div class="sx-note">Floods, bots and country rules are refused by the <b>Firewall</b> '
      + '(Store \u2192 Security \u2192 Firewall), which starts in Monitor mode \u2014 counting and logging, '
      + 'refusing nothing \u2014 so you can see what it would refuse before it does. The content-security '
      + 'policy above reports rather than refuses for the same reason.'
      + ' <button type="button" class="sx-btn" data-fw-open style="margin-left:6px">Open the Firewall</button></div>'

      + listHTML('Failed sign-ins', 'Every wrong password on the admin login, and every attempt the '
          + 'five-per-minute throttle turned away before it reached the password at all. The password '
          + 'itself is never recorded — only the email that was tried.',
          report.signin_trouble, 'No failed sign-in has been recorded.',
          rec.signins ? '' : 'Recording sign-ins is switched off below, so nothing new will appear here.')

      + listHTML('Requests refused as too many', 'The shop answers 429 to a caller that asks too often. '
          + 'Until now it said so to that caller and forgot. Repeats from one address on one path collapse '
          + 'onto a single row with a count.',
          report.trips, 'No request has been refused as too many.',
          rec.trips ? '' : 'Recording rate-limit trips is switched off below, so nothing new will appear here.')

      + listHTML('Administrative changes', 'Settings, back-office accounts and core-update packages: who, '
          + 'from where, and what the value said before. Values of settings whose name says they hold a '
          + 'credential are withheld rather than copied here.',
          report.changes, 'No administrative change has been recorded.',
          rec.changes ? '' : 'Recording administrative changes is switched off below, so nothing new will appear here.')

      + '<div class="sx-card">'
      + '<div class="sx-title">Settings</div>'
      + '<p class="sx-sub">What is recorded, what the verdict counts, and how long any of it is kept.</p>'
      + '<div class="sx-tabs" style="margin-top:12px">' + strip + '</div>'
      + '<p class="sx-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="sx-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '<div class="sx-actions">'
      + '<button class="sx-btn is-primary" data-sx-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="sx-btn" data-sx-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      + '<button class="sx-btn" data-sx-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
      + '</div>'
      + '<p class="sx-help" style="margin-top:8px">"Back to defaults" moves the controls in front of you. '
      + 'Nothing is stored until you press Save, and Reload undoes it.</p>'
      + '</div>'
      + '</div>';
  }

  /* --------------------------------------------------------------- events */
  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-sx-key]');
    if (!el) return;

    var key = el.getAttribute('data-sx-key');
    if (el.type === 'checkbox') values[key] = el.checked;
    else if (el.type === 'range') values[key] = Number(el.value);
    else values[key] = el.value;

    /* A checkbox or a select can change what belongs on the screen, and both
       are single clicks nobody is dragging, so they redraw. A range only
       repaints its own read-out: rebuilding the controls mid-drag drops the
       pointer capture. */
    if (el.type === 'checkbox' || el.tagName === 'SELECT') { render(); return; }

    var out = document.querySelector('[data-sx-val="' + key + '"]');
    if (out) {
      var f = null;
      tabs.forEach(function (t) { t.fields.forEach(function (x) { if (x.key === key) f = x; }); });
      if (f) out.textContent = shown(f);
    }
  });

  document.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-sx-tab]');
    if (tab) { open = tab.getAttribute('data-sx-tab'); render(); return; }
    if (e.target.closest('[data-sx-save]')) { save(); return; }
    if (e.target.closest('[data-sx-reload]')) { load(); return; }
    if (e.target.closest('[data-sx-defaults]')) { defaults(); return; }
    if (e.target.closest('[data-sx-check]')) { check(); return; }
    if (e.target.closest('[data-fw-open]')) { window.go('firewall'); return; }
  });

  /* "Check now". A POST, because it makes the server do work and write rows --
     not because it edits anything: every file it touches it opens for reading.
     Its own endpoint behind its own owner-only capability, which is why it is
     a separate call rather than a flag on load(). */
  async function check() {
    if (busy) return;
    busy = true; banner = null; render();

    try {
      var body = await api('/security/integrity', {});
      report = body.report || report;
      var n = (body.state || {}).findings || 0;
      say(n === 0
        ? 'Checked. Every file a package installed still matches it.'
        : 'Checked. ' + n + (n === 1 ? ' file does' : ' files do') + ' not match — listed below.');
    } catch (e) {
      banner = explain(e, 'The integrity check could not be run.');
    } finally {
      busy = false; render();
    }
  }

  function defaults() {
    if (!tabs) return;

    tabs.forEach(function (t) {
      t.fields.forEach(function (f) { values[f.key] = f['default']; });
    });

    render();
    say('Back to the shipped values. Nothing is saved until you press Save.');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
