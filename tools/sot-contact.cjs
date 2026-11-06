// Lane SOT: one contact sheet of every template's shots (docs/lane-sot-shots/<tpl>-<lang>-<width>.jpg).
//   NODE_PATH=<repo>/node_modules node tools/sot-contact.cjs
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const dir = path.join(__dirname, '..', 'docs', 'lane-sot-shots');
const names = {
  super: 'Super Sale (current)', m1: '11.11 · Midnight Neon', m2: '11.11 · Pink Burst', m3: '11.11 · Blush & Gold Ticket',
  u1: 'National Day · Skyline by Day', u2: 'National Day · Golden Dusk Skyline', u3: 'National Day · Flag Sky',
  n1: 'New Year · Midnight Gold', n2: 'New Year · Champagne Blush', n3: 'New Year · Fireworks',
};
const img = (f) => `<img src="data:image/jpeg;base64,${fs.readFileSync(path.join(dir, f)).toString('base64')}">`;
let rows = '';
for (const lang of ['en', 'ar']) {
  for (const [k, n] of Object.entries(names)) {
    rows += `<section><h2>${n} — ${lang === 'ar' ? 'Arabic' : 'English'}</h2><div class="r"><div class="d">${img(`${k}-${lang}-1280.jpg`)}</div><div class="p">${img(`${k}-${lang}-390.jpg`)}</div></div></section>`;
  }
}
const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:16px;background:#ece8ea;font:600 15px system-ui,sans-serif;color:#2a2228;width:1700px}
h2{font-size:15px;margin:14px 0 6px}.r{display:flex;gap:14px;align-items:flex-start}.d img{width:1240px;display:block;border-radius:8px}.p img{width:390px;display:block;border-radius:8px}</style>${rows}`;

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await browser.newContext({ viewport: { width: 1732, height: 900 } })).newPage();
  await page.setContent(html);
  await page.screenshot({ path: path.join(dir, 'contact-sheet.jpg'), type: 'jpeg', quality: 70, fullPage: true });
  await browser.close();
})();
