/*
 * Lane SE evidence. The Set as it arrives in an inbox and as it comes off a
 * printer, photographed at 390px and at 1280px, with the numbers under each
 * picture.
 *
 * INPUT is docs/lane-se-shots/*.html, which tests/Feature/SetDocumentPreviews-
 * Test.php regenerates on every suite run from the real Mailables and the real
 * admin routes. Nothing here builds a document of its own: a picture of
 * something assembled by the screenshot tool is a picture of something the shop
 * does not send.
 *
 * THE BROWSER IS THE FULL CHROMIUM AT THE PATH BELOW. The pinned headless-shell
 * build is not in this container and `npx playwright install` is forbidden here,
 * so executablePath is passed explicitly — the same arrangement tools/set-
 * shots.cjs uses.
 *
 * ── WHY THE EMAILS GET A WRAPPER AND THE SHEETS DO NOT ─────────────────────
 *
 * A Mailable renders a message BODY: no <html>, no <head>, no <body>, because
 * Symfony Mime wraps the part and every client re-wraps it (see
 * resources/views/emails/layout.blade.php). A renderer needs a document, so one
 * is built around an UNTOUCHED copy of the body — the same wrapper, and the
 * same reasoning, as tools/render-email-previews.sh, and with no styling of its
 * own beyond the page ground, because anything added here would be a rule the
 * real mail client does not have. The printed sheets already are documents and
 * are loaded exactly as served.
 *
 * ── WHAT IS MEASURED, AND WHY THESE NUMBERS ────────────────────────────────
 *
 *   scrollWidth vs clientWidth   the member list is inset from the item name
 *                                and a long member name is the obvious way to
 *                                push a document sideways. At 390px an email
 *                                that scrolls horizontally is an email nobody
 *                                can read on a phone.
 *   memberLines                  what the member block actually says, so the
 *                                picture can be checked against the snapshot
 *                                rather than trusted.
 *   fontSize / width / inset     the member lines are meant to read as
 *                                subordinate to the item name and to sit inside
 *                                the cell, at both widths.
 *   ordinaryLineHasMembers       the half of the evidence a screenshot of a
 *                                Set alone cannot give: the ordinary product in
 *                                the same order is still bare.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const DIR = process.env.SE_DIR || path.join(__dirname, '..', 'docs', 'lane-se-shots');
const CHROME = process.env.SE_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

/* The two widths the owner asked for. 390 is a phone held in one hand; 1280 is
   a desktop mail client's window and an A4 sheet on screen. */
const WIDTHS = [390, 1280];

/* Which files are message bodies needing a document built round them. */
const IS_EMAIL = (name) => name.startsWith('email-');

const MEASURE = () => {
  const round = (n) => (n === null || n === undefined ? null : Math.round(n));
  const px = (el, p) => (el ? round(parseFloat(getComputedStyle(el)[p])) : null);

  /* A member line is a LEAF element whose text begins "<n> × ". That finds all
     three shapes this application emits without knowing any of their class
     names or inline styles: one <div> per member in the emails, and one joined
     ".it-sub" cell on the printed sheets. Deliberately structure-blind, so this
     script keeps measuring the right thing if the markup is restyled. */
  const leaves = [...document.querySelectorAll('*')].filter((el) => el.children.length === 0);
  const members = leaves.filter((el) => /^\d+\s*×\s/.test((el.textContent || '').trim()));

  const first = members[0] || null;

  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    /* The whole point of the number above: anything wider than the viewport is
       a horizontal scrollbar on the document. */
    overflowsSideways: document.documentElement.scrollWidth > document.documentElement.clientWidth,

    memberBlocks: members.length,
    memberLines: members.map((el) => el.textContent.trim()),
    memberFontSize: px(first, 'fontSize'),
    memberLineHeight: px(first, 'lineHeight'),
    memberWidth: first ? round(first.getBoundingClientRect().width) : null,
    memberRightEdge: first ? round(first.getBoundingClientRect().right) : null,

    /* The item names beside them, so a reader of the report can see the member
       text really is smaller than the name it belongs to. */
    setNameFontSize: (() => {
      const el = leaves.find((n) => (n.textContent || '').trim() === 'Glow Starter Set');
      return px(el, 'fontSize');
    })(),

    /* THE OTHER HALF OF THE EVIDENCE. The ordinary product is in the same
       order; if any member line mentions it, the change leaked off set lines. */
    ordinaryLinePresent: document.body.innerText.includes('Rice Daily Moisturizing Toner 150ml'),
    ordinaryLineHasMembers: members.some((el) => el.textContent.includes('Rice Daily Moisturizing Toner')),
  };
};

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const page = await browser.newPage();
  const report = {};

  const files = fs.readdirSync(DIR).filter((f) => f.endsWith('.html')).sort();

  for (const file of files) {
    const name = file.replace(/\.html$/, '');
    const body = fs.readFileSync(path.join(DIR, file), 'utf8');

    const html = IS_EMAIL(name)
      ? '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        + '<body style="margin:0;padding:0;background:#FFF8F5;">' + body + '</body>'
      : body;

    for (const width of WIDTHS) {
      await page.setViewportSize({ width, height: 900 });
      await page.setContent(html, { waitUntil: 'load' });
      /* A beat for webfonts and for the sheet's own print stylesheet to apply;
         nothing here waits on a network the documents do not use. */
      await page.waitForTimeout(250);

      const out = path.join(DIR, `${name}.${width}.png`);
      await page.screenshot({ path: out, fullPage: true });

      report[`${name} @ ${width}`] = await page.evaluate(MEASURE);
      console.log(out);
    }
  }

  await browser.close();

  fs.writeFileSync(path.join(DIR, 'measurements.json'), JSON.stringify(report, null, 2) + '\n');
  console.log('\n' + JSON.stringify(report, null, 2));
})();
