/*
 * Lane IM2, the admin half: Content → Media Library at a realistic item count,
 * and the product picker beside it.
 *
 * WHY THIS IS MEASURED AT ALL. These screens are behind a login and there is
 * one user, so the bytes are not a shopper's. They are the OWNER'S, every time
 * he opens the screen he is in most — and the media library is a grid of dozens
 * of photographs at full resolution, which is what makes it the slowest screen
 * in the console.
 *
 * WHAT IS COUNTED. Every image response whose path is one of the preview's own
 * photographs, so the console's own icons and the page chrome are not mistaken
 * for catalogue bytes. The tiles' rendered boxes are read too, because the
 * width chosen on the server (400 for a grid tile whose column count is a
 * setting, 200 for a 36px list row) is only defensible against the box it is
 * actually drawn into.
 *
 *   node tools/im2-admin-shots.cjs        (IM2_BASE, IM2_OUT, IM2_TAG override)
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.IM2_BASE || 'http://127.0.0.1:8979';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.IM2_OUT || `${APP}/docs/lane-im2-shots`;
const TAG = process.env.IM2_TAG || 'after';
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const kb = (n) => (n / 1024).toFixed(1) + ' KB';

/** Photographs this preview put on disk — as opposed to the console's chrome. */
const isPhoto = (p) => p.includes('/uploads/im2/') || p.includes('/uploads/reviews/') || p.includes('/img-cache/');

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: EXE });
  const lines = [`# Lane IM2 — the console's own grids (${TAG})`, ''];
  const json = {};

  for (const [width, height] of [[390, 900], [1280, 1000]]) {
    const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type=submit], input[type=submit]'),
    ]);

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);

    const seen = [];
    page.on('response', async (res) => {
      if (!(res.headers()['content-type'] || '').startsWith('image/')) return;
      const p = new URL(res.url()).pathname;
      if (!isPhoto(p)) return;
      try {
        seen.push({ path: p, bytes: (await res.body()).length });
      } catch (e) { /* no body */ }
    });

    await page.evaluate(() => window.go('media'));
    await page.waitForTimeout(2200);
    // The grid is lazy, so everything has to be scrolled through before it is
    // asked for — otherwise this measures the first row and calls it the page.
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 300) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 90));
      }
    });
    await page.waitForTimeout(2500);

    const grid = await page.evaluate(() => {
      const tiles = [...document.querySelectorAll('.mlib-thumb img')];

      return {
        tiles: tiles.length,
        boxes: tiles.slice(0, 3).map((i) => {
          const r = i.getBoundingClientRect();
          return {
            src: i.getAttribute('src'),
            loaded: i.complete && i.naturalWidth > 0,
            natural: [i.naturalWidth, i.naturalHeight],
            box: [Math.round(r.width), Math.round(r.height)],
          };
        }),
        scrollWidth: document.documentElement.scrollWidth,
      };
    });

    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/${TAG}-admin-media-${width}.png`, fullPage: false });

    const photos = seen.slice();
    const total = photos.reduce((a, r) => a + r.bytes, 0);

    json[`media-${width}`] = { requests: photos.length, bytes: total, grid };

    lines.push(`## Content → Media Library @ ${width}px`);
    lines.push('');
    lines.push(`${grid.tiles} tiles drawn, ${photos.length} photograph requests, ${kb(total)} total`);
    lines.push(`scrollWidth ${grid.scrollWidth} (viewport ${width})`);
    lines.push('');
    lines.push('| tile | fetched | intrinsic | box (CSS) |');
    lines.push('|------|---------|-----------|-----------|');
    grid.boxes.forEach((b, i) => {
      lines.push(`| ${i + 1} | ${b.src} | ${b.natural.join('×')} | ${b.box.join('×')} |`);
    });
    lines.push('');
    lines.push(`largest single response: ${kb(Math.max(0, ...photos.map((p) => p.bytes)))}`);
    lines.push('');

    await ctx.close();
  }

  await browser.close();

  lines.push('### what is counted');
  lines.push('');
  lines.push('Only responses under /uploads/im2/, /uploads/reviews/ and /img-cache/ — the');
  lines.push("preview's own photographs. The console's chrome and its inline SVG icons are");
  lines.push('not catalogue bytes and would flatter the after figure if they were counted.');
  lines.push('');

  fs.writeFileSync(`${OUT}/${TAG}-admin.md`, lines.join('\n'));
  fs.writeFileSync(`${OUT}/${TAG}-admin.json`, JSON.stringify(json, null, 2));
  console.log(lines.join('\n'));
})();
