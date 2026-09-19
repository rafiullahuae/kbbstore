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

    /*
     * ── THE ZIP PHASE, FAKED THE SAME WAY THE EXPORT IS ─────────────────────
     *
     * Lane GL added a second browser-driven loop: once the export is done the
     * page posts kbb_export_zip until every archive is packed, and redraws the
     * download table between units. That loop is JavaScript too, and the PHP
     * suite can no more see it than it could see the group ticks -- which is
     * the whole finding docs/GK-EXPORT-GROUPS.md section 6.1 paid for. So the
     * stub answers that action as well, and it answers it with the SHAPE
     * KBB_Export_Runner::zip_progress() really returns: a group left out of the
     * export comes back `absent`, a group not yet packed comes back with
     * ready:false, and one comes back ready with a size. All three have to draw
     * differently, and a stub that only ever produced the happy one would prove
     * only that the happy one draws.
     */
    const zipGroups = (packed) => ([
        { key: 'catalogue', label: 'Catalogue', state: packed > 0 ? 'ready' : 'building', why: '',
          parts: [{ part: 1, parts: 1, archive: 'kbb-export-catalogue-8f14e45f.zip', ready: packed > 0, bytes: 727245, files: ['categories.csv', 'products.csv'] }] },
        { key: 'seo', label: 'SEO (Yoast)', state: 'absent', why: 'SEO (Yoast) was not in this export, so there is no file to download.', parts: [] },
        { key: 'coupons', label: 'Coupons', state: packed > 1 ? 'ready' : 'building', why: '',
          parts: [{ part: 1, parts: 1, archive: 'kbb-export-coupons-8f14e45f.zip', ready: packed > 1, bytes: 8294, files: ['coupons.csv'] }] },
        { key: 'customers', label: 'Customers', state: packed > 1 ? 'ready' : 'building', why: '',
          parts: [{ part: 1, parts: 1, archive: 'kbb-export-customers-8f14e45f.zip', ready: packed > 1, bytes: 653619, files: ['customers.csv'] }] },
        { key: 'sales', label: 'Orders', state: packed > 2 ? 'ready' : 'building', why: '',
          parts: [{ part: 1, parts: 1, archive: 'kbb-export-sales-8f14e45f.zip', ready: packed > 2, bytes: 2264924, files: ['orders.csv', 'order_items.csv', 'refunds.csv', 'order_notes.csv'] }] },
        { key: 'reviews', label: 'Reviews', state: 'absent', why: 'Reviews was not in this export, so there is no file to download.', parts: [] },
        { key: 'content', label: 'Journal articles', state: 'absent', why: 'Journal articles was not in this export, so there is no file to download.', parts: [] },
        { key: 'addresses', label: 'Addresses and pictures', state: 'absent', why: 'Addresses and pictures was not in this export, so there is no file to download.', parts: [] },
    ]);

    const zipReply = (packed) => ({
        ok: true,
        error: '',
        available: true,
        reason: '',
        units_done: packed,
        units: 3,
        done: packed >= 3,
        percent: Math.floor((packed / 3) * 100),
        groups: zipGroups(packed),
    });

    window.__steps = 0;
    window.__zipUnits = 0;

    window.fetch = function (url, options) {
        const body = String(options && options.body ? options.body : '');
        const sent = Object.fromEntries(new URLSearchParams(body));

        window.__posts.push(sent);

        if (sent.action === 'kbb_export_zip') {
            window.__zipUnits = Math.min(3, window.__zipUnits + 1);

            const reply = zipReply(window.__zipUnits);

            return new Promise((resolve) => setTimeout(() => resolve({
                json: () => Promise.resolve(reply),
            }), 90));
        }

        /*
         * The export finishes after a few batches when __hold is released, so
         * the page reaches the state that STARTS the zip loop. Without this the
         * stub answers done:false for ever and the download table is never
         * drawn -- which is the shape of hole that let M13 survive.
         */
        if (sent.action === 'kbb_export_step' && !window.__hold) {
            window.__steps++;

            if (window.__steps > 1) {
                return new Promise((resolve) => setTimeout(() => resolve({
                    json: () => Promise.resolve({
                        ok: true, error: '', export_id: '8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f',
                        dir: '/home/kbb/public_html/wp-content/uploads/kbb-export/8f14e45f',
                        stage: 'manifest.json', stage_index: 17, stage_count: 17,
                        groups: groupsMidRun, written: {}, totals: {},
                        rows_done: 51, rows_total: 51, percent: 100, done: true,
                        storage: 'legacy (wp_posts)', notes: [], zip: zipReply(0),
                    }),
                }), 90));
            }
        }

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

/*
 * ── --static: THE PAGE AS HE FINDS IT WHEN HE COMES BACK ────────────────────
 *
 * screen.php --with_export= runs a REAL export and a REAL zip phase, then
 * renders the page on top of the state it left. The download table is then
 * drawn from the SERVER on load rather than by the loop that packed it, which
 * is a different code path and the one that matters most: he will close the tab
 * and come back, and a download table that only exists in the page that started
 * the export is a download table he cannot reach.
 *
 * Nothing is intercepted or clicked here. What is read back is what the server
 * put on the page.
 */
if (args.static) {
    findings.static_downloads = await page.$$eval('#kbb-downloads tr', (rows) =>
        rows.map((row) => {
            const control = row.querySelector('.kbb-download');

            return {
                label: row.children[0].textContent.trim(),
                state: control ? control.getAttribute('data-state') : 'none',
                tag: control ? control.tagName.toLowerCase() : '',
                disabled: control ? !!control.disabled : null,
                href: control && control.tagName.toLowerCase() === 'a' ? control.getAttribute('href') : '',
                description: row.children[1].textContent.trim(),
            };
        }),
    );

    await shot(args.shotname || '02-after-a-run.png');

    findings.posts = await page.evaluate(() => window.__posts);

    await browser.close();

    if (args.out) {
        writeFileSync(resolve(args.out), JSON.stringify(findings, null, 2) + '\n');
    }

    console.log(JSON.stringify(findings, null, 2));

    process.exit(0);
}

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

/*
 * ── 5. THE DOWNLOAD TABLE ───────────────────────────────────────────────────
 *
 * The owner's whole request: "allow to download each group seperate files".
 * Three things have to be true of what the page draws, and none of them is
 * visible to a PHP test:
 *
 *   1. A group that WAS exported gets a real link, with the archive's name and
 *      its size on it, so he can see before clicking that it is not heavy.
 *   2. A group that was NOT gets a DISABLED control saying so -- not a link
 *      that 404s, and not a missing row, which reads as a bug.
 *   3. Every link carries the download nonce and the group it is for, and
 *      nothing else. A download URL with a path in it is the bug this design
 *      exists to make impossible, so the query is read back and reported.
 */
const readDownloads = () =>
    page.$$eval('#kbb-downloads tr', (rows) =>
        rows.map((row) => {
            const control = row.querySelector('.kbb-download');

            return {
                label: row.children[0].textContent.trim(),
                state: control ? control.getAttribute('data-state') : 'none',
                tag: control ? control.tagName.toLowerCase() : '',
                text: control ? control.textContent.trim() : '',
                disabled: control ? !!control.disabled : null,
                href: control && control.tagName.toLowerCase() === 'a' ? control.getAttribute('href') : '',
                description: row.children[1].textContent.trim(),
            };
        }),
    );

// Release the hold so the export can finish, which is what starts the zip loop.
// Start rather than Resume: Resume is disabled on a screen with no half-finished
// export in the option, which is exactly what this harness renders and is the
// state the button is correct to be in.
await page.evaluate(() => { window.__hold = false; });
await page.click('#kbb-start');
await page.waitForSelector('#kbb-downloads .kbb-download[data-state="ready"]', { timeout: 15000 });
await page.waitForFunction(() => window.__zipUnits >= 3, null, { timeout: 15000 });
await page.waitForTimeout(200);

findings.downloads = await readDownloads();
findings.zip_posts = (await page.evaluate(() => window.__posts)).filter((p) => p.action === 'kbb_export_zip').length;

await shot('05-downloads-per-group.png');

// ── 6. What the page actually sent ──────────────────────────────────────────
findings.posts = await page.evaluate(() => window.__posts);

await browser.close();

if (args.out) {
    writeFileSync(resolve(args.out), JSON.stringify(findings, null, 2) + '\n');
}

console.log(JSON.stringify(findings, null, 2));
