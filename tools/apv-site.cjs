#!/usr/bin/env node
/*
 * Lane APV3 — reads the LIVE website's header, mobile menu and menu bar, so the app
 * previews draw them exactly as the site does instead of from guesses.
 *
 *   node tools/apv-site.cjs            capture and measure (needs the network)
 *
 * In Chromium it opens https://kbeautybliss.com/ and /ar/ and writes:
 *
 *   docs/app-preview/en|ar/site.css    every stylesheet rule the site applies to its header,
 *                                      its menu bar and mega panels, its mobile menu (opened)
 *                                      and the delivery strip, rewritten for the app frame:
 *                                      scoped under .kh, `header` -> .kh-hd, #id -> .id-<id>,
 *                                      @media widths -> @container app (each device frame is
 *                                      its own viewport), hover-only rules dropped (the frames
 *                                      are touch devices), vw -> cqw, vh -> the frame height,
 *                                      position:fixed -> absolute (inside the frame)
 *   docs/app-preview/en|ar/site.js     the site's own markup for those parts (window.APV_SITE),
 *                                      each link turned into an app route (data-go)
 *   docs/app-preview/site-measure.json computed sizes at 390, 600, 768, 900, 1024 and 1280
 *                                      (header height, rows, paddings, logo, search box, icons,
 *                                      language switch, menu bar): the parity table and the
 *                                      checker's ±1px targets
 *
 * Nothing here runs in the app at view time: the previews read the files above.
 */
'use strict';
const pw = require('/opt/node22/lib/node_modules/playwright');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const APP = path.join(ROOT, 'docs/app-preview');
const SITE = 'https://kbeautybliss.com';
const WIDTHS = [390, 600, 768, 900, 1024, 1280];
/* the app's device widths (logical): Fold cover, SE, 16 Pro, Pixel 9 / Flip, Duo screen, Fold inner, iPad portrait, iPad landscape */
const DEVW = [344, 375, 402, 412, 540, 673, 744, 1133];
const SHOTS = path.join(APP, 'shots/site');

/* ── in the page: the rules that reach the captured elements ─────────────── */
async function extractRules() {
  const srcOf = {};
  for (const sh of document.styleSheets) if (sh.href) { try { srcOf[sh.href] = await (await fetch(sh.href)).text(); } catch {} }
  const q = (s) => document.querySelector(s);
  const annc = q('.kbb-home .kts');
  const roots = [q('header'), q('nav.mmenu'), q('.mscrim'), annc].filter(Boolean);
  const els = roots.flatMap((r) => [r, ...r.querySelectorAll('*')]);
  const DYN = /::?(?:hover|focus-visible|focus-within|focus|active|visited|link|before|after|placeholder|marker|selection|backdrop|first-letter|first-line|-webkit-[a-z-]+|-moz-[a-z-]+)(?:\((?:[^()]|\([^()]*\))*\))?/g;
  const split = (s) => { const out = []; let d = 0, cur = ''; for (const c of s) { if (c === '(' || c === '[') d++; if (c === ')' || c === ']') d--; if (c === ',' && !d) { out.push(cur.trim()); cur = ''; } else cur += c; } if (cur.trim()) out.push(cur.trim()); return out; };
  const ROOTSEL = /^(?:html|:root|body)(?:[.#\[:][^\s>+~]*)?$/;
  const rules = [], keyframes = {};
  let seq = 0;
  const walk = (list, ctx) => {
    for (const r of list) {
      if (r instanceof CSSStyleRule) {
        const i = seq++;
        const parts = split(r.selectorText);
        const keep = parts.filter((p) => {
          const t = p.replace(DYN, '').trim();
          if (!t) return false;
          if (ROOTSEL.test(t)) return true;
          try { return els.some((e) => e.matches(t)); } catch { return false; }
        });
        let body = r.cssText.slice(r.cssText.indexOf('{'));
        /* Chromium serialises a shorthand holding var() as empty longhands ("background-image: ;"):
           put the shorthand back as the stylesheet's own source writes it */
        if (/:\s*;/.test(body) && keep.length) {
          body = body.replace(/\s[a-z-]+:\s*;/g, '');
          const src = srcOf[r.parentStyleSheet.href] || '';
          const esc = r.selectorText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s*').replace(/\\s\*>\\s\*|>/g, '\\s*>\\s*');
          const names = [...body.matchAll(/([a-z-]+)\s*:/g)].map((x) => x[1].replace(/^-webkit-/, ''));
          const m = [...src.matchAll(new RegExp('(?:^|[{};,\\s])' + esc + '\\s*\\{([^}]*)\\}', 'g'))].sort((a, b) => names.filter((n) => b[1].includes(n + ':')).length - names.filter((n) => a[1].includes(n + ':')).length)[0];
          const decl = m ? m[1].split(';').map((x) => x.trim()).filter((x) => /^(background|border|font|margin|padding|inset|mask|transition|animation|outline)\s*:/.test(x) && /var\(/.test(x)) : [];
          body = body.replace(/^\{/, '{' + decl.map((x) => ' ' + x + ';').join(''));
        }
        if (keep.length) rules.push({i, ctx:ctx.slice(), sel:keep, body});
      } else if (r instanceof CSSMediaRule) walk(r.cssRules, ctx.concat(['@media ' + r.conditionText]));
      else if (r instanceof CSSSupportsRule) walk(r.cssRules, ctx.concat(['@supports ' + r.conditionText]));
      else if (typeof CSSLayerBlockRule !== 'undefined' && r instanceof CSSLayerBlockRule) walk(r.cssRules, ctx);
      else if (r instanceof CSSKeyframesRule) keyframes[r.name] = r.cssText;
    }
  };
  for (const sh of document.styleSheets) { let L; try { L = sh.cssRules; } catch { continue; } walk(L, []); }
  return {rules, keyframes, annc:annc ? annc.outerHTML : '', anncClass:annc ? annc.className : '', fonts:[...new Set([...document.styleSheets].map((s) => s.href).filter(Boolean))]};
}

/* ── in the page: computed sizes, the parity table ───────────────────────── */
function measure() {
  const R = (e) => { if (!e) return null; const r = e.getBoundingClientRect(); return r.width ? {x:+r.left.toFixed(1), y:+r.top.toFixed(1), w:+r.width.toFixed(1), h:+r.height.toFixed(1)} : null; };
  const q = (s) => document.querySelector(s);
  const S = (e, ks) => { if (!e) return null; const s = getComputedStyle(e); return Object.fromEntries(ks.map((k) => [k, s[k]])); };
  const vis = (e) => e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0;
  const hd = q('header'), si = q('header .search-in'), inp = si && si.querySelector('input');
  const lswD = q('header .lsw-d'), lswM = q('header .lsw-m');
  const lsw = vis(lswD) ? lswD : vis(lswM) ? lswM : null;
  const bar = q('header .mbar');
  const hact = q('header .hact');
  return {
    header:R(hd), headerS:S(hd, ['backgroundColor', 'borderBottomWidth', 'borderBottomColor']),
    wrap:S(q('header .wrap'), ['paddingLeft', 'paddingRight']),
    row1:R(q('header .hin .logo')) && {h:+q('header .wrap').getBoundingClientRect().height.toFixed(1)},
    menuIcon:vis(q('#burger')) ? {...R(q('#burger')), svg:R(q('#burger svg'))} : null,
    logo:R(q('header .logo')), logoIcon:R(q('header .logo .lgx-i')),
    word:{...R(q('header .lgx-w')), ...S(q('header .lgx-w'), ['fontFamily', 'fontSize', 'fontWeight', 'letterSpacing', 'color'])},
    tag:{...R(q('header .lgx-g')), ...S(q('header .lgx-g'), ['fontSize', 'fontWeight', 'letterSpacing', 'color'])},
    wordText:(q('header .lgx-w') || {}).textContent || '',
    search:{...R(si), ...S(si, ['borderRadius', 'borderTopWidth', 'borderTopColor', 'backgroundColor', 'paddingLeft', 'paddingRight']), icon:R(si && si.querySelector('svg')), iconColor:si && getComputedStyle(si.querySelector('svg')).color, font:S(inp, ['fontFamily', 'fontSize', 'color']), placeholder:inp && inp.placeholder},
    icons:hact ? [...hact.querySelectorAll(':scope > .ib, :scope > span > .ib')].filter(vis).map((a) => ({label:a.getAttribute('aria-label'), box:R(a), svg:R(a.querySelector('svg'))})) : [],
    lang:lsw ? {kind:lsw === lswD ? 'pill' : 'capsule', box:R(lsw), text:lsw.textContent.trim(), ...S(lsw, ['borderRadius', 'borderTopWidth', 'borderTopColor', 'fontSize', 'fontWeight'])} : null,
    menuBar:vis(bar) ? {...R(bar), items:[...bar.querySelectorAll('.navitem > .navlink, .wrap > a')].filter(vis).map((a) => a.textContent.replace(/[▾\s]+/g, ' ').trim())} : null,
    badge:S(q('#cartCt'), ['backgroundColor', 'fontSize']),
  };
}

/* ── the site's links as app routes ──────────────────────────────────────── */
function routeMap(lang) {
  global.window = {};
  eval(fs.readFileSync(path.join(APP, lang, 'data.js'), 'utf8'));
  const D = global.window.APV_DATA;
  const byUrl = Object.fromEntries(D.categories.map((c) => [c.url.replace(/\/+$/, ''), c.k]));
  const brands = new Set(D.brands.map((b) => b.slug));
  return (href, text) => {
    if (!href) return null;
    let p = href.replace(SITE, '').replace(/^\/ar(?=\/|$)/, '').replace(/[?#].*$/, '');
    const qs = (href.split('?')[1] || '');
    p = p.replace(/\/+$/, '') || '/';
    if (p === '/') return {go:'/home', root:'home'};
    if (p === '/brands') return {go:'/brands', root:'shop'};
    const b = p.match(/^\/brands\/([^/]+)$/);
    if (b) return brands.has(b[1]) ? {go:'/brand/' + b[1]} : {go:'/search?q=' + encodeURIComponent(text)};
    if (byUrl[p]) return {go:'/category/' + byUrl[p]};
    if (p === '/super-sale') return {go:'/products?tag=sale'};
    if (p === '/best-sellers') return {go:'/products?tag=best'};
    if (p === '/everything-under-54-aed') return {go:'/products?tag=under54'};
    if (p === '/shop') return {go:'/products'};
    if (p === '/my-account' && /register/.test(qs)) return {go:'/account/register'};
    if (p === '/my-account') return {tab:'account'};
    if (p === '/my-wishlist') return {go:'/wishlist'};
    if (p === '/cart') return {tab:'bag'};
    if (p === '/blog') return {go:'/blog'};
    if (p === '/contact' || p === '/contact-us') return {go:'/contact'};
    return {go:'/products'};
  };
}

/* ── markup clean-up: ids to classes, links to routes, no scripts ────────── */
function clean(html, lang, map, o = {}) {
  let h = html;
  h = h.replace(/<script[\s\S]*?<\/script>/g, '');
  h = h.replace(/<div class="sugg"[^>]*>[\s\S]*?<\/div>/, '');           // suggestions: the app's Search screen shows them
  h = h.replace(/\sdata-starter="[^"]*"/g, '');
  h = h.replace(/<header\b([^>]*)class="/, '<div$1class="kh-hd ').replace(/<\/header>\s*$/, '</div>');
  h = h.replace(/\sid="([^"]+)"/g, (m, id) => ` data-kid="${id}"`);
  h = h.replace(/<([a-z]+)\b([^>]*?)\sdata-kid="([^"]+)"([^>]*)>/g, (m, tag, a, id, b) => {
    const all = a + b;
    return /class="/.test(all) ? `<${tag}${(a + b).replace(/class="/, `class="id-${id} `)}>` : `<${tag}${a}${b} class="id-${id}">`;
  });
  h = h.replace(/\saria-controls="[^"]*"/g, '').replace(/\shreflang="[^"]*"/g, '');
  h = h.replace(/\s(?:method|action|role)="(?:get|\/shop\/|\/ar\/shop\/|search)"/g, '');
  // links: language switch to the other app; everything else to an app route
  h = h.replace(/<a\b([^>]*?)href="([^"]*)"([^>]*)>([\s\S]*?)<\/a>/g, (m, a, href, b, inner) => {
    const attrs = a + b;
    const text = inner.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
    const isLang = /\blsw\b|lang="(?:ar|en)"/.test(attrs) && (/^\/(ar\/)?$/.test(href.replace(SITE, '')));
    if (isLang) return `<a${attrs} data-lang="1" href="#">${inner}</a>`;
    if (/wa\.me/.test(href)) return `<a${attrs} data-act="wa" role="button" tabindex="0">${inner}</a>`;
    const r = map(href, text);
    if (/\bnavlink\b/.test(attrs) && o.megaHrefs && o.megaHrefs.has(href)) return `<a${attrs} data-act="mega" role="button" tabindex="0" data-r="${r.go || ''}">${inner}</a>`;
    if (r.tab) return `<a${attrs} data-tab="${r.tab}" role="button" tabindex="0">${inner}</a>`;
    return `<a${attrs} data-go="${r.go}"${r.root ? ` data-tabroot="${r.root}"` : ''} role="button" tabindex="0">${inner}</a>`;
  });
  h = h.replace(/\saria-current="page"/g, '');
  // the Arabic app marks what the site itself shows in Latin: brand names (the site has them only in
  // Latin), the "English" switch, and the phone number
  if (lang === 'ar') {
    h = h.replace(/<(a|span|small|b)\b([^>]*)>([^<]*[A-Za-z]{2,}[^<]*)<\/\1>/g, (m, tag, a, txt) => /data-x=/.test(a) ? m : `<${tag}${a} data-x="${/data-lang|lang="en"/.test(a) ? 'lang' : 'brand'}">${txt}</${tag}>`);
    h = h.replace(/(<a\b[^>]*data-lang="1"[^>]*?)>/g, (m, a) => /data-x=/.test(a) ? m : `${a} data-x="lang">`);
  }
  // viewport units inside inline styles
  h = h.replace(/style="([^"]*)"/g, (m, s) => `style="${units(s)}"`);
  return h.replace(/\n\s*\n/g, '\n').replace(/>\s+</g, '> <').trim();
}
const units = (s) => s.replace(/(-?[\d.]+)d?s?l?vw\b/g, '$1cqw').replace(/(-?[\d.]+)[dsl]?vh\b/g, 'calc(var(--h) * $1 / 100)').replace(/env\(safe-area-inset-[a-z]+(?:,\s*([^)]+))?\)/g, (m, d) => d || '0px');

/* ── CSS: scope, retarget, and turn viewport queries into frame queries ──── */
function scopeSel(sel) {
  let s = sel.trim();
  s = s.replace(/#([A-Za-z][\w-]*)/g, '.id-$1');
  s = s.replace(/(^|[\s>+~(,])header(?=$|[.#\[:\s>+~),])/g, '$1.kh-hd');
  // leading html / :root / body compounds merge into .kh (which carries dir, lang and the body's state)
  let lead = '';
  for (;;) {
    const m = s.match(/^(html|:root|body)((?:[.\[][^\s>+~]*|:(?!root)[^\s>+~]*)?)\s*(>\s*)?/);
    if (!m) break;
    lead += m[2]; s = s.slice(m[0].length);
  }
  if (lead || /^(html|:root|body)/.test(sel.trim())) return s ? `.kh${lead} ${s}` : `.kh${lead}`;
  if (/^\[(dir|lang)/.test(s)) { const m = s.match(/^(\[[^\]]+\])\s*/); return `.kh${m[1]} ${s.slice(m[0].length)}`.trim(); }
  return `.kh ${s}`;
}
function convertMedia(cond) {
  let c = cond.replace(/\b(only\s+)?(screen|all)\s+and\s+/gi, '').trim();
  if (/\bprint\b/.test(c)) return null;
  if (/(any-)?hover\s*:\s*hover|pointer\s*:\s*fine/.test(c)) return null;           // the frames are touch screens
  c = c.replace(/\(\s*(any-)?hover\s*:\s*none\s*\)\s*(and\s*)?/g, '').replace(/\(\s*(any-)?pointer\s*:\s*coarse\s*\)\s*(and\s*)?/g, '').replace(/\s+and\s*$/, '').trim();
  if (!c || /^(screen|all)$/.test(c)) return {media:null, cont:null};
  const feats = c.split(/\s+and\s+/);
  const width = feats.filter((f) => /width/.test(f) && !/device/.test(f));
  const other = feats.filter((f) => !/width/.test(f) || /device/.test(f));
  if (/,|\bor\b|\bnot\b/.test(c) && other.length) return {media:c, cont:null};
  return {media:other.length ? other.join(' and ') : null, cont:width.length ? width.join(' and ') : null};
}
function buildCss(captures, lang) {
  const seen = new Set(), out = [], kf = {};
  const used = new Set();
  /* one page, several captures (menu bar open, phone menu open): the union of the rules each
     matched, in the stylesheet's own order, so the cascade is the site's */
  const byI = new Map();
  for (const cap of captures) { for (const r of cap.rules) byI.set(r.i, r); Object.assign(kf, cap.keyframes); }
  {
    for (const r of [...byI.values()].sort((a, b) => a.i - b.i)) {
      let pre = '', post = '', drop = false;
      for (const c of r.ctx) {
        if (c.startsWith('@media ')) {
          const m = convertMedia(c.slice(7));
          if (!m) { drop = true; break; }
          if (m.media) { pre += `@media ${m.media}{`; post += '}'; }
          if (m.cont) { pre += `@container app ${m.cont}{`; post += '}'; }
        } else { pre += c + '{'; post += '}'; }
      }
      if (drop) continue;
      const sels = [];
      for (const p of r.sel) {
        sels.push(scopeSel(p));
        if (/\.navitem:(hover|focus-within)/.test(p)) sels.push(scopeSel(p.replace(/\.navitem:(hover|focus-within)/g, '.navitem.kopen')));
      }
      let body = units(r.body).replace(/position\s*:\s*fixed/g, 'position: absolute');
      body = body.replace(/#([A-Za-z][\w-]*)(?=[^{}]*:)/g, (m) => m); // ids never appear in bodies; kept for clarity
      for (const m of body.matchAll(/animation(?:-name)?\s*:\s*([^;}]+)/g)) for (const w of m[1].split(/[\s,]+/)) used.add(w);
      const line = `${pre}${[...new Set(sels)].join(',')}${body}${post}`;
      if (seen.has(line)) continue;
      seen.add(line); out.push(line);
    }
  }
  const frames = Object.entries(kf).filter(([n]) => used.has(n)).map(([, v]) => v);
  return `/* Generated by tools/apv-site.cjs from ${SITE}${lang === 'ar' ? '/ar/' : '/'} on ${new Date().toISOString().slice(0, 10)}.
   The website's own rules for its header, menu bar + mega panels, mobile menu and delivery
   strip, scoped to .kh inside each device frame. Do not edit by hand: re-run the tool. */
.kh{display:contents}
${out.join('\n')}
${frames.join('\n')}
`;
}

(async () => {
  const browser = await pw.chromium.launch({executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome', proxy:process.env.HTTPS_PROXY ? {server:process.env.HTTPS_PROXY} : undefined});
  const captures = {en:[], ar:[]}, measured = {}, markup = {};
  for (const lang of ['en', 'ar']) {
    const url = SITE + (lang === 'ar' ? '/ar/' : '/');
    const page = await browser.newPage({viewport:{width:1280, height:900}});
    await page.goto(url, {waitUntil:'networkidle', timeout:90000});
    fs.mkdirSync(SHOTS, {recursive:true});
    for (const w of [...new Set(WIDTHS.concat(DEVW))].sort((a, b) => a - b)) {
      await page.setViewportSize({width:w, height:900}); await page.waitForTimeout(350);
      measured[`${lang}-${w}`] = await page.evaluate(measure);
      // the site's own header at the app's widths, for the side-by-side pictures (regenerated, not kept)
      if (DEVW.includes(w)) await page.screenshot({path:path.join(SHOTS, `${lang}-${w}.png`), clip:{x:0, y:0, width:w, height:Math.ceil(measured[`${lang}-${w}`].header.h) + 40}});
    }
    // the phone menu open (402 x 874, as the iPhone 16 Pro; 673 x 841, as the Fold inner), and a mega panel (1133 x 744, iPad landscape)
    for (const [w, h] of [[402, 874], [673, 841]]) {
      await page.setViewportSize({width:w, height:h}); await page.waitForTimeout(350);
      await page.click('#burger'); await page.waitForTimeout(800);
      await page.screenshot({path:path.join(SHOTS, `${lang}-${w}-menu.png`)});
      await page.click('nav.mmenu .mm-par >> nth=2'); await page.waitForTimeout(600);
      await page.screenshot({path:path.join(SHOTS, `${lang}-${w}-menu-open.png`)});
      await page.click('#mmx').catch(() => {}); await page.waitForTimeout(500);
    }
    await page.setViewportSize({width:1133, height:744}); await page.waitForTimeout(350);
    await page.hover('header .navitem >> nth=2'); await page.waitForTimeout(600);
    await page.screenshot({path:path.join(SHOTS, `${lang}-1133-mega.png`)});
    await page.mouse.move(5, 600); await page.waitForTimeout(300);
    // desktop: header + menu bar (mega panels in the DOM); rules matched with a panel open
    await page.setViewportSize({width:1280, height:900}); await page.waitForTimeout(300);
    await page.hover('header .navitem .navlink').catch(() => {});
    captures[lang].push(await page.evaluate(extractRules));
    const head = await page.evaluate(() => document.querySelector('header').outerHTML);
    const megaHrefs = await page.evaluate(() => [...document.querySelectorAll('header .navitem')].filter((n) => n.querySelector('.drop')).map((n) => n.querySelector('.navlink').getAttribute('href')));
    // phone: the menu opened and a level expanded, so the open-state rules are matched too
    await page.setViewportSize({width:390, height:844}); await page.waitForTimeout(350);
    await page.click('#burger'); await page.waitForTimeout(700);
    await page.click('nav.mmenu .mm-par').catch(() => {}); await page.waitForTimeout(500);
    const cap = await page.evaluate(extractRules);
    captures[lang].push(cap);
    const mm = await page.evaluate(() => { const m = document.querySelector('nav.mmenu'); m.classList.remove('on'); m.querySelectorAll('.mm-node.on').forEach((n) => n.classList.remove('on')); return m.outerHTML; });
    const map = routeMap(lang);
    markup[lang] = {
      header:clean(head, lang, map, {megaHrefs:new Set(megaHrefs)}),
      mmenu:clean(mm, lang, map),
      annc:clean(cap.annc, lang, map),
      bodyClass:await page.evaluate(() => document.body.className.replace(/\bmenu-open\b/, '').trim()),
    };
    await page.close();
  }
  await browser.close();
  for (const lang of ['en', 'ar']) fs.writeFileSync(path.join(APP, lang, 'site.css'), buildCss(captures[lang], lang));
  for (const lang of ['en', 'ar']) fs.writeFileSync(path.join(APP, lang, 'site.js'), `/* Generated by tools/apv-site.cjs from ${SITE}${lang === 'ar' ? '/ar/' : '/'}: the site's own header, menu and delivery strip markup. */\nwindow.APV_SITE = ${JSON.stringify(markup[lang])};\n`);
  fs.writeFileSync(path.join(APP, 'site-measure.json'), JSON.stringify({source:SITE, captured:new Date().toISOString(), widths:WIDTHS, device_widths:DEVW, measured}, null, 1));
  console.log('site.css', ['en', 'ar'].map((l) => fs.statSync(path.join(APP, l, 'site.css')).size).join(' / '), 'bytes;', Object.entries(markup).map(([k, v]) => `${k}: header ${v.header.length}, menu ${v.mmenu.length}, strip ${v.annc.length}`).join('; '));
})().catch((e) => { console.error(e); process.exit(1); });
