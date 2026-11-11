// Photographs out/<name>.html at its exact pixel size -> out/<name>.png + .jpg
const { chromium } = require('playwright');
const path = require('path');
const OUT = path.join(__dirname, 'out');
const SIZES = { story: [1080, 1920], post: [1080, 1350], square: [1080, 1080], 'web-desk': [3840, 1200], 'web-phone': [1200, 1540], 'web-desk-ar': [3840, 1200], 'web-phone-ar': [1200, 1540] };
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [n, [w, h]] of Object.entries(SIZES)) {
    if (process.argv[2] && process.argv[2] !== n) continue;
    const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await p.goto('file://' + path.join(OUT, n + '.html'), { waitUntil: 'networkidle' });
    await p.evaluate(async () => { await document.fonts.ready; await Promise.all([...document.images].map(i => i.complete ? 0 : new Promise(r => { i.onload = i.onerror = r; }))); });
    await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(OUT, n + '.png') });
    await p.screenshot({ path: path.join(OUT, n + '.jpg'), type: 'jpeg', quality: 92 });
    console.log(n, w + 'x' + h);
    await p.close();
  }
  await b.close();
})();
