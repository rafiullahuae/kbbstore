/*
 * Lane FA: photograph each footer app row (tools/fa-options.cjs) INSIDE the
 * real footer of a booted preview (tools/fa-preview.sh), and measure it.
 *
 *   node tools/fa-shots.cjs http://127.0.0.1:<port> docs/fa-preview
 *
 * Writes <out>/shots/*.png and <out>/measure.json. The row is inserted as the
 * LAST child of footer.kft (below the copyright + payments row) unless the shot
 * says "above", where it goes in front of .kft-bot. D sits over the bottom of
 * the wordmark, so its default home is just after .kft-name.
 * The floating WhatsApp bubble (#kbbWa) is hidden for the comparison shots and
 * left in for the two "as it really sits" shots, which is the point of those.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const O = require('./fa-options.cjs');

const [base, out] = process.argv.slice(2);
const SH = out + '/shots';
fs.mkdirSync(SH, { recursive: true });

async function place(page, { letter, lang, line, where, wa, sheet, qr }) {
  await page.evaluate(({ css, html, where, wa, sheet, sheetCss, qr }) => {
    const st = document.createElement('style');
    st.textContent = css + sheetCss + (wa ? '' : '#kbbWa{display:none!important}');
    document.head.appendChild(st);
    const f = document.querySelector('footer.kft');
    const t = document.createElement('template');
    t.innerHTML = html;
    const row = t.content.firstElementChild;
    if (where === 'name') {
      const n = f.querySelector('.kft-name');
      n.parentNode.insertBefore(row, n.nextSibling);
    } else if (where === 'above') {
      f.insertBefore(row, f.querySelector('.kft-bot'));
    } else {
      f.appendChild(row);
    }
    if (sheet) { const s = document.createElement('template'); s.innerHTML = sheet; document.body.appendChild(s.content.firstElementChild); }
    if (qr) { const s = document.createElement('template'); s.innerHTML = qr; document.body.appendChild(s.content.firstElementChild); }
  }, {
    css: O.CSS.base + O.CSS[letter], html: O.html(letter, lang, line, where === 'below' && letter === 'D' ? ' kfa-below' : ''),
    where, wa, sheet: sheet ? O.sheet(lang) : '', sheetCss: (sheet || qr) ? O.SHEET_CSS : '', qr: qr ? O.qr() : '',
  });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(250);
}

async function measure(page) {
  return page.evaluate(() => {
    const r = (e) => e ? Math.round(e.getBoundingClientRect().height * 10) / 10 : null;
    const row = document.querySelector('.kfa');
    const bt = row.querySelector('.kfa-bt');
    const b = row.querySelector('.kfa-tx b');
    const f = document.querySelector('footer.kft');
    return {
      row: r(row), adds: Math.round((row.getBoundingClientRect().height + parseFloat(getComputedStyle(row).marginTop)) * 10) / 10, button: r(bt), buttonW: Math.round(bt.getBoundingClientRect().width), lineFont: getComputedStyle(b).fontSize,
      lineLines: Math.round(b.getBoundingClientRect().height / parseFloat(getComputedStyle(b).lineHeight)),
      footer: r(f), clientWidth: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth,
      lastChild: f.lastElementChild.className,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = {};
  const jobs = [];
  for (const L of O.LETTERS) {
    const home = L === 'D' ? 'name' : 'below';
    for (const w of [390, 1280]) jobs.push({ letter: L, lang: 'en', line: 0, where: home, w, name: `${L}-${w}` });
  }
  // Arabic RTL for every option at 390, and two at 1280.
  for (const L of O.LETTERS) jobs.push({ letter: L, lang: 'ar', line: 0, where: L === 'D' ? 'name' : 'below', w: 390, name: `${L}-390-ar` });
  for (const L of ['B', 'E']) jobs.push({ letter: L, lang: 'ar', line: 0, where: 'below', w: 1280, name: `${L}-1280-ar` });
  // Above the copyright row instead of below it.
  for (const L of ['A', 'B', 'E']) for (const w of [390, 1280]) jobs.push({ letter: L, lang: 'en', line: 0, where: 'above', w, name: `${L}-${w}-above` });
  // D the other way: below the copyright row, on its own blush backdrop.
  jobs.push({ letter: 'D', lang: 'en', line: 0, where: 'below', w: 390, name: 'D-390-below' });
  jobs.push({ letter: 'D', lang: 'en', line: 0, where: 'below', w: 1280, name: 'D-1280-below' });
  // The other two lines of every option (390, longest case for wrapping).
  for (const L of O.LETTERS) for (const line of [1, 2]) jobs.push({ letter: L, lang: 'en', line, where: L === 'D' ? 'name' : 'below', w: 390, name: `${L}-390-l${line + 1}`, noShot: true });
  for (const L of O.LETTERS) for (const line of [1, 2]) jobs.push({ letter: L, lang: 'ar', line, where: L === 'D' ? 'name' : 'below', w: 390, name: `${L}-390-ar-l${line + 1}`, noShot: true });
  // As it really sits: floating WhatsApp bubble left on, page scrolled to the end.
  jobs.push({ letter: 'A', lang: 'en', line: 0, where: 'below', w: 390, name: 'wa-below-390', wa: true, viewport: true });
  jobs.push({ letter: 'A', lang: 'en', line: 0, where: 'above', w: 390, name: 'wa-above-390', wa: true, viewport: true });
  // What the button opens: iPhone sheet (EN + AR), desktop QR panel.
  jobs.push({ letter: 'B', lang: 'en', line: 0, where: 'below', w: 390, name: 'sheet-ios-390', sheet: true, viewport: true });
  jobs.push({ letter: 'B', lang: 'ar', line: 0, where: 'below', w: 390, name: 'sheet-ios-390-ar', sheet: true, viewport: true });
  jobs.push({ letter: 'B', lang: 'en', line: 0, where: 'below', w: 1280, name: 'qr-desktop-1280', qr: true, viewport: true });

  const only = process.argv[4] ? new RegExp(process.argv[4]) : null; // e.g. '^(wa|sheet|qr)-'
  if (only && fs.existsSync(out + '/measure.json')) Object.assign(results, JSON.parse(fs.readFileSync(out + '/measure.json', 'utf8')));
  for (const j of jobs) {
    if (only && !only.test(j.name)) continue;
    const page = await browser.newPage({ viewport: { width: j.w, height: j.w === 390 ? 844 : 800 }, deviceScaleFactor: 2 });
    await page.goto(base + (j.lang === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
    await place(page, j);
    const m = await measure(page);
    results[j.name] = { ...m, letter: j.letter, lang: j.lang, where: j.where, w: j.w, line: j.line };
    if (!j.noShot) {
      await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
      // 900 ms, not 150: at 2x a viewport shot taken sooner after the jump
      // to the end came back as a blank pink frame (raster not done yet).
      await page.waitForTimeout(j.viewport ? 900 : 150);
      const file = `${SH}/${j.name}.png`;
      if (j.viewport) {
        await page.screenshot({ path: file });
      } else {
        const clip = await page.evaluate(() => {
          const f = document.querySelector('footer.kft').getBoundingClientRect();
          const n = document.querySelector('.kft-name');
          const top = (n ? n.getBoundingClientRect().top - 24 : f.top) + window.scrollY;
          return { x: 0, y: Math.max(0, top), width: document.documentElement.clientWidth, height: f.bottom + window.scrollY - Math.max(0, top) };
        });
        await page.screenshot({ path: file, clip, fullPage: true });
      }
    }
    console.log(j.name, JSON.stringify(m));
    await page.close();
  }
  fs.writeFileSync(out + '/measure.json', JSON.stringify(results, null, 1));
  await browser.close();
})();
