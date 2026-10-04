// Lane PF: full homepage at 1280 and 390, and every section heading's and
// description's COMPUTED type, read once after load (nothing resized, nothing
// observed) — the font-size table the owner's "same font size, desktop and
// mobile both" is checked against.
//
//   node tools/pf-shoot.cjs <base> <outdir> <tag>
//
// Close-ups of three section heads side by side:
//   node tools/pf-shoot.cjs <base> <outdir> <tag> heads
const { chromium } = require('playwright');
(async () => {
  const base = process.argv[2] || 'http://127.0.0.1:9882';
  const out = process.argv[3] || 'storage/pf-logs';
  const tag = process.argv[4] || 'home';
  const mode = process.argv[5] || 'full';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const p = await b.newPage({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: 1 });
    await p.goto(base + '/', { waitUntil: 'networkidle' });
    await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 120)); } window.scrollTo(0, 0); });
    await p.waitForTimeout(1200);
    const m = await p.evaluate(() => {
      const rows = [];
      document.querySelectorAll('.kbb-home > section').forEach(s => {
        if (getComputedStyle(s).display === 'none' || s.getBoundingClientRect().height === 0) return;
        const head = s.querySelector('.hs-head, .bndl-head, .spt-head, .sh');
        const h2 = head ? head.querySelector('h2') : null;
        if (!h2) return;
        const p = head.querySelector('p');
        const hs = getComputedStyle(h2);
        const ps = p ? getComputedStyle(p) : null;
        const hr = h2.getBoundingClientRect();
        const pr = p ? p.getBoundingClientRect() : null;
        rows.push({
          h2: h2.textContent.replace(/\s+/g, ' ').trim().slice(0, 44),
          h2Size: hs.fontSize, h2Weight: hs.fontWeight, h2Line: hs.lineHeight, h2Align: hs.textAlign,
          pSize: ps ? ps.fontSize : null, pShown: ps ? ps.display !== 'none' : null, pLine: ps ? ps.lineHeight : null,
          gap: pr && ps.display !== 'none' ? Math.round(pr.top - hr.bottom) : null,
          sectionH: Math.round(s.getBoundingClientRect().height),
        });
      });
      return { scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
        pageHeight: document.documentElement.scrollHeight, h1: document.querySelectorAll('h1').length, sections: rows };
    });
    console.log('=== ' + tag + ' @' + w + ' scrollWidth ' + m.scrollWidth + '/' + m.clientWidth + ' page ' + m.pageHeight + 'px h1=' + m.h1);
    for (const r of m.sections) {
      console.log([w, r.h2.padEnd(44), r.h2Size, r.h2Weight, r.h2Line, r.h2Align, '| p', r.pSize, r.pShown ? 'shown' : (r.pShown === null ? '-' : 'HIDDEN'), r.pLine, 'gap', r.gap, '| h', r.sectionH].join(' '));
    }
    if (mode === 'full') {
      await p.screenshot({ path: `${out}/${tag}-${w}.png`, fullPage: true });
    } else {
      // Three section heads, side by side in one image: bundles, Best Sellers, Spotted.
      const sel = ['.bndl .bndl-head', '.hs-bestselling .hs-head', '.spt .spt-head', '.hs-brands .hs-head'];
      let i = 0;
      for (const s of sel) {
        const el = await p.$(s);
        if (!el) continue;
        await el.scrollIntoViewIfNeeded();
        await el.screenshot({ path: `${out}/${tag}-${w}-head${++i}.png` });
      }
    }
    await p.close();
  }
  await b.close();
})();
