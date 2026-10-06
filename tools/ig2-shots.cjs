/*
 * Lane SG (IG2) screenshots and measurements of /kbeautybliss-spotted/.
 *
 *   IG2_CARD=a sh tools/ig2-preview.sh 10470 after
 *   node tools/ig2-shots.cjs <port> <mode> [label]
 *
 * modes: cards (A–D at 390 and 1280), modal (the video player), ar (Arabic 390),
 *        admin (the picker at 1280 and 390), page <prefix> (one page, both widths).
 * Every number is read in the browser from the finished page; the shop itself
 * measures nothing. instagram.com is unreachable from this machine, so the
 * player's frame is answered with a labelled stand-in page — the dialog, its
 * size and its focus are real, the video inside it is not.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const APP = path.resolve(__dirname, '..');
const PORT = process.argv[2] || '10470';
const MODE = process.argv[3] || 'cards';
const LABEL = process.argv[4] || 'after';
const BASE = 'http://127.0.0.1:' + PORT;
const OUT = path.join(APP, 'docs/lane-ig2-shots');
const DIR = path.join(APP, 'storage/framework/testing/ig2-preview-' + LABEL);
fs.mkdirSync(OUT, { recursive: true });

function php(code) {
  execFileSync('php', [path.join(APP, 'artisan'), 'tinker', '--execute=' + code], {
    env: Object.assign({}, process.env, {
      APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: DIR + '/preview.sqlite',
      CACHE_STORE: 'file', SESSION_DRIVER: 'file', KBB_PUBLIC_PATH: DIR + '/webroot',
      APP_CONFIG_CACHE: DIR + '/compiled/config.php', APP_ROUTES_CACHE: DIR + '/compiled/routes.php',
      APP_SERVICES_CACHE: DIR + '/compiled/services.php', APP_PACKAGES_CACHE: DIR + '/compiled/packages.php',
      VIEW_COMPILED_PATH: DIR + '/compiled',
    }),
    stdio: 'pipe',
  });
}

async function measure(page) {
  return page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.sig-card'));
    const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height) }; };
    const img = document.querySelector('.sig-ph img');
    return {
      innerWidth: window.innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      cards: cards.length,
      firstCard: r(cards[0]),
      firstCover: r(document.querySelector('.sig-ph')),
      videoCard: r(document.querySelector('.sig-card[data-sig-embed]')),
      captionFont: img ? getComputedStyle(document.querySelector('.sig-cap')).fontSize : null,
      captionLines: (() => { const c = document.querySelector('.sig-cap'); if (!c) return null; const s = getComputedStyle(c); return Math.round(c.getBoundingClientRect().height / parseFloat(s.lineHeight)); })(),
      imgCurrentSrc: img ? img.currentSrc.replace(location.origin, '') : null,
      imgSrcset: img ? img.getAttribute('srcset') : null,
      lazyImgs: document.querySelectorAll('.sig-ph img[loading=lazy]').length,
      iframesBeforeTap: document.querySelectorAll('iframe[src*="instagram.com"]').length,
    };
  });
}

async function shootPage(browser, url, name, widths, before) {
  const out = {};
  for (const w of widths) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: w < 600 ? 2 : 1 });
    const page = await ctx.newPage();
    await page.route(/instagram\.com/, (r) => r.abort());
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    await page.goto(BASE + url, { waitUntil: 'networkidle' }); // the second render offers the srcset copies
    // Walk the page so every lazy picture below the fold has loaded before a
    // full-page shot (the shop's lazy loading is real; the shot must not show holes).
    await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
    await page.waitForLoadState('networkidle');
    if (before) await before(page);
    await page.waitForTimeout(300);
    out[w] = await measure(page);
    await page.screenshot({ path: path.join(OUT, name + '-' + w + '.png'), fullPage: true });
    const grid = await page.$('.sig-grid, .spt-grid');
    if (grid) {
      // The grid crop only: the sticky header and the floating chat button would
      // otherwise be painted over the cards as the crop scrolls. The instrument
      // hides them; the shop is not changed.
      await page.evaluate(() => document.querySelectorAll('body *').forEach((el) => { const p = getComputedStyle(el).position; if (p === 'fixed' || p === 'sticky') el.style.visibility = 'hidden'; }));
      await grid.screenshot({ path: path.join(OUT, name + '-' + w + '-grid.png') });
    }
    await ctx.close();
  }
  return out;
}

(async () => {
  const browser = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium-1194/chrome-linux/chrome') ? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' : undefined });
  const results = {};

  if (MODE === 'cards') {
    for (const s of ['a', 'b', 'c', 'd']) {
      php("app(App\\Services\\SpottedSettings::class)->save(['page_card' => '" + s + "']);");
      results['card-' + s.toUpperCase()] = await shootPage(browser, '/kbeautybliss-spotted/', 'card-' + s.toUpperCase(), [390, 1280]);
    }
    php("app(App\\Services\\SpottedSettings::class)->save(['page_card' => 'a']);");
  } else if (MODE === 'sheet') {
    const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
    const page = await ctx.newPage();
    const names = { A: 'Instagram native', B: 'Overlay', C: 'Soft pink frame', D: 'Reel-first' };
    const img = (f) => 'data:image/png;base64,' + fs.readFileSync(path.join(OUT, f)).toString('base64');
    let html = '<!doctype html><meta charset=utf-8><body style="margin:0;padding:24px;font:15px system-ui;background:#fff5f8;color:#2a2228"><h1 style="margin:0 0 6px;font-size:24px">#KBeautyBliss Spotted — Instagram card styles</h1><p style="margin:0 0 18px;color:#6b5f66">Same synced posts in every style; nothing typed by hand. Laptop (1280) on the left, phone (390) on the right. Admin: Appearance → #KBeautyBliss Spotted → Settings → Spotted page → Instagram card style.</p>';
    for (const s of ['A', 'B', 'C', 'D']) {
      html += '<section style="display:grid;grid-template-columns:3fr 1fr;gap:18px;align-items:start;margin-bottom:28px"><div><h2 style="margin:0 0 8px;font-size:18px">' + s + ' — ' + names[s] + '</h2><img style="width:100%;border-radius:10px;box-shadow:0 6px 20px -12px #a0406a" src="' + img('card-' + s + '-1280-grid.png') + '"></div><div><h2 style="margin:0 0 8px;font-size:18px">&nbsp;</h2><img style="width:100%;max-height:1100px;object-fit:cover;object-position:top;border-radius:10px;box-shadow:0 6px 20px -12px #a0406a" src="' + img('card-' + s + '-390-grid.png') + '"></div></section>';
    }
    await page.setContent(html, { waitUntil: 'load' });
    await page.screenshot({ path: path.join(OUT, 'card-styles-sheet.png'), fullPage: true });
    await ctx.close();
  } else if (MODE === 'page') {
    const prefix = process.argv[5] || 'page';
    results[prefix] = await shootPage(browser, '/kbeautybliss-spotted/', prefix, [390, 1280]);
  } else if (MODE === 'ar') {
    results['arabic'] = await shootPage(browser, '/ar/kbeautybliss-spotted/', 'arabic', [390]);
  } else if (MODE === 'modal') {
    for (const w of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: w < 600 ? 2 : 1 });
      const page = await ctx.newPage();
      const asked = [];
      await page.route(/instagram\.com/, (r) => {
        asked.push(r.request().url());
        r.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><meta charset=utf-8><body style="margin:0;display:grid;place-items:center;height:100vh;font:15px system-ui;background:#fafafa;color:#555;text-align:center;padding:20px">Instagram’s own player loads here.<br><br><small>(Stand-in: this machine has no route to instagram.com. Requested:<br>' + r.request().url() + ')</small></body>' });
      });
      await page.goto(BASE + '/kbeautybliss-spotted/', { waitUntil: 'networkidle' });
      const before = asked.length;
      await page.click('.sig-card[data-sig-embed]');
      await page.waitForTimeout(600);
      const m = await page.evaluate(() => {
        const box = document.getElementById('sigm');
        const f = box.querySelector('iframe');
        const b = f.getBoundingClientRect();
        return { open: !box.hidden, iframeSrc: f.getAttribute('src'), frame: { w: Math.round(b.width), h: Math.round(b.height) }, focused: document.activeElement.className, scrollWidth: document.documentElement.scrollWidth, innerWidth: innerWidth };
      });
      await page.screenshot({ path: path.join(OUT, 'video-modal-' + w + '.png') });
      await page.keyboard.press('Escape');
      await page.waitForTimeout(200);
      const after = await page.evaluate(() => ({ hidden: document.getElementById('sigm').hidden, iframes: document.querySelectorAll('#sigm iframe').length, focusBackOnCard: !!document.activeElement.closest('.sig-card') }));
      results['modal-' + w] = { requestsToInstagramBeforeTap: before, ...m, afterEsc: after };
      await ctx.close();
    }
  } else if (MODE === 'admin') {
    for (const w of [1280, 390]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: w < 600 ? 2 : 1 });
      const page = await ctx.newPage();
      await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' }).catch(() => {});
      const adminPath = process.env.IG2_ADMIN || '/admin';
      await page.goto(BASE + adminPath, { waitUntil: 'networkidle' });
      if (await page.$('input[type=password]')) {
        await page.fill('input[type=email], input[name=email]', 'owner@preview.test');
        await page.fill('input[type=password]', 'preview-secret-1');
        await Promise.all([page.waitForLoadState('networkidle'), page.keyboard.press('Enter')]);
        await page.waitForTimeout(800);
      }
      await page.evaluate(() => window.go('spotted'));
      await page.waitForSelector('[data-spa-igcard] .spa-igt', { timeout: 15000 });
      await page.waitForTimeout(800);
      results['admin-' + w] = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, innerWidth: innerWidth, tiles: document.querySelectorAll('.spa-igt').length, line: (document.querySelector('[data-spa-igline]') || {}).textContent }));
      const card = await page.$('[data-spa-igcard]');
      await card.screenshot({ path: path.join(OUT, 'admin-picker-' + w + '.png') });
      await ctx.close();
    }
  }
  await browser.close();
  const file = path.join(OUT, 'measurements-' + MODE + (MODE === 'page' ? '-' + (process.argv[5] || 'page') : '') + '.json');
  fs.writeFileSync(file, JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
