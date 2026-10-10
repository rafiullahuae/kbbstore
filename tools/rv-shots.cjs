/*
 * Lane RV — the evidence for the review request email (CLAUDE.md rule 2).
 *
 *   php tools/rv-wire.php            (an unwired worktree only; revert after)
 *   sh tools/rv-preview.sh 10460
 *   BASE=http://127.0.0.1:<port> TOKEN=$(cat storage/framework/testing/rv-preview/token.txt) node tools/rv-shots.cjs
 *
 * The email at 375 and 600 in English and Arabic; the review page at 390 and
 * 1280 before and after a real submit; Emails → Customer emails → Review
 * request → Schedule & rules; the pending review in Reviews. And the numbers:
 * scrollWidth vs clientWidth, star target sizes, a real click on a star that
 * lands, console errors, every request the page made.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10460';
const TOKEN = process.env.TOKEN;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-rv-shots';
// BlockGate refuses HeadlessChrome: a real browser's user agent.
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

(async () => {
  if (!TOKEN) throw new Error('TOKEN is required');
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const numbers = { email: {}, page: {}, admin: {} };

  // ── the email ──────────────────────────────────────────────────────────
  for (const lang of ['en', 'ar']) {
    for (const width of [375, 600]) {
      const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: UA });
      const page = await ctx.newPage();
      // The email's pictures are on the live host (MailImage); serve them from this preview.
      await page.route('https://extrabeauty.ae/**', async (route) => {
        const r = await fetch(BASE + new URL(route.request().url()).pathname);
        await route.fulfill({ status: r.status, headers: { 'content-type': r.headers.get('content-type') || 'image/jpeg' }, body: Buffer.from(await r.arrayBuffer()) });
      });
      await page.goto(`${BASE}/rv-email-${lang}.html`, { waitUntil: 'networkidle' });
      await page.screenshot({ path: `${OUT}/email-${lang}-${width}.png`, fullPage: true });
      numbers.email[`${lang}-${width}`] = await page.evaluate(() => {
        const stars = [...document.querySelectorAll('a[href*="/review-order/"][href*="r="]')];
        const r = stars[0] ? stars[0].getBoundingClientRect() : null;
        return {
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
          height: document.documentElement.scrollHeight,
          starLinks: stars.length,
          starBox: r ? [Math.round(r.width), Math.round(r.height)] : null,
          starFont: stars[0] ? getComputedStyle(stars[0]).fontSize : null,
          dir: document.documentElement.getAttribute('dir'),
          headline: (document.querySelector('.h1') || {}).textContent || null,
        };
      });
      numbers.email[`${lang}-${width}`].subject = fs.existsSync(`storage/framework/testing/rv-preview/webroot/rv-email-${lang}-subject.txt`)
        ? fs.readFileSync(`storage/framework/testing/rv-preview/webroot/rv-email-${lang}-subject.txt`, 'utf8') : null;
      await ctx.close();
    }
  }

  // ── the review page ───────────────────────────────────────────────────
  const firstId = async (page) => page.evaluate(() => (document.querySelector('.rr-card[id^="rr-"]') || {}).id);
  const measure = async (page) => page.evaluate(() => {
    const label = document.querySelector('.rr-row label');
    const r = label ? label.getBoundingClientRect() : null;
    return {
      url: location.pathname + location.search,
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      height: document.documentElement.scrollHeight,
      starTarget: r ? [Math.round(r.width), Math.round(r.height)] : null,
      h1Size: getComputedStyle(document.querySelector('.rr-head h1')).fontSize,
      inputFont: (document.querySelector('.rr-f textarea') && getComputedStyle(document.querySelector('.rr-f textarea')).fontSize) || null,
      checked: [...document.querySelectorAll('.rr-row input:checked')].map((i) => i.name + '=' + i.value),
      robots: (document.querySelector('meta[name=robots]') || {}).content || null,
      canonical: (document.querySelector('link[rel=canonical]') || {}).href || null,
      scripts: document.querySelectorAll('main script, .rr script').length,
    };
  });

  const openPage = async (width, query) => {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    const requests = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('request', (r) => requests.push(new URL(r.url()).host === new URL(BASE).host ? 'own' : r.url()));
    await page.goto(`${BASE}/review-order/${TOKEN}/${query || ''}`, { waitUntil: 'networkidle' });
    return { ctx, page, errors, requests };
  };

  // 1280 before, from the email's 5th star of the first product.
  {
    const probe = await openPage(1280, '');
    const id = (await firstId(probe.page)).replace('rr-', '');
    await probe.ctx.close();
    numbers.page.firstProduct = id;

    const s = await openPage(1280, `?p=${id}&r=5`);
    await s.page.screenshot({ path: `${OUT}/page-1280-before.png`, fullPage: true });
    numbers.page['1280-before'] = { ...(await measure(s.page)), errors: s.errors, thirdParty: s.requests.filter((r) => r !== 'own') };
    await s.ctx.close();

    const m = await openPage(390, `?p=${id}&r=5`);
    await m.page.screenshot({ path: `${OUT}/page-390-before.png`, fullPage: true });
    numbers.page['390-before'] = { ...(await measure(m.page)), errors: m.errors, thirdParty: m.requests.filter((r) => r !== 'own') };

    // A real tap on the second product's fourth star: is the star what is under the finger?
    const cards = await m.page.$$('.rr-card[id^="rr-"]');
    const second = await cards[1].getAttribute('id');
    const lab = await m.page.$(`#${second} label[for="${second}-4"]`);
    await lab.scrollIntoViewIfNeeded();
    const hit = await m.page.evaluate((sel) => {
      const el = document.querySelector(sel);
      const r = el.getBoundingClientRect();
      const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return !!(top && el.contains(top));
    }, `#${second} label[for="${second}-4"]`);
    await lab.click();
    numbers.page.secondStarTap = { hitIsStar: hit, checked: await m.page.evaluate((n) => document.querySelector(`#${n}-4`).checked, second) };
    await m.page.screenshot({ path: `${OUT}/page-390-rating-chosen.png`, clip: await m.page.evaluate((sel) => { const r = document.querySelector(sel).getBoundingClientRect(); return { x: 0, y: r.top + scrollY - 8, width: 390, height: r.height + 16 }; }, `#${second}`) });

    await m.page.fill(`textarea[name="reviews[${id}][content]"]`, 'Depuffed my eyes in ten minutes — the jelly stays put and feels so cooling.');
    await m.page.fill(`input[name="reviews[${id}][title]"]`, 'My morning rescue');
    await m.page.fill(`textarea[name="reviews[${second.replace('rr-', '')}][content]"]`, 'Light, no white cast, sits well under make-up.');
    await Promise.all([m.page.waitForNavigation({ waitUntil: 'networkidle' }), m.page.click('.rr-submit')]);
    await m.page.screenshot({ path: `${OUT}/page-390-after.png`, fullPage: true });
    numbers.page['390-after'] = { ...(await measure(m.page)), errors: m.errors };
    await m.ctx.close();

    const a = await openPage(1280, '');
    await a.page.goto(`${BASE}/review-order/?thanks=1`, { waitUntil: 'networkidle' });
    await a.page.screenshot({ path: `${OUT}/page-1280-after.png`, fullPage: true });
    numbers.page['1280-after'] = { ...(await measure(a.page)), errors: a.errors };
    await a.ctx.close();

    const bad = await openPage(390, '').then(async (x) => { await x.page.goto(`${BASE}/review-order/not-a-real-token-at-all/`, { waitUntil: 'networkidle' }); return x; });
    await bad.page.screenshot({ path: `${OUT}/page-390-invalid-link.png`, fullPage: false });
    await bad.ctx.close();
  }

  // ── the admin ─────────────────────────────────────────────────────────
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

    await page.goto(`${BASE}/admin?go=emails-customer`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${OUT}/admin-customer-emails-list.png`, fullPage: true });

    await page.goto(`${BASE}/admin?go=emails-edit&email=review_request`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    await page.screenshot({ path: `${OUT}/admin-review-request-sections.png`, fullPage: true });
    await page.getByRole('tab', { name: 'Schedule & rules' }).click();
    await page.waitForTimeout(900);
    await page.screenshot({ path: `${OUT}/admin-review-request-rules.png`, fullPage: true });
    numbers.admin.rulesTabFields = await page.evaluate(() => document.querySelectorAll('[data-rv]').length);

    // Before → after of one control: Days after completion 2 → 3, saved.
    await page.fill('#rv-review_request_days', '3');
    await page.click('[data-rv-save]');
    await page.waitForTimeout(700);
    numbers.admin.daysAfterSave = await page.inputValue('#rv-review_request_days');
    await page.screenshot({ path: `${OUT}/admin-review-request-rules-after-save.png`, fullPage: true });
    await page.fill('#rv-review_request_days', '2');
    await page.click('[data-rv-save]');
    await page.waitForTimeout(500);

    await page.getByRole('tab', { name: 'Wording · العربية' }).click();
    await page.waitForTimeout(600);
    await page.screenshot({ path: `${OUT}/admin-review-request-wording-ar.png`, fullPage: true });

    await page.goto(`${BASE}/admin?go=rev-all`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    await page.screenshot({ path: `${OUT}/admin-reviews-pending-from-email.png`, fullPage: false });
    numbers.admin.reviewsListFromEmail = await page.evaluate(() => [...document.querySelectorAll('.rv-card')].filter((c) => c.textContent.includes('From review email')).length);
    numbers.admin.errors = errors;
    await ctx.close();
  }

  fs.writeFileSync(`${OUT}/numbers.json`, JSON.stringify(numbers, null, 2));
  console.log(JSON.stringify(numbers, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
