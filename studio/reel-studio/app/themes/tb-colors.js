// KBB Studio · themes · TEXT BEHIND THE HOST — country colours (the owner, 20 Nov 2026: "also the Behind big text, i want
// pre colors templates. like Europe has it's own colors, and each country color itself, and i can change these from
// white to colored, and manual colors of each country if required").
//
// Every country and region the picker offers has a palette drawn from its flag (drawn here, no flag image needed), in
// three fill styles: the flag's stripes across the letters, its main colour solid, or two of its colours with a third as
// the outline. The element chooses White (the look it always had), Country colours (each word in its own country's
// colours, a carousel changing palette as each country comes up) or One colour for all (the fill controls). Any
// country can be given its own colours by hand, and put back to its flag.
//
//   style.tb.cc = { mode: 'white' | 'country' | 'one', style: 'stripes' | 'solid' | 'two',
//                   over: { <code>: { style?, colors?: [hex…], outline?: hex } } }
//   style.tb.top.cmode = 'same' | 'white' | 'follow' | 'manual'   (the top line: as the main line, white, the current
//                   country's colours, or top.color)
// No cc at all = the element as it always drew (tb.fill): old projects render byte for byte as before.
// Leaf module: pure data and pure functions (the check reads the palettes in Node).

// ── the palettes ──
// 'dir colours… [sN] [mI] [tIJK] [oI]': dir = how the stripes run across a word — v: top to bottom (horizontal bands,
// Germany), h: left to right (vertical bands, France); the colours as the flag lays them out (a band twice as wide is
// listed twice); sN: only the first N are stripes (the rest are emblem colours); mI: the main colour (solid); tIJK: the
// two-tone fill (I top half, J bottom half) and K its outline; oI: the outline over a light fill (default: the darkest
// colour that is not the fill's own). Colours follow flag-icons 7.6.0, the flags the carousel shows beside the words.
const SPEC = {
  // regions and continents
  europe: 'v 003399 FFCC00 003399 m0 t010', eu: '=europe',
  gulf: 'v 00732F FFFFFF 000000 CE1126 s3 m0 t023 o3', 'middle-east': 'v CE1126 FFFFFF 000000 007A3D s3 m0 t023 o3',
  asia: 'v DE2910 FFDE00 DE2910 m0 t011', africa: 'v 078930 FCDD09 DA121A 000000 s3 m0 t023',
  'north-america': 'v B31942 FFFFFF B31942 FFFFFF 0A3161 s5 m0 t401 o4', 'south-america': 'v FCD116 003893 CE1126 m1 t012',
  oceania: 'v 00247D FFFFFF CC142B m0 t021',
  // Europe
  al: 'v E41E20 000000 E41E20 m0 t011', ad: 'h 10069F FEDF00 D50032 m0 t021', at: 'v C8102E FFFFFF C8102E m0 t010',
  by: 'v C8313E C8313E 4AA657 FFFFFF s3 m0 t023', be: 'h 000000 FDDA24 EF3340 m1 t120', ba: 'h 002395 FECB00 002395 FFFFFF s3 m0 t013',
  bg: 'v FFFFFF 00966E D62612 m1 t120', hr: 'v FF0000 FFFFFF 171796 m0 t021', cy: 'v D57800 FFFFFF 4E5B31 m0 t020',
  cz: 'v FFFFFF D7141A 11457E s2 m1 t012 o2', dk: 'v C8102E FFFFFF C8102E m0 t010', ee: 'v 0072CE 000000 FFFFFF m0 t010',
  fi: 'v FFFFFF 002F6C FFFFFF m1 t111 o1', fr: 'h 000091 FFFFFF E1000F m0 t021', de: 'v 000000 DD0000 FFCE00 m1 t120',
  gr: 'v 0D5EAF FFFFFF 0D5EAF FFFFFF 0D5EAF m0 t010', hu: 'v CD2A3E FFFFFF 436F4D m0 t020', is: 'v 02529C FFFFFF DC1E35 FFFFFF 02529C m0 t021',
  ie: 'h 169B62 FFFFFF FF883E m0 t020', it: 'h 009246 FFFFFF CE2B37 m0 t021', xk: 'v 244AA5 D0A650 244AA5 FFFFFF s3 m0 t013',
  lv: 'v 9E3039 9E3039 FFFFFF 9E3039 9E3039 m0 t020', li: 'v 002B7F CE1126 FFD83D s2 m0 t012', lt: 'v FDB913 006A44 C1272D m1 t021',
  lu: 'v EA141D FFFFFF 51ADDA m0 t021', mt: 'h FFFFFF CF142B m1 t111', md: 'h 0046AE FFD200 CC092F m0 t021',
  mc: 'v CE1126 FFFFFF m0 t010', me: 'v D3AE3B C40308 C40308 D3AE3B m1 t110', nl: 'v AE1C28 FFFFFF 21468B m0 t021',
  mk: 'v D20000 FFE600 D20000 m0 t011', no: 'v BA0C2F FFFFFF 00205B FFFFFF BA0C2F m0 t021', pl: 'v FFFFFF DC143C m1 t010',
  pt: 'h 006600 FF0000 FF0000 FFE900 s3 m1 t013', ro: 'h 002B7F FCD116 CE1126 m0 t021', ru: 'v FFFFFF 0039A6 D52B1E m1 t120',
  sm: 'v FFFFFF 5EB6E4 m1 t111', rs: 'v C6363C 0C4076 FFFFFF m0 t012', sk: 'v FFFFFF 0B4EA2 EE1C25 m1 t120', si: 'v FFFFFF 005DA4 ED1C24 m1 t120',
  es: 'v AA151B F1BF00 F1BF00 AA151B m0 t011', se: 'v 006AA7 FECC02 006AA7 m0 t011', ch: 'v DA291C FFFFFF DA291C m0 t010',
  ua: 'v 0057B7 FFD700 m0 t011', gb: 'v 012169 FFFFFF C8102E FFFFFF 012169 m0 t021', 'gb-eng': 'v FFFFFF CE1124 FFFFFF m1 t111',
  'gb-sct': 'v 005EB8 FFFFFF 005EB8 m0 t010', 'gb-wls': 'v FFFFFF 00AB39 C8102E s2 m2 t212', 'gb-nir': 'v FFFFFF CC0000 FFFFFF m1 t111',
  va: 'h FFE000 FFFFFF m0 t010', ax: 'v 0064AD FFD300 DA0E15 FFD300 0064AD m0 t021', fo: 'v FFFFFF 005EB8 EF303E 005EB8 FFFFFF m2 t212',
  gi: 'v FFFFFF FFFFFF DA000C m2 t220', gg: 'v FFFFFF E8112D FFFFFF F9DD16 s3 m1 t131', im: 'v CF142B FFFFFF CF142B m0 t010',
  je: 'v FFFFFF DF112D FFFFFF m1 t111', tr: 'v E30A17 FFFFFF E30A17 m0 t010', sj: '=no', bv: '=no',
  // the Middle East and the Gulf
  ae: 'v 00732F FFFFFF 000000 FF0000 s3 m3 t023 o3', sa: 'v 006C35 FFFFFF 006C35 m0 t010', qa: 'h FFFFFF 8A1538 8A1538 m1 t111',
  kw: 'v 007A3D FFFFFF CE1126 000000 s3 m0 t023 o3', bh: 'h FFFFFF CE1126 CE1126 m1 t111', om: 'v FFFFFF DB161B 008000 m1 t121',
  ye: 'v CE1126 FFFFFF 000000 m0 t020', iq: 'v CE1126 FFFFFF 000000 007A3D s3 m0 t023 o3', ir: 'v 239F40 FFFFFF DA0000 m0 t020',
  jo: 'v 000000 FFFFFF 007A3D CE1126 s3 m3 t023', lb: 'v ED1C24 FFFFFF ED1C24 00A651 s3 m0 t013', sy: 'v 007A3D FFFFFF 000000 CE1126 s3 m0 t023 o3',
  il: 'v 0038B8 FFFFFF 0038B8 m0 t010', ps: 'v 000000 FFFFFF 009639 ED2E38 s3 m3 t023', eg: 'v CE1126 FFFFFF 000000 C09300 s3 m0 t023',
  // Asia
  af: 'h 000000 BF0000 009900 m1 t120', am: 'v D90012 0033A0 F2A800 m0 t012', az: 'v 0092BC E4002B 00AF66 m1 t021',
  bd: 'v 006A4E F42A41 006A4E m0 t011', bt: 'v FFD520 FF4E12 FFFFFF s2 m1 t011', bn: 'v F7E017 FFFFFF 000000 CF1126 s3 m0 t023',
  kh: 'v 032EA1 E00025 E00025 032EA1 m1 t100', cn: 'v EE1C25 FFFF00 EE1C25 m0 t010', ge: 'v FFFFFF FF0000 FFFFFF m1 t111',
  hk: 'v DE2910 FFFFFF DE2910 m0 t010', in: 'v FF9933 FFFFFF 138808 000080 s3 m0 t023 o3', id: 'v FF0000 FFFFFF m0 t010',
  jp: 'h FFFFFF BC002D FFFFFF m1 t111', kz: 'v 00AFCA FEC50C 00AFCA m0 t011', kg: 'v E8112D FFEF00 E8112D m0 t011',
  la: 'v CE1126 002868 002868 CE1126 FFFFFF s4 m1 t100', mo: 'v 00785E FFFFFF 00785E FBD116 s3 m0 t013', my: 'v CC0001 FFFFFF CC0001 FFFFFF 010066 FFCC00 s4 m0 t045 o4',
  mv: 'v D21034 007E3A D21034 FFFFFF s3 m1 t100', mn: 'h C4272F 015197 C4272F F9CF02 s3 m0 t013', mm: 'v FECB00 34B233 EA2839 m1 t021',
  np: 'v DC143C 003893 FFFFFF s2 m0 t011 o1', kp: 'v 024FA2 FFFFFF ED1C27 FFFFFF 024FA2 m2 t200', pk: 'h FFFFFF 01411C 01411C 01411C m1 t111',
  ph: 'v 0038A8 CE1126 FFFFFF FCD116 s2 m0 t013', sg: 'v EF3340 FFFFFF m0 t010', kr: 'v CD2E3A 0047A0 FFFFFF 000000 s2 m0 t013',
  lk: 'h 00534E EB7400 8D153A 8D153A FFBE29 s4 m2 t244', tw: 'v 000095 FE0000 FFFFFF s2 m1 t010', tj: 'v CC0000 FFFFFF 006600 F8C300 s3 m0 t023',
  th: 'v A51931 F4F5F8 2D2A4A F4F5F8 A51931 m2 t020', tl: 'v DC241F FFC726 000000 FFFFFF s3 m0 t021', tm: 'h 00843D D22630 00843D FFFFFF s3 m0 t011',
  uz: 'v 0099B5 FFFFFF 1EB53A CE1126 s3 m0 t023', vn: 'v DA251D FFFF00 DA251D m0 t010',
  // North America and the Caribbean
  us: 'v B31942 FFFFFF B31942 FFFFFF B31942 0A3161 s5 m0 t501 o5', ca: 'h D52B1E FFFFFF FFFFFF D52B1E m0 t010', mx: 'h 006847 FFFFFF CE1126 m0 t031',
  bs: 'v 00ABC9 FAE042 00ABC9 000000 s3 m0 t013', bb: 'h 00267F FFC726 00267F 000000 s3 m0 t013', bz: 'v CE1126 003F87 003F87 CE1126 FFFFFF s4 m1 t100',
  cr: 'v 002B7F FFFFFF CE1126 FFFFFF 002B7F m2 t020', cu: 'v 002A8F FFFFFF 002A8F FFFFFF 002A8F CF142B s5 m0 t051', dm: 'v 006B3F FCD116 000000 FFFFFF D41C30 s4 m0 t041',
  do: 'v 002D62 FFFFFF CE1126 m0 t020', sv: 'v 0F47AF FFFFFF 0F47AF m0 t010', gd: 'v CE1126 FCD116 007A5E CE1126 m2 t210',
  gt: 'h 4997D0 FFFFFF 4997D0 m0 t010', ht: 'v 00209F D21034 FFFFFF s2 m0 t012', hn: 'v 00BCE4 FFFFFF 00BCE4 m0 t010', jm: 'v 009B3A FED100 000000 m0 t021',
  ni: 'v 0067C6 FFFFFF 0067C6 m0 t010', pa: 'h 072357 FFFFFF DA121A m0 t020', kn: 'v 009E49 FCD116 000000 FCD116 CE1126 m0 t042',
  lc: 'v 65CFFF FFFFFF 000000 FCD116 s3 m0 t032', vc: 'h 002674 FCD022 009E60 m2 t201', tt: 'v DA1A35 FFFFFF 000000 FFFFFF DA1A35 m0 t020',
  ag: 'v 000000 0072C6 FFFFFF CE1126 FCD116 s3 m3 t314', pr: 'v E92228 FFFFFF E92228 FFFFFF E92228 0050F0 s5 m0 t051 o5', gl: 'v FFFFFF D00C33 m1 t010',
  bm: 'v C8102E FFFFFF 012169 m0 t021', cw: 'v 002B7F F9E814 002B7F m0 t011', aw: 'v 418FDE FBE122 418FDE EF3340 s3 m0 t013',
  sx: 'v DC171D 012A87 FFFFFF s2 m0 t012', vi: 'v FFFFFF FFCE00 0F4D92 m2 t121', mq: 'v 00A650 231F1E EF1923 s2 m2 t201',
  // South America
  ar: 'v 74ACDF FFFFFF 74ACDF F6B40E s3 m0 t013', bo: 'v D52B1E F9E300 007934 m0 t021', br: 'v 009C3B FFDF00 002776 m0 t012',
  cl: 'v FFFFFF D52B1E 0039A6 s2 m1 t212 o2', co: 'v FCD116 FCD116 003893 CE1126 m0 t023', ec: 'v FFDD00 FFDD00 034EA2 ED1C24 m0 t023',
  gy: 'v 009E49 FCD116 CE1126 000000 FFFFFF s3 m0 t013', py: 'v D52B1E FFFFFF 0038A8 m0 t020', pe: 'h D91023 FFFFFF D91023 m0 t010',
  sr: 'v 377E3F FFFFFF B40A2D FFFFFF 377E3F ECC81D s5 m2 t205', uy: 'v FFFFFF 0038A8 FFFFFF 0038A8 FFFFFF FCD116 s5 m1 t151', ve: 'v FFCC00 00247D CF142B m0 t012',
  // Africa
  dz: 'h 006233 FFFFFF D21034 s2 m0 t012', ao: 'v CC092F 000000 FFCB00 s2 m0 t012', bj: 'v FCD116 E8112D 008751 s2 m2 t201',
  bw: 'v 75AADB FFFFFF 000000 FFFFFF 75AADB m0 t020', bf: 'v EF2B2D 009E49 FCD116 s2 m0 t012', bi: 'v CE1126 FFFFFF 1EB53A m0 t020',
  cv: 'v 003893 FFFFFF CF2027 FFFFFF 003893 F7D116 s5 m0 t025', cm: 'h 007A5E CE1126 FCD116 m1 t012', cf: 'v 003082 FFFFFF 289728 FFCE00 D21034 s4 m4 t023',
  td: 'h 002664 FECB00 C60C30 m0 t021', km: 'v FFC61E FFFFFF CE1126 3A75C4 3D8E33 s4 m4 t430', cd: 'v 007FFF F7D618 CE1021 F7D618 007FFF m0 t021',
  cg: 'v 009543 FBDE4A DC241F m0 t021', ci: 'h F77F00 FFFFFF 009E60 m0 t020', dj: 'v 6AB2E7 12AD2B FFFFFF D7141A s2 m0 t013',
  gq: 'v 3E9A00 FFFFFF E32118 0073CE s3 m0 t023', er: 'v 12AD2B EA0437 4189DD FFC726 s3 m1 t023', sz: 'v 3E5EB9 FFD900 B10C0C FFD900 3E5EB9 m2 t201',
  et: 'v 078930 FCDD09 DA121A 0F47AF s3 m0 t023', ga: 'v 009E60 FCD116 3A75C4 m0 t021', gm: 'v CE1126 FFFFFF 0C1C8C FFFFFF 3A7728 m2 t040',
  gh: 'v CE1126 FCD116 006B3F 000000 s3 m1 t023', gn: 'h CE1126 FCD116 009460 m0 t021', gw: 'v FCD116 009E49 CE1126 000000 s2 m2 t013',
  ke: 'v 000000 FFFFFF BB0000 FFFFFF 006600 m2 t240', ls: 'v 00209F FFFFFF 009543 000000 s3 m0 t023', lr: 'v BF0A30 FFFFFF BF0A30 FFFFFF 002868 s4 m0 t041 o4',
  ly: 'v E70013 000000 239E46 m0 t021', mg: 'v FC3D32 007E3A FFFFFF s2 m0 t012', mw: 'v 000000 CE1126 339E35 m1 t120',
  ml: 'h 14B53A FCD116 CE1126 m0 t021', mr: 'v D01C1F 00A95C 00A95C D01C1F FFD700 s4 m1 t014', mu: 'v EA2839 1A206D FFD500 00A551 m0 t032',
  ma: 'v C1272D 006233 C1272D m0 t011', mz: 'v 007168 FFFFFF 000000 FFFFFF FCE100 D21034 s5 m0 t045 o5', na: 'v 003580 FFFFFF D21034 FFFFFF 009543 FFCE00 s5 m2 t045',
  ne: 'v E05206 FFFFFF 0DB02B m0 t020', ng: 'h 008751 FFFFFF 008751 m0 t010', rw: 'v 00A1DE 00A1DE FAD201 20603D m0 t023',
  st: 'v 12AD2B FFCE00 12AD2B D21034 000000 s3 m0 t013', sn: 'h 00853F FDEF42 E31B23 m0 t021', sc: 'v 003F87 FCD856 D62828 FFFFFF 007A3D m2 t240',
  sl: 'v 1EB53A FFFFFF 0072C6 m0 t020', so: 'v 4189DD FFFFFF 4189DD m0 t010', za: 'v E03C31 FFFFFF 007749 FFFFFF 001489 FFB612 000000 s5 m2 t256',
  ss: 'v 000000 FFFFFF DA121A FFFFFF 078930 0F47AF FCDD09 s5 m2 t245', sd: 'v D21034 FFFFFF 000000 007229 s3 m0 t023', tz: 'v 1EB53A FCD116 000000 FCD116 00A3DD m0 t041',
  tg: 'v 006A4E FFCE00 006A4E FFCE00 006A4E D21034 s5 m0 t051', tn: 'v E70013 FFFFFF E70013 m0 t010', ug: 'v 000000 FCDC04 D90000 000000 FCDC04 D90000 m2 t210',
  eh: 'v 000000 FFFFFF 007A3D C4111B s3 m3 t023', zm: 'h 198A00 198A00 198A00 DE2010 000000 EF7D00 m0 t051', zw: 'v 319208 FFD200 D40000 000000 D40000 FFD200 319208 m0 t023',
  ic: 'h FFFFFF 0768A9 FFCC00 m1 t121',
  // Oceania
  au: 'v 012169 FFFFFF E4002B m0 t021', nz: 'v 012169 FFFFFF C8102E m0 t021', fj: 'v 68BFE5 FFFFFF CE1126 012169 s3 m0 t032',
  pg: 'v CE1126 000000 FCD116 s2 m0 t012', ws: 'v CE1126 002B7F FFFFFF s2 m0 t012', to: 'v C10000 FFFFFF s2 m0 t010',
  vu: 'v D21034 000000 009543 FDCE12 s3 m0 t023', sb: 'v 0051BA FCD116 215B33 m0 t021', ki: 'v CE1126 FCD116 FFFFFF 003F87 m0 t031',
  tv: 'v 009FCA FFCE00 012169 m0 t021', nr: 'v 002B7F FFC61E 002B7F m0 t011', pw: 'v 0099FF FFDE00 0099FF m0 t011',
  mh: 'v 003893 FFFFFF DD7500 m0 t021', fm: 'v 75B2DD FFFFFF 75B2DD m0 t010', nu: 'v FED000 012169 FFFFFF C8102E s1 m0 t013',
  nf: 'h 007835 FFFFFF 007835 m0 t010', tk: 'v 00247D FED100 00247D FFFFFF s3 m0 t011', as: 'v 002B7F FFFFFF BF0A30 m0 t020',
  gu: 'v C8102E 002868 002868 C8102E m1 t100', mp: 'v 0071BC FFFFFF 0071BC m0 t010', nc: 'v 0072CE E03C31 009639 FEDB00 s3 m0 t023',
  pf: 'v CE1126 FFFFFF CE1126 m0 t010', wf: 'v ED2939 FFFFFF 000091 s2 m0 t021', cx: 'v 0021AD 1C8A42 FFC639 s2 m0 t012', cc: 'v 008000 FFE000 008000 m0 t011',
  // territories that fly their sovereign's flag (as flag-icons draws them)
  gf: '=fr', gp: '=fr', re: '=fr', yt: '=fr', pm: '=fr', bl: '=fr', mf: '=fr', tf: '=fr', cp: '=fr', bq: '=nl', um: '=us', hm: '=au',
  ai: '=gb', ky: '=gb', ms: '=gb', tc: '=gb', vg: '=gb', io: '=gb', dg: '=gb', fk: '=gb', gs: '=gb', pn: '=gb', ck: '=gb',
  sh: '=gb', 'sh-ac': '=gb', 'sh-hl': '=gb', 'sh-ta': '=gb',
};

// ── reading a palette ──
const HEXRE = /^#[0-9a-f]{6}$/i;
const PALS = new Map();
/** the palette of a country / region code: { stripes, dir, main, two: [a, b, outline], outline, all } or null */
export function palOf(code) {
  if (!code) return null;
  let p = PALS.get(code);
  if (p !== undefined) return p;
  let spec = SPEC[code];
  if (typeof spec === 'string' && spec[0] === '=') spec = SPEC[spec.slice(1)];
  p = spec ? parse(spec) : null;
  PALS.set(code, p);
  return p;
}
function parse(spec) {
  const tok = spec.split(/\s+/), dir = tok[0] === 'h' ? 'h' : 'v', all = [], opt = {};
  for (const t of tok.slice(1)) { if (/^[0-9A-F]{6}$/i.test(t)) all.push('#' + t.toUpperCase()); else opt[t[0]] = t.slice(1); }
  const n = Math.max(1, Math.min(all.length, +opt.s || all.length)), stripes = all.slice(0, n), main = all[+opt.m || 0] || all[0];
  const tw = (opt.t || '').split('').map((x) => all[+x]).filter(Boolean);
  const darkest = (skip) => all.filter((c) => !skip.includes(c)).sort((a, b) => lum(a) - lum(b))[0] || '#000000';
  const two = tw.length === 3 ? tw : [main, all.find((c) => c !== main) || '#FFFFFF', darkest([main])];
  return { dir, stripes, main, two, outline: opt.o != null && all[+opt.o] ? all[+opt.o] : darkest(['#FFFFFF']), all: [...new Set(all)] };
}
/** relative luminance 0..1 */
export function lum(hex) {
  const v = parseInt(String(hex).slice(1), 16), f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * f((v >> 16) & 255) + 0.7152 * f((v >> 8) & 255) + 0.0722 * f(v & 255);
}
/** every code that has a palette (aliases included) */
export const PAL_CODES = Object.keys(SPEC);
export const hasPal = (code) => !!palOf(code);

// ── the choices ──
export const TB_CMODES = [['white', 'White'], ['country', 'Country colours'], ['one', 'One colour']];
export const TB_CSTYLES = [['stripes', 'Flag stripes'], ['solid', 'Solid'], ['two', 'Two-tone']];
export const TB_TOPC = [['same', 'As main'], ['white', 'White'], ['follow', 'Country'], ['manual', 'Own']];
const one = (v, list) => list.some((x) => x[0] === v);

/** the element's colour settings, checked: { mode: null (as it always drew) | 'white' | 'country' | 'one', style, over } */
export function ccOf(t) {
  const cc = t && typeof t.cc === 'object' && t.cc ? t.cc : null;
  if (!cc || !one(cc.mode, TB_CMODES)) return { mode: null, style: 'stripes', over: {} };
  const over = {};
  if (cc.over && typeof cc.over === 'object') for (const [k, o] of Object.entries(cc.over).slice(0, 120)) {
    if (!o || typeof o !== 'object' || !/^[a-z-]{2,16}$/.test(k)) continue;
    const r = {};
    if (one(o.style, TB_CSTYLES)) r.style = o.style;
    if (Array.isArray(o.colors)) { const c = o.colors.filter((x) => HEXRE.test(x)).slice(0, 8); if (c.length) r.colors = c; }
    if (HEXRE.test(o.outline || '')) r.outline = o.outline;
    if (Object.keys(r).length) over[k] = r;
  }
  return { mode: cc.mode, style: one(cc.style, TB_CSTYLES) ? cc.style : 'stripes', over };
}
/** the code a carousel entry stands for: its continent, else its country (the EU mark is Europe) */
export const codeOfEntry = (it) => (it?.cont || (it?.flag === 'eu' ? 'europe' : it?.flag) || null);

/** the fill colours a style takes from a palette (before any override) */
export function styleColors(p, style) {
  if (style === 'solid') return [p.main];
  if (style === 'two') return p.two.slice(0, 2);
  return p.stripes.slice();
}
/**
 * A country's look in the element: { fill: {type, colors, dir, hard}, outline (share of size), outlineCol, style } —
 * the palette in the element's style, the owner's override on top. `base` = the element's own outline width.
 * null: no palette for this code (the caller falls back to the element's fill).
 */
export function countryLook(code, cc, base = 0) {
  const p = palOf(code), o = cc.over[code] || {};
  if (!p && !o.colors) return null;
  const style = o.style || cc.style, def = p ? styleColors(p, style) : o.colors;
  let colors = def;
  if (o.colors) colors = style === 'solid' ? [o.colors[0]] : style === 'two' ? [o.colors[0], o.colors[1] || o.colors[0]] : o.colors;
  const light = colors.some((c) => lum(c) > 0.7);
  const outlineCol = o.outline || (p ? (style === 'two' ? p.two[2] : p.outline) : '#000000');
  // readable over any video: a two-tone always carries its outline, a light colour gets a thin dark edge
  const outline = Math.max(base, style === 'two' ? 0.035 : light ? 0.022 : 0);
  const fill = colors.length < 2 ? { type: 'solid', colors: [colors[0]] }
    : { type: 'grad', colors, dir: style === 'two' ? 'v' : p?.dir || 'v', hard: true };
  return { fill, outline, outlineCol, style };
}
/** CSS for a swatch of a fill (the editor's lists) */
export const fillCss = (f) => (f.type === 'grad' && f.colors.length > 1 ? `linear-gradient(${f.dir === 'h' ? '90deg' : '180deg'}, ${f.hard ? f.colors.map((c, i, a) => `${c} ${(i / a.length) * 100}% ${((i + 1) / a.length) * 100}%`).join(', ') : f.colors.join(', ')})` : f.colors[0]);
