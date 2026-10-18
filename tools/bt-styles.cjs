/*
 * Lane BT: the normal visitor's computed-style proof. For each page and
 * width, loads the page twice in plain Chromium -- once with the guard's
 * <script> cut out of the HTML (the "Let the phone enlarge the text" bytes,
 * which BigTextGuardTest proves are the base tree's) and once as served -- and
 * compares EVERY computed property of EVERY element, in document order. Each
 * side is loaded twice and a property that differs between two loads of the
 * same side (an animation mid-flight, a timer) is reported as noise, by name.
 *
 *   BASE=http://127.0.0.1:<port> node tools/bt-styles.cjs [out.json]
 */
const fs = require('fs');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const BASE = process.env.BASE || 'http://127.0.0.1:9732';
const OPEN = '<script style="font-size:16px">';
const PAGES = [['home', '/'], ['category', '/collections/toners/'], ['shop', '/shop/'], ['brand', '/brands/anua/'],
  ['product', '/product/azelaic-10-hyaluron-redness-soothing-pad/'], ['blog', '/blog/'], ['article', '/blog/how-to-layer-toner-pads/'], ['cart', '/cart/'], ['checkout', '/checkout/']];

async function grab(b, url, w, strip) {
  const ctx = await b.newContext({ viewport: { width: w, height: 900 }, isMobile: w < 800, hasTouch: w < 800, reducedMotion: 'reduce',
    userAgent: 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0 Mobile Safari/537.36 Instagram 350.0' });
  const p = await ctx.newPage();
  await p.goto(BASE + '/product/azelaic-10-hyaluron-redness-soothing-pad/', { waitUntil: 'networkidle' });
  await p.evaluate(async () => { for (const id of [1, 3]) await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) }); });
  if (strip) {
    await p.route((u) => u.href === BASE + url, async (route) => {
      const r = await route.fetch(); let h = await r.text();
      const at = h.indexOf(OPEN); if (at > -1) h = h.slice(0, at) + h.slice(h.indexOf('</script>', at) + 10);
      return route.fulfill({ response: r, body: h });
    });
  }
  await p.goto(BASE + url, { waitUntil: 'load' });
  await p.waitForTimeout(1500);
  const rows = await p.evaluate(() => [...document.querySelectorAll('*')].filter((e) => !(e.tagName === 'SCRIPT' && e.getAttribute('style') === 'font-size:16px')).map((e) => {
    const c = getComputedStyle(e); const o = {};
    for (let i = 0; i < c.length; i++) o[c[i]] = c.getPropertyValue(c[i]);
    return [e.tagName + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className ? '.' + e.className.trim().split(/\s+/)[0] : ''), o];
  }));
  await ctx.close();
  return rows;
}

function cmp(a, b) {
  const out = new Set();
  if (a.length !== b.length) { out.add('ELEMENT COUNT ' + a.length + ' vs ' + b.length); return out; }
  for (let i = 0; i < a.length; i++) for (const k of Object.keys(a[i][1])) if (a[i][1][k] !== b[i][1][k]) out.add(a[i][0] + ' ' + k);
  return out;
}

(async () => {
  const b = await pw.chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const res = {};
  for (const lang of ['en', 'ar']) for (const w of [390, 1280]) for (const [name, url0] of PAGES) {
    if (process.env.ONLY && !process.env.ONLY.split(',').includes(name)) continue;
    const url = (lang === 'ar' ? '/ar' : '') + url0;
    const b1 = await grab(b, url, w, true), b2 = await grab(b, url, w, true), a1 = await grab(b, url, w, false);
    const noise = cmp(b1, b2), real = [...cmp(b1, a1)].filter((x) => !noise.has(x));
    res[`${name}-${lang}-${w}`] = { elements: a1.length, properties: a1.reduce((n, r) => n + Object.keys(r[1]).length, 0), differing: real, noise: [...noise] };
    console.log(`${name}-${lang}-${w}`, a1.length, 'elements, differing:', real.length, real.slice(0, 5).join(' | '), ' noise:', noise.size, [...noise].slice(0, 3).join(' | '));
  }
  await b.close();
  fs.writeFileSync(process.argv[2] || 'bt-styles.json', JSON.stringify(res, null, 1));
})();
