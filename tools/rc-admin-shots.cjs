/*
 * Lane RC -- the admin card: Appearance -> Banners -> (a set), photographed at
 * 390 and 1280, with the "What this set is" select opened out as a list and
 * the "Shape & frame" section in view.
 *
 *   RC_BASE=http://127.0.0.1:9941 RC_TAG=before node tools/rc-admin-shots.cjs
 *
 * Writes docs/rc-shots/<tag>-admin-<w>.png (the editor's top half) and prints
 * the select's options, every control label and scrollWidth.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RC_BASE || 'http://127.0.0.1:9941';
const TAG = process.env.RC_TAG || 'shot';
const OUT = path.join(__dirname, '..', 'docs', 'rc-shots');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const WIDTHS = (process.env.RC_WIDTHS || '390,1280').split(',').map(Number);
const KIND = process.env.RC_SET_KIND || '';

fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const width of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width, height: 1400 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('banners'));
    await page.waitForTimeout(1400);
    await page.click('[data-bns-open]');
    await page.waitForTimeout(1800);

    if (KIND) {
      await page.selectOption('select[data-bns-set="kind"]', KIND).catch(() => {});
      await page.waitForTimeout(1500);
    }

    const info = await page.evaluate(() => {
      const kind = document.querySelector('select[data-bns-set="kind"]');
      return {
        kindOptions: kind ? [...kind.options].map((o) => o.value + ' = ' + o.textContent.trim()) : null,
        kindValue: kind ? kind.value : null,
        labels: [...document.querySelectorAll('#bns-editor .bns-lab')].map((l) => l.textContent.trim()),
        sections: [...document.querySelectorAll('#bns-editor .bns-sec')].map((l) => l.textContent.trim()),
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      };
    });
    console.log(JSON.stringify({ tag: TAG, width, ...info }));

    // Draw the type select as an open list so the picture shows its options.
    await page.evaluate(() => {
      const kind = document.querySelector('select[data-bns-set="kind"]');
      if (kind) { kind.size = kind.options.length; kind.style.height = 'auto'; }
    });
    const ed = await page.$('#bns-editor');
    const box = ed ? await ed.boundingBox() : null;
    const stop = await page.evaluate(() => {
      const secs = [...document.querySelectorAll('#bns-editor .bns-sec')];
      const after = secs.find((s) => /Motion|Behind/.test(s.textContent));
      return after ? after.getBoundingClientRect().top + window.scrollY : null;
    });
    if (box) {
      const h = Math.min(2400, Math.ceil((stop || box.y + 1300) - box.y + 10));
      await page.setViewportSize({ width, height: Math.ceil(box.y + h + 20) });
      await page.screenshot({ path: path.join(OUT, `${TAG}-admin-${width}.png`), clip: { x: 0, y: Math.max(0, box.y - 10), width, height: h } });
    }
    // The admin's own live preview ("How it looks"), drawn from the unsaved
    // buffer by the shop's partial, at the phone and desktop widths.
    if (process.env.RC_PREVIEW && width >= 1280) {
      await page.addStyleTag({ content: '.bns-foot{position:static !important}' });
      await page.setViewportSize({ width, height: 1400 });
      for (const w of [390, 1280]) {
        await page.click(`[data-bns-w="${w}"]`);
        await page.waitForTimeout(1600);
        const stage = await page.$('#bns-stage');
        if (stage) {
          await stage.scrollIntoViewIfNeeded();
          await page.waitForTimeout(300);
          await stage.screenshot({ path: path.join(OUT, `${TAG}-admin-preview-${w}.png`) });
          const inner = await page.evaluate(() => {
            const f = document.querySelector('.bns-frame');
            const d = f && f.contentDocument;
            return { frameStyle: f ? f.getAttribute('style') : null, hasSingle: !!(d && d.querySelector('.kbbi')), hasSlider: !!(d && d.querySelector('.kbbs')) };
          });
          console.log(JSON.stringify({ tag: TAG, preview: w, ...inner }));
        }
      }
    }
    await ctx.close();
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
