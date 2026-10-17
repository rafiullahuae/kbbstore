/*
 * LANE BIO — Face ID / fingerprint in the owner app, run end to end in Chromium
 * with the DevTools virtual authenticator (ctap2, internal, user verification),
 * photographed at 390 and 1280.
 *
 *   KBB_BASE=http://localhost:P KBB_APP=/<secret> node tools/bio-shots.cjs
 *
 * Against tools/mac-preview.sh (owner@example.com / PIN 482615). Use
 * "localhost", not 127.0.0.1: WebAuthn refuses an IP address as the rp id.
 * Output: docs/lane-bio/*.png and docs/lane-bio/run.txt.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE;
const APP = process.env.KBB_APP;
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OUT = path.join(__dirname, '..', 'docs', 'lane-bio');
fs.mkdirSync(OUT, { recursive: true });
const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));

async function phone(browser, w, h, withAuth) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: w < 700 ? 2 : 1, isMobile: w < 700, hasTouch: w < 700 });
  const page = await ctx.newPage();
  const errors = [];
  const api = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('response', (r) => { if (r.url().includes('/api/') && !/api\/(changes|notifications|dashboard|orders|products|customers|analytics|categories)/.test(r.url())) api.push(r.request().method() + ' ' + r.url().split('/api/')[1] + ' ' + r.status()); });
  let auth = null;
  const cdp = await ctx.newCDPSession(page);
  if (withAuth) {
    await cdp.send('WebAuthn.enable');
    auth = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
  }
  return { ctx, page, errors, api, cdp, auth };
}

const shot = async (page, name) => { await sleep(500); await page.screenshot({ path: path.join(OUT, name) }); say('  shot ' + name); };
const stage = (page) => page.evaluate(() => (document.querySelector('.nav') ? 'app' : document.querySelector('.pad') ? 'pin' : document.querySelector('[data-enrol]') ? 'enrol' : '?'));

async function enrol(page) {
  await page.goto(BASE + APP + '/');
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.click('button[type=submit]');
  await page.waitForSelector('.nav');
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });

  for (const [w, h] of [[390, 844], [1280, 860]]) {
    say(`== ${w}px, virtual authenticator (ctap2, internal, UV)`);
    const p = await phone(browser, w, h, true);
    const { page } = p;
    await enrol(page);
    await page.waitForSelector('[data-bio-yes]', { timeout: 5000 });
    say('  offer after PIN shown: yes');
    await shot(page, `offer-after-pin-${w}.png`);
    await page.click('[data-close]');
    await sleep(500);

    await page.evaluate(() => { location.hash = '#/more'; });
    await page.waitForSelector('[data-bio]');
    await page.locator('[data-bio]').scrollIntoViewIfNeeded();
    say('  settings before: aria-checked=' + await page.getAttribute('[data-bio]', 'aria-checked') + ' · ' + await page.locator('.row:has([data-bio]) small').innerText());
    await shot(page, `settings-before-${w}.png`);
    await page.click('[data-bio]');
    await page.waitForFunction(() => document.querySelector('[data-bio]') && document.querySelector('[data-bio]').getAttribute('aria-checked') === 'true', null, { timeout: 10000 });
    await page.locator('[data-bio]').scrollIntoViewIfNeeded();
    say('  settings after: aria-checked=' + await page.getAttribute('[data-bio]', 'aria-checked') + ' · ' + await page.locator('.row:has([data-bio]) small').innerText());
    const creds = (await p.cdp.send('WebAuthn.getCredentials', { authenticatorId: p.auth })).credentials;
    say('  credentials on the virtual authenticator: ' + creds.length + ' (resident ' + creds.filter((c) => c.isResidentCredential).length + ')');
    await shot(page, `settings-after-${w}.png`);

    // A fresh launch: the lock screen asks Face ID / fingerprint by itself, once.
    await page.evaluate(() => sessionStorage.clear()); await page.reload();   // a relaunch: the tab's key is gone
    await page.waitForSelector('.nav', { timeout: 10000 });
    say('  fresh launch -> auto prompt -> ' + await stage(page));

    // Lock now: the button, no auto prompt; then the button.
    await page.evaluate(() => { location.hash = '#/more'; });
    await page.waitForSelector('.view [data-lock]'); await page.waitForLoadState('networkidle'); await sleep(600);
    await page.click('[data-lock]');
    try { await page.waitForSelector('.pin [data-bio]:not([hidden])', { timeout: 8000 }); } catch (e) {
      await page.screenshot({ path: path.join(__dirname, '..', 'storage', 'bio-logs', 'fail.png') });
      say('  FAIL debug stage=' + await stage(page) + ' hint=' + await page.evaluate(() => localStorage.getItem('oa.bio')) + ' api=' + p.api.join(' | ') + ' html=' + (await page.evaluate(() => document.getElementById('oa-app').innerHTML)).slice(0, 400));
      throw e;
    }
    say('  lock now -> ' + await stage(page) + ', button visible');
    await shot(page, `lock-screen-${w}.png`);
    await page.click('.pin [data-bio]');
    await page.waitForSelector('.nav', { timeout: 10000 });
    say('  tap "Use Face ID / fingerprint" -> ' + await stage(page));

    // The phone says no (face not recognised): the PIN pad, as it was.
    await p.cdp.send('WebAuthn.setUserVerified', { authenticatorId: p.auth, isUserVerified: false });
    await page.evaluate(() => { location.hash = '#/more'; });
    await page.waitForSelector('.view [data-lock]'); await page.waitForLoadState('networkidle'); await sleep(600);
    await page.click('[data-lock]');
    await page.waitForSelector('.pin [data-bio]:not([hidden])');
    await page.click('.pin [data-bio]');
    await sleep(1500);
    say('  not verified -> ' + await stage(page) + ' · message "' + await page.locator('[data-msg]').innerText() + '"');
    if (w === 390) await shot(page, `lock-fallback-${w}.png`);
    for (const d of '482615') await page.click(`[data-k="${d}"]`);
    await page.waitForSelector('.nav', { timeout: 10000 });
    say('  then the PIN -> ' + await stage(page));

    // Turn it off from Settings.
    await page.evaluate(() => { location.hash = '#/more'; });
    await page.waitForSelector('[data-bio]');
    await page.waitForSelector('.view [data-bio][aria-checked="true"]');
    await page.click('.view [data-bio]');
    await page.waitForSelector('.view [data-bio][aria-checked="false"]');
    say('  switched off in Settings: aria-checked=false');
    await page.click('[data-lock]');
    await page.waitForSelector('.pad');
    await sleep(800);
    say('  lock after off -> button hidden: ' + await page.evaluate(() => document.querySelector('.pin [data-bio]').hidden));

    say('  scrollWidth ' + await page.evaluate(() => document.documentElement.scrollWidth) + ' at ' + w);
    say('  passkey requests: ' + p.api.join(' | '));
    say('  console/page errors: ' + (p.errors.length ? p.errors.join(' | ') : 'none'));
    await p.ctx.close();
  }

  say('== 390px, first login -> popup -> Enable');
  const e = await phone(browser, 390, 844, true);
  await enrol(e.page);
  await e.page.waitForSelector('[data-bio-yes]');
  await e.page.click('[data-bio-yes]');
  await e.page.waitForFunction(() => localStorage.getItem('oa.bio') === '1', null, { timeout: 10000 });
  say('  enabled from the popup; credentials: ' + (await e.cdp.send('WebAuthn.getCredentials', { authenticatorId: e.auth })).credentials.length);
  await e.page.evaluate(() => sessionStorage.clear()); await e.page.reload();
  await e.page.waitForSelector('.nav', { timeout: 10000 });
  await sleep(800);
  say('  next launch -> ' + await stage(e.page) + ' by Face ID; popup again: ' + (await e.page.$('[data-bio-yes]') ? 'yes' : 'no'));
  say('  passkey requests: ' + e.api.join(' | '));
  say('  console/page errors: ' + (e.errors.length ? e.errors.join(' | ') : 'none'));
  await e.ctx.close();

  say('== 390px, a phone with no Face ID / fingerprint (no authenticator)');
  const n = await phone(browser, 390, 844, false);
  await enrol(n.page);
  await sleep(800);
  say('  offer shown: ' + (await n.page.$('[data-bio-yes]') ? 'yes' : 'no'));
  await n.page.evaluate(() => { location.hash = '#/more'; });
  await n.page.waitForSelector('[data-bio]');
  await n.page.locator('[data-bio]').scrollIntoViewIfNeeded();
  say('  settings: disabled=' + await n.page.evaluate(() => document.querySelector('[data-bio]').disabled) + ' · ' + await n.page.locator('.row:has([data-bio]) small').innerText());
  await shot(n.page, 'settings-unavailable-390.png');
  say('  console/page errors: ' + (n.errors.length ? n.errors.join(' | ') : 'none'));
  await n.ctx.close();

  await browser.close();
  fs.writeFileSync(path.join(OUT, 'run.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); fs.writeFileSync(path.join(OUT, 'run.txt'), lines.join('\n') + '\nFAILED: ' + e.message + '\n'); process.exit(1); });
