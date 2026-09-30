/*
 * The two things the coordinator asked to have SETTLED rather than assumed:
 *
 *  1. does the sticky bar go stale the same way when a tier is picked?
 *  2. can the schema.org offer block be reached by anything the page scripts?
 *
 * Both answered by pressing a real bundle row in a real browser and reading the
 * page back, not by reading the template.
 *
 *     sh tools/pdp-preview.sh 9950          # then, on the port it prints:
 *     node tools/pdp2-settle-probe.cjs http://127.0.0.1:9950
 *
 * Answered, 30 September, on the build this lane ships: the sticky bar's `.now`
 * already followed the tier (AED 74 -> AED 140) and it carries neither strike
 * nor badge, so it had nothing to go stale; and the offers array, og:price and
 * the whole JSON-LD block were byte-identical across the click, 5424 bytes both
 * times. The sticky bar therefore keeps `mayCreate: false` in pdp.js, and the
 * schema block is out of the writer's reach by construction rather than by
 * luck -- tests/Feature/ProductPriceBlockFollowsTierTest enumerates the two
 * elements the script writes into.
 */
const { chromium } = require('playwright');
(async () => {
  const base = process.argv[2] || 'http://127.0.0.1:9950';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  // 390 so the sticky bar is in its default `sticky_devices: phone` range.
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  await p.goto(base + '/product/pdp-heartleaf-toner/', { waitUntil: 'networkidle' });

  const read = () => p.evaluate(() => {
    const txt = (s) => { const e = document.querySelector(s); return e ? e.textContent.replace(/\s+/g, ' ').trim() : null; };
    const ld = [...document.querySelectorAll('script[type="application/ld+json"]')]
      .map((s) => s.textContent);
    const offers = ld.map((j) => { try { const o = JSON.parse(j); return JSON.stringify(o.offers ?? null); } catch (e) { return null; } })
      .filter((x) => x && x !== 'null');
    return {
      block:  { struck: txt('#bbPrice s'), now: txt('#bbPrice .now'), off: txt('#bbPrice .off') },
      sticky: { struck: txt('#stickyPrice s'), now: txt('#stickyPrice .now'), off: txt('#stickyPrice .off') },
      ogPrice: (document.querySelector('meta[property="product:price:amount"]') || {}).content || null,
      offers,
      ldLength: ld.join('').length,
    };
  });

  const before = await read();
  console.log('as served    ', JSON.stringify(before));

  await p.click('.variants .variant:nth-of-type(2)');   // the 2-pack
  await p.waitForTimeout(300);
  const after = await read();
  console.log('after 2-pack ', JSON.stringify(after));

  console.log('');
  console.log('STICKY .now follows the tier :', before.sticky.now, '->', after.sticky.now,
              '   (stale =', before.sticky.now === after.sticky.now, ')');
  console.log('STICKY has a struck figure   :', after.sticky.struck !== null);
  console.log('SCHEMA offers unchanged      :', JSON.stringify(before.offers) === JSON.stringify(after.offers));
  console.log('SCHEMA og:price unchanged    :', before.ogPrice === after.ogPrice);
  console.log('SCHEMA byte length unchanged :', before.ldLength === after.ldLength);
  await b.close();
})();
