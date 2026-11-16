'use strict';
// KBB Studio · R3 · builds the Planet Careers country packs into brands/planet-careers/countries/:
//   flags/<cc>.svg       flag-icons 7.2.3 (MIT) 4:3 flags, copied
//   maps/<cc>.svg        country outline from Natural Earth 1:50m (public domain) via world-atlas 2.0.2 (ISC)
//   landmarks/<id>.svg   original silhouettes (app/auto/countries/landmarks.js)
//   stickers/<cc>/*.svg  flag pin, map, Study in X, Visa approved, Intake open, Scholarships
//   countries.json       the pack manifest (theme colours, facts placeholders, routes, files)
// Tool-time inputs (not shipped): R3_DL=<dir with flag-icons pkg + node_modules for world-atlas, topojson-client, d3-geo>
//   node tools/r3-countries.cjs
const fs = require('fs'), path = require('path');
const ROOT = path.resolve(__dirname, '..'), OUT = path.join(ROOT, 'brands/planet-careers/countries');
const DL = process.env.R3_DL;
if (!DL) { console.error('set R3_DL'); process.exit(1); }
const NM = path.join(DL, 'nm/node_modules');
const topo = require(path.join(NM, 'topojson-client'));
const d3 = require(path.join(NM, 'd3-geo'));
const ISO = { gb: 826, ca: 124, au: 36, nz: 554, us: 840, ie: 372, de: 276, fr: 250, nl: 528, it: 380, es: 724, pt: 620, pl: 616, mt: 470, hu: 348, cz: 203, lt: 440, lv: 428, se: 752, fi: 246, dk: 208, at: 40, be: 56, cy: 196, gr: 300, ch: 756, ae: 784, jp: 392, kr: 410, sg: 702, my: 458, tr: 792, ee: 233 };
const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');

function mainland(feature) {
  const g = feature.geometry; if (g.type !== 'MultiPolygon') return feature;
  const polys = g.coordinates.map(c => ({ c, a: d3.geoArea({ type: 'Polygon', coordinates: c }), ctr: d3.geoCentroid({ type: 'Polygon', coordinates: c }) }));
  const big = polys.reduce((a, b) => (b.a > a.a ? b : a));
  const keep = polys.filter(p => d3.geoDistance(p.ctr, big.ctr) < 0.2 || p === big); // ~1270 km
  return { ...feature, geometry: { type: 'MultiPolygon', coordinates: keep.map(p => p.c) } };
}
function mapPath(feature, size = 200) {
  const f = mainland(feature), [lon, lat] = d3.geoCentroid(f);
  const proj = d3.geoConicEqualArea().parallels([lat - 5, lat + 5]).rotate([-lon, 0]).center([0, lat]);
  proj.fitExtent([[6, 6], [size - 6, size - 6]], f);
  const p = d3.geoPath(proj).digits(1);
  return p(f);
}
function flagInner(cc) {
  const s = fs.readFileSync(path.join(OUT, 'flags', cc + '.svg'), 'utf8');
  return s.replace(/^<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '').replace(/id="([^"]+)"/g, `id="${cc}-$1"`).replace(/#(?=[a-z])([a-z0-9-]+)\)/g, `#${cc}-$1)`).replace(/href="#([^"]+)"/g, `href="#${cc}-$1"`);
}
const flagNested = (cc, x, y, w, h) => `<svg x="${x}" y="${y}" width="${w}" height="${h}" viewBox="0 0 640 480" preserveAspectRatio="xMidYMid slice">${flagInner(cc)}</svg>`;

async function main() {
  const { COUNTRIES, STICKERS } = await import(path.join(ROOT, 'app/auto/countries/countries.js'));
  const { LANDMARKS, landmarkInner, landmarkSvg } = await import(path.join(ROOT, 'app/auto/countries/landmarks.js'));
  for (const d of ['flags', 'maps', 'landmarks', 'stickers']) fs.mkdirSync(path.join(OUT, d), { recursive: true });
  const FI = path.join(DL, 'fi/package');
  fs.copyFileSync(path.join(FI, 'LICENSE'), path.join(OUT, 'flags', 'LICENSE-flag-icons.txt'));
  const world = JSON.parse(fs.readFileSync(path.join(NM, 'world-atlas/countries-50m.json'), 'utf8'));
  const feats = topo.feature(world, world.objects.countries).features;
  const NAVY = '#0b2545', TEAL = '#13a89e', GOLD = '#f2c14e';
  const manifest = [];
  for (const id of Object.keys(LANDMARKS)) fs.writeFileSync(path.join(OUT, 'landmarks', id + '.svg'), landmarkSvg(id, NAVY, 'rgba(255,255,255,.6)'));
  for (const c of COUNTRIES) {
    fs.copyFileSync(path.join(FI, 'flags/4x3', c.code + '.svg'), path.join(OUT, 'flags', c.code + '.svg'));
    const feat = feats.find(f => +f.id === ISO[c.code]);
    if (!feat) throw new Error('no map for ' + c.code);
    const d = mapPath(feat);
    fs.writeFileSync(path.join(OUT, 'maps', c.code + '.svg'), `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 200 200" width="200" height="200"><path d="${d}" fill="${NAVY}" stroke="#ffffff" stroke-width="1.5" stroke-linejoin="round"/></svg>`);
    const sd = path.join(OUT, 'stickers', c.code); fs.mkdirSync(sd, { recursive: true });
    const [c1, c2] = c.colors, accent = c.colors.find(x => !/^#fff/i.test(x) && x.toLowerCase() !== '#000000') || TEAL;
    const S = {};
    S['flag-pin'] = `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 200 260" width="200" height="260"><defs><clipPath id="p"><circle cx="100" cy="96" r="72"/></clipPath><filter id="s" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="6" stdDeviation="6" flood-opacity=".3"/></filter></defs><path filter="url(#s)" d="M100 252C100 252 12 160 12 96a88 88 0 0 1 176 0c0 64-88 156-88 156Z" fill="#ffffff"/><g clip-path="url(#p)">${flagNested(c.code, 4, 24, 192, 144)}</g><circle cx="100" cy="96" r="72" fill="none" stroke="${NAVY}" stroke-opacity=".15" stroke-width="2"/></svg>`;
    S['map'] = `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 200 200" width="200" height="200"><defs><clipPath id="m"><path d="${d}"/></clipPath></defs><path d="${d}" fill="${accent}"/><g clip-path="url(#m)" opacity=".9">${flagNested(c.code, 0, 0, 200, 200)}</g><path d="${d}" fill="none" stroke="#ffffff" stroke-width="3" stroke-linejoin="round"/></svg>`;
    const pill = (text, bg, fg, w = 560) => `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 ${w} 120" width="${w}" height="120"><defs><clipPath id="f"><circle cx="60" cy="60" r="42"/></clipPath></defs><rect x="4" y="8" width="${w - 8}" height="104" rx="52" fill="${bg}"/><circle cx="60" cy="60" r="46" fill="#ffffff"/><g clip-path="url(#f)">${flagNested(c.code, 4, 18, 112, 84)}</g><text x="${(w + 112) / 2}" y="74" text-anchor="middle" font-family="Montserrat, Poppins, Arial, sans-serif" font-weight="800" font-size="${Math.min(40, Math.floor((w - 150) / (text.length * 0.66)))}" fill="${fg}" letter-spacing="1">${esc(text)}</text></svg>`;
    S['study-in'] = pill(STICKERS[2].text(c), NAVY, '#ffffff', 640);
    S['intake-open'] = pill('INTAKE OPEN', TEAL, '#ffffff', 520);
    S['scholarships'] = pill('SCHOLARSHIPS', GOLD, NAVY, 520);
    S['visa-approved'] = `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 260 260" width="260" height="260"><defs><path id="a" d="M40 130a90 90 0 1 1 180 0a90 90 0 1 1-180 0"/><clipPath id="f"><circle cx="130" cy="130" r="40"/></clipPath></defs><g transform="rotate(-12 130 130)"><circle cx="130" cy="130" r="118" fill="#ffffff" fill-opacity=".92"/><circle cx="130" cy="130" r="112" fill="none" stroke="#1f9d55" stroke-width="8"/><circle cx="130" cy="130" r="72" fill="none" stroke="#1f9d55" stroke-width="3"/><text font-family="Montserrat, Poppins, Arial, sans-serif" font-weight="800" font-size="26" fill="#1f9d55" letter-spacing="4"><textPath href="#a" startOffset="0">VISA APPROVED ✓ ${esc(c.name.toUpperCase())} ✓</textPath></text><g clip-path="url(#f)">${flagNested(c.code, 76, 90, 108, 81)}</g></g></svg>`;
    for (const [k, v] of Object.entries(S)) fs.writeFileSync(path.join(sd, k + '.svg'), v);
    manifest.push({ code: c.code, name: c.name, nameAr: c.nameAr, region: c.region, colors: c.colors, landmark: { id: c.landmark, name: LANDMARKS[c.landmark].name, file: `landmarks/${c.landmark}.svg` },
      flag: `flags/${c.code}.svg`, map: `maps/${c.code}.svg`, stickers: Object.fromEntries(Object.keys(S).map(k => [k, `stickers/${c.code}/${k}.svg`])),
      routes: c.routes, recipe: 'pc-spotlight-' + c.code, facts: c.facts, sticker_text_ar: { 'study-in': STICKERS[2].textAr(c), 'visa-approved': STICKERS[3].textAr(c), 'intake-open': STICKERS[4].textAr(c), scholarships: STICKERS[5].textAr(c) } });
    console.log(c.code, c.name.padEnd(22), c.landmark, d.length + 'B map');
  }
  fs.writeFileSync(path.join(OUT, 'countries.json'), JSON.stringify({ v: 1, generated: 'tools/r3-countries.cjs', note: 'Facts marked <edit> are placeholders. Verify all visa / study / work rules on official sites before publishing.', countries: manifest }, null, 1));
}
main().catch(e => { console.error(e); process.exit(1); });
