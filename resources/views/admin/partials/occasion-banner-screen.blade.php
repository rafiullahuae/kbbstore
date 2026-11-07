{{--
    Appearance → Occasion banner.                                      Lane OHB

    The owner, 6 November 2026: "proceed with the development, allow us to
    choose the designs from the list along with preview, and need all controls
    to edit those sections." The homepage banner that shows before / with the
    picture slider: three layouts (G Floating Cluster, H Glow Halo, I Wave
    Stage) x four occasions, every word EN + AR, the colours, the button link,
    animation on/off, and the product POOL the five floating products are drawn
    from at random -- each with its real height in cm (true proportions) and a
    "white product" tick, and its transparent cut-out made from here.

    LIGHT: one GET when the screen opens. The live preview (computer and phone
    frames) is BUILT IN THE BROWSER from that answer -- the templates, the
    stylesheet the shop prints, the pool's cut-out URLs -- so typing costs no
    request; it repaints on the next animation frame after a change. The search
    asks at most once per 300 ms of typing (2+ characters). "Prepare cut-outs"
    is one request per product, one after another, with progress, and a Stop.
    No polling and no timer left running.

    SAFE: every value is escaped (esc()) before innerHTML; the preview frames
    are sandboxed iframes with no script. A late answer never paints over
    another screen: every repaint checks ours() (CLAUDE.md, the Marketing
    Emails landmine).

    ARRANGE (Lane DND, the owner 7 Nov 2026: "drag n drop etc anywhere the
    product images inside the slider ... change the size of the image by drag
    ... in desktop and mobile both"). "Arrange products" lays a handle over
    every product in both preview frames: drag to move, a corner dot to resize
    (proportions kept), arrow keys to nudge (Shift x10), + / - to resize,
    numbers to type, Reset place / Reset layout, Undo and Ctrl+Z. A position
    belongs to a PLACE (slot 1-5), not a product -- products are random per
    view (OccasionBanner::POS_RANGE). Pointer Events with pointer capture on
    the overlay, ONE rAF-throttled move step, no timer; the preview gets the
    same CSS the shop prints (posCss, a twin of OccasionBanner::posCss()).

    Registered with ONE line in admin/app.blade.php:
        @include('admin.partials.occasion-banner-screen')
    It adds its own sidebar row (Appearance, after Homepage content) and wraps
    window.go for 'occbanner'.
--}}
<style>
.obx{display:grid;gap:14px;min-width:0}
.obx-card{padding:16px;min-width:0;display:grid;gap:12px;border:1px solid var(--border);border-radius:14px;background:var(--surface)}
.obx-h{font-weight:700;font-size:15px;margin:0}
.obx-sub{color:var(--ink-soft);font-size:12.5px;line-height:1.55;margin:0;max-width:90ch}
.obx-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;min-width:0}
.obx-g2{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr))}
.obx-f{display:grid;gap:4px;min-width:0;align-content:start}
.obx-f>span{font-size:12px;font-weight:600;color:var(--ink-2)}
.obx-in{font:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--ink);min-width:0;width:100%;box-sizing:border-box}
.obx-in:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.obx-in.sm{width:84px}
.obx-pill{font:inherit;font-size:13px;padding:8px 12px;border:1px solid var(--border);border-radius:999px;background:var(--surface);color:var(--ink);cursor:pointer}
.obx-pill[aria-pressed="true"]{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:650}
.obx-btn{font:inherit;font-size:13px;font-weight:650;padding:9px 14px;border-radius:10px;border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer}
.obx-btn.ghost{background:var(--surface);color:var(--ink);border-color:var(--border)}
.obx-btn[disabled]{opacity:.55;cursor:default}
.obx-list{display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
.obx-opt{font:inherit;text-align:start;display:grid;gap:6px;padding:8px;border:2px solid var(--border);border-radius:12px;background:var(--surface);cursor:pointer;color:var(--ink)}
.obx-opt[aria-pressed="true"]{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.obx-opt b{font-size:12.5px}
.obx-sw{display:block;height:34px;border-radius:8px}
.obx-pv{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start}
.obx-fr{display:grid;gap:4px}
.obx-fr>span{font-size:11.5px;color:var(--ink-soft)}
.obx-box{position:relative;overflow:hidden;border-radius:10px;border:1px solid var(--border);background:#f6f2f4}
.obx-box iframe{position:absolute;top:0;left:0;border:0;transform-origin:0 0;pointer-events:none}
.obx-d{width:640px;height:300px}.obx-d iframe{width:1280px;height:600px;transform:scale(.5)}
.obx-m{width:234px;height:300px}.obx-m iframe{width:390px;height:500px;transform:scale(.6)}
@media (max-width:760px){.obx-d{width:320px;height:150px}.obx-d iframe{transform:scale(.25)}.obx-m{width:156px;height:200px}.obx-m iframe{transform:scale(.4)}}
.obx-cols{display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(130px,1fr))}
.obx-col{display:flex;gap:6px;align-items:center;font-size:12px}
.obx-col input[type=color]{width:38px;height:30px;padding:0;border:1px solid var(--border);border-radius:8px;background:none}
.obx-pool{display:grid;gap:6px}
.obx-it{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:10px;align-items:center;padding:8px;border:1px solid var(--border);border-radius:12px}
.obx-it img{width:44px;height:44px;object-fit:contain;border-radius:8px;background:#f2eef0}
.obx-it .nm{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.obx-it .br{font-size:11.5px;color:var(--ink-soft)}
.obx-ctl{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end;font-size:12px}
.obx-ok{color:#14804a;font-weight:650}.obx-no{color:#b42318;font-weight:650}
.obx-res{display:grid;gap:6px;max-height:320px;overflow:auto}
.obx-msg{font-size:13px;padding:10px 12px;border-radius:10px;background:var(--accent-soft)}
.obx-msg.bad{background:#fde8e8;color:#8a1c1c}
.obx-ov{position:absolute;inset:0;z-index:1;touch-action:none}
.obx-hd{position:absolute;box-sizing:border-box;margin:0;padding:0;border:1.5px dashed rgba(60,20,50,.55);border-radius:6px;background:rgba(255,255,255,.06);cursor:move;touch-action:none;-webkit-user-select:none;user-select:none;font:inherit;color:inherit}
.obx-hd:hover{border-color:var(--accent);background:rgba(255,255,255,.14)}
.obx-hd:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.obx-hd.on{border:2px solid var(--accent);background:rgba(255,255,255,.18);z-index:9}
.obx-hd.own{border-style:solid}
.obx-hn{position:absolute;left:-1px;top:-1px;min-width:16px;height:16px;padding:0 3px;box-sizing:border-box;border-radius:5px 0 6px 0;background:var(--accent);color:#fff;font-size:10.5px;font-weight:700;line-height:16px;text-align:center;pointer-events:none}
.obx-hk{position:absolute;z-index:20;width:16px;height:16px;margin:-8px 0 0 -8px;border-radius:50%;background:#fff;border:2px solid var(--accent);box-sizing:border-box;cursor:nesw-resize;touch-action:none;box-shadow:0 1px 4px rgba(0,0,0,.25)}
.obx-hk::before{content:"";position:absolute;inset:-10px}
.obx-hk.l{cursor:nwse-resize}
.obx-arr{display:grid;gap:8px;padding:10px 12px;border:1px dashed var(--border);border-radius:12px}
.obx-arr .obx-in.sm{width:76px}
.obx-bar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface)}
@media (max-width:560px){.obx-it{grid-template-columns:44px minmax(0,1fr)}.obx-ctl{grid-column:1 / -1;justify-content:flex-start}}
</style>
@verbatim
<script>
(function () {
  'use strict';
  var SCREEN = 'occbanner', GROUP = 'Appearance', TITLE = 'Occasion banner';
  var st = { data: null, cfg: null, saved: '', busy: false, msg: '', bad: false, lang: 'en', rot: 0,
    q: '', res: [], qt: 0, qctl: null, prep: null, raf: 0, placed: [],
    dnd: { on: false, sel: null, drag: null, raf: 0, undo: [], typing: false } };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function ours() { var c = document.querySelector('#content'); return !!c && (!c.firstChild || !!c.querySelector('[data-screen="' + SCREEN + '"]')); }
  function can() { return !!(st.data && st.data.can_manage); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function dirty() { return st.cfg && JSON.stringify(slim(st.cfg)) !== st.saved; }
  function cat() { return st.data.catalogue; }

  function api(method, path, body, signal) {
    var opts = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (signal) opts.signal = signal;
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(root() + '/admin-api/occasion-banner' + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok || j.ok === false) {
          var m = j.message || '';
          if (r.status === 403) m = 'Your role cannot change this.';
          if (r.status === 404 && !m) m = 'The Occasion banner endpoints are not in the compiled route table yet. Apply the package (its migration clears the route cache) and reload.';
          if (r.status === 422 && j.errors) m = j.errors[Object.keys(j.errors)[0]][0];
          var e = new Error(m || ('The server answered ' + r.status + '.')); e.status = r.status; throw e;
        }
        return j;
      });
    });
  }

  /* What the server stores from a pool row: the browser sends only these. */
  function slim(c) {
    var o = clone(c);
    o.pool = (o.pool || []).map(function (p) { return { id: p.id, cm: +p.cm || 0, white: !!p.white }; });
    return normPos(o);
  }

  /* ---------------------------------------------- the banner, as the shop draws it
     A twin of partials/home/occasion-banner.blade.php and OccasionBanner::view(). */
  function ready() { return (st.cfg.pool || []).filter(function (p) { return p.cut_url; }); }
  function pick() {
    var r = ready(), n = st.cfg.count, out = [], rest = [], seen = {};
    if (r.length) r = r.slice(st.rot % r.length).concat(r.slice(0, st.rot % r.length));
    if (!st.cfg.per_brand) return r.slice(0, n);
    r.forEach(function (p) { var b = p.brand_id > 0 ? 'i' + p.brand_id : 'n' + String(p.brand || '').toLowerCase(); if (b !== 'n' && seen[b]) { rest.push(p); return; } seen[b] = 1; out.push(p); });
    return out.concat(rest).slice(0, n);
  }
  function place(shown) {
    var C = cat(), max = 0, d = C.cm[2];
    ready().forEach(function (p) { max = Math.max(max, p.cm > 0 ? p.cm : d); });
    max = max || d;
    if (!shown.length) return [];
    var slots = C.slots_for[shown.length].slice().sort(function (a, b) { return C.slots[b].h - C.slots[a].h; });
    var by = shown.slice().sort(function (a, b) { return (b.cm || d) - (a.cm || d); });
    return by.map(function (p, i) { var h = Math.round(C.tallest * (p.cm > 0 ? p.cm : d) / max * 10) / 10; return { p: p, slot: slots[i], h: Math.max(10, Math.min(66, h)) }; })
      .sort(function (a, b) { return a.slot - b.slot; });
  }
  function banner(lang) {
    var c = st.cfg, t = c[lang], C = cat(), li = ['G', 'H', 'I'].indexOf(c.layout);
    var vars = ['ink', 'acc', 'btn', 'btnInk', 'a1', 'a2', 'a3', 'b1', 'b2', 'b3', 'c1', 'c2', 'c3'].map(function (k) { return '--' + k + ':' + c.colours[k]; }).join(';');
    var h = '<div class="ohb ohb-' + esc(c.layout) + (c.animate ? '' : ' ohb-still') + '" style="' + esc(vars) + '"><div class="ohb-bg"><i></i><i></i><i></i></div><div class="ohb-glow"></div>';
    if (c.layout === 'G') h += '<span class="ohb-dot ohb-d1"></span><span class="ohb-dot ohb-d2"></span><span class="ohb-dot ohb-d3"></span>';
    else if (c.layout === 'H') h += '<div class="ohb-halo"></div>';
    else h += '<div class="ohb-wave"></div><div class="ohb-wave w2"></div>';
    st.placed.forEach(function (x) {
      var s = C.slots[x.slot], u = x.p.cut_url;
      h += '<div class="ohb-p ohb-s' + x.slot + '" style="--h:' + x.h + ';--r:' + s.r[li] + 'deg;animation-delay:' + s.d[li] + 's"><img src="' + esc(u.src) + '" srcset="' + esc(u.srcset) + '" width="' + (+u.w) + '" height="' + (+u.h) + '" alt=""></div>';
    });
    h += '<div class="ohb-copy">';
    if (t.label) h += '<span class="ohb-label">' + esc(t.label) + '</span>';
    if (t.num) h += '<span class="ohb-num">' + esc(t.num) + '</span>';
    if (t.title) h += '<h2><span class="ohb-title">' + esc(t.title) + '</span></h2>';
    if (t.offer) h += '<div class="ohb-offer">' + esc(t.offer) + '</div>';
    if (t.line) h += '<p class="ohb-line">' + esc(t.line) + '</p>';
    if (t.cta && c.link) h += '<a class="ohb-btn" href="#">' + esc(t.cta) + ' <span class="ohb-arw">&rarr;</span></a>';
    return h + '</div></div>';
  }
  function frameDoc() {
    return '<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0}body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}</style><style>' + cat().css.replace(/<\//g, '<\\/') + '</style><style id="obx-pos"></style></head><body></body></html>';
  }
  function paintPreview() {
    st.raf = 0;
    if (!ours() || !st.cfg) return;
    st.placed = place(pick());
    document.querySelectorAll('[data-obx-frame]').forEach(function (f) {
      var d = f.contentDocument;
      if (!d || !d.body) return;
      d.documentElement.setAttribute('dir', st.lang === 'ar' ? 'rtl' : 'ltr');
      d.body.innerHTML = banner(st.lang);
    });
    framePos(); drawOv();
  }
  function later() { if (!st.raf) st.raf = window.requestAnimationFrame(paintPreview); }

  /* ------------------------------------------------------- arrange (Lane DND)
     Positions are % of the banner box: x / y of the product's bottom-centre
     (x from the left, mirrored on Arabic; y from the top) and h, its height,
     only once resized. They belong to a PLACE (slot 1-5), never to a product. */
  var FR = { d: { W: 1280, H: 600, name: 'Computer' }, m: { W: 390, H: 500, name: 'Phone' } }, LAYS = ['G', 'H', 'I'];
  /* The preview boxes' own sizes in px, as .obx-d / .obx-m set them (and under 760px): known, so nothing is measured. */
  var BOX = { d: [640, 300, 320, 150], m: [234, 300, 156, 200] };
  function boxPx(dev) { var b = BOX[dev], small = window.matchMedia && window.matchMedia('(max-width:760px)').matches; return small ? [b[2], b[3]] : [b[0], b[1]]; }
  function r1(v) { return Math.round(v * 10) / 10; }
  function numf(v) { var s = r1(v).toFixed(1); if (s.slice(-2) === '.0') s = s.slice(0, -2); return s === '-0' ? '0' : s; }
  function lim(k, v) { var R = cat().pos_range[k]; return r1(Math.max(R[0], Math.min(R[1], v))); }
  /* One canonical shape (layout -> device -> place -> {x, y, h?}), so "unsaved" compares like with like. */
  function normPos(c) {
    var o = {}, p = c.pos && typeof c.pos === 'object' ? c.pos : {};
    LAYS.forEach(function (L) {
      var l = p[L];
      if (!l || typeof l !== 'object') return;
      ['d', 'm'].forEach(function (dv) {
        var r = l[dv];
        if (!r || typeof r !== 'object') return;
        Object.keys(r).forEach(function (k) {
          var q = r[k];
          if (!q || !isFinite(+q.x) || !isFinite(+q.y)) return;
          var cell = { x: +q.x, y: +q.y };
          if (q.h != null && q.h !== '' && isFinite(+q.h)) cell.h = +q.h;
          o[L] = o[L] || {}; o[L][dv] = o[L][dv] || {}; o[L][dv][String(k)] = cell;
        });
      });
    });
    c.pos = o;
    return c;
  }
  function getP(dev, n) { var l = st.cfg.pos[st.cfg.layout]; return (l && l[dev] && l[dev][n]) || null; }
  function setP(dev, n, v) {
    var P = st.cfg.pos, L = st.cfg.layout;
    if (v) { P[L] = P[L] || {}; P[L][dev] = P[L][dev] || {}; P[L][dev][n] = v; return; }
    if (P[L] && P[L][dev]) { delete P[L][dev][n]; if (!Object.keys(P[L][dev]).length) delete P[L][dev]; if (!Object.keys(P[L]).length) delete P[L]; }
  }
  function put(dev, n, x, y, h) {
    var v = { x: lim('x', x), y: lim('y', y) };
    if (h != null) v.h = lim('h', h);
    setP(dev, n, v);
  }
  /* Where a placed product stands now: its own place if arranged, else the design's, at its true size. */
  function geom(dev, x) {
    var C = cat(), s = C.slots[x.slot], F = FR[dev], u = x.p.cut_url, ratio = (+u.w || 1) / Math.max(1, +u.h || 1);
    var dh = dev === 'd' ? x.h : x.h * C.phone_scale, c = getP(dev, x.slot + 1);
    var h = c && c.h != null ? c.h : dh, w = h * F.H * ratio / F.W, cx, cy;
    if (c) { cx = c.x; cy = c.y; }
    else if (dev === 'd') { cx = 100 - s.end - w / 2; cy = 100 - s.bottom; }
    else { cx = s.ps !== null ? s.ps + w / 2 : 100 - s.pe - w / 2; cy = 100 - s.pb; }
    return { x: r1(cx), y: r1(cy), h: r1(h), w: w, dh: r1(dh), own: !!c, ownH: !!(c && c.h != null) };
  }
  function placedFor(n) { return st.placed.filter(function (x) { return x.slot + 1 === n; })[0] || null; }
  /* The twin of OccasionBanner::posCss(): what the shop prints for this layout. */
  function posCss() {
    var l = st.cfg.pos[st.cfg.layout] || {}, out = '';
    [['d', '@media (min-width:768px){'], ['m', '@media (max-width:767px){']].forEach(function (D) {
      var rules = '', rows = l[D[0]] || {};
      Object.keys(rows).sort().forEach(function (n) {
        var p = rows[n], sel = '.ohb-s' + (+n - 1), x = numf(p.x);
        rules += sel + '{left:' + x + '%;right:auto;bottom:' + numf(100 - p.y) + '%;width:0;display:flex;justify-content:center' + (p.h != null ? ';height:' + numf(p.h) + '%' : '') + '}'
          + '[dir=rtl] ' + sel + '{left:auto;right:' + x + '%}' + sel + ' img{flex:none}';
      });
      if (rules) out += D[1] + rules + '}';
    });
    /* While arranging, the products hold still so a handle sits on its product. */
    return out + (st.dnd.on ? '.ohb-p{animation:none!important;transform:rotate(var(--r,0deg))}' : '');
  }
  function framePos() {
    var css = posCss();
    document.querySelectorAll('[data-obx-frame]').forEach(function (f) { var d = f.contentDocument, el = d && d.getElementById('obx-pos'); if (el && el.textContent !== css) el.textContent = css; });
  }
  /* Every handle of one frame. A smaller box sits ABOVE a larger one, so every
     product can be grabbed even where the boxes overlap (the transparent
     corners of a tall bottle would otherwise cover a mini). */
  function boxes(dev) {
    var bs = st.placed.map(function (x) {
      var g = geom(dev, x), cx = st.lang === 'ar' ? 100 - g.x : g.x;
      return { n: x.slot + 1, g: g, l: cx - g.w / 2, r: cx + g.w / 2, t: g.y - g.h, a: g.w * g.h };
    });
    bs.slice().sort(function (a, b) { return b.a - a.a; }).forEach(function (b, i) { b.z = i + 1; });
    bs.forEach(function (b) { b.style = 'left:' + b.l.toFixed(2) + '%;top:' + b.t.toFixed(2) + '%;width:' + b.g.w.toFixed(2) + '%;height:' + b.g.h.toFixed(2) + '%;z-index:' + b.z; });
    return bs;
  }
  function hdLabel(dev, n, g) { return 'Place ' + n + ', ' + FR[dev].name.toLowerCase() + ': across ' + numf(g.x) + '%, down ' + numf(g.y) + '%, size ' + numf(g.h) + '%' + (g.ownH ? '' : ' (true size)') + '. Arrow keys move it, plus and minus resize it.'; }
  function isSel(dev, n) { return !!(st.dnd.sel && st.dnd.sel.dev === dev && st.dnd.sel.n === n); }
  /* The chosen place's two corner dots, above every box. */
  function gripHtml(dev, bs) {
    var b = bs.filter(function (q) { return isSel(dev, q.n); })[0];
    if (!b) return '';
    /* Kept inside the frame, so a product taller or wider than the banner can still be resized. */
    var top = Math.max(2, Math.min(98, b.t)), at = function (x) { return 'left:' + Math.max(2, Math.min(98, x)).toFixed(2) + '%;top:' + top.toFixed(2) + '%'; };
    return '<i class="obx-hk l" data-dnd="size" data-c="l" data-dev="' + dev + '" data-n="' + b.n + '" style="' + at(b.l) + '"></i>'
      + '<i class="obx-hk r" data-dnd="size" data-c="r" data-dev="' + dev + '" data-n="' + b.n + '" style="' + at(b.r) + '"></i>';
  }
  function ovHtml(dev) {
    if (!st.dnd.on || !can()) return '';
    var bs = boxes(dev), h = '<div class="obx-ov" data-dnd-ov="' + dev + '">';
    bs.forEach(function (b) {
      h += '<button type="button" class="obx-hd' + (isSel(dev, b.n) ? ' on' : '') + (b.g.own ? ' own' : '') + '" data-dnd="move" data-dev="' + dev + '" data-n="' + b.n + '" style="' + b.style + '" aria-label="' + esc(hdLabel(dev, b.n, b.g)) + '"><span class="obx-hn">' + b.n + '</span></button>';
    });
    return h + gripHtml(dev, bs) + '</div>';
  }
  /* Repaint the handles in place (focus and pointer capture survive); rebuild only when the set of places changed. */
  function drawOv() {
    document.querySelectorAll('[data-dnd-ov]').forEach(function (ov) {
      var dev = ov.getAttribute('data-dnd-ov'), hs = ov.querySelectorAll('.obx-hd'), bs = boxes(dev);
      var same = hs.length === bs.length && bs.every(function (b, i) { return +hs[i].getAttribute('data-n') === b.n; });
      if (!same) { ov.outerHTML = ovHtml(dev); return; }
      bs.forEach(function (b, i) {
        var el = hs[i];
        if (el.getAttribute('style') !== b.style) el.setAttribute('style', b.style);
        el.classList.toggle('on', isSel(dev, b.n)); el.classList.toggle('own', b.g.own);
        el.setAttribute('aria-label', hdLabel(dev, b.n, b.g));
      });
      ov.querySelectorAll('.obx-hk').forEach(function (k) { k.remove(); });
      ov.insertAdjacentHTML('beforeend', gripHtml(dev, bs));
    });
  }
  function panelHtml() {
    var S = st.dnd.sel, x = S && placedFor(S.n), L = st.cfg.layout, R = cat().pos_range;
    var h = '<p class="obx-sub"><b>Places, not products:</b> the products are picked at random on every visit, so whichever product lands in place 3 stands where you put place 3, at its true size unless you resized that place.</p>';
    h += '<p class="obx-sub">Drag a product to move it. Drag a corner dot to resize it (it keeps its shape). Arrow keys move the chosen one (Shift: 10 times further), + and − resize it, Ctrl+Z undoes. Computer and phone are arranged separately, and each layout keeps its own.</p>';
    if (x) {
      var g = geom(S.dev, x);
      h += '<div class="obx-row"><b style="font-size:13px">Place ' + S.n + ' · ' + FR[S.dev].name + '</b>'
        + '<label class="obx-col">Across <input class="obx-in sm" type="number" step="0.5" min="' + R.x[0] + '" max="' + R.x[1] + '" value="' + numf(g.x) + '" data-dn="x"> %</label>'
        + '<label class="obx-col">Down <input class="obx-in sm" type="number" step="0.5" min="' + R.y[0] + '" max="' + R.y[1] + '" value="' + numf(g.y) + '" data-dn="y"> %</label>'
        + '<label class="obx-col">Size <input class="obx-in sm" type="number" step="0.5" min="' + R.h[0] + '" max="' + R.h[1] + '" value="' + (g.ownH ? numf(g.h) : '') + '" placeholder="' + numf(g.dh) + '" data-dn="h" title="Height, % of the banner. Empty: the product\'s true size"> %</label>'
        + '<button class="obx-btn ghost" data-dnd-a="one"' + (g.own ? '' : ' disabled') + '>Reset this place</button></div>';
    } else {
      h += '<p class="obx-sub">Click a product in either preview to choose it.</p>';
    }
    h += '<div class="obx-row"><button class="obx-btn ghost" data-dnd-a="undo"' + (st.dnd.undo.length ? '' : ' disabled') + '>Undo</button>'
      + '<button class="obx-btn ghost" data-dnd-a="all"' + (st.cfg.pos[L] ? '' : ' disabled') + '>Reset layout ' + esc(L) + ' to the design</button></div>';
    return h;
  }
  function panelSync() {
    var p = document.querySelector('[data-dnd-panel]');
    if (!p) return;
    var f = document.activeElement && p.contains(document.activeElement) && document.activeElement.getAttribute('data-dn');
    if (!f) { p.innerHTML = panelHtml(); return; }
    /* The owner is typing in one box: refresh the others and the buttons, not the box under his cursor. */
    var S = st.dnd.sel, x = S && placedFor(S.n);
    if (!x) return;
    var g = geom(S.dev, x);
    p.querySelectorAll('[data-dn]').forEach(function (i) { var k = i.getAttribute('data-dn'); if (k !== f) i.value = k === 'h' ? (g.ownH ? numf(g.h) : '') : numf(g[k]); });
    var o = p.querySelector('[data-dnd-a="one"]'); if (o) o.disabled = !g.own;
    var u = p.querySelector('[data-dnd-a="undo"]'); if (u) u.disabled = !st.dnd.undo.length;
    var a = p.querySelector('[data-dnd-a="all"]'); if (a) a.disabled = !st.cfg.pos[st.cfg.layout];
  }
  function live() { framePos(); drawOv(); panelSync(); barSync(); }
  function pushUndo() { st.dnd.undo.push(JSON.stringify(st.cfg.pos)); if (st.dnd.undo.length > 60) st.dnd.undo.shift(); }
  function undo() { if (!st.dnd.undo.length) return; st.cfg.pos = JSON.parse(st.dnd.undo.pop()); live(); }

  function onDown(e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-dnd]') : null;
    if (!t || !st.dnd.on || !ours() || !can() || e.button > 0) return;
    var ov = t.closest('[data-dnd-ov]'), dev = t.getAttribute('data-dev'), n = +t.getAttribute('data-n'), x = placedFor(n);
    var hd = ov && ov.querySelector('.obx-hd[data-n="' + n + '"]');
    if (!x || !ov || !hd) return;
    e.preventDefault();
    var r = boxPx(dev);
    pushUndo();
    st.dnd.sel = { dev: dev, n: n };
    st.dnd.drag = { id: e.pointerId, ov: ov, kind: t.getAttribute('data-dnd'), c: t.getAttribute('data-c'), dev: dev, n: n, x0: e.clientX, y0: e.clientY, lx: e.clientX, ly: e.clientY, W: r[0], H: r[1], g: geom(dev, x), moved: false };
    try { ov.setPointerCapture(e.pointerId); } catch (err) {}
    try { hd.focus({ preventScroll: true }); } catch (err) {}
    drawOv(); panelSync();
  }
  function onMove(e) {
    var d = st.dnd.drag;
    if (!d || e.pointerId !== d.id) return;
    d.lx = e.clientX; d.ly = e.clientY;
    if (!st.dnd.raf) st.dnd.raf = window.requestAnimationFrame(dragStep);
  }
  /* The one move step: at most once a frame, whatever the pointer's rate. */
  function dragStep() {
    st.dnd.raf = 0;
    var d = st.dnd.drag;
    if (!d || !ours()) return;
    if (!d.moved && Math.abs(d.lx - d.x0) + Math.abs(d.ly - d.y0) < 2) return;
    var dx = (d.lx - d.x0) / d.W * 100, dy = (d.ly - d.y0) / d.H * 100, g = d.g;
    if (d.kind === 'move') put(d.dev, d.n, g.x + (st.lang === 'ar' ? -dx : dx), g.y + dy, g.ownH ? g.h : null);
    else {
      var s1 = (g.h - dy) / g.h, s2 = (g.w + 2 * (d.c === 'l' ? -dx : dx)) / g.w, sc = Math.abs(s1 - 1) > Math.abs(s2 - 1) ? s1 : s2;
      put(d.dev, d.n, g.x, g.y, g.h * sc);
    }
    d.moved = true;
    live();
  }
  function onUp(e) {
    var d = st.dnd.drag;
    if (!d || e.pointerId !== d.id) return;
    if (st.dnd.raf) { window.cancelAnimationFrame(st.dnd.raf); dragStep(); }
    st.dnd.drag = null;
    try { d.ov.releasePointerCapture(d.id); } catch (err) {}
    if (!d.moved) st.dnd.undo.pop();
    if (ours()) { panelSync(); barSync(); }
  }
  function onKey(e) {
    if (!st.dnd.on || !ours() || !can()) return;
    var t = e.target, tag = t && t.tagName;
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 'z' || e.key === 'Z')) {
      if ((tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') && !t.hasAttribute('data-dn')) return;
      e.preventDefault(); undo(); return;
    }
    var hd = t && t.closest ? t.closest('.obx-hd') : null;
    if (!hd || e.ctrlKey || e.metaKey || e.altKey) return;
    var dev = hd.getAttribute('data-dev'), n = +hd.getAttribute('data-n'), x = placedFor(n);
    if (!x) return;
    var g = geom(dev, x), step = e.shiftKey ? 5 : 0.5, mx = st.lang === 'ar' ? -1 : 1, k = e.key;
    var dx = k === 'ArrowLeft' ? -step * mx : k === 'ArrowRight' ? step * mx : 0, dy = k === 'ArrowUp' ? -step : k === 'ArrowDown' ? step : 0;
    var grow = k === '+' || k === '=' ? (e.shiftKey ? 1.2 : 1.05) : k === '-' || k === '_' ? 1 / (e.shiftKey ? 1.2 : 1.05) : 0;
    if (!dx && !dy && !grow) return;
    e.preventDefault();
    pushUndo();
    st.dnd.sel = { dev: dev, n: n };
    put(dev, n, g.x + dx, g.y + dy, grow ? g.h * grow : (g.ownH ? g.h : null));
    live();
  }
  function onFocus(e) {
    var hd = e.target && e.target.closest ? e.target.closest('.obx-hd') : null;
    if (!hd || !ours()) return;
    var dev = hd.getAttribute('data-dev'), n = +hd.getAttribute('data-n');
    if (st.dnd.sel && st.dnd.sel.dev === dev && st.dnd.sel.n === n) return;
    st.dnd.sel = { dev: dev, n: n }; drawOv(); panelSync();
  }
  /* The typed numbers: live as he types, one undo step per box he edits. */
  function onNum(e) {
    var el = e.target, k = el && el.getAttribute && el.getAttribute('data-dn');
    if (!k || !ours() || !st.dnd.sel || !can()) return;
    if (e.type === 'change') { st.dnd.typing = false; panelSync(); return; }
    var S = st.dnd.sel, x = placedFor(S.n);
    if (!x) return;
    var g = geom(S.dev, x), v = el.value.trim() === '' ? null : parseFloat(el.value);
    if (k !== 'h' && (v === null || !isFinite(v))) return;
    if (k === 'h' && v !== null && !isFinite(v)) return;
    if (!st.dnd.typing) { pushUndo(); st.dnd.typing = true; }
    put(S.dev, S.n, k === 'x' ? v : g.x, k === 'y' ? v : g.y, k === 'h' ? v : (g.ownH ? g.h : null));
    live();
  }
  function onArr(e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-dnd-a]') : null;
    if (!b || !ours() || !can()) return;
    var a = b.getAttribute('data-dnd-a'), S = st.dnd.sel;
    if (a === 'undo') return undo();
    pushUndo();
    if (a === 'one' && S) setP(S.dev, S.n, null);
    else if (a === 'all') delete st.cfg.pos[st.cfg.layout];
    live();
  }
  function barSync() {
    var bar = document.querySelector('.obx-bar .obx-sub'); if (bar) bar.innerHTML = dirty() ? '<b>Unsaved changes.</b> The shop shows them after Save.' : 'Saved.';
    var sb = document.querySelector('[data-a="save"]'); if (sb) sb.disabled = st.busy || !can() || !dirty();
    var rb = document.querySelector('[data-a="reset"]'); if (rb) rb.disabled = !dirty();
  }

  /* ------------------------------------------------------------------- render */
  function render() {
    var c = document.querySelector('#content');
    if (!c || !ours()) return;
    if (!st.data) { c.innerHTML = '<div data-screen="' + SCREEN + '" class="obx"><p class="obx-sub">' + (st.msg ? esc(st.msg) : 'Loading the occasion banner…') + '</p></div>'; return; }
    var C = cat(), cf = st.cfg, ro = can() ? '' : ' disabled';
    st.placed = place(pick());
    var h = '<div data-screen="' + SCREEN + '" class="obx">';
    h += '<div class="obx-bar"><span class="obx-sub">' + (dirty() ? '<b>Unsaved changes.</b> The shop shows them after Save.' : 'Saved. This is what the homepage shows.') + '</span>'
      + '<span class="obx-row"><button class="obx-btn ghost" data-a="reset"' + (dirty() ? '' : ' disabled') + '>Discard</button><button class="obx-btn" data-a="save"' + (st.busy || !can() || !dirty() ? ' disabled' : '') + '>' + (st.busy ? 'Saving…' : 'Save') + '</button></span></div>';
    if (st.msg) h += '<div class="obx-msg' + (st.bad ? ' bad' : '') + '">' + esc(st.msg) + '</div>';

    h += '<section class="obx-card"><h3 class="obx-h">Display</h3><p class="obx-sub">Where the banner goes on the homepage. A phone turned on its side gets the computer design.</p><div class="obx-row">';
    C.displays.forEach(function (d) { h += '<button class="obx-pill" aria-pressed="' + (cf.display === d.key) + '" data-a="display" data-v="' + d.key + '"' + ro + '>' + esc(d.name) + '</button>'; });
    h += '</div></section>';

    h += '<section class="obx-card"><h3 class="obx-h">Design and live preview</h3><p class="obx-sub">Pick a layout and an occasion. Picking an occasion fills its words and colours (you can change them below). The preview redraws as you type.</p>';
    h += '<b style="font-size:12.5px">Layout</b><div class="obx-list">';
    C.layouts.forEach(function (l) { h += '<button class="obx-opt" aria-pressed="' + (cf.layout === l.key) + '" data-a="layout" data-v="' + l.key + '"' + ro + '><b>' + esc(l.name) + '</b></button>'; });
    h += '</div><b style="font-size:12.5px">Occasion</b><div class="obx-list">';
    C.templates.forEach(function (t) { var k = t.colours; h += '<button class="obx-opt" aria-pressed="' + (cf.template === t.key) + '" data-a="template" data-v="' + t.key + '"' + ro + '><span class="obx-sw" style="background:linear-gradient(115deg,' + k.c1 + ',' + k.a1 + ' 45%,' + k.b2 + ');border-bottom:4px solid ' + k.acc + '"></span><b>' + esc(t.name) + '</b></button>'; });
    h += '</div><div class="obx-row"><span style="font-size:12.5px;font-weight:600">Preview in</span><button class="obx-pill" aria-pressed="' + (st.lang === 'en') + '" data-a="lang" data-v="en">English</button><button class="obx-pill" aria-pressed="' + (st.lang === 'ar') + '" data-a="lang" data-v="ar">العربية</button><button class="obx-pill" data-a="shuffle">Other products</button>' + (can() ? '<button class="obx-pill" aria-pressed="' + st.dnd.on + '" data-a="arrange" title="Move and resize the products in the previews, for computer and phone">Arrange products</button>' : '') + '</div>';
    h += '<div class="obx-pv"><div class="obx-fr"><span>Computer · 1280 × 600</span><div class="obx-box obx-d"><iframe data-obx-frame sandbox="allow-same-origin" title="Computer preview" tabindex="-1"></iframe>' + ovHtml('d') + '</div></div>'
      + '<div class="obx-fr"><span>Phone · 390 × 500</span><div class="obx-box obx-m"><iframe data-obx-frame sandbox="allow-same-origin" title="Phone preview" tabindex="-1"></iframe>' + ovHtml('m') + '</div></div></div>';
    if (st.dnd.on && can()) h += '<div class="obx-arr" data-dnd-panel>' + panelHtml() + '</div>';
    if (!ready().length) h += '<p class="obx-sub">No product has a cut-out yet, so the preview shows the words only. Add products below and press <b>Prepare cut-outs</b>.</p>';
    h += '</section>';

    h += '<section class="obx-card"><h3 class="obx-h">Words</h3><p class="obx-sub">Leave a box empty to leave that line out. The body line shows on computers only.</p><div class="obx-g2">';
    ['en', 'ar'].forEach(function (lang) {
      h += '<div class="obx-f" style="gap:8px"' + (lang === 'ar' ? ' dir="rtl"' : '') + '><b style="font-size:13px">' + (lang === 'en' ? 'English' : 'العربية — Arabic') + '</b>';
      C.texts.forEach(function (t) {
        h += '<label class="obx-f"><span>' + esc(t.label) + '</span>' + (t.key === 'line'
          ? '<textarea class="obx-in" rows="2" maxlength="' + t.max + '" data-k="' + lang + '.' + t.key + '"' + ro + '>' + esc(cf[lang][t.key]) + '</textarea>'
          : '<input class="obx-in" maxlength="' + t.max + '" value="' + esc(cf[lang][t.key]) + '" data-k="' + lang + '.' + t.key + '"' + ro + '>') + '</label>';
      });
      h += '</div>';
    });
    h += '</div><label class="obx-f"><span>Button link</span><input class="obx-in" value="' + esc(cf.link) + '" data-k="link" placeholder="/super-sale/"' + ro + '><small class="obx-sub">A page on this shop (/super-sale/) or an https:// address. Anything else is refused and the button is not shown.</small></label></section>';

    h += '<section class="obx-card"><h3 class="obx-h">Colours and movement</h3><p class="obx-sub">The background cross-fades between three colour sets every 18 seconds. Each set: base, glow and light.</p><div class="obx-cols">';
    [['ink', 'Words'], ['acc', 'Accent'], ['btn', 'Button'], ['btnInk', 'Button words'], ['a1', 'Set 1 base'], ['b1', 'Set 1 glow'], ['c1', 'Set 1 light'], ['a2', 'Set 2 base'], ['b2', 'Set 2 glow'], ['c2', 'Set 2 light'], ['a3', 'Set 3 base'], ['b3', 'Set 3 glow'], ['c3', 'Set 3 light']].forEach(function (x) {
      h += '<label class="obx-col"><input type="color" value="' + esc(cf.colours[x[0]]) + '" data-k="colours.' + x[0] + '"' + ro + '>' + esc(x[1]) + '</label>';
    });
    h += '</div><div class="obx-row"><label class="obx-col"><input type="checkbox" data-k="animate"' + (cf.animate ? ' checked' : '') + ro + '> Animation (colours, floating products). Shoppers who ask their phone for less motion never see it move.</label></div></section>';

    h += '<section class="obx-card"><h3 class="obx-h">Products</h3><p class="obx-sub">The banner shows <b>' + cf.count + '</b> products picked at random on every page view from the ready ones below' + (cf.per_brand ? ', one per brand where it can' : '') + '. Each is drawn at its real height: the tallest product in this list is the tallest on the banner and the rest in proportion, so a mini stays smaller than a stick.</p>';
    h += '<div class="obx-row"><label class="obx-col">Show <select class="obx-in sm" data-k="count"' + ro + '>' + [1, 2, 3, 4, 5].map(function (n) { return '<option' + (n === cf.count ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select></label>'
      + '<label class="obx-col"><input type="checkbox" data-k="per_brand"' + (cf.per_brand ? ' checked' : '') + ro + '> One per brand</label></div>';
    h += '<div class="obx-row"><input class="obx-in" style="max-width:340px" type="search" placeholder="Search products to add (2+ letters)…" value="' + esc(st.q) + '" data-k="q"' + ro + '>'
      + '<button class="obx-btn ghost" data-a="suggest"' + ro + '>Add best sellers, one per brand</button></div>';
    if (st.res.length) {
      h += '<div class="obx-res">';
      st.res.forEach(function (p) { var inn = cf.pool.some(function (x) { return x.id === p.id; }); h += item(p, inn ? '<span class="obx-sub">In the list</span>' : '<button class="obx-btn ghost" data-a="add" data-id="' + p.id + '"' + ro + '>Add</button>'); });
      h += '</div>';
    }
    var miss = cf.pool.filter(function (p) { return !p.cut_url; }).length;
    h += '<div class="obx-row"><b style="font-size:13px">' + cf.pool.length + ' in the list · ' + (cf.pool.length - miss) + ' ready · ' + miss + ' missing</b>'
      + (st.prep ? '<span class="obx-sub">Preparing ' + Math.min(st.prep.at + 1, st.prep.total) + ' of ' + st.prep.total + '…</span><button class="obx-btn ghost" data-a="stop">Stop</button>'
        : '<button class="obx-btn" data-a="prep"' + (miss && can() ? '' : ' disabled') + '>Prepare cut-outs' + (miss ? ' (' + miss + ')' : '') + '</button>') + '</div>';
    if (st.prep && st.prep.log.length) h += '<p class="obx-sub">' + st.prep.log.map(esc).join('<br>') + '</p>';
    h += '<div class="obx-pool">';
    cf.pool.forEach(function (p, i) {
      h += item(p, '<label>Real height <input class="obx-in sm" type="number" min="' + C.cm[0] + '" max="' + C.cm[1] + '" step="0.5" value="' + (p.cm || '') + '" placeholder="cm" data-k="pool.' + i + '.cm"' + ro + '> cm</label>'
        + '<label><input type="checkbox" data-k="pool.' + i + '.white"' + (p.white ? ' checked' : '') + ro + '> White product</label>'
        + (p.cut_url ? '<span class="obx-ok">' + (p.cut_own ? 'Hand-made cut-out' : 'Cut-out ready') + '</span><button class="obx-btn ghost" data-a="remake" data-i="' + i + '"' + (st.prep ? ' disabled' : ro) + (p.cut_own ? ' title="Puts the hand-made cut-out back; Use automatic replaces it"' : '') + '>Remake</button>' : '<span class="obx-no">Cut-out missing</span>')
        + '<button class="obx-btn ghost" data-a="upl" data-i="' + i + '" title="Your own cut-out from the Media Library: a PNG or WebP with a transparent background, up to 4 MB"' + (st.prep ? ' disabled' : ro) + '>Upload cut-out</button>'
        + (p.cut_own ? '<button class="obx-btn ghost" data-a="auto" data-i="' + i + '"' + (st.prep ? ' disabled' : ro) + '>Use automatic</button>' : '')
        + '<button class="obx-btn ghost" data-a="remove" data-i="' + i + '"' + ro + '>Remove</button>');
    });
    h += '</div></section></div>';

    var keep = document.activeElement && document.activeElement.getAttribute && document.activeElement.getAttribute('data-k');
    c.innerHTML = h;
    c.querySelectorAll('[data-obx-frame]').forEach(function (f) { f.srcdoc = frameDoc(); f.onload = later; });
    if (keep) { var g = c.querySelector('[data-k="' + keep + '"]'); if (g) { g.focus(); if (g.setSelectionRange && g.type !== 'number' && g.type !== 'color' && g.type !== 'checkbox') { var l = g.value.length; try { g.setSelectionRange(l, l); } catch (e) {} } } }
  }
  function item(p, ctl) {
    var u = p.cut_url ? p.cut_url.src : p.image;
    return '<div class="obx-it"><img src="' + esc(u) + '" alt="" loading="lazy" decoding="async"><div style="min-width:0"><div class="nm">' + esc(p.name) + '</div><div class="br">' + esc(p.brand || '—') + '</div></div><div class="obx-ctl">' + ctl + '</div></div>';
  }

  /* ------------------------------------------------------------------ actions */
  function say(m, bad) { st.msg = m; st.bad = !!bad; }
  function setTemplate(k) {
    var t = cat().templates.filter(function (x) { return x.key === k; })[0];
    if (!t) return;
    st.cfg.template = k; st.cfg.en = clone(t.en); st.cfg.ar = clone(t.ar); st.cfg.colours = clone(t.colours);
  }
  function addProduct(p) {
    if (st.cfg.pool.some(function (x) { return x.id === p.id; })) return false;
    if (st.cfg.pool.length >= cat().pool_max) { say('The list holds at most ' + cat().pool_max + ' products.', true); return false; }
    st.cfg.pool.push({ id: p.id, name: p.name, brand: p.brand, brand_id: p.brand_id, image: p.image, cm: 0, white: false, cut: p.cut, cut_url: p.cut_url });
    return true;
  }
  function save() {
    st.busy = true; say(''); render();
    api('POST', '', { config: slim(st.cfg) }).then(function (j) {
      st.busy = false; st.cfg = normPos(j.config); st.full = JSON.stringify(st.cfg); st.saved = JSON.stringify(slim(st.cfg)); say('Saved. The homepage shows it now.');
      try { window.toast('Occasion banner saved'); } catch (e) {}
      if (ours()) { render(); }
    }).catch(function (e) { st.busy = false; say('Not saved: ' + e.message, true); if (ours()) render(); });
  }
  /* One product at a time, in order, until done or Stop. No timer. */
  function prepare(list) {
    st.prep = { done: 0, at: 0, total: list.length, stop: false, log: [] }; render();
    var step = function (i) {
      if (!st.prep || st.prep.stop || i >= list.length) {
        var p = st.prep; st.prep = null;
        say(p ? (p.stop ? 'Stopped. ' : '') + p.done + ' of ' + p.total + ' cut-outs ready' + (p.log.length ? ' (' + p.log.length + ' could not be cut out: ' + p.log.join('; ') + ')' : '') + '. Save to put products you just added on the shop.' : '', p && p.log.length > 0);
        if (ours()) render();
        return;
      }
      var row = list[i];
      api('POST', '/cutout', { id: row.id, white: !!row.white, remake: !!row.remake }).then(function (j) {
        st.cfg.pool.forEach(function (x) { if (x.id === row.id) { x.cut = j.product.cut; x.cut_url = j.product.cut_url; } });
        st.prep.done++;
      }).catch(function (e) { if (st.prep) st.prep.log.push(row.name + ': ' + e.message); })
        .then(function () { if (st.prep) st.prep.at = i + 1; if (ours()) render(); step(i + 1); });
    };
    step(0);
  }

  /* The owner's own cut-out (a Media Library file) or back to the automatic one (null): one request, on a click. */
  // The owner's own cut-out comes from the shared Media Library (the admin's
  // one image upload path): the server turns the picked file into the cut-out.
  function pickCut(row) {
    if (st.prep || !can() || typeof window.kbbPickMedia !== 'function') return;
    window.kbbPickMedia({ title: 'Choose a cut-out (PNG or WebP with a transparent background)', folder: 'cutouts', okLabel: 'Use as cut-out',
      onPick: function (urls) { if (urls && urls[0]) ownCut(row, String(urls[0])); } });
  }
  function ownCut(row, url) {
    if (st.prep || !can()) return;
    st.prep = { done: 0, at: 0, total: 1, stop: false, log: [] }; say(''); render();
    var req;
    if (url) {
      req = api('POST', '/cutout/from-media', { id: row.id, url: url });
    } else {
      req = api('POST', '/cutout/automatic', { id: row.id, white: !!row.white });
    }
    req.then(function (j) {
      st.cfg.pool.forEach(function (x) { if (x.id === row.id) { x.cut = j.product.cut; x.cut_url = j.product.cut_url; x.cut_own = !!j.product.cut_own; } });
      say(url ? 'Your cut-out is in use for ' + row.name + '.' : 'Automatic cut-out made for ' + row.name + '.');
    }).catch(function (e) { say((url ? 'Not used: ' : 'Not cut out: ') + e.message, true); })
      .then(function () { st.prep = null; if (ours()) render(); });
  }

  function onClick(e) {
    if (!ours()) return;
    var b = e.target.closest('[data-a]');
    if (!b || !document.querySelector('#content').contains(b)) return;
    var a = b.getAttribute('data-a'), v = b.getAttribute('data-v');
    if (a === 'save') return save();
    if (a === 'reset') { st.cfg = normPos(JSON.parse(st.full)); st.dnd.undo = []; say(''); return render(); }
    if (a === 'arrange') { st.dnd.on = !st.dnd.on && can(); st.dnd.drag = null; return render(); }
    if (a === 'display') st.cfg.display = v;
    else if (a === 'layout') st.cfg.layout = v;
    else if (a === 'template') setTemplate(v);
    else if (a === 'lang') st.lang = v;
    else if (a === 'shuffle') st.rot++;
    else if (a === 'add') { var p = st.res.filter(function (x) { return String(x.id) === b.getAttribute('data-id'); })[0]; if (p) addProduct(p); }
    else if (a === 'remove') st.cfg.pool.splice(+b.getAttribute('data-i'), 1);
    else if (a === 'upl') { var row = st.cfg && st.cfg.pool[+b.getAttribute('data-i')]; if (row) pickCut(row); return; }
    else if (a === 'auto') { var ra = st.cfg.pool[+b.getAttribute('data-i')]; if (ra) return ownCut(ra, null); return; }
    else if (a === 'remake') { var r = st.cfg.pool[+b.getAttribute('data-i')]; if (r) return prepare([{ id: r.id, name: r.name, white: r.white, remake: true }]); }
    else if (a === 'prep') return prepare(st.cfg.pool.filter(function (x) { return !x.cut_url; }).map(function (x) { return { id: x.id, name: x.name, white: x.white }; }));
    else if (a === 'stop') { if (st.prep) st.prep.stop = true; return; }
    else if (a === 'suggest') {
      b.disabled = true;
      api('GET', '/suggest').then(function (j) { var n = 0; j.products.forEach(function (p) { if (addProduct(p)) n++; }); say(n + ' best sellers added, one per brand. Set each one\'s real height, then Prepare cut-outs and Save.'); if (ours()) render(); })
        .catch(function (e) { say(e.message, true); if (ours()) render(); });
      return;
    } else return;
    render();
  }
  function onField(e) {
    if (!ours() || !st.cfg) return;
    var el = e.target, k = el.getAttribute && el.getAttribute('data-k');
    if (!k || !document.querySelector('#content').contains(el)) return;
    var v = el.type === 'checkbox' ? el.checked : el.value, parts = k.split('.');
    if (k === 'q') {
      st.q = v; clearTimeout(st.qt);
      if (st.qctl) { try { st.qctl.abort(); } catch (x) {} st.qctl = null; }
      if (v.trim().length < 2) { if (st.res.length) { st.res = []; render(); } return; }
      st.qt = setTimeout(function () {
        st.qt = 0; st.qctl = window.AbortController ? new AbortController() : null;
        api('GET', '/products?q=' + encodeURIComponent(v.trim()), undefined, st.qctl && st.qctl.signal).then(function (j) { st.res = j.products; if (ours()) render(); }).catch(function () {});
      }, 300);
      return;
    }
    if (parts[0] === 'en' || parts[0] === 'ar' || parts[0] === 'colours') st.cfg[parts[0]][parts[1]] = v;
    else if (parts[0] === 'pool') { var it = st.cfg.pool[+parts[1]]; if (!it) return; it[parts[2]] = parts[2] === 'cm' ? (parseFloat(v) || 0) : !!v; if (parts[2] === 'cm' && e.type !== 'change') { later(); return; } }
    else if (k === 'count') st.cfg.count = +v;
    else st.cfg[k] = v;
    /* Typing repaints only the preview (next frame); a change of a box redraws the bar too. */
    if (e.type === 'input' && el.tagName !== 'SELECT' && el.type !== 'checkbox') { later(); barSync(); return; }
    if (e.type === 'change') render();
  }
  document.addEventListener('click', onClick);
  document.addEventListener('input', onField);
  document.addEventListener('change', onField);
  document.addEventListener('pointerdown', onDown);
  document.addEventListener('pointermove', onMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onUp);
  document.addEventListener('keydown', onKey);
  document.addEventListener('focusin', onFocus);
  document.addEventListener('input', onNum);
  document.addEventListener('change', onNum);
  document.addEventListener('click', onArr);

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { clearTimeout(st.qt); if (st.prep) st.prep.stop = true; st.dnd.drag = null; if (st.dnd.raf) { window.cancelAnimationFrame(st.dnd.raf); st.dnd.raf = 0; } return previousGo.apply(this, arguments); }
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-go') === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = TITLE;
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    var c = document.querySelector('#content');
    if (c) c.textContent = '';
    st.data = null; st.cfg = null; st.res = []; st.q = ''; st.dnd.sel = null; st.dnd.undo = []; say('');
    render();
    api('GET', '').then(function (j) {
      st.data = j; st.cfg = normPos(j.config); st.full = JSON.stringify(st.cfg); st.saved = JSON.stringify(slim(j.config));
      if (ours()) render();
    }).catch(function (e) { say('The occasion banner could not be loaded: ' + e.message, true); if (ours()) render(); });
    return undefined;
  };

  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Occasion banner',
      icon: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 15l3-3 2 2 3-4 2 5"/><circle cx="8" cy="9" r="1.3"/>',
      group: GROUP,
      after: ['hpcontent', 'homepage']
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry); else addNavEntry();
})();
</script>
@endverbatim
