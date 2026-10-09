
(function () {
  var SCREEN = 'site-analytics';
  var POLL_MS = 15000;
  var WINDOWS = [5, 10, 15, 25];
  var RANGES = [['today', 'Today'], ['yesterday', 'Yesterday'], ['7d', '7 days'], ['30d', '30 days'], ['custom', 'Custom']];
  var CC = { AE: 'United Arab Emirates', SA: 'Saudi Arabia', QA: 'Qatar', OM: 'Oman', KW: 'Kuwait', BH: 'Bahrain', US: 'United States', GB: 'United Kingdom', IN: 'India', PK: 'Pakistan', EG: 'Egypt', JO: 'Jordan', LB: 'Lebanon', DE: 'Germany', FR: 'France', KR: 'South Korea', PH: 'Philippines', '': 'Unknown' };

  var st = { active: false, timer: 0, win: 10, range: 'today', from: '', to: '', since: 0, osince: 0, feed: [], live: null, sum: null, sumAt: 0, set: null, err: '', busy: false };

  try { var w = parseInt(localStorage.getItem('kbb_an_win') || '', 10); if (WINDOWS.indexOf(w) >= 0) st.win = w; } catch (e) { /* default 10 */ }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function cookie(n) { var m = document.cookie.match(new RegExp('(?:^|; )' + n + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  async function api(method, path, body) {
    var r = await fetch(base() + '/admin-api/site-analytics' + path, {
      method: method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      body: body ? JSON.stringify(body) : undefined
    });
    var j = null;
    try { j = await r.json(); } catch (e) { j = null; }
    if (!r.ok) { var err = new Error((j && j.message) || ('HTTP ' + r.status)); err.status = r.status; throw err; }
    return j;
  }
  function fmt(n) { return Number(n || 0).toLocaleString('en-US'); }
  function aed(fils) { return 'AED ' + Math.round((fils || 0) / 100).toLocaleString('en-US'); }
  function pct(a, b) { return b > 0 ? Math.round(a / b * 100) : 0; }
  function delta(cur, prev) {
    if (!prev) return '';
    var d = Math.round((cur - prev) / prev * 100);
    return ' <span class="' + (d >= 0 ? 'up' : 'down') + '">' + (d >= 0 ? '+' : '−') + Math.abs(d) + '%</span>';
  }
  function path(p) { try { return decodeURIComponent(p); } catch (e) { return p; } }
  function hhmm(sec) { var d = new Date(sec * 1000); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function q(sel) { var host = document.getElementById('content'); return host ? host.querySelector(sel) : null; }

  /* A ranked list with its share drawn behind it, to scale. */
  function bl(rows, money) {
    if (!rows || !rows.length) return '<li class="empty">Nothing yet in this range.</li>';
    var max = 0, tot = 0;
    rows.forEach(function (r) { max = Math.max(max, r[1]); tot += r[1]; });
    return rows.map(function (r) {
      var w = max ? (r[1] / max * 100).toFixed(1) : 0;
      return '<li><i style="width:' + w + '%"></i><span class="l" title="' + esc(r[0]) + '">' + esc(r[0]) + (r[2] ? ' <span class="faint">' + esc(r[2]) + '</span>' : '') + '</span>'
        + '<span class="v">' + (money ? aed(r[1]) : fmt(r[1])) + '</span><span class="p hide-s">' + (r[3] != null ? esc(r[3]) : (tot ? Math.round(r[1] / tot * 100) + '%' : '')) + '</span></li>';
    }).join('');
  }

  /* Visitors per minute, last 30 minutes, bars from zero. */
  function bars(series) {
    var W = 300, H = 80, n = series.length || 1, max = Math.max.apply(null, series.concat([1])), bw = W / n, o = '';
    series.forEach(function (v, i) {
      var h = v / max * (H - 2);
      o += '<rect x="' + (i * bw + 1).toFixed(1) + '" y="' + (H - h).toFixed(1) + '" width="' + Math.max(1, bw - 2).toFixed(1) + '" height="' + h.toFixed(1) + '" rx="1.5" fill="' + (i === n - 1 ? '#3ddc84' : '#22c06c') + '" opacity="' + (i === n - 1 ? 1 : 0.55) + '"><title>' + v + '</title></rect>';
    });
    return o;
  }

  /* Checkout funnel, each bar's width = its count / visitors. */
  function funnel(t) {
    var top = Math.max(1, t.visitors || 0);
    var st4 = [['Visitors', t.visitors || 0], ['Added to cart', t.carts || 0], ['Checkout', t.checkouts || 0], ['Orders', t.orders || 0]];
    var o = '';
    st4.forEach(function (r, i) {
      var w = Math.max(3, Math.min(300, r[1] / top * 300));
      o += '<rect x="0" y="' + (i * 37) + '" width="' + w.toFixed(1) + '" height="22" rx="5" fill="' + (i ? '#15a85a' : '#cfeedd') + '" opacity="' + (1 - i * 0.12) + '"/>'
        + '<text x="' + (w + 6 > 220 ? 6 : w + 6) + '" y="' + (i * 37 + 15) + '" font-size="11.5" fill="#101729">' + esc(r[0]) + ' · ' + fmt(r[1]) + (i ? ' (' + (r[1] / top * 100).toFixed(1) + '%)' : '') + '</text>';
    });
    return o;
  }

  /* Visitors per day, bars from zero. */
  function daily(series) {
    if (!series || series.length < 2) return '';
    var W = 320, H = 90, n = series.length, max = 1, bw = W / n, o = '';
    series.forEach(function (d) { max = Math.max(max, d.visitors); });
    series.forEach(function (d, i) {
      var h = d.visitors / max * (H - 4);
      o += '<rect x="' + (i * bw + 0.5).toFixed(1) + '" y="' + (H - h).toFixed(1) + '" width="' + Math.max(1, bw - 1).toFixed(1) + '" height="' + h.toFixed(1) + '" rx="1" fill="#15a85a" opacity=".75"><title>' + esc(d.day) + ': ' + d.visitors + '</title></rect>';
    });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" style="width:100%;height:90px;display:block">' + o + '</svg>'
      + '<div class="faint" style="display:flex;justify-content:space-between;margin-top:4px"><span>' + esc(series[0].day) + '</span><span>' + esc(series[n - 1].day) + '</span></div>';
  }

  function frame() {
    var host = document.getElementById('content');
    if (!host) return;
    host.innerHTML = '<div class="wrap anb" data-an>'
      + '<div class="an-top"><h2>Live</h2><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
      + '<div class="seg" data-an-ranges>' + RANGES.map(function (r) { return '<button type="button" data-an-range="' + r[0] + '">' + r[1] + '</button>'; }).join('') + '</div>'
      + '<span class="an-custom" data-an-custom><input type="date" data-an-from aria-label="From"> – <input type="date" data-an-to aria-label="To"><button type="button" class="btn ghost sm" data-an-apply>Show</button></span>'
      + '</div></div>'
      + '<div data-an-err></div>'
      + '<div class="board"><div class="night">'
      + '<div class="sec-title"><span style="display:flex;gap:8px;align-items:center"><span class="dot" data-an-dot></span>Active visitors · last <span data-an-wlabel>' + st.win + '</span> minutes</span>'
      + '<span class="seg dark" data-an-wins>' + WINDOWS.map(function (w) { return '<button type="button" data-an-win="' + w + '">' + w + '</button>'; }).join('') + '</span></div>'
      + '<div style="display:flex;align-items:end;gap:18px;flex-wrap:wrap"><div class="huge" data-an="active">–</div><div class="sub" style="padding-bottom:10px"><b data-an="views">–</b> page views<br><b data-an="carts">–</b> added to cart</div></div>'
      + '<svg class="bars" data-an="bars" viewBox="0 0 300 80" preserveAspectRatio="none" aria-label="Visitors per minute, last 30 minutes"></svg>'
      + '<div class="sub" style="font-size:11px;display:flex;justify-content:space-between"><span>−30 min</span><span>visitors per minute</span><span>now</span></div>'
      + '<div class="minis"><div class="mini"><b data-an="mobile">–</b><span>on mobile</span></div><div class="mini"><b data-an="langs">–</b><span>EN / AR</span></div><div class="mini"><b data-an="topcc">–</b><span>top country now</span></div><div class="mini"><b data-an="topsrc">–</b><span>top source now</span></div></div>'
      + '</div>'
      + '<div class="card pad"><div class="sec-title"><span>Happening now</span><span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">last 30 minutes, newest first</span></div><ul class="feed" data-an="feed"></ul></div>'
      + '</div>'
      + '<div class="now3">'
      + '<div class="card pad"><div class="sec-title">Pages being read now</div><table class="tbl"><colgroup><col><col style="width:48px"></colgroup><tbody data-an="pagesnow"></tbody></table></div>'
      + '<div class="card pad"><div class="sec-title">Sources now</div><ul class="bl" data-an="srcnow"></ul></div>'
      + '<div class="card pad"><div class="sec-title"><span>Countries now</span><span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">cities: coming later</span></div><ul class="bl" data-an="ccnow"></ul></div>'
      + '</div>'
      + '<div class="strip" data-an="strip"></div>'
      + '<div class="rest" data-an="rest"></div>'
      + '<div class="card pad an-set" data-an="settings"></div>'
      + '</div>';
    paintControls();
  }

  function paintControls() {
    var r = q('[data-an-ranges]');
    if (r) Array.prototype.forEach.call(r.querySelectorAll('button'), function (b) { b.classList.toggle('on', b.getAttribute('data-an-range') === st.range); });
    var c = q('[data-an-custom]');
    if (c) c.classList.toggle('on', st.range === 'custom');
    var w = q('[data-an-wins]');
    if (w) Array.prototype.forEach.call(w.querySelectorAll('button'), function (b) { b.classList.toggle('on', +b.getAttribute('data-an-win') === st.win); });
    var l = q('[data-an-wlabel]');
    if (l) l.textContent = st.win;
  }

  function set(sel, html) { var el = q('[data-an="' + sel + '"]'); if (el) el.innerHTML = html; }
  function text(sel, t) { var el = q('[data-an="' + sel + '"]'); if (el && el.textContent !== String(t)) el.textContent = t; }

  function paintLive() {
    var d = st.live;
    if (!d) return;
    text('active', fmt(d.active));
    text('views', fmt(d.views));
    text('carts', fmt(d.carts));
    set('bars', bars(d.bars || []));
    text('mobile', d.active ? d.mobile_pct + '%' : '–');
    var lt = (d.langs.en || 0) + (d.langs.ar || 0);
    text('langs', lt ? pct(d.langs.en, lt) + ' / ' + pct(d.langs.ar, lt) : '–');
    text('topcc', d.countries && d.countries.length ? (CC[d.countries[0].cc] || d.countries[0].cc) : '–');
    text('topsrc', d.sources && d.sources.length ? d.sources[0].label : '–');
    set('pagesnow', (d.pages || []).length ? d.pages.map(function (p) {
      return '<tr><td><span class="ttl">' + esc(p.title || path(p.path)) + '</span><span class="path">' + esc(path(p.path)) + '</span></td><td class="r" style="font-weight:700">' + fmt(p.n) + '</td></tr>';
    }).join('') : '<tr><td class="empty">Nobody on the shop in the last ' + st.win + ' minutes.</td></tr>');
    set('srcnow', bl((d.sources || []).map(function (s) { return [s.label, s.n]; })));
    set('ccnow', bl((d.countries || []).map(function (c) { return [CC[c.cc] || c.cc, c.n]; })));
    paintFeed();
  }

  /* The feed: merged by id, so a back-fill after a pause adds what was
     missed and never repeats a row. */
  function mergeFeed(items) {
    var seen = {};
    st.feed.forEach(function (f) { seen[f.id] = 1; });
    items.forEach(function (f) { if (!seen[f.id]) { st.feed.push(f); seen[f.id] = 1; } });
    var cutoff = Date.now() / 1000 - 30 * 60;
    st.feed = st.feed.filter(function (f) { return f.at >= cutoff; })
      .sort(function (a, b) { return b.at - a.at || (b.id > a.id ? 1 : -1); }).slice(0, 40);
  }

  function paintFeed() {
    set('feed', st.feed.length ? st.feed.map(function (f) {
      var lab = f.type === 'order' ? 'order' : f.type === 'cart' ? 'cart' : 'view';
      var main = f.type === 'order' ? 'Order <b>AED ' + esc(fmt(f.aed)) + '</b> · ' + esc(f.source)
        : f.type === 'cart' ? 'Added to cart' : esc(f.title || path(f.path));
      var meta = f.type === 'order' ? '' : [CC[f.cc] || f.cc, f.dev, f.source].filter(Boolean).map(esc).join(' · ');
      return '<li><time>' + hhmm(f.at) + '</time><span style="min-width:0"><span class="ttl" style="font-weight:500">' + main + '</span><span class="faint">' + meta + '</span></span><span class="ev ' + lab + '">' + lab + '</span></li>';
    }).join('') : '<li class="empty">Quiet for the last 30 minutes.</li>');
  }

  function paintSummary() {
    var s = st.sum;
    if (!s) return;
    var t = s.totals, p = s.previous;
    var bounce = t.sessions ? Math.round(t.bounces / t.sessions * 100) : 0;
    var pbounce = p.sessions ? Math.round(p.bounces / p.sessions * 100) : 0;
    var cards = [
      [fmt(t.visitors), 'Visitors' + delta(t.visitors, p.visitors)],
      [fmt(t.views), 'Page views' + delta(t.views, p.views)],
      [fmt(t.sessions), 'Sessions · ' + (t.sessions ? (t.views / t.sessions).toFixed(1) : '0') + ' pages each'],
      [bounce + '%', 'Bounce rate' + (p.sessions ? ' <span class="' + (bounce <= pbounce ? 'up' : 'down') + '">' + (bounce - pbounce >= 0 ? '+' : '−') + Math.abs(bounce - pbounce) + ' pts</span>' : '')],
      [fmt(t.orders), 'Orders · ' + (t.sessions ? (t.orders / t.sessions * 100).toFixed(1) : '0') + '% of sessions'],
      [aed(t.revenue_fils), 'Revenue']
    ];
    set('strip', cards.map(function (c) { return '<div class="card"><b>' + c[0] + '</b><span>' + c[1] + '</span></div>'; }).join(''));

    var d = s.dims;
    var rows = function (list, f, lab) { return (list || []).map(function (r) { return [lab ? lab(r) : (r.label || r.val), r[f]]; }); };
    var card = function (title, body, note) { return '<div class="card pad"><div class="sec-title"><span>' + title + '</span>' + (note ? '<span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">' + note + '</span>' : '') + '</div>' + body + '</div>'; };
    var list = function (r, money) { return '<ul class="bl">' + bl(r, money) + '</ul>'; };
    var ch = (s.orders_by_channel || []).map(function (c) { return [c.label, c.revenue_fils, c.orders + ' order' + (c.orders === 1 ? '' : 's'), c.rate != null ? c.rate + '%' : '–']; });
    var cp = (s.orders_by_campaign || []).map(function (c) { return [c.campaign, c.revenue_fils, c.channel + ' · ' + c.orders]; });
    var g = s.google || { connected: false, rows: [] };
    var gsc = g.connected
      ? list(g.rows.map(function (r) { return [r.term, r.clicks, r.impressions + ' impr.']; }))
      : '<div class="gsc"><b>Connect Search Console (coming next)</b><div style="margin-top:4px">The words people typed into Google before landing here. The shop already has the connector (Store → SEO keywords); this card fills once Search Console is connected and synced.</div></div>';

    set('rest', ''
      + (s.series && s.series.length > 1 ? card('Visitors per day', daily(s.series)) : '')
      + card('Top pages', list(rows(d.page, 'views', function (r) { return r.label || path(r.val); })), 'views')
      + card('Sources', list(rows(d.channel, 'sessions')), 'sessions')
      + card('Orders &amp; revenue by source', list(ch, true), 'AED · conv.')
      + card('Orders by campaign', list(cp, true), 'AED')
      + card('Campaigns (UTM)', list(rows(d.campaign, 'sessions')), 'sessions')
      + card('Checkout funnel', '<svg viewBox="0 0 320 150" style="width:100%;height:auto;display:block">' + funnel(t) + '</svg>')
      + card('Entry pages', list(rows(d.entry, 'sessions', function (r) { return path(r.val); })), 'sessions')
      + card('Searched on the shop', list((s.search || []).map(function (r) { return [r.term, r.hits, r.results === 0 ? 'no results' : '']; })), 'searches')
      + card('Google search keywords', gsc, g.connected ? 'clicks · last 90 days' : '')
      + card('Referrers · UTM source', list(rows(d.referrer, 'sessions')) + '<div style="height:10px"></div>' + list(rows(d.source, 'sessions')))
      + card('Devices · Browsers', list(rows(d.device, 'sessions')) + '<div style="height:10px"></div>' + list(rows(d.browser, 'sessions')))
      + card('Language · Countries', list(rows(d.lang, 'sessions')) + '<div style="height:10px"></div>' + list(rows(d.country, 'sessions', function (r) { return CC[r.val] || r.val; })))
    );
  }

  function paintSettings() {
    var s = st.set;
    var el = q('[data-an="settings"]');
    if (!el || !s) return;
    el.innerHTML = '<div class="sec-title">Settings</div>'
      + '<label><input type="checkbox" data-an-track' + (s.tracking ? ' checked' : '') + '> Record visits (one tiny request per opened page; no cookies, no IP stored)</label>'
      + '<div class="faint" style="margin:0 0 6px">Leave out these addresses (yours, your staff’s) — one per line, an address or a range like 10.0.0.0/24. Your address now: <code>' + esc(s.your_ip) + '</code> <button type="button" class="btn ghost sm" data-an-mine>Add mine</button></div>'
      + '<textarea data-an-ex spellcheck="false">' + esc(s.exclude) + '</textarea>'
      + '<div style="margin-top:10px;display:flex;align-items:center"><button type="button" class="btn sm" data-an-save>Save</button><span class="an-msg" data-an-msg role="status" aria-live="polite"></span></div>'
      + '<div class="faint" style="margin-top:8px">Raw visits are kept ' + esc(s.keep_hours) + ' hours, then only the daily totals stay. Signed-in admins and search-engine bots are never counted.</div>';
  }

  function showErr(e) {
    var el = q('[data-an-err]');
    if (el) el.innerHTML = e ? '<div class="an-err">' + esc(e) + '</div>' : '';
  }

  async function loadSummary() {
    var qs = '?range=' + encodeURIComponent(st.range) + (st.range === 'custom' ? '&from=' + encodeURIComponent(st.from) + '&to=' + encodeURIComponent(st.to) : '');
    try {
      var j = await api('GET', qs);
      if (!st.active) return;
      st.sum = j; st.sumAt = Date.now(); showErr('');
      paintSummary();
    } catch (e) {
      if (st.active) showErr(e.status === 403 ? 'Your role does not include Analytics. Ask the owner to add it in Users & Roles.' : 'Analytics could not be loaded (' + e.message + ').');
    }
  }

  async function loadLive() {
    if (!st.active || st.busy) return;
    st.busy = true;
    try {
      var j = await api('GET', '/live?w=' + st.win + '&since=' + st.since + '&osince=' + st.osince);
      if (!st.active || !q('[data-an]')) return;
      st.live = j;
      st.since = Math.max(st.since, j.since || 0);
      st.osince = Math.max(st.osince, j.order_since || 0);
      mergeFeed((j.feed || []).concat(j.orders || []));
      paintLive();
    } catch (e) { /* the next tick tries again */ }
    finally { st.busy = false; }
  }

  /* LIVE ONLY WHILE WATCHED: one timeout at a time, re-armed after each
     answer, and only while this screen is open and the tab is visible. */
  function schedule() {
    stop();
    // Another screen drew over this one (a wrapper further out handled the
    // click without passing it down): this screen has been left.
    if (st.active && !q('[data-an]')) teardown();
    if (!st.active || document.visibilityState !== 'visible') return;
    st.timer = setTimeout(async function () { st.timer = 0; await loadLive(); schedule(); }, POLL_MS);
  }
  function stop() { if (st.timer) clearTimeout(st.timer); st.timer = 0; var d = q('[data-an-dot]'); if (d) d.classList.toggle('off', !st.active || document.visibilityState !== 'visible'); }

  function onVisibility() {
    if (!st.active) return;
    if (document.visibilityState !== 'visible') { stop(); return; }
    var d = q('[data-an-dot]'); if (d) d.classList.remove('off');
    // Back: the gap since the last answer in one request, then on as before.
    loadLive().then(schedule);
    if (Date.now() - st.sumAt > 120000) loadSummary();
  }
  document.addEventListener('visibilitychange', onVisibility);

  function teardown() {
    st.active = false;
    stop();
  }

  async function open() {
    st.active = true; st.since = 0; st.osince = 0; st.feed = []; st.live = null; st.sum = null;
    frame();
    loadSummary();
    api('GET', '/settings').then(function (j) { st.set = j; paintSettings(); }).catch(function () { /* no settings card */ });
    await loadLive();
    schedule();
  }

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-an-range],[data-an-win],[data-an-apply],[data-an-save],[data-an-mine]') : null;
    if (!t || !t.closest('[data-an]')) return;
    if (t.hasAttribute('data-an-range')) {
      st.range = t.getAttribute('data-an-range');
      paintControls();
      if (st.range !== 'custom') loadSummary();
      return;
    }
    if (t.hasAttribute('data-an-apply')) {
      var f = q('[data-an-from]'), to = q('[data-an-to]');
      st.from = f ? f.value : ''; st.to = to ? to.value : '';
      if (st.from && st.to) loadSummary();
      return;
    }
    if (t.hasAttribute('data-an-win')) {
      st.win = +t.getAttribute('data-an-win');
      try { localStorage.setItem('kbb_an_win', String(st.win)); } catch (e2) { /* this visit only */ }
      paintControls();
      loadLive().then(schedule);
      return;
    }
    if (t.hasAttribute('data-an-mine') && st.set) {
      var ex = q('[data-an-ex]');
      if (ex && ex.value.split(/\s+/).indexOf(st.set.your_ip) < 0) ex.value = (ex.value.trim() ? ex.value.trim() + '\n' : '') + st.set.your_ip;
      return;
    }
    if (t.hasAttribute('data-an-save')) {
      var msg = q('[data-an-msg]'), tr = q('[data-an-track]'), exa = q('[data-an-ex]');
      api('POST', '/settings', { tracking: !!(tr && tr.checked), exclude: exa ? exa.value : '' })
        .then(function (j) { st.set = j; paintSettings(); var m = q('[data-an-msg]'); if (m) m.textContent = 'Saved.'; })
        .catch(function (e3) { if (msg) { msg.textContent = e3.status === 403 ? 'Your role cannot change these.' : e3.message; msg.classList.add('err'); } });
    }
  });

  /* AdminNav already draws this row at build time (Overview, under
     Dashboard), so this returns it; kept so the sidebar guard can pair the
     row with the partial that draws its screen. */
  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Analytics',
      icon: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
      after: ['dash']
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) {
      // Leaving the screen: the live display sleeps at once.
      if (st.active) teardown();
      return previousGo.apply(this, arguments);
    }

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Overview';
    if (title) title.textContent = 'Analytics';
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    open();
    return undefined;
  };
})();
