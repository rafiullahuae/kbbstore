/*
 * Lane BIM: the blog post editor (Content → Blog Posts → Edit → The article →
 * Media), a 2-picture row inserted with the cursor at the end of paragraph 3
 * of an article that sits inside one wrapper, as imported articles do.
 *   node tools/bim-editor-shots.cjs <port> <outdir> <label>
 * The Media Library is stubbed to answer two pictures; the rest is real.
 */
const { chromium } = require('playwright');
(async () => {
  const [port, out, label] = process.argv.slice(2);
  const B = `http://127.0.0.1:${port}`;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
  await p.goto(B + '/admin/login', { waitUntil: 'networkidle' });
  await p.fill('input[name=email]', 'owner@preview.test'); await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
  await p.goto(B + '/admin', { waitUntil: 'networkidle' });
  await p.evaluate(() => window.go('posts'));
  await p.waitForSelector('[data-pj-edit]'); await p.click('[data-pj-edit] >> nth=0');
  await p.waitForSelector('#pj-body'); await p.waitForTimeout(300);
  await p.evaluate(() => {
    const a = document.getElementById('pj-body');
    a.innerHTML = '<div class="entry">' + Array.from({ length: 6 }, (_, i) => `<p>Paragraph ${i + 1}: oil dissolves oil, so <a href="/shop/">an oil cleanser</a> goes first and a foam second.</p>`).join('') + '</div>';
    a.dispatchEvent(new Event('input', { bubbles: true }));
    window.kbbPickMedia = (o) => Promise.resolve().then(() => o.onPick(['/uploads/bim1.svg', '/uploads/bim2.svg']));
  });
  // Select the last words of paragraph 3 so the place shows in the picture.
  await p.evaluate(() => {
    const t = document.querySelectorAll('#pj-body p')[2].lastChild;
    const r = document.createRange(); r.setStart(t, t.data.length - 'a foam second.'.length); r.setEnd(t, t.data.length);
    document.getElementById('pj-body').focus(); const s = getSelection(); s.removeAllRanges(); s.addRange(r);
  });
  await p.locator('.pj-rte').scrollIntoViewIfNeeded();
  if (label === 'before') await p.locator('.pj-rte').screenshot({ path: `${out}/editor-cursor-p3.png` });
  await p.click('.pj-rte-bar [data-rte-media]');
  await p.click('.rgm-shade:not([hidden]) [data-n="2"]'); await p.click('.rgm-shade:not([hidden]) [data-ok]');
  await p.waitForTimeout(150);
  await p.evaluate(() => document.querySelector('#pj-body .kbb-gal').classList.add('rgm-sel'));
  await p.locator('.pj-rte').screenshot({ path: `${out}/editor-landed-${label}.png` });
  const r = await p.evaluate(() => {
    const a = document.getElementById('pj-body'); const g = a.querySelector('.kbb-gal');
    const ps = [...a.querySelectorAll('p')].map((x) => x.textContent.slice(0, 11));
    const prev = g.previousElementSibling; const next = g.nextElementSibling && g.nextElementSibling.nextElementSibling;
    return { after: prev ? prev.textContent.slice(0, 11) : null, before: next ? next.textContent.slice(0, 11) : null, parent: g.parentNode.className || g.parentNode.id, scrollWidth: document.documentElement.scrollWidth };
  });
  r.errors = errs;
  console.log(JSON.stringify(r));
  await b.close();
})();
