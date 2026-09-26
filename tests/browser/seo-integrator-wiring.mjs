/**
 * The two new SEO controls, and the markup each one moves.
 *
 * Lane S6 built FaqSchema and Lane S5 built the merchant returns vocabulary;
 * neither could add its own control, because `app.blade.php` and
 * `AdminController::SETTING_RULES` belong to the integrator. This drives both
 * controls in a real browser and then reads the storefront markup they change,
 * so the claim "it is wired" has a picture and a measurement behind it rather
 * than a file diff.
 *
 *   KBB_SIW_URL=http://127.0.0.1:8990 \
 *   KBB_SIW_OUT=docs/seo-wiring-shots \
 *   node tests/browser/seo-integrator-wiring.mjs
 *
 * Signs in as seo@example.test / secret-secret. Point it at a preview, never at
 * production -- it WRITES two settings and then puts both back.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_SIW_URL || 'http://127.0.0.1:8990';
const OUT = process.env.KBB_SIW_OUT || 'docs/seo-wiring-shots';
const CHROME = process.env.KBB_SIW_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });

/** Every JSON-LD node on the current page, by @type. */
const graph = (p) => p.evaluate(() => {
  const out = [];
  for (const s of document.querySelectorAll('script[type="application/ld+json"]')) {
    let d; try { d = JSON.parse(s.textContent); } catch { continue; }
    for (const n of (Array.isArray(d) ? d : [d])) out.push(n);
  }
  return out.map(n => ({
    type: n['@type'],
    url: n.url ?? null,
    questions: Array.isArray(n.mainEntity) ? n.mainEntity.map(q => q.name) : null,
  }));
});

const page = (p) => p.evaluate(() => ({
  scrollWidth: document.documentElement.scrollWidth,
  clientWidth: document.documentElement.clientWidth,
}));

/** The label + its rendered select, so the shot is checkable as text too. */
const control = (p, id) => p.evaluate((sel) => {
  const el = document.getElementById(sel);
  if (!el) return null;
  const field = el.closest('.sm-field');
  const cs = getComputedStyle(el);
  const r = el.getBoundingClientRect();
  return {
    label: field?.querySelector('.sm-label')?.textContent.trim() ?? null,
    options: [...el.options].map(o => `${o.value || '(blank)'}=${o.textContent.trim()}`),
    selected: el.value || '(blank)',
    help: field?.querySelector('.sm-help')?.textContent.trim() ?? null,
    fontSize: cs.fontSize,
    width: Math.round(r.width),
    height: Math.round(r.height),
  };
}, id);

for (const [label, width, height] of [['390', 390, 844], ['1280', 1280, 900]]) {
  const ctx = await b.newContext({ viewport: { width, height } });
  const p = await ctx.newPage();

  console.log(`\n════════ ${label}px ════════`);

  /* ── 1 · the storefront FAQ page, flag OFF (the shipped shop) ─────────── */
  await p.goto(`${BASE}/faqs/`, { waitUntil: 'networkidle' });
  console.log(`[${label}] /faqs/ flag OFF  page=${JSON.stringify(await page(p))}`);
  console.log(`[${label}]   graph=${JSON.stringify(await graph(p))}`);
  await p.screenshot({ path: `${OUT}/${label}-01-faqs-flag-off.png`, fullPage: false });

  /* ── 2 · sign in ──────────────────────────────────────────────────────── */
  await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await p.fill('input[type="email"]', 'seo@example.test');
  await p.fill('input[type="password"]', 'secret-secret');
  await Promise.all([
    p.waitForNavigation({ waitUntil: 'networkidle' }),
    p.click('button[type="submit"]'),
  ]);
  console.log(`[${label}] signed in -> ${p.url()}`);

  /* ── 3 · Store → SEO & Meta → Settings ────────────────────────────────── */
  /*
   * Reached the way the owner reaches it, and NOT by /admin#seo -- which signs
   * in fine and then times out waiting for the control, because the console is
   * a single page whose screens are drawn by JS on a nav click, not by the hash.
   *
   * Below 880px the sidebar is a drawer, off-canvas until the hamburger is
   * pressed. Opening it is what the owner does on a phone, so it is what this
   * does: clicking a nav item without it fails with "element is outside of the
   * viewport", which is the browser correctly reporting that nobody could have
   * clicked it either. The group header intercepts pointer events until it is
   * expanded, so it is clicked first -- the same two steps the owner makes.
   */
  const openSeoSettings = async () => {
    /*
     * The console first, ALWAYS. The second call to this comes from /faqs/ on
     * the storefront, where `.menubtn` does not exist -- so the first draft
     * waited 30s for a hamburger on a shop page. The console is idempotent about
     * being re-entered.
     */
    if (!p.url().includes('/admin')) {
      await p.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    }

    if (width < 880) {
      await p.locator('.menubtn').click();
      await p.waitForTimeout(400);
    }
    await p.locator('#nav .nav-group[data-sec="Store"] .nav-gh').click();
    await p.waitForTimeout(500);
    await p.locator('[data-go="seo"]').first().click();
    await p.waitForSelector('.subtab[data-st="settings"]', { timeout: 15000 });
    await p.locator('.subtab[data-st="settings"]').click();
    await p.waitForSelector('#seo_merch_returns', { timeout: 15000 });
    await p.waitForSelector('#seo_faq_schema', { timeout: 15000 });
  };

  await openSeoSettings();

  console.log(`[${label}] Returns policy  ${JSON.stringify(await control(p, 'seo_merch_returns'))}`);
  console.log(`[${label}] FAQ markup      ${JSON.stringify(await control(p, 'seo_faq_schema'))}`);
  console.log(`[${label}] admin page      ${JSON.stringify(await page(p))}`);

  // The card, not the whole screen: the control is the subject.
  const merchant = await p.$('#seo_merch_returns');
  await merchant.scrollIntoViewIfNeeded();
  await p.screenshot({ path: `${OUT}/${label}-02-returns-policy-before.png`, fullPage: false });

  const faq = await p.$('#seo_faq_schema');
  await faq.scrollIntoViewIfNeeded();
  await p.screenshot({ path: `${OUT}/${label}-03-faq-markup-before.png`, fullPage: false });

  /* ── 4 · move both, and save ──────────────────────────────────────────── */
  await p.selectOption('#seo_merch_returns', 'MerchantReturnNotPermitted');
  await p.selectOption('#seo_faq_schema', '1');

  await merchant.scrollIntoViewIfNeeded();
  await p.screenshot({ path: `${OUT}/${label}-04-returns-policy-after.png`, fullPage: false });

  await faq.scrollIntoViewIfNeeded();
  await p.screenshot({ path: `${OUT}/${label}-05-faq-markup-after.png`, fullPage: false });

  // Whichever Save button this tab carries.
  const saved = await p.evaluate(async () => {
    const btns = [...document.querySelectorAll('button, .btn')]
      .filter(b => /save/i.test(b.textContent || ''));
    if (!btns.length) return 'NO SAVE BUTTON FOUND';
    btns[0].click();
    return btns[0].textContent.trim();
  });
  console.log(`[${label}] clicked: ${saved}`);
  await p.waitForTimeout(1500);
  await p.screenshot({ path: `${OUT}/${label}-06-saved.png`, fullPage: false });

  /* ── 5 · the storefront again, flag ON ────────────────────────────────── */
  await p.goto(`${BASE}/faqs/`, { waitUntil: 'networkidle' });
  console.log(`[${label}] /faqs/ flag ON   page=${JSON.stringify(await page(p))}`);
  console.log(`[${label}]   graph=${JSON.stringify(await graph(p))}`);
  await p.screenshot({ path: `${OUT}/${label}-07-faqs-flag-on.png`, fullPage: false });

  /* ── 6 · and the control reopens showing what was saved ───────────────── */
  await openSeoSettings();
  console.log(`[${label}] reopened Returns policy = ${(await control(p, 'seo_merch_returns')).selected}`);
  console.log(`[${label}] reopened FAQ markup     = ${(await control(p, 'seo_faq_schema')).selected}`);

  /* ── 7 · put both back, so the preview ends where it started ──────────── */
  await p.selectOption('#seo_merch_returns', '');
  await p.selectOption('#seo_faq_schema', '0');
  await p.evaluate(() => {
    const b = [...document.querySelectorAll('button, .btn')].find(b => /save/i.test(b.textContent || ''));
    b?.click();
  });
  await p.waitForTimeout(1500);

  await ctx.close();
}

await b.close();
