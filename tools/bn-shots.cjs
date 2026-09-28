/*
 * Lane BN screenshots: the cards banner on the homepage.
 *
 * Run once per width. Everything it reports is MEASURED in the page — the
 * section's own numbers, the card boxes, and document.documentElement
 * .scrollWidth against clientWidth, which is the one that says whether a
 * peeking track has given the page horizontal scroll.
 *
 * Measuring HERE is not the thing CLAUDE.md rule 4 forbids. The rule is about
 * JavaScript THE SHOP SHIPS: a carousel that advances by reading offsetWidth.
 * This is the harness that checks the shipped CSS got it right, and it has to
 * measure or it proves nothing.
 */
const { chromium } = require('playwright');

const BASE = process.env.BN_BASE || 'http://127.0.0.1:8973';
const OUT = process.env.BN_OUT || (__dirname + '/../docs/lane-bn-shots');

(async () => {
  const width = +process.argv[2];
  const label = process.argv[3] || 'on';
  const browser = await chromium.launch({ executablePath: process.env.BN_CHROME });
  /* `reduced` as the third argument drives the whole page with
     prefers-reduced-motion: reduce, which is how the "stops dead" claim is
     photographed rather than asserted. */
  const ctx = await browser.newContext({
    viewport: { width, height: 900 },
    deviceScaleFactor: 1,
    reducedMotion: label === 'reduced' ? 'reduce' : 'no-preference',
  });
  const page = await ctx.newPage();

  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);

  const m = await page.evaluate(() => {
    const doc = document.documentElement;
    const vp = document.querySelector('.kbbn-vp');
    const cards = [...document.querySelectorAll('.kbbn-c')];
    const first = cards[0];
    const band = document.querySelector('.kbbn-bd');
    const imgs = [...document.querySelectorAll('.kbbn-c img')];
    const cs = vp ? getComputedStyle(vp) : null;

    const box = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { w: +r.width.toFixed(2), h: +r.height.toFixed(2) };
    };

    return {
      viewport: doc.clientWidth,
      scrollWidth: doc.scrollWidth,
      horizontalScroll: doc.scrollWidth > doc.clientWidth,
      sectionPresent: !!vp,
      cards: cards.length,
      per: cs ? cs.getPropertyValue('--kbbn-per').trim() : null,
      peek: cs ? cs.getPropertyValue('--kbbn-peek').trim() : null,
      gap: cs ? cs.getPropertyValue('--kbbn-gap').trim() : null,
      duration: cs ? cs.getPropertyValue('--kbbn-dur').trim() : null,
      trackWidth: box(document.querySelector('.kbbn-tr')),
      // Every card the same size is the owner's rule; these are the boxes.
      cardBoxes: cards.slice(0, 8).map(box),
      distinctCardSizes: [...new Set(cards.map(c => {
        const r = c.getBoundingClientRect();
        return r.width.toFixed(1) + 'x' + r.height.toFixed(1);
      }))],
      firstCard: box(first),
      band: box(band),
      lcp: imgs.length ? {
        fetchpriority: imgs[0].getAttribute('fetchpriority'),
        loading: imgs[0].getAttribute('loading'),
        width: imgs[0].getAttribute('width'),
        height: imgs[0].getAttribute('height'),
      } : null,
      laterImagesLazy: imgs.slice(1).every(i => i.getAttribute('loading') === 'lazy'),
      scripts: document.querySelectorAll('script').length,
      // The reduced-motion claim, measured: the track carries no animation at
      // all and no transform, the duplicate copy has left the layout, and the
      // scroller is a rail the shopper can push by hand again.
      trackAnimation: document.querySelector('.kbbn-tr')
        ? getComputedStyle(document.querySelector('.kbbn-tr')).animationName : null,
      trackTransform: document.querySelector('.kbbn-tr')
        ? getComputedStyle(document.querySelector('.kbbn-tr')).transform : null,
      duplicatesInLayout: [...document.querySelectorAll('.kbbn-dup')]
        .filter(el => getComputedStyle(el).display !== 'none').length,
      scrollerOverflow: vp ? getComputedStyle(vp).overflowX : null,
    };
  });

  console.log(JSON.stringify({ width, label, ...m }, null, 2));

  const section = await page.$('.kbbn');

  if (section) {
    await section.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
    await section.screenshot({ path: `${OUT}/home-${label}-${width}.png` });
  }

  await page.screenshot({ path: `${OUT}/home-${label}-${width}-full.png`, fullPage: false });

  await browser.close();
})();
