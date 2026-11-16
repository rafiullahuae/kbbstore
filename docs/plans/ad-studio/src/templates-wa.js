  // ════ WORK ABROAD · image-first hiring ads (owner, 16 Nov 2026: "not just for study abroad, i need work abroad also, for
  //      different occupations, and i don't want generic text ad. i want to focus more on the images, specially the
  //      pictures of the workers, employees in specific uniform etc"). Spliced into templates.js after the Planet Careers
  //      set by build.py; drawn from the company kit like every Planet Careers design.
  //      The worker is the hero: a photo slot (wk + ph) that ships with a placeholder figure in the occupation's uniform
  //      (art "fig:<occupation>", from studio/reel-studio/library/occupations) until the owner puts his photo in.
  //      An occupation (OC) swaps the figure, the title in English / Arabic, the tool icons and names, the accents.
  //      Honesty: salary, visa type, contract, accommodation are ‹edit› boxes; every design carries "Requirements and
  //      terms confirmed at consultation." No number, no promise is invented. ════
  var OCCS = /*OCC*/[], OCC = {};
  OCCS.forEach(function (o) { OCC[o.id] = o; });
  var OCC_SECT = /*OSECT*/[];
  // {O} title · {OA} Arabic title · {OL} "a welder" · {OP} plural · {OPA} Arabic plural · {T0..T2} tool names
  function occTokens(e, O) {
    if (!O || typeof e.txt !== 'string' || e.txt.indexOf('{') < 0) return;
    var k = '', t = e.txt, map = [['{OPA}', O.pl[1], 'q'], ['{OP}', O.pl[0], 'p'], ['{OL}', O.l, 'l'], ['{OA}', O.t[1], 'r'], ['{O}', O.t[0], 'o'],
      ['{T0}', O.tools[0][1], '0'], ['{T1}', O.tools[1][1], '1'], ['{T2}', O.tools[2][1], '2']];
    map.forEach(function (m) { if (t.indexOf(m[0]) >= 0) { k += m[2]; t = t.split(m[0]).join(m[1]); } });
    if (k) e.ock = k; e.txt = t.slice(0, 200);
  }
  // the occupation's own colours, as roles: u uniform, t trim, p PPE, a accent
  function oc(O, r) { return O.c[{ u: 0, t: 1, p: 2, a: 3 }[r]]; }
  function oFig(id, O, g, o) { return art(id, 'fig:' + O.id, Object.assign({ wk: true, ph: true, name: 'Worker photo (tap → Put a photo here)' }, o || {}), g); }
  function oTool(i, O, g, col, o) { return art('ti' + i, 'oi:' + O.tools[i][0], Object.assign({ col: col, oti: i, name: 'Tool icon · ' + O.tools[i][1] }, o || {}), g); }
  // ‹edit› badges (salary / visa / contract): pills -- never a number the owner has not typed himself
  function badges(P, list, x, y, w, k, light, horiz) {
    var out = [];
    (list || []).forEach(function (b, i) {
      var g = horiz ? G(x + i * w / list.length, y, w / list.length, 0, 0, k) : G(x, y + i * 6.2 * k, w, 0, 0, k);
      out.push(tx('b' + i, b, { fn: P.fb, fw: 700, fs: 2.2, lh: 120, col: light ? P.ink : P.on, al: horiz ? 'c' : 'l', sh: 'pill', fill: light ? A(P.nv, '12') : A(P.on, '1C'), bc: light ? A(P.nv, '33') : A(P.on, '40'), bw: 1.5, pd: .55, name: 'Badge (edit)' }, g));
    });
    return out;
  }
  // tool rows: icon in a chip + the tool's name ({T0} .. {T2}), laid out by column()
  function toolRows(P, O, x, w, k, light) {
    return [0, 1, 2].map(function (i) { return tx('tn' + i, '{T' + i + '}', { fn: P.fb, fw: 600, fs: 2.7, lh: 125, col: light ? P.ink : P.on, al: 'l', name: 'Tool ' + (i + 1) }, G(x + 6 * k, 0, w - 6 * k, 0, 0, k)); });
  }
  function toolIcons(els, P, O, rows, x, k, light) {
    rows.forEach(function (r, i) {
      var s = 4.4 * k, y = r.g.y + (r.fs * r.g.k * 1.25 - s) / 2;
      els.push(shp('tc' + i, 'circle', { fill: light ? A(P.nv, '14') : A(P.on, '1A'), name: 'Tool chip' }, G(x, y, s, s)));
      els.push(oTool(i, O, G(x + s * .18, y + s * .18, s * .64, s * .64), light ? P.nv : P.on));
    });
  }
  function wBtn(P, txt, g, o) { return pBtn(P, txt, o || {}, g); }
  function wa_bg(P, light, pat) { return light ? bgLight(P, pat || 'routes', 16) : bgDark(P, pat || 'globe', 5); }

  // WA1 ── WE'RE HIRING: the worker big on the right on a glow, the destination's landmark rising behind, a flight path;
  //        "We're hiring" pill, the occupation in the plural, "Work in <country>", the three tools, ‹edit› badges
  FAM.wahire = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], st = f === 'story', sq = f === 'square';
    var fg = v(f, G(44, 37, 70.4, 88), G(50, 25, 60, 75), G(2, 70, 96, 120));
    els.push(glow('g1', A(oc(OC, 'a'), '55'), G(fg.x - fg.w * .1, fg.y - fg.w * .05, fg.w * 1.2, fg.w * 1.1))); els[els.length - 1].oy = 'a';
    els.push(art('lm', 'lm:' + C.lm, { col: mix(P.nv, P.tlD, .55), op: 75, name: 'Landmark' }, v(f, G(52, 18, 56, 56), G(58, 6, 46, 46), G(46, 52, 60, 60))));
    els.push(route(P, v(f, G(36, 6, 80, 40, -14), G(44, 2, 66, 34, -14), G(12, 44, 90, 40, -14)), 50));
    stripes(els, 'st', C, 0, 0, 2.4, FH[f], false);
    els.push(oFig('fig', OC, fg));
    els.push(flagC('flag', C, v(f, G(80, 40, 13, 13), G(84, 26, 11, 11), G(76, 84, 15, 15))));
    if (st) els.push(shp('panel', 'rect', { fill: A(P.dk, 'F2'), name: 'Bottom panel' }, G(0, 141, 100, 37)));
    else els.push(shp('panel', 'rect', { fill: A(P.dk, 'F2'), name: 'Bottom panel' }, G(0, 0, 1, 1)));
    if (!st) els[els.length - 1].g.hd = true;
    els.push(pLogo(P, { al: 'l' }, v(f, G(7, 4.5, 50, 0, 0, .9), G(6, 4, 50, 0, 0, .8), G(8, 5, 60, 0, 0, 1.05))));
    var X = v(f, 7, 6, 8), W = v(f, 46, 46, 84);
    var top = [pKick(P, T.kick, { al: 'l' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 10, ock: 'p' }, G(X, 0, W, 0, 0, k)), pH2(P, T.h2, { al: 'l', cyk: 'n' }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    var rows = toolRows(P, OC, X, v(f, 46, 44, 46), k);
    var bd = badges(P, T.badges, X, 0, v(f, 36, 36, 84), k * .95, false, st);
    if (st) { column(top, 15, 46, 1.4 * k); column(rows, 49, 70, 1 * k, true); bd.forEach(function (b) { b.g.y = 146; }); }
    else { column(top.concat(rows), v(f, 14, 12, 0), v(f, 70, 56, 0), 1.6 * k); bd.forEach(function (b, i) { b.g.y = v(f, 73, 58.5, 0) + i * 6.3 * k; }); }
    els = els.concat(top, rows, bd);
    toolIcons(els, P, OC, rows, X, k);
    els.push(wBtn(P, T.cta, v(f, G(7, 94.5, 40, 8), G(6, 77, 36, 7.2), G(8, 155, 44, 8.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(7, 105, 44, 0, 0, .85), G(6, 86, 44, 0, 0, .75), G(55, 155, 38, 0, 0, .85))));
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(7, 114, 44, 0, 0, .85), G(6, 93.5, 44, 0, 0, .75), G(8, 168, 84, 0, 0, 1))));
    return { bg: bgDark(P, 'globe', 5), els: els, slots: [] };
  };

  // WA2 ── JOB CARD: the worker in a big rounded photo on top, the flag on its corner, then a white job card -- title,
  //        destination, three ‹edit› detail lines with icons, Apply
  FAM.wajob = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], sq = f === 'square';
    var ph = v(f, G(6, 6, 88, 58), G(52, 6, 42, 88), G(6, 6, 88, 92));
    els.push(shp('phbg', 'rect', { oy: 'a', fill: P.wash, fill2: A(oc(OC, 'a'), '66'), gang: 160, rad: 3, name: 'Photo ground' }, ph));
    els.push(art('lm', 'lm:' + C.lm, { col: P.nv, op: 22, name: 'Landmark' }, G(ph.x + ph.w * (sq ? .1 : .52), ph.y + ph.h * (sq ? .1 : .18), ph.w * (sq ? .8 : .44), ph.w * (sq ? .8 : .44))));
    var fw = Math.min(ph.w * .9, ph.h * .8), fh = fw * 1.25;
    els.push(oFig('fig', OC, G(ph.x + (ph.w - fw) / 2, ph.y + ph.h - Math.min(fh, ph.h), fw, Math.min(fh, ph.h)), { sh: 'rect', rad: 0 }));
    els.push(pKick(P, T.kick, { col: P.onGd, fill: P.gd, sh: 'pill', pd: .7, fs: 2.4, al: 'l' }, v(f, G(9, 9, 40, 0, 0, k), G(55, 9, 36, 0, 0, k), G(9, 9, 50, 0, 0, k))));
    els.push(flagC('flag', C, v(f, G(78, 54, 14, 14), G(80, 80, 12, 12), G(76, 88, 16, 16))));
    var cd = v(f, G(6, 67, 88, 52), G(6, 6, 44, 88), G(6, 102, 88, 70));
    els.push(shp('card', 'rect', { fill: '#FFFFFF', rad: 3, shd: 18, name: 'Job card' }, cd));
    var X = cd.x + 4, W = cd.w - 8;
    els.push(pLogo(P, { col: P.nv, al: 'r' }, G(cd.x + cd.w - 34, cd.y + 2.4, 31, 0, 0, .7 * k)));
    var top = [pH1(P, T.h1, { col: P.ink, al: 'l', fs: 7.4, ock: 'o' }, G(X, 0, W, 0, 0, k)),
      tx('loc', T.loc, { fn: P.fb, fw: 700, fs: 2.8, col: P.gd === P.nv ? P.tl : P.gd, al: 'l', cyk: 'n', name: 'Location' }, G(X, 0, W, 0, 0, k)),
      pAr(P, T.ar, { col: P.nv, al: 'l', fs: 2.9 }, G(X, 0, W, 0, 0, k))];
    var dl = (T.lines || []).map(function (s, i) { return tx('d' + i, s, { fn: P.fb, fw: 600, fs: 2.5, lh: 125, col: P.ink, al: 'l', name: 'Detail (edit)' }, G(X + 5 * k, 0, W - 5 * k, 0, 0, k)); });
    column(top.concat(dl), cd.y + v(f, 9, 10, 9), cd.y + cd.h - v(f, 22, 30, 26), 1.2 * k);
    els = els.concat(top, dl);
    dl.forEach(function (r, i) { var s = 3.4 * k; els.push(ico('di' + i, ['coins', 'doc', 'cal'][i] || 'check', { col: P.nv, name: 'Detail icon' }, G(X, r.g.y + (r.fs * r.g.k * 1.25 - s) / 2, s, s))); });
    els.push(wBtn(P, T.cta, G(X, cd.y + cd.h - v(f, 20, 28, 24), v(f, 40, 36, 48), v(f, 7.6, 7, 8.4))));
    els.push(pContact(P, T.contact, { col: P.ink, al: 'l' }, G(X, cd.y + cd.h - v(f, 10.5, 18.5, 13), W, 0, 0, v(f, .8, .72, .95))));
    els.push(pNote(P, T.note, { col: P.lsub, al: 'l' }, G(X, cd.y + cd.h - v(f, 5, 8.5, 6.5), W, 0, 0, v(f, .8, .72, .95))));
    return { bg: bgLight(P, 'routes', 14), els: els, slots: [] };
  };

  // WA3 ── SPLIT: the photo fills one side edge to edge; a flag-colour seam; the copy on the night ground beside
  FAM.wasplit = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], st = f === 'story';
    var ph = v(f, G(0, 0, 50, 125), G(0, 0, 48, 100), G(0, 0, 100, 96));
    els.push(shp('phbg', 'rect', { oy: 'a', fill: mix(P.nv, '#FFFFFF', .82), fill2: A(oc(OC, 'a'), '80'), gang: 180, name: 'Photo ground' }, ph));
    els.push(art('lm', 'lm:' + C.lm, { col: P.nv, op: 20, name: 'Landmark' }, v(f, G(2, 18, 46, 46), G(2, 10, 44, 44), G(8, 4, 84, 84))));
    els.push(oFig('fig', OC, v(f, G(-12, 30, 76, 95), G(-8, 25, 64, 75), G(6, 1, 88, 110)), { sh: 'rect', rad: 0 }));
    stripes(els, 'st', C, st ? 0 : ph.w, st ? ph.h : 0, st ? 100 : 2.2, st ? 2.2 : FH[f], !st);
    var X = v(f, 56, 54, 8), W = v(f, 38, 40, 84);
    els.push(pLogo(P, { al: 'l' }, v(f, G(X, 6, W, 0, 0, .8 * k), G(X, 5, W, 0, 0, .75 * k), G(8, 99.5, 50, 0, 0, .8 * k))));
    var top = [pKick(P, T.kick, { al: 'l' }, G(X, 0, W, 0, 0, k)), pH2(P, T.h2, { al: 'l', cyk: 'n', col: P.on, fs: 4 }, G(X, 0, W, 0, 0, k)),
      pH1(P, T.h1, { al: 'l', fs: 8.6, ock: 'l' }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    var bd = badges(P, T.badges, X, 0, W, k * .95, false, false);
    column(top.concat(bd), v(f, 16, 14, 108), v(f, 92, 72, 152), 1.5 * k);
    els = els.concat(top, bd);
    els.push(flagC('flag', C, v(f, G(36, 6, 11, 11), G(35, 5, 10, 10), G(82, 84, 13, 13))));
    els.push(wBtn(P, T.cta, v(f, G(X, 96, 38, 7.6), G(X, 76, 36, 7), G(8, 155, 44, 8.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(X, 106, W, 0, 0, .8), G(X, 85.5, W, 0, 0, .72), G(55, 155, 38, 0, 0, .85))));
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(X, 114.5, W, 0, 0, .8), G(X, 92.5, W, 0, 0, .72), G(8, 168, 84, 0, 0, 1))));
    return { bg: bgDark(P, 'none', 0), els: els, slots: [] };
  };

  // WA4 ── POSTER: the photo full bleed, a deep shade from the bottom, the occupation huge over it, the flag beside
  //        "Work in <country>", a "Now hiring" stamp at the top
  FAM.waposter = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], H = FH[f];
    els.push(shp('phbg', 'rect', { oy: 'a', fill: mix(P.nv, '#FFFFFF', .7), fill2: A(oc(OC, 'a'), '99'), gang: 180, name: 'Photo ground' }, G(0, 0, 100, H)));
    els.push(art('lm', 'lm:' + C.lm, { col: P.nv, op: 26, name: 'Landmark' }, v(f, G(52, 4, 50, 50), G(56, 2, 44, 44), G(48, 10, 56, 56))));
    els.push(oFig('fig', OC, v(f, G(12, 30, 76, 95), G(20, 22, 60, 78), G(4, 50, 92, 127.78)), { sh: 'rect', rad: 0 }));
    els.push(shp('fade', 'rect', { fill: A(P.dk, '00'), fill2: A(P.dk, 'F7'), gang: 180, name: 'Bottom shade' }, G(0, H * .48, 100, H * .52)));
    els.push(shp('stamp', 'burst', { fill: P.gd, txt: T.stamp, fn: P.fh, fw: 900, fs: 2.6, lh: 100, ls: 4, col: P.onGd, name: 'Stamp' }, v(f, G(74, 4, 22, 22, -10), G(77, 4, 19, 19, -10), G(72, 5, 24, 24, -10))));
    els.push(pLogo(P, { al: 'l', col: P.nv }, v(f, G(5, 4.5, 50, 0, 0, .85), G(5, 4, 50, 0, 0, .75), G(6, 6, 60, 0, 0, 1))));
    var X = 6, W = 88;
    var top = [pKick(P, T.kick, { al: 'l', cyk: 'n' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 13, ock: 'o', up: true, lh: 92 }, G(X, 0, W, 0, 0, k)), pH2(P, T.h2, { al: 'l', cyk: 'n', col: P.on }, G(X + 9 * k, 0, W - 9 * k, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k))].concat(badges(P, [T.terms || 'Salary · visa type · contract: ‹edit›'], X, 0, 70, k * .9, false, false));
    column(top, v(f, 70, 52, 112), v(f, 104, 82, 152), 1.2 * k);
    els = els.concat(top);
    els.push(flagC('flag', C, G(X, top[2].g.y - .6 * k, 7.4 * k, 7.4 * k), { bw: 3 }));
    els.push(wBtn(P, T.cta, v(f, G(6, 107, 38, 7.6), G(6, 85, 34, 7), G(6, 156, 44, 8.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(47, 107.5, 48, 0, 0, .8), G(44, 85, 52, 0, 0, .72), G(54, 156, 42, 0, 0, .85))));
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(6, 117, 88, 0, 0, .85), G(6, 94, 88, 0, 0, .75), G(6, 169, 88, 0, 0, 1))));
    return { bg: bgDark(P, 'none', 0), els: els, slots: [] };
  };

  // WA5 ── COLLAGE: two or three roles side by side, each in its own tile with its uniform and name; one headline
  FAM.wacollage = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], st = f === 'story', H = FH[f];
    var list = [OC].concat((O.with || []).map(function (id) { return OCC[id]; }).filter(Boolean)), n = list.length;
    var y0 = v(f, 38, 30, 52), th = v(f, 58, 50, n === 3 ? 92 : 96), gap = 2.4, tw = (88 - gap * (n - 1)) / n;
    list.forEach(function (o, i) {
      var g = st && n === 3 ? (i === 0 ? G(6, y0, 88, 46) : G(6 + (i - 1) * 45, y0 + 48.4, 43, 38)) : G(6 + i * (tw + gap), y0, tw, th);
      els.push(shp('tile' + i, 'rect', { fill: mix(P.nv, '#FFFFFF', .85), fill2: A(o.c[3], '88'), gang: 180, rad: 2.4, name: 'Tile' }, g));
      var fh = Math.min(g.h, g.w * 1.25), fw = fh * .8;
      els.push(art(i ? 'fig' + i : 'fig', 'fig:' + o.id, { wk: true, ph: true, sh: 'rect', rad: 0, name: 'Worker photo (tap → Put a photo here)' }, G(g.x + (g.w - fw) / 2, g.y + g.h - fh, fw, fh)));
      var lab = tx(i ? 'role' + i : 'role', i ? o.t[0] : '{O}', { fn: P.fh, fw: 800, fs: 2.6, col: P.onGd, sh: 'pill', fill: P.gd, pd: .6, name: 'Role' + (i ? '' : ' (follows the occupation)') }, G(g.x + 1, g.y + g.h - 5.4 * k, g.w - 2, 0, 0, k));
      if (!i) lab.ock = 'o';
      els.push(lab);
    });
    els.push(pLogo(P, { al: 'l' }, v(f, G(6, 4.5, 50, 0, 0, .9), G(6, 4, 50, 0, 0, .8), G(8, 5, 60, 0, 0, 1.05))));
    var X = 6, W = 88;
    var top = [pKick(P, T.kick, { al: 'l', cyk: 'n' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 7.6, cyk: 'n' }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    column(top, v(f, 13.5, 12, 15), y0 - 2.5, 1.2 * k);
    els = els.concat(top);
    els.push(flagC('flag', C, v(f, G(82, 4, 12, 12), G(84, 3.5, 10, 10), G(80, 5, 14, 14))));
    var by = y0 + (st && n === 3 ? 86.4 : th) + v(f, 3, 2.4, 4);
    var bd = badges(P, T.badges, X, by, W, k * .9, false, true);
    els = els.concat(bd);
    els.push(wBtn(P, T.cta, v(f, G(6, 107, 38, 7.4), G(6, 89, 30, 6.4), G(6, 156, 44, 8.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(47, 106.5, 48, 0, 0, .8), G(39, 88.8, 56, 0, 0, .7), G(54, 156, 42, 0, 0, .85))));
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(6, 117, 88, 0, 0, .85), G(6, 96, 88, 0, 0, .7), G(6, 169, 88, 0, 0, 1))));
    return { bg: bgDark(P, 'grid', 4), els: els, slots: [] };
  };

  // WA6 ── UNIFORM: the worker large, three call-out lines from the uniform to round tool badges with the tools' names --
  //        what the job looks like, in one picture
  FAM.wauniform = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], st = f === 'story';
    var fg = v(f, G(2, 33, 73.6, 92), G(2, 26, 59.2, 74), G(-4, 64, 90.4, 113));
    els.push(shp('disc', 'circle', { oy: 'a', fill: A(oc(OC, 'a'), '40'), name: 'Disc' }, G(fg.x + fg.w * .08, fg.y + fg.h * .02, fg.w * .84, fg.w * .84)));
    els.push(oFig('fig', OC, fg));
    var cx = v(f, 76, 68, 80), cs = v(f, 14, 11, 16), ys = v(f, [40, 60, 80], [30, 46, 62], [72, 96, 120]);
    [0, 1, 2].forEach(function (i) {
      var y = ys[i], tx0 = fg.x + fg.w * [.5, .38, .62][i], ty = fg.y + fg.h * [.15, .5, .78][i];
      els.push(shp('cl' + i, 'rect', { fill: A(P.on, '80'), name: 'Call-out line' }, G(tx0, Math.min(ty, y + cs / 2), Math.max(.4, cx - tx0), .35)));
      els.push(shp('cd' + i, 'circle', { fill: P.on, name: 'Call-out dot' }, G(tx0 - .9, ty - .9, 1.8, 1.8)));
      els.push(shp('cb' + i, 'circle', { fill: P.on, bc: oc(OC, 'a'), bw: 6, shd: 25, name: 'Tool badge' }, G(cx, y, cs, cs)));
      els.push(oTool(i, OC, G(cx + cs * .22, y + cs * .22, cs * .56, cs * .56), P.nv));
      els.push(tx('tn' + i, '{T' + i + '}', { fn: P.fb, fw: 700, fs: 2.3, col: P.on, al: 'c', name: 'Tool ' + (i + 1) }, G(cx - 6, y + cs + .8, cs + 12, 0, 0, k)));
    });
    els.push(pLogo(P, { al: 'l' }, v(f, G(6, 4.5, 50, 0, 0, .9), G(6, 4, 50, 0, 0, .8), G(8, 5, 60, 0, 0, 1.05))));
    var X = v(f, 6, 6, 8), W = v(f, 88, 88, 84);
    var top = [pKick(P, T.kick, { al: 'l', cyk: 'n' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 8.4, ock: 'o' }, G(X, 0, W, 0, 0, k)), pSub(P, 'sub', T.sub, { al: 'l' }, G(X, 0, st ? W : 62, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k))].concat(badges(P, [T.terms || 'Salary · visa type · contract: ‹edit›'], X, 0, 62, k * .9, false, false));
    column(top, v(f, 13.5, 12, 15), v(f, 36, 27, 62), 1.1 * k);
    els = els.concat(top);
    els.push(shp('panel', 'rect', { fill: A(P.dk, 'EE'), name: 'Bottom panel' }, v(f, G(0, 103, 100, 22), G(0, 82, 100, 18), G(0, 150, 100, 27.78))));
    els.push(wBtn(P, T.cta, v(f, G(6, 106, 38, 7.4), G(6, 84.5, 32, 6.6), G(6, 154, 44, 8.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(47, 106, 48, 0, 0, .8), G(41, 84.5, 54, 0, 0, .7), G(54, 154, 42, 0, 0, .85))));
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(6, 117, 88, 0, 0, .85), G(6, 94, 88, 0, 0, .7), G(6, 168, 88, 0, 0, 1))));
    return { bg: bgDark(P, 'grid', 4), els: els, slots: [] };
  };

  // WA7 ── TEAM: four or five workers of one trade family shoulder to shoulder on a light ground, the destination's landmark
  //        behind them, "Join our team in <country>", the roles as chips
  FAM.wateam = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], st = f === 'story', H = FH[f];
    var list = [OC].concat((O.with || []).map(function (id) { return OCC[id]; }).filter(Boolean)), n = list.length;
    var base = v(f, 96, 76, 136), fh = v(f, 50, 42, 62), fw = fh * .8, span = 96, step = (span - fw) / Math.max(1, n - 1);
    els.push(art('lm', 'lm:' + C.lm, { col: P.nv, op: 14, name: 'Landmark' }, G(50 - fh * .65, base - fh * 1.3, fh * 1.3, fh * 1.3)));
    els.push(shp('floor', 'ellipse', { fill: A(P.nv, '14'), name: 'Floor' }, G(-4, base - 3, 108, 8)));
    // the middle worker (the occupation) is drawn last, in front
    var mid = Math.floor((n - 1) / 2);
    list.slice(1).forEach(function (o, i) {
      var slot = i < mid ? i : i + 1, x = 2 + slot * step, s = .9;
      els.push(art('fig' + (i + 1), 'fig:' + o.id, { wk: true, ph: true, name: 'Worker photo (tap → Put a photo here)' }, G(x + fw * (1 - s) / 2, base - fh * s, fw * s, fh * s)));
    });
    els.push(oFig('fig', OC, G(2 + mid * step - fw * .06, base - fh * 1.12, fw * 1.12, fh * 1.12)));
    els.push(pLogo(P, { col: P.nv, al: 'l' }, v(f, G(6, 4.5, 50, 0, 0, .9), G(6, 4, 50, 0, 0, .8), G(8, 5, 60, 0, 0, 1.05))));
    var X = 6, W = 88;
    var top = [pKick(P, T.kick, { col: P.tl, al: 'l' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 7.6, col: P.ink, cyk: 'n' }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l', col: P.nv }, G(X, 0, W, 0, 0, k))];
    column(top, v(f, 13.5, 12, 15), base - fh * 1.12 - 2, 1.2 * k);
    els = els.concat(top);
    els.push(flagC('flag', C, v(f, G(82, 4, 12, 12), G(84, 3.5, 10, 10), G(80, 5, 14, 14))));
    var roles = tx('roles', T.roles, { fn: P.fb, fw: 700, fs: 2.6, lh: 130, col: P.ink, al: 'l', name: 'Roles (edit)' }, G(X, base + v(f, 3, 2.4, 4), W, 0, 0, k));
    roles.ock = 'o'; els.push(roles);
    els.push(wBtn(P, T.cta, v(f, G(6, 108, 38, 7.4), G(6, 88, 30, 6.4), G(6, 156, 44, 8.4)), { fill: P.nv, col: P.on }));
    els.push(pContact(P, T.contact, { al: 'l', col: P.ink }, v(f, G(47, 107.5, 48, 0, 0, .8), G(39, 87.8, 56, 0, 0, .7), G(54, 156, 42, 0, 0, .85))));
    els.push(pNote(P, T.note, { al: 'l', col: P.lsub }, v(f, G(6, 118, 88, 0, 0, .85), G(6, 95.6, 88, 0, 0, .7), G(6, 169, 88, 0, 0, 1))));
    return { bg: bgLight(P, 'routes', 14), els: els, slots: [] };
  };

  // WA8 ── WORKER ID: a big staff pass on a lanyard -- the photo, the occupation, the destination flag, ‹edit› lines
  FAM.waid = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], sq = f === 'square';
    var cd = v(f, G(36, 26, 58, 88), G(54, 14, 40, 76), G(16, 56, 68, 104));
    els.push(shp('strap1', 'rect', { fill: P.gd, name: 'Lanyard' }, G(cd.x + cd.w * .32, -5, 2.6, cd.y + 9, -8)));
    els.push(shp('strap2', 'rect', { fill: P.gd, name: 'Lanyard' }, G(cd.x + cd.w * .64, -5, 2.6, cd.y + 9, 8)));
    els.push(shp('card', 'rect', { fill: '#FFFFFF', rad: 3, shd: 35, name: 'Pass' }, cd));
    els.push(shp('clip', 'rect', { fill: '#C9D2DA', rad: 1, name: 'Clip' }, G(cd.x + cd.w * .4, cd.y - 1.5, cd.w * .2, 4)));
    els.push(shp('band', 'rect', { fill: P.nv, rad: 0, name: 'Pass band' }, G(cd.x, cd.y + 4, cd.w, cd.h * .12)));
    els.push(tx('pass', T.pass, { fn: P.fh, fw: 800, fs: 2.2, ls: 24, col: P.on, al: 'c', name: 'Pass title' }, G(cd.x, cd.y + 4 + cd.h * .06 - 1.4 * k, cd.w, 0, 0, k)));
    var ph = G(cd.x + cd.w * .18, cd.y + cd.h * .2, cd.w * .64, cd.w * .64 * 1.1);
    els.push(shp('phbg', 'rect', { oy: 'a', fill: P.wash, fill2: A(oc(OC, 'a'), '66'), gang: 180, rad: 2, name: 'Photo ground' }, ph));
    els.push(oFig('fig', OC, G(ph.x + ph.w * .05, ph.y, ph.w * .9, ph.h), { sh: 'rect', rad: 2 }));
    els.push(flagC('flag', C, G(ph.x + ph.w - ph.w * .2, ph.y + ph.h - ph.w * .2, ph.w * .3, ph.w * .3), { bw: 4 }));
    var cy = ph.y + ph.h + v(f, 3, 2.5, 4);
    var cl = [tx('role', '{O}', { fn: P.fh, fw: 900, fs: 4.4, col: P.ink, ock: 'o', name: 'Role' }, G(cd.x + 3, 0, cd.w - 6, 0, 0, k)),
      tx('dest', T.dest, { fn: P.fb, fw: 700, fs: 2.4, col: P.nv, cyk: 'n', name: 'Destination' }, G(cd.x + 3, 0, cd.w - 6, 0, 0, k)),
      tx('idl', T.idl, { fn: P.fb, fw: 600, fs: 2, col: P.lsub, name: 'ID line (edit)' }, G(cd.x + 3, 0, cd.w - 6, 0, 0, k))];
    column(cl, cy, cd.y + cd.h - 9, .8 * k, true);
    els = els.concat(cl);
    [1, 0, 2, 1, 0, 1, 2, 0, 1, 1, 0, 2, 1, 0].forEach(function (q, i) { els.push(shp('bc' + i, 'rect', { fill: P.ink, name: 'Barcode' }, G(cd.x + cd.w * .22 + i * cd.w * .04, cd.y + cd.h - 7, cd.w * .012 * (q + 1), 4.4))); });
    els.push(pLogo(P, { al: 'l' }, v(f, G(6, 4.5, 50, 0, 0, .85), G(6, 4, 46, 0, 0, .8), G(8, 5, 60, 0, 0, 1.05))));
    var X = v(f, 6, 6, 8), W = v(f, 28, 44, 84);
    var top = [pKick(P, T.kick, { al: 'l' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: v(f, 6, 7, 7.4), cyk: 'n', ock: 'l' }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    var bd = badges(P, T.badges, X, 0, W, k * .9, false, false);
    if (f === 'story') { column(top, 12, 44, 1.2 * k); bd.forEach(function (b, i) { b.g.y = 164; b.g.x = 8 + i * 28; b.g.w = 27; b.al = 'c'; }); }
    else column(top.concat(bd), v(f, 14, 12, 0), v(f, 96, 70, 0), 1.4 * k);
    els = els.concat(top, bd);
    els.push(wBtn(P, T.cta, v(f, G(6, 100, 28, 7.4), G(6, 76, 40, 6.8), G(8, 0, 1, 1))));
    if (f === 'story') { els[els.length - 1].g = G(28, 46, 44, 7.6); }
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(6, 117, 88, 0, 0, .8), G(6, 85, 44, 0, 0, .72), G(8, 2, 84, 0, 0, 1))));
    if (f === 'story') els[els.length - 1].g.hd = true;
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(6, 120.6, 88, 0, 0, .75), G(6, 93, 44, 0, 0, .72), G(8, 172, 84, 0, 0, .9))));
    return { bg: bgDark(P, 'routes', 8), els: els, slots: [] };
  };

  // WA9 ── JOURNEY: from home to abroad -- the worker in a ring at the end of a dashed flight path that starts at a home
  //        pin, the destination flag and landmark where it lands
  FAM.wajourney = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], st = f === 'story';
    var ph = v(f, G(40, 34, 54, 54), G(52, 20, 42, 42), G(18, 70, 64, 64));
    els.push(art('lm', 'lm:' + C.lm, { col: mix(P.nv, P.tlD, .55), op: 85, name: 'Landmark' }, v(f, G(4, 66, 50, 50), G(2, 54, 40, 40), G(54, 30, 44, 44))));
    els.push(shp('path', 'ellipse', { fill: 'transparent', bc: P.ic, bw: 3, bs: 'dashed', op: 80, name: 'Flight path' }, v(f, G(-30, 60, 100, 70, -8), G(-30, 52, 98, 56, -8), G(-30, 108, 90, 60, -8))));
    els.push(ico('home', 'home', { col: P.ic, name: 'Home pin' }, v(f, G(6, 98, 7, 7), G(5, 80, 6, 6), G(6, 146, 8, 8))));
    els.push(tx('from', T.from, { fn: P.fb, fw: 700, fs: 2.2, col: P.on, al: 'l', name: 'From (edit)' }, v(f, G(14.5, 99.5, 30, 0, 0, k), G(12.5, 81, 30, 0, 0, k), G(16, 148, 30, 0, 0, k))));
    els.push(plane(P, v(f, G(28, 64, 8, 8), G(30, 54, 7, 7), G(36, 108, 9, 9)), 60));
    els.push(shp('ring', 'ring', { fill: 'transparent', bc: P.ic, bw: 6, name: 'Photo ring' }, G(ph.x - 1.6, ph.y - 1.6, ph.w + 3.2, ph.w + 3.2)));
    els.push(shp('phbg', 'circle', { oy: 'a', fill: mix(P.nv, '#FFFFFF', .8), fill2: A(oc(OC, 'a'), 'AA'), gang: 180, name: 'Photo ground' }, ph));
    els.push(oFig('fig', OC, ph, { sh: 'circle' }));
    els.push(flagC('flag', C, G(ph.x + ph.w * .72, ph.y + ph.w * .72, ph.w * .28, ph.w * .28)));
    els.push(pLogo(P, { al: 'l' }, v(f, G(6, 4.5, 50, 0, 0, .9), G(6, 4, 46, 0, 0, .8), G(8, 5, 60, 0, 0, 1.05))));
    var X = v(f, 6, 6, 8), W = v(f, 88, 44, 84);
    var top = [pKick(P, T.kick, { al: 'l' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 8, cyk: 'n' }, G(X, 0, W, 0, 0, k)), pH2(P, T.h2, { al: 'l', ock: 'l' }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    column(top, v(f, 13.5, 12, 15), v(f, 33, 76, 66), 1.2 * k);
    els = els.concat(top);
    els.push(wBtn(P, T.cta, v(f, G(52, 98, 40, 7.6), G(52, 70, 40, 6.8), G(28, 152, 44, 8.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(6, 110, 88, 0, 0, .85), G(52, 79, 44, 0, 0, .72), G(8, 163, 84, 0, 0, .95))));
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(6, 117, 88, 0, 0, .85), G(52, 92, 44, 0, 0, .72), G(8, 170, 84, 0, 0, .95))));
    return { bg: bgDark(P, 'routes', 8), els: els, slots: [] };
  };

  // WA10 ── HOW TO APPLY: the worker on one side, four numbered steps on the other (nothing promised: the job offer and
  //         permit step says it depends on the employer and the authorities)
  FAM.wasteps = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], st = f === 'story';
    var fg = v(f, G(52, 45, 64, 80), G(58, 34, 52.8, 66), G(46, 100, 62.2, 77.78));
    els.push(shp('disc', 'circle', { oy: 'a', fill: A(oc(OC, 'a'), '30'), name: 'Disc' }, G(fg.x + fg.w * .05, fg.y + fg.h * .05, fg.w * .9, fg.w * .9)));
    els.push(oFig('fig', OC, fg));
    els.push(pLogo(P, { col: P.nv, al: 'l' }, v(f, G(6, 4.5, 50, 0, 0, .9), G(6, 4, 46, 0, 0, .8), G(8, 5, 60, 0, 0, 1.05))));
    var X = 6, W = v(f, 88, 88, 86);
    var top = [pKick(P, T.kick, { al: 'l', col: P.tl, cyk: 'n' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 7.4, col: P.ink, ock: 'l' }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l', col: P.nv }, G(X, 0, W, 0, 0, k))];
    column(top, v(f, 13.5, 12, 15), v(f, 40, 30, 46), 1.1 * k);
    els = els.concat(top);
    var sw = v(f, 50, 54, 86), sy = v(f, 44, 33, 50), sh = v(f, 13, 11, 12.4);
    (T.steps || []).forEach(function (s, i) {
      var y = sy + i * sh;
      els.push(shp('sn' + i, 'circle', { fill: P.nv, txt: String(i + 1), fn: P.fh, fw: 900, fs: 3, col: P.on, name: 'Step number' }, G(X, y, 6.4 * k, 6.4 * k)));
      els.push(tx('s' + i, s[0], { fn: P.fh, fw: 800, fs: 3, col: P.ink, al: 'l', name: 'Step' }, G(X + 8.4 * k, y - .2, sw - 8.4 * k, 0, 0, k)));
      els.push(tx('sd' + i, s[1], { fn: P.fb, fw: 500, fs: 2.2, lh: 125, col: P.lsub, al: 'l', name: 'Step detail' }, G(X + 8.4 * k, y + 3.8 * k, sw - 8.4 * k, 0, 0, k)));
    });
    els.push(wBtn(P, T.cta, v(f, G(6, 99, 40, 7.4), G(6, 79, 36, 6.6), G(6, 154, 38, 8.4)), { fill: P.nv, col: P.on }));
    els.push(pContact(P, T.contact, { al: 'l', col: P.ink }, v(f, G(6, 109, 46, 0, 0, .8), G(6, 87, 50, 0, 0, .7), G(6, 164, 40, 0, 0, .85))));
    els.push(pNote(P, T.note, { al: 'l', col: P.lsub }, v(f, G(6, 117, 46, 0, 0, .8), G(6, 93.5, 50, 0, 0, .7), G(6, 171, 40, 0, 0, .85))));
    return { bg: bgLight(P, 'globe', 8), els: els, slots: [] };
  };

  // WA11 ── URGENT HIRING: a burst, the worker big, a list of open roles each with its own tool icon (‹edit› numbers)
  FAM.waurgent = function (f, T, P, O, C, OC) {
    var k = v(f, 1, .9, 1.12), els = [], st = f === 'story';
    var fg = v(f, G(48, 41, 67.2, 84), G(54, 30, 56, 70), G(36, 96, 65.4, 81.78));
    els.push(glow('g1', A(oc(OC, 'a'), '66'), G(fg.x - 4, fg.y, fg.w * 1.1, fg.w * 1.1))); els[els.length - 1].oy = 'a';
    els.push(oFig('fig', OC, fg));
    els.push(flagC('flag', C, v(f, G(82, 34, 12, 12), G(84, 22, 10, 10), G(80, 92, 14, 14))));
    els.push(shp('burst', 'burst', { fill: P.gd, txt: T.burst, fn: P.fh, fw: 900, fs: 2.8, lh: 100, ls: 4, col: P.onGd, name: 'Urgent burst' }, v(f, G(70, 4, 24, 24, 10), G(74, 3, 20, 20, 10), G(70, 5, 26, 26, 10))));
    els.push(pLogo(P, { al: 'l' }, v(f, G(6, 4.5, 50, 0, 0, .9), G(6, 4, 46, 0, 0, .8), G(8, 5, 60, 0, 0, 1.05))));
    var X = 6, W = v(f, 50, 48, 86);
    var top = [pKick(P, T.kick, { al: 'l', cyk: 'n' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 8.4 }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    column(top, v(f, 13.5, 12, 15), v(f, 37, 29, 46), 1.1 * k);
    els = els.concat(top);
    var list = [OC].concat((O.with || []).map(function (id) { return OCC[id]; }).filter(Boolean)).slice(0, 5);
    var ry = v(f, 40, 31, 50), rh = v(f, 9.6, 8.6, 8.6);
    list.forEach(function (o, i) {
      var y = ry + i * rh, s = 5.6 * k;
      els.push(shp('rr' + i, 'rect', { fill: A(P.on, i % 2 ? '0F' : '1A'), rad: 2, name: 'Row' }, G(X, y, W - 4, rh - 1.4)));
      els.push(shp('rc' + i, 'circle', { fill: o.c[3], name: 'Role chip' }, G(X + 1.4, y + (rh - 1.4 - s) / 2, s, s)));
      var ic = art(i ? 'ri' + i : 'ti0', 'oi:' + o.tools[0][0], { col: '#FFFFFF', name: 'Role icon' }, G(X + 1.4 + s * .2, y + (rh - 1.4 - s) / 2 + s * .2, s * .6, s * .6));
      if (!i) ic.oti = 0;
      els.push(ic);
      var rt = tx(i ? 'rn' + i : 'rn', i ? o.pl[0] : '{OP}', { fn: P.fh, fw: 800, fs: 3, col: P.on, al: 'l', name: 'Role' }, G(X + 3.4 + s, y + (rh - 1.4) / 2 - 1.95 * k, W - 20 - s, 0, 0, k));
      if (!i) rt.ock = 'p';
      els.push(rt);
      els.push(tx('rq' + i, T.qty, { fn: P.fb, fw: 700, fs: 2, col: P.hl, al: 'r', name: 'Openings (edit)' }, G(X + W - 17, y + (rh - 1.4) / 2 - 1.3 * k, 12, 0, 0, k)));
    });
    els.push(wBtn(P, T.cta, v(f, G(6, 100, 40, 7.4), G(6, 79.5, 36, 6.6), G(6, 154, 44, 8.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(6, 110, 44, 0, 0, .8), G(6, 88, 48, 0, 0, .7), G(6, 164, 34, 0, 0, .8))));
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(6, 117.5, 44, 0, 0, .8), G(6, 94, 48, 0, 0, .7), G(6, 171, 34, 0, 0, .8))));
    if (st) { els[els.length - 2].g.hd = false; }
    return { bg: bgDark(P, 'grid', 4), els: els, slots: [] };
  };

  // ── the Work Abroad templates (appended after every Planet Careers template) ──
  PCCATS.push(['pc-work', 'Work abroad']);
  var WNOTE = 'Requirements and terms confirmed at consultation.', BADGES = ['Salary: ‹edit›', 'Visa type: ‹edit›', 'Contract: ‹edit›'];
  function wa(id, name, fam, occ, cty, T, O, tags) {
    if (!OCC[occ]) throw new Error('work abroad template ' + id + ': no occupation ' + occ);
    L.push({ id: id, name: name, cat: 'pc-work', fam: fam, co: 'pc', P: null, occ: occ, cty: cty,
      T: Object.assign({ contact: CT, note: WNOTE, cta: 'Apply now', badges: BADGES }, T), O: O || {}, tags: 'planet careers work abroad job hiring recruitment occupation ' + (tags || ''), pp: [] });
  }
  var HIRE = { kick: 'We’re hiring', h1: '{OP}', h2: 'Work in {N}', ar: 'مطلوب {OPA} للعمل في {A}' };
  wa('wa-hire-welder', 'We’re Hiring · Welders', 'wahire', 'welder', 'pl', HIRE, {}, 'welder trades construction poland');
  wa('wa-hire-nurse', 'We’re Hiring · Nurses', 'wahire', 'nurse', 'de', HIRE, {}, 'nurse health care germany');
  wa('wa-hire-chef', 'We’re Hiring · Chefs', 'wahire', 'chef', 'mt', HIRE, {}, 'chef kitchen hospitality malta');
  wa('wa-hire-driver', 'We’re Hiring · Truck Drivers', 'wahire', 'heavy-driver', 'cz', HIRE, {}, 'driver truck transport czechia');
  wa('wa-hire-electrician', 'We’re Hiring · Electricians', 'wahire', 'electrician', 'lt', HIRE, {}, 'electrician trades construction');
  var JOB = { kick: 'Job opening', h1: '{O}', loc: 'Work in {N}', lines: ['Salary: ‹edit›', 'Contract & visa type: ‹edit›', 'Start date: ‹edit›'], ar: 'وظيفة {OA} في {A}' };
  wa('wa-job-plumber', 'Job Card · Plumber', 'wajob', 'plumber', 'de', JOB, {}, 'plumber job card vacancy');
  wa('wa-job-caregiver', 'Job Card · Caregiver', 'wajob', 'caregiver', 'ca', JOB, {}, 'caregiver care job card vacancy');
  wa('wa-job-security', 'Job Card · Security Guard', 'wajob', 'security-guard', 'ae', JOB, {}, 'security guard job card');
  wa('wa-job-warehouse', 'Job Card · Warehouse', 'wajob', 'warehouse-worker', 'nl', JOB, {}, 'warehouse logistics job card');
  wa('wa-job-cook', 'Job Card · Cook', 'wajob', 'cook', 'cy', JOB, {}, 'cook kitchen job card');
  var SPL = { kick: 'Work abroad', h2: 'Work in {N} as', h1: '{OL}', ar: 'اعمل في {A} في مهنة {OA}' };
  wa('wa-split-teacher', 'Split · Teacher', 'wasplit', 'teacher', 'ae', SPL, {}, 'teacher education school split');
  wa('wa-split-mason', 'Split · Mason', 'wasplit', 'mason', 'hu', SPL, {}, 'mason construction split');
  wa('wa-split-factory', 'Split · Factory Worker', 'wasplit', 'factory-worker', 'cz', SPL, {}, 'factory production split');
  wa('wa-split-housekeeping', 'Split · Housekeeping', 'wasplit', 'housekeeping', 'mt', SPL, {}, 'housekeeping hotel split');
  var POS = { stamp: 'NOW\nHIRING', kick: 'Work abroad · {N}', h1: '{O}', h2: 'Work in {N}', ar: 'اعمل في {A}', cta: 'Apply now' };
  wa('wa-poster-nurse', 'Poster · Nurse', 'waposter', 'nurse', 'gb', POS, {}, 'nurse poster full photo');
  wa('wa-poster-builder', 'Poster · Construction Worker', 'waposter', 'construction-labourer', 'pl', POS, {}, 'construction poster full photo');
  wa('wa-poster-rider', 'Poster · Delivery Rider', 'waposter', 'delivery-rider', 'ae', POS, {}, 'delivery rider poster');
  wa('wa-poster-barista', 'Poster · Barista', 'waposter', 'barista', 'pt', POS, {}, 'barista coffee poster');
  var COL = { kick: 'Work in {N}', h1: 'Now hiring in {N}', ar: 'فرص عمل في {A}', badges: ['Salary: ‹edit›', 'Visa type: ‹edit›', 'Contract: ‹edit›'] };
  wa('wa-duo-trades', 'Collage · Welder & Electrician', 'wacollage', 'welder', 'pl', COL, { with: ['electrician'] }, 'collage duo trades');
  wa('wa-trio-hospitality', 'Collage · Chef, Waiter, Barista', 'wacollage', 'chef', 'mt', COL, { with: ['waiter', 'barista'] }, 'collage trio hospitality');
  wa('wa-trio-care', 'Collage · Nurse & Care', 'wacollage', 'nurse', 'de', COL, { with: ['caregiver', 'elderly-care'] }, 'collage trio health care');
  wa('wa-duo-transport', 'Collage · Driver & Mechanic', 'wacollage', 'heavy-driver', 'lt', COL, { with: ['mechanic'] }, 'collage duo transport');
  var UNI = { kick: 'Work in {N}', h1: '{O}', sub: 'The job, the uniform, the tools', ar: 'مهنة {OA}' };
  wa('wa-uniform-electrician', 'Uniform & Tools · Electrician', 'wauniform', 'electrician', 'de', UNI, {}, 'uniform tools callout');
  wa('wa-uniform-chef', 'Uniform & Tools · Chef', 'wauniform', 'chef', 'ae', UNI, {}, 'uniform tools chef');
  wa('wa-uniform-nurse', 'Uniform & Tools · Nurse', 'wauniform', 'nurse', 'ie', UNI, {}, 'uniform tools nurse');
  wa('wa-uniform-welder', 'Uniform & Tools · Welder', 'wauniform', 'welder', 'cz', UNI, {}, 'uniform tools welder ppe');
  var TEAM = { kick: 'Join our team', h1: 'Work in {N}', roles: 'Open roles: {O} · ‹edit›', ar: 'انضم إلى فريق العمل في {A}', cta: 'Apply now' };
  wa('wa-team-construction', 'Team · Construction', 'wateam', 'construction-labourer', 'pl', TEAM, { with: ['electrician', 'welder', 'carpenter', 'site-supervisor'] }, 'team group construction');
  wa('wa-team-hospitality', 'Team · Hospitality', 'wateam', 'chef', 'ae', TEAM, { with: ['waiter', 'housekeeping', 'front-desk'] }, 'team group hotel hospitality');
  wa('wa-team-factory', 'Team · Factory', 'wateam', 'factory-worker', 'cz', TEAM, { with: ['packer', 'machine-operator', 'quality-inspector'] }, 'team group factory production');
  wa('wa-team-care', 'Team · Health & Care', 'wateam', 'nurse', 'gb', TEAM, { with: ['caregiver', 'doctor', 'elderly-care'] }, 'team group health care');
  var IDT = { pass: 'STAFF PASS', kick: 'Your next job', h1: 'Work in {N} as {OL}', dest: 'Destination: {N}', idl: 'ID ‹edit› · Start ‹edit›', ar: 'وظيفتك القادمة في {A}' };
  wa('wa-id-forklift', 'Staff Pass · Forklift Operator', 'waid', 'forklift-operator', 'de', IDT, {}, 'staff pass id badge lanyard');
  wa('wa-id-security', 'Staff Pass · Security', 'waid', 'security-guard', 'ae', IDT, {}, 'staff pass id security');
  wa('wa-id-housemaid', 'Staff Pass · Domestic', 'waid', 'housemaid', 'sg', IDT, {}, 'staff pass domestic helper');
  wa('wa-id-farm', 'Staff Pass · Farm Worker', 'waid', 'farm-worker', 'pt', IDT, {}, 'staff pass farm agriculture');
  var JRN = { kick: 'From home to abroad', h1: 'Next stop: {N}', h2: 'Your new job as {OL}', from: 'Home ‹edit›', ar: 'من بلدك إلى {A}' };
  wa('wa-journey-picker', 'Journey · Fruit Picker', 'wajourney', 'fruit-picker', 'nz', JRN, {}, 'journey travel from home seasonal');
  wa('wa-journey-driver', 'Journey · Driver', 'wajourney', 'light-driver', 'ca', JRN, {}, 'journey travel driver');
  wa('wa-journey-teacher', 'Journey · Teacher', 'wajourney', 'english-teacher', 'kr', JRN, {}, 'journey travel teacher');
  wa('wa-journey-butcher', 'Journey · Butcher', 'wajourney', 'butcher', 'ie', JRN, {}, 'journey travel food');
  var STP = { kick: 'Work in {N}', h1: 'How to apply as {OL}', ar: 'خطوات التقديم', steps: [['Free assessment', 'Your skills and experience'], ['Documents', 'We tell you what is needed'], ['Job offer & work permit', 'Terms ‹edit› · set by the employer and the authorities'], ['Travel & arrival', 'Pre-departure guidance']] };
  wa('wa-steps-welder', 'How to Apply · Welder', 'wasteps', 'welder', 'hu', STP, {}, 'how to apply steps process');
  wa('wa-steps-caregiver', 'How to Apply · Caregiver', 'wasteps', 'caregiver', 'gb', STP, {}, 'how to apply steps care');
  wa('wa-steps-it', 'How to Apply · IT', 'wasteps', 'it-specialist', 'de', STP, {}, 'how to apply steps it office');
  var URG = { burst: 'URGENT\nHIRING', kick: 'Work in {N}', h1: 'Open roles', qty: '‹edit› posts', ar: 'مطلوب بشكل عاجل' };
  wa('wa-urgent-construction', 'Urgent Hiring · Construction', 'waurgent', 'mason', 'pl', URG, { with: ['steel-fixer', 'carpenter', 'electrician', 'plumber'] }, 'urgent hiring list roles construction');
  wa('wa-urgent-hospitality', 'Urgent Hiring · Hospitality', 'waurgent', 'cook', 'ae', URG, { with: ['waiter', 'barista', 'housekeeping', 'steward'] }, 'urgent hiring list roles hotel');
  wa('wa-urgent-factory', 'Urgent Hiring · Factory', 'waurgent', 'factory-worker', 'lt', URG, { with: ['packer', 'forklift-operator', 'machine-operator', 'warehouse-worker'] }, 'urgent hiring list roles factory');
