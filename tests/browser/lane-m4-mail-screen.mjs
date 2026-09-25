/*
 * Lane M4 — Store → Mail, measured in a real browser.
 *
 * Same contract as tests/browser/lane-m3-settings-screens.mjs, which this
 * follows rather than replaces: IT REPORTS, IT DOES NOT JUDGE. The Pest suite
 * has no browser in it, and the claim this round has to support — that moving
 * this screen's reader, writer and password onto the shared schema changed
 * nothing the owner can see — is a claim about rendered layout.
 *
 * WHAT IT ANSWERS, per width:
 *
 *   scrollWidth / innerWidth   the horizontal-overflow check the project notes
 *                              ask for by name. `overflow: true` is a bug.
 *   controls                   how many settings controls the screen drew, per
 *                              band and summed. This is the number that must
 *                              not move.
 *   keys                       every control's `data-mail` key, IN ORDER, so
 *                              "same count" cannot hide "different controls" or
 *                              "same controls, reordered" — which a rewritten
 *                              schema could do, since the order this screen
 *                              draws in is the order of MailSettings::SCHEMA.
 *   selected                   THE CHOSEN OPTION OF EVERY <select>, and the
 *                              full option list beside it. This is the one
 *                              measurement this round needs that round 3's
 *                              script did not take: Task 2 moved where the
 *                              transport LABEL is derived, and a dropdown that
 *                              silently paints on its first entry instead of
 *                              the owner's saved choice is exactly the failure
 *                              a control count cannot see.
 *   password                   the type and placeholder of the secret box, so
 *                              "Stored — leave blank to keep it" versus "Not
 *                              set" is measured rather than assumed, and so a
 *                              `value` attribute appearing on it would show.
 *
 * Produces the BEFORE and the AFTER tables without being edited between them:
 * point it at a checkout of either revision and change KBB_M4_TAG.
 *
 *   KBB_M4_BASE=http://127.0.0.1:8973 \
 *   KBB_M4_EMAIL=owner@example.com KBB_M4_PASSWORD=secret-secret \
 *   KBB_M4_OUT=docs/m-module-shots KBB_M4_TAG=m4-after \
 *   KBB_M4_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-m4-mail-screen.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_M4_BASE || 'http://127.0.0.1:8973';
const EMAIL = process.env.KBB_M4_EMAIL;
const PASSWORD = process.env.KBB_M4_PASSWORD;
const OUT = process.env.KBB_M4_OUT || '/tmp';
const TAG = process.env.KBB_M4_TAG || 'run';
const CHROME = process.env.KBB_M4_CHROME;

/* Every control the Mail screen draws carries `data-mail="<key>"` —
   mailControl() in resources/views/admin/app.blade.php puts it on the select,
   the password input and the text input alike, which is what makes one
   selector enough. */
const SEL = '[data-mail]';

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

    /*
     * LOADED AND THEN DISPATCHED FROM THE PAGE, not ?go=mail — round 2's note,
     * repeated here rather than rediscovered. These partials wrap window.go
     * AFTER the console has booted, the URL is read before those wrappers
     * exist, and `|| renderDash` quietly draws the Dashboard: a screen with no
     * controls, which this script would report as "0 controls, no overflow" for
     * both revisions and call them identical.
     */
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('mail'));
    await page.waitForTimeout(2400);

    const measured = await page.evaluate((sel) => {
      const rows = Array.from(document.querySelectorAll(sel));

      return {
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
        controls: rows.length,
        keys: rows.map((r) => r.getAttribute('data-mail')),
        /* The Mail screen has no tabs; it has four titled bands from
           MAIL_SECTIONS plus an "Other settings" band for anything the schema
           declares that no band names. Counted per band so a control moving
           between bands is visible. */
        bands: Array.from(document.querySelectorAll('.mlf-card .mlf-sec')).map((s) => ({
          title: (s.querySelector('.mlf-sec-t') || {}).textContent || '',
          controls: s.querySelectorAll(sel).length,
        })),
        selects: rows
          .filter((r) => r.tagName === 'SELECT')
          .map((r) => ({
            key: r.getAttribute('data-mail'),
            selected: r.value,
            selectedIndex: r.selectedIndex,
            options: Array.from(r.options).map((o) => o.value),
          })),
        password: rows
          .filter((r) => r.getAttribute('type') === 'password')
          .map((r) => ({
            key: r.getAttribute('data-mail'),
            type: r.getAttribute('type'),
            placeholder: r.getAttribute('placeholder'),
            /* A `value` attribute here would be a stored credential in the
               DOM. Reported rather than assumed absent. */
            valueAttr: r.getAttribute('value'),
            value: r.value,
          })),
      };
    }, SEL);

    await page.screenshot({ path: `${OUT}/${TAG}-mail-${width}.png`, fullPage: true });

    report.widths.push({
      width,
      ...measured,
      overflow: measured.scrollWidth > measured.innerWidth,
    });

    await ctx.close();
  }
} catch (e) {
  report.error = String(e && e.message ? e.message : e);
} finally {
  await browser.close();
}

console.log(JSON.stringify(report, null, 1));
