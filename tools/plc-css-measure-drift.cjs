/*
 * LANE PLC — WHAT THE DRIFT BETWEEN THE TWO GRID SHEETS ACTUALLY RENDERS AS.
 *
 * tools/plc-css-sides.php --json lists every declaration one copy of the card
 * carries and the other does not. This takes that list to a BROWSER and reads
 * getComputedStyle for each of them on a page that loads both sheets and on a
 * page that loads only kbb.css.
 *
 * The point is that "which copy wins" is not answerable from source order.
 * Specificity, custom-property fallbacks, a later rule in the same sheet and a
 * media query all outrank the order the <link>s appear in, and this repo has
 * already paid for a column count that was declared in six places and won from
 * the one nobody was reading. So the probe list is GENERATED from the parse —
 * not typed by hand, which can only find what its author already suspected —
 * and the engine gives the verdict.
 *
 *   php tools/plc-css-sides.php --json > storage/plc-logs/plc-divergent.json
 *   node tools/plc-css-measure-drift.cjs storage/plc-logs/plc-divergent.json
 *
 * NOTHING HERE RUNS ON THE SHOP (CLAUDE.md: no JavaScript that measures layout).
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8981';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const LIST = process.argv[2] || 'storage/plc-logs/plc-divergent.json';
const WIDTH = Number(process.env.KBB_WIDTH || 1280);

/* A page that loads BOTH sheets, and a page that loads only kbb.css. Which is
   which is not assumed — the census below reads document.styleSheets. */
const PAGES = ['/', '/shop'];

async function main() {
  const parsed = JSON.parse(fs.readFileSync(LIST, 'utf8'));

  /*
   * A declaration inside a @media block is only in force when the query
   * matches, so its context travels with it and the report says so rather than
   * reporting the 900px ladder as "missing" at 1280.
   */
  const probes = parsed.decls.map((d) => ({
    side: d.side,
    context: d.context,
    selector: d.selector,
    prop: d.prop,
    declared: d.value,
  }));

  const browser = await chromium.launch({ executablePath: EXE });
  const results = {};

  for (const path of PAGES) {
    const page = await browser.newPage({ viewport: { width: WIDTH, height: 900 } });
    await page.goto(BASE + path, { waitUntil: 'networkidle' });

    const sheets = await page.evaluate(() =>
      [...document.styleSheets].map((s) => (s.href || 'inline').split('/').pop()));

    const read = await page.evaluate((list) => list.map((p) => {
      /* The first comma part is enough to find an element; the browser
         resolves the whole rule regardless of which part matched. */
      const first = p.selector.split(',')[0];
      let el = null;

      try {
        el = document.querySelector(first);
      } catch (e) {
        return { found: false, value: '(selector not queryable: ' + e.message + ')' };
      }

      if (!el) {
        return { found: false, value: '(no such element on this page)' };
      }

      return { found: true, value: getComputedStyle(el).getPropertyValue(p.prop).trim() };
    }), probes);

    results[path] = { sheets, read };
    await page.close();
  }

  await browser.close();

  const [both, only] = PAGES;
  const rows = [];

  probes.forEach((p, i) => {
    const a = results[both].read[i];
    const b = results[only].read[i];
    rows.push({ ...p, both: a.value, onlyKbb: b.value, differs: a.found && b.found && a.value !== b.value });
  });

  const differ = rows.filter((r) => r.differs);
  const absentBoth = rows.filter((r) => !r.differs && (!results[both].read[probes.indexOf(r)] || r.both.startsWith('(')) );

  console.log('sheets on ' + both + ' : ' + results[both].sheets.join(', '));
  console.log('sheets on ' + only + ' : ' + results[only].sheets.join(', '));
  console.log('');
  console.log('divergent declarations probed        : ' + rows.length);
  console.log('  render DIFFERENTLY on the two pages: ' + differ.length);
  console.log('  render the SAME on both            : ' + rows.filter((r) => !r.differs && !r.both.startsWith('(')).length);
  console.log('  selector matches nothing on a page : ' + rows.filter((r) => r.both.startsWith('(') || r.onlyKbb.startsWith('(')).length);

  console.log('\n════ DECLARATIONS THAT RENDER DIFFERENTLY ════\n');

  for (const r of differ) {
    console.log('  [' + r.side + ' only] ' + (r.context ? r.context + ' :: ' : '') + r.selector + ' { ' + r.prop + ' }');
    console.log('      declared : ' + r.declared);
    console.log('      ' + both.padEnd(6) + '   : ' + r.both);
    console.log('      ' + only.padEnd(6) + '   : ' + r.onlyKbb);
  }

  if (process.env.KBB_JSON) {
    fs.writeFileSync(process.env.KBB_JSON, JSON.stringify(rows, null, 2));
    console.log('\nrows written to ' + process.env.KBB_JSON);
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
