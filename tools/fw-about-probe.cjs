// Lane FW: measure a header picture set to Full width on any page. node tools/fw-about-probe.cjs <port> [path, default /about/]
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', ignoreDefaultArgs: ['--hide-scrollbars'] });
  for (const [w, mob] of [[390, true], [1280, false], [1920, false]]) {
    const c = await b.newContext({ viewport: { width: w, height: 900 }, isMobile: mob, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36' });
    const p = await c.newPage();
    await p.goto(`http://127.0.0.1:${process.argv[2]}${process.argv[3] || '/about/'}`, { waitUntil: 'networkidle' });
    console.log(process.argv[3] || '/about/', w, JSON.stringify(await p.evaluate(() => { const i = document.querySelector('[data-kbb-ph] > picture img'); const r = i && i.getBoundingClientRect(); const t = document.querySelector('[data-kbb-ph] h1').getBoundingClientRect(); return { client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth, pic: r && [Math.round(r.left), Math.round(r.right)], h1Left: Math.round(t.left), radius: i && getComputedStyle(i).borderTopLeftRadius }; })));
    await c.close();
  }
  await b.close();
})();
