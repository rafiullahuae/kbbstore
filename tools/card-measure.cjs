/*
 * LANE CARD — read the card's real geometry off a rendered page.
 *
 * The owner: "make sure the grid must remain same heighted overall even if the
 * name of the product is long. i need all equal height in desktop and mobile
 * both."
 *
 * "Overall" is the word this tool exists for. `height:100%` on a grid item
 * equalises a card against the OTHER CARDS IN ITS OWN ROW and says nothing
 * about the row above it, so a measurement that reports one number per row --
 * or that reports twelve numbers from a fixture whose every row happens to
 * hold a reviewed product -- cannot tell "equal" from "equal by accident".
 *
 * So every tile is measured, each one is stamped with the ROW it is in (its
 * rounded top offset, which is what a grid row is), and the summary prints the
 * set of distinct card heights per row AND across the whole grid. One height in
 * the whole grid is the claim; anything else is printed as a failure with the
 * rows that disagree named.
 *
 * NOTHING HERE RUNS ON THE SHOP. This is the harness: the shipped card sizes
 * with calc() and there is no JavaScript on the storefront that measures
 * layout -- two tests forbid the element-measuring APIs by name.
 *
 *   node tools/card-measure.cjs <path> [widths...]     KBB_BASE=http://…
 */
const { chromium } = require('playwright');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8931';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const PATHNAME = process.argv[2] || '/collections/skincare-sets/';
const WIDTHS = (process.argv.slice(3).length ? process.argv.slice(3) : ['390', '1280']).map(Number);
const JSON_OUT = process.env.KBB_JSON || '';

/* THE PAGE IS SCROLLED TO THE BOTTOM BEFORE ANYTHING IS MEASURED.
   Every tile but the first is loading="lazy", and a browser does not fetch an
   image it has decided it does not need yet -- tools/pg2-names-shot.cjs carries
   the same note. An undecoded photograph cannot move THIS card (the frame is an
   aspect-ratio box reserved in CSS), but a lazy image is also the difference
   between a screenshot with pictures in it and one with pale rectangles. */
async function settle(page) {
  await page.evaluate(async () => {
    const step = Math.floor(window.innerHeight * 0.8);

    for (let y = 0; y < document.body.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 60));
    }

    window.scrollTo(0, 0);
  });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(250);
}

async function measure(page) {
  return page.evaluate(() => {
    const px = (n) => Math.round(n * 100) / 100;
    const grids = [...document.querySelectorAll('.kbb-pgrid, #grid, .rel')].filter((g) =>
      g.querySelector('.kbb-tile')
    );

    const out = grids.map((grid) => {
      const tiles = [...grid.querySelectorAll('.kbb-tile')];

      /* ── A TILE WITH NO BOX AT ALL IS NOT A TILE OF A DIFFERENT HEIGHT ──
         partials/home/grid-section.blade.php prints `count` cards and hides the
         ones past `mobile_count` with `.gs-d-only{display:none}` on a phone --
         the owner's BEST SELLERS preset is ten on a desktop and six on a phone.
         A display:none element's getBoundingClientRect() is all zeros, so those
         four arrived here as "height 0" tiles in a row at y=0, and the summary
         read `0, 336.08  ◀ NOT EQUAL` on a grid that is in fact uniform. They
         are counted and named instead. */
      const shown = tiles.filter((t) => t.getBoundingClientRect().height > 0);
      const hidden = tiles.length - shown.length;

      const rows = shown.map((t) => {
        const box = (sel) => {
          const e = t.querySelector(sel);

          if (!e) return null;

          const b = e.getBoundingClientRect();

          return { w: px(b.width), h: px(b.height) };
        };
        const b = t.getBoundingClientRect();

        return {
          name: (t.querySelector('.kbb-card-nm')?.textContent || '').trim(),
          chars: (t.querySelector('.kbb-card-nm')?.textContent || '').trim().length,
          card: px(b.height),
          width: px(b.width),
          top: Math.round(b.top + window.scrollY),
          /* THE NAME BOX AND NOT THE TEXT. `.cn` is the link and holds the
             brand line too; `.kbb-card-nm` is the name's own clamped box, which
             is the thing that is supposed to be the same on every tile. */
          nameBox: box('.kbb-card-nm'),
          cn: box('.cn'),
          brand: box('.kbb-card-brand'),
          cat: box('.kbb-card-cat'),
          rate: box('.kbb-card-rate'),
          price: box('.cp'),
          button: box('.kbb-card-cart'),
          shot: box('.kbb-card-shot'),
          /* The photograph's frame under the showcase family is the <a>, NOT
             `.kbb-card-thumb` -- the thumb is display:contents there and a
             probe reading it reports 0x0 on exactly the skins it exists to
             check. tools/pg2-shoot.sh carries the same note. */
          thumb: box('.kbb-card-thumb'),
          heart: box('.heart'),
        };
      });

      const byRow = {};

      for (const r of rows) {
        (byRow[r.top] ||= []).push(r.card);
      }

      return {
        selector: grid.className || grid.id,
        skin: grid.getAttribute('data-skin'),
        tiles: rows.length,
        hidden,
        columns: Object.values(byRow)[0]?.length ?? 0,
        rowHeights: Object.fromEntries(
          Object.entries(byRow).map(([top, hs]) => [top, [...new Set(hs)]])
        ),
        distinctCardHeights: [...new Set(rows.map((r) => r.card))].sort((a, b) => a - b),
        /* ── AND THE SAME SET TO A TENTH OF A PIXEL ─────────────────────────
           A five-column grid on a 1280 viewport has a FRACTIONAL track width,
           so the square photograph resolves to 230.80px in four columns and
           230.81px in the fifth; a row that is not full takes the smaller
           figure and its cards come out 0.02px shorter than the rows above.
           That is subpixel rounding of the track, not a layout difference --
           it was there on the shipped card too (453.89 against 453.91) and it
           is a fiftieth of a pixel. Reporting both is what keeps "one height"
           an honest claim rather than a rounded one. */
        distinctCardHeightsTenth: [
          ...new Set(rows.map((r) => Math.round(r.card * 10) / 10)),
        ].sort((a, b) => a - b),
        distinctNameBoxes: [...new Set(rows.map((r) => r.nameBox?.h ?? null))].sort(),
        rows,
      };
    });

    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      bodyClass: document.body.className,
      grids: out,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const all = {};

  for (const w of WIDTHS) {
    const page = await browser.newPage({ viewport: { width: w, height: 1000 } });
    const res = await page.goto(BASE + PATHNAME, { waitUntil: 'networkidle' });

    if (!res || res.status() !== 200) {
      console.log(`${PATHNAME} @${w}: HTTP ${res ? res.status() : 'none'} — NOT MEASURED`);
      await page.close();
      continue;
    }

    await settle(page);

    const m = await measure(page);

    all[w] = m;

    console.log(`\n══ ${PATHNAME}  @${w}px ══`);
    console.log(
      `   scrollWidth ${m.scrollWidth} / clientWidth ${m.clientWidth}` +
        (m.scrollWidth > m.clientWidth ? '   ◀ OVERFLOWS' : '   (no overflow)')
    );
    console.log(`   body.class  ${m.bodyClass.trim() || '(none)'}`);

    if (!m.grids.length) {
      console.log('   no product grid on this page');
    }

    for (const g of m.grids) {
      console.log(
        `   grid [${g.selector}] skin=${g.skin} tiles=${g.tiles} columns=${g.columns}` +
          (g.hidden ? `  (+${g.hidden} hidden at this width)` : '')
      );
      console.log(
        `   card heights across the WHOLE grid: ${g.distinctCardHeights.join(', ')}` +
          (g.distinctCardHeights.length === 1 ? '   ✓ one height' : '   ◀ NOT EQUAL')
      );
      console.log(
        `   …to a tenth of a pixel: ${g.distinctCardHeightsTenth.join(', ')}` +
          (g.distinctCardHeightsTenth.length === 1 ? '   ✓ one height' : '   ◀ NOT EQUAL')
      );
      console.log(`   name boxes: ${g.distinctNameBoxes.join(', ')}`);

      for (const [top, hs] of Object.entries(g.rowHeights)) {
        console.log(`     row y=${top}: ${hs.join(', ')}`);
      }

      for (const r of g.rows) {
        console.log(
          `     ${String(r.card).padStart(7)}  ` +
            `name ${String(r.nameBox?.h ?? '-').padStart(6)} (${String(r.chars).padStart(3)}ch)  ` +
            `cn ${String(r.cn?.h ?? '-').padStart(6)}  ` +
            `brand ${String(r.brand?.h ?? '-').padStart(6)}  ` +
            `cat ${String(r.cat?.h ?? '-').padStart(6)}  ` +
            `rate ${String(r.rate?.h ?? '-').padStart(6)}  ` +
            `price ${String(r.price?.h ?? '-').padStart(6)}  ` +
            `btn ${String(r.button?.w ?? '-')}x${String(r.button?.h ?? '-')}  ` +
            `shot ${String(r.shot?.w ?? '-')}x${String(r.shot?.h ?? '-')}  ` +
            `${r.name.slice(0, 44)}`
        );
      }
    }

    await page.close();
  }

  await browser.close();

  if (JSON_OUT) {
    require('fs').writeFileSync(JSON_OUT, JSON.stringify(all, null, 1));
    console.log(`\nwrote ${JSON_OUT}`);
  }
})();
