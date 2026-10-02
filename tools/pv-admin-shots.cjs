/*
 * Lane PV — Appearance → Product page, the two new halves, with the live
 * preview moving as the controls move. Nothing is saved.
 *
 *   PV_BASE=http://127.0.0.1:8961 node tools/pv-admin-shots.cjs
 *
 * Every number is read out of the two preview frames by the harness; the
 * console itself measures nothing (CLAUDE.md rule 4).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PV_BASE || 'http://127.0.0.1:8961';
const OUT = process.env.PV_OUT || path.join(__dirname, '..', 'docs', 'lane-pv-shots');
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const READ = () => {
  const one = (kind) => {
    const fr = document.querySelector(`[data-ppframe="${kind}"]`);
    if (!fr || !fr.contentDocument) return null;
    const d = fr.contentDocument;
    const r = (s) => { const e = d.querySelector(s); return e ? e.getBoundingClientRect() : null; };
    const cs = (s, p) => { const e = d.querySelector(s); return e ? getComputedStyle(e)[p] : null; };
    const g = r('.gmain'); const t = r('#gthumbs'); const b = r('.bb-brand'); const h = r('.bb-title');
    const rate = r('.cap-area .sr-capbar'); const desc = r('.bb-desc');
    return {
      title: (d.querySelector('.bb-title') || {}).textContent || null,
      pdpPaddingTop: cs('.pdp', 'paddingTop'),
      thumbsOverlapPhoto: (g && t) ? Math.round(g.bottom - t.top) : null,
      brandToTitle: (b && h) ? Math.round(h.top - b.bottom) : null,
      ratingToDesc: (rate && desc) ? Math.round(desc.top - rate.bottom) : null,
      badgeBg: cs('.gmain .lbl', 'backgroundColor'),
      badgeFg: cs('.gmain .lbl', 'color'),
      brandSize: cs('.bb-brand', 'fontSize'),
    };
  };
  return { scrollWidth: document.documentElement.scrollWidth, desktop: one('desktop'), mobile: one('mobile') };
};

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
}

async function set(page, key, value) {
  await page.evaluate(([k, v]) => {
    const el = document.querySelector(`[data-pl="${k}"]`);
    if (!el) throw new Error('no control ' + k);
    el.value = String(v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }, [key, value]);
  await page.waitForTimeout(400);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();
  await signIn(page);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.go('productpage'));
  await page.waitForTimeout(3000);

  await page.click('[data-pptab="photo"]');
  await page.waitForTimeout(800);
  report['photo-shipped'] = await page.evaluate(READ);
  await page.screenshot({ path: `${OUT}/admin-photo-shipped.png` });

  await set(page, 'thumb_over', '1');
  await set(page, 'badge_bg', '#c13e63');
  await set(page, 'gal_top_m', 30);
  report['photo-moved'] = await page.evaluate(READ);
  await page.screenshot({ path: `${OUT}/admin-photo-moved.png` });

  await page.click('[data-pptab="sp_buy"]');
  await page.waitForTimeout(600);
  report['sp_buy-shipped'] = await page.evaluate(READ);
  await page.screenshot({ path: `${OUT}/admin-spbuy-shipped.png` });

  await set(page, 'head_gap', 0);
  await set(page, 'desc_gap_m', 4);
  await set(page, 'head_gap_d', 30);
  report['sp_buy-moved'] = await page.evaluate(READ);
  await page.screenshot({ path: `${OUT}/admin-spbuy-moved.png` });

  fs.writeFileSync(path.join(OUT, 'admin-measure.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
