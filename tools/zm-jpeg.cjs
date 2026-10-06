/*
 * LANE ZM — the screenshots as JPEGs small enough to commit. The PNGs are what
 * zm-shots.cjs diffs (docs/zm-shots/diff-1280.json); this keeps the pictures,
 * the 390 ones at half their DPR-3 size, and removes the PNGs.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const DIR = path.join(__dirname, '..', 'docs', 'zm-shots');
(async () => {
  const b = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  const p = await b.newPage();
  for (const f of fs.readdirSync(DIR).filter((x) => x.endsWith('.png'))) {
    const scale = f.includes('-390--') ? 0.5 : 1;
    const jpg = await p.evaluate(async ([src, s]) => {
      const i = await new Promise((ok) => { const im = new Image(); im.onload = () => ok(im); im.src = src; });
      const c = document.createElement('canvas'); c.width = Math.round(i.width * s); c.height = Math.round(i.height * s);
      c.getContext('2d').drawImage(i, 0, 0, c.width, c.height);
      return c.toDataURL('image/jpeg', 0.78).split(',')[1];
    }, ['data:image/png;base64,' + fs.readFileSync(path.join(DIR, f)).toString('base64'), scale]);
    fs.writeFileSync(path.join(DIR, f.replace(/\.png$/, '.jpg')), Buffer.from(jpg, 'base64'));
    fs.unlinkSync(path.join(DIR, f));
  }
  await b.close();
})();
