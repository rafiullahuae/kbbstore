// The owner's preview pictures, from the built file:
//   shots/templates-post.jpg (+ -square, -story)   every template, by category
//   shots/editor-1280.png, shots/editor-390.png      the editor on a computer and on a phone
// node tools/shots.cjs [post,square,story] [noeditor]
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const ROOT = path.join(__dirname, '..'), FILE = path.join(ROOT, 'ad-studio.html'), OUT = path.join(ROOT, 'shots');
const fmts = (process.argv[2] || 'post,square,story').split(',').filter((f) => ['post', 'square', 'story'].includes(f));
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1 });
  await p.goto('file://' + FILE); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  for (const f of fmts) {
    const W = f === 'story' ? 220 : 280;
    const list = await p.evaluate(() => window.KBB_STUDIO.templates.map((t) => ({ id: t.id, name: t.name, cat: t.cat })));
    const imgs = {};
    for (const t of list) {
      await p.evaluate(([id, f, W]) => { const S = window.KBB_STUDIO; S.useTemplate(id); S.st.fmt = f; S.st.sel = null; S.st.psel = null; S.draw(); const s = document.getElementById('stage'); s.style.width = W * 2 + 'px'; S.draw(); }, [t.id, f, W]);
      await p.evaluate(() => document.fonts.ready);
      imgs[t.id] = (await p.locator('#stage .cv').screenshot({ type: 'jpeg', quality: 86 })).toString('base64');
    }
    const CN = { uae: 'UAE & Gulf occasions', global: 'Global occasions', sale: 'Sales & offers', product: 'Products & routines' };
    let html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:22px;background:#EDEAEC;font:600 14px Inter,system-ui,sans-serif;width:${(W + 18) * 6 + 30}px;color:#1F1B1E}
      h1{font-size:24px;margin:0 0 4px}p{margin:0 0 14px;color:#6B6268;font-weight:500}h2{font-size:17px;margin:18px 0 10px}
      .g{display:grid;grid-template-columns:repeat(6,${W}px);gap:18px}figure{margin:0}img{width:${W}px;display:block;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.15)}figcaption{margin-top:6px;font-size:12.5px}</style>
      <h1>KBB Ad Studio · ${list.length} templates · ${f} ${f === 'post' ? '1080×1350' : f === 'square' ? '1080×1080' : '1080×1920'}</h1><p>Every template as it opens, with the shop's own product cut-outs. Each one also comes in the other two sizes.</p>`;
    for (const c of Object.keys(CN)) {
      const ts = list.filter((t) => t.cat === c);
      html += `<h2>${CN[c]} · ${ts.length}</h2><div class="g">` + ts.map((t) => `<figure><img src="data:image/jpeg;base64,${imgs[t.id]}"><figcaption>${t.name.replace(/&/g, '&amp;').replace(/</g, '&lt;')}</figcaption></figure>`).join('') + '</div>';
    }
    const s = await b.newPage({ viewport: { width: (W + 18) * 6 + 74, height: 900 } });
    await s.setContent(html); await s.screenshot({ path: path.join(OUT, `templates-${f}.jpg`), type: 'jpeg', quality: 80, fullPage: true }); await s.close();
    console.log('sheet', f, list.length);
  }
  if (process.argv[3] !== 'noeditor') {
    for (const [vw, vh, name] of [[1280, 900, 'editor-1280.png'], [390, 844, 'editor-390.png']]) {
      const e = await b.newPage({ viewport: { width: vw, height: vh }, deviceScaleFactor: vw < 500 ? 2 : 1 });
      await e.goto('file://' + FILE); await e.waitForFunction(() => document.documentElement.dataset.ready === '1');
      await e.evaluate(() => { const S = window.KBB_STUDIO; S.useTemplate('eid-al-fitr'); });
      await e.screenshot({ path: path.join(OUT, name), fullPage: vw < 500 });
      if (vw >= 1000) {
        // the Edit tab with the headline selected
        await e.locator('#stage .el[data-id="h1"]').click(); await e.waitForTimeout(100);
        await e.screenshot({ path: path.join(OUT, 'editor-1280-edit.png') });
      }
      await e.close();
    }
    console.log('editor shots');
  }
  await b.close();
})();
