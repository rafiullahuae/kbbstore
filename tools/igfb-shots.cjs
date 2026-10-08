/*
 * Content -> Instagram -> "Connect with Facebook" (Lane IG2): the pictures.
 *
 *   node tools/igfb-shots.cjs <base> <outDir> <state>
 *
 *   state  setup      the Facebook route: steps, fields, both redirect URIs
 *          picker     after a login with several linked Pages
 *          unlinked   after a login with no Page linked
 *          connected  a stored Facebook connection
 *          invalid    a connection Facebook stopped accepting (error 190)
 *
 * The picker, unlinked and invalid states need a real Facebook login (or a
 * real 190) to reach, which this container cannot make -- egress to Meta is
 * blocked. So for those three the screen's own GET /admin-api/instagram answer
 * is taken from the server and ONLY the `connection` fields the server would
 * have set are filled in, in exactly the shape InstagramFacebookLoginTest
 * proves the server sends. The drawing is the shipped JavaScript either way.
 *
 * Chromium at deviceScaleFactor 2. Numbers are written beside the pictures.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const [BASE, OUT, STATE] = process.argv.slice(2);
const EXEC = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const WIDTHS = [[390, 2400], [1280, 1900]];

const PATCH = {
  picker: (c) => { c.facebook.pending = { state: 'pick', pages: ['KBB Shop', 'KBB Outlet'], candidates: [
    { page_id: '5550001', page_name: 'KBB Shop', ig_username: 'kbeauty.bliss' },
    { page_id: '5550002', page_name: 'KBB Outlet', ig_username: 'kbb.outlet' } ] }; },
  unlinked: (c) => { c.facebook.pending = { state: 'unlinked', pages: ['KBB Shop'], candidates: [] }; },
  invalid: (c) => { c.invalid = true; c.invalid_since = new Date().toISOString(); },
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXEC });
  const report = {};

  for (const [w, h] of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + (m.location().url || '')); });
    page.on('pageerror', (e) => errors.push(String(e)));

    if (PATCH[STATE]) {
      await page.route('**/admin-api/instagram', async (route) => {
        if (route.request().method() !== 'GET') return route.continue();
        const res = await route.fetch();
        const body = await res.json();
        PATCH[STATE](body.connection);
        await route.fulfill({ response: res, json: body });
      });
    }

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type=submit], input[type=submit]'),
    ]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('instagram'));
    await page.waitForSelector('[data-igs-route]');
    await page.waitForTimeout(600);

    const numbers = await page.evaluate(() => {
      const hit = (el) => {
        if (!el) return null;
        const r = el.getBoundingClientRect();
        el.scrollIntoView({ block: 'center' });
        const r2 = el.getBoundingClientRect();
        const top = document.elementFromPoint(r2.left + r2.width / 2, r2.top + r2.height / 2);
        return { w: Math.round(r.width), h: Math.round(r.height), clickable: !!top && (top === el || el.contains(top)) };
      };
      const fs = (el) => el ? parseFloat(getComputedStyle(el).fontSize) : null;
      return {
        viewport: document.documentElement.clientWidth,
        pageScrollWidth: document.documentElement.scrollWidth,
        horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        routeTabs: [...document.querySelectorAll('[data-igs-route]')].map((b) => b.textContent.trim() + (b.getAttribute('aria-selected') === 'true' ? ' [selected]' : '')),
        steps: document.querySelectorAll('.igs-step').length,
        stepStates: [...document.querySelectorAll('.igs-step')].map((s) => s.className.replace('igs-step', '').trim() || 'todo'),
        redirectUris: [...document.querySelectorAll('.igs-uri code')].map((c) => c.textContent),
        copyButtons: [...document.querySelectorAll('[data-igs-copy]')].map(hit),
        fields: [...document.querySelectorAll('.igs-f label')].map((l) => l.textContent.trim()),
        inputFontSize: fs(document.querySelector('[data-igs-fbappid]')),
        connectButton: hit(document.querySelector('a[href*="via=facebook"]')),
        buttons: [...document.querySelectorAll('.igs-btn')].map((b) => b.textContent.trim()),
        picks: [...document.querySelectorAll('[data-igs-pick]')].map((b) => ({ text: b.innerText.replace(/\n/g, ' | '), ...hit(b) })),
        unlinked: document.querySelector('[data-igs-unlinked]')?.innerText ?? null,
        checkAgain: hit(document.querySelector('[data-igs-fbcheck]')),
        connectedNote: document.querySelector('[data-igs-fbconnected]')?.innerText ?? null,
        invalidNote: document.querySelector('[data-igs-invalid]')?.innerText ?? null,
        secretInputValue: document.querySelector('[data-igs-fbsecret]')?.value ?? null,
        htmlHasSecret: document.documentElement.outerHTML.includes('notarealsecret'),
      };
    });
    numbers.consoleErrors = errors;
    report[`${STATE}-${w}`] = numbers;

    // The connection card, top to bottom.
    await page.evaluate(() => window.scrollTo(0, 0));
    const card = await page.$('.igs-wrap .igs-card');
    await card.screenshot({ path: `${OUT}/${STATE}-${w}.png` });
    await ctx.close();
  }

  fs.writeFileSync(`${OUT}/${STATE}.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
