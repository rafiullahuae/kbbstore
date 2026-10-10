/*
 * Lane RC — the evidence. Every reset-to-default in the admin asks
 * "Are you sure?" (Yes / No) in the admin's own box first.
 *
 *   sh tools/rcc-preview.sh 10340
 *   BASE=http://127.0.0.1:10340 node tools/rcc-shots.cjs
 *
 * At 390 and 1280:
 *   - Appearance -> Checkout page -> Back to defaults: the box open over the
 *     screen; No leaves the moved slider where it was; Yes puts it back.
 *   - Appearance -> Header -> Reset to defaults: the box open.
 *   - Store -> Modules -> a group's "Defaults" (asked nothing before): the box
 *     open, naming the group; No leaves the switched module alone.
 *   - window.kbbConfirm itself: Escape, the backdrop and Enter-on-No answer
 *     No, Yes answers Yes, focus starts on No and goes back to the opener.
 * Exits non-zero if any check fails, so it doubles as the Chromium check.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10340';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-rc-shots';
const out = { checks: [], numbers: {} };
let failed = 0;

function check(name, ok, detail) {
  out.checks.push({ name, ok: !!ok, detail });
  if (!ok) failed++;
  console.log((ok ? 'ok   ' : 'FAIL ') + name + (detail !== undefined ? '  ' + JSON.stringify(detail) : ''));
}

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
}

const box = (page) => page.evaluate(() => {
  const bg = document.getElementById('kbbSureBg');
  const card = bg.querySelector('.kbb-sure');
  const r = card.getBoundingClientRect();
  return {
    open: bg.classList.contains('on'),
    text: document.getElementById('kbbSureText').textContent,
    focus: document.activeElement && document.activeElement.id,
    card: { w: Math.round(r.width), h: Math.round(r.height), x: Math.round(r.x), y: Math.round(r.y) },
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  };
});

async function open(page, screen, sel) {
  await page.evaluate((s) => window.go(s), screen);
  await page.waitForSelector(sel, { timeout: 20000 });
  await page.waitForTimeout(500);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const errors = [];

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(width + ' ' + e.message));
    page.on('dialog', (d) => { errors.push(width + ' native dialog: ' + d.message()); d.dismiss(); });
    await signIn(page);

    /* ── Appearance -> Checkout page ── */
    await open(page, 'checkoutpage', '[data-chp-defaults]');
    const slider = 'input[type=range][data-chp-key]';
    const key = await page.$eval(slider, (el) => el.dataset.chpKey);
    const sel = `input[type=range][data-chp-key="${key}"]`;
    const before = await page.$eval(sel, (el) => el.value);
    const moved = await page.$eval(sel, (el) => {
      el.value = el.value === el.max ? el.min : el.max;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return el.value;
    });
    await page.click('[data-chp-defaults]');
    await page.waitForTimeout(250);
    let b = await box(page);
    out.numbers[`checkout-open-${width}`] = b;
    check(`${width} checkout: box opens with its own question`, b.open && /Checkout page settings/.test(b.text), b.text);
    check(`${width} checkout: focus starts on No`, b.focus === 'kbbSureNo', b.focus);
    check(`${width} checkout: no horizontal scroll`, b.scrollWidth <= b.clientWidth, [b.scrollWidth, b.clientWidth]);
    await page.screenshot({ path: `${OUT}/checkout-ask-${width}.png` });

    await page.click('#kbbSureNo');
    await page.waitForTimeout(250);
    const afterNo = await page.$eval(sel, (el) => el.value);
    check(`${width} checkout: No leaves the moved slider alone`, afterNo === moved && !(await box(page)).open, { before, moved, afterNo });
    await page.screenshot({ path: `${OUT}/checkout-after-no-${width}.png` });

    await page.click('[data-chp-defaults]');
    await page.waitForTimeout(250);
    await page.click('#kbbSureYes');
    await page.waitForTimeout(400);
    const afterYes = await page.$eval(sel, (el) => el.value);
    check(`${width} checkout: Yes puts the default back`, afterYes !== moved, { before, moved, afterYes });
    await page.screenshot({ path: `${OUT}/checkout-after-yes-${width}.png` });

    /* ── Appearance -> Header ── */
    await open(page, 'header', '#hdReset');
    await page.click('#hdReset');
    await page.waitForTimeout(250);
    b = await box(page);
    out.numbers[`header-open-${width}`] = b;
    check(`${width} header: box opens`, b.open && /Header settings/.test(b.text), b.text);
    await page.screenshot({ path: `${OUT}/header-ask-${width}.png` });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);
    check(`${width} header: Escape answers No and focus returns to the button`,
      !(await box(page)).open && (await page.evaluate(() => document.activeElement && document.activeElement.id)) === 'hdReset');

    /* ── Store -> Modules: a group's "Defaults" asked nothing before ── */
    await open(page, 'modules', '[data-mdgdef]');
    const group = await page.$eval('[data-mdgdef]', (el) => el.dataset.mdgdef);
    const onBefore = await page.$eval('.mdcard', (c) => c.querySelector('.mdghd em').textContent);
    await page.click('[data-mdgall="' + group + '"]');
    await page.waitForTimeout(200);
    const onAll = await page.$eval('.mdcard', (c) => c.querySelector('.mdghd em').textContent);
    await page.click('[data-mdgdef="' + group + '"]');
    await page.waitForTimeout(250);
    b = await box(page);
    out.numbers[`modules-open-${width}`] = b;
    check(`${width} modules: group Defaults now asks, naming the group`, b.open && /modules in “/.test(b.text), b.text);
    await page.screenshot({ path: `${OUT}/modules-ask-${width}.png` });
    // The backdrop answers No.
    await page.mouse.click(4, 4);
    await page.waitForTimeout(200);
    const onNo = await page.$eval('.mdcard', (c) => c.querySelector('.mdghd em').textContent);
    check(`${width} modules: backdrop answers No, the group stays as switched`, onNo === onAll && !(await box(page)).open, { onBefore, onAll, onNo });
    await page.click('[data-mdgdef="' + group + '"]');
    await page.waitForTimeout(200);
    await page.click('#kbbSureYes');
    await page.waitForTimeout(300);
    const onYes = await page.$eval('.mdcard', (c) => c.querySelector('.mdghd em').textContent);
    check(`${width} modules: Yes puts the group back to its defaults`, onYes === onBefore, { onBefore, onAll, onYes });

    /* ── window.kbbConfirm itself ── */
    const api = await page.evaluate(async () => {
      const r = {};
      let p = window.kbbConfirm('Test question?', { yes: 'Yes, reset' });
      r.yesLabel = document.getElementById('kbbSureYes').textContent;
      document.getElementById('kbbSureYes').click();
      r.yes = await p;
      r.yesLabelAfter = (window.kbbConfirm('again'), document.getElementById('kbbSureYes').textContent);
      document.getElementById('kbbSureNo').click();
      p = window.kbbConfirm('Enter on No?');
      document.getElementById('kbbSureNo').click(); // what Enter on the focused No does
      r.enterNo = await p;
      p = window.kbbConfirm('Escape?');
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      r.escape = await p;
      p = window.kbbConfirm('Tab?');
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
      r.tabTo = document.activeElement.id;
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
      r.tabBack = document.activeElement.id;
      document.getElementById('kbbSureNo').click();
      r.tabAnswer = await p;
      return r;
    });
    check(`${width} kbbConfirm: Yes -> true, custom label used once`, api.yes === true && api.yesLabel === 'Yes, reset' && api.yesLabelAfter === 'Yes', api);
    check(`${width} kbbConfirm: No, Escape -> false; Tab stays inside`, api.enterNo === false && api.escape === false && api.tabTo === 'kbbSureYes' && api.tabBack === 'kbbSureNo', api);

    // Enter on the focused No, for real.
    const pEnter = page.evaluate(() => window.kbbConfirm('Enter?'));
    await page.waitForTimeout(100);
    await page.keyboard.press('Enter');
    check(`${width} kbbConfirm: a real Enter on the focused No answers No`, (await pEnter) === false);

    await ctx.close();
  }

  check('no page errors and no native dialogs', errors.length === 0, errors);
  fs.writeFileSync(`${OUT}/numbers.json`, JSON.stringify(out, null, 2));
  await browser.close();
  console.log(failed ? `${failed} check(s) failed` : 'all checks passed');
  process.exit(failed ? 1 : 0);
})();
