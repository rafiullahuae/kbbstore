/*
 * Lane S7 — the four screens this round draws a Google-result preview on, plus
 * the new Overview tab, measured in a real browser.
 *
 * Same contract as tests/browser/lane-m2-module-screens.mjs and
 * lane-m3-settings-screens.mjs, which this follows rather than replaces:
 * IT REPORTS, IT DOES NOT JUDGE. The Pest suite has no browser in it, and the
 * claims this lane has to support — that a preview was added to four screens and
 * NOTHING ELSE ON THEM MOVED — are claims about rendered layout.
 *
 * WHAT IT ANSWERS, per screen and per width:
 *
 *   controls            how many settings controls the screen drew, counted as
 *                       real form elements inside #content. This is the number
 *                       that must not move: a preview is a read-only picture and
 *                       must add zero.
 *   keys                the id of every control, IN DOCUMENT ORDER — because a
 *                       count cannot see a reordering, and inserting a mount
 *                       point in the middle of a band is exactly the change that
 *                       keeps a count and moves the order.
 *   previews            how many .s7-prev blocks were drawn, and the title,
 *                       description and counter text of each. This is the new
 *                       thing, so it is reported in full rather than counted.
 *   contentScrollWidth  and contentClientWidth.
 *
 * WHY #content AND NOT THE DOCUMENT, which is the measurement the brief asks for
 * by name and which a lane has already recorded: the admin is a TWO-PANE layout.
 * The page itself does not scroll, the column inside it does — `#content` is
 * `overflow-x: auto`, so a child that is too wide is absorbed there and
 * `document.documentElement.scrollWidth` stays exactly at the viewport width.
 * tests/browser/admin-overflow.mjs records four screens reporting 390 by that
 * measure while the column was 416 to 490 wide. Both numbers are printed here:
 * documentScrollWidth so the figure the project notes name is on the record, and
 * the column's own pair because it is the one that can see the defect.
 *
 * ?go=<id> IS NOT USED, AND THAT IS DELIBERATE. Nine partials in this console
 * wrap window.go AFTER boot, and the URL is read before those wrappers exist —
 * so ?go=<id> dispatches through the original go(), finds no entry, and
 * `|| renderDash` quietly draws the Dashboard. A script measuring that would
 * report "0 controls, no overflow" for both revisions and call them identical.
 * So this loads /admin, lets the partials install themselves, and calls go()
 * from the page, exactly as lane-m2-module-screens.mjs does.
 *
 * Produces the BEFORE and the AFTER tables without being edited between them:
 * point it at a checkout of either revision and change KBB_S7_TAG.
 *
 *   KBB_S7_BASE=http://127.0.0.1:8907 \
 *   KBB_S7_EMAIL=s7@example.com KBB_S7_PASSWORD=secret-secret \
 *   KBB_S7_OUT=docs/s7-seo-shots KBB_S7_TAG=s7-after \
 *   KBB_S7_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-s7-seo-back-office.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_S7_BASE || 'http://127.0.0.1:8907';
const EMAIL = process.env.KBB_S7_EMAIL;
const PASSWORD = process.env.KBB_S7_PASSWORD;
const OUT = process.env.KBB_S7_OUT || '/tmp';
const TAG = process.env.KBB_S7_TAG || 'run';
const CHROME = process.env.KBB_S7_CHROME;

/*
 * Each screen, and how to get to the thing this lane touched.
 *
 * `sub` is a step run after go() has drawn the screen: the three row editors
 * open a MODAL, so the preview does not exist until a row is opened, and the SEO
 * screen's Settings tab is a subtab. Returning false means "the thing could not
 * be reached", which is reported rather than silently measured as zero.
 */
const SCREENS = [
  {
    name: 'seo-settings',
    go: 'seo',
    sub: async (page) => {
      await page.click('.subtab[data-st="settings"]');
      await page.waitForTimeout(1800);
      return true;
    },
  },
  {
    name: 'seo-overview',
    go: 'seo',
    sub: async (page) => {
      const tab = page.locator('.subtab[data-st="overview"]');
      if (!(await tab.count())) return false;
      await tab.click();
      // The overview runs a whole-catalogue scan, so it gets a longer settle
      // than a screen that reads one row.
      await page.waitForTimeout(6000);
      return true;
    },
  },
  /*
   * The two taxonomy editors are their OWN screens, installed by partials that
   * wrap window.go and claim an id of their own — 'category-tree' and
   * 'brands-manager', not a tab of Catalog. Reading the sidebar would have
   * measured the old preview grid on the Catalog tab instead, which draws no
   * editor at all.
   */
  {
    name: 'category-editor',
    go: 'category-tree',
    sub: async (page) => {
      await page.waitForTimeout(2500);
      const edit = page.locator('[data-edit]');
      if (!(await edit.count())) return false;
      await edit.first().click();
      await page.waitForTimeout(2500);
      return true;
    },
  },
  {
    name: 'brand-editor',
    go: 'brands-manager',
    sub: async (page) => {
      await page.waitForTimeout(2500);
      const edit = page.locator('[data-bedit]');
      if (!(await edit.count())) return false;
      await edit.first().click();
      await page.waitForTimeout(2500);
      return true;
    },
  },
  {
    name: 'article-editor',
    go: 'posts',
    sub: async (page) => {
      await page.waitForTimeout(2000);
      const edit = page.locator('[data-pj-edit]');
      if (!(await edit.count())) return false;
      await edit.first().click();
      await page.waitForTimeout(2500);
      return true;
    },
  },
];

const report = { tag: TAG, base: BASE, widths: [], error: null };

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

try {
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({
      viewport: { width, height: width === 390 ? 844 : 900 },
    });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });

    if (await page.locator('input[type=email]').count()) {
      await page.fill('input[type=email]', EMAIL);
      await page.fill('input[type=password]', PASSWORD);
      await page.click('button[type=submit]');
      await page.waitForLoadState('networkidle');
    }

    const block = { width, screens: [] };

    for (const screen of SCREENS) {
      await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(1200);
      await page.evaluate((id) => window.go(id), screen.go);
      await page.waitForTimeout(2200);

      let reached = true;
      try {
        reached = (await screen.sub(page)) !== false;
      } catch (e) {
        reached = false;
      }

      const measured = await page.evaluate(() => {
        const content = document.querySelector('#content');
        if (!content) return null;

        /*
         * THE THREE ROW EDITORS ARE MODALS, AND A MODAL IS NOT INSIDE #content.
         * Both taxonomy editors append their dialog to <body> (`position:fixed;
         * inset:0`), so a count scoped to #content reported ZERO controls for
         * all three — and zero before and zero after would have "proved" nothing
         * moved. Found by running this, which is the whole reason the script
         * prints the number rather than asserting it.
         *
         * So the measured root is the open dialog when there is one, and the
         * content column otherwise. The column's own scrollWidth pair is still
         * read off #content either way, because that is the pane that scrolls.
         */
        const modal = document.querySelector('.ct-modal, .bz-modal, .pj-modal');
        const root = modal || content;

        /*
         * A CONTROL IS A FORM ELEMENT, counted by tag rather than by class.
         *
         * The five screens here build their own field markup with five different
         * wrapper classes (.sm-field, .ct-fld, .bz-fld, .pj-f, and the modal's
         * own), so any class-based count would be five counts. What "a control"
         * means on all five is the same thing: a box the operator edits. Hidden
         * file inputs are excluded — every image field carries one and it is not
         * a control anybody sees.
         */
        const nodes = Array.from(root.querySelectorAll('input, select, textarea'))
          .filter((n) => n.type !== 'hidden' && n.type !== 'file');

        const previews = Array.from(document.querySelectorAll('.s7-prev')).map((p) => ({
          url: (p.querySelector('.seo-snip .u') || {}).textContent || '',
          title: (p.querySelector('.seo-snip .t') || {}).textContent || '',
          description: (p.querySelector('.seo-snip .d') || {}).textContent || '',
          counters: Array.from(p.querySelectorAll('.s7-count')).map((c) => c.textContent),
          note: (p.querySelector('.s7-prev-note') || {}).textContent || '',
        }));

        /* The Overview's own shape, so "it drew" is a measurement rather than a
           screenshot somebody has to read. */
        const overview = {
          verdict: (document.querySelector('.s7-verdict') || {}).textContent || '',
          cards: document.querySelectorAll('.s7-item').length,
          bands: Array.from(document.querySelectorAll('.s7-band-h')).map((b) => b.textContent),
          takeMeThere: document.querySelectorAll('.s7-acts .btn').length,
        };

        return {
          documentScrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
          contentScrollWidth: content.scrollWidth,
          contentClientWidth: content.clientWidth,
          measuredIn: modal ? 'dialog' : 'content column',
          dialogScrollWidth: modal ? modal.scrollWidth : null,
          dialogClientWidth: modal ? modal.clientWidth : null,
          controls: nodes.length,
          keys: nodes.map((n) => n.id || n.name || ('(' + n.tagName.toLowerCase() + ')')),
          previews,
          overview,
        };
      });

      await page.screenshot({ path: `${OUT}/${TAG}-${screen.name}-${width}.png`, fullPage: true });

      /*
       * A SECOND SHOT OF THE PREVIEW ITSELF.
       *
       * The two taxonomy editors are FIXED-POSITION dialogs that scroll
       * internally, and `fullPage` cannot see past a fixed element's own
       * viewport — the first shot of the category editor showed the top of the
       * dialog and not the thing this lane added, which would have been a
       * picture that proves nothing. So the preview is scrolled into view and
       * photographed on its own. Absent on the before revision, which is the
       * point: the file simply is not written.
       */
      const prev = page.locator('.s7-prev').first();
      if (await prev.count()) {
        await prev.scrollIntoViewIfNeeded();
        await page.waitForTimeout(500);
        await prev.screenshot({ path: `${OUT}/${TAG}-${screen.name}-preview-${width}.png` });
      }

      block.screens.push({
        screen: screen.name,
        reached,
        ...measured,
        columnOverflow: measured ? measured.contentScrollWidth > measured.contentClientWidth : null,
        documentOverflow: measured ? measured.documentScrollWidth > measured.innerWidth : null,
      });
    }

    report.widths.push(block);
    await ctx.close();
  }
} catch (e) {
  report.error = String(e && e.message ? e.message : e);
} finally {
  await browser.close();
}

console.log(JSON.stringify(report, null, 1));
