/*
 * Lane QB — the share sheet, photographed and measured at 390 and 1280 in
 * Chromium, plus the admin Share tab and the og tags.
 *
 *   sh tools/qb-preview.sh 9900                # boots the fixture (tools/qb-seed.php)
 *   QB_BASE=http://127.0.0.1:9900 node tools/qb-shots.cjs
 *
 * Writes docs/qb-shots/*.png and docs/qb-shots/MEASUREMENTS.json.
 *
 * ▲ THE SHARE ICON BESIDE THE TITLE IS LANE QA'S, not this lane's, so until QA
 *   merges this harness INJECTS a stand-in `<button data-share-open>` next to
 *   the title — the same attributes QA's contract names — and drives the real
 *   sheet with it. The sheet listens on the document, so it cannot tell the
 *   difference. Nothing the shop serves is changed by this.
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, NEVER BY A SCRIPT THE SHOP
 *   SERVES (CLAUDE.md rule 4).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.QB_BASE || 'http://127.0.0.1:9900';
const OUT = path.join(__dirname, '..', 'docs', 'qb-shots');
const CHROME = process.env.QB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const SLUG = 'qb-heartleaf-toner';
const M = {};

fs.mkdirSync(OUT, { recursive: true });

async function login(ctx) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

async function injectButton(page) {
  await page.evaluate(() => {
    const h1 = document.querySelector('#bbTitle');
    if (!h1 || document.querySelector('[data-share-open]')) return;
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'pdp-share-btn';
    b.setAttribute('data-share-open', '');
    b.setAttribute('aria-haspopup', 'dialog');
    b.setAttribute('aria-controls', 'pdpShareSheet');
    b.setAttribute('aria-label', 'Share this product');
    b.style.cssText = 'float:right;margin:2px 0 6px 10px;width:36px;height:36px;border:0;border-radius:50%;background:#F2F2F2;color:#222;display:grid;place-items:center;cursor:pointer';
    b.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8.3 10.9l7.4-4.3M8.3 13.1l7.4 4.3"/><circle cx="6" cy="12" r="2.7" fill="currentColor"/><circle cx="18" cy="5.3" r="2.7" fill="currentColor"/><circle cx="18" cy="18.7" r="2.7" fill="currentColor"/></svg>';
    h1.prepend(b);
  });
}

async function measure(page) {
  return page.evaluate(() => {
    const r = (el) => (el ? el.getBoundingClientRect() : null);
    const q = (s) => document.querySelector(s);
    const panel = q('.pdp-share-panel');
    const tiles = [...document.querySelectorAll('.pdp-share-grid > li:not([hidden]) .pdp-share-ico')];
    const rows = new Set(tiles.map((t) => Math.round(r(t).top)));
    const name = q('.pdp-share-name');
    const cs = (el) => (el ? getComputedStyle(el) : null);
    return {
      viewport: window.innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      sheetHidden: q('#pdpShareSheet').hidden,
      panel: panel ? { width: Math.round(r(panel).width), height: Math.round(r(panel).height), top: Math.round(r(panel).top), bottomGap: Math.round(window.innerHeight - r(panel).bottom) } : null,
      heading: { text: q('.pdp-share-h').textContent, fontSize: cs(q('.pdp-share-h')).fontSize, fontWeight: cs(q('.pdp-share-h')).fontWeight },
      picture: { w: Math.round(r(q('.pdp-share-pic')).width), h: Math.round(r(q('.pdp-share-pic')).height), src: (q('.pdp-share-pic img') || {}).currentSrc || null },
      title: { fontSize: cs(name).fontSize, height: Math.round(r(name).height), lineHeight: cs(name).lineHeight, clamp: cs(name).webkitLineClamp },
      tiles: { count: tiles.length, icon: tiles[0] ? Math.round(r(tiles[0]).width) : 0, rows: rows.size, perRow: tiles.filter((t) => Math.round(r(t).top) === Math.round(r(tiles[0]).top)).length,
        labelSize: cs(q('.pdp-share-lbl')).fontSize, labels: [...document.querySelectorAll('.pdp-share-grid > li:not([hidden]) .pdp-share-lbl')].map((l) => l.textContent) },
      htmlLocked: document.documentElement.classList.contains('pdp-share-lock'),
      focused: document.activeElement ? (document.activeElement.className || document.activeElement.tagName) : null,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });

  /* ── the og tags, before (seeded on a cold cache) and after the first view ── */
  const og = (html) => (html.match(/<meta (property|name)="(og:image[^"]*|twitter:image)" content="[^"]*">/g) || []).join('\n');
  const first = await (await fetch(`${BASE}/product/${SLUG}/`)).text();
  await new Promise((r) => setTimeout(r, 1500));
  const second = await (await fetch(`${BASE}/product/${SLUG}/`)).text();
  M.og = { firstView: og(first), afterFirstView: og(second) };
  const shareUrl = (second.match(/<meta property="og:image" content="([^"]+)"/) || [])[1];
  if (shareUrl) {
    const res = await fetch(shareUrl);
    const buf = Buffer.from(await res.arrayBuffer());
    fs.writeFileSync(path.join(OUT, 'share-image-1200x630.jpg'), buf);
    M.shareImage = { url: shareUrl, contentType: res.headers.get('content-type'), bytes: buf.length };
  }

  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2,
      hasTouch: w === 390, isMobile: w === 390, permissions: ['clipboard-read', 'clipboard-write'] });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
    M[w] = {};
    M[w].shareRowGone = await page.evaluate(() => !document.querySelector('.pts-share, .pts-share-list'));
    M[w].sheetCount = await page.evaluate(() => document.querySelectorAll('#pdpShareSheet').length);
    await injectButton(page);

    // The buy column without the row: the authenticity line is the last block.
    const stack = page.locator('.pts-stack');
    if (await stack.count()) await stack.screenshot({ path: path.join(OUT, `buy-column-no-row-${w}.png`) });
    await page.locator('#bbTitle').screenshot({ path: path.join(OUT, `title-with-stand-in-icon-${w}.png`) });

    // Opening, mid-slide.
    await page.click('[data-share-open]');
    await page.waitForTimeout(w === 390 ? 60 : 50);
    await page.screenshot({ path: path.join(OUT, `sheet-opening-${w}.png`), animations: 'allow' });
    await page.waitForTimeout(700);
    await page.screenshot({ path: path.join(OUT, `sheet-open-${w}.png`) });
    M[w].open = await measure(page);

    // Copy → toast.
    await page.click('[data-net="copy"]');
    await page.waitForTimeout(260);
    await page.screenshot({ path: path.join(OUT, `sheet-link-copied-${w}.png`) });
    M[w].toast = await page.evaluate(() => document.querySelector('.pdp-share-toast').textContent);
    M[w].clipboard = await page.evaluate(() => navigator.clipboard.readText().catch(() => null));

    // Escape closes and focus goes back to the button.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(450);
    M[w].afterEscape = await page.evaluate(() => ({ hidden: document.querySelector('#pdpShareSheet').hidden,
      focusIsButton: document.activeElement && document.activeElement.hasAttribute('data-share-open'),
      locked: document.documentElement.classList.contains('pdp-share-lock') }));

    // Backdrop closes.
    await page.click('[data-share-open]');
    await page.waitForTimeout(500);
    await page.mouse.click(w / 2, 40);
    await page.waitForTimeout(450);
    M[w].afterBackdrop = await page.evaluate(() => document.querySelector('#pdpShareSheet').hidden);

    // Back (history) closes, and leaves the page where it was.
    await page.click('[data-share-open]');
    await page.waitForTimeout(500);
    await page.goBack();
    await page.waitForTimeout(450);
    M[w].afterBack = await page.evaluate(() => ({ hidden: document.querySelector('#pdpShareSheet').hidden, path: location.pathname }));

    // × closes.
    await page.click('[data-share-open]');
    await page.waitForTimeout(500);
    await page.click('.pdp-share-x');
    await page.waitForTimeout(450);
    M[w].afterX = await page.evaluate(() => document.querySelector('#pdpShareSheet').hidden);

    // What a phone does with the app tiles: emulate a coarse pointer and read
    // the swapped hrefs.
    M[w].hrefs = await page.evaluate(() => Object.fromEntries([...document.querySelectorAll('.pdp-share-tile[data-net]')].map((a) => [a.dataset.net, a.getAttribute('href') || a.getAttribute('data-share-copy') || null])));
    await ctx.close();
  }

  /* ── the More button on a device that has navigator.share (emulated) ── */
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    await ctx.addInitScript(() => {
      window.__shared = null;
      navigator.share = (d) => { window.__shared = { files: (d.files || []).map((f) => ({ name: f.name, type: f.type, size: f.size })), title: d.title, text: d.text, url: d.url || null }; return Promise.resolve(); };
      navigator.canShare = (d) => !!d;
    });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
    await injectButton(page);
    await page.click('[data-share-open]');
    await page.waitForTimeout(900);
    await page.screenshot({ path: path.join(OUT, `sheet-open-with-more-390.png`) });
    M.more = { visible: await page.isVisible('[data-share-native]') };
    M.more.appHrefs = await page.evaluate(() => ({ messenger: document.querySelector('[data-net="messenger"]').getAttribute('href').slice(0, 40), snapchat: document.querySelector('[data-net="snapchat"]').getAttribute('href').slice(0, 50) }));
    await page.click('[data-share-native]');
    await page.waitForTimeout(300);
    M.more.shared = await page.evaluate(() => window.__shared);
    await ctx.close();
  }

  /* ── reduced motion: no slide, opens at once ── */
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
    await injectButton(page);
    await page.click('[data-share-open]');
    await page.waitForTimeout(60);
    M.reducedMotion = await page.evaluate(() => getComputedStyle(document.querySelector('.pdp-share-panel')).transitionDuration);
    await ctx.close();
  }

  /* ── the admin Share tab ── */
  for (const w of [390, 1280]) {
    const actx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 }, deviceScaleFactor: 1 });
    const admin = await login(actx);
    await admin.goto(`${BASE}/admin?go=productpage`, { waitUntil: "load", timeout: 60000 });
    await admin.waitForSelector('[data-ptstab="ts_share"]', { timeout: 60000 });
    await admin.click('[data-ptstab="ts_share"]');
    await admin.waitForTimeout(600);
    await admin.screenshot({ path: path.join(OUT, `admin-share-tab-${w}.png`), fullPage: true });
    if (w === 1280) {
      M.adminTabs = await admin.evaluate(() => [...document.querySelectorAll('[data-ptstab]')].map((b) => b.textContent));
      // Move Snapchat up one: the list answers before Save.
      await admin.click('[data-pts-move="-1"][data-k="snapchat"]');
      await admin.waitForTimeout(200);
      M.adminOrderAfterMove = await admin.evaluate(() => [...document.querySelectorAll('#ptsOrder li')].map((l) => l.dataset.k).join(','));
      await admin.locator('#ptsOrder').screenshot({ path: path.join(OUT, `admin-share-order-moved-${w}.png`) });
    }
    await actx.close();
  }

  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS.json'), JSON.stringify(M, null, 2));
  console.log(JSON.stringify(M, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
