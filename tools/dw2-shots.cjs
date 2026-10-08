/*
 * LANE DW2 — Platform → Domain switch as a numbered installer, in Chromium.
 *
 *   sh tools/dw-preview.sh 10790        # the preview, outside world faked (tools/dw-router.php)
 *   node tools/dw2-shots.cjs 1280
 *   sh tools/dw-preview.sh 10790 && node tools/dw2-shots.cjs 390
 *
 * Shots (docs/lane-dw2-shots/<width>-*.png): the overview, a step whose Verify
 * is green, one red with its fix, a skipped step, and the final summary. Also
 * recorded: every request the page made on open (must be ONE admin-api GET),
 * console errors, horizontal overflow, and each Verify's answer.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const APP = path.join(__dirname, '..');
const PORT = process.env.DW_PORT || '10790';
const DIR = path.join(APP, 'storage/framework/testing/dw-preview');
const WIDTH = +(process.argv[2] || 1280);
const OUT = path.join(APP, 'docs', 'lane-dw2-shots');
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OLD = 'https://extrabeauty.ae';
const IP = '134.209.147.13';
fs.mkdirSync(OUT, { recursive: true });

function startTlsProxy() {
  const https = require('https');
  const http = require('http');
  const key = path.join(DIR, 'tls.key');
  const crt = path.join(DIR, 'tls.crt');
  if (!fs.existsSync(crt)) {
    execSync(`openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=kbeautybliss.com" -addext "subjectAltName=DNS:kbeautybliss.com,DNS:www.kbeautybliss.com,DNS:extrabeauty.ae,DNS:www.extrabeauty.ae" -keyout ${key} -out ${crt}`, { stdio: 'ignore' });
  }
  const server = https.createServer({ key: fs.readFileSync(key), cert: fs.readFileSync(crt) }, (req, res) => {
    const up = http.request({ host: '127.0.0.1', port: PORT, method: req.method, path: req.url,
      headers: Object.assign({}, req.headers, { 'x-forwarded-proto': 'https' }) }, (u) => { res.writeHead(u.statusCode, u.headers); u.pipe(res); });
    up.on('error', () => { res.writeHead(502); res.end('preview down'); });
    req.pipe(up);
  });
  return new Promise((ok) => server.listen(443, '127.0.0.1', () => ok(server)));
}

const GOOD = {
  'kbeautybliss.com': { A: [IP], NS: ['ns1.internet.bs.', 'ns2.internet.bs.'], MX: ['1 smtp.google.com.'], TXT: ['"v=spf1 include:_spf.google.com ~all"'] },
  'www.kbeautybliss.com': { CNAME: ['kbeautybliss.com.'] },
  'extrabeauty.ae': { A: [IP] },
};
function scenario(s) { fs.writeFileSync(path.join(DIR, 'scenario.json'), JSON.stringify(Object.assign({ stripe: 'ok', tabby: 'ok', tamara: 'ok' }, s))); }

const report = { width: WIDTH, onOpen: [], console: [], verify: {}, overflow: {} };

async function shot(page, name, key) {
  report.overflow[name] = await page.evaluate(() => document.documentElement.scrollWidth);
  const file = path.join(OUT, `${WIDTH}-${name}.png`);
  // A step: the whole card, top to bottom. The overview: the page as it opens.
  // The console scrolls an inner pane under a sticky header, so a tall card is
  // shot by growing the window until the whole card fits, then clipping to it.
  if (!key) { await page.screenshot({ path: file }); return; }
  const vp = page.viewportSize();
  const h = await page.$eval(`[data-key="${key}"]`, (el) => el.offsetHeight);
  await page.setViewportSize({ width: vp.width, height: Math.min(h + 400, 7000) });
  await page.$eval(`[data-key="${key}"]`, (el) => el.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(150);
  const box = await page.locator(`[data-key="${key}"]`).boundingBox();
  await page.screenshot({ path: file, clip: { x: Math.max(0, box.x - 8), y: Math.max(0, box.y - 8), width: Math.min(box.width + 16, vp.width), height: box.height + 16 } });
  await page.setViewportSize(vp);
}

async function openStep(page, n) {
  await page.click(`#dwi-step-${n} > .dwi-head`);
  await page.waitForSelector(`#dwi-step-${n} .dwi-body`);
}

/** Press a step's Verify / Mark as done / Skip and wait for the server's answer. */
async function stepDo(page, key, doWhat) {
  const done = page.waitForResponse((r) => r.url().includes('/admin-api/domain-switch/step') && r.request().method() === 'POST');
  await page.click(`[data-key="${key}"] [data-dwi-step="${key}"][data-dwi-do="${doWhat}"]`);
  const r = await done;
  const body = await r.json();
  await page.waitForFunction(() => !document.querySelector('[data-dw-screen] button[disabled][data-dwi-step]'));
  return { status: r.status(), level: body.level, message: body.message };
}

async function action(page, act) {
  const done = page.waitForResponse((r) => r.url().includes('/admin-api/domain-switch/run'));
  await page.click(`[data-dw="${act}"]`);
  const r = await done;
  await page.waitForFunction(() => !document.querySelector('[data-dw-screen] button[disabled][data-dw]') || true);
  return { status: r.status(), body: await r.json() };
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: ['--no-proxy-server',
    '--host-resolver-rules=MAP extrabeauty.ae 127.0.0.1,MAP www.extrabeauty.ae 127.0.0.1,MAP kbeautybliss.com 127.0.0.1,MAP www.kbeautybliss.com 127.0.0.1'] });
  const proxy = await startTlsProxy();
  const mobile = WIDTH < 600;
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: WIDTH, height: mobile ? 844 : 900 }, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, hasTouch: mobile });
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());
  page.on('console', (m) => { if (m.type() === 'error') report.console.push(m.text()); });
  page.on('pageerror', (e) => report.console.push(String(e)));
  scenario({ dns: GOOD, tls: 'ok', tls_old: 'ok', oldhome: 'stale' });

  await page.goto(OLD + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(OLD + '/admin', { waitUntil: 'networkidle' });

  // 1. The overview, as it opens: one GET, nothing else.
  const outbound0 = fs.readFileSync(path.join(DIR, 'outbound.log'), 'utf8');
  page.on('request', (r) => { if (r.url().includes('/admin-api/')) report.onOpen.push(r.method() + ' ' + new URL(r.url()).pathname); });
  await page.evaluate(() => window.go('domainswitch'));
  await page.waitForSelector('.dwi-steps');
  await page.waitForTimeout(400);
  report.onOpenFiltered = report.onOpen.filter((u) => u.includes('domain-switch'));
  report.outboundOnOpen = fs.readFileSync(path.join(DIR, 'outbound.log'), 'utf8').length - outbound0.length;
  report.progressLine = await page.$eval('.dwi-bar-top', (e) => e.textContent);
  await shot(page, '01-overview');

  // 2. A red Verify with its fix: step 1 finds the seeded RISK lines.
  report.verify.start = await stepDo(page, 'start', 'verify');
  await shot(page, '02-step1-red-with-fix', 'start');

  // 3. Step 2 saved (verified at once), step 6 Verify green with the learned IP.
  await openStep(page, 2);
  report.verify.name = (await action(page, 'set_names')).body.message;
  await openStep(page, 6);
  report.verify.dns_wait = await stepDo(page, 'dns_wait', 'verify');
  await shot(page, '03-step6-verify-green', 'dns_wait');

  // 4. Payments: Verify (sandbox, no live keys) then Skip for now.
  await openStep(page, 11);
  report.verify.payments = await stepDo(page, 'payments', 'verify');
  report.verify.paymentsSkip = await stepDo(page, 'payments', 'skip');
  await openStep(page, 11);
  await shot(page, '04-step11-skipped', 'payments');

  // 5. The step 8 gate (DNS green, certificate not yet verified).
  await openStep(page, 8);
  await shot(page, '05-step8-gate', 'switch');

  // 6. The final summary.
  await openStep(page, 17);
  await shot(page, '06-final-summary', 'done');
  report.summary = await page.$eval('[data-key="done"] .dwi-sum', (e) => e.innerText);

  // Every visible step header answers a real click (elementFromPoint over its centre).
  report.clickable = await page.$$eval('.dwi-head', (els) => els.every((el) => {
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return hit === el || el.contains(hit);
  }));

  fs.writeFileSync(path.join(OUT, `report-${WIDTH}.json`), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
  proxy.close();
})().catch((e) => { console.error(e); process.exit(1); });
