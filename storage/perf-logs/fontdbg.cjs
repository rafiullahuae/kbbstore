const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args:['--ignore-certificate-errors'] });
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  const fails = [];
  p.on('requestfailed', r => fails.push(r.url() + ' :: ' + (r.failure()||{}).errorText));
  p.on('response', r => { if (r.url().includes('woff2')) console.log('RESP', r.status(), r.url()); });
  p.on('console', m => console.log('CONSOLE', m.type(), m.text().slice(0,200)));
  await p.goto(process.argv[2], { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);
  console.log('failed:', fails);
  console.log(await p.evaluate(async () => {
    await document.fonts.ready;
    return {
      size: document.fonts.size,
      faces: [...document.fonts].map(f => f.family + '/' + f.weight + '/' + f.status).slice(0,20),
      check: document.fonts.check('13px Poppins'),
      bodyFont: getComputedStyle(document.body).fontFamily,
    };
  }));
  await b.close();
})();
