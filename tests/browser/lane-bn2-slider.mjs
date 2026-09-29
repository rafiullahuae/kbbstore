/**
 * The picture slider, in a real browser — Lane BN2.
 *
 * Shoots one treatment at two widths and, with KBB_BN2_PROBE=1, drives the
 * controls and reports what they did. Everything it measures is measured HERE,
 * in the harness; the shipped slider measures nothing, which is the property
 * SliderBannerTest asserts over the source.
 *
 * Prints one JSON object on stdout and nothing else.
 *
 *   KBB_BN2_BASE    base URL of a running preview
 *   KBB_BN2_CHROME  chromium executable
 *   KBB_BN2_SHOTS   directory for the PNGs
 *   KBB_BN2_STYLE   the treatment the preview database is currently set to
 *   KBB_BN2_PROBE   '1' to also drive the arrows, the bars, autoplay and RTL
 *   KBB_BN2_PATH    '' for English, '/ar' for Arabic
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.KBB_BN2_BASE;
const EXE = process.env.KBB_BN2_CHROME;
const SHOTS = process.env.KBB_BN2_SHOTS || '';
const STYLE = process.env.KBB_BN2_STYLE || 'inset';
const PROBE = process.env.KBB_BN2_PROBE === '1';
const PATHNAME = process.env.KBB_BN2_PATH || '';
const TAG = process.env.KBB_BN2_TAG || (PATHNAME ? 'ar' : 'en');

const out = { ok: false, style: STYLE, lang: TAG, widths: {}, probe: {}, pageErrors: [] };
const done = () => { process.stdout.write(JSON.stringify(out)); process.exit(0); };
const fail = (m) => { out.error = m; done(); };

if (!BASE) fail('KBB_BN2_BASE is not set');
if (SHOTS) mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch(EXE ? { executablePath: EXE } : {});

/** What the slider says about itself, read from the DOM rather than guessed. */
const state = (page) => page.evaluate(() => {
  const root = document.querySelector('.kbbs');
  if (!root) return null;
  const track = root.querySelector('.kbbs-tr');
  const bars = Array.from(root.querySelectorAll('.kbbs-bar'));
  const live = root.querySelector('.kbbs-live');
  const frame = root.querySelector('.kbbs-vp');
  const prev = root.querySelector('.kbbs-prev');
  const next = root.querySelector('.kbbs-next');
  const box = (el) => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };
  };
  return {
    classes: root.className,
    i: Number(track.style.getPropertyValue('--kbbs-i') || 0),
    on: bars.findIndex((b) => b.classList.contains('is-on')),
    current: bars.findIndex((b) => b.getAttribute('aria-current') === 'true'),
    bars: bars.length,
    barBox: box(bars[0]),
    barLine: box(bars[0] ? bars[0].querySelector('.kbbs-line') : null),
    live: live ? live.textContent : null,
    frame: box(frame),
    prev: box(prev),
    next: box(next),
    prevName: prev ? prev.getAttribute('aria-label') : null,
    nextName: next ? next.getAttribute('aria-label') : null,
    barName: bars[0] ? bars[0].getAttribute('aria-label') : null,
    paused: root.classList.contains('is-paused'),
    inert: Array.from(root.querySelectorAll('.kbbs-s')).map((s) => !!s.inert),
    dir: document.documentElement.getAttribute('dir'),
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    dirn: getComputedStyle(root).getPropertyValue('--kbbs-dirn').trim(),
  };
});

for (const width of [390, 1280]) {
  const context = await browser.newContext({ viewport: { width, height: width === 390 ? 780 : 900 }, deviceScaleFactor: 2 });
  const page = await context.newPage();
  page.on('pageerror', (e) => out.pageErrors.push(String(e)));

  await page.goto(BASE + PATHNAME + '/', { waitUntil: 'networkidle' });
  const section = page.locator('.kbbs').first();
  if (await section.count() === 0) fail('no .kbbs on the page at ' + width);
  await page.waitForTimeout(400);

  const s = await state(page);
  out.widths[width] = s;

  if (SHOTS) {
    await section.screenshot({ path: `${SHOTS}/${STYLE}-${TAG}-${width}.png` });
  }

  if (PROBE && width === 1280) {
    const p = {};

    /* THE SLIDER IS BELOW THE FOLD ON THIS SHOP'S HOMEPAGE, so every pointer
       probe below has to bring it into the viewport first. Without this the
       mouse is moved to a y outside the window, nothing is hovered, and the
       hover and swipe cases pass for the wrong reason — which is what the
       first run of this script did. */
    await section.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);

    /* THE ARROWS. A real button, clicked, and the index moves by one — and the
       bar that says which picture is up moves with it, which is the half the
       cards banner's dots could not do. */
    await page.locator('.kbbs-next').click();
    await page.waitForTimeout(700);
    p.afterNext = await state(page);
    if (SHOTS) await section.screenshot({ path: `${SHOTS}/${STYLE}-${TAG}-1280-after-next.png` });

    await page.locator('.kbbs-prev').click();
    await page.waitForTimeout(700);
    p.afterPrev = await state(page);

    /* THE BARS. The fourth bar, tapped, goes to the fourth picture. */
    await page.locator('.kbbs-bar').nth(3).click();
    await page.waitForTimeout(700);
    p.afterBar = await state(page);
    if (SHOTS) await section.screenshot({ path: `${SHOTS}/${STYLE}-${TAG}-1280-after-bar.png` });

    /* KEYBOARD. Focus the next button and drive it with the arrow keys; the
       keys follow what the shopper sees, so ArrowRight is the NEXT picture in
       English and the PREVIOUS one in Arabic. */
    await page.locator('.kbbs-next').focus();
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(700);
    p.afterArrowRight = await state(page);
    await page.keyboard.press('ArrowLeft');
    await page.waitForTimeout(700);
    p.afterArrowLeft = await state(page);

    /* AUTOPLAY, AND THE THREE WAYS TO STOP IT.
       The dwell is 4s in the preview database. Focus is still inside the
       slider from the keyboard run above, so it is paused right now — the
       first thing to prove is that it IS. */
    p.pausedByFocus = (await state(page)).paused;

    /* Blur rather than click something else: a click anywhere on this shop's
       header is a link, and navigating away mid-probe is how the first run of
       this script ended. */
    await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
    await page.mouse.move(2, 2);
    await page.waitForTimeout(300);
    const before = (await state(page)).i;
    await page.waitForTimeout(5200);
    p.autoplay = { before, after: (await state(page)).i };

    /* Hover. The pointer goes onto the picture and stays there for longer than
       a dwell; the index must not move. */
    const frame = await page.locator('.kbbs-vp').boundingBox();
    await page.mouse.move(frame.x + frame.width / 2, frame.y + frame.height / 2);
    await page.waitForTimeout(400);
    const hoverBefore = (await state(page)).i;
    await page.waitForTimeout(5200);
    p.hover = { runningBeforeHover: p.autoplay.before !== p.autoplay.after,
      paused: (await state(page)).paused, before: hoverBefore, after: (await state(page)).i };
    await page.mouse.move(2, 2);

    /* The pause button. */
    if (await page.locator('.kbbs-pp').count()) {
      await page.locator('.kbbs-pp').click();
      p.pauseButton = { label: await page.locator('.kbbs-pp').getAttribute('aria-label') };
      await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
      await page.mouse.move(2, 2);
      await page.waitForTimeout(300);
      const stoppedBefore = (await state(page)).i;
      await page.waitForTimeout(5200);
      p.pauseButton.before = stoppedBefore;
      p.pauseButton.after = (await state(page)).i;
      await page.locator('.kbbs-pp').click();
    }

    /* SWIPE, with a real pointer drag, and it must not carry the link. */
    const url0 = page.url();
    const f2 = await page.locator('.kbbs-vp').boundingBox();
    const swipeBefore = (await state(page)).i;
    await page.mouse.move(f2.x + f2.width * 0.75, f2.y + f2.height / 2);
    await page.mouse.down();
    await page.mouse.move(f2.x + f2.width * 0.25, f2.y + f2.height / 2, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(800);
    p.swipe = { before: swipeBefore, after: (await state(page)).i, navigated: page.url() !== url0 };
    await page.mouse.move(2, 2);

    out.probe = p;
  }

  await context.close();
}

/* ── TOUCH, IN A CONTEXT THAT REALLY HAS IT ────────────────────────────────
   Two gestures on the same element, and the point is that they do DIFFERENT
   things: a sideways drag moves the slider and must not scroll the page, and a
   downward drag scrolls the page and must not move the slider. `touch-action:
   pan-y` is what buys the second one, and a slider that fights vertical scroll
   is the commonest complaint about carousels on phones. */
if (PROBE) {
  const context = await browser.newContext({ viewport: { width: 390, height: 780 }, hasTouch: true, isMobile: true });
  const page = await context.newPage();
  page.on('pageerror', (e) => out.pageErrors.push(String(e)));
  await page.goto(BASE + PATHNAME + '/', { waitUntil: 'networkidle' });
  const root = page.locator('.kbbs').first();
  await root.scrollIntoViewIfNeeded();
  await page.waitForTimeout(400);

  const f = await page.locator('.kbbs-vp').boundingBox();
  const cy = f.y + f.height / 2;
  const i0 = (await state(page)).i;
  const y0 = await page.evaluate(() => window.scrollY);

  const drag = async (fromX, toX, fromY, toY) => {
    await page.touchscreen.tap(1, 1).catch(() => {});
    const client = await page.context().newCDPSession(page);
    const pts = (x, y) => [{ x, y, radiusX: 8, radiusY: 8, force: 1 }];
    await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pts(fromX, fromY) });
    for (let k = 1; k <= 10; k++) {
      await client.send('Input.dispatchTouchEvent', {
        type: 'touchMove',
        touchPoints: pts(fromX + ((toX - fromX) * k) / 10, fromY + ((toY - fromY) * k) / 10),
      });
    }
    await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await client.detach();
  };

  await drag(f.x + f.width * 0.8, f.x + f.width * 0.2, cy, cy);
  await page.waitForTimeout(800);
  out.probe.touchSideways = { before: i0, after: (await state(page)).i, scrollMoved: (await page.evaluate(() => window.scrollY)) !== y0 };

  const i1 = (await state(page)).i;
  const y1 = await page.evaluate(() => window.scrollY);
  await drag(f.x + f.width / 2, f.x + f.width / 2, cy + 90, cy - 90);
  await page.waitForTimeout(800);
  out.probe.touchDown = { before: i1, after: (await state(page)).i, scrolled: (await page.evaluate(() => window.scrollY)) - y1 };

  await context.close();
}

/* REDUCED MOTION IS A SEPARATE CONTEXT, because the emulation has to be in
   place before the script asks matchMedia. */
if (PROBE) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  page.on('pageerror', (e) => out.pageErrors.push(String(e)));
  await page.goto(BASE + PATHNAME + '/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  const before = (await state(page)).i;
  await page.waitForTimeout(5200);
  const after = await state(page);
  out.probe.reducedMotion = { before, after: after.i, paused: after.paused, arrowsStillThere: !!after.next };
  if (SHOTS) await page.locator('.kbbs').first().screenshot({ path: `${SHOTS}/${STYLE}-${TAG}-1280-reduced-motion.png` });
  await context.close();
}

await browser.close();
out.ok = true;
done();
