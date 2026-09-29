/*
 * The Lane SPL deliverable: the "What is in this set" box, as it is today and
 * under each of three proposed treatments, on the real product page.
 *
 *   node tools/spl-box-shots.cjs <baseUrl> <outDir> [locale]
 *
 * ── WHY THE TREATMENT IS APPENDED TO document.body ─────────────────────────
 *
 * Each tools/spl-box/t*.css is written to be pasted at the END of the @once
 * <style> in resources/views/partials/set-contents-panel.blade.php, so its rules
 * win over the ones already there by SOURCE ORDER at equal specificity — no
 * `!important`, no extra selector, nothing that would have to be unpicked on the
 * way in.
 *
 * That block is emitted inline in the BODY, where the partial renders. A
 * <style> added to <head> — which is what page.addStyleTag() does — comes
 * EARLIER in the document than it, so it would lose every tie and the
 * screenshots would show today's box with a few stray rules applied. Appending
 * to document.body puts it last in document order, which is exactly the
 * cascade position the paste will have. Anything that works here works there.
 *
 * ── AND WHY THE SHOP'S OWN PAGE RATHER THAN A PREVIEW TEMPLATE ─────────────
 *
 * The box lives in a 346px column at 390px and a 582px one at 1280, between a
 * price and an Add to cart button, on a page with a header, a gallery and a
 * footer. Every one of those is part of what the owner is judging. A mock-up of
 * the box on a blank page would flatter all three treatments equally and tell
 * him nothing — and it would need a route, a controller and a template, all of
 * which would then have to be deleted. This adds none of them: nothing in the
 * repository knows these three treatments exist except this script.
 *
 * ── WHAT IT MEASURES ───────────────────────────────────────────────────────
 *
 * CLAUDE.md rule 2 and rule 4. Per treatment and per width: the photograph, the
 * row padding, the name and brand sizes, the row height, the whole block's
 * height, and document.documentElement.scrollWidth. The element-measuring APIs
 * are in the CAMERA and nowhere near the page — there is no script in this
 * block at all and the fold is a <details>.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BOX = path.join(__dirname, 'spl-box');

const TREATMENTS = [
  { key: 'now', file: null, name: 'Today' },
  { key: 't1', file: 't1-filled-tint.css', name: 'Filled tint' },
  { key: 't2', file: 't2-white-card.css', name: 'White card' },
  { key: 't3', file: 't3-hanging-photos.css', name: 'Hanging photos' },
];

// The eight-member set (the box folds) and the three-member one (it does not).
const SETS = [
  { key: 'long', slug: 'spl-glow-ritual-set' },
  { key: 'short', slug: 'spl-starter-duo-set' },
];

const WIDTHS = [390, 1280];

(async () => {
  const [base, outDir, locale] = process.argv.slice(2);
  const prefix = locale === 'ar' ? '/ar' : '';
  const tag = locale === 'ar' ? 'ar-' : '';

  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch({ executablePath: CHROME });
  const measurements = {};
  const problems = [];

  for (const set of SETS) {
    for (const treatment of TREATMENTS) {
      for (const width of WIDTHS) {
        const page = await browser.newPage({
          viewport: { width, height: width === 390 ? 844 : 950 },
          deviceScaleFactor: 2,
        });

        const url = `${base}${prefix}/product/${set.slug}/`;
        const response = await page.goto(url, { waitUntil: 'networkidle' });

        if (response.status() !== 200) {
          problems.push(`${set.key}/${treatment.key} @${width}: ${url} answered ${response.status()}`);
          await page.close();
          continue;
        }

        if (treatment.file) {
          const css = fs.readFileSync(path.join(BOX, treatment.file), 'utf8');
          await page.evaluate((text) => {
            const style = document.createElement('style');
            style.setAttribute('data-spl-treatment', '1');
            style.textContent = text;
            // LAST in document order, which is the cascade position this block
            // will really have once it is pasted into the panel's own @once
            // <style>. See the header.
            document.body.appendChild(style);
          }, css);
        }

        const facts = await page.evaluate(() => {
          const px = (v) => Math.round(parseFloat(v) * 100) / 100;
          const box = document.querySelector('.ksl');

          if (!box) return { missing: true };

          const rows = [...document.querySelectorAll('.ksl .ksl-r')];
          // The rows that STAND — the ones inside the disclosure are not on the
          // page until somebody opens it, and averaging them in would report a
          // block height nobody sees.
          const standing = [...document.querySelectorAll('.ksl .ksl-rows .ksl-r')];
          const first = rows[0] || null;
          const photo = first ? first.querySelector('.ksl-ph') : null;
          const name = first ? first.querySelector('.ksl-nm') : null;
          const brand = first ? first.querySelector('.ksl-br') : null;
          const fold = document.querySelector('.ksl-more');
          const link = document.querySelector('a.ksl-nm');
          const linkBox = link ? link.getBoundingClientRect() : null;
          const boxStyle = getComputedStyle(box);

          return {
            missing: false,
            rows: rows.length,
            folds: !!fold,
            /* The panel as an OBJECT: has it got a fill, a radius, a border and
               inner padding, and how wide is it in this column. */
            boxBackground: boxStyle.backgroundColor,
            boxRadius: px(boxStyle.borderTopLeftRadius),
            boxBorderTop: px(boxStyle.borderTopWidth),
            boxShadow: boxStyle.boxShadow === 'none' ? 'none' : 'yes',
            boxPaddingBlock: px(boxStyle.paddingTop),
            boxPaddingInlineStart: px(boxStyle.paddingInlineStart),
            boxWidth: Math.round(box.getBoundingClientRect().width),
            blockHeight: Math.round(box.getBoundingClientRect().height),

            /* The squeeze.

               ROW HEIGHT IS AN AVERAGE OVER THE STANDING ROWS, not the first
               row's. At 390 the first row's name happens to fit on one line and
               the next one's does not, so "the row height" read off row 1 says
               46px for today's list and 55px for a treatment that is in fact
               shorter overall — a number that reverses the finding it is
               supposed to report. The rows are listed as well as averaged so
               the wrapping is visible rather than smoothed away. */
            photo: photo ? Math.round(photo.getBoundingClientRect().width) : null,
            rowPadding: first ? px(getComputedStyle(first).paddingTop) : null,
            rowHeight: first ? Math.round(first.getBoundingClientRect().height) : null,
            rowHeights: standing.map((r) => Math.round(r.getBoundingClientRect().height)),
            rowHeightAvg: standing.length
              ? Math.round((standing.reduce((n, r) => n + r.getBoundingClientRect().height, 0) / standing.length) * 10) / 10
              : null,
            nameSize: name ? px(getComputedStyle(name).fontSize) : null,
            brandSize: brand ? px(getComputedStyle(brand).fontSize) : null,
            rowRule: first && rows[1] ? px(getComputedStyle(rows[1]).borderTopWidth) : null,

            /* The floor: a member name is a link and keeps a real hit target. */
            linkHeight: linkBox ? Math.round(linkBox.height) : null,

            /* And the page. */
            scrollWidth: Math.round(document.documentElement.scrollWidth),
            clientWidth: document.documentElement.clientWidth,
            buyColumn: Math.round(
              (document.querySelector('.buybox') || document.body).getBoundingClientRect().width
            ),
          };
        });

        const id = `${tag}${set.key}-${treatment.key}-${width}`;

        if (facts.missing) {
          problems.push(`${id}: no .ksl box on the page at all`);
        } else {
          if (set.key === 'long' && !facts.folds) problems.push(`${id}: the eight-member set did not fold`);
          if (set.key === 'short' && facts.folds) problems.push(`${id}: the three-member set folded, which it must not`);
          if (facts.scrollWidth > facts.clientWidth) {
            problems.push(`${id}: scrollWidth ${facts.scrollWidth} > clientWidth ${facts.clientWidth}`);
          }
          if (facts.nameSize !== null && facts.nameSize < 12.5) {
            problems.push(`${id}: the member name is ${facts.nameSize}px — below the legible floor of 12.5`);
          }
          if (facts.photo !== null && facts.photo < 30) {
            problems.push(`${id}: the photograph is ${facts.photo}px — below the floor of 30`);
          }
          if (treatment.key !== 'now' && facts.boxBackground === 'rgba(0, 0, 0, 0)') {
            problems.push(`${id}: treatment ${treatment.key} left the panel with no fill — it is not a box`);
          }
          if (facts.linkHeight !== null && facts.linkHeight < 18) {
            problems.push(`${id}: the member link is ${facts.linkHeight}px tall — the hit target was squeezed away`);
          }
        }

        measurements[id] = facts;

        /* THE BOX, NOT THE PAGE. A full-page shot of a product page makes the
           block being judged about a fifth of the picture; four of those side
           by side are unreadable. The element screenshot is the deliverable and
           the page shot beside it is the context. */
        /* CLIPPED WITH A MARGIN, not element.screenshot(). An element shot is
           cropped to the border box, and treatment 3's photographs deliberately
           stand OUTSIDE it — so the one design whose whole idea is an overhang
           was the one design photographed with the overhang sliced off. 16px of
           air around the box shows it, and shows the other two sitting inside
           their own edges, which is the comparison. */
        const rect = await page.evaluate(() => {
          const b = document.querySelector('.ksl');

          if (!b) return null;

          const r = b.getBoundingClientRect();

          return {
            x: r.left + window.scrollX,
            y: r.top + window.scrollY,
            width: r.width,
            height: r.height,
          };
        });

        if (rect) {
          const pad = 16;

          await page.screenshot({
            path: path.join(outDir, `${tag}box-${set.key}-${treatment.key}-${width}.png`),
            fullPage: true,
            clip: {
              x: Math.max(0, rect.x - pad),
              y: Math.max(0, rect.y - pad),
              width: rect.width + pad * 2,
              height: rect.height + pad * 2,
            },
          });
        }

        if (set.key === 'long') {
          await page.screenshot({
            path: path.join(outDir, `${tag}page-${treatment.key}-${width}.png`),
            fullPage: true,
          });
        }

        await page.close();
      }
    }
  }

  await browser.close();

  fs.writeFileSync(
    path.join(outDir, `${tag}box-measurements.json`),
    JSON.stringify(measurements, null, 2) + '\n'
  );

  console.log(JSON.stringify(measurements, null, 2));

  if (problems.length) {
    console.error('\nREFUSED — these shots would not show what they claim to:\n  ' + problems.join('\n  '));
    process.exit(1);
  }
})();
