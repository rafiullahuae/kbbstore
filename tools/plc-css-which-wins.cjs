/*
 * LANE PLC — WHICH COPY OF THE GRID SKIN THE SHOP ACTUALLY RENDERS.
 *
 * The two sheets duplicate the product card. Reading their source order and
 * concluding which wins is exactly the kind of answer this repo has been wrong
 * about before, so this asks the BROWSER: it loads the real page, finds a real
 * card, and reads getComputedStyle for the properties the two copies diverge
 * on. What the engine reports is the answer; the file order is the hypothesis.
 *
 *   node tools/plc-css-which-wins.cjs            KBB_BASE=http://127.0.0.1:8981
 *
 * NOTHING HERE RUNS ON THE SHOP. This is the harness — the shipped card sizes
 * with calc() and two tests forbid the element-measuring APIs on the storefront
 * by name (CLAUDE.md, "no JavaScript that measures layout").
 */
const { chromium } = require('playwright');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8981';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';

/*
 * Pages chosen because they differ in WHICH SHEETS THEY LOAD, measured over
 * HTTP rather than assumed: `/` pushes kbb-grid-skins.css into the styles
 * stack, `/shop` does not, and both link kbb.css from the layout. If that ever
 * stops being true the sheet census below says so before any number is read.
 */
const PAGES = ['/', '/shop'];

/*
 * The properties the parse says the two copies disagree about, each with the
 * sheet that declares it and what it declares. A page that loads only kbb.css
 * must report the FALLBACK for a grid-skins-only property, and a page that
 * loads both must report the declared value.
 */
const PROBES = [
  { sel: '.kbb-pgrid .kbb-card-price', prop: 'color', only: 'kbb-grid-skins.css', rule: 'color:var(--kbb-price,#2A2228)' },
  { sel: '.kbb-pgrid .kbb-card-cart', prop: 'background-color', only: 'kbb-grid-skins.css', rule: 'background:var(--kbb-cart-bg,#E0567B)' },
  { sel: '.kbb-pgrid .kbb-cstar.on', prop: 'color', only: 'kbb-grid-skins.css', rule: 'color:var(--kbb-star,#E8A33D)' },
  { sel: '.kbb-pgrid .kbb-card-thumb', prop: 'aspect-ratio', only: 'kbb-grid-skins.css', rule: 'aspect-ratio:var(--kbb-ratio,1/1)' },
  { sel: '.kbb-pgrid .cn', prop: '-webkit-line-clamp', only: 'kbb-grid-skins.css', rule: '-webkit-line-clamp:var(--kbb-name-lines,99)' },
  { sel: '.kbb-pgrid .cn', prop: 'display', only: 'kbb-grid-skins.css', rule: 'display:-webkit-box' },
  { sel: '.kbb-pgrid .kbb-card-brand', prop: 'display', only: 'kbb-grid-skins.css', rule: 'display:block' },
  { sel: '.kbb-card', prop: 'border-radius', only: 'both', rule: 'border-radius:14px / var(--kbb-radius,14px)' },
  { sel: '.kbb-card-cart', prop: 'font-family', only: 'both', rule: "font-family:'Outfit',sans-serif" },
];

/* The .cb equal-height stack: declared in kbb-grid-skins.css only. */
const FLEX_PROBE = { sel: '.kbb-pgrid .kbb-card .cb', prop: 'display', only: 'kbb-grid-skins.css', rule: 'display:flex' };

async function main() {
  const browser = await chromium.launch({ executablePath: EXE });
  const out = {};

  for (const path of PAGES) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.goto(BASE + path, { waitUntil: 'networkidle' });

    /*
     * THE SHEET CENSUS IS READ OFF document.styleSheets, NOT OFF THE HTML.
     * A <link> in the markup that 404s leaves no rules behind, and a census
     * taken from the source would report a sheet the page does not actually
     * have — the same shape as a font ruler reporting the fallback.
     */
    const sheets = await page.evaluate(() =>
      [...document.styleSheets]
        .map((s) => (s.href || 'inline').split('/').pop())
        .filter(Boolean));

    const cards = await page.evaluate(() => document.querySelectorAll('.kbb-pgrid .kbb-card').length);

    const probes = {};

    for (const probe of [...PROBES, FLEX_PROBE]) {
      probes[probe.sel + ' { ' + probe.prop + ' }'] = await page.evaluate(
        ([sel, prop]) => {
          const el = document.querySelector(sel);

          return el ? getComputedStyle(el).getPropertyValue(prop).trim() : '(no such element)';
        },
        [probe.sel, probe.prop]);
    }

    out[path] = { sheets, cards, probes };
    await page.close();
  }

  await browser.close();

  console.log(JSON.stringify(out, null, 2));

  /* The human summary: every probe where the two pages disagree. */
  const [a, b] = PAGES;
  console.log('\n════ PROPERTIES WHERE ' + a + ' AND ' + b + ' RENDER DIFFERENTLY ════\n');
  let n = 0;

  for (const key of Object.keys(out[a].probes)) {
    if (out[a].probes[key] !== out[b].probes[key]) {
      n++;
      console.log('  ' + key);
      console.log('      ' + a.padEnd(8) + ' : ' + out[a].probes[key]);
      console.log('      ' + b.padEnd(8) + ' : ' + out[b].probes[key]);
    }
  }

  console.log('\n  ' + n + ' of ' + Object.keys(out[a].probes).length + ' probed properties differ between the two pages.');
  console.log('  ' + a + ' sheets: ' + out[a].sheets.join(', '));
  console.log('  ' + b + ' sheets: ' + out[b].sheets.join(', '));
}

main().catch((e) => { console.error(e); process.exit(1); });
