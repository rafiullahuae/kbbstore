/*
 * LANE MN — pixel difference between two screenshots, counted in Chromium's own
 * canvas (no image library in this repo): differing pixels and their bounding box.
 *   node tools/mn-pixdiff.cjs a.png b.png [a2.png b2.png ...]
 */
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const b = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  const p = await b.newPage();
  const args = process.argv.slice(2);
  for (let i = 0; i < args.length; i += 2) {
    const [x, y] = [args[i], args[i + 1]].map((f) => 'data:image/png;base64,' + fs.readFileSync(f).toString('base64'));
    const r = await p.evaluate(async ([x, y]) => {
      const load = (s) => new Promise((res) => { const im = new Image(); im.onload = () => res(im); im.src = s; });
      const [A, B] = await Promise.all([load(x), load(y)]);
      const px = (im) => { const c = document.createElement('canvas'); c.width = im.width; c.height = im.height; const g = c.getContext('2d'); g.drawImage(im, 0, 0); return g.getImageData(0, 0, im.width, im.height).data; };
      if (A.width !== B.width || A.height !== B.height) return { size: [A.width, A.height, B.width, B.height] };
      const a = px(A), d = px(B); let n = 0, x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1;
      for (let i = 0; i < a.length; i += 4) if (a[i] !== d[i] || a[i + 1] !== d[i + 1] || a[i + 2] !== d[i + 2]) { n++; const k = i / 4, xx = k % A.width, yy = (k / A.width) | 0; x0 = Math.min(x0, xx); y0 = Math.min(y0, yy); x1 = Math.max(x1, xx); y1 = Math.max(y1, yy); }
      return { differing: n, box: n ? [x0, y0, x1, y1] : null };
    }, [x, y]);
    console.log(args[i].split('/').pop(), JSON.stringify(r));
  }
  await b.close();
})();
