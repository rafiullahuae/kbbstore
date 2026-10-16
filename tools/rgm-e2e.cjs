/*
 * Picture galleries end to end, on a preview: the blog post editor's U and
 * Media buttons, a 3-picture gallery in 2 columns, one picture linked to a
 * product with its alt text filled from the product's name, Save, then the
 * post on the shop: grid, link, alt, columns at 1280 and 390, scrollWidth.
 * The Media Library itself is stubbed to answer three addresses (it is an
 * existing, separately tested picker); everything after it is the real code.
 *   node tools/rgm-e2e.cjs <port> <post-id> <post-slug> <outdir>
 */
const { chromium } = require('playwright');
(async () => {
  const [port, postId, slug, out] = process.argv.slice(2);
  const B = `http://127.0.0.1:${port}`;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = []; p.on('pageerror', e => errs.push(String(e))); p.on('response', r => r.status() >= 400 && errs.push(r.status() + ' ' + r.url()));
  await p.goto(B + '/admin/login', { waitUntil: 'networkidle' });
  await p.fill('input[name=email]', 'owner@preview.test'); await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
  await p.goto(B + '/admin', { waitUntil: 'networkidle' });
  await p.evaluate(() => window.go('posts')); await p.waitForSelector(`[data-pj-edit="${postId}"]`); await p.click(`[data-pj-edit="${postId}"]`);
  await p.waitForSelector('#pj-body'); await p.waitForTimeout(300);
  const bar = await p.$$eval('.pj-rte-bar button', bs => bs.map(x => x.textContent.trim()));
  // underline the first paragraph's words
  await p.locator('#pj-body > *').first().click({ clickCount: 3 }); await p.click('.pj-rte-bar [data-cmd="underline"]');
  // put the cursor at the end, then Media
  await p.evaluate(() => { const a = document.getElementById('pj-body'); const r = document.createRange(); r.selectNodeContents(a); r.collapse(false); const s = getSelection(); s.removeAllRanges(); s.addRange(r); a.focus(); });
  await p.evaluate(() => { window.kbbPickMedia = o => setTimeout(() => o.onPick(['/uploads/galtest/g1.png', '/uploads/galtest/g2.png', '/uploads/galtest/g3.png']), 50); });
  await p.click('.pj-rte-bar [data-rte-media]');
  await p.waitForSelector('.rgm-shade:not([hidden]) [data-n="2"]'); await p.click('.rgm-shade:not([hidden]) [data-n="2"]');
  await p.screenshot({ path: `${out}/admin-columns-1280.png` });
  await p.click('.rgm-shade:not([hidden]) [data-ok]');
  // the first picture: link it to a product by name
  await p.click('#pj-body .kbb-gal img >> nth=0'); await p.waitForSelector('#rgpLink');
  const firstName = await p.evaluate(() => fetch(location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/media/link-targets', { headers: { Accept: 'application/json' } }).then(r => r.json()).then(j => j.items.find(t => t[0] === 'product')));
  await p.type('#rgpLink', firstName[1].slice(0, 6), { delay: 20 }); await p.waitForSelector('#rgpSug [data-t]');
  await p.click('#rgpSug [data-t] >> nth=0');
  const panel = await p.evaluate(() => ({ link: document.getElementById('rgpLink').value, alt: document.getElementById('rgpAlt').value }));
  await p.screenshot({ path: `${out}/admin-picture-1280.png` });
  await p.click('.rgm-shade:not([hidden]) [data-ok]');
  const saved = await p.evaluate(() => document.getElementById('pj-body').innerHTML);
  await p.click('#pj-save'); await p.waitForTimeout(1500);
  // the shop
  const res = {};
  for (const w of [1280, 390]) {
    const s = await b.newPage({ viewport: { width: w, height: 900 } });
    s.on('pageerror', e => errs.push(String(e)));
    await s.goto(`${B}/blog/${slug}/`, { waitUntil: 'load' });
    const g = s.locator('.abody .kbb-gal').first(); await g.scrollIntoViewIfNeeded();
    res[w] = await s.evaluate(() => {
      const g = document.querySelector('.abody .kbb-gal'); if (!g) return null;
      const imgs = [...g.querySelectorAll('img')];
      return { cls: g.className, cols: getComputedStyle(g).gridTemplateColumns.split(' ').length, imgs: imgs.length,
        firstLink: (g.querySelector('a') || {}).getAttribute?.('href') || null, firstAlt: imgs[0].getAttribute('alt'), lazy: imgs.every(i => i.loading === 'lazy'),
        underline: document.querySelectorAll('.abody u').length, sw: document.documentElement.scrollWidth };
    });
    await g.screenshot({ path: `${out}/shop-gallery-${w}.png` });
    await s.close();
  }
  console.log(JSON.stringify({ bar, panel, savedHasGallery: saved.includes('kbb-gal kbb-gal-2'), shop: res, errs }, null, 1));
  await b.close();
})();
