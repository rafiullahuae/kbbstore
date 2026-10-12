/* Lane VAR (previews only): draws the colour/size picker designs onto the LIVE
   shop's own pages -- the "cushion" search grid and the Missha BB Cream product
   page -- and photographs them at 390 and 1280. Nothing is sent to the shop; the
   designs are added in the browser only. node tools/var-live-shots.cjs */
const pw = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = path.resolve(__dirname, '../docs/variant-options/live');
fs.mkdirSync(OUT, { recursive: true });
const BASE = process.env.BASE || 'https://kbeautybliss.com';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

// The shop's real options (names, prices, stock from the live pages); colours are shade approximations.
const DATA = {
  'missha-m-perfect-cover-bb-cream-spf-42-pa50ml': { shades: [['#13 Bright Beige', '#F1D2B6', 139], ['#21 Light Beige', '#E8C3A0', 125], ['#27 Honey Beige', '#D3A47A', 128]] },
  'heimish-artless-perfect-cushion-set-2-colors': { shades: [['#21 Light Beige', '#EAC7A6', 108, 1], ['#23 Natural Beige', '#DDB48F', 108, 1]] },
  'tirtir-mask-fit-red-cushion-foundation-9-shades': { shades: [['17C Porcelain', '#F3D9C7', 145, 1], ['21N Ivory', '#EED0B3', 145, 1], ['21W Natural Ivory', '#EDCBA6', 145, 1], ['23N Sand', '#E2BC98', 145, 1], ['24N Latte', '#D9B08A', 145, 1], ['24W Soft Beige', '#DDB385', 145, 1], ['25N Mocha', '#CFA27A', 145, 1], ['27N Camel', '#C2925F', 145, 1], ['29N Natural Beige', '#B4835A', 145, 1]] },
};

const CSS = `
.kv-row{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin:6px 0 4px}
.kv-sw{width:16px;height:16px;border-radius:50%;background:var(--c);box-shadow:inset 0 0 0 1px rgba(0,0,0,.14);flex:none;position:relative;display:inline-block}
.kv-sw.on{box-shadow:0 0 0 2px #fff,0 0 0 3.5px #2A2228}
.kv-sw.out{opacity:.5}
.kv-sw.out::after{content:"";position:absolute;inset:0;border-radius:50%;background:linear-gradient(to top right,transparent 46%,#fff 46%,#fff 54%,transparent 54%)}
.kv-more,.kv-lbl{font-size:12px;color:#756C74}
.kv-lbl b{color:#2A2228;font-weight:600}
.kvB .kv-sw{width:20px;height:20px}
.kv-glass{position:absolute;inset-inline-start:8px;top:8px;background:rgba(255,255,255,.85);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);border-radius:99px;padding:5px 7px;display:flex;gap:6px;align-items:center;z-index:3}
.kv-strip{display:flex;height:9px;gap:2px;margin:6px 0 2px}
.kv-strip i{flex:1;border-radius:3px;background:var(--c)}
.kv-strip i.on{transform:scaleY(1.6);box-shadow:0 0 0 1.5px #fff,0 0 0 3px #2A2228}
.kv-strip i.out{opacity:.35}
.kv-qp{position:absolute;inset-inline:0;bottom:0;background:#fff;border-top:1px solid #EADFE3;padding:12px 10px;display:grid;gap:8px;z-index:4;box-shadow:0 -10px 24px -14px rgba(42,34,40,.25)}
.kv-qp .kv-add{border:0;background:#2A2228;color:#fff;border-radius:10px;padding:9px;font:600 13px Outfit,system-ui,sans-serif}
/* product page */
.kv-p{display:grid;gap:10px;margin:4px 0 6px}
.kv-p .kv-sw{width:34px;height:34px}
.kv-sqs{display:flex;gap:8px;flex-wrap:wrap}
.kv-sq{display:grid;gap:4px;justify-items:center;width:62px}
.kv-sq i{display:block;width:48px;height:48px;border-radius:10px;background:var(--c);box-shadow:inset 0 0 0 1px rgba(0,0,0,.1)}
.kv-sq.on i{box-shadow:0 0 0 2px #fff,0 0 0 4px #2A2228}
.kv-sq span{font-size:11px;color:#756C74;text-align:center;line-height:1.2}
.kv-sq small{font-size:10.5px;color:#2A2228;font-weight:600}
.kv-tiles{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px}
.kv-tile{flex:none;width:84px;border:1.5px solid #EADFE3;border-radius:12px;background:#fff;padding:6px;display:grid;gap:5px}
.kv-tile i{display:block;height:58px;border-radius:8px;background:var(--c)}
.kv-tile span{font-size:11.5px;line-height:1.2}
.kv-tile small{font-size:11px;color:#756C74}
.kv-tile.on{border-color:#2A2228}
.kv-dd{border:1.5px solid #EADFE3;border-radius:12px;overflow:hidden;background:#fff}
.kv-dd .h{display:flex;align-items:center;gap:10px;padding:12px 14px}
.kv-dd .h .k{color:#756C74;font-size:13px;width:46px}
.kv-dd .h b{flex:1}
.kv-dd ul{list-style:none;margin:0;padding:0;border-top:1px solid #EADFE3}
.kv-dd li{display:flex;gap:10px;align-items:center;padding:10px 14px;font-size:14px}
.kv-dd li.on{font-weight:600;background:#FFF8F5}
.kv-dd li .r{margin-inline-start:auto;color:#756C74;font-size:13px}
.kv-dd .dot{width:18px;height:18px;border-radius:50%;background:var(--c);box-shadow:inset 0 0 0 1px rgba(0,0,0,.12);flex:none}
.kv-sum{display:flex;align-items:center;gap:12px;border:1.5px solid #EADFE3;border-radius:12px;padding:10px 12px;background:#fff}
.kv-sum .dot{width:30px;height:30px;border-radius:50%;background:var(--c);flex:none}
.kv-sum .t{flex:1;display:grid}
.kv-sum .t small{color:#756C74;font-size:12px}
.kv-sum .ch{color:#C13E63;font-weight:600;font-size:14px}
.kv-tag{position:absolute;top:10px;inset-inline-end:10px;background:#2A2228;color:#fff;font:600 11px Outfit,system-ui,sans-serif;padding:4px 9px;border-radius:99px;z-index:9}
`;

function inject(page, part, L) {
  return page.evaluate(({ DATA, CSS, part, L }) => {
    if (!document.getElementById('kv-css')) { const s = document.createElement('style'); s.id = 'kv-css'; s.textContent = CSS; document.head.appendChild(s); }
    document.querySelectorAll('.kv-x').forEach(n => n.remove());
    document.querySelectorAll('.kbb-card-cart[data-kv-was]').forEach(b => { b.textContent = b.dataset.kvWas; delete b.dataset.kvWas; });
    const sw = (sh, i, on) => `<span class="kv-sw${on ? ' on' : ''}${sh[3] ? ' out' : ''}" style="--c:${sh[1]}" title="${sh[0]}"></span>`;
    const firstIn = (sh) => Math.max(0, sh.findIndex(x => !x[3]));
    if (part === 'card') {
      document.querySelectorAll('.kbb-card').forEach(card => {
        const a = card.querySelector('a[href*="/product/"]'); if (!a) return;
        const slug = a.getAttribute('href').replace(/.*\/product\//, '').replace(/\/$/, '');
        const d = DATA[slug]; if (!d) return;
        const sh = d.shades, on = firstIn(sh), cb = card.querySelector('.cb'), cp = card.querySelector('.cp'), th = card.querySelector('.kbb-card-thumb');
        const add = (html, where, ref) => { const t = document.createElement('div'); t.className = 'kv-x'; t.innerHTML = html; (where || cb).insertBefore(t, ref === undefined ? cp : ref); return t; };
        const max = L === 'A' ? 4 : 5, list = sh.slice(0, max).map((s, i) => sw(s, i, i === on)).join(''), more = sh.length > max ? `<span class="kv-more">+${sh.length - max}</span>` : '';
        if (L === 'A') add(`<div class="kv-row">${list}${more}</div>`);
        if (L === 'B') { const t = add(`<div class="kv-row">${list}${more}</div><div class="kv-lbl">Shade: <b>${sh[on][0]}</b>${sh[on][3] ? ' · sold out' : ''}</div>`); t.classList.add('kvB'); }
        if (L === 'C') { th.style.position = 'relative'; add(`<div class="kv-glass">${sh.slice(0, 4).map((s, i) => sw(s, i, i === on)).join('')}${sh.length > 4 ? `<span class="kv-more">+${sh.length - 4}</span>` : ''}</div>`, th, null); }
        if (L === 'D') { card.style.position = 'relative'; const btn = card.querySelector('.kbb-card-cart'); if (btn && !/sold/i.test(btn.textContent)) { btn.dataset.kvWas = btn.textContent; btn.textContent = 'Choose shade'; } add(`<div class="kv-lbl">${sh.length} shades</div>`); }
        if (L === 'E') add(`<div class="kv-strip">${sh.map((s, i) => `<i class="${i === on ? 'on' : ''}${s[3] ? ' out' : ''}" style="--c:${s[1]}"></i>`).join('')}</div><div class="kv-lbl"><b>${sh[on][0]}</b>${sh[on][3] ? ' · sold out' : ''}</div>`);
      });
      if (L === 'D') { // show the picker open on the first card, as a shopper would see it after tapping
        const card = [...document.querySelectorAll('.kbb-card')].find(c => /missha-m-perfect/.test(c.innerHTML));
        if (card) { const sh = DATA['missha-m-perfect-cover-bb-cream-spf-42-pa50ml'].shades; const t = document.createElement('div'); t.className = 'kv-x kv-qp'; t.innerHTML = `<div class="kv-lbl">Shade: <b>${sh[0][0]}</b></div><div class="kv-row">${sh.map((s, i) => sw(s, i, i === 0)).join('')}</div><button class="kv-add">Add to bag · AED ${sh[0][2]}</button>`; card.appendChild(t); }
      }
    } else {
      const sh = DATA['missha-m-perfect-cover-bb-cream-spf-42-pa50ml'].shades, on = 0, box = document.querySelector('.pm-sec.pm-bundles');
      if (!box) return 'no box';
      // Live today this block is display:none (the section is switched off), so the shade cannot be chosen at all; show it for the preview.
      box.style.display = 'block';
      box.querySelectorAll(':scope > *').forEach(n => { n.style.display = 'none'; n.classList.add('kv-hid'); });
      let h = '';
      if (L === 'A') h = `<div class="kv-lbl" style="font-size:14px">Shade: <b>${sh[on][0]}</b></div><div class="kv-row">${sh.map((s, i) => sw(s, i, i === on)).join('')}</div>`;
      if (L === 'B') h = `<div class="kv-lbl" style="font-size:14px">Shade: <b>${sh[on][0]}</b></div><div class="kv-sqs">${sh.map((s, i) => `<span class="kv-sq${i === on ? ' on' : ''}" style="--c:${s[1]}"><i></i><span>${s[0]}</span><small>AED ${s[2]}</small></span>`).join('')}</div>`;
      if (L === 'C') h = `<div class="kv-lbl" style="font-size:14px">Shade: <b>${sh[on][0]}</b></div><div class="kv-tiles">${sh.map((s, i) => `<span class="kv-tile${i === on ? ' on' : ''}" style="--c:${s[1]}"><i></i><span>${s[0]}</span><small>AED ${s[2]}</small></span>`).join('')}</div>`;
      if (L === 'D') h = `<div class="kv-dd"><div class="h"><span class="k">Shade</span><span class="dot" style="--c:${sh[on][1]}"></span><b>${sh[on][0]}</b><span>▴</span></div><ul>${sh.map((s, i) => `<li class="${i === on ? 'on' : ''}"><span class="dot" style="--c:${s[1]}"></span>${s[0]}<span class="r">AED ${s[2]} · In stock</span></li>`).join('')}</ul></div>`;
      if (L === 'E') h = `<div class="kv-sum"><span class="dot" style="--c:${sh[on][1]}"></span><span class="t"><b>${sh[on][0]}</b><small>${sh.length} shades</small></span><span class="ch">Change</span></div>`;
      const t = document.createElement('div'); t.className = 'kv-x kv-p'; t.innerHTML = h; box.appendChild(t);
    }
    document.querySelectorAll('.wab, .kbb-wa, [class*="wa-float"], [class*="whatsapp"]').forEach(n => { if (getComputedStyle(n).position === 'fixed') n.style.display = 'none'; });
    const tag = document.createElement('div'); tag.className = 'kv-x kv-tag'; tag.textContent = (part === 'card' ? 'Card ' : 'Product page ') + L + ' · preview'; tag.style.position = 'fixed'; document.body.appendChild(tag);
    return 'ok';
  }, { DATA, CSS, part, L });
}

(async () => {
  const b = await pw.chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const pg = await ctx.newPage();
    await pg.goto(BASE + '/shop/?s=cushion', { waitUntil: 'networkidle', timeout: 60000 });
    const grid = pg.locator('.kbb-card').first();
    for (const L of 'ABCDE') {
      console.log('card', L, w, await inject(pg, 'card', L));
      await grid.scrollIntoViewIfNeeded(); await pg.waitForTimeout(150);
      const g = await pg.evaluate(() => { const cs = [...document.querySelectorAll('.kbb-card')]; const r = cs.map(c => c.getBoundingClientRect()); const top = Math.min(...r.map(x => x.top)) + scrollY, bot = Math.max(...r.map(x => x.bottom)) + scrollY; return { top, bot }; });
      await pg.screenshot({ path: `${OUT}/card-${L}-${w}.png`, fullPage: true, clip: { x: 0, y: Math.max(0, g.top - 12), width: w, height: Math.min(g.bot - g.top + 24, w === 390 ? 1500 : 700) } });
    }
    await pg.goto(BASE + '/product/missha-m-perfect-cover-bb-cream-spf-42-pa50ml/', { waitUntil: 'networkidle', timeout: 60000 });
    for (const L of 'ABCDE') {
      console.log('pdp', L, w, await inject(pg, 'pdp', L));
      const box = await pg.evaluate(() => { const t = document.querySelector('.pm-sec.pm-title') || document.querySelector('.pm-sec'), c = document.querySelector('.pm-sec.pm-cart') || document.querySelector('.pm-sec.pm-bundles'); const a = t.getBoundingClientRect(), z = c.getBoundingClientRect(); return { top: a.top + scrollY, bot: z.bottom + scrollY }; });
      const top = w === 390 ? Math.max(0, box.top - 20) : 0;
      await pg.screenshot({ path: `${OUT}/pdp-${L}-${w}.png`, fullPage: true, clip: { x: 0, y: top, width: w, height: Math.min(w === 390 ? box.bot - top + 20 : 900, 1400) } });
    }
    await ctx.close();
  }
  await b.close();
})();
