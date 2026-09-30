/*
 * Lane BG, the homepage-width round: the home page at five widths, before and
 * after, with the numbers read OUT OF THE DOCUMENT rather than off the CSS.
 *
 *   SHOT_BASE=http://127.0.0.1:8990 SHOT_LABEL=before \
 *   SHOT_OUT=docs/home-width-shots node tools/bg-width-shots.cjs
 *
 * 1920 is the width the cap is about and 1440 is the one in between, because
 * "auto adjusted to the screen sizes below 1920px width" is a claim that only
 * a width between the cap and the desktop can check. 320 is the narrowest the
 * shop supports.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.SHOT_BASE || 'http://127.0.0.1:8990';
const LABEL = process.env.SHOT_LABEL || 'after';
const OUT = process.env.SHOT_OUT || 'docs/home-width-shots';
const WIDTHS = (process.env.SHOT_WIDTHS || '320,390,1280,1440,1920').split(',').map(Number);

/* The preview has no APP_URL, so asset() builds http://localhost/... for every
   upload and the browser asks a host that is not this server. Route them at the
   preview rather than photograph broken-image icons — the mistake the 330 run
   made and recorded. */
const routeUploads = async (ctx) => {
  await ctx.route('**://localhost/**', (route) => {
    const u = new URL(route.request().url());
    return route.continue({ url: BASE + u.pathname + u.search });
  });
};

const MEASURE = () => {
  const px = (v) => Math.round(parseFloat(v) || 0);
  const secs = [...document.querySelectorAll('.kbb-home > .sec')];

  const sections = secs.map((s, i) => {
    const w = s.querySelector(':scope > .wrap');
    const cs = w ? getComputedStyle(w) : null;
    /* The PHONE draws a `panel` section as a full-width band on the <section>
       itself rather than as a card on the wrap, so a reading that only looked
       at the wrap would report "no background" on a section that has one. */
    const scs = getComputedStyle(s);
    return {
      i,
      cls: s.className.trim(),
      secBg: scs.backgroundColor,
      secBgImage: scs.backgroundImage === 'none' ? 'none' : 'set',
      /* NON-EMPTY IS ASSERTED, not assumed: a width reading off a section that
         rendered no children is the false green this round was warned about. */
      children: w ? w.children.length : 0,
      wrapWidth: w ? Math.round(w.getBoundingClientRect().width) : null,
      wrapLeft: w ? Math.round(w.getBoundingClientRect().left) : null,
      bg: cs ? cs.backgroundColor : null,
      bgImage: cs ? (cs.backgroundImage === 'none' ? 'none' : 'set') : null,
      radius: cs ? cs.borderTopLeftRadius : null,
      shadow: cs ? (cs.boxShadow === 'none' ? 'none' : 'set') : null,
      border: cs ? cs.borderTopWidth : null,
      padInline: cs ? cs.paddingLeft : null,
    };
  });

  const vp = document.querySelector('.kbbs-vp');
  const vpcs = vp ? getComputedStyle(vp) : null;
  const imgs = [...document.querySelectorAll('.kbbs-a img')];

  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    innerWidth: window.innerWidth,
    sectionCount: secs.length,
    sections,
    banner: vp ? {
      width: Math.round(vp.getBoundingClientRect().width),
      left: Math.round(vp.getBoundingClientRect().left),
      height: Math.round(vp.getBoundingClientRect().height),
      radius: vpcs.borderTopLeftRadius,
      shadow: vpcs.boxShadow === 'none' ? 'none' : vpcs.boxShadow,
      slides: document.querySelectorAll('.kbbs-s').length,
      /* An <img> element is not a picture. */
      painted: imgs.filter((im) => im.naturalWidth > 0).length,
    } : null,
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const out = {};

  for (const w of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
    await routeUploads(ctx);
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);

    out[w] = await page.evaluate(MEASURE);

    await page.screenshot({ path: path.join(OUT, `${LABEL}-home-${w}.png`) });
    if (w === 1280 || w === 1920 || w === 390) {
      await page.screenshot({ path: path.join(OUT, `${LABEL}-home-${w}-full.png`), fullPage: true });
    }
    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, `${LABEL}-measurements.json`), JSON.stringify(out, null, 2));

  for (const w of WIDTHS) {
    const m = out[w];
    const b = m.banner;
    console.log(`${w}: scrollWidth=${m.scrollWidth} client=${m.clientWidth} sections=${m.sectionCount}` +
      ` wrap0=${m.sections[0] ? m.sections[0].wrapWidth : '-'}(${m.sections[0] ? m.sections[0].children : '-'} kids)` +
      ` bg0=${m.sections[0] ? m.sections[0].bg : '-'}` +
      (b ? ` | banner w=${b.width} left=${b.left} r=${b.radius} sh=${b.shadow === 'none' ? 'none' : 'set'} painted=${b.painted}/${b.slides}` : ' | no banner'));
  }
})();
