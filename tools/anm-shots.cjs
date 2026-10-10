/*
 * Lane AN (motion): the same frame before and after, pixel by pixel.
 *
 *   node tools/anm-shots.cjs BEFORE_BASE AFTER_BASE OUTDIR
 *
 * Every animation on the page is paused and set to the SAME time on both
 * previews, so a moved layer and the old background-position are compared at
 * the same point of their cycle. Each part is shot at 390 (DPR 3) and 1280,
 * at three times; the diff is counted in a page canvas (no image library).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const [BEFORE, AFTER, OUT] = process.argv.slice(2);
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const PARTS = { 'footer-help': '.kft-help', 'footer-name': '.kft-name', whatsapp: '.kbw', 'buy-together': '.kbb-fbt', logo: '.lgx',
  // The wash alone: the cards are picked afresh on each load, and the strip's
  // words are compared in the full shot above.
  'buy-together-wash': '.kbb-fbt|.kbb-fbt > *', 'footer-help-wash': '.kft-help|.kft-help > *' };
const ONLY = process.env.ANM_PARTS ? process.env.ANM_PARTS.split(',') : null;
const TIMES = [0, 2300, 5200, 9100];
const VPS = { 390: { width: 390, height: 844, deviceScaleFactor: 3 }, 1280: { width: 1280, height: 800, deviceScaleFactor: 1 } };
fs.mkdirSync(OUT, { recursive: true });

async function shoot(browser, base, vp, spec, t) {
  const [sel, hide] = spec.split('|');
  const ctx = await browser.newContext({ userAgent: UA, viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: vp.deviceScaleFactor });
  const page = await ctx.newPage();
  await page.goto(base + '/product/co-glow-serum/', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  if (hide) await page.addStyleTag({ content: `${hide}{visibility:hidden!important}` });
  const el = page.locator(sel).first();
  await el.scrollIntoViewIfNeeded();
  await page.waitForTimeout(1200);
  await page.evaluate(t => document.getAnimations().forEach(a => { a.pause(); a.currentTime = t; }), t);
  await page.waitForTimeout(150);
  const buf = await el.screenshot({ animations: 'allow' });
  await ctx.close();
  return buf;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const cmp = await (await browser.newContext()).newPage();
  for (const [w, vp] of Object.entries(VPS)) for (const [part, sel] of Object.entries(PARTS)) for (const t of TIMES) {
    if (ONLY && !ONLY.includes(part)) continue;
    const a = await shoot(browser, BEFORE, vp, sel, t), b = await shoot(browser, AFTER, vp, sel, t);
    if (t === TIMES[2]) { fs.writeFileSync(`${OUT}/${part}-${w}-before.png`, a); fs.writeFileSync(`${OUT}/${part}-${w}-after.png`, b); }
    const r = await cmp.evaluate(async ([a, b]) => {
      const load = s => new Promise(r => { const i = new Image(); i.onload = () => r(i); i.src = 'data:image/png;base64,' + s; });
      const [ia, ib] = await Promise.all([load(a), load(b)]);
      if (ia.width !== ib.width || ia.height !== ib.height) return { size: [ia.width, ia.height, ib.width, ib.height] };
      const px = i => { const c = new OffscreenCanvas(i.width, i.height), x = c.getContext('2d'); x.drawImage(i, 0, 0); return x.getImageData(0, 0, i.width, i.height).data; };
      const da = px(ia), db = px(ib); let diff = 0, max = 0, over2 = 0;
      const c = new OffscreenCanvas(ia.width, ia.height), x = c.getContext('2d'), out = x.createImageData(ia.width, ia.height);
      for (let k = 0; k < da.length; k += 4) { const m = Math.max(Math.abs(da[k] - db[k]), Math.abs(da[k + 1] - db[k + 1]), Math.abs(da[k + 2] - db[k + 2])); if (m) diff++; if (m > 2) over2++; if (m > max) max = m;
        out.data[k] = m > 2 ? 255 : 230; out.data[k + 1] = out.data[k + 2] = m > 2 ? 0 : 230; out.data[k + 3] = 255; }
      x.putImageData(out, 0, 0);
      const png = await new Promise(r => { const fr = new FileReader(); fr.onload = () => r(fr.result.split(',')[1]); c.convertToBlob().then(b => fr.readAsDataURL(b)); });
      return { w: ia.width, h: ia.height, pixels: da.length / 4, diff, over2, max, png };
    }, [a.toString('base64'), b.toString('base64')]);
    if (t === TIMES[2] && r.png && process.env.ANM_DIFF) fs.writeFileSync(`${process.env.ANM_DIFF}/${part}-${w}-diff.png`, Buffer.from(r.png, 'base64'));
    delete r.png;
    console.log(JSON.stringify({ vp: +w, part, t, ...r }));
  }
  await browser.close();
})();
