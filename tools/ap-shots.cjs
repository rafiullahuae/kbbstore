/*
 * Lane AP -- the sidebar's hide / show button, photographed and measured.
 *
 *   sh tools/ap-preview.sh          (prints the port)
 *   node tools/ap-shots.cjs <port>
 *
 * Writes docs/ap-shots/*.png and storage/ap-logs/shots.json. At 1280 and 390:
 * the console with the sidebar shown, then hidden with the button, then
 * reloaded (the remembered state must be on <html> before the first paint),
 * a frame from the middle of the slide, and the phone's existing drawer.
 * Measured: the sidebar's and the content's boxes, scrollWidth, the button's
 * aria state, and the class on <html> as the first script after it sees it.
 * The measuring is the harness's; the console measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '10470';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'ap-shots');
const LOGS = path.join(__dirname, '..', 'storage', 'ap-logs');
const UA_DESK = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
fs.mkdirSync(OUT, { recursive: true });
fs.mkdirSync(LOGS, { recursive: true });

const measure = (page) => page.evaluate(() => {
  const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { x: Math.round(r.left), w: Math.round(r.width), h: Math.round(r.height) }; };
  const b = document.getElementById('sideTog');
  return {
    htmlClass: document.documentElement.className,
    side: box(document.getElementById('side')),
    sideVisibility: getComputedStyle(document.getElementById('side')).visibility,
    main: box(document.querySelector('.main')),
    content: box(document.getElementById('content')),
    toggle: b ? { shown: getComputedStyle(b).display !== 'none', expanded: b.getAttribute('aria-expanded'), label: b.getAttribute('aria-label'), box: box(b) } : null,
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    navRows: document.querySelectorAll('#nav .nav-item').length,
  };
});

async function run(browser, w, h, role = 'owner') {
  const phone = w < 600;
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, userAgent: phone ? UA_PHONE : UA_DESK, deviceScaleFactor: 1 });
  // The class on <html> as the FIRST body script sees it: proof the head
  // script ran before anything was painted.
  await ctx.addInitScript(() => {
    document.addEventListener('readystatechange', () => {}, { once: true });
    const mo = new MutationObserver(() => {
      if (document.body && window.__apFirstBody === undefined) { window.__apFirstBody = document.documentElement.className; mo.disconnect(); }
    });
    mo.observe(document, { childList: true, subtree: true });
  });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=email]', role + '@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit]')]);
  await page.evaluate(() => { try { localStorage.removeItem('kbb.admin.side'); } catch (e) {} });
  await page.reload({ waitUntil: 'load' });
  await page.waitForTimeout(700);
  const tag = `${w}`;
  const out = { width: w };

  out.open = await measure(page);
  await page.screenshot({ path: path.join(OUT, `${tag}-sidebar-open.png`) });

  if (!phone) {
    await page.click('#sideTog');
    await page.waitForTimeout(110);
    out.midSlide = await measure(page);
    await page.screenshot({ path: path.join(OUT, `${tag}-sidebar-mid-slide.png`) });
    await page.waitForTimeout(500);
    out.closed = await measure(page);
    await page.screenshot({ path: path.join(OUT, `${tag}-sidebar-closed.png`) });

    await page.reload({ waitUntil: 'load' });
    await page.waitForTimeout(500);
    out.closedAfterReload = await measure(page);
    out.closedAfterReload.htmlClassAtFirstBodyNode = await page.evaluate(() => window.__apFirstBody);

    await page.focus('#sideTog');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(500);
    out.reopenedByKeyboard = await measure(page);
    await page.screenshot({ path: path.join(OUT, `${tag}-sidebar-reopened.png`) });
  } else {
    await page.click('.menubtn');
    await page.waitForTimeout(450);
    out.drawerOpen = await measure(page);
    await page.screenshot({ path: path.join(OUT, `${tag}-phone-drawer-open.png`) });
    await page.click('#nav .nav-group[data-sec="Appearance"] .nav-gh');
    await page.click('#nav [data-go="spotted"]');
    await page.waitForTimeout(700);
    out.drawerAfterRow = await measure(page);
    out.drawerAfterRow.title = await page.textContent('#ptitle');
    await page.screenshot({ path: path.join(OUT, `${tag}-phone-after-row.png`) });
  }
  out.errors = errors;
  await ctx.close();
  return out;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const res = { desktop: await run(browser, 1280, 860), phone: await run(browser, 390, 844) };
  fs.writeFileSync(path.join(LOGS, 'shots.json'), JSON.stringify(res, null, 2));
  console.log(JSON.stringify(res, null, 1).slice(0, 4000));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
