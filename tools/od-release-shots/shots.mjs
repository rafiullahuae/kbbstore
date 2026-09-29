/* Photograph the release control on Orders -> (an order) -> Items, in the four
   states an operator can meet, and measure it. One viewport per invocation,
   RE-SEEDING BETWEEN THEM: step 3 actually releases the hold in the shared
   preview database, so running both widths in one process would give a second
   "before" that is really the first "after".

   Usage: node shots.mjs <width> <label>

   Scratchpad tooling. tools/ is not on UpdateGuard::ALLOWED_PREFIXES, so none of
   this can reach the server in a package. */
import pw from '/opt/node22/lib/node_modules/playwright/index.js';
const { chromium } = pw;

const BASE = 'http://127.0.0.1:8953';
const OUT = '/home/user/lane-od/docs/od-shots';
const [W, LABEL] = [Number(process.argv[2]), process.argv[3]];

/* No element-measuring API is used by the PANEL — ReleaseTheHoldTest forbids
   each of them in that file by name. This is the harness, which is the only
   place in this lane allowed to measure anything, and it measures in order to
   report numbers rather than to lay anything out. */
const measure = () => {
  const px = (el, p) => (el ? Math.round(parseFloat(getComputedStyle(el)[p])) : null);
  const box = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { w: Math.round(r.width), h: Math.round(r.height), fontSize: px(el, 'fontSize') };
  };
  const content = document.querySelector('#content');
  const panel = document.querySelector('#orh-panel');
  return {
    viewport: document.documentElement.clientWidth,
    docScrollWidth: document.documentElement.scrollWidth,
    contentScrollWidth: content ? content.scrollWidth : null,
    contentClientWidth: content ? content.clientWidth : null,
    horizontalOverflow: content ? content.scrollWidth > content.clientWidth : null,
    heading: (document.querySelector('#content .page-head h2') || {}).textContent || null,
    panel: box('#orh-panel'),
    panelPresent: !!panel,
    title: (document.querySelector('#orh-panel .orh-title') || {}).textContent || null,
    amount: (document.querySelector('#orh-panel .orh-amt') || {}).textContent || null,
    text: panel ? panel.textContent.replace(/\s+/g, ' ').trim().slice(0, 320) : null,
    buttons: panel
      ? [...panel.querySelectorAll('button')].map((b) => ({
          id: b.id, text: b.textContent.trim(), disabled: b.disabled, ...box('#' + b.id),
        }))
      : [],
    /* The control this one sits beside, so the pair can be read in one shot. */
    captureButton: box('#odCaptureGo'),
    refundButton: box('#odRefundToggle'),
  };
};

const openOrder = async (page, number) => {
  await page.evaluate(() => window.go('orders'));
  await page.waitForTimeout(2200);
  await page.evaluate((n) => {
    const b = [...document.querySelectorAll('[data-olview]')].find(
      (x) => (x.closest('tr') || {}).textContent && x.closest('tr').textContent.includes(n));
    if (b) b.click();
  }, number);
  await page.waitForTimeout(2200);
  await page.evaluate(() => {
    const el = document.querySelector('#orh-panel') || document.querySelector('#odItems');
    if (el) el.scrollIntoView({ block: 'center' });
  });
  await page.waitForTimeout(500);
};

const press = async (page, id) => {
  await page.evaluate((i) => { const b = document.getElementById(i); if (b) b.click(); }, id);
  await page.waitForTimeout(1600);
  await page.evaluate(() => {
    const el = document.querySelector('#orh-panel');
    if (el) el.scrollIntoView({ block: 'center' });
  });
  await page.waitForTimeout(300);
};

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  /* deviceScaleFactor 1 and a viewport shot rather than fullPage: the admin sets
     body{overflow:hidden} and scrolls inside #content, so a "full page" shot is
     the viewport anyway. */
  const ctx = await browser.newContext({ viewport: { width: W, height: 1180 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const out = { label: LABEL, width: W, steps: [] };

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@od.test');
  await page.fill('input[name=password]', 'od-preview-secret');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.waitForTimeout(1500);

  /* 0. BASELINE: the same order, with the partial switched off. This is the
        order screen exactly as it ships today — the panel is purely additive
        and this is the proof. */
  await page.goto(BASE + '/admin?odoff=1', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await openOrder(page, 'OD-RELEASE-1');
  out.steps.push({ step: '0-baseline-no-panel', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/0-baseline-${LABEL}.png`, fullPage: false });

  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);

  /* 1. A hold that CAN be released. */
  await openOrder(page, 'OD-RELEASE-1');
  out.steps.push({ step: '1-releasable', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/1-releasable-${LABEL}.png`, fullPage: false });

  /* 2. The confirmation, which says what it does to the buyer. */
  await press(page, 'orh-open');
  out.steps.push({ step: '2-confirm', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/2-confirm-${LABEL}.png`, fullPage: false });

  /* 3. Answering no keeps the hold, and the panel is back where it was. */
  await press(page, 'orh-cancel');
  out.steps.push({ step: '3-kept', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/3-kept-${LABEL}.png`, fullPage: false });

  /* 4. ...and answering yes releases it. */
  await press(page, 'orh-open');
  await press(page, 'orh-go');
  out.steps.push({ step: '4-released', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/4-released-${LABEL}.png`, fullPage: false });

  /* 5. A hold that CANNOT be released, with the server's own reason and no
        button at all. */
  await openOrder(page, 'OD-LIVE-1');
  out.steps.push({ step: '5-reason', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/5-reason-${LABEL}.png`, fullPage: false });

  /* 6. A payment method that holds nothing gets no panel at all. */
  await openOrder(page, 'OD-COD-1');
  out.steps.push({ step: '6-no-panel', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/6-no-panel-${LABEL}.png`, fullPage: false });

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
