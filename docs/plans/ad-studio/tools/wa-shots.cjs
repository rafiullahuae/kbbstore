// Work Abroad contact sheets (the image-first hiring ads), from the built file:
//   shots/work-abroad-<n>.jpg   every Work Abroad template in post, square and story, 9 templates a sheet
//   node tools/wa-shots.cjs [ids-regex] [out-prefix]
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const ROOT = path.join(__dirname, '..'), FILE = path.join(ROOT, 'ad-studio.html'), OUT = path.join(ROOT, 'shots');
const re = new RegExp(process.argv[2] || '^wa-'), prefix = process.argv[3] || path.join(OUT, 'work-abroad');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1 });
  const errs = []; p.on('pageerror', (e) => errs.push(e.message)); p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  await p.goto('file://' + FILE); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  await p.evaluate(() => window.KBB_STUDIO.setCompany('planet-careers'));
  const list = (await p.evaluate(() => window.KBB_STUDIO.templates.filter((t) => t.co === 'pc').map((t) => ({ id: t.id, name: t.name, cty: t.cty, occ: t.occ })))).filter((t) => re.test(t.id));
  const shots = [];
  for (const t of list) {
    const row = { t, imgs: {} };
    for (const f of ['post', 'square', 'story']) {
      const W = f === 'story' ? 230 : 300;
      await p.evaluate(([id, f, W]) => { const S = window.KBB_STUDIO; S.useTemplate(id); S.st.fmt = f; S.st.sel = null; S.st.psel = null; S.setSel([]); S.draw(); document.getElementById('stage').style.width = W * 2 + 'px'; S.draw(); }, [t.id, f, W]);
      await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(30);
      row.imgs[f] = (await p.locator('#stage .cv').screenshot({ type: 'jpeg', quality: 84 })).toString('base64');
    }
    shots.push(row);
  }
  const per = 9, pages = Math.ceil(shots.length / per);
  for (let pg = 0; pg < pages; pg++) {
    const part = shots.slice(pg * per, pg * per + per);
    let html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:22px;background:#E9EDF1;font:600 14px Inter,system-ui,sans-serif;color:#0A1B30;width:${3 * 860}px}
      h1{font-size:24px;margin:0 0 4px}p{margin:0 0 16px;color:#55606E;font-weight:500}.g{display:grid;grid-template-columns:repeat(3,840px);gap:22px 20px}
      figure{margin:0;background:#fff;border-radius:12px;padding:12px;box-shadow:0 1px 3px rgba(0,0,0,.1)}figcaption{font-size:14px;margin:0 0 8px}.r{display:flex;gap:10px;align-items:flex-start}img{display:block;border-radius:6px}</style>
      <h1>KBB Studio · Planet Careers · Work Abroad · templates ${pg * per + 1}–${pg * per + part.length} of ${shots.length}</h1><p>Each template in post 1080×1350 · square 1080×1080 · story 1080×1920, as it opens (kit: navy / red / white, Afacad, the logo from theplanetcareers.com). The worker figures are placeholders in each occupation's uniform: tap one → Put a photo here (✂ Cut out the person). The Occupation picker swaps worker, title EN/AR, tools and accents; the Country picker swaps flag, landmark, colours and name. Salary / visa / contract are ‹edit› boxes.</p><div class="g">`;
    for (const r of part) html += `<figure><figcaption>${r.t.name.replace(/&/g, '&amp;')}${r.t.occ ? ' · ' + r.t.occ : ''}${r.t.cty ? ' · ' + r.t.cty.toUpperCase() : ''}</figcaption><div class="r"><img style="width:300px" src="data:image/jpeg;base64,${r.imgs.post}"><img style="width:300px" src="data:image/jpeg;base64,${r.imgs.square}"><img style="width:200px" src="data:image/jpeg;base64,${r.imgs.story}"></div></figure>`;
    html += '</div>';
    const s = await b.newPage({ viewport: { width: 3 * 860 + 44, height: 900 } });
    await s.setContent(html); await s.screenshot({ path: `${prefix}-${pg + 1}.jpg`, type: 'jpeg', quality: 82, fullPage: true }); await s.close();
    console.log('sheet', `${prefix}-${pg + 1}.jpg`, part.length);
  }
  console.log('errors', errs.length, errs.slice(0, 5));
  await b.close();
})();
