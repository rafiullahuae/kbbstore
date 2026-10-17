/*
 * Lane GAR -- an ordinary Grid section's heading on /ar, and the editor's
 * English | Arabic table with tabs off.
 *
 *   BASE=http://127.0.0.1:<port> NODE_PATH=$(npm root -g) node tools/gar-shots.cjs
 *
 * Against a tools/htb-preview.sh server (PREVIEW DATABASE ONLY): it makes a
 * NON-TAB grid section headed "Recommended for you" with an English sub and
 * NO Arabic typed, so /ar shows the shop's own Arabic for the phrase.
 */
const fs = require('fs');
const path = require('path');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const { chromium } = pw;

const BASE = process.env.BASE || 'http://127.0.0.1:9720';
const OUT = path.join(__dirname, '..', 'docs', 'lane-gar');
fs.mkdirSync(OUT, { recursive: true });

async function api(page, p, method, body) {
  return page.evaluate(async ([p, method, body]) => {
    const m = document.cookie.match(/(^|;)\s*XSRF-TOKEN\s*=\s*([^;]+)/);
    const r = await fetch('/admin-api' + p, { method, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[2]) : '' },
      body: body ? JSON.stringify(body) : undefined });
    return { status: r.status, body: await r.json().catch(() => null) };
  }, [p, method, body]);
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium' });
  const ctx = await browser.newContext();
  const page = await ctx.newPage();

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });

  const list = await api(page, '/grid-sections', 'GET');
  for (const s of (list.body && list.body.sections) || []) {
    if (s.name === 'GAR Recommended') await api(page, '/grid-sections/' + s.id, 'DELETE');
  }
  const made = await api(page, '/grid-sections', 'POST', { preset: 'bestsellers' });
  const id = made.body.section.id;
  const put = await api(page, '/grid-sections/' + id, 'PUT', { values: {
    name: 'GAR Recommended', status: 'publish', show_heading: true, heading: 'Recommended for you',
    subheading: 'Handpicked K-beauty for your routine', show_view_all: true, view_all_label: '', view_all_url: '/shop/',
  } });
  console.log('section', id, 'put', put.status, 'tabs', JSON.stringify(put.body && put.body.product_tabs));

  const report = {};
  for (const lang of ['', 'ar/']) {
    for (const w of [390, 1280]) {
      const p = await ctx.newPage();
      const errors = [];
      p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
      p.on('pageerror', e => errors.push(String(e)));
      await p.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
      await p.goto(BASE + '/' + lang, { waitUntil: 'networkidle' });
      const r = await p.evaluate(() => {
        const sec = [...document.querySelectorAll('section.kbb-gsec')].find(s => s.querySelector('.gs-foot a[href$="/shop/"]'));
        return {
          found: !!sec,
          h2: sec && sec.querySelector('h2') ? sec.querySelector('h2').textContent : null,
          sub: sec && sec.querySelector('.gs-head p') ? sec.querySelector('.gs-head p').textContent : null,
          all: sec && sec.querySelector('.gs-all') ? sec.querySelector('.gs-all').textContent : null,
          h2px: sec && sec.querySelector('h2') ? getComputedStyle(sec.querySelector('h2')).fontSize : null,
          scrollWidth: document.documentElement.scrollWidth, width: window.innerWidth,
        };
      });
      r.consoleErrors = errors;
      report[(lang || 'en/') + w] = r;
      const sec = await p.$('section.kbb-gsec:has(.gs-foot a[href$="/shop/"])');
      if (sec) {
        await sec.scrollIntoViewIfNeeded();
        await p.waitForTimeout(500);
        await sec.screenshot({ path: path.join(OUT, 'section-' + (lang ? 'ar' : 'en') + '-' + w + '.png') });
      }
      await p.close();
    }
  }

  // The editor, tabs off, at both widths: the Heading step with its Arabic column.
  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
    await page.goto(BASE + '/admin?go=gridsections', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-gss-edit="' + id + '"]');
    await page.click('[data-gss-edit="' + id + '"]');
    await page.waitForSelector('[data-gss-step="heading"]');
    await page.waitForTimeout(1200);
    report['editor-' + w] = await page.evaluate(() => ({
      tabsStep: !!document.querySelector('[data-gss-step="tabs"]'),
      arabicBoxes: [...document.querySelectorAll('[data-gss-step="heading"] [data-lang="Arabic"] input')].map(i => i.dataset.gssT),
      translateButtons: document.querySelectorAll('[data-gss-step="heading"] [data-gss-tr]').length,
      scrollWidth: document.documentElement.scrollWidth, width: window.innerWidth,
    }));
    const step = await page.$('[data-gss-step="heading"]');
    await step.scrollIntoViewIfNeeded();
    await step.screenshot({ path: path.join(OUT, 'editor-heading-tabs-off-' + w + '.png') });
  }

  fs.writeFileSync(path.join(OUT, 'measure.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
