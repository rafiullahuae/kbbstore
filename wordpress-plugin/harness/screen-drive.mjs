/*
 * Drive Tools -> KBB Export in a real browser, and report what it did.
 *
 *   node wordpress-plugin/harness/screen-drive.mjs \
 *       --page=/tmp/screen.html --shots=docs/gk-export-shots
 *
 * ── WHY A BROWSER AND NOT A GREP ────────────────────────────────────────────
 *
 * Everything this lane added to the screen is JavaScript, and the PHP suite
 * cannot see a line of it. Measured: deleting `body.set('groups', ...)` from
 * the page -- so that every export becomes a whole export whatever is ticked --
 * left the whole PHP suite green. A grep for that one line would close that one
 * mutation and nothing else; what has to be checked is BEHAVIOUR, and the three
 * behaviours are:
 *
 *   1. Ticking a group off makes its dependants' warnings appear, with the one
 *      that loses rows drawn differently from the ones the import report names.
 *   2. Start is NOT pressable until every warning has been answered, either by
 *      adding the group or by confirming it is already in the new shop.
 *   3. The request the page sends carries the selection and the confirmations.
 *
 * `fetch` is intercepted rather than served, for two reasons: there is no
 * WordPress here to answer admin-ajax.php, and a stub is the only way to hold
 * the page mid-run -- a real run of this fixture finishes in under a second and
 * there is no moving bar to photograph.
 *
 * What is NOT stubbed is the page. It is KBB_Export_Admin::screen()'s own
 * markup and its own script, rendered by screen.php.
 */

import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => {
        const m = /^--([a-z-]+)=(.*)$/.exec(a);
        return m ? [m[1], m[2]] : [a.replace(/^--/, ''), '1'];
    }),
);

const page_path = resolve(args.page);
const shots = args.shots ? resolve(args.shots) : null;

if (shots) {
    mkdirSync(shots, { recursive: true });
}

const browser = await chromium.launch({
    executablePath: args.chrome || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
});

const page = await browser.newPage({ viewport: { width: 1180, height: 1000 } });

const findings = { posts: [], errors: [] };

page.on('pageerror', (e) => findings.errors.push(String(e)));

/*
 * The fake admin-ajax. It records every body the page posts and answers with a
 * progress document shaped exactly like KBB_Export_Runner::progress() -- the
 * same keys, because the page reads those keys and a reply missing one would
 * prove the page works against a server that does not exist.
 *
 * `hold` keeps it part way through, which is the state a screenshot of a moving
 * bar has to be taken in.
 */
await page.addInitScript(() => {
    window.__posts = [];
    window.__hold = true;

    const groupsMidRun = [
        { key: 'catalogue', label: 'Catalogue', files: ['categories.csv'], rows_done: 14, rows_total: 14, percent: 100, state: 'done' },
        { key: 'seo', label: 'SEO (Yoast)', files: ['seo.csv'], rows_done: 4, rows_total: 4, percent: 100, state: 'done' },
        { key: 'coupons', label: 'Coupons', files: ['coupons.csv'], rows_done: 1, rows_total: 1, percent: 100, state: 'done' },
        { key: 'customers', label: 'Customers', files: ['customers.csv'], rows_done: 3, rows_total: 3, percent: 100, state: 'done' },
        { key: 'sales', label: 'Orders', files: ['orders.csv'], rows_done: 5, rows_total: 9, percent: 55, state: 'running' },
        { key: 'reviews', label: 'Reviews', files: ['reviews.csv'], rows_done: 0, rows_total: 2, percent: 0, state: 'pending' },
        { key: 'content', label: 'Journal articles', files: ['posts.csv'], rows_done: 0, rows_total: 2, percent: 0, state: 'pending' },
        { key: 'addresses', label: 'Addresses and pictures', files: ['permalinks.csv', 'media.csv'], rows_done: 0, rows_total: 18, percent: 0, state: 'pending' },
    ];

    window.fetch = function (url, options) {
        const body = String(options && options.body ? options.body : '');

        window.__posts.push(Object.fromEntries(new URLSearchParams(body)));

        /*
         * A REAL DELAY, not an immediately-resolved promise. The page answers
         * each batch by posting the next one, so a stub that resolves in the
         * microtask queue never lets a macrotask run: the browser stops
         * painting and the driver's own clicks never land. A shared host takes
         * hundreds of milliseconds per batch, so this is also the honest shape.
         */
        return new Promise((resolve) => setTimeout(() => resolve({
            json: () => Promise.resolve({
                ok: true,
                error: '',
                export_id: '8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f',
                dir: '/home/kbb/public_html/wp-content/uploads/kbb-export/8f14e45f',
                stage: 'orders.csv',
                stage_index: 9,
                stage_count: 17,
                groups: groupsMidRun,
                written: {},
                totals: {},
                rows_done: 27,
                rows_total: 51,
                percent: 52,
                done: false,
                storage: 'legacy (wp_posts)',
                notes: [],
            }),
        }), 90));
    };
});

await page.goto('file://' + page_path);

const shot = async (name) => {
    if (shots) {
        await page.screenshot({ path: shots + '/' + name, fullPage: true });
    }
};

const startDisabled = () => page.$eval('#kbb-start', (b) => b.disabled);
const blockedText = () => page.$eval('#kbb-blocked', (b) => b.textContent.trim());
const warnings = () =>
    page.$$eval('#kbb-warnings .notice', (ns) =>
        ns.map((n) => ({
            severity: n.classList.contains('notice-error') ? 'loses' : 'reported',
            heading: n.querySelector('strong').textContent.trim(),
        })),
    );

// ── 1. At rest ──────────────────────────────────────────────────────────────
findings.at_rest = {
    groups: await page.$$eval('.kbb-group', (b) => b.map((x) => x.value)),
    all_ticked: await page.$$eval('.kbb-group', (b) => b.every((x) => x.checked)),
    warnings: await warnings(),
    start_disabled: await startDisabled(),
};

// ── 2. A partial selection with no unmet dependency ──────────────────────────
await page.click('#kbb-none');
await page.check('#kbb-group-catalogue');

findings.catalogue_only = {
    warnings: await warnings(),
    start_disabled: await startDisabled(),
    blocked: await blockedText(),
};

await shot('01-at-rest-groups-unticked.png');

// ── 3. The dependency warning ───────────────────────────────────────────────
await page.click('#kbb-none');
await page.check('#kbb-group-sales');

findings.sales_alone = {
    warnings: await warnings(),
    start_disabled: await startDisabled(),
    blocked: await blockedText(),
};

await shot('02-dependency-warning.png');

// Confirming one of the two clears one and leaves the other.
await page.check('#kbb-warnings .kbb-confirm[value="sales:customers"]');

findings.one_confirmed = {
    start_disabled: await startDisabled(),
    blocked: await blockedText(),
};

// Adding the other group by its own button, which is the way out that does not
// rest on the operator's memory of another shop.
await page.click('#kbb-warnings .kbb-add[data-group="catalogue"]');

findings.after_add = {
    catalogue_ticked: await page.$eval('#kbb-group-catalogue', (b) => b.checked),
    warnings: await warnings(),
    start_disabled: await startDisabled(),
    blocked: await blockedText(),
};

await shot('03-dependency-answered.png');

// ── 4. Mid-run, with the bars moving ────────────────────────────────────────
await page.click('#kbb-all');
await page.click('#kbb-start');
await page.waitForSelector('#kbb-group-bars table');
await page.waitForTimeout(400);
await page.click('#kbb-stop');

findings.mid_run = {
    status: await page.$eval('#kbb-status', (n) => n.textContent.trim()),
    overall_width: await page.$eval('#kbb-bar', (n) => n.style.width),
    bars: await page.$$eval('#kbb-group-bars tr', (rows) =>
        rows.map((r) => ({
            label: r.children[0].childNodes[0].textContent.trim(),
            state: r.children[0].querySelector('.description').textContent.trim(),
            /*
             * The fill, reached by walking rather than by a `div div`
             * selector: Element.querySelector still evaluates the selector
             * against the whole document, and the bar's own track is inside
             * #kbb-state, which is a div -- so `div div` matched the TRACK and
             * every width read back empty while the page was drawing them
             * correctly.
             */
            width: r.children[1].firstElementChild.firstElementChild.style.width,
        })),
    ),
};

await shot('04-mid-run-bars.png');

// ── 5. What the page actually sent ──────────────────────────────────────────
findings.posts = await page.evaluate(() => window.__posts);

await browser.close();

if (args.out) {
    writeFileSync(resolve(args.out), JSON.stringify(findings, null, 2) + '\n');
}

console.log(JSON.stringify(findings, null, 2));
