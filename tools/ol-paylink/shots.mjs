/*
 * Lane OL — "Send order link", end to end in Chromium against tools/ol-paylink/preview.sh:
 *
 *   node tools/ol-paylink/shots.mjs PORT
 *
 *   1. admin (1280, 390): failed order #56187 → the button → the share sheet →
 *      WhatsApp (wa.me intercepted) → "Link last sent: WhatsApp".
 *   2. owner app (390, 1280): failed order #56188 → the button → the sheet.
 *   3. shopper at 390: opens #56187's link → pay page → Cash on delivery →
 *      the order-received page for the SAME number. Re-opening the link →
 *      "This order is already paid".
 *   4. shopper, faked Stripe: #56188's link → Card → the gateway's start for
 *      THAT order (client secret for #56188) → confirmed → paid, same number.
 *
 * Every console error, page error and failed request is counted; the harness
 * measures (scrollWidth, boxes) — the pages measure nothing. Dev tooling only.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const LANE = path.resolve(HERE, '../..');
const PORT = process.argv[2] || '8971';
const BASE = `http://127.0.0.1:${PORT}`;
const DB = `${LANE}/storage/ol-logs/preview-${PORT}/preview.sqlite`;
const SHOTS = path.resolve(LANE, 'docs/lane-ol-shots');
const STUB = fs.readFileSync(`${LANE}/tools/co3-polish/stripe-stub.js`, 'utf8');
fs.mkdirSync(SHOTS, { recursive: true });

const sql = (q) => JSON.parse(execFileSync('php', ['-r', `$p=new PDO('sqlite:${DB}');echo json_encode($p->query(${JSON.stringify(q)})->fetchAll(PDO::FETCH_ASSOC));`]).toString());
const numbers = {};
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
let failures = 0;
const ok = (c, m) => { console.log(`   ${c ? 'PASS' : 'FAIL'}  ${m}`); if (!c) failures++; };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

async function open(width, opts = {}) {
  // A real browser's UA: BlockGate answers HeadlessChrome on the checkout paths with its bot page.
  const context = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, userAgent: UA, ...(opts.mobile ? { isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : {}) });
  const page = await context.newPage();
  const errors = [];
  await context.route('**/wa.me/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<title>wa.me</title>WhatsApp would open here' }));
  await page.route('**stripe.com/**', (r) => r.abort());
  await page.route('**js.stripe.com/**', (r) => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  // A beacon the browser cancels because the page navigated away (ERR_ABORTED)
  // is the navigation, not a fault; it is printed, not counted.
  page.on('requestfailed', (r) => {
    if (/stripe\.com/.test(r.url())) return;
    const why = (r.failure() || {}).errorText || '';
    if (why === 'net::ERR_ABORTED') { console.log('   note  cancelled by navigation: ' + r.url().replace(BASE, '')); return; }
    errors.push('failed: ' + r.url() + ' ' + why);
  });
  return { context, page, errors };
}
const scroll = (page) => page.evaluate(() => ({ doc: document.documentElement.scrollWidth, view: document.documentElement.clientWidth }));

const ids = Object.fromEntries(sql("select order_number, id from orders").map((r) => [r.order_number, Number(r.id)]));
const before = sql('select count(*) n from orders')[0].n;
console.log('orders', ids, 'count', before);

/* ------------------------------------------------------------ 1. the admin */
let link = '';
for (const W of [1280, 390]) {
  console.log(`\n== admin ${W}`);
  const { context, page, errors } = await open(W);
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@ol.test');
  await page.fill('input[name=password]', 'ol-preview-secret');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.evaluate((id) => window.go('orders', id), ids['56187']);
  await page.waitForSelector('#odPayLinkGo', { timeout: 15000 });
  await page.evaluate(() => document.getElementById('odPayPanel').scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(300);
  const btn = await page.evaluate(() => { const r = document.getElementById('odPayLinkGo').getBoundingClientRect(); const el = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return { w: Math.round(r.width), h: Math.round(r.height), hit: el && el.id }; });
  ok(btn.hit === 'odPayLinkGo', `button answers a real click at its centre (${btn.w}x${btn.h})`);
  await page.screenshot({ path: `${SHOTS}/admin-${W}-1-button.png` });

  await page.click('#odPayLinkGo');
  await page.waitForSelector('#odPlUrl', { timeout: 15000 });
  link = await page.inputValue('#odPlUrl');
  const wa = await page.getAttribute('#odPlWa', 'href');
  ok(/^https:\/\/wa\.me\/971508883841\?text=/.test(wa), 'WhatsApp opens wa.me/971508883841 with the message: ' + decodeURIComponent(wa.split('text=')[1]).slice(0, 80) + '…');
  ok(link.includes('/checkout/order-pay?order=56187&t='), 'link is the signed pay link for #56187');
  await page.screenshot({ path: `${SHOTS}/admin-${W}-2-share.png` });

  if (W === 1280) {
    const [popup] = await Promise.all([context.waitForEvent('page'), page.click('#odPlWa')]);
    await popup.waitForLoadState();
    ok(popup.url().startsWith('https://wa.me/971508883841'), 'pressing WhatsApp opened wa.me in a new tab');
    await popup.close();
    await page.waitForFunction(() => /WhatsApp/.test(document.getElementById('odPlLast').textContent), null, { timeout: 8000 });
    await page.evaluate(() => { const d = document.getElementById('odPlSet'); if (d) d.open = true; });
    await page.waitForSelector('#odPlDays', { timeout: 8000 });
    await page.screenshot({ path: `${SHOTS}/admin-${W}-3-sent-and-settings.png` });
    await page.click('[data-odx]');
    await page.waitForSelector('#odPayLinkLast', { timeout: 15000 });
    await page.evaluate(() => document.getElementById('odPayPanel').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    ok(/Link last sent: WhatsApp/.test(await page.textContent('#odPayLinkLast')), 'order screen shows "Link last sent: WhatsApp"');
    await page.screenshot({ path: `${SHOTS}/admin-${W}-4-last-sent.png` });
  }
  const sw = await scroll(page);
  numbers[`admin-${W}`] = { button: btn, scrollWidth: sw };
  ok(errors.length === 0, `no console errors (${errors.join(' | ')})`);
  await context.close();
}
const note = sql(`select content from order_notes where order_id=${ids['56187']} and content like 'Payment link %' order by id desc limit 1`)[0];
ok(note && /sent by WhatsApp/.test(note.content), 'order note: ' + (note && note.content));

/* -------------------------------------------------------- 2. the owner app */
for (const W of [390, 1280]) {
  console.log(`\n== owner app ${W}`);
  const { context, page, errors } = await open(W, { mobile: W < 600 });
  await context.addInitScript(() => { try { localStorage.setItem('oa.a2', '1'); } catch (e) {} });
  await page.goto(BASE + '/ol_owner_app/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@ol.test');
  await page.fill('input[name=pin]', '482615');
  await page.click('[data-enrol] button[type=submit]');
  await page.waitForTimeout(2500);
  await page.evaluate((id) => { location.hash = '#/orders/' + id; }, ids['56188']);
  await page.waitForSelector('[data-act=paylink]', { timeout: 15000 });
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOTS}/owner-${W}-1-button.png` });
  await page.click('[data-act=paylink]');
  await page.waitForSelector('.sheet.open [data-pl=whatsapp]', { timeout: 15000 });
  await page.waitForTimeout(500);
  const wa = await page.getAttribute('.sheet.open [data-pl=whatsapp]', 'href');
  ok(/^https:\/\/wa\.me\/971508883841\?text=/.test(wa), 'owner app WhatsApp href normalised');
  await page.screenshot({ path: `${SHOTS}/owner-${W}-2-share.png` });
  numbers[`owner-${W}`] = { scrollWidth: await scroll(page) };
  ok(errors.length === 0, `no console errors (${errors.join(' | ')})`);
  await context.close();
}

/* ------------------------------------------------ 3. the shopper pays (COD) */
const path_ = (u) => u.slice(u.indexOf('/checkout/order-pay'));
for (const W of [1280, 390]) {
  console.log(`\n== pay page ${W}`);
  const { context, page, errors } = await open(W, { mobile: W < 600 });
  const t0 = Date.now();
  const res = await page.goto(BASE + path_(link), { waitUntil: 'networkidle' });
  numbers[`pay-${W}`] = { status: res.status(), serverMs: res.headers()['x-co-ms'], queries: res.headers()['x-co-queries'], loadMs: Date.now() - t0, scrollWidth: await scroll(page),
    methods: await page.$$eval('input[name=method]', (els) => els.map((e) => e.value)) };
  ok(res.status() === 200, 'pay page ' + res.status() + ' at ' + page.url().replace(BASE, '').slice(0, 60) + '; methods ' + numbers[`pay-${W}`].methods.join(','));
  if (res.status() !== 200) console.log((await page.textContent('body')).replace(/\s+/g, ' ').slice(0, 400));
  await page.screenshot({ path: `${SHOTS}/pay-${W}-1-page.png`, fullPage: true });
  if (W === 390) {
    await page.check('input[name=method][value=cod]');
    await page.screenshot({ path: `${SHOTS}/pay-390-2-cod-chosen.png`, fullPage: true });
    await Promise.all([page.waitForURL(/checkout\/success/, { timeout: 20000 }), page.click('#kbbopGo')]);
    await page.waitForLoadState('networkidle');
    ok(/order=56187/.test(page.url()), 'landed on the order-received page for #56187: ' + page.url().replace(BASE, ''));
    await page.screenshot({ path: `${SHOTS}/pay-390-3-received.png`, fullPage: true });
  }
  ok(errors.length === 0, `no console errors (${errors.join(' | ')})`);
  await context.close();
}
const paid = sql(`select order_number, status, payment_method, paid_at from orders where id=${ids['56187']}`)[0];
ok(paid.order_number === '56187' && paid.status === 'processing' && paid.payment_method === 'cod', 'same order now ' + JSON.stringify(paid));
ok(sql('select count(*) n from orders')[0].n === before, 'no new order row');
numbers.stock = sql("select stock from products where slug='co-glow-serum'")[0];

for (const W of [390, 1280]) {
  const { context, page, errors } = await open(W, { mobile: W < 600 });
  await page.goto(BASE + path_(link), { waitUntil: 'networkidle' });
  ok(/already paid/.test(await page.textContent('body')), `${W}: re-opened link says "This order is already paid" at ${page.url().replace(BASE, '').slice(0, 40)}…`);
  await page.screenshot({ path: `${SHOTS}/paid-${W}-already-paid.png`, fullPage: true });
  ok(errors.length === 0, `no console errors (${errors.join(' | ')})`);
  await context.close();
}

/* --------------------------------------------- 4. the card path (faked Stripe) */
{
  console.log('\n== card path');
  const { context, page, errors } = await open(390, { mobile: true });
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@ol.test');
  await page.fill('input[name=password]', 'ol-preview-secret');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  const j = await page.evaluate(async (id) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/admin-api/orders/' + id + '/pay-link', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: '{"via":"copy"}' });
    return r.json();
  }, ids['56188']);
  const shopper = await context.browser().newContext({ viewport: { width: 390, height: 844 }, userAgent: UA });
  const sp = await shopper.newPage();
  await sp.route('**stripe.com/**', (r) => r.abort());
  await sp.route('**js.stripe.com/**', (r) => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
  sp.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  await sp.addInitScript((base) => { window.__onConfirm = (secret) => fetch(base + '/__co/confirmed/' + secret.split('_secret')[0]); }, BASE);
  const starts = [];
  sp.on('response', async (r) => { if (r.url().endsWith('/checkout/order-pay') && r.request().method() === 'POST') starts.push(await r.json()); });
  await sp.goto(BASE + path_(j.url), { waitUntil: 'networkidle' });
  await sp.check('input[name=method][value=stripe]');
  await sp.screenshot({ path: `${SHOTS}/pay-390-4-card-chosen.png`, fullPage: true });
  await Promise.all([sp.waitForURL(/checkout\/success/, { timeout: 20000 }), sp.click('#kbbopGo')]);
  ok(starts[0] && starts[0].action === 'confirm' && starts[0].order === '56188', 'Stripe start for the SAME order: ' + JSON.stringify(starts[0] || {}).slice(0, 120));
  const card = sql(`select order_number, status, payment_method, transaction_id from orders where id=${ids['56188']}`)[0];
  ok(card.payment_method === 'stripe' && /^pi_co_/.test(card.transaction_id || ''), 'order #56188 carries the intent: ' + JSON.stringify(card));
  ok(sql('select count(*) n from orders')[0].n === before, 'still no new order row');
  ok(errors.length === 0, `no console errors (${errors.join(' | ')})`);
  await shopper.close();
  await context.close();
}

fs.writeFileSync(`${SHOTS}/numbers.json`, JSON.stringify(numbers, null, 1));
console.log(JSON.stringify(numbers, null, 1));
await browser.close();
console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
process.exit(failures ? 1 : 0);
