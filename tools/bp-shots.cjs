/*
 * Lane BP screenshots: the cards banner's background, its button colours, where
 * its title sits, and what the dots do.
 *
 * Run once per width per set. Everything it reports is MEASURED in the page —
 * the section's own custom properties, the card boxes, the dot geometry, and
 * document.documentElement.scrollWidth against clientWidth, which is the one
 * that says whether a bled background or a peeking track has given the page
 * horizontal scroll.
 *
 * Measuring HERE is not the thing CLAUDE.md rule 4 forbids. The rule is about
 * JavaScript THE SHOP SHIPS: a carousel that advances by reading offsetWidth.
 * This is the harness that checks the shipped CSS got it right, and it has to
 * measure or it proves nothing.
 */
const { chromium } = require('playwright');

const BASE = process.env.BP_BASE || 'http://127.0.0.1:8974';
const OUT = process.env.BP_OUT || (__dirname + '/../docs/lane-bp-shots');

(async () => {
  const width = +process.argv[2];
  const label = process.argv[3] || 'shot';
  const reduced = process.argv[4] === 'reduced';

  const browser = await chromium.launch({ executablePath: process.env.BP_CHROME });
  const ctx = await browser.newContext({
    viewport: { width, height: 1000 },
    deviceScaleFactor: 1,
    reducedMotion: reduced ? 'reduce' : 'no-preference',
  });
  const page = await ctx.newPage();

  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);

  const m = await page.evaluate(() => {
    const doc = document.documentElement;
    const root = document.querySelector('.kbbn');
    const vp = document.querySelector('.kbbn-vp');
    const cards = [...document.querySelectorAll('.kbbn-c')];
    const btn = document.querySelector('.kbbn-btn');
    const band = document.querySelector('.kbbn-bd');
    const dots = [...document.querySelectorAll('.kbbn-dot')];
    const cs = vp ? getComputedStyle(vp) : null;
    const rs = root ? getComputedStyle(root) : null;

    const box = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { w: +r.width.toFixed(2), h: +r.height.toFixed(2) };
    };

    return {
      viewport: doc.clientWidth,
      scrollWidth: doc.scrollWidth,
      horizontalScroll: doc.scrollWidth > doc.clientWidth,
      rootClass: root ? root.className : null,
      cards: cards.length,
      per: cs ? cs.getPropertyValue('--kbbn-per').trim() : null,
      peek: cs ? cs.getPropertyValue('--kbbn-peek').trim() : null,
      gap: cs ? cs.getPropertyValue('--kbbn-gap').trim() : null,
      duration: cs ? cs.getPropertyValue('--kbbn-dur').trim() : null,
      firstCard: box(cards[0]),
      distinctCardSizes: [...new Set(cards.map(c => {
        const r = c.getBoundingClientRect();
        return r.width.toFixed(1) + 'x' + r.height.toFixed(1);
      }))],
      band: band ? {
        box: box(band),
        position: getComputedStyle(band).position,
        backgroundImage: getComputedStyle(band).backgroundImage.slice(0, 60),
      } : null,
      /* THE BACKGROUND, measured rather than inferred from the class: the whole
         point of the bleed is that the painted box is WIDER than the content
         column and still inside the page. */
      background: root ? {
        color: rs.backgroundColor,
        image: rs.backgroundImage === 'none' ? 'none' : 'set',
        box: box(root),
        wrap: box(root.closest('.wrap')),
      } : null,
      button: btn ? {
        background: getComputedStyle(btn).backgroundColor,
        color: getComputedStyle(btn).color,
        box: box(btn),
      } : null,
      dots: dots.length,
      /* Chrome only. Reported so the picture and the number agree about why
         there are or are not arrows in it. */
      scrollButtonsSupported: CSS.supports('selector(::scroll-button(inline-start))'),
      hasSelector: CSS.supports('selector(:has(*))'),
      // The reduced-motion claim, measured: no animation, no transform, the
      // duplicate copy out of the layout, and the rail hand-scrollable again.
      trackAnimation: (() => {
        const tr = document.querySelector('.kbbn-tr');
        if (!tr) return null;
        const s = getComputedStyle(tr);
        return { name: s.animationName, transform: s.transform, overflowX: cs ? cs.overflowX : null };
      })(),
      duplicatesInLayout: [...document.querySelectorAll('.kbbn-dup')]
        .filter(d => getComputedStyle(d).display !== 'none').length,
      scripts: document.querySelectorAll('script').length,
    };
  });

  /* ── THE DOTS, IN THEIR ACTIVE STATE, AND WHAT PRESSING ONE COSTS ────────
     history.length is read before and after, because the dots are in-page
     anchors and the brief asks what that really does to the back button. */
  let dotState = null;

  if (m.dots > 1) {
    dotState = await page.evaluate(async () => {
      const dots = [...document.querySelectorAll('.kbbn-dot')];
      const widths = () => dots.map(d => +d.getBoundingClientRect().width.toFixed(1));

      const before = { history: history.length, hash: location.hash, widths: widths() };

      dots[2].click();
      await new Promise(r => setTimeout(r, 450));
      const afterOne = { history: history.length, hash: location.hash, widths: widths() };

      dots[4].click();
      await new Promise(r => setTimeout(r, 300));
      dots[1].click();
      await new Promise(r => setTimeout(r, 300));
      const afterFour = { history: history.length, hash: location.hash, widths: widths() };

      // Put it back on dot 3 for the photograph.
      dots[2].click();
      await new Promise(r => setTimeout(r, 450));

      return {
        before,
        afterOne,
        afterFour,
        entriesPerDot: (afterFour.history - before.history) / 4,
        activeWidths: widths(),
        activeColour: getComputedStyle(dots[2]).backgroundColor,
        inactiveColour: getComputedStyle(dots[0]).backgroundColor,
      };
    });
  }

  const section = await page.$('.kbbn');

  if (section) {
    /* Centred, not merely "into view". The shop's header is sticky, so an
       element screenshot of a section scrolled to the top of the viewport has
       the header sitting over the first 90px of it — which is a picture of the
       header, not of the row. */
    await section.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    await section.screenshot({ path: `${OUT}/${label}-${width}.png` });
  }

  await page.screenshot({ path: `${OUT}/${label}-${width}-page.png` });

  console.log(JSON.stringify({ label, width, reduced, ...m, dotState }, null, 2));

  await browser.close();
})();
