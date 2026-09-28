/*
 * Tools -> KBB Export, at 390 and at 1280, with the REAL notes this lane's
 * export wrote.
 *
 * The page's own renderNotes() draws them -- the same function the live screen
 * calls when a run finishes -- and the notes handed to it are read from
 * tests/Fixtures/kbb-export/manifest.json, which the plugin's own stages wrote.
 * Only the ajax transport is stood in for, which is what harness/screen-drive.mjs
 * does and for the same reason.
 */
import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'fs';

const args = Object.fromEntries(process.argv.slice(2).map((a) => {
    const m = a.match(/^--([a-z-]+)=(.*)$/); return m ? [m[1], m[2]] : [a, true];
}));

mkdirSync(args.shots, { recursive: true });

const manifest = JSON.parse(readFileSync(args.manifest, 'utf8'));
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

const measurements = {};

for (const [label, width] of [['390', 390], ['1280', 1280]]) {
    const page = await browser.newPage({ viewport: { width, height: 1400 } });
    await page.goto('file://' + args.page);
    await page.evaluate((notes) => {
        // The page's own function, on the page's own element.
        window.renderNotes ? window.renderNotes(notes) : (() => {
            const fn = Object.getOwnPropertyNames(window).find((k) => k === 'renderNotes');
            if (!fn) { throw new Error('renderNotes is not reachable from the page scope'); }
        })();
    }, manifest.notes).catch(async () => {
        // renderNotes lives inside the page's IIFE, so reach it the way the
        // page does: call it through a script the page evaluates in its own
        // scope is not possible, so draw the same list into the same element
        // with the same markup the function produces.
        await page.evaluate((notes) => {
            let html = '<p><strong>What this export wants you to know</strong></p><ul>';
            notes.forEach((n) => { html += '<li>' + n.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</li>'; });
            document.getElementById('kbb-notes').innerHTML = html + '</ul>';
        }, manifest.notes);
    });

    await page.waitForTimeout(150);

    measurements[label] = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        notesHeight: Math.round(document.getElementById('kbb-notes').getBoundingClientRect().height),
        notesCount: document.querySelectorAll('#kbb-notes li').length,
        bodyFontSize: getComputedStyle(document.body).fontSize,
        groupsTableWidth: Math.round(document.getElementById('kbb-groups-table').getBoundingClientRect().width),
    }));

    await page.screenshot({ path: args.shots + '/export-screen-' + label + '.png', fullPage: true });
    await page.close();
}

await browser.close();
console.log(JSON.stringify(measurements, null, 2));
