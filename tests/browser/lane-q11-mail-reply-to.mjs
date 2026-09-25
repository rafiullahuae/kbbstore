/*
 * Lane Q11 — Store → Mail, the refusal on screen, measured in a real browser.
 *
 * WHAT IT SHOWS, at 390px and at 1280px, in that order:
 *
 *   before   the Reply-To box holding a working address the shop has saved.
 *   typed    the same box with a malformed address typed into it, Save pressed.
 *            BEFORE this lane the screen answered "Mail settings saved" and the
 *            old address came back on the reload. It now answers a sentence
 *            naming the box, and the stored address is visibly unchanged
 *            underneath it.
 *   after    the reload, with the working address still in the box.
 *
 * Reports document.documentElement.scrollWidth against innerWidth on every
 * capture — the horizontal-overflow check the project notes ask for by name —
 * and the toast text verbatim, because the whole point of the change is what
 * that sentence says.
 *
 *   KBB_Q11_BASE=http://127.0.0.1:8977 \
 *   KBB_Q11_EMAIL=q11@example.com KBB_Q11_PASSWORD=secret-secret \
 *   KBB_Q11_OUT=docs/q11-shots KBB_Q11_TAG=q11-after \
 *   KBB_Q11_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-q11-mail-reply-to.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_Q11_BASE || 'http://127.0.0.1:8977';
const EMAIL = process.env.KBB_Q11_EMAIL;
const PASSWORD = process.env.KBB_Q11_PASSWORD;
const OUT = process.env.KBB_Q11_OUT || '/tmp';
const TAG = process.env.KBB_Q11_TAG || 'run';
const CHROME = process.env.KBB_Q11_CHROME;

const GOOD = 'replies@kbeautybliss.com';
const BAD = 'replies at kbeautybliss.com';

const report = { tag: TAG, base: BASE, good: GOOD, bad: BAD, widths: [], error: null };

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

/* The Mail screen's controls all carry data-mail="<key>". */
const SEL = '[data-mail="mail_reply_to"]';

async function openMail(page) {
  /* Loaded and then dispatched from the page, not ?go=mail — the console wraps
     window.go after boot, and the URL is read before those wrappers exist, so
     ?go=mail quietly draws the Dashboard. Round 2's note, repeated rather than
     rediscovered. */
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.evaluate(() => window.go('mail'));
  await page.waitForTimeout(2400);
}

async function measure(page) {
  return page.evaluate((sel) => {
    const box = document.querySelector(sel);

    return {
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      replyToValue: box ? box.value : null,
      replyToLabel: box && box.closest('.mlf-field')
        ? ((box.closest('.mlf-field').querySelector('.mlf-label') || {}).textContent || null)
        : null,
      /* #toast auto-hides after 2400ms, so the class is reported beside the
         text: a sentence with no `show` is a sentence the owner never saw. */
      toast: (document.querySelector('#toast') || {}).textContent || null,
      toastVisible: !!(document.querySelector('#toast') || { classList: { contains: () => false } })
        .classList.contains('show'),
    };
  }, SEL);
}

try {
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });

    if (await page.locator('input[type=email]').count()) {
      await page.fill('input[type=email]', EMAIL);
      await page.fill('input[type=password]', PASSWORD);
      await page.click('button[type=submit]');
      await page.waitForLoadState('networkidle');
    }

    // 1 · plant a working address and save it the way the owner does
    await openMail(page);
    await page.fill(SEL, GOOD);
    await page.click('#mlSave');
    await page.waitForTimeout(1800);

    await openMail(page);
    const before = await measure(page);
    await page.screenshot({ path: `${OUT}/${TAG}-1-before-${width}.png`, fullPage: true });

    // 2 · mistype it and press Save
    await page.fill(SEL, BAD);
    await page.click('#mlSave');
    await page.waitForTimeout(700);
    await page.screenshot({ path: `${OUT}/${TAG}-2-refused-${width}.png`, fullPage: true });
    const typed = await measure(page);

    // 3 · reload: the working address is still there
    await openMail(page);
    const after = await measure(page);
    await page.screenshot({ path: `${OUT}/${TAG}-3-after-${width}.png`, fullPage: true });

    report.widths.push({
      width,
      before,
      typed,
      after,
      overflow: [before, typed, after].some((m) => m.scrollWidth > m.innerWidth),
    });

    await ctx.close();
  }
} catch (e) {
  report.error = String(e && e.message ? e.message : e);
} finally {
  await browser.close();
}

console.log(JSON.stringify(report, null, 1));
