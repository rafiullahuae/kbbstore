/*
 * Lane MK — the proof pictures for Marketing Emails (CLAUDE.md rule 2), into
 * docs/mk-shots/. Run against tools/mk-preview.sh:
 *
 *     sh tools/mk-preview.sh && node tools/mk-shots.cjs
 *
 * Every screen at 1280 and 390, side by side with its approved mock
 * (docs/rj-email-previews/admin/m0–m5); every ready template rendered at 600
 * and 390; the builder before and after a drag; a group's count changing as
 * rules are added; a campaign sent end to end through the LOG mailer with the
 * message that went out; the unsubscribe page. measurements.json carries the
 * numbers (document.documentElement.scrollWidth at each width, heights, the
 * order of blocks before and after the drag, the counts).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'docs/mk-shots');
const MOCKS = path.join(ROOT, 'docs/rj-email-previews/admin');
const PREVIEW = path.join(ROOT, 'storage/framework/testing/mk-preview');
const BASE = 'http://127.0.0.1:' + fs.readFileSync(path.join(PREVIEW, 'port'), 'utf8').trim();
const M = {};
fs.mkdirSync(path.join(OUT, 'templates'), { recursive: true });
fs.mkdirSync(path.join(OUT, 'raw'), { recursive: true });

const FULL = 'html,body{height:auto!important}.app{height:auto!important;min-height:100vh}.content{overflow:visible!important;height:auto!important}';

async function login(browser, width, email = 'owner@preview.test') {
  const ctx = await browser.newContext({ viewport: { width, height: width > 500 ? 1100 : 844 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('pageerror', width, String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  return { ctx, page };
}

async function open(page, fn) {
  await page.evaluate(() => window.go('mkt-email'));
  await page.waitForTimeout(900);
  if (fn) { await page.evaluate(fn); }
  await page.waitForTimeout(2600);
}

async function shoot(page, name, width, full = true) {
  await page.addStyleTag({ content: FULL });
  await page.waitForTimeout(300);
  const file = path.join(OUT, 'raw', `${name}-${width}.png`);
  const m = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight }));
  await page.screenshot({ path: file, fullPage: full });
  M[`${name}-${width}`] = m;
  return file;
}

/* mock | built, one picture. */
async function compare(browser, name, mockFile, builtFile, width) {
  const ctx = await browser.newContext({ viewport: { width: width * 2 + 60, height: 600 } });
  const page = await ctx.newPage();
  const img = (f) => 'data:image/' + (f.endsWith('.jpg') ? 'jpeg' : 'png') + ';base64,' + fs.readFileSync(f).toString('base64');
  await page.setContent(`<html><body style="margin:0;background:#e9ecf3;font:600 15px system-ui"><div style="display:flex;gap:20px;padding:20px;align-items:flex-start">
    <div><div style="margin:0 0 8px">Approved mock — ${path.basename(mockFile)}</div><img style="width:${width}px;display:block;border:1px solid #ccd" src="${img(mockFile)}"></div>
    <div><div style="margin:0 0 8px">Built — ${path.basename(builtFile)}</div><img style="width:${width}px;display:block;border:1px solid #ccd" src="${img(builtFile)}"></div></div></body></html>`);
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(OUT, `${name}-${width}-mock-vs-built.png`), fullPage: true });
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  for (const width of [1280, 390]) {
    let { ctx, page } = await login(browser, width);

    // m0 — the sidebar / the phone menu.
    await open(page);
    if (width === 1280) {
      await page.screenshot({ path: path.join(OUT, 'raw', 'm0-sidebar-1280.png'), clip: { x: 0, y: 0, width: 248, height: 1000 } });
    } else {
      await page.evaluate(() => { const s = document.querySelector('#side'); if (s) s.classList.add('open'); });
      await page.waitForTimeout(500);
      await page.screenshot({ path: path.join(OUT, 'raw', 'm0-phone-menu-390.png') });
      await page.evaluate(() => { const s = document.querySelector('#side'); if (s) s.classList.remove('open'); });
    }

    // m1 — Campaigns.
    await open(page);
    const m1 = await shoot(page, 'm1-campaigns', width);

    // m2 — the builder on "More Medicube for you".
    ({ ctx, page } = await (async () => { await ctx.close(); return login(browser, width); })());
    await open(page, () => window.kbbMarketingEmails.openBuilder('campaign', 5));
    const m2 = await shoot(page, 'm2-builder', width);

    // m3 — Customer groups with the mock's rules.
    ({ ctx, page } = await (async () => { await ctx.close(); return login(browser, width); })());
    await open(page, () => document.querySelector('[data-mke-tab="groups"]').click());
    await page.waitForTimeout(1500);
    await page.evaluate(() => window.kbbMarketingEmails.setGroup({ name: 'Mostly bought Medicube · Dubai · 2026', rules: [
      { field: 'brand', op: 'mostly', value: 'Medicube' }, { field: 'emirate', op: 'any_of', value: ['dubai', 'abu_dhabi', 'sharjah'] },
      { field: 'order_date', op: 'in_year', value: 2026 }, { field: 'spent', op: 'gte', value: 100 }, { field: 'orders', op: 'gte', value: 1 }] }));
    await page.waitForTimeout(1500);
    await page.evaluate(() => window.kbbMarketingEmails.people(1));
    await page.waitForTimeout(2000);
    const m3 = await shoot(page, 'm3-groups', width);

    // m4 — Review & send.
    ({ ctx, page } = await (async () => { await ctx.close(); return login(browser, width); })());
    await open(page, () => window.kbbMarketingEmails.openReview(5));
    await page.evaluate(() => { const t = document.querySelector('[data-mke-tab="schedule"]'); if (t) t.click(); });
    await page.waitForTimeout(1200);
    const m4 = await shoot(page, 'm4-review-send', width);

    // m5 — the report of the campaign that really went out.
    ({ ctx, page } = await (async () => { await ctx.close(); return login(browser, width); })());
    await open(page, () => window.kbbMarketingEmails.openReport(1));
    const m5 = await shoot(page, 'm5-report', width);

    // Templates library.
    ({ ctx, page } = await (async () => { await ctx.close(); return login(browser, width); })());
    await open(page, () => document.querySelector('[data-mke-tab="templates"]').click());
    await page.waitForTimeout(2500);
    await shoot(page, 'templates-library', width);

    await ctx.close();

    const mockW = width === 1280 ? 1280 : 390;
    await compare(browser, 'm1-campaigns', path.join(MOCKS, `m1-campaigns-${mockW}.jpg`), m1, width === 1280 ? 1280 : 390);
    await compare(browser, 'm2-builder', path.join(MOCKS, `m2-builder-${mockW}.jpg`), m2, width === 1280 ? 1280 : 390);
    await compare(browser, 'm3-groups', path.join(MOCKS, `m3-groups-${mockW}.jpg`), m3, width === 1280 ? 1280 : 390);
    await compare(browser, 'm4-review-send', path.join(MOCKS, `m4-review-send-${mockW}.jpg`), m4, width === 1280 ? 1280 : 390);
    await compare(browser, 'm5-report', path.join(MOCKS, `m5-report-${mockW}.jpg`), m5, width === 1280 ? 1280 : 390);
  }
  await compare(browser, 'm0-sidebar', path.join(MOCKS, 'm0-sidebar-proposed.jpg'), path.join(OUT, 'raw', 'm0-sidebar-1280.png'), 248);
  await compare(browser, 'm0-phone-menu', path.join(MOCKS, 'm0-phone-menu-390.jpg'), path.join(OUT, 'raw', 'm0-phone-menu-390.png'), 390);

  // Every ready template, at 600 and 390.
  {
    const { ctx, page } = await login(browser, 600);
    const list = await page.evaluate(async () => (await (await fetch('/admin-api/email-marketing/templates', { headers: { Accept: 'application/json' } })).json()).templates);
    for (const t of list.filter((x) => x.preset)) {
      for (const w of [600, 390]) {
        await page.setViewportSize({ width: w, height: 900 });
        await page.goto(`${BASE}/admin-api/email-marketing/templates/${t.id}/preview`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(300);
        const m = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight, bytes: document.documentElement.outerHTML.length }));
        M[`template-${t.key}-${w}`] = m;
        await page.screenshot({ path: path.join(OUT, 'templates', `${t.key}-${w}.png`), fullPage: true });
      }
    }
    await ctx.close();
  }

  // The builder before and after a drag (layers: the coupon dragged above the products).
  {
    const { ctx, page } = await login(browser, 1280);
    await open(page, () => window.kbbMarketingEmails.openBuilder('campaign', 5));
    await page.click('[data-mke-tab="order"]');
    await page.waitForTimeout(1500);
    const before = await page.evaluate(() => window.kbbMarketingEmails.state.b.blocks.map((b) => b.type));
    await page.addStyleTag({ content: FULL });
    await page.screenshot({ path: path.join(OUT, 'builder-drag-before-1280.png'), fullPage: true });
    // The HARNESS asks where things are (Playwright's boundingBox) to move a
    // real mouse; the screen itself never measures anything.
    const a = await page.locator('[data-mke-grip="3"]').boundingBox();
    const row = await page.locator('[data-mke-i="2"]').boundingBox();
    await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
    await page.mouse.down();
    await page.mouse.move(a.x + 10, a.y - 20, { steps: 4 });
    await page.mouse.move(row.x + 60, row.y + 6, { steps: 6 });
    await page.screenshot({ path: path.join(OUT, 'builder-drag-during-1280.png'), fullPage: true });
    await page.mouse.up();
    await page.waitForTimeout(2500);
    const after = await page.evaluate(() => window.kbbMarketingEmails.state.b.blocks.map((b) => b.type));
    await page.screenshot({ path: path.join(OUT, 'builder-drag-after-1280.png'), fullPage: true });
    M['builder-drag'] = { before, after };
    // …and back with the keyboard's ↓, so the shop's draft is as it was.
    await page.click('[data-mke-down="2"]');
    await page.waitForTimeout(1500);
    M['builder-drag'].restored_with_down_button = await page.evaluate(() => window.kbbMarketingEmails.state.b.blocks.map((b) => b.type));
    await ctx.close();
  }

  // A group's count as rules are added.
  {
    const { ctx, page } = await login(browser, 1280);
    await open(page, () => document.querySelector('[data-mke-tab="groups"]').click());
    const steps = [
      [],
      [{ field: 'orders', op: 'gte', value: 1 }],
      [{ field: 'orders', op: 'gte', value: 1 }, { field: 'emirate', op: 'any_of', value: ['dubai', 'abu_dhabi', 'sharjah'] }],
      [{ field: 'orders', op: 'gte', value: 1 }, { field: 'emirate', op: 'any_of', value: ['dubai', 'abu_dhabi', 'sharjah'] }, { field: 'brand', op: 'mostly', value: 'Medicube' }],
      [{ field: 'orders', op: 'gte', value: 1 }, { field: 'emirate', op: 'any_of', value: ['dubai', 'abu_dhabi', 'sharjah'] }, { field: 'brand', op: 'mostly', value: 'Medicube' }, { field: 'spent', op: 'gte', value: 300 }],
    ];
    M['group-count'] = [];
    await page.waitForTimeout(1500);
    for (let i = 0; i < steps.length; i++) {
      await page.evaluate((rules) => window.kbbMarketingEmails.setGroup({ name: 'Count demo', rules: rules }), steps[i]);
      await page.waitForTimeout(1800);
      const c = await page.evaluate(() => { const c = window.kbbMarketingEmails.state.g.count; return { matched: c.matched, emailable: c.emailable, reasons: c.reasons }; });
      M['group-count'].push({ rules: steps[i].map((r) => r.field + ' ' + r.op + ' ' + JSON.stringify(r.value)), ...c });
      const card = await page.$('.mke-split > div:first-child .mke-card');
      await card.screenshot({ path: path.join(OUT, `group-count-step${i}-1280.png`) });
    }
    await ctx.close();
  }

  // A campaign sent end to end from Review & send (Driver A), through the LOG mailer.
  {
    const { ctx, page } = await login(browser, 1280);
    await open(page, () => window.kbbMarketingEmails.openReview(4));
    await page.click('[data-mke="go"]');
    await page.waitForTimeout(400);
    const expected = await page.evaluate(() => window.kbbMarketingEmails.state.rv.d.count.emailable);
    await page.addStyleTag({ content: FULL });
    await page.screenshot({ path: path.join(OUT, 'send-typed-confirmation-1280.png'), fullPage: true });
    await page.fill('#mkeConfirm', String(expected));
    await page.click('[data-mke="confirm"]');
    for (let i = 0; i < 40; i++) {
      await page.waitForTimeout(1000);
      const st = await page.evaluate(() => (window.kbbMarketingEmails.state.rv.progress || {}).status);
      if (st === 'sent') break;
    }
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(OUT, 'send-done-1280.png'), fullPage: true });
    M['send'] = await page.evaluate(() => window.kbbMarketingEmails.state.rv.progress);
    await ctx.close();
  }

  // The unsubscribe page a recipient sees.
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 800 } });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/email/u/1-${'0'.repeat(32)}`, { waitUntil: 'networkidle' });
    M[`unsubscribe-page-${w}`] = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth }));
    await page.screenshot({ path: path.join(OUT, `unsubscribe-page-${w}.png`), fullPage: false });
    await ctx.close();
  }

  // The message that went out, from the LOG mailer, shown at 600 and 390.
  {
    const { execFileSync } = require('child_process');
    const sent = path.join(OUT, 'sent');
    M['sent-email'] = execFileSync('python3', [path.join(__dirname, 'mk-sent-email.py'), path.join(PREVIEW, 'app/storage/logs/laravel.log'), sent]).toString().trim();
    const ctx = await browser.newContext({ viewport: { width: 600, height: 900 } });
    const page = await ctx.newPage();
    for (const w of [600, 390]) {
      await page.setViewportSize({ width: w, height: 900 });
      await page.goto('file://' + path.join(sent, 'sent-email.html'));
      await page.waitForTimeout(800);
      M[`sent-email-${w}`] = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth }));
      await page.screenshot({ path: path.join(sent, `sent-email-${w}.png`), fullPage: true });
    }
    await ctx.close();
  }

  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(M, null, 2));
  await browser.close();
  console.log(JSON.stringify(M, null, 1).slice(0, 3000));
})();
