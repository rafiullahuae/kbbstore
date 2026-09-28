/*
 * Orders → New order, driven for real, and photographed once the order exists.
 * (Lane SE)
 *
 * WHY THE WHOLE FLOW AND NOT AN INJECTED PAYLOAD. The receipt is rendered by
 * `doneHTML()` inside manual-order-screen.blade.php's IIFE from `created`, which
 * is set only by the response to the real POST. Nothing outside that closure can
 * reach either, and a picture of markup this script assembled would be a picture
 * of something the console does not draw. So the operator's own path is walked:
 * search the customer, search each product, add it, place the order.
 *
 * Boot the preview first:
 *
 *   sh tools/se-preview.sh 8993
 *   SE_BASE=http://127.0.0.1:8993 node tools/se-manual-order-shots.cjs
 *
 * tools/se-seed.php has already put a Set of three members, an ordinary product
 * beside it, a customer, a UAE delivery zone and cash on delivery in the
 * preview database.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SE_BASE || 'http://127.0.0.1:8993';
const OUT = process.env.SE_OUT || path.join(__dirname, '..', 'docs', 'lane-se-shots');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const MEASURE = () => {
  const round = (n) => (n == null ? null : Math.round(n));
  const px = (el, p) => (el ? round(parseFloat(getComputedStyle(el)[p])) : null);

  const rows = [...document.querySelectorAll('#moScreen table tbody tr')];

  /* Structure-blind, like tools/se-doc-shots.cjs: a member block is an element
     with no ELEMENT children of its own whose text starts "<n> × ".
     
     ▲ <br> COUNTS AS NO CHILD HERE, and that is the whole reason this predicate
       is spelled out rather than `!el.children.length`. This receipt joins its
       member lines with <br> inside ONE div, so the strict test found nothing
       and reported memberLines: [] for a receipt that was visibly drawing all
       three of them — a measurement bug that would have read as a feature bug.
       se-doc-shots.cjs can use the strict test because the documents emit one
       element per member. */
  const blocks = [...document.querySelectorAll('#moScreen *')]
    .filter((el) => [...el.children].every((c) => c.tagName === 'BR'));
  const members = blocks.filter((el) => /^\d+\s*×\s/.test((el.textContent || '').trim()));

  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    overflowsSideways: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    state: document.querySelector('#moScreen')?.getAttribute('data-mo-state') ?? null,
    itemRows: rows.length,
    rowText: rows.map((r) => r.innerText.replace(/\s+/g, ' ').trim()),
    memberLines: members.map((el) => el.innerText.trim().split('\n').map((l) => l.trim())),
    memberFontSize: px(members[0], 'fontSize'),
    /* The other half of the evidence: the ordinary product in the same order
       must not have grown a member list. */
    ordinaryLineHasMembers: members.some((el) => el.textContent.includes('Ceramide Daily Moisturiser')),
  };
};

async function typeAndPick(page, input, text, pickSelector, index = 0) {
  await page.fill(input, '');
  await page.type(input, text, { delay: 25 });
  await page.waitForTimeout(1100);
  const rows = await page.$$(pickSelector);
  if (!rows[index]) throw new Error(`no result for "${text}" at ${pickSelector}[${index}]`);
  await rows[index].click();
  await page.waitForTimeout(500);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1500 } });
  const page = await ctx.newPage();

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);

  await page.evaluate(() => window.go('order-new'));
  await page.waitForTimeout(1200);

  /* The customer, then the two products: the Set FIRST, so the receipt shows a
     member list above a line that has none. */
  await typeAndPick(page, '#moCustSearch', 'Aisha', '#moCustResults [data-mo-pick], #moCustResults button, #moCustResults [role=button]');
  await typeAndPick(page, '#moProdSearch', 'Glow Starter', '#moProdResults [data-mo-add], #moProdResults button, #moProdResults [role=button]');
  await typeAndPick(page, '#moProdSearch', 'Ceramide', '#moProdResults [data-mo-add], #moProdResults button, #moProdResults [role=button]');

  /*
   * The delivery address, typed the way an operator types it. The seeded
   * customer HAS a saved address, but the screen does not prefill one from the
   * customer record — it asks — so a script that skipped these three fields got
   * "A delivery address is needed / Which city? / Which emirate or region?" and
   * never reached the receipt. That is the screen behaving correctly; this is
   * the operator's own path.
   */
  await page.fill('#mo_line1', 'Apartment 1204, Marina Heights');
  await page.fill('#mo_city', 'Dubai');
  await page.fill('#mo_state', 'Dubai');
  await page.waitForTimeout(700);

  await page.click('#moSubmit');
  await page.waitForSelector('#moScreen[data-mo-state="created"]', { timeout: 20000 });
  await page.waitForTimeout(600);

  const report = {};

  for (const width of [390, 1280]) {
    await page.setViewportSize({ width, height: 1500 });
    await page.waitForTimeout(450);
    const out = path.join(OUT, `admin-manual-order.${width}.png`);
    await page.screenshot({ path: out, fullPage: true });
    report[`admin-manual-order @ ${width}`] = await page.evaluate(MEASURE);
    console.log(out);
  }

  await browser.close();

  fs.writeFileSync(path.join(OUT, 'measurements-manual-order.json'), JSON.stringify(report, null, 2) + '\n');
  console.log('\n' + JSON.stringify(report, null, 2));
})();
