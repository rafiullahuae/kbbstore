/**
 * The Gallery bar's Columns buttons after a picture is clicked, in Chromium.
 * Driven by tests/Feature/RteGalleryColumnsAfterPictureTest.php.
 *
 * The owner, 19 October 2026: "after inserting image, when click on image, then
 * the column options is not giving to change, it's freezed there." Clicking a
 * picture opens its link/alt dialog, whose full-screen backdrop sat ABOVE the
 * Gallery bar (z-index 100000 over 99999), and closing that dialog bubbled to
 * the document click handler, which hid the bar. It REPORTS; Pest judges.
 *
 *   KBB_GBC_PARTIAL=<rendered partial.html> node tests/browser/gallery-bar-columns.mjs
 */
import fs from 'fs';
import { createRequire } from 'module';

const require = createRequire(import.meta.url);
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = await import('/home/user/kbbstore/node_modules/playwright-core/index.mjs')); }

const PARTIAL = fs.readFileSync(process.env.KBB_GBC_PARTIAL, 'utf8');
const IMG = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600" fill="#e9a"/></svg>');
const html = `<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px/1.6 sans-serif;margin:0;padding:16px}.kbb-gal{display:grid;gap:8px}.kbb-gal-1{grid-template-columns:1fr}.kbb-gal-2{grid-template-columns:1fr 1fr}.kbb-gal-3{grid-template-columns:repeat(3,1fr)}.kbb-gal img{width:100%;display:block}</style></head><body>
<div class="pj-rte-area" id="pj-body" contenteditable="true"><p>Before.</p><div class="kbb-gal kbb-gal-1"><img src="${IMG}" alt=""></div><p>After.</p></div>
${PARTIAL}</body></html>`;

const out = {};
const browser = await chromium.launch(process.env.KBB_BROWSER_CHROME ? { executablePath: process.env.KBB_BROWSER_CHROME } : {});
try {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.setContent(html);
  await page.click('#pj-body p');                      // focus the box (locks galleries)
  await page.click('.kbb-gal img');                    // opens the picture dialog and the bar
  out.dialogOpen = await page.isVisible('.rgm-shade:not([hidden]) .rgm-dlg');
  out.barVisible = await page.isVisible('.rgm-bar:not([hidden])');
  const b2 = await page.$('.rgm-bar button[data-c="2"]');
  const box = b2 ? await b2.boundingBox() : null;
  out.topAtButton2 = box ? await page.evaluate(([x, y]) => { const e = document.elementFromPoint(x, y); return e ? (e.closest('.rgm-bar') ? 'bar' : e.className || e.tagName) : null; }, [box.x + box.width / 2, box.y + box.height / 2]) : null;
  if (box) await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  out.classWhileDialog = await page.getAttribute('.kbb-gal', 'class');
  out.barAfterColumn = await page.isVisible('.rgm-bar:not([hidden])');
  await page.click('.rgm-dlg [data-x]');               // Cancel the picture dialog
  out.barAfterCancel = await page.isVisible('.rgm-bar:not([hidden])');
  if (out.barAfterCancel) await page.click('.rgm-bar button[data-c="3"]');
  out.classAfterCancel = await page.getAttribute('.kbb-gal', 'class');
  await page.click('.kbb-gal img');
  await page.click('.rgm-dlg [data-ok]');              // Save the picture dialog
  await page.waitForTimeout(150);
  out.barAfterSave = await page.isVisible('.rgm-bar:not([hidden])');
  if (out.barAfterSave) await page.click('.rgm-bar button[data-c="1"]');
  out.classAfterSave = await page.getAttribute('.kbb-gal', 'class');
  if (await page.isVisible('.rgm-bar:not([hidden])')) await page.click('.rgm-bar button[data-done]');
  out.barAfterDone = await page.isVisible('.rgm-bar:not([hidden])');
  out.errors = errors;
} finally {
  await browser.close();
}
process.stdout.write(JSON.stringify(out));
