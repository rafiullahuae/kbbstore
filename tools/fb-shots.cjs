/*
 * Lane FB screenshots and measurements: the flag bar above the header.
 *
 * Everything reported here is MEASURED in the page — the strip's own box, the
 * two flags' boxes, document.documentElement.scrollWidth against clientWidth
 * (the one that says whether anything has given the page horizontal scroll),
 * and Cumulative Layout Shift collected from a PerformanceObserver armed before
 * the first byte of the document.
 *
 * Measuring HERE is not the thing CLAUDE.md rule 4 forbids. The rule is about
 * JavaScript THE SHOP SHIPS — the strip ships none at all. This is the harness
 * that checks the shipped CSS got it right, and it has to measure or it proves
 * nothing.
 *
 * ── HOW CLS IS COLLECTED, AND WHY IT IS ARMED BEFORE NAVIGATION ─────────────
 *
 * A layout shift is only reported to an observer that exists when it happens,
 * and the shifts that matter here are the ones during load — a strip whose
 * height arrives late pushes the whole page down at first paint and is gone
 * from the DOM's point of view by the time a script could ask about it. So the
 * observer is installed with addInitScript, which runs before the document's
 * own scripts, with buffered:true so nothing between installation and the first
 * frame is lost.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.FB_BASE || 'http://127.0.0.1:8978';
const OUT = process.env.FB_OUT || (__dirname + '/../docs/lane-fb-shots');

const ARM = `
  window.__cls = 0;
  window.__shifts = [];
  new PerformanceObserver((list) => {
    for (const e of list.getEntries()) {
      if (e.hadRecentInput) continue;
      window.__cls += e.value;
      window.__shifts.push({
        value: +e.value.toFixed(5),
        sources: (e.sources || []).map((s) => (s.node && s.node.nodeName
          ? s.node.nodeName + (s.node.className && typeof s.node.className === 'string'
              ? '.' + s.node.className.trim().split(/\s+/).join('.') : '')
          : '?')),
      });
    }
  }).observe({ type: 'layout-shift', buffered: true });
`;

(async () => {
  const width = +process.argv[2];
  const label = process.argv[3];
  const path = process.argv[4] || '/';

  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: process.env.FB_CHROME });
  const ctx = await browser.newContext({ viewport: { width, height: 860 }, deviceScaleFactor: 2 });
  await ctx.addInitScript(ARM);

  const page = await ctx.newPage();
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);

  const m = await page.evaluate(() => {
    const doc = document.documentElement;
    const box = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { x: +r.x.toFixed(1), y: +r.y.toFixed(1), w: +r.width.toFixed(1), h: +r.height.toFixed(1) };
    };
    const strip = document.querySelector('.kfb');
    const flags = [...document.querySelectorAll('.kfb-fl')];
    const text = document.querySelector('.kfb-tx');
    const header = document.querySelector('header');

    return {
      dir: doc.getAttribute('dir'),
      lang: doc.getAttribute('lang'),
      scrollWidth: doc.scrollWidth,
      clientWidth: doc.clientWidth,
      stripInDom: !!strip,
      stripDisplay: strip ? getComputedStyle(strip).display : null,
      strip: box(strip),
      stripBg: strip ? getComputedStyle(strip).backgroundColor : null,
      text: box(text),
      textContent: text ? text.textContent.trim() : null,
      textSize: text ? getComputedStyle(text).fontSize : null,
      textColour: text ? getComputedStyle(text).color : null,
      flagCount: flags.length,
      flagNames: flags.map((f) => f.getAttribute('aria-label')),
      flagBoxes: flags.map(box),
      header: box(header),
      cls: +(window.__cls || 0).toFixed(5),
      shifts: window.__shifts || [],
    };
  });

  const file = `${OUT}/${label}-${width}.png`;
  await page.screenshot({ path: file, fullPage: false });

  // And the strip on its own, at its real pixels, when there is one to shoot.
  if (m.stripInDom && m.stripDisplay !== 'none') {
    await page.locator('.kfb').screenshot({ path: `${OUT}/${label}-${width}-strip.png` });
  }

  console.log(JSON.stringify({ label, width, path, file, ...m }, null, 2));

  await browser.close();
})();
