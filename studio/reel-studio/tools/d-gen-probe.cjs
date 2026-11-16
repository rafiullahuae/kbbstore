// KBB Studio · Designs · dev probe: generate designs for the test briefs in Chrome and write contact sheets.
// node tools/d-gen-probe.cjs [briefId]
'use strict';
const fs = require('fs'), path = require('path');
const { ROOT, makeStudio, startEngine, stopEngine, launchChrome } = require('./studio-harness.cjs');
(async () => {
  const only = process.argv[2] || '';
  const { BRIEFS } = await import(path.join(ROOT, 'tools', 'd-briefs.mjs'));
  const dir = makeStudio(); const e = await startEngine(dir); const b = await launchChrome();
  const page = await b.newPage({ viewport: { width: 1280, height: 800 } });
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') console.log('[page]', m.text()); });
  page.on('pageerror', (x) => console.log('[pageerror]', x.message));
  await page.goto(e.url + '/app/');
  await page.waitForTimeout(1500);
  const OUT = path.join(ROOT, 'tools', 'out', 'd'); fs.mkdirSync(OUT, { recursive: true });
  for (const br of BRIEFS.filter((x) => !only || x.id === only)) {
    const r = await page.evaluate(async ({ br }) => {
      const B = await import('/app/designs/lib/brief.js'), Gn = await import('/app/designs/generate.js'), R = await import('/app/designs/render.js'), Ct = await import('/app/designs/catalogue.js');
      const ws = await (await fetch('/brands/' + br.ws + '/kit.json')).json();
      const W = { id: ws.id, name: ws.name, fullName: ws.fullName, industry: ws.industry, industries: ws.industries, colors: ws.colors, fonts: { heading: ws.fonts.heading.family, body: ws.fonts.body.family, arabic: ws.fonts.arabic?.family }, brandRef: ws.id, logoFull: 'brands/' + ws.id + '/' + ws.logos.full, logo: 'brands/' + ws.id + '/' + (ws.logos.appIcon || ws.logos.mark), endCard: { cta: ws.endCard.cta[0], ctaAr: ws.endCard.ctaAr?.[0] } };
      const cat = await Ct.catalogueAll();
      const spec = B.parseBrief(br.text, W, cat);
      const t0 = performance.now();
      const res = await Gn.generate(spec, W, cat, { count: 8 });
      const ms = Math.round(performance.now() - t0);
      // contact sheet: each design in its first size
      const size = spec.sizes[0], [w, h] = R.PX[size], cols = 4, tw = 270, th = Math.round(tw * h / w);
      const sheet = document.createElement('canvas'); sheet.width = cols * (tw + 10) + 10; sheet.height = Math.ceil(res.designs.length / cols) * (th + 10) + 10;
      const sx = sheet.getContext('2d'); sx.fillStyle = '#222'; sx.fillRect(0, 0, sheet.width, sheet.height);
      res.designs.forEach((D, i) => { const c = R.renderCanvas(D, size, 0.25); sx.drawImage(c, 10 + (i % cols) * (tw + 10), 10 + Math.floor(i / cols) * (th + 10), tw, th); });
      const other = spec.sizes[1];
      let sheet2 = null;
      if (other) { const [w2, h2] = R.PX[other], th2 = Math.round(tw * h2 / w2); const s2 = document.createElement('canvas'); s2.width = cols * (tw + 10) + 10; s2.height = Math.ceil(res.designs.length / cols) * (th2 + 10) + 10; const c2 = s2.getContext('2d'); c2.fillStyle = '#222'; c2.fillRect(0, 0, s2.width, s2.height); res.designs.forEach((D, i) => { c2.drawImage(R.renderCanvas(D, other, 0.25), 10 + (i % cols) * (tw + 10), 10 + Math.floor(i / cols) * (th2 + 10), tw, th2); }); sheet2 = s2.toDataURL('image/jpeg', 0.85); }
      return { ms, n: res.designs.length, tried: res.tried, fams: res.designs.map((d) => d.family + ':' + d.score + (d.fixes ? '(fix' + d.fixes + ')' : '')), rejected: res.rejected, sheet: sheet.toDataURL('image/jpeg', 0.85), sheet2 };
    }, { br });
    fs.writeFileSync(path.join(OUT, `sheet-${br.id}.jpg`), Buffer.from(r.sheet.split(',')[1], 'base64'));
    if (r.sheet2) fs.writeFileSync(path.join(OUT, `sheet-${br.id}-2.jpg`), Buffer.from(r.sheet2.split(',')[1], 'base64'));
    delete r.sheet; delete r.sheet2;
    console.log(br.id, JSON.stringify(r, null, 0).slice(0, 1500));
  }
  await b.close(); await stopEngine(e); fs.rmSync(dir, { recursive: true, force: true });
})().catch((e) => { console.error(e); process.exit(1); });
