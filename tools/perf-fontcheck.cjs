/*
 * Lane PERF — does the page actually get its fonts?
 *
 * Written after an hour lost to an instrument bug: php -S's own static handler
 * answered some of a dozen concurrent small files with
 * net::ERR_INVALID_HTTP_RESPONSE, so Chromium reported four @font-face as
 * `status: "error"` and fell back to the system face -- on a shop whose font
 * files curl fetched happily one at a time. `document.fonts` says which faces
 * a page HAS and which it LOADED, and the two are not the same question.
 *
 *   node tools/perf-fontcheck.cjs http://127.0.0.1:8991/
 */
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
