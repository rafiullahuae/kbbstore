{{--
    Analytics.                                                         (Lane AN)

    The owner: "another strong module for Analytics, same like google
    analytics, so i can track live visitors, live visits pages list, keywords
    and much more, MUST BE SUPER LIGHTENING without load to the server."
    Design B, "Realtime board", which he picked from docs/analytics-options/.

    LIVE ONLY WHILE WATCHED. The live slice is one small GET
    (/admin-api/site-analytics/live) every 15 s, and ONLY while this screen is
    the one open AND the tab is visible: a setTimeout chain (never
    setInterval) that visibilitychange stops and restarts, and that leaving the
    screen (any other window.go) tears down. On return it asks for what
    happened meanwhile with `since`, so the feed is back-filled once, without
    duplicates. Recording never sleeps -- the shop's beacon writes every page
    view whoever is looking; only this display rests.

    The window (5 / 10 / 15 / 25 minutes, default 10) is the owner's choice per
    device, kept in localStorage. The range strip is one GET when the screen
    opens or the range changes. Charts are inline SVG drawn to scale from
    numbers already here; nothing measures layout.

    SAFE. Every value printed passes through esc(); the server sends aggregates
    only (counts, labels, paths). analytics.view reads, analytics.manage saves
    the two settings, both enforced server-side. Restyling is CSS and markup
    only: the data is painted into [data-an] hooks by paint functions below.

    Pulled into app.blade.php by tools/an-wire.php; its sidebar row is
    AdminNav's (Overview, under Dashboard). It wraps window.go.
--}}
@verbatim
<style>
.anb{--night:#0c1120;--night-2:#141a2b;--night-3:#232c42;--night-ink:#eef1f9;--night-soft:#9aa5bd;max-width:1280px}
.anb .an-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin:0 0 14px}
.anb .an-top h2{margin:0;font-size:20px;letter-spacing:-.01em}
.anb .seg{display:inline-flex;background:var(--surface-2,#f2f4fb);border:1px solid var(--border,#e6e9f2);border-radius:9px;padding:3px;gap:2px;flex-wrap:wrap}
.anb .seg button{font:inherit;font-size:12px;font-weight:600;color:var(--ink-soft,#626c80);padding:6px 11px;border-radius:7px;border:0;background:none;cursor:pointer}
.anb .seg button.on{background:var(--surface,#fff);color:var(--accent-ink,#0b6e3a);box-shadow:0 1px 2px rgba(16,24,40,.05)}
.anb .seg.dark{background:var(--night-2);border-color:var(--night-3)}
.anb .seg.dark button{color:var(--night-soft);padding:4px 9px}
.anb .seg.dark button.on{background:var(--night-3);color:var(--night-ink);box-shadow:none}
.anb .an-custom{display:none;gap:6px;align-items:center;font-size:12px}
.anb .an-custom.on{display:inline-flex}
.anb .an-custom input{font:inherit;font-size:12px;padding:5px 6px;border:1px solid var(--border,#e6e9f2);border-radius:7px;background:var(--surface,#fff);color:var(--ink,#101729)}
.anb .card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);border-radius:var(--r,18px);box-shadow:0 1px 2px rgba(16,24,40,.05);min-width:0}
.anb .pad{padding:16px 18px}
.anb .sec-title{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-faint,#97a0b2);margin:0 0 10px;display:flex;gap:8px;align-items:center;justify-content:space-between}
.anb .board{display:grid;grid-template-columns:1.25fr 1fr;gap:14px}
.anb .night{background:var(--night);color:var(--night-ink);border-radius:var(--r,18px);padding:18px 20px;border:1px solid #1c2438;min-width:0}
.anb .night .sec-title{color:var(--night-soft)}
.anb .dot{width:8px;height:8px;border-radius:50%;background:#22c06c;flex:none;animation:anpulse 2s infinite}
.anb .dot.off{background:var(--night-soft);animation:none}
@keyframes anpulse{0%{box-shadow:0 0 0 0 rgba(34,192,108,.55)}70%{box-shadow:0 0 0 8px rgba(34,192,108,0)}100%{box-shadow:0 0 0 0 rgba(34,192,108,0)}}
@media (prefers-reduced-motion:reduce){.anb .dot{animation:none}}
.anb .huge{font-size:84px;font-weight:800;letter-spacing:-.05em;line-height:.95;font-variant-numeric:tabular-nums}
.anb .night .sub{color:var(--night-soft);font-size:13px}
.anb .night .sub b{color:var(--night-ink)}
.anb .bars{width:100%;height:auto;margin-top:14px;display:block;overflow:visible}
.anb .an-chart{position:relative}
.anb .an-ax{fill:var(--night-soft);font-size:9px;font-variant-numeric:tabular-nums}
.anb .bars rect[data-i]{cursor:pointer}
.anb .an-tip{position:absolute;top:0;transform:translate(-50%,-110%);background:#fff;color:#101729;font-size:11.5px;font-weight:600;padding:3px 8px;border-radius:6px;white-space:nowrap;pointer-events:none;font-variant-numeric:tabular-nums;box-shadow:0 2px 8px rgba(0,0,0,.25)}
.anb .an-tip[hidden]{display:none}
.anb .an-wl{font-size:10.5px;color:var(--night-soft);text-transform:none;letter-spacing:0;font-weight:500;margin-right:6px}
.anb .an-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;grid-auto-flow:row dense}
.anb .an-blk{position:relative;min-width:0}
.anb .an-blk>.night,.anb .an-blk>.card{height:100%}
.anb .an-s2{grid-column:span 2}
.anb .an-s3{grid-column:1/-1}
.anb .an-s3 .strip{margin-top:0}
.anb .an-off,.anb .an-empty{display:none}
.anb .an-hdl,.anb .an-eye{position:absolute;top:8px;z-index:3;width:26px;height:26px;border-radius:7px;border:1px solid var(--border,#e6e9f2);background:var(--surface,#fff);color:var(--ink-soft,#626c80);font-size:13px;line-height:1;cursor:grab;opacity:0;transition:opacity .15s;touch-action:none}
.anb .an-hdl{right:38px}
.anb .an-eye{right:8px;cursor:pointer;font-size:11px}
.anb .an-blk:hover>.an-hdl,.anb .an-blk:hover>.an-eye,.anb .an-hdl:focus-visible,.anb .an-eye:focus-visible{opacity:1}
@media (hover:none){.anb .an-hdl,.anb .an-eye{opacity:.7}}
.anb .an-dragging .an-blk{transition:none}
.anb .an-drag{outline:2px dashed var(--accent,#15a85a);outline-offset:3px;border-radius:var(--r,18px);opacity:.55}
.anb .an-drag>.an-hdl{opacity:1;cursor:grabbing}
.anb .an-tools{display:flex;gap:12px;justify-content:flex-end;align-items:center;margin:0 0 8px;font-size:12px}
.anb .an-link{border:0;background:none;color:var(--accent-ink,#0b6e3a);font:inherit;font-size:12px;font-weight:600;cursor:pointer;padding:2px}
.anb .an-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.anb .an-hint{font-size:12px;color:var(--ink-soft,#626c80);background:var(--surface-2,#f2f4fb);border-radius:8px;padding:8px 10px;margin-top:6px}
.anb .minis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
.anb .mini{background:var(--night-2);border:1px solid var(--night-3);border-radius:12px;padding:10px 12px;min-width:0}
.anb .mini b{display:block;font-size:18px;letter-spacing:-.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.anb .mini span{font-size:11px;color:var(--night-soft)}
.anb .feed{list-style:none;margin:0;padding:0;max-height:360px;overflow:auto}
.anb .feed li{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:10px;align-items:center;padding:7px 0;border-bottom:1px solid var(--border-2,#eef0f6);font-size:13px}
.anb .feed time{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--ink-faint,#97a0b2)}
.anb .ev{font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:99px;background:#eaf0fd;color:#3f6fe0}
.anb .ev.cart{background:#fdf2e2;color:#a0620e}
.anb .ev.order{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.anb .ttl{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.anb .path{display:block;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--ink-faint,#97a0b2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.anb .faint{color:var(--ink-faint,#97a0b2);font-size:11.5px}
.anb .now3{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:14px;margin-top:14px}
.anb .strip{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:14px}
.anb .strip .card{padding:12px 14px}
.anb .strip b{display:block;font-size:20px;letter-spacing:-.02em;font-variant-numeric:tabular-nums;white-space:nowrap}
.anb .strip span{font-size:11.5px;color:var(--ink-soft,#626c80)}
.anb .up{color:var(--accent-ink,#0b6e3a);font-weight:600}.anb .down{color:var(--red,#e3493f);font-weight:600}
.anb .rest{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:14px}
.anb .bl{list-style:none;margin:0;padding:0;display:grid;gap:4px}
.anb .bl li{position:relative;display:flex;align-items:center;gap:10px;padding:6px 10px;border-radius:8px;min-width:0;font-size:13px}
.anb .bl li>i{position:absolute;inset:0 auto 0 0;border-radius:8px;background:var(--accent-soft,#e7f7ee);z-index:0}
.anb .bl li>*:not(i){position:relative;z-index:1}
.anb .bl .l{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.anb .bl .v{font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap}
.anb .bl .p{font-variant-numeric:tabular-nums;color:var(--ink-faint,#97a0b2);min-width:40px;text-align:right;font-size:12px}
.anb .tbl{width:100%;border-collapse:collapse;table-layout:fixed;font-size:13px}
.anb .tbl td{padding:6px 4px;border-bottom:1px solid var(--border-2,#eef0f6);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.anb .tbl td.r{text-align:right;font-variant-numeric:tabular-nums}
.anb .empty{color:var(--ink-faint,#97a0b2);font-size:12.5px;padding:6px 2px}
.anb .gsc{border:1px dashed var(--border,#e6e9f2);border-radius:12px;padding:12px;font-size:12.5px;color:var(--ink-soft,#626c80)}
.anb .an-set{margin-top:14px}
.anb .an-set textarea{width:100%;min-height:70px;font:12px ui-monospace,SFMono-Regular,Menlo,monospace;padding:8px;border:1px solid var(--border,#e6e9f2);border-radius:9px;background:var(--surface,#fff);color:var(--ink,#101729)}
.anb .an-set label{display:flex;gap:8px;align-items:center;font-size:13px;margin:0 0 10px}
.anb .an-msg{font-size:12px;color:var(--ink-soft,#626c80);margin-left:8px}
.anb .an-msg.err{color:var(--red,#e3493f)}
.anb .an-err{background:#fdeceb;color:#9b2c25;border-radius:10px;padding:10px 12px;font-size:12.5px;margin:0 0 12px}
@media (max-width:760px){
  .anb .board,.anb .now3,.anb .rest,.anb .an-grid{grid-template-columns:minmax(0,1fr)}
  .anb .an-s2,.anb .an-s3{grid-column:auto}
  .anb .huge{font-size:64px}
  .anb .minis{grid-template-columns:repeat(2,minmax(0,1fr))}
  .anb .strip{grid-template-columns:repeat(3,minmax(0,1fr))}
  .anb .hide-s{display:none}
  .anb .pad{padding:14px}
}
</style>
<script>
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
</script>
@endverbatim
