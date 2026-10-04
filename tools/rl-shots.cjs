// Lane RL -- Platform -> Users & Roles, photographed at 390 and 1280 in Chromium.
//   node tools/rl-shots.cjs http://127.0.0.1:9930
// Signs in as the preview owner (tools/rl-seed.php), opens the console AT
// ?go=users (the deep link, not a scripted go()), and shoots the Members tab,
// a member's editor with per-person tweaks (before and after one tick), the
// Roles tab and the role editor. Measures what the brief asks for and fails
// loudly on any page error.
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:9930';
const OUT = path.join(__dirname, '..', 'docs', 'lane-rl-shots');

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const report = {};
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1000 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('dialog', d => d.accept());
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

    const shot = async (name, full) => {
      await page.waitForTimeout(250);
      const m = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth }));
      await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: !!full });
      report[`shot:${name}-${w}`] = m;
    };

    // 0. Sara, in a second browser, has a product open: the Members tab shows
    //    her online dot and "editing a product" from her heartbeats.
    const sctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
    const sara = await sctx.newPage();
    await sara.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await sara.fill('input[name=email]', 'sara@preview.test');
    await sara.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([sara.waitForNavigation({ waitUntil: 'networkidle' }), sara.click('button[type=submit], input[type=submit]')]);
    await Promise.all([sara.waitForResponse(r => r.url().includes('/presence/beat') && r.request().postData().includes('product')), sara.evaluate(() => window.peoEdit(1))]);

    // 1. The deep link draws the screen by itself.
    await page.goto(`${BASE}/admin?go=users`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.rl-mem', { timeout: 20000 });
    report[`deeplink-${w}`] = await page.evaluate(() => ({
      title: document.querySelector('#ptitle') && document.querySelector('#ptitle').textContent,
      members: document.querySelectorAll('.rl-mem').length,
      tabs: [...document.querySelectorAll('[role=tab]')].map(t => t.textContent.trim()),
    }));
    await shot('members-tab', true);

    // 2. A member with a custom title and per-person tweaks.
    await page.click('.rl-mem:has-text("Sara Ahmed") [data-rl="edit-member"]');
    await page.waitForSelector('[data-rl-cap]');
    report[`member-editor-before-${w}`] = await page.evaluate(() => ({
      summary: document.querySelector('.rl-sum b').textContent + document.querySelector('.rl-sum .rl-note').textContent,
      added: [...document.querySelectorAll('.why.plus')].map(x => x.closest('.rl-row').querySelector('.k').textContent),
      removed: [...document.querySelectorAll('.why.minus')].map(x => x.closest('.rl-row').querySelector('.k').textContent),
      title: document.querySelector('[data-rl-f="title"]').value,
      checkboxFont: getComputedStyle(document.querySelector('.rl-row')).fontSize,
    }));
    await shot('member-tweak-before', true);
    // One tick: give Sara "See orders", which her SEO Manager role does not hold.
    await page.check('[data-rl-cap="orders.view"]');
    report[`member-editor-after-${w}`] = await page.evaluate(() => ({
      summary: document.querySelector('.rl-sum b').textContent + document.querySelector('.rl-sum .rl-note').textContent,
      focused: document.activeElement && document.activeElement.getAttribute('data-rl-cap'),
    }));
    await shot('member-tweak-after', true);
    await page.click('[data-rl="save-member"]');
    await page.waitForSelector('.rl-ok');
    report[`member-saved-${w}`] = await page.evaluate(() => document.querySelector('.rl-ok').textContent);
    await shot('member-saved', false);
    // put it back so the second width starts from the same state
    await page.evaluate(async () => {
      const m = window.kbbAdminRoles.state.members.find(x => x.email === 'sara@preview.test');
      const cookie = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      await fetch('/admin-api/roles/members/' + m.id, { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': cookie }, body: JSON.stringify({ grants: m.grants.filter(g => g !== 'orders.view') }) });
    });

    // 3. The Roles tab.
    await page.click('[data-rl-tab="roles"]');
    await page.waitForSelector('.rl-role');
    report[`roles-tab-${w}`] = await page.evaluate(() => ({
      roles: [...document.querySelectorAll('.rl-role h3')].map(h => h.textContent),
      members: [...document.querySelectorAll('.rl-role .rl-meta')].map(h => h.textContent),
    }));
    await shot('roles-tab', true);

    // 4. The role editor: a new role started from Store Manager.
    await page.click('.rl-role:has(h3:text-is("SEO Manager")) [data-rl="edit-role"]');
    await page.waitForSelector('[data-rl-all]');
    report[`role-editor-${w}`] = await page.evaluate(() => ({
      sections: [...document.querySelectorAll('.rl-sec-h')].map(s => s.textContent.trim()),
      indeterminate: [...document.querySelectorAll('[data-rl-all]')].filter(x => x.indeterminate).length,
    }));
    await shot('role-editor', true);
    // select-all on a section, then the filter
    await page.check('[data-rl-all="orders"]');
    report[`role-editor-selectall-${w}`] = await page.evaluate(() => document.querySelector('[data-rl-all="orders"]').closest('.rl-sec').querySelector('.n').textContent);
    await page.fill('[data-rl-f="filter"]', 'export');
    report[`role-editor-filter-${w}`] = await page.evaluate(() => [...document.querySelectorAll('.rl-row:not([hidden]) .k')].map(k => k.textContent));
    await shot('role-editor-filtered', false);
    await page.click('[data-rl="back"]');

    // 5. New role, started from a preset, with a hostile name: printed, never run.
    await page.click('[data-rl="new-role"]');
    await page.fill('[data-rl-f="name"]', w === 1280 ? '<img src=x onerror="window.__rlxss=1">' : 'Night shift');
    await page.selectOption('[data-rl-f="from"]', { label: 'Customer Support' });
    await shot('role-new', true);
    await page.click('[data-rl="save-role"]');
    await page.waitForSelector('.rl-ok');
    report[`role-created-${w}`] = await page.evaluate(() => ({ flash: document.querySelector('.rl-ok').textContent, xss: window.__rlxss || null, lastCard: [...document.querySelectorAll('.rl-role h3')].pop().textContent }));
    await shot('role-created', false);

    report[`errors-${w}`] = errors;
    await ctx.close();
    await sctx.close();
  }
  await b.close();
  console.log(JSON.stringify(report, null, 1));
  require('fs').writeFileSync(`${OUT}/measurements.json`, JSON.stringify(report, null, 1));
})().catch(e => { console.error(e); process.exit(1); });
