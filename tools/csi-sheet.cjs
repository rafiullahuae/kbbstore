const { chromium } = require('playwright'); const path = require('path');
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) { const p = await b.newPage({ viewport: { width: w, height: 600 }, deviceScaleFactor: 2 });
    await p.goto('file://' + path.resolve(__dirname, `../docs/lane-csi-options/sheet-${w}.html`)); await p.waitForTimeout(300);
    await p.screenshot({ path: path.resolve(__dirname, `../docs/lane-csi-options/options-${w}.png`), fullPage: true }); }
  await b.close(); })();
