/*
 * Lane HB -- the banner slider's text box on the real homepage.
 *
 *   HB_BASE=http://127.0.0.1:10460 HB_TAG=a-default HB_LANGS=en,ar node tools/hb-shots.cjs
 *   HB_ADMIN=1 ... node tools/hb-shots.cjs          the admin Text box screen + one picture's words
 *
 * Writes docs/lane-hb-shots/<tag>-<lang>-<w>.png and prints, per shot: the
 * frame and box size, the box's room inside the frame, the button's drawn and
 * tappable heights, whether a real click on the button and on the picture
 * lands on a link, console errors, and scrollWidth.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.HB_BASE || 'http://127.0.0.1:10460';
const TAG = process.env.HB_TAG || 'shot';
const OUT = path.join(__dirname, '..', 'docs', process.env.HB_OUT || 'lane-hb-shots');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const WIDTHS = (process.env.HB_WIDTHS || '390,1280').split(',').map(Number);
const LANGS = (process.env.HB_LANGS || 'en').split(',');
fs.mkdirSync(OUT, { recursive: true });

async function shop(browser, width, lang) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(BASE + (lang === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(400);
  const m = await page.evaluate(() => {
    const vp = document.querySelector('.kbbs-vp');
    if (!vp) return { none: true };
    const f = vp.getBoundingClientRect();
    const slide = document.querySelector('.kbbs-s');
    const box = slide.querySelector('.hb-box');
    const out = { frame: Math.round(f.width) + 'x' + Math.round(f.height), frameBottom: Math.ceil(f.bottom + scrollY), scrollWidth: document.documentElement.scrollWidth };
    if (box && getComputedStyle(box).display !== 'none') {
      const b = box.getBoundingClientRect();
      const stk = box.querySelector('.hb-stk');
      const s = stk ? stk.getBoundingClientRect() : b;
      out.box = Math.round(b.width) + 'x' + Math.round(b.height);
      out.room = { top: Math.round(Math.min(b.top, s.top) - f.top), bottom: Math.round(f.bottom - b.bottom), start: Math.round(b.left - f.left), end: Math.round(f.right - b.right) };
      out.inside = Math.min(b.top, s.top) >= f.top && b.bottom <= f.bottom && Math.max(b.right, s.right) <= f.right && b.left >= f.left;
      const h = box.querySelector('.hb-h');
      out.heading = h ? getComputedStyle(h).fontSize + ' ' + getComputedStyle(h).fontFamily.split(',')[0] : '-';
      const btn = box.querySelector('.hb-btn');
      if (btn) {
        const r = btn.getBoundingClientRect();
        const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
        let up = 0, dn = 0;
        while (up < 60 && btn.contains(document.elementFromPoint(cx, cy - up - 1))) up++;
        while (dn < 60 && btn.contains(document.elementFromPoint(cx, cy + dn + 1))) dn++;
        out.button = { drawn: btn.offsetHeight, tap: up + dn + 1, font: getComputedStyle(btn).fontSize, hitIsButton: document.elementFromPoint(cx, cy) === btn || btn.contains(document.elementFromPoint(cx, cy)) };
      }
      // A point on the picture away from the box: the picture's own link must get it.
      const px = box.classList.contains('is-end') ? f.left + 30 : f.right - 80, py = f.top + f.height * 0.25;
      const hit = document.elementFromPoint(px, py);
      out.pictureHit = hit ? (hit.closest('a') ? 'a.' + hit.closest('a').className : hit.className || hit.tagName) : null;
      // A point on the box's heading: falls through to the picture link (whole-slide click).
      if (h) { const hr = h.getBoundingClientRect(); const t = document.elementFromPoint(hr.left + 10, hr.top + hr.height / 2); out.headingHit = t && t.closest('a') ? 'a.' + t.closest('a').className : (t ? t.className : null); }
    } else out.box = box ? 'hidden' : 'none';
    return out;
  });
  if (process.env.HB_CONTRAST && m.box && m.box !== 'hidden' && m.box !== 'none') m.contrast = await contrast(page);
  const file = `${TAG}-${lang}-${width}.png`;
  if (!m.none) await page.screenshot({ path: path.join(OUT, file), clip: { x: 0, y: 0, width, height: Math.min(m.frameBottom + 70, 1400) } });
  // A real click on the button navigates.
  let clicked = null;
  if (m.button && process.env.HB_CLICK) {
    await Promise.all([page.waitForURL((u) => !/\/(ar\/)?$/.test(new URL(u).pathname), { timeout: 5000 }).catch(() => {}), page.click('.kbbs-s .hb-btn')]);
    clicked = new URL(page.url()).pathname;
  }
  console.log(JSON.stringify({ tag: TAG, lang, width, file, ...m, clicked, errors }));
  await ctx.close();
}

/* WORST-CASE CONTRAST "over any photo": the picture is hidden and the frame
   painted pure white, then pure black; the words are made transparent and every
   pixel behind every text run is tested against that run's own colour. */
async function contrast(page) {
  const hb = await page.evaluate(() => { const r = document.querySelector('.kbbs-vp').getBoundingClientRect(); return { x: r.x, y: r.y + scrollY, width: r.width, height: r.height }; });
  const runs = await page.evaluate(() => {
    const f = document.querySelector('.kbbs-vp').getBoundingClientRect();
    const box = document.querySelector('.kbbs-s .hb-box');
    return [...box.querySelectorAll('.hb-eb,.hb-h,.hb-t,.hb-btn,.hb-stk b')].map((el) => {
      const rg = document.createRange();
      const tn = [...el.childNodes].find((n) => n.nodeType === 3);
      if (el.matches('.hb-btn') && tn) rg.selectNodeContents(tn); else rg.selectNodeContents(el);
      const q = rg.getBoundingClientRect(), e = el.getBoundingClientRect();
      const x0 = Math.max(q.left, e.left), x1 = Math.min(q.right, e.right), y0 = Math.max(q.top, e.top), y1 = Math.min(q.bottom, e.bottom);
      return { n: el.matches('.hb-stk b') ? 'sticker' : el.className.split(' ')[0].replace('hb-', '') + (el.matches('.hb-btn') ? ':' + el.className.split(' ')[1] : ''),
        x: x0 - f.x, y: y0 - f.y, w: x1 - x0, h: y1 - y0, c: getComputedStyle(el).color };
    });
  });
  const worst = {};
  for (const bg of ['#ffffff', '#000000']) {
    const st = await page.addStyleTag({ content: `.kbbs-a img{visibility:hidden!important}.kbbs .kbbs-vp{background:${bg}!important}
      .hb-box,.hb-box *{color:transparent!important;text-decoration-color:transparent!important}.hb-box svg,.kbbs-ctl,.kbbs-bars{visibility:hidden!important}` });
    const png = (await page.screenshot({ clip: hb, fullPage: true })).toString('base64');
    const res = await page.evaluate(async ({ png, runs }) => {
      const img = new Image(); img.src = 'data:image/png;base64,' + png; await img.decode();
      const cv = document.createElement('canvas'); cv.width = img.width; cv.height = img.height;
      const cx = cv.getContext('2d'); cx.drawImage(img, 0, 0);
      const lin = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
      const L = (r, g, b) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
      const out = {};
      for (const t of runs) {
        const m = t.c.match(/[\d.]+/g).map(Number); const a = m.length > 3 ? m[3] : 1;
        const d = cx.getImageData(Math.max(0, Math.round(t.x)), Math.max(0, Math.round(t.y)), Math.max(1, Math.round(t.w)), Math.max(1, Math.round(t.h))).data;
        let w = 99;
        for (let i = 0; i < d.length; i += 4) {
          const l1 = L(m[0] * a + d[i] * (1 - a), m[1] * a + d[i + 1] * (1 - a), m[2] * a + d[i + 2] * (1 - a)), l2 = L(d[i], d[i + 1], d[i + 2]);
          const c = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); if (c < w) w = c;
        }
        out[t.n] = Math.min(out[t.n] ?? 99, w);
      }
      return out;
    }, { png, runs });
    for (const [k, v] of Object.entries(res)) worst[k] = Math.min(worst[k] ?? 99, v);
    await st.evaluate((n) => n.remove());
  }
  return Object.fromEntries(Object.entries(worst).map(([k, v]) => [k, +v.toFixed(2)]));
}

async function admin(browser, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 1400 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.evaluate(() => window.go('banners'));
  await page.waitForTimeout(1400);
  await page.click('[data-bns-open]');
  await page.waitForTimeout(1800);
  await page.addStyleTag({ content: '.bns-foot{position:static !important}' });
  const clipOf = async (fromSel, toSel) => page.evaluate(([a, b]) => {
    const x = document.querySelector(a), y = b ? document.querySelector(b) : null;
    const top = x.getBoundingClientRect().top + scrollY - 10;
    const bottom = y ? y.getBoundingClientRect().top + scrollY - 6 : x.getBoundingClientRect().bottom + scrollY + 10;
    return { top, h: bottom - top };
  }, [fromSel, toSel]);
  const info = await page.evaluate(() => ({
    sections: [...document.querySelectorAll('#bns-editor .bns-sec')].map((s) => s.textContent.trim()),
    tbControls: document.querySelectorAll('[data-bns-set^="tb_"]').length,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  // The Text box section: from its heading to the next section heading.
  const nextSec = await page.evaluate(() => { const s = [...document.querySelectorAll('#bns-editor .bns-sec')]; const i = s.findIndex((e) => e.id === 'bns-textbox'); if (s[i + 1]) s[i + 1].id = 'hb-after-tb'; return !!s[i + 1]; });
  let c = await clipOf('#bns-textbox', nextSec ? '#hb-after-tb' : null);
  await page.setViewportSize({ width, height: Math.ceil(c.top + c.h + 40) });
  await page.screenshot({ path: path.join(OUT, `admin-textbox-${width}.png`), clip: { x: 0, y: c.top, width, height: Math.min(c.h, 2600) } });
  // One picture's words, opened, Arabic tab too.
  await page.evaluate(() => { const d = document.querySelector('.bns-words'); d.open = true; d.closest('.bns-cd').id = 'hb-card1'; });
  c = await clipOf('#hb-card1', null);
  await page.setViewportSize({ width, height: Math.ceil(c.top + c.h + 40) });
  await page.screenshot({ path: path.join(OUT, `admin-words-en-${width}.png`), clip: { x: 0, y: c.top, width, height: c.h } });
  await page.click('#hb-card1 [data-bns-wtab="ar"]');
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(OUT, `admin-words-ar-${width}.png`), clip: { x: 0, y: c.top, width, height: c.h } });
  console.log(JSON.stringify({ admin: width, ...info, errors }));
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  if (process.env.HB_ADMIN) { for (const w of WIDTHS) await admin(browser, w); }
  else for (const lang of LANGS) for (const w of WIDTHS) await shop(browser, w, lang);
  await browser.close();
})();
