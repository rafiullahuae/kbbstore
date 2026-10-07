/*
 * LANE DW — Platform → Domain switch, end to end, in Chromium.
 *
 *   php tools/dw-wire.php && sh tools/dw-preview.sh 10780
 *   node tools/dw-e2e.cjs 1280      # then re-run dw-preview.sh, and:
 *   node tools/dw-e2e.cjs 390
 *
 * Every button on the screen is pressed, in the checklist's order, the way the
 * owner presses it: signed in on extrabeauty.ae first, then on kbeautybliss.com
 * (both reached through --host-resolver-rules; tools/dw-router.php plays the
 * TLS-ending proxy). The outside world — DNS, the certificate, Stripe, Tabby,
 * Tamara, the WordPress picture host — is faked at the boundary only, from
 * scenario.json, which this script rewrites to walk each failure path first:
 * DNS still at Hostinger, an AAAA record, no certificate, a provider refusing,
 * RISK above 0. Recorded for each press: the HTTP status, the message the
 * owner sees, the step's status before and after. Also: every copy button's
 * clipboard content (Clipboard API on a secure origin, the execCommand
 * fallback on plain http), console errors, 5xx responses, the page's
 * horizontal overflow, and every outbound request the shop made.
 *
 * Writes docs/lane-dw-shots/e2e-<width>.json and the screenshots; exits 1 if
 * any expectation failed.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const APP = path.join(__dirname, '..');
const PORT = process.env.DW_PORT || '10780';
const DIR = process.env.DW_DIR || path.join(APP, 'storage/framework/testing/dw-preview');
const WIDTH = +(process.argv[2] || 1280);
const OUT = path.join(APP, 'docs', 'lane-dw-shots');
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OLD = 'https://extrabeauty.ae';
const NEW = 'https://kbeautybliss.com';
// A name nothing treats as secure (127.0.0.1 and localhost ARE secure contexts),
// for the copy fallback: a staging copy reached over plain http.
const STAGING = `http://staging.test:${PORT}`;
const TLS_PORT = 443;

/*
 * CLOUDWAYS' TLS-ENDING PROXY, PLAYED IN THIS PROCESS. Chromium reaches
 * https://extrabeauty.ae and https://kbeautybliss.com on 127.0.0.1:443 (through
 * --host-resolver-rules); this server ends TLS with a throwaway self-signed
 * certificate and hands the request to the preview over plain http with the
 * Host the browser sent, the way nginx/Varnish hand it to PHP on the server.
 * tools/dw-router.php then marks those two names HTTPS.
 */
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
  return new Promise((ok) => server.listen(TLS_PORT, '127.0.0.1', () => ok(server)));
}
fs.mkdirSync(OUT, { recursive: true });

const IP = '134.209.147.13';
const ZONES = {
  hostinger: { 'kbeautybliss.com': { A: ['177.202.242.149'], NS: ['ns1.dns-parking.com.'] }, 'extrabeauty.ae': { A: [IP] } },
  good: {
    'kbeautybliss.com': { A: [IP], NS: ['ns1.internet.bs.', 'ns2.internet.bs.'], MX: ['1 smtp.google.com.'], TXT: ['"v=spf1 include:_spf.google.com ~all"'] },
    'www.kbeautybliss.com': { CNAME: ['kbeautybliss.com.'] }, 'extrabeauty.ae': { A: [IP] },
  },
};
ZONES.aaaa = JSON.parse(JSON.stringify(ZONES.good));
ZONES.aaaa['kbeautybliss.com'].AAAA = ['2606:4700::6810:84e5'];

let sc = { dns: {}, tls: 'nodns', stripe: 'ok', tabby: 'ok', tamara: 'ok' };
function scenario(change) { sc = Object.assign({}, sc, change); fs.writeFileSync(path.join(DIR, 'scenario.json'), JSON.stringify(sc)); }

const log = [];
let failed = 0;
function check(what, pass, got) {
  log.push({ what, pass: !!pass, got });
  if (!pass) failed++;
  console.log((pass ? 'PASS ' : 'FAIL ') + what + (got !== undefined ? '  →  ' + (typeof got === 'string' ? got : JSON.stringify(got)) : ''));
}

const consoleErrors = [];
const serverErrors = [];
const clientErrors = [];
function watch(page, label) {
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(label + ': ' + m.text()); });
  page.on('pageerror', (e) => consoleErrors.push(label + ': ' + e.message));
  page.on('response', (r) => {
    if (r.status() >= 500) serverErrors.push(r.status() + ' ' + r.url());
    else if (r.status() >= 400) clientErrors.push(label + ': ' + r.status() + ' ' + r.request().method() + ' ' + r.url());
  });
  page.on('dialog', (d) => d.accept());
}

async function login(page, origin, email) {
  await page.goto(origin + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function open(page, origin) {
  await page.goto(origin + '/admin', { waitUntil: 'networkidle' });
  const ready = Promise.all([
    page.waitForResponse((r) => r.url().includes('/admin-api/domain-switch/readiness')),
    page.waitForResponse((r) => r.url().includes('/admin-api/domain-switch/pictures')),
  ]);
  await page.evaluate(() => window.go('domainswitch'));
  await ready;
  await page.waitForFunction(() => !document.querySelector('[data-dw="readiness"][disabled]') || !!document.querySelector('[data-dw="readiness_more"]'));
}

async function status(page, n) {
  return page.evaluate((n) => {
    const el = document.getElementById('dw-step-' + n);
    if (!el) return null;
    const cls = [...el.classList].find((c) => c.startsWith('is-')) || '';
    const msgs = el.querySelectorAll(':scope > .dw-body > .dw-msg, :scope > .dw-body > .dw-row + .dw-msg, .dw-msg');
    return { level: cls.slice(3), badge: el.querySelector('.dw-badge').textContent, msg: msgs.length ? msgs[msgs.length - 1].textContent : '' };
  }, n);
}

async function all(page) { const out = {}; for (let n = 1; n <= 11; n++) out[n] = (await status(page, n)).level; return out; }

/** Click a button the owner sees and wait for the server's answer. */
async function press(page, action, stepN) {
  const disabled = await page.$eval(`[data-dw="${action}"]`, (b) => b.disabled).catch(() => 'missing');
  if (disabled !== false) return { disabled };
  const resp = page.waitForResponse((r) => r.url().includes('/admin-api/domain-switch/run') && r.request().method() === 'POST');
  await page.click(`[data-dw="${action}"]`);
  const r = await resp;
  const body = await r.json().catch(() => ({}));
  await page.waitForFunction(() => !document.querySelector('.dw-btn[data-dw]') || ![...document.querySelectorAll('.dw-btn[data-dw]')].some((b) => b.textContent === 'Working…'));
  const s = stepN ? await status(page, stepN) : null;
  return { http: r.status(), ok: body.ok, message: body.message || '', level: s && s.level, shown: s && s.msg };
}

/** The same POST a button makes, sent while its button is disabled: the server must refuse too. */
async function force(page, body) {
  return page.evaluate(async (body) => {
    const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)');
    const r = await fetch('/admin-api/domain-switch/run', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m.pop()) : '' }, body: JSON.stringify(body) });
    const j = await r.json().catch(() => ({}));
    return { http: r.status, ok: j.ok, message: j.message || '' };
  }, body);
}

async function overflow(page) { return page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth })); }

/**
 * The whole screen. The console scrolls inside its content pane, so a
 * fullPage shot is one viewport tall; the viewport is grown to the pane's
 * scroll height for the picture and put back afterwards. (Tooling only: the
 * screen itself measures nothing.)
 */
async function shot(page, name) {
  const file = path.join(OUT, `${WIDTH}-${name}.png`);
  const vp = page.viewportSize();
  const h = await page.evaluate(() => {
    let tallest = document.documentElement.scrollHeight;
    for (let el = document.querySelector('[data-dw-screen]'); el; el = el.parentElement) tallest = Math.max(tallest, el.scrollHeight + 160);
    return tallest;
  });
  await page.setViewportSize({ width: vp.width, height: Math.min(h, 16000) });
  await page.screenshot({ path: file, fullPage: true });
  await page.setViewportSize(vp);
  return path.relative(APP, file);
}

function env() { return fs.readFileSync(path.join(DIR, '.env'), 'utf8'); }

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: [
    '--no-proxy-server',
    '--host-resolver-rules=MAP extrabeauty.ae 127.0.0.1,MAP www.extrabeauty.ae 127.0.0.1,MAP kbeautybliss.com 127.0.0.1,MAP www.kbeautybliss.com 127.0.0.1,MAP staging.test 127.0.0.1',
  ] });
  const proxy = await startTlsProxy();
  const mobile = WIDTH < 600;
  const ctxOpts = { ignoreHTTPSErrors: true, viewport: { width: WIDTH, height: mobile ? 844 : 900 }, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, hasTouch: mobile };
  scenario({ dns: ZONES.hostinger, tls: 'nodns' });

  /* ── 0. a Store Manager is refused, and has no sidebar row ─────────────── */
  {
    const ctx = await browser.newContext(ctxOpts);
    const page = await ctx.newPage(); watch(page, 'manager');
    await login(page, OLD, 'manager@example.com');
    await page.goto(OLD + '/admin', { waitUntil: 'networkidle' });
    const r = await page.evaluate(async () => (await fetch('/admin-api/domain-switch', { headers: { Accept: 'application/json' } })).status);
    const row = await page.$('.side .nav-item[data-go="domainswitch"]');
    const post = await force(page, { action: 'set_names', domain: 'kbeautybliss.com' });
    check('manager: GET state is 403', r === 403, r);
    check('manager: POST set_names is 403', post.http === 403, post);
    check('manager: no sidebar row', row === null);
    await ctx.close();
  }

  /* ── 1. copy buttons, Clipboard API, on the shop's own https origin ─────── */
  {
    const ctx = await browser.newContext(ctxOpts);
    await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: OLD });
    const page = await ctx.newPage(); watch(page, 'clipboard');
    await login(page, OLD, 'owner@example.com');
    await open(page, OLD);
    const values = await page.$$eval('[data-copy]', (bs) => bs.map((b) => b.getAttribute('data-copy')));
    const expected = ['kbeautybliss.com', 'www.kbeautybliss.com', IP, 'smtp.google.com', 'v=spf1 include:_spf.google.com ~all', '177.202.242.149',
      'kbeautybliss.com, www.kbeautybliss.com, extrabeauty.ae, www.extrabeauty.ae', 'https://kbeautybliss.com/admin-api/instagram/callback',
      'extrabeauty.ae', 'www.extrabeauty.ae', 'kbeautybliss.com, www.kbeautybliss.com'];
    check('every value the checklist names has a copy button', expected.every((v) => values.includes(v)), values);
    const wrong = [];
    for (let i = 0; i < values.length; i++) {
      const b = (await page.$$('[data-copy]'))[i];
      await b.click();
      await page.waitForFunction((el) => el.textContent !== 'Copy', b);
      const got = await page.evaluate(() => navigator.clipboard.readText());
      const label = await b.textContent();
      if (got !== values[i] || label !== 'Copied ✓') wrong.push({ want: values[i], got, label });
    }
    check(`Clipboard API: all ${values.length} copy buttons put exactly their value on the clipboard`, wrong.length === 0, wrong.length ? wrong : values.length + ' exact');
    await ctx.close();
  }

  /* ── 2. the owner on extrabeauty.ae, before the switch ──────────────────── */
  const ctxOld = await browser.newContext(ctxOpts);
  const page = await ctxOld.newPage(); watch(page, 'extrabeauty.ae');
  await login(page, OLD, 'owner@example.com');
  await open(page, OLD);

  const before = await all(page);
  check('before: statuses', JSON.stringify(before) === JSON.stringify({ 1: 'problem', 2: 'todo', 3: 'yours', 4: 'todo', 5: 'todo', 6: 'todo', 7: 'todo', 8: 'todo', 9: 'yours', 10: 'todo', 11: 'todo' }), before);
  const rd = await page.$$eval('#dw-step-1 .dw-find li', (ls) => ls.map((l) => l.textContent.slice(0, 140)));
  check('readiness: finds the WordPress-only photo and the extrabeauty.ae link, each RISK', rd.some((t) => t.startsWith('RISK') && t.includes('only WordPress has today')) && rd.some((t) => t.startsWith('RISK') && t.includes('still send shoppers to extrabeauty.ae')), rd.filter((t) => t.startsWith('RISK')));
  const locked = await page.$$eval('[data-dw="switch_address"],[data-dw="stripe"],[data-dw="tabby"],[data-dw="tamara"],[data-dw="forward_on"],[data-dw="remove_old"]', (bs) => bs.map((b) => b.disabled));
  check('before: switch, payments, forward and remove buttons are disabled', locked.every(Boolean), locked);
  check('before: no horizontal overflow', (await overflow(page)).scrollWidth <= (await overflow(page)).clientWidth, await overflow(page));
  const shots = { before: await shot(page, '01-before-switch') };

  // Copy fallback on plain http (no Clipboard API on an insecure origin): a
  // staging copy reached by IP, which is exactly where the fallback matters.
  const ctxLocal = await browser.newContext(ctxOpts);
  const pl = await ctxLocal.newPage(); watch(pl, 'plain-http');
  await login(pl, STAGING, 'owner@example.com');
  await open(pl, STAGING);
  await pl.evaluate(() => {
    window.__copied = [];
    const orig = document.execCommand.bind(document);
    document.execCommand = function (c) { if (c === 'copy') window.__copied.push(document.activeElement && document.activeElement.value); return orig.apply(document, arguments); };
  });
  const hasApi = await pl.evaluate(() => !!(navigator.clipboard && navigator.clipboard.writeText));
  await pl.click('#dw-step-4 [data-copy="v=spf1 include:_spf.google.com ~all"]');
  const copied = await pl.evaluate(() => window.__copied);
  const label = await pl.$eval('#dw-step-4 [data-copy="v=spf1 include:_spf.google.com ~all"]', (b) => b.textContent);
  check('fallback copy on plain http: the exact value is selected and copied', !hasApi && copied[0] === 'v=spf1 include:_spf.google.com ~all' && /Copied|Select/.test(label), { hasApi, copied, label });
  await ctxLocal.close();

  /* step 2 — refusal, then success, then again */
  await page.fill('#dw-domain', 'extrabeauty.ae');
  let r = await press(page, 'set_names', 2);
  check('step 2 refuses the old domain, plainly, changing nothing', r.http === 422 && /address the shop is leaving/.test(r.shown) && r.level === 'todo', r);
  await page.fill('#dw-domain', 'kbeautybliss.com');
  r = await press(page, 'set_names', 2);
  check('step 2: Save the new name → ✓', r.http === 200 && r.level === 'done' && /Main address: kbeautybliss\.com\. Old addresses: extrabeauty\.ae\. Forwarding: off/.test(r.shown), r);
  r = await press(page, 'set_names', 2);
  check('step 2 pressed twice: still ✓, same settings', r.http === 200 && r.level === 'done' && /Forwarding: off/.test(r.message), r.message);

  /* step 3 */
  r = await press(page, 'check_old_dns', 3);
  check('step 3: where extrabeauty.ae points', r.http === 200 && /extrabeauty\.ae points at 134\.209\.147\.13/.test(r.shown), r.shown);

  /* step 4 — Hostinger, AAAA, then right; and a reload */
  r = await press(page, 'check_dns', 4);
  check('step 4 with DNS still at Hostinger → ✕ and says so', r.level === 'problem' && /still points at the old host \(177\.202\.242\.149\)/.test(r.shown), r.shown);
  shots.dnsHostinger = await shot(page, '02-dns-still-hostinger');
  scenario({ dns: ZONES.aaaa });
  r = await press(page, 'check_dns', 4);
  check('step 4 with an AAAA record → ✕ and says delete it', r.level === 'problem' && /has an AAAA record .* Delete it/.test(r.shown), r.shown);
  scenario({ dns: ZONES.good });
  r = await press(page, 'check_dns', 4);
  check('step 4 with the right records → ✓', r.level === 'done' && /points at this server and has no AAAA record/.test(r.shown), r.shown);
  await open(page, OLD);
  check('step 4 after a reload: still ✓ (the dated result, not a click flag)', (await status(page, 4)).level === 'done', await status(page, 4));

  /* step 5 — no certificate, then a valid one */
  scenario({ tls: 'nocert' });
  r = await press(page, 'check_tls', 5);
  check('step 5 with no certificate → ✕ and says what to do', r.level === 'problem' && /no valid certificate for kbeautybliss\.com yet/.test(r.shown), r.shown);
  scenario({ tls: 'ok' });
  r = await press(page, 'check_tls', 5);
  check('step 5 with a valid certificate → ✓', r.level === 'done' && /valid/.test(r.shown), r.shown);
  check('step 3 reads ✓ once the new domain answers over https', (await status(page, 3)).level === 'done', await status(page, 3));

  /* the locked buttons, forced: the server refuses too */
  const envBefore = env();
  r = await force(page, { action: 'switch_address', confirm: 'https://extrabeauty.ae' });
  check('step 6 forced from extrabeauty.ae → 409, .env untouched', r.http === 409 && /only works on the new address/.test(r.message) && env() === envBefore, r);
  r = await force(page, { action: 'stripe' });
  check('step 7 forced before the switch → 409', r.http === 409 && /Do step 6 first/.test(r.message), r);
  r = await force(page, { action: 'forward_on' });
  check('step 10 forced before the switch → 409', r.http === 409, r);
  r = await force(page, { action: 'remove_old', confirm: 'REMOVE' });
  check('step 11 forced before the switch → 409', r.http === 409, r);

  /* step 8 — fetch the WordPress-only photo, then again */
  r = await press(page, 'fetch_pictures', 8);
  check('step 8: fetch → 1 picture, then ✓', r.http === 200 && /Fetched 1 picture\(s\)/.test(r.shown) && r.level === 'done', r);
  r = await force(page, { action: 'fetch_pictures' });
  check('step 8 pressed again: nothing left, no error', r.http === 200 && /Fetched 0 picture\(s\)/.test(r.message), r.message);
  await ctxOld.close();

  /* ── 3. the owner on kbeautybliss.com ──────────────────────────────────── */
  const ctxNew = await browser.newContext(ctxOpts);
  const p2 = await ctxNew.newPage(); watch(p2, 'kbeautybliss.com');
  await login(p2, NEW, 'owner@example.com');
  await open(p2, NEW);
  const onNew = await all(p2);
  check('on kbeautybliss.com: steps 2–5 ✓, 6 to do and its button live', onNew[2] === 'done' && onNew[3] === 'done' && onNew[4] === 'done' && onNew[5] === 'done' && onNew[6] === 'todo'
    && (await p2.$eval('[data-dw="switch_address"]', (b) => !b.disabled)), onNew);

  r = await press(p2, 'switch_address', 6);
  check('step 6: Use https://kbeautybliss.com → ✓, APP_URL written, Site URL set, caches cleared', r.http === 200 && r.level === 'done' && /APP_URL="https:\/\/kbeautybliss\.com"/.test(env()) && /Site URL is https:\/\/kbeautybliss\.com/.test(r.shown), { r, env: env().split('\n') });
  r = await press(p2, 'switch_address', 6);
  check('step 6 pressed twice: harmless', r.http === 200 && r.level === 'done', r);
  await open(p2, NEW);
  check('step 6 after a reload: ✓', (await status(p2, 6)).level === 'done');

  /* step 7 — each provider failing first, then succeeding, then again */
  for (const [p, failMsg] of [['stripe', /Stripe: .*Invalid API Key/], ['tabby', /Tabby: .*AE: Tabby refused to register this shop\./], ['tamara', /registering again failed/]]) {
    scenario({ [p]: 'error' });
    r = await press(p2, p, 7);
    const lvl = await p2.$eval(`[data-dw="${p}"] + .dw-badge`, (b) => b.textContent);
    check(`step 7 ${p} refusing → ✕ with the provider's reason`, r.http === 422 && failMsg.test(r.message) && /Needs attention/.test(lvl), r.message);
    scenario({ [p]: 'ok' });
    r = await press(p2, p, 7);
    const ok = await p2.$eval(`[data-dw="${p}"] + .dw-badge`, (b) => b.textContent);
    check(`step 7 ${p} → ✓`, r.http === 200 && /Done/.test(ok), r.message);
    r = await press(p2, p, 7);
    check(`step 7 ${p} pressed twice: still ✓`, r.http === 200, r.message);
  }
  check('step 7: ✓ with all three', (await status(p2, 7)).level === 'done', await status(p2, 7));

  /* step 10 */
  r = await press(p2, 'forward_on', 10);
  check('step 10: forward → ✓', r.http === 200 && r.level === 'done', r.shown);
  r = await force(p2, { action: 'forward_on' });
  check('step 10 pressed twice: harmless', r.http === 200, r.message);

  /* step 11 — refused at RISK > 0, then the owner fixes the link, then removed */
  r = await press(p2, 'remove_old', 11);
  check('step 11 with RISK > 0 → refused, nothing removed', r.http === 409 && /Nothing was removed/.test(r.shown) && (await status(p2, 2)).level === 'done', r.shown);
  shots.removeRefused = await shot(p2, '03-remove-refused-risk');
  execSync(`. ${DIR}/env.sh && php artisan tinker --execute="DB::table('products')->where('slug','dw-snail-cream')->update(['description' => '<p>A rich cream.</p>']);"`, { cwd: APP, shell: '/bin/sh' });
  const recheck = p2.waitForResponse((x) => x.url().includes('/domain-switch/readiness'));
  await p2.click('[data-dw="readiness"]');
  await recheck;
  await p2.waitForFunction(() => !document.querySelector('[data-dw="readiness"][disabled]'));
  check('step 1 after the fix: RISK 0 → ✓', (await status(p2, 1)).level === 'done', await p2.$eval('#dw-step-1 .dw-row', (e) => e.textContent));
  r = await press(p2, 'remove_old', 11);
  check('step 11: remove extrabeauty.ae → ✓', r.http === 200 && r.level === 'done' && /no longer knows extrabeauty\.ae/.test(r.shown), r.shown);
  r = await force(p2, { action: 'remove_old', confirm: 'REMOVE' });
  check('step 11 pressed twice: harmless', r.http === 200, r.message);
  r = await force(p2, { action: 'set_names', domain: 'kbeautybliss.com' });
  check('step 2 pressed after step 11 does not bring extrabeauty.ae back', r.http === 200 && /Nothing to do/.test(r.message), r.message);

  await open(p2, NEW);
  const after = await all(p2);
  check('all green after a reload', JSON.stringify(after) === JSON.stringify({ 1: 'done', 2: 'done', 3: 'done', 4: 'done', 5: 'done', 6: 'done', 7: 'done', 8: 'done', 9: 'yours', 10: 'done', 11: 'done' }), after);
  check('all green: no horizontal overflow', (await overflow(p2)).scrollWidth <= (await overflow(p2)).clientWidth, await overflow(p2));
  const s1 = await p2.$eval('#dw-step-1 .dw-row', (e) => e.textContent);
  check('all green: the check reads RISK 0 · TODO 0, and step 2 offers no button that would undo step 11', /RISK 0/.test(s1) && /TODO 0/.test(s1) && !(await p2.$('#dw-step-2 [data-dw="set_names"]')), s1);
  shots.allGreen = await shot(p2, '04-all-green');
  await ctxNew.close();
  await browser.close();
  proxy.close();

  const outbound = fs.readFileSync(path.join(DIR, 'outbound.log'), 'utf8').trim().split('\n').filter(Boolean);
  const hosts = [...new Set(outbound.map((l) => new URL(l.split(' ')[1]).host))].sort();
  const names = [...new Set(outbound.filter((l) => l.includes('dns.google')).map((l) => new URL(l.split(' ')[1]).searchParams.get('name')))].sort();
  check('outbound: only the resolver, the new domain, Stripe, Tabby and Tamara', hosts.every((h) => ['dns.google', 'kbeautybliss.com', 'api.stripe.com', 'api.tabby.ai', 'api-sandbox.tamara.co', 'api.tamara.co'].includes(h)), hosts);
  check('DNS: only the configured names were looked up', names.every((n) => ['kbeautybliss.com', 'www.kbeautybliss.com', 'extrabeauty.ae'].includes(n)), names);
  // Chromium logs every 4xx answer to a fetch as "Failed to load resource";
  // those are the refusals this run provoked on purpose and are listed and
  // checked one by one below. Anything else on the console is a defect.
  // Plus one browser notice that only exists on PLAIN HTTP: the existing
  // sign-in and presence endpoints send Clear-Site-Data, which Chromium says it
  // ignores on an insecure origin. The live shop is https; the staging.test
  // context is here only to exercise the copy fallback.
  const jsErrors = consoleErrors.filter((e) => !/Failed to load resource: the server responded with a status of (403|409|422)/.test(e)
    && !/^plain-http: Clear-Site-Data header on 'http:\/\/staging\.test:\d+\/(admin\/login|admin-api\/presence\/beat)': Not supported for insecure origins\.$/.test(e));
  check('no console errors (no script error, no unexpected failed load)', jsErrors.length === 0, jsErrors);
  const unexpected4xx = clientErrors.filter((e) => !/admin-api\/domain-switch(\/run)?$/.test(e) || !/: (403|409|422) /.test(e));
  check('every 4xx was a refusal this run provoked (403 manager, 409 locked step, 422 failure path)', unexpected4xx.length === 0, unexpected4xx.length ? unexpected4xx : clientErrors.length + ' expected');
  check('no 5xx response', serverErrors.length === 0, serverErrors);

  const report = { width: WIDTH, passed: log.length - failed, failed, shots, outbound_hosts: hosts, dns_names: names, refusals: clientErrors, log };
  fs.writeFileSync(path.join(OUT, `e2e-${WIDTH}.json`), JSON.stringify(report, null, 2));
  console.log(`\n${WIDTH}px: ${log.length - failed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
