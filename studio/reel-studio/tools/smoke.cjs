// quick: open the studio, create a reel from the portrait clip, print console messages
'use strict';
const path = require('path');
const fs = require('fs');
const H = require('./studio-harness.cjs');
(async () => {
  const dir = H.makeStudio();
  const e = await H.startEngine(dir);
  const b = await H.launchChrome();
  const page = await b.newPage({ viewport: { width: 1280, height: 800 } });
  page.on('console', (m) => console.log('[console]', m.type(), m.text()));
  page.on('pageerror', (err) => console.log('[pageerror]', err.message));
  await page.goto(e.url + '/app/');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(H.ROOT, 'tools/out/smoke-home.png') });
  const later = await page.$('#su-later'); if (later) await later.click();
  await page.setInputFiles('.home-drop input[type=file]', path.join(H.ROOT, 'tools/out/portrait.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 20000 });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(H.ROOT, 'tools/out/smoke-editor.png') });
  console.log(await page.evaluate(() => JSON.stringify({ media: kbbEditor.project.media, items: kbbEditor.project.tracks.map((t) => t.items.length) })));
  await b.close(); await H.stopEngine(e);
  fs.rmSync(dir, { recursive: true, force: true });
})().catch((x) => { console.error(x); process.exit(1); });
