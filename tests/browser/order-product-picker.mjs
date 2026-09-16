/**
 * Drive the product picker on both order screens, in a real browser.
 *
 * WHY THIS HAS TO BE A BROWSER. Everything the owner reported is a fact about
 * what happens between a keystroke and a click: the suggestion list appeared and
 * was gone before it could be clicked. No assertion against rendered HTML can
 * see that — the HTML was right both before and after. What was wrong was that
 * the SCREEN was replaced underneath the list while the operator was typing, and
 * the only way to be sure that is fixed is to type, wait for the thing that
 * replaces the screen, and then click a suggestion.
 *
 * THE RACE IS FORCED, not waited for. /admin-api/manual-orders/bootstrap is
 * delayed through the browser's own routing so the late render lands at a known
 * moment, while the operator is mid-search. Without that the test would pass on
 * a fast machine and fail on a slow one, which is the same as not having it.
 *
 * Driven by tests/Feature/AdminProductPickerBrowserTest.php, which supplies the
 * server and the fixtures. Prints one JSON object on stdout and nothing else.
 *
 * Config, all via environment:
 *   KBB_PP_BASE       base URL of a running preview   (required)
 *   KBB_PP_EMAIL      admin login                     (required)
 *   KBB_PP_PASSWORD   admin password                  (required)
 *   KBB_PP_CHROME     chromium executable             (required)
 *   KBB_PP_ORDER      id of the seeded editable order (required)
 *   KBB_PP_TERM       a term matching products WITH images, default 'serum'
 *   KBB_PP_TERM_NOIMG a term matching a product with NO image, default 'balm'
 *   KBB_PP_WIDTH      viewport width, default 1280
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_PP_BASE;
const EMAIL = process.env.KBB_PP_EMAIL;
const PASSWORD = process.env.KBB_PP_PASSWORD;
const EXE = process.env.KBB_PP_CHROME;
const ORDER = process.env.KBB_PP_ORDER;
const TERM = process.env.KBB_PP_TERM || 'serum';
const TERM_NOIMG = process.env.KBB_PP_TERM_NOIMG || 'balm';
const WIDTH = Number(process.env.KBB_PP_WIDTH || 1280);

const fail = (msg) => {
  process.stdout.write(JSON.stringify({ ok: false, error: msg }));
  process.exit(0);
};

for (const [k, v] of Object.entries({
  KBB_PP_BASE: BASE, KBB_PP_EMAIL: EMAIL, KBB_PP_PASSWORD: PASSWORD,
  KBB_PP_CHROME: EXE, KBB_PP_ORDER: ORDER,
})) {
  if (!v) fail(`${k} is not set`);
}

const pageErrors = [];   // thrown JavaScript, which breaks the screen
const httpErrors = [];   // 4xx/5xx, with the URL, so the PHP side can judge
const out = {};
let browser;

const hits = (page, box) =>
  page.evaluate((sel) => document.querySelectorAll(sel + ' .kpp-hit').length, box);

try {
  browser = await chromium.launch({ executablePath: EXE });
  const context = await browser.newContext({ viewport: { width: WIDTH, height: 950 } });
  const page = await context.newPage();

  page.on('pageerror', (e) => pageErrors.push(`[pageerror] ${e.message.split('\n')[0]}`));
  // Recorded with the URL rather than as console noise: the admin asks for a
  // favicon it does not ship, and "there was a 404" would fail this test
  // forever for a reason it is not about. The PHP side asserts on the paths
  // this screen owns.
  page.on('response', (r) => {
    if (r.status() >= 400) httpErrors.push(`${r.status()} ${r.url()}`);
  });

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);

  if (!page.url().includes('/admin')) fail(`login did not reach the admin: ${page.url()}`);

  /* ======================================================= New Order ===== */

  /*
   * The reported bug, reproduced deliberately. The screen paints, the operator
   * types, and THEN the vocabularies land and the screen re-renders. Before the
   * fix this is the moment the list, the typed text and the caret all vanished.
   */
  await page.route('**/admin-api/manual-orders/bootstrap*', async (route) => {
    await new Promise((r) => setTimeout(r, 2500));
    await route.continue();
  });

  await page.waitForFunction(() => typeof window.go === 'function');
  await page.evaluate(() => window.go('order-new'));
  await page.waitForSelector('#moProdSearch');

  await page.type('#moProdSearch', TERM, { delay: 40 });
  await page.waitForTimeout(900);

  out.beforeLateRender = {
    hits: await hits(page, '#moProdResults'),
    value: await page.evaluate(() => document.querySelector('#moProdSearch')?.value ?? null),
    focused: await page.evaluate(() => document.activeElement?.id ?? null),
  };

  // Wait for the late bootstrap to land and re-render the screen.
  await page.waitForTimeout(2600);

  out.afterLateRender = {
    hits: await hits(page, '#moProdResults'),
    value: await page.evaluate(() => document.querySelector('#moProdSearch')?.value ?? null),
    focused: await page.evaluate(() => document.activeElement?.id ?? null),
  };

  // … and the list is still clickable, which is the operator's actual complaint.
  out.clickAfterLateRender = { attempted: false, ok: false, error: null, lines: 0 };

  if (out.afterLateRender.hits > 0) {
    out.clickAfterLateRender.attempted = true;
    try {
      await page.click('#moProdResults .kpp-hit:first-child', { timeout: 3000 });
      out.clickAfterLateRender.ok = true;
    } catch (e) {
      out.clickAfterLateRender.error = e.message.split('\n')[0];
    }
    await page.waitForTimeout(700);
    out.clickAfterLateRender.lines = await page.evaluate(() => document.querySelectorAll('.mo-line').length);
  }

  await page.unroute('**/admin-api/manual-orders/bootstrap*');

  /* ---- the suggestion rows: an image, or initials when there is none ---- */
  await page.fill('#moProdSearch', '');
  await page.type('#moProdSearch', TERM, { delay: 40 });
  await page.waitForTimeout(1200);

  out.newOrderRows = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('#moProdResults .kpp-hit')];
    return {
      count: rows.length,
      withImage: rows.filter((r) => r.querySelector('.kpp-th img')).length,
      // A src the browser actually decoded, not merely an <img> tag.
      loaded: rows.filter((r) => {
        const img = r.querySelector('.kpp-th img');
        return img && img.complete && img.naturalWidth > 0;
      }).length,
      listRole: document.querySelector('#moProdResults')?.getAttribute('role') ?? null,
      optionRole: rows[0]?.getAttribute('role') ?? null,
    };
  });

  await page.fill('#moProdSearch', '');
  await page.type('#moProdSearch', TERM_NOIMG, { delay: 40 });
  await page.waitForTimeout(1200);

  out.newOrderNoImageRows = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('#moProdResults .kpp-hit')];
    return {
      count: rows.length,
      broken: rows.filter((r) => {
        const img = r.querySelector('.kpp-th img');
        return img && (!img.complete || img.naturalWidth === 0);
      }).length,
      initials: rows.filter((r) => !r.querySelector('img') && (r.querySelector('.kpp-th')?.textContent ?? '').trim() !== '').length,
    };
  });

  /* ---- keyboard: highlight the second row and take it with Enter ---- */
  await page.fill('#moProdSearch', '');
  await page.type('#moProdSearch', TERM, { delay: 40 });
  await page.waitForTimeout(1200);

  const linesBefore = await page.evaluate(() => document.querySelectorAll('.mo-line').length);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  const highlighted = await page.evaluate(
    () => document.querySelector('#moProdResults [data-kpp-active="1"] .kpp-main b')?.textContent ?? null
  );
  await page.keyboard.press('Enter');
  await page.waitForTimeout(800);

  out.keyboard = {
    highlighted,
    linesBefore,
    linesAfter: await page.evaluate(() => document.querySelectorAll('.mo-line').length),
    names: await page.evaluate(() => [...document.querySelectorAll('.mo-line .mo-line-main b')].map((b) => b.textContent)),
    lineThumbs: await page.evaluate(() => document.querySelectorAll('.mo-line .mo-line-th').length),
  };

  /* ---- Escape closes without clearing what was typed ---- */
  await page.fill('#moProdSearch', '');
  await page.type('#moProdSearch', TERM, { delay: 40 });
  await page.waitForTimeout(1200);
  const escBefore = await hits(page, '#moProdResults');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(250);

  out.escape = { before: escBefore, after: await hits(page, '#moProdResults') };

  /* ==================================================== Order detail ===== */
  await page.evaluate(() => window.go('orders'));
  await page.waitForSelector(`[data-olview="${ORDER}"]`, { timeout: 15000 });
  await page.click(`[data-olview="${ORDER}"]`);
  await page.waitForSelector('#odItems', { timeout: 15000 });
  await page.waitForTimeout(600);

  out.orderItems = await page.evaluate(() => {
    const cells = [...document.querySelectorAll('#odItems .odthumb')];
    return {
      rows: document.querySelectorAll('#odItems tbody tr').length,
      thumbs: cells.length,
      loaded: cells.filter((c) => {
        const img = c.querySelector('img');
        return img && img.complete && img.naturalWidth > 0;
      }).length,
      initials: cells.filter((c) => !c.querySelector('img') && c.textContent.trim() !== '').length,
      empty: cells.filter((c) => !c.querySelector('img') && c.textContent.trim() === '').length,
    };
  });

  await page.type('#odAddProductSearch', TERM, { delay: 40 });
  await page.waitForTimeout(1400);

  out.orderDetailRows = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('#odAddProductResults .kpp-hit')];
    return {
      count: rows.length,
      loaded: rows.filter((r) => {
        const img = r.querySelector('.kpp-th img');
        return img && img.complete && img.naturalWidth > 0;
      }).length,
    };
  });

  const itemsBefore = await page.evaluate(() => document.querySelectorAll('#odItems tbody tr').length);
  await page.keyboard.press('ArrowDown');
  const detHighlighted = await page.evaluate(
    () => document.querySelector('#odAddProductResults [data-kpp-active="1"] .kpp-main b')?.textContent ?? null
  );
  await page.keyboard.press('Enter');
  await page.waitForTimeout(2200);

  out.orderDetailKeyboard = {
    highlighted: detHighlighted,
    itemsBefore,
    itemsAfter: await page.evaluate(() => document.querySelectorAll('#odItems tbody tr').length),
    names: await page.evaluate(() => [...document.querySelectorAll('#odItems tbody tr td:nth-child(2) b')].map((b) => b.textContent)),
  };

  /* ---- and a plain mouse click on the order detail picker ---- */
  await page.type('#odAddProductSearch', TERM, { delay: 40 });
  await page.waitForTimeout(1400);

  out.orderDetailClick = { attempted: false, ok: false, error: null, itemsBefore: out.orderDetailKeyboard.itemsAfter, itemsAfter: 0 };

  if ((await hits(page, '#odAddProductResults')) > 0) {
    out.orderDetailClick.attempted = true;
    try {
      await page.click('#odAddProductResults .kpp-hit:first-child', { timeout: 3000 });
      out.orderDetailClick.ok = true;
    } catch (e) {
      out.orderDetailClick.error = e.message.split('\n')[0];
    }
    await page.waitForTimeout(2200);
    out.orderDetailClick.itemsAfter = await page.evaluate(() => document.querySelectorAll('#odItems tbody tr').length);
  }
} catch (e) {
  fail(String(e && e.message ? e.message : e).split('\n')[0]);
} finally {
  if (browser) await browser.close().catch(() => {});
}

process.stdout.write(JSON.stringify({ ok: true, width: WIDTH, ...out, pageErrors, httpErrors }));
