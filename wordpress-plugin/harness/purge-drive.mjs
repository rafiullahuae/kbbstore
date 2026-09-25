/*
 * Drive the delete section of Tools -> KBB Export in a real browser, against a
 * real folder, and report what is left on the disk afterwards.
 *
 *   KBB_PURGE_UPLOADS=/tmp/kbb-purge-shots \
 *     php -S 127.0.0.1:8731 wordpress-plugin/harness/purge-serve.php &
 *   node wordpress-plugin/harness/purge-drive.mjs \
 *       --base=http://127.0.0.1:8731 --shots=docs/gn-purge-shots
 *
 * ── WHY A BROWSER ───────────────────────────────────────────────────────────
 *
 * The confirmation box, the button's disabled state and the result line are all
 * JavaScript, and no PHP test can see one of them. screen-drive.mjs makes the
 * same argument for the export; this is its other half.
 *
 * ── AND WHY THE ANSWER IS READ OFF THE DISK ─────────────────────────────────
 *
 * Every number below that matters comes from /state, which walks the folder
 * with RecursiveDirectoryIterator in the harness -- not from the page, and not
 * from the endpoint's own reply. The whole defect this feature exists to close
 * is a screen that says the files are gone while they are not, so a driver that
 * believed the screen would be reproducing the bug rather than catching it.
 */

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => {
        const m = a.match(/^--([a-z_]+)=(.*)$/);
        return m ? [m[1], m[2]] : [a.replace(/^--/, ''), true];
    })
);

const base = args.base || 'http://127.0.0.1:8731';
const shots = args.shots ? resolve(args.shots) : null;

if (shots) {
    mkdirSync(shots, { recursive: true });
}

const state = async () => (await fetch(base + '/state')).json();
const seed = async () => (await fetch(base + '/seed')).json();

const browser = await chromium.launch({
    executablePath: args.chrome || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: ['--no-sandbox'],
});

const report = { viewports: {}, manager: null, deleted: null };

/** The three numbers rule 2 asks for, measured in the page and not guessed. */
const widths = (page) =>
    page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    }));

const section = async (page) => {
    await page.locator('#kbb-delete-heading').scrollIntoViewIfNeeded();
    await page.waitForTimeout(120);
};

for (const [label, width] of [['390', 390], ['1280', 1280]]) {
    await seed();

    const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 900 } });

    await page.goto(base + '/', { waitUntil: 'networkidle' });
    await section(page);

    const before = await state();
    const listed = await page.locator('#kbb-purge-list tbody tr').count();
    const sensitiveShown = await page.locator('#kbb-purge-list strong').first().textContent();

    if (shots) {
        await page.screenshot({ path: `${shots}/${label}-1-before.png`, fullPage: false });
    }

    // ── THE CONFIRMATION, both ways round ────────────────────────────────────
    const box = page.locator('#kbb-purge-confirm');
    const button = page.locator('#kbb-purge');

    const emptyDisabled = await button.isDisabled();

    await box.fill('delete');
    await page.waitForTimeout(60);
    const lowerDisabled = await button.isDisabled();

    if (shots) {
        await section(page);
        await page.screenshot({ path: `${shots}/${label}-2-confirm-lowercase.png`, fullPage: false });
    }

    await box.fill('DELETE');
    await page.waitForTimeout(60);
    const upperDisabled = await button.isDisabled();

    if (shots) {
        await section(page);
        await page.screenshot({ path: `${shots}/${label}-3-confirm-armed.png`, fullPage: false });
    }

    // ── PRESS IT. The files are real and so is what happens to them. ─────────
    await button.click();
    await page.waitForFunction(
        () => !/^Deleting/.test(document.getElementById('kbb-purge-said').textContent || ''),
        null,
        { timeout: 15000 }
    );
    await section(page);

    const after = await state();
    const said = (await page.locator('#kbb-purge-said').textContent()).trim();
    const listedAfter = await page.locator('#kbb-purge-list tbody tr').count();

    if (shots) {
        await page.screenshot({ path: `${shots}/${label}-4-after.png`, fullPage: false });
    }

    report.viewports[label] = {
        ...(await widths(page)),
        filesOnDiskBefore: before.files,
        customersCsvBefore: before.customers_present,
        exportsListedBefore: listed,
        sensitiveNamedOnScreen: (sensitiveShown || '').trim(),
        buttonDisabledWithBoxEmpty: emptyDisabled,
        buttonDisabledAfterTypingLowercaseDelete: lowerDisabled,
        buttonDisabledAfterTypingDELETE: upperDisabled,
        filesOnDiskAfter: after.files,
        namesOnDiskAfter: after.names,
        customersCsvAfter: after.customers_present,
        guardsKept: after.guards,
        outsideTheFolderKept: after.outside,
        exportsListedAfter: listedAfter,
        whatTheScreenSays: said,
    };

    await page.close();
}

// ── The shop manager, who may run the export and may not destroy it ─────────
await seed();

const managerPage = await browser.newPage({ viewport: { width: 1280, height: 900 } });

await managerPage.goto(base + '/?caps=manage_woocommerce', { waitUntil: 'networkidle' });
await managerPage.locator('#kbb-delete-heading').scrollIntoViewIfNeeded();
await managerPage.waitForTimeout(120);

report.manager = {
    buttonPresent: (await managerPage.locator('#kbb-purge').count()) > 0,
    said: (await managerPage.locator('#kbb-delete-heading + p').textContent()).trim().replace(/\s+/g, ' '),
    filesStillOnDisk: (await state()).files,
};

if (shots) {
    await managerPage.screenshot({ path: `${shots}/1280-5-shop-manager-refused.png`, fullPage: false });
}

await managerPage.close();
await browser.close();

console.log(JSON.stringify(report, null, 2));
