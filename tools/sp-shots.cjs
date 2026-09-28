/*
 * Lane SP evidence. Chromium at 390 and 1280, the shots the owner and the
 * coordinator asked for, and the measured numbers under each of them.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below — `npx playwright install` is forbidden
 * here.
 *
 * ── WHAT IS PHOTOGRAPHED ───────────────────────────────────────────────────
 *
 *   storefront   a set's own product page, which now names what is in the box
 *   editor       the product editor with type Simple  (no set panel)
 *                the product editor with type Set     (the panel)
 *                the pricing controls with a discount applied
 *                the member list mid-drag
 *   catalog      Catalog → Products, with the Sets chip and the Set pill
 *   sets         Catalog → Sets, which is now a list
 *
 * ── WHY TWO SCREENS ARE INJECTED AND ONE IS NOT ────────────────────────────
 *
 * resources/views/admin/app.blade.php is the INTEGRATOR's file. The product
 * editor is ALREADY included by it, so it is reached with a plain
 * window.go('product-editor') and what is photographed is the real screen.
 * Catalog → Sets is included too. What is NOT there yet is the integrator's
 * two-line change for the Sets chip and the Set pill, so tools/sp-shoot.sh
 * applies exactly those two lines to a working copy, runs this, and puts the
 * file back — see that script's header for why that is safe and how it is
 * checked.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SP_BASE || 'http://127.0.0.1:8991';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SP_OUT || `${APP}/docs/lane-sp-shots`;

const SET_SLUG = 'lanesp-glow-starter-set';
const PLAIN_SLUG = 'lanesp-ceramide-moisturiser';

async function shoot(page, name, w, h, extra) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(450);

  const m = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    /* THE NUMBER THAT MATTERS AT 390. scrollWidth greater than the viewport is
       a page with a horizontal scrollbar, which is the defect every width
       measurement in this repository is really about. */
    overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    setPanel: document.querySelectorAll('.ksp-grid').length,
    setMembers: document.querySelectorAll('.ksp-m').length,
    setLinks: document.querySelectorAll('a.ksp-nm').length,
    setUnlinked: document.querySelectorAll('span.ksp-nm').length,
    footing: document.querySelector('.ksp-foot')?.innerText.replace(/\n/g, ' | ') ?? null,
    editorSetPanel: document.querySelectorAll('[data-peo-panel="setbox"]').length,
    editorMembers: document.querySelectorAll('[data-peo-setdrag]').length,
    editorTiles: [...document.querySelectorAll('[data-peo-money]')]
      .map((n) => n.getAttribute('data-peo-money') + '=' + n.textContent).join(' | ') || null,
    editorType: document.querySelector('[data-bind="type"]')?.value ?? null,
    editorMode: document.querySelector('[data-bind="price_mode"]')?.value ?? null,
    dragMarked: document.querySelectorAll('.peo-setm.is-over').length,
    chips: [...document.querySelectorAll('.chips [data-cpf]')].map((n) => n.textContent.trim()).join(' / ') || null,
    setPills: [...document.querySelectorAll('.pill.blue')].filter((n) => n.textContent === 'Set').length,
    productRows: document.querySelectorAll('#cplListArea tbody tr').length,
    activeChip: document.querySelector('.chips .chip.on')?.textContent.trim() ?? null,
    setsListRows: document.querySelectorAll('[data-kst-edit]').length,
    stockSwitch: document.querySelector('[data-kst-stock]')?.value ?? null,
  }));

  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
  console.log(JSON.stringify({ shot: `${name}-${w}`, ...m, ...(extra || {}) }));
}

async function signIn(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  // The editor asks before losing unsaved changes; this script is the one
  // answering, and it always says yes.
  page.on('dialog', (d) => d.accept());

  /* ───────────────────────────────────────────── 1. the storefront ─── */

  await page.goto(`${BASE}/product/${SET_SLUG}/`, { waitUntil: 'networkidle' });
  for (const w of [390, 1280]) await shoot(page, 'storefront-set-page', w, 1800);

  /* The control case: an ordinary product draws NOTHING, which is what
     "nothing that already works may change" looks like on this page. */
  await page.goto(`${BASE}/product/${PLAIN_SLUG}/`, { waitUntil: 'networkidle' });
  for (const w of [390, 1280]) await shoot(page, 'storefront-plain-page', w, 1800);

  /* ───────────────────────────────────────── 2. the product editor ─── */

  await signIn(page);

  // A SIMPLE product: the set panel must be absent entirely.
  await page.evaluate(() => window.peoNew());
  await page.waitForTimeout(1400);
  for (const w of [390, 1280]) await shoot(page, 'editor-type-simple', w, 1700);

  // Switch it to Set: the panel appears, and nothing else on the page moves.
  await page.setViewportSize({ width: 1280, height: 1700 });
  await page.selectOption('[data-bind="type"]', 'set');
  await page.waitForTimeout(700);
  for (const w of [390, 1280]) await shoot(page, 'editor-type-set', w, 1900);

  /* Fill the box and apply a discount, which is the control the owner asked
     for by name — "give option that how much discount on total, and it will
     auto set the price". */
  await page.setViewportSize({ width: 1280, height: 1900 });
  await page.fill('#peo-name', 'Barrier Repair Set');
  await page.click('#peo-setq');
  await page.type('#peo-setq', 'Toner', { delay: 25 });
  await page.waitForTimeout(1400);

  for (let i = 0; i < 3; i += 1) {
    // Re-queried every pass: adding a member RE-RENDERS the panel, so a handle
    // taken before the first click is detached by the second.
    const rows = await page.$$('[data-peo-add]');
    if (!rows[i]) break;
    await rows[i].click();
    await page.waitForTimeout(400);
  }

  await page.selectOption('[data-bind="price_mode"]', 'discount_percent');
  await page.waitForTimeout(500);
  await page.fill('#peo-setpct', '15');
  await page.waitForTimeout(400);
  for (const w of [390, 1280]) await shoot(page, 'editor-set-discount', w, 2000);

  /* The member list mid-drag. The drag is driven by the same events the
     browser fires, so what is photographed is the real handler's own class on
     the real row — not a class this script added. */
  await page.setViewportSize({ width: 1280, height: 2000 });

  const dragged = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('[data-peo-setdrag]')];
    if (rows.length < 2) return { rows: rows.length, marked: 0 };

    const dt = new DataTransfer();
    rows[0].dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
    rows[rows.length - 1].dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));

    return { rows: rows.length, marked: document.querySelectorAll('.peo-setm.is-over').length };
  });

  for (const w of [390, 1280]) await shoot(page, 'editor-set-drag', w, 2000, { dragged });

  /* ─────────────────────────────────────── 3. Catalog → Products ─── */

  /* A FULL RELOAD FIRST, and it is not superstition: the editor is holding
     unsaved changes, and leaving it in-page is a confirm() this script would
     have to answer. Reloading the console is what an operator does anyway. */
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.setViewportSize({ width: 1280, height: 1700 });
  await page.evaluate(() => window.go('catalog'));
  await page.waitForSelector('.chips [data-cpf]', { timeout: 15000 });
  await page.waitForTimeout(900);
  for (const w of [390, 1280]) await shoot(page, 'catalog-products-chip', w, 1700);

  // And the chip pressed, which is the filter.
  await page.setViewportSize({ width: 1280, height: 1700 });
  const chip = await page.$('[data-cpf="set"]');
  if (chip) {
    await chip.click();
    await page.waitForTimeout(1400);
  }
  for (const w of [390, 1280]) await shoot(page, 'catalog-products-sets-only', w, 1700);

  /* ─────────────────────────────────────────── 4. Catalog → Sets ─── */

  await page.setViewportSize({ width: 1280, height: 1400 });
  await page.evaluate(() => window.go('sets'));
  await page.waitForTimeout(1600);
  for (const w of [390, 1280]) await shoot(page, 'admin-sets-list', w, 1400);

  await browser.close();
})();
