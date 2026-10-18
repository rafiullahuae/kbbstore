/*
 * Lane BT -- phones set to large text. Against a tools/arr-preview.sh server
 * (preview DB only).
 *
 *   MODE=normal TAG=before BASE=http://127.0.0.1:<port> node tools/bt-shots.cjs
 *     Every page type, English and Arabic, at 390 and 1280, full page, plain
 *     Chromium: the NORMAL visitor. Run it for `before`, `control` (before
 *     again, which proves the pages are deterministic) and `after`, then
 *     python3 tools/bt-diff.py compares them pixel by pixel.
 *
 *   MODE=zoom TAG=before ZOOMS=1,1.3,1.6,2 BASE=... node tools/bt-shots.cjs
 *     The ENLARGED visitor, emulated faithfully: Chromium 141 with every
 *     renderer under gdb (tools/bt/renderer-gdb.sh), which calls the real
 *     blink::LocalFrame::SetTextZoomFactor(Z) on each document -- the call
 *     Android WebView makes for a phone whose font size is Z x normal.
 *     360 / 390 / 412, mobile, a WebView user agent. First screen, measured:
 *     scrollWidth, <html> class, header geometry, the font sizes the owner's
 *     screenshot showed huge, CLS, console errors, and elementFromPoint over
 *     the header icons, the menu, the search box, Add to cart and checkout's
 *     Place order.
 *
 * Out: storage/bt-logs/shots/<mode>/<tag>/... (bulk, not committed) and
 * <name>.json beside them; the pictures worth keeping are copied into
 * docs/lane-bt/ by hand.
 */
const fs = require('fs');
const path = require('path');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }

const BASE = process.env.BASE || 'http://127.0.0.1:9732';
const MODE = process.env.MODE || 'normal';
const TAG = process.env.TAG || 'before';
const OUT = path.join(__dirname, '..', 'storage', 'bt-logs', 'shots', MODE, TAG);
const CHROME = process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const PDP = '/product/azelaic-10-hyaluron-redness-soothing-pad/';
const PAGES = [
  ['home', '/'], ['category', '/collections/toners/'], ['shop', '/shop/'], ['brand', '/brands/anua/'],
  ['product', PDP], ['blog', '/blog/'], ['article', '/blog/how-to-layer-toner-pads/'], ['cart', '/cart/'], ['checkout', '/checkout/'],
];
const UA_M = 'Mozilla/5.0 (Linux; Android 14; SM-A146B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0.7390.37 Mobile Safari/537.36 Instagram 350.0.0.0.0';
const UA_D = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const sel = (v) => (process.env.ONLY ? process.env.ONLY.split(',').includes(v) : true);

async function basket(page, lang) {
  // networkidle first: the page's own cart script must have settled its cookie,
  // or two first requests race and the basket differs between runs.
  await page.goto(BASE + (lang === 'ar' ? '/ar' : '') + PDP, { waitUntil: 'networkidle' });
  const res = await page.evaluate(async () => {
    const out = [];
    for (const id of [1, 3]) {
      const r = await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
      const j = await r.json().catch(() => ({}));
      out.push(r.status + ':' + JSON.stringify(j).slice(0, 80));
    }
    return out.join(' | ');
  });
  console.log('basket', lang, res);
}

/* Same pixels every run: every lazy picture loaded (a missing preview photo
   then always shows the same broken-image mark), and the one line drawn from
   the server's clock -- the product page's dispatch countdown, "Order within
   17h 59m" -- masked by name. */
async function settle(page) {
  await page.evaluate(async () => {
    for (const i of document.querySelectorAll('img[loading=lazy]')) i.loading = 'eager';
    await Promise.all([...document.images].map((i) => (i.complete ? null : new Promise((r) => { i.onload = i.onerror = r; setTimeout(r, 4000); }))));
  });
  await page.waitForTimeout(300);
  return [page.locator('#cutoff').locator('xpath=..')];
}

/* CLS from the page's own layout-shift entries, read by the harness only. */
const CLS_INIT = () => {
  window.__btCls = 0;
  try {
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__btCls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  } catch (e) {}
};

async function measure(page) {
  return page.evaluate(() => {
    const q = (s) => document.querySelector(s);
    const fs = (s) => { const e = q(s); return e ? getComputedStyle(e).fontSize : null; };
    const box = (s) => { const e = q(s); if (!e) return null; const r = e.getBoundingClientRect(); return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)]; };
    const lines = (s) => { const e = q(s); if (!e) return null; const r = document.createRange(); r.selectNodeContents(e); return new Set([...r.getClientRects()].map((x) => Math.round(x.top))).size; };
    return {
      htmlClass: document.documentElement.className,
      rootFont: getComputedStyle(document.documentElement).fontSize,
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: innerWidth,
      cls: Math.round((window.__btCls || 0) * 10000) / 10000,
      header: box('header .hin'), logo: box('header .logo.lgx'), icon: box('header .lgx-i'), search: box('header .sbox'),
      wordmarkFont: fs('.lgx-w'), taglineFont: fs('.lgx-g'), taglineLines: lines('.lgx-g'),
      searchFont: fs('header .sbox input'), h1Font: fs('h1'), badgeFont: fs('.kbb-badge, .pbadge, .disc, [class*="badge"]'),
      bodyFont: fs('main p, .pdesc, p'),
    };
  });
}

/* elementFromPoint at the centre of each control: the control (or a child) must be what a tap lands on. */
async function clicks(page, name) {
  const targets = ['#burger', 'header .sbox input[type=search]', 'header a[aria-label=Account]', 'header a[aria-label=Cart]', 'header a.logo'];
  if (name === 'product') targets.push('#mainAdd');
  if (name === 'checkout') targets.push('button.place[data-place]');
  const out = {};
  for (const s of targets) {
    const loc = page.locator(s).filter({ visible: true }).first();
    if (!(await loc.count())) { out[s] = 'absent'; continue; }
    if (!(await loc.isVisible())) { out[s] = 'hidden'; continue; }
    await loc.scrollIntoViewIfNeeded();
    out[s] = await loc.evaluate((el) => {
      const r = el.getBoundingClientRect(); const x = r.x + r.width / 2, y = r.y + r.height / 2;
      const hit = document.elementFromPoint(x, y);
      return hit && (el === hit || el.contains(hit)) ? 'ok' : 'COVERED by ' + (hit ? hit.tagName + '.' + hit.className : 'nothing');
    });
  }
  // A real tap on the menu button opens the menu, and its first link answers a tap.
  if (out['#burger'] === 'ok') {
    await page.evaluate(() => scrollTo(0, 0));
    await page.locator('#burger').click();
    await page.waitForTimeout(500);
    out.menuOpens = await page.evaluate(() => {
      const m = document.getElementById('mmenu');
      const a = m && [...m.querySelectorAll('a[href]')].find((x) => { const r = x.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.top >= 0 && r.bottom <= innerHeight; });
      if (!a) return 'no visible link';
      const r = a.getBoundingClientRect(); const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
      return hit && (a === hit || a.contains(hit)) ? 'ok' : 'COVERED by ' + (hit ? hit.tagName + '.' + hit.className : 'nothing');
    });
    await page.keyboard.press('Escape');
  }
  await page.evaluate(() => scrollTo(0, 0));
  return out;
}

/* Every element's whole computed style, hashed: equal before and after means
   the page is styled identically, element for element (the guard's own
   <script> excepted, which renders nothing). */
async function styleHash(page) {
  return page.evaluate(async () => {
    const parts = [];
    for (const el of document.querySelectorAll('*')) {
      if (el.tagName === 'SCRIPT' && el.getAttribute('style') === 'font-size:16px') continue;
      const cs = getComputedStyle(el); let s = el.tagName;
      for (let i = 0; i < cs.length; i++) s += ';' + cs[i] + ':' + cs.getPropertyValue(cs[i]);
      parts.push(s);
    }
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(parts.join('\n')));
    return { elements: parts.length, sha256: [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('').slice(0, 16) };
  });
}

async function launch(z) {
  const args = [];
  const opts = { executablePath: CHROME, timeout: 240000 };
  if (z !== null) {
    args.push('--renderer-cmd-prefix=' + path.join(__dirname, 'bt', 'renderer-gdb.sh'), '--no-zygote', '--no-sandbox');
    opts.env = { ...process.env, BT_TEXT_ZOOM: String(z) };
  }
  opts.args = args;
  return pw.chromium.launch(opts);
}

async function run(browser, dir, widths, langs, full, z) {
  fs.mkdirSync(dir, { recursive: true });
  const report = {};
  for (const lang of langs) {
    for (const w of widths) {
      const mobile = w < 800;
      const ctx = await browser.newContext({
        viewport: { width: w, height: mobile ? 844 : 900 }, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 1,
        userAgent: mobile ? UA_M : UA_D, reducedMotion: 'reduce', locale: 'en-GB', timezoneId: 'Asia/Dubai',
      });
      await ctx.addInitScript(CLS_INIT);
      const page = await ctx.newPage();
      page.setDefaultTimeout(240000);
      await basket(page, lang);
      for (const [name, url] of PAGES) {
        if (!sel(name)) continue;
        const errors = [];
        // A missing preview photo (404) is the preview's, not the page's: counted apart.
        const onErr = (m) => { if (m.type() === 'error') errors.push(m.text().startsWith('Failed to load resource') ? '404' : m.text()); };
        const onPe = (e) => errors.push(String(e));
        page.on('console', onErr); page.on('pageerror', onPe);
        await page.goto(BASE + (lang === 'ar' ? '/ar' : '') + url, { waitUntil: 'load' });
        await page.waitForTimeout(700);
        const key = `${name}-${lang}-${w}`;
        const m = await measure(page);
        if (process.env.STYLES === '1') m.styles = await styleHash(page);
        const mask = await settle(page);
        if (process.env.NOSHOT !== '1') await page.screenshot({ path: path.join(dir, key + '.png'), fullPage: full, animations: 'disabled', caret: 'hide', mask, maskColor: '#00FF00' });
        if (z !== null) m.clicks = await clicks(page, name);
        m.missingPhotos = errors.filter((e) => e === '404').length;
        m.errors = errors.filter((e) => e !== '404');
        page.off('console', onErr); page.off('pageerror', onPe);
        report[key] = m;
        console.log(key, JSON.stringify(m));
      }
      await ctx.close();
    }
  }
  return report;
}

(async () => {
  if (MODE === 'normal') {
    const browser = await launch(null);
    const rep = await run(browser, OUT, [390, 1280], ['en', 'ar'], true, null);
    fs.writeFileSync(path.join(OUT, 'measure.json'), JSON.stringify(rep, null, 1));
    await browser.close();
    return;
  }
  for (const z of (process.env.ZOOMS || '1,1.3,1.6,2').split(',').map(Number)) {
    const browser = await launch(z);
    const dir = path.join(OUT, 'z' + z);
    const widths = (process.env.WIDTHS || '360,390,412').split(',').map(Number);
    const rep = await run(browser, dir, widths, (process.env.LANGS || 'en,ar').split(','), process.env.FULL === '1', z);
    fs.writeFileSync(path.join(dir, 'measure.json'), JSON.stringify(rep, null, 1));
    await browser.close();
  }
})();
