// Every template x every size, measured in Chromium from the built file (file://, no window.claude):
//  - every text's glyph box (rotation included) is inside the canvas
//  - text in a fixed box (buttons, badges, bands) fits its box
//  - no two texts overlap; no product covers a text
//  - no console errors
// node tools/check-templates.cjs [ad-studio.html] [--json out.json]
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const file = path.resolve(process.argv[2] && !process.argv[2].startsWith('--') ? process.argv[2] : path.join(__dirname, '..', 'ad-studio.html'));
const jo = process.argv.indexOf('--json');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1400, height: 1000 } });
  const errs = []; p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); }); p.on('pageerror', (e) => errs.push(e.message));
  const net = []; p.on('request', (r) => { if (!/^(file|data|blob):/.test(r.url())) net.push(r.url()); });
  await p.goto('file://' + file); await p.waitForFunction(() => document.documentElement.dataset.ready === '1'); await p.evaluate(() => document.fonts.ready);
  const res = await p.evaluate(async () => {
    const S = window.KBB_STUDIO, out = [];
    // measure at a large stage so rounding is negligible
    document.getElementById('stage').style.setProperty('width', '900px');
    for (const t of S.templates) {
      S.useTemplate(t.id);
      for (const f of ['post', 'square', 'story']) {
        S.st.fmt = f; S.draw();
        const stage = document.getElementById('stage'); stage.style.width = (f === 'story' ? 600 : 900) + 'px';
        S.draw(); await new Promise((r) => requestAnimationFrame(r));
        const cv = stage.querySelector('.cv'), W = cv.clientWidth, H = cv.clientHeight, cr = cv.getBoundingClientRect(), u = W / 100;
        const issues = [], texts = [];
        cv.querySelectorAll('.el').forEach((n) => {
          const t = n.querySelector('.tx'); if (!t) return;
          const id = n.dataset.id, g = S.doc.geo[f][id], ang = (g.r || 0) * Math.PI / 180;
          const tc = t.parentNode, lx = tc.offsetLeft + t.offsetLeft, ly = tc.offsetTop + t.offsetTop, w = t.offsetWidth, h = t.offsetHeight;
          const cx = n.offsetLeft + n.offsetWidth / 2, cy = n.offsetTop + n.offsetHeight / 2;
          const pts = [[lx, ly], [lx + w, ly], [lx, ly + h], [lx + w, ly + h]].map(([x, y]) => { const dx = n.offsetLeft + x - cx, dy = n.offsetTop + y - cy; return [cx + dx * Math.cos(ang) - dy * Math.sin(ang), cy + dx * Math.sin(ang) + dy * Math.cos(ang)]; });
          const tol = .4 * u, out_ = pts.some(([x, y]) => x < -tol || y < -tol || x > W + tol || y > H + tol);
          if (out_) issues.push('outside canvas: ' + id + ' "' + t.textContent.slice(0, 24) + '"');
          if (n.classList.contains('box') && (h > tc.clientHeight + 2 || w > tc.clientWidth + 2)) issues.push('spills its box: ' + id);
          if (n.classList.contains('nw') && w > n.clientWidth + 2) issues.push('wider than its box: ' + id);
          const xs = pts.map((q) => q[0]), ys = pts.map((q) => q[1]);
          if (Math.abs(g.r || 0) <= 15) texts.push({ id, x0: Math.min(...xs), x1: Math.max(...xs), y0: Math.min(...ys), y1: Math.max(...ys) });
        });
        const inter = (a, b) => Math.max(0, Math.min(a.x1, b.x1) - Math.max(a.x0, b.x0)) * Math.max(0, Math.min(a.y1, b.y1) - Math.max(a.y0, b.y0));
        const area = (a) => (a.x1 - a.x0) * (a.y1 - a.y0);
        for (let i = 0; i < texts.length; i++) for (let j = i + 1; j < texts.length; j++) { const o = inter(texts[i], texts[j]); if (o > .04 * Math.min(area(texts[i]), area(texts[j]))) issues.push('texts overlap: ' + texts[i].id + ' / ' + texts[j].id); }
        cv.querySelectorAll('.bp').forEach((n) => {
          const r = n.getBoundingClientRect(), pr = { x0: r.left - cr.left + r.width * .12, x1: r.right - cr.left - r.width * .12, y0: r.top - cr.top + r.height * .06, y1: r.bottom - cr.top - r.height * .04 };
          texts.forEach((t) => { if (t.id === 'word') return; /* the big word sits behind the hero product on purpose */ const o = inter(pr, t); if (o > .06 * area(t)) issues.push('product covers text: ' + t.id + ' (' + Math.round(o / area(t) * 100) + '%)'); });
          if (pr.x1 < 0 || pr.x0 > W || pr.y1 < 0 || pr.y0 > H) issues.push('product off canvas');
        });
        out.push({ id: t.id, cat: t.cat, fam: t.fam, fmt: f, issues });
      }
    }
    return out;
  });
  const bad = res.filter((r) => r.issues.length);
  const cats = {}; res.forEach((r) => { if (r.fmt === 'post') cats[r.cat] = (cats[r.cat] || 0) + 1; });
  console.log('templates', res.length / 3, 'renders', res.length, 'by category', JSON.stringify(cats));
  console.log('renders with issues', bad.length); bad.forEach((r) => console.log(' ', r.id, r.fmt, '|', r.issues.join('; ')));
  console.log('console errors', errs.length, errs.slice(0, 5)); console.log('network requests', net.length, net.slice(0, 5));
  if (jo > 0) fs.writeFileSync(process.argv[jo + 1], JSON.stringify({ res, errs, net }, null, 1));
  await b.close(); process.exit(bad.length || errs.length || net.length ? 1 : 0);
})();
