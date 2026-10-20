// node tools/shoot.mjs  -> out/<id>.png for every .frame in all.html
const { chromium } = await import('/opt/node22/lib/node_modules/playwright/index.mjs');
const fs = await import('fs'); const path = await import('path');
const d = path.dirname(path.dirname(new URL(import.meta.url).pathname));
fs.mkdirSync(d + '/out', { recursive: true });
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const p = await b.newPage({ viewport: { width: 1200, height: 2000 } });
await p.goto('file://' + d + '/all.html'); await p.waitForTimeout(1000);
for (const id of await p.$$eval('.frame', e => e.map(x => x.id))) await p.locator('#' + id).screenshot({ path: d + '/out/' + id + '.png' });
await b.close();
