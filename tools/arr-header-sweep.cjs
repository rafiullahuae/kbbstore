/*
 * Lane AR round 3 -- the CLAUDE.md header sweep, on English AND /ar.
 *
 *   node tools/arr-header-sweep.cjs <port> <tag> [outDir=docs/lane-ar-shots/round3]
 *
 * For each of three browsers --
 *   modern      the page as served
 *   old         round( and color-mix( renamed out of every stylesheet (an older
 *               Android WebView / in-app browser, as tools/lgo-shots.cjs does)
 *   blocked     the kbb stylesheet refused
 * -- and each language, every 20px from 320 to 1024: the logo box, the lotus,
 * the wordmark and the search row, read on 40 consecutive animation frames
 * (stable = all 40 identical), and scrollWidth against the width. Pictures at
 * 390, 600 and 1280 (modern), plus 390 for old and blocked.
 * Writes <outDir>/sweep-<tag>.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const [port, tag = 'run', outDir = 'docs/lane-ar-shots/round3'] = process.argv.slice(2);
const B = `http://127.0.0.1:${port}`;
const UA = 'Mozilla/5.0 (Linux; Android 12; SM-A125F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36';
fs.mkdirSync(outDir, { recursive: true });

const MEASURE = () => new Promise((resolve) => {
  const pick = (sel) => {
    const e = document.querySelector(sel);
    if (!e) return null;
    const r = e.getBoundingClientRect();
    return [Math.round(r.x * 10) / 10, Math.round(r.y * 10) / 10, Math.round(r.width * 10) / 10, Math.round(r.height * 10) / 10];
  };
  const frames = [];
  const step = () => {
    frames.push(JSON.stringify([pick('header .logo.lgx'), pick('header .logo.lgx>.lgx-i'), pick('header .logo.lgx .lgx-w'), pick('header .sbox')]));
    if (frames.length < 40) requestAnimationFrame(step);
    else {
      const [logo, icon, word, sbox] = JSON.parse(frames[0]);
      resolve({ logo, icon, word, sbox, stable: new Set(frames).size === 1, sw: document.documentElement.scrollWidth });
    }
  };
  requestAnimationFrame(step);
});

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};
  for (const mode of ['modern', 'old', 'blocked']) {
    for (const [lang, pre] of [['en', ''], ['ar', '/ar']]) {
      const ctx = await browser.newContext({ viewport: { width: 320, height: 844 }, userAgent: UA, deviceScaleFactor: 1, reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      await page.route('**/*', async (route) => {
        const u = route.request().url();
        if (!u.startsWith(B)) return route.abort();
        if (/\/build\/assets\/[^/]+\.css$/.test(u)) {
          if (mode === 'blocked' && /\/kbb-[A-Za-z0-9_-]+\.css$/.test(u)) return route.abort();
          if (mode === 'old') {
            const r = await route.fetch();
            const css = (await r.text()).replace(/\bround\(/g, 'nosuchfn(').replace(/\bcolor-mix\(/g, 'nosuchmix(');
            return route.fulfill({ response: r, body: css });
          }
        }
        return route.continue();
      });
      await page.goto(B + pre + '/', { waitUntil: 'load' });
      await page.waitForTimeout(500);
      const rows = {};
      for (let w = 320; w <= 1024; w += 20) {
        await page.setViewportSize({ width: w, height: 844 });
        await page.waitForTimeout(300);
        rows[w] = await page.evaluate(MEASURE);
      }
      for (const w of mode === 'modern' ? [390, 600, 1280] : [390]) {
        await page.setViewportSize({ width: w, height: w === 1280 ? 900 : 844 });
        await page.waitForTimeout(250);
        rows['@' + w] = await page.evaluate(MEASURE);
        await page.screenshot({ path: path.join(outDir, `header-${tag}-${mode}-${lang}-${w}.png`), clip: { x: 0, y: 0, width: w, height: w <= 900 ? 170 : 150 } });
      }
      out[`${mode} ${lang}`] = rows;
      const bad = Object.entries(rows).filter(([w, r]) => !r.stable || r.sw !== Number(String(w).replace('@', '')));
      const side = rows['@390'] && rows['@390'].icon && rows['@390'].word ? (rows['@390'].icon[0] > rows['@390'].word[0] ? 'icon RIGHT of name' : 'icon LEFT of name') : 'n/a';
      console.log(mode.padEnd(8), lang, 'widths', Object.keys(rows).length, 'unstable/overflow', bad.length ? bad.map(([w]) => w).join(',') : 'none', '|', side, '| 390 logo', JSON.stringify(rows['@390'].logo), 'icon', JSON.stringify(rows['@390'].icon));
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(outDir, `sweep-${tag}.json`), JSON.stringify(out));
})();
