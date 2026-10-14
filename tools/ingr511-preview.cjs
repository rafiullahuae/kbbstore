// 2.60.511 picture: the live product page with the Ingredients tab the
// migration's list gives it (inserted after Description, as the shop orders it).
const { chromium } = require('playwright');
const fs = require('fs');
const [pickFile, out] = process.argv.slice(2);
const { slug, body } = JSON.parse(fs.readFileSync(pickFile, 'utf8'))[0];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    const url = `https://kbeautybliss.com/product/${slug}/`;
    await p.route(url, async r => {
      const res = await r.fetch(); let h = await res.text();
      h = h.replace(/(<div class="dtabbar">\s*<button class="dtab on" type="button" data-i="0">Description<\/button>)/, '$1<button class="dtab" type="button" data-i="9">Ingredients</button>');
      h = h.replace(/(<div class="dpanel" id="dpanel">)/, `$1<div class="dtabpanel" data-panel="9"><div class="dcontent clamp">${body}</div><button class="readmore" type="button">Read more ↓</button></div>`);
      h = h.replace(/(<div class="macc">)/, `$1<div class="macc-i"><button class="macc-h" type="button" data-i="9">Ingredients<span class="pm">+</span></button><div class="macc-b"><div class="dcontent open">${body}</div></div></div>`);
      await r.fulfill({ response: res, body: h });
    });
    await p.goto(url, { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(1500);
    await p.evaluate((w) => { document.querySelectorAll('header,[class*="whatsapp"]').forEach(e => e.style.visibility = 'hidden');
      if (w > 900) { const t = document.querySelector('.dtab[data-i="9"]'); t.click(); const on = document.querySelector('.dtabpanel[data-panel="9"]'); document.querySelectorAll('.dtabpanel').forEach(x => x.classList.toggle('on', x === on)); document.querySelectorAll('.dtab').forEach(x => x.classList.toggle('on', x === t)); on.querySelector('.dcontent').classList.remove('clamp'); }
      else { const h = document.querySelector('.macc-h[data-i="9"]'); h.closest('.macc-i').classList.add('open'); } }, w);
    const el = await p.$('#details'); await el.scrollIntoViewIfNeeded();
    await el.screenshot({ path: `${out}/${slug}-${w}.png` });
    console.log(w, 'sw', await p.evaluate(() => document.documentElement.scrollWidth));
    await p.close();
  }
  await b.close();
})();
