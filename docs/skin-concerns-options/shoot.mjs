// Screenshots for docs/skin-concerns-options. Run from the repo root:
//   node docs/skin-concerns-options/shoot.mjs [fontsDir]
// Google Fonts is answered from the shop's own Outfit/Cairo files (public/build/assets),
// so the shots are the shop's real type and need no network.
import { createRequire } from 'node:module';
import { readFileSync, readdirSync, mkdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const load = () => { try { return require('playwright'); } catch { return require((process.env.NODE_PATH || '/opt/node22/lib/node_modules') + '/playwright'); } };
const { chromium } = load();
const here = dirname(fileURLToPath(import.meta.url));
const fontsDir = resolve(process.argv[2] || 'public/build/assets');
const out = join(here, 'shots');
mkdirSync(out, { recursive: true });
const pick = re => { const f = readdirSync(fontsDir).find(n => re.test(n)); return f ? join(fontsDir, f) : null; };
const fonts = { outfit: pick(/^outfit-latin-[^e].*\.woff2$/), cairoAr: pick(/^cairo-arabic-.*\.woff2$/), cairo: pick(/^cairo-latin-[^e].*\.woff2$/) };
const css = `@font-face{font-family:Outfit;font-weight:100 900;src:url(https://fonts.gstatic.com/x/outfit.woff2)}
@font-face{font-family:Cairo;font-weight:200 1000;src:url(https://fonts.gstatic.com/x/cairo-ar.woff2);unicode-range:U+0600-06FF,U+0750-077F,U+FB50-FDFF,U+FE70-FEFF}
@font-face{font-family:Cairo;font-weight:200 1000;src:url(https://fonts.gstatic.com/x/cairo.woff2);unicode-range:U+0000-00FF}`;

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
async function page(width, height = 900) {
  const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(String(e)));
  p.on('console', m => m.type() === 'error' && errors.push(m.text()));
  await p.route('https://fonts.googleapis.com/**', r => r.fulfill({ contentType: 'text/css', body: css }));
  await p.route('https://fonts.gstatic.com/**', r => {
    const u = r.request().url();
    const f = u.endsWith('outfit.woff2') ? fonts.outfit : u.endsWith('cairo-ar.woff2') ? fonts.cairoAr : fonts.cairo;
    return f ? r.fulfill({ contentType: 'font/woff2', body: readFileSync(f) }) : r.abort();
  });
  await p.goto('file://' + join(here, 'index.html'));
  await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
  await p.reload();
  await p.evaluate(() => document.fonts.ready);
  return { p, ctx, errors };
}
const click = (p, sel, text) => p.locator(sel + ' button', { hasText: text }).first().click();

const only = process.argv[3];
const { p, ctx, errors } = await page(1720, 1000); // wide enough that the laptop screen draws at zoom 1
if (!only || only === 'art') {
  await p.locator('#more-sets summary').click();
  for (const [label, file] of [['White on deep tone', 'artwork-sheet-deep.png'], ['Line, ring only', 'artwork-sheet-ring.png'], ['Pastel light', 'artwork-sheet.png']]) {
    await click(p, '#c-pres', label);
    await p.locator('#sheet').screenshot({ path: join(out, file) });
  }
  await p.locator('#more-sets summary').click();
}
const tap = async (sel, text) => { await click(p, sel, text); await p.waitForTimeout(120); };
const sec = async (name) => {
  await p.waitForTimeout(250);
  await p.locator('#scr-m .skc').screenshot({ path: join(out, name + '-390.png') });
  await p.locator('#scr-d .skc').screenshot({ path: join(out, name + '-1280.png') });
};
if (!only || only === 'designs') {
  await tap('#c-view', 'Both');
  for (const d of ['A', 'B', 'C', 'D', 'E']) {
    await p.locator('#c-design button', { hasText: d + ' ·' }).click();
    await sec('design-' + d);
  }
  await p.locator('#c-design button', { hasText: 'A ·' }).click();
  await tap('#c-arrM', 'On');
  for (const [st, name] of [['Round white', 'white'], ['Round pink', 'pink'], ['Outline ring', 'ring'], ['Square soft', 'square'], ['Chevron only', 'chevron']]) {
    await tap('#c-arrStyle', st); await sec('arrows-' + name);
  }
  await tap('#c-arrStyle', 'Round white');
  for (const [pos, name] of [['Outside the row', 'outside'], ['Top, by the heading', 'top'], ['Bottom centre', 'bottom'], ['Sides, over the row', 'sides']]) {
    await tap('#c-arrPos', pos); await sec('arrows-pos-' + name);
  }
  for (const [pg, name] of [['None', 'none'], ['Progress bar', 'bar'], ['1 / 7', 'count'], ['Dots', 'dots']]) {
    await tap('#c-pag', pg); await sec('pagination-' + name);
  }
  // it moves: one press of next on each frame
  await p.evaluate(() => document.querySelectorAll('.screen .skc .nx').forEach(b => b.click()));
  await p.waitForTimeout(900); await sec('after-next');
  await tap('#c-lang', 'العربية'); await sec('arabic');
  await p.evaluate(() => document.querySelectorAll('.screen .skc .nx').forEach(b => b.click()));
  await p.waitForTimeout(900); await sec('arabic-after-next');
  await tap('#c-lang', 'English'); await tap('#c-arrM', 'Off');
  await p.screenshot({ path: join(out, 'playground-1720.png') });
}
// the playground itself at phone width: no sideways page scroll
const ph = await page(390, 844);
const sw = await ph.p.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
await ph.p.screenshot({ path: join(out, 'playground-390.png') });
console.log('playground at 390: scrollWidth', sw[0], 'innerWidth', sw[1]);
console.log('errors:', [...errors, ...ph.errors].join('\n') || 'none');
await browser.close();
