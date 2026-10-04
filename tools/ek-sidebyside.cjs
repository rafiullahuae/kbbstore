/* Lane EK — "mock | built" pictures: the approved mock on the left, the built
   screen on the right, same width, for docs/ek-shots/. */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const ROOT = path.join(__dirname, '..');
const pairs = [
  ['docs/rj-email-previews/admin/e3-customer-emails-1280.jpg', 'docs/ek-shots/e3-customer-emails-1280.png', 'e3-side-by-side-1280', 1280],
  ['docs/rj-email-previews/admin/e3-customer-emails-390.jpg', 'docs/ek-shots/e3-customer-emails-390.png', 'e3-side-by-side-390', 390],
  ['docs/rj-email-previews/admin/e4-template-editor-1280.jpg', 'docs/ek-shots/e4-template-editor-1280.png', 'e4-side-by-side-1280', 1280],
  ['docs/rj-email-previews/admin/e4-template-editor-390.jpg', 'docs/ek-shots/e4-template-editor-390.png', 'e4-side-by-side-390', 390],
];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [mock, built, out, w] of pairs) {
    const page = await b.newPage({ viewport: { width: w * 2 + 60, height: 800 } });
    const src = (p) => 'data:image/' + (p.endsWith('.jpg') ? 'jpeg' : 'png') + ';base64,' + fs.readFileSync(path.join(ROOT, p)).toString('base64');
    await page.setContent(`<body style="margin:0;background:#fff;font:600 14px sans-serif;display:flex;gap:20px;padding:10px 10px;align-items:flex-start">
      <div><div style="padding:6px 0">APPROVED MOCK</div><img src="${src(mock)}" style="width:${w}px;display:block;border:1px solid #ddd"></div>
      <div><div style="padding:6px 0">BUILT</div><img src="${src(built)}" style="width:${w}px;display:block;border:1px solid #ddd"></div></body>`);
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(ROOT, 'docs/ek-shots', out + '.png'), fullPage: true });
    await page.close();
  }
  await b.close();
  console.log('done');
})();
