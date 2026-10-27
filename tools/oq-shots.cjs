/*
 * Lane OQ -- product search on Store -> Orders and the red quantity circle on
 * the admin order screen and the owner app, with the numbers behind them.
 *
 *   sh tools/oq-preview.sh            # prints the port and the owner-app path
 *   BASE=http://127.0.0.1:<port> APP=/<owner-app-path> OUT=docs/oq-shots node tools/oq-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE;
const APP = process.env.APP;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/oq-shots';
const TERM = process.env.TERM_Q || 'medicube pad';
const NEWEST = process.env.NEWEST || '';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };

function watch(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console ' + m.text()); });
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
  return errors;
}

async function tallShot(page, width, path) {
  const h = await page.evaluate(() => Math.ceil(document.querySelector('#content .wrap').getBoundingClientRect().bottom + 40));
  await page.setViewportSize({ width, height: Math.max(900, Math.min(h, 3200)) });
  await page.waitForTimeout(300);
  await page.screenshot({ path });
  await page.setViewportSize({ width, height: 900 });
}

async function login(browser, width) {
  const mobile = width < 700;
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: mobile ? IPHONE : undefined, deviceScaleFactor: mobile ? 2 : 1 });
  const page = await ctx.newPage();
  const errors = watch(page);
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin#orders', { waitUntil: 'networkidle' });
  await page.evaluate(() => window.go('orders'));
  await page.waitForSelector('#content .odlscroll table', { timeout: 20000 });
  await page.waitForTimeout(400);
  return { ctx, page, errors };
}

async function search(browser, width) {
  const { ctx, page, errors } = await login(browser, width);

  // Typed letter by letter, the way a person types: count the requests.
  const reqs = [];
  page.on('request', (r) => { if (r.url().includes('/admin-api/orders-list')) reqs.push(r.url()); });
  await page.click('#olSearch');
  await page.keyboard.type(TERM, { delay: 70 });
  await page.waitForSelector('#content .olpm', { timeout: 20000 });
  await page.waitForTimeout(900);

  const m = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('#content tr[data-olrow]')];
    return {
      sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
      focus: document.activeElement && document.activeElement.id,
      value: document.getElementById('olSearch').value,
      rows: rows.length,
      withChip: rows.filter((r) => r.querySelector('.olmc')).length,
      rowH: rows.slice(0, 6).map((r) => Math.round(r.getBoundingClientRect().height)),
      summary: (document.querySelector('#content .olpm') || {}).innerText,
      chip: (document.querySelector('#content .olmc') || {}).innerText,
      marks: document.querySelectorAll('#content mark.olhi').length,
      badge: (() => { const b = document.querySelector('#content .olmc .kqb'); if (!b) return null; const s = getComputedStyle(b); const r = b.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), bg: s.backgroundColor, fg: s.color, label: b.getAttribute('aria-label') }; })(),
      placeholder: document.getElementById('olSearch').placeholder,
    };
  });
  say(`search ${width}: typed "${m.value}" (${TERM.length} keys) -> ${reqs.length} list request(s); focus stays on ${m.focus}`);
  say(`search ${width}: ${m.rows} rows, ${m.withChip} with a matched-line chip; first row heights ${JSON.stringify(m.rowH)}; ${m.marks} highlights`);
  say(`search ${width}: summary ${JSON.stringify(m.summary)}; first chip ${JSON.stringify(m.chip)}; badge ${JSON.stringify(m.badge)}`);
  say(`search ${width}: scrollWidth ${m.sw} / clientWidth ${m.cw}; placeholder ${JSON.stringify(m.placeholder)}`);
  await tallShot(page, width, `${OUT}/orders-search-${width}.png`);

  if (width >= 1000) {
    // Server time for the list, with and without the product search, as the
    // browser sees it (median of 7, same session).
    const listUrl = reqs[reqs.length - 1].split('?')[0];
    const t = await page.evaluate(async ([term, listUrl]) => {
      const time = async (qs) => {
        const out = [];
        for (let i = 0; i < 7; i++) {
          const a = performance.now();
          const r = await fetch(listUrl + '?per_page=50' + qs, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
          await r.json();
          out.push(performance.now() - a);
        }
        out.sort((x, y) => x - y);
        return Math.round(out[3]);
      };
      return { plain: await time(''), product: await time('&search=' + encodeURIComponent(term)), name: await time('&search=Sabina') };
    }, [TERM, listUrl]);
    say(`timing (median of 7, preview server, SQLite, 133 orders): list ${t.plain} ms; product search ${t.product} ms; name search ${t.name} ms`);

    // With the status chip on top of the search.
    await page.click('#content .chip[data-olf="completed"]').catch(() => {});
    await page.waitForTimeout(900);
    const c = await page.evaluate(() => ({ rows: document.querySelectorAll('#content tr[data-olrow]').length, summary: (document.querySelector('#content .olpm') || {}).innerText }));
    say(`search + Completed chip: ${c.rows} rows; summary ${JSON.stringify(c.summary)}`);
    await tallShot(page, width, `${OUT}/orders-search-completed-${width}.png`);
  }
  say(`search ${width}: errors ${JSON.stringify(errors)}`);
  await ctx.close();
}

async function detail(browser, width, nth, tag) {
  const { ctx, page, errors } = await login(browser, width);
  await page.locator('#content tr[data-olrow] [data-olview]').nth(nth).click();
  await page.waitForSelector('#content .odgrid', { timeout: 20000 });
  await page.waitForTimeout(500);
  const d = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('#content .odgrid table tbody tr')];
    return {
      sw: document.documentElement.scrollWidth,
      lines: rows.map((r) => {
        const b = r.querySelector('.kqb');
        const inp = r.querySelector('.odqty');
        const qtyCell = r.children[3];
        const s = b ? getComputedStyle(b) : (inp ? getComputedStyle(inp) : null);
        const box = (b || inp) ? (b || inp).getBoundingClientRect() : null;
        return {
          name: (r.querySelector('b') || {}).textContent,
          qty: b ? b.textContent : (inp ? 'input ' + inp.value + (inp.classList.contains('kqhot') ? ' (red)' : ' (plain)') : (qtyCell || {}).textContent),
          html: qtyCell ? qtyCell.innerHTML.slice(0, 160) : '',
          size: box ? Math.round(box.width) + 'x' + Math.round(box.height) : null,
          bg: s ? s.backgroundColor : null,
        };
      }),
    };
  });
  say(`admin order ${tag} ${width}: scrollWidth ${d.sw}`);
  d.lines.forEach((l) => say(`  ${JSON.stringify(l)}`));
  // The items card into view.
  await page.evaluate(() => { const t = document.querySelector('#content .odgrid table'); if (t) t.scrollIntoView({ block: 'center' }); });
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/admin-order-${tag}-${width}.png` });
  say(`admin order ${width}: errors ${JSON.stringify(errors)}`);
  await ctx.close();
}

async function ownerApp(browser, width, rtl) {
  const mobile = width < 700;
  const ctx = await browser.newContext({ viewport: { width, height: 844 }, deviceScaleFactor: 2, isMobile: mobile, hasTouch: mobile, userAgent: mobile ? IPHONE : undefined });
  await ctx.addInitScript(() => { try { localStorage.setItem('oa.a2', '1'); } catch (e) {} });
  await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
  if (rtl) await ctx.addInitScript(() => { document.addEventListener('DOMContentLoaded', () => { document.documentElement.setAttribute('dir', 'rtl'); }); });
  const page = await ctx.newPage();
  const errors = watch(page);
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'oq-' + width + (rtl ? '-rtl' : ''));
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/api/enrol')), page.click('[data-enrol] button[type=submit]')]);
  await page.waitForTimeout(1500);
  await page.evaluate(() => { location.hash = '#/orders'; });
  await page.waitForSelector('.row.ord a.rm', { timeout: 20000 });
  await page.waitForTimeout(600);
  // The owner app's own search box, same backend rule.
  await page.fill('[data-search]', 'zero pore');
  await page.waitForTimeout(1500);
  const found = await page.evaluate(() => document.querySelectorAll('.row.ord').length);
  say(`owner app ${width}: search "zero pore" -> ${found} order rows`);
  await page.screenshot({ path: `${OUT}/owner-app-search-${width}${rtl ? '-rtl' : ''}.png` });
  await page.evaluate((id) => { location.hash = '#/orders/' + id; }, NEWEST);
  await page.waitForSelector('.od-sum', { timeout: 15000 });
  await page.waitForTimeout(900);
  const d = await page.evaluate(() => ({
    sw: document.documentElement.scrollWidth, dir: document.documentElement.dir,
    lines: [...document.querySelectorAll('section.card .row')].filter((r) => r.querySelector('.th')).slice(0, 5).map((r) => {
      const b = r.querySelector('.qb');
      const th = r.querySelector('.th').getBoundingClientRect();
      const bb = b ? b.getBoundingClientRect() : null;
      return { name: r.querySelector('b').textContent, badge: b ? b.textContent : null, label: b ? b.getAttribute('aria-label') : null,
        badgeBox: bb ? `${Math.round(bb.width)}x${Math.round(bb.height)} at ${Math.round(bb.left - th.left)},${Math.round(bb.top - th.top)} from photo` : null,
        rowH: Math.round(r.getBoundingClientRect().height), wrapped: !!r.querySelector('.thq') };
    }),
  }));
  say(`owner app order ${width}${rtl ? ' rtl' : ''}: scrollWidth ${d.sw}; dir ${d.dir}`);
  d.lines.forEach((l) => say(`  ${JSON.stringify(l)}`));
  const card = page.locator('section.card').filter({ has: page.locator('.th') }).first();
  await card.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/owner-app-order-${width}${rtl ? '-rtl' : ''}.png` });
  say(`owner app ${width}: errors ${JSON.stringify(errors)}`);
  await ctx.close();
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const w of [1280, 390]) await search(browser, w);
  for (const w of [1280, 390]) { await detail(browser, w, 0, 'editable'); await detail(browser, w, 1, 'completed'); }
  if (APP) { await ownerApp(browser, 390, false); await ownerApp(browser, 390, true); }
  await browser.close();
  fs.writeFileSync(`${OUT}/numbers.txt`, lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
