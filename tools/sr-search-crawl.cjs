/*
 * Lane SR -- what the console draws that the sidebar search cannot find yet.
 *
 * AdminSearchIndex::CURATED was captured by walking the console in Chromium:
 * every sidebar row, every tab on each screen, and every heading and control
 * label each tab draws. Run this after a lane adds a screen, a tab or a
 * hand-drawn section, and it prints -- as PHP, ready to paste into CURATED --
 * every label the console now draws that the index does not have, screen by
 * screen and tab by tab. Labels that look like data rather than names (sample
 * products, prices, sentences, lowercase option values) are left out the way
 * they were the first time; read what it prints before pasting it.
 *
 *   # against any preview with an owner login and the search include applied
 *   BASE=http://127.0.0.1:9931 EMAIL=owner@preview.test PASS=preview-secret-1 \
 *     node tools/sr-search-crawl.cjs [screen-id ...]
 *
 * Read-only: it opens screens and clicks tabs, and saves nothing.
 */
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const BASE = process.env.BASE || 'http://127.0.0.1:9931';
const ONLY = process.argv.slice(2);
const TAB_SEL = '#content button, #content [role=tab]';

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium' });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', process.env.EMAIL || 'owner@preview.test');
  await page.fill('input[name=password]', process.env.PASS || 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

  const index = await page.evaluate(() => JSON.parse((document.getElementById('ksrIndex') || {}).textContent || '{}'));
  const rows = await page.evaluate(() => [...document.querySelectorAll('#nav [data-go], .side-pin [data-go]')].map((b) => b.getAttribute('data-go')));

  const labels = () => page.evaluate(() => {
    const c = document.getElementById('content');
    const sq = (s) => String(s || '').replace(/\s+/g, ' ').trim();
    const out = new Set();
    c.querySelectorAll('h2,h3,h4,b,strong,label,legend').forEach((e) => {
      if (e.closest('button') || !e.checkVisibility()) return;
      let t = e.tagName === 'LABEL' ? sq([...e.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join(' ')) : sq(e.textContent);
      if (t.length >= 3 && t.length <= 64) out.add(t);
    });
    return [...out];
  });
  const tabText = (s) => String(s || '').replace(/\s+/g, ' ').trim().replace(/[\s•\d/]+$/, '').trim();
  const noise = (t) => /^[a-z[(+{]/.test(t) || /د\.إ|AED \d|\d+px$|^\d/.test(t) || /\.$/.test(t)
    || /^(Appearance|Store|Content|Platform|Catalog|Emails|Growth|Reviews|Safety|Pages|Translation) →/.test(t)
    || ['Save', 'Save changes', 'Preview', 'Live preview', 'Show', 'From', 'Reset'].includes(t);

  const report = {};
  for (const id of rows) {
    if (ONLY.length && !ONLY.includes(id)) continue;
    await page.evaluate((i) => { try { document.querySelector(`#nav [data-go="${i}"], .side-pin [data-go="${i}"]`).click(); } catch (e) {} }, id);
    await page.waitForTimeout(1500);
    const [tabs, known] = index[id] || [[], []];
    const have = new Set(known.map(([t, l]) => `${t < 0 ? '' : tabs[t]}|${l}`));
    const tabNames = await page.evaluate((sel) => [...document.querySelectorAll(sel)]
      .filter((e) => /tab/i.test(typeof e.className === 'string' ? e.className : '') || e.getAttribute('role') === 'tab')
      .map((e) => e.textContent), TAB_SEL);
    const seen = new Set();
    const add = (tab, list) => list.forEach((l) => {
      if (noise(l) || seen.has(l) || l === tab || have.has(`${tab}|${l}`) || have.has(`|${l}`)) return;
      seen.add(l);
      ((report[id] ||= {})[tab] ||= []).push(l);
    });
    add('', await labels());
    for (const raw of tabNames) {
      const name = tabText(raw);
      if (!name) continue;
      await page.evaluate(([sel, want]) => {
        const strip = (s) => String(s || '').replace(/\s+/g, ' ').trim().replace(/[\s•\d/]+$/, '').trim();
        const b = [...document.querySelectorAll(sel)]
          .filter((e) => /tab/i.test(typeof e.className === 'string' ? e.className : '') || e.getAttribute('role') === 'tab')
          .find((e) => strip(e.textContent) === want);
        if (b) b.click();
      }, [TAB_SEL, name]);
      await page.waitForTimeout(500);
      if (!tabs.includes(name)) ((report[id] ||= {})[name] ||= []);
      add(name, await labels());
    }
  }

  const q = (s) => `'${s.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
  for (const [id, byTab] of Object.entries(report)) {
    console.log(`        ${q(id)} => [`);
    for (const [tab, list] of Object.entries(byTab)) console.log(`            ${q(tab)} => [${list.map(q).join(', ')}],`);
    console.log('        ],');
  }
  if (!Object.keys(report).length) console.log('Nothing new: every label the console draws is in the index.');
  await browser.close();
})();
