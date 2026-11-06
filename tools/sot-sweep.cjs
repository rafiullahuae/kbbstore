// Lane SOT: the alignment sweep and the screenshots of every Super Sale banner template.
//   sh tools/sot-preview.sh            (once; prints the URL)
//   NODE_PATH=<repo>/node_modules node tools/sot-sweep.cjs http://127.0.0.1:11780 [templates]
// For each template (switched with tools/sot-tinker.sh, the editor's own
// withTemplate()), English and Arabic, at every width: scrollWidth == width,
// every line of the copy (logos, tagline, number, title, coupon) inside the
// hero, the title clear of the big number and of the picture, the gift row
// whole, and no console error. Shots of the banner at 390 and 1280 go to
// docs/lane-sot-shots/, the numbers to docs/lane-sot-shots/sweep.json.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const base = process.argv[2];
const only = process.argv[3] ? process.argv[3].split(',') : null;
const APP = path.join(__dirname, '..');
const out = path.join(APP, 'docs', 'lane-sot-shots');
fs.mkdirSync(out, { recursive: true });
const TEMPLATES = (only || ['super', 'm1', 'm2', 'm3', 'u1', 'u2', 'u3', 'n1', 'n2', 'n3']);
const WIDTHS = [320, 360, 390, 414, 600, 768, 900, 901, 1024, 1280, 1440, 1920];
const SHOTS = [390, 1280];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = {};
  for (const tpl of TEMPLATES) {
    execSync(`sh ${path.join(APP, 'tools', 'sot-tinker.sh')}`, { env: { ...process.env, SOT_TPL: tpl }, stdio: 'ignore' });
    for (const lang of ['en', 'ar']) {
      const ctx = await browser.newContext({ viewport: { width: 390, height: 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      // One load first: the preview makes a gift photo's img-cache copy on its
      // first request (a 404 the first time, then the file) -- not the banner.
      await page.goto(base + (lang === 'ar' ? '/ar/super-sale/' : '/super-sale/'), { waitUntil: 'networkidle' });
      for (const width of WIDTHS) {
        errors.length = 0;
        await page.setViewportSize({ width, height: 900 });
        await page.goto(base + (lang === 'ar' ? '/ar/super-sale/' : '/super-sale/'), { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts && document.fonts.ready);
        const m = await page.evaluate(() => {
          const box = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return b.width || b.height ? { l: b.left, r: b.right, t: b.top, b: b.bottom, w: b.width, h: b.height } : null; };
          const q = (s) => box(document.querySelector(s));
          const inside = (a, h) => !a || (a.l >= h.l - 1 && a.r <= h.r + 1 && a.t >= h.t - 1 && a.b <= h.b + 1);
          const meet = (a, b) => (a && b ? Math.max(0, Math.min(a.r, b.r) - Math.max(a.l, b.l)) * Math.max(0, Math.min(a.b, b.b) - Math.max(a.t, b.t)) : 0);
          // A line of text: the box its glyphs fill (a Range), not its block's width.
          // The Range's box is the font's whole line (ascent to descent); the
          // letters fill roughly its middle two thirds, so a stacked line that
          // only touches the next one's leading is not reported as on top of it.
          const ink = (s) => { const el = document.querySelector(s); if (!el || !el.firstChild) return null; const rg = document.createRange(); rg.selectNodeContents(el); return box(rg); };
          const glyphs = (b) => (b ? { ...b, t: b.t + b.h * 0.18, b: b.b - b.h * 0.18 } : null);
          const root = document.querySelector('.kbb-ssb');
          const hero = q('.ssb-hero');
          const lines = { pay: q('.ssb-pay'), tag: ink('.ssb-tag'), num: ink('.ssb-num'), title: ink('.ssb-super'), coupon: q('.ssb-coupon') };
          const pic = q('.ssb-model img');
          const gifts = document.querySelector('.ssb-gifts');
          const gb = box(gifts);
          const clipped = Object.entries(lines).filter(([, b]) => !inside(b, hero)).map(([k]) => k);
          const sel = ['.ssb-gi', '.ssb-circs', '.ssb-free'].map((s) => [s, q(s)]);
          const giftClipped = sel.filter(([, b]) => b && !inside(b, gb)).map(([s]) => s);
          return {
            cls: root ? root.className : null, scrollWidth: document.documentElement.scrollWidth, innerWidth,
            banner: q('.kbb-ssb'), hero, clipped,
            titleNum: Math.round(meet(glyphs(lines.title), glyphs(lines.num))), titlePic: Math.round(meet(glyphs(lines.title), pic)), numPic: Math.round(meet(glyphs(lines.num), pic)),
            couponPic: Math.round(meet(lines.coupon, pic)), tagPic: Math.round(meet(glyphs(lines.tag), pic)),
            titleOverflow: (() => { const t = document.querySelector('.ssb-super'); return t ? t.scrollWidth - t.clientWidth : 0; })(),
            giftsH: gb ? Math.round(gb.h) : 0, giftClipped, giftOverflow: gifts ? gifts.scrollWidth - gifts.clientWidth : 0,
          };
        });
        m.errors = [...errors];
        m.ok = m.scrollWidth === width && m.clipped.length === 0 && m.titleNum === 0 && m.titlePic === 0 && m.numPic === 0 && m.couponPic === 0
          && m.tagPic === 0 && m.titleOverflow <= 1 && m.giftsH > 0 && m.giftClipped.length === 0 && m.giftOverflow <= 1 && m.errors.length === 0;
        results[`${tpl} ${lang} ${width}`] = m;
        process.stdout.write(`${m.ok ? 'ok ' : 'BAD'} ${tpl} ${lang} ${width}${m.ok ? '' : ' ' + JSON.stringify({ sw: m.scrollWidth, clipped: m.clipped, titleNum: m.titleNum, titlePic: m.titlePic, numPic: m.numPic, couponPic: m.couponPic, tagPic: m.tagPic, titleOverflow: m.titleOverflow, gifts: [m.giftsH, m.giftClipped, m.giftOverflow], errors: m.errors })}\n`);
        if (SHOTS.includes(width)) {
          const el = await page.$('.kbb-ssb');
          if (el) await el.screenshot({ path: path.join(out, `${tpl}-${lang}-${width}.jpg`), type: 'jpeg', quality: 82 });
        }
      }
      await ctx.close();
    }
  }
  const file = path.join(out, 'sweep.json');
  const all = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
  fs.writeFileSync(file, JSON.stringify({ ...all, ...results }, null, 1));
  await browser.close();
})();
