
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
    if (!r.ok) {
      var err = new Error((j && j.message) || ('HTTP ' + r.status));
      err.status = r.status;
      // A 404 whose body says nothing at all is the compiled route table.
      err.blank = !(j && (j.message || j.error));
      throw err;
    }
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

  /* Visitors per minute, last 30 minutes, read like Google Analytics'
     realtime chart: the server's round ticks (Report::ticks, 0 at the base)
     on the right with faint gridlines, "-30 min … -1 min" underneath, every
     bar drawn to scale against the top tick. Exact value on hover or tap,
     from the numbers already here -- no request, nothing measured. */
  var CH = { W: 320, H: 104, PW: 292, TOP: 6, BASE: 84 };
  function bars(series, ticks) {
    var n = series.length || 1, top = ticks && ticks.length ? ticks[ticks.length - 1] : Math.max.apply(null, series.concat([1]));
    var bw = CH.PW / n, span = CH.BASE - CH.TOP, o = '';
    (ticks || []).forEach(function (t) {
      var y = (CH.BASE - t / top * span).toFixed(1);
      o += '<line x1="0" x2="' + CH.PW + '" y1="' + y + '" y2="' + y + '" stroke="rgba(255,255,255,' + (t === 0 ? '.22' : '.08') + ')" stroke-width="1"/>'
        + '<text class="an-ax" x="' + (CH.PW + 6) + '" y="' + (+y + 3).toFixed(1) + '">' + t + '</text>';
    });
    series.forEach(function (v, i) {
      var h = v / top * span;
      o += '<rect data-i="' + i + '" x="' + (i * bw + 1).toFixed(1) + '" y="' + (CH.BASE - h).toFixed(1) + '" width="' + Math.max(1, bw - 2).toFixed(1) + '" height="' + Math.max(0, h).toFixed(1) + '" rx="1.5" fill="' + (i === n - 1 ? '#3ddc84' : '#22c06c') + '" opacity="' + (i === n - 1 ? 1 : 0.6) + '"/>'
        // An invisible full-height target, so a bar of 0 or 1 can still be tapped.
        + '<rect data-i="' + i + '" x="' + (i * bw).toFixed(1) + '" y="' + CH.TOP + '" width="' + bw.toFixed(1) + '" height="' + span + '" fill="transparent"/>';
    });
    [30, 25, 20, 15, 10, 5, 1].forEach(function (k, j, all) {
      var i = n - k, x = (i * bw + bw / 2).toFixed(1);
      o += '<text class="an-ax" x="' + (j === 0 ? 0 : (j === all.length - 1 ? CH.PW : x)) + '" y="' + (CH.H - 4) + '" text-anchor="' + (j === 0 ? 'start' : (j === all.length - 1 ? 'end' : 'middle')) + '">−' + k + (j === 0 || j === all.length - 1 ? ' min' : '') + '</text>';
    });
    return o;
  }
  function barTip(i) {
    var tip = q('[data-an="tip"]'), d = st.live;
    if (!tip || !d || !d.bars || d.bars[i] == null) return;
    var v = d.bars[i], at = d.now - (d.bars.length - 1 - i) * 60;
    tip.textContent = hhmm(at) + ' · ' + v + ' visitor' + (v === 1 ? '' : 's');
    tip.style.left = (((i + 0.5) * CH.PW / d.bars.length) / CH.W * 100).toFixed(2) + '%';
    tip.hidden = false;
  }
  document.addEventListener('pointerover', function (e) {
    var r = e.target && e.target.closest ? e.target.closest('[data-an="bars"] rect[data-i]') : null;
    if (r) barTip(+r.getAttribute('data-i'));
  });
  document.addEventListener('pointerout', function (e) {
    if (e.pointerType === 'mouse' && e.target && e.target.closest && e.target.closest('[data-an="bars"]')) { var t = q('[data-an="tip"]'); if (t) t.hidden = true; }
  });

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
      + '<span class="faint" data-an="updated"></span>'
      + '<div class="seg" data-an-ranges>' + RANGES.map(function (r) { return '<button type="button" data-an-range="' + r[0] + '">' + r[1] + '</button>'; }).join('') + '</div>'
      + '<span class="an-custom" data-an-custom><input type="date" data-an-from aria-label="From"> – <input type="date" data-an-to aria-label="To"><button type="button" class="btn ghost sm" data-an-apply>Show</button></span>'
      + '</div></div>'
      + '<div data-an-err></div><div data-an="cron"></div>'
      + '<div class="an-tools"><span data-an="hiddenchip"></span><button type="button" class="an-link" data-an-reset>Reset layout</button><span class="an-sr" aria-live="polite" data-an-say></span></div>'
      + '<div class="an-grid" data-an-grid>'
      + blk('live', 2, '<div class="night">'
      + '<div class="sec-title" style="flex-wrap:wrap"><span style="display:flex;gap:8px;align-items:center"><span class="dot" data-an-dot></span><span>Online now</span></span>'
      + '<span style="display:inline-flex;align-items:center"><span class="an-wl">Figures below: last</span><span class="seg dark" data-an-wins>' + WINDOWS.map(function (w) { return '<button type="button" data-an-win="' + w + '">' + w + '</button>'; }).join('') + '</span><span class="an-wl" style="margin:0 0 0 6px">min</span></span></div>'
      + '<div style="display:flex;align-items:end;gap:18px;flex-wrap:wrap"><div class="huge" data-an="online" title="Visitors on the shop this moment">–</div><div class="sub" style="padding-bottom:10px">on <b data-an="onpages">–</b> page<span data-an="onpl">s</span> right now<br><b data-an="active">–</b> visitors in the last <span data-an-wlabel>' + st.win + '</span> min · <b data-an="views">–</b> page views · <b data-an="carts">–</b> added to cart</div></div>'
      + '<div class="an-chart"><svg class="bars" data-an="bars" viewBox="0 0 ' + CH.W + ' ' + CH.H + '" aria-label="Visitors per minute, last 30 minutes"></svg><div class="an-tip" data-an="tip" hidden></div></div>'
      + '<div class="sub" style="font-size:11px;text-align:center">visitors per minute, last 30 minutes</div>'
      + '<div class="minis"><div class="mini"><b data-an="mobile">–</b><span>on mobile · last <span data-an-wlabel>' + st.win + '</span> min</span></div><div class="mini"><b data-an="langs">–</b><span>EN / AR · last <span data-an-wlabel>' + st.win + '</span> min</span></div><div class="mini"><b data-an="topcc">–</b><span>top country · last <span data-an-wlabel>' + st.win + '</span> min</span></div><div class="mini"><b data-an="topsrc">–</b><span>top source · last <span data-an-wlabel>' + st.win + '</span> min</span></div></div>'
      + '</div>')
      + blk('feed', 1, '<div class="card pad"><div class="sec-title"><span>Happening now</span><span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">last 30 minutes, newest first</span></div><ul class="feed" data-an="feed"></ul></div>')
      + blk('pagesnow', 1, '<div class="card pad"><div class="sec-title"><span>Pages being read now</span><span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">online now</span></div><table class="tbl"><colgroup><col><col style="width:48px"></colgroup><tbody data-an="pagesnow"></tbody></table></div>')
      + blk('srcnow', 1, '<div class="card pad"><div class="sec-title"><span>Sources</span><span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">last <span data-an-wlabel>' + st.win + '</span> min</span></div><ul class="bl" data-an="srcnow"></ul></div>')
      + blk('ccnow', 1, '<div class="card pad"><div class="sec-title"><span>Countries</span><span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">last <span data-an-wlabel>' + st.win + '</span> min · cities: coming later</span></div><ul class="bl" data-an="ccnow"></ul><div data-an="cchint"></div></div>')
      + blk('strip', 3, '<div class="strip" data-an="strip"></div>')
      + REST.map(function (id) { return blk(id, 1, '<div data-an="b-' + id + '"></div>'); }).join('')
      + '</div>'
      + '<div class="card pad an-set" data-an="settings"></div>'
      + '</div>';
    paintControls();
    applyLayout();
  }

  function paintControls() {
    var r = q('[data-an-ranges]');
    if (r) Array.prototype.forEach.call(r.querySelectorAll('button'), function (b) { b.classList.toggle('on', b.getAttribute('data-an-range') === st.range); });
    var c = q('[data-an-custom]');
    if (c) c.classList.toggle('on', st.range === 'custom');
    var w = q('[data-an-wins]');
    if (w) Array.prototype.forEach.call(w.querySelectorAll('button'), function (b) { b.classList.toggle('on', +b.getAttribute('data-an-win') === st.win); });
    var host = document.getElementById('content');
    if (host) Array.prototype.forEach.call(host.querySelectorAll('[data-an-wlabel]'), function (l) { l.textContent = st.win; });
  }

  function set(sel, html) { var el = q('[data-an="' + sel + '"]'); if (el) el.innerHTML = html; }
  function text(sel, t) { var el = q('[data-an="' + sel + '"]'); if (el && el.textContent !== String(t)) el.textContent = t; }

  function paintLive() {
    var d = st.live;
    if (!d) return;
    if (drag) { st.deferred = true; return; }
    text('online', fmt(d.online));
    text('onpages', fmt(d.online_pages));
    text('onpl', d.online_pages === 1 ? '' : 's');
    text('active', fmt(d.active));
    text('views', fmt(d.views));
    text('carts', fmt(d.carts));
    set('bars', bars(d.bars || [], d.bar_ticks));
    var tip = q('[data-an="tip"]'); if (tip) tip.hidden = true;
    text('mobile', d.active ? d.mobile_pct + '%' : '–');
    var lt = (d.langs.en || 0) + (d.langs.ar || 0);
    text('langs', lt ? pct(d.langs.en, lt) + ' / ' + pct(d.langs.ar, lt) : '–');
    var known = (d.countries || []).filter(function (c) { return c.cc; });
    text('topcc', known.length ? (CC[known[0].cc] || known[0].cc) : (d.country_db ? '–' : 'Needs data'));
    text('topsrc', d.sources && d.sources.length ? d.sources[0].label : '–');
    set('pagesnow', (d.pages || []).length ? d.pages.map(function (p) {
      return '<tr><td><span class="ttl">' + esc(p.title || path(p.path)) + '</span><span class="path">' + esc(path(p.path)) + '</span></td><td class="r" style="font-weight:700">' + fmt(p.n) + '</td></tr>';
    }).join('') : '<tr><td class="empty">Nobody on the shop right now.</td></tr>');
    set('srcnow', bl((d.sources || []).map(function (s) { return [s.label, s.n]; })));
    // No country file and no Cloudflare header: say how to get one, rather
    // than a list that reads "Unknown".
    set('ccnow', !d.country_db && !known.length ? '' : bl((d.countries || []).map(function (c) { return [CC[c.cc] || c.cc, c.n]; })));
    set('cchint', d.country_db ? '' : '<div class="an-hint" data-an-cchint>Countries need the country database: Store → Security → Firewall → Data → <b>Download country database</b> (once; the hourly schedule keeps it fresh).</div>');
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

  function paintCron(c) {
    var el = q('[data-an="cron"]');
    if (!el || !c) return;
    el.innerHTML = c.alive ? '' : '<div class="an-hint" style="margin:0 0 12px">The scheduler is not running on this server, so today’s figures are rebuilt only while this board is open (every poll keeps them current). To keep them current all the time, add this cron job in ' + esc(c.where) + ':<br><code style="font-size:11.5px;word-break:break-all">' + esc(c.line) + '</code></div>';
  }

  function paintSummary() {
    var s = st.sum;
    if (!s) return;
    if (drag) { st.deferred = true; return; }
    var up = q('[data-an="updated"]');
    if (up) up.textContent = s.updated ? 'Updated ' + hhmm(Date.parse(s.updated) / 1000) : '';
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

    var B = {
      daily: s.series && s.series.length > 1 ? card('Visitors per day', daily(s.series)) : '',
      pages: card('Top pages', list(rows(d.page, 'views', function (r) { return r.label || path(r.val); })), 'views'),
      sources: card('Sources', list(rows(d.channel, 'sessions')), 'sessions'),
      revsrc: card('Orders &amp; revenue by source', list(ch, true), 'AED · conv.'),
      campaigns: card('Orders by campaign', list(cp, true), 'AED'),
      utm: card('Campaigns (UTM)', list(rows(d.campaign, 'sessions')), 'sessions'),
      funnel: card('Checkout funnel', '<svg viewBox="0 0 320 150" style="width:100%;height:auto;display:block">' + funnel(t) + '</svg>'
        + '<div class="faint" style="margin-top:4px">Same period for every bar: visitors, visitors who added to cart, visitors who reached checkout, and paid orders.</div>'),
      entry: card('Entry pages', list(rows(d.entry, 'sessions', function (r) { return path(r.val); })), 'sessions'),
      search: card('Searched on the shop', list((s.search || []).map(function (r) { return [r.term, r.hits, r.results === 0 ? 'no results' : '']; })), 'searches'),
      google: card('Google search keywords', gsc, g.connected ? 'clicks · last 90 days' : ''),
      referrers: card('Referrers · UTM source', list(rows(d.referrer, 'sessions')) + '<div style="height:10px"></div>' + list(rows(d.source, 'sessions'))),
      devices: card('Devices · Browsers', list(rows(d.device, 'sessions')) + '<div style="height:10px"></div>' + list(rows(d.browser, 'sessions'))),
      langs: card('Language · Countries', list(rows(d.lang, 'sessions')) + '<div style="height:10px"></div>' + list(rows(d.country, 'sessions', function (r) { return CC[r.val] || r.val; })))
    };
    // Each block's content goes into that block, wherever the owner put it.
    REST.forEach(function (id) {
      set('b-' + id, B[id]);
      var b = q('[data-blk="' + id + '"]');
      if (b) b.classList.toggle('an-empty', B[id] === '');
    });
    if (s.layout && !st.layout) { st.layout = s.layout; applyLayout(); }
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
      if (st.active) showErr(e.status === 403 ? 'Your role does not include Analytics. Ask the owner to add it in Users & Roles.'
        : e.status === 404 && e.blank ? 'This screen is not in the server’s compiled route table yet. Clear it from Platform → Cache, then reload.'
        : 'Analytics could not be loaded (' + e.message + ').');
    }
  }

  async function loadLive() {
    if (!st.active || st.busy) return;
    st.busy = true;
    try {
      var j = await api('GET', '/live?w=' + st.win + '&since=' + st.since + '&osince=' + st.osince + (st.range === 'today' ? '&today=1' : ''));
      if (!st.active || !q('[data-an]')) return;
      // Today's KPIs, funnel and lists ride on the same poll (Lane AN2).
      if (j.today && st.range === 'today') { st.sum = j.today; st.sumAt = Date.now(); paintSummary(); }
      paintCron(j.cron);
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
    var t = e.target && e.target.closest ? e.target.closest('[data-an-range],[data-an-win],[data-an-apply],[data-an-save],[data-an-mine],[data-an-hide],[data-an-showall],[data-an-reset]') : null;
    if (!t || !t.closest('[data-an]')) return;
    if (t.hasAttribute('data-an-hide')) {
      var id = t.closest('[data-blk]').getAttribute('data-blk');
      st.layout = { order: order(), hidden: ((st.layout && st.layout.hidden) || []).concat([id]) };
      applyLayout(); save(); say(NAMES[id] + ' hidden.');
      return;
    }
    if (t.hasAttribute('data-an-showall')) {
      st.layout = { order: order(), hidden: [] };
      applyLayout(); save(); say('Every block is shown.');
      return;
    }
    if (t.hasAttribute('data-an-reset')) {
      api('DELETE', '/layout').then(function (j) { st.layout = j; applyLayout(); say('Layout reset.'); }).catch(function () { say('The layout could not be reset.'); });
      return;
    }
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

  /* ── MOVE THE BLOCKS (Lane AN2) ─────────────────────────────────────────
     The owner: "the blocks should be moveable to change the position as per
     my convenience. should be drag n drop." Native POINTER events (mouse and
     touch alike; HTML5 drag-and-drop is poor on touch): press the handle (a
     long press on touch), and the block moves through the grid to wherever
     the pointer is -- found with elementFromPoint, nothing measured. The
     handle is a button: arrow up / down moves the block, announced. One PUT,
     debounced, after a drop; none while dragging. The order and hidden blocks
     are this admin's own (BoardLayout), shared with the owner app. */
  var NAMES = { live: 'Online now', feed: 'Happening now', pagesnow: 'Pages being read now', srcnow: 'Sources (live)', ccnow: 'Countries (live)', strip: 'Today’s figures',
    daily: 'Visitors per day', pages: 'Top pages', sources: 'Sources', revsrc: 'Orders & revenue by source', campaigns: 'Orders by campaign', utm: 'Campaigns (UTM)',
    funnel: 'Checkout funnel', entry: 'Entry pages', search: 'Searched on the shop', google: 'Google search keywords', referrers: 'Referrers · UTM source', devices: 'Devices · Browsers', langs: 'Language · Countries' };
  var REST = ['daily', 'pages', 'sources', 'revsrc', 'campaigns', 'utm', 'funnel', 'entry', 'search', 'google', 'referrers', 'devices', 'langs'];
  var drag = null, press = null, saveT = 0;

  function blk(id, span, inner) {
    return '<section class="an-blk an-s' + span + '" data-blk="' + id + '">'
      + '<button type="button" class="an-hdl" data-an-hdl aria-label="Move ' + esc(NAMES[id]) + ' (arrow keys, or drag)" title="Drag to move">⠿</button>'
      + '<button type="button" class="an-eye" data-an-hide aria-label="Hide ' + esc(NAMES[id]) + '" title="Hide this block">✕</button>'
      + inner + '</section>';
  }
  function grid() { return q('[data-an-grid]'); }
  function order() { var g = grid(); return g ? Array.prototype.map.call(g.children, function (b) { return b.getAttribute('data-blk'); }) : []; }
  function applyLayout() {
    var g = grid(), L = st.layout;
    if (!g || !L) return;
    (L.order || []).forEach(function (id) { var b = g.querySelector('[data-blk="' + id + '"]'); if (b) g.appendChild(b); });
    Array.prototype.forEach.call(g.children, function (b) { b.classList.toggle('an-off', (L.hidden || []).indexOf(b.getAttribute('data-blk')) >= 0); });
    var chip = q('[data-an="hiddenchip"]'), n = (L.hidden || []).length;
    if (chip) chip.innerHTML = n ? '<button type="button" class="an-link" data-an-showall>Hidden blocks (' + n + ') · show</button>' : '';
  }
  function say(t) { var el = q('[data-an-say]'); if (el) el.textContent = t; }
  function save() {
    if (saveT) clearTimeout(saveT);
    saveT = setTimeout(function () {
      saveT = 0;
      api('PUT', '/layout', { order: order(), hidden: (st.layout && st.layout.hidden) || [] })
        .then(function (j) { st.layout = j; }).catch(function () { say('The layout could not be saved. It is kept on this screen until you leave.'); });
    }, 600);
  }
  function settle() {
    st.layout = { order: order(), hidden: (st.layout && st.layout.hidden) || [] };
    save();
  }
  function endDrag() {
    if (press) { clearTimeout(press.t); press = null; }
    if (!drag) return;
    drag.el.classList.remove('an-drag');
    var g = grid(); if (g) g.classList.remove('an-dragging');
    var moved = drag.from !== order().join();
    drag = null;
    if (moved) settle();
    if (st.deferred) { st.deferred = false; paintLive(); paintSummary(); }
  }
  document.addEventListener('pointerdown', function (e) {
    var h = e.target && e.target.closest ? e.target.closest('[data-an-hdl]') : null;
    if (!h || !grid() || e.button > 0) return;
    var el = h.closest('[data-blk]');
    var start = function () {
      press = null;
      drag = { el: el, from: order().join() };
      el.classList.add('an-drag');
      grid().classList.add('an-dragging');
      say('Moving ' + NAMES[el.getAttribute('data-blk')] + '.');
    };
    if (e.pointerType === 'touch') press = { t: setTimeout(start, 350), x: e.clientX, y: e.clientY };
    else { e.preventDefault(); start(); }
  });
  document.addEventListener('pointermove', function (e) {
    if (press && (Math.abs(e.clientX - press.x) > 10 || Math.abs(e.clientY - press.y) > 10)) { clearTimeout(press.t); press = null; }
    if (!drag) return;
    e.preventDefault();
    // Near the top or bottom of the window: scroll the board along.
    var edge = e.clientY < 70 ? -14 : (e.clientY > window.innerHeight - 70 ? 14 : 0);
    if (edge) { var c = document.getElementById('content'); if (c) c.scrollBy(0, edge); window.scrollBy(0, edge); }
    var over = document.elementFromPoint(e.clientX, e.clientY);
    var tb = over && over.closest ? over.closest('[data-blk]') : null;
    var g = grid();
    if (!tb || tb === drag.el || tb.parentNode !== g) return;
    var kids = Array.prototype.slice.call(g.children);
    g.insertBefore(drag.el, kids.indexOf(tb) > kids.indexOf(drag.el) ? tb.nextSibling : tb);
  }, { passive: false });
  document.addEventListener('pointerup', endDrag);
  document.addEventListener('pointercancel', endDrag);
  document.addEventListener('keydown', function (e) {
    var h = e.target && e.target.closest ? e.target.closest('[data-an-hdl]') : null;
    if (!h || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    var el = h.closest('[data-blk]'), g = grid();
    var sib = e.key === 'ArrowUp' ? el.previousElementSibling : el.nextElementSibling;
    if (!sib) return;
    g.insertBefore(el, e.key === 'ArrowUp' ? sib : sib.nextSibling);
    h.focus();
    say(NAMES[el.getAttribute('data-blk')] + ' moved to position ' + (order().indexOf(el.getAttribute('data-blk')) + 1) + ' of ' + order().length + '.');
    settle();
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
