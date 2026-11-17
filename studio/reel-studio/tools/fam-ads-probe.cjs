#!/usr/bin/env node
// KBB Studio — design-family ad templates, a quick probe while building: every family template × 4 formats × EN/AR
// through the design rules, and a contact sheet per template → tools/out/families/ads-<id>.png. ONLY=fam-cv  LANG=ar
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/fam-ads-probe.cjs
'use strict';
const fs = require('fs'), path = require('path');
const H = require('./studio-harness.cjs');
const OUT = path.join(H.ROOT, 'tools', 'out', 'families');
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const dir = H.makeStudio(), e = await H.startEngine(dir), b = await H.launchChrome(), p = await b.newPage();
  const errs = []; p.on('pageerror', (x) => errs.push(String(x))); p.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errs.push(m.text()); });
  await p.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await p.goto(e.url + '/app/'); await p.waitForTimeout(1500);
  const only = (process.env.ONLY || '').split(',').filter(Boolean), langs = (process.env.LANG2 || 'en,ar').split(',');
  const res = await p.evaluate(async ({ only, langs }) => {
    const W = await import('/app/editor/workspaces.js'), Ct = await import('/app/designs/catalogue.js'), Ad = await import('/app/designs/ads.js'), Ru = await import('/app/designs/rules.js'), R = await import('/app/designs/render.js');
    W.switchWorkspace('planet-careers'); const ws = W.state.current, cat = await Ct.catalogueAll(), out = [];
    for (const t of Ad.AD_TEMPLATES.filter((x) => x.fam && (!only.length || only.includes(x.id)))) {
      const fails = [], cvs = [], hd = {};
      for (const lang of langs) {
        const D = Ad.makeAd(t.id, ws, cat, { lang }); await R.prepare(D);
        for (const s of Ad.AD_SIZES) hd[lang + s] = D.els.filter((e) => D.geo[s][e.id]?.hd).map((e) => e.id).sort().join();
        for (const s of Ad.AD_SIZES) { const c = Ru.check(D, s); if (!c.pass) fails.push(`${lang} ${s}: ` + c.checks.filter((x) => !x.ok && !['grid', 'words'].includes(x.id)).map((x) => x.msg).join('; ')); cvs.push(R.renderCanvas(D, s, 0.25)); }
      }
      for (const s of Ad.AD_SIZES) if (hd['en' + s] !== hd['ar' + s]) fails.push(`${s}: EN hides ${hd['en' + s] || '-'} / AR hides ${hd['ar' + s] || '-'}`);
      const Wd = cvs.reduce((a, c) => a + c.width + 8, 8), Hh = Math.max(...cvs.map((c) => c.height)) + 16, sh = document.createElement('canvas'); sh.width = Wd; sh.height = Hh; const g = sh.getContext('2d'); g.fillStyle = '#222'; g.fillRect(0, 0, Wd, Hh); let x = 8; for (const c of cvs) { g.drawImage(c, x, 8); x += c.width + 8; }
      out.push({ id: t.id, fails, url: sh.toDataURL('image/png') });
    }
    return out;
  }, { only, langs });
  let n = 0;
  for (const r of res) { fs.writeFileSync(path.join(OUT, `ads-${r.id}.png`), Buffer.from(r.url.split(',')[1], 'base64')); console.log(r.fails.length ? 'FAIL' : 'ok  ', r.id, r.fails.join(' | ')); n += r.fails.length; }
  console.log('fails', n, 'errors', errs.length, errs.slice(0, 8).join('\n'));
  await b.close(); await H.stopEngine(e); fs.rmSync(dir, { recursive: true, force: true });
})().catch((x) => { console.error(x); process.exit(2); });
