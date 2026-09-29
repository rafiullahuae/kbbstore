/**
 * Appearance → Banners, with the second banner type in it — Lane BN2.
 *
 * Signs in, opens the Banners screen, opens the seeded slider set, and shoots
 * the editor at 1280 and at 390. Also flips the type picker back to "Cards" and
 * shoots that, because the one thing this screen must never do is show the
 * slider's controls for a cards banner or the other way round.
 *
 * Prints one JSON object on stdout and nothing else.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.KBB_BN2_BASE;
const EXE = process.env.KBB_BN2_CHROME;
const SHOTS = process.env.KBB_BN2_SHOTS || '';
const EMAIL = process.env.KBB_BN2_EMAIL;
const PASSWORD = process.env.KBB_BN2_PASSWORD;

const out = { ok: false, screens: {}, pageErrors: [] };
const done = () => { process.stdout.write(JSON.stringify(out)); process.exit(0); };
const fail = (m) => { out.error = m; done(); };

if (!BASE) fail('KBB_BN2_BASE is not set');
if (SHOTS) mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch(EXE ? { executablePath: EXE } : {});

for (const width of [1280, 390]) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, deviceScaleFactor: 2 });
  const page = await context.newPage();
  page.on('pageerror', (e) => out.pageErrors.push(width + ': ' + String(e)));

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[type="email"], input[name="email"]', EMAIL);
  await page.fill('input[type="password"], input[name="password"]', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');

  /* The console is a single page whose sidebar entries are registered by each
     screen's own script, so the screen is reached by its nav entry rather than
     by a URL. */
  /* THE GROUP IS COLLAPSED, so the sidebar button cannot simply be clicked —
     `window.go` is the console's own navigator and is what the entry calls. */
  await page.evaluate(() => window.go('banners'));
  await page.waitForTimeout(1200);

  const row = { nav: await page.locator('.side .nav-item', { hasText: 'Banners' }).count() };
  row.pills = await page.locator('.bns-set .bns-pill').allTextContents();
  row.newButtons = await page.locator('[data-bns-kind]').allTextContents();

  if (SHOTS) await page.screenshot({ path: `${SHOTS}/admin-list-${width}.png`, fullPage: true });

  await page.locator('[data-bns-open]').first().click();
  await page.waitForTimeout(1200);

  row.sections = await page.locator('#bns-editor .bns-sec').allTextContents();
  row.kind = await page.locator('[data-bns-set="kind"]').inputValue();
  row.look = await page.locator('[data-bns-set="slider_style"]').inputValue();
  row.lookOptions = await page.locator('[data-bns-set="slider_style"] option').allTextContents();
  row.note = (await page.locator('[data-bns-note="slider_style"]').textContent() || '').slice(0, 90);
  row.ratios = await page.locator('[data-bns-set="slider_ratio"] option').allTextContents();

  if (SHOTS) await page.screenshot({ path: `${SHOTS}/admin-slider-${width}.png`, fullPage: true });

  /* The note under the Look picker must follow the picker without a redraw. */
  await page.selectOption('[data-bns-set="slider_style"]', 'corner');
  await page.waitForTimeout(500);
  row.noteAfter = (await page.locator('[data-bns-note="slider_style"]').textContent() || '').slice(0, 90);
  row.dirtyAfterLook = await page.locator('#bns-saveset').textContent();

  /* And the type picker must swap the whole control set. */
  await page.selectOption('[data-bns-set="kind"]', 'cards');
  await page.waitForTimeout(900);
  row.sectionsAsCards = await page.locator('#bns-editor .bns-sec').allTextContents();
  row.hasSliderControlsAsCards = await page.locator('[data-bns-set="slider_style"]').count();
  row.hasPerViewAsCards = await page.locator('[data-bns-set="per_view"]').count();

  if (SHOTS && width === 1280) await page.screenshot({ path: `${SHOTS}/admin-cards-${width}.png`, fullPage: true });

  await page.selectOption('[data-bns-set="kind"]', 'slider');
  await page.waitForTimeout(900);
  row.hasPerViewAsSlider = await page.locator('[data-bns-set="per_view"]').count();
  row.hasSliderControlsAsSlider = await page.locator('[data-bns-set="slider_style"]').count();

  /* The live preview, drawn by the shop's own template from the unsaved
     buffer — the thing the owner actually looks at. */
  /* THE PREVIEW IS AN IFRAME, so it is reached as a frame and not as a
     selector on this document. It is sandboxed to `allow-scripts` with no
     `allow-same-origin`, which is an opaque origin — Playwright can still
     drive it, a page on the parent's origin could not. */
  await page.waitForTimeout(700);
  const pv = page.frameLocator('#bns-stage iframe');
  row.previewHasSlider = await pv.locator('.kbbs').count();
  row.previewBars = await pv.locator('.kbbs-bar').count();
  row.previewIsJs = await pv.locator('.kbbs.is-js').count();
  try {
    /* dispatchEvent rather than click(): the iframe is taller than the console's
       viewport, so a real pointer click is clamped to the visible area and
       lands somewhere else. The handler is an ordinary click listener. */
    await pv.locator('.kbbs-next').dispatchEvent('click', { timeout: 5000 });
    await page.waitForTimeout(700);
    row.previewAfterNext = await pv.locator('.kbbs-bar.is-on').getAttribute('aria-label');
  } catch (e) {
    row.previewAfterNext = 'could not click: ' + String(e).slice(0, 120);
  }
  if (SHOTS) await page.locator('#bns-stage').screenshot({ path: `${SHOTS}/admin-preview-${width}.png` });

  out.screens[width] = row;
  await context.close();
}

await browser.close();
out.ok = true;
done();
