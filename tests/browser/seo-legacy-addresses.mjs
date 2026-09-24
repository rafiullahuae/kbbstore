/**
 * Lane SEO round 1 — the fifteen old category addresses, followed in a real
 * browser, and the SEO Audit card whose sentence changed because of them.
 *
 * The suite proves the status codes. This proves the two things a person can
 * actually see: that an address the old shop published lands on a real page in
 * one hop, at the address that page itself calls canonical; and that the audit
 * has stopped reporting the ones that now work.
 *
 *   KBB_SEO_URL=http://127.0.0.1:8977 \
 *   KBB_SEO_OUT=docs/seo-url-map-shots \
 *   node tests/browser/seo-legacy-addresses.mjs
 *
 * Signs in as seo@example.test / secret-secret. Point it at a preview, never at
 * production.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_SEO_URL || 'http://127.0.0.1:8977';
const OUT = process.env.KBB_SEO_OUT || 'docs/seo-url-map-shots';
const CHROME = process.env.KBB_SEO_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });

const measure = async (p) => p.evaluate(() => ({
  scrollWidth: document.documentElement.scrollWidth,
  clientWidth: document.documentElement.clientWidth,
  canonical: document.querySelector('link[rel=canonical]')?.href ?? null,
  lang: document.documentElement.lang,
  h1: document.querySelector('h1')?.textContent.trim().slice(0, 60) ?? null,
  h1Size: (() => { const h = document.querySelector('h1'); return h ? getComputedStyle(h).fontSize : null; })(),
}));

for (const [label, width, height] of [['390', 390, 844], ['1280', 1280, 900]]) {
  const ctx = await b.newContext({ viewport: { width, height } });
  const p = await ctx.newPage();

  /* ── 1 · an old address, followed ─────────────────────────────────────── */
  const hops = [];
  p.on('response', r => { if ([301, 302].includes(r.status())) hops.push(`${r.status()} ${r.url()} -> ${r.headers().location}`); });

  await p.goto(`${BASE}/toners/`, { waitUntil: 'networkidle' });
  console.log(`\n[${label}px] GET /toners/`);
  hops.forEach(h => console.log('   hop:', h));
  console.log('   landed:', p.url());
  console.log('   ', JSON.stringify(await measure(p)));
  await p.screenshot({ path: `${OUT}/${label}-01-toners-landed.png`, fullPage: false });

  /* ── 2 · the same in Arabic ───────────────────────────────────────────── */
  hops.length = 0;
  await p.goto(`${BASE}/ar/toners/`, { waitUntil: 'networkidle' });
  console.log(`[${label}px] GET /ar/toners/`);
  hops.forEach(h => console.log('   hop:', h));
  console.log('   landed:', p.url());
  console.log('   ', JSON.stringify(await measure(p)));
  await p.screenshot({ path: `${OUT}/${label}-02-toners-landed-ar.png`, fullPage: false });

  /* ── 3 · the SEO Audit card ───────────────────────────────────────────── */
  await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await p.fill('input[type="email"]', 'seo@example.test');
  await p.fill('input[type="password"]', 'secret-secret');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);

  // Reached the way the owner reaches it: Store → SEO & Meta → SEO Audit.
  /*
   * Below 880px the console's sidebar is a drawer, off-canvas until the
   * hamburger is pressed (app.blade.php:677, `.side.open`). Opening it is what
   * the owner does on a phone, so it is what this does — clicking a nav item
   * without it times out on "element is outside of the viewport", which is the
   * browser correctly reporting that nobody could have clicked it either.
   */
  if (width < 880) {
    await p.locator('.menubtn').click();
    await p.waitForTimeout(400);
  }

  // The group header intercepts pointer events until it is expanded, so the
  // header is clicked first — the same two steps the owner makes.
  await p.locator('#nav .nav-group[data-sec="Store"] .nav-gh').click();
  await p.waitForTimeout(500);
  await p.locator('[data-go="seo"]').first().click();
  await p.waitForSelector('.subtab[data-st="seoaudit"]', { timeout: 15000 });
  await p.locator('.subtab[data-st="seoaudit"]').click();
  await p.waitForTimeout(3000);

  const card = await p.evaluate(() => {
    const el = [...document.querySelectorAll('*')]
      .find(n => n.children.length === 0 && /Old shop address with no redirect/.test(n.textContent));
    if (!el) return { found: false };
    const box = el.closest('div');
    const r = box?.getBoundingClientRect();
    return {
      found: true,
      heading: el.textContent.trim(),
      text: (box?.parentElement?.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 520),
      width: r ? Math.round(r.width) : null,
      height: r ? Math.round(r.height) : null,
      fontSize: getComputedStyle(el).fontSize,
    };
  });

  // Scroll the card into view so the shot shows the thing that changed.
  await p.evaluate(() => {
    const el = [...document.querySelectorAll('*')]
      .find(n => n.children.length === 0 && /Old shop address with no redirect/.test(n.textContent));
    el?.scrollIntoView({ block: 'center' });
  });
  await p.waitForTimeout(400);
  console.log(`[${label}px] SEO Audit card:`, JSON.stringify(card).slice(0, 500));
  console.log('   ', JSON.stringify(await measure(p)));
  await p.screenshot({ path: `${OUT}/${label}-03-seo-audit.png` });

  await ctx.close();
}

await b.close();
