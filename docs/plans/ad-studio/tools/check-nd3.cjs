// National Day round three (19 Nov 2026) and the saved-design actions of 1.3.1, driven in Chromium from the built file:
//  - the 16 templates (Glass & Dew / Skyline Dusk x line-up / scattered x EN / AR x plain / flag heading) exist, every part
//    is its own layer (logo, top line, heading, offer line, offer pill, button, small print, tiny flag, each blob / the
//    pane / the dew, or each firework / the skyline / the floor), each product its own item (7 in a line-up, 9 scattered,
//    the scattered ones turned), and nothing says "55" or a year
//  - Heading style: the heading of a flag template shows "UAE flag gradient", Plain takes it off, the gradient is back
//    with one pick; its four colours are editable; the downloaded PNG carries it (no console errors)
//  - My saved designs: Duplicate (a full copy, " (copy)", same company, nothing opened), Move to another company (gone here,
//    there with the same id), Copy to another company (both keep one)
//  - shots/nd3/editor-heading.png, editor-product.png, editor-saved.png
// node tools/check-nd3.cjs
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const HTML = path.join(__dirname, '..', 'ad-studio.html'), SH = path.join(__dirname, '..', 'shots', 'nd3');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
(async () => {
  fs.mkdirSync(SH, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1280, height: 1000 } });
  const errs = []; p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); }); p.on('pageerror', (e) => errs.push(e.message));
  await p.goto('file://' + HTML); await p.waitForFunction(() => document.documentElement.dataset.ready === '1'); await p.evaluate(() => document.fonts.ready);
  const ids = await p.evaluate(() => window.KBB_STUDIO.templates.filter((t) => /^nd-(dew|dusk)/.test(t.id)).map((t) => t.id));
  ok(ids.length === 16, '16 National Day round-three templates: ' + ids.join(' '));
  const words = await p.evaluate(() => window.KBB_STUDIO.templates.filter((t) => /^nd-(dew|dusk)/.test(t.id)).map((t) => JSON.stringify(t.T)).join(' '));
  ok(!/55|٥٥|20\d\d/.test(words), 'no "55" and no year in any of their words');
  const want = { dew: ['bgreen', 'bred', 'bblack', 'pane', 'dew'], dusk: ['fw1', 'fw2', 'fw3', 'fw4', 'fw5', 'fw6', 'city', 'floor1', 'floor2'] };
  for (const id of ids) {
    const r = await p.evaluate((id) => { const S = window.KBB_STUDIO; S.useTemplate(id); const d = S.doc;
      return { els: d.els.map((e) => e.id), h1: d.els.find((e) => e.id === 'h1'), n: ['post', 'square', 'story'].map((f) => d.sets[f][0].length), turned: d.sets.post[0].filter((it) => it.r).length, ss: d.sets.post[0].map((it) => it.ss) }; }, id);
    const kind = /dusk/.test(id) ? 'dusk' : 'dew', sc = /-s(-|$)/.test(id), fl = /-f$/.test(id);
    const need = ['logo', 'kick', 'h1', 'h2', 'off', 'cta', 'trust', 'tflag', 'prods'].concat(want[kind]);
    ok(need.every((k) => r.els.includes(k)) && r.n.every((n) => n === (sc ? 9 : 7)) && (sc ? r.turned >= 8 : r.turned === 0) && (fl ? r.h1.hg === (kind === 'dew' ? 'uae' : 'uaed') : !r.h1.hg)
      && (kind === 'dusk' && !sc ? r.ss.every((s) => s === 'reflect') : r.ss.every((s) => s === 'soft')),
      id + ': ' + r.els.length + ' layers, ' + r.n.join('/') + ' products, ' + r.turned + ' turned, heading ' + (r.h1.hg || 'plain'));
  }
  // the layers panel as the owner sees it, for the report
  await p.evaluate(() => { window.KBB_STUDIO.useTemplate('nd-dew-s-f'); window.KBB_STUDIO.setSel([]); });
  await p.click('[data-tab="el"]');
  const lay = await p.$$eval('#layers > *', (ns) => ns.map((n) => n.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean));
  console.log('LAYERS nd-dew-s-f: ' + lay.join(' | '));
  ok(lay.length === 14 + 9 && lay.filter((t) => /^Product \d/.test(t)).length === 9, 'layers panel: 14 parts + each of the 9 products on its own row (' + lay.length + ' rows)');
  // Heading style: select the heading, the switch shows the flag gradient; Plain takes it off; back on
  await p.evaluate(() => { const S = window.KBB_STUDIO; S.st.fmt = 'post'; S.draw(); S.setSel(['h1']); });
  ok(await p.isVisible('#p-hg') && await p.inputValue('#p-hg') === 'uae', 'heading selected: Heading style shows "UAE flag gradient · light background"');
  const clip = await p.evaluate(() => { const t = document.querySelector('#stage .el[data-id="h1"] .tx'); const s = getComputedStyle(t); return s.webkitBackgroundClip + ' ' + s.color; });
  ok(/text/.test(clip) && /rgba\(0, 0, 0, 0\)/.test(clip), 'the stage draws it clipped to the letters (' + clip + ')');
  ok(await p.isVisible('[data-cp="hg1"]') && await p.isVisible('[data-cp="hg4"]'), 'its four flag colours are on show to edit');
  await p.screenshot({ path: path.join(SH, 'editor-heading.png') });
  await p.selectOption('#p-hg', 'none');
  ok(await p.evaluate(() => !window.KBB_STUDIO.doc.els.find((e) => e.id === 'h1').hg) && !(await p.isVisible('[data-cp="hg1"]')), 'Plain: the gradient is off and its colours hide');
  await p.selectOption('#p-hg', 'uaed');
  ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'h1').hg === 'uaed'), 'the dusk gradient (with white) is one pick away');
  await p.selectOption('#p-hg', 'uae');
  // a product: its own panel (swap, size, turn, shadow style)
  await p.click('#layers .ly[data-k="p:8"] .n');
  ok(JSON.stringify(await p.evaluate(() => window.KBB_STUDIO.selKeys())) === '["p:8"]', 'a tap on "Product 9" in Layers selects that product alone');
  const hero = await p.evaluate(() => window.KBB_STUDIO.doc.sets.post[0][8].p);
  await p.click('#layers .ly[data-k="p:8"] button[title="Send backward"]');
  const z = await p.evaluate(() => ({ k: window.KBB_STUDIO.selKeys(), p: window.KBB_STUDIO.doc.sets.post[0][7].p }));
  ok(z.k[0] === 'p:7' && z.p === hero, 'its ▼ sends it one step back (now Product 8, still selected)');
  await p.click('#layers .ly[data-k="p:7"] button[title="Bring forward"]');
  await p.click('[data-tab="prod"]');
  ok(await p.isVisible('#ps-ss') && await p.isVisible('#ps-p') && await p.isVisible('#ps-r'), 'its own panel: product (swap), turn, shadow, shadow style, smaller / bigger');
  await p.screenshot({ path: path.join(SH, 'editor-product.png') });
  const png = await p.evaluate(async () => { const bl = await window.KBB_STUDIO.renderFile('post', 0, 'png'); return bl.size; });
  ok(png > 200000, 'downloads a full-HD PNG (' + Math.round(png / 1024) + ' KB)');
  // saved designs: duplicate, move, copy
  const r = await p.evaluate(async () => {
    const S = window.KBB_STUDIO; S.useTemplate('nd-dusk'); await S.saveNow(); const id = S.doc.id, name = S.doc.name;
    const mine = async (c) => (await S.store.listProjects()).filter((x) => x.co === c);
    const dup = await S.copyProject(id, 'kbb', ' (copy)'); const a = await mine('kbb');
    const cp = await S.copyProject(id, 'planet-careers', ''); const b1 = await mine('kbb'), c1 = await mine('planet-careers');
    const mv = await S.moveProject(dup.id, 'planet-careers'); const b2 = await mine('kbb'), c2 = await mine('planet-careers');
    return { id, name, open: S.doc.id, dup: dup && { id: dup.id, name: dup.name, co: dup.co, els: dup.els.length }, a: a.map((x) => x.name), b1: b1.length, c1: c1.map((x) => x.id), cp: cp && cp.id, mv: mv && mv.id, b2: b2.map((x) => x.id), c2: c2.map((x) => x.id) };
  });
  ok(r.dup && r.dup.id !== r.id && r.dup.name === r.name + ' (copy)' && r.dup.co === 'kbb' && r.a.includes(r.name + ' (copy)') && r.open === r.id, 'Duplicate: "' + (r.dup && r.dup.name) + '" saved in KBB beside the original, the original still open');
  ok(r.c1.includes(r.cp) && r.b1 === r.a.length, 'Copy to Planet Careers: a new design there, KBB keeps its own');
  ok(!r.b2.includes(r.mv) && r.c2.includes(r.mv) && r.mv === r.dup.id, 'Move to Planet Careers: the same design (same id) leaves KBB and is there');
  await p.click('[data-tab="tpl"]');
  await p.evaluate(() => { document.getElementById('mine-d').open = true; return window.KBB_STUDIO.listMine(); });
  await p.waitForTimeout(300);
  ok(await p.$$eval('#mine .it', (ns) => ns.some((n) => /Duplicate/.test(n.textContent) && n.querySelector('select'))), 'each saved design shows Duplicate and Move / copy…');
  await p.locator('#mine-d').scrollIntoViewIfNeeded();
  await p.screenshot({ path: path.join(SH, 'editor-saved.png') });
  ok(errs.length === 0, 'no console errors ' + errs.slice(0, 3).join(' | '));
  console.log(fails ? fails + ' FAILED' : 'ALL PASS');
  await b.close(); process.exit(fails ? 1 : 0);
})();
