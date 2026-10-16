/*
 * The picture panel's alt text from a PASTED address (16 October 2026: the
 * owner pasted https://kbeautybliss.com/product/... and the alt stayed empty).
 * Pastes a full address of this shop, presses Save straight away, reopens the
 * picture; then reopens a picture linked earlier with no alt. Preview only.
 *   node tools/rgm-paste.cjs <port> <post-id>
 */
const { chromium } = require('playwright');
(async () => {
  const [port, postId] = process.argv.slice(2);
  const B = `http://127.0.0.1:${port}`;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = []; p.on('pageerror', e => errs.push(String(e))); p.on('response', r => r.status() >= 400 && errs.push(r.status() + ' ' + r.url()));
  await p.goto(B + '/admin/login'); await p.fill('input[name=email]', 'owner@preview.test'); await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
  await p.goto(B + '/admin', { waitUntil: 'networkidle' });
  await p.evaluate(() => window.go('posts')); await p.waitForSelector(`[data-pj-edit="${postId}"]`); await p.click(`[data-pj-edit="${postId}"]`);
  await p.waitForSelector('#pj-body .kbb-gal img');
  const prod = await p.evaluate(() => fetch(location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/media/link-targets').then(r => r.json()).then(j => j.items.filter(t => t[0] === 'product')[3]));
  // 1) paste a full address into picture 2 and Save at once
  await p.click('#pj-body .kbb-gal img >> nth=1'); await p.waitForSelector('#rgpLink');
  await p.fill('#rgpAlt', '');
  await p.evaluate(v => { const l = document.getElementById('rgpLink'); l.value = v; l.dispatchEvent(new Event('input', { bubbles: true })); }, `${B}${prod[2]}`);
  await p.click('.rgm-shade:not([hidden]) [data-ok]'); await p.waitForTimeout(300);
  const after = await p.evaluate(() => { const i = document.querySelectorAll('#pj-body .kbb-gal img')[1]; return { alt: i.getAttribute('alt'), href: i.parentElement.tagName === 'A' ? i.parentElement.getAttribute('href') : null, target: i.parentElement.getAttribute('target') }; });
  // 2) reopen it: the field shows the saved alt
  await p.click('#pj-body .kbb-gal img >> nth=1'); await p.waitForSelector('#rgpAlt');
  const reopened = await p.inputValue('#rgpAlt'); await p.click('.rgm-shade:not([hidden]) [data-x]');
  // 3) a picture linked earlier with an empty alt: fills on open
  await p.evaluate(v => { const i = document.querySelectorAll('#pj-body .kbb-gal img')[2]; const a = document.createElement('a'); a.href = v; i.replaceWith(a); a.appendChild(i); i.setAttribute('alt', ''); }, prod[2]);
  await p.click('#pj-body .kbb-gal img >> nth=2'); await p.waitForTimeout(400);
  const onOpen = await p.inputValue('#rgpAlt');
  // 4) his own words are never replaced
  await p.fill('#rgpAlt', 'My own words'); await p.evaluate(v => { const l = document.getElementById('rgpLink'); l.value = v; l.dispatchEvent(new Event('input', { bubbles: true })); }, `${B}${prod[2]}`); await p.waitForTimeout(300);
  const kept = await p.inputValue('#rgpAlt');
  // 5) other kinds of address, alt emptied first each time
  const kinds = {};
  for (const [k, v] of [['page', `${B}/delivery/`], ['other-site-product', 'https://www.example-shop.com/products/anua-heartleaf-77-soothing-toner'], ['bare-site', 'https://instagram.com/'], ['unknown-own-path', `${B}/collections/new-glow-edit/`]]) {
    await p.fill('#rgpAlt', '');
    await p.evaluate(v => { const l = document.getElementById('rgpLink'); l.value = v; l.dispatchEvent(new Event('input', { bubbles: true })); }, v);
    await p.waitForTimeout(250);
    kinds[k] = await p.inputValue('#rgpAlt');
  }
  console.log(JSON.stringify({ product: prod[1], after, reopened, onOpen, kept, kinds, errs }, null, 1));
  await b.close();
})();
// (appended) more address kinds, through the same panel's own functions
