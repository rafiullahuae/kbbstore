// 2.60.508 PREVIEW (nothing on the shop changes): the live product page with its
// detail tabs rebuilt as Description / Ingredients / How to Use / Shipping Policy
// / Return Policy -- How to Use lifted out of the description by
// RichText::splitHowToUse(), the description through the 2.60.507 pipeline and
// stylesheet, and the two shop-wide tabs with sample text. Empty tabs are not drawn.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const CSS_OLD = 'kbb-product-DWiP7C6M.css', CSS_NEW = process.env.CSS_NEW;
const out = 'docs/lane-co13-shots/tabs-preview-508/';
const php = (f, input) => execFileSync('php', ['storage/cpb-logs/ds507/' + f], { input }).toString();
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
const SHIP = '<p><b>Shipping Policy</b></p><p>Sample text — the real policy is written in Catalog → Product tabs → Shipping Policy, and shows on every product.</p><ul><li>Free delivery across the UAE over AED 199.</li><li>Dubai &amp; Sharjah: next day. Other Emirates: 1–3 working days.</li></ul>';
const RET = '<p><b>Return Policy</b></p><p>Sample text — the real policy is written in Catalog → Product tabs → Return Policy, and shows on every product.</p><ul><li>Unopened items can be returned within 7 days.</li></ul>';
function rebuild(html) {
  const a = html.indexOf('<div class="details" id="details">');
  // The block ends after the phone accordion; a description can hold </section> itself.
  const m = html.indexOf('<div class="macc">', a);
  const b = html.indexOf('\n</div>\n  </section>', m) + '\n</div>\n'.length;
  const block = html.slice(a, b);
  const titles = [...block.matchAll(/<button class="dtab[^"]*" type="button" data-i="\d+">([^<]*)<\/button>/g)].map(m => m[1]);
  const bodies = [...block.matchAll(/<div class="dtabpanel[^"]*" data-panel="\d+">\s*<div class="dcontent[^"]*">([\s\S]*?)<\/div>\s*<button class="readmore"/g)].map(m => m[1]);
  let desc = '', ingTitle = null, ing = '', how = '';
  titles.forEach((t, i) => {
    if (i === 0) desc = bodies[i];
    else if (/ingredient/i.test(t)) { ingTitle = t; ing = bodies[i]; }
    else if (/how to use/i.test(t)) how = bodies[i];
  });
  desc = php('transform.php', desc);
  const split = JSON.parse(php('howto.php', desc));
  desc = split.description; if (!how) how = split.how;
  const tabs = [['Description', desc], [ingTitle || 'Ingredients', ing], ['How to Use', how], ['Shipping Policy', SHIP], ['Return Policy', RET]]
    .filter(([, body]) => body.replace(/<[^>]*>/g, '').trim() !== '');
  const bar = tabs.map(([t], i) => `<button class="dtab${i ? '' : ' on'}" type="button" data-i="${i}">${esc(t)}</button>`).join('');
  const panels = tabs.map(([, body], i) => `<div class="dtabpanel${i ? '' : ' on'}" data-panel="${i}"><div class="dcontent clamp">${body}</div><button class="readmore" type="button">Read more ↓</button></div>`).join('');
  const acc = tabs.map(([t, body], i) => `<div class="macc-i${i ? '' : ' open'}"><button class="macc-h" type="button" data-i="${i}">${esc(t)}<span class="pm">${i ? '+' : '−'}</span></button><div class="macc-b"><div class="dcontent open">${body}</div></div></div>`).join('');
  const nb = `<div class="details" id="details"><div class="dtabbar">${bar}</div><div class="dpanel" id="dpanel">${panels}</div><div class="macc">${acc}</div></div>\n`;
  return { html: html.slice(0, a) + nb + html.slice(b), tabs: tabs.map(t => t[0]) };
}
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const slug of (process.env.SLUGS || '').split(',')) {
    const url = `https://kbeautybliss.com/product/${slug}/`;
    for (const w of [1280, 390]) {
      const p = await b.newPage({ viewport: { width: w, height: 900 } });
      const errs = []; p.on('pageerror', e => errs.push(String(e)));
      let tabs = [];
      await p.route(url, async r => { const res = await r.fetch(); const x = rebuild(await res.text()); tabs = x.tabs; await r.fulfill({ response: res, body: x.html }); });
      await p.route('**/build/assets/' + CSS_OLD, r => r.fulfill({ contentType: 'text/css', body: fs.readFileSync('public/build/assets/' + CSS_NEW) }));
      await p.goto(url, { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(1500);
      await p.evaluate(() => document.querySelectorAll('header,[class*="whatsapp"],[class*="wa-"]').forEach(e => e.style.visibility = 'hidden'));
      const shots = w > 900 ? tabs.map((t, i) => [t, `.dtabbar .dtab[data-i="${i}"]`]) : [['accordion', null], ['How to Use', '.macc-h']];
      for (const [t, sel] of shots) {
        if (w > 900) { await p.evaluate(s => document.querySelector(s).click(), sel); await p.waitForTimeout(250);
          await p.evaluate(() => { const el = document.querySelector('.dtabpanel.on .dcontent'); el.classList.remove('clamp'); el.style.maxHeight = 'none'; }); }
        else if (t === 'How to Use') { await p.evaluate(() => { const h = [...document.querySelectorAll('.macc-h')].find(x => /How to Use/.test(x.textContent)); if (h) h.click(); }); await p.waitForTimeout(300); }
        const el = await p.$('#details'); await el.scrollIntoViewIfNeeded();
        const name = `${slug}-${w}-${t.replace(/\W+/g, '-').toLowerCase()}.png`;
        await el.screenshot({ path: out + name });
      }
      console.log(slug, w, JSON.stringify(tabs), 'scrollWidth', await p.evaluate(() => document.documentElement.scrollWidth), errs.length ? errs : '');
      await p.close();
    }
  }
  await b.close();
})();
