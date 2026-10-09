
(function () {
  'use strict';

  var SCREEN = 'firewall';
  var TABS = [['overview', 'Overview'], ['live', 'Live activity'], ['rules', 'Rules'], ['countries', 'Countries'],
              ['bots', 'Good bots'], ['lists', 'Allow & block lists'], ['data', 'Data']];
  var data = null, live = null, blocks = null, values = {}, rules = {}, bots = {};
  var banner = null, busy = false, seq = 0, tab = 'overview', query = '', filter = 'rule';

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function call(path, body) {
    var opts = { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
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
      var err = new Error(path + ' -> ' + r.status);
      err.status = r.status; err.body = payload;
      throw err;
    }
    return payload;
  }
  function api(path, body) { return call('/security/firewall' + path, body); }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  function explain(e, fallback) {
    if (e && e.status === 404) return 'The Firewall endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot do that: the Firewall is the owner\'s.';
    if (e && e.status === 429) return 'Too many presses in a minute. Wait a moment and try again.';
    return (e && e.body && (e.body.error || e.body.message)) ? (e.body.error || e.body.message) : fallback;
  }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Firewall',
      icon: '<path d="M3 5h18v14H3z"/><path d="M3 10h18M3 14.5h18M9 5v5M15 10v4.5M9 14.5V19"/>',
      group: 'Store',
      after: ['security']
    });
  }

  /* ─────────────────────────────────────────── the tab in the address */
  function hashTab() {
    var m = /^#firewall\/([a-z]+)$/.exec(location.hash || '');
    return m && TABS.some(function (t) { return t[0] === m[1]; }) ? m[1] : null;
  }

  function remember() {
    try { history.replaceState(null, '', location.pathname + location.search + '#firewall/' + tab); } catch (e) {}
  }

  function onScreen() { return (document.querySelector('#ptitle') || {}).textContent === 'Firewall'; }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Store → Security';
    if (title) title.textContent = 'Firewall';
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    tab = hashTab() || tab;
    remember();
    render();
    load();
    return undefined;
  };

  // #firewall or #firewall/<tab> pasted into an open console (a same-document
  // navigation, so the console's own boot never sees it): on this screen,
  // move to the tab; anywhere else, open the screen at that tab.
  window.addEventListener('hashchange', function () {
    if (!/^#firewall(\/[a-z]+)?$/.test(location.hash || '')) return;
    var want = hashTab();
    if (onScreen() && data) { if (want && want !== tab) open(want); return; }
    try { window.go(SCREEN); } catch (e) {}
  });

  /* ─────────────────────────────────────────────────── data */
  async function load() {
    var mine = ++seq;
    busy = true; banner = null; render();
    try {
      var body = await api('');
      if (mine !== seq) return;
      take(body);
      live = null; blocks = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The firewall could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); lazy(); }
    }
  }

  function take(body) {
    data = body;
    values = {}; rules = {}; bots = {};
    body.fields.forEach(function (f) { values[f.key] = f.value; });
    body.countries.forEach(function (c) { rules[c.code] = c.action; });
    body.bots.forEach(function (b) { bots[b.family] = b.on; });
  }

  /* The two tabs whose data is fetched only when they are opened. */
  function lazy() {
    if (!data || busy) return;
    if (tab === 'live' && live === null) {
      act(function () { return api('/live'); }, function (b) { live = b.live; }, 'The live view could not be read.');
    } else if (tab === 'lists' && blocks === null) {
      blocks = 'loading';
      call('/cart-tracking/blocks').then(function (b) { blocks = b.rows || []; }, function () { blocks = 'denied'; })
        .then(function () { if (onScreen() && tab === 'lists') render(); });
    }
  }

  async function act(run, done, fallback) {
    if (busy) return;
    busy = true; banner = null; render();
    try {
      done(await run());
    } catch (e) {
      banner = explain(e, fallback);
    } finally {
      busy = false; render();
    }
  }

  function open(t) {
    tab = t;
    remember();
    render();
    lazy();
  }

  /* ─────────────────────────────────────────────────── pieces */
  var MODE = {
    off: ['grey', 'Off', 'Nothing is counted and nothing is refused.'],
    monitor: ['amber', 'Monitor', 'Counting and logging, refusing nothing. Watch Live activity for a few days, then switch it On.'],
    enforce: ['green', 'On', 'Refusing what the rules describe. Shoppers see nothing: no captcha, no check page, no delay.']
  };
  var SHORT = { flood: 'flood ban', banned: 'while banned', no_proof: 'posted without a page', fake_bot: 'fake bot', country: 'country blocked', watch: 'watched' };
  var DIS = function () { return busy ? ' disabled' : ''; };

  function field(key) {
    var fl = null;
    data.fields.forEach(function (x) { if (x.key === key) fl = x; });
    if (!fl) return '';
    var id = 'fwl-' + fl.key, help = fl.help ? '<p class="sx-help">' + esc(fl.help) + '</p>' : '';
    if (fl.type === 'bool') {
      return '<div class="sx-f"><div class="sx-check"><input type="checkbox" id="' + id + '" data-fwl-key="' + esc(fl.key) + '"'
        + (values[fl.key] ? ' checked' : '') + '><div><label for="' + id + '">' + esc(fl.label) + '</label>' + help + '</div></div></div>';
    }
    if (fl.type === 'select') {
      var opts = Object.keys(fl.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[fl.key]) === k ? ' selected' : '') + '>' + esc(fl.options[k]) + '</option>';
      }).join('');
      return '<div class="sx-f"><div class="sx-fh"><label for="' + id + '">' + esc(fl.label) + '</label></div>'
        + '<select class="inp" id="' + id + '" data-fwl-key="' + esc(fl.key) + '">' + opts + '</select>' + help + '</div>';
    }
    return '<div class="sx-f"><div class="sx-fh"><label for="' + id + '">' + esc(fl.label) + '</label>'
      + '<span class="sx-val" data-fwl-val="' + esc(fl.key) + '">' + esc(values[fl.key] + (fl.unit || '')) + '</span></div>'
      + '<input type="range" id="' + id + '" data-fwl-key="' + esc(fl.key) + '" min="' + esc(fl.min) + '" max="' + esc(fl.max)
      + '" step="' + esc(fl.step) + '" value="' + esc(values[fl.key]) + '">' + help + '</div>';
  }

  /* One section: a heading, a card, a one-line lead, its body, and its own Save. */
  function section(title, lead, body, save, extra) {
    return '<div class="fwl-head"><div class="sec-title">' + esc(title) + '</div>' + (extra || '') + '</div>'
      + '<div class="card pad">' + (lead ? '<p class="fwl-lead">' + lead + '</p>' : '') + body
      + (save ? '<div class="fwl-actions"><button type="button" class="btn sm" data-fwl-save="' + esc(save) + '"' + DIS() + '>Save</button></div>' : '')
      + '</div>';
  }

  function list(rows, empty, line) {
    if (!rows || !rows.length) return '<div class="fwl-empty">' + esc(empty) + '</div>';
    return '<div class="fwl-list">' + rows.map(line).join('') + '</div>';
  }

  function banRow(b) {
    return '<div class="fwl-li"><span><b>' + esc(b.who) + '</b>' + (b.country ? ' <span class="pill grey">' + esc(b.country) + '</span>' : '')
      + (b.monitor ? ' <span class="pill amber">monitor: not enforced</span>' : '')
      + '<small>' + esc(b.why) + ' · strike ' + esc(b.strikes) + ' · until ' + esc(String(b.until).replace('T', ' ').slice(0, 16)) + '</small></span>'
      + '<button type="button" class="btn ghost sm" data-fwl-unban="' + esc(b.subject) + '"' + DIS() + '>Unban</button></div>';
  }

  function kpi(label, value, sub) {
    return '<div class="kpi"><div class="lbl">' + esc(label) + '</div><div class="val">' + esc(value) + '</div>'
      + (sub ? '<div class="sub">' + esc(sub) + '</div>' : '') + '</div>';
  }

  /* ─────────────────────────────────────────────────── tabs */
  function overviewTab() {
    var mode = values.mode || 'off', m = MODE[mode] || MODE.off, s = data.summary, cdb = data.data.country;
    var seg = [['off', 'Off'], ['monitor', 'Monitor'], ['enforce', 'On']].map(function (x) {
      return '<button type="button" class="' + (mode === x[0] ? 'on' : '') + '" data-fwl-mode="' + x[0] + '"' + DIS() + '>' + x[1] + '</button>';
    }).join('');
    var protect = Object.keys(rules).filter(function (k) { return rules[k] === 'protect'; }).length;
    var block = Object.keys(rules).filter(function (k) { return rules[k] === 'block'; }).length;

    return section('Status', 'The switch for the whole firewall. Monitor is where it starts: it logs what it would refuse and refuses nothing.',
        '<div class="fwl-status"><span class="pill ' + m[0] + '"><span class="d"></span>' + esc(m[1]) + '</span>'
        + '<span class="sx-help" style="flex:1 1 260px">' + esc(m[2]) + '</span>'
        + '<div class="seg" role="group" aria-label="Firewall mode">' + seg + '</div></div>'
        + '<p class="sx-help" style="margin-top:14px">Emergency off from the server: <span class="fwl-cmd">php artisan kbb:firewall off</span></p>'
        + (cdb.ok ? '' : '<div class="fwl-warn">The country database is not on this server yet, so country rules are not applied. Open the Data tab and press "Download country database" once.</div>'))
      + '<div class="fwl-head"><div class="sec-title">Last 24 hours</div></div>'
      + '<div class="kpis">'
      + kpi('Requests refused', s.refused, mode === 'enforce' ? 'by the rules in force' : 'nothing is refused unless the mode is On')
      + kpi('Logged only', s.logged, 'what Monitor and Watch saw')
      + kpi('Active bans', s.bans.length, 'each ends by itself')
      + kpi('Countries with a rule', protect + block, protect + ' Protect · ' + block + ' Block')
      + '</div>'
      + section('Active bans', 'Addresses and ranges that sent more than any person could. Each ban ends by itself; Unban ends it now.',
          list(s.bans.slice(0, 8), 'No address is banned right now.', banRow)
          + (s.bans.length > 8 ? '<p class="sx-help" style="margin-top:8px">' + esc(s.bans.length - 8) + ' more on Live activity.</p>' : ''),
          null, '<button type="button" class="btn ghost sm" data-fwl-tab="live">Live activity</button>');
  }

  function liveTab() {
    if (live === null) return '<div class="card pad"><div class="fwl-empty">Loading the last 24 hours…</div></div>';
    var lv = live;
    var reasons = list(lv.reasons, 'Nothing flagged in the last 24 hours.', function (r) {
      return '<div class="fwl-li"><span>' + esc(r.label) + '</span><span class="fwl-n">'
        + (r.refused ? esc(r.refused) + ' refused' : '') + (r.refused && r.logged ? ' · ' : '') + (r.logged ? esc(r.logged) + ' logged' : '') + '</span></div>';
    });
    var countries = list(lv.countries, 'No country yet.', function (c) {
      return '<div class="fwl-li"><span>' + esc(c.country) + '</span><span class="fwl-n">' + esc(c.hits) + '</span></div>';
    });
    var ips = list(lv.ips, 'No address yet.', function (i) {
      return '<div class="fwl-li"><span><b>' + esc(i.ip) + '</b>' + (i.country ? ' <span class="pill grey">' + esc(i.country) + '</span>' : '')
        + '<small>' + esc(i.reasons.map(function (r) { return SHORT[r] || r; }).join(', ')) + '</small></span><span class="fwl-n">' + esc(i.hits) + '</span></div>';
    });
    var nets = list(lv.nets, 'No range yet.', function (n) {
      return '<div class="fwl-li"><span>' + esc(n.net) + '</span><span class="fwl-n">' + esc(n.hits) + '</span></div>';
    });

    return section('By reason', 'Every request the firewall refused, or would have refused in Monitor, in the last 24 hours. The newest minute can lag.',
        reasons, null, '<button type="button" class="btn ghost sm" data-fwl-live' + DIS() + '>Refresh</button>')
      + '<div class="grid2b">'
      + '<div>' + section('Top countries', 'Where the flagged requests came from.', countries) + '</div>'
      + '<div>' + section('Top ranges', 'The /24 (IPv6 /64) networks behind them.', nets) + '</div>'
      + '</div>'
      + section('Top addresses', 'The single addresses with the most flagged requests, and why.', ips)
      + section('Active bans', 'Each ban ends by itself. Unban ends it now.', list(lv.bans, 'No address is banned right now.', banRow));
  }

  function rulesTab() {
    return section('What a ban blocks', 'Where a banned address is refused. The admin, webhooks and payment returns are never refused.', field('scope'), 'scope')
      + section('Flood limits', 'More requests than these from one place is a flood, and earns a temporary ban. Set well above what a fast shopper makes.',
          '<div class="sx-fields" style="margin-top:0">' + field('ip_10s') + field('ip_60s') + field('net_60s') + field('prefetch_10s') + '</div>', 'flood')
      + section('Ban length', 'How long a flood is shut out. Repeat offenders double each time, up to the longest ban.',
          '<div class="sx-fields" style="margin-top:0">' + field('ban_minutes') + field('ban_max_minutes') + '</div>', 'bans')
      + section('Protect countries', 'Invisible extra protection for the countries set to Protect on the Countries tab.',
          '<div class="sx-fields" style="margin-top:0">' + field('protect_percent') + field('protect_proof') + '</div>', 'protect')
      + section('Search-engine impostors', 'A visitor that names itself Googlebot, Bingbot and the like but does not come from that company.',
          field('fake_bots'), 'fake');
  }

  function countriesTab() {
    var q = query.toLowerCase();
    var count = { all: data.countries.length, rule: 0, protect: 0, block: 0, watch: 0 };
    data.countries.forEach(function (c) {
      var a = rules[c.code];
      if (a !== 'allow') { count.rule++; if (count[a] !== undefined) count[a]++; }
    });
    var rows = data.countries.filter(function (c) {
      var a = rules[c.code];
      if (q && c.name.toLowerCase().indexOf(q) === -1 && c.code.toLowerCase() !== q) return false;
      if (q || filter === 'all') return true;
      return filter === 'rule' ? a !== 'allow' : a === filter;
    });
    var chips = [['rule', 'With a rule'], ['protect', 'Protect'], ['block', 'Block'], ['watch', 'Watch'], ['all', 'All countries']].map(function (c) {
      return '<button type="button" class="chip' + (filter === c[0] && !q ? ' on' : '') + '" data-fwl-filter="' + c[0] + '">'
        + esc(c[1]) + ' · ' + esc(count[c[0]]) + '</button>';
    }).join('');
    var opts = function (code) {
      return Object.keys(data.actions).map(function (k) {
        return '<option value="' + esc(k) + '"' + (rules[code] === k ? ' selected' : '') + '>' + esc(data.actions[k]) + '</option>';
      }).join('');
    };
    var body = '<div class="toolbar"><label class="search"><input type="search" placeholder="Find a country" value="' + esc(query) + '" data-fwl-q aria-label="Find a country"></label>'
      + '<div class="chips">' + chips + '</div></div>'
      + '<div class="fwl-ccs">' + (rows.length ? rows.map(function (c) {
        return '<div class="fwl-cc"><span><b>' + esc(c.name) + '</b> <span class="pill grey">' + esc(c.code) + '</span></span>'
          + '<select class="inp" data-fwl-cc="' + esc(c.code) + '" aria-label="' + esc(c.name) + '">' + opts(c.code) + '</select></div>';
      }).join('') : '<div class="fwl-empty">No country matches.</div>') + '</div>'
      + '<p class="fwl-attr"><a href="' + esc(data.data.attribution.url) + '" target="_blank" rel="noopener">' + esc(data.data.attribution.text) + '</a></p>';

    return section('Country rules', '<b>Allow</b>: normal. <b>Watch</b>: logged only. <b>Protect</b>: half the flood limits, and cart, checkout and forms need the invisible page-load proof. <b>Block</b>: refused within the ban scope. China, Russia, Singapore and Hong Kong start on Protect.',
      body, 'countries');
  }

  function botsTab() {
    var rows = data.bots.map(function (x) {
      var how = x.method === 'none' ? 'Cannot be verified: the company publishes no method. A link preview is let through on its own.'
        : (x.ranges ? x.ranges + ' published ranges' : (x.method === 'ranges' ? 'Published list not downloaded yet' : ''))
          + (x.method.indexOf('dns') !== -1 ? (x.ranges ? ' · ' : '') + 'DNS check' : '');
      return '<div class="pref"><div class="pl"><b>' + esc(x.label) + '</b><small>' + esc(how) + '</small></div>'
        + '<div class="tog' + (bots[x.family] ? ' on' : '') + '" role="switch" tabindex="0" aria-checked="' + (bots[x.family] ? 'true' : 'false') + '"'
        + ' aria-label="' + esc(x.label) + '" data-fwl-bot="' + esc(x.family) + '"></div></div>';
    }).join('');
    var b = data.data.bots;

    return section('Search engines and link previews', 'Always allowed and never counted, once the address is confirmed by the company\'s own published list or DNS.', rows, 'bots')
      + section('Published address lists', 'Google, Bing, Apple, DuckDuckGo and Meta publish where their crawlers come from. Refreshed weekly when the server runs scheduled tasks.',
          '<p class="sx-help">Last downloaded: <b>' + esc(b.at ? String(b.at).replace('T', ' ').slice(0, 16) : 'never') + '</b></p>'
          + '<div class="fwl-actions"><button type="button" class="btn ghost sm" data-fwl-data="bots"' + DIS() + '>Refresh bot lists</button></div>');
  }

  function listsTab() {
    var allow = list(data.allow, 'Nothing on the list yet.', function (a) {
      return '<div class="fwl-li"><span><b>' + esc(a.cidr) + '</b><small>' + esc(a.note || '') + '</small></span>'
        + '<button type="button" class="btn ghost sm" data-fwl-unallow="' + esc(a.cidr) + '"' + DIS() + '>Remove</button></div>';
    });
    var mine = data.my_ip_allowed
      ? '<span class="pill green"><span class="d"></span>Your address (' + esc(data.my_ip) + ') is allowed</span>'
      : '<button type="button" class="btn ghost sm" data-fwl-mine' + DIS() + '>Add my address (' + esc(data.my_ip) + ')</button>';
    var blocked;
    if (blocks === null || blocks === 'loading') blocked = '<div class="fwl-empty">Loading…</div>';
    else if (blocks === 'denied') blocked = '<div class="fwl-empty">' + esc(data.blocks) + ' addresses and ranges are blocked by hand.</div>';
    else blocked = list(blocks, 'Nothing is blocked by hand.', function (r) {
      return '<div class="fwl-li"><span><b>' + esc(r.cidr) + '</b><small>' + esc((r.reason || 'no reason given') + ' · ' + (r.hits || 0) + ' refused'
        + (r.expires_at ? ' · until ' + String(r.expires_at).slice(0, 10) : '')) + '</small></span>'
        + '<button type="button" class="btn ghost sm" data-fwl-unblock="' + esc(r.id) + '"' + DIS() + '>Unblock</button></div>';
    });

    return section('Always allow', 'Never counted, never banned, never held to a country rule: your office, a courier\'s system, a monitoring service.',
        allow + '<div class="fwl-in"><input class="inp" type="text" placeholder="203.0.113.7 or 203.0.113.0/24" maxlength="49" data-fwl-target aria-label="Address or range">'
        + '<input class="inp" type="text" placeholder="Note (optional)" maxlength="190" data-fwl-note aria-label="Note">'
        + '<button type="button" class="btn sm" data-fwl-allow' + DIS() + '>Add</button></div>'
        + '<div class="fwl-actions">' + mine + '</div>')
      + section('Block list', 'Addresses and ranges you blocked by hand, enforced before the firewall. New blocks are added from a cart in Growth & Marketing → Cart Tracking.',
          blocked, null, '<button type="button" class="btn ghost sm" data-fwl-goto="carttracking">Cart Tracking</button>');
  }

  function dataTab() {
    var c = data.data.country, b = data.data.bots;
    var fams = Object.keys(b.families).map(function (k) { return b.families[k]; }).filter(function (x) { return x.method !== 'none' && x.method !== 'dns'; });

    return section('Country database', 'Which country an address belongs to, looked up on this server with no outside call. Refreshed monthly when the server runs scheduled tasks.',
        (c.ok
          ? '<p class="sx-help"><span class="pill green"><span class="d"></span>Installed</span> <b>' + esc(c.v4) + '</b> IPv4 and <b>' + esc(c.v6) + '</b> IPv6 ranges, '
            + esc(c.countries) + ' countries, dated ' + esc(String(c.date).replace(/^(\d{4})(\d\d)(\d\d)$/, '$1-$2-$3')) + ', checksum verified.</p>'
          : '<p class="sx-help"><span class="pill amber"><span class="d"></span>Not installed</span> ' + esc(c.error) + '. Until it is, country rules are not applied.</p>')
        + '<div class="fwl-actions"><button type="button" class="btn sm" data-fwl-data="countries"' + DIS() + '>Download country database</button></div>'
        + '<p class="fwl-attr">Country data: <a href="' + esc(data.data.attribution.url) + '" target="_blank" rel="noopener">' + esc(data.data.attribution.text) + '</a>, licensed CC BY 4.0.</p>')
      + section('Bot address lists', 'The ranges each company publishes for its crawlers. Without them, Google, Bing, Apple, Yandex and Pinterest are checked by DNS instead.',
          list(fams, 'No list.', function (x) {
            return '<div class="fwl-li"><span>' + esc(x.label) + '</span><span class="fwl-n">' + (x.ranges ? esc(x.ranges) + ' ranges' : 'not downloaded') + '</span></div>';
          })
          + '<div class="fwl-actions"><button type="button" class="btn ghost sm" data-fwl-data="bots"' + DIS() + '>Refresh bot lists</button></div>')
      + section('Where counts are kept', 'Per-address counts live in a fixed 4 MB table on this server\'s disk, never in the database; the 24-hour log is written in batches.',
          '<p class="sx-help">Log store: <b>' + esc(data.store.logs) + '</b> · shop cache: <b>' + esc(data.store.shop) + '</b></p>');
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || !onScreen()) return;

    if (!data) {
      host.innerHTML = '<div class="fwl"><div role="tabpanel">' + section('Firewall', esc(busy ? 'Loading…' : (banner || 'Nothing to show yet.')),
        busy ? '' : '<div class="fwl-actions"><button type="button" class="btn ghost sm" data-fwl-reload>Retry</button></div>') + '</div></div>';
      return;
    }

    var strip = '<div class="subtabs" role="tablist" aria-label="Firewall">' + TABS.map(function (t) {
      return '<button type="button" class="subtab' + (t[0] === tab ? ' on' : '') + '" role="tab" aria-selected="' + (t[0] === tab ? 'true' : 'false')
        + '" data-fwl-tab="' + t[0] + '">' + esc(t[1]) + '</button>';
    }).join('') + '</div>';
    var body = { overview: overviewTab, live: liveTab, rules: rulesTab, countries: countriesTab, bots: botsTab, lists: listsTab, data: dataTab }[tab] || overviewTab;

    host.innerHTML = '<div class="fwl" data-fwl-screen="' + esc(tab) + '">' + strip
      + (banner ? '<div class="fwl-err">' + esc(banner) + '</div>' : '')
      + '<div role="tabpanel">' + body() + '</div></div>';
  }

  /* ─────────────────────────────────────────────────── events */
  var SAVES = {
    scope: ['scope'], flood: ['ip_10s', 'ip_60s', 'net_60s', 'prefetch_10s'], bans: ['ban_minutes', 'ban_max_minutes'],
    protect: ['protect_percent', 'protect_proof'], fake: ['fake_bots']
  };

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t.closest || !t.closest('.fwl')) return;
    var k = t.closest('[data-fwl-key]');
    if (k) {
      var key = k.getAttribute('data-fwl-key');
      values[key] = k.type === 'checkbox' ? k.checked : (k.type === 'range' ? Number(k.value) : k.value);
      var out = document.querySelector('[data-fwl-val="' + key + '"]');
      data.fields.forEach(function (fl) { if (fl.key === key && out) out.textContent = values[key] + (fl.unit || ''); });
      return;
    }
    if (t.matches('[data-fwl-q]')) {
      query = t.value; render();
      var again = document.querySelector('[data-fwl-q]');
      if (again) { again.focus(); again.setSelectionRange(query.length, query.length); }
    }
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t.closest || !t.closest('.fwl')) return;
    var cc = t.closest('[data-fwl-cc]');
    if (cc) rules[cc.getAttribute('data-fwl-cc')] = cc.value;
  });

  function toggleBot(el) {
    var fam = el.getAttribute('data-fwl-bot');
    bots[fam] = !bots[fam];
    el.classList.toggle('on', bots[fam]);
    el.setAttribute('aria-checked', bots[fam] ? 'true' : 'false');
  }

  document.addEventListener('keydown', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-fwl-bot]') : null;
    if (el && (e.key === ' ' || e.key === 'Enter')) { e.preventDefault(); toggleBot(el); }
  });

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target : null;
    if (!t || !t.closest('.fwl')) return;
    var el;
    if ((el = t.closest('[data-fwl-tab]'))) { open(el.getAttribute('data-fwl-tab')); return; }
    if (t.closest('[data-fwl-reload]')) { load(); return; }
    if (!data) return;
    if ((el = t.closest('[data-fwl-bot]'))) { toggleBot(el); return; }
    if ((el = t.closest('[data-fwl-mode]'))) {
      var mode = el.getAttribute('data-fwl-mode');
      if (mode === values.mode) return;
      if (mode === 'enforce' && !window.confirm('Switch the firewall On? It will start refusing what Live activity shows as "logged".')) return;
      act(function () { return api('', { values: { mode: mode } }); }, function (b) {
        data.fields = b.fields; b.fields.forEach(function (x) { values[x.key] = x.value; }); say('Firewall: ' + MODE[mode][1]);
      }, 'Not saved.');
      return;
    }
    if ((el = t.closest('[data-fwl-save]'))) {
      var which = el.getAttribute('data-fwl-save');
      if (which === 'countries') {
        var changed = {};
        data.countries.forEach(function (c) { if (rules[c.code] !== c.action) changed[c.code] = rules[c.code]; });
        if (!Object.keys(changed).length) { say('Nothing changed.'); return; }
        act(function () { return api('/countries', { rules: changed }); }, function (b) { data.countries = b.countries; say(b.changed + ' saved.'); }, 'Not saved.');
      } else if (which === 'bots') {
        act(function () { return api('/bots', { bots: bots }); }, function (b) { data.bots = b.bots; say('Saved.'); }, 'Not saved.');
      } else if (SAVES[which]) {
        var part = {};
        SAVES[which].forEach(function (k) { part[k] = values[k]; });
        act(function () { return api('', { values: part }); }, function (b) { data.fields = b.fields; say('Saved.'); }, 'Not saved.');
      }
      return;
    }
    if (t.closest('[data-fwl-live]')) { act(function () { return api('/live'); }, function (b) { live = b.live; }, 'The live view could not be read.'); return; }
    if ((el = t.closest('[data-fwl-unban]'))) {
      var subject = el.getAttribute('data-fwl-unban');
      act(function () { return api('/unban', { subject: subject }); }, function (b) {
        data.summary.bans = b.bans; if (live) live.bans = b.bans; say('Ban lifted.');
      }, 'Not lifted.');
      return;
    }
    if ((el = t.closest('[data-fwl-filter]'))) { filter = el.getAttribute('data-fwl-filter'); query = ''; render(); return; }
    if (t.closest('[data-fwl-allow]')) {
      var tg = document.querySelector('[data-fwl-target]'), nt = document.querySelector('[data-fwl-note]');
      var target = tg ? tg.value : '', note = nt ? nt.value : '';
      act(function () { return api('/allow', { target: target, note: note }); }, function (b) { data.allow = b.allow; say(b.message); }, 'Not added.');
      return;
    }
    if (t.closest('[data-fwl-mine]')) { act(function () { return api('/allow', { mine: true }); }, function (b) { data.allow = b.allow; data.my_ip_allowed = true; say(b.message); }, 'Not added.'); return; }
    if ((el = t.closest('[data-fwl-unallow]'))) {
      var cidr = el.getAttribute('data-fwl-unallow');
      act(function () { return api('/allow/remove', { cidr: cidr }); }, function (b) { data.allow = b.allow; data.my_ip_allowed = false; say('Removed.'); }, 'Not removed.');
      return;
    }
    if ((el = t.closest('[data-fwl-unblock]'))) {
      var id = el.getAttribute('data-fwl-unblock');
      if (!/^\d+$/.test(id)) return;
      act(function () { return call('/cart-tracking/blocks/' + id + '/unblock', {}); }, function () { blocks = null; say('Unblocked.'); }, 'Not unblocked.')
        .then(lazy);
      return;
    }
    if ((el = t.closest('[data-fwl-data]'))) {
      var what = el.getAttribute('data-fwl-data');
      say(what === 'countries' ? 'Downloading the country database — this takes up to a minute.' : 'Downloading the published bot lists…');
      act(function () { return api('/data', { what: what }); }, function () { say('Done.'); }, 'The download did not finish.').then(load);
      return;
    }
    if ((el = t.closest('[data-fwl-goto]'))) { window.go(el.getAttribute('data-fwl-goto')); }
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
