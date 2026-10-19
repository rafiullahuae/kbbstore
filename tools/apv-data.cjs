#!/usr/bin/env node
/*
 * Lane APV2 — real data for the two app previews (docs/app-preview/en, /ar).
 *
 *   node tools/apv-data.cjs            fetch everything, write data.json + en/data.js + ar/data.js
 *   node tools/apv-data.cjs --split    re-write en/data.js + ar/data.js from data.json (no network)
 *
 * Reads the LIVE public site at build time. Nothing here runs in the preview:
 * each app gets a plain <script> file (window.APV_DATA = …) so it opens from
 * file:// and from a phone with no server and no fetch. The English file holds
 * no Arabic string and the Arabic file holds the Arabic text wherever the site
 * has it (names, brands, categories, descriptions, how-to-use, banners).
 *
 * Sources (all public, read-only):
 *   /api/products?limit=100&page=n           catalogue: prices (fils), photos, stock, rating
 *   /collections/<path>/  and /ar/…          category banner, name, about, product list, AR card names
 *   /brands/  /brands/<slug>/ and /ar/…      brand list, banner, about, product list
 *   /product/<slug>/ and /ar/product/<slug>/ JSON-LD description, Benefits / How to use /
 *                                            Best suited for, Ingredients (INCI), reviews with photos
 *   /api/posts, /api/posts/{slug}            blog (no Arabic version on the site yet)
 *   / and /ar/                               banner slides, trust row
 *   docs/sbc-preview/concerns.json           Shop by Concern tabs, tips (EN + AR)
 *
 * curl does the HTTP (it honours the agent proxy; node's fetch does not).
 */
'use strict';
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'docs/app-preview');
const SITE = 'https://kbeautybliss.com';
const AR_RE = /[؀-ۿ]/;

function get(url) {
  for (let i = 0; i < 3; i++) {
    try { return execFileSync('curl', ['-sS', '-m', '60', '--compressed', url], { encoding: 'utf8', maxBuffer: 64 << 20 }); }
    catch (e) { if (i === 2) throw e; }
  }
}
const json = (url) => JSON.parse(get(url));

/** GET many pages in parallel into a temp dir; returns Map url -> html. */
// APV_CACHE=<dir> keeps the fetched pages between runs (a re-run then costs no requests).
const CACHE = process.env.APV_CACHE || null;
const cacheFile = (u) => path.join(CACHE, require('crypto').createHash('sha1').update(u).digest('hex') + '.html');
function getMany(all) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'apv-'));
  const out = new Map();
  if (CACHE) fs.mkdirSync(CACHE, { recursive: true });
  const urls = all.filter((u) => { if (CACHE && fs.existsSync(cacheFile(u))) { out.set(u, fs.readFileSync(cacheFile(u), 'utf8')); return false; } return true; });
  try {
    for (let i = 0; i < urls.length; i += 48) {
      const chunk = urls.slice(i, i + 48);
      const args = ['-sS', '-m', '60', '--compressed', '--parallel', '--parallel-max', '8'];
      chunk.forEach((u, j) => args.push('-o', path.join(dir, `${i + j}.html`), u));
      try { execFileSync('curl', args, { stdio: ['ignore', 'ignore', 'ignore'] }); } catch {}
      chunk.forEach((u, j) => { const f = path.join(dir, `${i + j}.html`); if (fs.existsSync(f)) { const h = fs.readFileSync(f, 'utf8'); out.set(u, h); if (CACHE && h.length > 2000) fs.writeFileSync(cacheFile(u), h); fs.unlinkSync(f); } });
      process.stdout.write(`  pages ${Math.min(i + 48, urls.length)}/${urls.length}\n`);
    }
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
  return out;
}

/** HEAD many URLs; returns Set of URLs that answered 200. */
function alive(urls) {
  const ok = new Set();
  for (let i = 0; i < urls.length; i += 60) {
    const chunk = urls.slice(i, i + 60);
    const args = ['-s', '-I', '-m', '30', '--parallel', '--parallel-max', '12'];
    for (const u of chunk) args.push('-o', '/dev/null', '-w', '%{http_code} %{url_effective}\\n', u);
    let out = '';
    try { out = execFileSync('curl', args, { encoding: 'utf8' }); } catch (e) { out = String(e.stdout || ''); }
    for (const line of out.split('\n')) { const m = line.match(/^(\d{3}) (\S+)/); if (m && m[1] === '200') ok.add(m[2]); }
  }
  return ok;
}

const ent = (s) => String(s || '').replace(/&nbsp;| /g, ' ').replace(/&amp;/g, '&').replace(/&#0?39;|&#8217;|&rsquo;/g, '’').replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#8211;|&ndash;/g, '–');
const text = (h) => ent(String(h || '').replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();
const clip = (s, n) => { s = String(s || '').replace(/\s+/g, ' ').trim(); return s.length > n ? s.slice(0, n).replace(/\s\S*$/, '') + '…' : s; };
const fils = (n) => (n == null ? null : Math.round(n) / 100);
const abs = (u) => (!u ? null : /^https?:/.test(u) ? u.replace(/^https?:\/\/[^/]+/, SITE) : SITE + u);
const cache = (w, u) => { if (!u) return null; const p = abs(u).replace(SITE, '').replace(/^\/img-cache\/(c1x1\/)?\d+/, ''); return `${SITE}/img-cache/${w}${encodeURI(decodeURI(p))}`; };

/* ── split: write each language its own data file ───────────────────────── */
// Prose the Arabic app must not show in English: when the site has no Arabic
// version, the Arabic file carries null and the app says so in Arabic.
const PROSE = new Set(['desc', 'about', 'sec', 'excerpt', 'alt', 'crumb']);
function split(data) {
  const pick = (o, ar) => {
    if (Array.isArray(o)) return o.map((x) => pick(x, ar));
    if (!o || typeof o !== 'object') return o;
    const r = {};
    for (const [k, v] of Object.entries(o)) {
      if (k.endsWith('_ar')) continue;
      const a = o[k + '_ar'];
      const has = a != null && a !== '' && !(Array.isArray(a) && !a.length);
      r[k] = ar && has ? pick(a, ar) : ar && PROSE.has(k) && (k + '_ar') in o ? null : pick(v, ar);
      if (ar && k === 'name' && a) r.name_en = v; // search matches the English spelling too; never rendered
    }
    return r;
  };
  for (const lang of ['en', 'ar']) {
    const d = pick(data, lang === 'ar');
    d.lang = lang;
    // Photo URLs travel as paths; the app rebuilds <site>/img-cache/<w><path>.
    const strip = (u) => String(u || '').replace(/^https:\/\/kbeautybliss\.com\/img-cache\/\d+/, '');
    d.products = d.products.map(({ img, img200, gallery, ...p }) => ({ ...p, ...(img200 === img ? { n2: 1 } : {}), im: strip(img), g: gallery.map((x) => strip(x.src)).filter((x) => x !== strip(img)) }));
    const blob = JSON.stringify(d).replace(/</g, '\\u003c').replace(/\u2028|\u2029/g, ' ');
    if (lang === 'en' && AR_RE.test(blob)) {
      const m = blob.match(new RegExp('.{0,60}' + AR_RE.source + '.{0,20}'));
      throw new Error('Arabic text left in the English data: ' + (m ? m[0] : ''));
    }
    fs.mkdirSync(path.join(OUT, lang), { recursive: true });
    fs.writeFileSync(path.join(OUT, lang, 'data.js'), `/* generated by tools/apv-data.cjs — ${data.generated} — do not edit */\nwindow.APV_DATA=${blob};\n`);
    console.log(lang, 'data.js', Math.round(blob.length / 1024), 'KB');
  }
}
if (process.argv.includes('--split')) { split(JSON.parse(fs.readFileSync(path.join(OUT, 'data.json'), 'utf8'))); process.exit(0); }

/* ── 1. Catalogue ────────────────────────────────────────────────────────── */
const all = [];
for (let page = 1; page <= 30; page++) {
  const rows = json(`${SITE}/api/products?limit=100&page=${page}`);
  all.push(...rows);
  if (rows.length < 100) break;
}
console.log('catalogue rows', all.length);
const bySlug = new Map(all.map((p) => [p.slug, p]));

/* ── 2. Listing pages: header (banner, name, about) and the product cards ── */
function listing(h) {
  const name = text((h.match(/<h1[^>]*class="brw-ph__name"[^>]*>([\s\S]*?)<\/h1>/) || [])[1]);
  const banner = (h.match(/class="brw-ph__img"\s+src="([^"]+)"/) || [])[1] || null;
  const about = text((h.match(/id="brw-ph-text">([\s\S]*?)<\/div>/) || [])[1]);
  const cards = [];
  for (const m of h.matchAll(/<a class="cn" href="(?:\/ar)?\/product\/([^/"]+)\/?"[^>]*><span class="kbb-card-nm">([\s\S]*?)<\/span>/g)) cards.push({ slug: m[1], name: text(m[2]) });
  return { name, banner, about, cards };
}

const CATS = [
  // key, path, parent
  ['skincare', '/collections/skincare/', null],
  ['cleansers', '/collections/cleansers/', 'skincare'],
  ['exfoliators', '/collections/skincare/exfoliators/', 'skincare'],
  ['toners', '/collections/skincare/toners/', 'skincare'],
  ['serums', '/collections/skincare/face-serums/', 'skincare'],
  ['moisturizers', '/collections/skincare/moisturizers/', 'skincare'],
  ['sunscreens', '/collections/skincare/sunscreens/', 'skincare'],
  ['masks', '/collections/skincare/face-masks/', 'skincare'],
  ['eye-care', '/collections/skincare/eye-care/', 'skincare'],
  ['lip-care', '/collections/lip-care/', 'skincare'],
  ['acne', '/collections/acne-blemish-care/', 'concerns'],
  ['anti-aging', '/collections/anti-aging/', 'concerns'],
  ['hydration', '/collections/hydration-glow/', 'concerns'],
  ['pigmentation', '/collections/pigmentation/', 'concerns'],
  ['pores', '/collections/pore-care/', 'concerns'],
  ['sensitive', '/collections/redness-sensitive-skin/', 'concerns'],
  ['oily', '/collections/oily-skin/', 'concerns'],
  ['sets', '/collections/skincare-sets/', null],
  ['hair', '/collections/hair-care/', null],
  ['body', '/collections/body-care/', null],
  ['devices', '/collections/beauty-devices/', null],
];
const catUrls = [];
for (const [, p] of CATS) catUrls.push(SITE + p, SITE + '/ar' + p);
catUrls.push(SITE + '/collections/skincare/?paged=2', SITE + '/ar/collections/skincare/?paged=2');

// brand list (name, slug, count) from /brands/
const bh = get(SITE + '/brands/');
const brandIndex = [];
for (const m of bh.matchAll(/<a class="brw-card"\s+href="\/brands\/([^/]+)\/">[\s\S]*?<span class="brw-name">([\s\S]*?)<\/span>\s*<span class="brw-count">\s*(\d+)/g)) brandIndex.push({ slug: m[1], name: text(m[2]), count: Number(m[3]) });
brandIndex.sort((a, b) => b.count - a.count);
const TOPB = brandIndex.slice(0, 18);
console.log('brands on the site', brandIndex.length, 'top', TOPB.map((b) => b.slug).join(','));
const brandUrls = [];
for (const b of TOPB) brandUrls.push(`${SITE}/brands/${b.slug}/`, `${SITE}/ar/brands/${b.slug}/`);
const pages = getMany([...catUrls, ...brandUrls]);

const arName = new Map();
const categories = [];
for (const [k, p, parent] of CATS) {
  const en = listing(pages.get(SITE + p) || ''), ar = listing(pages.get(SITE + '/ar' + p) || '');
  let cards = en.cards;
  if (k === 'skincare') { const e2 = listing(pages.get(SITE + p + '?paged=2') || ''), a2 = listing(pages.get(SITE + '/ar' + p + '?paged=2') || ''); cards = cards.concat(e2.cards); a2.cards.forEach((c) => AR_RE.test(c.name) && arName.set(c.slug, c.name)); }
  ar.cards.forEach((c) => AR_RE.test(c.name) && arName.set(c.slug, c.name));
  if (!en.name) { console.log('  no page for', p); continue; }
  categories.push({ k, parent, name: en.name, name_ar: AR_RE.test(ar.name) ? ar.name : null, banner: en.banner, about: en.about.slice(0, 600), about_ar: AR_RE.test(ar.about) ? ar.about.slice(0, 600) : null, items: [...new Set(cards.map((c) => c.slug))], url: p });
}
const brandPages = [];
for (const b of TOPB) {
  const en = listing(pages.get(`${SITE}/brands/${b.slug}/`) || ''), ar = listing(pages.get(`${SITE}/ar/brands/${b.slug}/`) || '');
  ar.cards.forEach((c) => AR_RE.test(c.name) && arName.set(c.slug, c.name));
  brandPages.push({ slug: b.slug, name: en.name || b.name, name_ar: AR_RE.test(ar.name) ? ar.name : null, count: b.count, banner: en.banner, about: en.about.slice(0, 600), about_ar: AR_RE.test(ar.about) ? ar.about.slice(0, 600) : null, items: [...new Set(en.cards.map((c) => c.slug))] });
}
console.log('categories', categories.length, 'brand pages', brandPages.length, 'arabic card names', arName.size);

/* ── 3. Shop by Concern (owner's chosen tabs, tips EN + AR) ──────────────── */
const concernsSrc = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/sbc-preview/concerns.json'), 'utf8'));
const slugOf = (u) => (u.match(/\/product\/([^/]+)\/?$/) || [])[1];

/* ── 4. The product set ─────────────────────────────────────────────────── */
const want = new Set();
concernsSrc.forEach((c) => c.items.forEach((it) => want.add(slugOf(it.u))));
categories.forEach((c) => c.items.slice(0, c.k === 'skincare' ? 36 : 14).forEach((s) => want.add(s)));
brandPages.forEach((b) => b.items.slice(0, 12).forEach((s) => want.add(s)));
const buyable = all.filter((p) => p.image && p.stock_status !== 'outofstock');
[...buyable].sort((a, b) => (b.review_count || 0) - (a.review_count || 0)).slice(0, 16).forEach((p) => want.add(p.slug));
buyable.filter((p) => fils(p.sale_price ?? p.price) <= 54).sort((a, b) => (b.review_count || 0) - (a.review_count || 0)).slice(0, 14).forEach((p) => want.add(p.slug));
const slugs = [...want].filter((s) => s && bySlug.has(s) && bySlug.get(s).image);
console.log('product set', slugs.length);

/* ── 5. Product pages, EN + AR: description, sections, ingredients, reviews ─ */
const pp = getMany(slugs.flatMap((s) => [`${SITE}/product/${s}/`, `${SITE}/ar/product/${s}/`]));
function ldProduct(h) {
  for (const m of h.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)) {
    try { const j = JSON.parse(m[1]); if (j['@type'] === 'Product') return j; } catch {}
  }
  return null;
}
function crumbs(h) {
  for (const m of h.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)) {
    try { const j = JSON.parse(m[1]); if (j['@type'] === 'BreadcrumbList') return j.itemListElement.map((x) => ({ name: x.name, url: x.item })); } catch {}
  }
  return [];
}
function sections(h) {
  const i = h.indexOf('data-panel="0"'); if (i < 0) return [];
  const blk = h.slice(i, h.indexOf('data-panel="1"', i) > 0 ? h.indexOf('data-panel="1"', i) : i + 40000);
  const out = [];
  const re = /<b>([^<]{2,40})<\/b>([\s\S]*?)(?=<b>[^<]{2,40}<\/b>|$)/g;
  for (const m of blk.matchAll(re)) {
    const h1 = text(m[1]).replace(/[:：]\s*$/, '').trim();
    const items = [...m[2].matchAll(/<li[^>]*>([\s\S]*?)<\/li>/g)].map((x) => text(x[1])).filter((x) => x.length > 2).slice(0, 3).map((x) => (x.length > 220 ? x.slice(0, 220).replace(/\s\S*$/, '') + '…' : x));
    const one = items.length ? null : text(m[2]).slice(0, 300);
    if (items.length || (one && one.length > 1)) out.push({ h: h1, items: items.length ? items : [one] });
    if (out.length >= 3) break;
  }
  return out;
}
const ingredients = (h) => { const i = h.indexOf('data-panel="1"'); if (i < 0) return null; const t = text(h.slice(i + 15, h.indexOf('</div>', i + 15))); return t.length > 10 ? (t.length > 380 ? t.slice(0, 380).replace(/,[^,]*$/, '') + ' …' : t) : null; };
const MON = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12 };
function isoDate(d) { const m = String(d || '').match(/(\d{1,2}) ([A-Za-z]{3})[a-z]* (\d{4})/); return m && MON[m[2].toLowerCase()] ? `${m[3]}-${String(MON[m[2].toLowerCase()]).padStart(2, '0')}-${m[1].padStart(2, '0')}` : null; }
function reviewsOf(h) {
  const out = [];
  for (const m of h.matchAll(/<script type="application\/json" class="sr-data">([\s\S]*?)<\/script>/g)) {
    try { const r = JSON.parse(m[1]); out.push({ name: r.name, rating: r.rate, text: String(r.text || '').length > 320 ? String(r.text).slice(0, 320).replace(/\s\S*$/, '') + '…' : String(r.text || ''), title: r.title || '', verified: !!r.vf, date: isoDate(r.date), photos: (r.imgs || []).slice(0, 3).map((u) => cache('c1x1/200', u)) }); } catch {}
  }
  return out;
}

/* ── 6. Images that really answer at the widths the app asks for ─────────── */
const imgWant = [];
for (const s of slugs) { const p = bySlug.get(s); imgWant.push(cache(400, p.image), cache(200, p.image)); [p.image, ...(p.images || [])].slice(0, 4).forEach((g) => imgWant.push(cache(800, g), cache(200, g))); }
const revPhotos = []; for (const s of slugs) for (const u of [`${SITE}/product/${s}/`, `${SITE}/ar/product/${s}/`]) reviewsOf(pp.get(u) || '').slice(0, 8).forEach((r) => r.photos.forEach((x) => revPhotos.push(x)));
categories.forEach((c) => c.banner && imgWant.push(cache(800, c.banner), cache(1280, c.banner)));
brandPages.forEach((b) => b.banner && imgWant.push(cache(800, b.banner), cache(1280, b.banner)));
const live = alive([...new Set([...imgWant, ...revPhotos])]);
console.log('image urls alive', live.size, '/', new Set([...imgWant, ...revPhotos]).size);

/* ── 7. Products out ─────────────────────────────────────────────────────── */
const brandBySlug = new Map(brandIndex.map((b) => [b.slug, b]));
const brandByName = new Map(brandIndex.map((b) => [b.name.toLowerCase(), b]));
const products = [];
for (const s of slugs) {
  const p = bySlug.get(s);
  const card = cache(400, p.image);
  if (!live.has(card)) continue;
  const he = pp.get(`${SITE}/product/${s}/`) || '', ha = pp.get(`${SITE}/ar/product/${s}/`) || '';
  const le = ldProduct(he) || {}, la = ldProduct(ha) || {};
  const bName = (le.brand && le.brand.name) || (p.name.includes(' - ') ? p.name.split(' - ')[0].trim() : p.name.split(' ')[0]);
  const bRow = brandByName.get(String(bName).toLowerCase()) || [...brandIndex].find((b) => p.name.toLowerCase().startsWith(b.name.toLowerCase()));
  const gallery = [p.image, ...(p.images || [])].filter((g, i, a) => a.indexOf(g) === i).filter((g) => live.has(cache(800, g)) && live.has(cache(200, g))).slice(0, 4);
  const nameAr = (la.name && AR_RE.test(la.name) ? la.name : null) || arName.get(s) || null;
  const okPhotos = (r) => ({ ...r, photos: r.photos.filter((u) => live.has(u)) });
  const rv = reviewsOf(he).filter((r) => !AR_RE.test(r.text + r.title + r.name)).slice(0, 4).map(okPhotos);
  const rvAr = reviewsOf(ha).slice(0, 4).map(okPhotos);
  const ce = crumbs(he), ca = crumbs(ha);
  const secE = sections(he), secA = sections(ha);
  products.push({
    slug: s, name: p.name, name_ar: nameAr,
    brand: bRow ? bRow.name : bName, brand_ar: la.brand && AR_RE.test(la.brand.name) ? la.brand.name : null, bslug: bRow ? bRow.slug : null,
    price: fils(p.price), sale: p.sale_price != null && p.sale_price < p.price ? fils(p.sale_price) : null,
    was: p.compare_at_price && p.compare_at_price > (p.sale_price ?? p.price) ? fils(p.compare_at_price) : null,
    rating: Number((le.aggregateRating || {}).ratingValue || p.rating || 0), reviews: Number((le.aggregateRating || {}).reviewCount || p.review_count || 0),
    stock: p.stock_status, total: null,
    img: card, img200: live.has(cache(200, p.image)) ? cache(200, p.image) : card,
    gallery: gallery.map((g) => ({ src: cache(800, g), thumb: cache(200, g) })),
    desc: clip(le.description || p.short_description, 380),
    desc_ar: la.description && AR_RE.test(la.description) ? clip(la.description, 380) : null,
    sec: secE, sec_ar: secA.length && secA.some((x) => AR_RE.test(x.h)) ? secA : null,
    inci: ingredients(he),
    crumb: ce.length > 2 ? ce[ce.length - 2].name : null, crumb_ar: ca.length > 2 && AR_RE.test(ca[ca.length - 2].name) ? ca[ca.length - 2].name : null,
    revs: rv, revs_ar: rvAr.length ? rvAr : null,
    tags: [],
  });
}
const P = new Map(products.map((p) => [p.slug, p]));
for (const c of concernsSrc) for (const it of c.items) { const x = P.get(slugOf(it.u)); if (x) x.tags.push('concern:' + c.k); }
[...products].sort((a, b) => b.reviews - a.reviews).slice(0, 12).forEach((p) => p.tags.push('best'));
products.filter((p) => !p.tags.includes('best')).slice(0, 14).forEach((p) => p.tags.push('trending'));
products.filter((p) => (p.sale ?? p.price) <= 54).sort((a, b) => b.reviews - a.reviews).slice(0, 12).forEach((p) => p.tags.push('under54'));
console.log('products out', products.length, 'arabic names', products.filter((p) => p.name_ar).length, 'arabic desc', products.filter((p) => p.desc_ar).length, 'with reviews', products.filter((p) => p.revs.length).length);

/* ── 8. Categories, brands, concerns ─────────────────────────────────────── */
const inSet = (arr) => arr.filter((s) => P.has(s));
const cats = categories.map((c) => ({ ...c, banner: c.banner && live.has(cache(800, c.banner)) ? cache(800, c.banner) : null, banner_w: c.banner && live.has(cache(1280, c.banner)) ? cache(1280, c.banner) : null, items: inSet(c.items) })).filter((c) => c.items.length);
for (const c of cats) for (const s of c.items) { const p = P.get(s); (p.cats = p.cats || []).push(c.k); }
const bset = new Map();
for (const p of products) if (p.bslug && !bset.has(p.bslug)) bset.set(p.bslug, brandBySlug.get(p.bslug));
const brands = [...bset.values()].filter(Boolean).map((b) => {
  const pg = brandPages.find((x) => x.slug === b.slug);
  const sample = products.find((p) => p.bslug === b.slug && p.brand_ar);
  return {
    slug: b.slug, name: (pg && pg.name) || b.name, name_ar: (pg && pg.name_ar) || (sample && sample.brand_ar) || null, count: b.count,
    banner: pg && pg.banner && live.has(cache(800, pg.banner)) ? cache(800, pg.banner) : null, banner_w: pg && pg.banner && live.has(cache(1280, pg.banner)) ? cache(1280, pg.banner) : null,
    about: pg ? pg.about : null, about_ar: pg ? pg.about_ar : null,
    items: products.filter((p) => p.bslug === b.slug).map((p) => p.slug),
  };
}).sort((a, b) => b.count - a.count);
const concerns = concernsSrc.map((c) => ({ k: c.k, name: c.en, name_ar: c.ar, short: c.t, short_ar: c.tar, tip: c.tip, tip_ar: c.tipar, sub: c.s, sub_ar: c.sar, items: c.items.map((it) => slugOf(it.u)).filter((s) => P.has(s)) }));

/* ── 9. Blog ─────────────────────────────────────────────────────────────── */
const postsEn = json(`${SITE}/api/posts`);
const posts = postsEn.slice(0, 8).map((x) => ({ slug: x.slug, title: x.title, excerpt: text(x.excerpt || '').slice(0, 220), cover: cache(800, (x.cover || '').replace(/^https?:\/\/[^/]+/, '')), date: (x.published_at || '').slice(0, 10), author: x.author }));
const strip = (h) => h.replace(/<\/(p|h[1-6]|li)>/gi, '\n').replace(/<[^>]+>/g, '').split('\n').map((s) => ent(s).trim()).filter((s) => s.length > 30);
const articles = {};
for (const x of posts.slice(0, 8)) { try { const one = json(`${SITE}/api/posts/${x.slug}`); articles[x.slug] = strip(one.body || '').slice(0, 10); } catch {} }
const coversOk = alive(posts.map((x) => x.cover));
posts.forEach((x) => { if (!coversOk.has(x.cover)) x.cover = null; });

/* ── 10. Banners and trust row from the homepage ─────────────────────────── */
function homepage(base) {
  const h = get(base + '/');
  const slides = [];
  for (const chunk of h.split('<source media="(max-width: 767.98px)"').slice(1)) {
    const ss = (chunk.match(/srcset="([^"]*)"/) || [])[1] || '';
    const im = chunk.match(/<img src="([^"]*)"[^>]*?alt="([^"]*)"/);
    if (!im) continue;
    const mob = (ss.match(/\/img-cache\/800\/[^ ,]+/) || ss.match(/\/uploads\/[^ ,]+/) || [])[0];
    const imgTag = chunk.slice(chunk.indexOf('<img'), chunk.indexOf('>', chunk.indexOf('<img')));
    const dss = (imgTag.match(/srcset="([^"]*)"/) || [])[1] || '';
    const dsk = (dss.match(/\/img-cache\/1280\/[^ ,]+/) || [])[0];
    slides.push({ mobile: mob ? SITE + mob : null, desktop: dsk ? SITE + dsk : abs(im[1]), alt: ent(im[2]) });
  }
  const i = h.indexOf('class="ktr');
  const trust = [];
  if (i > 0) { const t = h.slice(i, i + 6000).split('</section>')[0]; for (const n of t.matchAll(/<b>([^<]*)<br>([^<]*)<\/b>/g)) trust.push([ent(n[1]).trim(), ent(n[2]).trim()]); }
  return { slides, trust };
}
const homeEn = homepage(SITE), homeAr = homepage(SITE + '/ar');
const st = json(`${SITE}/api/settings`);

const data = {
  generated: new Date().toISOString(),
  source: SITE,
  settings: { currency: st.currency, vat: Number(st.vat_rate), cod_fee: fils(Number(st.cod_fee)), ship_uae: 20, free_over_uae: 199, ship_gcc: 150, free_over_gcc: 1600 },
  banners: homeEn.slides.map((s, i) => ({ ...s, alt_ar: (homeAr.slides[i] || {}).alt || null })),
  trust: homeEn.trust.map((t, i) => ({ t, t_ar: homeAr.trust[i] || null })),
  categories: cats, brands, concerns, products, posts, articles,
  counts: { catalogue: all.length, products: products.length, arabic_names: products.filter((p) => p.name_ar).length, arabic_desc: products.filter((p) => p.desc_ar).length, brands: brands.length, brands_on_site: brandIndex.length, categories: cats.length },
};
fs.mkdirSync(OUT, { recursive: true });
fs.writeFileSync(path.join(OUT, 'data.json'), JSON.stringify(data, null, 1));
split(data);
console.log(JSON.stringify(data.counts), 'banners', data.banners.length, 'trust', data.trust.length, 'posts', posts.length);
