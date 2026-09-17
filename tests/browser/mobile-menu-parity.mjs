/**
 * The Arabic mobile menu is the English one, to the pixel.
 *
 * WHY THIS EXISTS. The owner was sent a screenshot of an Arabic mobile menu
 * sliding in from the side and answered: "we don't have menu opening from side
 * in mobile, we have dedicated developed menu opening from downside. i need the
 * exact everything in mobile arabic version."
 *
 * He was right. The shop's menu is `.mmenu`, a full-width sheet that rises from
 * the bottom; the drawer in the picture was `.mnav`, which is rendered into
 * every page and opened by nothing (see MobileMenuIsTheSheetTest). No PHP test
 * can see the difference — both are in the markup, both have a class, and only
 * a browser knows which one moves when the burger is pressed and where it ends
 * up.
 *
 * WHAT IT ASSERTS, and the asymmetry is the point:
 *   - the burger MIRRORS. It is a corner button, so in RTL it belongs in the
 *     other corner, and a burger that did not move would be the bug.
 *   - the sheet DOES NOT. It is full width. A full-width sheet has no side to
 *     come from, so its box must be identical in both languages — same x, same
 *     y, same width, same height, open and closed.
 *   - `.mnav` never opens in either language. If a future change wires it up,
 *     this fails rather than shipping two different mobile menus.
 *   - neither language scrolls sideways.
 *
 * Prints one JSON object on stdout and nothing else.
 *
 *   KBB_MM_BASE    base URL of a running preview with Arabic switched on
 *   KBB_MM_CHROME  chromium executable
 *   KBB_MM_SHOTS   optional directory for screenshots
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.KBB_MM_BASE;
const EXE = process.env.KBB_MM_CHROME;
const SHOTS = process.env.KBB_MM_SHOTS || '';

const out = { ok: false, english: null, arabic: null, checks: {}, pageErrors: [] };
const fail = (m) => { out.error = m; process.stdout.write(JSON.stringify(out)); process.exit(0); };

for (const [k, v] of Object.entries({ KBB_MM_BASE: BASE, KBB_MM_CHROME: EXE })) if (!v) fail(`${k} is not set`);
if (SHOTS) mkdirSync(SHOTS, { recursive: true });

const read = (page) =>
    page.evaluate(() => {
        const box = (sel) => {
            const el = document.querySelector(sel);
            if (!el) return null;
            const r = el.getBoundingClientRect();
            return {
                on: el.classList.contains('on'),
                x: Math.round(r.x), y: Math.round(r.y),
                w: Math.round(r.width), h: Math.round(r.height),
            };
        };
        return {
            lang: document.documentElement.getAttribute('lang'),
            dir: document.documentElement.getAttribute('dir'),
            burger: box('#burger'),
            sheet: box('.mmenu'),
            drawer: box('.mnav'),
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        };
    });

let browser;
try {
    browser = await chromium.launch({ executablePath: EXE });

    const visit = async (path, tag) => {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => out.pageErrors.push(`${tag}: ${e.message.split('\n')[0]}`));
        await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        const closed = await read(page);
        await page.click('#burger');
        await page.waitForTimeout(700);
        const open = await read(page);
        if (SHOTS) await page.screenshot({ path: `${SHOTS}/${tag}.png` });
        await ctx.close();
        return { closed, open };
    };

    out.english = await visit('/', 'en-mobile-menu-open');
    out.arabic = await visit('/ar/', 'ar-mobile-menu-open');

    const en = out.english, ar = out.arabic;

    if (!en.closed.sheet || !ar.closed.sheet) fail('the sheet is not in the markup on one of the two pages');
    if (!en.closed.burger || !ar.closed.burger) fail('the burger is not in the markup on one of the two pages');

    // The sheet is the same object in both languages, open and closed.
    const same = (a, b) => a && b && a.x === b.x && a.y === b.y && a.w === b.w && a.h === b.h;
    out.checks.sheetIdenticalClosed = same(en.closed.sheet, ar.closed.sheet);
    out.checks.sheetIdenticalOpen = same(en.open.sheet, ar.open.sheet);

    // It really is a bottom sheet: it starts below the fold and rises.
    out.checks.risesFromTheBottom = en.closed.sheet.y > en.open.sheet.y && ar.closed.sheet.y > ar.open.sheet.y;
    out.checks.fullWidth = en.open.sheet.w === en.closed.clientWidth && ar.open.sheet.w === ar.closed.clientWidth;
    out.checks.opens = en.open.sheet.on === true && ar.open.sheet.on === true;

    // The burger is a corner button and MUST move to the other corner.
    out.checks.burgerMirrors = en.closed.burger.x < en.closed.clientWidth / 2
        && ar.closed.burger.x > ar.closed.clientWidth / 2;

    // The side drawer stays shut in both.
    out.checks.drawerNeverOpens = !(en.open.drawer && en.open.drawer.on) && !(ar.open.drawer && ar.open.drawer.on);

    out.checks.noSidewaysScroll = en.open.scrollWidth <= en.open.clientWidth && ar.open.scrollWidth <= ar.open.clientWidth;
    out.checks.arabicIsArabic = ar.closed.lang === 'ar';

    out.ok = Object.values(out.checks).every(Boolean) && out.pageErrors.length === 0;
} catch (e) {
    out.error = e.message;
} finally {
    if (browser) await browser.close();
}

process.stdout.write(JSON.stringify(out));
