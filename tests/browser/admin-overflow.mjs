/**
 * Walk every admin screen at a given viewport width and report, for each, the
 * content column's scrollWidth against its clientWidth.
 *
 * WHY #content AND NOT THE DOCUMENT. The admin is a two-pane layout: the page
 * itself does not scroll, the column inside it does. `#content` is
 * `overflow-x: auto`, so a child that is too wide is absorbed there and
 * `document.documentElement.scrollWidth` stays exactly at the viewport width.
 * All four of the screens this was written for reported 390 by that measure
 * while the column was 416 to 490 wide. The only number that sees the defect is
 * the one below.
 *
 * Driven by tests/Feature/AdminMobileOverflowTest.php, which supplies the
 * server. Prints one JSON object on stdout and nothing else, so the PHP side
 * can read it without parsing prose.
 *
 * Config, all via environment:
 *   KBB_BROWSER_BASE      base URL of a running preview  (required)
 *   KBB_BROWSER_EMAIL     admin login                    (required)
 *   KBB_BROWSER_PASSWORD  admin password                 (required)
 *   KBB_BROWSER_CHROME    chromium executable            (required)
 *   KBB_BROWSER_WIDTH     viewport width, default 390
 *   KBB_BROWSER_PROBE     '1' to inject an oversized block into every screen,
 *                         which must make every screen fail — the harness
 *                         proving it can still see an overflow at all
 *   KBB_BROWSER_PROBE_W   width of that block, default 1672
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_BROWSER_BASE;
const EMAIL = process.env.KBB_BROWSER_EMAIL;
const PASSWORD = process.env.KBB_BROWSER_PASSWORD;
const EXE = process.env.KBB_BROWSER_CHROME;
const WIDTH = Number(process.env.KBB_BROWSER_WIDTH || 390);
const PROBE = process.env.KBB_BROWSER_PROBE === '1';
const PROBE_W = Number(process.env.KBB_BROWSER_PROBE_W || 1672);

/**
 * Every screen the admin's own go() router can render.
 *
 * Deliberately the whole list and not the four that were reported: the point
 * of a walk is that it catches the fifth. The `p-` placeholders and the `rev-`
 * review frames are out — they are iframes, so their content is not this
 * document's to measure.
 *
 * THE LAST THREE ARE NOT FRAME SCREENS ANY MORE. When this list was written,
 * 'analytics', 'store-settings' and 'seo' were in FRAME_SRC and were excluded
 * on that ground. They are in LIVE_RENDERED now — a later window.go override
 * draws all three in this document, no iframe involved — so the ground for
 * excluding them has gone, and with it the reason three of the screens the
 * owner opens daily were the only ones nothing measured. All three squeezed
 * rather than overflowed at 390px, which is a defect this walk cannot see; it
 * can see the next one, which is why they belong here.
 */
const SCREENS = [
  'dash', 'updates', 'layout', 'bundles', 'homepage', 'productpage',
  'mobilemenu', 'header', 'search', 'acctpanel', 'cartpanel', 'dividers',
  'mobilehdr', 'prodstyles', 'newsletter', 'ecommerce', 'modules', 'megamenu',
  'payship', 'mail', 'shipping', 'pages-store', 'pages-user', 'theme', 'users',
  'settings', 'debug', 'sandbox', 'console', 'catalog', 'import', 'labels',
  'pixels', 'meta', 'shopfilters', 'democontent',
  'analytics', 'store-settings', 'seo',
];

const fail = (msg) => {
  process.stdout.write(JSON.stringify({ ok: false, error: msg }));
  process.exit(0);
};

for (const [k, v] of Object.entries({ KBB_BROWSER_BASE: BASE, KBB_BROWSER_EMAIL: EMAIL, KBB_BROWSER_PASSWORD: PASSWORD, KBB_BROWSER_CHROME: EXE })) {
  if (!v) fail(`${k} is not set`);
}

const rows = [];
const pageErrors = [];

let browser;

try {
  browser = await chromium.launch({ executablePath: EXE });
  const context = await browser.newContext({ viewport: { width: WIDTH, height: 900 } });
  const page = await context.newPage();

  page.on('pageerror', (e) => pageErrors.push(`[pageerror] ${e.message.split('\n')[0]}`));
  page.on('console', (m) => {
    if (m.type() === 'error') pageErrors.push(`[console.error] ${m.text()}`);
  });
  // Recorded synchronously. Awaiting the body first lets the run finish before
  // the push lands, which reads back as "no failures".
  page.on('response', (r) => {
    if (r.status() >= 400) pageErrors.push(`[http ${r.status()}] ${r.url()}`);
  });

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);

  if (!page.url().includes('/admin')) fail(`login did not reach the admin: ${page.url()}`);

  for (const id of SCREENS) {
    // The hash does not route; go(id) is the router. A hash-driven pass
    // silently measures the dashboard once per screen and reports all clear.
    await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof window.go === 'function');

    try {
      await page.evaluate((h) => window.go(h), id);
    } catch (e) {
      // A screen that throws is somebody's bug, but not an overflow. Record
      // it and keep walking rather than losing the other 35 results.
      rows.push({ id, threw: String(e.message).split('\n')[0] });
      continue;
    }

    await page
      .waitForFunction(() => !document.querySelector('#content .kpis'), { timeout: 15000 })
      .catch(() => {});
    await page.waitForTimeout(1200);

    if (PROBE) {
      await page.evaluate((w) => {
        const d = document.createElement('div');
        d.id = 'kbb-overflow-probe';
        d.style.cssText = `width:${w}px;height:8px`;
        document.querySelector('#content').appendChild(d);
      }, PROBE_W);
      await page.waitForTimeout(150);
    }

    const m = await page.evaluate(() => {
      const c = document.querySelector('#content');
      if (!c) return null;

      let worst = null;
      let right = -Infinity;

      for (const el of c.querySelectorAll('*')) {
        const r = el.getBoundingClientRect();
        if (!r.width && !r.height) continue;
        if (r.right > right) {
          right = r.right;
          worst = el;
        }
      }

      const name = (el) => {
        if (!el) return '(none)';
        const cls = (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).join('.');
        return el.tagName.toLowerCase() + (cls ? '.' + cls : '');
      };

      return {
        documentScrollWidth: document.documentElement.scrollWidth,
        scrollWidth: c.scrollWidth,
        clientWidth: c.clientWidth,
        worst: name(worst),
      };
    });

    if (PROBE) await page.evaluate(() => document.getElementById('kbb-overflow-probe')?.remove());

    rows.push({ id, ...m });
  }
} catch (e) {
  fail(String(e && e.message ? e.message : e).split('\n')[0]);
} finally {
  if (browser) await browser.close().catch(() => {});
}

process.stdout.write(JSON.stringify({ ok: true, width: WIDTH, probe: PROBE, rows, pageErrors }));
