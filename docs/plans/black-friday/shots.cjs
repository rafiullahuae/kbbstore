// Draws each Black Friday design on the real Super Sale banner and photographs it.
//   sh tools/sot-preview.sh 10141 && SOT_TPL=m1 sh tools/sot-tinker.sh
//   NODE_PATH=<repo>/node_modules node docs/plans/black-friday/shots.cjs
const { chromium } = require('playwright');
const path = require('path');
const D = require('./designs.cjs');
const BASE = process.env.SOT_BASE || 'http://127.0.0.1:10141';
const OUT = path.join(__dirname, 'shots');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const res = {};
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    const errs = []; p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
    await p.goto(BASE + '/super-sale/', { waitUntil: 'networkidle' });
    for (const [k, d] of Object.entries(D)) {
      await p.evaluate(([k, d]) => {
        const el = document.querySelector('.kbb-ssb'); el.classList.remove('bf'); el.className = el.className.replace(/\bbf-\w\b/g, '');
        el.classList.add('bf');
        let st = document.getElementById('bf-css'); if (!st) { st = document.createElement('style'); st.id = 'bf-css'; document.head.appendChild(st); }
        st.textContent = d.css;
        const copy = el.querySelector('.ssb-copy');
        el.querySelector('.ssb-super').textContent = d.words.title;
        let tag = copy.querySelector('.ssb-tag');
        if (d.words.tag) { if (!tag) { tag = document.createElement('span'); tag.className = 'ssb-tag'; copy.querySelector('.ssb-pay').after(tag); } tag.textContent = d.words.tag; } else if (tag) tag.remove();
        const num = copy.querySelector('.ssb-num');
        if (num) { num.textContent = d.words.num; num.style.display = d.words.num ? '' : 'none'; }
      }, [k, d]);
      await p.waitForTimeout(250);
      const box = p.locator('.kbb-ssb');
      await box.screenshot({ path: path.join(OUT, `${k}-${w}.png`) });
      res[`${k}-${w}`] = await p.evaluate(() => { const r = document.querySelector('.kbb-ssb').getBoundingClientRect(); return { h: Math.round(r.height), sw: document.documentElement.scrollWidth }; });
    }
    res['errors-' + w] = errs;
    await ctx.close();
  }
  // One sheet to choose from.
  const fs = require('fs');
  const img = (f) => 'data:image/png;base64,' + fs.readFileSync(path.join(OUT, f)).toString('base64');
  let rows = '';
  for (const [k, d] of Object.entries(D)) rows += `<section><h2>${d.name}</h2><p>${d.line}</p><div class="r"><img class="dk" src="${img(k + '-1280.png')}"><img class="ph" src="${img(k + '-390.png')}"></div></section>`;
  const html = `<!doctype html><meta charset="utf-8"><title>Black Friday banner designs</title><style>body{margin:0;padding:20px;background:#ece8ea;font:15px system-ui,sans-serif;color:#2a2228;max-width:1800px}h1{font-size:22px;margin:0 0 4px}h2{font-size:17px;margin:22px 0 2px}p{margin:0 0 8px;color:#5a5058}.r{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap}.dk{width:min(1280px,100%);border-radius:10px}.ph{width:390px;max-width:100%;border-radius:10px}</style><h1>Black Friday · Super Sale banner · pick a letter</h1><p>Each design drawn on the real banner (your picture, coupon, tabby/tamara and free gifts row), computer 1280 and phone 390. Every word and colour stays editable after you pick.</p>${rows}`;
  fs.writeFileSync(path.join(__dirname, 'preview.html'), html);
  const sp = await (await b.newContext({ viewport: { width: 1760, height: 900 } })).newPage();
  await sp.setContent(html); await sp.screenshot({ path: path.join(__dirname, 'black-friday-designs.jpg'), type: 'jpeg', quality: 80, fullPage: true });
  console.log(JSON.stringify(res));
  await b.close();
})();
