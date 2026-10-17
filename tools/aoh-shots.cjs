/*
 * The owner's own "Recommended for you" heading on /ar with no Arabic typed
 * (17 October 2026). Against a tools/arf-preview.sh server, preview DB only.
 *   BASE=http://127.0.0.1:<port> node tools/aoh-shots.cjs
 */
const fs = require('fs');
const path = require('path');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const BASE = process.env.BASE || 'http://127.0.0.1:9461';
const OUT = path.join(__dirname, '..', 'docs', 'lane-aoh');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await pw.chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium' });
  const out = {};
  for (const [lang, url] of [['ar', '/ar/'], ['en', '/']]) {
    for (const w of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
      await page.goto(BASE + url, { waitUntil: 'networkidle' });
      const h = page.locator('h2', { hasText: lang === 'ar' ? 'مقترحة لكِ' : 'Recommended for you' }).first();
      const found = await h.count();
      let info = { found, errors, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) };
      if (found) {
        const sec = h.locator('xpath=ancestor::section[1]');
        await sec.scrollIntoViewIfNeeded();
        info.heading = (await h.innerText()).trim();
        info.sub = (await sec.locator('p').first().innerText().catch(() => '')).trim();
        info.englishLeftOnPage = await page.evaluate(() => document.body.innerText.includes('Recommended for you'));
        // The heading 150px under the top of the window (below the sticky header).
        await h.evaluate(el => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 150));
        await page.waitForTimeout(300);
        await page.screenshot({ path: path.join(OUT, `section-${lang}-${w}.png`) });
      }
      out[`${lang}-${w}`] = info;
      await ctx.close();
    }
  }
  fs.writeFileSync(path.join(OUT, 'measure.json'), JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 2));
  await browser.close();
})();
