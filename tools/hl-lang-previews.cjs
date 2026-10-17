/* Lane HL (17 October 2026) -- PREVIEWS ONLY of a header language switch and a
   cart-panel Wishlist tab. Nothing here ships: the header is frozen (CLAUDE.md,
   "The header and logo are frozen"), so every candidate is INJECTED into a
   rendered preview page in Chromium (markup + a preview stylesheet) and
   photographed. No shop view, stylesheet, setting or test is touched.

     BASE=http://127.0.0.1:<port> NODE_PATH=$(npm root -g) node tools/hl-lang-previews.cjs

   against tools/arr-preview.sh (Arabic on, mirrored). Writes
   docs/lane-hl/previews/*.png and docs/lane-hl/previews/measurements.json.

   The switch's address is the footer switch's own link (partials/lang-switch,
   Seo::readerAlternatePaths), read off the page, so the preview points where
   the real one will. getBoundingClientRect/elementFromPoint are used here, in
   the HARNESS, to measure; nothing of it is shop JavaScript. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:9521';
const OUT = path.resolve(__dirname, '../docs/lane-hl/previews');
fs.mkdirSync(OUT, { recursive: true });

const GLOBE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.6 3.9 5.6 3.9 9s-1.3 6.4-3.9 9c-2.6-2.6-3.9-5.6-3.9-9S9.4 5.6 12 3Z"/></svg>';
const CHEV = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
const HEART = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg>';

/* Every candidate: desktop ones live inside min-width:901px, phone ones inside
   max-width:900px, so each is checked for leaking into the other range too. */
const CSS = `
.hlp-ribbon{position:fixed;inset-inline:0;bottom:0;z-index:2147483647;background:#1d1d1f;color:#fff;font:600 12px/1.2 system-ui,sans-serif;padding:7px 12px;text-align:center;letter-spacing:.02em;pointer-events:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hlp-ribbon b{color:#ffb3c7}
.hlp{display:none}
@media (min-width:901px){
  .hlp-dA{display:inline-flex;align-items:center;height:32px;padding:0 13px;margin-inline-end:8px;border:1px solid #e9dfe3;border-radius:999px;font-size:13px;font-weight:600;color:inherit;text-decoration:none;white-space:nowrap}
  .hlp-dA:hover{border-color:var(--pink,#e0567b);color:var(--pink-deep,#c23d63)}
  .hlp-dB{display:block;position:relative;margin-inline-end:4px}
  .hlp-dB>summary{list-style:none;display:flex;align-items:center;gap:5px;height:40px;padding:0 8px;border-radius:10px;cursor:pointer;font-size:12.5px;font-weight:700;letter-spacing:.04em}
  .hlp-dB>summary::-webkit-details-marker{display:none}
  .hlp-dB>summary:hover,.hlp-dB[open]>summary{background:#fbeff3}
  .hlp-dB .hlp-menu{position:absolute;top:44px;inset-inline-end:0;min-width:150px;background:#fff;border:1px solid #eee;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.12);padding:6px;z-index:60}
  .hlp-dB .hlp-menu>*{display:flex;justify-content:space-between;align-items:center;min-height:38px;padding:0 12px;border-radius:8px;font-size:13.5px;color:inherit;text-decoration:none}
  .hlp-dB .hlp-menu a:hover{background:#fbeff3}
  .hlp-dB .hlp-menu span{font-weight:700}
  .hlp-dC{display:flex;align-items:center;gap:2px;padding:3px;margin-inline-start:14px;border-radius:999px;background:#f6f1f3;font-size:12.5px;font-weight:600;white-space:nowrap}
  .hlp-dC>*{display:flex;align-items:center;height:28px;padding:0 11px;border-radius:999px;color:inherit;text-decoration:none}
  .hlp-dC>span{background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);font-weight:700}
  .hlp-dD{display:flex;align-items:center;height:30px;margin-inline-start:8px;border:1px solid #e9dfe3;border-radius:999px;overflow:hidden;font-size:12px;font-weight:700;white-space:nowrap}
  .hlp-dD>*{display:flex;align-items:center;height:100%;padding:0 10px;color:inherit;text-decoration:none}
  .hlp-dD>span{background:var(--pink,#e0567b);color:#fff}
}
@media (max-width:900px){
  html.hlp-nowish header .hact a.ib[href*="my-wishlist"]{display:none}
  .hlp-mE,.hlp-mF{display:grid;place-items:center;width:44px;height:44px;color:inherit;text-decoration:none;flex:none}
  .hlp-mE>.hlp-c{display:grid;place-items:center;width:30px;height:30px;border:1.5px solid currentColor;border-radius:999px;font-style:normal;font-weight:700;font-size:14px;line-height:1}
  .hlp-mE[lang=en]>.hlp-c{font-size:11px;letter-spacing:.03em}
  .hlp-mF{grid-template-rows:auto auto;align-content:center;gap:1px}
  .hlp-mF>b{font-size:9px;font-weight:800;letter-spacing:.06em;line-height:1}
  .hlp-mG{display:flex;gap:4px;margin-block:14px 8px;margin-inline:16px 64px;padding:4px;border-radius:999px;background:#f6f1f3;font-size:14px;font-weight:600}
  .hlp-mG>*{flex:1;display:flex;align-items:center;justify-content:center;min-height:40px;border-radius:999px;color:inherit;text-decoration:none}
  .hlp-mG>span{background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);font-weight:700}
  .hlp-mG-ic{display:inline-flex;margin-inline-end:6px}
}
/* the cart panel: a third tab, phone and computer alike */
.hlp-kw .empty-d svg{display:block;margin:0 auto 10px;color:var(--pink,#e0567b)}
.hlp-kw .hlp-wrm{display:grid;place-items:center;width:34px;height:34px;border-radius:999px;border:1px solid #f1d5de;color:var(--pink,#e0567b);background:#fff;margin-inline-end:6px}
.hlp-kw .kc-right{display:flex;flex-direction:row;align-items:center;gap:0}
.kc-tab .hlp-h{display:inline-flex;color:var(--pink,#e0567b)}
.kc-tab.on .hlp-h{color:inherit}
`;

const DESK = {
  A: { name: 'Text pill "العربية" before the account icon', ins: (a) => ({ where: 'hact-first', html: `<a class="hlp hlp-dA" data-hlp-sw href="${a.href}" lang="${a.code}" hreflang="${a.code}">${a.label}</a>` }) },
  B: { name: 'Globe + "EN" with a two-line dropdown (no script: <details>)', ins: (a) => ({ where: 'hact-first', html: `<details class="hlp hlp-dB"><summary data-hlp-sw aria-label="${a.hereLabel}">${GLOBE}<span>${a.hereCode}</span>${CHEV}</summary><div class="hlp-menu"><span lang="${a.hereLang}" aria-current="true">${a.hereName} ✓</span><a data-hlp-go href="${a.href}" lang="${a.code}" hreflang="${a.code}">${a.label}</a></div></details>` }) },
  C: { name: 'Segmented "English | العربية" beside the WhatsApp chip', ins: (a) => ({ where: 'before-hinfo', html: `<nav class="hlp hlp-dC" aria-label="Language">${a.pair(true)}</nav>` }) },
  D: { name: 'Segmented "EN | ع" right after the search field', ins: (a) => ({ where: 'after-sbox', html: `<nav class="hlp hlp-dD" aria-label="Language">${a.pair(false)}</nav>` }) },
};
const MOB = {
  E: { name: '"ع" / "EN" circle where the wishlist heart was', ins: (a) => ({ where: 'wish-slot', html: `<a class="ib hlp hlp-mE" data-hlp-sw href="${a.href}" lang="${a.code}" hreflang="${a.code}" aria-label="${a.label}"><span class="hlp-c">${a.code === 'ar' ? 'ع' : 'EN'}</span></a>` }) },
  F: { name: 'Globe with a tiny "AR" / "EN" under it, where the heart was', ins: (a) => ({ where: 'wish-slot', html: `<a class="ib hlp hlp-mF" data-hlp-sw href="${a.href}" lang="${a.code}" hreflang="${a.code}" aria-label="${a.label}">${GLOBE}<b>${a.code.toUpperCase()}</b></a>` }) },
  G: { name: 'No header icon: "English | العربية" at the top of the menu sheet', ins: (a) => ({ where: 'mmenu-top', html: `<nav class="hlp hlp-mG" aria-label="Language">${a.pair(true, true)}</nav>` }) },
};

const WIDTHS = [320, 360, 390, 430, 768, 900, 1024, 1280];

async function measure(page) {
  return page.evaluate(() => {
    const bx = (e) => { if (!e) return null; const r = e.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) }; };
    const q = (s) => document.querySelector(s);
    const sw = q('[data-hlp-sw]');
    const swBox = sw && getComputedStyle(sw.closest('.hlp') || sw).display !== 'none' ? bx(sw) : null;
    // every visible header link / button / summary answers a click at its centre
    const bad = [];
    let n = 0;
    document.querySelectorAll('header a, header button, header summary').forEach((e) => {
      const r = e.getBoundingClientRect();
      if (r.width < 2 || r.height < 2 || getComputedStyle(e).visibility === 'hidden') return;
      if (r.bottom <= 0 || r.top >= innerHeight || r.right <= 0 || r.left >= innerWidth) return;
      if (e.closest('.sugg, .hlp-menu, [hidden]')) return;
      n++;
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      if (!hit || !(hit === e || e.contains(hit))) bad.push((e.getAttribute('aria-label') || e.textContent.trim()).slice(0, 30) + ' <- ' + (hit ? hit.tagName + '.' + hit.className : 'null'));
    });
    return { logo: bx(q('header .logo')), icon: bx(q('header .logo .lgx-i')), sbox: bx(q('header .sbox')), hin: bx(q('header .hin')), hact: bx(q('header .hact')),
      sw: swBox, scrollW: document.documentElement.scrollWidth, vw: innerWidth, clickable: n, notClickable: bad };
  });
}

async function inject(page, spec, label) {
  await page.addStyleTag({ content: CSS });
  await page.evaluate(({ spec, label }) => {
    document.documentElement.classList.add('hlp-on');
    if (spec.nowish) document.documentElement.classList.add('hlp-nowish');
    const tmp = document.createElement('div');
    for (const part of spec.parts) {
      tmp.innerHTML = part.html;
      const el = tmp.firstElementChild;
      const hact = document.querySelector('header .hact');
      if (part.where === 'hact-first') hact.prepend(el);
      else if (part.where === 'before-hinfo') document.querySelector('header .hinfo').before(el);
      else if (part.where === 'after-sbox') document.querySelector('header .sbox').after(el);
      else if (part.where === 'wish-slot') hact.querySelector('a.ib[href*="my-wishlist"]').before(el);
      else if (part.where === 'mmenu-top') { const m = document.getElementById('mmenu'); const after = m.querySelector('.mm-head') || m.querySelector('.mm-x'); after ? after.after(el) : m.prepend(el); }
    }
    const rb = document.createElement('div');
    rb.className = 'hlp-ribbon';
    rb.innerHTML = label;
    document.body.append(rb);
  }, { spec, label });
}

async function langInfo(page) {
  return page.evaluate(() => {
    const a = document.querySelector('.kbb-lang a');
    const here = document.documentElement.lang.startsWith('ar') ? 'ar' : 'en';
    return { href: a.getAttribute('href'), code: a.getAttribute('lang'), label: a.textContent.trim(), here };
  });
}
function args(li) {
  const names = { en: 'English', ar: 'العربية' };
  const short = { en: 'EN', ar: 'ع' };
  return {
    ...li,
    hereCode: li.here.toUpperCase(), hereLang: li.here, hereName: names[li.here], hereLabel: li.here === 'ar' ? 'اللغة' : 'Language',
    pair: (full, icon) => ['en', 'ar'].map((c) => {
      const t = (icon && c === 'en' ? '' : '') + (full ? names[c] : short[c]);
      return c === li.here ? `<span lang="${c}" aria-current="true">${t}</span>` : `<a data-hlp-sw href="${li.href}" lang="${c}" hreflang="${c}">${t}</a>`;
    }).join(''),
  };
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = { header: {}, cart: {} };
  const options = [...Object.entries(DESK).map(([k, v]) => [k, v, 'desk']), ...Object.entries(MOB).map(([k, v]) => [k, v, 'mob'])];

  const open = async (w, p) => {
    const ctx = await browser.newContext({ viewport: { width: w, height: w > 900 ? 720 : 760 }, deviceScaleFactor: w > 900 ? 1 : 2, isMobile: w <= 430, hasTouch: w <= 430 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errs.push(m.text()); });
    await page.goto(BASE + p, { waitUntil: 'networkidle' });
    return { ctx, page, errs };
  };

  // 1. the lock: every option at every width, before vs after, both languages
  for (const p of ['/', '/ar/']) {
    for (const w of WIDTHS) {
      const base = await open(w, p);
      const before = await measure(base.page);
      if ([360, 390, 430, 1280].includes(w)) await base.page.screenshot({ path: `${OUT}/current-${w}${p === '/ar/' ? '-ar' : ''}.png`, clip: { x: 0, y: 0, width: w, height: w > 900 ? 260 : 300 } });
      await base.ctx.close();
      for (const [k, opt, kind] of options) {
        const o = await open(w, p);
        const li = await langInfo(o.page);
        const spec = { nowish: kind === 'mob', parts: [opt.ins(args(li))] };
        await inject(o.page, spec, `PREVIEW ONLY &middot; Option <b>${k}</b> &middot; ${opt.name}`);
        const after = await measure(o.page);
        const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
        results.header[`${k}|${w}|${p}`] = {
          logoSame: same(before.logo, after.logo) && same(before.icon, after.icon),
          logo: after.logo && `${after.logo.w}x${after.logo.h}`, icon: after.icon && `${after.icon.w}x${after.icon.h}`,
          logoBefore: before.logo && `${before.logo.w}x${before.logo.h}`, iconBefore: before.icon && `${before.icon.w}x${before.icon.h}`,
          sboxTopH: after.sbox && `${after.sbox.y}/${after.sbox.h}`, sboxTopHBefore: before.sbox && `${before.sbox.y}/${before.sbox.h}`,
          sboxW: after.sbox?.w, sboxWBefore: before.sbox?.w,
          searchRowSame: before.sbox && after.sbox && before.sbox.y === after.sbox.y && before.sbox.h === after.sbox.h,
          hinH: `${before.hin.h}->${after.hin.h}`,
          scrollOk: after.scrollW === after.vw, sw: after.sw, clickable: after.clickable, notClickable: after.notClickable, errors: o.errs,
        };
        const shoot = (kind === 'desk' && (w === 1280)) || (kind === 'mob' && [360, 390, 430].includes(w));
        if (shoot) await o.page.evaluate((t) => { const r = document.querySelector(".hlp-ribbon"); r.style.bottom = "auto"; r.style.top = t + "px"; }, kind === "desk" ? 232 : 272);
        if (shoot) await o.page.screenshot({ path: `${OUT}/${k}-${w}${p === '/ar/' ? '-ar' : ''}.png`, clip: { x: 0, y: 0, width: w, height: kind === 'desk' ? 260 : 300 } });
        await o.ctx.close();
      }
    }
  }

  // 2. pictures that need a state: B open, G's menu open, phone 360, real clicks
  for (const p of ['/', '/ar/']) {
    const sfx = p === '/ar/' ? '-ar' : '';
    // B open
    let o = await open(1280, p);
    let li = await langInfo(o.page);
    await inject(o.page, { parts: [DESK.B.ins(args(li))] }, 'PREVIEW ONLY &middot; Option <b>B</b> open');
    await o.page.click('.hlp-dB>summary');
    await o.page.evaluate(() => { const r = document.querySelector(".hlp-ribbon"); r.style.bottom = "auto"; r.style.top = "232px"; });
    await o.page.screenshot({ path: `${OUT}/B-1280-open${sfx}.png`, clip: { x: 0, y: 0, width: 1280, height: 260 } });
    await Promise.all([o.page.waitForURL((u) => u.pathname !== p, { timeout: 15000 }), o.page.click('.hlp-dB [data-hlp-go]')]);
    results.header[`B-click${sfx}`] = new URL(o.page.url()).pathname;
    await o.ctx.close();
    // real click on each other option's switch
    for (const [k, opt, kind] of options) {
      if (k === 'B') continue;
      const w = kind === 'desk' ? 1280 : 390;
      o = await open(w, p);
      li = await langInfo(o.page);
      await inject(o.page, { nowish: kind === 'mob', parts: [opt.ins(args(li))] }, 'x');
      if (k === 'G') { await o.page.click('header .kbbmi'); await o.page.waitForTimeout(700); }
      await Promise.all([o.page.waitForURL((u) => u.pathname !== p, { timeout: 15000 }), o.page.click(k === 'G' ? '.hlp-mG [data-hlp-sw]' : '[data-hlp-sw]')]);
      results.header[`${k}-click${sfx}`] = new URL(o.page.url()).pathname;
      await o.ctx.close();
    }
    // G: the menu sheet open, at 390 and 360
    for (const w of [360, 390, 430]) {
      o = await open(w, p);
      li = await langInfo(o.page);
      await inject(o.page, { nowish: true, parts: [MOB.G.ins(args(li))] }, 'PREVIEW ONLY &middot; Option <b>G</b> &middot; menu sheet');
      await o.page.click('header .kbbmi');
      await o.page.waitForTimeout(700);
      const t = await o.page.evaluate(() => {
        const hits = (e) => { const r = e.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return h === e || e.contains(h); };
        const sw = document.querySelector('.hlp-mG [data-hlp-sw]'), x = document.querySelector('#mmenu .mm-x'), r = sw.getBoundingClientRect();
        return `${Math.round(r.width)}x${Math.round(r.height)} switchHit=${hits(sw)} closeHit=${x ? hits(x) : 'n/a'} scrollW=${document.documentElement.scrollWidth}`;
      });
      results.header[`G-menu-tap|${w}${sfx}`] = t;
      await o.page.screenshot({ path: `${OUT}/G-${w}-menu${sfx}.png` });
      await o.ctx.close();
    }
  }

  // 3. the cart panel at 390, with a Wishlist tab
  const products = (await (await fetch(BASE + '/')).text()).match(/\/product\/[a-z0-9-]+\//g).filter((v, i, a) => a.indexOf(v) === i).slice(0, 4);
  for (const p of ['/', '/ar/']) {
    const sfx = p === '/ar/' ? '-ar' : '';
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1" });
    const page = await ctx.newPage();
    for (const pr of products) { await page.goto(BASE + (p === '/ar/' ? '/ar' : '') + pr, { waitUntil: 'networkidle' }); }
    await page.goto(BASE + p, { waitUntil: 'networkidle' });
    await page.evaluate(async () => {
      await new Promise((r) => setTimeout(r, 2500));
      const id = Number(document.querySelector('[data-kbb-add],[data-add]')?.dataset.kbbAdd || document.querySelector('[data-add]')?.dataset.add || 1);
      await fetch(`${window.KBB.routes.cartApi}/add`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json', 'X-KBB-Hm': String(Math.round(performance.now())) }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
      if (window.kbbRefreshCart) await window.kbbRefreshCart();
    });
    await page.addStyleTag({ content: CSS });
    await page.click('header [data-kbb-cart]');
    await page.waitForTimeout(900);
    const ar = p === '/ar/';
    for (const variant of [1, 2]) {
      for (const state of ['empty', 'filled']) {
        await page.evaluate(({ variant, state, ar, HEART }) => {
          document.querySelectorAll('.hlp-kwtab,#hlpKw,.hlp-ribbon').forEach((e) => e.remove());
          const tabs = document.querySelector('.kc-tabs');
          const bTab = tabs.querySelector('[data-kctab=browsed]');
          const cTab = tabs.querySelector('[data-kctab=cart]');
          const browsed = document.getElementById('kcBrowsed');
          const rows = [...browsed.querySelectorAll('.kc-item')].slice(0, 3);
          const n = state === 'filled' ? rows.length : 0;
          const label = ar ? 'المفضلة' : 'Wishlist';
          const t = document.createElement('button');
          t.type = 'button'; t.className = 'kc-tab on hlp-kwtab';
          t.innerHTML = variant === 1 ? `${label}${n ? ` <span class="n">${n}</span>` : ''}` : `<span class="hlp-h">${HEART}</span>${label}${n ? ` <span class="n">${n}</span>` : ''}`;
          tabs.querySelectorAll('.kc-tab').forEach((x) => x.classList.remove('on'));
          if (variant === 1) bTab.after(t); else cTab.after(t);
          const pane = document.createElement('div');
          pane.id = 'hlpKw'; pane.className = 'hlp-kw';
          if (n) {
            const body = document.createElement('div'); body.className = 'dbody';
            for (const r of rows) {
              const c = r.cloneNode(true);
              const right = c.querySelector('.kc-right');
              const rm = document.createElement('span'); rm.className = 'hlp-wrm'; rm.innerHTML = HEART; rm.title = ar ? 'إزالة من المفضلة' : 'Remove from wishlist';
              right.prepend(rm);
              body.append(c);
            }
            pane.append(body);
          } else {
            pane.innerHTML = `<div class="dbody"><div class="empty-d" style="padding:30px 20px"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 20.5s-7.5-4.6-9.2-9.4A5 5 0 0 1 12 6a5 5 0 0 1 9.2 5.1C19.5 15.9 12 20.5 12 20.5z"/></svg>${ar ? 'قائمة المفضلة فارغة' : 'Your wishlist is empty'}<br>${ar ? 'اضغطي ♥ على أي منتج لحفظه هنا.' : 'Tap ♥ on any product to save it here.'}</div></div>`;
          }
          document.getElementById('kcCart').style.display = 'none';
          browsed.style.display = 'none';
          browsed.after(pane);
          const rb = document.createElement('div'); rb.className = 'hlp-ribbon';
          rb.innerHTML = `PREVIEW ONLY &middot; Cart panel <b>${variant}</b> &middot; Wishlist tab ${variant === 1 ? 'after Browsed' : 'between Cart and Browsed, with a heart'} &middot; ${state}`;
          document.body.append(rb);
        }, { variant, state, ar, HEART });
        const m = await page.evaluate(() => {
          const t = [...document.querySelectorAll('.kc-tabs .kc-tab')].map((e) => { const r = e.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return `${e.textContent.trim().replace(/\s+/g, ' ')}:${Math.round(r.width)}x${Math.round(r.height)}${h === e || e.contains(h) ? '' : ' NOT-HIT'}`; });
          const x = document.querySelector('.kc-tabs .kc-x').getBoundingClientRect();
          return { tabs: t, close: `${Math.round(x.width)}x${Math.round(x.height)}`, scrollW: document.documentElement.scrollWidth };
        });
        results.cart[`${variant}-${state}${sfx}`] = m;
        await page.screenshot({ path: `${OUT}/cart-${variant}-${state}-390${sfx}.png` });
      }
    }
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(results, null, 1));
  await browser.close();
  console.log('done');
})();
