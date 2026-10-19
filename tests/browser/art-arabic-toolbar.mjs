/**
 * The Arabic article's own toolbar, in Chromium -- Lane ART.
 * Driven by tests/Feature/ArabicRichToolbarTest.php, which renders the REAL
 * admin partials (arabic-boxes, rte-media and the three editors that have an
 * Arabic rich-text twin) to a file and reads the JSON this prints.
 *
 * The owner, 19 October 2026, on Content → Blog Posts → Edit: "also i think
 * you didn't make the editors tools etc for arabic version. make it urgently
 * please." The Arabic box "العربية Arabic · The article" was a bare
 * contenteditable with no bar at all.
 *
 * Every screen is the real code, opened the way the console opens it; only
 * the network (fetch), the Media Library (kbbPickMedia) and the link prompt
 * are stubbed. Buttons are pressed with real mouse clicks. It REPORTS; the
 * Pest test judges.
 *
 *   KBB_ART_PAGE=<rendered partials.html> KBB_BROWSER_CHROME=<chrome> \
 *   node tests/browser/art-arabic-toolbar.mjs
 */
import fs from 'fs';
import { createRequire } from 'module';

const require = createRequire(import.meta.url);
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = await import('/home/user/kbbstore/node_modules/playwright-core/index.mjs')); }

const PARTIALS = fs.readFileSync(process.env.KBB_ART_PAGE, 'utf8');

const EN = '<p>English first paragraph words.</p><p>English second paragraph.</p>';
const AR = '<p>فقرة أولى عربية</p><p>فقرة ثانية</p>';
const cell = (v) => ({ value: v, status: v ? 'published' : '', source: v ? 'manual' : '', stale: false });

const STUB = `<script>
window.__saved = [];
window.prompt = function(){ return 'https://example.com/ar-link'; };
window.kbbPickMedia = function(o){ Promise.resolve().then(function(){ o.onPick(['/pic-ar.png']); }); };
window.go = function(){};
window.toast = function(){};
var EN = ${JSON.stringify(EN)}, AR = ${JSON.stringify(AR)};
var TR = function(fields){ var o = {ar:{}}; Object.keys(fields).forEach(function(k){ o.ar[k] = (${cell.toString()})(fields[k]); }); return o; };
window.fetch = function(url, opts){
  var u = String(url), body = opts && opts.body ? JSON.parse(opts.body) : null, out = {}; (window.__log = window.__log || []).push(u);
  if (body) window.__saved.push({url: u, body: body});
  if (/translations\\/settings/.test(u)) out = {has_api_key:false, provider_available:false};
  else if (/post-editor-bootstrap/.test(u)) out = {statuses:['published','draft'], translations: TR({title:'', excerpt:'', body:''})};
  else if (/post-editor-load\\/1/.test(u)) out = {post: {id:1, slug:'a', path:'/a/', title:'A title', excerpt:'', body:EN, cover:'', tag:'', author:'', status:'published', published_at:'', seo:{}, translations: TR({title:'', excerpt:'', body:AR})}};
  else if (/post-editor-save\\/1/.test(u)) out = {post: {id:1, slug:'a', path:'/a/', title:'A title', excerpt:'', body:body.body, cover:'', tag:'', author:'', status:'published', published_at:'', seo:{}, translations: TR({title:'', excerpt:'', body:body.translations.ar.body})}};
  else if (/page-editor-bootstrap/.test(u)) out = {statuses:['published','draft']};
  else if (/page-editor-load\\/1/.test(u)) out = {page: {id:1, slug:'about', path:'/about/', url:'/about/', routed:true, title:'About', content:EN, status:'published', seo:{}, translations: TR({title:'', content:AR})}};
  else if (/product-tabs$/.test(u)) out = {tabs:[], builtins:[], audiences:['global'], translations: TR({title:'', body:''})};
  return Promise.resolve(new Response(JSON.stringify(out), {status:200, headers:{'Content-Type':'application/json'}}));
};
</script>`;

const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font:14px/1.6 sans-serif;margin:0;padding:16px}</style>${STUB}</head><body><div id="content"></div>${PARTIALS}</body></html>`;

/* Select `text` inside `sel` (a whole word, or collapse at its end). */
const SELECT = ([sel, text, collapse]) => {
  const a = document.querySelector(sel);
  const w = document.createTreeWalker(a, NodeFilter.SHOW_TEXT);
  let n;
  while ((n = w.nextNode())) {
    const i = n.data.indexOf(text);
    if (i < 0) continue;
    const r = document.createRange();
    r.setStart(n, collapse ? i + text.length : i); r.setEnd(n, i + text.length);
    a.focus();
    const s = getSelection(); s.removeAllRanges(); s.addRange(r);
    return true;
  }
  return false;
};

const out = { ok: true, error: '', post: {}, page: {}, tabs: {} };
let browser;
const errors = [];
try {
  browser = await chromium.launch({ executablePath: process.env.KBB_BROWSER_CHROME });
  const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  p.on('pageerror', (e) => errors.push(String(e)));
  p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  /* A real origin: the editors read document.cookie (the XSRF token), which
     throws on about:blank. Nothing leaves the browser -- this route answers. */
  await p.route('http://kbb.test/**', (r) => r.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html }));
  await p.goto('http://kbb.test/admin', { waitUntil: 'load' });

  const EN_SEL = '#pj-body', AR_SEL = '[data-kbbar-box="body"] [data-kbbar-rich]';
  const AR_BAR = '[data-kbbar-box="body"] .kbbar-bar';

  /* ---------------------------------------------------------- blog post */
  await p.evaluate(() => window.KBBPostEditor.open(1));
  await p.waitForSelector(AR_SEL);
  const post = out.post;
  const labels = (bar) => p.$$eval(bar + ' button', (bs) => bs.map((b) => b.textContent.trim()));
  post.enLabels = await labels('.pj-rte > .pj-rte-bar');
  post.arLabels = await labels(AR_BAR);
  post.arDir = await p.$eval(AR_BAR, (b) => getComputedStyle(b).direction);
  /* RTL: the first button (B) is the RIGHT-most one. Read once for the
     report; the page itself never measures anything. */
  post.arFirstIsRightmost = await p.$$eval(AR_BAR + ' button', (bs) => bs[0].getBoundingClientRect().left > bs[bs.length - 1].getBoundingClientRect().left);
  post.arWordsInitial = await p.$eval('[data-pj-ar-words]', (e) => e.textContent);
  const en0 = await p.$eval(EN_SEL, (a) => a.innerHTML);

  // Bold in the Arabic box.
  await p.evaluate(SELECT, [AR_SEL, 'أولى']);
  await p.click(AR_BAR + ' [data-cmd="bold"]');
  post.arAfterBold = await p.$eval(AR_SEL, (a) => a.innerHTML);
  post.enAfterBold = await p.$eval(EN_SEL, (a) => a.innerHTML);

  // A selection left in the ENGLISH box, then the ARABIC bar's B.
  await p.evaluate(SELECT, [EN_SEL, 'second']);
  await p.click(AR_BAR + ' [data-cmd="bold"]');
  post.enAfterCrossBold = await p.$eval(EN_SEL, (a) => a.innerHTML);

  // A link in the Arabic box.
  await p.evaluate(SELECT, [AR_SEL, 'ثانية']);
  await p.click(AR_BAR + ' [data-cmd="createLink"]');
  post.arAfterLink = await p.$eval(AR_SEL, (a) => a.innerHTML);
  post.enAfterLink = await p.$eval(EN_SEL, (a) => a.innerHTML);

  // H2 on the Arabic second paragraph.
  await p.evaluate(SELECT, [AR_SEL, 'ثانية', true]);
  await p.click(AR_BAR + ' [data-cmd="formatBlock:h2"]');
  post.arAfterH2 = await p.$eval(AR_SEL, (a) => a.innerHTML);

  // Media at the caret: the end of the first Arabic paragraph.
  await p.evaluate(SELECT, [AR_SEL, 'عربية', true]);
  await p.click(AR_BAR + ' [data-rte-media]');
  await p.waitForFunction((s) => !!document.querySelector(s + ' .kbb-gal'), AR_SEL);
  post.arMedia = await p.$eval(AR_SEL, (a) => {
    const g = a.querySelector('.kbb-gal');
    const prev = g.previousElementSibling;
    let next = g.nextElementSibling;
    while (next && next.textContent.trim() === '') next = next.nextElementSibling;
    return { cls: g.className, src: g.querySelector('img').getAttribute('src'),
      before: prev ? prev.textContent : '', after: next ? next.textContent : '' };
  });
  post.enAfterMedia = await p.$eval(EN_SEL, (a) => a.innerHTML);
  post.enGalleries = await p.$$eval(EN_SEL + ' .kbb-gal', (g) => g.length);
  post.arWordsAfter = await p.$eval('[data-pj-ar-words]', (e) => e.textContent);
  post.enUntouched = [post.enAfterBold, post.enAfterCrossBold, post.enAfterLink, post.enAfterMedia].every((h) => h === en0);

  // The reverse: the English bar's B, with the selection in English.
  const arBeforeEn = await p.$eval(AR_SEL, (a) => a.innerHTML);
  await p.evaluate(SELECT, [EN_SEL, 'first']);
  await p.click('.pj-rte > .pj-rte-bar [data-cmd="bold"]');
  post.enAfterEnBold = await p.$eval(EN_SEL, (a) => a.innerHTML);
  post.arUntouchedByEn = (await p.$eval(AR_SEL, (a) => a.innerHTML)) === arBeforeEn;

  // Save: what the server is sent.
  await p.click('#pj-save');
  await p.waitForFunction(() => window.__saved.some((s) => /post-editor-save/.test(s.url)));
  const saved = await p.evaluate(() => window.__saved.filter((s) => /post-editor-save/.test(s.url)).pop().body);
  post.savedBody = saved.body;
  post.savedArBody = saved.translations && saved.translations.ar ? saved.translations.ar.body : null;
  post.errors = errors.splice(0);

  /* --------------------------------------------------------- the page */
  await p.evaluate(() => window.KBBPageEditor.open(1));
  const PG_AR = '[data-kbbar-box="content"] [data-kbbar-rich]';
  await p.waitForSelector(PG_AR);
  const page = out.page;
  page.enLabels = await labels('.pg-rte > .pg-rte-bar');
  page.arLabels = await labels('[data-kbbar-box="content"] .kbbar-bar');
  const pgEn0 = await p.$eval('#pg-content', (a) => a.innerHTML);
  await p.evaluate(SELECT, [PG_AR, 'أولى']);
  await p.click('[data-kbbar-box="content"] .kbbar-bar [data-cmd="bold"]');
  page.arAfterBold = await p.$eval(PG_AR, (a) => a.innerHTML);
  page.enUntouched = (await p.$eval('#pg-content', (a) => a.innerHTML)) === pgEn0;
  page.arWords = await p.$eval('[data-pg-ar-words]', (e) => e.textContent);
  page.errors = errors.splice(0);

  /* ------------------------------------------------- product tabs */
  await p.evaluate(() => { document.getElementById('content').innerHTML = ''; window.go('product-tabs'); });
  await p.waitForSelector('[data-kpt-open="new-global"]');
  await p.click('[data-kpt-open="new-global"]');
  const KT_AR = '[data-kbbar-box="body"] [data-kbbar-rich]';
  await p.waitForSelector(KT_AR);
  const tabs = out.tabs;
  tabs.enLabels = await labels('.kpt-rte > .kpt-rte-bar');
  tabs.arLabels = await labels('[data-kbbar-box="body"] .kbbar-bar');
  await p.$eval('[data-kpt-body]', (a) => { a.innerHTML = '<p>English tab text</p>'; });
  await p.$eval(KT_AR, (a, h) => { a.innerHTML = h; }, AR);
  await p.evaluate(SELECT, [KT_AR, 'أولى']);
  await p.click('[data-kbbar-box="body"] .kbbar-bar [data-kpt-cmd="bold"]');
  tabs.arAfterBold = await p.$eval(KT_AR, (a) => a.innerHTML);
  tabs.enAfter = await p.$eval('[data-kpt-body]', (a) => a.innerHTML);
  await p.evaluate(SELECT, ['[data-kpt-body]', 'tab']);
  await p.click('.kpt-rte > .kpt-rte-bar [data-kpt-cmd="bold"]');
  tabs.enAfterEnBold = await p.$eval('[data-kpt-body]', (a) => a.innerHTML);
  tabs.arAfterEnBold = await p.$eval(KT_AR, (a) => a.innerHTML);
  tabs.errors = errors.splice(0);
} catch (e) {
  out.ok = false; out.error = String(e && e.stack || e); try { out.dom = await (await browser.contexts()[0].pages()[0]).evaluate(() => JSON.stringify(window.__log) + document.getElementById("content").innerHTML.slice(0, 500)); out.errs = errors; } catch (x) {}
} finally {
  if (browser) await browser.close();
}
console.log(JSON.stringify(out));
