/*
 * LANE PG — the grid evidence. One row per (page, width).
 *
 * Measures, per shot:
 *   viewport                the width the page was rendered at
 *   scrollWidth/clientWidth document.documentElement, i.e. "does it fit"
 *   cols                    the RENDERED column count, read off the grid's
 *                           computed grid-template-columns (the resolved track
 *                           list) — not inferred from a breakpoint
 *   cardHeights             every tile's offsetHeight, so equal means equal
 *   rated/unrated           how many tiles drew a rating bar
 *
 * This is a HARNESS and lives outside the shop: rule 4 forbids the shop's own
 * JavaScript from measuring layout, and none of this ships.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8341';
const EXE  = process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT  = process.env.KBB_SHOTS || '/home/user/lane-pg/docs/lane-pg-shots';

const SHOTS = JSON.parse(fs.readFileSync(process.env.KBB_PLAN, 'utf8'));

const probe = () => {
  const de = document.documentElement;
  const grids = [...document.querySelectorAll('.kbb-pgrid, #grid, .rel')];
  const rows = grids.map((g) => {
    const tracks = getComputedStyle(g).gridTemplateColumns.trim();
    const tiles = [...g.querySelectorAll(':scope > .kbb-tile')];
    return {
      sel: g.id ? '#' + g.id : '.' + [...g.classList].join('.'),
      cols: tracks === 'none' ? 0 : tracks.split(/\s+/).length,
      track: tracks,
      tiles: tiles.length,
      heights: tiles.map((t) => Math.round(t.getBoundingClientRect().height)),
      widths: tiles.map((t) => Math.round(t.getBoundingClientRect().width)),
      rated: tiles.filter((t) => t.querySelector('.kbb-card-rate')).length,
      names: tiles.map((t) => (t.querySelector('.kbb-card-nm')?.textContent || '').slice(0, 40)),
      nameBox: tiles.map((t) => {
        const n = t.querySelector('.kbb-card-nm');
        return n ? Math.round(n.getBoundingClientRect().height) : null;
      }),
    };
  });
  return {
    scrollWidth: de.scrollWidth,
    clientWidth: de.clientWidth,
    bodyClass: document.body.className,
    filterRail: (() => {
      const a = document.querySelector('aside.filtercol');
      if (!a) return null;
      const cs = getComputedStyle(a);
      return { display: cs.display, width: Math.round(a.getBoundingClientRect().width) };
    })(),
    showFilters: (() => {
      const b = document.getElementById('showFilters');
      if (!b) return null;
      const r = b.getBoundingClientRect();
      const cs = getComputedStyle(b);
      return { display: cs.display, w: Math.round(r.width), h: Math.round(r.height), bg: cs.backgroundColor, color: cs.color };
    })(),
    /*
     * The page container's own geometry, which is how the /shop gutter defect
     * was found: `.shop` declared `padding:22px 0 60px`, the `0` beat
     * `.wrap{padding-inline:var(--site-gutter)}`, and the grid ran edge to edge
     * under an <h1> that was inset by 22px.
     */
    wrap: (() => {
      const el = document.querySelector('.wrap.shop') || document.querySelector('.wrap');
      if (!el) return null;
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return { x: Math.round(r.x), w: Math.round(r.width), padL: cs.paddingLeft, padR: cs.paddingRight };
    })(),
    grids: rows,
  };
};

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const results = [];
  for (const shot of SHOTS) {
    const ctx = await browser.newContext({ viewport: { width: shot.w, height: shot.h || 1200 }, deviceScaleFactor: 1 });
    if (shot.cookies) await ctx.addCookies(shot.cookies.map((c) => ({ name: c.name, value: c.value, domain: "127.0.0.1", path: "/" })));
    const page = await ctx.newPage();
    // An optional warm-up visit: click things on another page first (saving
    // three products to the wishlist, say) so the page under test has content.
    if (shot.pre) {
      await page.goto(BASE + shot.pre.path, { waitUntil: 'networkidle' });
      for (const sel of shot.pre.clicks || []) { await page.click(sel); await page.waitForTimeout(500); }
    }
    await page.goto(BASE + shot.path, { waitUntil: 'networkidle' });
    if (shot.click) { await page.click(shot.click); await page.waitForTimeout(400); }
    const data = await page.evaluate(probe);
    await page.screenshot({ path: `${OUT}/${shot.name}.png`, fullPage: shot.full !== false });
    results.push({ name: shot.name, path: shot.path, viewport: shot.w, ...data });
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(results, null, 2));
  for (const r of results) {
    const g = r.grids[0] || {};
    console.log(`${r.name.padEnd(34)} vw=${String(r.viewport).padStart(4)} scrollW=${r.scrollWidth} clientW=${r.clientWidth} cols=${g.cols} tiles=${g.tiles} rated=${g.rated} heights=[${(g.heights||[]).slice(0,6).join(',')}]`);
  }
})();
