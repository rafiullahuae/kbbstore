#!/usr/bin/env node
/*
 * Lane APV — real data for the app previews (docs/app-preview/).
 *
 *   node tools/apv-data.cjs
 *
 * Reads the LIVE public API of kbeautybliss.com at build time and writes a
 * trimmed docs/app-preview/data.json, then inlines the same JSON into
 * docs/app-preview/index.html (between the APV-DATA markers) so the preview
 * opens from file:// and from a phone with no server and no fetch.
 *
 * Sources (all public, read-only):
 *   /api/products?limit=100&page=n   catalogue (prices in fils)
 *   /api/search?q=…  and  /ar/api/search?q=…   brand + Arabic product names
 *   /api/products/{slug}/reviews     reviews for the featured products
 *   /api/posts, /ar/api/posts, /api/posts/{slug}   blog
 *   /  and  /ar/                     banner slides + trust row (homepage HTML)
 *   docs/sbc-preview/concerns.json   Shop by Concern tabs, tips (EN + AR)
 *
 * curl does the HTTP (it honours the agent proxy; node's fetch does not).
 */
'use strict';
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'docs/app-preview');
const SITE = 'https://kbeautybliss.com';

function get(url) {
  for (let i = 0; i < 3; i++) {
    try {
      return execFileSync('curl', ['-sS', '-m', '40', '--compressed', url], { encoding: 'utf8', maxBuffer: 64 << 20 });
    } catch (e) { if (i === 2) throw e; }
  }
}
const json = (url) => JSON.parse(get(url));

/** HEAD many URLs in one curl process; returns Set of URLs that answered 200. */
function alive(urls) {
  const ok = new Set();
  for (let i = 0; i < urls.length; i += 40) {
    const chunk = urls.slice(i, i + 40);
    const args = ['-s', '-I', '-m', '30', '--parallel', '--parallel-max', '8'];
    for (const u of chunk) args.push('-o', '/dev/null', '-w', '%{http_code} %{url_effective}\\n', u);
    let out = '';
    try { out = execFileSync('curl', args, { encoding: 'utf8' }); } catch (e) { out = String(e.stdout || ''); }
    for (const line of out.split('\n')) {
      const m = line.match(/^(\d{3}) (\S+)/);
      if (m && m[1] === '200') ok.add(m[2]);
    }
  }
  return ok;
}

/** Put data.json into index.html (between the APV-DATA markers). */
function inject(data) {
  const idx = path.join(OUT, 'index.html');
  if (!fs.existsSync(idx)) return;
  const html = fs.readFileSync(idx, 'utf8');
  const blob = JSON.stringify(data).replace(/</g, '\\u003c');
  fs.writeFileSync(idx, html.replace(/<!--APV-DATA-->[\s\S]*?<!--\/APV-DATA-->/,
    () => `<!--APV-DATA--><script id="apv-data" type="application/json">${blob}</script><!--/APV-DATA-->`));
}
// `--inject` re-inlines the stored data.json without touching the network.
if (process.argv.includes('--inject')) {
  inject(JSON.parse(fs.readFileSync(path.join(OUT, 'data.json'), 'utf8')));
  console.log('injected');
  process.exit(0);
}

const fils = (n) => (n == null ? null : Math.round(n) / 100);
const img = (w, p) => (p ? `${SITE}/img-cache/${w}${encodeURI(decodeURI(p))}` : null);

// ── 1. Catalogue ────────────────────────────────────────────────────────────
const all = [];
for (let page = 1; page <= 30; page++) {
  const rows = json(`${SITE}/api/products?limit=100&page=${page}`);
  all.push(...rows);
  if (rows.length < 100) break;
}
console.log('catalogue rows', all.length);
const bySlug = new Map(all.map((p) => [p.slug, p]));

// ── 2. Shop by Concern (owner's chosen tabs, tips EN + AR) ──────────────────
const concernsSrc = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/sbc-preview/concerns.json'), 'utf8'));
const slugOf = (u) => (u.match(/\/product\/([^/]+)\/?$/) || [])[1];

// ── 3. Selection: concerns first, then best sellers, deals, under 54, trending
const buyable = all.filter((p) => p.image && p.stock_status !== 'outofstock');
const price = (p) => fils(p.sale_price ?? p.price);
const pick = new Map();
const add = (p, tag) => { if (!p) return; if (!pick.has(p.slug)) pick.set(p.slug, { p, tags: new Set() }); pick.get(p.slug).tags.add(tag); };

for (const c of concernsSrc) for (const it of c.items) add(bySlug.get(slugOf(it.u)), 'concern:' + c.k);
const best = [...buyable].sort((a, b) => (b.review_count || 0) - (a.review_count || 0)).slice(0, 12);
best.forEach((p) => add(p, 'best'));
const trending = buyable.slice(0, 40).filter((p) => !best.includes(p)).slice(0, 12);
trending.forEach((p) => add(p, 'trending'));
const under = buyable.filter((p) => price(p) <= 54).sort((a, b) => (b.review_count || 0) - (a.review_count || 0)).slice(0, 10);
under.forEach((p) => add(p, 'under54'));
console.log('selected', pick.size);

// ── 4. Brand + Arabic name, from the site's own search ─────────────────────
function searchMatch(base, q, slug) {
  try {
    const r = json(`${base}/api/search?q=${encodeURIComponent(q)}`);
    const g = (r.groups || []).find((x) => x.key === 'products');
    return (g ? g.items : []).find((it) => (it.url || '').includes('/product/' + slug + '/')) || null;
  } catch { return null; }
}
const words = (s) => s.replace(/[^A-Za-z0-9.' ]+/g, ' ').split(/\s+/).filter(Boolean);
function queriesFor(name) {
  const w = words(name);
  return [w.slice(0, 5).join(' '), w.slice(1, 6).join(' '), w.slice(-4).join(' ')].filter((q) => q.length > 2);
}
let arHits = 0;
for (const { p } of pick.values()) {
  let en = null, ar = null;
  for (const q of queriesFor(p.name)) { en = searchMatch(SITE, q, p.slug); if (en) break; }
  for (const q of queriesFor(p.name)) { ar = searchMatch(SITE + '/ar', q, p.slug); if (ar) break; }
  p._brand = (en && en.meta) || (p.name.includes(' - ') ? p.name.split(' - ')[0].trim() : words(p.name)[0]);
  // One spelling per brand ("medicube" and "Medicube" are one brand on the site).
  const seen = [...pick.values()].map((x) => x.p._brand).find((b) => b && p._brand && b.toLowerCase() === p._brand.toLowerCase());
  if (seen) p._brand = seen;
  p._ar = ar && /[؀-ۿ]/.test(ar.label) ? ar.label : null;
  p._arBrand = ar && ar.meta && /[؀-ۿ]/.test(ar.meta) ? ar.meta : null;
  if (p._ar) arHits++;
}
console.log('arabic names found', arHits, '/', pick.size);

// ── 5. Images that really exist at the widths the app asks for ─────────────
const want = [];
for (const { p } of pick.values()) {
  want.push(img(400, p.image), img(800, p.image), img(200, p.image));
  (p.images || []).slice(0, 5).forEach((g) => want.push(img(800, g), img(200, g)));
}
const live = alive([...new Set(want)]);
console.log('image urls alive', live.size, '/', new Set(want).size);

// ── 6. Categories, derived from names (labelled as derived in the README) ──
const CATS = [
  ['cleansers', 'Cleansers', 'منظفات', /cleans|foam|wash|oil to foam|balm cleans/i],
  ['toners', 'Toners & Pads', 'تونر وأقراص', /toner|pad|mist/i],
  ['serums', 'Serums & Ampoules', 'سيروم وأمبول', /serum|ampoule|essence|booster|spicule/i],
  ['moisturisers', 'Moisturisers', 'مرطبات', /cream|lotion|gel|emulsion|balm/i],
  ['sun', 'Sun Care', 'واقي الشمس', /sun|spf|uv/i],
  ['masks', 'Masks & Patches', 'أقنعة ولصقات', /mask|patch|sheet/i],
  ['eyes-lips', 'Eyes & Lips', 'العيون والشفاه', /eye|lip/i],
  ['body-hair', 'Body & Hair', 'الجسم والشعر', /body|hair|shampoo|deodor|hand/i],
  ['sets', 'Sets & Kits', 'مجموعات', /set|kit|duo|trio/i],
];
const catOf = (name) => (CATS.find((c) => c[3].test(name)) || ['other'])[0];

// ── 7. Products out ─────────────────────────────────────────────────────────
const products = [];
for (const { p, tags } of pick.values()) {
  const card = img(400, p.image);
  if (!live.has(card)) continue;
  const gallery = [p.image, ...(p.images || [])].filter((g, i, a) => a.indexOf(g) === i)
    .filter((g) => live.has(img(800, g)) && live.has(img(200, g))).slice(0, 5);
  products.push({
    slug: p.slug,
    name: p.name,
    name_ar: p._ar,
    brand: p._brand,
    brand_ar: p._arBrand,
    price: fils(p.price),
    sale: p.sale_price != null && p.sale_price < p.price ? fils(p.sale_price) : null,
    was: p.compare_at_price && p.compare_at_price > (p.sale_price ?? p.price) ? fils(p.compare_at_price) : null,
    rating: p.rating || 0,
    reviews: p.review_count || 0,
    stock: p.stock_status,
    img: card,
    img200: live.has(img(200, p.image)) ? img(200, p.image) : card,
    gallery: gallery.map((g) => ({ src: img(800, g), thumb: img(200, g) })),
    desc: (p.short_description || '').replace(/\s+/g, ' ').trim().slice(0, 700),
    cat: catOf(p.name),
    tags: [...tags],
  });
}
console.log('products out', products.length);
const inSet = new Set(products.map((p) => p.slug));

// ── 8. Concerns (tabs, tips, photos, products) ──────────────────────────────
const concerns = concernsSrc.map((c) => ({
  k: c.k, en: c.en, ar: c.ar, short: c.t, short_ar: c.tar, tip: c.tip, tip_ar: c.tipar, sub: c.s, sub_ar: c.sar,
  items: c.items.map((it) => slugOf(it.u)).filter((s) => inSet.has(s)),
}));

// ── 9. Brands ───────────────────────────────────────────────────────────────
const brandNames = [...new Set(products.map((p) => p.brand))];
const brands = [];
for (const b of brandNames) {
  let en = null, ar = null;
  try { en = (json(`${SITE}/api/search?q=${encodeURIComponent(b)}`).groups || []).find((g) => g.key === 'brands'); } catch {}
  try { ar = (json(`${SITE}/ar/api/search?q=${encodeURIComponent(b)}`).groups || []).find((g) => g.key === 'brands'); } catch {}
  const e = en && en.items[0], a = ar && ar.items[0];
  brands.push({
    name: (e && e.label) || b,
    name_ar: a && /[؀-ۿ]/.test(a.label) ? a.label : null,
    count: e ? parseInt(e.meta, 10) || null : null,
    colour: (e && e.colour) || null,
    initials: (e && e.initials) || b[0],
    slug: e ? (e.url.match(/\/brands\/([^/]+)/) || [])[1] : null,
  });
}
brands.sort((x, y) => (y.count || 0) - (x.count || 0));

// ── 10. Reviews for the featured products ───────────────────────────────────
const reviewed = [...products].sort((a, b) => b.reviews - a.reviews).slice(0, 8);
const reviews = {};
for (const p of reviewed) {
  try {
    reviews[p.slug] = json(`${SITE}/api/products/${p.slug}/reviews`).slice(0, 8).map((r) => ({
      name: r.author_name, rating: r.rating, text: r.content, verified: !!r.verified, date: (r.created_at || '').slice(0, 10),
    }));
  } catch {}
}

// ── 11. Blog ────────────────────────────────────────────────────────────────
const postsEn = json(`${SITE}/api/posts`);
let postsAr = [];
try { postsAr = json(`${SITE}/ar/api/posts`); } catch {}
const arBySlug = new Map((Array.isArray(postsAr) ? postsAr : []).map((x) => [x.slug, x]));
const posts = postsEn.slice(0, 6).map((x) => {
  const a = arBySlug.get(x.slug);
  const arT = a && /[؀-ۿ]/.test(a.title) ? a.title : null;
  return { slug: x.slug, title: x.title, title_ar: arT, excerpt: (x.excerpt || '').replace(/\s+/g, ' ').slice(0, 220),
    excerpt_ar: arT ? (a.excerpt || '').replace(/\s+/g, ' ').slice(0, 220) : null, cover: `${SITE}/img-cache/800${encodeURI(decodeURI((x.cover || '').replace(/^https?:\/\/[^/]+/, '')))}`, date: (x.published_at || '').slice(0, 10), author: x.author };
});
const strip = (h) => h.replace(/<\/(p|h[1-6]|li)>/gi, '\n').replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&')
  .replace(/&#8217;|&rsquo;/g, '’').replace(/&quot;/g, '"').split('\n').map((s) => s.trim()).filter((s) => s.length > 30);
let article = null;
try {
  const one = json(`${SITE}/api/posts/${posts[0].slug}`);
  article = { slug: one.slug, paras: strip(one.body || '').slice(0, 8) };
  try {
    const oa = json(`${SITE}/ar/api/posts/${posts[0].slug}`);
    const pa = strip(oa.body || '');
    if (pa.some((s) => /[؀-ۿ]/.test(s))) article.paras_ar = pa.slice(0, 8);
  } catch {}
} catch {}

// ── 12. Banners and trust row from the homepage ─────────────────────────────
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
    slides.push({ mobile: mob ? SITE + mob : null, desktop: dsk ? SITE + dsk : im[1], alt: im[2] });
  }
  const i = h.indexOf('class="ktr');
  const trust = [];
  if (i > 0) {
    const t = h.slice(i, i + 6000).split('</section>')[0];
    for (const n of t.matchAll(/<b>([^<]*)<br>([^<]*)<\/b>/g)) trust.push([n[1].trim(), n[2].trim()]);
  }
  return { slides, trust };
}
const homeEn = homepage(SITE), homeAr = homepage(SITE + '/ar');

// ── 13. Shop settings that the bag and checkout show ───────────────────────
const st = json(`${SITE}/api/settings`);

const data = {
  generated: new Date().toISOString(),
  source: SITE,
  note: 'Prices are AED (the API answers in fils). name_ar null = no Arabic name on the live site yet; the preview shows the English name and marks it.',
  settings: { currency: st.currency, vat: Number(st.vat_rate), cod_fee: fils(Number(st.cod_fee)), ship_uae: 20, free_over_uae: 199 },
  banners: homeEn.slides.map((s, i) => ({ ...s, alt_ar: (homeAr.slides[i] || {}).alt || null })),
  trust: homeEn.trust.map((t, i) => ({ en: t, ar: homeAr.trust[i] || null })),
  categories: CATS.map(([k, en, ar]) => ({ k, en, ar, n: products.filter((p) => p.cat === k).length })).filter((c) => c.n),
  concerns, brands, products, reviews, posts, article,
  counts: { catalogue: all.length, products: products.length, arabic_names: products.filter((p) => p.name_ar).length, brands: brands.length },
};

fs.mkdirSync(OUT, { recursive: true });
fs.writeFileSync(path.join(OUT, 'data.json'), JSON.stringify(data, null, 1));
inject(data);
console.log(JSON.stringify(data.counts), 'banners', data.banners.length, 'trust', data.trust.length, 'posts', posts.length, 'article', !!article);
