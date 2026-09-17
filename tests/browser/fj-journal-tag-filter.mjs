/**
 * What the Journal's tag filter actually does when a shopper clicks a chip.
 *
 * A "the highlight follows the click" claim cannot be settled by reading the
 * script: the defect this pins was that the active chip was found by comparing
 * its RENDERED TEXT against the tag value, and rendered text is a thing only a
 * parser and a layout engine produce. The hostile-tag case is the same point
 * twice over — whether a tag containing markup becomes an element depends
 * entirely on how the browser parsed the chip, so a string assertion about the
 * template proves nothing about the page.
 *
 * This REPORTS, it does not judge. It loads a page of already-rendered HTML,
 * clicks every chip in turn, and prints what it saw; the PHP side decides what
 * those observations mean, so the same script produces the before and the
 * after.
 *
 * `file://` rather than a preview server, deliberately. The page under test is
 * a complete standalone document whose filter is inline and needs no network,
 * so a server would add a port to leak and prove nothing extra.
 */
import { chromium } from 'playwright';

const file = process.env.KBB_BROWSER_FILE;

const out = { ok: false, file, initial: null, clicks: [], dialogs: [], errors: [], chipsHtml: '' };

let browser;

try {
    browser = await chromium.launch({
        executablePath: process.env.KBB_BROWSER_CHROME,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const page = await browser.newPage();

    // An alert() reaching here is a tag that executed. Dismissed rather than
    // left open, so one hostile fixture cannot hang the run.
    page.on('dialog', async (d) => { out.dialogs.push(d.message()); await d.dismiss(); });
    page.on('pageerror', (e) => out.errors.push(String(e.message)));

    await page.goto('file://' + file);

    const read = () => page.evaluate(() => ({
        chips: [...document.querySelectorAll('#chips .chip')].map((c) => ({
            label: c.textContent,
            on: c.classList.contains('on'),
            tag: 'tag' in c.dataset ? c.dataset.tag : null,
        })),
        visible: [...document.querySelectorAll('#grid .post')]
            .filter((a) => a.style.display !== 'none')
            .map((a) => a.dataset.tag),
    }));

    out.initial = await read();

    for (let i = 0; i < out.initial.chips.length; i++) {
        // .click() on the element itself rather than page.click(): a chip whose
        // own handler is broken must report as "nothing happened" rather than
        // as a timeout waiting for an actionable element.
        await page.evaluate((n) => document.querySelectorAll('#chips .chip')[n].click(), i);
        out.clicks.push(await read());
    }

    out.chipsHtml = await page.evaluate(() => document.getElementById('chips').innerHTML);
    out.ok = true;
} catch (e) {
    out.error = String(e && e.message ? e.message : e);
} finally {
    if (browser) await browser.close();
}

process.stdout.write(JSON.stringify(out));
