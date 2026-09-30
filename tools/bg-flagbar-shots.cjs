/*
 * The flag bar, before and after the wording came off the desktop.  (Lane BG)
 *
 *   SHOT_BASE=http://127.0.0.1:8992 SHOT_LABEL=after node tools/bg-flagbar-shots.cjs
 *
 * 1280 is the width the change is about and 390 is the width that must NOT
 * move, so both are photographed on both sides. 1920 is here because the
 * emptiness the centring answers is worst on the widest strip.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.SHOT_BASE || 'http://127.0.0.1:8992';
const LABEL = process.env.SHOT_LABEL || 'after';
const OUT = process.env.SHOT_OUT || 'docs/flagbar-shots';
const WIDTHS = (process.env.SHOT_WIDTHS || '390,1280,1920').split(',').map(Number);
const PATHS = (process.env.SHOT_PATHS || '/,/shop/').split(',');

const routeUploads = async (ctx) => {
  await ctx.route('**://localhost/**', (route) => {
    const u = new URL(route.request().url());
    return route.continue({ url: BASE + u.pathname + u.search });
  });
};

const MEASURE = () => {
  const bar = document.querySelector('.kfb');
  if (!bar) return { bar: null, scrollWidth: document.documentElement.scrollWidth };

  const inner = bar.querySelector('.kfb-in');
  const tx = bar.querySelector('.kfb-tx');
  const flags = [...bar.querySelectorAll('.kfb-fl')];
  const r = (el) => { const b = el.getBoundingClientRect(); return { x: Math.round(b.left), w: Math.round(b.width), h: Math.round(b.height) }; };

  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    bar: {
      cls: bar.className.trim(),
      display: getComputedStyle(bar).display,
      height: Math.round(bar.getBoundingClientRect().height),
      innerJustify: inner ? getComputedStyle(inner).justifyContent : null,
      /* The words are IN THE MARKUP either way — one document serves both
         widths. What changes is whether they are displayed, and therefore
         whether they are in the accessibility tree. */
      textInMarkup: tx ? tx.textContent.trim() : null,
      textDisplay: tx ? getComputedStyle(tx).display : null,
      textBox: tx ? r(tx) : null,
      flags: flags.map(r),
      /* The gap the flags actually leave between them — the number the
         `justify-content` decision is about. */
      flagGap: flags.length === 2
        ? Math.round(flags[1].getBoundingClientRect().left - flags[0].getBoundingClientRect().right)
        : null,
    },
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const out = {};

  for (const uri of PATHS) {
    const name = uri === '/' ? 'home' : uri.replace(/\//g, '') || 'home';
    out[name] = {};

    for (const w of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 700 }, deviceScaleFactor: 1 });
      await routeUploads(ctx);
      const page = await ctx.newPage();
      await page.goto(BASE + uri, { waitUntil: 'networkidle' });
      await page.waitForTimeout(500);

      out[name][w] = await page.evaluate(MEASURE);

      const bar = await page.$('.kfb');
      if (bar) await bar.screenshot({ path: path.join(OUT, `${LABEL}-${name}-bar-${w}.png`) });
      await page.screenshot({ path: path.join(OUT, `${LABEL}-${name}-${w}.png`) });
      await ctx.close();
    }
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, `${LABEL}-measurements.json`), JSON.stringify(out, null, 2));

  for (const name of Object.keys(out)) {
    for (const w of WIDTHS) {
      const m = out[name][w];
      const b = m.bar;
      console.log(`${name} ${w}: sw=${m.scrollWidth}` + (b
        ? ` | bar h=${b.height} cls="${b.cls}" textDisplay=${b.textDisplay} justify=${b.innerJustify}`
          + ` flags=${JSON.stringify(b.flags.map((f) => f.x))} gap=${b.flagGap}`
        : ' | no bar'));
    }
  }
})();
