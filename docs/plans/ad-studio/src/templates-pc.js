  // ════ PLANET CAREERS · study, visa and immigration consultancy (owner, 16 Nov 2026: "create an environment
  //      'Planet Careers' ... european countries, UK, Canada, AUS, etc and other major countries need to be covered
  //      in terms of images ads, related stuff ... flags, themes etc." -- professional, not basic). Spliced into
  //      templates.js by build.py. Every design is drawn from the company's kit (navy / red / white and Afacad for
  //      Planet Careers, from theplanetcareers.com; the company's own colours for any other company on this set), never
  //      from a fixed palette. kitPal turns the kit into roles, so text keeps 4.5:1 on its ground whatever the colours.
  //      Country templates take a country (C): flag, outline, landmark, flag colours and the name in English and
  //      Arabic. Facts the owner must verify are placeholders ("‹edit›"); no visa rule is ever written as fact. ════
  var CTRY = {}, CTRY_LIST = [];
  [['gb', 'United Kingdom', 'the UK', 'المملكة المتحدة', 'big-ben', 'Elizabeth Tower, London', 'europe', ['#012169', '#C8102E', '#FFFFFF']],
    ['ca', 'Canada', 'Canada', 'كندا', 'cn-tower', 'CN Tower, Toronto', 'north-america', ['#D80621', '#FFFFFF', '#D80621']],
    ['au', 'Australia', 'Australia', 'أستراليا', 'opera-house', 'Opera House, Sydney', 'oceania', ['#00008B', '#FFFFFF', '#E4002B']],
    ['nz', 'New Zealand', 'New Zealand', 'نيوزيلندا', 'sky-tower', 'Sky Tower, Auckland', 'oceania', ['#00247D', '#CC142B', '#FFFFFF']],
    ['us', 'United States', 'the USA', 'الولايات المتحدة', 'liberty', 'Statue of Liberty, New York', 'north-america', ['#0A3161', '#B31942', '#FFFFFF']],
    ['ie', 'Ireland', 'Ireland', 'أيرلندا', 'hapenny-bridge', "Ha'penny Bridge, Dublin", 'europe', ['#169B62', '#FFFFFF', '#FF883E']],
    ['de', 'Germany', 'Germany', 'ألمانيا', 'brandenburg-gate', 'Brandenburg Gate, Berlin', 'europe', ['#000000', '#DD0000', '#FFCE00']],
    ['fr', 'France', 'France', 'فرنسا', 'eiffel', 'Eiffel Tower, Paris', 'europe', ['#002654', '#FFFFFF', '#CE1126']],
    ['nl', 'Netherlands', 'the Netherlands', 'هولندا', 'windmill', 'Windmill, Netherlands', 'europe', ['#AE1C28', '#FFFFFF', '#21468B']],
    ['it', 'Italy', 'Italy', 'إيطاليا', 'pisa', 'Leaning Tower, Pisa', 'europe', ['#009246', '#FFFFFF', '#CE2B37']],
    ['es', 'Spain', 'Spain', 'إسبانيا', 'sagrada', 'Sagrada Família, Barcelona', 'europe', ['#AA151B', '#F1BF00', '#AA151B']],
    ['pt', 'Portugal', 'Portugal', 'البرتغال', 'belem', 'Belém Tower, Lisbon', 'europe', ['#006600', '#FF0000', '#FFFF00']],
    ['pl', 'Poland', 'Poland', 'بولندا', 'palace-culture', 'Palace of Culture and Science, Warsaw', 'europe', ['#FFFFFF', '#DC143C', '#DC143C']],
    ['mt', 'Malta', 'Malta', 'مالطا', 'valletta-dome', 'Valletta skyline, Malta', 'europe', ['#FFFFFF', '#CF142B', '#CF142B']],
    ['hu', 'Hungary', 'Hungary', 'المجر', 'parliament-hu', 'Parliament, Budapest', 'europe', ['#CE2939', '#FFFFFF', '#477050']],
    ['cz', 'Czechia', 'Czechia', 'التشيك', 'prague-tower', 'Old Town Bridge Tower, Prague', 'europe', ['#11457E', '#FFFFFF', '#D7141A']],
    ['lt', 'Lithuania', 'Lithuania', 'ليتوانيا', 'gediminas', 'Gediminas Tower, Vilnius', 'europe', ['#FDB913', '#006A44', '#C1272D']],
    ['lv', 'Latvia', 'Latvia', 'لاتفيا', 'riga-spire', "St Peter's spire, Riga", 'europe', ['#9E3039', '#FFFFFF', '#9E3039']],
    ['se', 'Sweden', 'Sweden', 'السويد', 'stockholm-hall', 'City Hall, Stockholm', 'europe', ['#006AA7', '#FECC02', '#006AA7']],
    ['fi', 'Finland', 'Finland', 'فنلندا', 'helsinki-cathedral', 'Helsinki Cathedral', 'europe', ['#FFFFFF', '#002F6C', '#FFFFFF']],
    ['dk', 'Denmark', 'Denmark', 'الدنمارك', 'nyhavn', 'Nyhavn, Copenhagen', 'europe', ['#C8102E', '#FFFFFF', '#C8102E']],
    ['at', 'Austria', 'Austria', 'النمسا', 'riesenrad', 'Riesenrad, Vienna', 'europe', ['#C8102E', '#FFFFFF', '#C8102E']],
    ['be', 'Belgium', 'Belgium', 'بلجيكا', 'atomium', 'Atomium, Brussels', 'europe', ['#000000', '#FDDA24', '#EF3340']],
    ['cy', 'Cyprus', 'Cyprus', 'قبرص', 'aphrodite-rock', "Aphrodite's Rock, Cyprus", 'europe', ['#FFFFFF', '#D57800', '#4E5B31']],
    ['gr', 'Greece', 'Greece', 'اليونان', 'parthenon', 'Parthenon, Athens', 'europe', ['#0D5EAF', '#FFFFFF', '#0D5EAF']],
    ['ch', 'Switzerland', 'Switzerland', 'سويسرا', 'matterhorn', 'Matterhorn, Switzerland', 'europe', ['#DA291C', '#FFFFFF', '#DA291C']],
    ['ae', 'United Arab Emirates', 'the UAE', 'الإمارات', 'burj-khalifa', 'Burj Khalifa, Dubai', 'middle-east', ['#00732F', '#FFFFFF', '#FF0000']],
    ['jp', 'Japan', 'Japan', 'اليابان', 'fuji-torii', 'Mount Fuji with torii, Japan', 'asia', ['#FFFFFF', '#BC002D', '#FFFFFF']],
    ['kr', 'South Korea', 'South Korea', 'كوريا الجنوبية', 'seoul-tower', 'N Seoul Tower', 'asia', ['#FFFFFF', '#CD2E3A', '#0047A0']],
    ['sg', 'Singapore', 'Singapore', 'سنغافورة', 'marina-bay', 'Marina Bay Sands, Singapore', 'asia', ['#EF3340', '#FFFFFF', '#EF3340']],
    ['my', 'Malaysia', 'Malaysia', 'ماليزيا', 'petronas', 'Petronas Towers, Kuala Lumpur', 'asia', ['#010066', '#CC0001', '#FFCC00']],
    ['tr', 'Türkiye', 'Türkiye', 'تركيا', 'galata', 'Galata Tower, Istanbul', 'europe-asia', ['#E30A17', '#FFFFFF', '#E30A17']],
    ['ee', 'Estonia', 'Estonia', 'إستونيا', 'tallinn-towers', 'Old Town towers, Tallinn', 'europe', ['#0072CE', '#000000', '#FFFFFF']]
  ].forEach(function (r) {
    var c = { code: r[0], name: r[1], dn: r[2], ar: r[3], lm: r[4], lmName: r[5], region: r[6], colors: r[7] };
    // the accent: the flag's most saturated colour (red for Germany, blue for Greece, green for Ireland)
    c.acc = c.colors.slice().sort(function (a, b) { return satOf(b) - satOf(a); })[0];
    CTRY[c.code] = c; CTRY_LIST.push(c);
  });
  function rgbOf(h) { var n = parseInt(h.slice(1, 7), 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; }
  function satOf(h) { var c = rgbOf(h), mx = Math.max.apply(null, c), mn = Math.min.apply(null, c); return mx ? (mx - mn) / mx * (mx / 255 > .25 ? 1 : .3) : 0; }
  function mix(a, b, t) { var x = rgbOf(a), y = rgbOf(b); return '#' + x.map(function (c, i) { return ('0' + Math.round(c + (y[i] - c) * t).toString(16)).slice(-2); }).join('').toUpperCase(); }
  // WCAG contrast ratio, hue, and a colour lifted toward white just far enough to reach a ratio on a ground
  function relL(h) { return rgbOf(h).reduce(function (s, c, i) { c /= 255; return s + (c <= .04045 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4)) * [.2126, .7152, .0722][i]; }, 0); }
  function cr(a, b) { var x = relL(a), y = relL(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }
  function hueOf(h) { var c = rgbOf(h), mx = Math.max.apply(null, c), mn = Math.min.apply(null, c), d = mx - mn; if (d < 24) return -1; var r = c[0], g = c[1], b = c[2];
    return (mx === r ? (g - b) / d + (g < b ? 6 : 0) : mx === g ? (b - r) / d + 2 : (r - g) / d + 4) * 60; }
  function hueGap(a, b) { var x = hueOf(a), y = hueOf(b); if (x < 0 || y < 0) return 0; var d = Math.abs(x - y); return d > 180 ? 360 - d : d; }
  function lift(c, bg, r) { for (var t = 0; t <= 1; t += .02) { var m = mix(c, '#FFFFFF', t); if (cr(m, bg) >= r) return m; } return '#FFFFFF'; }
  // a company's kit colours -> the roles every Planet Careers family draws with
  //   nv / tl / gd   primary / secondary / accent (gd fills buttons, chips, badges; onGd is the text on it)
  //   hl             text highlight on the night ground: the accent when it reads there at 4.5:1 (gold), else the light colour
  //   kp             the accent does not read as text on the ground (Planet Careers' red on navy): kickers become accent pills
  //   ic             icons, rings, flight paths on the night ground: the accent, lifted to 3:1 when it is darker than that
  //   tlD / tlW      the secondary for washes and glows on dark / light grounds -- when its hue is far from the primary's
  //                  (red on navy) the primary's own lighter shade, so the red stays a crisp accent and nothing turns purple
  //   gdD            glows of the accent on dark; orn the greeting ornaments; my / fact the myth and fact labels
  function kitPal(c, fh, fb) {
    var nv = c.primary, tl = c.secondary, gd = c.accent, ink = c.ink || '#0A1B30', soft = c.soft || '#EEF3F6', on = readable(nv), light = lum(nv) > .62;
    var near = hueGap(nv, tl) < 70, tlD = near ? tl : mix(nv, '#FFFFFF', .26), tlW = near ? tl : nv;
    var hiOk = light || cr(gd, nv) >= 4.5, hl = hiOk ? gd : cr(soft, nv) >= 4.5 ? soft : on;
    var tlL = light ? tl : mix(tl, '#FFFFFF', .35); if (!light && cr(tlL, nv) < 4.5) tlL = hl;
    var lsub = c.slate || mix(ink, '#FFFFFF', .38); if (cr(lsub, '#FFFFFF') < 4.6) lsub = mix(lsub, ink, .35);
    var hu = hueOf(gd), my = hu >= 0 && (hu < 20 || hu > 330) ? gd : '#C0392B';
    return { nv: nv, tl: tl, gd: gd, soft: soft, ink: ink, nv2: mix(nv, tlD, .2), dk: mix(nv, '#000000', .38), on: on,
      sub: mix(on, nv, .3), tlL: tlL, onGd: lum(gd) > .55 ? (lum(ink) < .3 ? ink : '#161616') : '#FFFFFF', onTl: readable(tl),
      lsub: lsub, fh: fh || 'afacad', fb: fb || 'afacad',
      hl: hl, kp: !hiOk, ic: cr(gd, nv) >= 3 ? gd : lift(gd, nv, 3.2), tlD: tlD, tlW: tlW, gdD: hiOk ? gd : tlD, orn: hiOk ? gd : soft,
      wash: near ? mix(soft, tl, .25) : mix(soft, nv, .12), my: my, fact: hueGap(tl, my) < 40 ? nv : tl };
  }
  // {N} country name, {D} name in a sentence ("the UK"), {A} Arabic name, {CO} company, {CONTACT}, {WEB}, {CTA}
  function tokens(e, C, c) {
    if (typeof e.txt !== 'string' || e.txt.indexOf('{') < 0) return;
    var k = '', t = e.txt, ct = c.contact || {};
    if (t.indexOf('{N}') >= 0) k += 'n'; if (t.indexOf('{D}') >= 0) k += 'd'; if (t.indexOf('{A}') >= 0) k += 'a';
    var contact = c.contactLine || [ct.phone, ct.whatsapp && 'WhatsApp ' + ct.whatsapp, ct.website].filter(Boolean).join(' · ') || '‹edit: phone · WhatsApp · website›';
    var map = { '{N}': C ? C.name : 'Your destination', '{D}': C ? C.dn : 'your destination', '{A}': C ? C.ar : 'وجهتك', '{CO}': c.fullName || c.name, '{CONTACT}': contact,
      '{WEB}': ct.website || '‹yourwebsite.com›', '{PHONE}': ct.phone || '‹+971 phone›', '{WA}': ct.whatsapp || ct.phone || '‹WhatsApp number›', '{MAIL}': ct.email || '‹email›', '{IG}': ct.instagram || '‹@instagram›', '{ADDR}': ct.address || '‹office address›',
      '{CTA}': (c.endCard && c.endCard.cta && c.endCard.cta[0]) || 'Book a free consultation' };
    Object.keys(map).forEach(function (m) { t = t.split(m).join(map[m]); });
    if (k) e.cyk = k; e.txt = t.slice(0, 200);
  }

  // ── Planet Careers pieces ──
  function bgDark(P, pat, po) { return { k: 'radial', c1: P.nv2, c2: P.dk, stops: [{ c: P.nv2, p: 0 }, { c: P.nv, p: 48 }, { c: P.dk, p: 100 }], rx: 82, ry: 8, ang: 160, pat: pat || 'globe', pc: P.on, po: po == null ? 6 : po, img: '', ov: 0 }; }
  function bgLight(P, pat, po) { return { k: 'linear', c1: '#FFFFFF', c2: P.soft, ang: 165, pat: pat || 'routes', pc: P.tlW, po: po == null ? 22 : po, img: '', ov: 0 }; }
  // a kicker on the night ground (no colour given) is the highlight colour -- or, when the accent cannot be text there, an accent pill
  function pKick(P, txt, o, g) {
    var d = { fn: P.fh, fw: 800, fs: 2.4, ls: 28, up: true, col: P.hl, name: 'Kicker' };
    if (P.kp && (!o || o.col == null)) Object.assign(d, { col: P.onGd, sh: 'pill', fill: P.gd, pd: .7, ls: 22, fs: 2.7 });
    if (o && o.col == null) { o = Object.assign({}, o); delete o.col; }
    return tx('kick', txt, Object.assign(d, o || {}), g);
  }
  function pH1(P, txt, o, g) { return tx('h1', txt, Object.assign({ fn: P.fh, fw: 900, fs: 9.5, lh: 100, ls: -1, col: P.on, name: 'Headline' }, o || {}), g); }
  function pH2(P, txt, o, g) { return tx('h2', txt, Object.assign({ fn: P.fh, fw: 700, fs: 3.6, lh: 120, col: P.tlL, name: 'Second line' }, o || {}), g); }
  function pSub(P, id, txt, o, g) { return tx(id, txt, Object.assign({ fn: P.fb, fw: 500, fs: 2.9, lh: 135, col: P.sub, name: 'Supporting line' }, o || {}), g); }
  function pAr(P, txt, o, g) { return tx('ar', txt, Object.assign({ fn: 'cairo', fw: 700, fs: 3.3, lh: 150, col: P.hl, name: 'Arabic line' }, o || {}), g); }
  function pBtn(P, txt, o, g) { return btn('cta', txt, Object.assign({ fn: P.fh, fw: 800, fs: 3.3, ls: 2, col: P.onGd, fill: P.gd, sh: 'pill', shd: 30, cr: 'acc' }, o || {}), g); }
  function pContact(P, txt, o, g) { return tx('contact', txt, Object.assign({ fn: P.fb, fw: 600, fs: 2.4, lh: 130, col: P.on, name: 'Contact line' }, o || {}), g); }
  function pNote(P, txt, o, g) { return tx('note', txt, Object.assign({ fn: P.fb, fw: 500, fs: 1.9, lh: 130, col: P.sub, op: o && o.col ? 100 : 85, name: 'Check-the-rules note' }, o || {}), g); }   // small print on a light ground stays at full strength (4.5:1)
  function pLogo(P, o, g) { return logo('logo', Object.assign({ txt: 'Planet Careers', fn: P.fh, fw: 800, fs: 2.7, col: P.on }, o || {}), g); }
  function art(id, a, o, g) { return Object.assign({ id: id, t: 'art', art: a, name: 'Art' }, o || {}, { g: g }); }
  function flagC(id, C, g, o) { return art(id, 'flag:' + C.code, Object.assign({ sh: 'circle', bw: 5, bc: '#FFFFFF', shd: 35, name: 'Flag' }, o || {}), g); }
  function flagR(id, C, g, o) { return art(id, 'flag:' + C.code, Object.assign({ sh: 'rect', rad: 1.2, shd: 25, name: 'Flag' }, o || {}), g); }
  // three flag-colour stripes (they follow the country picker)
  function stripes(els, id, C, x, y, w, h, vert) {
    [0, 1, 2].forEach(function (i) {
      var g = vert ? G(x + i * w / 3, y, w / 3 + .05, h) : G(x, y + i * h / 3, w, h / 3 + .05);
      els.push(shp(id + i, 'rect', { fill: C.colors[i], cy: 'c' + (i + 1), name: 'Flag stripe' }, g));
    });
  }
  // feature rows: a text per row; the icons are placed once the column has laid the rows out
  function rowsOf(P, list, x, w, k, o) { return (list || []).map(function (t, i) { return tx('r' + i, t, Object.assign({ fn: P.fb, fw: 600, fs: 2.9, lh: 125, col: P.on, al: 'l', name: 'Point ' + (i + 1) }, o || {}), G(x + 5.4 * k, 0, w - 5.4 * k, 0, 0, k)); }); }
  function rowIcons(els, rows, x, k, col, ic, chip) {
    rows.forEach(function (r, i) {
      var s = r.fs * k * 1.05, y = r.g.y + (r.fs * k * (r.lh || 125) / 100 - s) / 2;
      if (chip) els.push(shp('rc' + i, 'circle', { fill: chip, name: 'Point dot' }, G(x - .3 * k, y - .3 * k, s + .6 * k, s + .6 * k)));
      els.push(ico('ri' + i, ic || 'check', { col: col, name: 'Point icon' }, G(x + (chip ? .2 * k : 0), y + (chip ? .2 * k : 0), s - (chip ? .4 * k : 0), s - (chip ? .4 * k : 0))));
    });
  }
  function fadeB(P, y, h) { return shp('fade', 'rect', { fill: A(P.dk, '00'), fill2: P.dk, gang: 180, name: 'Bottom shade' }, G(0, y, 100, h)); }
  function route(P, g, op) { return shp('route', 'ellipse', { fill: 'transparent', bc: P.ic, bw: 2.2, bs: 'dashed', op: op || 60, name: 'Flight path' }, g); }
  function plane(P, g, r) { g.r = r || 0; return ico('plane', 'plane', { col: P.ic, name: 'Plane' }, g); }
  function none() { return []; }

  // PC1 ── HERO: a country's study (or work / PR) hero on the night-navy ground -- the flag in a white ring, the
  //        name big, three points with accent ticks, the landmark rising behind the button, an accent flight path
  FAM.pchero = function (f, T, P, O, C) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], st = f === 'story';
    els.push(glow('g1', A(P.tlD, '59'), v(f, G(30, -32, 100, 86), G(40, -30, 84, 70), G(10, -18, 120, 96))));
    els.push(art('lm', 'lm:' + C.lm, { col: mix(P.nv, P.tlD, .5), op: 80, name: 'Landmark' }, v(f, G(42, 59, 66, 66), G(56, 50, 50, 50), G(14, 93.78, 84, 84))));
    els.push(fadeB(P, H * .7, H * .3));
    els.push(route(P, v(f, G(38, 5, 84, 34, -12), G(46, 3, 70, 28, -12), G(18, 8, 100, 40, -12)), 55));
    els.push(plane(P, v(f, G(79, 5.5, 7, 7), G(83, 3, 6, 6), G(82, 9, 8, 8)), 70));
    els.push(flagC('flag', C, v(f, G(8, 13, 15, 15), G(8, 12, 12, 12), G(40.5, 18, 19, 19))));
    els.push(pLogo(P, { al: st ? 'c' : 'l' }, v(f, G(8, 4.5, 60, 0, 0, .95), G(8, 3.5, 60, 0, 0, .85), G(20, 6, 60, 0, 0, 1.1))));
    var al = st ? 'c' : 'l', X = v(f, 8, 8, 10), W = v(f, 60, 52, 80);
    var top = [pKick(P, T.kick, { al: al }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: al, fs: 11, cyk: 'n' }, G(X, 0, W, 0, 0, k)), pH2(P, T.h2, { al: al }, G(X, 0, W, 0, 0, k))];
    var rx = st ? 22 : X, rows = rowsOf(P, T.rows, rx, st ? 62 : W, k);
    var arl = pAr(P, T.ar, { al: al }, G(X, 0, W, 0, 0, k));
    column(top.concat(rows, [arl]), v(f, 32, 28, 41), v(f, 89, 72, 112), 1.9 * k);
    if (st) { var c0 = rows[0].g.y - 2, c1 = rows[rows.length - 1].g.y + 5.5; els.push(shp('card', 'rect', { fill: A(P.on, '12'), bc: A(P.on, '2E'), bw: 1.5, rad: 2.5, name: 'Glass card' }, G(16, c0, 68, c1 - c0))); }
    else els.push(shp('card', 'rect', { fill: A(P.on, '12'), name: 'Glass card' }, G(0, 0, 1, 1, 0, 1)));
    if (!st) els[els.length - 1].g.hd = true;
    els = els.concat(top, rows, [arl]);
    rowIcons(els, rows, rx, k, P.ic, 'check');
    els.push(pBtn(P, T.cta, {}, v(f, G(8, 96, 46, 8.4), G(8, 77.5, 40, 7.6), G(20, 141, 60, 9.4))));
    els.push(pContact(P, T.contact, { al: al }, v(f, G(8, 107.5, 62, 0), G(8, 88, 54, 0, 0, .9), G(8, 155, 84, 0, 0, 1.1))));
    els.push(pNote(P, T.note, { al: al }, v(f, G(8, 113.5, 62, 0), G(8, 93.5, 54, 0, 0, .9), G(8, 162, 84, 0, 0, 1.1))));
    return { bg: bgDark(P), els: els, slots: [] };
  };

  // PC2 ── BOARDING PASS: a white ticket on a soft ground -- FROM UAE → TO the country, the flag on the stub, a plane
  //        between the cities, a barcode; "Next stop: …" above it
  FAM.pcpass = function (f, T, P, O, C) {
    var H = FH[f], k = v(f, 1, .88, 1.1), els = [], ink = P.ink;
    var t = v(f, G(7, 46, 86, 48), G(26, 26, 68, 46), G(7, 70, 86, 56));
    els.push(glow('g1', A(P.tlW, '40'), G(-30, -20, 90, 70)));
    els.push(route(P, v(f, G(-20, 22, 140, 70, -8), G(-10, 14, 120, 60, -8), G(-24, 34, 150, 90, -8)), 45));
    els.push(shp('ticket', 'ticket', { fill: '#FFFFFF', shd: 30, name: 'Boarding pass' }, t));
    var bh = t.h * .25;
    els.push(shp('band', 'rect', { fill: P.nv, fill2: P.nv2, gang: 90, rad: 0, name: 'Pass header' }, G(t.x + t.w * .035, t.y, t.w * .93, bh)));
    els.push(shp('perf', 'rect', { fill: 'transparent', bc: '#C9D2DA', bw: 2, bs: 'dashed', name: 'Perforation' }, G(t.x + t.w * .72, t.y + bh + 2, .2, t.h - bh - 4)));
    var cx = t.x + t.w * .05, cw = t.w * .62, k2 = k * t.w / 86;
    els.push(tx('pass', T.pass || 'BOARDING PASS', { fn: P.fh, fw: 800, fs: 2.3, ls: 30, col: P.hl, al: 'l', name: 'Pass title' }, G(cx, t.y + bh * .3, cw, 0, 0, k2)));
    els.push(ico('pi', 'plane', { col: P.on, name: 'Pass plane' }, G(t.x + t.w * .62, t.y + bh * .22, bh * .56, bh * .56, 0)));
    els[els.length - 1].g.r = 90;
    var ry = t.y + bh + t.h * .08;
    els.push(tx('fl', 'FROM', { fn: P.fb, fw: 700, fs: 1.8, ls: 20, col: P.lsub, al: 'l', name: 'Label' }, G(cx, ry, 24, 0, 0, k2)));
    els.push(tx('tl', 'TO', { fn: P.fb, fw: 700, fs: 1.8, ls: 20, col: P.lsub, al: 'l', name: 'Label' }, G(cx + cw * .55, ry, 20, 0, 0, k2)));
    els.push(tx('fv', T.from || 'UAE', { fn: P.fh, fw: 900, fs: 6, lh: 100, col: ink, al: 'l', name: 'From' }, G(cx, ry + 3.2 * k2, cw * .42, 0, 0, k2)));
    els.push(tx('tv', T.to || '{N}', { fn: P.fh, fw: 900, fs: 6, lh: 100, col: P.nv, al: 'l', cyk: 'n', name: 'To' }, G(cx + cw * .55, ry + 3.2 * k2, cw * .45, 0, 0, k2)));
    els.push(ico('arr', 'plane', { col: P.tl, name: 'Arrow plane' }, G(cx + cw * .43, ry + 3.6 * k2, 4.6 * k2, 4.6 * k2)));
    els[els.length - 1].g.r = 90;
    var ry2 = ry + t.h * .3;
    [['l1', 'v1', 'PASSENGER', T.pax || 'You'], ['l2', 'v2', 'PURPOSE', T.purpose || 'Study'], ['l3', 'v3', 'GATE', T.gate || 'Intake ‹edit›']].forEach(function (q, i) {
      var x = cx + i * cw * .36;
      els.push(tx(q[0], q[2], { fn: P.fb, fw: 700, fs: 1.6, ls: 16, col: P.lsub, al: 'l', name: 'Label' }, G(x, ry2, cw * .34, 0, 0, k2)));
      els.push(tx(q[1], q[3], { fn: P.fb, fw: 800, fs: 2.6, col: ink, al: 'l', name: q[2].charAt(0) + q[2].slice(1).toLowerCase() }, G(x, ry2 + 2.6 * k2, cw * .34, 0, 0, k2)));
    });
    var sx = t.x + t.w * .76, sw = t.w * .19;
    els.push(flagC('flag', C, G(sx + sw / 2 - t.h * .13, t.y + bh + t.h * .07, t.h * .26, t.h * .26), { bw: 0, shd: 20 }));
    // barcode: fixed bars, drawn as shapes
    var bx = sx, bw0 = sw / 22, by = t.y + t.h * .7, bhh = t.h * .2;
    [1, 0, 2, 1, 0, 1, 2, 0, 1, 1, 0, 2, 1, 0, 1, 2].forEach(function (q, i) { els.push(shp('bc' + i, 'rect', { fill: ink, name: 'Barcode' }, G(bx + i * bw0 * 1.38, by, bw0 * (q + 1) * .55, bhh))); });
    var al = f === 'square' ? 'l' : 'c', X = f === 'square' ? 6 : 8, W = f === 'square' ? 84 : 84;
    var top = [pKick(P, T.kick, { col: P.tl, al: al }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { col: P.ink, al: al, fs: 9.5, cyk: 'n' }, G(X, 0, W, 0, 0, k)), pSub(P, 'sub', T.sub, { col: P.lsub, al: al }, G(X, 0, W, 0, 0, k))];
    if (f === 'square') { top.forEach(function (e) { e.g.w = 60; }); column(top, 8, 24, 1.2 * k); }
    else column(top, v(f, 10, 0, 16), t.y - 3, 1.6 * k);
    els = els.concat(top);
    els.push(pAr(P, T.ar, { col: P.tl, al: al }, v(f, G(8, t.y + t.h + 3, 84, 0, 0, k), G(26, t.y + t.h + 2.5, 68, 0, 0, k), G(8, t.y + t.h + 5, 84, 0, 0, k))));
    els.push(pBtn(P, T.cta, { fill: P.nv, col: P.on }, v(f, G(27, 103, 46, 8.2), G(6, 82, 40, 7.6), G(20, 145, 60, 9.4))));
    els.push(pContact(P, T.contact, { col: P.ink, al: f === 'square' ? 'l' : 'c' }, v(f, G(8, 113.5, 84, 0), G(48, 83.2, 46, 0, 0, .9), G(8, 159, 84, 0, 0, 1.1))));
    els.push(pNote(P, T.note, { col: P.lsub, al: f === 'square' ? 'l' : 'c' }, v(f, G(8, 119, 84, 0), G(48, 88.5, 46, 0, 0, .9), G(8, 166, 84, 0, 0, 1.1))));
    els.push(pLogo(P, { col: P.nv, al: f === 'square' ? 'l' : 'c' }, v(f, G(20, 4, 60, 0, 0, .95), G(6, 93.2, 50, 0, 0, .8), G(20, 6, 60, 0, 0, 1.1))));
    return { bg: bgLight(P), els: els, slots: [] };
  };

  // PC3 ── POSTCARD ARCH: ivory-white ground, a tall arch window washed in the kit's soft colour with the landmark standing in
  //        it, an accent hairline arch around it, flag-colour stripes at its sill; an elegant serif title
  FAM.pcarch = function (f, T, P, O, C) {
    var H = FH[f], k = v(f, 1, .9, 1.1), els = [], sq = f === 'square';
    var a = v(f, G(52, 22, 40, 74), G(56, 12, 36, 68), G(20, 50, 60, 68));
    els.push(shp('al', 'arch', { fill: 'transparent', bc: P.gd, bw: 3, name: 'Arch line' }, G(a.x - 2.2, a.y - 2.2, a.w + 4.4, a.h + 2.2)));
    els.push(shp('arch', 'arch', { fill: P.wash, fill2: P.soft, gang: 180, name: 'Arch window' }, a));
    els.push(glow('ag', '#FFFFFFC0', G(a.x, a.y + a.h * .05, a.w, a.w * .9)));
    els.push(art('lm', 'lm:' + C.lm, { col: P.nv, name: 'Landmark' }, G(a.x - a.w * .05, a.y + a.h - a.w * 1.1, a.w * 1.1, a.w * 1.1)));
    stripes(els, 'st', C, a.x, a.y + a.h, a.w, 2.4, true);
    els.push(flagC('flag', C, G(a.x + a.w - a.w * .18, a.y + a.h * .5, a.w * .3, a.w * .3), { bw: 6 }));
    var X = v(f, 7, 6, 8), W = v(f, 42, 46, 84), al = f === 'story' ? 'c' : 'l';
    var top = [pLogo(P, { col: P.nv, al: al }, G(X, 0, W, 0, 0, k * .95)), pKick(P, T.kick, { col: P.tl, al: al, ls: 22 }, G(X, 0, W, 0, 0, k)),
      pH1(P, T.h1, { fn: 'playfair', fw: 700, ls: 0, lh: 104, fs: v(f, 8.4, 7, 10), col: P.ink, al: al, cyk: 'n' }, G(X, 0, W, 0, 0, k)),
      tx('rule', '— ✦ —', { fn: P.fb, fw: 600, fs: 2.2, col: P.gd, al: al, name: 'Ornament' }, G(X, 0, W, 0, 0, k)),
      pSub(P, 'sub', T.sub, { col: P.lsub, al: al }, G(X, 0, W, 0, 0, k))];
    var rows = rowsOf(P, T.rows, f === 'story' ? 18 : X, f === 'story' ? 64 : W, k, { col: P.ink, fs: 2.7 });
    var arl = pAr(P, T.ar, { col: P.tl, al: al }, G(X, 0, W, 0, 0, k));
    if (f === 'story') { column(top, 5, a.y - 4, 1.4 * k); column(rows.concat([arl]), a.y + a.h + 6, 154, 1.8 * k); }
    else column(top.concat(rows, [arl]), v(f, 6, 5, 0), v(f, 100, 78, 0), 1.7 * k);
    els = els.concat(top, rows, [arl]);
    rowIcons(els, rows, f === 'story' ? 18 : X, k, P.onTl, 'check', P.tl);
    els.push(pBtn(P, T.cta, { fill: P.nv, col: P.on }, v(f, G(7, 106, 42, 8.2), G(6, 85, 40, 7.4), G(22, 158, 56, 9))));
    els.push(pContact(P, T.contact, { col: P.ink, al: al }, v(f, G(52, 104.5, 42, 0, 0, .9), G(56, 85, 38, 0, 0, .8), G(8, 169.5, 84, 0, 0, 1.05))));
    els.push(pNote(P, T.note, { col: P.lsub, al: al }, v(f, G(52, 110.2, 42, 0, 0, .9), G(56, 90.2, 38, 0, 0, .8), G(8, 2, 84, 0, 0, 1))));
    if (f === 'story') els[els.length - 1].g.hd = true;
    return { bg: { k: 'linear', c1: '#FFFDF8', c2: mix(P.soft, '#FFFFFF', .3), ang: 180, pat: 'none', pc: P.tl, po: 0, img: '', ov: 0 }, els: els, slots: [] };
  };

  // PC4 ── MAP SPLIT: navy ground, the country's outline huge in a lighter shade with an accent pin; the flag as a rounded card;
  //        the copy beside (post, square) or under it (story)
  FAM.pcmap = function (f, T, P, O, C) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], st = f === 'story';
    els.push(glow('g1', A(P.tlD, '4D'), v(f, G(30, 6, 90, 90), G(40, -4, 76, 76), G(-5, 10, 110, 100))));
    var m = v(f, G(50, 16, 56, 56), G(57, 9, 44, 44), G(10, 18, 80, 80));
    els.push(art('map', 'map:' + C.code, { col: P.tlD === P.tl ? mix(P.tl, P.nv, .25) : P.tlD, name: 'Country outline' }, m));
    els.push(ico('pin', 'pin', { col: P.ic, shd: 40, name: 'Pin' }, G(m.x + m.w * .45, m.y + m.h * .36, m.w * .12, m.w * .12)));
    els.push(flagR('flag', C, v(f, G(70, 84, 22, 16.5), G(76, 66, 18, 13.5), G(66, 90, 24, 18)), { bw: 0, rad: 1.5 }));
    els.push(pLogo(P, { al: 'l' }, v(f, G(7, 5, 60, 0, 0, .95), G(6, 4, 60, 0, 0, .85), G(7, 6, 60, 0, 0, 1.1))));
    var X = v(f, 7, 6, 8), W = v(f, 46, 48, 84), al = 'l';
    var top = [pKick(P, T.kick, { al: al }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: al, fs: 9, cyk: 'n' }, G(X, 0, W, 0, 0, k)), pH2(P, T.h2, { al: al }, G(X, 0, W, 0, 0, k))];
    var rows = rowsOf(P, T.rows, X, v(f, 60, 48, 84), k);
    var arl = pAr(P, T.ar, { al: al }, G(X, 0, W, 0, 0, k));
    column(top.concat(rows, [arl]), v(f, 30, 22, 100), v(f, 94, 74, 148), 1.8 * k);
    els = els.concat(top, rows, [arl]);
    rowIcons(els, rows, X, k, P.ic, 'check');
    els.push(pBtn(P, T.cta, {}, v(f, G(7, 101, 46, 8.2), G(6, 79, 40, 7.4), G(8, 153, 56, 9))));
    els.push(pContact(P, T.contact, { al: al }, v(f, G(7, 112, 86, 0), G(6, 89, 60, 0, 0, .9), G(8, 165, 84, 0, 0, 1.1))));
    els.push(pNote(P, T.note, { al: al }, v(f, G(7, 117.6, 86, 0), G(6, 94, 60, 0, 0, .9), G(8, 171.4, 84, 0, 0, 1.05))));
    return { bg: bgDark(P, 'grid', 4), els: els, slots: [] };
  };

  // PC5 ── INTAKE: a calendar card with the month huge, "Intake open", five destination flags under it
  FAM.pcintake = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], sq = f === 'square';
    els.push(glow('g1', A(P.gdD, '33'), v(f, G(-20, 10, 140, 90), G(-10, -10, 120, 90), G(-30, 20, 160, 110))));
    var c = v(f, G(16, 15, 68, 50), G(6, 16, 44, 54), G(12, 22, 76, 56));
    els.push(shp('card', 'rect', { fill: '#FFFFFF', rad: 3, shd: 45, name: 'Calendar card' }, c));
    els.push(shp('band', 'rect', { fill: P.tl, rad: 0, name: 'Calendar band' }, G(c.x, c.y, c.w, c.h * .2)));
    [.28, .72].forEach(function (q, i) { els.push(shp('ring' + i, 'pill', { fill: P.nv, name: 'Calendar ring' }, G(c.x + c.w * q - 1.2, c.y - 2.4, 2.4, 5.6))); });
    els.push(tx('cl', T.cl || 'INTAKE', { fn: P.fh, fw: 800, fs: 3, ls: 40, col: P.onTl, name: 'Card label' }, G(c.x, c.y + c.h * .055, c.w, 0, 0, c.w / 68)));
    els.push(tx('mon', T.mon, { fn: 'bebas', fw: 400, fs: 22, lh: 90, ls: 2, col: P.nv, name: 'Month' }, G(c.x + 2, c.y + c.h * .25, c.w - 4, 0, 0, c.w / 68)));
    els.push(tx('cs', T.cs || 'APPLICATIONS OPEN', { fn: P.fh, fw: 800, fs: 2.6, ls: 22, col: P.tl, name: 'Card line' }, G(c.x + 2, c.y + c.h * .8, c.w - 4, 0, 0, c.w / 68)));
    var fl = ['gb', 'ca', 'au', 'de', 'ie'];
    var fy = v(f, 69, 76, 82), fs = v(f, 9, 7.5, 11), fx = v(f, 50 - fs * 2.75, 6, 50 - fs * 2.75);
    fl.forEach(function (cc, i) { els.push(art('f' + i, 'flag:' + cc, { sh: 'circle', bw: 4, bc: '#FFFFFF', shd: 25, name: 'Flag' }, G(fx + i * fs * 1.12, fy, fs, fs))); });
    var X = v(f, 8, 54, 8), W = v(f, 84, 40, 84), al = sq ? 'l' : 'c';
    var top = [pKick(P, T.kick, { al: al }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: al, fs: 8.4 }, G(X, 0, W, 0, 0, k)), pSub(P, 'sub', T.sub, { al: al }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: al }, G(X, 0, W, 0, 0, k))];
    if (sq) column(top, 16, 70, 2 * k); else column(top, v(f, 81, 0, 96), v(f, 108, 0, 136), 1.6 * k);
    els = els.concat(top);
    els.push(pBtn(P, T.cta, {}, v(f, G(8, 111, 40, 7.8), G(54, 76, 40, 7.4), G(20, 142, 60, 9.2))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(51, 110.5, 43, 0, 0, .85), G(54, 86, 42, 0, 0, .85), G(8, 155, 84, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(51, 115.8, 43, 0, 0, .85), G(54, 91.2, 42, 0, 0, .85), G(8, 161.5, 84, 0, 0, 1.05))));
    if (f === 'story') els[els.length - 1].al = 'c';
    els.push(pLogo(P, { al: 'c' }, v(f, G(20, 4.5, 60, 0, 0, .95), G(6, 4, 60, 0, 0, .85), G(20, 6, 60, 0, 0, 1.1))));
    if (sq) els[els.length - 1].al = 'l';
    return { bg: bgDark(P, 'routes', 10), els: els, slots: [] };
  };

  // PC6 ── STEPS: an accent timeline of 4 numbered steps (PR pathway, how it works, work permit process)
  FAM.pcsteps = function (f, T, P, O, C) {
    var H = FH[f], k = v(f, 1, .88, 1.12), els = [], st = f === 'story', light = !!O.light;
    var ink = light ? P.ink : P.on, sub = light ? P.lsub : P.sub;
    if (C) els.push(art('lm', 'lm:' + C.lm, { col: light ? mix(P.soft, P.tlW, .3) : mix(P.nv, P.tlD, .35), op: 70, name: 'Landmark' }, v(f, G(58, 63, 50, 50), G(64, 50, 40, 40), G(46, 115, 62, 62))));
    if (C) els.push(flagC('flag', C, v(f, G(80, 5, 12, 12), G(84, 4, 10, 10), G(78, 6, 14, 14)), { bw: 4 }));
    els.push(pLogo(P, { al: 'l', col: light ? P.nv : P.on }, v(f, G(7, 5, 60, 0, 0, .95), G(6, 4, 60, 0, 0, .85), G(7, 7, 60, 0, 0, 1.1))));
    var X = v(f, 7, 6, 8), W = v(f, 86, 88, 84);
    var top = [pKick(P, T.kick, { al: 'l', col: light ? P.tl : null }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 8, col: ink, cyk: C ? 'n' : undefined }, G(X, 0, W - 14, 0, 0, k))];
    var ty = column(top, v(f, 14, 11, 16), v(f, 38, 30, 44), 1.6 * k); els = els.concat(top);
    var steps = T.steps || [], n = steps.length, y0 = ty + v(f, 4, 3, 7), y1 = v(f, 96, 78, 132), sh = (y1 - y0) / n, d = v(f, 8, 6.8, 10);
    els.push(shp('spine', 'rect', { fill: A(light ? P.gd : P.ic, '80'), name: 'Timeline' }, G(X + d / 2 - .25, y0 + d / 2, .5, sh * (n - 1))));
    steps.forEach(function (s, i) {
      var y = y0 + i * sh;
      els.push(shp('n' + i, 'circle', { fill: i === n - 1 ? P.gd : light ? P.nv : P.tlD, txt: String(i + 1), fn: P.fh, fw: 900, fs: 3.6, col: i === n - 1 ? P.onGd : '#FFFFFF', shd: 20, name: 'Step ' + (i + 1) }, G(X, y, d, d)));
      var tt = tx('s' + i, s[0], { fn: P.fh, fw: 800, fs: 3.3, lh: 115, col: ink, al: 'l', name: 'Step ' + (i + 1) + ' title' }, G(X + d + 3, y - .2, W - d - 3, 0, 0, k));
      var td = tx('d' + i, s[1], { fn: P.fb, fw: 500, fs: 2.4, lh: 125, col: sub, al: 'l', name: 'Step ' + (i + 1) + ' detail' }, G(X + d + 3, y + 3.9 * k, W - d - 3, 0, 0, k));
      els.push(tt, td);
    });
    var fy = v(f, 101, 82, 140);
    els.push(pAr(P, T.ar, { al: 'l', col: light ? P.tl : P.hl }, v(f, G(X, fy - 1, 40, 0, 0, k), G(52, fy - .5, 42, 0, 0, k), G(X, fy, 84, 0, 0, k))));
    els.push(pBtn(P, T.cta, light ? { fill: P.nv, col: P.on } : {}, v(f, G(X, 107.5, 44, 8), G(X, 87, 40, 7.2), G(X, 148, 56, 9))));
    els.push(pContact(P, T.contact, { al: 'l', col: ink }, v(f, G(54, 107.6, 40, 0, 0, .85), G(50, 87.2, 44, 0, 0, .8), G(X, 160.5, 84, 0, 0, 1.1))));
    els.push(pNote(P, T.note, { al: 'l', col: sub }, v(f, G(54, 112.8, 40, 0, 0, .85), G(50, 92.2, 44, 0, 0, .8), G(X, 167, 84, 0, 0, 1.05))));
    return { bg: light ? bgLight(P) : bgDark(P), els: els, slots: [] };
  };

  // PC7 ── CHECKLIST: a white sheet on a clipboard, ticked boxes down it (documents; why choose us)
  FAM.pccheck = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .88, 1.1), els = [], sq = f === 'square';
    var c = v(f, G(8, 33, 84, 66), G(46, 8, 48, 84), G(8, 44, 84, 92));
    els.push(shp('sheet2', 'rect', { fill: A(P.on, '1F'), rad: 3, name: 'Sheet behind' }, G(c.x + 2.5, c.y + 2.5, c.w, c.h, 2)));
    els.push(shp('sheet', 'rect', { fill: '#FFFFFF', rad: 3, shd: 40, name: 'Sheet' }, c));
    els.push(shp('clip', 'pill', { fill: P.gd, name: 'Clip' }, G(c.x + c.w / 2 - 9, c.y - 2.4, 18, 4.8)));
    var items = T.items || [], ix = c.x + c.w * .1, iw = c.w * .8, s = v(f, 4.6, 3.8, 5.4);
    var rows = items.map(function (t, i) { return tx('i' + i, t, { fn: P.fb, fw: 600, fs: v(f, 3.3, 2.6, 3.5), lh: 125, col: P.ink, al: 'l', name: 'Item ' + (i + 1) }, G(ix + s + 2.6, 0, iw - s - 2.6, 0, 0, k)); });
    column(rows, c.y + 5, c.y + c.h - 4, 2.4 * k);
    rows.forEach(function (r, i) {
      var y = r.g.y + (r.fs * (r.g.k || k) * 1.25 - s) / 2;
      els.push(shp('b' + i, 'rect', { fill: A(P.tl, '1A'), bc: P.tl, bw: 2.5, rad: .9, name: 'Tick box' }, G(ix, y, s, s)));
      els.push(ico('c' + i, O.icon || 'check', { col: P.tl, name: 'Tick' }, G(ix + s * .15, y + s * .12, s * .7, s * .7)));
      if (i < rows.length - 1) els.push(shp('hr' + i, 'rect', { fill: '#E6ECF0', name: 'Rule' }, G(ix, r.g.y + r.fs * k * 1.25 + 1.2 * k, iw, .25)));
    });
    els = els.concat(rows);
    els.push(pLogo(P, { al: 'l' }, v(f, G(8, 5, 60, 0, 0, .95), G(6, 5, 38, 0, 0, .8), G(8, 7, 60, 0, 0, 1.1))));
    var X = v(f, 8, 6, 8), W = v(f, 84, 37, 84);
    var top = [pKick(P, T.kick, { al: 'l', ls: sq ? 14 : 28 }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: v(f, 8, 6.8, 9) }, G(X, 0, W, 0, 0, k))];
    if (sq) top.push(pSub(P, 'sub', T.sub, { al: 'l' }, G(X, 0, W, 0, 0, k)), pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k)));
    column(top, v(f, 12, 15, 15), v(f, 30, 66, 41), 1.6 * k);
    els = els.concat(top);
    if (!sq) { els.push(pSub(P, 'sub', T.sub, { al: 'c' }, v(f, G(8, 101.5, 84, 0, 0, k), 0, G(8, 140, 84, 0, 0, k)))); els.push(pAr(P, T.ar, { al: 'c' }, v(f, G(8, 0, 0, 0), 0, G(8, 146.5, 84, 0, 0, k)))); }
    var ar = els[els.length - 1];
    if (f === 'post') { ar.g.hd = true; ar.g.y = 2; }
    els.push(pBtn(P, T.cta, {}, v(f, G(8, 108, 44, 8), G(6, 71, 37, 7.2), G(20, 153, 60, 9))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(55, 108, 39, 0, 0, .85), G(6, 81, 38, 0, 0, .8), G(8, 165, 84, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(55, 113.2, 39, 0, 0, .85), G(6, 86.2, 38, 0, 0, .8), G(8, 171, 84, 0, 0, 1.05))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgDark(P, 'grid', 4), els: els, slots: [] };
  };

  // PC8 ── VISA APPROVED: the client's photo in an accent ring (a slot to fill), a "VISA APPROVED" stamp, the
  //        quote, the name -- and the flag of where they are going
  FAM.pcstory = function (f, T, P, O, C) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], sq = f === 'square';
    var ph = v(f, G(27, 14, 46, 46), G(8, 18, 40, 40), G(22, 22, 56, 56));
    els.push(glow('g1', A(P.tlD, '66'), G(ph.x - ph.w * .4, ph.y - ph.w * .4, ph.w * 1.8, ph.w * 1.8)));
    els.push(shp('ring', 'ring', { fill: 'transparent', bc: P.ic, bw: 6, name: 'Photo ring' }, G(ph.x - 2, ph.y - 2, ph.w + 4, ph.w + 4)));
    els.push(shp('photo', 'circle', { fill: mix(P.nv, P.tlD, .3), txt: 'CLIENT\nPHOTO', fn: P.fh, fw: 800, fs: 2.4, ls: 20, col: A(P.on, 'B3'), ph: true, name: 'Client photo (tap → Put a photo here)' }, ph));
    els.push(flagC('flag', C, G(ph.x + ph.w * .74, ph.y + ph.w * .7, ph.w * .3, ph.w * .3)));
    var sp = G(ph.x - ph.w * .12, ph.y + ph.w * .66, ph.w * .5, ph.w * .5);
    els.push(shp('stamp', 'ring', { fill: 'transparent', bc: P.ic, bw: 4, bs: 'dashed', name: 'Stamp ring', op: 95 }, sp));
    els.push(shp('stampc', 'circle', { fill: P.kp ? P.gd : A(P.dk, 'E6'), txt: T.stamp || 'VISA\nAPPROVED', fn: P.fh, fw: 900, fs: 2.6, lh: 105, ls: 6, col: P.kp ? P.onGd : P.gd, name: 'Stamp' }, G(sp.x + sp.w * .08, sp.y + sp.w * .08, sp.w * .84, sp.w * .84, -14)));
    var X = v(f, 8, 52, 8), W = v(f, 84, 42, 84), al = sq ? 'l' : 'c';
    var top = [pKick(P, T.kick, { al: al, ls: sq ? 16 : 28 }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: al, fs: 8.6 }, G(X, 0, W, 0, 0, k)),
      tx('quote', T.quote, { fn: 'playfair', fw: 400, fi: true, fs: 3.6, lh: 132, col: P.on, al: al, name: 'Quote (use the client\'s real words)' }, G(X, 0, W, 0, 0, k)),
      tx('who', T.who, { fn: P.fb, fw: 700, fs: 2.5, lh: 130, col: P.tlL, al: al, cyk: 'n', name: 'Client name' }, G(X, 0, W, 0, 0, k)),
      pAr(P, T.ar, { al: al }, G(X, 0, W, 0, 0, k))];
    column(top, v(f, 66, 10, 86), v(f, 104, 74, 144), 1.8 * k);
    els = els.concat(top);
    els.push(pBtn(P, T.cta, {}, v(f, G(27, 106, 46, 8), G(52, 78.5, 40, 7.2), G(20, 148, 60, 9.2))));
    els.push(pContact(P, T.contact, { al: al }, v(f, G(8, 116.5, 84, 0, 0, .9), G(52, 88, 44, 0, 0, .8), G(8, 161, 84, 0, 0, 1.1))));
    els.push(pLogo(P, { al: sq ? 'l' : 'c' }, v(f, G(20, 4.5, 60, 0, 0, .9), G(8, 5, 60, 0, 0, .85), G(20, 7, 60, 0, 0, 1.1))));
    els.push(pNote(P, T.note, { al: al }, v(f, G(8, 121, 84, 0, 0, .8), G(52, 93, 44, 0, 0, .75), G(8, 167.5, 84, 0, 0, 1))));
    return { bg: bgDark(P, 'sparkle', 30), els: els, slots: [] };
  };

  // PC9 ── TESTIMONIAL: soft white card, big accent quote marks, five stars, the words, a small photo + name
  FAM.pcquote = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [];
    var c = v(f, G(7, 18, 86, 80), G(7, 13, 86, 66), G(7, 30, 86, 108));
    els.push(shp('card', 'rect', { fill: '#FFFFFF', rad: 4, shd: 30, name: 'Card' }, c));
    els.push(shp('bar', 'rect', { fill: P.tlW, fill2: P.gd, gang: 90, rad: 0, name: 'Card top line' }, G(c.x + 6, c.y, c.w - 12, .9)));
    els.push(ico('qm', 'quote', { col: P.gd, name: 'Quote marks' }, G(c.x + 6, c.y + 6, v(f, 12, 10, 14), v(f, 12, 10, 14))));
    els.push(ico('stars', 'stars5', { col: P.gd, name: 'Stars (decoration)' }, G(c.x + c.w - 34, c.y + 8, 28, 5.6)));
    var X = c.x + 7, W = c.w - 14;
    var body = [tx('quote', T.quote, { fn: 'playfair', fw: 400, fs: 4.2, lh: 135, col: P.ink, al: 'l', name: 'Review (paste a real one)' }, G(X, 0, W, 0, 0, k))];
    column(body, c.y + v(f, 22, 18, 26), c.y + c.h - v(f, 22, 17, 26), 2 * k);
    els = els.concat(body);
    var py = c.y + c.h - v(f, 17, 14, 20), ps = v(f, 12, 10, 14);
    els.push(shp('photo', 'circle', { fill: P.soft, bc: P.gd, bw: 4, txt: 'PHOTO', fn: P.fh, fw: 800, fs: 1.6, ls: 10, col: P.lsub, ph: true, name: 'Client photo (tap → Put a photo here)' }, G(X, py, ps, ps)));
    els.push(tx('who', T.who, { fn: P.fh, fw: 800, fs: 2.8, col: P.ink, al: 'l', name: 'Client name' }, G(X + ps + 3, py + ps * .12, W - ps - 3, 0, 0, k)));
    els.push(tx('what', T.what, { fn: P.fb, fw: 500, fs: 2.3, col: P.lsub, al: 'l', name: 'Client detail' }, G(X + ps + 3, py + ps * .12 + 4.2 * k, W - ps - 3, 0, 0, k)));
    els.push(pKick(P, T.kick, { al: 'l', col: P.tl }, v(f, G(7, 11.5, 60, 0, 0, k), G(7, 6.5, 50, 0, 0, k), G(7, 22, 60, 0, 0, k))));
    els.push(pLogo(P, { al: 'l', col: P.nv }, v(f, G(7, 4.5, 60, 0, 0, .95), G(52, 5.8, 41, 0, 0, .8), G(7, 8, 60, 0, 0, 1.1))));
    if (f === 'square') els[els.length - 1].al = 'r';
    els.push(pAr(P, T.ar, { col: P.tl }, v(f, G(7, c.y + c.h + 2.6, 86, 0, 0, k), G(50, 84, 44, 0, 0, k), G(7, c.y + c.h + 4, 86, 0, 0, k))));
    els.push(pBtn(P, T.cta, { fill: P.nv, col: P.on }, v(f, G(7, 107, 42, 8), G(7, 84, 40, 7.2), G(20, 151, 60, 9))));
    els.push(pContact(P, T.contact, { col: P.ink, al: 'l' }, v(f, G(52, 108, 41, 0, 0, .85), G(50, 92, 44, 0, 0, .75), G(7, 164, 86, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgLight(P, 'globe', 10), els: els, slots: [] };
  };

  // PC10 ── EVENT: webinar / expo -- a chip, the title, three info tiles (date, time, where), the speaker
  FAM.pcevent = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], sq = f === 'square';
    els.push(glow('g1', A(P.gdD, '2E'), G(-30, -30, 120, 90)));
    els.push(route(P, v(f, G(40, 60, 90, 60, -18), G(50, 40, 70, 50, -18), G(30, 90, 110, 70, -18)), 35));
    els.push(pLogo(P, { al: 'l' }, v(f, G(7, 5, 60, 0, 0, .95), G(6, 4.5, 60, 0, 0, .85), G(7, 7, 60, 0, 0, 1.1))));
    els.push(ico('evi', O.icon || 'mic', { col: P.ic, name: 'Event icon' }, v(f, G(80, 4, 12, 12), G(84, 3.5, 10, 10), G(78, 6, 14, 14))));
    var X = v(f, 7, 6, 8), W = v(f, 86, 88, 84);
    var top = [tx('chip', T.chip, { fn: P.fh, fw: 800, fs: 2.4, ls: 24, up: true, col: P.onGd, sh: 'pill', fill: P.gd, pd: .9, al: 'l', name: 'Event type' }, G(X, 0, W, 0, 0, k)),
      pH1(P, T.h1, { al: 'l', fs: 8.6 }, G(X, 0, W, 0, 0, k)), pSub(P, 'sub', T.sub, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    var ty = column(top, v(f, 17, 14, 20), v(f, 56, 44, 70), 2 * k); els = els.concat(top);
    var tw = (W - 4) / 3, th = v(f, 15, 13, 18), tyy = ty + v(f, 4, 3, 6);
    [['DATE', T.date || '‹edit date›', 'cal'], ['TIME', T.time || '‹edit time›', 'clock'], [T.whereL || 'WHERE', T.where || '‹edit venue›', 'pin']].forEach(function (q, i) {
      var x = X + i * (tw + 2);
      els.push(shp('tile' + i, 'rect', { fill: A(P.on, '14'), bc: A(P.on, '33'), bw: 1.5, rad: 2.2, name: 'Info tile' }, G(x, tyy, tw, th)));
      els.push(ico('ti' + i, q[2], { col: P.ic, name: 'Tile icon' }, G(x + 2.4, tyy + 2.4, 4.2, 4.2)));
      els.push(tx('tl' + i, q[0], { fn: P.fb, fw: 700, fs: 1.8, ls: 18, col: P.sub, al: 'l', name: 'Tile label' }, G(x + 7.6, tyy + 3, tw - 9, 0, 0, k)));
      els.push(tx('tv' + i, q[1], { fn: P.fh, fw: 800, fs: 2.8, lh: 115, col: P.on, al: 'l', name: q[0].charAt(0) + q[0].slice(1).toLowerCase() }, G(x + 2.4, tyy + th * .52, tw - 4.8, 0, 0, k)));
    });
    var sy = tyy + th + v(f, 6, 4, 8), ps = v(f, 13, 11, 15);
    els.push(shp('photo', 'circle', { fill: mix(P.nv, P.tlD, .3), bc: P.ic, bw: 4, txt: 'PHOTO', fn: P.fh, fw: 800, fs: 1.7, ls: 10, col: A(P.on, 'B3'), ph: true, name: 'Speaker photo (tap → Put a photo here)' }, G(X, sy, ps, ps)));
    els.push(tx('sp', T.speaker || 'Speaker name', { fn: P.fh, fw: 800, fs: 3, col: P.on, al: 'l', name: 'Speaker' }, G(X + ps + 3, sy + ps * .14, 56, 0, 0, k)));
    els.push(tx('spr', T.role || 'Senior consultant', { fn: P.fb, fw: 500, fs: 2.4, col: P.sub, al: 'l', name: 'Speaker role' }, G(X + ps + 3, sy + ps * .14 + 4.4 * k, 56, 0, 0, k)));
    els.push(pAr(P, T.ar, { al: 'l' }, v(f, G(X, sy + ps + 4, 86, 0, 0, k), G(52, 84, 42, 0, 0, k), G(X, sy + ps + 6, 84, 0, 0, k))));
    if (sq) els[els.length - 1].al = 'r';
    els.push(pBtn(P, T.cta, {}, v(f, G(X, 108, 44, 8), G(X, 85, 40, 7.2), G(20, 150, 60, 9.2))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(54, 108.6, 40, 0, 0, .85), G(50, 92, 44, 0, 0, .75), G(8, 163, 84, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgDark(P, 'globe', 6), els: els, slots: [] };
  };

  // PC11 ── DEADLINE: "Last chance" over four countdown tiles (days / hours / minutes / seconds -- edit them)
  FAM.pccount = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [];
    els.push(glow('g1', A(P.ic, '33'), G(-20, v(f, 30, 20, 50), 140, 80)));
    els.push(ico('clk', 'clock', { col: A(P.ic, '2E'), name: 'Big clock' }, v(f, G(52, 6, 56, 56), G(62, 4, 44, 44), G(40, 8, 70, 70))));
    els.push(pLogo(P, { al: 'l' }, v(f, G(7, 5, 60, 0, 0, .95), G(6, 4.5, 60, 0, 0, .85), G(7, 7, 60, 0, 0, 1.1))));
    var X = v(f, 7, 6, 8), W = v(f, 86, 88, 84);
    var top = [pKick(P, T.kick, { al: 'l' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 10 }, G(X, 0, W, 0, 0, k)), pSub(P, 'sub', T.sub, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    var ty = column(top, v(f, 18, 14, 24), v(f, 60, 46, 76), 2 * k); els = els.concat(top);
    var n = 4, gap = 2.4, tw = (W - gap * 3) / 4, th = tw * 1.05, y = ty + v(f, 5, 4, 8);
    [['07', 'DAYS'], ['12', 'HOURS'], ['30', 'MINUTES'], ['00', 'SECONDS']].forEach(function (q, i) {
      var x = X + i * (tw + gap);
      els.push(shp('box' + i, 'rect', { fill: '#FFFFFF', rad: 2.2, shd: 35, name: 'Countdown tile' }, G(x, y, tw, th)));
      els.push(shp('half' + i, 'rect', { fill: A(P.nv, '14'), rad: 0, name: 'Tile fold' }, G(x, y + th / 2, tw, .3)));
      els.push(tx('cn' + i, (T.nums || [])[i] || q[0], { fn: 'bebas', fw: 400, fs: 11, lh: 90, col: P.nv, name: q[1].charAt(0) + q[1].slice(1).toLowerCase() + ' (edit)' }, G(x, y + th * .12, tw, 0, 0, tw / 19.6)));
      els.push(tx('cl' + i, q[1], { fn: P.fb, fw: 700, fs: 1.7, ls: 14, col: P.on, name: 'Label' }, G(x, y + th + 1.6, tw, 0, 0, k)));
    });
    var by = y + th + v(f, 8, 6, 12);
    els.push(pAr(P, T.ar, { al: 'l' }, G(X, by, W, 0, 0, k)));
    els.push(pBtn(P, T.cta, {}, v(f, G(X, 108, 46, 8.2), G(X, 85, 40, 7.4), G(20, 150, 60, 9.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(56, 108.6, 38, 0, 0, .85), G(50, 86, 44, 0, 0, .8), G(8, 163, 84, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(56, 113.8, 38, 0, 0, .85), G(50, 91.2, 44, 0, 0, .8), G(8, 169.5, 84, 0, 0, 1.05))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgDark(P, 'none', 0), els: els, slots: [] };
  };

  // PC12 ── TOP 5: a carousel cover -- five destinations as rows of flag + name + a soft tag, "swipe" chip
  FAM.pctop = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .88, 1.12), els = [], sq = f === 'square';
    els.push(pLogo(P, { al: 'l' }, v(f, G(7, 5, 60, 0, 0, .95), G(6, 4.5, 60, 0, 0, .85), G(7, 7, 60, 0, 0, 1.1))));
    var X = v(f, 7, 6, 8), W = v(f, 86, 40, 84);
    var top = [pKick(P, T.kick, { al: 'l' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: v(f, 9, 7.4, 10) }, G(X, 0, W, 0, 0, k)), pSub(P, 'sub', T.sub, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    column(top, v(f, 15, 16, 18), v(f, 44, 64, 56), 1.8 * k);
    els = els.concat(top);
    var list = T.list || [], rx = v(f, 7, 48, 8), rw = v(f, 86, 46, 84), y0 = v(f, 48, 10, 62), rh = v(f, 10.6, 15.4, 15), s = rh * .66;
    list.forEach(function (q, i) {
      var y = y0 + i * rh, C = CTRY[q[0]];
      els.push(shp('row' + i, 'rect', { fill: A(P.on, i % 2 ? '0D' : '17'), rad: 2, name: 'Row' }, G(rx, y, rw, rh - 1.4)));
      els.push(tx('num' + i, '0' + (i + 1), { fn: 'bebas', fw: 400, fs: 5, col: P.hl, al: 'l', name: 'Number' }, G(rx + 2, y + (rh - 1.4) / 2 - 2.6, 8, 0, 0, k)));
      els.push(art('f' + i, 'flag:' + C.code, { sh: 'circle', bw: 3, bc: '#FFFFFF', name: 'Flag' }, G(rx + 9.5, y + (rh - 1.4 - s) / 2, s, s)));
      els.push(tx('cn' + i, C.name, { fn: P.fh, fw: 800, fs: 3.4, col: P.on, al: 'l', name: 'Country' }, G(rx + 11.5 + s, y + (rh - 1.4) / 2 - (sq ? 3.6 : 3.4) * k, rw - 14 - s, 0, 0, k)));
      els.push(tx('ct' + i, q[1], { fn: P.fb, fw: 500, fs: 2.2, col: P.sub, al: 'l', name: 'Tag' }, G(rx + 11.5 + s, y + (rh - 1.4) / 2 + .6 * k, rw - 14 - s, 0, 0, k)));
    });
    els.push(tx('swipe', T.swipe || 'SWIPE  →', { fn: P.fh, fw: 800, fs: 2.4, ls: 24, col: P.onGd, sh: 'pill', fill: P.gd, pd: 1, name: 'Swipe chip' }, v(f, G(64, 107.5, 30, 0, 0, k), G(6, 70, 34, 0, 0, k), G(30, 147, 40, 0, 0, k))));
    els.push(pAr(P, T.ar, { al: 'l' }, v(f, G(7, 107, 50, 0, 0, k), G(6, 79, 38, 0, 0, k), G(8, 158, 84, 0, 0, k))));
    if (f === 'story') els[els.length - 1].al = 'c';
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(7, 115.5, 86, 0, 0, .9), G(6, 89, 38, 0, 0, .75), G(8, 167, 84, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgDark(P, 'routes', 8), els: els, slots: [] };
  };

  // PC13 ── TWO CARDS: myth vs fact, or a question and its answer
  FAM.pctwo = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], sq = f === 'square', faq = !!O.faq;
    els.push(pLogo(P, { al: 'l', col: P.nv }, v(f, G(7, 5, 60, 0, 0, .95), G(6, 4.5, 60, 0, 0, .85), G(7, 7, 60, 0, 0, 1.1))));
    var X = v(f, 7, 6, 8), W = v(f, 86, 88, 84);
    var top = [pKick(P, T.kick, { al: 'l', col: P.tl }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: v(f, 8.4, 7, 9.4), col: P.ink }, G(X, 0, W, 0, 0, k))];
    var ty = column(top, v(f, 13, 11, 17), v(f, 34, 27, 42), 1.6 * k) + v(f, 3, 2.5, 5); els = els.concat(top);
    var cw = sq ? (W - 3) / 2 : W, ch = v(f, 31, 50, 44);
    [[faq ? 'Q' : 'MYTH', T.a, faq ? P.nv : P.my, faq ? 'chat' : 'xmark'], [faq ? 'A' : 'FACT', T.b, faq ? P.tl : P.fact, faq ? 'shield' : 'check']].forEach(function (q, i) {
      var x = sq ? X + i * (cw + 3) : X, y = sq ? ty : ty + i * (ch + 3);
      els.push(shp('c' + i, 'rect', { fill: '#FFFFFF', bc: A(q[2], '40'), bw: 2, rad: 3, shd: 18, name: 'Card' }, G(x, y, cw, ch)));
      els.push(shp('cb' + i, 'rect', { fill: q[2], rad: 0, name: 'Card edge' }, G(x, y + 2, .9, ch - 4)));
      els.push(tx('cl' + i, q[0], { fn: P.fh, fw: 900, fs: 2.6, ls: 24, col: '#FFFFFF', sh: 'pill', fill: q[2], pd: .8, al: 'l', name: 'Card label' }, G(x + 4, y + 4, cw - 8, 0, 0, k)));
      var body = tx('ct' + i, q[1], { fn: i === 0 && !faq ? 'playfair' : P.fb, fw: i === 0 && !faq ? 700 : 600, fi: i === 0 && !faq, fs: v(f, 3.6, 3.1, 3.9), lh: 130, col: P.ink, al: 'l', nw: false, name: 'Card text' }, G(x + 4, 0, cw - 8, 0, 0, k));
      column([body], y + v(f, 11, 11, 13), y + ch - 4, 0);
      els.push(body);
    });
    var by = ty + (sq ? ch : ch * 2 + 3) + v(f, 3, 3, 5);
    els.push(pAr(P, T.ar, { al: 'l', col: P.tl }, G(X, by, W, 0, 0, k)));
    els.push(pBtn(P, T.cta, { fill: P.nv, col: P.on }, v(f, G(X, 109, 44, 8), G(X, 87, 40, 7.2), G(20, 150, 60, 9.2))));
    els.push(pContact(P, T.contact, { al: 'l', col: P.ink }, v(f, G(54, 109.4, 40, 0, 0, .85), G(50, 87.6, 44, 0, 0, .8), G(8, 163, 84, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    els.push(pNote(P, T.note, { al: 'l', col: P.lsub }, v(f, G(54, 114.6, 40, 0, 0, .85), G(50, 92.8, 44, 0, 0, .8), G(8, 169.5, 84, 0, 0, 1.05))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgLight(P, 'globe', 8), els: els, slots: [] };
  };

  // PC14 ── PEOPLE & PLACE: a consultant in an arch (team intro), or the office card (contact): rows with icons
  FAM.pcpeople = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], sq = f === 'square', office = !!O.office;
    var a = v(f, G(8, 16, 40, 52), G(6, 14, 40, 54), G(22, 18, 56, 72));
    els.push(shp('al', 'arch', { fill: 'transparent', bc: P.ic, bw: 3, name: 'Arch line' }, G(a.x - 1.8, a.y - 1.8, a.w + 3.6, a.h + 1.8)));
    if (office) {
      els.push(shp('photo', 'arch', { fill: mix(P.nv, P.tlD, .25), name: 'Office' }, a));
      els.push(art('lm', 'lm:burj-khalifa', { col: mix(P.nv, P.tlD, .55), name: 'Landmark' }, G(a.x + a.w * .08, a.y + a.h - a.w * .84, a.w * .84, a.w * .84)));
      els.push(ico('pinb', 'pin', { col: P.ic, shd: 40, name: 'Pin' }, G(a.x + a.w * .38, a.y + a.h * .16, a.w * .24, a.w * .24)));
    } else {
      els.push(shp('photo', 'arch', { fill: mix(P.nv, P.tlD, .3), txt: 'CONSULTANT\nPHOTO', fn: P.fh, fw: 800, fs: 2.4, ls: 16, col: A(P.on, 'B3'), ph: true, name: 'Consultant photo (tap → Put a photo here)' }, a));
      els.push(shp('lmx', 'rect', { fill: 'transparent', name: 'Spacer' }, G(0, 0, 1, 1)));
      els[els.length - 1].g.hd = true;
      els.push(ico('pinb', 'award', { col: P.ic, name: 'Badge' }, G(a.x + a.w - a.w * .2, a.y + a.h - a.w * .26, a.w * .3, a.w * .3)));
    }
    els.push(pLogo(P, { al: 'l' }, v(f, G(52, 6, 44, 0, 0, .9), G(52, 5, 44, 0, 0, .85), G(20, 6, 60, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    var X = v(f, 52, 52, 10), W = v(f, 42, 42, 80), al = f === 'story' ? 'c' : 'l';
    var top = [pKick(P, T.kick, { al: al, ls: 18 }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: al, fs: v(f, 6.2, 5.6, 8) }, G(X, 0, W, 0, 0, k)), pSub(P, 'sub', T.sub, { al: al, col: P.tlL }, G(X, 0, W, 0, 0, k))];
    column(top, v(f, 16, 14, 94), v(f, 68, 68, 120), 1.6 * k);
    els = els.concat(top);
    var rx = v(f, 8, 6, 14), rw = v(f, 84, 88, 72);
    var rows = rowsOf(P, T.rows, rx, rw, k, { fs: 2.8 });
    column(rows, v(f, 74, 72, 124), v(f, 100, 84, 150), 1.6 * k, true);
    els = els.concat(rows);
    rowIcons(els, rows, rx, k, P.ic, null);
    var icn = T.icons || [];
    rows.forEach(function (r, i) { els[els.length - rows.length + i].ic = icn[i] || 'check'; });
    els.push(pBtn(P, T.cta, {}, v(f, G(8, 106, 46, 8.2), G(52, 86, 42, 7.2), G(20, 156, 60, 9.2))));
    els.push(pAr(P, T.ar, { al: 'l' }, v(f, G(57, 106.3, 37, 0, 0, k), G(6, 86, 44, 0, 0, k), G(10, 168.5, 80, 0, 0, k))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgDark(P, 'globe', 6), els: els, slots: [] };
  };

  // PC15 ── OFFER: the consultancy's own offer (free consultation, scholarships, family visas, language) -- an accent
  //         badge, the headline, points, five destination flags along the bottom
  FAM.pcoffer = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], sq = f === 'square';
    els.push(glow('g1', A(P.tlD, '4D'), v(f, G(20, -20, 100, 80), G(40, -20, 80, 70), G(0, -10, 120, 90))));
    els.push(ico('big', O.icon || 'grad', { col: A(P.on, '12'), name: 'Big icon' }, v(f, G(46, 28, 64, 64), G(58, 22, 48, 48), G(30, 40, 80, 80))));
    els.push(shp('badge', 'burst', { fill: P.gd, txt: T.badge || 'FREE', fn: 'bebas', fw: 400, fs: 6.4, lh: 90, col: P.onGd, shd: 30, name: 'Badge' }, v(f, G(72, 12, 20, 20, 12), G(76, 8, 17, 17, 12), G(70, 16, 22, 22, 12))));
    els.push(pLogo(P, { al: 'l' }, v(f, G(7, 5, 60, 0, 0, .95), G(6, 4.5, 60, 0, 0, .85), G(7, 7, 60, 0, 0, 1.1))));
    var X = v(f, 7, 6, 8), W = v(f, 62, 60, 84);
    var top = [pKick(P, T.kick, { al: 'l' }, G(X, 0, W, 0, 0, k)), pH1(P, T.h1, { al: 'l', fs: 9.4 }, G(X, 0, W, 0, 0, k)), pH2(P, T.h2, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    var rows = rowsOf(P, T.rows, X, W, k);
    var arl = pAr(P, T.ar, { al: 'l' }, G(X, 0, W, 0, 0, k));
    column(top.concat(rows, [arl]), v(f, 22, 16, 44), v(f, 88, 72, 128), 1.9 * k);
    els = els.concat(top, rows, [arl]);
    rowIcons(els, rows, X, k, P.ic, 'check');
    var fl = T.flags || ['gb', 'ca', 'au', 'de', 'ie'], fs = v(f, 6.4, 5.4, 7.6), fx = v(f, 94 - fs * 5.6, 94 - fs * 5.6, 50 - fs * 2.8), fy = v(f, 93, 77, 135);
    fl.forEach(function (cc, i) { els.push(art('f' + i, 'flag:' + cc, { sh: 'circle', bw: 3, bc: '#FFFFFF', shd: 20, name: 'Flag' }, G(fx + i * fs * 1.12, fy, fs, fs))); });
    els.push(pBtn(P, T.cta, {}, v(f, G(X, 92, 44, 8.4), G(X, 76, 40, 7.4), G(20, 146, 60, 9.4))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(X, 104, 86, 0), G(X, 87, 86, 0, 0, .9), G(8, 159, 84, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    els.push(pNote(P, T.note, { al: 'l' }, v(f, G(X, 110, 86, 0), G(X, 92.4, 86, 0, 0, .9), G(8, 166, 84, 0, 0, 1.05))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgDark(P), els: els, slots: [] };
  };

  // PC16 ── LANGUAGE TEST: IELTS / PTE / a language course -- the test's name huge, the four skills as chips, a
  //         target-score ring
  FAM.pcexam = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [], sq = f === 'square';
    els.push(glow('g1', A(P.gdD, '2E'), G(-20, -20, 140, 90)));
    els.push(pLogo(P, { al: 'l' }, v(f, G(7, 5, 60, 0, 0, .95), G(6, 4.5, 60, 0, 0, .85), G(7, 7, 60, 0, 0, 1.1))));
    var r = v(f, G(64, 14, 28, 28), G(70, 10, 24, 24), G(62, 16, 30, 30));
    els.push(shp('ringb', 'ring', { fill: 'transparent', bc: A(P.on, '26'), bw: 10, name: 'Score ring' }, r));
    els.push(shp('ringg', 'ring', { fill: 'transparent', bc: P.ic, bw: 10, bs: 'dashed', name: 'Score ring (accent)' }, r));
    els.push(tx('score', T.score || '‹7.5›', { fn: 'bebas', fw: 400, fs: 9, lh: 90, col: P.on, name: 'Target score (edit)' }, G(r.x, r.y + r.h * .24, r.w, 0, 0, r.w / 28)));
    els.push(tx('scl', T.scl || 'TARGET', { fn: P.fb, fw: 700, fs: 1.6, ls: 20, col: P.hl, name: 'Score label' }, G(r.x, r.y + r.h * .66, r.w, 0, 0, r.w / 28)));
    if (O.flag) els.push(art('flag', 'flag:' + O.flag, { sh: 'circle', bw: 4, bc: '#FFFFFF', shd: 25, name: 'Flag' }, G(r.x - 2, r.y - 2, r.w * .32, r.w * .32)));
    var X = v(f, 7, 6, 8), W = v(f, 56, 62, 84);
    var top = [pKick(P, T.kick, { al: 'l' }, G(X, 0, W, 0, 0, k)), tx('word', T.word, { fn: P.fh, fw: 900, fs: 17, lh: 92, ls: -2, col: P.on, al: 'l', name: 'Test name' }, G(X, 0, W, 0, 0, k)), pH2(P, T.h2, { al: 'l' }, G(X, 0, W, 0, 0, k))];
    var ty = column(top, v(f, 16, 14, 50), v(f, 56, 46, 92), 1.6 * k);
    var sk = T.skills || ['Listening', 'Reading', 'Writing', 'Speaking'], cx = X, cy = ty + v(f, 4, 3, 6), cw = v(f, 41.5, 42, 41), chh = v(f, 7, 6.2, 8);
    sk.forEach(function (s, i) {
      var x = cx + (i % 2) * (cw + 3), y = cy + Math.floor(i / 2) * (chh + 2.6);
      els.push(shp('sk' + i, 'pill', { fill: A(P.on, '14'), bc: A(P.tlD === P.tl ? P.tl : P.ic, 'AA'), bw: 1.5, txt: s, fn: P.fh, fw: 700, fs: 2.6, col: P.on, name: 'Skill' }, G(x, y, cw, chh)));
    });
    var by = cy + 2 * chh + 2.6 + v(f, 5, 4, 8);
    var rows = rowsOf(P, T.rows, X, v(f, 86, 88, 84), k);
    column(rows, by, v(f, 100, 82, 140), 1.5 * k, true);
    els = els.concat(top, rows);
    rowIcons(els, rows, X, k, P.ic, 'check');
    els.push(pAr(P, T.ar, { al: 'l' }, v(f, G(54, 107, 40, 0, 0, k), G(50, 88, 44, 0, 0, k), G(8, 144, 84, 0, 0, k))));
    if (f === 'story') els[els.length - 1].al = 'c';
    els.push(pBtn(P, T.cta, {}, v(f, G(X, 107, 44, 8.2), G(X, 87, 40, 7.2), G(20, 152, 60, 9.2))));
    els.push(pContact(P, T.contact, { al: 'l' }, v(f, G(X, 118, 86, 0, 0, .9), G(50, 94.5, 44, 0, 0, .75), G(8, 165, 84, 0, 0, 1.1))));
    if (f === 'story') els[els.length - 1].al = 'c';
    return { bg: bgDark(P, 'grid', 4), els: els, slots: [] };
  };

  // PC17 ── GREETING: a consultancy's greeting (Eid, National Day, New Year) -- night-navy, a motif on
  //         a ringed medallion, the greeting in serif and in Arabic, "from all of us at …"
  FAM.pcgreet = function (f, T, P, O) {
    var H = FH[f], k = v(f, 1, .9, 1.12), els = [];
    els.push(glow('g1', A(P.gdD, '3D'), v(f, G(0, 0, 100, 80), G(10, -10, 80, 70), G(-10, 10, 120, 100))));
    var m = v(f, G(34, 14, 32, 32), G(38, 7, 24, 24), G(30, 20, 40, 40));
    els.push(shp('med', 'circle', { fill: P.nv2, fill2: P.dk, gang: 180, bc: P.ic, bw: 4, shd: 40, name: 'Medallion' }, m));
    els.push(shp('medr', 'ring', { fill: 'transparent', bc: A(P.orn, '80'), bw: 1.5, name: 'Medallion ring' }, G(m.x - 2.6, m.y - 2.6, m.w + 5.2, m.w + 5.2)));
    els.push(ico('mot', O.icon || 'moon', { col: P.orn, name: 'Motif' }, G(m.x + m.w * .22, m.y + m.w * .22, m.w * .56, m.w * .56)));
    [[12, v(f, 20, 12, 26), 4], [84, v(f, 34, 22, 44), 3.4], [18, v(f, 56, 40, 80), 2.6], [80, v(f, 10, 6, 14), 2.4]].forEach(function (q, i) { els.push(spark('sp' + i, P.orn, G(q[0], q[1], q[2], q[2]), 80)); });
    if (O.hang) [[14, 0, 1], [80, 0, .8]].forEach(function (q, i) {
      var hl = v(f, 12 + i * 5, 8 + i * 4, 18 + i * 8), sz = v(f, 7, 5.6, 9) * q[2];
      els.push(shp('str' + i, 'rect', { fill: P.orn, op: 70, name: 'String' }, G(q[0] + sz / 2 - .15, 0, .3, hl)));
      els.push(ico('lan' + i, 'lantern', { col: P.orn, name: 'Lantern' }, G(q[0], hl - 1, sz, sz * 1.3)));
    });
    var top = [tx('h1', T.h1, { fn: 'playfair', fw: 700, fs: v(f, 10, 8.4, 11), lh: 105, col: P.on, name: 'Greeting' }, G(6, 0, 88, 0, 0, k)),
      tx('ar', T.ar, { fn: 'cairo', fw: 700, fs: 6, lh: 140, col: P.hl, name: 'Arabic greeting' }, G(6, 0, 88, 0, 0, k)),
      tx('rule', '— ✦ —', { fn: P.fb, fw: 600, fs: 2.4, col: P.ic, name: 'Ornament' }, G(6, 0, 88, 0, 0, k)),
      pSub(P, 'sub', T.sub, { al: 'c' }, G(8, 0, 84, 0, 0, k))];
    column(top, m.y + m.h + v(f, 5, 4, 8), v(f, 104, 84, 150), 2 * k);
    els = els.concat(top);
    els.push(pLogo(P, { al: 'c' }, v(f, G(20, 108, 60, 0, 0, 1.05), G(20, 88, 60, 0, 0, .95), G(20, 156, 60, 0, 0, 1.2))));
    els.push(pContact(P, T.contact, { al: 'c', col: P.sub }, v(f, G(8, 117.5, 84, 0, 0, .9), G(8, 94.5, 84, 0, 0, .8), G(8, 167, 84, 0, 0, 1.1))));
    return { bg: bgDark(P, O.pat || 'sparkle', 30), els: els, slots: [] };
  };

  // ── the Planet Careers templates (appended after every KBB template, so no KBB product rotation moves) ──
  var PCCATS = [['pc-study', 'Study abroad'], ['pc-visa', 'Visas & PR'], ['pc-prep', 'Language & tests'], ['pc-serv', 'Services'], ['pc-trust', 'Stories & trust'], ['pc-event', 'Events & deadlines'], ['pc-learn', 'Tips & carousels'], ['pc-greet', 'Greetings']];
  var NOTE = 'Rules change — we confirm the current official requirements for your case.', CT = '{CONTACT}';
  function pc(cat, id, name, fam, T, O, tags, cty) { L.push({ id: id, name: name, cat: cat, fam: fam, co: 'pc', P: null, T: Object.assign({ contact: CT, note: NOTE, cta: '{CTA}' }, T), O: O || {}, tags: 'planet careers consultancy ' + (tags || ''), pp: [], cty: cty || '' }); }
  var STUDY_ROWS = ['Admission & university shortlist', 'Visa file preparation', 'Pre-departure & arrival support'];
  var SH = { kick: 'Study in', h1: '{N}', h2: 'Your degree abroad starts here', rows: STUDY_ROWS, ar: 'ادرس في {A}' };
  // study visa, by country -- four looks, each on several countries (the picker swaps any of them)
  pc('pc-study', 'pc-study-gb', 'Study in the UK · Hero', 'pchero', SH, {}, 'study visa student university hero', 'gb');
  pc('pc-study', 'pc-study-ca', 'Study in Canada · Hero', 'pchero', Object.assign({}, SH, { h2: 'Colleges & universities, one plan' }), {}, 'study visa student college hero', 'ca');
  pc('pc-study', 'pc-study-au', 'Study in Australia · Hero', 'pchero', Object.assign({}, SH, { h2: 'Study where the future is bright' }), {}, 'study visa student hero', 'au');
  pc('pc-study', 'pc-study-de', 'Study in Germany · Hero', 'pchero', Object.assign({}, SH, { h2: 'Public universities · English-taught options', rows: ['Programme & university shortlist', 'Blocked account & visa file', 'Accommodation guidance'] }), {}, 'study visa student europe hero', 'de');
  pc('pc-study', 'pc-study-ie', 'Study in Ireland · Hero', 'pchero', Object.assign({}, SH, { h2: 'English-speaking Europe' }), {}, 'study visa student europe hero', 'ie');
  var SP = { kick: 'Your study journey', h1: 'Next stop:\n{N}', sub: 'Admission, visa and travel — planned with you', ar: 'وجهتك القادمة: {A}', purpose: 'Study' };
  pc('pc-study', 'pc-study-us', 'Study in the USA · Boarding pass', 'pcpass', SP, {}, 'study visa student boarding pass ticket travel', 'us');
  pc('pc-study', 'pc-study-nz', 'Study in New Zealand · Boarding pass', 'pcpass', SP, {}, 'study visa student boarding pass ticket', 'nz');
  pc('pc-study', 'pc-study-fr', 'Study in France · Boarding pass', 'pcpass', Object.assign({}, SP, { sub: 'Grandes écoles, universities and business schools' }), {}, 'study visa student boarding pass europe', 'fr');
  pc('pc-study', 'pc-study-mt', 'Study in Malta · Boarding pass', 'pcpass', Object.assign({}, SP, { sub: 'English-taught courses in the Mediterranean' }), {}, 'study visa student boarding pass europe', 'mt');
  var SA = { kick: 'Study abroad', h1: 'Study in\n{N}', sub: 'Bachelor’s · Master’s · Foundation', rows: ['University shortlist', 'Application & visa file', 'Arrival support'], ar: 'ادرس في {A}' };
  pc('pc-study', 'pc-study-it', 'Study in Italy · Postcard', 'pcarch', SA, {}, 'study visa student postcard elegant europe', 'it');
  pc('pc-study', 'pc-study-es', 'Study in Spain · Postcard', 'pcarch', SA, {}, 'study visa student postcard elegant europe', 'es');
  pc('pc-study', 'pc-study-pt', 'Study in Portugal · Postcard', 'pcarch', SA, {}, 'study visa student postcard elegant europe', 'pt');
  pc('pc-study', 'pc-study-nl', 'Study in the Netherlands · Postcard', 'pcarch', Object.assign({}, SA, { h1: 'Study in\n{D}' }), {}, 'study visa student postcard elegant europe', 'nl');
  var SM = { kick: 'Study in Europe', h1: '{N}', h2: 'Quality degrees · central Europe', rows: ['Course & university match', 'Admission & visa file', 'Accommodation guidance'], ar: 'ادرس في {A}' };
  pc('pc-study', 'pc-study-pl', 'Study in Poland · Map', 'pcmap', SM, {}, 'study visa student map europe', 'pl');
  pc('pc-study', 'pc-study-hu', 'Study in Hungary · Map', 'pcmap', SM, {}, 'study visa student map europe medicine', 'hu');
  pc('pc-study', 'pc-study-se', 'Study in Sweden · Map', 'pcmap', Object.assign({}, SM, { h2: 'Innovation-led universities' }), {}, 'study visa student map europe nordic', 'se');
  // intakes
  var IT = { kick: 'UK · Canada · Australia · Europe', h1: 'Intake now open', sub: 'Seats fill early — start your application today', ar: 'باب القبول مفتوح', cta: 'Apply now' };
  pc('pc-study', 'pc-intake-sep', 'Intake Open · September', 'pcintake', Object.assign({ mon: 'SEPTEMBER' }, IT), {}, 'intake september fall autumn applications open');
  pc('pc-study', 'pc-intake-jan', 'Intake Open · January', 'pcintake', Object.assign({ mon: 'JANUARY' }, IT), {}, 'intake january winter spring applications open');
  pc('pc-study', 'pc-intake-may', 'Intake Open · May', 'pcintake', Object.assign({ mon: 'MAY' }, IT, { sub: 'Selected universities — ask which ones' }), {}, 'intake may summer applications open');
  // scholarships
  pc('pc-study', 'pc-scholar', 'Scholarships', 'pcoffer', { badge: 'UP TO\n‹XX›%', kick: 'Scholarships', h1: 'Study abroad\nfor less', h2: 'Merit scholarships at partner universities', rows: ['We find the ones you qualify for', 'Help with essays & documents', 'Deadlines tracked for you'], ar: 'منح دراسية', cta: 'Check my eligibility' }, { icon: 'grad' }, 'scholarship funding merit discount tuition');
  pc('pc-study', 'pc-scholar-de', 'Scholarships · Country', 'pcmap', { kick: 'Scholarships', h1: '{N}', h2: 'Funding options for international students', rows: ['Scholarship search for your profile', 'Application & motivation letter', 'Deadlines tracked for you'], ar: 'منح دراسية في {A}', cta: 'Check my eligibility' }, {}, 'scholarship funding country map', 'de');
  // work permit, PR
  pc('pc-visa', 'pc-work', 'Work Permit · Country', 'pchero', { kick: 'Work permit', h1: '{N}', h2: 'Work in Europe — the legal way', rows: ['Profile & job-route assessment', 'Documents & application file', 'Embassy appointment guidance'], ar: 'تصريح عمل في {A}' }, {}, 'work permit job employment visa europe', 'pl');
  pc('pc-visa', 'pc-work-steps', 'Work Permit · Process', 'pcsteps', { kick: 'Work permit', h1: 'Work in {N}', steps: [['Free assessment', 'Your profile, your options'], ['Job & employer route', 'We explain each step'], ['Documents & file', 'Prepared and checked'], ['Visa & travel', 'Appointment to arrival']], ar: 'تصريح عمل في {A}' }, {}, 'work permit process steps job europe', 'cz');
  pc('pc-visa', 'pc-pr-ca', 'Permanent Residency · Pathway', 'pcsteps', { kick: 'Permanent residency', h1: 'PR in {N}', steps: [['Eligibility check', 'Points, age, education, work'], ['Language & credentials', 'Tests and assessments'], ['Profile & application', 'Filed with care'], ['Your new home', 'Landing & settling in']], ar: 'الإقامة الدائمة في {A}' }, {}, 'pr permanent residency immigration pathway points express entry', 'ca');
  pc('pc-visa', 'pc-pr-au', 'Permanent Residency · Hero', 'pchero', { kick: 'Permanent residency', h1: '{N}', h2: 'Skilled migration, planned properly', rows: ['Skills & points assessment', 'Occupation & route advice', 'Complete application support'], ar: 'الإقامة الدائمة في {A}' }, {}, 'pr permanent residency skilled migration immigration', 'au');
  // visit visas, family
  var VP = { kick: 'Visit visa', h1: 'Holiday in\n{N}', sub: 'Tourist & family-visit visas — files done right', ar: 'تأشيرة زيارة إلى {A}', purpose: 'Visit', pass: 'TRAVEL PASS', gate: 'Dates ‹edit›' };
  pc('pc-visa', 'pc-visit-fr', 'Visit Visa · Boarding pass', 'pcpass', VP, {}, 'visit tourist visa schengen holiday travel', 'fr');
  pc('pc-visa', 'pc-visit-tr', 'Tourist Visa · Boarding pass', 'pcpass', Object.assign({}, VP, { kick: 'Tourist visa' }), {}, 'visit tourist visa holiday travel', 'tr');
  pc('pc-visa', 'pc-family', 'Family & Spouse Visa', 'pcoffer', { badge: 'FAMILY', kick: 'Family & spouse visas', h1: 'Bring your\nfamily together', h2: 'Spouse, children and parents', rows: ['Eligibility check for your case', 'Relationship & sponsor documents', 'Application and follow-up'], ar: 'تأشيرة عائلية ولمّ شمل', cta: 'Talk to a consultant' }, { icon: 'people' }, 'family spouse dependant reunion visa');
  // language & tests
  pc('pc-prep', 'pc-ielts', 'IELTS Preparation', 'pcexam', { kick: 'Test preparation', word: 'IELTS', h2: 'Academic & General Training', rows: ['Mock tests with feedback', 'Small groups · flexible timings'], ar: 'التحضير لاختبار الآيلتس', cta: 'Book a free level test' }, {}, 'ielts english test band preparation course');
  pc('pc-prep', 'pc-pte', 'PTE Preparation', 'pcexam', { kick: 'Test preparation', word: 'PTE', h2: 'PTE Academic — computer-based', score: '‹65›', rows: ['Practice on real-style tasks', 'Tips for every section'], ar: 'التحضير لاختبار PTE', cta: 'Book a free level test' }, {}, 'pte english test score preparation course');
  pc('pc-prep', 'pc-german', 'German Language Course', 'pcexam', { kick: 'Language course', word: 'Deutsch', h2: 'German A1 → B2', score: 'B2', scl: 'GOAL', skills: ['Hören', 'Lesen', 'Schreiben', 'Sprechen'], rows: ['For study & work in Germany', 'Evening & weekend batches'], ar: 'دورة اللغة الألمانية', cta: 'Join the next batch' }, { flag: 'de' }, 'german language course deutsch a1 b2 goethe');
  // services
  pc('pc-serv', 'pc-consult', 'Free Consultation', 'pcoffer', { badge: 'FREE', kick: 'Study · work · settle abroad', h1: 'Free\nconsultation', h2: 'One-to-one with a senior consultant', rows: ['Your best-fit countries & routes', 'Honest eligibility check', 'A clear plan and timeline'], ar: 'احجز استشارتك المجانية', cta: 'Book my free slot' }, { icon: 'chat' }, 'free consultation booking appointment advice');
  pc('pc-serv', 'pc-how', 'How It Works', 'pcsteps', { kick: 'How it works', h1: 'From dream\nto departure', steps: [['Book a free call', 'Tell us your goals'], ['Get your plan', 'Countries, courses, costs'], ['We file it with you', 'Documents & applications'], ['Fly with confidence', 'Pre-departure briefing']], ar: 'كيف نعمل' }, { light: true }, 'how it works process steps consultation');
  pc('pc-serv', 'pc-docs-study', 'Documents Checklist · Study', 'pccheck', { kick: 'Documents checklist', h1: 'Study visa\nchecklist', sub: 'Your exact list depends on country & course', items: ['Valid passport', 'Academic transcripts & certificates', 'English test result (if needed)', 'Offer / admission letter', 'Proof of funds', 'Passport photos'], ar: 'قائمة المستندات' }, {}, 'documents checklist study visa requirements papers');
  pc('pc-serv', 'pc-docs-visit', 'Documents Checklist · Visit', 'pccheck', { kick: 'Documents checklist', h1: 'Visit visa\nchecklist', sub: 'We confirm the list for your destination', items: ['Passport & copies', 'Bank statements', 'Employment / NOC letter', 'Travel insurance', 'Flight & hotel plan', 'Photos'], ar: 'قائمة مستندات تأشيرة الزيارة' }, {}, 'documents checklist visit visa tourist papers');
  pc('pc-serv', 'pc-why', 'Why Choose Us', 'pccheck', { kick: 'Why choose us', h1: 'Advice you\ncan trust', sub: 'Study, work and visit visas under one roof', items: ['Honest assessment — no false promises', 'One consultant from start to finish', 'Files checked twice', 'Clear fees, agreed up front', 'Support after you land'], ar: 'لماذا نحن؟', cta: 'Talk to us' }, { icon: 'shield' }, 'why choose us trust benefits reasons');
  pc('pc-serv', 'pc-team', 'Meet Your Consultant', 'pcpeople', { kick: 'Meet the team', h1: '‹Consultant\nname›', sub: 'Senior study & visa consultant', rows: ['‹X› years guiding students', 'Speaks English · Arabic · ‹edit›', 'Specialist: UK, Canada, Europe'], icons: ['award', 'lang', 'globe'], ar: 'تعرّف على فريقنا', cta: 'Book a session' }, {}, 'team consultant staff meet advisor');
  pc('pc-serv', 'pc-office', 'Visit Our Office', 'pcpeople', { kick: 'Visit us', h1: 'Our office', sub: '{ADDR}', rows: ['{PHONE}', 'WhatsApp {WA}', '{MAIL} · {WEB}'], icons: ['phone', 'chat', 'mail'], ar: 'زورونا في مكتبنا', cta: 'Get directions' }, { office: true }, 'office contact address phone whatsapp email location');
  // stories & trust
  pc('pc-trust', 'pc-approved-ca', 'Visa Approved · Story', 'pcstory', { kick: 'Success story', h1: 'Visa approved!', quote: '“Paste your client’s own words here —\nshort, real and with their permission.”', who: '— ‹Client name› · ‹course› · {N}', ar: 'تمت الموافقة على التأشيرة' }, {}, 'visa approved success story client result', 'ca');
  pc('pc-trust', 'pc-approved-gb', 'Visa Approved · Student', 'pcstory', { kick: 'Another one flies', h1: 'Congratulations!', quote: '“A real quote from your student goes here,\nwith their permission.”', who: '— ‹Student name› · ‹university› · {N}', ar: 'مبروك القبول والتأشيرة', stamp: 'STUDY\nVISA ✓' }, {}, 'visa approved success student congratulations', 'gb');
  pc('pc-trust', 'pc-review', 'Client Review', 'pcquote', { kick: 'What clients say', quote: '“Replace with a real review from a client —\nspecific, honest and short.”', who: '‹Client name›', what: '‹Study visa · country›', ar: 'آراء عملائنا' }, {}, 'testimonial review client feedback rating');
  // events & deadlines
  pc('pc-event', 'pc-webinar', 'Free Webinar', 'pcevent', { chip: 'Free webinar', h1: 'Study in Europe:\nwhere to start', sub: 'Countries, costs, timelines — live Q&A', whereL: 'WHERE', where: 'Online · Zoom', ar: 'ندوة مجانية عبر الإنترنت', cta: 'Register free' }, { icon: 'mic' }, 'webinar seminar online live event zoom');
  pc('pc-event', 'pc-expo', 'Study Abroad Expo', 'pcevent', { chip: 'Study abroad expo', h1: 'Meet universities\nface to face', sub: 'On-the-spot profile checks · free entry', where: '‹Venue, city›', speaker: 'Planet Careers stand', role: 'Stand ‹no.› · free assessment', ar: 'معرض الدراسة في الخارج', cta: 'Reserve your visit' }, { icon: 'cal' }, 'expo fair event exhibition universities seminar');
  pc('pc-event', 'pc-deadline', 'Application Deadline', 'pccount', { kick: 'Application deadline', h1: 'Last chance\nto apply', sub: 'For the coming intake — seats are limited', ar: 'آخر موعد للتقديم', cta: 'Apply before it closes' }, {}, 'deadline countdown last chance closing urgent');
  // tips & carousels
  pc('pc-learn', 'pc-top5', 'Top 5 Destinations · Carousel', 'pctop', { kick: 'Carousel', h1: 'Top 5 study\ndestinations', sub: 'Swipe for the why behind each one', list: [['gb', 'World-famous universities'], ['ca', 'Colleges & universities'], ['au', 'Sunny campuses'], ['de', 'Strong public universities'], ['ie', 'English-speaking Europe']], ar: 'أفضل ٥ وجهات للدراسة' }, {}, 'top 5 carousel list destinations countries cover');
  pc('pc-learn', 'pc-myth', 'Myth vs Fact', 'pctwo', { kick: 'Myth vs fact', h1: 'Only top grades\ncan study abroad?', a: 'You need perfect grades\nto study abroad.', b: 'Many universities look at your whole\nprofile — we help you find the right fit.', ar: 'خرافة أم حقيقة؟', cta: 'Check my options' }, {}, 'myth fact tips education misconception');
  pc('pc-learn', 'pc-faq', 'FAQ · Work While Studying', 'pctwo', { kick: 'FAQ', h1: 'Can I work while\nI study?', a: 'Can I work part-time\nduring my studies?', b: 'It depends on the country and your visa,\nand the rules change — we check the\ncurrent official rules for your case.', ar: 'أسئلة شائعة', cta: 'Ask a consultant' }, { faq: true }, 'faq question answer work while studying part time');
  // greetings
  pc('pc-greet', 'pc-eid-fitr', 'Eid al-Fitr Greeting', 'pcgreet', { h1: 'Eid Mubarak', ar: 'عيد فطر مبارك', sub: 'From all of us at {CO}' }, { icon: 'moon', hang: true }, 'eid fitr mubarak greeting ramadan');
  pc('pc-greet', 'pc-eid-adha', 'Eid al-Adha Greeting', 'pcgreet', { h1: 'Eid al-Adha\nMubarak', ar: 'عيد أضحى مبارك', sub: 'Wishing you and your family joy and peace' }, { icon: 'mosque', pat: 'lattice' }, 'eid adha mubarak greeting');
  pc('pc-greet', 'pc-national-day', 'UAE National Day Greeting', 'pcgreet', { h1: 'Happy\nNational Day', ar: 'كل عام والإمارات بخير', sub: 'Proud to help our community go further' }, { icon: 'uae' }, 'uae national day greeting 2 december');
  pc('pc-greet', 'pc-new-year', 'New Year Greeting', 'pcgreet', { h1: 'Happy\nNew Year', ar: 'سنة جديدة سعيدة', sub: 'New year, new destinations — let’s plan yours' }, { icon: 'globe', pat: 'routes' }, 'new year greeting january wishes');
