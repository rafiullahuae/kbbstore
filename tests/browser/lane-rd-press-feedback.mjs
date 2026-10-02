/*
 * Lane RD — press feedback ("tap any button or icon: no grey box, a live
 * response"), measured in a real browser at 390px (isMobile, hasTouch) and
 * 1280px. Reports, does not judge.
 *
 *   RD_BASE    http://127.0.0.1:9960
 *   RD_OUT     where the PNGs and report-<tag>.json go
 *   RD_TAG     before | after | a | b | c | d | e | off   (a label only)
 *   RD_PRESS   1 = also photograph every target mid-press
 *   RD_CHROME  the Chromium binary
 *
 * Measurements are taken by the SCRIPT, from outside the page. The page's own
 * JavaScript reads no element geometry at all (CLAUDE.md rule 4); this harness
 * is allowed to, because it is the ruler and not the shop.
 *
 * "Mid-press" is a real pointerdown dispatched on the element, so it goes
 * through the shop's own delegated listener exactly as a finger does, and the
 * picture is taken while the animation is running (90ms in, of a ~500ms wave).
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.RD_BASE || 'http://127.0.0.1:9960';
const OUT = process.env.RD_OUT || './storage/rd-logs/shots';
const TAG = process.env.RD_TAG || 'after';
const PRESS = process.env.RD_PRESS === '1';
const CHROME = process.env.RD_CHROME;

mkdirSync(OUT, { recursive: true });

/* name => [page, selector, what to photograph around it ('self' or a selector)] */
const TARGETS = {
  burger: ['/', '#burger', 'header'],
  account: ['/', '.hact .ib-acct .ib', 'header'],
  wishlist: ['/', '.hact a.ib[href*="wishlist"]', 'header'],
  cart: ['/', '.hact a.ib[data-kbb-cart]', 'header'],
  search: ['/', '.sbox .search-in', 'header'],
  navlink: ['/', '.mbar .navlink', 'header'],
  allsets: ['/', 'a.lnk[href*="skincare-sets"]', 'section'],
  cardcart: ['/', '.kbb-card-cart', 'tile'],
  heart: ['/', '.heart', 'tile'],
  sliderarrow: ['/', '.sarr.next', 'section'],
  pdpadd: ['/product/pg2-relief-sun/', '#mainAdd', 'buyrow'],
  qtyplus: ['/product/pg2-relief-sun/', '.qty button[data-q="1"]', 'buyrow'],
  gwish: ['/product/pg2-relief-sun/', '#gwish', 'self'],
  share: ['/product/pg2-relief-sun/', '.pdp-share-btn', 'bbhead'],
  ymal: ['/product/pg2-relief-sun/', '.ymal-btn[data-ymal-next]', 'section'],
  pchip: ['/collections/skincare-sets/', '.pchip', 'self'],
  filterbtn: ['/collections/skincare-sets/', '.mobi-filter', 'self'],
};

const PROPS = [
  'backgroundImage', 'backgroundColor', 'boxShadow', 'transform', 'scale', 'outlineStyle', 'outlineWidth',
  'position', 'overflow', 'webkitTapHighlightColor', 'transitionProperty', 'borderRadius', 'color', 'display', 'filter',
];

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

const report = { tag: TAG, base: BASE, widths: {} };

for (const [label, opts] of [
  ['390', { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }],
  ['1280', { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 }],
]) {
  const ctx = await browser.newContext({ ...opts, reducedMotion: process.env.RD_REDUCED === '1' ? 'reduce' : 'no-preference' });
  const page = await ctx.newPage();
  const out = { pages: {}, targets: {} };
  let at = null;

  for (const [name, [path, sel, around]] of Object.entries(TARGETS)) {
    if (at !== path) {
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      await page.waitForTimeout(300);
      at = path;
      out.pages[path] = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
        htmlPress: document.documentElement.getAttribute('data-press'),
      }));
    }

    const handle = await page.$(sel);
    if (!handle) { out.targets[name] = { missing: true }; continue; }

    const visible = await handle.isVisible();
    if (visible) await handle.scrollIntoViewIfNeeded();
    await page.waitForTimeout(80);

    out.targets[name] = await handle.evaluate((el, PROPS) => {
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      const style = {};
      for (const p of PROPS) style[p] = cs[p];
      return {
        visible: r.width > 0 && r.height > 0,
        rect: { x: +(r.left + window.scrollX).toFixed(2), y: +(r.top + window.scrollY).toFixed(2), w: +r.width.toFixed(2), h: +r.height.toFixed(2) },
        className: el.className && el.className.baseVal === undefined ? el.className : '',
        style,
      };
    }, PROPS);

    if (!visible) continue;

    const box = await handle.evaluate((el, around) => {
      const ctxEl = around === 'self' ? el : (({
        header: (e) => e.closest('header'),
        section: (e) => e.closest('section') || e.parentElement,
        tile: (e) => e.closest('.kbb-tile, .kbb-card, article, li') || e.parentElement,
        buyrow: (e) => e.closest('.buyrow') || e.parentElement,
        bbhead: (e) => e.closest('.bb-head') || e.parentElement,
      })[around] || ((e) => e))(el) || el;
      const r = ctxEl.getBoundingClientRect();
      const s = el.getBoundingClientRect();
      const pad = 10;
      // Clip to the context, but never more than 700px tall, centred on the target.
      let y = Math.max(0, r.top - pad);
      let h = Math.min(r.height + pad * 2, 700);
      if (s.top < y || s.bottom > y + h) { y = Math.max(0, s.top - 200); h = Math.min(500, s.height + 400); }
      return { x: Math.max(0, r.left - pad), y, width: Math.min(window.innerWidth, r.width + pad * 2), height: h };
    }, around);

    await page.screenshot({ path: `${OUT}/${TAG}-${label}-${name}-rest.png`, clip: box });

    if (PRESS) {
      // A real pointer press through the shop's own listener.
      await handle.dispatchEvent('pointerdown', { bubbles: true, isPrimary: true, pointerType: label === '390' ? 'touch' : 'mouse', button: 0 });
      await page.waitForTimeout(Number(process.env.RD_FRAME || 90));
      await page.screenshot({ path: `${OUT}/${TAG}-${label}-${name}-press.png`, clip: box });
      out.targets[name].pressed = await handle.evaluate((el, PROPS) => {
        const cs = getComputedStyle(el);
        const style = {};
        for (const p of PROPS) style[p] = cs[p];
        return { className: typeof el.className === 'string' ? el.className : '', style };
      }, PROPS);
      await handle.dispatchEvent('pointerup', { bubbles: true, isPrimary: true, button: 0 });
      await page.waitForTimeout(700);
      out.targets[name].afterRelease = await handle.evaluate((el) => ({
        className: typeof el.className === 'string' ? el.className : '',
        rect: (() => { const r = el.getBoundingClientRect(); return { x: +(r.left + window.scrollX).toFixed(2), y: +(r.top + window.scrollY).toFixed(2), w: +r.width.toFixed(2), h: +r.height.toFixed(2) }; })(),
      }));
    }
  }

  report.widths[label] = out;
  await ctx.close();
}

await browser.close();
writeFileSync(`${OUT}/report-${TAG}.json`, JSON.stringify(report, null, 2));
console.log(`wrote ${OUT}/report-${TAG}.json`);
