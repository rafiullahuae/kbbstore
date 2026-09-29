/*
 * The member-name underline on the set contents list, shot and MEASURED.
 * (Lane FIN2)
 *
 * The defect: `a.ksl-nm` is underlined with `border-bottom`, and a border on an
 * inline box is painted on its FIRST FRAGMENT only -- so a member name that
 * wraps to two lines is underlined under line one and not line two, which at
 * 390px reads as a rule struck through the middle of the name.
 *
 * The measurement that proves it without eyes: Range.getClientRects() over the
 * link's own text returns one rect per LINE BOX. A correctly underlined link
 * has an underline under every one of them. This script reports, per member:
 * the number of line boxes, and -- for the border spelling -- which line the
 * single painted border sits on.
 *
 * THE MEASURING HAPPENS IN THE CAMERA AND NOT ON THE PAGE. CLAUDE.md forbids
 * JavaScript that measures layout in the shop; this file is a Puppeteer script
 * that never ships.
 *
 * Usage: node tools/fin2-underline-shots.cjs <port> <label> <outdir>
 */
const { chromium } = require('playwright');

const PORT = process.argv[2] || '8947';
const LABEL = process.argv[3] || 'now';
const OUT = process.argv[4] || 'storage/fin2-logs';
const URL = `http://127.0.0.1:${PORT}/product/spl-glow-ritual-set/`;

(async () => {
  /* This container carries playwright's browsers at build 1194 while the
     package in node_modules wants a newer one, so the binary is named rather
     than resolved. KBB_CHROMIUM overrides it on a host where it moves. */
  const browser = await chromium.launch({
    executablePath:
      process.env.KBB_CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
  });

  const report = {};

  for (const width of [390, 1280]) {
    const page = await browser.newPage({
      viewport: { width, height: 900 },
      deviceScaleFactor: 2,
    });
    await page.goto(URL, { waitUntil: 'networkidle' });

    const panel = await page.$('.ksl');
    if (!panel) throw new Error('no .ksl panel on ' + URL);

    const measured = await page.evaluate(() => {
      const out = [];
      document.querySelectorAll('a.ksl-nm').forEach((a) => {
        const cs = getComputedStyle(a);
        const r = document.createRange();
        r.selectNodeContents(a);
        const rects = Array.from(r.getClientRects()).map((x) => ({
          top: Math.round(x.top * 10) / 10,
          bottom: Math.round(x.bottom * 10) / 10,
          width: Math.round(x.width * 10) / 10,
        }));
        const box = a.getBoundingClientRect();
        out.push({
          text: (a.textContent || '').trim().slice(0, 44),
          lineBoxes: rects.length,
          fontSize: cs.fontSize,
          borderBottomWidth: cs.borderBottomWidth,
          borderBottomColor: cs.borderBottomColor,
          textDecorationLine: cs.textDecorationLine,
          textDecorationColor: cs.textDecorationColor,
          // Where the ONE painted border sits, for the border spelling: the
          // bottom edge of the whole inline box. With two line boxes the
          // border is on the first, so this sits ABOVE the last line's bottom.
          inlineBoxBottom: Math.round(box.bottom * 10) / 10,
          lastLineBottom: rects.length ? rects[rects.length - 1].bottom : null,
          firstLineBottom: rects.length ? rects[0].bottom : null,
        });
      });
      return {
        names: out,
        scrollWidth: document.documentElement.scrollWidth,
        panelHeight: Math.round(document.querySelector('.ksl').getBoundingClientRect().height),
      };
    });

    report[width] = measured;

    await panel.screenshot({ path: `${OUT}/fin2-setbox-${LABEL}-${width}.png` });
    await page.close();
  }

  require('fs').writeFileSync(
    `${OUT}/fin2-underline-${LABEL}.json`,
    JSON.stringify(report, null, 2)
  );

  for (const w of [390, 1280]) {
    const wrapped = report[w].names.filter((n) => n.lineBoxes > 1);
    console.log(
      `${LABEL} @${w}: scrollWidth=${report[w].scrollWidth} panel=${report[w].panelHeight}px ` +
        `names=${report[w].names.length} wrapped=${wrapped.length} ` +
        `decoration=${report[w].names[0].textDecorationLine} ` +
        `border=${report[w].names[0].borderBottomWidth}`
    );
    wrapped.forEach((n) =>
      console.log(
        `    "${n.text}" lines=${n.lineBoxes} firstLineBottom=${n.firstLineBottom} ` +
          `lastLineBottom=${n.lastLineBottom} inlineBoxBottom=${n.inlineBoxBottom}`
      )
    );
  }

  await browser.close();
})();
