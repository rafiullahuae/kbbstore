/*
 * Lane CT (contrast) — before/after pixel diff, with every changed pixel
 * attributed to one approved colour change or reported as an accident.
 *
 *   node tools/ctc-pixdiff.cjs <beforeDir> <afterDir> [outDir]
 *
 * A changed pixel is ATTRIBUTED to an approved pair (old -> new) when its
 * change is that pair's change scaled by one coverage factor:
 *
 *     after - before = a * (new - old),   0 < a <= 1
 *
 * which is exactly what an anti-aliased glyph edge, a translucent overlay or a
 * white label drawn over a recoloured button produces. Anything else is
 * UNATTRIBUTED and counted, with its bounding boxes, so it can be looked at.
 *
 * Shots of different heights (the phone footer grows by its tap areas) are
 * compared TOP-aligned above the first footer link and BOTTOM-aligned below
 * the last one; the band between is the geometry change, reported as such.
 * Runs in Chromium's canvas: no image library is added to the project.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const [BEFORE, AFTER, OUT = AFTER + '/../diff'] = process.argv.slice(2);
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const PAIRS = [
  ['muted', '#8C828A', '#756C74'],
  ['muted (showcase)', '#9A8D94', '#756C74'],
  ['muted (PDP was-price, was .75 opacity)', '#A89DA4', '#756C74'],
  ['was-price', '#B3AAB0', '#796C75'],
  ['was-price (showcase)', '#A2959B', '#796C75'],
  ['pink', '#E0567B', '#C6395F'],
  ['sale badge', '#E23B57', '#D22B47'],
  ['new badge', '#1F9D55', '#1A7F45'],
  ['savings', '#2E9E6B', '#1F7A50'],
  ['footer WhatsApp', '#2E9E6B', '#27865B'],
  ['footer WhatsApp label', '#CDBFC6', '#FFFFFF'],
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const axeB = JSON.parse(fs.readFileSync(BEFORE + '/axe.json', 'utf8'));
  const axeA = JSON.parse(fs.readFileSync(AFTER + '/axe.json', 'utf8'));
  const browser = await chromium.launch({ executablePath: EXE });
  const page = await browser.newPage();
  const results = {};

  for (const f of fs.readdirSync(AFTER).filter((n) => n.endsWith('.png')).sort()) {
    const name = f.replace('.png', '');
    const b64 = (p) => 'data:image/png;base64,' + fs.readFileSync(p).toString('base64');
    const fb = axeB[name] ? axeB[name].geo.footerLinks : [];
    const fa = axeA[name] ? axeA[name].geo.footerLinks : [];
    const r = await page.evaluate(async ({ a, b, pairs, fb, fa }) => {
      const load = (src) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = src; });
      const [ib, ia] = await Promise.all([load(b), load(a)]);
      const px = (img) => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
        const x = c.getContext('2d'); x.drawImage(img, 0, 0); return x.getImageData(0, 0, img.width, img.height); };
      const B = px(ib), A = px(ia);
      const W = Math.min(B.width, A.width);
      const hex = (h) => [1, 3, 5].map((i) => parseInt(h.substr(i, 2), 16));
      const P = pairs.map(([n, o, w]) => { const O = hex(o), N = hex(w); return { n, o: O, w: N, v: [N[0] - O[0], N[1] - O[1], N[2] - O[2]] }; });
      const dh = A.height - B.height;
      // Rows [0, cut) top-aligned; rows from cut2 (in AFTER) bottom-aligned.
      let cut = Math.min(A.height, B.height), cut2 = A.height;
      if (dh !== 0 && fb.length && fa.length) { cut = Math.floor(Math.min(fb[0].top, fa[0].top)); cut2 = Math.ceil(fa[fa.length - 1].top + fa[fa.length - 1].h) + 80; }
      const counts = {}, boxes = [];
      let changed = 0, unattr = 0, tiny = 0;
      const mark = (x, y) => {
        for (const bx of boxes) if (x >= bx[0] - 12 && x <= bx[2] + 12 && y >= bx[1] - 12 && y <= bx[3] + 12) {
          bx[0] = Math.min(bx[0], x); bx[1] = Math.min(bx[1], y); bx[2] = Math.max(bx[2], x); bx[3] = Math.max(bx[3], y); bx[4]++; return; }
        if (boxes.length < 200) boxes.push([x, y, x, y, 1]);
      };
      const cmp = (ya, yb) => {
        for (let x = 0; x < W; x++) {
          const ia2 = (ya * A.width + x) * 4, ib2 = (yb * B.width + x) * 4;
          const d = [A.data[ia2] - B.data[ib2], A.data[ia2 + 1] - B.data[ib2 + 1], A.data[ia2 + 2] - B.data[ib2 + 2]];
          if (!d[0] && !d[1] && !d[2]) continue;
          changed++;
          // SOLID: the before pixel IS the old colour and the after pixel IS
          // the new one. EDGE: an anti-aliased or translucent pixel whose change
          // is that pair's change scaled by one coverage factor; when several
          // pairs fit (all these colours darken in nearly the same direction)
          // the one with the smallest residual is named.
          let hit = null, best = 1e9;
          const bp = [B.data[ib2], B.data[ib2 + 1], B.data[ib2 + 2]], ap = [A.data[ia2], A.data[ia2 + 1], A.data[ia2 + 2]];
          for (const p of P) {
            if (Math.max(...[0, 1, 2].map((c) => Math.abs(bp[c] - p.o[c]))) <= 4 && Math.max(...[0, 1, 2].map((c) => Math.abs(ap[c] - p.w[c]))) <= 4) { hit = 'solid ' + p.n; break; }
            const vv = p.v[0] ** 2 + p.v[1] ** 2 + p.v[2] ** 2;
            const al = (d[0] * p.v[0] + d[1] * p.v[1] + d[2] * p.v[2]) / vv;
            if (al <= 0 || al > 1.08) continue;
            const res = Math.max(Math.abs(d[0] - al * p.v[0]), Math.abs(d[1] - al * p.v[1]), Math.abs(d[2] - al * p.v[2]));
            if (res <= 3.5 && res < best) { best = res; hit = 'edge ' + p.n; }
          }
          if (hit) counts[hit] = (counts[hit] || 0) + 1;
          else if (Math.max(...d.map(Math.abs)) <= 2) tiny++;
          else { unattr++; mark(x, ya); }
        }
      };
      for (let y = 0; y < cut; y++) cmp(y, y);
      if (dh !== 0) for (let y = cut2; y < A.height; y++) if (y - dh >= 0) cmp(y, y - dh);
      return { size: [A.width, A.height, B.height], cut, cut2: dh ? cut2 : null, changed, counts, tiny, unattr,
        boxes: boxes.sort((p, q) => q[4] - p[4]).slice(0, 12) };
    }, { a: b64(AFTER + '/' + f), b: b64(BEFORE + '/' + f), pairs: PAIRS, fb, fa });
    results[name] = r;
    console.log(name, JSON.stringify({ changed: r.changed, unattr: r.unattr, tiny: r.tiny, counts: r.counts }));
    if (r.unattr) console.log('   unattributed boxes [x0,y0,x1,y1,n]:', JSON.stringify(r.boxes.slice(0, 6)));
  }
  fs.writeFileSync(path.join(OUT, 'pixdiff.json'), JSON.stringify(results, null, 1));
  await browser.close();
})();
