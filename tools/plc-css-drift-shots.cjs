/*
 * LANE PLC — the picture behind the drift numbers.
 *
 * Shoots the same product card on a page that loads BOTH grid sheets and on a
 * page that loads only kbb.css, at 390 and 1280, and prints the measured badge
 * colours and name-clamp beside each shot. The claim is that ten declarations
 * render differently between the two; these are the two that a shopper sees.
 *
 *   node tools/plc-css-drift-shots.cjs        KBB_BASE=http://127.0.0.1:8981
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8981';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OUT = process.env.KBB_SHOTS || 'docs/PLC-css-drift-shots';

const PAGES = [['home', '/'], ['shop', '/shop']];
const WIDTHS = [390, 1280];

async function settle(page) {
  await page.evaluate(async () => {
    /* Every tile but the first is loading="lazy" and a browser does not fetch
       an image it has decided it does not need yet — so an unscrolled page
       photographs as pale rectangles. */
    await new Promise((r) => {
      let y = 0;
      const step = () => {
        window.scrollTo(0, y);
        y += 400;

        if (y < document.body.scrollHeight) {
          setTimeout(step, 30);
        } else {
          window.scrollTo(0, 0);
          setTimeout(r, 400);
        }
      };
      step();
    });
  });
  await page.waitForTimeout(500);
}

async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE });
  const report = [];

  for (const [name, path] of PAGES) {
    for (const width of WIDTHS) {
      const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 2 });
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      await settle(page);

      const sheets = await page.evaluate(() =>
        [...document.styleSheets].map((s) => (s.href || 'inline').split('/').pop()));

      const measured = await page.evaluate(() => {
        const pick = (sel) => document.querySelector(sel);
        const sale = pick('.kbb-badge-sale');
        const fresh = pick('.kbb-badge-new');
        const nm = pick('.kbb-pgrid .cn') || pick('.cn');
        const card = pick('.kbb-pgrid .kbb-card');

        const cs = (el, p) => (el ? getComputedStyle(el).getPropertyValue(p).trim() : '(absent)');

        return {
          saleBackground: cs(sale, 'background-image') === 'none' ? cs(sale, 'background-color') : cs(sale, 'background-image'),
          newBackground: cs(fresh, 'background-image') === 'none' ? cs(fresh, 'background-color') : cs(fresh, 'background-image'),
          nameDisplay: cs(nm, 'display'),
          nameLineClamp: cs(nm, '-webkit-line-clamp'),
          nameOverflow: cs(nm, 'overflow'),
          cardHeight: card ? Math.round(card.getBoundingClientRect().height * 100) / 100 : null,
          scrollWidth: document.documentElement.scrollWidth,
        };
      });

      /* The grid itself, not the whole page: the badge is 9.5px tall and a
         full-page shot at 1280 renders it unreadable. */
      const grid = await page.$('.kbb-pgrid');
      const file = OUT + '/' + name + '-' + width + '.png';

      if (grid) {
        await grid.screenshot({ path: file });
      } else {
        await page.screenshot({ path: file, fullPage: false });
      }

      report.push({ page: name, path, width, file, sheets, ...measured });
      console.log(name + ' @' + width + '  -> ' + file);
      console.log('    sheets        : ' + sheets.join(', '));
      console.log('    SALE badge    : ' + measured.saleBackground);
      console.log('    NEW badge     : ' + measured.newBackground);
      console.log('    name display  : ' + measured.nameDisplay + '   line-clamp: ' + measured.nameLineClamp + '   overflow: ' + measured.nameOverflow);
      console.log('    card height   : ' + measured.cardHeight + 'px   scrollWidth: ' + measured.scrollWidth);
      await page.close();
    }
  }

  await browser.close();
  fs.writeFileSync(OUT + '/measured.json', JSON.stringify(report, null, 2));
  console.log('\nnumbers in ' + OUT + '/measured.json');
}

main().catch((e) => { console.error(e); process.exit(1); });
