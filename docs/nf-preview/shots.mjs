// Screenshots + measurements for the 404 previews, in Chromium at 390 and 1280.
// node docs/nf-preview/shots.mjs   (writes docs/nf-preview/shots/*.png, prints JSON)
import { chromium } from 'playwright';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { mkdirSync } from 'node:fs';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, 'shots');
mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ executablePath: process.env.NF_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const only = process.argv[2];
const rows = [];
for (const key of ['a', 'b', 'c', 'd']) {
  if (only && !only.includes(key)) continue;
  for (const lang of ['en', 'ar']) {
    for (const w of [390, 1280]) {
      const page = await browser.newPage({ viewport: { width: w, height: w === 390 ? 844 : 800 }, deviceScaleFactor: w === 390 ? 2 : 1 });
      const requests = [];
      page.on('request', (r) => requests.push(r.url()));
      await page.goto(pathToFileURL(join(here, `option-${key}${lang === 'ar' ? '-ar' : ''}.html`)).href);
      await page.evaluate(() => document.fonts.ready);
      await page.waitForTimeout(2600); // let the one-shot draw-on animation finish
      const m = await page.evaluate(() => {
        const h1 = document.querySelector('h1'), art = document.querySelector('.art'), hero = document.querySelector('.hero');
        return {
          scrollWidth: document.documentElement.scrollWidth,
          h1px: getComputedStyle(h1).fontSize,
          h1font: getComputedStyle(h1).fontFamily.split(',')[0],
          heroH: Math.round(hero.getBoundingClientRect().height),
          artW: Math.round(art.getBoundingClientRect().width),
          artH: Math.round(art.getBoundingClientRect().height),
          svgBytes: art.outerHTML.length,
          fonts: [...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family).join('+'),
        };
      });
      const external = requests.filter((u) => !u.startsWith('file:'));
      rows.push({ key, lang, w, ...m, external: external.length });
      await page.screenshot({ path: join(out, `${key}-${lang}-${w}.png`), fullPage: true });
      await page.close();
    }
  }
}
await browser.close();
console.log(JSON.stringify(rows, null, 0).replace(/\},\{/g, '},\n{'));
