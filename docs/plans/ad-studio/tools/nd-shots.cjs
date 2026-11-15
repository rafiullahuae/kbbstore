// The National Day contact sheet (owner, 15 Nov 2026): every nd-* template in post, square and story.
//   node tools/nd-shots.cjs [out.jpg] [set]   -> shots/national-day.jpg (set 0, 1 or 2 = the product set)
const { chromium } = require('playwright');
const path = require('path');
const ROOT = path.join(__dirname, '..'), FILE = path.join(ROOT, 'ad-studio.html');
const OUT = process.argv[2] || path.join(ROOT, 'shots', 'national-day.jpg'), SET = +(process.argv[3] || 0);
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1 });
  await p.goto('file://' + FILE); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  const list = await p.evaluate(() => window.KBB_STUDIO.templates.filter((t) => /^nd-/.test(t.id)).map((t) => ({ id: t.id, name: t.name })));
  const rows = [];
  for (const t of list) {
    const r = { name: t.name, imgs: [] };
    for (const f of ['post', 'square', 'story']) {
      await p.evaluate(([id, f, set]) => { const S = window.KBB_STUDIO; S.useTemplate(id); S.st.fmt = f; S.st.set = set; S.st.sel = null; S.st.psel = null; S.draw(); const s = document.getElementById('stage'); s.style.width = (f === 'story' ? 560 : 760) + 'px'; S.draw(); }, [t.id, f, SET]);
      await p.evaluate(() => document.fonts.ready);
      r.imgs.push((await p.locator('#stage .cv').screenshot({ type: 'jpeg', quality: 88 })).toString('base64'));
    }
    rows.push(r);
  }
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
  const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:24px;background:#EDEAEC;font:600 15px Inter,system-ui,sans-serif;color:#1F1B1E;width:1090px}
    h1{font-size:24px;margin:0 0 4px}p{margin:0 0 16px;color:#6B6268;font-weight:500}h2{font-size:17px;margin:20px 0 8px}
    .r{display:flex;gap:16px;align-items:flex-start}.r img{display:block;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.18)}</style>
    <h1>KBB Ad Studio · UAE National Day · ${list.length} templates × 3 sizes</h1><p>Post 1080×1350 · square 1080×1080 · story 1080×1920, with the shop's newest products (set ${SET + 1}). Offers are editable placeholders.</p>` +
    rows.map((r) => `<h2>${esc(r.name)}</h2><div class="r"><img src="data:image/jpeg;base64,${r.imgs[0]}" style="width:380px"><img src="data:image/jpeg;base64,${r.imgs[1]}" style="width:380px"><img src="data:image/jpeg;base64,${r.imgs[2]}" style="width:280px"></div>`).join('');
  const s = await b.newPage({ viewport: { width: 1138, height: 900 } });
  await s.setContent(html); await s.screenshot({ path: OUT, type: 'jpeg', quality: 82, fullPage: true });
  console.log('national day sheet', list.length, OUT);
  await b.close();
})();
