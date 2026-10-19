/**
 * Where a picture lands in the admin's rich-text boxes, in Chromium -- Lane BIM.
 * Driven by tests/Feature/RteMediaInsertPlaceTest.php, which renders the ONE
 * shared gallery script (admin/partials/rte-media) to a file and reads the JSON
 * this prints.
 *
 * The owner, 19 October 2026: "in blog when i insert image. the image change
 * it's position to random ... i added the image at one place, it's added in
 * some different location." Each case below sets up an article body the way
 * the blog post editor draws it (toolbar + contenteditable #pj-body, and the
 * Arabic box beside it), places the cursor, presses the real Media button with
 * a real mouse click and reports what sits either side of the new gallery.
 * The Media Library is stubbed to answer one or two addresses (it is an
 * existing, separately tested picker); everything after it is the real code.
 *
 * It REPORTS; the Pest test judges.
 *
 *   KBB_BIM_PARTIAL=<rendered partial.html> KBB_BROWSER_CHROME=<chrome> \
 *   node tests/browser/bim-gallery-insert.mjs
 */
import fs from 'fs';
import { createRequire } from 'module';

const require = createRequire(import.meta.url);
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = await import('/home/user/kbbstore/node_modules/playwright-core/index.mjs')); }

const PARTIAL = fs.readFileSync(process.env.KBB_BIM_PARTIAL, 'utf8');

const paras = (n, from = 1) => Array.from({ length: n }, (_, i) => `<p>Paragraph ${i + from} with <a href="/x">a link</a> and words of ${i + from}.</p>`).join('');
const BODIES = {
  plain: paras(10),
  list: paras(5) + '<ul><li>Bullet one</li><li>Bullet two</li><li>Bullet three</li></ul>' + paras(5, 6),
  heading: paras(3) + '<h2>A heading</h2>' + paras(7, 4),
  wrapped: '<div class="entry">' + paras(10) + '</div>',
  docs: '<b class="docs">' + paras(10) + '</b>',
  lines: Array.from({ length: 10 }, (_, i) => `Paragraph ${i + 1} with <a href="/x">a link</a> words.`).join('<br>'),
  gallery: paras(3) + '<div class="kbb-gal kbb-gal-2"><img src="/old1.png" alt=""><img src="/old2.png" alt=""></div>' + paras(7, 4),
};

function page(body) {
  return `<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px/1.6 sans-serif;margin:0;padding:16px}.pj-rte-area{min-height:200px;border:1px solid #ddd;padding:12px}.pj-rte-area p{margin:0 0 .7em}</style></head><body>
<input id="pj-title" value="A title">
<div class="pj-rte"><div class="pj-rte-bar"><button type="button" data-cmd="bold">B</button><button type="button" data-cmd="kbbMedia" data-rte-media id="m-en">Media</button></div>
<div class="pj-rte-area" id="pj-body" contenteditable="true">${body}</div></div>
<div style="height:1600px"></div>
<div class="ar-rich"><div class="bar"><button type="button" data-rte-media id="m-ar">Media</button></div>
<div class="pj-rte-area" id="ar-body" dir="rtl" contenteditable="true"><p>فقرة أولى</p><p>فقرة ثانية</p><p>فقرة ثالثة</p></div></div>
<div style="height:1600px"></div>
${PARTIAL}</body></html>`;
}

/* The cursor at a text: `at` is 'end' of the node holding `text`, or 'start'
   of its block, or an offset just after `text`. */
const CARET = ([sel, text, where]) => {
  const a = document.querySelector(sel);
  const w = document.createTreeWalker(a, NodeFilter.SHOW_TEXT);
  let n;
  while ((n = w.nextNode())) {
    const i = n.data.indexOf(text);
    if (i < 0) continue;
    const r = document.createRange();
    if (where === 'start') r.setStart(n, i); else r.setStart(n, i + text.length);
    r.collapse(true);
    a.focus();
    const s = getSelection(); s.removeAllRanges(); s.addRange(r);
    return true;
  }
  return false;
};

/* What is either side of the newest gallery: the words just before it and
   just after it, and where it sits. */
const WHERE = (sel) => {
  const a = document.querySelector(sel);
  const gs = [...a.querySelectorAll('.kbb-gal')].filter((g) => !g.querySelector('img[src^="/old"]'));
  const g = gs[gs.length - 1];
  if (!g) return { found: false };
  const before = document.createRange(); before.setStart(a, 0); before.setEndBefore(g);
  const after = document.createRange(); after.setStartAfter(g); after.setEnd(a, a.childNodes.length);
  const t = (s) => s.replace(/\s+/g, ' ').trim();
  return {
    found: true,
    galleries: gs.length,
    cls: g.className,
    imgs: g.querySelectorAll('img').length,
    parent: g.parentNode === a ? 'box' : g.parentNode.tagName.toLowerCase() + (g.parentNode.className ? '.' + g.parentNode.className : ''),
    insideLi: !!g.closest('li'),
    before: t(before.toString()).slice(-24),
    after: t(after.toString()).slice(0, 24),
    otherBoxGalleries: [...document.querySelectorAll('[contenteditable="true"]')].filter((x) => x !== a).reduce((k, x) => k + x.querySelectorAll('.kbb-gal').length, 0),
  };
};

const CASES = [
  // [name, body, setup(page), media button, box]
  ['endOfP3', 'plain', async (p) => p.evaluate(CARET, ['#pj-body', 'words of 3.', 'end'])],
  ['row2EndOfP3', 'plain', async (p) => p.evaluate(CARET, ['#pj-body', 'words of 3.', 'end']), 2],
  ['inList', 'list', async (p) => p.evaluate(CARET, ['#pj-body', 'Bullet two', 'end'])],
  ['afterHeading', 'heading', async (p) => p.evaluate(CARET, ['#pj-body', 'A heading', 'end'])],
  ['wrapped', 'wrapped', async (p) => p.evaluate(CARET, ['#pj-body', 'Paragraph 3 with', 'end'])],
  ['docsWrapper', 'docs', async (p) => p.evaluate(CARET, ['#pj-body', 'Paragraph 3 with', 'end'])],
  ['plainLines', 'lines', async (p) => p.evaluate(CARET, ['#pj-body', 'Paragraph 3 with', 'end'])],
  ['startOfP4', 'plain', async (p) => p.evaluate(CARET, ['#pj-body', 'Paragraph 4', 'start'])],
  ['afterGallery', 'gallery', async (p) => p.evaluate(() => {
    const a = document.getElementById('pj-body'); const g = a.querySelector('.kbb-gal');
    const r = document.createRange(); r.setStartAfter(g); r.collapse(true);
    a.focus(); const s = getSelection(); s.removeAllRanges(); s.addRange(r);
  })],
  // A real click in the middle of paragraph 3's words.
  ['clickedP3', 'plain', async (p) => { await p.click('#pj-body p:nth-child(3)', { position: { x: 40, y: 8 } }); }],
  // Cursor placed, then the page scrolled away and back to the toolbar.
  ['afterScroll', 'plain', async (p) => {
    await p.evaluate(CARET, ['#pj-body', 'Paragraph 7 with', 'end']);
    await p.mouse.wheel(0, 2500); await p.waitForTimeout(80); await p.mouse.wheel(0, -5000); await p.waitForTimeout(80);
  }],
  // Cursor placed in the article, then a click in the title box: the
  // selection has left the article before Media is pressed.
  ['afterTitle', 'plain', async (p) => {
    await p.evaluate(CARET, ['#pj-body', 'Paragraph 5 with', 'end']);
    await p.click('#pj-title'); await p.keyboard.type(' more');
  }],
  // Cursor placed, then words typed further up the article.
  ['typedAbove', 'plain', async (p) => {
    await p.evaluate(CARET, ['#pj-body', 'Paragraph 6 with', 'end']);
    await p.click('#pj-title');
    await p.evaluate(CARET, ['#pj-body', 'Paragraph 1 with', 'end']);
    await p.keyboard.type(' typed here');
    await p.click('#pj-title');
  }, 1, '#m-en', 'Paragraph 1 with'],
  // Nothing placed since the page opened: the end.
  ['freshLoad', 'plain', async () => {}],
  // The Arabic box's own button.
  ['arabic', 'plain', async (p) => {
    await p.evaluate(CARET, ['#pj-body', 'Paragraph 2 with', 'end']);
    await p.evaluate(CARET, ['#ar-body', 'فقرة أولى', 'end']);
  }, 1, '#m-ar'],
];

const browser = await chromium.launch({ executablePath: process.env.KBB_BROWSER_CHROME, args: ['--no-sandbox'] });
const out = { ok: true, cases: {} };
try {
  for (const [name, body, setup, count = 1, btn = '#m-en'] of CASES) {
    const p = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    const errors = [];
    p.on('pageerror', (e) => errors.push(String(e)));
    await p.setContent(page(BODIES[body]));
    await p.evaluate((k) => {
      window.kbbPickMedia = (o) => Promise.resolve().then(() => o.onPick(k === 2 ? ['/new1.png', '/new2.png'] : ['/new1.png']));
    }, count);
    await setup(p);
    await p.click(btn);
    if (count === 2) { await p.click('.rgm-shade:not([hidden]) [data-n="2"]'); await p.click('.rgm-shade:not([hidden]) [data-ok]'); }
    await p.waitForTimeout(60);
    out.cases[name] = { ...(await p.evaluate(WHERE, btn === '#m-ar' ? '#ar-body' : '#pj-body')), errors };
    if (process.env.KBB_BIM_SHOTS && ['row2EndOfP3', 'inList'].includes(name)) {
      await p.locator('#pj-body').screenshot({ path: `${process.env.KBB_BIM_SHOTS}/editor-${name}.png` });
    }
    await p.close();
  }
} catch (e) {
  out.ok = false; out.error = String(e && e.stack || e);
}
await browser.close();
process.stdout.write(JSON.stringify(out));
