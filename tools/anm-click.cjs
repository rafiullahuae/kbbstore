/* Lane AN: every footer link and the WhatsApp button answer a real click
   (elementFromPoint over each one's centre), scrollWidth = viewport, and the
   console. node tools/anm-click.cjs BASE */
const { chromium } = require('playwright');
const BASE = process.argv[2];
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const path of ['/', '/product/co-glow-serum/', '/collections/serums/', '/brands/cosrx/', '/super-sale/', '/blog/']) for (const [w, h] of [[390, 844], [1280, 800]]) {
    const p = await browser.newPage({ userAgent: UA, viewport: { width: w, height: h } });
    const errs = []; p.on('console', m => m.type() === 'error' && errs.push(m.text())); p.on('response', r => r.status() >= 400 && errs.push(r.status() + ' ' + r.url()));
    await p.goto(BASE + path, { waitUntil: 'networkidle' });
    const wa = await p.evaluate(() => { const a = document.querySelector('.kbw-a'); if (!a) return 'none'; const r = a.getBoundingClientRect(); const e = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return a.contains(e) ? 'ok' : 'BLOCKED by ' + (e && e.className); });
    const links = await p.$$('footer.kft a');
    let ok = 0, bad = [];
    for (const l of links) {
      if (!(await l.isVisible())) continue;
      await l.scrollIntoViewIfNeeded();
      const r = await l.evaluate(a => { const b = a.getBoundingClientRect(); const e = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2); return a.contains(e) || (e && e.closest('.kbw')) ? 'ok' : (e && (e.className || e.tagName)); });
      r === 'ok' ? ok++ : bad.push(r);
    }
    const sw = await p.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
    console.log(JSON.stringify({ path, w, wa, footerLinksOk: ok, blocked: bad, sw, errs }));
    await p.close();
  }
  await browser.close();
})();
