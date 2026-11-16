'use strict';
// KBB Studio · R3 · contact sheets of the brand kits and the country packs -> shots/r3/{brands,countries-*.png}
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/r3-sheets.cjs
const fs = require('fs'), path = require('path'), { chromium } = require('playwright');
const ROOT = path.resolve(__dirname, '..'), SH = path.join(ROOT, 'shots/r3');
const url = p => 'file://' + path.join(ROOT, p);
(async () => {
  fs.mkdirSync(SH, { recursive: true });
  const man = JSON.parse(fs.readFileSync(path.join(ROOT, 'brands/planet-careers/countries/countries.json'), 'utf8')).countries;
  const fontCss = `@font-face{font-family:Montserrat;src:url(${url('brands/planet-careers/fonts/montserrat.woff2')})}@font-face{font-family:'Playfair Display';src:url(${url('brands/kbb/fonts/playfair-display.woff2')})}@font-face{font-family:Poppins;font-weight:600;src:url(${url('brands/kbb/fonts/poppins-600.woff2')})}`;
  const b = await chromium.launch(), pg = await b.newPage({ viewport: { width: 1280, height: 800 } });
  const shot = async (html, file) => { const tmp = path.join(SH, '.r3-sheet.html'); fs.writeFileSync(tmp, `<!doctype html><meta charset="utf-8"><style>${fontCss}body{margin:0;font:13px Inter,Arial;background:#f4f5f7}</style>` + html); await pg.goto('file://' + tmp, { waitUntil: 'load' }); fs.unlinkSync(tmp); await pg.evaluate(() => document.fonts.ready); await pg.screenshot({ path: path.join(SH, file), fullPage: true }); };
  await shot(`<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:16px">
    ${['kbb', 'planet-careers'].map(k => `<div style="background:#fff;padding:16px;border-radius:12px"><img src="${url(`brands/${k}/logo.svg`)}" style="width:100%"><div style="display:flex;gap:12px;align-items:center;margin-top:12px"><img src="${url(`brands/${k}/logo-mark.svg`)}" style="height:110px"><div style="background:${k === 'kbb' ? '#2A2228' : '#0b2545'};padding:12px;border-radius:8px;flex:1"><img src="${url(`brands/${k}/logo-white.svg`)}" style="width:100%"></div></div>
    <div style="display:flex;gap:6px;margin-top:10px">${Object.values(JSON.parse(fs.readFileSync(path.join(ROOT, `brands/${k}/kit.json`))).colors).map(c => `<div style="width:48px;height:48px;border-radius:8px;background:${c};border:1px solid #ddd"></div>`).join('')}</div></div>`).join('')}</div>`, 'brands.png');
  const half = Math.ceil(man.length / 2);
  for (const [n, list] of [[1, man.slice(0, half)], [2, man.slice(half)]]) {
    await shot(`<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:10px">${list.map(c => `<div style="background:#fff;border-radius:10px;padding:8px;border-top:6px solid ${c.colors[1]}">
      <b>${c.name}</b> · ${c.landmark.name}<div style="display:flex;gap:6px;align-items:flex-end;margin:6px 0">
      <img src="${url('brands/planet-careers/countries/' + c.flag)}" style="height:44px;border:1px solid #ddd"><img src="${url('brands/planet-careers/countries/' + c.map)}" style="height:70px"><img src="${url('brands/planet-careers/countries/' + c.landmark.file)}" style="height:70px;background:${c.colors[0] === '#FFFFFF' ? '#e8f4f3' : '#e8f4f3'}"><img src="${url('brands/planet-careers/countries/' + c.stickers['flag-pin'])}" style="height:62px"><img src="${url('brands/planet-careers/countries/' + c.stickers['visa-approved'])}" style="height:66px"></div>
      <img src="${url('brands/planet-careers/countries/' + c.stickers['study-in'])}" style="height:34px;max-width:100%"><br><img src="${url('brands/planet-careers/countries/' + c.stickers['intake-open'])}" style="height:28px"> <img src="${url('brands/planet-careers/countries/' + c.stickers['scholarships'])}" style="height:28px"></div>`).join('')}</div>`, `countries-${n}.png`);
  }
  await b.close();
  console.log('sheets ok');
})().catch(e => { console.error(e); process.exit(1); });
