/* Photograph Store -> Gateway webhooks, before and after every action, and
   measure it. One viewport per invocation, RE-SEEDING BETWEEN THEM: the whole
   job is a before/after pair and the first run registers the webhook and stores
   the limits in the shared preview database, so running both widths in one
   process gives a second "before" that is really the first "after".

   Usage: node shots.mjs <width> <label>

   Scratchpad tooling. tools/ is not on UpdateGuard::ALLOWED_PREFIXES, so none
   of this can reach the server in a package. */
import pw from '/opt/node22/lib/node_modules/playwright/index.js';
const { chromium } = pw;

const BASE = 'http://127.0.0.1:8952';
const OUT = '/home/user/lane-tm/docs/tm-shots';
const [W, LABEL] = [Number(process.argv[2]), process.argv[3]];

/* No element-measuring API is used by the SCREEN. This is the harness, which is
   the only place in this lane allowed to measure anything, and it measures in
   order to report numbers rather than to lay anything out. */
const measure = () => {
  const px = (el, p) => (el ? Math.round(parseFloat(getComputedStyle(el)[p])) : null);
  const box = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { w: Math.round(r.width), h: Math.round(r.height), fontSize: px(el, 'fontSize') };
  };
  const content = document.querySelector('#content');
  return {
    viewport: document.documentElement.clientWidth,
    docScrollWidth: document.documentElement.scrollWidth,
    contentScrollWidth: content ? content.scrollWidth : null,
    contentClientWidth: content ? content.clientWidth : null,
    horizontalOverflow: content ? content.scrollWidth > content.clientWidth : null,
    heading: (document.querySelector('.tmw-head h2') || {}).textContent || null,
    cards: document.querySelectorAll('.tmw-card').length,
    facts: [...document.querySelectorAll('.tmw-fact')].map((el) => ({
      label: el.querySelector('dt').textContent,
      value: el.querySelector('dd').textContent.trim().slice(0, 90),
    })),
    buttons: [...document.querySelectorAll('.tmw-wrap button')].map((b) => ({
      id: b.id, text: b.textContent.trim(), ...box('#' + b.id),
    })),
    said: (document.querySelector('.tmw-said') || {}).textContent || null,
    rows: [...document.querySelectorAll('.tmw-list li')].map((li) => li.textContent.trim()),
  };
};

const openScreen = async (page) => {
  await page.evaluate(() => window.go('paygw'));
  await page.waitForTimeout(1500);
};

const press = async (page, id) => {
  await page.evaluate((i) => { const b = document.getElementById(i); if (b) b.click(); }, id);
  await page.waitForTimeout(1500);
};

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  /* deviceScaleFactor 1 and a viewport shot rather than fullPage: the admin sets
     body{overflow:hidden} and scrolls inside #content, so a "full page" shot is
     the viewport anyway -- at scale 2 it was 7 MB per frame and 116 MB for the
     set, which is not a thing to put in a repository. */
  const ctx = await browser.newContext({ viewport: { width: W, height: 1180 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const out = { label: LABEL, width: W, steps: [] };

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@tm.test');
  await page.fill('input[name=password]', 'tm-preview-secret');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.waitForTimeout(1500);

  /* 0. The payments screen, to show the button this lane drops into the Tamara
        card -- the only change it makes to a screen it does not own. */
  await page.evaluate(() => window.go('payments'));
  await page.waitForTimeout(2200);
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('[data-paytab]')].find((x) => x.dataset.paytab === 'tamara');
    if (t) t.click();
  });
  await page.waitForTimeout(1200);
  await page.evaluate(() => {
    const el = document.querySelector('#tm-jump');
    if (el) el.scrollIntoView({ block: 'center' });
  });
  await page.waitForTimeout(400);
  out.steps.push({
    step: '0-payments-card',
    jumpButton: await page.evaluate(() => {
      const b = document.getElementById('tm-jump');
      if (!b) return null;
      const r = b.getBoundingClientRect();
      return { text: b.textContent, w: Math.round(r.width), h: Math.round(r.height) };
    }),
    footButtons: await page.evaluate(() =>
      [...document.querySelectorAll('[data-paycard="tamara"] .payfoot button')].map((b) => b.textContent.trim())),
  });
  await page.screenshot({ path: `${OUT}/0-payments-${LABEL}.png`, fullPage: false });

  /* 1. BEFORE: the shipped state. Keys in, no webhook, no limits. */
  await openScreen(page);
  out.steps.push({ step: '1-before', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/1-before-${LABEL}.png`, fullPage: false });

  /* 2. Register the webhook. */
  await press(page, 'tm-register');
  out.steps.push({ step: '2-registered', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/2-registered-${LABEL}.png`, fullPage: false });

  /* 3. Pull the basket limits. */
  await press(page, 'tm-limits');
  out.steps.push({ step: '3-limits', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/3-limits-${LABEL}.png`, fullPage: false });

  /* 4. Run the sweep. minutes -> 0 so the seeded three-hour-old order is in
        range whatever the default is. */
  await page.evaluate(() => { const el = document.getElementById('tm-minutes'); if (el) el.value = '0'; });
  await press(page, 'tm-sweep');
  out.steps.push({ step: '4-swept', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/4-swept-${LABEL}.png`, fullPage: false });

  /* 5. Tabby: register / re-sync. */
  await press(page, 'tb-sync');
  out.steps.push({ step: '5-tabby', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/5-tabby-${LABEL}.png`, fullPage: false });

  /* 6. The destructive one asks first. */
  await press(page, 'tm-unregister');
  out.steps.push({ step: '6-confirm', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/6-confirm-${LABEL}.png`, fullPage: false });

  /* 7. ...and answering no changes nothing. */
  await press(page, 'tm-unreg-no');
  out.steps.push({ step: '7-kept', ...(await page.evaluate(measure)) });

  /* 8. ...and answering yes removes it. */
  await press(page, 'tm-unregister');
  await press(page, 'tm-unreg-yes');
  out.steps.push({ step: '8-removed', ...(await page.evaluate(measure)) });
  await page.screenshot({ path: `${OUT}/8-removed-${LABEL}.png`, fullPage: false });

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
