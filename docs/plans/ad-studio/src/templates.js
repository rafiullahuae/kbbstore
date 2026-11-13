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
  function add(cat, id, name, fam, P, T, O, tags) { L.push({ id: id, name: name, cat: cat, fam: fam, P: P, T: T, O: O || {}, tags: tags || '', pp: rot(L.length * 2) }); }

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

  var CATS = [['all', 'All'], ['uae', 'UAE & Gulf'], ['global', 'Global occasions'], ['sale', 'Sales & offers'], ['product', 'Products & routines']];
  return { FAM: FAM, LIST: L, CATS: CATS, FH: FH, PAL: PAL };
})();
