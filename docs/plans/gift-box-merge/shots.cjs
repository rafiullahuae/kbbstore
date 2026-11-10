/*
 * Lane GB: "bonus gift inside the Free gifts box" previews. Nothing on the shop changes.
 *
 *   GO_LANE=lane-gb GO_SEED=../docs/plans/gift-box-merge/gb-seed.php sh tools/go-preview.sh 9590
 *   GO_BASE=http://127.0.0.1:<port> NODE_PATH=/home/user/kbbstore/node_modules node docs/plans/gift-box-merge/shots.cjs
 *
 * Opens the real cart panel, cart page and checkout with a claimed ×2 gift, takes the
 * claimed gift's data from the real gift-offer row, hides that row, and draws each
 * design INTO the real Free gifts box (designs.cjs). Writes shots/<design>-<page>-<w>-<state>.png,
 * one contact sheet per design (contact-<design>.jpg), result.json and preview.html.
 * A normal Chrome user agent: the preview's /cart/ answers 403 to HeadlessChrome.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { D, BASE_CSS, TICK } = require('./designs.cjs');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.GO_BASE || 'http://127.0.0.1:9590';
const APP = path.resolve(__dirname, '../../..');
const OUT = path.join(__dirname, 'shots');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
// A popup claim's hold; the preview's claim comes from an abandoned-cart link (8 h),
// which would leave the ring and the line all but full in every shot.
const TOTAL = 1200;
const STATES = { live: 892, low: 98, ended: 0 };
const result = { data: null, boxes: {}, scrollWidth: {}, console: [] };
fs.mkdirSync(OUT, { recursive: true });
const tinker = (env) => execSync(`sh ${APP}/tools/go-tinker.sh ${APP}/tools/go-claim.php ${new URL(BASE).port}`, { env: { ...process.env, GO_LANE: 'lane-gb', ...env }, encoding: 'utf8' });

// In the page: hide the separate gift-offer row(s), draw the design into every Free gifts box.
function inject([css, pieces, key, state, left, total]) {
  if (!document.getElementById('gbm-css')) { const st = document.createElement('style'); st.id = 'gbm-css'; document.head.appendChild(st); }
  document.getElementById('gbm-css').textContent = css;
  document.querySelectorAll('.kgo-r, .kgo-end').forEach((e) => { e.style.display = 'none'; });
  document.querySelectorAll('fieldset.fgb').forEach((f) => {
    f.classList.add('gbm', 'gb' + key); if (state === 'low') f.classList.add('low');
    if (state !== 'ended') { f.setAttribute('data-gbm-left', left); f.setAttribute('data-gbm-total', total); }
    const lg = f.querySelector('legend'), th = f.querySelector('.fgb-th'), list = f.querySelector('.fgb-list');
    if (pieces.legendSide) f.insertAdjacentHTML('beforeend', pieces.legendSide);
    if (pieces.top) lg.insertAdjacentHTML('afterend', pieces.top);
    if (pieces.mid && th) th.insertAdjacentHTML('beforebegin', pieces.mid);
    if (pieces.tile && th) th.insertAdjacentHTML('afterbegin', pieces.tile);
    if (pieces.after && th) th.insertAdjacentHTML('afterend', pieces.after);
    if (pieces.row && list) list.insertAdjacentHTML('afterbegin', pieces.row);
  });
}

async function boxRect(page) {
  return page.evaluate(() => {
    const f = [...document.querySelectorAll('fieldset.fgb')].find((e) => e.getBoundingClientRect().width > 0 && getComputedStyle(e).visibility !== 'hidden');
    if (!f) return null;
    const r = f.getBoundingClientRect();
    return { x: r.left, y: r.top, w: r.width, h: r.height, sy: scrollY };
  });
}

async function shoot(page, file, vw, panel) {
  if (!panel) {
    await page.evaluate(() => { const f = [...document.querySelectorAll('fieldset.fgb')].find((e) => e.getBoundingClientRect().width > 0); if (f) f.scrollIntoView({ block: 'center' }); });
    await page.waitForTimeout(250);
  }
  const r = await boxRect(page);
  const vh = page.viewportSize().height;
  const padX = vw === 390 ? 0 : 60;
  const x = vw === 390 ? 0 : Math.max(0, r.x - padX);
  const w = vw === 390 ? 390 : Math.min(vw - x, r.w + padX * 2);
  const y = Math.max(0, r.y - 110), h = Math.min(vh - y, r.h + 110 + 70);
  await page.screenshot({ path: file, clip: { x, y, width: w, height: h } });
  return { w: Math.round(r.w * 10) / 10, h: Math.round(r.h * 10) / 10 };
}

async function openPage(page, which) {
  if (which === 'panel') {
    await page.goto(`${BASE}/product/go-glow-serum/`, { waitUntil: 'networkidle' });
    await page.locator('[data-kbb-open="cart"]:visible').first().click();
    await page.waitForTimeout(1100);
  } else {
    await page.goto(`${BASE}/${which}/`, { waitUntil: 'networkidle' });
    if (which === 'checkout' && page.viewportSize().width === 390) { await page.click('#kbbSumRow').catch(() => {}); }
    await page.waitForTimeout(700);
  }
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const captured = {}; // design -> state -> {phone, desk} box HTML for preview.html
  for (const vw of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: vw, height: vw === 390 ? 844 : 900 }, userAgent: UA });
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') result.console.push(`${vw} ${m.text()}`); });
    page.on('response', (r) => { if (r.status() >= 400) result.console.push(`${vw} ${r.status()} ${r.url()}`); });
    page.on('pageerror', (e) => result.console.push(`${vw} pageerror ${e.message}`));
    const tok = tinker({ GO_DO: 'token' }).match(/TOKEN=(\S+)/)[1];
    await page.goto(`${BASE}/gift-offer/c/${tok}`, { waitUntil: 'networkidle' });
    tinker({ GO_DO: 'fill' });

    // The claimed gift, read from the real row.
    await openPage(page, 'cart');
    const d = await page.evaluate(() => {
      const r = document.querySelector('.kgo-r');
      return { name: r.querySelector('.kgo-nm b').textContent.trim(), img: r.querySelector('.kgo-im img').getAttribute('src'),
        qty: +(r.querySelector('.kgo-meta i:last-child').textContent.replace(/\D/g, '')) || 1, total: +r.getAttribute('data-total'), left: +r.getAttribute('data-left') };
    });
    result.data = d;

    // Before: today's two boxes.
    for (const which of ['panel', 'cart', 'checkout']) {
      await openPage(page, which);
      result.boxes[`before-${which}-${vw}`] = await shoot(page, `${OUT}/before-${which}-${vw}.png`, vw, which === 'panel');
    }

    for (const [key, des] of Object.entries(D)) {
      for (const which of ['panel', 'cart', 'checkout']) {
        const states = which === 'panel' || (which === 'cart' && vw === 1280) ? ['live', 'low', 'ended'] : ['live'];
        for (const st of states) {
          await openPage(page, which);
          const dd = { ...d, left: STATES[st], total: TOTAL };
          await page.evaluate(inject, [BASE_CSS + des.css, des.build(dd, st), key, st, dd.left, TOTAL]);
          await page.waitForTimeout(450);
          result.boxes[`${key}-${which}-${vw}-${st}`] = await shoot(page, `${OUT}/${key}-${which}-${vw}-${st}.png`, vw, which === 'panel');
          result.scrollWidth[`${key}-${which}-${vw}-${st}`] = await page.evaluate(() => document.documentElement.scrollWidth);
          if ((which === 'panel' && vw === 390) || (which === 'cart' && vw === 1280)) {
            // Keep the drawn box for preview.html, its pictures inlined.
            const html = await page.evaluate(async () => {
              const f = [...document.querySelectorAll('fieldset.fgb')].find((e) => e.getBoundingClientRect().width > 0).cloneNode(true);
              for (const img of f.querySelectorAll('img')) {
                const b = await (await fetch(img.currentSrc || img.src)).blob();
                img.src = await new Promise((ok) => { const fr = new FileReader(); fr.onload = () => ok(fr.result); fr.readAsDataURL(b); });
                img.removeAttribute('srcset'); img.removeAttribute('loading');
              }
              return f.outerHTML;
            });
            captured[key] = captured[key] || {};
            captured[key][st] = captured[key][st] || {};
            captured[key][st][vw === 390 ? 'phone' : 'desk'] = html;
          }
        }
      }
    }
    await ctx.close();
  }

  /* ---------------- contact sheets: one image per design */
  const sheet = await browser.newPage({ viewport: { width: 2050, height: 900 } });
  const img = (f) => `data:image/png;base64,${fs.readFileSync(path.join(OUT, f)).toString('base64')}`;
  for (const [key, des] of Object.entries(D)) {
    const cell = (f, cap) => `<figure><img src="${img(f)}"><figcaption>${cap}</figcaption></figure>`;
    const html = `<html><body style="margin:0;padding:24px;background:#f3eff1;font:14px system-ui;color:#2a2228">
      <h1 style="margin:0 0 4px;font-size:24px">${des.name}</h1><p style="margin:0 0 16px;max-width:1500px;font-size:16px;color:#5e545a">${des.line}</p>
      <style>figure{margin:0;background:#fff;border-radius:10px;padding:8px;box-shadow:0 1px 3px rgba(0,0,0,.08)}figure img{display:block;max-width:100%}figcaption{font-size:12px;font-weight:700;color:#756c74;margin-top:6px}.r{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start;margin-bottom:14px}figure img{max-width:none}</style>
      <div class="r">${cell(`${key}-panel-390-live.png`, 'Cart panel · 390 · live')}${cell(`${key}-panel-390-low.png`, 'Cart panel · 390 · under 2 min')}${cell(`${key}-panel-390-ended.png`, 'Cart panel · 390 · expired')}${cell(`${key}-cart-390-live.png`, 'Cart page · 390')}${cell(`${key}-checkout-390-live.png`, 'Checkout summary · 390')}</div>
      <div class="r">${cell(`${key}-panel-1280-live.png`, 'Cart panel · 1280')}${cell(`${key}-cart-1280-live.png`, 'Cart page · 1280 · live')}${cell(`${key}-cart-1280-low.png`, 'Cart page · 1280 · under 2 min')}${cell(`${key}-cart-1280-ended.png`, 'Cart page · 1280 · expired')}${cell(`${key}-checkout-1280-live.png`, 'Checkout summary · 1280')}</div></body></html>`;
    await sheet.setContent(html, { waitUntil: 'load' });
    await sheet.screenshot({ path: path.join(__dirname, `contact-${key}.jpg`), type: 'jpeg', quality: 82, fullPage: true });
  }

  /* ---------------- preview.html: every design, side by side, phone and desktop widths */
  const deskW = Math.round(result.boxes['A-cart-1280-live'].w);
  const phoneW = Math.round(result.boxes['A-panel-390-live'].w);
  const css = BASE_CSS + Object.values(D).map((x) => x.css).join('\n');
  const col = (h, w, cap) => `<div class="pv"><div class="cap">${cap}</div><div class="surf" style="width:${w + 24}px">${h}</div></div>`;
  let body = '';
  for (const [key, des] of Object.entries(D)) {
    const c = captured[key];
    body += `<section><h2>${des.name}</h2><p>${des.line}</p><div class="row">${col(c.live.phone, phoneW, `Phone · cart panel (${phoneW}px) · live, ticking`)}${col(c.low.phone, phoneW, 'Phone · under 2 minutes')}${col(c.ended.phone, phoneW, 'Phone · expired')}${col(c.live.desk, deskW, `Desktop · cart page (${deskW}px) · live, ticking`)}</div></section>`;
  }
  const page = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bonus Gift Box Previews</title>
<style>:root{--bg:#f3eff1;--card:#fff;--ink:#2a2228;--mut:#5e545a}@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#1f1a1d;--card:#fff;--ink:#f3eff1;--mut:#c9bfc5}}:root[data-theme="dark"]{--bg:#1f1a1d;--card:#fff;--ink:#f3eff1;--mut:#c9bfc5}
*{box-sizing:border-box}body{margin:0;padding:24px 16px 48px;background:var(--bg);color:var(--ink);font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}h1{margin:0 0 4px;font-size:24px}header p,section>p{margin:0 0 14px;color:var(--mut);max-width:900px}section{margin:28px 0 0}h2{margin:0 0 4px;font-size:18px}
.row{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}.pv{min-width:0;max-width:100%}.cap{font-size:12px;font-weight:700;color:var(--mut);margin-bottom:6px}.surf{max-width:100%;background:var(--card);border-radius:12px;padding:6px 12px 22px;color:#2a2228;font-family:"Jost",system-ui,sans-serif}</style>
<style>${css}</style></head><body><header><h1>Bonus gift inside the Free gifts box</h1><p>Five ways to draw the gift claimed from the exit popup INSIDE the Free gifts box, with its live timer on top and no separate box. Each is drawn into a copy of the real box from the preview shop (Relief Sun ×2, 4 range gifts). Live boxes tick here; red under 2 minutes; the expired state is what replaces the timer when it reaches zero.</p></header>${body}<script>${TICK}</script></body></html>`;
  fs.writeFileSync(path.join(__dirname, 'preview.html'), page);
  fs.writeFileSync(path.join(__dirname, 'result.json'), JSON.stringify(result, null, 1));
  await browser.close();
  console.log('done', Object.keys(result.boxes).length, 'shots; console errors', result.console.length);
})();
