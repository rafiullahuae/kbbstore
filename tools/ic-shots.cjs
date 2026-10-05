/*
 * Lane IC — App icon and favicon, photographed and measured at 390 and 1280.
 *
 *   sh tools/mac-preview.sh 10640              # the shop + the owner app (tools/mac-seed.php)
 *   php tools/ic-lotus.php storage/ic-logs     # or any square PNG: IC_ICON / IC_FAV
 *   IC_BASE=http://127.0.0.1:10640 node tools/ic-shots.cjs
 *
 * Writes docs/ic-shots/*.png and docs/ic-shots/MEASUREMENTS.json. Uploads go
 * through the real card (a file chosen in the real <input>, the real Upload
 * button), so the shots are what the owner will see. Rectangles are read by
 * this harness only, never by anything the shop serves.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.IC_BASE || 'http://127.0.0.1:10640';
const ROOT = path.join(__dirname, '..');
const OUT = path.join(ROOT, 'docs', 'ic-shots');
const CHROME = process.env.PW_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const ICON = process.env.IC_ICON || path.join(ROOT, 'storage', 'ic-logs', 'ic-lotus-1050.png');
const FAV = process.env.IC_FAV || path.join(ROOT, 'storage', 'ic-logs', 'ic-lotus-favicon-512.png');
const M = {};
fs.mkdirSync(OUT, { recursive: true });

async function ctxFor(browser, width) {
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 2600 : 1000 }, deviceScaleFactor: width < 600 ? 2 : 1 });
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return { ctx, page };
}

async function open(page, screen) {
  await page.goto(BASE + '/admin?go=' + screen, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-aic]', { timeout: 15000 });
  await page.waitForTimeout(400);
}

async function measure(page, screen) {
  return page.evaluate((screen) => {
    const card = document.querySelector('[data-aic]');
    const r = card.getBoundingClientRect();
    const imgs = [...card.querySelectorAll('.aic-prev img')].map((i) => ({ src: i.getAttribute('src').slice(0, 60), w: Math.round(i.getBoundingClientRect().width), natural: i.naturalWidth }));
    return {
      screen, viewport: window.innerWidth, scrollWidth: document.documentElement.scrollWidth,
      card: { width: Math.round(r.width), height: Math.round(r.height) },
      guideFont: getComputedStyle(card.querySelector('.aic-guide li')).fontSize,
      previews: imgs,
      note: (card.querySelector('.aic-note') || {}).textContent || null,
    };
  }, screen);
}

async function shoot(page, name, screen) {
  const card = await page.$('[data-aic]');
  await card.screenshot({ path: path.join(OUT, name + '.png') });
  M[name] = await measure(page, screen);
}

async function upload(page, kind, file) {
  const inputs = await page.$$('[data-aic] input[type=file]');
  await inputs[kind === 'app' ? 0 : 1].setInputFiles(file);
  await page.waitForSelector('.aic-note', { timeout: 10000 });
  await page.waitForTimeout(300);
}

async function press(page, label) {
  await page.click(`[data-aic] button:has-text("${label}")`);
  await page.waitForSelector('.aic-note.is-ok, .aic-note.is-bad', { timeout: 20000 });
  await page.waitForTimeout(600);
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const wide = await ctxFor(browser, 1280);
  const narrow = await ctxFor(browser, 390);

  // BEFORE: nothing uploaded.
  for (const [v, w] of [[wide, 1280], [narrow, 390]]) {
    for (const s of ['siteapp', 'ownerapp']) {
      await open(v.page, s);
      await shoot(v.page, `${s}-before-${w}`, s);
    }
  }
  // Shop before: no favicon tag.
  const shop = await wide.ctx.newPage();
  await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
  M.shopBefore = await shop.evaluate(() => ({ icons: [...document.querySelectorAll('link[rel=icon]')].length, apple: document.querySelector('link[rel=apple-touch-icon]')?.getAttribute('href'), scrollWidth: document.documentElement.scrollWidth }));

  // Site App: choose the lotus (preview, not saved), then upload it, then a separate favicon.
  await open(wide.page, 'siteapp');
  await upload(wide.page, 'app', ICON);
  await shoot(wide.page, 'siteapp-chosen-1280', 'siteapp');
  await press(wide.page, 'Upload and use');
  await open(wide.page, 'siteapp');
  await shoot(wide.page, 'siteapp-after-1280', 'siteapp');
  await wide.page.screenshot({ path: path.join(OUT, 'siteapp-after-1280-full.png'), fullPage: true });

  // A refusal, through the same card: a 300 px image.
  // (The browser warns before any upload; this is the warning.)
  const small = path.join(ROOT, 'storage', 'ic-logs', 'ic-small-300.png');
  if (!fs.existsSync(small)) fs.copyFileSync(path.join(ROOT, 'resources', 'site-app', 'icons', 'icon-192.png'), small);
  await upload(wide.page, 'app', small);
  await shoot(wide.page, 'siteapp-refused-1280', 'siteapp');

  // Owner App: choose and upload.
  await open(wide.page, 'ownerapp');
  await upload(wide.page, 'app', ICON);
  await press(wide.page, 'Upload and use');
  await shoot(wide.page, 'ownerapp-after-1280', 'ownerapp');

  for (const s of ['siteapp', 'ownerapp']) {
    await open(narrow.page, s);
    await shoot(narrow.page, `${s}-after-390`, s);
    await narrow.page.screenshot({ path: path.join(OUT, `${s}-after-390-full.png`), fullPage: true });
  }

  // Shop after: the tags, each file's pixel size, and the favicon as a tab would draw it.
  await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
  M.shopAfter = await shop.evaluate(async () => {
    const links = [...document.querySelectorAll('link[rel=icon], link[rel=apple-touch-icon]')];
    const out = [];
    for (const l of links) {
      const r = await fetch(l.href);
      const b = await r.blob();
      const bmp = await createImageBitmap(b);
      out.push({ rel: l.rel, sizes: l.getAttribute('sizes'), href: l.getAttribute('href'), bytes: b.size, px: bmp.width + 'x' + bmp.height, type: r.headers.get('content-type'), cache: r.headers.get('cache-control') });
    }
    return { tags: out, scrollWidth: document.documentElement.scrollWidth, head: links.map((l) => l.outerHTML) };
  });
  const tab = M.shopAfter.tags.filter((t) => t.rel === 'icon');
  const html = `<!doctype html><html><body style="margin:0;font:13px system-ui;background:#fff">
  <div style="background:#dfe3ea;padding:8px 10px 0;display:flex;gap:4px">
    <div style="display:flex;align-items:center;gap:8px;height:34px;padding:0 14px;background:#fff;border-radius:10px 10px 0 0;width:220px"><img src="${BASE}${tab[0].href}" width="16" height="16"><span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">K-Beauty Bliss | Korean Skincare</span></div>
    <div style="display:flex;align-items:center;gap:8px;height:34px;padding:0 14px;color:#555;width:160px"><span style="width:16px;height:16px;border-radius:50%;background:#bbb;display:inline-block"></span><span>New Tab</span></div>
  </div>
  <div style="padding:14px 16px;border-top:1px solid #ccc;color:#333">
    <p style="margin:0 0 10px">Favicon as the shop serves it, drawn at the sizes a browser and Google use:</p>
    <div style="display:flex;gap:18px;align-items:flex-end">
      ${[16, 32, 48, 96].map((s) => `<figure style="margin:0;text-align:center"><img src="${BASE}${tab[s <= 32 ? 0 : s === 48 ? 0 : 1].href}" width="${s}" height="${s}"><figcaption>${s}px</figcaption></figure>`).join('')}
      <figure style="margin:0;text-align:center"><div style="display:flex;align-items:center;gap:10px;border:1px solid #ddd;border-radius:10px;padding:8px 12px"><img src="${BASE}${tab[0].href}" width="26" height="26" style="border-radius:50%;border:1px solid #eee"><div><div>K-Beauty Bliss</div><div style="color:#777;font-size:12px">extrabeauty.ae</div></div></div><figcaption>Google result</figcaption></figure>
    </div>
  </div></body></html>`;
  const tp = await wide.ctx.newPage();
  await tp.setViewportSize({ width: 720, height: 200 });
  await tp.setContent(html, { waitUntil: 'networkidle' });
  await tp.screenshot({ path: path.join(OUT, 'shop-tab-favicon.png') });

  // The real shop page at both widths, after (nothing on the page moves; head only).
  for (const [v, w] of [[wide, 1280], [narrow, 390]]) {
    const p = await v.ctx.newPage();
    await p.goto(BASE + '/', { waitUntil: 'networkidle' });
    M['shop-' + w] = await p.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, icons: document.querySelectorAll('link[rel=icon]').length }));
  }

  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS.json'), JSON.stringify(M, null, 2));
  console.log(JSON.stringify(M, null, 1).slice(0, 4000));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
