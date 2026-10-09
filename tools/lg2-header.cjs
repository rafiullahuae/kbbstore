/*
 * Lane LG2: the header logo, measured and photographed at each phone width and
 * at 1280. Per width: the logo link's box, the space the row really leaves it
 * (from the menu button's right edge to the icons' left edge), every glyph's
 * right edge against the logo box and against every clipping ancestor, overlap
 * with the menu button and the icons, elementFromPoint over a grid of points
 * inside the logo, and a real click that must land on "/".
 *
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/lg2-header.cjs <base> <label> [path]
 */
const { chromium } = require('playwright');
const BASE = process.argv[2];
const LABEL = process.argv[3] || 'x';
const PATH = process.argv[4] || '/collections/cbbanner/';
const OUT = __dirname + '/../docs/lane-lg2-shots/';
const WIDTHS = (process.env.LG2_WIDTHS || '320,360,375,390,430,1280').split(',').map(Number);
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];
  for (const w of WIDTHS) {
    const phone = w < 600;
    const ctx = await browser.newContext(phone
      ? { viewport: { width: w, height: 800 }, isMobile: true, hasTouch: true, userAgent: UA, deviceScaleFactor: 2, reducedMotion: process.env.LG2_RM ? 'reduce' : 'no-preference' }
      : { viewport: { width: w, height: 800 }, deviceScaleFactor: 2, reducedMotion: process.env.LG2_RM ? 'reduce' : 'no-preference' });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e.message).slice(0, 120)));
    page.on('console', (c) => { if (c.type() === 'error') errs.push(c.text().slice(0, 120)); });
    await page.goto(BASE + PATH, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(400);
    const m = await page.evaluate(() => {
      const r = (e) => { if (!e) return null; const b = e.getBoundingClientRect(); return { l: +b.left.toFixed(1), r: +b.right.toFixed(1), t: +b.top.toFixed(1), b: +b.bottom.toFixed(1), w: +b.width.toFixed(1), h: +b.height.toFixed(1) }; };
      const hdr = document.querySelector('header');
      const logo = hdr.querySelector('a.logo, a.lg');
      const menu = hdr.querySelector('.hin > :first-child');
      const hact = hdr.querySelector('.hact');
      const visible = (e) => e && e.getBoundingClientRect().width > 0;
      const icons = hact ? [...hact.children].filter(visible) : [];
      const iconsL = icons.length ? Math.min(...icons.map((e) => e.getBoundingClientRect().left)) : innerWidth;
      const L = logo.getBoundingClientRect();
      // Every glyph, one Range per character.
      const glyphs = [];
      const walker = document.createTreeWalker(logo, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = walker.nextNode())) {
        for (let i = 0; i < n.data.length; i++) {
          if (!n.data[i].trim()) continue;
          const rg = document.createRange(); rg.setStart(n, i); rg.setEnd(n, i + 1);
          const b = rg.getBoundingClientRect();
          if (b.width === 0) continue;
          glyphs.push({ ch: n.data[i], l: b.left, r: b.right, t: b.top, b: b.bottom, el: n.parentElement });
        }
      }
      // Clipping ancestors of each glyph, up to <html>.
      let worst = 0; let worstCh = '';
      for (const g of glyphs) {
        for (let a = g.el; a; a = a.parentElement) {
          const cs = getComputedStyle(a);
          if (cs.overflowX !== 'visible' || a === logo) {
            const ab = a.getBoundingClientRect();
            const over = Math.max(g.r - ab.right, ab.left - g.l);
            if (over > worst) { worst = over; worstCh = g.ch + ' in ' + a.tagName + '.' + a.className; }
          }
        }
        const overV = Math.max(g.r - innerWidth, -g.l);
        if (overV > worst) { worst = overV; worstCh = g.ch + ' vs viewport'; }
      }
      const svg = logo.querySelector('svg');
      // elementFromPoint over a 5x3 grid inside the logo box.
      let hits = 0; let tries = 0;
      for (let i = 1; i <= 5; i++) for (let j = 1; j <= 3; j++) {
        tries++;
        const e = document.elementFromPoint(L.left + (L.width * i) / 6, L.top + (L.height * j) / 4);
        if (e && e.closest('a') === logo) hits++;
      }
      const lastGlyph = glyphs.reduce((m, g) => Math.max(m, g.r), 0);
      const menuR = menu ? menu.getBoundingClientRect().right : 0;
      const cs = getComputedStyle(logo);
      const nameEl = logo.querySelector('.lgx-w') || logo;
      return {
        logo: r(logo), menuR: +menuR.toFixed(1), iconsL: +iconsL.toFixed(1), header: r(hdr.querySelector('.hin') || hdr),
        glyphs: glyphs.length, lastGlyph: +lastGlyph.toFixed(1), worstClip: +worst.toFixed(2), worstCh,
        overlapMenu: +(menuR - L.left).toFixed(1), overlapIcons: +(L.right - iconsL).toFixed(1),
        svgH: svg ? +svg.getBoundingClientRect().height.toFixed(2) : null,
        nameSize: getComputedStyle(nameEl).fontSize, tagSize: logo.querySelector('.lgx-g') ? getComputedStyle(logo.querySelector('.lgx-g')).fontSize : null,
        efp: hits + '/' + tries, lines: (() => { const w = logo.querySelector('.lgx-w'); if (!w) return 1; const tops = new Set([...w.childNodes].map((c) => { const rg = document.createRange(); rg.selectNodeContents(c); return Math.round(rg.getBoundingClientRect().top); })); return tops.size; })(), sw: document.documentElement.scrollWidth, cls: logo.className,
      };
    });
    const hdr = page.locator('header').first();
    await hdr.screenshot({ path: `${OUT}${LABEL}-header-${w}.png`, animations: 'disabled' });
    const lg = page.locator('header a.logo, header a.lg').first();
    await lg.screenshot({ path: `${OUT}${LABEL}-closeup-${w}.png`, animations: 'disabled' });
    // A real click on the logo must navigate home.
    const box = await lg.boundingBox();
    if (phone) await page.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2);
    else await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    let home = false;
    try { await page.waitForURL((u) => u.pathname === '/', { timeout: 15000 }); home = true; } catch (e) { /* */ }
    rows.push({ w, ...m, home, errs: errs.length });
    await ctx.close();
  }
  for (const x of rows) {
    console.log(`${x.w}: logo ${x.logo.l}-${x.logo.r} (w ${x.logo.w} h ${x.logo.h}) | room ${x.menuR}-${x.iconsL} (${(x.iconsL - x.menuR).toFixed(1)}) | last glyph ${x.lastGlyph} | worst clip ${x.worstClip}${x.worstCh ? ' ' + x.worstCh : ''} | overlap menu ${x.overlapMenu} icons ${x.overlapIcons} | svg ${x.svgH} | name ${x.nameSize} (${x.lines} line${x.lines > 1 ? 's' : ''}) tag ${x.tagSize} | efp ${x.efp} | click home ${x.home} | sw ${x.sw} | errs ${x.errs} | ${x.cls}`);
  }
  await browser.close();
})();
