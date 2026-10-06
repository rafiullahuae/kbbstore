/*
 * LANE IN — "Install App" installs directly; the owner app's install card.
 *
 *   KBB_BASE=http://127.0.0.1:P KBB_APP=/<secret> node tools/in-shots.cjs
 *
 * Against tools/mac-preview.sh (the shop and the owner app in one preview).
 * Chromium at 390 (and the owner card at 1280). The install offer Chrome would
 * hand over (beforeinstallprompt) is SIMULATED: a real one needs Chrome's
 * engagement rule and an Android phone. The harness counts prompt() calls and,
 * for the picture, pins a grey "[harness]" label where Chrome's own dialog would
 * open — that label is not part of the shop.
 * Output: docs/in-shots/*.png and docs/in-shots/numbers.txt.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE;
const APP = process.env.KBB_APP;
const OUT = path.join(__dirname, '..', 'docs', 'in-shots');
fs.mkdirSync(OUT, { recursive: true });
const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));
const UA = {
  android: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
  iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
  iphone26: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1',
  instagram: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0',
  desktop: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
};

/* A stand-in for Chrome's install offer: counts prompt() and shows where Chrome's dialog would be. */
const OFFER = () => {
  window.__inPrompts = 0;
  window.__inOffer = () => {
    const e = new Event('beforeinstallprompt', { cancelable: true });
    e.prompt = () => {
      window.__inPrompts++;
      const d = document.createElement('div');
      d.textContent = '[harness] BeforeInstallPromptEvent.prompt() called ×' + window.__inPrompts + ' — Chrome draws its own "Install app?" dialog here';
      d.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483647;background:#333;color:#fff;font:13px/1.4 system-ui;padding:12px 14px;border-radius:10px';
      document.body.appendChild(d);
      return Promise.resolve();
    };
    e.userChoice = new Promise(() => {});
    window.dispatchEvent(e);
  };
};

async function shop(browser, name, ua, offer) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: ua });
  await ctx.addInitScript(OFFER);
  const page = await ctx.newPage();
  page.on('pageerror', (e) => say('  PAGE ERROR ' + e.message));
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  if (offer) await page.evaluate(() => window.__inOffer());
  await page.locator('.kfa').scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, 200));
  await sleep(300);
  const m = () => page.evaluate(() => ({ rowH: Math.round(document.querySelector('.kfa').getBoundingClientRect().height),
    lnH: Math.round(document.querySelector('.kfa-ln').getBoundingClientRect().height),
    line: document.querySelector('.kfa-ty').textContent, sw: document.documentElement.scrollWidth,
    arrow: (document.querySelector('.kfa-ar') || {}).className || '-', sheet: !!document.querySelector('.kfa-bk'), prompts: window.__inPrompts }));
  const before = await m();
  if (name === 'android-offer') await page.screenshot({ path: path.join(OUT, 'shop-390-row-before.png') });
  await page.tap('.kfa-bt');
  await sleep(450);
  const after = await m();
  await page.screenshot({ path: path.join(OUT, 'shop-390-' + name + '.png') });
  say(name.padEnd(16) + ' before: row ' + before.rowH + 'px line-box ' + before.lnH + 'px | after tap: row ' + after.rowH + 'px line-box ' + after.lnH
    + 'px, line "' + after.line + '", arrow ' + after.arrow + ', sheet ' + after.sheet + ', prompt() calls ' + after.prompts + ', scrollWidth ' + after.sw);
  if (name === 'android-no-offer') {
    await page.evaluate(() => window.__inOffer());
    await sleep(100);
    const mid = await m();
    await page.tap('.kfa-bt');
    await sleep(300);
    const late = await m();
    say('  offer arrives later: hint gone -> line "' + mid.line + '"; next tap prompt() calls ' + late.prompts + ', sheet ' + late.sheet);
  }
  await ctx.close();
}

async function owner(browser, name, w, h, ua, offer) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: w < 700 ? 2 : 1, isMobile: w < 700, hasTouch: w < 700, userAgent: ua });
  await ctx.addInitScript(OFFER);
  const page = await ctx.newPage();
  page.on('pageerror', (e) => say('  PAGE ERROR ' + e.message));
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  if (offer) await page.evaluate(() => window.__inOffer());
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'in-' + name);
  await page.click('[data-enrol] button[type=submit]');
  await page.waitForSelector('[data-ic]', { timeout: 15000 });
  await sleep(900);
  const m = await page.evaluate(() => { const c = document.querySelector('[data-ic]'); const r = c.getBoundingClientRect();
    return { top: Math.round(r.top), h: Math.round(r.height), w: Math.round(r.width), first: c.parentElement.firstElementChild === c,
      btn: c.querySelector('.ic-go').textContent.trim(), sheets: document.querySelectorAll('.sheet').length, sw: document.documentElement.scrollWidth }; });
  await page.screenshot({ path: path.join(OUT, 'owner-' + name + '.png') });
  say('owner ' + name.padEnd(18) + ' card top ' + m.top + 'px, ' + m.w + 'x' + m.h + ', first on My store ' + m.first + ', button "' + m.btn + '", open sheets ' + m.sheets + ', scrollWidth ' + m.sw);
  if (!offer) {
    await page.click('[data-ic-show]');
    await sleep(500);
    await page.screenshot({ path: path.join(OUT, 'owner-' + name + '-show-me.png') });
    say('  Show me -> arrow ' + await page.evaluate(() => (document.querySelector('.ic-ar') || {}).className || '-'));
  } else {
    await page.click('[data-ic-go]');
    await sleep(300);
    await page.screenshot({ path: path.join(OUT, 'owner-' + name + '-tapped.png') });
    say('  Install app -> prompt() calls ' + await page.evaluate(() => window.__inPrompts));
  }
  await page.click('[data-ic-later]').catch(() => {});
  await sleep(200);
  say('  Not now -> card gone ' + await page.evaluate(() => !document.querySelector('[data-ic]')) + ', oa.ic stored ' + await page.evaluate(() => localStorage.getItem('oa.ic') !== null));
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  await shop(browser, 'android-offer', UA.android, true);
  await shop(browser, 'android-no-offer', UA.android, false);
  await shop(browser, 'iphone', UA.iphone, false);
  await shop(browser, 'iphone-ios26', UA.iphone26, false);
  await shop(browser, 'instagram', UA.instagram, false);
  await owner(browser, 'android-390', 390, 844, UA.android, false);
  await owner(browser, 'android-390-offer', 390, 844, UA.android, true);
  await owner(browser, 'iphone-390', 390, 844, UA.iphone, false);
  await owner(browser, 'laptop-1280-offer', 1280, 800, UA.desktop, true);
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'numbers.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
