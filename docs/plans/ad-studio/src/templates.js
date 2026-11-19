/* KBB Ad Studio -- templates.
 *
 * A template is DATA: a layout family + a palette + texts + options + a product pool.
 * A family is a function (format, texts, palette, options) -> {bg, els, slots}; it is called once per
 * format (post 100x125, square 100x100, story 100x177.78, all in canvas-width units = cqw) and must
 * return the same element ids in the same order every time, so one design has ONE list of elements
 * (text, colours, fonts shared by the three sizes) and a position per size.
 *
 * Copy is honest by construction: offers, dates and codes are placeholders the owner edits; no medical,
 * "cure" or whitening claims; the review template carries a clearly marked placeholder, not a rating.
 */
var KBB_T = (function () {
  'use strict';
  var FH = { post: 125, square: 100, story: 177.78 };
  function v(f, a, b, c) { return f === 'post' ? a : f === 'square' ? b : c; }
  function A(hex, a) { return hex.slice(0, 7) + a; }               // hex + 2-digit alpha
  function lum(hex) { var n = parseInt(hex.slice(1, 7), 16); return ((n >> 16 & 255) * 299 + (n >> 8 & 255) * 587 + (n & 255) * 114) / 255000; }
  function readable(bg) { return lum(bg) > .62 ? '#161616' : '#FFFFFF'; }
  // text that sits ON the card / ticket / tile: the palette's ink when it reads there, otherwise black or white
  function onCard(P) {
    if (P.cink) return Object.assign({}, P, { ink: P.cink, sub: P.csub || P.cink });
    if (Math.abs(lum(P.card) - lum(P.ink)) > .45) return P;
    var dark = lum(P.card) > .5; return Object.assign({}, P, { ink: dark ? '#161616' : '#FFFFFF', sub: dark ? '#4E4A4D' : '#D8D4D7' });
  }
  function G(x, y, w, h, r, k) { return { x: x, y: y, w: w, h: h || 0, r: r || 0, k: k || 1 }; }

  // ── element constructors (props shared by the 3 sizes; g = this size's geometry) ──
  function tx(id, txt, o, g) { return Object.assign({ id: id, t: 'text', txt: txt, fn: 'poppins', fw: 700, fs: 4, col: '#111111', al: 'c', lh: 115, nw: true }, o || {}, { g: g }); }
  function btn(id, txt, o, g) { return Object.assign({ id: id, t: 'btn', txt: txt, fn: 'poppins', fw: 700, fs: 3.8, col: '#FFFFFF', al: 'c', lh: 110, sh: 'pill', fill: '#111111', shd: 35, cr: 'acc', name: 'Button' }, o || {}, { g: g }); }
  function shp(id, sh, o, g) { return Object.assign({ id: id, t: 'shape', sh: sh, fill: '#FFFFFF', op: 100 }, o || {}, { g: g }); }
  function ico(id, ic, o, g) { return Object.assign({ id: id, t: 'icon', ic: ic, col: '#FFFFFF' }, o || {}, { g: g }); }
  function pay(id, g) { return { id: id, t: 'pay', pay: 'both', name: 'Tabby / Tamara', g: g }; }
  function logo(id, o, g) { return Object.assign({ id: id, t: 'logo', txt: 'K-Beauty Bliss', fn: 'playfair', fw: 700, fs: 3, col: '#111111', al: 'c', name: 'Logo' }, o || {}, { g: g }); }
  function prods() { return { id: 'prods', t: 'prods', name: 'Products', g: G(0, 0, 100, 0) }; }

  // estimated height of a block (text: lines x size x line-height; boxes: their own height)
  function est(e) {
    if (e.g.h) return e.g.h;
    var lines = String(e.txt || '').split('\n').length, k = e.g.k || 1;
    return lines * e.fs * k * (e.lh || 115) / 100 + (e.sh && e.t === 'text' ? 2 * (e.pd || 0) * k : 0);
  }
  // put blocks one under another inside [y0, y1], centred vertically (or from the top), with gaps
  // ...and when they do not fit, the gaps close first, then every block shrinks by the same factor:
  // a template never runs off its canvas whatever the size
  function shrink(b, s) {
    b.g.k = +((b.g.k || 1) * s).toFixed(4);
    if (b.g.h) { var cx = b.g.x + b.g.w / 2; b.g.h *= s; if (b.t === 'pay') { b.g.w *= s; b.g.x = cx - b.g.w / 2; } }
  }
  function column(blocks, y0, y1, gap, top) {
    blocks = blocks.filter(Boolean);
    var n = blocks.length, hs = blocks.reduce(function (s, b) { return s + est(b); }, 0), avail = y1 - y0;
    if (hs + gap * (n - 1) > avail) gap = Math.max(gap * .5, (avail - hs) / Math.max(1, n - 1));
    if (hs + gap * (n - 1) > avail) { var s = (avail - gap * (n - 1)) / hs; blocks.forEach(function (b) { shrink(b, s); }); }
    var tot = blocks.reduce(function (s, b) { return s + est(b) + (b.gapB || 0); }, 0) + gap * (blocks.length - 1);
    var y = top ? y0 : y0 + Math.max(0, (y1 - y0 - tot) / 2);
    blocks.forEach(function (b) { b.g.y = +(y).toFixed(2); y += est(b) + gap + (b.gapB || 0); });
    return y - gap;
  }
  function textBlock(T, P, O, f, k, x, w, al) {
    // the family-neutral stack: kicker, headline, offer, sub, arabic, button, pay, trust
    al = al || 'c'; var out = [], hf = O.hf || 'bebas', isB = hf === 'bebas';
    if (T.kick) out.push(tx('kick', T.kick, { fn: O.kf || 'montserrat', fw: 800, fs: 2.7, ls: 18, col: O.kc || P.acc, tr: 'acc', al: al, name: 'Kicker', sh: O.kchip ? 'pill' : undefined, fill: O.kchip ? A(P.acc, '1F') : undefined, pd: O.kchip ? 0.9 : undefined }, G(x, 0, w, 0, 0, k)));
    if (T.h1) out.push(tx('h1', T.h1, { fn: hf, fw: isB ? 400 : (O.hw || 900), fs: O.hs || (isB ? 15 : 10.5), lh: isB ? 92 : 102, ls: isB ? 2 : -1, col: P.ink, al: al, up: !!O.hup, fi: !!O.hi, name: 'Headline' }, G(x, 0, w, 0, 0, k)));
    if (T.h2) out.push(tx('h2', T.h2, { fn: O.of || 'poppins', fw: 800, fs: O.os || 5, lh: 110, col: O.oc || P.acc, tr: 'acc', al: al, name: 'Offer' }, G(x, 0, w, 0, 0, k)));
    if (T.sub) out.push(tx('sub', T.sub, { fn: 'poppins', fw: 500, fs: 3, lh: 135, col: P.sub, al: al, name: 'Supporting line' }, G(x, 0, w, 0, 0, k)));
    if (T.ar) out.push(tx('ar', T.ar, { fn: 'cairo', fw: 700, fs: 3.1, lh: 140, col: P.acc, tr: 'acc', al: al, sh: 'pill', fill: A(P.acc, '17'), pd: 0.7, name: 'Arabic line' }, G(x, 0, w, 0, 0, k)));
    return out;
  }
  function ctaBlock(T, P, O, k, x, w, h, pay0, ptx) {
    var out = [];
    if (T.cta) out.push(btn('cta', T.cta, { fill: P.acc, fill2: P.acc2 || '', gang: 180, col: P.btnTxt || '#FFFFFF', sh: O.btn || 'pill', rad: 2, fs: 3.8 }, G(x, 0, w, h, 0, k)));
    if (pay0) out.push(pay('pay', G(50 - 19 * k, 0, 38 * k, 3.4 * k)));
    if (T.trust) out.push(tx('trust', T.trust, { fn: 'poppins', fw: 600, fs: 2.4, lh: 130, col: ptx || P.sub, name: 'Urgency / trust line' }, G(6, 0, 88, 0, 0, k)));
    return out;
  }
  function ribbon(T, P) {
    if (!T.rib) return null;
    return tx('rib', T.rib, { fn: 'poppins', fw: 800, fs: 2.4, ls: 4, col: P.ribTxt || '#FFFFFF', sh: 'rect', fill: P.rib || P.acc, cr: 'acc', name: 'Corner ribbon', box: 1, shd: 30 }, G(-20, 13.9, 74, 6.2, -45));
  }
  function bgOf(P, O) {
    return { k: O.bgk || 'radial', c1: P.bg1, c2: P.bg2, ang: O.bga || 160, pat: O.pat || 'none', pc: P.pc || P.acc, po: O.po == null ? 14 : O.po, img: '', ov: 0 };
  }
  function slot(cx, by, h, mw, r) { return { cx: cx, by: by, h: h, mw: mw, r: r || 0 }; }
  function decoIcon(id, ic, col, g, op) { return ico(id, ic, { col: col, op: op == null ? 100 : op, name: 'Decoration' }, g); }

  var FAM = {};

  // 1 ── CARD: a centred card, products leaning on its sides, corner ribbon (the Black Friday look)
  FAM.card = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .86, 1.14), els = [];
    var c = v(f, { x: 14, y: 22, w: 72, h: 84 }, { x: 21, y: 13, w: 58, h: 74 }, { x: 9, y: 54, w: 82, h: 98 });
    els.push(shp('card', O.cardSh || 'rect', { fill: P.card, fill2: P.card2 || '', gang: 180, rad: 4, shd: 45, name: 'Card', cr: 'card' }, G(c.x, c.y, c.w, c.h)));
    if (O.icon) els.push(decoIcon('deco', O.icon, P.acc, v(f, G(40, 4, 20, 17), G(44, 0.5, 12, 11), G(41, 158, 18, 15)), 100));
    els.push(decoIcon('spk1', 'sparkle', P.spark || P.acc, v(f, G(88, 8, 5, 5), G(88, 4, 4, 4), G(86, 12, 6, 6)), 80));
    els.push(decoIcon('spk2', 'sparkle', P.spark || P.acc, v(f, G(6, 112, 4, 4), G(7, 90, 3.5, 3.5), G(8, 162, 5, 5)), 70));
    var PC = onCard(P), tb = textBlock(T, PC, O, f, k, c.x + 3, c.w - 6), cb = ctaBlock(T, PC, O, k, c.x + c.w * .14, c.w * .72, 8.4 * k, O.pay !== false);
    var bottom = c.y + c.h - 3;
    column(tb.concat(cb), c.y + 4, bottom, 2.2 * k);
    els = els.concat(tb, cb);
    els.push(prods());
    var rb = ribbon(T, P); if (rb) els.push(rb);
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.8 }, v(f, G(30, 112, 40, 0, 0, 1), G(30, 90, 40, 0, 0, .9), G(30, 6, 40, 0, 0, 1.15))));
    var slots = v(f,
      [slot(11, 96, 38, 18, -8), slot(89, 52, 32, 17, 9), slot(89, 118, 30, 18, 6)],
      [slot(11, 82, 36, 19, -7), slot(89, 48, 32, 18, 8), slot(89, 96, 26, 18, 6)],
      [slot(28, 56, 34, 22, -6), slot(52, 54, 42, 28, 0), slot(76, 56, 34, 22, 6)]);
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 2 ── SPLIT: words on one side, a big colour disc with the products on the other
  FAM.split = function (f, T, P, O) {
    var k = v(f, 1, .9, 1.12), els = [], story = f === 'story';
    var disc = v(f, G(44, 34, 64, 64), G(48, 20, 58, 58), G(12, 64, 76, 76));
    els.push(shp('disc', O.discSh || 'circle', { fill: P.disc || A(P.acc, '2E'), fill2: P.disc2 || '', gang: 160, name: 'Backdrop shape', cr: 'acc2' }, disc));
    if (O.ring) els.push(shp('ring', 'ring', { fill: 'transparent', bc: P.acc, bw: 4, op: 60, name: 'Ring' }, v(f, G(40, 30, 72, 72), G(44, 16, 66, 66), G(8, 60, 84, 84))));
    var x = story ? 8 : 6, w = story ? 84 : 46, al = story ? 'c' : 'l';
    var tb = textBlock(T, P, O, f, k, x, w, al);
    var cb = ctaBlock(T, P, O, k, story ? 20 : 6, story ? 60 : 40, 8.4 * k, O.pay !== false);
    cb.forEach(function (e) { if (!story && e.t !== 'btn') { e.al = 'l'; e.g.x = 6; e.g.w = e.t === 'pay' ? e.g.w : 44; } });
    if (!story) { var p = cb.filter(function (e) { return e.t === 'pay'; })[0]; if (p) p.g.x = 6; }
    if (story) { column(tb, 12, 62, 2.2 * k); column(cb, 146, 174, 2 * k); }
    else { column(tb.concat(cb), v(f, 14, 10), v(f, 118, 96), 2.2 * k); }
    els = els.concat(tb, cb);
    if (O.icon) els.push(decoIcon('deco', O.icon, P.acc, T.badge ? v(f, G(56, 6, 12, 12), G(62, 3, 10, 10), G(8, 4, 12, 12)) : v(f, G(78, 6, 14, 14), G(84, 4, 11, 11), G(80, 4, 14, 14)), 90));
    if (T.badge) els.push(shp('badge', O.badgeSh || 'burst', { fill: P.badge || P.acc, txt: T.badge, fn: 'bebas', fw: 400, fs: 4.6, lh: 95, col: P.badgeTxt || readable(P.badge || P.acc), al: 'c', shd: 30, name: 'Badge', cr: 'acc' }, v(f, G(76, 25, 19, 19, 10), G(78, 12, 16, 16, 10), G(72, 60, 21, 21, 10))));
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, al: story ? 'c' : 'l' }, v(f, G(6, 4.5, 40, 0), G(6, 3.5, 40, 0, 0, .9), G(30, 5, 40, 0, 0, 1.15))));
    var slots = v(f,
      [slot(62, 92, 40, 24, -6), slot(80, 90, 46, 26, 4), slot(95, 96, 30, 14, 10)],
      [slot(64, 74, 36, 22, -6), slot(81, 72, 42, 24, 4), slot(95, 77, 26, 13, 10)],
      [slot(31, 140, 44, 24, -5), slot(52, 138, 52, 28, 0), slot(73, 140, 44, 24, 5)]);
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 3 ── ARCH: a tall arched window (Ramadan, Eid, National Day) holding the products
  FAM.arch = function (f, T, P, O) {
    var k = v(f, 1, .86, 1.14), els = [], H = FH[f];
    var a = v(f, G(22, 40, 56, 60), G(31, 31, 38, 45), G(14, 66, 72, 76));
    els.push(shp('arch', 'arch', { fill: P.disc || A(P.acc, '26'), fill2: P.disc2 || '', gang: 180, bc: P.acc, bw: 5, name: 'Arch', cr: 'acc2' }, a));
    els.push(shp('ped', 'ellipse', { fill: A('#000000', '30'), name: 'Shadow under products' }, G(a.x + a.w * .12, a.y + a.h - 6, a.w * .76, 7)));
    if (O.hang !== false) {
      var ic = O.icon || 'lantern';
      v(f, [[10, 0, 1], [86, 0, .8]], [[10, 0, 1], [86, 0, .8]], [[3, 0, 1], [87.5, 0, .85]]).forEach(function (q, i) {
        var hl = v(f, 16 + i * 6, 10 + i * 4, 62 + i * 9), sz = v(f, 9, 7, 11) * q[2];
        els.push(shp('str' + i, 'rect', { fill: P.acc, op: 70, name: 'String' }, G(q[0] + sz / 2 - .15, 0, .3, hl)));
        els.push(decoIcon('lan' + i, ic, P.acc, G(q[0], hl - 1, sz, sz * 1.3), 100));
      });
    }
    if (O.moon !== false) els.push(decoIcon('moon', 'moon', P.acc, G(a.x + a.w / 2 - a.w * .08, a.y + a.w * .1, a.w * .16, a.w * .16), 90));
    var tb = textBlock(T, P, O, f, k, 8, 84);
    column(tb, v(f, 6, 3, 14), a.y - 2.5, 1.8 * k);
    var cb = ctaBlock(T, P, O, k, 24, 52, 8.2 * k, O.pay !== false);
    column(cb, a.y + a.h + 2, H - 2.5, 1.8 * k);
    els = els.concat(tb, cb);
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.acc, fs: 2.6 }, v(f, G(4, 3, 34, 0, 0, .9), G(3, 2.5, 34, 0, 0, .8), G(30, 4, 40, 0, 0, 1.1))));
    if (f !== 'story') els[els.length - 1].al = 'l';
    var by = a.y + a.h - 3, cx = a.x + a.w / 2, sh = a.h * .62;
    var slots = [slot(cx - a.w * .26, by, sh * .82, a.w * .26, -4), slot(cx, by + .5, sh, a.w * .32, 0), slot(cx + a.w * .26, by, sh * .82, a.w * .26, 4)];
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 4 ── BIG NUMBER: a giant % or number, the offer at a glance
  FAM.bignum = function (f, T, P, O) {
    var k = v(f, 1, .88, 1.12), els = [], story = f === 'story';
    els.push(shp('blob', 'blob', { fill: P.disc || A(P.acc, '24'), name: 'Backdrop shape', cr: 'acc2' }, v(f, G(42, 40, 70, 74), G(50, 22, 60, 64), G(14, 92, 92, 80))));
    var big = tx('num', T.num || '30%', { fn: O.nf || 'bebas', fw: 400, fs: v(f, 34, 28, 40), lh: 86, col: P.acc, tr: 'acc', al: story ? 'c' : 'l', ls: 0, name: 'Big number', tsh: 0 }, G(story ? 6 : 6, 0, story ? 88 : 60, 0));
    var tb = textBlock(T, P, O, f, k, story ? 8 : 6, story ? 84 : 47, story ? 'c' : 'l');
    var cb = ctaBlock(T, P, O, k, story ? 20 : 6, story ? 60 : 40, 8.4 * k, O.pay !== false);
    if (!story) cb.forEach(function (e) { if (e.t !== 'btn') { e.al = 'l'; e.g.x = 6; if (e.t !== 'pay') e.g.w = 47; } });
    var list = [];
    tb.forEach(function (e, i) { list.push(e); if (e.id === 'h1' || (i === 0 && e.id !== 'kick')) { } });
    // number goes right after the kicker
    var ki = list.findIndex(function (e) { return e.id === 'kick'; });
    list.splice(ki + 1, 0, big);
    if (story) { column(list, 12, 96, 1.6 * k); column(cb, 146, 172, 2.2 * k); }
    else column(list.concat(cb), v(f, 12, 8), v(f, 120, 97), 1.8 * k);
    els = els.concat(list, cb);
    if (T.badge) els.push(shp('badge', O.badgeSh || 'burst', { fill: P.badge || P.ink, txt: T.badge, fn: 'bebas', fw: 400, fs: 4.2, lh: 95, col: P.badgeTxt || readable(P.badge || P.ink), shd: 25, name: 'Badge' }, v(f, G(76, 6, 19, 19, 12), G(78, 4, 17, 17, 12), G(74, 98, 20, 20, 12))));
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, al: 'l', fs: 2.7 }, v(f, G(6, 4.5, 40, 0), G(6, 2.5, 40, 0, 0, .85), G(6, 4, 40, 0, 0, 1.1))));
    var slots = v(f,
      [slot(67, 102, 38, 20, -8), slot(85, 106, 46, 22, 4)],
      [slot(66, 80, 34, 20, -8), slot(84, 84, 42, 22, 4)],
      [slot(36, 138, 38, 26, -6), slot(64, 140, 44, 28, 5)]);
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 5 ── FRAME: an elegant inset frame, serif title, script line, products on a soft stage
  FAM.frame = function (f, T, P, O) {
    var k = v(f, 1, .86, 1.12), els = [], H = FH[f];
    els.push(shp('frame', 'rect', { fill: 'transparent', bc: P.acc, bw: 3, rad: 1.5, name: 'Frame', op: 85 }, G(4, 4, 92, H - 8)));
    els.push(shp('frame2', 'rect', { fill: 'transparent', bc: P.acc, bw: 1, rad: 1, name: 'Inner frame', op: 55 }, G(5.8, 5.8, 88.4, H - 11.6)));
    els.push(shp('stage', 'ellipse', { fill: P.disc || A(P.acc, '22'), name: 'Stage', cr: 'acc2' }, v(f, G(16, 92, 68, 14), G(22, 74, 56, 11), G(12, 128, 76, 16))));
    var ic = O.icon || 'flower';
    els.push(decoIcon('c1', ic, P.acc, v(f, G(9, 9, 9, 9), G(9, 9, 7, 7), G(9, 9, 11, 11)), 85));
    els.push(decoIcon('c2', ic, P.acc, v(f, G(82, 9, 9, 9), G(84, 9, 7, 7), G(80, 9, 11, 11)), 85));
    var O2 = Object.assign({ hf: 'playfair', hs: 9.5, hw: 700, kf: 'montserrat' }, O);
    var tb = textBlock(T, P, O2, f, k, 12, 76);
    if (T.script) tb.splice(T.kick ? 2 : 1, 0, tx('script', T.script, { fn: 'dancing', fw: 700, fs: 6.2, lh: 115, col: P.acc, tr: 'acc', name: 'Script line' }, G(10, 0, 80, 0, 0, k)));
    column(tb, v(f, 15, 13, 20), v(f, 66, 50, 92), 1.6 * k);
    var cb = ctaBlock(T, P, O, k, 28, 44, 7.8 * k, O.pay !== false);
    column(cb, v(f, 103, 82, 142), H - 8, 1.8 * k);
    els = els.concat(tb, cb);
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.acc, fs: 2.4 }, G(30, H - 5.2, 40, 0, 0, .9)));
    var b = v(f, 100, 80, 138);
    var slots = v(f,
      [slot(30, b, 28, 16, -5), slot(50, b + 1, 34, 22, 0), slot(70, b, 28, 16, 5)],
      [slot(34, b, 22, 13, -5), slot(50, b + 1, 26, 17, 0), slot(66, b, 22, 13, 5)],
      [slot(28, b, 36, 20, -5), slot(50, b + 1, 44, 26, 0), slot(72, b, 36, 20, 5)]);
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 6 ── STRIPE: a bold diagonal band across the ad (flash sale, cyber, limited stock)
  FAM.stripe = function (f, T, P, O) {
    var k = v(f, 1, .88, 1.14), els = [], H = FH[f];
    var by = v(f, 58, 46, 84);
    els.push(shp('band2', 'rect', { fill: P.acc2 || P.ink, op: 100, name: 'Back band', cr: 'acc2' }, G(-15, by - 3, 130, 22 * k, -7)));
    els.push(tx('band', T.band || 'FLASH SALE', { t: 'shape', sh: 'rect', fill: P.acc, cr: 'acc', fn: 'bebas', fw: 400, fs: 13, lh: 100, ls: 3, col: P.bandTxt || '#FFFFFF', box: 1, name: 'Band', shd: 40 }, G(-15, by, 130, 20 * k, -7, k)));
    els[els.length - 1].t = 'shape';
    var T2 = Object.assign({}, T); delete T2.h1;
    var top = textBlock(T2, P, O, f, k, 8, 84).filter(function (e) { return e.id === 'kick'; });
    var h1 = T.h1 ? tx('h1', T.h1, { fn: O.hf || 'montserrat', fw: 900, fs: O.hs || 7, lh: 100, col: P.ink, fi: !!O.hi, name: 'Headline' }, G(6, 0, 88, 0, 0, k)) : null;
    column(top.concat([h1]), v(f, 9, 7, 14), v(f, 30, 24, 44), 1.4 * k);
    var lower = textBlock({ h2: T.h2, sub: T.sub, ar: T.ar }, P, O, f, k, 8, 84);
    var cb = ctaBlock(T, P, O, k, 22, 56, 8.2 * k, O.pay !== false);
    column(lower.concat(cb), by + 22 * k + v(f, 6, 4, 10), H - 3, 1.8 * k);
    els = els.concat(top, h1 ? [h1] : [], lower, cb);
    if (O.icon) els.push(decoIcon('deco', O.icon, P.acc, v(f, G(82, by - 18, 12, 12), G(85, by - 15, 10, 10), G(80, by - 22, 14, 14)), 100));
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.6 }, G(30, 3, 40, 0, 0, v(f, .9, .8, 1.1))));
    var pb = by + 3;
    var slots = v(f,
      [slot(20, pb, 26, 16, -10), slot(80, pb - 2, 28, 16, 8)],
      [slot(14, pb, 20, 13, -10), slot(86, pb - 2, 22, 13, 8)],
      [slot(26, pb, 30, 20, -8), slot(50, pb - 2, 34, 22, 0), slot(74, pb, 30, 20, 8)]);
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 7 ── STEPS: a 1-2-3 routine, one product per step
  FAM.steps = function (f, T, P, O) {
    var k = v(f, 1, .86, 1.12), els = [], H = FH[f];
    var tb = textBlock({ kick: T.kick, h1: T.h1 }, P, Object.assign({ hs: 8.6, hf: 'montserrat', hw: 900 }, O), f, k, 6, 88);
    column(tb, v(f, 9, 5, 16), v(f, 32, 22.5, 52), 1.4 * k);
    els = els.concat(tb);
    var cy = v(f, 38, 28, 62), ch = v(f, 46, 35, 74);
    var steps = T.steps || ['Cleanse', 'Treat', 'Moisturise'];
    for (var i = 0; i < 3; i++) {
      var x = 5 + i * 31;
      els.push(shp('tile' + i, 'rect', { fill: P.card, rad: 3, shd: 20, name: 'Step card ' + (i + 1), cr: 'card' }, G(x, cy, 28, ch)));
      els.push(shp('num' + i, 'circle', { fill: P.acc, txt: String(i + 1), fn: 'montserrat', fw: 900, fs: 3.6, col: P.btnTxt || '#FFFFFF', name: 'Step number ' + (i + 1), cr: 'acc' }, G(x + 10.5, cy - 3.5, 7, 7)));
      els.push(tx('st' + i, steps[i], { fn: 'poppins', fw: 700, fs: 3, col: onCard(P).ink, name: 'Step ' + (i + 1) }, G(x, cy + ch - 7.5, 28, 0, 0, k)));
    }
    var lower = textBlock({ sub: T.sub, ar: T.ar }, P, O, f, k, 8, 84);
    var cb = ctaBlock(T, P, O, k, 24, 52, 8 * k, O.pay !== false);
    column(lower.concat(cb), cy + ch + 3, H - 3, 1.8 * k);
    els = els.concat(lower, cb);
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.5 }, G(30, 2.6, 40, 0, 0, v(f, .9, .8, 1.1))));
    var b = cy + ch - 9, sh = ch - 15;
    var slots = [slot(19, b, sh, 22, 0), slot(50, b, sh, 22, 0), slot(81, b, sh, 22, 0)];
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 8 ── REVIEW: a quote card (placeholder text the owner replaces with a real review)
  FAM.review = function (f, T, P, O) {
    var k = v(f, 1, .88, 1.12), els = [], H = FH[f], story = f === 'story';
    var c = v(f, G(6, 14, 88, 62), G(6, 9, 60, 58), G(7, 16, 86, 74));
    els.push(shp('card', 'rect', { fill: P.card, rad: 4, shd: 35, name: 'Quote card', cr: 'card' }, c));
    els.push(decoIcon('q', 'quote', P.acc, G(c.x + 5, c.y + 4, 11 * k, 9 * k), 90));
    var qw = c.w - 12;
    var list = [
      tx('kick', T.kick || 'WHAT OUR CUSTOMERS SAY', { fn: 'montserrat', fw: 800, fs: 2.5, ls: 16, col: P.acc, tr: 'acc', al: 'l', name: 'Kicker' }, G(c.x + 6, 0, qw, 0, 0, k)),
      tx('quote', T.quote, { fn: 'playfair', fw: 700, fi: true, fs: 5, lh: 128, col: onCard(P).ink, al: 'l', nw: false, name: 'Review text', pd: 0 }, G(c.x + 6, 0, qw, v(f, 20, 30, 30) * k, 0, k)),
      ico('stars', 'stars5', { col: P.star || '#F5B301', name: 'Stars (decoration)' }, G(c.x + 6, 0, 28 * k, 5.6 * k)),
      tx('who', T.who || '— Customer name, City', { fn: 'poppins', fw: 600, fs: 2.8, col: onCard(P).sub, al: 'l', name: 'Reviewer' }, G(c.x + 6, 0, qw, 0, 0, k))];
    column(list, c.y + 14 * k, c.y + c.h - 4, 2 * k);
    els = els.concat(list);
    var tb = textBlock({ h2: T.h2, ar: T.ar }, P, O, f, k, 8, 84);
    var cb = ctaBlock(T, P, O, k, 24, 52, 8.2 * k, O.pay !== false);
    column(tb.concat(cb), v(f, 82, 70, 134), H - 3, 1.8 * k);
    if (f === 'square') { tb.concat(cb).forEach(function (e) { if (e.t === 'pay') { e.g.x = 6; } else { e.g.x = 6; e.g.w = 58; } if (e.t !== 'btn' && e.t !== 'pay') e.al = 'l'; }); column(tb.concat(cb), 70, 97.5, 1.2 * k); }
    els = els.concat(tb, cb);
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.6, al: 'l' }, G(6, 4, 40, 0, 0, v(f, .9, .8, 1.1))));
    var slots = v(f,
      [slot(80, 82, 30, 18, 6)],
      [slot(83, 64, 44, 26, 4), slot(85, 97, 26, 22, 8)],
      [slot(34, 129, 36, 24, -6), slot(66, 129, 36, 24, 6)]);
    if (f === 'post') slots = [slot(82, 84, 26, 16, 8)];
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 9 ── BUNDLE: product tiles joined with + and = (bundles, buy 1 get 1, gift sets)
  FAM.bundle = function (f, T, P, O) {
    var k = v(f, 1, .86, 1.12), els = [], H = FH[f], n = O.n || 3;
    var tb = textBlock({ kick: T.kick, h1: T.h1 }, P, Object.assign({ hs: 9.5 }, O), f, k, 6, 88);
    column(tb, v(f, 9, 6, 16), v(f, 38, 30, 56), 1.4 * k);
    els = els.concat(tb);
    var ty = v(f, 44, 34, 64), th = v(f, 40, 32, 52), tw = n === 2 ? 36 : 25, gap = n === 2 ? 14 : 8.5, x0 = (100 - n * tw - (n - 1) * gap) / 2, slots = [];
    for (var i = 0; i < n; i++) {
      var x = x0 + i * (tw + gap);
      els.push(shp('tile' + i, O.tileSh || 'rect', { fill: P.card, rad: 3.5, shd: 25, name: 'Tile ' + (i + 1), cr: 'card' }, G(x, ty, tw, th)));
      slots.push(slot(x + tw / 2, ty + th - 3, th - 7, tw - 5, 0));
      if (i < n - 1) els.push(tx('plus' + i, '+', { fn: 'montserrat', fw: 900, fs: 7, lh: 100, col: P.acc, tr: 'acc', name: 'Plus sign' }, G(x + tw, ty + th / 2 - 4, gap, 0, 0, k)));
    }
    if (T.badge) els.push(shp('badge', 'burst', { fill: P.badge || P.acc, txt: T.badge, fn: 'bebas', fw: 400, fs: 4.4, lh: 95, col: P.badgeTxt || readable(P.badge || P.acc), shd: 30, name: 'Badge', cr: 'acc' }, v(f, G(78, ty - 9, 19, 19, 10), G(80, ty - 8, 16, 16, 10), G(76, ty - 10, 21, 21, 10))));
    var lower = textBlock({ h2: T.h2, sub: T.sub, ar: T.ar }, P, O, f, k, 8, 84);
    var cb = ctaBlock(T, P, O, k, 24, 52, 8.2 * k, O.pay !== false);
    column(lower.concat(cb), ty + th + 3, H - 3, 1.8 * k);
    els = els.concat(lower, cb);
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.6 }, G(30, 2.8, 40, 0, 0, v(f, .9, .8, 1.1))));
    var rb = ribbon(T, P); if (rb) els.push(rb);
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 10 ── BIG TYPE: one huge word behind a hero product (new in, restock, product hero)
  FAM.type = function (f, T, P, O) {
    var k = v(f, 1, .86, 1.14), els = [], H = FH[f];
    var wy = v(f, 26, 17, 46);
    els.push(tx('word', T.word || 'NEW', { fn: O.wf || 'bebas', fw: 400, fs: O.ws || 44, lh: 84, ls: 1, col: P.word || A(P.acc, 'FF'), tr: 'acc', name: 'Big word', op: O.wop || 100 }, G(0, wy, 100, 0, 0, v(f, 1, .9, 1.15))));
    if (O.disc !== false) els.push(shp('disc', 'circle', { fill: P.disc || A(P.acc, '30'), name: 'Glow', op: 100, cr: 'acc2' }, v(f, G(22, 34, 56, 56), G(30, 22, 40, 40), G(14, 62, 72, 72))));
    var top = textBlock({ kick: T.kick, h1: T.h1 }, P, Object.assign({ hf: 'montserrat', hs: 6, hw: 900 }, O), f, k, 8, 84);
    column(top, v(f, 9, 6, 16), wy - 1.5, 1.2 * k);
    els = els.concat(top);
    els.push(prods());
    var lower = textBlock({ h2: T.h2, sub: T.sub, ar: T.ar }, P, O, f, k, 8, 84);
    var cb = ctaBlock(T, P, O, k, 24, 52, 8.2 * k, O.pay !== false);
    column(lower.concat(cb), v(f, 92, 68, 136), H - 3, 1.6 * k);
    els = els.concat(lower, cb);
    if (O.icon) els.push(decoIcon('deco', O.icon, P.acc, v(f, G(80, 8, 12, 12), G(84, 6, 9, 9), G(80, 10, 13, 13)), 90));
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.6, al: 'l' }, G(6, 3.5, 40, 0, 0, v(f, .9, .8, 1.1))));
    var b = v(f, 90, 66, 134);
    var slots = v(f,
      [slot(36, b - 1, 36, 22, -6), slot(58, b, 50, 30, 3)],
      [slot(40, b - 1, 28, 18, -6), slot(58, b, 38, 24, 3)],
      [slot(36, b - 1, 46, 28, -6), slot(60, b, 62, 36, 3)]);
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 11 ── COUPON: a ticket with a code (12.12, loyalty, refer a friend, giveaway)
  FAM.coupon = function (f, T, P, O) {
    var k = v(f, 1, .88, 1.14), els = [], H = FH[f];
    var t = v(f, G(8, 46, 84, 44), G(14, 33, 72, 34), G(8, 78, 84, 54));
    var tb = textBlock({ kick: T.kick, h1: T.h1 }, P, Object.assign({ hs: 10 }, O), f, k, 6, 88);
    column(tb, v(f, 9, 6, 16), v(f, 33, 24, 52), 1.4 * k);
    els = els.concat(tb);
    els.push(shp('ticket', 'ticket', { fill: P.card, rad: 0, shd: 40, name: 'Ticket', cr: 'card' }, t));
    els.push(shp('dash', 'rect', { fill: 'transparent', bc: P.acc, bw: 2, bs: 'dashed', rad: 2.5, name: 'Dashed line', op: 80 }, G(t.x + 5, t.y + 4, t.w - 10, t.h - 8)));
    var PC = onCard(P), inner = [
      tx('h2', T.h2 || 'USE CODE', { fn: 'montserrat', fw: 800, fs: 3, ls: 20, col: PC.sub, name: 'Code label' }, G(t.x + 6, 0, t.w - 12, 0, 0, k)),
      tx('code', T.code || 'GLOW15', { fn: 'bebas', fw: 400, fs: 13, lh: 95, ls: 6, col: P.acc, tr: 'acc', name: 'Code' }, G(t.x + 6, 0, t.w - 12, 0, 0, k)),
      T.sub ? tx('sub', T.sub, { fn: 'poppins', fw: 600, fs: 2.9, lh: 130, col: PC.ink, name: 'Supporting line' }, G(t.x + 8, 0, t.w - 16, 0, 0, k)) : null];
    column(inner, t.y + 6, t.y + t.h - 6, 1.5 * k);
    els = els.concat(inner.filter(Boolean));
    var cb = ctaBlock({ cta: T.cta, trust: T.trust }, P, O, k, 24, 52, 8.2 * k, O.pay !== false);
    var ar = T.ar ? textBlock({ ar: T.ar }, P, O, f, k, 8, 84) : [];
    column(ar.concat(cb), t.y + t.h + 3, H - 3, 1.8 * k);
    els = els.concat(ar, cb);
    if (O.icon) els.push(decoIcon('deco', O.icon, P.acc, v(f, G(78, t.y - 13, 13, 13), G(80, t.y - 11, 10, 10), G(76, t.y - 16, 15, 15)), 100));
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.6 }, G(30, 3, 40, 0, 0, v(f, .9, .8, 1.1))));
    var b = t.y + 3;
    var slots = v(f, [slot(18, b, 24, 15, -10)], [slot(10, b + 4, 26, 13, -8)], [slot(20, b, 30, 18, -10), slot(50, b - 1, 28, 18, 0)]);
    if (f === 'square') slots = [slot(9, b + 20, 30, 13, -6)];
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 12 ── PODIUM: products on three plinths (bestsellers, K-beauty edit)
  FAM.podium = function (f, T, P, O) {
    var k = v(f, 1, .86, 1.12), els = [], H = FH[f];
    var tb = textBlock({ kick: T.kick, h1: T.h1, h2: T.h2, ar: T.ar }, P, Object.assign({ hs: 9.5 }, O), f, k, 6, 88);
    column(tb, v(f, 8, 5, 16), v(f, 42, 31, 62), 1.3 * k);
    els = els.concat(tb);
    var base = v(f, 102, 80, 142), hs = v(f, [10, 16, 7], [8, 13, 6], [13, 20, 9]), w = 28, xs = [8, 36, 64], slots = [];
    for (var i = 0; i < 3; i++) {
      els.push(shp('pod' + i, 'rect', { fill: i === 1 ? P.card : P.card2 || P.card, fill2: P.disc || '', gang: 180, rad: 1.2, shd: 25, name: 'Plinth ' + (i + 1), cr: 'card' }, G(xs[i], base - hs[i], w, hs[i])));
      slots.push(slot(xs[i] + w / 2, base - hs[i] + .8, v(f, 30, 22, 42) * (i === 1 ? 1.12 : 1), 20, 0));
    }
    els.push(shp('floor', 'rect', { fill: P.acc, op: 30, rad: .5, name: 'Floor line' }, G(4, base, 92, .8)));
    var lower = textBlock({ sub: T.sub }, P, O, f, k, 8, 84);
    var cb = ctaBlock(T, P, O, k, 24, 52, 8.2 * k, O.pay !== false);
    column(lower.concat(cb), base + 2.5, H - 2.5, 1.5 * k);
    els = els.concat(lower, cb);
    els.push(decoIcon('spk1', 'sparkle', P.acc, v(f, G(84, 44, 6, 6), G(86, 34, 5, 5), G(84, 64, 7, 7)), 80));
    els.push(decoIcon('spk2', 'sparkle', P.acc, v(f, G(10, 50, 4, 4), G(10, 38, 3.5, 3.5), G(10, 72, 5, 5)), 70));
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.6 }, G(30, 3, 40, 0, 0, v(f, .9, .8, 1.1))));
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 13 ── COLLAGE: tilted photo cards, each holding a product (gift sets, K-beauty edit, back to school)
  FAM.collage = function (f, T, P, O) {
    var k = v(f, 1, .86, 1.12), els = [], H = FH[f];
    var cards = v(f,
      [G(6, 12, 30, 40, -8), G(36, 7, 30, 40, 3), G(64, 13, 30, 40, 9)],
      [G(8, 8, 26, 34, -8), G(37, 5, 26, 34, 3), G(66, 9, 26, 34, 9)],
      [G(5, 20, 31, 52, -8), G(35, 14, 31, 52, 3), G(64, 21, 31, 52, 9)]);
    var slots = [];
    cards.forEach(function (c, i) {
      els.push(shp('pc' + i, 'rect', { fill: P.card, rad: 1.6, shd: 40, name: 'Photo card ' + (i + 1), cr: 'card' }, c));
      els.push(shp('pi' + i, 'rect', { fill: P.disc || A(P.acc, '2A'), rad: 1, name: 'Photo ' + (i + 1), cr: 'acc2' }, G(c.x + 2, c.y + 2, c.w - 4, c.h - 9, c.r)));
      slots.push(slot(c.x + c.w / 2, c.y + c.h - 9, c.h - 13, c.w - 9, c.r));
    });
    var tb = textBlock(T, P, O, f, k, 8, 84);
    var cb = ctaBlock(T, P, O, k, 24, 52, 8.2 * k, O.pay !== false);
    column(tb.concat(cb), v(f, 58, 46, 68), H - 6, 1.7 * k);
    if (f === 'story') { column(tb, 82, 128, 2 * k); column(cb, 132, 164, 2 * k); }
    els = els.concat(tb, cb);
    if (O.icon) els.push(decoIcon('deco', O.icon, P.acc, v(f, G(86, 52, 9, 9), G(88, 42, 7, 7), G(84, 62, 10, 10)), 90));
    els.push(prods());
    var rb = ribbon(T, P); if (rb) els.push(rb);
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.4 }, G(30, H - 4.6, 40, 0, 0, v(f, .9, .8, 1.1))));
    if (f === 'square') els[els.length - 1].g.y = 95.2;
    return { bg: bgOf(P, O), els: els, slots: slots };
  };

  // 14 ── GLASS: soft gradient, floating bubbles, a frosted panel (hydration, glass skin)
  FAM.glass = function (f, T, P, O) {
    var k = v(f, 1, .88, 1.12), els = [], H = FH[f], story = f === 'story';
    els.push(shp('b1', 'circle', { fill: A(P.acc, '40'), name: 'Bubble' }, v(f, G(-12, -8, 50, 50), G(-10, -10, 40, 40), G(-16, -6, 64, 64))));
    els.push(shp('b2', 'circle', { fill: A(P.acc2 || P.acc, '33'), name: 'Bubble' }, v(f, G(62, 70, 52, 52), G(66, 56, 44, 44), G(56, 120, 66, 66))));
    els.push(shp('b3', 'ring', { fill: 'transparent', bc: '#FFFFFF', bw: 2, op: 70, name: 'Bubble ring' }, v(f, G(70, 8, 18, 18), G(76, 6, 14, 14), G(72, 10, 20, 20))));
    var pnl = v(f, G(6, 54, 56, 66), G(5, 18, 54, 76), G(8, 90, 84, 82));
    els.push(shp('panel', 'rect', { fill: '#FFFFFF66', bc: '#FFFFFFAA', bw: 1.5, rad: 4, shd: 20, name: 'Glass panel' }, pnl));
    var tb = textBlock(T, P, O, f, k, pnl.x + 4, pnl.w - 8, story ? 'c' : 'l');
    var cb = ctaBlock(T, P, O, k, story ? 20 : pnl.x + 4, story ? 60 : pnl.w - 8, 8.2 * k, O.pay !== false);
    if (!story) cb.forEach(function (e) { if (e.t !== 'btn') { e.al = 'l'; e.g.x = pnl.x + 4; if (e.t !== 'pay') e.g.w = pnl.w - 8; } });
    column(tb.concat(cb), pnl.y + 3.5, pnl.y + pnl.h - 3, 1.7 * k);
    els = els.concat(tb, cb);
    if (O.icon) els.push(decoIcon('deco', O.icon, P.acc, v(f, G(8, 8, 9, 9), G(64, 6, 8, 8), G(10, 10, 10, 10)), 85));
    els.push(prods());
    els.push(logo('logo', { col: P.logo || P.ink, fs: 2.6, al: story ? 'c' : 'l' }, v(f, G(20, 4, 40, 0, 0, .9), G(6, 4, 40, 0, 0, .8), G(30, 4, 40, 0, 0, 1.1))));
    var slots = v(f,
      [slot(48, 50, 34, 24, -8), slot(80, 64, 48, 26, 5), slot(84, 112, 34, 22, 8)],
      [slot(72, 52, 34, 22, -6), slot(88, 62, 40, 20, 6), slot(78, 96, 30, 22, 0)],
      [slot(30, 84, 52, 28, -6), slot(56, 82, 60, 30, 0), slot(80, 86, 46, 22, 7)]);
    return { bg: bgOf(P, Object.assign({ bgk: 'linear' }, O)), els: els, slots: slots };
  };

  // ── UAE NATIONAL DAY · six luxury-beauty looks (owner, 15 Nov 2026: "don't mention 55 etc. just heading it
  //    'National Day' Mega Offer. i need more beautiful, attracted to ladies etc. don't just throw classic basic
  //    looks."). Every design: the heading "National Day", the offer line "Mega Offer", an editable offer value,
  //    an Arabic line, 4-5 of the shop's newest products, one button, tabby / tamara and the logo. The flag's red,
  //    green, white and black are always there and recognisable, but as satin, petals, pinstripes and glow --
  //    never as blocks. No emblem, no coat of arms, no photographs of people, no year or number in the heading. ──
  var UAE = { red: '#EF3340', green: '#00843D', white: '#FFFFFF', black: '#0B0B0B' };
  var SATIN = { red: ['#FF5C6B', '#C8102E'], green: ['#19A85E', '#006A30'], white: ['#FFFFFF', '#EADDD8'], black: ['#4A4A4A', '#050505'] };
  function solidBg(c1, c2, k, pat, pc, po, ang) { return { k: k || 'solid', c1: c1, c2: c2 || c1, ang: ang || 180, pat: pat || 'none', pc: pc || '#000000', po: po || 0, img: '', ov: 0 }; }
  function glow(id, c, g, nm) { return shp(id, 'glow', { fill: c, name: nm || 'Soft glow' }, g); }
  function petal(id, c, g, o) { return shp(id, 'petal', Object.assign({ fill: c[0], fill2: c[1], gang: 135, shd: 18, name: 'Petal' }, o || {}), g); }
  function spark(id, c, g, op) { return ico(id, 'sparkle', { col: c, op: op == null ? 90 : op, name: 'Sparkle' }, g); }
  function line(id, c, g, nm) { return shp(id, 'rect', { fill: c, rad: .2, name: nm || 'Fine line' }, g); }
  function ndLogo(col, g, o) { return logo('logo', Object.assign({ col: col, fs: 2.6 }, o || {}), g); }
  function ndKick(T, col, g, o) { return tx('kick', T.kick, Object.assign({ fn: 'montserrat', fw: 600, fs: 2.2, ls: 36, col: col, name: 'Kicker' }, o || {}), g); }
  function ndH1(T, o, g) { return tx('h1', T.h1, Object.assign({ fn: 'playfair', fw: 700, fs: 12, lh: 104, col: '#2A1A1C', name: 'Heading (National Day)' }, o || {}), g); }
  function ndMega(T, o, g) { return tx('h2', T.h2, Object.assign({ fn: 'dancing', fw: 700, fs: 10, lh: 110, col: UAE.red, name: 'Offer line (Mega Offer)' }, o || {}), g); }
  function ndOff(T, o, g) { return tx('off', T.off, Object.assign({ fn: 'montserrat', fw: 800, fs: 3, ls: 12, col: '#FFFFFF', sh: 'pill', fill: UAE.green, pd: .8, up: true, name: 'Offer value (edit me)' }, o || {}), g); }
  function ndAr(T, col, g, o) { return tx('ar', T.ar, Object.assign({ fn: 'cairo', fw: 700, fs: 3.2, lh: 150, col: col, name: 'Arabic line' }, o || {}), g); }
  function ndCta(T, g, o) { return btn('cta', T.cta, Object.assign({ fill: '#2A1A1C', col: '#FFFFFF', sh: 'pill', fs: 3.5, fw: 600, ls: 8, shd: 30 }, o || {}), g); }
  function ndTrust(T, col, g, o) { return tx('trust', T.trust, Object.assign({ fn: 'poppins', fw: 600, fs: 2.1, lh: 130, ls: 4, col: col, name: 'Urgency / trust line' }, o || {}), g); }
  // the footer every design shares: the button, tabby / tamara under it, the trust line under that
  function ndFoot(els, T, f, y, x, w, h, tcol, o, al) {
    var k = v(f, 1, .9, 1.12), cx = x + w / 2, pw = 34 * k;
    els.push(ndCta(T, G(x, y, w, h), o));
    els.push(pay('pay', G(al === 'l' ? x : cx - pw / 2, y + h + 2 * k, pw, 3.2 * k)));
    els.push(ndTrust(T, tcol, G(al === 'l' ? x : 6, y + h + 7.4 * k, al === 'l' ? 60 : 88, 0, 0, k), { al: al || 'c' }));
  }
  // a pearl podium: a soft floor shadow, a rounded base, the body, a lit top
  function podium(els, id, x, y, w, h, top, body, eh) {
    els.push(glow(id + 's', '#5A2A2A40', G(x - w * .1, y + h - eh * .2, w * 1.2, eh * 1.4), 'Podium shadow'));
    els.push(shp(id + 'b', 'ellipse', { fill: body[1], name: 'Podium base' }, G(x, y + h - eh / 2, w, eh)));
    els.push(shp(id + 'r', 'rect', { fill: body[0], fill2: body[1], gang: 90, name: 'Podium' }, G(x, y, w, h)));
    els.push(shp(id + 't', 'ellipse', { fill: top[0], fill2: top[1], gang: 180, name: 'Podium top' }, G(x, y - eh / 2, w, eh)));
  }

  // ND1 ── SATIN RIBBONS: a blush-and-pearl ground with four satin ribbons -- red, green, white, black --
  //        flowing across it like a sash; the heading in italic serif, "Mega Offer" in script, the products
  //        on a pearl dish where the ribbons cross
  FAM.ndsilk = function (f, T, P, O) {
    var els = [], s = v(f, 1, .78, 1.5);
    els.push(glow('ga', '#FFFFFFF2', v(f, G(-35, -30, 110, 90), G(-35, -35, 100, 80), G(-45, -25, 140, 110))));
    els.push(glow('gb', '#EFAFB0B0', v(f, G(45, 55, 90, 80), G(50, 40, 80, 70), G(35, 95, 110, 100))));
    var ry = v(f, 57, 44.5, 74), rh = v(f, 34, 27, 46), dy = rh * .14;
    [['rr', SATIN.red, 'red'], ['rg', SATIN.green, 'green'], ['rw', SATIN.white, 'white'], ['rb', SATIN.black, 'black']].forEach(function (q, i) {
      els.push(shp(q[0], 'silk', { fill: q[1][0], fill2: q[1][1], gang: 100, shd: 16, name: 'Satin ribbon (' + q[2] + ')' }, G(-16, ry + i * dy, 132, rh, -8)));
    });
    var by = v(f, 97, 78, 140);
    els.push(glow('pg', '#FFFFFFD9', G(8, by - 16 * s, 84, 28 * s)));
    els.push(shp('pod', 'ellipse', { fill: '#FFFFFF', fill2: '#DDB3AE', gang: 180, shd: 30, name: 'Pearl dish' }, G(14, by - 4.2 * s, 72, 9 * s)));
    [[9, v(f, 8, 6, 12), 4.2], [88, v(f, 30, 24, 40), 3.2], [91, v(f, 6, 4, 9), 2.4], [5, v(f, 45, 35, 70), 2.6]].forEach(function (q, i) { els.push(spark('sp' + i, '#C9877F', G(q[0] - q[2] / 2, q[1], q[2], q[2]), 80)); });
    var top = [ndLogo('#5B3A3E', G(25, 0, 50, 0)), ndKick(T, '#B5707A', G(5, 0, 90, 0)),
      ndH1(T, { fi: true, fs: v(f, 13.5, 11, 14.5), col: '#3B2328' }, G(2, 0, 96, 0)),
      ndMega(T, { fs: v(f, 11.5, 9, 13) }, G(5, 0, 90, 0)),
      ndOff(T, { fill: '#2A1A1C' }, G(20, 0, 60, 0)), ndAr(T, '#8A5A5C', G(10, 0, 80, 0))];
    column(top, v(f, 4, 3, 7), v(f, 55, 43, 70), v(f, 1.8, 1.2, 2.6), false);
    els = els.concat(top);
    els.push(prods());
    ndFoot(els, T, f, by + v(f, 6.5, 5, 10), v(f, 27, 29, 22), v(f, 46, 42, 56), v(f, 7.8, 6.8, 9), '#7A5558', { fill: UAE.red, fill2: '#C8102E', gang: 180 });
    var sl = [slot(36, by - 1.6 * s, 32 * s, 12 * s, -3), slot(64, by - 1.6 * s, 32 * s, 12 * s, 3), slot(50, by, 39 * s, 17 * s),
      slot(26, by + 1.4 * s, 19 * s, 21 * s, -5), slot(74, by + 1.4 * s, 19 * s, 21 * s, 5)];
    return { bg: solidBg('#FFF7F3', '#F3D2CF', 'linear', 'sparkle', '#FFFFFF', 70, 165), els: els, slots: sl };
  };

  // ND2 ── PEARL ARCH: champagne ground, a blush arch traced by fine red, green and black lines (the white
  //        is the pearl between them), a two-tier pearl podium inside it; "MEGA OFFER" in spaced capitals
  //        between a red and a green hairline
  FAM.ndpearl = function (f, T, P, O) {
    var els = [], sq = f === 'square';
    var a = v(f, G(16, 39, 68, 62), G(50, 8, 46, 84), G(12, 63, 76, 84));
    els.push(glow('ga', '#FFFFFFE6', G(a.x - 20, a.y - 18, a.w + 40, a.w + 20)));
    [['a1', UAE.red, 3.2, 5], ['a2', UAE.green, 1.9, 3.2], ['a3', UAE.black, .8, 2.4]].forEach(function (q) {
      els.push(shp(q[0], 'arch', { fill: 'transparent', bc: q[1], bw: q[3], name: 'Arch line' }, G(a.x - q[2], a.y - q[2], a.w + q[2] * 2, a.h + q[2])));
    });
    els.push(shp('arch', 'arch', { fill: '#FBE9E5', fill2: '#EDBDB8', gang: 180, name: 'Arch' }, a));
    els.push(glow('ag', '#FFFFFFB3', G(a.x + a.w * .1, a.y + a.h * .05, a.w * .8, a.w * .7)));
    var cx = a.x + a.w / 2, base = a.y + a.h, k = a.w / v(f, 70, 60, 70);
    podium(els, 'p1', cx - 30 * k, base - 12 * k, 60 * k, 10 * k, ['#FFFFFF', '#F1E6E1'], ['#FBF6F3', '#DCCBC4'], 6 * k);
    podium(els, 'p2', cx - 16 * k, base - 22 * k, 32 * k, 10 * k, ['#FFFFFF', '#F1E6E1'], ['#FBF6F3', '#DCCBC4'], 4.4 * k);
    [[a.x + a.w * .16, a.y + a.h * .2, 3], [a.x + a.w * .8, a.y + a.h * .32, 2.4]].forEach(function (q, i) { els.push(spark('sp' + i, '#FFFFFF', G(q[0], q[1], q[2], q[2]), 95)); });
    var X = 5, W = sq ? 40 : 90, al = sq ? 'l' : 'c';
    var top = [ndLogo('#3A2A26', G(X, 0, W, 0), { al: al }), ndKick(T, '#A57F5A', G(X, 0, W, 0), { al: al, ls: sq ? 22 : 36 }),
      ndH1(T, { fn: 'dmserif', fw: 400, fs: v(f, 13.5, 7.2, 14.5), lh: 100, col: '#1F1A1A', al: al }, G(X, 0, W, 0)),
      tx('h2', T.h2, { fn: 'montserrat', fw: 600, fs: v(f, 3.6, 3.1, 4), ls: 44, up: true, col: '#1F1A1A', al: al, name: 'Offer line (Mega Offer)' }, G(sq ? X : 25, 0, sq ? W : 50, 0)),
      ndOff(T, { fill: 'transparent', col: '#9C6B4E', bc: '#C49A7A', bw: 3, fs: v(f, 3, 2.7, 3.4), al: al }, G(X, 0, W, 0)),
      ndAr(T, UAE.green, G(X, 0, W, 0), { al: al })];
    if (sq) column(top, 8, 62, 2.4, false); else column(top, v(f, 4, 0, 6), v(f, 35, 0, 57), v(f, 1.5, 0, 2.4), false);
    els = els.concat(top);
    var h2 = top[3], ry = h2.g.y + h2.fs * (h2.g.k || 1) * .5, lw = v(f, 14, 8, 15);
    if (sq) els.push(line('l1', UAE.red, G(X, ry + 3, 9, .45)), line('l2', UAE.green, G(X + 10, ry + 3, 9, .45)));
    else els.push(line('l1', UAE.red, G(19 - lw / 2, ry, lw, .45)), line('l2', UAE.green, G(81 - lw / 2, ry, lw, .45)));
    els.push(prods());
    if (sq) ndFoot(els, T, f, 72, X, 36, 7, '#6E5A50', { fill: '#1F1A1A' }, 'l');
    else ndFoot(els, T, f, base + v(f, 3, 0, 6), v(f, 28, 0, 22), v(f, 44, 0, 56), v(f, 7.6, 0, 9), '#6E5A50', { fill: '#1F1A1A' });
    var t1 = base - 22 * k - 2.2 * k, t2 = base - 12 * k - 3 * k;
    var sl = [slot(cx - 7 * k, t1, 34 * k, 11 * k, 0), slot(cx + 7 * k, t1, 30 * k, 11 * k, 0),
      slot(cx - 21 * k, t2, 24 * k, 14 * k, 0), slot(cx + 21 * k, t2, 22 * k, 15 * k, 0)];
    return { bg: solidBg('#FFFBF6', '#EFE0CF', 'radial'), els: els, slots: sl };
  };

  // ND3 ── ROSE NIGHT: an emerald-to-black evening dusted with sparkles, satin petals in red, white and green
  //        drifting across it, a rose-gold glow behind a glass plinth; the heading in light serif
  FAM.ndnight = function (f, T, P, O) {
    var els = [], s = v(f, 1.08, .84, 1.36);
    var by = v(f, 97, 75, 140);
    els.push(glow('g1', '#F2B9A6A6', G(10, by - 46 * s, 80, 62 * s), 'Rose-gold glow'));
    els.push(glow('g2', '#EF334059', v(f, G(-30, 70, 70, 60), G(-30, 55, 60, 50), G(-35, 110, 80, 80)), 'Red glow'));
    els.push(glow('g3', '#19A85E66', v(f, G(60, -20, 70, 60), G(60, -20, 60, 50), G(55, -20, 80, 70)), 'Green glow'));
    var pts = v(f,
      [[4, 8, 9, 20, SATIN.red], [86, 4, 7, -30, SATIN.white], [90, 46, 8, 60, SATIN.red], [2, 50, 6, 110, SATIN.white], [80, 68, 5, 15, SATIN.green], [12, 74, 7, -50, SATIN.green], [93, 24, 4, 80, SATIN.green], [8, 32, 4, 140, SATIN.red]],
      [[3, 6, 7, 20, SATIN.red], [88, 3, 6, -30, SATIN.white], [91, 42, 6, 60, SATIN.red], [2, 40, 5, 110, SATIN.white], [84, 60, 4, 15, SATIN.green], [10, 62, 5, -50, SATIN.green], [94, 22, 3.5, 80, SATIN.green], [7, 25, 3.5, 140, SATIN.red]],
      [[4, 12, 10, 20, SATIN.red], [84, 6, 8, -30, SATIN.white], [88, 70, 9, 60, SATIN.red], [2, 84, 7, 110, SATIN.white], [82, 104, 6, 15, SATIN.green], [8, 112, 8, -50, SATIN.green], [92, 38, 5, 80, SATIN.green], [10, 48, 5, 140, SATIN.red]]);
    pts.forEach(function (q, i) { els.push(petal('pt' + i, q[4], G(q[0], q[1], q[2], q[2], q[3]), { shd: 30 })); });
    var pw = v(f, 66, 58, 72), ph = v(f, 3.6, 3, 4.6), px = 50 - pw / 2;
    els.push(glow('pg', '#FFFFFF59', G(px - 8, by - ph * .6, pw + 16, ph * 2.4)));
    els.push(shp('plinth', 'rect', { fill: '#FFFFFF38', fill2: '#FFFFFF0A', gang: 180, bc: '#FFFFFF80', bw: 2.4, rad: .8, name: 'Glass plinth' }, G(px, by - .6, pw, ph)));
    els.push(line('edge', '#FFFFFFB3', G(px + 1, by - .6, pw - 2, .35), 'Glass edge'));
    var top = [ndLogo('#F3D9CF', G(25, 0, 50, 0)), ndKick(T, '#E7B9A8', G(5, 0, 90, 0)),
      ndH1(T, { fw: 400, fs: v(f, 14, 11, 15), lh: 100, col: '#FFFFFF', tsh: 35 }, G(2, 0, 96, 0)),
      ndMega(T, { fn: 'playfair', fw: 700, fi: true, fs: v(f, 9.5, 7.6, 10.5), col: '#F2BCA8' }, G(5, 0, 90, 0)),
      ndOff(T, { fill: '#F7D6C9', fill2: '#D99A86', gang: 90, col: '#3A0E14' }, G(20, 0, 60, 0)), ndAr(T, '#FFFFFF', G(10, 0, 80, 0))];
    column(top, v(f, 4, 3, 7), v(f, 54, 42.5, 82), v(f, 1.8, 1.2, 2.6), false);
    els = els.concat(top);
    els.push(prods());
    ndFoot(els, T, f, by + ph + v(f, 4, 3, 6), v(f, 27, 29, 22), v(f, 46, 42, 56), v(f, 7.6, 6.6, 9), '#C9B3AC', { fill: '#FFFFFF', col: '#123222' });
    var sl = [slot(37, by - .4, 30 * s, 11 * s, 0), slot(63, by - .4, 30 * s, 11 * s, 0), slot(50, by, 40 * s, 16 * s),
      slot(25, by + .2, 20 * s, 19 * s, 0), slot(75, by + .2, 19 * s, 20 * s, 0)];
    return { bg: solidBg('#0F4A2E', '#020A06', 'linear', 'sparkle', '#F6D7C8', 55, 170), els: els, slots: sl };
  };

  // ND4 ── THE GIFT BOW: the canvas wrapped like a present -- a satin ribbon striped red, white, green and
  //        black runs along the top and down the side, tied in a red satin bow at the corner
  FAM.ndgift = function (f, T, P, O) {
    var H = FH[f], els = [], RX = v(f, 15, 13, 15), RY = v(f, 17, 14, 19);
    function ribbon(vert) {
      var o = 0; [['red', 1.1], ['white', 1.7], ['green', 1.7], ['white', .5], ['black', 1.7], ['red', 1.1]].forEach(function (q, i) {
        var c = SATIN[q[0]], g = vert ? G(RX - 4 + o, 0, q[1], H) : G(0, RY - 4 + o, 100, q[1]);
        els.push(shp((vert ? 'rv' : 'rh') + i, 'rect', { fill: c[0], fill2: c[1], gang: vert ? 90 : 180, name: 'Ribbon stripe (' + q[0] + ')' }, g)); o += q[1];
      });
    }
    ribbon(true); ribbon(false);
    // the bow: two satin tails with cut ends, two loops with a darker fold inside each, the knot
    var cx = RX - .1, cy = RY - .1, L = v(f, 15, 12.5, 16), sat = { fill: '#FF6B79', fill2: '#C40F2C', shd: 28 };
    els.push(shp('tail1', 'banner', Object.assign({}, sat, { gang: 0, name: 'Bow tail' }), G(cx - L * .32 - L * .55, cy + L * .5 - 2.1, L * 1.1, 4.2, -62)));
    els.push(shp('tail2', 'banner', Object.assign({}, sat, { gang: 0, name: 'Bow tail' }), G(cx + L * .3 - L * .5, cy + L * .52 - 2.1, L, 4.2, 66)));
    els.push(shp('loop1', 'ellipse', Object.assign({}, sat, { gang: 60, name: 'Bow loop' }), G(cx - L * .5 - L / 2, cy - L * .24 - L * .3, L, L * .6, 24)));
    els.push(shp('loop2', 'ellipse', Object.assign({}, sat, { gang: 300, name: 'Bow loop' }), G(cx + L * .5 - L / 2, cy - L * .24 - L * .3, L, L * .6, -24)));
    els.push(shp('fold1', 'ellipse', { fill: '#9E0B22', fill2: '#E5384B', gang: 90, op: 85, name: 'Bow fold' }, G(cx - L * .36 - L * .25, cy - L * .14 - L * .12, L * .5, L * .24, 24)));
    els.push(shp('fold2', 'ellipse', { fill: '#E5384B', fill2: '#9E0B22', gang: 90, op: 85, name: 'Bow fold' }, G(cx + L * .36 - L * .25, cy - L * .14 - L * .12, L * .5, L * .24, -24)));
    els.push(shp('knot', 'rect', { fill: '#F0394C', fill2: '#A30D23', gang: 180, rad: 1.4, shd: 30, name: 'Bow knot' }, G(cx - 2.5, cy - 2.9, 5, 5.8)));
    els.push(glow('pg', '#FFFFFFCC', v(f, G(22, 70, 82, 46), G(52, 38, 56, 56), G(10, 104, 90, 60))));
    var X = v(f, 24, 22, 22), W = 100 - X - 5, sq = f === 'square';
    var top = [ndKick(T, '#B04A5E', G(X, 0, W, 0), { ls: 30 }),
      ndH1(T, { fw: 900, fs: v(f, 11.5, 9.4, 11.8), lh: 100, col: '#3A1C24', ls: -1 }, G(X, 0, W, 0)),
      ndMega(T, { fs: v(f, 11.5, 9, 12.5) }, G(X, 0, W, 0)),
      ndOff(T, { fill: '#1A1214' }, G(X + 8, 0, W - 16, 0)), ndAr(T, UAE.green, G(X, 0, W, 0))];
    if (sq) { top.forEach(function (e) { e.al = 'l'; e.g.x = X; e.g.w = 44; }); column(top, 20, 76, 1.8, false); }
    else column(top, v(f, 23, 0, 28), v(f, 62, 0, 92), v(f, 1.8, 0, 2.6), false);
    els = els.concat(top);
    els.push(ndLogo('#5B2A36', v(f, G(X, 4.5, W, 0), G(X + 8, 3.2, W - 16, 0), G(X, 5, W, 0))));
    var by = v(f, 99, 76, 146), s = v(f, .95, .72, 1.5), cx = v(f, X + W / 2, 77, X + W / 2);
    els.push(shp('cush', 'ellipse', { fill: '#FFFFFF', fill2: '#EFC0C8', gang: 180, bc: '#EF334066', bw: 2, shd: 22, name: 'Satin cushion' }, G(cx - 26 * s, by - 4 * s, 52 * s, 8.4 * s)));
    els.push(prods());
    if (sq) ndFoot(els, T, f, 82, X, 40, 6.6, '#8C5A66', { fill: UAE.red, fill2: '#C8102E', gang: 180 }, 'l');
    else ndFoot(els, T, f, by + v(f, 6, 0, 8), X + W / 2 - v(f, 23, 0, 27), v(f, 46, 0, 54), v(f, 7.6, 0, 9), '#8C5A66', { fill: UAE.red, fill2: '#C8102E', gang: 180 });
    var sl = [slot(cx - 12 * s, by - 1.4 * s, 32 * s, 11 * s, -3), slot(cx + 12 * s, by - 1.4 * s, 30 * s, 11 * s, 3), slot(cx, by, 37 * s, 16 * s, 0),
      slot(cx - 23 * s, by + 1 * s, 17 * s, 20 * s, -5), slot(cx + 23 * s, by + 1 * s, 17 * s, 20 * s, 5)];
    return { bg: solidBg('#FDF1F1', '#F4D2D7', 'linear', 'dots', '#FFFFFF', 45, 160), els: els, slots: sl };
  };

  // ND5 ── EDITORIAL: an ivory magazine page -- a pinstripe edge in the flag's colours, the heading set large
  //        and left, a tall rose arch framed in champagne holding the products like a window display
  FAM.ndedit = function (f, T, P, O) {
    var H = FH[f], els = [], st = f === 'story';
    var o = 0; [['red', 2.4, UAE.red], ['white', 1.1, '#FFFFFF'], ['green', 1.1, UAE.green], ['white', .5, '#FFFFFF'], ['black', 1.1, UAE.black]].forEach(function (q, i) {
      els.push(shp('e' + i, 'rect', { fill: q[2], name: 'Pinstripe (' + q[0] + ')' }, G(o, 0, q[1], H))); o += q[1];
    });
    var a = v(f, G(52, 10, 42, 98), G(55, 8, 39, 80), G(17, 74, 66, 72));
    els.push(shp('aring', 'arch', { fill: 'transparent', bc: '#C9A27A', bw: 3, name: 'Arch frame' }, G(a.x - 1.6, a.y - 1.6, a.w + 3.2, a.h + 1.6)));
    els.push(shp('arch', 'arch', { fill: '#F8DCD6', fill2: '#E3A6A0', gang: 180, name: 'Arch' }, a));
    els.push(glow('ag', '#FFFFFFA6', G(a.x - a.w * .1, a.y + a.h * .25, a.w * 1.2, a.w * 1.1)));
    var ly = a.y + a.h - v(f, 9, 8, 12);
    els.push(shp('ledge', 'rect', { fill: '#FFFFFF', fill2: '#E6D6CF', gang: 180, shd: 25, rad: .4, name: 'Glass shelf' }, G(a.x + 3, ly, a.w - 6, 1.6)));
    var X = 11, W = st ? 82 : 38, al = st ? 'c' : 'l';
    var top = [ndKick(T, '#A0705A', G(X, 0, W, 0), { al: al, ls: 26 }),
      ndH1(T, { fn: 'dmserif', fw: 400, fs: v(f, 15.5, 13, 15), lh: 88, col: '#1E1716', al: al }, G(X, 0, W - 1, 0)),
      line('rule', UAE.red, G(st ? 44 : X, 0, 12, .7), 'Red rule'),
      ndMega(T, { fn: 'playfair', fw: 700, fi: true, fs: v(f, 8.6, 7, 9.5), col: UAE.green, al: al }, G(X, 0, W, 0)),
      ndOff(T, { fill: 'transparent', col: '#1E1716', pd: 0, fs: v(f, 3.4, 2.9, 3.8), ls: 14, al: al }, G(X, 0, W, 0)),
      ndAr(T, UAE.red, G(X, 0, W, 0), { al: al })];
    column(top, v(f, 10, 8, 10), v(f, 92, 70, 66), v(f, 2.6, 1.8, 2.6), false);
    els = els.concat(top);
    els.push(ndLogo('#3A2A26', v(f, G(X, 4, W, 0), G(X, 3, W, 0), G(25, 4, 50, 0)), { al: al }));
    els.push(prods());
    if (st) ndFoot(els, T, f, a.y + a.h + 4.5, 24, 52, 9, '#6E5A50', { fill: '#1E1716', sh: 'rect', rad: .4 });
    else ndFoot(els, T, f, v(f, 98, 75, 0), X, v(f, 36, 34, 0), v(f, 7.4, 6.8, 0), '#6E5A50', { fill: '#1E1716', sh: 'rect', rad: .4 }, 'l');
    var cx = a.x + a.w / 2, s = Math.min(1.3, a.w / 42);
    var sl = [slot(cx - 9 * s, ly + .4, 40 * s, 10 * s, 0), slot(cx + 9 * s, ly + .4, 36 * s, 10 * s, 0), slot(cx, ly + .6, 30 * s, 12 * s, 0),
      slot(cx - 12 * s, a.y + a.h + 1.2, 13 * s, 17 * s, 0), slot(cx + 12 * s, a.y + a.h + 1.2, 13 * s, 18 * s, 0)];
    return { bg: solidBg('#FFFBF6', '#F6EDE4', 'linear', 'none', '#000000', 0, 180), els: els, slots: sl };
  };

  // ND6 ── THE BLOOM: a pearl ground and one great flower behind the products -- an outer ring of pearl
  //        petals and an inner ring in satin red, green and black; the heading in fine spaced capitals
  FAM.ndbloom = function (f, T, P, O) {
    var els = [], s = v(f, 1, .7, 1.4);
    var C = v(f, { x: 50, y: 80 }, { x: 50, y: 62 }, { x: 50, y: 118 }), F = { x: C.x, y: C.y - 5 * s };
    els.push(glow('bg1', '#F4BFC3B3', G(C.x - 70 * s, C.y - 60 * s, 140 * s, 120 * s)));
    function ring(n, R, p, off, cols, pre, sh) {
      for (var i = 0; i < n; i++) {
        var th = (i / n) * 360 + off, rd = th * Math.PI / 180, x = F.x + Math.cos(rd) * R * s - p * s / 2, y = F.y + Math.sin(rd) * R * s - p * s / 2;
        els.push(petal(pre + i, cols[i % cols.length], G(x, y, p * s, p * s, th + 45), { shd: sh }));
      }
    }
    ring(12, 25.5, 18, -90, [['#FFF3F2', '#EDB5B7'], ['#FFFFFF', '#F3CFD0']], 'o', 16);
    ring(9, 17, 14, -90, [SATIN.red, SATIN.green, SATIN.black], 'm', 24);
    ring(6, 9.5, 10, -60, [SATIN.white], 'i', 24);
    els.push(glow('core', '#FFFFFFF2', G(F.x - 9 * s, F.y - 9 * s, 18 * s, 18 * s)));
    var top = [ndLogo('#3A2A2E', G(25, 0, 50, 0)),
      ndH1(T, { fn: 'montserrat', fw: 400, up: true, ls: 22, fs: v(f, 7.4, 6, 8), lh: 110, col: '#1E1719' }, G(2, 0, 96, 0)),
      ndMega(T, { fn: 'playfair', fw: 700, fi: true, fs: v(f, 11, 8.6, 12), col: UAE.red }, G(5, 0, 90, 0)),
      ndOff(T, {}, G(20, 0, 60, 0)), ndAr(T, '#6A4A50', G(10, 0, 80, 0))];
    column(top, v(f, 4, 3, 8), C.y - 41 * s, v(f, 2, 1.2, 3), false);
    els = els.concat(top);
    var by = C.y + 15 * s;
    els.push(shp('pod', 'ellipse', { fill: '#FFFFFF', fill2: '#E2C2C0', gang: 180, shd: 30, name: 'Pearl dish' }, G(C.x - 30 * s, by - 3.6 * s, 60 * s, 7.6 * s)));
    els.push(prods());
    ndFoot(els, T, f, by + v(f, 7, 5, 9), v(f, 27, 29, 22), v(f, 46, 42, 56), v(f, 7.8, 6.8, 9), '#7A6066', { fill: UAE.green, fill2: '#006A30', gang: 180 });
    var sl = [slot(C.x - 14 * s, by - 1, 34 * s, 12 * s, -3), slot(C.x + 14 * s, by - 1, 34 * s, 12 * s, 3), slot(C.x, by + .4, 40 * s, 17 * s, 0), slot(C.x - 23 * s, by + 1.4, 18 * s, 19 * s, -4)];
    return { bg: solidBg('#FFFFFF', '#F3E3E3', 'radial'), els: els, slots: sl };
  };

  // ── UAE NATIONAL DAY, ROUND TWO (owner, 15 Nov 2026, on nd-silk: "i like this one, need 5 more like this, but
  //    totally different and with different styles"). The same family -- logo, spaced kicker, "National Day",
  //    "Mega Offer" in script, the offer value, an Arabic line, the newest products, one button, tabby / tamara,
  //    the trust line -- in five new moods: champagne foil, a watercolour garden, lilac glass, wine velvet and a
  //    pearl arch with a flag-coloured sky. ──
  function bubble(id, g, op) { return shp(id, 'circle', { fill: '#FFFFFFA6', fill2: '#FFFFFF0D', gang: 150, bc: '#FFFFFFE6', bw: 1.6, op: op == null ? 90 : op, name: 'Bubble' }, g); }
  // a five-petal flower: petals pointing out from the centre, a dark or golden heart
  function flower(els, id, cx, cy, d, pc, cc, rot, n) {
    n = n || 5; var pw = d * .42, ph = d * .5, R = d * .27;
    for (var i = 0; i < n; i++) {
      var th = i / n * 360 + (rot || 0) - 90, rd = th * Math.PI / 180;
      els.push(shp(id + i, 'ellipse', { fill: pc[1], fill2: pc[0], gang: 180, shd: 6, op: 88, name: 'Flower petal' }, G(cx + Math.cos(rd) * R - pw / 2, cy + Math.sin(rd) * R - ph / 2, pw, ph, th + 90)));
    }
    els.push(shp(id + 'c', 'circle', { fill: cc[0], fill2: cc[1], gang: 135, name: 'Flower heart' }, G(cx - d * .11, cy - d * .11, d * .22, d * .22)));
  }
  // a leaf: a long petal whose base sits at (x, y), pointing at angle a
  function leaf(id, x, y, len, a, c) {
    var p = len / 1.41, rd = a * Math.PI / 180, cx = x + Math.cos(rd) * len / 2, cy = y + Math.sin(rd) * len / 2;
    return petal(id, c, G(cx - p / 2, cy - p / 2, p, p, a + 45), { shd: 4, op: 88, gang: 0, name: 'Leaf' });
  }
  // one tier of a glass pedestal: a clear body with a lit rim and a reflection
  function glassTier(els, id, x, y, w, h, eh) {
    els.push(shp(id + 'b', 'ellipse', { fill: '#FFFFFF59', fill2: '#E9D5FF4D', gang: 180, bc: '#FFFFFFB3', bw: 1.6, name: 'Glass base' }, G(x, y + h - eh / 2, w, eh)));
    els.push(shp(id + 'r', 'rect', { fill: '#FFFFFF8C', fill2: '#FFFFFF26', gang: 90, name: 'Glass pedestal' }, G(x, y, w, h)));
    els.push(line(id + 'l', '#FFFFFFCC', G(x + w * .08, y + h * .15, .5, h * .7), 'Glass reflection'));
    els.push(shp(id + 't', 'ellipse', { fill: '#FFFFFFE6', fill2: '#F4E6FFB3', gang: 180, bc: '#FFFFFF', bw: 2, shd: 12, name: 'Glass top' }, G(x, y - eh / 2, w, eh)));
  }

  // ND7 ── CHAMPAGNE BOW: warm ivory and champagne inside a double gold-foil frame, gold arcs sweeping out of
  //        one corner, and in the opposite corner the flag's four colours as thin silk ribbons tied in a bow
  //        with a gold knot; the products on a champagne dais with a gold rim
  FAM.ndgold = function (f, T, P, O) {
    var H = FH[f], els = [], s = v(f, 1.1, .8, 1.44);
    els.push(glow('ga', '#FFFFFFF0', v(f, G(-30, -25, 160, 95), G(-30, -30, 160, 80), G(-40, -20, 180, 130))));
    els.push(glow('gb', '#E7C68A8C', v(f, G(5, 58, 90, 64), G(12, 42, 76, 54), G(-5, 92, 110, 90)), 'Champagne glow'));
    els.push(shp('arc1', 'ring', { bc: '#D2AA58', bw: 2.4, name: 'Gold foil arc' }, G(-50, H - v(f, 34, 26, 44), 84, 84)));
    els.push(shp('arc2', 'ring', { bc: '#E6C985', bw: 1, name: 'Gold foil arc' }, G(-45, H - v(f, 29, 21, 39), 74, 74)));
    els.push(shp('fr1', 'rect', { fill: 'transparent', bc: '#C99B45', bw: 3.4, rad: 1, name: 'Gold foil frame' }, G(3, 3, 94, H - 6)));
    els.push(shp('fr2', 'rect', { fill: 'transparent', bc: '#E2C27A', bw: 1.2, rad: .6, name: 'Gold foil frame (inner)' }, G(4.7, 4.7, 90.6, H - 9.4)));
    // the bow: four wavy silk tails falling off the corner, two loops each side, a gold knot
    var K = v(f, { x: 89.5, y: 11 }, { x: 90.5, y: 10 }, { x: 89.5, y: 11.5 }), b = v(f, 1, .85, 1.1);
    [['tr', SATIN.red, 'red', 64, 30], ['tg', SATIN.green, 'green', 73, 36], ['tw', SATIN.white, 'white', 81, 27], ['tb', SATIN.black, 'black', 88, 22]].forEach(function (q) {
      var L = q[4] * b, rd = q[3] * Math.PI / 180, cx = K.x + Math.cos(rd) * L / 2, cy = K.y + Math.sin(rd) * L / 2;
      els.push(shp(q[0], 'silk', { fill: q[1][0], fill2: q[1][1], gang: 100, shd: 14, name: 'Silk ribbon (' + q[2] + ')' }, G(cx - L / 2, cy - 2.4 * b, L, 4.8 * b, q[3])));
    });
    [['l1', SATIN.red, -1, 22, 0], ['l2', SATIN.white, -1, 12, .9], ['l3', SATIN.green, 1, -22, 0], ['l4', SATIN.black, 1, -12, .9]].forEach(function (q) {
      var w = (11 - q[4] * 3) * b, h = (6 - q[4] * 1.6) * b, cx = K.x + q[2] * (w / 2 - .6 * b), cy = K.y - 1.6 * b;
      els.push(shp(q[0], 'ring', { bc: q[1][1], bw: 9, shd: 16, name: 'Bow loop' }, G(cx - w / 2, cy - h / 2, w, h, q[3])));
    });
    els.push(shp('knot', 'rect', { fill: '#F6DE9C', fill2: '#B98A36', gang: 135, rad: 1, shd: 24, name: 'Gold knot' }, G(K.x - 1.9 * b, K.y - 3.6 * b, 3.8 * b, 4.4 * b)));
    var by = v(f, 90, 72, 136), pw = v(f, 66, 54, 78), ph = v(f, 5, 4, 6.5), eh = v(f, 9, 7.4, 11), px = 50 - pw / 2;
    els.push(glow('ps', '#7A5A2A40', G(px - 6, by + ph - eh * .4, pw + 12, eh * 1.6), 'Dais shadow'));
    els.push(shp('dbase', 'ellipse', { fill: '#D7B57A', name: 'Dais base' }, G(px, by + ph - eh / 2, pw, eh)));
    els.push(shp('dbody', 'rect', { fill: '#F8ECD3', fill2: '#CDAA6C', gang: 90, name: 'Dais' }, G(px, by, pw, ph)));
    els.push(shp('dtop', 'ellipse', { fill: '#FFFDF7', fill2: '#EFDDBB', gang: 180, name: 'Dais top' }, G(px, by - eh / 2, pw, eh)));
    els.push(shp('drim', 'ring', { bc: '#C99B45', bw: 4, name: 'Gold rim' }, G(px, by - eh / 2, pw, eh)));
    [[9, v(f, 30, 24, 40), 3.4], [13, v(f, 62, 50, 92), 2.4], [86, v(f, 50, 40, 74), 2.8], [92, v(f, 70, 56, 104), 2]].forEach(function (q, i) { els.push(spark('sp' + i, '#C9A04E', G(q[0] - q[2] / 2, q[1], q[2], q[2]), 85)); });
    var top = [ndLogo('#4A3520', G(25, 0, 50, 0)), ndKick(T, '#A8823F', G(15, 0, 70, 0)),
      ndH1(T, { fn: 'dmserif', fw: 400, fs: v(f, 12.5, 10.2, 13.5), lh: 100, col: '#33261A' }, G(13, 0, 74, 0)),
      ndMega(T, { fs: v(f, 11, 8.6, 12.5), col: '#D21F3C' }, G(8, 0, 84, 0)),
      ndOff(T, { fill: '#F4DC9C', fill2: '#C3953F', gang: 90, col: '#33261A' }, G(20, 0, 60, 0)), ndAr(T, '#94683A', G(10, 0, 80, 0))];
    column(top, v(f, 6.5, 6, 10), v(f, 50, 41, 80), v(f, 1.6, 1.1, 2.6), false);
    els = els.concat(top);
    els.push(prods());
    ndFoot(els, T, f, by + ph + eh / 2 + v(f, 2.4, 1.6, 4), v(f, 27, 29, 22), v(f, 46, 42, 56), v(f, 7.6, 6.6, 9), '#8A7050', { fill: '#E2334A', fill2: '#A80F26', gang: 180 });
    var sl = [slot(50 - 14 * s, by + .4, 30 * s, 12 * s, -3), slot(50 + 14 * s, by + .4, 30 * s, 12 * s, 3), slot(50, by + 1, 37 * s, 16 * s),
      slot(50 - 25 * s, by + 2.2, 18 * s, 20 * s, -4), slot(50 + 25 * s, by + 2.2, 18 * s, 20 * s, 4)];
    return { bg: solidBg('#FFFCF5', '#EEDDBF', 'linear', 'sparkle', '#C9A24A', 35, 170), els: els, slots: sl };
  };

  // ND8 ── WATERCOLOUR GARDEN: pastel sage, soft washes of red and green, and two bouquets of red poppies and
  //        white anemones with black hearts and green leaves framing the products on a white dish
  FAM.ndgarden = function (f, T, P, O) {
    var els = [], s = v(f, 1, .76, 1.42), by = v(f, 92, 74, 138);
    var RED = ['#FF9AA3', '#E0303F'], WHT = ['#FFFFFF', '#EDE3DE'], BLU = ['#FFE1E3', '#F4A2AA'], DARK = ['#3A3A3A', '#0B0B0B'], GOLD = ['#FFE8A8', '#D9A63A'];
    els.push(glow('w1', '#F59AA466', v(f, G(-30, 60, 70, 60), G(-30, 42, 60, 56), G(-35, 100, 80, 80)), 'Red wash'));
    els.push(glow('w2', '#86C39A66', v(f, G(60, 50, 70, 64), G(66, 36, 60, 56), G(55, 88, 80, 84)), 'Green wash'));
    els.push(glow('w3', '#FFFFFFE6', G(10, by - 36 * s, 80, 52 * s), 'White glow'));
    els.push(glow('w4', '#F59AA44D', v(f, G(66, -20, 50, 40), G(70, -18, 44, 34), G(64, -20, 56, 50)), 'Red wash'));
    // leaves first, flowers over them
    var LV = v(f,
      [[10, 96, 16, -120], [4, 88, 14, -70], [14, 106, 12, -20], [90, 90, 16, -60], [96, 82, 13, -110], [86, 104, 12, -160], [92, 10, 12, 160], [86, 4, 10, 120], [7, 15, 10, 30], [94, 22, 9, 70]],
      [[9, 77, 13, -120], [3, 70, 12, -70], [13, 86, 10, -20], [91, 72, 13, -60], [97, 65, 11, -110], [87, 84, 10, -160], [93, 8, 10, 160], [87, 3, 8, 120], [6, 12, 8, 30], [95, 17, 7, 70]],
      [[10, 141, 20, -120], [3, 130, 17, -70], [15, 154, 15, -20], [90, 134, 20, -60], [97, 122, 16, -110], [85, 151, 15, -160], [92, 14, 15, 160], [85, 6, 12, 120], [7, 20, 12, 30], [94, 30, 11, 70]]);
    LV.forEach(function (q, i) { els.push(leaf('lf' + i, q[0], q[1], q[2], q[3], i % 2 ? ['#9BD1A6', '#2E8B57'] : ['#B9DDB4', '#3E9A5E'])); });
    var FL = v(f,
      [[7, 84, 15, RED, DARK, 10], [17, 97, 11, WHT, DARK, 30], [3, 101, 9, BLU, GOLD, 0], [93, 79, 15, WHT, DARK, 20], [85, 95, 10, RED, DARK, 0], [98, 96, 9, BLU, GOLD, 40], [91, 8, 11, RED, DARK, 15], [6, 7, 8, WHT, DARK, 0]],
      [[6, 66, 12, RED, DARK, 10], [15, 78, 9, WHT, DARK, 30], [2, 82, 7, BLU, GOLD, 0], [94, 62, 12, WHT, DARK, 20], [86, 77, 8, RED, DARK, 0], [99, 78, 7, BLU, GOLD, 40], [92, 6, 9, RED, DARK, 15], [5, 5, 7, WHT, DARK, 0]],
      [[7, 128, 19, RED, DARK, 10], [18, 146, 14, WHT, DARK, 30], [2, 150, 11, BLU, GOLD, 0], [93, 122, 19, WHT, DARK, 20], [84, 142, 13, RED, DARK, 0], [99, 143, 11, BLU, GOLD, 40], [91, 12, 14, RED, DARK, 15], [6, 10, 10, WHT, DARK, 0]]);
    FL.forEach(function (q, i) { flower(els, 'f' + i, q[0], q[1], q[2], q[3], q[4], q[5]); });
    [[24, v(f, 64, 50, 98), 3, 30, RED], [78, v(f, 60, 46, 92), 2.6, -40, WHT], [70, v(f, 112, 90, 162), 2.4, 60, RED]].forEach(function (q, i) {
      els.push(petal('lp' + i, q[4], G(q[0], q[1], q[2], q[2], q[3]), { shd: 10, op: 85, name: 'Loose petal' }));
    });
    var pw = v(f, 64, 52, 74), eh = v(f, 8, 6.6, 10);
    els.push(shp('pod', 'ellipse', { fill: '#FFFFFF', fill2: '#C3D7BE', gang: 180, shd: 34, name: 'White dish' }, G(50 - pw / 2, by - eh / 2, pw, eh)));
    var top = [ndLogo('#2F4A38', G(25, 0, 50, 0)), ndKick(T, '#4F8A62', G(15, 0, 70, 0)),
      ndH1(T, { fw: 400, fs: v(f, 13, 10.4, 14), lh: 102, col: '#1E3A2A' }, G(10, 0, 80, 0)),
      ndMega(T, { fs: v(f, 11.5, 9, 13), col: '#D92B3D' }, G(10, 0, 80, 0)),
      ndOff(T, { fill: '#2F7A4B', fill2: '#1F5E37', gang: 90 }, G(20, 0, 60, 0)), ndAr(T, '#2F6B45', G(10, 0, 80, 0))];
    column(top, v(f, 5, 4, 9), v(f, 52, 42, 80), v(f, 1.6, 1.1, 2.6), false);
    els = els.concat(top);
    els.push(prods());
    ndFoot(els, T, f, by + eh / 2 + v(f, 3.6, 2.6, 6), v(f, 27, 29, 22), v(f, 46, 42, 56), v(f, 7.6, 6.6, 9), '#4E6B57', { fill: '#E23A4C', fill2: '#B81D33', gang: 180 });
    var sl = [slot(50 - 13 * s, by - 1, 30 * s, 12 * s, -3), slot(50 + 13 * s, by - 1, 30 * s, 12 * s, 3), slot(50, by + .2, 37 * s, 16 * s),
      slot(50 - 24 * s, by + 1.4, 18 * s, 19 * s, -4), slot(50 + 24 * s, by + 1.4, 18 * s, 19 * s, 4)];
    return { bg: solidBg('#F1F6EE', '#D3E2CE', 'linear', 'none', '#FFFFFF', 0, 175), els: els, slots: sl };
  };

  // ND9 ── LILAC GLASS: a glossy lilac-to-pink gradient, floating bubbles and sparkles, the products on a
  //        two-tier glass pedestal, and the flag's four colours as one wavy satin ribbon along the bottom
  FAM.ndlilac = function (f, T, P, O) {
    var H = FH[f], els = [], s = v(f, 1.05, .78, 1.45), by = v(f, 83, 64.5, 125);
    els.push(glow('g1', '#C7B0FFB3', v(f, G(-40, -30, 100, 80), G(-40, -30, 90, 70), G(-45, -25, 120, 110)), 'Lilac glow'));
    els.push(glow('g2', '#FFA8CBB3', v(f, G(40, 60, 90, 70), G(45, 45, 80, 60), G(30, 100, 110, 90)), 'Pink glow'));
    els.push(glow('g3', '#FFFFFFD9', G(5, by - 44 * s, 90, 62 * s), 'Light'));
    var BB = v(f,
      [[7, 30, 8], [86, 25, 10], [91, 52, 5], [4, 56, 5], [80, 6, 4.4], [12, 8, 3.6], [90, 74, 7], [8, 76, 6], [60, 44, 2.8]],
      [[6, 26, 7], [88, 22, 8], [92, 44, 4.4], [3, 46, 4.4], [82, 5, 3.6], [11, 6, 3], [92, 60, 6], [6, 62, 5], [62, 40, 2.4]],
      [[6, 44, 10], [84, 38, 12], [90, 84, 6], [4, 90, 6], [80, 10, 5], [12, 12, 4.4], [88, 112, 9], [7, 116, 8], [62, 72, 3.4]]);
    BB.forEach(function (q, i) { els.push(bubble('bu' + i, G(q[0] - q[2] / 2, q[1], q[2], q[2]))); });
    [[20, v(f, 40, 32, 62), 3.6], [76, v(f, 40, 34, 64), 2.8], [94, v(f, 100, 80, 150), 2.4], [5, v(f, 102, 82, 152), 3]].forEach(function (q, i) { els.push(spark('sp' + i, '#FFFFFF', G(q[0] - q[2] / 2, q[1], q[2], q[2]), 95)); });
    var w1 = v(f, 70, 58, 80), h1 = v(f, 5, 3.4, 7), e1 = v(f, 8.6, 7.2, 10.5), w2 = w1 * .52, h2 = v(f, 5.5, 3.6, 8), e2 = e1 * .66;
    els.push(glow('ps', '#7A3F9A40', G(50 - w1 / 2 - 6, by + h1 - e1 * .3, w1 + 12, e1 * 1.6), 'Pedestal shadow'));
    glassTier(els, 'k1', 50 - w1 / 2, by, w1, h1, e1);
    var by2 = by - h2 - e1 * .2;
    glassTier(els, 'k2', 50 - w2 / 2, by2, w2, h2 + e1 * .2, e2);
    // the ribbon: four satin bands in one wave along the foot of the canvas
    var rh = v(f, 10, 8, 13), ry = H - rh * 1.05, dy = rh * .15;
    [['rr', SATIN.red, 'red'], ['rw', SATIN.white, 'white'], ['rg', SATIN.green, 'green'], ['rb', SATIN.black, 'black']].forEach(function (q, i) {
      els.push(shp(q[0], 'silk', { fill: q[1][0], fill2: q[1][1], gang: 100, shd: 14, name: 'Satin ribbon (' + q[2] + ')' }, G(-12, ry + i * dy, 124, rh, -2)));
    });
    var top = [ndLogo('#4A2C5E', G(25, 0, 50, 0)), ndKick(T, '#8B55B8', G(15, 0, 70, 0)),
      ndH1(T, { fw: 900, fs: v(f, 12.5, 10, 13.5), lh: 100, col: '#3A1D52', ls: -1 }, G(5, 0, 90, 0)),
      ndMega(T, { fs: v(f, 11.5, 9, 13), col: '#E2234B' }, G(10, 0, 80, 0)),
      ndOff(T, { fill: '#FFFFFFCC', bc: '#FFFFFF', bw: 2, col: '#3A1D52', shd: 10 }, G(20, 0, 60, 0)), ndAr(T, '#6E3A92', G(10, 0, 80, 0))];
    column(top, v(f, 4.5, 4, 9), by2 - v(f, 31, 22, 44), v(f, 1.5, 1, 2.6), false);
    els = els.concat(top);
    els.push(prods());
    ndFoot(els, T, f, by + h1 + e1 / 2 + v(f, 2.2, 1.6, 4), v(f, 28, 30, 22), v(f, 44, 40, 56), v(f, 7.4, 6.4, 9), '#5E3F74', { fill: '#F0384F', fill2: '#FF7AA2', gang: 90 });
    var sl = [slot(50 - 9 * s, by2 - .2, 25 * s, 13 * s, -2), slot(50 + 9 * s, by2 - .2, 25 * s, 12 * s, 2), slot(50, by2 + .6, 31 * s, 15 * s),
      slot(50 - 24 * s, by + 1.4, 19 * s, 17 * s, -3), slot(50 + 24 * s, by + 1.4, 19 * s, 17 * s, 3)];
    return { bg: solidBg('#E8DAFB', '#FFD0E4', 'linear', 'sparkle', '#FFFFFF', 75, 165), els: els, slots: sl };
  };

  // ND10 ── VELVET SPOTLIGHT: deep wine velvet, a beam of warm light falling on a velvet plinth with a gold rim,
  //         a satin ribbon in the flag's colours sweeping behind it; the heading in gold serif
  FAM.ndvelvet = function (f, T, P, O) {
    var els = [], s = v(f, 1, .76, 1.42), by = v(f, 90, 72, 136);
    els.push(glow('beam', '#FFE3B866', G(18, -20, 64, by + 26), 'Spotlight'));
    els.push(glow('g2', '#C2184B59', v(f, G(-40, 70, 80, 70), G(-40, 50, 70, 60), G(-45, 110, 90, 90)), 'Rose glow'));
    var ry = by - v(f, 16, 13, 24), rh = v(f, 24, 19, 32), dy = rh * .14;
    [['rr', SATIN.red, 'red'], ['rw', SATIN.white, 'white'], ['rg', SATIN.green, 'green'], ['rb', ['#5A5A5A', '#0A0A0A'], 'black']].forEach(function (q, i) {
      els.push(shp(q[0], 'silk', { fill: q[1][0], fill2: q[1][1], gang: 100, shd: 30, name: 'Satin ribbon (' + q[2] + ')' }, G(-20, ry + i * dy, 140, rh, -14)));
    });
    els.push(glow('pool', '#FFD9A6B3', G(8, by - 14 * s, 84, 26 * s), 'Spotlight pool'));
    var pw = v(f, 62, 50, 74), ph = v(f, 6, 5, 8), eh = v(f, 8.6, 7.2, 10.5), px = 50 - pw / 2;
    els.push(shp('vb', 'ellipse', { fill: '#2A0510', name: 'Plinth base' }, G(px, by + ph - eh / 2, pw, eh)));
    els.push(shp('vr', 'rect', { fill: '#3E0A18', fill2: '#8E1F3C', gang: 90, name: 'Velvet plinth' }, G(px, by, pw, ph)));
    els.push(line('vline', '#E3BF6E', G(px, by + ph - .9, pw, .45), 'Gold band'));
    els.push(shp('vt', 'ellipse', { fill: '#9C2846', fill2: '#5E0F25', gang: 180, name: 'Plinth top' }, G(px, by - eh / 2, pw, eh)));
    els.push(shp('vrim', 'ring', { bc: '#E3BF6E', bw: 4, name: 'Gold rim' }, G(px, by - eh / 2, pw, eh)));
    [[8, v(f, 28, 22, 64), 3.4], [92, v(f, 44, 36, 62), 2.6], [88, v(f, 62, 50, 92), 2.2], [12, v(f, 58, 46, 90), 2]].forEach(function (q, i) { els.push(spark('sp' + i, '#F2D58E', G(q[0] - q[2] / 2, q[1], q[2], q[2]), 90)); });
    var top = [ndLogo('#EBCB85', G(25, 0, 50, 0)), ndKick(T, '#E3BF6E', G(10, 0, 80, 0)),
      ndH1(T, { fw: 400, fs: v(f, 13, 10.4, 14), lh: 102, col: '#F4D894', tsh: 40 }, G(5, 0, 90, 0)),
      ndMega(T, { fs: v(f, 11, 8.6, 12.5), col: '#FFF2E2', tsh: 30 }, G(10, 0, 80, 0)),
      ndOff(T, { fill: 'transparent', bc: '#E3BF6E', bw: 3, col: '#F4D894' }, G(20, 0, 60, 0)), ndAr(T, '#F2D2C2', G(10, 0, 80, 0))];
    column(top, v(f, 5, 4, 9), v(f, 52, 42, 82), v(f, 1.7, 1.1, 2.6), false);
    els = els.concat(top);
    els.push(prods());
    ndFoot(els, T, f, by + ph + eh / 2 + v(f, 2.6, 1.8, 4), v(f, 27, 29, 22), v(f, 46, 42, 56), v(f, 7.6, 6.6, 9), '#D9B9A6', { fill: '#F6DC9A', fill2: '#C3933D', gang: 180, col: '#3A0812' });
    var sl = [slot(50 - 14 * s, by + .2, 30 * s, 12 * s, -3), slot(50 + 14 * s, by + .2, 30 * s, 12 * s, 3), slot(50, by + .8, 37 * s, 16 * s),
      slot(50 - 24 * s, by + 2, 18 * s, 19 * s, -4), slot(50 + 24 * s, by + 2, 18 * s, 19 * s, 4)];
    return { bg: solidBg('#7C1733', '#22040E', 'radial', 'sparkle', '#F2D28B', 30), els: els, slots: sl };
  };

  // ND11 ── ARCH SKY: white and pearl, one tall soft arch like a window onto a sky that runs from a blush
  //         red dawn through white into mint green, a black hairline round it; modern bold sans with script
  FAM.ndsky = function (f, T, P, O) {
    var els = [], sq = f === 'square';
    var a = v(f, G(15, 45, 70, 56), G(49, 6, 47, 88), G(10, 64, 80, 80));
    els.push(glow('ga', '#F7C7CB66', G(a.x - 22, a.y - 16, a.w + 44, a.w + 10), 'Blush glow'));
    els.push(shp('ao', 'arch', { fill: 'transparent', bc: '#141414', bw: 2.6, name: 'Hairline (black)' }, G(a.x - 2.2, a.y - 2.2, a.w + 4.4, a.h + 2.2)));
    els.push(shp('sky', 'arch', { fill: '#F48C98', fill2: '#FFF4F1', gang: 180, name: 'Sky (red to white)' }, a));
    els.push(shp('sky2', 'rect', { fill: '#FFFFFF00', fill2: '#8ED3A6', gang: 180, name: 'Sky (white to green)' }, G(a.x, a.y + a.h * .48, a.w, a.h * .52)));
    els.push(glow('sun', '#FFFFFFF2', G(a.x + a.w * .2, a.y + a.h * .06, a.w * .6, a.w * .5), 'Sun glow'));
    [[.06, .3, .42, .12], [.55, .22, .4, .1], [.3, .44, .5, .12]].forEach(function (q, i) {
      els.push(glow('cl' + i, '#FFFFFFD9', G(a.x + a.w * q[0], a.y + a.h * q[1], a.w * q[2], a.w * q[3]), 'Cloud'));
    });
    els.push(line('sill', '#141414', G(a.x - 2.2, a.y + a.h - .5, a.w + 4.4, .5), 'Sill line'));
    var k = a.w / 74 * v(f, 1, 1.25, 1), pw = a.w * .84, ph = 4.6 * k, eh = 6.4 * k, by = a.y + a.h - ph - eh * .5 - 1 * k, px = a.x + (a.w - pw) / 2, cx = a.x + a.w / 2;
    podium(els, 'pp', px, by, pw, ph, ['#FFFFFF', '#F2ECE8'], ['#FFFFFF', '#E3DAD5'], eh);
    [[a.x + a.w * .12, a.y + a.h * .16, 3], [a.x + a.w * .84, a.y + a.h * .3, 2.4]].forEach(function (q, i) { els.push(spark('sp' + i, '#FFFFFF', G(q[0], q[1], q[2], q[2]), 95)); });
    var X = 5, W = sq ? 40 : 90, al = sq ? 'l' : 'c';
    var top = [ndLogo('#141414', G(X, 0, W, 0), { al: al }), ndKick(T, '#2E8B57', G(X, 0, W, 0), { al: al, ls: sq ? 26 : 36 }),
      ndH1(T, { fn: 'montserrat', fw: 800, fs: v(f, 10.5, 7, 12), lh: 102, ls: -2, col: '#141414', al: al }, G(X, 0, sq ? 38.5 : W, 0)),
      ndMega(T, { fs: v(f, 10, 8, 12.5), col: '#E0293F', al: al }, G(X, 0, W, 0)),
      ndOff(T, { fill: '#E0293F', al: al }, G(sq ? X : 20, 0, sq ? W : 60, 0)), ndAr(T, '#141414', G(X, 0, W, 0), { al: al })];
    if (sq) column(top, 8, 66, 2.2, false); else column(top, v(f, 4, 0, 7), v(f, 41.5, 0, 59), v(f, 1.2, 0, 2.4), false);
    els = els.concat(top);
    els.push(prods());
    if (sq) ndFoot(els, T, f, 72, X, 36, 6.8, '#6B6B6B', { fill: '#141414' }, 'l');
    else ndFoot(els, T, f, a.y + a.h + v(f, 3, 0, 5), v(f, 28, 0, 22), v(f, 44, 0, 56), v(f, 7.4, 0, 9), '#6B6B6B', { fill: '#141414' });
    var sp = v(f, 1, .78, 1), sl = [slot(cx - 13 * k * sp, by + .2, 30 * k, 12 * k, -3), slot(cx + 13 * k * sp, by + .2, 30 * k, 12 * k, 3), slot(cx, by + .8, 37 * k, 16 * k),
      slot(cx - 25 * k * sp, by + 1.8, 18 * k, 19 * k, -4), slot(cx + 25 * k * sp, by + 1.8, 18 * k, 19 * k, 4)];
    return { bg: solidBg('#FFFFFF', '#F2EDE9', 'linear', 'none', '#000000', 0, 180), els: els, slots: sl };
  };

  // ── UAE NATIONAL DAY, ROUND THREE (owner, 19 Nov 2026, approved from the preview kept as shots/nd3/preview.html:
  //    04A / 04B "Glass & Dew" and 05A / 05B "Skyline Dusk", line-up and scattered, each with the plain heading or the
  //    "National Day" heading in the UAE flag's colours, in English and Arabic). Every part is its own layer: the logo,
  //    the top line, the heading (Heading style: Plain / UAE flag gradient), "Mega Offer", the offer pill, the button,
  //    the small print and its tiny flag; each product (swap, move, size, turn); and the scene -- for Glass & Dew the
  //    three flag-colour blobs, the frosted pane and the dew drops on the ivory background; for Skyline Dusk each firework
  //    burst, the skyline and the glossy floor on the dusk-sky background. Geometry measured from the preview (cqw). ──
  var ASP = { anua: .789, anuacream: .276, anuaeye: .194, azelaic: .424, balmpink: .263, balmred: .266, bdeye: .242, bdserum: .146, celimax: .373, dalba: .275,
    deodorant: .338, eosbutter: 1.213, eospeach: .482, eyemask: 1.03, glowvanilla: .387, guasha: .532, hairoil: .37, marymay: .218, nbz5: .944, nbz9: .349 };
  var ND3 = {
    dew: { h1: { fn: 'bodoni', fw: 500, fs: 14.2, lh: 92, ls: -1.5, col: '#101914' }, h2: { fn: 'jost', fw: 600, fs: 4.6, lh: 110, ls: 36, up: true, col: '#1D3A2B' },
      kc: '#1D3A2B', off: { fill: '#101914', col: '#F6F2EC' }, cta: { fill: '#FFFFFF', col: '#101914', shd: 35 }, tc: '#1D3A2B', hg: 'uae' },
    dusk: { h1: { fn: 'italiana', fw: 400, fs: 15.6, lh: 92, col: '#FFFFFF', shd: 100, shc: '#5A3C8C' }, h2: { fn: 'cormorant', fw: 600, fi: true, fs: 7.6, lh: 110, ls: 2, col: '#FFF6F8' },
      kc: '#FFFFFF', off: { fill: '#FFFFFFE6', col: '#6A4C8C' }, cta: { fill: '#6A4C8C', col: '#FFFFFF', shd: 0 }, tc: '#5A3F78', hg: 'uaed' }
  };
  // the words: logo, top line, heading, "Mega Offer", the offer pill, then the button, the small print and its flag
  function nd3Copy(f, T, O, S) {
    var ar = !!O.ar, k = v(f, 1, .88, 1), y = v(f, 6, 4.5, 6), top = [], foot = [];
    function at(h) { var y0 = y; y += h; return y0; }
    top.push(logo('logo', { lk: true, col: '#2A2228', fs: 3.73, name: 'Logo' }, G(30, at(11.79 * k), 40, 0, 0, k)));
    var kfs = ar ? 3.8 : 3, kick = tx('kick', T.kick, { fn: ar ? 'messiri' : 'jost', fw: 500, fs: kfs, lh: 120, ls: ar ? 0 : 28, col: S.kc, name: 'Top line' }, G(5, at(kfs * 1.2 * k + 1.6 * k), 90, 0, 0, k));
    var h1o = ar ? { fn: 'reemkufi', fw: 500, fs: 15, lh: 112, ls: 0, col: S.h1.col, shd: S.h1.shd, shc: S.h1.shc } : S.h1;
    if (O.hg) h1o = Object.assign({}, h1o, { hg: S.hg, shd: 0 });
    var h1 = tx('h1', T.h1, Object.assign({ name: 'Heading (National Day)' }, h1o), G(3, at(h1o.fs * h1o.lh / 100 * k + 1.19 * k), 94, 0, 0, k));
    var h2o = ar ? { fn: 'messiri', fw: 500, fs: 7, lh: 110, ls: 0, col: S.h2.col } : S.h2;
    var h2 = tx('h2', T.h2, Object.assign({ name: 'Offer line (Mega Offer)' }, h2o), G(5, at(h2o.fs * h2o.lh / 100 * k + 2.2 * k), 90, 0, 0, k));
    var off = tx('off', T.off, { fn: ar ? 'messiri' : 'jost', fw: 600, fs: 5.2, lh: 100, ls: ar ? 0 : 3, col: S.off.col, sh: 'pill', fill: S.off.fill, pd: 2, name: 'Offer pill (edit me)' }, G(10, at(0), 80, 0, 0, k));
    top.push(kick, h1, h2, off);
    var cy = v(f, 105.57, 81.2, 158.35), ch = 9.39 * k;
    foot.push(btn('cta', T.cta, { fn: ar ? 'messiri' : 'jost', fw: 600, fs: ar ? 4.4 : 3.8, ls: ar ? 0 : 12, up: !ar, sh: 'pill', fill: S.cta.fill, col: S.cta.col, shd: S.cta.shd, cr: undefined, name: 'Button' }, G(50 - 25.43 * k, cy, 50.86 * k, ch)));
    // the small print with a tiny UAE flag before it (after it, in Arabic): the pair is centred as the preview set it
    var ty = cy + ch + 1.3 * k, tw = (ar ? 35.1 : 46.8) * k, fw_ = 5.2 * k, gap = .8 * k, x0 = 50 - (tw + fw_ + gap) / 2;
    var fx = ar ? x0 + tw + gap : x0;
    foot.push(tx('trust', T.trust, { fn: ar ? 'messiri' : 'jost', fw: 500, fs: 2.7, lh: 120, ls: ar ? 0 : 4, col: S.tc, op: 90, al: ar ? 'r' : 'l', name: 'Small print' }, ar ? G(4, ty, x0 + tw - 4, 0, 0, k) : G(fx + fw_ + gap, ty, 96 - (fx + fw_ + gap), 0, 0, k)));
    foot.push({ id: 'tflag', t: 'art', art: 'flag:ae', sh: 'rect', rad: .4, bw: 2.5, bc: '#FFFFFFB3', name: 'UAE flag (small print)', g: G(fx, ty + (3.24 - 2.6) / 2 * k + .25 * k, fw_, 2.6 * k) });
    return { top: top, foot: foot };
  }
  // a line-up: the products side by side, overlapping a little, standing on one line (mirrored in Arabic, as the preview)
  function nd3Row(list, by, hs, ar, ss) {
    var ws = list.map(function (q) { return hs[q[1]] * ASP[q[0]]; }), tot = ws.reduce(function (a, b) { return a + b; }, 0) - 1.2 * (list.length - 1), x = 50 - tot / 2;
    return list.map(function (q, i) { var cx = x + ws[i] / 2; x += ws[i] - 1.2; var s = slot(ar ? 100 - cx : cx, by, hs[q[1]], ws[i] * 1.3); if (ss) s.ss = ss; return s; });
  }
  // scattered: [product, x %, y % of the zone, height, turn]; the zone is a band of the canvas
  function nd3Scatter(list, z0, z1, sc) {
    return list.map(function (q) { var h = q[3] * sc, cy = z0 + q[2] / 100 * (z1 - z0); return slot(q[1], cy + h / 2, h, h * ASP[q[0]] * 1.3 + 2, q[4]); });
  }
  var ND3_ROW = {
    dew: [['bdserum', 'm'], ['anuacream', 'm'], ['nbz9', ''], ['eospeach', ''], ['azelaic', 'm'], ['marymay', 's'], ['eyemask', 'w']],
    dusk: [['balmred', 's'], ['hairoil', 'm'], ['nbz9', ''], ['glowvanilla', ''], ['celimax', 'm'], ['bdeye', 's'], ['anua', 'w']]
  };
  var ND3_SCAT = {      // the hero last, so it stands in front; the bottom two kept clear of the button's words
    dew: [['guasha', 14, 18, 20, -28], ['marymay', 86, 16, 22, 24], ['bdserum', 9, 62, 34, -18], ['anuacream', 91, 60, 34, 16], ['nbz9', 29, 50, 38, -10], ['azelaic', 71, 50, 36, 9], ['eyemask', 21, 92, 20, -12], ['nbz5', 79, 92, 20, 10], ['eospeach', 50, 55, 46, 0]],
    dusk: [['balmred', 12, 16, 22, -26], ['bdeye', 88, 18, 24, 22], ['dalba', 10, 62, 34, -16], ['hairoil', 90, 62, 36, 14], ['celimax', 30, 54, 34, -9], ['nbz9', 70, 52, 38, 10], ['anua', 20, 92, 20, -10], ['eosbutter', 80, 92, 20, 12], ['glowvanilla', 50, 58, 46, 0]]
  };
  function nd3Slots(kind, f, O) {
    var ar = !!O.ar;
    if (O.sc) {
      var L = ND3_SCAT[kind], sl = v(f, nd3Scatter(L, 62.5, 107.5, 1), nd3Scatter(L, ar ? 58.5 : 57, 84, .7), nd3Scatter(L, 67.6, 156.4, 1));
      // nothing may sit over the button's words: a product low enough to reach them steps up (in the middle) or out (at the sides)
      var cb = v(f, 105.57, 81.2, 158.35) + (v(f, 1, .88, 1) * 9.39 - 4.2) / 2, half = ar ? 15 : 19;
      sl.forEach(function (s, i) {
        if (s.by <= cb + .5) return;
        var rd = Math.abs(s.r || 0) * Math.PI / 180, w = (s.h * ASP[L[i][0]] * Math.cos(rd) + s.h * Math.sin(rd)) * .78;   // its drawn box, turned
        if (Math.abs(s.cx - 50) < 12) s.by = cb; else if (s.cx < 50) s.cx = Math.min(s.cx, 50 - half - w / 2); else s.cx = Math.max(s.cx, 50 + half + w / 2);
      });
      return sl;
    }
    var dew = kind === 'dew', row = ND3_ROW[kind], ss = dew ? '' : 'reflect';
    var post = { '': 38.76, m: 31.2, s: 24.9, w: 16.39 }, story = { '': 46, m: 37, s: 29.6, w: 19.5 }, sq = {}, q = ar ? .66 : dew ? .78 : .72;
    Object.keys(post).forEach(function (c) { sq[c] = post[c] * q; });
    return v(f, nd3Row(row, dew ? 99.6 : 104.6, post, ar, ss), nd3Row(row, dew ? 78.6 : 79.6, sq, ar, ss), nd3Row(row, dew ? 152.4 : 157.4, story, ar, ss));
  }

  // ND12 ── GLASS & DEW (04A line-up / 04B scattered): a frosted glass pane over soft blobs of the flag's green, red and
  //         black on ivory, dew drops on the glass; the heading in Bodoni, "MEGA OFFER" in spaced capitals
  FAM.nddew = function (f, T, P, O) {
    var H = FH[f], els = [], c = nd3Copy(f, T, O, ND3.dew), ar = !!O.ar;
    els.push(shp('bgreen', 'haze', { fill: '#00843D', op: 72, name: 'Green blob' }, G(-14, v(f, 47.5, 38, 67.6), 62, 62)));
    els.push(shp('bred', 'haze', { fill: '#C8102E', op: 72, name: 'Red blob' }, G(58, v(f, 57.5, 46, 81.8), 54, 54)));
    els.push(shp('bblack', 'haze', { fill: '#141414', op: 40, name: 'Black blob' }, G(34, H - 26, 40, 40)));
    var py = v(f, 55, ar ? 51 : 48, 78.22);
    els.push(shp('pane', 'glass', { fill: '#FFFFFF8C', fill2: '#FFFFFF2E', gang: 160, rad: 4, bw: 3, bc: '#FFFFFFBF', name: 'Glass pane' }, G(6, py, 88, v(f, 113.76, 91, 161.78) - py)));
    els.push({ id: 'dew', t: 'art', art: f === 'story' ? 'nd:dew-tall' : 'nd:dew', sh: 'rect', name: 'Dew drops', g: f === 'story' ? G(8, 45, 84, 82) : G(8, v(f, 31, 30, 0), 84, 58) });
    els.push(prods());
    els = els.concat(c.top, c.foot);
    return { bg: solidBg('#F6F2EC'), els: els, slots: nd3Slots('dew', f, O) };
  };

  // ND13 ── SKYLINE DUSK (05A line-up / 05B scattered): a pastel lavender-peach-rose dusk, an original city skyline (no real
  //         building), soft fireworks in the flag's colours kept to the edges, a glossy floor the products reflect in
  FAM.nddusk = function (f, T, P, O) {
    var H = FH[f], els = [], c = nd3Copy(f, T, O, ND3.dusk), st = f === 'story';
    var FW = [[10, 12, 9, 'green'], [90, 10, 10, 'red'], [6, 46, 6, 'white'], [94, 44, 7, 'gold'], [80, 60, 5, 'green, small'], [18, 62, 5, 'red, small']];
    FW.forEach(function (q, i) {
      var s = st ? 1.4222 : 1, S = (q[2] * 1.05 + 3.3) * s, R = q[2] * s, cx = st ? Math.max(R * .75, Math.min(100 - R * .75, q[0] * s - 21.11)) : q[0];
      els.push({ id: 'fw' + (i + 1), t: 'art', art: 'nd:fw-' + (i + 1), name: 'Firework (' + q[3] + ')', g: G(+(cx - S).toFixed(2), +(q[1] * s - S).toFixed(2), +(2 * S).toFixed(2), +(2 * S).toFixed(2)) });
    });
    els.push({ id: 'city', t: 'art', art: 'nd:skyline', name: 'City skyline', g: v(f, G(0, 34, 100, 74), G(0, 9, 100, 74), G(-21.11, 48.36, 142.22, 105.24)) });
    var fy = H * .76, fh = H * .24;
    els.push(shp('floor1', 'rect', { fill: '#FFECE600', fill2: '#FFF0ECBF', gang: 180, name: 'Glossy floor (fade)' }, G(0, +fy.toFixed(2), 100, +(fh * .4).toFixed(2))));
    els.push(shp('floor2', 'rect', { fill: '#FFF0ECBF', fill2: '#FBE9E3', gang: 180, name: 'Glossy floor' }, G(0, +(fy + fh * .4).toFixed(2), 100, +(fh * .6 + .5).toFixed(2))));
    els.push(prods());
    els = els.concat(c.top, c.foot);
    var bg = solidBg('#8E86C6', '#F4E1D6', 'linear', 'none', '#000000', 0, 180);
    bg.stops = [{ c: '#8E86C6', p: 0 }, { c: '#C9A9D6', p: 34 }, { c: '#F2BFC0', p: 62 }, { c: '#F7D4C0', p: 78 }, { c: '#F4E1D6', p: 100 }];
    return { bg: bg, els: els, slots: nd3Slots('dusk', f, O) };
  };

  // ── palettes ──
  function pal(bg1, bg2, ink, sub, acc, acc2, card, extra) {
    return Object.assign({ bg1: bg1, bg2: bg2, ink: ink, sub: sub, acc: acc, acc2: acc2, card: card, btnTxt: '#FFFFFF' }, extra || {});
  }
  var PAL = {
    noir: pal('#1B1A1D', '#060607', '#FFFFFF', '#CFCACE', '#F0091E', '#B5000F', '#232123', { card2: '#151416', logo: '#FFFFFF', disc: '#F0091E33' }),
    blushRed: pal('#FFF6F8', '#F7DCE3', '#141214', '#5C5257', '#E5153A', '#B30E2C', '#FFFFFF'),
    night: pal('#1D2557', '#0B0F2B', '#FFF5E1', '#D9D2F0', '#E8B954', '#B8862E', '#141A44', { btnTxt: '#1B1508', disc: '#2A3373', disc2: '#141A44', logo: '#E8B954', pc: '#E8B954' }),
    plumGold: pal('#4A1838', '#22091A', '#FFF3E6', '#E7CFDC', '#E9B949', '#B8862E', '#2E0F24', { btnTxt: '#22091A', disc: '#5E2449', logo: '#E9B949' }),
    emerald: pal('#0F5A44', '#06261D', '#FFF7E3', '#CFE6DC', '#E3B85C', '#B88A2E', '#0A3A2C', { btnTxt: '#14210F', disc: '#147258', disc2: '#0A3A2C', logo: '#E3B85C' }),
    sandTeal: pal('#F6EBDD', '#E9D3B8', '#1F3B3A', '#5C6B66', '#0F7C74', '#0A5852', '#FFFFFF', { disc: '#E3CBAA' }),
    cream: pal('#FFF9F0', '#F3E4CC', '#3A2A12', '#7A6A55', '#B8862E', '#8A6420', '#FFFFFF', { disc: '#F0DDBA' }),
    uae: pal('#FFFFFF', '#EEF2EE', '#0B0B0B', '#4A514C', '#00843D', '#006130', '#FFFFFF', { rib: '#EF3340', disc: '#00843D1F', pc: '#00843D' }),
    roseGold: pal('#FFF1EC', '#F2D2C6', '#3B2420', '#7A5A52', '#C9826E', '#A0614F', '#FFFFFF', { disc: '#F2C7B7' }),
    blush: pal('#FFEFF4', '#FBD3DF', '#3D1A28', '#7A5563', '#D9467A', '#A82D5B', '#FFFFFF', { disc: '#FBC4D5' }),
    magenta: pal('#FF2E7E', '#C2125A', '#FFFFFF', '#FFE1EC', '#FFD23F', '#FFB800', '#FFFFFF', { btnTxt: '#3A0A1F', disc: '#FFFFFF2E', logo: '#FFFFFF', badge: '#FFD23F', badgeTxt: '#3A0A1F', pc: '#FFFFFF' }),
    sunny: pal('#FFE36E', '#FFB547', '#2B1A05', '#5B4317', '#E2475B', '#C02F43', '#FFFFFF', { disc: '#FFFFFF66', pc: '#FFFFFF' }),
    midTeal: pal('#0E3B43', '#062127', '#F2FBF9', '#BFDCD8', '#7FD1C4', '#4FB3A4', '#0B2E34', { btnTxt: '#062127', disc: '#14525C', logo: '#7FD1C4' }),
    silver: pal('#FFFFFF', '#E9EBEE', '#111318', '#5A606B', '#111318', '#3A3F48', '#FFFFFF', { rib: '#111318', disc: '#DADDE2', spark: '#9AA1AC' }),
    cyber: pal('#0A0F2E', '#020414', '#FFFFFF', '#B9C3FF', '#2EE6FF', '#7B5CFF', '#11183F', { btnTxt: '#02121A', disc: '#2EE6FF26', logo: '#FFFFFF', bandTxt: '#02121A', pc: '#2EE6FF' }),
    red1111: pal('#E8102E', '#9E0018', '#FFFFFF', '#FFD9DE', '#FFE14D', '#FFC400', '#FFFFFF', { btnTxt: '#5A0010', disc: '#FFFFFF26', logo: '#FFFFFF', badge: '#111111', pc: '#FFFFFF' }),
    purple: pal('#2B0F4C', '#120626', '#FFFFFF', '#D8C8F2', '#C77DFF', '#9B4DE0', '#FFFFFF', { btnTxt: '#FFFFFF', disc: '#C77DFF33', logo: '#FFFFFF', pc: '#C77DFF' }),
    gold: pal('#16120C', '#050403', '#FFF6DF', '#D8CDB4', '#E9C46A', '#B8923A', '#1F1910', { btnTxt: '#1A1408', disc: '#E9C46A2A', logo: '#E9C46A', spark: '#E9C46A', pc: '#E9C46A' }),
    valentine: pal('#FFE4EA', '#FFB9C9', '#5A0F24', '#8A4155', '#E0234E', '#B5123A', '#FFFFFF', { disc: '#FFFFFF80' }),
    violet: pal('#F3EDFF', '#DCCBFF', '#2A1650', '#5E4E80', '#7B3FE4', '#5A27B8', '#FFFFFF', { disc: '#E6DAFF' }),
    school: pal('#FFF4D6', '#FFE2A3', '#1E2A4A', '#4E5878', '#2F6BFF', '#1E4FD1', '#FFFFFF', { disc: '#FFD36B', rib: '#FF5A5F' }),
    spf: pal('#FFF3D1', '#FFD89A', '#3A2108', '#6E5236', '#FF7A1A', '#E35D00', '#FFFFFF', { disc: '#FFFFFF8C' }),
    ice: pal('#EAF6FF', '#C9E3FA', '#0F2A44', '#4A6680', '#2E86DE', '#1B5FA8', '#FFFFFF', { disc: '#FFFFFF99', pc: '#FFFFFF' }),
    diwali: pal('#5A1E04', '#2A0B00', '#FFF2D6', '#F2CFA6', '#FFB627', '#FF7A00', '#3D1402', { btnTxt: '#2A0B00', disc: '#7A2D08', logo: '#FFB627' }),
    xmas: pal('#0E4A2E', '#062617', '#FFFFFF', '#CFE5D8', '#E23A3A', '#B11F1F', '#FFFFFF', { disc: '#E23A3A33', logo: '#FFFFFF', spark: '#F5D27A', pc: '#F5D27A', cink: '#0E3B26', csub: '#4E6B5C' }),
    hotPink: pal('#FFF0F6', '#FFD1E4', '#1F0B14', '#6A4655', '#FF2D87', '#D10F67', '#FFFFFF', { disc: '#FFC2DC', badge: '#1F0B14' }),
    coral: pal('#FFE9E2', '#FFC7B5', '#3B1209', '#7A4A3E', '#F2542D', '#C73E1D', '#FFFFFF', { disc: '#FFFFFF8C' }),
    mint: pal('#ECFBF4', '#C6F0DD', '#0D3326', '#4C6B5F', '#13A06F', '#0C7A54', '#FFFFFF', { disc: '#FFFFFFA6' }),
    tealPay: pal('#E9FFF6', '#C2F7E3', '#0C2A22', '#456B5E', '#0C2A22', '#253F37', '#FFFFFF', { disc: '#3BFFC84D', rib: '#0C2A22' }),
    peach: pal('#FFF2E8', '#FFD9C2', '#3A1E10', '#7A5A48', '#E8743B', '#C45A25', '#FFFFFF', { disc: '#FFE6D6' }),
    lilac: pal('#F6F0FF', '#E3D5FF', '#26164A', '#5C4D80', '#8E5CF7', '#6B3BD9', '#FFFFFF', { disc: '#FFFFFF99' }),
    aqua: pal('#E3FAFF', '#D8DCFF', '#0C2B3D', '#4A6577', '#1BA8C9', '#6C7BFF', '#FFFFFF', { disc: '#FFFFFF80' }),
    pearl: pal('#FBFAFF', '#E7ECF7', '#1A2238', '#55607A', '#7C8DB5', '#5A6B95', '#FFFFFF', { disc: '#FFFFFFB3' }),
    slate: pal('#1F2A33', '#0D1318', '#F3F6F8', '#AEBBC5', '#5BC0EB', '#2F8FBF', '#2A3742', { btnTxt: '#0D1318', disc: '#5BC0EB26', logo: '#F3F6F8' }),
    navyNight: pal('#141B33', '#070A16', '#F4F1FF', '#B9B6D3', '#9C8CFF', '#6F5CE6', '#1C2545', { disc: '#9C8CFF26', logo: '#F4F1FF' }),
    honey: pal('#FFF8E6', '#FBE3A8', '#3A2A05', '#6E5A2E', '#D99A00', '#B07A00', '#FFFFFF', { disc: '#FFE9A8' }),
    sage: pal('#F1F5EC', '#D8E4CC', '#24321C', '#5A6A50', '#5E7F45', '#456133', '#FFFFFF', { disc: '#E3EDD6' }),
    berry: pal('#3B0A2A', '#1A0412', '#FFEFF7', '#E8BFD3', '#FF4FA3', '#D12A7E', '#521238', { disc: '#FF4FA333', logo: '#FFEFF7' }),
    sky: pal('#E8F3FF', '#BBD9FF', '#0B2140', '#3F5A7A', '#FF5A5F', '#E03E44', '#FFFFFF', { disc: '#FFFFFF99' })
  };

  // ── the templates (owner, 13 Nov: "50+ fully highly intact conversion ads ... beauty and skincare ...
  //    different occasions as per UAE and global") ──
  var AR = {
    ramadan: 'رمضان كريم', eid: 'عيد مبارك', adha: 'عيد أضحى مبارك', nday: 'كل عام والإمارات بخير', mom: 'كل عام وأنتِ بخير يا أمي',
    women: 'يوم المرأة الإماراتية', dsf: 'عروض مهرجان دبي للتسوق', dss: 'مفاجآت صيف دبي', hijri: 'كل عام وأنتم بخير', white: 'عروض الجمعة البيضاء',
    bf: 'عروض بلاك فرايدي بدأت الآن', free: 'توصيل مجاني داخل الإمارات', gifts: 'هدايا مجانية مع كل طلب', newin: 'وصل حديثاً', sale: 'تخفيضات لفترة محدودة',
    pay: 'قسّميها على 4 دفعات', back: 'عاد من جديد', glow: 'إشراقة بشرتك تبدأ هنا', gift: 'هدية مثالية', limited: 'الكمية محدودة', shop: 'تسوّقي الآن'
  };
  var PP = ['guasha', 'anuacream', 'celimax', 'dalba', 'glowvanilla', 'marymay', 'balmpink', 'balmred', 'anua', 'eyemask', 'hairoil', 'deodorant'];
  function rot(n) { return PP.slice(n % PP.length).concat(PP.slice(0, n % PP.length)); }
  var RIB = 'K-Beauty Bliss ♥';
  var L = [];
  function add(cat, id, name, fam, P, T, O, tags, pool) { L.push({ id: id, name: name, cat: cat, fam: fam, P: P, T: T, O: O || {}, tags: tags || '', pp: pool || rot(L.length * 2) }); }

  // UAE & Gulf occasions
  add('uae', 'ramadan-kareem', 'Ramadan Kareem', 'arch', PAL.night, { kick: 'RAMADAN KAREEM', h1: 'Glow Through\nRamadan', h2: 'Up to 25% off selected K-beauty', ar: AR.ramadan, cta: 'Shop the Ramadan Edit', trust: 'Fast delivery across the UAE' }, { hf: 'playfair', hs: 8.6, hw: 700, pat: 'lattice', po: 10, bgk: 'radial' }, 'ramadan iftar suhoor night lantern moon');
  add('uae', 'ramadan-nights', 'Ramadan Nights Offer', 'frame', PAL.plumGold, { kick: 'RAMADAN NIGHTS', h1: 'Self-Care\nAfter Iftar', script: 'your evening ritual', h2: 'Gift with every order', ar: AR.ramadan, cta: 'Shop Now', trust: 'Offer ends with the holy month' }, { icon: 'star8', pat: 'lattice', po: 8 }, 'ramadan evening night routine gift');
  add('uae', 'eid-al-fitr', 'Eid al-Fitr Sale', 'arch', PAL.emerald, { kick: 'EID AL-FITR', h1: 'Eid Glow\nSale', h2: 'Up to 30% off + free gift', ar: AR.eid, cta: 'Shop Eid Deals', trust: 'Order early for Eid delivery' }, { icon: 'star8', hs: 13, pat: 'lattice', po: 10 }, 'eid fitr sale gifts');
  add('uae', 'eid-gifts', 'Eid Mubarak Gifts', 'collage', PAL.cream, { kick: 'EID MUBARAK', h1: 'Gifts They\'ll Glow For', h2: 'Ready-to-gift K-beauty sets', ar: AR.eid, cta: 'Find a Gift', trust: 'Gift wrapping on request', rib: RIB }, { hf: 'playfair', hs: 6.8, hw: 700, icon: 'moon', pat: 'dots', po: 10 }, 'eid gifts sets family');
  add('uae', 'eid-al-adha', 'Eid al-Adha', 'frame', PAL.sandTeal, { kick: 'EID AL-ADHA', h1: 'Celebrate\nin Glow', script: 'Eid Mubarak', h2: 'Special Eid prices this week', ar: AR.adha, cta: 'Shop Now', trust: 'Delivery across all Emirates' }, { icon: 'star8', pat: 'none' }, 'eid adha celebration');
  add('uae', 'national-day', 'UAE National Day', 'card', PAL.uae, { kick: 'UAE NATIONAL DAY · 2 DEC', h1: 'Proud\nTo Glow', h2: 'Up to 55% off — limited time', sub: 'Celebrating the UAE with our community', ar: AR.nday, cta: 'Shop National Day', trust: 'Selected products · while stocks last', rib: 'UAE National Day' }, { icon: 'uae', hs: 14, pat: 'stripes', po: 6, bgk: 'linear' }, 'national day uae union 2 december flag');
  add('uae', 'emirati-women', "Emirati Women's Day", 'frame', PAL.roseGold, { kick: '28 AUGUST', h1: "Emirati\nWomen's Day", script: 'strong, kind & radiant', h2: 'A thank-you gift with every order', ar: AR.women, cta: 'Shop the Edit', trust: 'For the women who inspire us' }, { icon: 'flower', hs: 8.2 }, 'emirati women day august');
  add('uae', 'mothers-day', "Mother's Day (UAE · 21 March)", 'frame', PAL.blush, { kick: "MOTHER'S DAY · 21 MARCH", h1: 'For Mum,\nWith Love', script: 'pamper the one who cares', h2: 'Gift sets she will love', ar: AR.mom, cta: 'Shop Gifts for Mum', trust: 'Order by 18 March for UAE delivery' }, { icon: 'heart', hs: 8.6 }, 'mothers day mom 21 march gift');
  add('uae', 'dsf', 'Dubai Shopping Festival', 'bignum', PAL.magenta, { kick: 'DUBAI SHOPPING FESTIVAL', num: '50%', h1: 'OFF K-Beauty', h2: 'On selected favourites', ar: AR.dsf, cta: 'Shop DSF Deals', trust: 'For the festival only', badge: 'DSF\nDEALS' }, { hs: 9, kc: '#FFFFFF', oc: '#FFFFFF', pat: 'rays', po: 10, bgk: 'radial' }, 'dsf dubai shopping festival winter sale');
  add('uae', 'dss', 'Dubai Summer Surprises', 'split', PAL.sunny, { kick: 'DUBAI SUMMER SURPRISES', h1: 'Summer\nSurprises', h2: 'Up to 40% off sun care', ar: AR.dss, cta: 'Shop Summer', trust: 'Limited-time summer offers', badge: 'SUMMER\nSALE' }, { icon: 'sun', pat: 'dots', po: 12 }, 'dss dubai summer surprises sale sun');
  add('uae', 'hijri-new-year', 'Hijri New Year', 'arch', PAL.midTeal, { kick: 'HIJRI NEW YEAR', h1: 'A Fresh Start\nFor Your Skin', h2: 'New-year routine picks', ar: AR.hijri, cta: 'Start Your Routine', trust: 'Fast delivery across the UAE' }, { hf: 'playfair', hs: 7.8, hw: 700, icon: 'star8', pat: 'lattice', po: 9 }, 'hijri islamic new year muharram');
  add('uae', 'white-friday', 'White Friday', 'card', PAL.silver, { kick: 'WHITE FRIDAY', h1: 'White\nFriday', h2: 'Our biggest deals of the year', ar: AR.white, cta: 'Grab the Deals', trust: 'Limited time · while stocks last', rib: 'K-Beauty Bliss Specials ♥' }, { icon: 'gift', pat: 'none', bgk: 'radial' }, 'white friday november sale');
  add('uae', 'ramadan-iftar-box', 'Ramadan Gift Box', 'bundle', PAL.night, { kick: 'RAMADAN GIFTING', h1: 'The Ramadan Box', h2: 'Three favourites, one gift', ar: AR.ramadan, cta: 'Shop the Box', trust: 'Gift wrapping on request', badge: 'GIFT\nREADY' }, { hf: 'playfair', hs: 8.4, hw: 700, pat: 'lattice', po: 8 }, 'ramadan gift box set bundle');

  // Global occasions
  add('global', 'black-friday', 'Black Friday', 'card', PAL.noir, { kick: 'NOV 21 – NOV 30', h1: 'Black\nFriday', h2: 'FREE GIFTS', sub: 'with every order', ar: AR.bf, cta: 'Shop Now', trust: 'While stocks last', rib: 'K-Beauty Bliss Specials ♥' }, { icon: 'gift', hs: 16, pat: 'none', bgk: 'radial' }, 'black friday november sale gifts');
  add('global', 'cyber-monday', 'Cyber Monday', 'stripe', PAL.cyber, { kick: 'ONLINE ONLY', h1: 'Cyber Monday', band: 'CYBER DEALS', h2: 'Extra 15% off sitewide', sub: 'Use code at checkout', ar: AR.sale, cta: 'Shop Online Deals', trust: 'Ends midnight' }, { icon: 'bolt', hf: 'montserrat', pat: 'grid', po: 14 }, 'cyber monday online sale');
  add('global', 'singles-1111', '11.11 Sale', 'bignum', PAL.red1111, { kick: 'THE 11.11 SALE', num: '11.11', h1: 'Mega Sale', h2: 'Deals on your K-beauty faves', ar: AR.sale, cta: 'Shop 11.11', trust: 'One day only', badge: 'UP TO\n40% OFF' }, { kc: '#FFE14D', oc: '#FFFFFF', hs: 10, pat: 'rays', po: 10 }, '11.11 singles day double eleven');
  add('global', 'double-1212', '12.12 Sale', 'coupon', PAL.purple, { kick: 'THE 12.12 SALE', h1: '12.12 Deals', h2: 'USE CODE', code: 'GLOW1212', sub: 'Extra savings on K-beauty favourites', ar: AR.sale, cta: 'Shop 12.12', trust: 'Valid 12 December only' }, { icon: 'tag', pat: 'dots', po: 12, bgk: 'radial' }, '12.12 double twelve code');
  add('global', 'new-year', 'New Year, New Glow', 'type', PAL.gold, { kick: 'HELLO NEW YEAR', word: 'GLOW', h1: 'New Year, New Glow', h2: 'Fresh routine, fresh start', ar: AR.glow, cta: 'Shop New Year Picks', trust: 'Limited-time offers' }, { icon: 'sparkle', pat: 'sparkle', po: 30, wop: 90 }, 'new year january resolution');
  add('global', 'valentines', "Valentine's Day", 'frame', PAL.valentine, { kick: '14 FEBRUARY', h1: 'Love Your\nSkin', script: "Valentine's gifts", h2: 'Gift sets for someone special', ar: AR.gift, cta: 'Shop Valentine\'s', trust: 'Order by 11 Feb for delivery' }, { icon: 'heart', pat: 'none' }, 'valentine love gift february');
  add('global', 'womens-day', "International Women's Day", 'split', PAL.violet, { kick: '8 MARCH', h1: "Women's\nDay", h2: 'Celebrate you — 20% off', ar: 'يوم المرأة العالمي', cta: 'Treat Yourself', trust: 'Today only', badge: 'TREAT\nYOURSELF' }, { icon: 'flower', ring: true, pat: 'none' }, 'international women day 8 march');
  add('global', 'back-to-school', 'Back to School', 'collage', PAL.school, { kick: 'BACK TO SCHOOL', h1: 'Ready, Set, Glow', h2: 'Easy routines for busy days', ar: 'العودة إلى المدارس', cta: 'Shop Essentials', trust: 'Fast delivery across the UAE', rib: 'Back to School' }, { hf: 'montserrat', hs: 7.4, hw: 900, icon: 'star', pat: 'grid', po: 10 }, 'back to school september students');
  add('global', 'summer-spf', 'Summer Sale · SPF', 'split', PAL.spf, { kick: 'SUMMER SALE', h1: 'Sun-Ready\nSkin', h2: 'Up to 30% off SPF & after-sun', sub: 'Reapply every 2 hours outdoors', ar: 'عروض الصيف', cta: 'Shop Sun Care', trust: 'Limited-time summer prices', badge: 'SPF\nSEASON' }, { icon: 'sun', pat: 'rays', po: 10 }, 'summer sale spf sunscreen sun');
  add('global', 'winter-care', 'Winter Skincare', 'glass', PAL.ice, { kick: 'WINTER SKINCARE', h1: 'Cosy Skin\nSeason', h2: 'Rich creams & barrier care', ar: 'عناية الشتاء', cta: 'Shop Winter Care', trust: 'Fast delivery across the UAE' }, { icon: 'snow', hf: 'playfair', hs: 8.4, hw: 700 }, 'winter skincare cold dry');
  add('global', 'diwali', 'Diwali Glow', 'arch', PAL.diwali, { kick: 'HAPPY DIWALI', h1: 'Festival\nof Glow', h2: 'Festive offers on gift sets', ar: 'عيد ديوالي سعيد', cta: 'Shop Diwali', trust: 'Limited-time festive prices' }, { icon: 'diya', moon: false, hf: 'playfair', hs: 8.6, hw: 700, pat: 'dots', po: 14 }, 'diwali festival lights');
  add('global', 'christmas', 'Christmas Gifts', 'card', PAL.xmas, { kick: 'MERRY CHRISTMAS', h1: 'Gifts\nThat Glow', h2: 'Festive sets + free gift wrap', ar: AR.gift, cta: 'Shop Christmas', trust: 'Order by 20 Dec for delivery', rib: 'K-Beauty Bliss ♥' }, { icon: 'tree', hs: 14, pat: 'sparkle', po: 30 }, 'christmas xmas december gifts');
  add('global', 'black-friday-countdown', 'Black Friday · Last Chance', 'stripe', PAL.noir, { kick: 'BLACK FRIDAY', h1: 'Last Chance', band: 'ENDS TONIGHT', h2: 'Up to 40% off ends at midnight', ar: AR.bf, cta: 'Shop Before It Ends', trust: 'While stocks last' }, { icon: 'clock', hf: 'montserrat', pat: 'none' }, 'black friday last chance ends tonight countdown');
  add('global', 'singles-glow', 'Self-Love Day', 'glass', PAL.lilac, { kick: 'SELF-LOVE DAY', h1: 'Treat\nYourself', h2: 'Your routine, your rules', ar: 'دلّلي نفسك', cta: 'Shop Self-Care', trust: 'Limited-time offers' }, { icon: 'heart', hf: 'dancing', hs: 10, hw: 700 }, 'self love care day');

  // Evergreen sales
  add('sale', 'flash-sale', 'Flash Sale', 'stripe', PAL.hotPink, { kick: '24 HOURS ONLY', h1: 'Don\'t Miss Out', band: 'FLASH SALE', h2: 'Up to 50% off selected items', ar: AR.sale, cta: 'Shop the Flash Sale', trust: 'Ends at midnight · while stocks last' }, { icon: 'bolt', hf: 'montserrat', pat: 'dots', po: 10 }, 'flash sale 24 hours');
  add('sale', 'percent-off', '% Off Sale', 'bignum', PAL.coral, { kick: 'LIMITED TIME', num: '30%', h1: 'OFF Skincare', h2: 'On selected K-beauty', ar: AR.sale, cta: 'Shop the Sale', trust: 'Prices as marked online', badge: 'SALE' }, { hs: 9, pat: 'none' }, 'percent off sale discount');
  add('sale', 'bogo', 'Buy 1 Get 1', 'bundle', PAL.mint, { kick: 'BUY 1 GET 1 FREE', h1: 'Double the Glow', h2: 'On selected products', ar: 'اشترِ واحداً واحصل على الثاني مجاناً', cta: 'Shop BOGO', trust: 'While stocks last', badge: 'FREE' }, { n: 2, hs: 10 }, 'buy one get one bogo free');
  add('sale', 'bundle-save', 'Bundle & Save', 'bundle', PAL.peach, { kick: 'BUNDLE & SAVE', h1: 'Build Your Routine', h2: 'Save 20% on any 3', ar: 'وفّري أكثر مع الباقات', cta: 'Build a Bundle', trust: 'Mix & match across the shop', badge: 'SAVE\n20%' }, { hs: 9 }, 'bundle save routine set');
  add('sale', 'free-gifts', 'Free Gifts', 'card', PAL.blushRed, { kick: 'THIS WEEK ONLY', h1: 'Free\nGifts', h2: 'with every order', ar: AR.gifts, cta: 'Shop Now', trust: 'While gifts last', rib: 'K-Beauty Bliss Specials ♥' }, { icon: 'gift', hs: 16, pat: 'none' }, 'free gifts gift with purchase');
  add('sale', 'free-delivery', 'Free UAE Delivery', 'split', PAL.sky, { kick: 'ALL EMIRATES', h1: 'Free\nDelivery', h2: 'Across the UAE', sub: 'On qualifying orders — see site for details', ar: AR.free, cta: 'Shop Now', trust: 'Fast dispatch from the UAE', badge: 'FREE\nSHIPPING' }, { icon: 'truck', pat: 'none' }, 'free delivery shipping uae');
  add('sale', 'pay-later', 'Pay Later · Tabby & Tamara', 'split', PAL.tealPay, { kick: 'SHOP NOW, PAY LATER', h1: 'Split in 4.\nNo Interest.', h2: 'With tabby or tamara', ar: AR.pay, cta: 'Shop & Split', trust: 'Subject to provider approval' }, { icon: 'card', hf: 'montserrat', hs: 7.4, hw: 900, pat: 'none' }, 'tabby tamara pay later installments bnpl');
  add('sale', 'limited-stock', 'Limited Stock', 'stripe', PAL.berry, { kick: 'SELLING FAST', h1: 'Almost Gone', band: 'LIMITED STOCK', h2: 'Grab yours before it\'s gone', ar: AR.limited, cta: 'Shop Now', trust: 'Limited quantities available' }, { icon: 'clock', hf: 'montserrat', pat: 'none' }, 'limited stock selling fast scarcity');
  add('sale', 'restock', 'Back in Stock', 'type', PAL.mint, { kick: 'YOU ASKED, IT\'S BACK', word: 'BACK', h1: 'Back in Stock', h2: 'Your favourite has returned', ar: AR.back, cta: 'Get It Before It Goes', trust: 'Limited quantities' }, { icon: 'check' }, 'restock back in stock returned');
  add('sale', 'giveaway', 'Giveaway', 'coupon', PAL.hotPink, { kick: 'GIVEAWAY', h1: 'Win a Glow Set', h2: 'HOW TO ENTER', code: 'FOLLOW · LIKE · TAG', sub: 'Follow us, like this post & tag a friend', ar: 'سحب على هدية', cta: 'Enter Now', trust: 'Terms apply · winner announced on our page' }, { icon: 'gift', pat: 'sparkle', po: 24 }, 'giveaway contest win');
  add('sale', 'loyalty', 'Loyalty Points', 'coupon', PAL.honey, { kick: 'BLISS REWARDS', h1: 'Earn Points\nEvery Order', h2: 'THIS WEEK', code: '2X POINTS', sub: 'Redeem points on your next order', ar: 'اجمعي النقاط مع كل طلب', cta: 'Start Earning', trust: 'See site for programme details' }, { icon: 'coins' }, 'loyalty points rewards');
  add('sale', 'refer-friend', 'Refer a Friend', 'coupon', PAL.lilac, { kick: 'SHARE THE GLOW', h1: 'Refer a Friend', h2: 'YOU BOTH GET', code: 'AED 25 OFF', sub: 'When your friend places their first order', ar: 'ادعي صديقتك', cta: 'Invite a Friend', trust: 'Terms apply' }, { icon: 'people' }, 'refer friend referral invite');
  add('sale', 'clearance', 'Last Chance Sale', 'bignum', PAL.slate, { kick: 'FINAL REDUCTIONS', num: '60%', h1: 'OFF Last Pieces', h2: 'Once they\'re gone, they\'re gone', ar: 'الفرصة الأخيرة', cta: 'Shop Last Chance', trust: 'Selected items · final sale', badge: 'LAST\nCHANCE' }, { kc: '#5BC0EB', hs: 8.6, pat: 'grid', po: 8 }, 'clearance last chance final');

  // Products & routines
  add('product', 'new-arrival', 'New Arrival', 'type', PAL.pearl, { kick: 'JUST LANDED', word: 'NEW', h1: 'New Arrival', h2: 'Meet our latest K-beauty find', ar: AR.newin, cta: 'Discover Now', trust: 'Authentic Korean skincare' }, { icon: 'sparkle', wop: 55 }, 'new arrival launch just landed');
  add('product', 'bestsellers', 'Bestsellers', 'podium', PAL.cream, { kick: 'CUSTOMER FAVOURITES', h1: 'Our Bestsellers', h2: 'The products everyone reorders', ar: 'الأكثر مبيعاً', cta: 'Shop Bestsellers', trust: 'Authentic K-beauty · fast UAE delivery' }, { hf: 'playfair', hs: 8.6, hw: 700, pat: 'none' }, 'bestseller best seller favourites popular');
  add('product', 'product-hero', 'Product Hero', 'type', PAL.blush, { kick: 'SPOTLIGHT', word: 'GLOW', h1: 'Your New Favourite', h2: 'For a smooth, radiant look', ar: AR.glow, cta: 'Shop Now', trust: 'Authentic Korean skincare' }, { wop: 60, wf: 'dmserif', ws: 36 }, 'product hero single spotlight');
  add('product', 'routine-123', 'Routine 1-2-3', 'steps', PAL.sage, { kick: 'SIMPLE ROUTINE', h1: '3 Steps to Glow', steps: ['Cleanse', 'Treat', 'Moisturise'], sub: 'A simple routine for every day', ar: 'روتين من ٣ خطوات', cta: 'Shop the Routine', trust: 'Fast delivery across the UAE' }, { pat: 'none' }, 'routine steps 1 2 3 morning');
  add('product', 'night-routine', 'Night Routine', 'steps', PAL.navyNight, { kick: 'PM ROUTINE', h1: 'Your Night Ritual', steps: ['Cleanse', 'Serum', 'Seal'], sub: 'Wake up to soft, fresh-looking skin', ar: 'روتين المساء', cta: 'Shop Night Care', trust: 'Fast delivery across the UAE' }, { icon: 'moon', pat: 'sparkle', po: 18 }, 'night routine pm evening');
  add('product', 'ingredient', 'Ingredient Spotlight', 'split', PAL.aqua, { kick: 'INGREDIENT SPOTLIGHT', h1: 'Why We Love\nPDRN', h2: 'Supports a smooth, bouncy look', sub: 'Edit this to the ingredient you feature', ar: 'مكوّن مميّز', cta: 'Shop PDRN Picks', trust: 'Authentic Korean formulas', badge: 'KEY\nINGREDIENT' }, { icon: 'drop', badgeSh: 'circle', pat: 'none' }, 'ingredient spotlight pdrn niacinamide retinal');
  add('product', 'review', 'Customer Review', 'review', PAL.blush, { quote: '“Paste a real customer review here — short, honest and specific.”', who: '— Customer name, City', h2: 'Loved by our customers', ar: 'آراء عملائنا', cta: 'Shop Now', trust: 'Authentic K-beauty · fast UAE delivery' }, { pat: 'none' }, 'review testimonial customer quote stars');
  add('product', 'review-dark', 'Customer Review · Dark', 'review', PAL.gold, { quote: '“Replace with a real review from your customers.”', who: '— Customer name, Dubai', h2: 'Real words from real customers', ar: 'آراء عملائنا', cta: 'See the Product', trust: 'Fast delivery across the UAE' }, { pat: 'sparkle', po: 20 }, 'review testimonial dark luxe');
  add('product', 'kbeauty-edit', 'The K-Beauty Edit', 'collage', PAL.hotPink, { kick: 'CURATED FOR YOU', h1: 'The K-Beauty Edit', h2: 'Seoul\'s favourites, in the UAE', ar: 'مختارات الجمال الكوري', cta: 'Shop the Edit', trust: 'Authentic · fast UAE delivery', rib: 'K-Beauty Bliss ♥' }, { hf: 'playfair', hs: 7.4, hw: 700, icon: 'sparkle', pat: 'dots', po: 10 }, 'k-beauty edit korean curated');
  add('product', 'hydration', 'Hydration Boost', 'glass', PAL.aqua, { kick: 'HYDRATION', h1: 'Thirsty Skin?', h2: 'Layer up with hydrating picks', ar: 'ترطيب عميق', cta: 'Shop Hydration', trust: 'Fast delivery across the UAE' }, { icon: 'drop', hf: 'montserrat', hs: 7.6, hw: 900 }, 'hydration moisture dry skin water');
  add('product', 'glass-skin', 'Glass Skin', 'glass', PAL.pearl, { kick: 'THE GLASS SKIN EDIT', h1: 'Glass Skin\nStarts Here', h2: 'Dewy, glowing-looking skin', ar: 'بشرة زجاجية', cta: 'Get the Glow', trust: 'Authentic Korean skincare' }, { icon: 'sparkle', hf: 'playfair', hs: 8, hw: 700 }, 'glass skin dewy glow');
  add('product', 'gift-sets', 'Gift Sets', 'bundle', PAL.valentine, { kick: 'GIFT SETS', h1: 'Give the Glow', h2: 'Beautifully boxed favourites', ar: AR.gift, cta: 'Shop Gift Sets', trust: 'Gift wrapping on request', badge: 'GIFT\nREADY', rib: 'K-Beauty Bliss ♥' }, { hf: 'playfair', hs: 8.6, hw: 700 }, 'gift sets boxed present');
  add('product', 'mens-skincare', "Men's Skincare", 'split', PAL.slate, { kick: 'FOR HIM', h1: 'Skincare,\nSimplified', h2: 'Easy steps, sharp results', ar: 'عناية الرجل', cta: 'Shop Men\'s Care', trust: 'Fast delivery across the UAE' }, { icon: 'bolt', hf: 'montserrat', hs: 7.6, hw: 900, discSh: 'blob', pat: 'grid', po: 8 }, 'men mens grooming him');
  add('product', 'sensitive', 'Gentle Care', 'frame', PAL.sage, { kick: 'GENTLE CARE', h1: 'Kind to\nYour Skin', script: 'soothing picks', h2: 'Gentle formulas for daily care', ar: 'عناية لطيفة', cta: 'Shop Gentle Care', trust: 'Patch test new products first' }, { icon: 'leaf' }, 'sensitive gentle soothing calm');
  add('product', 'sunscreen-daily', 'Daily SPF', 'bignum', PAL.spf, { kick: 'EVERY. SINGLE. DAY.', num: 'SPF', h1: 'Your Daily Step', h2: 'Lightweight sun care to wear daily', ar: 'واقي الشمس يومياً', cta: 'Shop Sun Care', trust: 'Reapply every 2 hours outdoors', badge: 'DAILY\nHABIT' }, { hs: 8.4, pat: 'rays', po: 8 }, 'sunscreen spf daily sun');
  add('product', 'vegan-clean', 'Clean K-Beauty', 'podium', PAL.sage, { kick: 'THOUGHTFUL PICKS', h1: 'Clean K-Beauty', h2: 'Thoughtfully made favourites', ar: 'جمال كوري نظيف', cta: 'Shop the Edit', trust: 'Check each product page for details' }, { hf: 'playfair', hs: 8.6, hw: 700 }, 'clean vegan gentle');
  add('product', 'hair-body', 'Hair & Body', 'card', PAL.peach, { kick: 'BEYOND THE FACE', h1: 'Hair &\nBody Care', h2: 'Head-to-toe K-beauty', ar: 'عناية الشعر والجسم', cta: 'Shop Hair & Body', trust: 'Fast delivery across the UAE', rib: 'K-Beauty Bliss ♥' }, { icon: 'leaf', hf: 'playfair', hs: 10, hw: 700 }, 'hair body care oil');
  add('product', 'pay-later-hero', 'Treat Now, Pay Later', 'type', PAL.tealPay, { kick: 'SPLIT IN 4 · NO INTEREST', word: 'SPLIT', h1: 'Treat Now, Pay Later', h2: 'With tabby or tamara at checkout', ar: AR.pay, cta: 'Shop Now', trust: 'Subject to provider approval' }, { wop: 30 }, 'tabby tamara pay later');

  // UAE National Day -- six luxury-beauty looks (owner, 15 Nov 2026: heading "National Day", offer line "Mega
  // Offer", no year or number; beautiful, made for women, never classic or basic). Each carries 4-5 of the shop's
  // NEWEST products (cut-outs from kbeautybliss.com/shop/?orderby=date), its pool ordered so set 1 composes well.
  // Appended last so no older template's product rotation moves.
  var ND = pal('#FFF7F3', '#F3D2CF', '#2A1A1C', '#7A5558', '#EF3340', '#00843D', '#FFFFFF', { rib: '#00843D', logo: '#2A1A1C' });
  var NDT = 'national day uae union 2 december flag mega offer sale new arrivals women beauty';
  function ndT(o) { return Object.assign({ h1: 'National Day', h2: 'Mega Offer', off: 'Up to 50% off', trust: 'Selected products · while stocks last' }, o); }
  var ARN = 'عروض اليوم الوطني الكبرى', ARU = 'اليوم الوطني الإماراتي';
  add('uae', 'nd-silk', 'National Day · Satin Ribbons', 'ndsilk', ND, ndT({ kick: 'CELEBRATE IN GLOW', ar: ARN, cta: 'Shop the Offer' }), {}, NDT + ' satin ribbon silk', ['bdserum', 'anuaeye', 'nbz9', 'nbz5', 'eosbutter', 'bdeye', 'azelaic', 'eospeach']);
  add('uae', 'nd-pearl', 'National Day · Pearl Arch', 'ndpearl', ND, ndT({ kick: 'THE PEARL EDIT', ar: ARU, cta: 'Discover the Offer' }), {}, NDT + ' pearl arch podium', ['nbz9', 'bdserum', 'eospeach', 'azelaic', 'anuaeye', 'bdeye', 'nbz5', 'eosbutter']);
  add('uae', 'nd-night', 'National Day · Rose Night', 'ndnight', ND, ndT({ kick: 'AN EVENING OF GLOW', ar: ARN, cta: 'Shop Now' }), {}, NDT + ' night petals glow emerald', ['bdeye', 'anuaeye', 'eospeach', 'eosbutter', 'nbz5', 'bdserum', 'nbz9', 'azelaic']);
  add('uae', 'nd-gift', 'National Day · The Gift Bow', 'ndgift', ND, ndT({ kick: 'A GIFT FROM US TO YOU', ar: 'كل عام والإمارات بخير', cta: 'Unwrap the Offer' }), {}, NDT + ' gift bow ribbon present', ['anuaeye', 'azelaic', 'nbz9', 'eosbutter', 'bdeye', 'bdserum', 'eospeach', 'nbz5']);
  add('uae', 'nd-edit', 'National Day · Editorial', 'ndedit', ND, ndT({ kick: 'THE CELEBRATION ISSUE', h1: 'National\nDay', ar: ARU, cta: 'Shop the Edit' }), {}, NDT + ' editorial magazine arch', ['bdserum', 'bdeye', 'nbz9', 'nbz5', 'eosbutter', 'anuaeye', 'eospeach', 'azelaic']);
  add('uae', 'nd-bloom', 'National Day · The Bloom', 'ndbloom', ND, ndT({ ar: ARN, cta: 'Shop the Offer' }), {}, NDT + ' bloom flower petals', ['anuaeye', 'bdserum', 'eospeach', 'nbz5', 'bdeye', 'azelaic', 'nbz9', 'eosbutter']);
  // round two (owner, 15 Nov 2026, on nd-silk: "i like this one, need 5 more like this, but totally different and
  // with different styles") -- five more looks in the same family, appended last so no older rotation moves
  add('uae', 'nd-gold', 'National Day · Champagne Bow', 'ndgold', ND, ndT({ kick: 'A GIFT OF GLOW', ar: ARN, cta: 'Discover the Offer' }), {}, NDT + ' champagne gold foil bow ribbon ivory', ['nbz5', 'bdserum', 'anuaeye', 'eospeach', 'eosbutter', 'nbz9', 'bdeye', 'azelaic']);
  add('uae', 'nd-garden', 'National Day · Watercolour Garden', 'ndgarden', ND, ndT({ kick: 'IN FULL BLOOM', ar: ARU, cta: 'Shop Now' }), {}, NDT + ' flowers floral garden watercolour sage poppy', ['azelaic', 'nbz9', 'bdserum', 'eosbutter', 'anuaeye', 'eospeach', 'nbz5', 'bdeye']);
  add('uae', 'nd-lilac', 'National Day · Lilac Glass', 'ndlilac', ND, ndT({ kick: 'SPARKLE & GLOW', ar: ARN, cta: 'Grab the Deals' }), {}, NDT + ' lilac pink glass pedestal bubbles wave ribbon', ['anuaeye', 'bdeye', 'bdserum', 'nbz5', 'eospeach', 'nbz9', 'eosbutter', 'azelaic']);
  add('uae', 'nd-velvet', 'National Day · Velvet Spotlight', 'ndvelvet', ND, ndT({ kick: 'INDULGE IN GLOW', ar: 'كل عام والإمارات بخير', cta: 'Shop the Offer' }), {}, NDT + ' velvet burgundy wine gold spotlight luxury', ['bdserum', 'eospeach', 'nbz9', 'eosbutter', 'nbz5', 'anuaeye', 'azelaic', 'bdeye']);
  add('uae', 'nd-sky', 'National Day · Arch Sky', 'ndsky', ND, ndT({ kick: 'A FRESH NEW GLOW', ar: ARU, cta: 'Get the Deals' }), {}, NDT + ' minimal pearl arch window sky modern', ['nbz9', 'anuaeye', 'bdserum', 'azelaic', 'nbz5', 'bdeye', 'eosbutter', 'eospeach']);
  // round three (owner, 19 Nov 2026, approved from a preview): Glass & Dew and Skyline Dusk, line-up and scattered, each in
  // English and Arabic, with the plain heading or the UAE flag heading (the same switch is Heading style in the editor).
  // Never a year or "55". Appended last so no older rotation moves.
  var ND3T = {
    en: { h1: 'National Day', h2: 'Mega Offer', off: 'Up to 50% off', cta: 'Shop the Offer', trust: 'Selected products · while stocks last' },
    ar: { h1: 'اليوم الوطني', h2: 'عروض كبرى', off: 'خصم حتى ٥٠٪', cta: 'تسوّقي العرض', trust: 'منتجات مختارة · حتى نفاد الكمية' }
  };
  var ND3K = { dew: ['FRESH · DEWY · PROUD', 'نضارة · إشراق · فخر'], dusk: ['A NIGHT TO SHINE', 'ليلة للتألّق'] };
  var ND3P = {
    dew: ['bdserum', 'anuacream', 'nbz9', 'eospeach', 'azelaic', 'marymay', 'eyemask', 'bdeye', 'nbz5', 'anuaeye'],
    dews: ['guasha', 'marymay', 'bdserum', 'anuacream', 'nbz9', 'azelaic', 'eyemask', 'nbz5', 'eospeach', 'anuaeye', 'bdeye', 'celimax'],
    dusk: ['balmred', 'hairoil', 'nbz9', 'glowvanilla', 'celimax', 'bdeye', 'anua', 'dalba', 'eosbutter', 'balmpink'],
    dusks: ['balmred', 'bdeye', 'dalba', 'hairoil', 'celimax', 'nbz9', 'anua', 'eosbutter', 'glowvanilla', 'balmpink', 'eospeach', 'guasha']
  };
  [['dew', 'Glass & Dew', 'nddew', ' frosted glass dew drops ivory blobs 04'], ['dusk', 'Skyline Dusk', 'nddusk', ' skyline city dusk fireworks lavender floor 05']].forEach(function (d) {
    [false, true].forEach(function (sc) { ['en', 'ar'].forEach(function (lg) { [false, true].forEach(function (fl) {
      var ar = lg === 'ar', T = Object.assign({ kick: ND3K[d[0]][ar ? 1 : 0] }, ND3T[lg]);
      add('uae', 'nd-' + d[0] + (sc ? '-s' : '') + (ar ? '-ar' : '') + (fl ? '-f' : ''), 'National Day · ' + d[1] + (sc ? ' Scattered' : '') + (ar ? ' · Arabic' : '') + (fl ? ' · Flag heading' : ''),
        d[2], ND, T, { sc: sc, ar: ar, hg: fl }, NDT + d[1].toLowerCase() + d[3] + (sc ? ' scattered' : ' line-up lineup') + (ar ? ' arabic' : '') + (fl ? ' flag heading gradient' : ''), ND3P[d[0] + (sc ? 's' : '')]);
    }); }); });
  });

/*PC*/
  var CATS = [['all', 'All'], ['uae', 'UAE & Gulf'], ['global', 'Global occasions'], ['sale', 'Sales & offers'], ['product', 'Products & routines']];
  return { FAM: FAM, LIST: L, CATS: CATS, FH: FH, PAL: PAL, PCCATS: PCCATS, CTRY: CTRY, CTRY_LIST: CTRY_LIST, kitPal: kitPal, tokens: tokens, OCC: OCC, OCCS: OCCS, OSECT: OCC_SECT, occTokens: occTokens };
})();
