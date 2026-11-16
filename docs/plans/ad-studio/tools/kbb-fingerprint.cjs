// KBB's templates must not move by a pixel when other companies are added: every KBB template x 3 sizes
// is drawn on the stage and its markup hashed. tools/kbb-baseline.json holds the hashes of the build before
// companies existed (16 Nov 2026); check-companies.cjs compares against it.
//   node tools/kbb-fingerprint.cjs [file.html] [--write tools/kbb-baseline.json]
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs'), crypto = require('crypto');
async function fingerprint(file, b) {
  const p = await b.newPage({ viewport: { width: 1400, height: 1000 } });
  await p.goto('file://' + file); await p.waitForFunction(() => document.documentElement.dataset.ready === '1'); await p.evaluate(() => document.fonts.ready);
  const out = await p.evaluate(async () => {
    const S = window.KBB_STUDIO, o = {};
    if (S.setCompany) await S.setCompany('kbb');
    for (const t of S.templates.filter((t) => !t.co || t.co === 'kbb')) {
      S.useTemplate(t.id);
      for (const f of ['post', 'square', 'story']) { S.st.fmt = f; S.st.set = 0; S.draw(); o[t.id + ':' + f] = document.querySelector('#stage .cv').outerHTML; }
    }
    return o;
  });
  await p.close();
  const h = {}; Object.keys(out).forEach((k) => { h[k] = crypto.createHash('sha1').update(out[k]).digest('hex').slice(0, 16); });
  return h;
}
module.exports = fingerprint;
if (require.main === module) (async () => {
  const a = process.argv.slice(2), file = path.resolve(a[0] && !a[0].startsWith('--') ? a[0] : path.join(__dirname, '..', 'ad-studio.html'));
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const h = await fingerprint(file, b); await b.close();
  const w = a.indexOf('--write'); if (w >= 0) fs.writeFileSync(path.resolve(a[w + 1]), JSON.stringify(h, null, 1) + '\n');
  console.log(Object.keys(h).length, 'renders hashed');
})();
