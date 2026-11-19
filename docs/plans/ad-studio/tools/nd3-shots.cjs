// National Day round three (19 Nov 2026): Glass & Dew and Skyline Dusk, every template x every size, drawn through the
// editor's own download path (html-to-image, 1080 px wide) so the pictures are the files the owner will post.
//   node tools/nd3-shots.cjs <out-dir> [id-regex] [set]       -> <out-dir>/<id>-<fmt>.png and <out-dir>/sheet-*.jpg
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const out = path.resolve(process.argv[2] || path.join(__dirname, '..', 'shots', 'nd3')), RE = process.argv[3] || '^nd-(dew|dusk)', SET = +(process.argv[4] || 0);
(async () => {
  fs.mkdirSync(out, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = []; p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); }); p.on('pageerror', (e) => errs.push(e.message));
  await p.goto('file://' + path.join(__dirname, '..', 'ad-studio.html')); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  const list = await p.evaluate((re) => window.KBB_STUDIO.templates.filter((t) => new RegExp(re).test(t.id)).map((t) => ({ id: t.id, name: t.name })), RE);
  const rows = [];
  for (const t of list) {
    const r = { name: t.name, id: t.id, imgs: [] };
    for (const fmt of ['post', 'square', 'story']) {
      const data = await p.evaluate(async ([id, fmt, set]) => { const S = window.KBB_STUDIO; S.useTemplate(id); const blob = await S.renderFile(fmt, set, 'png');
        return await new Promise((r) => { const fr = new FileReader(); fr.onload = () => r(fr.result.split(',')[1]); fr.readAsDataURL(blob); }); }, [t.id, fmt, SET]);
      const f = path.join(out, t.id + '-' + fmt + '.png'); fs.writeFileSync(f, Buffer.from(data, 'base64')); r.imgs.push(data);
    }
    rows.push(r); console.log('drew', t.id);
  }
  // contact sheets: one per design (EN / AR x plain / flag), post · square · story side by side
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
  const groups = {}; rows.forEach((r) => { const k = r.id.replace(/-(ar|f)/g, ''); (groups[k] = groups[k] || []).push(r); });
  const s = await b.newPage({ viewport: { width: 1138, height: 900 } });
  for (const k of Object.keys(groups)) {
    const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:24px;background:#EDEAEC;font:600 15px system-ui,sans-serif;color:#1F1B1E;width:1090px}
      h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:18px 0 8px}.r{display:flex;gap:14px;align-items:flex-start}.r img{display:block;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.18)}</style>
      <h1>KBB Ad Studio · ${esc(groups[k][0].name.replace(/ · (Arabic|Flag heading)/g, ''))} · post 1080×1350 · square 1080×1080 · story 1080×1920</h1>` +
      groups[k].map((r) => `<h2>${esc(r.name)} <small style="color:#6B6268">(${r.id})</small></h2><div class="r"><img src="data:image/png;base64,${r.imgs[0]}" style="width:380px"><img src="data:image/png;base64,${r.imgs[1]}" style="width:380px"><img src="data:image/png;base64,${r.imgs[2]}" style="width:280px"></div>`).join('');
    await s.setContent(html); await s.screenshot({ path: path.join(out, 'sheet-' + k + '.jpg'), type: 'jpeg', quality: 80, fullPage: true });
    console.log('sheet', k);
  }
  console.log('console errors', errs.length, errs.slice(0, 5));
  await b.close();
})();
