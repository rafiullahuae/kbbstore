/*
 * Catalog → Sets, photographed.
 *
 * ── WHY THE SCREEN IS INJECTED RATHER THAN INCLUDED ────────────────────────
 *
 * resources/views/admin/app.blade.php is the INTEGRATOR's file and this lane
 * may not edit it, so the console in this preview does not yet carry
 * `@include('admin.partials.sets-screen')`. Rather than photograph a screen
 * that is not there, this script reads the REAL partial off disk, takes out the
 * Blade wrapper (the comment and the verbatim markers — the file is otherwise
 * plain <style> and <script>) and injects exactly those two blocks into the
 * loaded console. What is photographed is therefore the same bytes the
 * integrator will include, running against the same endpoints, with the same
 * window.go wrapper and the same sidebar registration.
 *
 * The moment the include lands this script can be replaced with a plain
 * `window.go('sets')`. SetRoutesWiredTest is the pin that says when.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SET_BASE || 'http://127.0.0.1:8989';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SET_OUT || `${APP}/docs/lane-set-shots`;

function partialBlocks() {
  let src = fs.readFileSync(`${APP}/resources/views/admin/partials/sets-screen.blade.php`, 'utf8');
  src = src.replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/@verbatim|@endverbatim/g, '');
  const style = /<style>([\s\S]*?)<\/style>/.exec(src)[1];
  const script = /<script>([\s\S]*?)<\/script>/.exec(src)[1];
  return { style, script };
}

async function shoot(page, name, w, h) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(500);
  const m = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
    crumb: document.querySelector('#crumb')?.textContent ?? null,
    title: document.querySelector('#ptitle')?.textContent ?? null,
    sidebarRows: [...document.querySelectorAll('#nav [data-go="sets"]')].length,
    cards: document.querySelectorAll('.kst-card').length,
    memberRows: document.querySelectorAll('[data-kst-mq]').length,
    pickerRows: document.querySelectorAll('[data-kst-add]').length,
    summary: document.querySelector('.kst-sum')?.innerText.replace(/\n/g, ' | ') ?? null,
    listRows: document.querySelectorAll('[data-kst-edit]').length,
  }));
  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
  console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const { style, script } = partialBlocks();

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
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

  await page.addStyleTag({ content: style });
  await page.addScriptTag({ content: script });
  await page.waitForTimeout(300);

  /* 1. the list */
  await page.evaluate(() => window.go('sets'));
  await page.waitForTimeout(1200);
  for (const w of [390, 1280]) await shoot(page, 'admin-sets-list', w, 1400);

  /* 2. creating a set: the editor, with a search run and a member chosen */
  await page.setViewportSize({ width: 1280, height: 1400 });
  await page.click('[data-kst-new]');
  await page.waitForTimeout(400);
  await page.fill('[data-kst-f="name"]', 'Barrier Repair Set');
  await page.fill('[data-kst-f="price_aed"]', '179.00');
  await page.click('[data-kst-q]');
  await page.type('[data-kst-q]', 'Toner', { delay: 25 });
  await page.waitForTimeout(1200);

  /* Re-queried on every pass. Adding a member RE-RENDERS the screen, so a
     handle taken before the first click is detached by the second — which is
     what "Element is not attached to the DOM" was telling us. */
  for (let i = 0; i < 3; i++) {
    const rows = await page.$$('[data-kst-add]');
    if (!rows[i]) break;
    // rows[i], not rows[0]: pressing Add on the same row twice is refused
    // ("already in this set — raise its quantity instead"), which is correct
    // behaviour and a shot of one member.
    await rows[i].click();
    await page.waitForTimeout(350);
  }

  for (const w of [390, 1280]) await shoot(page, 'admin-sets-create', w, 1600);

  await browser.close();
})();
