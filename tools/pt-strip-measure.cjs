const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
  await p.goto('http://127.0.0.1:51237/product/lanept-heartleaf-toner/', { waitUntil: 'networkidle' });
  for (const w of [1280, 900, 760, 730, 720, 390]) {
    await p.setViewportSize({ width: w, height: 1000 });
    await p.waitForTimeout(250);
    console.log(JSON.stringify(await p.evaluate(() => {
      const s = document.querySelector('.dtabbar');
      const a = document.querySelector('.macc');
      const cs = getComputedStyle(s);
      return {
        viewport: document.documentElement.clientWidth,
        pageScrollWidth: document.documentElement.scrollWidth,
        pageOverflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        stripDisplay: cs.display,
        stripOverflowX: cs.overflowX,
        stripWhiteSpace: getComputedStyle(document.querySelector('.dtab')).whiteSpace,
        stripScrollWidth: s.scrollWidth, stripClientWidth: s.clientWidth,
        stripScrolls: s.scrollWidth > s.clientWidth,
        accordionDisplay: getComputedStyle(a).display,
        accordionHeadingWraps: [...document.querySelectorAll('.macc-h')].map(n => Math.round(n.getBoundingClientRect().height)),
        tabFontSize: getComputedStyle(document.querySelector('.dtab')).fontSize,
        accFontSize: getComputedStyle(document.querySelector('.macc-h')).fontSize,
      };
    })));
  }
  await b.close();
})();
