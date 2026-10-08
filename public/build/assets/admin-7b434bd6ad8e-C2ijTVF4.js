
(function () {
  'use strict';

  var SCREEN = 'firewall';
  var data = null, values = {}, rules = {}, bots = {}, banner = null, busy = false, seq = 0;
  var query = '', showAll = false;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api/security/firewall' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('firewall ' + path + ' -> ' + r.status);
      err.status = r.status; err.body = payload;
      throw err;
    }
    return payload;
  }

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
    render();
    load();
    return undefined;
  };

  async function load() {
    var mine = ++seq;
    busy = true; banner = null; render();
    try {
      var body = await api('');
      if (mine !== seq) return;
      take(body);
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The firewall could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  function take(body) {
    data = body;
    values = {}; rules = {}; bots = {};
    body.fields.forEach(function (f) { values[f.key] = f.value; });
    body.countries.forEach(function (c) { rules[c.code] = c.action; });
    body.bots.forEach(function (b) { bots[b.family] = b.on; });
  }

  async function act(path, payload, done, fallback) {
    if (busy) return;
    busy = true; banner = null; render();
    try {
      var body = await api(path, payload);
      done(body);
    } catch (e) {
      banner = explain(e, fallback);
    } finally {
      busy = false; render();
    }
  }

  /* ─────────────────────────────────────────────────────────── drawing */
  var MODE_LINE = {
    off: ['Off', 'Nothing is counted and nothing is refused.'],
    monitor: ['Monitor — counting and logging, refusing nothing', 'Watch the live view below for a few days. When what it would have refused is only bots, switch it On.'],
    enforce: ['On — refusing what the rules below describe', 'Real visitors see nothing: no captcha, no check page, no delay. Search engines and link previews are let through.']
  };

  function f(key) {
    var out = null;
    data.fields.forEach(function (x) { if (x.key === key) out = x; });
    return out;
  }

  function fieldHTML(fl) {
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
        + '<select id="' + id + '" data-fwl-key="' + esc(fl.key) + '">' + opts + '</select>' + help + '</div>';
    }
    return '<div class="sx-f"><div class="sx-fh"><label for="' + id + '">' + esc(fl.label) + '</label>'
      + '<span class="sx-val" data-fwl-val="' + esc(fl.key) + '">' + esc(values[fl.key] + (fl.unit || '')) + '</span></div>'
      + '<input type="range" id="' + id + '" data-fwl-key="' + esc(fl.key) + '" min="' + esc(fl.min) + '" max="' + esc(fl.max)
      + '" step="' + esc(fl.step) + '" value="' + esc(values[fl.key]) + '">' + help + '</div>';
  }

  function statusHTML() {
    var mode = values.mode || 'off', line = MODE_LINE[mode] || MODE_LINE.off;
    var tone = mode === 'enforce' ? 'is-quiet' : (mode === 'monitor' ? 'is-watch' : '');
    var buttons = [['off', 'Off'], ['monitor', 'Monitor'], ['enforce', 'On']].map(function (m) {
      return '<button type="button" class="fwl-mode is-' + m[0] + '" data-fwl-mode="' + m[0] + '" aria-pressed="'
        + (mode === m[0] ? 'true' : 'false') + '"' + (busy ? ' disabled' : '') + '>' + m[1] + '</button>';
    }).join('');
    var cdb = data.data.country;
    var geo = cdb.ok ? '' : '<div class="sx-off">The country database is not on this server yet, so country rules are not applied. Press "Download country database" further down (once).</div>';

    return '<div class="sx-card sx-verdict ' + tone + '">'
      + '<div class="sx-vline">' + esc(line[0]) + '</div>'
      + '<p class="sx-sub">' + esc(line[1]) + '</p>'
      + '<div class="fwl-modes">' + buttons + '</div>'
      + '<p class="sx-help" style="margin-top:10px">Emergency off from the server: <code>php artisan kbb:firewall off</code></p>' + geo
      + '</div>';
  }

  var SHORT = { flood: 'flood ban', banned: 'while banned', no_proof: 'posted without a page', fake_bot: 'fake bot', country: 'country blocked', watch: 'watched' };

  function listHTML(rows, empty, line) {
    if (!rows || !rows.length) return '<div class="sx-empty">' + esc(empty) + '</div>';
    return '<div class="fwl-list">' + rows.map(line).join('') + '</div>';
  }

  function liveHTML() {
    var lv = data.live;
    var reasons = listHTML(lv.reasons, 'Nothing flagged in the last 24 hours.', function (r) {
      return '<div class="fwl-li"><span>' + esc(r.label) + '</span><span class="fwl-n">'
        + (r.refused ? esc(r.refused) + ' refused' : '') + (r.refused && r.logged ? ' · ' : '')
        + (r.logged ? esc(r.logged) + ' logged' : '') + '</span></div>';
    });
    var countries = listHTML(lv.countries, 'No country yet.', function (c) {
      return '<div class="fwl-li"><span>' + esc(c.country) + '</span><span class="fwl-n">' + esc(c.hits) + '</span></div>';
    });
    var ips = listHTML(lv.ips, 'No address yet.', function (i) {
      return '<div class="fwl-li"><span><b>' + esc(i.ip) + '</b>' + (i.country ? ' <span class="fwl-tag">' + esc(i.country) + '</span>' : '')
        + '<small>' + esc(i.reasons.map(function (r) { return SHORT[r] || r; }).join(', ')) + '</small></span><span class="fwl-n">' + esc(i.hits) + '</span></div>';
    });
    var nets = listHTML(lv.nets, 'No range yet.', function (n) {
      return '<div class="fwl-li"><span>' + esc(n.net) + '</span><span class="fwl-n">' + esc(n.hits) + '</span></div>';
    });
    var bans = listHTML(lv.bans, 'No address is banned right now.', function (b) {
      return '<div class="fwl-li"><span><b>' + esc(b.who) + '</b>' + (b.country ? ' <span class="fwl-tag">' + esc(b.country) + '</span>' : '')
        + (b.monitor ? ' <span class="fwl-tag is-warn">monitor: not enforced</span>' : '')
        + '<small>' + esc(b.why) + ' · strike ' + esc(b.strikes) + ' · until ' + esc(String(b.until).replace('T', ' ').slice(0, 16)) + '</small></span>'
        + '<button type="button" class="sx-btn" data-fwl-unban="' + esc(b.subject) + '"' + (busy ? ' disabled' : '') + '>Unban</button></div>';
    });

    return '<div class="sx-card"><div class="sx-head"><div class="sx-title">Live view — last 24 hours</div>'
      + '<button type="button" class="sx-btn" data-fwl-live' + (busy ? ' disabled' : '') + '>Refresh</button></div>'
      + '<div class="sx-counts"><div class="sx-count"><b>' + esc(lv.refused) + '</b><span>requests refused</span></div>'
      + '<div class="sx-count"><b>' + esc(lv.logged) + '</b><span>logged only (monitor / watch)</span></div>'
      + '<div class="sx-count"><b>' + esc(lv.bans.length) + '</b><span>active bans</span></div></div>'
      + '<div class="fwl-grid" style="margin-top:12px">'
      + '<div><div class="sx-sum">By reason</div>' + reasons + '</div>'
      + '<div><div class="sx-sum">Top countries</div>' + countries + '</div>'
      + '<div><div class="sx-sum">Top addresses</div>' + ips + '</div>'
      + '<div><div class="sx-sum">Top ranges</div>' + nets + '</div>'
      + '</div>'
      + '<div style="margin-top:14px"><div class="sx-sum">Active bans</div>' + bans + '</div>'
      + '<p class="sx-help" style="margin-top:8px">Bans end by themselves. Counts are gathered in memory and written every few minutes, so the newest minute can lag.</p>'
      + '</div>';
  }

  function limitsHTML() {
    var keys = ['scope', 'ip_10s', 'ip_60s', 'net_60s', 'prefetch_10s', 'ban_minutes', 'ban_max_minutes', 'protect_percent', 'protect_proof', 'fake_bots'];
    return '<div class="sx-card"><div class="sx-title">Flood limits and bans</div>'
      + '<p class="sx-sub">An address that asks faster than any person can is banned for a few minutes, invisibly to everyone else. Signed-in admins, always-allowed addresses and verified search engines are never counted.</p>'
      + '<div class="sx-fields">' + keys.map(function (k) { var fl = f(k); return fl ? fieldHTML(fl) : ''; }).join('') + '</div>'
      + '<div class="sx-actions"><button type="button" class="sx-btn is-primary" data-fwl-save' + (busy ? ' disabled' : '') + '>Save</button>'
      + '<button type="button" class="sx-btn" data-fwl-reload' + (busy ? ' disabled' : '') + '>Reload</button></div></div>';
  }

  function countriesHTML() {
    var q = query.toLowerCase();
    var rows = data.countries.filter(function (c) {
      if (q) return c.name.toLowerCase().indexOf(q) !== -1 || c.code.toLowerCase() === q;
      return showAll || rules[c.code] !== 'allow' || c.preset !== 'allow';
    });
    var opts = function (code) {
      return Object.keys(data.actions).map(function (k) {
        return '<option value="' + esc(k) + '"' + (rules[code] === k ? ' selected' : '') + '>' + esc(data.actions[k]) + '</option>';
      }).join('');
    };
    var list = rows.length ? rows.map(function (c) {
      return '<div class="fwl-cc is-' + esc(rules[c.code]) + '"><span><b>' + esc(c.name) + '</b> <span class="fwl-tag">' + esc(c.code) + '</span></span>'
        + '<select data-fwl-cc="' + esc(c.code) + '" aria-label="' + esc(c.name) + '">' + opts(c.code) + '</select></div>';
    }).join('') : '<div class="sx-empty">No country matches.</div>';

    return '<div class="sx-card"><div class="sx-title">Countries</div>'
      + '<p class="sx-sub"><b>Allow</b>: normal. <b>Watch</b>: logged only. <b>Protect</b>: stricter flood limits, and adding to cart, checkout and forms need the invisible page-load proof — nothing is shown to a shopper. <b>Block</b>: refused, within the scope above. China, Russia, Singapore and Hong Kong start on Protect.</p>'
      + '<div class="fwl-in"><input type="search" placeholder="Find a country" value="' + esc(query) + '" data-fwl-q>'
      + '<button type="button" class="sx-btn" data-fwl-all>' + (showAll ? 'Only countries with a rule' : 'Show every country') + '</button></div>'
      + '<div class="fwl-ccs">' + list + '</div>'
      + '<div class="sx-actions"><button type="button" class="sx-btn is-primary" data-fwl-ccsave' + (busy ? ' disabled' : '') + '>Save countries</button></div>'
      + '<p class="fwl-attr"><a href="' + esc(data.data.attribution.url) + '" target="_blank" rel="noopener">' + esc(data.data.attribution.text) + '</a></p></div>';
  }

  function botsHTML() {
    var b = data.data.bots;
    var rows = data.bots.map(function (x) {
      var how = x.method === 'none' ? '<span class="fwl-tag">cannot be verified</span>'
        : (x.ranges ? '<span class="fwl-tag is-good">' + esc(x.ranges) + ' ranges</span>' : '')
          + (x.method.indexOf('dns') !== -1 ? '<span class="fwl-tag is-good">DNS check</span>' : '')
          + (!x.ranges && x.method === 'ranges' ? '<span class="fwl-tag is-warn">list not downloaded</span>' : '');
      return '<div class="fwl-li"><span><label class="sx-check"><input type="checkbox" data-fwl-bot="' + esc(x.family) + '"'
        + (bots[x.family] ? ' checked' : '') + '><span>' + esc(x.label) + ' ' + how + '</span></label></span><span></span></div>';
    }).join('');

    return '<div class="sx-card"><div class="sx-title">Search engines and link previews</div>'
      + '<p class="sx-sub">Always allowed and never counted — once their address is confirmed by the company\'s own published list or DNS. One that only claims the name is treated as a bot and refused.</p>'
      + '<div class="fwl-list">' + rows + '</div>'
      + '<div class="sx-actions"><button type="button" class="sx-btn is-primary" data-fwl-botsave' + (busy ? ' disabled' : '') + '>Save</button>'
      + '<button type="button" class="sx-btn" data-fwl-data="bots"' + (busy ? ' disabled' : '') + '>Refresh bot lists</button></div>'
      + '<p class="sx-help" style="margin-top:8px">Lists last downloaded: ' + esc(b.at ? String(b.at).replace('T', ' ').slice(0, 16) : 'never') + '.</p></div>';
  }

  function allowHTML() {
    var rows = listHTML(data.allow, 'Nothing on the list yet.', function (a) {
      return '<div class="fwl-li"><span><b>' + esc(a.cidr) + '</b><small>' + esc(a.note || '') + '</small></span>'
        + '<button type="button" class="sx-btn" data-fwl-unallow="' + esc(a.cidr) + '"' + (busy ? ' disabled' : '') + '>Remove</button></div>';
    });
    return '<div class="sx-card"><div class="sx-title">Always allow</div>'
      + '<p class="sx-sub">Never counted, never banned, never held to a country rule. Your own office, a courier\'s system, a monitoring service.</p>'
      + rows
      + '<div class="fwl-in"><input type="text" placeholder="203.0.113.7 or 203.0.113.0/24" maxlength="49" data-fwl-target>'
      + '<input type="text" placeholder="Note (optional)" maxlength="190" data-fwl-note>'
      + '<button type="button" class="sx-btn is-primary" data-fwl-allow' + (busy ? ' disabled' : '') + '>Add</button></div>'
      + '<div class="sx-actions"><button type="button" class="sx-btn" data-fwl-mine' + (busy || data.my_ip_allowed ? ' disabled' : '') + '>'
      + (data.my_ip_allowed ? 'Your address (' + esc(data.my_ip) + ') is allowed' : 'Add my current address (' + esc(data.my_ip) + ')') + '</button></div></div>';
  }

  function dataHTML() {
    var c = data.data.country;
    return '<div class="sx-card"><div class="sx-title">Block list and data</div>'
      + '<p class="sx-sub"><b>' + esc(data.blocks) + '</b> address' + (data.blocks === 1 ? '' : 'es and ranges') + ' blocked by hand. That list lives in Growth &amp; Marketing → Cart Tracking → Blocked, and is enforced before the firewall.</p>'
      + '<div class="sx-actions"><button type="button" class="sx-btn" data-fwl-goto="carttracking">Open the block list</button></div>'
      + '<p class="sx-sub" style="margin-top:14px">Country database: '
      + (c.ok ? '<b>' + esc(c.v4) + '</b> IPv4 and <b>' + esc(c.v6) + '</b> IPv6 ranges, ' + esc(c.countries) + ' countries, dated ' + esc(String(c.date).replace(/^(\d{4})(\d\d)(\d\d)$/, '$1-$2-$3')) + ', checksum verified.'
          : '<b>not installed</b> (' + esc(c.error) + ').')
      + ' It refreshes itself monthly when the server runs scheduled tasks.</p>'
      + '<div class="sx-actions"><button type="button" class="sx-btn is-primary" data-fwl-data="countries"' + (busy ? ' disabled' : '') + '>Download country database</button></div>'
      + '<p class="fwl-attr">Country data: <a href="' + esc(data.data.attribution.url) + '" target="_blank" rel="noopener">' + esc(data.data.attribution.text) + '</a>, licensed CC BY 4.0.</p></div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Firewall') return;

    if (!data) {
      host.innerHTML = '<div class="sx-wrap"><div class="sx-card"><div class="sx-title">Firewall</div><p class="sx-sub">'
        + esc(busy ? 'Loading…' : (banner || 'Nothing to show yet.')) + '</p>'
        + (busy ? '' : '<div class="sx-actions"><button class="sx-btn" data-fwl-reload>Retry</button></div>') + '</div></div>';
      return;
    }

    host.innerHTML = '<div class="sx-wrap">'
      + (banner ? '<div class="sx-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">' + esc(banner) + '</div>' : '')
      + statusHTML() + liveHTML() + limitsHTML() + countriesHTML()
      + '<div class="fwl-grid">' + botsHTML() + allowHTML() + '</div>'
      + dataHTML() + '</div>';
  }

  /* ─────────────────────────────────────────────────────────── events */
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t.closest) return;
    var k = t.closest('[data-fwl-key]');
    if (k) {
      var key = k.getAttribute('data-fwl-key');
      values[key] = k.type === 'checkbox' ? k.checked : (k.type === 'range' ? Number(k.value) : k.value);
      var out = document.querySelector('[data-fwl-val="' + key + '"]');
      var fl = f(key);
      if (out && fl) out.textContent = values[key] + (fl.unit || '');
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
    if (!t.closest) return;
    var cc = t.closest('[data-fwl-cc]');
    if (cc) { rules[cc.getAttribute('data-fwl-cc')] = cc.value; cc.parentNode.className = 'fwl-cc is-' + cc.value; return; }
    var bt = t.closest('[data-fwl-bot]');
    if (bt) bots[bt.getAttribute('data-fwl-bot')] = bt.checked;
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : null;
    if (!t || !data && !t.closest('[data-fwl-reload]')) return;
    var el;
    if ((el = t.closest('[data-fwl-mode]'))) {
      var mode = el.getAttribute('data-fwl-mode');
      if (mode === 'enforce' && values.mode !== 'enforce'
          && !window.confirm('Switch the firewall On? It will start refusing what the live view shows as "would refuse".')) return;
      act('', { values: { mode: mode } }, function (b) { b.fields.forEach(function (x) { values[x.key] = x.value; }); data.fields = b.fields; say('Firewall: ' + MODE_LINE[mode][0]); }, 'Not saved.');
      return;
    }
    if (t.closest('[data-fwl-save]')) { act('', { values: values }, function (b) { data.fields = b.fields; say('Saved.'); }, 'Not saved.'); return; }
    if (t.closest('[data-fwl-reload]')) { load(); return; }
    if (t.closest('[data-fwl-live]')) { act('/live', undefined, function (b) { data.live = b.live; }, 'The live view could not be read.'); return; }
    if ((el = t.closest('[data-fwl-unban]'))) { act('/unban', { subject: el.getAttribute('data-fwl-unban') }, function (b) { data.live.bans = b.bans; say('Ban lifted.'); }, 'Not lifted.'); return; }
    if (t.closest('[data-fwl-all]')) { showAll = !showAll; render(); return; }
    if (t.closest('[data-fwl-ccsave]')) {
      var changed = {};
      data.countries.forEach(function (c) { if (rules[c.code] !== c.action) changed[c.code] = rules[c.code]; });
      if (!Object.keys(changed).length) { say('Nothing changed.'); return; }
      act('/countries', { rules: changed }, function (b) { data.countries = b.countries; say(b.changed + ' saved.'); }, 'Not saved.');
      return;
    }
    if (t.closest('[data-fwl-botsave]')) { act('/bots', { bots: bots }, function (b) { data.bots = b.bots; say('Saved.'); }, 'Not saved.'); return; }
    if (t.closest('[data-fwl-allow]')) {
      var tg = document.querySelector('[data-fwl-target]'), nt = document.querySelector('[data-fwl-note]');
      act('/allow', { target: tg ? tg.value : '', note: nt ? nt.value : '' }, function (b) { data.allow = b.allow; say(b.message); }, 'Not added.');
      return;
    }
    if (t.closest('[data-fwl-mine]')) { act('/allow', { mine: true }, function (b) { data.allow = b.allow; data.my_ip_allowed = true; say(b.message); }, 'Not added.'); return; }
    if ((el = t.closest('[data-fwl-unallow]'))) { act('/allow/remove', { cidr: el.getAttribute('data-fwl-unallow') }, function (b) { data.allow = b.allow; data.my_ip_allowed = false; say('Removed.'); }, 'Not removed.'); return; }
    if ((el = t.closest('[data-fwl-data]'))) {
      var what = el.getAttribute('data-fwl-data');
      say(what === 'countries' ? 'Downloading the country database — this takes up to a minute.' : 'Downloading the published bot lists…');
      act('/data', { what: what }, function () { say('Done.'); load(); }, 'The download did not finish.');
      return;
    }
    if ((el = t.closest('[data-fwl-goto]'))) { window.go(el.getAttribute('data-fwl-goto')); }
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
