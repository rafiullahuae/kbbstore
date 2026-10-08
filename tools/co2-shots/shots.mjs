/*
 * Lane CO2 — the sold-out dialog as the owner redesigned it, in Chromium, in
 * English and Arabic, at 390 and 1280. Preview: tools/co2-shots/preview.sh.
 *   node tools/co2-shots/shots.mjs PORT [SHOTS_DIR]
 * Layout is MEASURED here (the harness may measure; the shop's script may not).
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.argv[2] || '8871';
const BASE = `http://127.0.0.1:${PORT}`;
const SHOTS = process.argv[3] || path.resolve(HERE, '../../docs/lane-co2-shots');
const STUB = fs.readFileSync(path.resolve(HERE, '../co-card-walk/stripe-stub.js'), 'utf8');
fs.mkdirSync(SHOTS, { recursive: true });
const IDS = { serum: 25, jelly: 26, set: 28 };

let failures = 0;
const ok = (c, m) => { console.log(`   ${c ? 'PASS' : 'FAIL'}  ${m}`); if (!c) failures++; };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

async function run(lang, width, bagIds, label) {
  const pre = lang === 'ar' ? '/ar' : '';
  const context = await browser.newContext({
    viewport: { width, height: width < 600 ? 844 : 900 },
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
  });
  const page = await context.newPage();
  const errors = [];
  await page.route('**stripe.com/**', r => r.abort());
  await page.route('**js.stripe.com/**', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error' && !/status of 422/.test(m.text())) errors.push(m.text()); });
  await page.addInitScript(() => {
    window.__cls = 0; window.__shifts = [];
    try { new PerformanceObserver(l => { for (const e of l.getEntries()) if (!e.hadRecentInput) { window.__cls += e.value; window.__shifts.push({ v: +e.value.toFixed(4), t: Math.round(e.startTime), n: (e.sources || []).map(s => s.node ? (s.node.id || s.node.className || s.node.nodeName) : '?').join(',') }); } }).observe({ type: 'layout-shift', buffered: true }); } catch (e) {}
  });

  for (const s of ['co-glow-serum', 'co-fwee-jelly-pot', 'co2-heartleaf-toner']) await page.goto(`${BASE}/__co/in-stock/${s}`);
  await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
  for (const id of bagIds) {
    await page.evaluate(async (pid) => {
      await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: pid, quantity: 1 }) });
    }, id);
  }
  await page.goto(`${BASE}/__co/sold-out/co-fwee-jelly-pot`);
  await page.goto(`${BASE}/__co/empty/co2-heartleaf-toner`);
  await page.goto(`${BASE}${pre}/checkout/`, { waitUntil: 'networkidle' });

  await page.fill('#billing_email', 'buyer@example.com');
  await page.fill('#billing_phone', '0501234567');
  await page.fill('#billing_first_name', lang === 'ar' ? 'عائشة خان' : 'Aisha Khan');
  if (await page.$('#billing_last_name:visible')) await page.fill('#billing_last_name', 'Khan');
  await page.fill('#billing_address_1', 'Villa 12');
  if (await page.$('#billing_address_2:visible')) await page.fill('#billing_address_2', 'Marina Walk');
  if (await page.$('#billing_city:visible')) await page.fill('#billing_city', 'Dubai');
  const st = await page.$('#billing_state');
  if (st) { if ((await st.evaluate(e => e.tagName)) === 'SELECT') await page.selectOption('#billing_state', { index: 1 }); else await page.fill('#billing_state', 'Dubai'); }
  await page.click('label[for="payment_method_cod"]');
  await page.waitForTimeout(250);

  await page.waitForTimeout(600);
  const clsBefore = await page.evaluate(() => window.__cls);
  const btn = page.locator('[data-place]:visible').first();
  await btn.scrollIntoViewIfNeeded();
  await btn.click();
  await page.waitForSelector('dialog#kbbSoldOut[open]', { timeout: 6000 });
  await page.waitForFunction(() => [...document.querySelectorAll('#kbbSoldOut img')].every(i => i.complete));

  await page.waitForTimeout(300);
  const clsDialog = await page.evaluate(() => window.__cls);
  ok(clsDialog - clsBefore < 0.0001, `opening the dialog shifts nothing (${(clsDialog - clsBefore).toFixed(4)})`);
  const m = await page.evaluate(() => {
    const d = document.getElementById('kbbSoldOut');
    const lis = [...d.querySelectorAll('li')].map(li => {
      const img = li.querySelector('img');
      const b = li.querySelector('b');
      const r = li.querySelector('.kbb-so-r');
      return {
        text: li.innerText.trim(),
        img: img ? { src: img.getAttribute('src'), w: img.getAttribute('width'), h: img.getAttribute('height'), natural: img.naturalWidth, box: Math.round(img.getBoundingClientRect().width) } : null,
        nameColor: b && getComputedStyle(b).color, nameSize: b && getComputedStyle(b).fontSize,
        redColor: r && getComputedStyle(r).color,
      };
    });
    return { lis, dir: document.documentElement.dir, sw: document.documentElement.scrollWidth, vw: innerWidth, focus: d.contains(document.activeElement) };
  });
  console.log(`\n=== ${lang} ${width} ${label}`);
  console.log('   ' + JSON.stringify(m));
  ok(m.lis.length === bagIds.filter(id => id !== IDS.serum).length, `${m.lis.length} sold-out lines listed`);
  ok(m.lis.every(l => l.img && l.img.w === '44' && l.img.h === '44' && l.img.natural > 0 && /img-cache\/200\//.test(l.img.src)), 'each line has its 200px img-cache picture, 44x44 attributes, loaded');
  ok(m.lis.every(l => l.nameColor === 'rgb(42, 34, 40)' && l.nameSize === '13px'), 'names in the ink colour at 13px');
  ok(m.lis.every(l => l.redColor === 'rgb(226, 58, 78)'), 'sold-out words in the sale red');
  ok(bagIds.includes(IDS.set) ? m.lis.some(l => /Glow Starter Set/.test(l.text)) : true, 'the set component carries its set');
  ok(m.sw <= m.vw && m.focus, 'no horizontal scroll; focus inside the dialog');
  await page.screenshot({ path: `${SHOTS}/co2-popup-${lang}-${width}.png` });

  await page.click('dialog#kbbSoldOut button');
  if (label === 'empty') {
    await page.waitForSelector('#kbbSoldOut .kbb-so-cap', { timeout: 6000 });
  } else {
    await page.waitForSelector('#kbbSoNote .kbb-so-cap', { timeout: 6000 });
    await page.waitForTimeout(300);
  }
  const cap = await page.evaluate((empty) => {
    const c = document.querySelector(empty ? '#kbbSoldOut .kbb-so-cap' : '#kbbSoNote .kbb-so-cap');
    const r = c.getBoundingClientRect(); const t = c.querySelector('i').getBoundingClientRect();
    const cs = getComputedStyle(c);
    const region = c.closest('[aria-live],[role=status]');
    return {
      text: c.innerText.trim(), w: Math.round(r.width), h: Math.round(r.height), radius: cs.borderTopLeftRadius,
      blur: cs.backdropFilter || cs.webkitBackdropFilter, bg: cs.backgroundColor,
      tickTop: Math.round(t.top - r.top), tickH: Math.round(t.height),
      tickSide: (t.left + t.width / 2) > (r.left + r.width / 2) ? 'right' : 'left',
      live: region ? (region.getAttribute('aria-live') || region.getAttribute('role')) : null,
      cls: window.__cls, shifts: window.__shifts, sw: document.documentElement.scrollWidth, vw: innerWidth,
    };
  }, label === 'empty');
  console.log('   capsule ' + JSON.stringify(cap));
  ok(/999px|\d{3,}px/.test(cap.radius) && cap.w > cap.h * 3, 'a long, fully rounded capsule');
  ok(/blur\(12px\)/.test(cap.blur), 'frosted: backdrop-filter blur(12px) saturate(1.4)');
  ok(cap.tickTop === -13 && cap.tickH === 26, 'the 26px tick sits half outside the top edge (centred on it)');
  ok(cap.tickSide === (lang === 'ar' ? 'left' : 'right'), `tick on the ${lang === 'ar' ? 'left (mirrored)' : 'right'} corner`);
  ok(!!cap.live, 'announced through a live region');
  console.log(`   CLS: page load ${clsBefore.toFixed(4)}, after the remove ${cap.cls.toFixed(4)}`);
  ok(cap.sw <= cap.vw, 'no horizontal scroll');
  await page.screenshot({ path: `${SHOTS}/co2-${label === 'empty' ? 'empty' : 'updated'}-${lang}-${width}.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await context.close();
}

for (const lang of ['en', 'ar']) {
  for (const width of [1280, 390]) await run(lang, width, [IDS.serum, IDS.jelly, IDS.set], 'two');
}
await run('en', 390, [IDS.jelly], 'empty');
await run('ar', 390, [IDS.jelly], 'empty');

await browser.close();
console.log(`\n${failures === 0 ? 'ALL PASS' : failures + ' FAILED'}`);
process.exit(failures === 0 ? 0 : 1);
