/*
 * Lane HTE -- the Grid sections editor, laid out as steps: screenshots at 1280
 * and 390, the measured numbers, and ONE frozen self-contained copy of the live
 * editor for the owner (docs/lane-hte/editor-live.html).
 *
 *   BASE=http://127.0.0.1:<port> NODE_PATH=$(npm root -g) node tools/hte-shots.cjs
 *
 * Against a tools/htb-preview.sh server (PREVIEW DATABASE ONLY): it creates a
 * "Product tabs" section from the preset and fills its three tabs in English
 * and Arabic through the editor's own API.
 *
 * Freezing: every stylesheet is inlined as its TEXT (fetch(sheet.href), or the
 * <style>'s own textContent -- never cssRules, which drops var() shorthands),
 * fonts and images become data: URIs, every <script> is removed, and one tiny
 * script is added so the tab cards' arrows and the tabs on/off switch still
 * toggle. The tabs-off state is the live screen's own rendering, captured.
 */
const fs = require('fs');
const path = require('path');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const { chromium } = pw;

const BASE = process.env.BASE || 'http://127.0.0.1:9641';
const OUT = path.join(__dirname, '..', 'docs', 'lane-hte');
fs.mkdirSync(OUT, { recursive: true });

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
}

async function api(page, p, method, body) {
  return page.evaluate(async ([p, method, body]) => {
    const m = document.cookie.match(/(^|;)\s*XSRF-TOKEN\s*=\s*([^;]+)/);
    const r = await fetch('/admin-api' + p, { method, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[2]) : '' },
      body: body ? JSON.stringify(body) : undefined });
    return { status: r.status, body: await r.json().catch(() => null) };
  }, [p, method, body]);
}

async function openEditor(page, id) {
  await page.goto(BASE + '/admin?go=gridsections', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-gss-edit="' + id + '"]');
  await page.click('[data-gss-edit="' + id + '"]');
  await page.waitForSelector('[data-gss-step="tabs"]');
  await page.waitForSelector('#gss-stage iframe', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1200);
}

async function measure(page) {
  return page.evaluate(() => {
    const r = s => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height) }; };
    const fs = s => { const e = document.querySelector(s); return e ? getComputedStyle(e).fontSize : null; };
    return {
      viewport: innerWidth, scrollWidth: document.documentElement.scrollWidth,
      steps: [...document.querySelectorAll('[data-gss-step]')].map(s => s.dataset.gssStep),
      headingRow: r('.gss-pr:nth-child(3)'), englishBox: r('#gss-f-heading'), arabicBox: r('[data-gss-t="heading_ar"]'),
      tabCard0: r('[data-gss-tcard="0"]'), tabCard1: r('[data-gss-tcard="1"]'),
      labelFont: fs('.gss-lab'), inputFont: fs('.gss-in'), stepTitleFont: fs('.gss-shx .gss-title'),
      overflowing: [...document.querySelectorAll('#content *')].filter(e => e.getBoundingClientRect().right > innerWidth + 1).map(e => e.tagName + '.' + e.className),
    };
  });
}

async function freeze(page, offHTML) {
  return page.evaluate(async (offHTML) => {
    const toData = async (url) => {
      try {
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!r.ok) return null;
        const b = await r.blob();
        return await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(b); });
      } catch (e) { return null; }
    };
    const inlineUrls = async (css, base) => {
      const urls = [...new Set([...css.matchAll(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g)].map(m => m[2]).filter(u => !u.startsWith('data:') && !u.startsWith('#')))];
      for (const u of urls) {
        const abs = new URL(u, base).href;
        const d = await toData(abs);
        if (d) css = css.split(u).join(d);
      }
      return css;
    };

    const doc = document.documentElement.cloneNode(true);

    // Stylesheets as TEXT, in document order.
    const live = [...document.querySelectorAll('link[rel="stylesheet"], style')];
    const copies = [...doc.querySelectorAll('link[rel="stylesheet"], style')];
    for (let i = 0; i < live.length; i++) {
      const el = live[i], copy = copies[i];
      let text = '', base = location.href;
      if (el.tagName === 'LINK') {
        base = el.href;
        try { text = await (await fetch(el.href, { credentials: 'same-origin' })).text(); } catch (e) { text = ''; }
      } else {
        text = el.textContent;
      }
      const st = document.createElement('style');
      st.textContent = await inlineUrls(text, base);
      if (el.media && el.tagName === 'LINK') st.media = el.media;
      copy.replaceWith(st);
    }

    // Scripts, preloads and anything that would reach back to the server go.
    doc.querySelectorAll('script, link[rel="preload"], link[rel="modulepreload"], link[rel="prefetch"], link[rel="icon"], link[rel="manifest"]').forEach(e => e.remove());
    doc.querySelectorAll('[onclick],[onchange],[oninput],[onsubmit]').forEach(e => { ['onclick', 'onchange', 'oninput', 'onsubmit'].forEach(a => e.removeAttribute(a)); });

    // Images as data: URIs.
    for (const img of doc.querySelectorAll('img[src]')) {
      const d = await toData(new URL(img.getAttribute('src'), location.href).href);
      if (d) img.setAttribute('src', d); else img.removeAttribute('src');
      img.removeAttribute('srcset');
    }

    // The preview frame: its own stylesheets and pictures inlined too.
    const frame = doc.querySelector('#gss-stage iframe');
    if (frame) {
      let src = frame.getAttribute('srcdoc') || '';
      const links = [...src.matchAll(/<link rel="stylesheet" href="([^"]+)">/g)];
      for (const m of links) {
        const href = new URL(m[1].replace(/&amp;/g, '&'), location.href).href;
        let css = '';
        try { css = await (await fetch(href)).text(); } catch (e) {}
        css = await inlineUrls(css, href);
        src = src.split(m[0]).join('<style>' + css.replace(/<\/style/gi, '<\\/style') + '</style>');
      }
      const imgs = [...new Set([...src.matchAll(/<img[^>]*?\ssrc="([^"]+)"/g)].map(m => m[1]))];
      for (const u of imgs) {
        const d = await toData(new URL(u.replace(/&amp;/g, '&'), location.href).href);
        if (d) src = src.split('src="' + u + '"').join('src="' + d + '"');
      }
      src = src.replace(/\s(srcset|sizes)="[^"]*"/g, '').replace(/<script[\s\S]*?<\/script>/gi, '');
      frame.setAttribute('srcdoc', src);
    }

    // The tabs-off rendering, captured from the live screen, for the switch.
    const tpl = document.createElement('template');
    tpl.id = 'hte-off';
    tpl.innerHTML = offHTML;
    doc.querySelector('body').appendChild(tpl);

    const note = document.createElement('div');
    note.setAttribute('style', 'position:fixed;left:12px;bottom:12px;z-index:9999;background:#111827;color:#fff;font:12px/1.4 system-ui,sans-serif;padding:7px 11px;border-radius:9px;max-width:calc(100vw - 24px)');
    note.textContent = 'Frozen copy of Appearance → Grid sections → Edit (Lane HTE). The arrows and the tabs switch work; nothing saves.';
    doc.querySelector('body').appendChild(note);

    const js = document.createElement('script');
    js.textContent = "(function(){var c=document.querySelector('#content'),on=c.innerHTML,off=document.querySelector('#hte-off').innerHTML;"
      + "document.addEventListener('click',function(e){var b=e.target.closest('[data-gss-tfold]');if(!b)return;var k=b.closest('.gss-tab');var o=!k.classList.contains('is-open');k.classList.toggle('is-open',o);b.setAttribute('aria-expanded',o?'true':'false');});"
      + "document.addEventListener('change',function(e){var t=e.target;if(t.matches('[data-gss-tabson]')){var y=window.scrollY;c.innerHTML=t.checked?on:off;window.scrollTo(0,y);return;}"
      + "if(t.type==='radio'){document.querySelectorAll('input[name=\"'+t.name+'\"]').forEach(function(r){r.parentNode.classList.toggle('is-on',r.checked);});}});"
      + "document.addEventListener('click',function(e){if(e.target.closest('a[href]')){e.preventDefault();}});})();";
    doc.querySelector('body').appendChild(js);

    return '<!doctype html>\n' + doc.outerHTML;
  }, offHTML);
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });

  await signIn(page);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });

  // A re-run replaces the section an earlier run made (preview database only).
  const list = await api(page, '/grid-sections', 'GET');
  for (const s of (list.body && list.body.sections) || []) {
    if (s.name === 'Homepage product tabs') await api(page, '/grid-sections/' + s.id, 'DELETE');
  }
  const made = await api(page, '/grid-sections', 'POST', { preset: 'tabs' });
  const id = made.body.section.id;
  const put = await api(page, '/grid-sections/' + id, 'PUT', {
    values: { name: 'Homepage product tabs', heading: 'Loved most', subheading: 'The K-beauty our customers come back for',
      view_all_label: 'View all', show_heading: true },
    tab_style: 'a',
    product_tabs: {
      eyebrow: 'Shop the edit', eyebrow_ar: 'تسوّقي المختارات', heading_ar: 'الأكثر حبًا',
      sub_ar: 'منتجات الجمال الكوري التي يعود إليها عملاؤنا', view_all_ar: 'عرض الكل', align: 'center',
      list: [
        { title: 'Best Sellers', title_ar: 'الأكثر مبيعًا', source: 'bestsellers', url: '/best-sellers' },
        { title: 'Trending K-Beauty', title_ar: 'الأكثر رواجًا في الجمال الكوري', source: 'trending', url: '/shop/' },
        { title: 'New Arrivals', title_ar: 'وصل حديثًا', source: 'newest', url: '/new-in' },
      ],
    },
  });
  console.log('section', id, 'put', put.status);

  const nums = {};
  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
    await openEditor(page, id);
    nums[w] = await measure(page);
    // Tall viewport for the picture: the console scrolls inside its own panel.
    await page.setViewportSize({ width: w, height: 4600 });
    await page.waitForTimeout(400);
    await (await page.$('#content')).screenshot({ path: path.join(OUT, 'editor-' + w + '.png') });
    // The second tab opened, to show a tab card's inside.
    await page.click('[data-gss-tfold="1"]');
    await page.waitForTimeout(200);
    const tabs = await page.$('[data-gss-step="tabs"]');
    await tabs.screenshot({ path: path.join(OUT, 'tabs-step-open-' + w + '.png') });
    nums[w].tabCard1Open = await page.evaluate(() => { const b = document.querySelector('[data-gss-tcard="1"]').getBoundingClientRect(); return Math.round(b.height); });
    await page.click('[data-gss-tfold="1"]');
  }

  // Tabs OFF, as the live screen draws it (not saved: Discard follows).
  await page.setViewportSize({ width: 1280, height: 4600 });
  await openEditor(page, id);
  const onHTML = await page.$eval('#content', e => e.innerHTML);
  await page.click('[data-gss-tabson]');
  await page.waitForSelector('[data-gss-key="source"]');
  await page.waitForTimeout(600);
  await (await page.$('#content')).screenshot({ path: path.join(OUT, 'editor-tabs-off-1280.png') });
  const offHTML = await page.$eval('#content', e => e.innerHTML);
  nums.offSteps = await page.$$eval('[data-gss-step]', s => s.map(x => x.dataset.gssStep));
  page.once('dialog', d => d.accept());
  await page.click('#gss-discard');
  await page.waitForSelector('[data-gss-step="tabs"]');
  await page.waitForTimeout(1500);

  const html = await freeze(page, offHTML);
  const file = path.join(OUT, 'editor-live.html');
  fs.writeFileSync(file, html);
  nums.frozenBytes = html.length;
  nums.onHTMLMatches = onHTML.length > 0;

  // The frozen copy, opened from disk with the network OFF: same look, toggles work.
  for (const w of [1280, 390]) {
    const c2 = await browser.newContext({ viewport: { width: w, height: 4600 }, offline: true });
    const p2 = await c2.newPage();
    const reqs = [];
    p2.on('request', r => { if (!r.url().startsWith('file:') && !r.url().startsWith('data:')) reqs.push(r.url()); });
    const ferr = [];
    p2.on('pageerror', e => ferr.push(String(e)));
    await p2.goto('file://' + file, { waitUntil: 'load' });
    await p2.waitForTimeout(500);
    await p2.screenshot({ path: path.join(OUT, 'frozen-' + w + '.png'), fullPage: true });
    const before = await p2.$eval('[data-gss-tcard="2"]', e => e.classList.contains('is-open'));
    await p2.click('[data-gss-tfold="2"]');
    const after = await p2.$eval('[data-gss-tcard="2"]', e => e.classList.contains('is-open'));
    await p2.click('[data-gss-tabson]');
    const offShown = await p2.$$eval('[data-gss-key="source"]', e => e.length);
    await p2.click('[data-gss-tabson]');
    const onBack = await p2.$$eval('[data-gss-step="tabs"]', e => e.length);
    nums['frozen' + w] = { foldBefore: before, foldAfter: after, offShowsSource: offShown, onBack, scrollWidth: await p2.evaluate(() => document.documentElement.scrollWidth), externalRequests: reqs.length, errors: ferr };
    await c2.close();
  }

  nums.consoleErrors = errors;
  fs.writeFileSync(path.join(OUT, 'numbers.json'), JSON.stringify(nums, null, 2));
  console.log(JSON.stringify(nums, null, 2));
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
