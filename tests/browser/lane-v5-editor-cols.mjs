/*
 * Lane V5 — Content → Shoppable video → All clips, the two-column editor
 * measured in a real browser, with a real 31 MB upload going through it.
 *
 * IT REPORTS, IT DOES NOT JUDGE, the same way tests/browser/lane-v4-add-clip.mjs
 * and lane-m-module-screens.mjs do. The claims this lane makes are claims about
 * rendered layout and about a request in flight — how many columns a step panel
 * really has at 390px and at 1280px, whether anything overflows sideways,
 * whether the upload bar's percentage is the upload's own — and the server's
 * output cannot settle any of them.
 *
 * MEASURING HERE IS NOT THE THING RULE 4 FORBIDS. Rule 4 is about SHIPPED
 * JavaScript sizing the page. This file is the instrument that proves the
 * shipped CSS did it without script, which is why every column count below is
 * read out of `getComputedStyle(...).gridTemplateColumns` — the resolved tracks
 * the stylesheet produced — rather than out of anything the screen computed.
 *
 *   KBB_V5_BASE=http://127.0.0.1:8951 \
 *   KBB_V5_EMAIL=v5@example.test KBB_V5_PASSWORD=lane-v5-password \
 *   KBB_V5_CLIP=/tmp/clip-big.mp4 \
 *   KBB_V5_OUT=/tmp/shots KBB_V5_TAG=after \
 *   node tests/browser/lane-v5-editor-cols.mjs
 *
 * KBB_V5_UPLOAD_KBPS throttles the upload so the bar can be sampled in flight;
 * without it a 31 MB body over loopback is gone before the first sample.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_V5_BASE || 'http://127.0.0.1:8951';
const EMAIL = process.env.KBB_V5_EMAIL || 'v5@example.test';
const PASSWORD = process.env.KBB_V5_PASSWORD || 'lane-v5-password';
const OUT = process.env.KBB_V5_OUT || '/tmp';
const TAG = process.env.KBB_V5_TAG || 'run';
const CLIP = process.env.KBB_V5_CLIP || '';
const CHROME = process.env.KBB_V5_CHROME;
const UPLOAD_KBPS = Number(process.env.KBB_V5_UPLOAD_KBPS || 2600);
/* The upload endpoint is throttled to twelve a minute, so re-shooting one width
   after a throttled run beats waiting out the window for both. */
const WIDTHS = (process.env.KBB_V5_WIDTHS || '390,1280').split(',').map(Number);

const report = { tag: TAG, base: BASE, clip: CLIP, widths: [], console: [], error: null };

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

/* Click the first visible button whose text matches. */
async function clickAny(page, rx) {
  const buttons = page.locator('button, a[role=button], label');
  const n = await buttons.count();
  for (let i = 0; i < n; i += 1) {
    const b = buttons.nth(i);
    if (!(await b.isVisible())) continue;
    const text = ((await b.innerText()) || '').replace(/\s+/g, ' ').trim();
    if (rx.test(text)) {
      await b.click();
      return text;
    }
  }
  return null;
}

/* ?go=ugcvideo now works, but the screen's own entry point is what the tab strip
   and the sidebar row call, so it is what this uses — same reasoning as V4. */
async function openLibrary(page) {
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.go('ugcvideo'));
  await page.waitForTimeout(1800);
}

/*
 * THE NUMBERS. Everything here is read off the rendered document: the page's own
 * overflow, the CONTENT column's overflow (which is the real scroller — see
 * AdminMobileOverflowTest), and one entry per section row saying how many grid
 * tracks the stylesheet resolved it to.
 */
async function measure(page) {
  return page.evaluate(() => {
    const content = document.querySelector('#content');

    /* Track count from the RESOLVED template, not from positions: two children
       that happen to wrap onto one line would read as two columns from their
       rectangles and as one from the stylesheet, and the stylesheet is what is
       being claimed. */
    const tracks = (el) => {
      if (!el) return null;
      const t = window.getComputedStyle(el).gridTemplateColumns || '';
      return t.trim() === '' || t === 'none' ? 0 : t.trim().split(/\s+/).length;
    };

    const rows = Array.prototype.map.call(
      document.querySelectorAll('.ugs-panel:not([hidden]) .ugs-cols, .ugs-panel:not([hidden]) .ugs-loopcols'),
      (el) => ({
        cls: el.className,
        columns: tracks(el),
        template: window.getComputedStyle(el).gridTemplateColumns,
        children: el.children.length,
        /* The widest child's right edge against the row's own, which is what a
           sideways scroll actually is. */
        overflowsBy: Math.max(
          0,
          Math.round(
            Math.max(
              ...Array.prototype.map.call(el.children, (c) => c.getBoundingClientRect().right),
            ) - el.getBoundingClientRect().right,
          ),
        ),
      }),
    );

    const sections = Array.prototype.map.call(
      document.querySelectorAll('.ugs-panel:not([hidden]) .ugs-sec'),
      (el) => {
        const head = el.querySelector('.ugs-sech');
        return {
          title: (el.querySelector('.ugs-sect') || {}).textContent || null,
          note: (el.querySelector('.ugs-secw') || {}).textContent || null,
          tone: el.className.replace('ugs-sec', '').trim(),
          headHeight: head ? Math.round(head.getBoundingClientRect().height) : null,
          titleSize: el.querySelector('.ugs-sect')
            ? window.getComputedStyle(el.querySelector('.ugs-sect')).fontSize
            : null,
        };
      },
    );

    return {
      docScrollWidth: document.documentElement.scrollWidth,
      docClientWidth: document.documentElement.clientWidth,
      contentScrollWidth: content ? content.scrollWidth : null,
      contentClientWidth: content ? content.clientWidth : null,
      panelOn: (document.querySelector('.ugs-step.is-on .ugs-steplab') || {}).textContent || null,
      rows,
      sections,
      /* The one thing that must appear exactly once in the DOM. */
      deriveButtons: document.querySelectorAll('[data-ugs-derive]').length,
      cutSection: (() => {
        const heads = Array.prototype.filter.call(
          document.querySelectorAll('.ugs-sec'),
          (s) => /Cut the cover|cover and the teaser|cut on this server/i.test(
            (s.querySelector('.ugs-sect') || {}).textContent || '',
          ),
        );
        if (!heads.length) return null;
        const s = heads[0];
        return {
          title: (s.querySelector('.ugs-sect') || {}).textContent,
          note: (s.querySelector('.ugs-secw') || {}).textContent,
          tone: s.className.replace('ugs-sec', '').trim(),
          hasButton: !!s.querySelector('[data-ugs-derive]'),
          /* Where it sits relative to the two file boxes: a section AFTER them
             is the "natural next thing" claim, measured. */
          topRelativeToFiles: (() => {
            const files = document.querySelector('.ugs-panel:not([hidden]) .ugs-cols');
            if (!files) return null;
            return Math.round(
              s.getBoundingClientRect().top - files.getBoundingClientRect().bottom,
            );
          })(),
        };
      })(),
      upload: (() => {
        const up = document.querySelector('.ugs-up');
        if (!up) return null;
        const bar = up.querySelector('.ugs-progb');
        return {
          name: (up.querySelector('.ugs-upn') || {}).textContent || null,
          percent: (up.querySelector('[data-ugs-pct]') || up.querySelector('.ugs-uph span:last-child') || {})
            .textContent || null,
          sent: (up.querySelector('[data-ugs-sent]') || {}).textContent || null,
          stage: (up.querySelector('[data-ugs-stage]') || {}).textContent || null,
          meta: Array.prototype.map.call(up.querySelectorAll('.ugs-upm > span'), (s) => s.textContent),
          barWidthStyle: bar ? bar.style.width : null,
          bad: up.className.indexOf('is-bad') !== -1,
        };
      })(),
    };
  });
}

/* A full-flow picture: the console scrolls INSIDE #content, so a fullPage shot
   of it shows one viewport and nothing else. Released afterwards. */
async function expand(page, on) {
  await page.evaluate((flag) => {
    const c = document.querySelector('#content');
    if (!c) return;
    if (flag) {
      document.body.style.overflow = 'visible';
      document.documentElement.style.height = 'auto';
      document.body.style.height = 'auto';
      c.style.overflow = 'visible';
      c.style.height = 'auto';
      const app = document.querySelector('.app');
      if (app) { app.style.height = 'auto'; app.style.minHeight = '100%'; }
      document.querySelectorAll('.ugs-foot').forEach((f) => { f.style.position = 'static'; });
    } else {
      document.body.style.overflow = '';
      document.documentElement.style.height = '';
      document.body.style.height = '';
      c.style.overflow = '';
      c.style.height = '';
      const app = document.querySelector('.app');
      if (app) { app.style.height = ''; app.style.minHeight = ''; }
      document.querySelectorAll('.ugs-foot').forEach((f) => { f.style.position = ''; });
    }
  }, on);
  await page.waitForTimeout(220);
}

async function shot(page, name, width) {
  await expand(page, true);
  await page.screenshot({ path: `${OUT}/${TAG}-${name}-${width}.png`, fullPage: true });
  await expand(page, false);
}

try {
  for (const width of WIDTHS) {
    const ctx = await browser.newContext({
      viewport: { width, height: width === 390 ? 844 : 900 },
    });
    const page = await ctx.newPage();
    page.on('console', (m) => {
      if (m.type() === 'error') report.console.push(`${width}: ${m.text()}`);
    });
    page.on('pageerror', (e) => report.console.push(`${width} pageerror: ${e.message}`));
    page.on('dialog', (d) => d.accept());

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    if (await page.locator('input[type=email]').count()) {
      await page.fill('input[type=email]', EMAIL);
      await page.fill('input[type=password]', PASSWORD);
      await page.click('button[type=submit]');
      await page.waitForLoadState('networkidle');
    }

    const block = { width, steps: {}, uploadSamples: [], afterUpload: null, afterCut: null };

    await openLibrary(page);
    await shot(page, 'list', width);

    /* ── step 1, the two-column details panel ─────────────────────────── */
    await clickAny(page, /^Add a clip$/i);
    await page.waitForTimeout(900);
    await page.fill('#ugs-title', `Lane V5 probe ${width}`);
    block.steps.step1 = await measure(page);
    await shot(page, 'step1', width);

    /* ── step 2 before any file exists ───────────────────────────────── */
    await clickAny(page, /^Save and continue$/i);
    await page.waitForTimeout(2600);
    block.steps.step2empty = await measure(page);
    await shot(page, 'step2-empty', width);

    /* ── the upload, throttled so the bar can be watched ─────────────── */
    if (CLIP) {
      const cdp = await ctx.newCDPSession(page);
      await cdp.send('Network.enable');
      await cdp.send('Network.emulateNetworkConditions', {
        offline: false,
        latency: 20,
        downloadThroughput: -1,
        uploadThroughput: UPLOAD_KBPS * 1024,
      });

      await page.setInputFiles('[data-ugs-upload="clip"]', CLIP);

      /* Sampled while the request is in flight. Every figure read here was put
         on screen by paintProgress() out of the upload's own progress event. */
      for (let i = 0; i < 26; i += 1) {
        await page.waitForTimeout(700);
        const now = await page.evaluate(() => {
          const up = document.querySelector('.ugs-up');
          if (!up) return null;
          const bar = up.querySelector('.ugs-progb');
          return {
            percent: (up.querySelector('[data-ugs-pct]') || {}).textContent || null,
            sent: (up.querySelector('[data-ugs-sent]') || {}).textContent || null,
            stage: (up.querySelector('[data-ugs-stage]') || {}).textContent || null,
            barWidthStyle: bar ? bar.style.width : null,
            inFlight: !!up.querySelector('[data-ugs-pct]'),
          };
        });
        if (now) block.uploadSamples.push({ at: (i + 1) * 0.7, ...now });
        if (now && now.inFlight && i === 3) await shot(page, 'upload-in-flight', width);
        if (now && !now.inFlight) break;
        if (!now) break;
      }

      await cdp.send('Network.emulateNetworkConditions', {
        offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1,
      });

      await page.waitForTimeout(2600);
      block.afterUpload = await measure(page);
      await shot(page, 'after-upload', width);

      /* ── and the cut, taken ──────────────────────────────────────── */
      if (await page.locator('[data-ugs-derive]').count()) {
        await page.locator('[data-ugs-derive]').first().click();
        await page.waitForTimeout(4000);
        block.afterCut = await measure(page);
        await shot(page, 'after-cut', width);
      }
    }

    /* ── steps 3, 4 and 5 ────────────────────────────────────────────── */
    for (const n of [3, 4, 5]) {
      const btn = page.locator(`[data-ugs-step="${n}"]`).first();
      if (!(await btn.count())) continue;
      await btn.click();
      await page.waitForTimeout(1500);
      block.steps[`step${n}`] = await measure(page);
      await shot(page, `step${n}`, width);
    }

    /* Take the probe clip back out, so the next width sees the same library. */
    await clickAny(page, /^Delete$/i);
    await page.waitForTimeout(2200);

    report.widths.push(block);
    await ctx.close();
  }
} catch (e) {
  report.error = String(e && e.stack ? e.stack : e);
} finally {
  await browser.close();
}

console.log(JSON.stringify(report, null, 1));
