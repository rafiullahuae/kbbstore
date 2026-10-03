/*
 * Lane RI — Desktop sections: Buy these together dragged between the two
 * lists, in a real Chromium, against the REAL screen partial.
 *
 * Driven by tests/Feature/ProductBuyTogetherRightColumnTest.php, which renders
 * admin/partials/product-desktop-sections-screen.blade.php into a page with
 * the console's few globals stubbed (PP, paintProductPage, escHtml, fetch …)
 * and a preview frame holding a skeleton product page. No server: the page is
 * loaded with setContent, so this costs about two seconds.
 *
 *   RI_PAGE    the page to load (a file path)
 *   RI_CHROME  Chromium
 *   RI_PW      the playwright module (path or name)
 *
 * Prints one JSON object on stdout and nothing else.
 */
const fs = require('fs');
const { chromium } = require(process.env.RI_PW || 'playwright');

(async () => {
  const out = { ok: false };
  const browser = await chromium.launch({ executablePath: process.env.RI_CHROME, args: ['--no-sandbox'] });
  try {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 1600 } })).newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.setContent(fs.readFileSync(process.env.RI_PAGE, 'utf8'), { waitUntil: 'load' });
    await page.waitForTimeout(150);

    const lists = () => page.evaluate(() => ({
      buy: [...document.querySelectorAll('#pdsBuy [data-pds-row]')].map((r) => r.getAttribute('data-pds-row')),
      under: [...document.querySelectorAll('#pdsList [data-pds-row]')].map((r) => r.getAttribute('data-pds-row')),
    }));
    const frame = () => page.evaluate(() => {
      const d = document.querySelector('[data-ppframe]').contentDocument;
      const bt = d.querySelectorAll('.kbb-fbt');
      const pg = d.querySelector('.pdp-page');
      return {
        count: bt.length, parent: bt[0] ? (bt[0].parentElement.classList.contains('buybox') ? 'buybox' : bt[0].parentElement.classList.contains('pdp-page') ? 'pdp-page' : bt[0].parentElement.className) : null,
        cls: pg.className, vars: (pg.getAttribute('style') || '').split(';').map((s) => s.trim()).filter(Boolean),
      };
    });
    const drag = async (from, to) => {
      const h = await page.$(`[data-pds-handle="${from}"]`);
      const t = await page.$(`[data-pds-row="${to}"]`);
      const hb = await h.boundingBox();
      const tb = await t.boundingBox();
      await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
      await page.mouse.down();
      await page.mouse.move(hb.x + 20, hb.y - 10, { steps: 3 });
      await page.mouse.move(tb.x + 200, tb.y + 10, { steps: 6 });
      await page.mouse.move(tb.x + 210, tb.y + 12, { steps: 2 });
      const marks = await page.evaluate(() => ({
        over: [...document.querySelectorAll('.pds-row.over')].map((r) => r.getAttribute('data-pds-row')),
        after: [...document.querySelectorAll('.pds-row.over.after')].map((r) => r.getAttribute('data-pds-row')),
      }));
      await page.mouse.up();
      await page.waitForTimeout(60);
      return marks;
    };

    await page.click('[data-pdstab]');
    await page.waitForSelector('#pdsBuy');
    out.start = await lists();
    out.frameStart = await frame();

    // A full-width block other than Buy these together cannot cross.
    out.reviewsMarks = await drag('reviews', 'trust');
    out.afterReviewsDrag = await lists();

    // Buy these together, dragged UP onto Trust lines: lands above it.
    out.btMarks = await drag('buytogether', 'trust');
    out.afterDrop = await lists();
    out.frameAfterDrop = await frame();

    // And dragged back DOWN onto Reviews: lands below it.
    out.backMarks = await drag('buytogether', 'reviews');
    out.afterDropBack = await lists();
    out.frameAfterBack = await frame();

    // ↑ ↑ to the top of the full-width list, then ↑ once more: last in the Buy column.
    await page.click('[data-pds-up="buytogether"]');
    await page.click('[data-pds-up="buytogether"]');
    out.afterUp2 = await lists();
    await page.click('[data-pds-up="buytogether"]');
    out.afterUp3 = await lists();
    // ↓ on the handle from the bottom of the Buy column: first under the columns.
    await page.focus('[data-pds-handle="buytogether"]');
    await page.keyboard.press('ArrowDown');
    out.afterKeyDown = await lists();
    // The button: under Authenticity.
    await page.click('[data-pds-move="buytogether"]');
    out.afterButton = await lists();
    out.buttonLabel = await page.textContent('[data-pds-move="buytogether"]');
    out.frameAfterButton = await frame();

    // Save posts both lists, the block in one of them.
    await page.click('#ppSave');
    await page.waitForTimeout(100);
    out.posted = await page.evaluate(() => window.__posted || null);

    // Reset (the console's guard passes the second click) puts it home.
    await page.click('[data-pds-move="buytogether"]');
    await page.evaluate(() => { const b = document.getElementById('pdsReset'); b.setAttribute('data-kbb-sure-pass', '1'); b.click(); });
    await page.waitForTimeout(60);
    out.afterReset = await lists();
    out.frameAfterReset = await frame();
    out.errors = errors;
    out.ok = true;
  } catch (e) {
    out.error = String(e && e.stack || e);
  }
  await browser.close();
  process.stdout.write(JSON.stringify(out));
})();
