/* Lane BT -- the header-freeze sweep CLAUDE.md owes (this brief touches what
   the header looks like on an enlarged phone). tools/hlb-sweep.cjs's method:
   the logo box, the lotus and the search row sampled over 40 frames, and
   scrollWidth against the width, at every 20px from 320 to 1024 and at 1280,
   English and Arabic, in three browsers -- modern, "old" (round() and
   color-mix() renamed out of every stylesheet) and "blocked" (no stylesheet).

   PART A, the normal visitor: BEFORE is the page with the guard's <script>
   cut out of the HTML (the "Let the phone enlarge the text" bytes, which
   BigTextGuardTest proves are the base tree's), AFTER is the page as served.
   PART B, the enlarged visitor: with ZOOM=1.6 (or 1.3 / 2), every renderer
   under tools/bt/renderer-gdb.sh (the real text zoom), AFTER at that zoom
   against the same page at zoom 1 -- the header must be the normal one.

   BASE=http://127.0.0.1:<port> [ZOOM=1.6] node tools/bt-sweep.cjs <out.json> */
const path = require('path');
const fs = require('fs');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const BASE = process.env.BASE || 'http://127.0.0.1:9732';
const ZOOM = process.env.ZOOM || '';
const OUT = process.argv[2] || 'bt-sweep.json';
const OPEN = '<script style="font-size:16px">';
const WIDTHS = []; for (let w = 320; w <= 1024; w += 20) WIDTHS.push(w); WIDTHS.push(1280);

async function sample(p) {
  return p.evaluate(async () => {
    const box = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return [b.left, b.top, b.width, b.height].map((v) => Math.round(v * 10) / 10).join(','); };
    const frames = [];
    for (let i = 0; i < 40; i++) {
      await new Promise((r) => requestAnimationFrame(r));
      frames.push([box('header .logo'), box('header .logo .lgx-i'), box('header .sbox')].join('|'));
    }
    return { first: frames[0], stable: frames.every((f) => f === frames[0]), sw: document.documentElement.scrollWidth, cls: document.documentElement.className };
  });
}

function launch(z) {
  const o = { executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', timeout: 240000 };
  if (z) { o.env = { ...process.env, BT_TEXT_ZOOM: z }; o.args = ['--renderer-cmd-prefix=' + path.join(__dirname, 'bt', 'renderer-gdb.sh'), '--no-zygote', '--no-sandbox']; }
  return pw.chromium.launch(o);
}

async function pass(b, mode, here, strip) {
  const ctx = await b.newContext({ viewport: { width: WIDTHS[0], height: 800 } });
  const p = await ctx.newPage();
  p.setDefaultTimeout(240000);
  if (mode !== 'modern') {
    await p.route('**/*.css', async (route) => {
      if (mode === 'blocked') return route.abort();
      const r = await route.fetch(); const t = (await r.text()).replace(/round\(/g, 'xround(').replace(/color-mix\(/g, 'xcolor-mix(');
      return route.fulfill({ response: r, body: t, headers: { ...r.headers(), 'content-type': 'text/css' } });
    });
  }
  if (strip) {
    await p.route((u) => u.href === BASE + (here === 'ar' ? '/ar/' : '/'), async (route) => {
      const r = await route.fetch(); let h = await r.text();
      const at = h.indexOf(OPEN); if (at > -1) h = h.slice(0, at) + h.slice(h.indexOf('</script>', at) + 10);
      return route.fulfill({ response: r, body: h });
    });
  }
  await p.goto(BASE + (here === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
  const rows = [];
  for (const w of WIDTHS) {
    await p.setViewportSize({ width: w, height: 800 });
    await p.waitForTimeout(500);
    rows.push({ w, ...(await sample(p)) });
  }
  await ctx.close();
  return rows;
}

function compare(A, B) {
  const s = { widths: 0, logoSame: 0, lotusSame: 0, searchSame: 0, stable: 0, swOk: 0, diffs: [] };
  A.forEach((a, i) => {
    const z = B[i]; const [lA, iA, sA] = a.first.split('|'); const [lB, iB, sB] = z.first.split('|');
    s.widths++;
    if (lA === lB) s.logoSame++; else s.diffs.push(`${a.w} logo ${lB} -> ${lA}`);
    if (iA === iB) s.lotusSame++; else s.diffs.push(`${a.w} lotus ${iB} -> ${iA}`);
    if (sA === sB) s.searchSame++; else s.diffs.push(`${a.w} search ${sB} -> ${sA}`);
    if (a.stable && z.stable) s.stable++;
    if (a.sw === a.w) s.swOk++;
  });
  return s;
}

(async () => {
  const sum = {};
  const raw = {};
  if (!ZOOM) {
    const b = await launch('');
    for (const mode of ['modern', 'old', 'blocked']) for (const here of ['en', 'ar']) {
      const before = await pass(b, mode, here, true);
      const after = await pass(b, mode, here, false);
      raw[`${mode}|${here}`] = { before, after };
      sum[`A normal visitor ${mode}|${here}`] = compare(after, before);
    }
    await b.close();
  } else {
    for (const here of ['en', 'ar']) {
      const b1 = await launch('1'); const normal = await pass(b1, 'modern', here, false); await b1.close();
      const bz = await launch(ZOOM); const big = await pass(bz, 'modern', here, false); await bz.close();
      raw[`z${ZOOM}|${here}`] = { normal, big };
      sum[`B text zoom ${ZOOM} vs normal phone, modern|${here}`] = compare(big, normal);
      sum[`B text zoom ${ZOOM} vs normal phone, modern|${here}`].kbbBtClass = big.every((r) => r.cls.includes('kbb-bt'));
    }
  }
  fs.writeFileSync(OUT, JSON.stringify({ sum, raw }, null, 1));
  console.log(JSON.stringify(sum, null, 1));
})();
