/*
 * The admin search and a browser's password manager. Chrome fills a saved
 * login's email into the text box before a password field, without focusing
 * it, and fires `input`. Here: open Translation -> Language settings, do
 * exactly that to the search box, and report whether the search ran.
 *   node tools/ksr-autofill.cjs <port>
 */
const { chromium } = require('playwright');
(async () => {
  const port = process.argv[2];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = []; p.on('pageerror', e => errs.push(String(e)));
  await p.goto(`http://127.0.0.1:${port}/admin/login`, { waitUntil: 'networkidle' });
  await p.fill('input[name=email]', 'owner@preview.test');
  await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
  await p.goto(`http://127.0.0.1:${port}/admin#tr-settings`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(800);
  const r = await p.evaluate(() => new Promise(res => {
    const i = document.getElementById('ksrIn');
    const key = document.getElementById('tr-key');
    i.blur(); document.body.focus();
    i.value = 'rite2rafi@gmail.com';                        // what autofill does
    i.dispatchEvent(new Event('input', { bubbles: true }));
    setTimeout(() => {
      const pop = document.getElementById('ksrList') && document.getElementById('ksrList').closest('[hidden]') === null && document.querySelector('.ksr-pop:not([hidden]), #ksrPop:not([hidden])');
      res({ autofilled: { valueAfter: i.value, popupShown: !!pop }, keyField: key ? key.getAttribute('autocomplete') : 'no key field' });
    }, 400);
  }));
  // and a person typing still works
  await p.click('#ksrIn'); await p.keyboard.type('logo'); await p.waitForTimeout(400);
  const typed = await p.evaluate(() => ({ value: document.getElementById('ksrIn').value, results: document.querySelectorAll('#ksrList [role=option], #ksrList li').length }));
  console.log(JSON.stringify({ ...r, typed, errs }));
  await b.close();
})();
