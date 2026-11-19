// Companies + Planet Careers + the country picker, driven like the owner would, from the built file on file://:
//  - KBB's 69 templates draw exactly as before companies existed (Planet Careers: 51 + 44 Work Abroad = 95) (tools/kbb-baseline.json, 207 renders, 0 diffs);
//    since 19 Nov 2026 KBB also has the 16 National Day Glass & Dew / Skyline Dusk templates (nd-dew*, nd-dusk*): 85, all 180
//  - the switcher: KBB <-> Planet Careers is instant, each company has its own templates, designs, favourites
//  - + New company (the form), its kit colours on the Planet Careers set, export -> import into a clean browser
//  - the country picker on every country template: all 33 countries, flag / outline / landmark / name swap, undo
//  - template browser filters (set, category, country, size, search), photo slots, a full-HD PNG, 390 px
//  - shots/companies.png (the switcher, the browser, the company form, the picker)
// node tools/check-companies.cjs
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs'), os = require('os');
const fingerprint = require('./kbb-fingerprint.cjs');
const ROOT = path.join(__dirname, '..'), HTML = path.join(ROOT, 'ad-studio.html'), FILE = 'file://' + HTML, FIX = path.join(__dirname, 'fixtures');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'adstudio-co-'));
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
function watch(p, errs, net) { p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); }); p.on('pageerror', (e) => errs.push(e.message)); p.on('request', (r) => { if (!/^(file|data|blob):/.test(r.url())) net.push(r.url()); }); }
async function open(ctx) { const p = await ctx.newPage(); const errs = [], net = []; watch(p, errs, net); await p.goto(FILE); await p.waitForFunction(() => document.documentElement.dataset.ready === '1'); await p.evaluate(() => document.fonts.ready); return { p, errs, net }; }
async function dl(p, click) { const [d] = await Promise.all([p.waitForEvent('download', { timeout: 60000 }), click()]); const f = path.join(TMP, d.suggestedFilename()); await d.saveAs(f); return f; }
const vis = (p) => p.$$eval('#tg button:not([hidden])', (b) => b.map((x) => x.dataset.id));

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  // ── KBB untouched
  const base = JSON.parse(fs.readFileSync(path.join(__dirname, 'kbb-baseline.json'), 'utf8')), now = await fingerprint(HTML, b);
  const diff = Object.keys(base).filter((k) => base[k] !== now[k]);
  const added = Object.keys(now).filter((k) => !(k in base));
  ok(Object.keys(base).length === 207 && added.length === 48 && added.every((k) => /^nd-(dew|dusk)/.test(k)) && diff.length === 0, 'KBB templates: ' + Object.keys(now).length + ' renders (207 from before companies + ' + added.length + ' National Day round three), ' + diff.length + ' diffs ' + diff.slice(0, 4).join(' '));

  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  let { p, errs, net } = await open(ctx);
  ok(await p.evaluate(() => window.KBB_STUDIO.company().id) === 'kbb', 'opens in KBB');
  ok(JSON.stringify(await p.$$eval('#co-sel option', (o) => o.map((x) => x.value))) === JSON.stringify(['kbb', 'planet-careers', '__new', '__imp']), 'switcher: KBB, Planet Careers, + New company, Import company');
  ok((await vis(p)).length === 85 && (await vis(p)).every((id) => !/^(pc|wa)-/.test(id)), 'KBB sees its own 85 templates');
  ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.some((e) => e.t === 'pay')), 'KBB designs keep tabby / tamara');

  // ── switch to Planet Careers
  const t0 = Date.now(); await p.selectOption('#co-sel', 'planet-careers'); await p.waitForFunction(() => window.KBB_STUDIO.company().id === 'planet-careers'); const ms = Date.now() - t0;
  ok(ms < 1500, 'switch to Planet Careers in ' + ms + ' ms');
  const pcv = await vis(p); ok(pcv.length === 95 && pcv.every((id) => /^(pc|wa)-/.test(id)), 'Planet Careers sees its own ' + pcv.length + ' templates');
  const d0 = await p.evaluate(() => { const d = window.KBB_STUDIO.doc; return { co: d.co, tpl: d.tpl, logo: (d.els.find((e) => e.t === 'logo') || {}).txt, pay: d.els.filter((e) => e.t === 'pay' && !e.hide).length }; });
  ok(d0.co === 'planet-careers' && /^pc-/.test(d0.tpl) && d0.logo === 'Planet Careers' && d0.pay === 0, 'a Planet Careers design: its logo, no pay badges ' + JSON.stringify(d0));
  ok(await p.evaluate(() => { const i = document.querySelector('#stage .lg img'); return !!i && i.naturalWidth > 0; }), 'the Planet Careers mark draws in the logo spot');
  const cats = await p.$$eval('#cats .chip', (c) => c.map((x) => x.textContent)); ok(cats.length >= 9 && /Study abroad/.test(cats.join()) && /Greetings/.test(cats.join()), 'categories: ' + cats.join(' | '));
  ok(await p.evaluate(() => window.KBB_STUDIO.art()) >= 100, 'built-in art unpacked: ' + await p.evaluate(() => window.KBB_STUDIO.art()) + ' pieces');

  // ── template browser filters
  await p.fill('#tq', 'ielts'); ok((await vis(p)).join() === 'pc-ielts', 'search "ielts" -> ' + (await vis(p)).join()); await p.fill('#tq', '');
  await p.click('#cats .chip[data-cat="pc-greet"]'); ok((await vis(p)).length === 4, 'Greetings category shows ' + (await vis(p)).length); await p.click('#cats .chip[data-cat="all"]');
  await p.selectOption('#tf-cty', 'de'); const dv = await vis(p);
  ok(dv.length >= 18 && await p.$$eval('#tg button:not([hidden])', (b) => b.every((x) => /Germany/.test(x.title))), 'country filter Germany: ' + dv.length + ' country templates, all drawn for Germany');
  ok(await p.$eval('#tg button[data-id="pc-study-gb"] .cv', (c) => [...c.querySelectorAll('img.ii')].some((i) => /^data:image\/svg/.test(i.src))), 'thumbnails carry the flag art');
  await p.click('#tg button[data-id="pc-study-gb"]'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.cty) === 'de', 'a template picked under Germany opens for Germany');
  await p.selectOption('#tf-cty', ''); await p.selectOption('#tf-size', 'story'); ok(await p.$eval('#tg', (g) => g.dataset.size) === 'story' && await p.$eval('#tg button .cv', (c) => Math.abs(c.clientHeight / c.clientWidth - 1920 / 1080) < .05), 'size filter previews in story 9:16');
  await p.selectOption('#tf-size', 'post'); await p.selectOption('#tf-set', 'all'); ok((await vis(p)).length === 180, 'All templates: ' + (await vis(p)).length); await p.selectOption('#tf-set', '');

  // ── country picker: every country, on every country template
  const sweep = await p.evaluate(async () => {
    const S = window.KBB_STUDIO, bad = [], cs = S.countries.map((c) => c.code); let n = 0;
    for (const t of S.templates.filter((t) => t.co === 'pc' && t.cty)) {
      S.useTemplate(t.id);
      for (const c of cs) {
        S.setCountry(c); n++;
        const d = S.doc, C = S.countries.find((x) => x.code === c);
        if (d.cty !== c) bad.push(t.id + ':' + c + ' cty');
        d.els.filter((e) => e.t === 'art').forEach((e) => { if (/^flag:|^map:/.test(e.art) && !e.art.endsWith(':' + c) && !/^flag:/.test(e.art + '') === false && e.art.split(':')[1].length === 2 && e.name !== 'Flag') {} });
        const flags = d.els.filter((e) => e.t === 'art' && e.name === 'Flag' && /^flag:/.test(e.art)); if (flags.some((e) => e.art !== 'flag:' + c)) bad.push(t.id + ':' + c + ' flag');
        const lm = d.els.find((e) => e.t === 'art' && /^lm:/.test(e.art)); if (lm && lm.art !== 'lm:' + C.lm) bad.push(t.id + ':' + c + ' landmark');
        const named = d.els.filter((e) => e.cyk && /[nd]/.test(e.cyk)); if (named.length && !named.some((e) => e.txt.indexOf(C.name) >= 0 || e.txt.indexOf(C.dn) >= 0)) bad.push(t.id + ':' + c + ' name');
        const ar = d.els.find((e) => e.id === 'ar'); if (ar && (/\{A\}/.test(ar.txt) || S.countries.some((o) => o !== C && ar.txt.indexOf(o.ar) >= 0 && C.ar.indexOf(o.ar) < 0))) bad.push(t.id + ':' + c + ' arabic');
        const imgs = [...document.querySelectorAll('#stage img.ii')]; if (imgs.some((i) => !i.getAttribute('src'))) bad.push(t.id + ':' + c + ' empty art');
      }
    }
    return { n, bad };
  });
  ok(sweep.bad.length === 0, 'country picker: ' + sweep.n + ' swaps (every country template x 33 countries), problems: ' + sweep.bad.slice(0, 6).join(' '));
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-study-gb'));
  await p.click('#stage .el[data-id="h2"]'); await p.fill('#p-txt', 'My own line'); await p.locator('#p-txt').blur();
  await p.selectOption('#cty', 'ca');
  const sw = await p.evaluate(() => { const d = window.KBB_STUDIO.doc, g = (id) => d.els.find((e) => e.id === id); return { h1: g('h1').txt, ar: g('ar').txt, flag: g('flag').art, lm: g('lm').art, h2: g('h2').txt }; });
  ok(sw.h1 === 'Canada' && /كندا/.test(sw.ar) && sw.flag === 'flag:ca' && sw.lm === 'lm:cn-tower' && sw.h2 === 'My own line', 'UK -> Canada in one click, the owner\'s own line kept ' + JSON.stringify(sw));
  await p.waitForFunction(() => { const i = document.querySelector('#stage .el[data-id="flag"] img'); return i && i.complete && i.naturalWidth > 0; });
  ok(true, 'the Canada flag draws');
  await p.keyboard.press('Escape'); await p.keyboard.press('Escape'); await p.locator('#stage').click({ position: { x: 3, y: 3 } }).catch(() => {});
  await p.keyboard.press('Control+z'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.cty) === 'gb', 'Ctrl+Z takes it back to the UK');
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-study-it')); ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.filter((e) => e.cy).map((e) => e.fill).join()) === '#009246,#FFFFFF,#CE2B37', 'flag-colour stripes are Italy\'s');
  await p.selectOption('#cty', 'de'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.filter((e) => e.cy).map((e) => e.fill).join()) === '#000000,#DD0000,#FFCE00', '…and Germany\'s after the picker');
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-consult')); ok(await p.isHidden('#ctyw'), 'no picker on a template without a country');

  // ── favourites are per company
  await p.click('.tab[data-tab="tpl"]'); await p.click('#fav'); ok(await p.$eval('#fav', (x) => x.getAttribute('aria-pressed')) === 'true' && /Favourites · 1/.test(await p.$eval('#cats .chip[data-cat="fav"]', (x) => x.textContent)), 'Free Consultation is a Planet Careers favourite');

  // ── designs are per company
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-study-gb')); await p.fill('#pname', 'PC design one'); await p.locator('#pname').blur(); await p.evaluate(() => window.KBB_STUDIO.saveNow());
  await p.selectOption('#co-sel', 'kbb'); await p.waitForFunction(() => window.KBB_STUDIO.company().id === 'kbb'); await p.waitForTimeout(150);
  const kbbMine = await p.$eval('#mine', (m) => m.textContent); ok(!/PC design one/.test(kbbMine), 'KBB does not list the Planet Careers design');
  ok(/Favourites · 0/.test(await p.$eval('#cats .chip[data-cat="fav"]', (x) => x.textContent)), 'KBB favourites are its own (0)');
  ok(await p.evaluate(() => window.KBB_STUDIO.doc.co) !== 'planet-careers', 'KBB is back on its own design');
  await p.selectOption('#co-sel', 'planet-careers'); await p.waitForFunction(() => window.KBB_STUDIO.company().id === 'planet-careers'); await p.waitForTimeout(150);
  ok(/PC design one/.test(await p.$eval('#mine', (m) => m.textContent)) && await p.$eval('#pname', (x) => x.value) === 'PC design one', 'Planet Careers lists its design and reopens where it was');

  // ── photo slot
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-approved-ca')); await p.click('.tab[data-tab="edit"]'); await p.evaluate(() => window.KBB_STUDIO.setSel(['photo']));
  ok(await p.isVisible('#ph-row'), 'a photo slot offers "Put a photo here"');
  await p.setInputFiles('#ph-file', path.join(FIX, 'backdrop.jpg')); await p.waitForFunction(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'photo').t === 'img');
  ok(await p.evaluate(() => { const e = window.KBB_STUDIO.doc.els.find((x) => x.id === 'photo'); const im = document.querySelector('#stage .el[data-id="photo"] img'); return e.sh === 'circle' && getComputedStyle(im).borderRadius === '50%' && getComputedStyle(im).objectFit === 'cover'; }), 'the photo takes the round slot (cover, circle)');

  // ── a full-HD file of a Planet Careers design draws its flag and landmark
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-study-gb')); await p.click('.tab[data-tab="tpl"]');
  const px = await p.evaluate(async () => { const blob = await window.KBB_STUDIO.renderFile('post', 0, 'png'); const bm = await createImageBitmap(blob); const c = document.createElement('canvas'); c.width = bm.width; c.height = bm.height; const x = c.getContext('2d'); x.drawImage(bm, 0, 0); const g = window.KBB_STUDIO.doc.geo.post.flag, u = bm.width / 100; const d = x.getImageData(Math.round((g.x + g.w * .5) * u), Math.round((g.y + g.h * .5) * u), 1, 1).data; return [bm.width, bm.height, d[0], d[1], d[2]]; });
  ok(px[0] === 1080 && px[1] === 1350 && !(px[2] < 40 && px[3] < 60 && px[4] < 90), 'full-HD PNG 1080×1350 with the flag drawn (centre pixel ' + px.slice(2).join(',') + ')');

  // ── + New company
  await p.selectOption('#co-sel', '__new'); ok(await p.isVisible('#codlg'), '+ New company opens the form');
  await p.fill('#co-name', 'Nova Visas'); await p.fill('#co-full', 'Nova Visas & Travel'); await p.fill('#co-ind', 'Visa services');
  await p.fill('#co-c-primary', '#3a0ca3'); await p.fill('#co-c-secondary', '#4cc9f0'); await p.fill('#co-c-accent', '#f72585');
  await p.fill('#co-cl', '+971 50 000 0000 · novavisas.example'); await p.selectOption('#co-set', 'planet-careers');
  await p.click('#co-save'); await p.waitForFunction(() => window.KBB_STUDIO.company().name === 'Nova Visas' && window.KBB_STUDIO.doc.co === 'nova-visas');
  const nv = await p.evaluate(() => { const S = window.KBB_STUDIO, d = S.doc, g = (id) => d.els.find((e) => e.id === id); return { id: S.company().id, co: d.co, logo: g('logo').txt, cta: g('cta').fill, contact: (g('contact') || {}).txt, bg: d.bg.c1 }; });
  ok(nv.id === 'nova-visas' && nv.co === 'nova-visas' && nv.logo === 'Nova Visas & Travel' && nv.cta === '#F72585' && nv.contact === '+971 50 000 0000 · novavisas.example', 'Nova Visas: its name, accent button, contact line on the Planet Careers set ' + JSON.stringify(nv));
  ok((await p.$$eval('#co-sel option', (o) => o.map((x) => x.value))).includes('nova-visas'), 'Nova Visas is in the switcher');
  await p.fill('#pname', 'Nova first ad'); await p.locator('#pname').blur(); await p.evaluate(() => window.KBB_STUDIO.saveNow());
  // export -> a .json in the brand-kit schema, with its design
  await p.click('#co-edit'); const jf = await dl(p, () => p.click('#co-xp')); const j = JSON.parse(fs.readFileSync(jf, 'utf8'));
  const k = j.kit || {}; const schemaOk = k.v === 1 && /^[a-z0-9-]{1,40}$/.test(k.id) && k.name && ['primary', 'secondary', 'accent', 'text', 'bg'].every((c) => /^#[0-9A-F]{6}$/i.test(k.colors[c])) && k.fonts.heading.family && k.fonts.body.family && typeof k.logos.mark === 'string';
  ok(path.basename(jf) === 'KBB-Studio-company-nova-visas.json' && j.app === 'kbb-studio' && j.kind === 'company' && schemaOk && j.projects.length === 1 && j.projects[0].name === 'Nova first ad', 'export: ' + path.basename(jf) + ' — kit in kit.schema.json shape, 1 design');
  await p.click('#co-cancel');
  // and edit: colours change, thumbnails follow
  await p.click('#co-edit'); await p.fill('#co-c-accent', '#00b894'); await p.click('#co-save'); await p.waitForTimeout(100);
  ok(await p.evaluate(() => window.KBB_STUDIO.company().colors.accent) === '#00B894', 'Edit company saves the new accent');
  ok(errs.length === 0, 'no console errors ' + errs.slice(0, 3).join(' | ')); ok(net.length === 0, 'no network requests ' + net.slice(0, 3).join(' '));

  // ── import into a clean browser
  const ctx2 = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true }); const o2 = await open(ctx2);
  await o2.p.setInputFiles('#co-in', jf); await o2.p.waitForFunction(() => window.KBB_STUDIO.company().id === 'nova-visas');
  await o2.p.waitForTimeout(200);
  ok(/Nova first ad/.test(await o2.p.$eval('#mine', (m) => m.textContent)) && await o2.p.evaluate(() => window.KBB_STUDIO.company().contactLine) === '+971 50 000 0000 · novavisas.example', 'import into a clean browser: Nova Visas with its design and contact line');
  // a hostile company file is refused or cleaned
  const bad = path.join(TMP, 'bad.json'); fs.writeFileSync(bad, JSON.stringify({ app: 'kbb-studio', kind: 'company', v: 1, kit: { id: '../x', name: '<img src=x onerror=alert(1)>', colors: { primary: 'red;}' }, logos: { mark: 'javascript:alert(1)' } }, projects: [{ els: [{ id: 'a', t: 'art', art: '<svg onload=alert(1)>' }] }] }));
  await o2.p.setInputFiles('#co-in', bad); await o2.p.waitForTimeout(400);
  const hb = await o2.p.evaluate(() => { const c = window.KBB_STUDIO.companies().slice(-1)[0]; return { id: c.id, prim: c.colors.primary, mark: c.logos.mark, img: document.querySelectorAll('img[src="x"]').length, opt: [...document.querySelectorAll('#co-sel option')].some((o) => o.innerHTML.indexOf('<img') >= 0) }; });
  ok(/^[a-z0-9-]+$/.test(hb.id) && hb.prim === '#002768' && hb.mark === '' && hb.img === 0 && !hb.opt, 'a hostile company file is cleaned: ' + JSON.stringify(hb));
  ok(o2.errs.length === 0, 'clean browser: no console errors ' + o2.errs.slice(0, 2).join(' | '));
  await ctx2.close();

  // ── reload keeps the company and its design
  await p.reload(); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  ok(await p.evaluate(() => window.KBB_STUDIO.company().id) === 'nova-visas' && await p.$eval('#co-sel', (s) => s.value) === 'nova-visas', 'reload opens the last company');

  // ── phone
  const ph = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 }); const o3 = await open(ph);
  await o3.p.selectOption('#co-sel', 'planet-careers'); await o3.p.waitForFunction(() => window.KBB_STUDIO.company().id === 'planet-careers');
  await o3.p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-study-gb'));
  ok(await o3.p.evaluate(() => document.documentElement.scrollWidth) === 390, '390 px: no sideways scroll (' + await o3.p.evaluate(() => document.documentElement.scrollWidth) + ')');
  await o3.p.selectOption('#co-sel', '__new'); ok(await o3.p.evaluate(() => { const r = document.querySelector('#codlg .box').getBoundingClientRect(); return r.left >= 0 && r.right <= 390; }), '390 px: the company form fits the phone');
  const phone = await o3.p.screenshot(); await o3.p.click('#co-cancel'); ok(o3.errs.length === 0, 'phone: no console errors');
  await ph.close();

  // ── shots/companies.png
  const sc = await b.newContext({ viewport: { width: 1280, height: 860 } }); const o4 = await open(sc);
  await o4.p.selectOption('#co-sel', 'planet-careers'); await o4.p.waitForFunction(() => window.KBB_STUDIO.company().id === 'planet-careers');
  await o4.p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-study-gb')); await o4.p.selectOption('#cty', 'ca'); await o4.p.waitForTimeout(150);
  const s1 = await o4.p.screenshot();
  await o4.p.selectOption('#co-sel', 'kbb'); await o4.p.waitForFunction(() => window.KBB_STUDIO.company().id === 'kbb'); await o4.p.waitForTimeout(150);
  const s2 = await o4.p.screenshot();
  await o4.p.selectOption('#co-sel', 'planet-careers'); await o4.p.waitForFunction(() => window.KBB_STUDIO.company().id === 'planet-careers'); await o4.p.click('#co-edit'); await o4.p.waitForTimeout(100);
  const s3 = await o4.p.screenshot();
  const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:20px;background:#E9EDF1;font:600 15px Inter,system-ui,sans-serif;color:#0A1B30;width:${1280 * 2 + 60}px}h1{margin:0 0 4px;font-size:26px}p{margin:0 0 14px;color:#55606E;font-weight:500}.g{display:grid;grid-template-columns:1280px 1280px;gap:20px}figure{margin:0}figcaption{margin:0 0 6px}img{display:block;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.18)}.ph{display:flex;gap:20px;align-items:flex-start}</style>
    <h1>KBB Studio · companies</h1><p>The switcher in the top bar (KBB · Planet Careers · + New company · Import company); each company has its own templates, saved designs and favourites. Country templates carry the country picker next to Play.</p>
    <div class="g"><figure><figcaption>Planet Careers · Study in Canada (picked from the UK with the country picker)</figcaption><img src="data:image/png;base64,${s1.toString('base64')}" width="1280"></figure>
    <figure><figcaption>Switched to KBB · its own 85 templates, unchanged</figcaption><img src="data:image/png;base64,${s2.toString('base64')}" width="1280"></figure>
    <figure><figcaption>Edit company · the brand kit (kit.schema.json fields), export / import .json</figcaption><img src="data:image/png;base64,${s3.toString('base64')}" width="1280"></figure>
    <figure class="ph"><figcaption>Phone 390 px · + New company</figcaption><img src="data:image/png;base64,${phone.toString('base64')}" width="390"></figure></div>`;
  const sp = await b.newPage({ viewport: { width: 1280 * 2 + 100, height: 900 } }); await sp.setContent(html); await sp.screenshot({ path: path.join(ROOT, 'shots', 'companies.png'), fullPage: true }); await sp.close();
  ok(o4.errs.length === 0, 'screenshots: no console errors');
  await b.close();
  console.log(fails ? fails + ' FAILED' : 'ALL PASSED'); process.exit(fails ? 1 : 0);
})();
