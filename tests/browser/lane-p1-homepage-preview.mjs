/**
 * Appearance → Homepage · Preview, driven in a real browser — Lane P1, Phase 15.
 *
 * WHAT THE PEST SUITE CANNOT SAY. HomepagePreviewTest pins what the SERVER
 * does: that the rendered document is byte-identical to GET /, that a proposal
 * nobody saved draws the page saving it would produce, that nothing is written.
 * It stops at the screen. "Live editing" is a claim about a SCREEN — that the
 * picture appears where the rows are, at a real device width, and that the line
 * under it stops claiming to be current the moment a row moves — and this is
 * the half that checks it.
 *
 * IT REPORTS AND SCREENSHOTS; IT DOES NOT ASSERT. It prints, per width:
 *
 *   scrollWidth / innerWidth   the horizontal-overflow check the project notes
 *                              ask for by name. `overflow: true` is a bug.
 *   rows                       how many section rows the screen drew — the
 *                              number that must not move between revisions.
 *   arrows / switches          the controls on those rows, likewise.
 *   note                       the sentence under the picture, verbatim, before
 *                              and after a row is moved.
 *   frameWidth                 the width the previewed DOCUMENT is laid out at,
 *                              read from inside the frame. 1280 or 390, never
 *                              the panel's own width.
 *
 * Point it at either revision and change KBB_P1_TAG; it needs no editing
 * between the two runs.
 *
 *   KBB_P1_URL=http://127.0.0.1:8977 \
 *   KBB_P1_EMAIL=p1@example.test KBB_P1_PASSWORD=secret-secret \
 *   KBB_P1_OUT=docs/p1-preview-shots KBB_P1_TAG=p1-after \
 *   KBB_P1_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-p1-homepage-preview.mjs
 *
 * It signs in and PRESSES PREVIEW, which writes nothing — that is the whole
 * point of the endpoint — but it does not press Save. Even so, point it at a
 * preview database rather than at production.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_P1_URL || 'http://127.0.0.1:8977';
const EMAIL = process.env.KBB_P1_EMAIL || 'p1@example.test';
const PASSWORD = process.env.KBB_P1_PASSWORD || 'secret-secret';
const OUT = process.env.KBB_P1_OUT || 'docs/p1-preview-shots';
const TAG = process.env.KBB_P1_TAG || 'run';
const CHROME = process.env.KBB_P1_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const report = { tag: TAG, base: BASE, widths: [], error: null };
const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });

try {
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('  page error:', e.message));

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });

    if (await page.locator('input[type=email]').count()) {
      await page.fill('input[type=email]', EMAIL);
      await page.fill('input[type=password]', PASSWORD);
      await page.click('button[type=submit]');
      await page.waitForLoadState('networkidle');
    }

    /* ?go=<id> is read before the console's partials wrap window.go, so it
       fails silently on several screens — see tests/browser/lane-m2-module-
       screens.mjs. Calling go() from the page is the reliable form. */
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('homepage'));
    await page.waitForTimeout(2000);

    const shape = () => page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      rows: document.querySelectorAll('.hprow').length,
      arrows: document.querySelectorAll('.hpmove .hpb').length,
      switches: document.querySelectorAll('.hprow .ectog').length,
      previewPanel: document.querySelectorAll('.hppv').length,
      note: (document.querySelector('#hppvNote') || {}).textContent || null,
    }));

    const block = { width, arrival: await shape() };
    await page.screenshot({ path: `${OUT}/${TAG}-homepage-${width}.png` });

    /* The arrival shot is the top of the screen, and at 390px the Preview card
       is below the fold — so the before/after pair at that width would be two
       identical pictures of the Layouts row, which is evidence about nothing.
       This scrolls to where the card is (or, on a revision that has none, to
       where it would be) so the pair says something. */
    const sections = page.locator('.hpsec-h').last();
    if (await sections.count()) {
      await sections.scrollIntoViewIfNeeded();
      await page.waitForTimeout(400);
      await page.screenshot({ path: `${OUT}/${TAG}-sections-${width}.png` });
      await page.evaluate(() => { const c = document.querySelector('#content'); if (c) c.scrollTop = 0; });
      await page.waitForTimeout(300);
    }

    /* The console scrolls #content rather than the document, so fullPage is the
       viewport here and the panel has to be brought into view before it can be
       photographed. The card is also shot on its own, because a viewport shot
       of a 19,000-line console is a picture of the sidebar. */
    const shotCard = async (name) => {
      const card = page.locator('.hppv');
      if (await card.count()) {
        await card.scrollIntoViewIfNeeded();
        await page.waitForTimeout(500);
        await page.screenshot({ path: `${OUT}/${TAG}-${name}-${width}.png` });
        await card.screenshot({ path: `${OUT}/${TAG}-${name}-card-${width}.png` });
      }
    };

    // Press Preview, if this revision has one.
    if (await page.locator('#hppvGo').count()) {
      await page.click('#hppvGo');
      await page.waitForTimeout(3500);

      block.afterPreview = await shape();
      block.frameWidth = await page.evaluate(() => {
        const f = document.querySelector('.hppv-frame');
        return f && f.contentDocument ? f.contentDocument.documentElement.clientWidth : null;
      });
      block.frameSections = await page.evaluate(() => {
        const f = document.querySelector('.hppv-frame');
        if (!f || !f.contentDocument) return null;
        return Array.from(f.contentDocument.querySelectorAll('.kbb-home > section'))
          .map(s => (s.className || '').replace(/\s+/g, ' ').trim()).slice(0, 6);
      });
      block.frameSandbox = await page.evaluate(() =>
        (document.querySelector('.hppv-frame') || {}).getAttribute
          ? document.querySelector('.hppv-frame').getAttribute('sandbox') : null);

      await shotCard('preview-desktop');

      // Mobile device button: the frame becomes a 390px viewport, unscaled.
      await page.click('[data-hppv-dev="mob"]');
      await page.waitForTimeout(2500);
      block.mobileFrameWidth = await page.evaluate(() => {
        const f = document.querySelector('.hppv-frame');
        return f && f.contentDocument ? f.contentDocument.documentElement.clientWidth : null;
      });
      await shotCard('preview-mobile');

      // Move a row and read the note again: it must stop claiming to be current.
      await page.click('[data-hppv-dev="desk"]');
      await page.waitForTimeout(600);
      const tog = page.locator('.hprow .ectog').nth(6);
      if (await tog.count()) { await tog.click(); await page.waitForTimeout(400); }
      block.noteAfterEdit = await page.evaluate(() =>
        (document.querySelector('#hppvNote') || {}).textContent || null);
      block.overflowAfterAll = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
      }));
      await shotCard('preview-stale');
    }

    block.overflow = block.arrival.scrollWidth > block.arrival.innerWidth;
    report.widths.push(block);
    await ctx.close();
  }
} catch (e) {
  report.error = String(e && e.message ? e.message : e);
} finally {
  await browser.close();
}

console.log(JSON.stringify(report, null, 1));
