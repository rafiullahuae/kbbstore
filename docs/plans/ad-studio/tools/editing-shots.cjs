// The editing tools, pictured from the built file at 1280 and 390 (Chromium):
//   shots/editing/multi-select-<w>.png    three items picked: one box, corner + turn handles, the arrange bar
//   shots/editing/group-layers-<w>.png    a group in the Layers list (folded open, its items under it)
//   shots/editing/bg-solid-<w>.png, bg-gradient-<w>.png, bg-image-<w>.png   the Background panel
//   shots/editing/colour-control-<w>.png  the colour control (hex, opacity, eyedropper, recent, design, brand)
// node tools/editing-shots.cjs
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const ROOT = path.join(__dirname, '..'), FILE = 'file://' + path.join(ROOT, 'ad-studio.html'), OUT = path.join(ROOT, 'shots', 'editing'), FIX = path.join(__dirname, 'fixtures');
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [vw, vh] of [[1280, 900], [390, 844]]) {
    const ctx = await b.newContext({ viewport: { width: vw, height: vh }, deviceScaleFactor: vw < 500 ? 2 : 1 });
    const p = await ctx.newPage(), errs = []; p.on('pageerror', (e) => errs.push(e.message)); p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
    await p.goto(FILE); await p.waitForFunction(() => document.documentElement.dataset.ready === '1'); await p.evaluate(() => document.fonts.ready);
    const S = (fn, a) => p.evaluate(fn, a), shot = async (n, el) => { if (el) await p.locator(el).first().scrollIntoViewIfNeeded(); else await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(120); await p.screenshot({ path: path.join(OUT, n + '-' + vw + '.png') }); console.log(n + '-' + vw, 'scrollWidth', await S(() => document.documentElement.scrollWidth)); };
    await S(() => window.KBB_STUDIO.useTemplate('eid-al-fitr')); await p.click('.tab[data-tab="edit"]');
    // three picked
    await p.click('#stage .el[data-id="h1"]'); await p.click('#stage .el[data-id="h2"]', { modifiers: ['Shift'] }); await p.click('#stage .el[data-id="cta"]', { modifiers: ['Shift'] });
    const m = await S(() => { const bx = document.querySelector('#stage .msbox').getBoundingClientRect(), ab = document.getElementById('abar').getBoundingClientRect(); return { box: [Math.round(bx.width), Math.round(bx.height)], abar: [Math.round(ab.width), Math.round(ab.height)], picked: window.KBB_STUDIO.selKeys() }; });
    console.log('  measured', JSON.stringify(m));
    await shot('multi-select', vw < 500 ? '#abar' : null);
    // grouped, Layers open
    await p.keyboard.press('Control+g');
    await shot('group-layers', '#layers .ly.grp');
    // the background panel: solid, gradient (a third stop added), picture
    await p.keyboard.press('Escape'); await p.click('.tab[data-tab="style"]');
    await p.click('#bg-kind button[data-v="solid"]'); await shot('bg-solid', '#bg-kind');
    await p.click('#bg-kind button[data-v="linear"]'); const g = await p.locator('#gbar').boundingBox(); await p.mouse.click(g.x + g.width * .55, g.y + g.height / 2);
    await p.click('[data-bgk~="linear"] [data-bgc="stop"]'); await p.fill('#cc-hex', '#C9A24A'); await p.press('#cc-hex', 'Enter'); await p.click('#cc-x');
    await shot('bg-gradient', '#bg-kind');
    await p.setInputFiles('#bg-file', path.join(FIX, 'backdrop.jpg')); await p.waitForFunction(() => window.KBB_STUDIO.doc.bg.k === 'image');
    await p.locator('#bg-blur').evaluate((n) => { n.value = '3'; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); });
    await p.click('[data-bgk="image"] [data-bgc="oc"]'); await p.fill('#cc-hex', '#E8306F4D'); await p.press('#cc-hex', 'Enter'); await p.click('#cc-x');
    await shot('bg-image', '#bg-kind');
    // the colour control on a text
    await p.click('.tab[data-tab="edit"]'); await p.click('#stage .el[data-id="kick"]');
    await p.click('#props [data-show~="text"] [data-cp="col"]'); await p.fill('#cc-hex', '#C9A24A'); await p.press('#cc-hex', 'Enter');
    await shot('colour-control', vw < 500 ? '#props [data-show~="text"] [data-cp="col"]' : null);
    console.log('  errors', errs.length, errs.slice(0, 3));
    await ctx.close();
  }
  await b.close();
})();
