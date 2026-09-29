/*
 * Lane AR — the evidence.
 *
 * SEVEN SURFACES × TWO WIDTHS × THREE LANGUAGE STATES, plus the measured
 * numbers that decide what the pictures mean. The states are the whole point of
 * this lane and they are named the way tools/ar-preview.sh names them:
 *
 *   en   both switches off        -- the shop as it ships. /ar is a 404.
 *   ar   language_ar_enabled on   -- Arabic words, LTR document. THE DEFAULT
 *                                    the moment the owner flips one switch.
 *   rtl  both on                  -- the mirrored document.
 *
 * For each shot we record documentElement.dir, lang, scrollWidth against
 * clientWidth, and whether the layout is actually mirrored -- taken as the
 * x-position of the header's logo relative to the page centre, because "is it
 * mirrored" is a question about where things ARE and not about what the dir
 * attribute claims.
 *
 * Chromium 1194 at the path CLAUDE.md names, browser.newContext({viewport})
 * rather than page.setViewportSize (which has produced wrong-sized shots on
 * this box), deviceScaleFactor 1, reducedMotion reduce, animations frozen.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const OUT = process.argv[2] || 'docs/lane-ar-shots';
/* A prefix on every filename, so the SAME seven surfaces can be shot twice --
   once with the shipped Arabic still in draft (what applying the package
   gives you) and once after the owner has pressed Approve all. The pair is the
   deliverable; either one alone proves only half of it. */
const PRE = process.argv[3] || '';
const PORTS = { en: 8993, ar: 8991, rtl: 8992 };

const FREEZE = `*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;
  transition-duration:0s!important;transition-delay:0s!important}
html{overflow-y:scroll}`;

/* The seven surfaces the brief names. `bag` means two units have to be in the
   basket first or /cart and /checkout redirect and photograph nothing. */
const PAGES = [
  { key: 'home',     path: '/',                             bag: false },
  { key: 'product',  path: '/product/lanear-1/',             bag: false },
  { key: 'set',      path: '/product/lanear-glow-starter-set/', bag: false },
  { key: 'cart',     path: '/cart/',                        bag: true  },
  { key: 'checkout', path: '/checkout/',                    bag: true  },
  { key: 'rail',     path: '/#kbb-ugc',                     bag: false },
  { key: 'tabs',     path: '/product/lanear-1/#tabs',       bag: false },
];

const VIEWPORTS = [
  { w: 390,  h: 844,  tag: '390'  },
  { w: 1280, h: 900,  tag: '1280' },
];

function prefix(state) { return state === 'en' ? '' : '/ar'; }

async function measure(page) {
  return page.evaluate(() => {
    const de = document.documentElement;
    const centre = de.clientWidth / 2;

    /* WHERE THE LOGO SITS is the mirror test. A dir attribute is a claim; the
       logo's x is the fact. In English it is left of centre; mirrored, right. */
    const logo = document.querySelector('.hd-logo, .logo, header a[href="/"], header img');
    const r = logo ? logo.getBoundingClientRect() : null;

    return {
      dir: de.getAttribute('dir'),
      lang: de.getAttribute('lang'),
      scrollWidth: de.scrollWidth,
      clientWidth: de.clientWidth,
      overflow: de.scrollWidth - de.clientWidth,
      logoX: r ? Math.round(r.left * 10) / 10 : null,
      logoSide: r ? (r.left + r.width / 2 < centre ? 'left' : 'right') : null,
      /* Two strings that prove which language actually rendered. */
      addToCart: (document.body.innerText.match(/أضف إلى السلة|Add to cart/) || [null])[0],
      bagWord: (document.body.innerText.match(/حقيبتك|Your Bag/) || [null])[0],
    };
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });

  const rows = [];

  for (const [state, port] of Object.entries(PORTS)) {
    const base = `http://127.0.0.1:${port}`;

    for (const vp of VIEWPORTS) {
      const ctx = await browser.newContext({
        viewport: { width: vp.w, height: vp.h },
        deviceScaleFactor: 1,
        reducedMotion: 'reduce',
      });

      /* Nothing off 127.0.0.1 — a webfont resolving at its own pace moves the
         layout between two captures of the same tree. */
      await ctx.route('**/*', (route) => {
        const u = route.request().url();
        return u.startsWith('http://127.0.0.1') || u.startsWith('data:')
          ? route.continue() : route.abort();
      });

      await ctx.addInitScript((css) => {
        document.addEventListener('DOMContentLoaded', () => {
          const s = document.createElement('style');
          s.textContent = css;
          document.head.appendChild(s);
        });
      }, FREEZE);

      const page = await ctx.newPage();

      /* THE BAG, ONCE PER CONTEXT, through the shop's own endpoint so the
         cookie and the CSRF token are the ones the shop issued. */
      let bagged = false;
      const fillBag = async () => {
        if (bagged) return;
        await page.goto(base + prefix(state) + '/lanear-1/', { waitUntil: 'domcontentloaded' });
        await page.evaluate(async (p) => {
          const tok = document.querySelector('meta[name="csrf-token"]');
          const body = new URLSearchParams();
          body.set('product_id', '1');
          body.set('quantity', '2');
          await fetch(p + '/api/cart/add', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': tok ? tok.content : '',
                       'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
          });
        }, prefix(state));
        bagged = true;
      };

      for (const pg of PAGES) {
        if (state === 'en' && pg.path === '/') { /* English home is the control */ }
        if (pg.bag) await fillBag();

        const url = base + prefix(state) + pg.path;
        const resp = await page.goto(url, { waitUntil: 'networkidle' }).catch(() => null);
        const status = resp ? resp.status() : 0;

        const file = `${PRE}${state}-${pg.key}-${vp.tag}.png`;   /* converted to q70 JPEG afterwards -- see the README */

        let m = { note: 'not rendered' };
        if (status === 200) {
          await page.waitForTimeout(250);
          m = await measure(page);
          await page.screenshot({ path: path.join(OUT, file), fullPage: false });
        }

        rows.push({ state, page: pg.key, width: vp.tag, url: prefix(state) + pg.path, status, file, ...m });
        console.log(`${state.padEnd(4)} ${pg.key.padEnd(9)} ${vp.tag.padStart(4)}  ${status}  dir=${m.dir} sw=${m.scrollWidth}/${m.clientWidth} logo=${m.logoSide}@${m.logoX} "${m.addToCart || ''}"`);
      }

      await ctx.close();
    }
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, PRE + 'measurements.json'), JSON.stringify(rows, null, 2));
  console.log('\nwrote ' + rows.length + ' rows to ' + path.join(OUT, 'measurements.json'));
})();
