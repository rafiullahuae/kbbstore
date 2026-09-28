/*
 * Lane IM, the admin half: Content -> Media Library, and the one control the
 * owner has to drive any of this.
 *
 * Nothing on that screen CHANGED. What changed is the number it prints: the
 * backlog is counted off a work list that now includes every gallery shot and
 * every variant photograph, so the screen finally states the real size of the
 * job instead of counting featured images and calling the shop finished. This
 * photographs it with a catalogue whose galleries have not been sized, which is
 * what the owner will see when he applies the package.
 *
 * The screen is ALREADY included by resources/views/admin/app.blade.php (the
 * integrator's file), so nothing is injected here: what is photographed is the
 * real screen.
 *
 *   node tools/im-admin-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.IM_BASE || 'http://127.0.0.1:8977';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.IM_OUT || `${APP}/docs/lane-im-shots`;
const TAG = process.env.IM_TAG || 'after';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1200 }, deviceScaleFactor: 2 });
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
  await page.evaluate(() => window.go('media'));
  await page.waitForTimeout(1600);

  const stats = await page.evaluate(() => ({
    button: document.getElementById('mlib-sizes')?.textContent?.trim() || '(no button)',
    stats: [...document.querySelectorAll('.mlib-stat')].map((s) => s.textContent.trim().replace(/\s+/g, ' ')),
    scrollWidth: document.documentElement.scrollWidth,
  }));

  const api = await page.evaluate(async () => {
    const res = await fetch('/admin-api/media/image-sizes', { headers: { Accept: 'application/json' } });
    return res.json();
  });

  for (const w of [390, 1280]) {
    await page.setViewportSize({ width: w, height: 1200 });
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${OUT}/${TAG}-admin-media-${w}.png` });
  }

  await browser.close();

  const lines = [
    `# Lane IM — Content -> Media Library (${TAG})`,
    '',
    'The only control on this shop that can make copies of photographs that were',
    'already here. Nothing on the screen changed; the number did.',
    '',
    `button: ${stats.button}`,
    ...stats.stats.map((s) => `stat:   ${s}`),
    `scrollWidth 1280: ${stats.scrollWidth}`,
    '',
    '/admin-api/media/image-sizes:',
    `  widths     ${JSON.stringify(api.widths)}`,
    `  total      ${api.total}`,
    `  done       ${api.done}`,
    `  remaining  ${api.remaining}`,
    `  not_ours   ${api.not_ours}`,
    '',
  ];

  fs.writeFileSync(`${OUT}/${TAG}-admin-media.md`, lines.join('\n'));
  console.log(lines.join('\n'));
})();
