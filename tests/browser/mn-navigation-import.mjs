/**
 * Lane MN — the imported WordPress navigation, as the shopper and the owner see it.
 *
 * The suite proves the rows. This proves the thing that is actually being
 * claimed: the header of a shop whose menu came out of WordPress, at both
 * widths, with the item this shop could not place ABSENT from it and PRESENT on
 * the screen where the owner fixes it.
 *
 *   KBB_MN_URL=http://127.0.0.1:8973 KBB_MN_OUT=docs/lane-mn-shots \
 *   node tests/browser/mn-navigation-import.mjs
 *
 * Point it at a preview, never at production: it signs in to the admin console.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'fs';

const BASE = process.env.KBB_MN_URL || 'http://127.0.0.1:8973';
const OUT = process.env.KBB_MN_OUT || 'docs/lane-mn-shots';
const CHROME = process.env.KBB_MN_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const EMAIL = process.env.KBB_MN_EMAIL || 'mn@example.test';
const PASSWORD = process.env.KBB_MN_PASSWORD || 'secret-secret';

mkdirSync(OUT, { recursive: true });

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const findings = { errors: [] };

for (const [label, width, height] of [['390', 390, 844], ['1280', 1280, 900]]) {
  const ctx = await b.newContext({ viewport: { width, height } });
  const p = await ctx.newPage();

  p.on('pageerror', (e) => findings.errors.push(`${label}: ${e.message}`));

  /* ── The storefront header ──────────────────────────────────────────────── */

  await p.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(500);

  findings[`store_${label}`] = await p.evaluate(() => {
    const bar = document.querySelector('.navbar, .nav-bar, header nav, header');
    const r = bar?.getBoundingClientRect();
    /*
     * `.navlink` AND `.colh`. A mega-panel COLUMN HEADING is a <div class=
     * "colh"> and not an anchor (nav-bar.blade.php), so a query over anchors
     * alone reports the parent of a panel as absent from a header it is
     * plainly in.
     */
    const links = [...document.querySelectorAll('.mbar a, .mbar .colh, header a, nav a')]
      .map((a) => ({ text: a.textContent.replace(/\s+/g, ' ').trim().replace(/\s*▾$/, ''), href: a.getAttribute('href') }))
      .filter((a) => a.text);

    return {
      scrollWidth: document.documentElement.scrollWidth,
      headerHeight: r ? Math.round(r.height) : null,
      headerWidth: r ? Math.round(r.width) : null,
      bodyFont: getComputedStyle(document.body).fontSize,
      // The imported menu, by label. `About us` is the parked one and must not
      // be here; everything else must.
      hasSkincare: links.some((a) => a.text === 'Skincare'),
      hasFaceCleansers: links.some((a) => a.text === 'Face Cleansers'),
      hasBrand: links.some((a) => a.text === 'Beauty of Joseon'),
      hasProduct: links.some((a) => a.text === 'Our hero serum'),
      hasArticle: links.some((a) => a.text.startsWith('How to layer')),
      hasSale: links.some((a) => a.text === 'Sale'),
      hasParked: links.some((a) => a.text === 'About us'),
      importedHrefs: links
        .filter((a) => ['Skincare', 'Face Cleansers', 'Beauty of Joseon', 'Our hero serum', 'Sale'].includes(a.text)
          || a.text.startsWith('How to layer'))
        .map((a) => `${a.text} -> ${a.href}`),
    };
  });

  await p.screenshot({ path: `${OUT}/storefront-header-${label}.png` });

  /*
   * And again with the panel OPEN, which is where the two-level import shows.
   * `Skincare` is the parent; its three children are a category, a brand and a
   * product, and they are the whole point of the exercise.
   */
  if (width >= 900) {
    const parent = p.locator('.mbar .navlink', { hasText: 'Skincare' }).first();

    if (await parent.count()) {
      await parent.hover();
      await p.waitForTimeout(600);

      findings[`panel_${label}`] = await p.evaluate(() => {
        const drop = [...document.querySelectorAll('.mbar .drop')]
          .find((d) => getComputedStyle(d).visibility !== 'hidden' && d.getBoundingClientRect().height > 0);
        const r = drop?.getBoundingClientRect();

        return {
          open: !!drop,
          height: r ? Math.round(r.height) : null,
          width: r ? Math.round(r.width) : null,
          links: [...(drop?.querySelectorAll('a') || [])]
            .map((a) => `${a.textContent.replace(/\s+/g, ' ').trim()} -> ${a.getAttribute('href')}`),
        };
      });

      await p.screenshot({ path: `${OUT}/storefront-panel-${label}.png`, clip: { x: 0, y: 0, width, height: 420 } });
    }
  }

  /* ── The mobile drawer, which is the same tree through another template ── */

  if (width < 900) {
    const burger = p.locator('#burger').first();

    if (await burger.count()) {
      await burger.click();
      await p.waitForTimeout(700);

      findings[`drawer_${label}`] = await p.evaluate(() => {
        const sheet = document.querySelector('#mmenu');
        const r = sheet?.getBoundingClientRect();
        const rows = [...(sheet?.querySelectorAll('a, button') || [])]
          .map((n) => n.textContent.replace(/\s+/g, ' ').trim())
          .filter(Boolean);

        return {
          found: !!sheet,
          sheetHeight: r ? Math.round(r.height) : null,
          sheetWidth: r ? Math.round(r.width) : null,
          scrollWidth: document.documentElement.scrollWidth,
          rows,
          hasParked: rows.includes('About us'),
        };
      });

      await p.screenshot({ path: `${OUT}/mobile-drawer-${label}.png` });
    }
  }

  /* ── Store → Modules → Mega Menu, where the owner switches it on ────────── */

  await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await p.fill('input[type="email"]', EMAIL);
  await p.fill('input[type="password"]', PASSWORD);
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);

  // Below 880px the console's sidebar is off-canvas until the hamburger is
  // pressed (app.blade.php) — which is what the owner does on a phone.
  if (width < 880) {
    await p.locator('.menubtn').click();
    await p.waitForTimeout(400);
  }

  // The console is one page whose sections are rendered by JavaScript, so the
  // screen is reached the way the owner reaches it: open the Store group in the
  // sidebar and press Mega Menu.
  await p.locator('#nav .nav-group[data-sec="Store"] .nav-gh').click();
  await p.waitForTimeout(500);
  await p.locator('[data-go="megamenu"]').first().click();
  await p.waitForSelector('#mgmTree', { timeout: 15000 });
  await p.waitForTimeout(1200);

  // The imported menu, selected, so the shot is of the thing this lane built.
  const tab = p.locator('.mgm-menutab', { hasText: 'Main menu' }).first();
  if (await tab.count()) {
    await tab.click();
    await p.waitForTimeout(1500);
  }

  findings[`admin_${label}`] = await p.evaluate(() => {
    const text = document.body.innerText.replace(/\s+/g, ' ');

    return {
      scrollWidth: document.documentElement.scrollWidth,
      // Both menus are in the picker: the one that was already here and the
      // one that came out of WordPress.
      mentionsImported: /Main menu/.test(text),
      // And the parked item IS on this screen, which is the other half of
      // "nothing is lost".
      mentionsParked: /About us/.test(text),
      menuTabs: [...document.querySelectorAll('.mgm-menutab')].map((n) => n.textContent.replace(/\s+/g, ' ').trim()),
      topLevel: [...document.querySelectorAll('#mgmTree > .mgmrow-wrap > .mgmitem .mgmlabel')].length,
      rows: [...document.querySelectorAll('#mgmTree .mgmitem .mgmlabel')]
        .map((n) => n.textContent.replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .slice(0, 40),
    };
  });

  await p.screenshot({ path: `${OUT}/mega-menu-${label}.png`, fullPage: true });

  await ctx.close();
}

writeFileSync(`${OUT}/findings.json`, JSON.stringify(findings, null, 2));
console.log(JSON.stringify(findings, null, 2));

await b.close();
