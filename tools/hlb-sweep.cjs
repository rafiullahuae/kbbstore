/* Lane HLB -- the header-freeze sweep CLAUDE.md owes for a header change.
   BEFORE = a preview of HEAD without this lane (BASE), AFTER = this worktree
   (NEW), both tools/arr-preview.sh (Arabic on, mirrored). For English and
   Arabic, in three browsers -- modern, "old" (round() and color-mix() renamed
   out of every stylesheet, as a browser without them reads it) and "blocked"
   (no stylesheet at all) -- at every 20px from 320 to 1024 and at 1280:
   the logo box, the lotus and the search row, sampled over 40 frames, and
   scrollWidth against the width.

   Usage: BASE=http://127.0.0.1:9580 NEW=http://127.0.0.1:9581 node tools/hlb-sweep.cjs <out.json> */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:9580';
const NEW = process.env.NEW || 'http://127.0.0.1:9581';
const OUT = process.argv[2] || 'hlb-sweep.json';
const WIDTHS = []; for (let w = 320; w <= 1024; w += 20) WIDTHS.push(w); WIDTHS.push(1280);

async function sample(p) {
  return p.evaluate(async () => {
    const box = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return [b.left, b.top, b.width, b.height].map((v) => Math.round(v * 10) / 10).join(','); };
    const frames = [];
    for (let i = 0; i < 40; i++) {
      await new Promise((r) => requestAnimationFrame(r));
      frames.push([box('header .logo'), box('header .logo .lgx-i'), box('header .sbox')].join('|'));
    }
    const vis = (s) => { const e = document.querySelector(s); return !!(e && getComputedStyle(e).display !== 'none'); };
    const svgMax = Math.max(0, ...[...document.querySelectorAll('header svg')].map((s) => s.getBoundingClientRect().width));
    const newSvg = [...document.querySelectorAll('header .lsw-g')].map((s) => { const r = s.getBoundingClientRect(); return Math.round(r.width * 10) / 10 + 'x' + Math.round(r.height * 10) / 10; }).join(' ');
    return { newSvg, first: frames[0], stable: frames.every((f) => f === frames[0]), sw: document.documentElement.scrollWidth, pill: vis('header .lsw-d'), capsule: vis('header .lsw-m'), heart: vis('header .hact a[href*="my-wishlist"]'), svgMax: Math.round(svgMax) };
  });
}

(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium' });
  const rows = [];
  for (const mode of ['modern', 'old', 'blocked']) for (const here of ['en', 'ar']) for (const [side, host] of [['before', BASE], ['after', NEW]]) {
    const ctx = await b.newContext({ viewport: { width: WIDTHS[0], height: 800 } });
    const p = await ctx.newPage();
    if (mode !== 'modern') {
      await p.route('**/*.css', async (route) => {
        if (mode === 'blocked') return route.abort();
        const r = await route.fetch(); const t = (await r.text()).replace(/round\(/g, 'xround(').replace(/color-mix\(/g, 'xcolor-mix(');
        return route.fulfill({ response: r, body: t, headers: { ...r.headers(), 'content-type': 'text/css' } });
      });
    }
    await p.goto(host + (here === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
    for (const w of WIDTHS) {
      await p.setViewportSize({ width: w, height: 800 });
      await p.waitForTimeout(500); // past the .2s icon transitions a resize starts
      rows.push({ mode, here, side, w, ...(await sample(p)) });
    }
    await ctx.close();
  }
  await b.close();
  fs.writeFileSync(OUT, JSON.stringify(rows, null, 1));

  // Before vs after, per mode/language/width.
  const key = (r) => `${r.mode}|${r.here}|${r.w}`;
  const before = new Map(rows.filter((r) => r.side === 'before').map((r) => [key(r), r]));
  const sum = {};
  for (const a of rows.filter((r) => r.side === 'after')) {
    const z = before.get(key(a)); const k = `${a.mode}|${a.here}`;
    const s = (sum[k] ||= { widths: 0, logoSame: 0, lotusSame: 0, searchSame: 0, searchRowSame: 0, stable: 0, swOk: 0, diffs: [] });
    const [lA, iA, sA] = a.first.split('|'); const [lB, iB, sB] = z.first.split('|');
    s.widths++;
    if (lA === lB) s.logoSame++; else s.diffs.push(`${a.w} logo ${lB} -> ${lA}`);
    if (iA === iB) s.lotusSame++; else s.diffs.push(`${a.w} lotus ${iB} -> ${iA}`);
    if (sA === sB) s.searchSame++; else s.diffs.push(`${a.w} search ${sB} -> ${sA}`);
    const row = (x) => (x || '').split(',').slice(1, 2).concat((x || '').split(',').slice(3)).join(',');
    if (row(sA) === row(sB)) s.searchRowSame++;
    if (a.stable && z.stable) s.stable++;
    if (a.sw === a.w) s.swOk++;
  }
  console.log(JSON.stringify(sum, null, 1));
})();
