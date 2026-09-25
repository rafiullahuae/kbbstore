/*
 * Measure the article-address page at a phone and a desktop width — Lane U3.
 *
 *   node tools/u3-measure.cjs <url>
 *
 * Prints, per viewport, the numbers CLAUDE.md rule 2 asks for — clientWidth,
 * document.documentElement.scrollWidth, scrollHeight, the h1 and body font
 * sizes, and the size of the download button — and then every element whose
 * CONTENT is wider than its box.
 *
 * That second list is the one that earns its keep. The page first measured
 * scrollWidth 543 at a 390px viewport while no element's bounding rect
 * exceeded 391, because the overflow was a single unbreakable token — a
 * percent-encoded Arabic permalink — inside a paragraph whose box was already
 * the right width. A rect-based probe reports nothing at all in that case; the
 * scrollWidth/clientWidth comparison names the paragraph. The fix was
 * `overflow-wrap:anywhere`, in CSS, once, at render time.
 *
 * Read-only. It renders a file:// or http:// URL and writes nothing.
 */
const { chromium } = require('playwright');
(async () => {
  const url = process.argv[2];
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  for (const width of [390, 1280]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.goto(url, { waitUntil: 'networkidle' });

    const out = await page.evaluate(() => {
      const el = document.documentElement;
      const h1 = document.querySelector('h1');
      const link = document.querySelector('.card a.btn');
      const overflowing = [];

      document.querySelectorAll('*').forEach(node => {
        if (node.scrollWidth > node.clientWidth + 1 && node.scrollWidth > document.documentElement.clientWidth) {
          overflowing.push({
            tag: node.tagName,
            cls: String(node.className).slice(0, 24),
            scrollWidth: node.scrollWidth,
            clientWidth: node.clientWidth,
            text: (node.textContent || '').trim().slice(0, 48),
          });
        }
      });

      return {
        clientWidth: el.clientWidth,
        scrollWidth: el.scrollWidth,
        scrollHeight: el.scrollHeight,
        h1FontSize: h1 ? getComputedStyle(h1).fontSize : null,
        bodyFontSize: getComputedStyle(document.body).fontSize,
        cards: document.querySelectorAll('.card').length,
        rows: document.querySelectorAll('tr.row').length,
        downloadButton: link
          ? Math.round(link.getBoundingClientRect().width) + 'x' + Math.round(link.getBoundingClientRect().height)
          : null,
        overflowing: overflowing.slice(0, 10),
      };
    });

    console.log(width + 'px ' + JSON.stringify(out));
    await page.close();
  }

  await browser.close();
})();
