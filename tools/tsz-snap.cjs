/* Lane TS: static snapshots of the cart panel, the cart page and the checkout,
   rendered by THIS repo's code on the seeded preview shop, for the type-size
   preview in docs/type-preview/.

     APP_KEY=... FG_SEED=tsz-seed.php sh tools/fg-preview.sh 11240
     BASE=http://127.0.0.1:PORT MODE=snap node tools/tsz-snap.cjs

   MODE=snap  writes docs/type-preview/snap/<page>-<d|m>.html (+ shared files in
              snap/a/) and the reference screenshots of the live render to
              storage/ts-logs/ref-<page>-<w>.png;
   MODE=dump  prints every text-bearing element inside each page's scope with
              its computed font-size, to storage/ts-logs/dump-<page>-<w>.json.

   The snapshot is the DOM as Chromium rendered it after the page's own
   scripts ran, with:
     - every <script>, <noscript>, preload/modulepreload/manifest/icon link and
       on* attribute removed (sizes need no shopper JS);
     - every stylesheet inlined as <style>, its url()s copied as files;
     - every picture copied as a file (src = the candidate Chromium chose;
       srcset/sizes dropped, which changes no box because the size is the
       element's own) and data: pictures written out as files;
     - live form state written back as attributes (value, checked, selected);
     - any <iframe> (card fields) replaced by an empty box of the same size,
       so the preview makes no request to anybody.
   No basket, no order and no request ever goes to the live shop: BASE is the
   preview server. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path'), crypto = require('crypto');

const BASE = process.env.BASE, MODE = process.env.MODE || 'snap';
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be the local preview, http://127.0.0.1:PORT');
const OUT = path.resolve(__dirname, '../docs/type-preview/snap');
const ASSETS = path.join(OUT, 'a');
const LOGS = path.resolve(__dirname, '../storage/ts-logs');
fs.mkdirSync(ASSETS, { recursive: true }); fs.mkdirSync(LOGS, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
// AED 182: under the AED 199 free-delivery line, so the progress notes show; one line on sale.
const BASKET = [[26, 1], [5, 1], [11, 1]];
// The three surfaces the owner named; the site header and footer are outside
// every scope (the header is frozen and was not asked about).
const SCOPES = { panel: '#cart', cart: '#cartPage', checkout: '#kbbCheckoutForm' };

async function fill(page) {
  // Two fields typed (lifted label + typed text), the rest left empty (resting label).
  const typed = { billing_email: 'layla.ahmed@example.com', billing_first_name: 'Layla Ahmed' };
  for (const [k, v] of Object.entries(typed)) {
    const f = page.locator(`.kbb-checkout input[name="${k}"]`).filter({ visible: true }).first();
    if (await f.count()) { await f.fill(v); await f.blur(); }
  }
}

async function open(page, where, w) {
  if (where === 'panel') {
    await page.goto(BASE + '/product/mac-cosrx/', { waitUntil: 'networkidle' });
    await page.locator('[data-kbb-cart]').filter({ visible: true }).first().click();
    await page.locator('#cart .kc-fragment, #cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(900);
  } else if (where === 'cart') {
    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
  } else {
    await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    await fill(page);
    const row = page.locator('#kbbSumRow').first();
    if (await row.count() && await row.isVisible() && (await row.getAttribute('aria-expanded')) !== 'true') await row.click();
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(900);
  }
}

/* Runs in the page: the serialised, script-free DOM plus every URL it needs. */
function serialise(SCOPE) {
  const abs = (u, b) => { try { return new URL(u, b).href; } catch { return u; } };
  const fixCss = (css, b) => css.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, (m, q, u) => u.startsWith('data:') || u.startsWith('#') ? m : `url("${abs(u, b)}")`);
  // live state -> attributes
  document.querySelectorAll('input, textarea, select').forEach((el) => {
    if (el.tagName === 'SELECT') { [...el.options].forEach((o) => o.toggleAttribute('selected', o.selected)); return; }
    if (el.type === 'checkbox' || el.type === 'radio') { el.toggleAttribute('checked', el.checked); return; }
    if (el.type === 'password' || el.type === 'file') return;
    if (el.tagName === 'TEXTAREA') el.textContent = el.value; else if (el.value !== '') el.setAttribute('value', el.value); else el.removeAttribute('value');
  });
  // pictures: the chosen candidate, as an absolute URL
  document.querySelectorAll('img').forEach((im) => {
    const src = im.currentSrc || im.getAttribute('src');
    if (src) im.setAttribute('src', abs(src, location.href));
    im.removeAttribute('srcset'); im.removeAttribute('sizes');
    if (im.loading === 'lazy') im.removeAttribute('loading');
  });
  document.querySelectorAll('picture source').forEach((s) => s.remove());
  document.querySelectorAll('iframe').forEach((f) => {
    const r = f.getBoundingClientRect(), d = document.createElement('div');
    d.setAttribute('style', `display:block;width:${r.width}px;height:${r.height}px`);
    d.setAttribute('data-ts-was', 'iframe'); f.replaceWith(d);
  });
  document.querySelectorAll('script, noscript, template[data-kbb-lazy]').forEach((s) => s.remove());
  document.querySelectorAll('link').forEach((l) => { if (!/(^|\s)stylesheet(\s|$)/i.test(l.rel)) l.remove(); });
  document.querySelectorAll('*').forEach((el) => {
    for (const a of [...el.attributes]) if (/^on/i.test(a.name)) el.removeAttribute(a.name);
    const st = el.getAttribute('style'); if (st && st.includes('url(')) el.setAttribute('style', fixCss(st, location.href));
  });
  document.querySelectorAll('style').forEach((s) => { s.textContent = fixCss(s.textContent, location.href); });
  document.querySelectorAll('a[href]').forEach((a) => a.setAttribute('href', '#'));
  document.querySelectorAll('form[action]').forEach((f) => f.setAttribute('action', '#'));
  const links = [...document.querySelectorAll('link[rel~="stylesheet"]')].map((l) => l.href);
  document.documentElement.setAttribute('data-ts-scope', SCOPE);
  document.documentElement.setAttribute('data-ts-scroll', String(window.scrollY));
  return { html: '<!DOCTYPE html>\n' + document.documentElement.outerHTML, links };
}

function dumpText(SCOPE) {
  const root = document.querySelector(SCOPE); if (!root) return [];
  const out = [];
  const path = (el) => { const p = []; for (let e = el; e && e !== root.parentElement; e = e.parentElement) p.unshift(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.classList.length ? '.' + [...e.classList].join('.') : '')); return p.slice(-4).join(' > '); };
  root.querySelectorAll('*').forEach((el) => {
    const own = [...el.childNodes].filter((n) => n.nodeType === 3 && n.textContent.trim()).map((n) => n.textContent.trim()).join(' ');
    const ph = el.getAttribute && el.getAttribute('placeholder');
    const isField = /^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(el.tagName);
    if (!own && !ph && !isField) return;
    if (!el.getClientRects().length) return;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden') return;
    out.push({ p: path(el), fs: cs.fontSize, fw: cs.fontWeight, t: (own || ph || el.value || '').slice(0, 50), ph: ph ? getComputedStyle(el, '::placeholder').fontSize : undefined });
  });
  return out;
}

const files = new Map();
async function fetchAsset(req, url) {
  if (files.has(url)) return files.get(url);
  let buf, ext;
  if (url.startsWith('data:')) {
    const m = /^data:([^;,]+)(;base64)?,(.*)$/s.exec(url); if (!m) return url;
    buf = m[2] ? Buffer.from(m[3], 'base64') : Buffer.from(decodeURIComponent(m[3]));
    ext = { 'image/svg+xml': '.svg', 'image/png': '.png', 'image/webp': '.webp', 'image/jpeg': '.jpg', 'image/gif': '.gif' }[m[1]] || '';
    if (!ext || buf.length < 600) { files.set(url, url); return url; } // tiny inline icons stay inline
  } else {
    if (!url.startsWith(BASE)) { files.set(url, null); return null; }
    const r = await req.get(url); if (!r.ok()) { files.set(url, null); return null; }
    buf = await r.body();
    ext = path.extname(new URL(url).pathname).toLowerCase() || '.bin';
  }
  const name = crypto.createHash('sha1').update(buf).digest('hex').slice(0, 12) + ext;
  fs.writeFileSync(path.join(ASSETS, name), buf);
  files.set(url, 'a/' + name);
  return 'a/' + name;
}

async function localise(req, text, prefix) {
  const urls = new Set();
  text.replace(/url\("([^"]+)"\)/g, (m, u) => urls.add(u));
  text.replace(/\ssrc="([^"]+)"/g, (m, u) => urls.add(u.replace(/&amp;/g, '&')));
  text.replace(/\shref="(http[^"]+)"/g, (m, u) => urls.add(u.replace(/&amp;/g, '&')));
  text.replace(/\sxlink:href="(http[^"]+)"/g, (m, u) => urls.add(u.replace(/&amp;/g, '&')));
  for (const u of urls) {
    if (!/^(https?:|data:)/.test(u)) continue;
    const local = await fetchAsset(req, u);
    const to = local === null ? (u.startsWith('data:') ? u : 'data:,') : (local.startsWith('data:') ? local : prefix + local);
    if (to === u) continue;
    text = text.split(`url("${u}")`).join(`url("${to}")`)
      .split(`src="${u.replace(/&/g, '&amp;')}"`).join(`src="${to}"`).split(`src="${u}"`).join(`src="${to}"`)
      .split(`href="${u.replace(/&/g, '&amp;')}"`).join(`href="${to}"`).split(`href="${u}"`).join(`href="${to}"`);
  }
  return text;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = [];
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ userAgent: w === 390 ? UA_M : UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1, isMobile: w === 390, hasTouch: w === 390 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    for (const slug of ['mac-medi', 'mac-aren', 'mac-oil']) { await page.goto(BASE + '/product/' + slug + '/', { waitUntil: 'networkidle' }); await page.waitForTimeout(300); }
    for (const [pid, q] of BASKET) {
      const s = await page.evaluate(async ([id, qty]) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: qty }) })).status, [pid, q]);
      if (s !== 200) throw new Error('add ' + pid + ' -> ' + s);
    }
    for (const where of ['panel', 'cart', 'checkout']) {
      await open(page, where, w);
      const tag = `${where}-${w}`;
      if (MODE === 'dump') {
        fs.writeFileSync(`${LOGS}/dump-${tag}.json`, JSON.stringify(await page.evaluate(dumpText, SCOPES[where]), null, 0).replace(/},{/g, '},\n{'));
        await page.screenshot({ path: `${LOGS}/ref-${tag}.png`, fullPage: where !== 'panel' });
        report.push({ tag, errors: errors.splice(0) });
        continue;
      }
      await page.screenshot({ path: `${LOGS}/ref-${tag}.png` });
      const scrollY = await page.evaluate(() => window.scrollY);
      const { html, links } = await page.evaluate(serialise, SCOPES[where]);
      // Each stylesheet becomes ONE shared file in snap/a/ (six snapshots,
      // one copy of kbb.css), linked where it was, so the cascade order
      // against the page's own <style> blocks is exactly as rendered.
      let out = html;
      for (const href of links) {
        if (!files.has(href)) {
          const r = await page.request.get(href);
          let t = await r.text();
          t = t.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, (m, q, u) => u.startsWith('data:') || u.startsWith('#') ? m : `url("${new URL(u, href).href}")`);
          t = (await localise(page.request, t, '')).replace(/url\("a\//g, 'url("');
          const name = crypto.createHash('sha1').update(t).digest('hex').slice(0, 12) + '.css';
          fs.writeFileSync(path.join(ASSETS, name), t);
          files.set(href, 'a/' + name);
        }
        out = out.split(`href="${href}"`).join(`href="${files.get(href)}"`);
        const rel = new URL(href).pathname; out = out.split(`href="${rel}"`).join(`href="${files.get(href)}"`);
      }
      out = await localise(page.request, out, '');
      const name = `${where}-${w === 390 ? 'm' : 'd'}.html`;
      fs.writeFileSync(path.join(OUT, name), out);
      report.push({ tag, file: name, bytes: out.length, scrollY, errors: errors.splice(0) });
    }
    await ctx.close();
  }
  await browser.close();
  console.log(report.map((r) => JSON.stringify(r)).join('\n'));
})().catch((e) => { console.error(e); process.exit(1); });
