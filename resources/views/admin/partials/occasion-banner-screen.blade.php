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
.obx-bar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface)}
@media (max-width:560px){.obx-it{grid-template-columns:44px minmax(0,1fr)}.obx-ctl{grid-column:1 / -1;justify-content:flex-start}}
</style>
@verbatim
<script>
(function () {
  'use strict';
  var SCREEN = 'occbanner', GROUP = 'Appearance', TITLE = 'Occasion banner';
  var st = { data: null, cfg: null, saved: '', busy: false, msg: '', bad: false, lang: 'en', rot: 0,
    q: '', res: [], qt: 0, qctl: null, prep: null, raf: 0 };

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
    return o;
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
    place(pick()).forEach(function (x) {
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
    return '<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0}body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}</style><style>' + cat().css.replace(/<\//g, '<\\/') + '</style></head><body></body></html>';
  }
  function paintPreview() {
    st.raf = 0;
    if (!ours() || !st.cfg) return;
    document.querySelectorAll('[data-obx-frame]').forEach(function (f) {
      var d = f.contentDocument;
      if (!d || !d.body) return;
      d.documentElement.setAttribute('dir', st.lang === 'ar' ? 'rtl' : 'ltr');
      d.body.innerHTML = banner(st.lang);
    });
  }
  function later() { if (!st.raf) st.raf = window.requestAnimationFrame(paintPreview); }

  /* ------------------------------------------------------------------- render */
  function render() {
    var c = document.querySelector('#content');
    if (!c || !ours()) return;
    if (!st.data) { c.innerHTML = '<div data-screen="' + SCREEN + '" class="obx"><p class="obx-sub">' + (st.msg ? esc(st.msg) : 'Loading the occasion banner…') + '</p></div>'; return; }
    var C = cat(), cf = st.cfg, ro = can() ? '' : ' disabled';
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
    h += '</div><div class="obx-row"><span style="font-size:12.5px;font-weight:600">Preview in</span><button class="obx-pill" aria-pressed="' + (st.lang === 'en') + '" data-a="lang" data-v="en">English</button><button class="obx-pill" aria-pressed="' + (st.lang === 'ar') + '" data-a="lang" data-v="ar">العربية</button><button class="obx-pill" data-a="shuffle">Other products</button></div>';
    h += '<div class="obx-pv"><div class="obx-fr"><span>Computer · 1280 × 600</span><div class="obx-box obx-d"><iframe data-obx-frame sandbox="allow-same-origin" title="Computer preview" tabindex="-1"></iframe></div></div>'
      + '<div class="obx-fr"><span>Phone · 390 × 500</span><div class="obx-box obx-m"><iframe data-obx-frame sandbox="allow-same-origin" title="Phone preview" tabindex="-1"></iframe></div></div></div>';
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
        + '<button class="obx-btn ghost" data-a="upl" data-i="' + i + '" title="Your own cut-out: a PNG or WebP with a transparent background, up to 4 MB"' + (st.prep ? ' disabled' : ro) + '>Upload cut-out</button><input type="file" accept="image/png,image/webp" hidden data-obx-file="' + i + '">'
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
      st.busy = false; st.cfg = j.config; st.full = JSON.stringify(j.config); st.saved = JSON.stringify(slim(st.cfg)); say('Saved. The homepage shows it now.');
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

  /* The owner's own cut-out (file) or back to the automatic one (null): one request, on a click. */
  function ownCut(row, file) {
    if (st.prep || !can()) return;
    if (file && file.size > 4 * 1024 * 1024) { say('That file is larger than 4 MB. Save it smaller and try again.', true); return render(); }
    st.prep = { done: 0, at: 0, total: 1, stop: false, log: [] }; say(''); render();
    var req;
    if (file) {
      var fd = new FormData(); fd.append('id', String(row.id)); fd.append('file', file);
      req = fetch(root() + '/admin-api/occasion-banner/cutout/upload', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } }).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok || j.ok === false) {
            var m = j.message || '';
            if (r.status === 403) m = 'Your role cannot change this.';
            if (r.status === 404 && !m) m = 'The upload endpoint is not in the compiled route table yet. Apply the package and reload.';
            if (r.status === 422 && j.errors) m = j.errors[Object.keys(j.errors)[0]][0];
            throw new Error(m || ('The server answered ' + r.status + '.'));
          }
          return j;
        });
      });
    } else {
      req = api('POST', '/cutout/automatic', { id: row.id, white: !!row.white });
    }
    req.then(function (j) {
      st.cfg.pool.forEach(function (x) { if (x.id === row.id) { x.cut = j.product.cut; x.cut_url = j.product.cut_url; x.cut_own = !!j.product.cut_own; } });
      say(file ? 'Your cut-out is in use for ' + row.name + '.' : 'Automatic cut-out made for ' + row.name + '.');
    }).catch(function (e) { say((file ? 'Not uploaded: ' : 'Not cut out: ') + e.message, true); })
      .then(function () { st.prep = null; if (ours()) render(); });
  }

  function onClick(e) {
    if (!ours()) return;
    var b = e.target.closest('[data-a]');
    if (!b || !document.querySelector('#content').contains(b)) return;
    var a = b.getAttribute('data-a'), v = b.getAttribute('data-v');
    if (a === 'save') return save();
    if (a === 'reset') { st.cfg = JSON.parse(st.full); say(''); return render(); }
    if (a === 'display') st.cfg.display = v;
    else if (a === 'layout') st.cfg.layout = v;
    else if (a === 'template') setTemplate(v);
    else if (a === 'lang') st.lang = v;
    else if (a === 'shuffle') st.rot++;
    else if (a === 'add') { var p = st.res.filter(function (x) { return String(x.id) === b.getAttribute('data-id'); })[0]; if (p) addProduct(p); }
    else if (a === 'remove') st.cfg.pool.splice(+b.getAttribute('data-i'), 1);
    else if (a === 'upl') { var fi = b.parentNode.querySelector('[data-obx-file="' + b.getAttribute('data-i') + '"]'); if (fi) fi.click(); return; }
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
    if (e.type === 'change' && el.hasAttribute && el.hasAttribute('data-obx-file') && document.querySelector('#content').contains(el)) {
      var row = st.cfg.pool[+el.getAttribute('data-obx-file')];
      if (row && el.files && el.files[0]) ownCut(row, el.files[0]);
      return;
    }
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
    if (e.type === 'input' && el.tagName !== 'SELECT' && el.type !== 'checkbox') { later(); var bar = document.querySelector('.obx-bar .obx-sub'); if (bar) bar.innerHTML = dirty() ? '<b>Unsaved changes.</b> The shop shows them after Save.' : 'Saved.'; var sb = document.querySelector('[data-a="save"]'); if (sb) sb.disabled = st.busy || !can() || !dirty(); var rb = document.querySelector('[data-a="reset"]'); if (rb) rb.disabled = !dirty(); return; }
    if (e.type === 'change') render();
  }
  document.addEventListener('click', onClick);
  document.addEventListener('input', onField);
  document.addEventListener('change', onField);

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { clearTimeout(st.qt); if (st.prep) st.prep.stop = true; return previousGo.apply(this, arguments); }
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
    st.data = null; st.cfg = null; st.res = []; st.q = ''; say('');
    render();
    api('GET', '').then(function (j) {
      st.data = j; st.cfg = j.config; st.full = JSON.stringify(j.config); st.saved = JSON.stringify(slim(j.config));
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
