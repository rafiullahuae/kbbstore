// Lane GS2: Appearance → Product page → Product gifts strip -- the Style select
// (grouped, shown open), the live sample per style, the greyed controls, and a
// save round trip. node tools/gs2-admin-shots.cjs <base url> <out dir>
const { chromium } = require('playwright');
const path = require('path');
const [base, out] = process.argv.slice(2);

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = []; const posts = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('request', (r) => { if (r.method() !== 'GET') posts.push(r.url().replace(base, '')); });
  await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.evaluate(() => window.go('productpage'));
  await page.waitForSelector('[data-gsatab]', { timeout: 15000 });
  await page.click('[data-gsatab]');
  await page.waitForSelector('#gsaSample .pdp-gs');
  await page.waitForTimeout(600);
  const card = async (name) => {
    const tall = await page.evaluate(() => Math.ceil(document.getElementById('ppCol').scrollHeight + 400));
    await page.setViewportSize({ width: 1280, height: Math.min(4200, tall) });
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(out, name) });
    await page.setViewportSize({ width: 1280, height: 900 });
  };
  await card('admin-default.png');

  // The Style select, open: a native drop-down is not in a screenshot, so the
  // same <select> is drawn as a list box (size) for the picture only.
  await page.evaluate(() => { const s = document.querySelector('[data-gsa-opt="style"]'); s.size = 31; s.style.height = 'auto'; s.style.minWidth = '260px'; });
  await page.waitForTimeout(200);
  const sel = await page.$('[data-gsa-opt="style"]');
  const box = await sel.boundingBox();
  await page.setViewportSize({ width: 1280, height: Math.ceil(box.y + box.height + 40) });
  await page.screenshot({ path: path.join(out, 'admin-style-select-open.png'), clip: { x: Math.max(0, box.x - 520), y: Math.max(0, box.y - 40), width: 820, height: box.height + 70 } });
  const groups = await page.$$eval('[data-gsa-opt="style"] optgroup', (g) => g.map((x) => [x.label, [...x.querySelectorAll('option')].map((o) => o.textContent)]));
  await page.evaluate(() => { const s = document.querySelector('[data-gsa-opt="style"]'); s.size = 0; s.removeAttribute('style'); });
  await page.setViewportSize({ width: 1280, height: 900 });

  // Pick Confetti + Holographic: the sample follows, Accent / Background grey out.
  const before = posts.length;
  await page.selectOption('[data-gsa-opt="style"]', 'kl');
  await page.waitForTimeout(400);
  const kl = await page.evaluate(() => ({
    sample: document.querySelector('#gsaSample .pdp-gs').className,
    greyed: [...document.querySelectorAll('#ppCol .mmrow.gsa-na b')].map((b) => b.textContent),
  }));
  await card('admin-style-kl.png');
  await page.selectOption('[data-gsa-opt="style"]', 'sway');
  await page.fill('[data-gsa-hex="accent"]', '#7B5CFF');
  await page.waitForTimeout(300);
  const swaySample = await page.evaluate(() => document.querySelector('#gsaSample .pdp-gs').getAttribute('style'));
  const requestsWhileEditing = posts.length - before;
  await (await page.$('#gsaSample')).screenshot({ path: path.join(out, 'admin-sample-sway-accent.png') });
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/admin-api/product-page') && r.request().method() === 'POST'),
    page.click('#ppSave'),
  ]);
  const j = await resp.json();
  const saved = j.giftstrip.options[0].fields.filter((f) => ['style', 'accent'].includes(f.key)).map((f) => [f.key, f.value]);
  // Put the default back.
  await page.selectOption('[data-gsa-opt="style"]', 'underline');
  await page.click('[data-gsa-own="accent"]');
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin-api/product-page') && r.request().method() === 'POST'), page.click('#ppSave')]);
  const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
  console.log(JSON.stringify({ groups, kl, swaySample, requestsWhileEditing, save: [resp.status(), j.ok, saved], ...m, errors }, null, 1));
  await browser.close();
})();
