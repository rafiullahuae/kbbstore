/* KBB Ad Studio -- the editor. One file, no network: fonts, libraries, product pictures and templates
 * are inline. Storage is an adapter: the claude.ai artifact runtime (db / assets / downloads) when the
 * page is published there, IndexedDB (then localStorage, then memory) when the file is opened offline.
 *
 * Everything read back from storage or from an imported project goes through clean*(): ids match a
 * pattern, colours a regex, fonts / shapes / icons / patterns a fixed list, numbers are clamped, text
 * is a bounded string set with textContent, and pictures must be base64 PNG/JPEG/WebP data that
 * decodes. No stored or imported string is ever parsed as HTML.
 */
(function () {
  'use strict';
  var D = /*DATA*/;
  var KT = window.KBB_T;
  // the product pictures, once: one class per product (the data lives in D, not twice)
  (function () { var css = ''; Object.keys(D.prods).forEach(function (p) { if (/^[a-z0-9]+$/.test(p)) css += '.p-' + p + '{background-image:url("' + D.prods[p].src + '")}'; }); var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s); })();
  var FMTS = ['post', 'square', 'story'], PX = { post: [1080, 1350], square: [1080, 1080], story: [1080, 1920] };
  var FLAB = { post: 'Post 4:5', square: 'Square 1:1', story: 'Story 9:16' };
  function HU(f) { return PX[f][1] / PX[f][0] * 100; }
  function $(id) { return document.getElementById(id); }
  function mk(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function uid(p) { var a = new Uint8Array(6); (window.crypto || {}).getRandomValues ? crypto.getRandomValues(a) : a.forEach(function (_, i) { a[i] = Math.random() * 256; }); return (p || 'e') + Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function r2(n) { return Math.round(n * 100) / 100; }

  // ── fixed lists (the allowlists every stored value is checked against) ──
  var FONTS = { inter: 'Inter', poppins: 'Poppins', montserrat: 'Montserrat', playfair: 'Playfair Display', dmserif: 'DM Serif Display', bebas: 'Bebas Neue', dancing: 'Dancing Script', cairo: 'Cairo' };
  var SHAPES = { none: 'None', rect: 'Rectangle', pill: 'Pill', circle: 'Circle', ellipse: 'Oval', ring: 'Ring', arch: 'Arch', burst: 'Burst', star: 'Star', heart: 'Heart', banner: 'Banner', ticket: 'Ticket', blob: 'Blob' };
  var BORDERED = { rect: 1, pill: 1, circle: 1, ellipse: 1, ring: 1, arch: 1 };
  var TYPES = { text: 'Text', btn: 'Button', shape: 'Shape', icon: 'Icon', img: 'Picture', pay: 'tabby / tamara', logo: 'Logo', prods: 'Products' };
  var PATS = { none: 'None', dots: 'Dots', grid: 'Grid', stripes: 'Stripes', rays: 'Rays', lattice: 'Lattice', waves: 'Waves', sparkle: 'Sparkles' };
  var HEX = /^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/, ID = /^[a-z][a-z0-9_]{0,31}$/i, IMGID = /^[A-Za-z0-9][A-Za-z0-9_-]{5,63}$/;
  var DATAURL = /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/]+=*$/;
  function poly(n, r1, r2_, rot) { var p = []; for (var i = 0; i < n * 2; i++) { var a = (i / (n * 2)) * Math.PI * 2 + (rot || 0), r = i % 2 ? r2_ : r1; p.push((50 + Math.sin(a) * r).toFixed(1) + '% ' + (50 - Math.cos(a) * r).toFixed(1) + '%'); } return 'polygon(' + p.join(',') + ')'; }
  function heartPoly() { var p = []; for (var i = 0; i < 48; i++) { var t = i / 48 * Math.PI * 2, x = 16 * Math.pow(Math.sin(t), 3), y = 13 * Math.cos(t) - 5 * Math.cos(2 * t) - 2 * Math.cos(3 * t) - Math.cos(4 * t); p.push((50 + x * 3.05).toFixed(1) + '% ' + (44 - y * 3.05).toFixed(1) + '%'); } return 'polygon(' + p.join(',') + ')'; }
  function blobPoly() { var p = []; for (var i = 0; i < 48; i++) { var a = i / 48 * Math.PI * 2, r = 46 * (1 + .07 * Math.sin(3 * a + .6) + .05 * Math.cos(5 * a)); p.push((50 + Math.cos(a) * r).toFixed(1) + '% ' + (50 + Math.sin(a) * r).toFixed(1) + '%'); } return 'polygon(' + p.join(',') + ')'; }
  var CLIP = { burst: poly(14, 50, 41), star: poly(5, 50, 21), heart: heartPoly(), blob: blobPoly(),
    banner: 'polygon(0 0,100% 0,95% 50%,100% 100%,0 100%,5% 50%)',
    ticket: 'polygon(0 0,100% 0,100% 40%,96% 50%,100% 60%,100% 100%,0 100%,0 60%,4% 50%,0 40%)' };
  function svgPoly(n, r1, r2_) { var p = []; for (var i = 0; i < n * 2; i++) { var a = i / (n * 2) * Math.PI * 2, r = i % 2 ? r2_ : r1; p.push((12 + Math.sin(a) * r).toFixed(2) + ',' + (12 - Math.cos(a) * r).toFixed(2)); } return '<polygon points="' + p.join(' ') + '" fill="currentColor"/>'; }
  var SW = ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
  // Constant markup only: an icon is chosen by name from this list, never supplied.
  var ICONS = {
    sparkle: ['Sparkle', '<path fill="currentColor" d="M12 0c.9 6.6 4.8 10.6 12 12-7.2 1.4-11.1 5.4-12 12-.9-6.6-4.8-10.6-12-12C7.2 10.6 11.1 6.6 12 0z"/>'],
    star: ['Star', '<path fill="currentColor" d="M12 1.5l3.1 6.6 7.2.9-5.3 5 1.4 7.1L12 17.6 5.6 21.1 7 14l-5.3-5 7.2-.9z"/>'],
    heart: ['Heart', '<path fill="currentColor" d="M12 21.3S2 15.2 2 8.6C2 5.5 4.4 3 7.4 3c1.9 0 3.6 1 4.6 2.6C13 4 14.7 3 16.6 3 19.6 3 22 5.5 22 8.6c0 6.6-10 12.7-10 12.7z"/>'],
    gift: ['Gift', '<g' + SW + '><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></g>'],
    moon: ['Crescent moon', '<path fill="currentColor" d="M15.5 2.2A10 10 0 1 0 21.8 16 8.2 8.2 0 0 1 15.5 2.2z"/>'],
    star8: ['Eight-point star', svgPoly(8, 11.5, 8.2)],
    lantern: ['Lantern', '<path fill="currentColor" fill-rule="evenodd" d="M10.5 1h3v2.2h-3zM8 3.6h8l1.6 3H6.4zM6.5 7.4h11l-.9 10.8H7.4zM9.6 9.6v6.4h4.8V9.6zM8 19h8l-1.4 3.4H9.4z"/><path fill="currentColor" opacity=".45" d="M9.6 9.6h4.8V16H9.6z"/>'],
    mosque: ['Dome', '<path fill="currentColor" fill-rule="evenodd" d="M12 2.5c.6 1.9 5.5 3.6 5.5 8V11h-11v-.5c0-4.4 4.9-6.1 5.5-8zM4.5 12h15v9.5h-15zM10.2 15.2a1.8 1.8 0 0 1 3.6 0v6.3h-3.6zM1.8 8.5h2v13h-2zM20.2 8.5h2v13h-2z"/>'],
    uae: ['UAE flag', '<rect x="0" y="4" width="7" height="16" fill="#EF3340"/><rect x="7" y="4" width="17" height="5.34" fill="#00843D"/><rect x="7" y="9.33" width="17" height="5.34" fill="#FFFFFF"/><rect x="7" y="14.66" width="17" height="5.34" fill="#000000"/><rect x=".25" y="4.25" width="23.5" height="15.5" fill="none" stroke="#00000022" stroke-width=".5"/>'],
    sun: ['Sun', '<circle cx="12" cy="12" r="5" fill="currentColor"/><g' + SW + '><path d="M12 1.5v2.5M12 20v2.5M1.5 12H4M20 12h2.5M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></g>'],
    snow: ['Snowflake', '<g' + SW + '><path d="M12 2v20M3.3 7l17.4 10M3.3 17L20.7 7M9 3.8l3 2.2 3-2.2M9 20.2l3-2.2 3 2.2M3.6 10.2l3.1-1.5-.4-3.6M20.4 13.8l-3.1 1.5.4 3.6M3.6 13.8l3.1 1.5-.4 3.6M20.4 10.2l-3.1-1.5.4-3.6"/></g>'],
    drop: ['Drop', '<path fill="currentColor" d="M12 2c-3.9 5.4-7 9.1-7 12.8a7 7 0 0 0 14 0C19 11.1 15.9 7.4 12 2z"/><path fill="#FFFFFF" opacity=".45" d="M8.6 14.2a3.6 3.6 0 0 0 2.4 3.9c.5.2.3.9-.2.8a4.6 4.6 0 0 1-3.2-4.7c0-.5.9-.6 1 0z"/>'],
    leaf: ['Leaf', '<path fill="currentColor" d="M20.5 3C9.5 3 3.4 8.6 4.1 17.7l-1.6 1.6 1.2 1.2 1.6-1.6C14.4 19.6 20.5 13.5 20.5 3zM7.4 16.6c2.7-3.7 5.6-6.3 9.4-8.4-3.2 2.6-5.6 5.3-7.8 9z"/>'],
    flower: ['Flower', (function () { var s = ''; for (var i = 0; i < 6; i++) { var a = i * Math.PI / 3; s += '<ellipse cx="' + (12 + Math.sin(a) * 5.4).toFixed(2) + '" cy="' + (12 - Math.cos(a) * 5.4).toFixed(2) + '" rx="3.6" ry="5.2" transform="rotate(' + (i * 60) + ' ' + (12 + Math.sin(a) * 5.4).toFixed(2) + ' ' + (12 - Math.cos(a) * 5.4).toFixed(2) + ')" fill="currentColor" opacity=".85"/>'; } return s + '<circle cx="12" cy="12" r="3.2" fill="#FFFFFF" opacity=".8"/>'; })()],
    lotus: ['Lotus', '<g transform="scale(.0308)"><path fill="currentColor" d="M154 103Q399 122 439 424Q194 343 154 103ZM521 2Q407 136 471 389Q640 185 521 2ZM548 387Q642 239 778 249Q738 393 548 387ZM2 481Q190 313 434 475Q189 595 2 481ZM534 456Q707 461 737 544Q613 589 534 456Z"/></g>'],
    diya: ['Diya lamp', '<path fill="currentColor" d="M2.5 13.5h19c-.6 4.4-4.7 7.5-9.5 7.5s-8.9-3.1-9.5-7.5z"/><path fill="currentColor" opacity=".8" d="M12 2.5c2.3 3 3.3 4.8 3.3 6.4a3.3 3.3 0 0 1-6.6 0c0-1.6 1-3.4 3.3-6.4z"/>'],
    tree: ['Tree', '<path fill="currentColor" d="M12 1.5l5 6.5h-2.8l4.8 6h-3.4l4.4 5.6H4l4.4-5.6H5l4.8-6H7z"/><rect x="10.6" y="19.6" width="2.8" height="3" fill="currentColor"/>'],
    truck: ['Delivery truck', '<g' + SW + '><path d="M1.5 5.5h12.5v10.5H1.5zM14 9h4.3l3.2 3.4V16H14z"/><circle cx="6" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></g>'],
    tag: ['Price tag', '<g' + SW + '><path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.6"/></g>'],
    percent: ['Percent', '<g' + SW + '><path d="M19 5L5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></g>'],
    bag: ['Shopping bag', '<g' + SW + '><path d="M5 7h14l-1 14H6zM9 10V6a3 3 0 0 1 6 0v4"/></g>'],
    crown: ['Crown', '<path fill="currentColor" d="M2 7l5 4.2L12 4l5 7.2L22 7l-2 12H4z"/>'],
    bolt: ['Lightning', '<path fill="currentColor" d="M13.5 1.5L4 14h7l-1.5 8.5L20 9.5h-7z"/>'],
    clock: ['Clock', '<g' + SW + '><circle cx="12" cy="12" r="9.5"/><path d="M12 6.5V12l3.8 2.3"/></g>'],
    check: ['Check', '<g' + SW + ' stroke-width="2.6"><path d="M4 12.5l5 5L20 6.5"/></g>'],
    card: ['Card', '<g' + SW + '><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20M6 15h4"/></g>'],
    coins: ['Coins', '<g' + SW + '><ellipse cx="9" cy="7" rx="6" ry="2.6"/><path d="M3 7v4c0 1.4 2.7 2.6 6 2.6s6-1.2 6-2.6V7M9 13.6c0 1.4 2.7 2.6 6 2.6s6-1.2 6-2.6v-4c0-1.2-2-2.2-4.6-2.5M9 13.6v4c0 1.4 2.7 2.6 6 2.6s6-1.2 6-2.6"/></g>'],
    people: ['People', '<g' + SW + '><circle cx="8.5" cy="8" r="3.5"/><path d="M2 20c.6-3.6 3.2-5.5 6.5-5.5S14.4 16.4 15 20"/><circle cx="17" cy="9" r="2.8"/><path d="M16.5 14.5c2.8 0 4.8 1.8 5.5 5"/></g>'],
    quote: ['Quote marks', '<path fill="currentColor" d="M3 21v-7.4C3 8.4 5.6 4.6 10.2 3l1 2.2C8.4 6.6 7.2 8.6 7.1 11.2H11V21zm11 0v-7.4c0-5.2 2.6-9 7.2-10.6l1 2.2c-2.8 1.4-4 3.4-4.1 6H22V21z"/>'],
    stars5: ['Five stars (decoration)', (function () { var s = ''; for (var i = 0; i < 5; i++) s += '<path transform="translate(' + (i * 24.6) + ' 0)" fill="currentColor" d="M12 1.5l3.1 6.6 7.2.9-5.3 5 1.4 7.1L12 17.6 5.6 21.1 7 14l-5.3-5 7.2-.9z"/>'; return s; })(), '0 0 122 24'],
    percent2: ['Discount', '<circle cx="12" cy="12" r="11" fill="currentColor"/><g fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round"><path d="M16 8l-8 8"/><circle cx="8.6" cy="8.6" r="1.6"/><circle cx="15.4" cy="15.4" r="1.6"/></g>']
  };
  function iconSvg(name) { var ic = Object.prototype.hasOwnProperty.call(ICONS, name) ? ICONS[name] : ICONS.sparkle; return '<svg viewBox="' + (ic[2] || '0 0 24 24') + '" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" aria-hidden="true">' + ic[1] + '</svg>'; }

  // ── validation ──
  function has(o, k) { return typeof k === 'string' && Object.prototype.hasOwnProperty.call(o, k); }   // allowlists: own keys only (never __proto__)
  function num(v, lo, hi, d) { v = +v; return isFinite(v) ? Math.max(lo, Math.min(hi, v)) : d; }
  function str(v, n) { return typeof v === 'string' ? v.slice(0, n) : ''; }
  function col(v, d) { return v === 'transparent' || HEX.test(v) ? v : d; }
  var GEO = [['x', -150, 250, 0], ['y', -150, 400, 0], ['w', .3, 400, 20], ['h', 0, 400, 0], ['r', -360, 360, 0], ['k', .2, 5, 1]];
  function cleanGeo(g) { var o = {}; g = g && typeof g === 'object' ? g : {}; GEO.forEach(function (q) { o[q[0]] = num(g[q[0]], q[1], q[2], q[3]); }); if (g.hd === true) o.hd = true; if (g.al === 'l' || g.al === 'c' || g.al === 'r') o.al = g.al; return o; }
  var NUMP = [['fs', .5, 60], ['ls', -20, 80], ['lh', 60, 250], ['gang', 0, 360], ['bw', 0, 30], ['rad', 0, 50], ['op', 0, 100], ['shd', 0, 100], ['tsh', 0, 100], ['pd', 0, 10]];
  function cleanEl(e, imgs) {
    if (!e || typeof e !== 'object' || !has(TYPES, e.t) || !ID.test(e.id)) return null;
    var o = { id: e.id, t: e.t };
    if (typeof e.name === 'string') o.name = e.name.slice(0, 40);
    if (typeof e.txt === 'string') o.txt = e.txt.slice(0, 200);
    if (has(FONTS, e.fn)) o.fn = e.fn;
    if ([400, 500, 600, 700, 800, 900].indexOf(+e.fw) >= 0) o.fw = +e.fw;
    ['fi', 'up', 'hide', 'lock', 'nw'].forEach(function (b) { if (e[b] === true) o[b] = true; });
    NUMP.forEach(function (q) { if (e[q[0]] != null) { var n = num(e[q[0]], q[1], q[2]); if (n !== undefined) o[q[0]] = n; } });
    ['col', 'fill', 'fill2', 'bc'].forEach(function (c) { var v = col(e[c]); if (v) o[c] = v; });
    if (e.al === 'l' || e.al === 'c' || e.al === 'r') o.al = e.al;
    if (has(SHAPES, e.sh)) o.sh = e.sh;
    if (e.bs === 'solid' || e.bs === 'dashed' || e.bs === 'dotted') o.bs = e.bs;
    if (has(ICONS, e.ic)) o.ic = e.ic;
    if (e.pay === 'both' || e.pay === 'tabby' || e.pay === 'tamara') o.pay = e.pay;
    if (e.cr === 'acc' || e.cr === 'acc2' || e.cr === 'card') o.cr = e.cr;
    if (e.tr === 'acc') o.tr = e.tr;
    if (e.t === 'img') { if (!IMGID.test(e.img) || (imgs && !has(imgs, e.img))) return null; o.img = e.img; }
    return o;
  }
  function prodOk(p) { return has(D.prods, p) || (/^u_[A-Za-z0-9][A-Za-z0-9_-]{5,63}$/.test(p) && has(images, p.slice(2))); }
  function cleanItem(it) {
    if (!it || !prodOk(it.p)) return null;
    var o = { p: it.p, x: num(it.x, -100, 200, 40), y: num(it.y, -100, 300, 40), w: num(it.w, 1, 150, 12), r: num(it.r, -360, 360, 0), sh: num(it.sh, 0, 100, 55) };
    o.ss = it.ss === 'sharp' || it.ss === 'glow' ? it.ss : 'soft'; return o;
  }
  function cleanDoc(raw, imgs) {
    if (!raw || typeof raw !== 'object' || !Array.isArray(raw.els)) return null;
    var d = { v: 1, id: IMGID.test(raw.id) ? raw.id : uid('d'), name: str(raw.name, 60) || 'Untitled design', tpl: typeof raw.tpl === 'string' && /^[a-z0-9-]{1,40}$/.test(raw.tpl) ? raw.tpl : '', els: [], geo: {}, sets: {} };
    var seen = {};
    raw.els.slice(0, 120).forEach(function (e) { var c = cleanEl(e, imgs); if (c && !seen[c.id]) { seen[c.id] = 1; d.els.push(c); } });
    if (!d.els.some(function (e) { return e.t === 'prods'; })) d.els.push({ id: 'prods', t: 'prods', name: 'Products' });
    d.els = d.els.filter(function (e, i, a) { return e.t !== 'prods' || a.findIndex(function (x) { return x.t === 'prods'; }) === i; });
    FMTS.forEach(function (f) {
      var gs = raw.geo && raw.geo[f] || {}; d.geo[f] = {};
      d.els.forEach(function (e) { d.geo[f][e.id] = cleanGeo(gs[e.id]); });
      var ss = raw.sets && Array.isArray(raw.sets[f]) ? raw.sets[f] : [];
      d.sets[f] = [0, 1, 2].map(function (i) { return (Array.isArray(ss[i]) ? ss[i] : []).slice(0, 16).map(cleanItem).filter(Boolean); });
    });
    var b = raw.bg || {};
    d.bg = { k: ['solid', 'linear', 'radial', 'image'].indexOf(b.k) >= 0 ? b.k : 'radial', c1: col(b.c1, '#FFFFFF'), c2: col(b.c2, '#EEEEEE'), ang: num(b.ang, 0, 360, 160), pat: has(PATS, b.pat) ? b.pat : 'none', pc: col(b.pc, '#000000'), po: num(b.po, 0, 100, 12), img: IMGID.test(b.img) && (!imgs || has(imgs, b.img)) ? b.img : '', ov: num(b.ov, 0, 90, 0) };
    if (d.bg.k === 'image' && !d.bg.img) d.bg.k = 'radial';
    var a = raw.anim || {};
    d.anim = { in: ['fade', 'pop', 'up', 'none'].indexOf(a.in) >= 0 ? a.in : 'fade', idle: ['none', 'float', 'sway'].indexOf(a.idle) >= 0 ? a.idle : 'none', sec: [1.5, 2, 2.5, 3, 4, 5, 6].indexOf(+a.sec) >= 0 ? +a.sec : 3, len: [5, 10, 15, 20, 30].indexOf(+a.len) >= 0 ? +a.len : 10 };
    return d;
  }
  function cleanBrand(b) { b = b || {}; return { c1: HEX.test(b.c1) ? b.c1.slice(0, 7) : '#E8306F', c2: HEX.test(b.c2) ? b.c2.slice(0, 7) : '#1F1B1E', logo: IMGID.test(b.logo) ? b.logo : '', on: b.on === true }; }

  // ── state ──
  var images = Object.create(null);   // id -> {name, aspect, kind: prod|bg|logo|img, src} (no prototype: ids come from storage)
  var brand = cleanBrand({});
  var doc = null, st = { fmt: 'post', set: 0, sel: null, psel: null, tab: 'tpl', cat: 'all', q: '' };
  var lastSaved = '', hist = [], fut = [], pending = null;

  // ── storage adapter ──
  var Store = (function () {
    var cdb = null, cas = null, cdl = null, idb = null, mem = { projects: {}, images: {}, kv: {} }, kind = 'memory';
    function withTimeout(p, ms) { return Promise.race([p, new Promise(function (r) { setTimeout(function () { r(null); }, ms); })]); }
    function useCap(n) { try { return window.claude && typeof window.claude.use === 'function' ? Promise.resolve(window.claude.use(n)).catch(function () { return null; }) : Promise.resolve(null); } catch (e) { return Promise.resolve(null); } }
    function openIdb() {
      return new Promise(function (res) {
        try {
          if (!window.indexedDB) return res(null);
          var rq = indexedDB.open('kbb-ad-studio', 1);
          rq.onupgradeneeded = function () { var db = rq.result; ['projects', 'images', 'kv'].forEach(function (s) { if (!db.objectStoreNames.contains(s)) db.createObjectStore(s, { keyPath: 'id' }); }); };
          rq.onsuccess = function () { res(rq.result); }; rq.onerror = function () { res(null); }; rq.onblocked = function () { res(null); };
        } catch (e) { res(null); }
      });
    }
    function tx(store, mode, fn) {
      return new Promise(function (res, rej) {
        var t = idb.transaction(store, mode), s = t.objectStore(store), out; var r = fn(s);
        if (r) r.onsuccess = function () { out = r.result; };
        t.oncomplete = function () { res(out); }; t.onerror = function () { rej(t.error); }; t.onabort = function () { rej(t.error || new Error('Storage refused the write')); };
      });
    }
    function blobToData(b) { return new Promise(function (res, rej) { var fr = new FileReader(); fr.onload = function () { res(fr.result); }; fr.onerror = rej; fr.readAsDataURL(b); }); }
    function lsGet(k) { try { return JSON.parse(localStorage.getItem('kbbas:' + k)); } catch (e) { return null; } }
    function lsSet(k, v) { try { localStorage.setItem('kbbas:' + k, JSON.stringify(v)); return true; } catch (e) { return false; } }
    var api = {
      kind: function () { return kind; },
      init: function () {
        return withTimeout(useCap('db'), window.claude ? 10000 : 1).then(function (d) {
          if (d) { cdb = d; kind = 'claude'; }
          return Promise.all([withTimeout(useCap('assets'), window.claude ? 10000 : 1), withTimeout(useCap('downloads'), window.claude ? 10000 : 1), openIdb()]);
        }).then(function (r) {
          cas = r[0]; cdl = r[1]; idb = r[2];
          if (!cdb) { if (idb) kind = 'browser'; else { try { localStorage.setItem('kbbas:t', '1'); kind = 'local'; } catch (e) { kind = 'memory'; } } }
          return kind;
        });
      },
      listProjects: function () {
        if (cdb) return cdb.collection('projects').get().then(function (q) { return q.docs.map(function (s) { var v = s.data() || {}; return { id: s.id, name: str(v.name, 60), updated: +v.updated || 0 }; }); });
        if (idb) return tx('projects', 'readonly', function (s) { return s.getAll(); }).then(function (a) { return (a || []).map(function (p) { return { id: p.id, name: str(p.name, 60), updated: +p.updated || 0 }; }); });
        var src = kind === 'local' ? (lsGet('projects') || {}) : mem.projects;
        return Promise.resolve(Object.keys(src).map(function (k) { return { id: k, name: str(src[k].name, 60), updated: +src[k].updated || 0 }; }));
      },
      getProject: function (id) {
        if (cdb) return cdb.doc('projects/' + id).get().then(function (s) { if (!s.exists) return null; try { return JSON.parse(s.data().json); } catch (e) { return null; } });
        if (idb) return tx('projects', 'readonly', function (s) { return s.get(id); }).then(function (p) { return p ? p.doc : null; });
        var src = kind === 'local' ? (lsGet('projects') || {}) : mem.projects; return Promise.resolve(src[id] ? src[id].doc : null);
      },
      putProject: function (d) {
        var rec = { id: d.id, name: d.name, updated: Date.now() };
        if (cdb) return cdb.doc('projects/' + d.id).set({ name: d.name, updated: rec.updated, json: JSON.stringify(d) });
        if (idb) return tx('projects', 'readwrite', function (s) { return s.put(Object.assign(rec, { doc: d })); });
        if (kind === 'local') { var all = lsGet('projects') || {}; all[d.id] = Object.assign(rec, { doc: d }); if (!lsSet('projects', all)) return Promise.reject(new Error('This browser is out of space')); return Promise.resolve(); }
        mem.projects[d.id] = Object.assign(rec, { doc: d }); return Promise.resolve();
      },
      delProject: function (id) {
        if (cdb) return cdb.doc('projects/' + id).delete();
        if (idb) return tx('projects', 'readwrite', function (s) { return s.delete(id); });
        if (kind === 'local') { var all = lsGet('projects') || {}; delete all[id]; lsSet('projects', all); } else delete mem.projects[id];
        return Promise.resolve();
      },
      listImages: function () {
        var metas;
        if (cdb && cas) return cdb.collection('images').get().then(function (q) { return q.docs.filter(function (s) { return IMGID.test(s.id); }).map(function (s) { var v = s.data() || {}; return { id: s.id, name: str(v.name, 60), aspect: num(v.aspect, .05, 20, 1), kind: v.kind, src: '/_blob/' + s.id }; }); });
        if (idb) return tx('images', 'readonly', function (s) { return s.getAll(); }).then(function (a) {
          return Promise.all((a || []).map(function (r) { return (r.blob instanceof Blob ? blobToData(r.blob) : Promise.resolve(r.data)).then(function (src) { return { id: r.id, name: str(r.name, 60), aspect: num(r.aspect, .05, 20, 1), kind: r.kind, src: src }; }); }));
        });
        metas = kind === 'local' ? (lsGet('images') || {}) : mem.images;
        return Promise.resolve(Object.keys(metas).map(function (k) { var r = metas[k]; return { id: k, name: str(r.name, 60), aspect: num(r.aspect, .05, 20, 1), kind: r.kind, src: r.data }; }));
      },
      putImage: function (blob, meta) {
        if (cdb && cas) return cas.upload(blob).then(function (r) { return cdb.doc('images/' + r.id).set({ name: meta.name, aspect: meta.aspect, kind: meta.kind, added: Date.now() }).then(function () { return { id: r.id, src: '/_blob/' + r.id }; }); });
        var id = uid('i');
        return blobToData(blob).then(function (data) {
          if (idb) return tx('images', 'readwrite', function (s) { return s.put({ id: id, name: meta.name, aspect: meta.aspect, kind: meta.kind, blob: blob }); }).then(function () { return { id: id, src: data }; });
          if (kind === 'local') { var all = lsGet('images') || {}; all[id] = { name: meta.name, aspect: meta.aspect, kind: meta.kind, data: data }; if (!lsSet('images', all)) return Promise.reject(new Error('This browser is out of space')); return { id: id, src: data }; }
          mem.images[id] = { name: meta.name, aspect: meta.aspect, kind: meta.kind, data: data }; return { id: id, src: data };
        });
      },
      delImage: function (id) {
        if (cdb && cas) return cdb.doc('images/' + id).delete().then(function () { return cas.delete(id); });
        if (idb) return tx('images', 'readwrite', function (s) { return s.delete(id); });
        if (kind === 'local') { var all = lsGet('images') || {}; delete all[id]; lsSet('images', all); } else delete mem.images[id];
        return Promise.resolve();
      },
      getKV: function (k) {
        if (cdb) return cdb.doc('kv/' + k).get().then(function (s) { if (!s.exists) return null; try { return JSON.parse(s.data().json); } catch (e) { return null; } });
        if (idb) return tx('kv', 'readonly', function (s) { return s.get(k); }).then(function (r) { return r ? r.v : null; });
        return Promise.resolve(kind === 'local' ? lsGet('kv:' + k) : mem.kv[k] || null);
      },
      putKV: function (k, v) {
        if (cdb) return cdb.doc('kv/' + k).set({ json: JSON.stringify(v) });
        if (idb) return tx('kv', 'readwrite', function (s) { return s.put({ id: k, v: v }); });
        if (kind === 'local') lsSet('kv:' + k, v); else mem.kv[k] = v; return Promise.resolve();
      },
      // a draft of the design on screen: this browser only, survives a reload before Save
      draft: function (d) { try { localStorage.setItem('kbbas:draft', JSON.stringify(d)); } catch (e) { if (idb) tx('kv', 'readwrite', function (s) { return s.put({ id: 'draft', v: d }); }).catch(function () {}); } },
      readDraft: function () {
        var d = null; try { d = JSON.parse(localStorage.getItem('kbbas:draft')); } catch (e) {}
        if (d || !idb) return Promise.resolve(d);
        return tx('kv', 'readonly', function (s) { return s.get('draft'); }).then(function (r) { return r ? r.v : null; }).catch(function () { return null; });
      },
      save: function (name, blob) {
        if (cdl) return cdl.save({ filename: name, data: blob });
        var a = document.createElement('a'), u = URL.createObjectURL(blob); a.href = u; a.download = name; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(u); }, 5000); return Promise.resolve();
      },
      toData: function (src) { if (/^data:/.test(src)) return Promise.resolve(src); return fetch(src).then(function (r) { return r.blob(); }).then(blobToData); }
    };
    return api;
  })();

  // ── templates -> designs ──
  var tplById = {}; KT.LIST.forEach(function (t) { tplById[t.id] = t; });
  function aspectOf(p) { return D.prods[p] ? D.prods[p].a : (images[p.slice(2)] ? images[p.slice(2)].aspect : .5); }
  function itemFromSlot(s, p) { var a = aspectOf(p), h = s.h, w = h * a; if (w > s.mw) { w = s.mw; h = w / a; } return { p: p, x: r2(s.cx - w / 2), y: r2(s.by - h), w: r2(w), r: s.r || 0, sh: 55, ss: 'soft' }; }
  function brandPal(P) {
    if (!brand.on) return P;
    var Q = Object.assign({}, P); Q.acc = brand.c1; Q.acc2 = brand.c2; Q.rib = brand.c1; Q.badge = brand.c1; Q.btnTxt = contrast(brand.c1); Q.badgeTxt = contrast(brand.c1); Q.bandTxt = contrast(brand.c1); Q.ribTxt = contrast(brand.c1);
    Q.disc = brand.c1 + '2E'; return Q;
  }
  function contrast(hex) { var n = parseInt(hex.slice(1, 7), 16), r = n >> 16 & 255, g = n >> 8 & 255, b = n & 255; return (r * 299 + g * 587 + b * 114) / 1000 > 160 ? '#111111' : '#FFFFFF'; }
  function fromTemplate(t) {
    var P = brandPal(t.P), d = { v: 1, id: uid('d'), name: t.name, tpl: t.id, els: [], geo: {}, sets: {}, anim: { in: 'fade', idle: 'none', sec: 3, len: 10 } };
    FMTS.forEach(function (f, fi) {
      var r = KT.FAM[t.fam](f, t.T, P, t.O);
      if (fi === 0) { d.bg = r.bg; d.els = r.els.map(function (e) { var c = Object.assign({}, e); delete c.g; return c; }); }
      d.geo[f] = {};
      r.els.forEach(function (e, i) {
        var g = Object.assign({}, e.g), p = d.els[i];       // a family may size or align one element differently per size
        if (p && p.id === e.id) { if (e.fs && p.fs && e.fs !== p.fs) g.k = (g.k || 1) * e.fs / p.fs; if ((e.al || 'c') !== (p.al || 'c')) g.al = e.al || 'c'; }
        d.geo[f][e.id] = g;
      });
      var n = r.slots.length;
      d.sets[f] = [0, 1, 2].map(function (s) { return r.slots.map(function (sl, j) { return itemFromSlot(sl, t.pp[(s * n + j) % t.pp.length]); }); });
    });
    return cleanDoc(d, images);
  }

  // ── drawing (one function for the stage, the thumbnails, the downloads and the video layers) ──
  function fontStack(k) { return '"' + (FONTS[k] || 'Poppins') + '",Cairo,system-ui,sans-serif'; }
  function fillCss(e) { if (!e.fill || e.fill === 'transparent') return 'transparent'; return e.fill2 ? 'linear-gradient(' + (e.gang == null ? 180 : e.gang) + 'deg,' + e.fill + ',' + e.fill2 + ')' : e.fill; }
  function patCss(p, c) {
    switch (p) {
      case 'dots': return ['radial-gradient(' + c + ' 14%,transparent 16%)', '4cqw 4cqw'];
      case 'grid': return ['linear-gradient(90deg,' + c + ' .14cqw,transparent .14cqw),linear-gradient(' + c + ' .14cqw,transparent .14cqw)', '6cqw 6cqw'];
      case 'stripes': return ['repeating-linear-gradient(45deg,' + c + ' 0 1.1cqw,transparent 1.1cqw 3.4cqw)', 'auto'];
      case 'rays': return ['repeating-conic-gradient(from 0deg at 50% 45%,' + c + ' 0 5deg,transparent 5deg 15deg)', 'auto'];
      case 'lattice': return ['repeating-linear-gradient(45deg,' + c + ' 0 .18cqw,transparent .18cqw 5cqw),repeating-linear-gradient(-45deg,' + c + ' 0 .18cqw,transparent .18cqw 5cqw)', 'auto'];
      case 'waves': return ['radial-gradient(circle at 50% 100%,transparent 36%,' + c + ' 38% 44%,transparent 46%)', '8cqw 4cqw'];
      case 'sparkle': return ['radial-gradient(circle at 12% 18%,' + c + ' .35cqw,transparent .5cqw),radial-gradient(circle at 78% 9%,' + c + ' .5cqw,transparent .7cqw),radial-gradient(circle at 88% 62%,' + c + ' .3cqw,transparent .45cqw),radial-gradient(circle at 30% 76%,' + c + ' .45cqw,transparent .6cqw),radial-gradient(circle at 55% 40%,' + c + ' .25cqw,transparent .4cqw),radial-gradient(circle at 6% 52%,' + c + ' .3cqw,transparent .45cqw),radial-gradient(circle at 64% 90%,' + c + ' .35cqw,transparent .5cqw)', '100% 60cqw'];
    }
    return null;
  }
  function bgPaint(layer, bg) {
    var s = layer.style;
    if (bg.k === 'image' && images[bg.img]) { s.backgroundColor = bg.c1; s.backgroundImage = (bg.ov ? 'linear-gradient(rgba(0,0,0,' + bg.ov / 100 + '),rgba(0,0,0,' + bg.ov / 100 + ')),' : '') + 'url("' + images[bg.img].src + '")'; s.backgroundSize = 'cover'; }
    else if (bg.k === 'solid') s.background = bg.c1;
    else if (bg.k === 'linear') s.background = 'linear-gradient(' + bg.ang + 'deg,' + bg.c1 + ',' + bg.c2 + ')';
    else s.background = 'radial-gradient(120% 90% at 50% 38%,' + bg.c1 + ',' + bg.c2 + ')';
  }
  function shadowCss(a) { a = a / 100; return a ? 'drop-shadow(0 ' + (1.2 * a).toFixed(2) + 'cqw ' + (1.8 * a).toFixed(2) + 'cqw rgba(0,0,0,' + (.38 * a).toFixed(2) + '))' : ''; }
  function prodShadow(it, accent) {
    var a = (it.sh == null ? 55 : it.sh) / 100; if (!a) return 'none';
    if (it.ss === 'sharp') return 'drop-shadow(0 ' + (.5 * a).toFixed(2) + 'cqw ' + (.25 * a).toFixed(2) + 'cqw rgba(0,0,0,' + (.55 * a).toFixed(2) + '))';
    if (it.ss === 'glow') return 'drop-shadow(0 0 ' + (2.2 * a).toFixed(2) + 'cqw ' + accent + ')';
    return 'drop-shadow(0 ' + (1.4 * a).toFixed(2) + 'cqw ' + (1.6 * a).toFixed(2) + 'cqw rgba(0,0,0,' + (.42 * a).toFixed(2) + ')) drop-shadow(0 ' + (.2 * a).toFixed(2) + 'cqw ' + (.3 * a).toFixed(2) + 'cqw rgba(0,0,0,' + (.22 * a).toFixed(2) + '))';
  }
  function accentOf(d) { var b = d.els.filter(function (e) { return e.t === 'btn'; })[0]; return (b && b.fill && b.fill !== 'transparent' ? b.fill.slice(0, 7) : '#E8306F') + 'B3'; }
  function placeProd(n, it, accent) {
    var a = aspectOf(it.p);
    n.style.left = it.x + 'cqw'; n.style.top = it.y + 'cqw'; n.style.width = it.w + 'cqw'; n.style.height = (it.w / a) + 'cqw';
    n.style.transform = it.r ? 'rotate(' + it.r + 'deg)' : ''; n.style.filter = prodShadow(it, accent);
  }
  function prodClass(p) { return 'p-' + (D.prods[p] ? p : 'u-' + p.slice(2)); }
  function isAr(s) { return /[؀-ۿ]/.test(s || ''); }
  function placeEl(n, e, g) {
    var s = n.style; s.left = g.x + 'cqw'; s.top = g.y + 'cqw'; s.width = g.w + 'cqw'; s.height = g.h ? g.h + 'cqw' : '';
    s.transform = g.r ? 'rotate(' + g.r + 'deg)' : '';
    if (e.t === 'logo' || e.t === 'text' || e.t === 'btn' || e.t === 'shape') s.fontSize = ((e.fs || 4) * (g.k || 1)).toFixed(3) + 'cqw';
    if (e.t === 'shape' && e.sh === 'arch') { var sf = n.querySelector('.sf'); if (sf) sf.style.borderRadius = (g.w / 2) + 'cqw ' + (g.w / 2) + 'cqw ' + (e.rad || 0) + 'cqw ' + (e.rad || 0) + 'cqw'; }
  }
  function drawEl(e, g, mode) {
    var n = mk('div', 'el'), hasTxt = (e.t === 'text' || e.t === 'btn' || e.t === 'shape') && e.txt;
    n.dataset.id = e.id;
    var box = !!g.h && e.t !== 'logo';
    if (box) { n.classList.add('box'); if (e.t === 'btn' && e.txt && e.txt.indexOf('\n') < 0) n.classList.add('one'); } else if (e.nw) n.classList.add('nw');
    // shape (on its own layer, so a clip-path never clips the text or the shadow)
    var chip = e.t === 'text' && !g.h && e.sh && e.sh !== 'none';
    if (e.sh && e.sh !== 'none' && !chip && (e.t === 'shape' || e.t === 'btn' || e.t === 'text')) {
      var sf = mk('div', 'sf'), k = e.sh;
      sf.style.background = fillCss(e);
      if (k === 'circle' || k === 'ellipse') sf.style.borderRadius = '50%';
      else if (k === 'ring') { sf.style.borderRadius = '50%'; sf.style.background = 'transparent'; sf.style.border = ((e.bw || 3) / 10) + 'cqw ' + (e.bs || 'solid') + ' ' + (e.bc || e.fill || '#FFFFFF'); }
      else if (k === 'pill') sf.style.borderRadius = '999px';
      else if (k === 'rect') sf.style.borderRadius = (e.rad || 0) + 'cqw';
      else if (CLIP[k]) sf.style.clipPath = CLIP[k];
      if (e.bw && BORDERED[k] && k !== 'ring') sf.style.border = (e.bw / 10) + 'cqw ' + (e.bs || 'solid') + ' ' + (e.bc || '#000000');
      n.appendChild(sf);
    }
    if (hasTxt || e.t === 'logo') {
      n.style.fontFamily = fontStack(e.fn); n.style.fontWeight = e.fw || 700; if (e.fi) n.style.fontStyle = 'italic';
      n.style.color = e.col || '#111111'; n.style.lineHeight = ((e.lh || 115) / 100);
      if (e.ls) n.style.letterSpacing = (e.ls / 100) + 'em';
      if (e.up) n.style.textTransform = 'uppercase';
      if (e.tsh) n.style.textShadow = '0 .25em .7em rgba(0,0,0,' + (e.tsh / 100 * .6).toFixed(2) + ')';
    }
    if (hasTxt) {
      var tc = mk('div', 'tc'), t = mk('span', 'tx', e.txt), al = g.al || e.al || 'c';
      tc.style.justifyContent = al === 'l' ? 'flex-start' : al === 'r' ? 'flex-end' : 'center'; tc.style.textAlign = al === 'l' ? 'left' : al === 'r' ? 'right' : 'center';
      if (isAr(e.txt)) { t.dir = 'rtl'; t.lang = 'ar'; } else t.dir = 'auto';
      var pd = (e.pd == null ? (box ? 1 : 0) : e.pd) * (g.k || 1);
      if (box) tc.style.padding = pd.toFixed(2) + 'cqw ' + (pd * 1.4).toFixed(2) + 'cqw';
      if (chip) { t.style.background = fillCss(e); t.style.padding = pd.toFixed(2) + 'cqw ' + (pd * 2.4).toFixed(2) + 'cqw'; t.style.borderRadius = e.sh === 'pill' ? '999px' : (e.rad || 0) + 'cqw'; if (e.bw) t.style.border = (e.bw / 10) + 'cqw ' + (e.bs || 'solid') + ' ' + (e.bc || '#000000'); }
      tc.appendChild(t); n.appendChild(tc);
    }
    if (e.t === 'icon') { n.innerHTML = iconSvg(e.ic); n.style.color = e.col || '#111111'; }            // constant markup only
    if (e.t === 'img') { var im = mk('img', 'ii'); im.alt = ''; im.draggable = false; if (images[e.img]) im.src = images[e.img].src; n.appendChild(im); }
    if (e.t === 'pay') {
      var py = mk('div', 'py'); py.style.gap = (g.h * .35) + 'cqw';
      if (e.pay !== 'tamara') { var a1 = mk('img'); a1.src = D.pay.tabby; a1.alt = 'tabby'; py.appendChild(a1); }
      if (e.pay !== 'tabby') { var a2 = mk('img'); a2.src = D.pay.tamara; a2.alt = 'tamara'; py.appendChild(a2); }
      n.appendChild(py);
    }
    if (e.t === 'logo') {
      var lg = mk('div', 'lg'), al2 = g.al || e.al || 'c'; lg.style.justifyContent = al2 === 'l' ? 'flex-start' : al2 === 'r' ? 'flex-end' : 'center';
      if (brand.logo && images[brand.logo]) { var li = mk('img'); li.src = images[brand.logo].src; li.alt = ''; li.style.height = '2.4em'; lg.appendChild(li); }
      else { var sv = mk('span'); sv.innerHTML = iconSvg('lotus'); var svg = sv.firstChild; svg.setAttribute('viewBox', '0 0 24 18'); lg.appendChild(svg); lg.appendChild(mk('span', null, e.txt || 'K-Beauty Bliss')); }
      n.appendChild(lg);
    }
    if (e.op != null && e.op < 100) n.style.opacity = e.op / 100;
    if (e.shd) n.style.filter = shadowCss(e.shd);
    if (e.lock && mode === 'edit') n.classList.add('lk');
    placeEl(n, e, g);
    return n;
  }
  // mode: 'edit' | 'thumb' | 'file' ; part: undefined (all) | 'under' | 'over' (video layers)
  function renderCanvas(d, f, set, mode, part) {
    var cv = mk('div', 'cv'); cv.style.aspectRatio = PX[f][0] + '/' + PX[f][1];
    if (part !== 'over') {
      var bgl = mk('div', 'bgl'); bgPaint(bgl, d.bg); cv.appendChild(bgl);
      var pc = patCss(d.bg.pat, d.bg.pc);
      if (pc && d.bg.po) { var bp = mk('div', 'bgp'); bp.style.backgroundImage = pc[0]; bp.style.backgroundSize = pc[1]; bp.style.opacity = d.bg.po / 100; cv.appendChild(bp); }
    }
    var acc = accentOf(d), after = false;
    d.els.forEach(function (e) {
      var g = d.geo[f][e.id];
      if (e.t === 'prods') {
        after = true; if (part) return;
        var pl = mk('div', 'pl'); pl.dataset.id = e.id;
        if (!e.hide && !g.hd) (d.sets[f][set] || []).forEach(function (it, i) { if (!prodOk(it.p)) return; var n = mk('i', 'bp ' + prodClass(it.p)); n.dataset.i = i; placeProd(n, it, acc); pl.appendChild(n); });
        cv.appendChild(pl); return;
      }
      if (part === 'under' && after) return; if (part === 'over' && !after) return;
      if (e.hide || g.hd) return;
      cv.appendChild(drawEl(e, g, mode));
    });
    return cv;
  }
  // a text in a fixed box (buttons, badges, bands) shrinks until it fits -- the owner's edits never spill out
  function fitText(root) {
    root.querySelectorAll('.el.nw').forEach(function (n) {            // "one line each": shrink until the longest line fits
      var t = n.querySelector('.tx'); if (!t) return; var fs = parseFloat(n.style.fontSize), i = 0, w = n.clientWidth;
      while (i++ < 30 && t.offsetWidth > w + .5) { fs *= Math.max(.8, Math.min(.98, w / t.offsetWidth)); n.style.fontSize = fs.toFixed(3) + 'cqw'; }
    });
    root.querySelectorAll('.el.box').forEach(function (n) {
      var t = n.querySelector('.tx'), tc = n.querySelector('.tc'); if (!t || !tc) return;
      var fs = parseFloat(n.style.fontSize), i = 0;
      while (i++ < 14 && (t.offsetHeight > tc.clientHeight + 1 || t.scrollWidth > t.offsetWidth + 1)) { fs *= .93; n.style.fontSize = fs.toFixed(3) + 'cqw'; }
    });
  }

  // ── the stage ──
  var stage = $('stage'), statusEl = $('status');
  function setStatus(t, cls) { statusEl.textContent = t; statusEl.className = 'st ' + (cls || ''); }
  function selEl() { return st.sel ? doc.els.filter(function (e) { return e.id === st.sel; })[0] || null : null; }
  function curItems() { return doc.sets[st.fmt][st.set]; }
  function draw() {
    stage.style.setProperty('--sw', st.fmt === 'story' ? '360px' : st.fmt === 'square' ? '520px' : '470px');
    var cv = renderCanvas(doc, st.fmt, st.set, 'edit');
    stage.textContent = ''; stage.appendChild(cv); fitText(cv);
    markSel();
    chipsTop(); panelSel(); panelProd(); layers(); panelStyle(); panelMotion(); drawPk(); dirtyMark();
    $('dl-size').textContent = PX[st.fmt][0] + ' × ' + PX[st.fmt][1] + ' px';
  }
  function markSel() {
    stage.querySelectorAll('.sel').forEach(function (n) { n.classList.remove('sel'); n.querySelectorAll('.hd,.hw,.rh').forEach(function (h) { h.remove(); }); });
    var n = null, isText = false;
    if (st.psel != null) n = stage.querySelector('.bp[data-i="' + st.psel + '"]');
    else if (st.sel) { n = stage.querySelector('.el[data-id="' + st.sel + '"]'); var e = selEl(); isText = e && !doc.geo[st.fmt][e.id].h && (e.t === 'text' || e.t === 'logo'); }
    if (!n) return;
    n.classList.add('sel');
    var hd = mk('span', 'hd'); hd.dataset.h = 'c'; n.appendChild(hd);
    if (isText) { var hw = mk('span', 'hw'); hw.dataset.h = 'w'; n.appendChild(hw); }
    var rh = mk('span', 'rh'); rh.dataset.h = 'r'; n.appendChild(rh);
  }
  function chipsTop() {
    var fh = $('fmts'); fh.textContent = '';
    FMTS.forEach(function (f) { var b = mk('button', 'chip', FLAB[f]); b.type = 'button'; b.setAttribute('aria-pressed', String(f === st.fmt)); b.onclick = function () { stopPlay(); st.fmt = f; st.psel = null; draw(); }; fh.appendChild(b); });
    var sh = $('sets'); sh.textContent = '';
    [0, 1, 2].forEach(function (i) { var b = mk('button', 'chip', 'Set ' + (i + 1)); b.type = 'button'; b.setAttribute('aria-pressed', String(i === st.set)); b.onclick = function () { stopPlay(); st.set = i; st.psel = null; draw(); }; sh.appendChild(b); });
  }

  // ── history: one entry per finished change (a whole drag or slider move is one step) ──
  function begin() { if (pending == null) pending = JSON.stringify(doc); }
  function end() {
    if (pending == null) return; var now = JSON.stringify(doc);
    if (now !== pending) { hist.push(pending); if (hist.length > 100) hist.shift(); fut = []; }
    pending = null; undoBtns(); scheduleDraft(); dirtyMark();
  }
  function act(fn) { begin(); fn(); end(); draw(); }
  function undo() { end(); if (!hist.length) return; fut.push(JSON.stringify(doc)); doc = JSON.parse(hist.pop()); afterJump(); }
  function redo() { end(); if (!fut.length) return; hist.push(JSON.stringify(doc)); doc = JSON.parse(fut.pop()); afterJump(); }
  function afterJump() { if (st.sel && !selEl()) st.sel = null; st.psel = null; $('pname').value = doc.name; draw(); undoBtns(); scheduleDraft(); }
  function undoBtns() { $('undo').disabled = !hist.length; $('redo').disabled = !fut.length; }
  var draftT = 0;
  function scheduleDraft() { clearTimeout(draftT); draftT = setTimeout(function () { Store.draft({ doc: doc, t: Date.now(), saved: lastSaved === JSON.stringify(doc) }); }, 500); }
  function dirtyMark() { var dirty = JSON.stringify(doc) !== lastSaved; $('save').textContent = dirty ? 'Save •' : 'Saved'; }

  // ── pointer: select, move, resize, rotate (this is the editor, not a shop page: measuring is fine here) ──
  var drag = null, guides = [];
  function cvRect() { return stage.querySelector('.cv').getBoundingClientRect(); }
  function clearGuides() { guides.forEach(function (g) { g.remove(); }); guides = []; }
  function guide(cls, pos) { var g = mk('div', 'guide ' + cls); if (cls === 'v') g.style.left = pos; else g.style.top = pos; stage.querySelector('.cv').appendChild(g); guides.push(g); }
  stage.addEventListener('pointerdown', function (ev) {
    if (playT) return;
    var t = ev.target, h = t.dataset && t.dataset.h, cvr = cvRect(), px = 100 / cvr.width;
    var bp = t.closest('.bp'), en = t.closest('.el');
    if (h && st.psel != null && bp) { }
    else if (bp) { st.psel = +bp.dataset.i; st.sel = null; }
    else if (en && !h) { st.sel = en.dataset.id; st.psel = null; }
    else if (!h) { st.sel = null; st.psel = null; markSel(); panelSel(); panelProd(); layers(); return; }
    begin();
    if (st.psel != null) {
      var it = curItems()[st.psel], node = stage.querySelector('.bp[data-i="' + st.psel + '"]'), rb = node.getBoundingClientRect();
      drag = { kind: 'p', h: h, it: it, node: node, sx: ev.clientX, sy: ev.clientY, x: it.x, y: it.y, w: it.w, px: px, cx: rb.left + rb.width / 2, cy: rb.top + rb.height / 2 };
      if (!h && st.tab !== 'prod') showTab('prod');
    } else {
      var e = selEl(), g = doc.geo[st.fmt][e.id], n = stage.querySelector('.el[data-id="' + e.id + '"]'), r = n.getBoundingClientRect();
      drag = { kind: 'e', h: h, e: e, g: g, node: n, sx: ev.clientX, sy: ev.clientY, x: g.x, y: g.y, w: g.w, gh: g.h, k: g.k || 1, px: px, cx: r.left + r.width / 2, cy: r.top + r.height / 2, nh: n.offsetHeight * px };
      if (!h && st.tab !== 'edit') showTab('edit');
    }
    markSel(); panelSel(); panelProd(); layers();
    stage.setPointerCapture(ev.pointerId); ev.preventDefault();
  });
  stage.addEventListener('pointermove', function (ev) {
    if (!drag) return;
    var dx = (ev.clientX - drag.sx) * drag.px, dy = (ev.clientY - drag.sy) * drag.px;
    if (drag.h === 'r') {
      var ang = Math.atan2(ev.clientY - drag.cy, ev.clientX - drag.cx) * 180 / Math.PI + 90; ang = ((ang + 540) % 360) - 180;
      if (ev.shiftKey) ang = Math.round(ang / 15) * 15; ang = Math.round(ang);
      if (drag.kind === 'p') { drag.it.r = ang; placeProd(drag.node, drag.it, accentOf(doc)); } else { drag.g.r = ang; placeEl(drag.node, drag.e, drag.g); }
      return;
    }
    if (drag.kind === 'p') {
      var it = drag.it;
      if (drag.h === 'c') { var a = aspectOf(it.p); it.w = r2(Math.max(2, drag.w + Math.max(dx, dy * a))); }
      else { it.x = r2(drag.x + dx); it.y = r2(drag.y + dy); }
      placeProd(drag.node, it, accentOf(doc)); return;
    }
    var g = drag.g, e = drag.e;
    if (drag.h === 'c') {
      if (!drag.gh && (e.t === 'text' || e.t === 'logo')) { var s = Math.max(.15, (drag.w + dx) / drag.w); g.w = r2(drag.w * s); g.k = r2(Math.max(.2, Math.min(5, drag.k * s))); }
      else if (e.t === 'icon' || e.t === 'img' || e.t === 'pay' || ev.shiftKey || e.sh === 'circle') { var s2 = Math.max(.1, (drag.w + Math.max(dx, dy * drag.w / (drag.gh || 1))) / drag.w); g.w = r2(drag.w * s2); g.h = r2(drag.gh * s2); }
      else { g.w = r2(Math.max(1, drag.w + dx)); g.h = r2(Math.max(.5, drag.gh + dy)); }
    } else if (drag.h === 'w') g.w = r2(Math.max(3, drag.w + dx));
    else {
      clearGuides(); var nx = drag.x + dx, ny = drag.y + dy, hh = drag.gh || drag.nh, H = HU(st.fmt);
      if (!ev.altKey) {
        if (Math.abs(nx + drag.w / 2 - 50) < .8) { nx = 50 - drag.w / 2; guide('v', '50%'); }
        if (Math.abs(ny + hh / 2 - H / 2) < .8) { ny = H / 2 - hh / 2; guide('h', '50%'); }
      }
      g.x = r2(nx); g.y = r2(ny);
    }
    placeEl(drag.node, e, g); if (drag.h) fitText(drag.node.parentNode);
  });
  function dragEnd() { if (!drag) return; drag = null; clearGuides(); end(); draw(); }
  stage.addEventListener('pointerup', dragEnd); stage.addEventListener('pointercancel', dragEnd);
  document.addEventListener('keydown', function (ev) {
    var inField = /input|select|textarea/i.test((document.activeElement || {}).tagName || '');
    var mod = ev.ctrlKey || ev.metaKey, k = ev.key.toLowerCase();
    if (mod && k === 's') { ev.preventDefault(); saveNow(); return; }
    if (inField) return;
    if (mod && k === 'z' && !ev.shiftKey) { ev.preventDefault(); undo(); return; }
    if (mod && (k === 'y' || (k === 'z' && ev.shiftKey))) { ev.preventDefault(); redo(); return; }
    if (mod && k === 'd') { ev.preventDefault(); duplicate(); return; }
    if (ev.key === 'Delete' || ev.key === 'Backspace') { if (st.sel || st.psel != null) { ev.preventDefault(); removeSel(); } return; }
    if (ev.key === 'Escape') { st.sel = null; st.psel = null; draw(); return; }
    var d = ev.shiftKey ? 1 : .25, m = { ArrowLeft: [-d, 0], ArrowRight: [d, 0], ArrowUp: [0, -d], ArrowDown: [0, d] }[ev.key];
    if (!m || (!st.sel && st.psel == null)) return;
    ev.preventDefault();
    act(function () { var o = st.psel != null ? curItems()[st.psel] : doc.geo[st.fmt][st.sel]; o.x = r2(o.x + m[0]); o.y = r2(o.y + m[1]); });
  });

  // ── Edit tab: selected element ──
  var fnSel = $('p-fn'); Object.keys(FONTS).forEach(function (k) { var o = mk('option', null, FONTS[k]); o.value = k; fnSel.appendChild(o); });
  var shSel = $('p-sh'); Object.keys(SHAPES).forEach(function (k) { var o = mk('option', null, SHAPES[k]); o.value = k; shSel.appendChild(o); });
  var icSel = $('p-ic'); Object.keys(ICONS).forEach(function (k) { if (k === 'lotus') return; var o = mk('option', null, ICONS[k][0]); o.value = k; icSel.appendChild(o); });
  function show(sel, on) { document.querySelectorAll(sel).forEach(function (n) { n.hidden = !on; }); }
  function setV(n, v) { if (n.type === 'checkbox') n.checked = !!v; else if (String(n.value) !== String(v)) n.value = v; }
  function hex6(c, d) { return c && HEX.test(c) ? c.slice(0, 7) : d; }
  function panelSel() {
    var e = selEl(), props = $('props');
    $('sel-t').textContent = e ? (e.name || TYPES[e.t]) + (e.t === 'prods' ? '' : ' · ' + TYPES[e.t]) : 'Nothing selected';
    $('sel-empty').hidden = !!e;
    props.querySelectorAll('[data-need]').forEach(function (n) { n.hidden = !e || (n.dataset.need === 'geo' && e.t === 'prods'); });
    props.querySelectorAll('[data-show]').forEach(function (n) {
      var on = !!e && n.dataset.show.split(' ').indexOf(e.t) >= 0;
      if (n.dataset.show === 'fill') on = !!e && (e.t === 'shape' || e.t === 'btn' || (e.t === 'text' && e.sh && e.sh !== 'none'));
      if (n.dataset.show === 'border') on = !!e && (e.t === 'shape' || e.t === 'btn') && !!BORDERED[e.sh];
      n.hidden = !on;
    });
    if (!e) return;
    var g = doc.geo[st.fmt][e.id];
    props.querySelectorAll('[data-p]').forEach(function (n) {
      var p = n.dataset.p, v = e[p];
      if (n.type === 'color') v = hex6(v, p === 'col' ? '#111111' : '#FFFFFF');
      else if (n.type === 'checkbox') v = !!v;
      else if (v == null) v = { fs: 4, ls: 0, lh: 115, rad: 0, pd: e.t === 'text' ? 0 : 1, op: 100, shd: 0, bw: 0, fw: 700, fn: 'poppins', sh: 'none', bs: 'solid', pay: 'both', ic: 'sparkle', txt: '' }[p];
      if (v != null) setV(n, v);
    });
    $('v-fs').textContent = (e.fs || 4).toFixed(1);
    props.querySelectorAll('[data-g]').forEach(function (n) { setV(n, g[n.dataset.g] || 0); });
    $('v-r').textContent = (g.r || 0) + '°';
    $('p-hd').checked = !!g.hd;
    $('p-al').querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', String((g.al || e.al || 'c') === b.dataset.v)); });
    $('del').disabled = e.t === 'prods'; $('dup').disabled = e.t === 'prods';
    $('eres').disabled = !doc.tpl || !tplById[doc.tpl];
  }
  function propVal(n, p) {
    if (n.type === 'checkbox') return n.checked || undefined;
    if (n.type === 'color') { var e = selEl(), old = e && e[p]; var a = old && old.length === 9 ? old.slice(7) : ''; return n.value.toUpperCase() + a; }
    if (n.type === 'range' || n.type === 'number' || p === 'fw') return +n.value;
    return n.value;
  }
  $('props').addEventListener('input', function (ev) {
    var n = ev.target, e = selEl(); if (!e) return;
    begin();
    if (n.dataset.p) {
      var raw = {}; raw[n.dataset.p] = propVal(n, n.dataset.p);
      var c = cleanEl(Object.assign({}, e, raw), images); if (!c) return;
      if (raw[n.dataset.p] === undefined) delete e[n.dataset.p]; else if (c[n.dataset.p] !== undefined) e[n.dataset.p] = c[n.dataset.p];
      if (n.dataset.p === 'sh' && e.t === 'text' && e.sh !== 'none' && !e.fill) e.fill = '#FFFFFF';
      if (n.dataset.p === 'fs') $('v-fs').textContent = (+n.value).toFixed(1);
    } else if (n.dataset.g) {
      var g = doc.geo[st.fmt][e.id], v = +n.value; if (!isFinite(v)) return;
      var cg = cleanGeo(Object.assign({}, g, (function () { var o = {}; o[n.dataset.g] = v; return o; })())); g[n.dataset.g] = cg[n.dataset.g];
      if (n.dataset.g === 'r') $('v-r').textContent = g.r + '°';
    } else if (n.id === 'p-hd') { var g2 = doc.geo[st.fmt][e.id]; if (n.checked) g2.hd = true; else delete g2.hd; }
    else return;
    redrawStageOnly();
    if (ev.type === 'input' && (n.type !== 'range' && n.tagName !== 'TEXTAREA' && n.type !== 'text' && n.type !== 'color')) end();
    if (n.dataset.p === 'sh') panelSel();
  });
  $('props').addEventListener('change', function () { end(); layers(); });
  function redrawStageOnly() { var cv = renderCanvas(doc, st.fmt, st.set, 'edit'); stage.textContent = ''; stage.appendChild(cv); fitText(cv); markSel(); dirtyMark(); }
  $('p-al').addEventListener('click', function (ev) { var b = ev.target.closest('button'), e = selEl(); if (!b || !e) return; act(function () { e.al = b.dataset.v; FMTS.forEach(function (f) { delete doc.geo[f][e.id].al; }); }); });
  $('p-fill0').onclick = function () { var e = selEl(); if (e) act(function () { e.fill = 'transparent'; delete e.fill2; }); };
  $('p-fill20').onclick = function () { var e = selEl(); if (e) act(function () { delete e.fill2; }); };
  function removeSel() {
    if (st.psel != null) { act(function () { curItems().splice(st.psel, 1); st.psel = null; }); return; }
    var e = selEl(); if (!e || e.t === 'prods') return;
    act(function () { doc.els = doc.els.filter(function (x) { return x !== e; }); FMTS.forEach(function (f) { delete doc.geo[f][e.id]; }); st.sel = null; });
  }
  function duplicate() {
    var e = selEl(); if (!e || e.t === 'prods') return;
    act(function () { var c = clone(e); c.id = uid('e'); c.name = (e.name || TYPES[e.t]) + ' copy'; doc.els.splice(doc.els.indexOf(e) + 1, 0, c);
      FMTS.forEach(function (f) { var g = clone(doc.geo[f][e.id]); g.x = r2(g.x + 2); g.y = r2(g.y + 2); doc.geo[f][c.id] = g; }); st.sel = c.id; });
  }
  function moveZ(d) { var e = selEl(); if (!e) return; var i = doc.els.indexOf(e), j = i + d; if (j < 0 || j >= doc.els.length) return; act(function () { doc.els.splice(i, 1); doc.els.splice(j, 0, e); }); }
  $('dup').onclick = duplicate; $('del').onclick = removeSel; $('fwd').onclick = function () { moveZ(1); }; $('bwd').onclick = function () { moveZ(-1); };
  $('match').onclick = function () { var e = selEl(); if (!e) return; act(function () { var g = doc.geo[st.fmt][e.id], H = HU(st.fmt); FMTS.forEach(function (f) { if (f === st.fmt) return; var o = clone(g); o.y = r2((g.y + (g.h || 0) / 2) / H * HU(f) - (g.h || 0) / 2); doc.geo[f][e.id] = cleanGeo(o); }); }); setStatus('Placed the same way in all 3 sizes', 'ok'); };
  $('eres').onclick = function () { var e = selEl(), t = tplById[doc.tpl]; if (!e || !t) return; var src = fromTemplate(t), o = src.els.filter(function (x) { return x.id === e.id; })[0];
    if (!o) { setStatus('This item is not part of the template', 'warn'); return; }
    act(function () { var i = doc.els.indexOf(e); doc.els[i] = o; FMTS.forEach(function (f) { doc.geo[f][o.id] = src.geo[f][o.id]; }); }); };

  // ── Edit tab: add ──
  function addEl(e, gw, gh) {
    act(function () {
      e.id = uid('e'); var top = doc.els.length;
      doc.els.splice(top, 0, e);
      FMTS.forEach(function (f) { doc.geo[f][e.id] = { x: r2(50 - gw / 2), y: r2(HU(f) / 2 - (gh || 6) / 2), w: gw, h: gh || 0, r: 0, k: 1 }; });
      st.sel = e.id; st.psel = null;
    });
    showTab('edit');
  }
  var accNow = function () { var a = accentOf(doc).slice(0, 7); return a; };
  document.querySelectorAll('[data-add]').forEach(function (b) { b.onclick = function () {
    var a = accNow();
    switch (b.dataset.add) {
      case 'heading': addEl({ t: 'text', name: 'Heading', txt: 'Your headline', fn: 'montserrat', fw: 900, fs: 8, col: '#111111', al: 'c', lh: 105 }, 80); break;
      case 'text': addEl({ t: 'text', name: 'Text', txt: 'Add your text', fn: 'poppins', fw: 500, fs: 3.6, col: '#333333', al: 'c', lh: 130 }, 70); break;
      case 'btn': addEl({ t: 'btn', name: 'Button', txt: 'Shop Now', fn: 'poppins', fw: 700, fs: 3.8, col: contrast(a), al: 'c', sh: 'pill', fill: a, shd: 35, cr: 'acc' }, 48, 8.4); break;
      case 'arabic': addEl({ t: 'text', name: 'Arabic line', txt: 'تسوّقي الآن', fn: 'cairo', fw: 700, fs: 4, col: a, al: 'c', lh: 140, sh: 'pill', fill: a + '1A', pd: .7, tr: 'acc' }, 70); break;
      case 'logo': addEl({ t: 'logo', name: 'Logo', txt: 'K-Beauty Bliss', fn: 'playfair', fw: 700, fs: 3, col: '#111111', al: 'c' }, 40); break;
      case 'pay': addEl({ t: 'pay', name: 'Tabby / Tamara', pay: 'both' }, 38, 3.4); break;
    }
  }; });
  $('add-badge').onchange = function (ev) {
    var k = ev.target.value, a = accNow(); ev.target.value = ''; if (!k) return;
    if (k === 'ribbon') { act(function () { var e = { id: uid('e'), t: 'text', name: 'Corner ribbon', txt: 'K-Beauty Bliss ♥', fn: 'poppins', fw: 800, fs: 2.4, ls: 4, col: contrast(a), sh: 'rect', fill: a, shd: 30, cr: 'acc' }; doc.els.push(e); FMTS.forEach(function (f) { doc.geo[f][e.id] = { x: -20, y: 13.9, w: 74, h: 6.2, r: -45, k: 1 }; }); st.sel = e.id; }); return; }
    var wide = k === 'pill' || k === 'banner';
    addEl({ t: 'shape', name: 'Badge', sh: k, fill: a, txt: wide ? 'NEW IN' : 'UP TO\n30% OFF', fn: 'bebas', fw: 400, fs: wide ? 4.2 : 4.4, lh: 95, col: contrast(a), shd: 30, cr: 'acc' }, wide ? 30 : 20, wide ? 8 : 20);
  };
  $('add-shape').onchange = function (ev) {
    var k = ev.target.value, a = accNow(); ev.target.value = ''; if (!k) return;
    if (k === 'line') { addEl({ t: 'shape', name: 'Line', sh: 'rect', fill: a, rad: .2 }, 40, .6); return; }
    addEl({ t: 'shape', name: SHAPES[k], sh: k, fill: k === 'ring' ? 'transparent' : a + '40', bc: a, bw: k === 'ring' ? 4 : 0, rad: 3 }, k === 'ticket' ? 60 : 30, k === 'ticket' ? 26 : k === 'arch' ? 40 : 30);
  };
  var icg = $('icg');
  Object.keys(ICONS).forEach(function (k) { if (k === 'lotus') return; var b = mk('button'); b.type = 'button'; b.title = ICONS[k][0]; b.setAttribute('aria-label', 'Add ' + ICONS[k][0]); b.innerHTML = iconSvg(k); b.onclick = function () { addEl({ t: 'icon', name: ICONS[k][0], ic: k, col: accNow() }, k === 'stars5' ? 28 : 12, k === 'stars5' ? 5.6 : 12); }; icg.appendChild(b); });
  $('add-img').onchange = function () { var f = this.files && this.files[0]; this.value = ''; if (!f) return;
    readPicture(f, { cut: false, max: 1400, kind: 'img' }).then(function (r) { var w = r.aspect >= 1 ? 40 : 40 * r.aspect; addEl({ t: 'img', name: r.name, img: r.id }, r2(w), r2(w / r.aspect)); }).catch(pictureError); };

  // ── layers ──
  function layers() {
    var host = $('layers'); host.textContent = '';
    doc.els.slice().reverse().forEach(function (e) {
      var row = mk('div', 'ly' + (e.id === st.sel ? ' on' : '')), g = doc.geo[st.fmt][e.id];
      var label = e.name || TYPES[e.t]; if (e.txt && e.t !== 'logo') label += ' — ' + e.txt.replace(/\s+/g, ' ').slice(0, 22);
      var nm = mk('span', 'n' + (e.hide || g.hd ? ' off' : ''), label); row.appendChild(nm);
      var a = mk('span', 'a');
      function b(txt, title, pressed, fn) { var x = mk('button', null, txt); x.type = 'button'; x.title = title; x.setAttribute('aria-label', title + ': ' + label); if (pressed != null) x.setAttribute('aria-pressed', String(pressed)); x.onclick = function (ev) { ev.stopPropagation(); fn(); }; a.appendChild(x); }
      b(e.hide ? '◌' : '●', e.hide ? 'Show' : 'Hide', !e.hide, function () { act(function () { if (e.hide) delete e.hide; else e.hide = true; }); });
      b(e.lock ? '🔒' : '🔓', e.lock ? 'Unlock' : 'Lock', !!e.lock, function () { act(function () { if (e.lock) delete e.lock; else e.lock = true; }); });
      b('▲', 'Bring forward', null, function () { st.sel = e.id; moveZ(1); });
      b('▼', 'Send backward', null, function () { st.sel = e.id; moveZ(-1); });
      row.appendChild(a);
      row.onclick = function () { st.sel = e.id; st.psel = null; markSel(); panelSel(); layers(); };
      host.appendChild(row);
    });
  }

  // ── Products tab ──
  var pk = $('pk'), psP = $('ps-p');
  function prodName(p) { return D.prods[p] ? D.prods[p].n : (images[p.slice(2)] ? images[p.slice(2)].name : p); }
  function allProds() { return Object.keys(D.prods).concat(Object.keys(images).filter(function (id) { return images[id].kind === 'prod'; }).map(function (id) { return 'u_' + id; })); }
  function buildPk() {
    pk.textContent = ''; psP.textContent = '';
    allProds().forEach(function (p) {
      var b = mk('button'); b.type = 'button'; b.dataset.p = p; b.title = prodName(p);
      b.appendChild(mk('i', prodClass(p))); b.appendChild(mk('span', null, prodName(p)));
      if (p.indexOf('u_') === 0) { var x = mk('span', 'x', '×'); x.setAttribute('role', 'button'); x.setAttribute('aria-label', 'Delete ' + prodName(p)); x.onclick = function (ev) { ev.stopPropagation(); dropUpload(p); }; b.appendChild(x); }
      b.onclick = function () { addProduct(p); };
      pk.appendChild(b);
      var o = mk('option', null, prodName(p)); o.value = p; psP.appendChild(o);
    });
  }
  function drawPk() { var have = curItems().map(function (x) { return x.p; }); pk.querySelectorAll('button').forEach(function (b) { b.classList.toggle('in', have.indexOf(b.dataset.p) >= 0); }); }
  function stdW(l, p) { var hs = l.map(function (x) { return x.w / aspectOf(x.p); }); var h = hs.length ? hs.reduce(function (a, b) { return a + b; }, 0) / hs.length : 30; return Math.max(4, Math.min(40, h * aspectOf(p))); }
  function addTo(l, p, f) { var i = l.findIndex(function (x) { return x.p === p; }); if (i >= 0) return i; var w = stdW(l, p), n = l.length;
    l.push({ p: p, x: r2(Math.max(2, 50 - w / 2 + (n % 3 - 1) * 10)), y: r2(HU(f) * .45 + (n % 2) * 4 - w / aspectOf(p) / 2), w: r2(w), r: 0, sh: 55, ss: 'soft' }); return l.length - 1; }
  function addProduct(p) {
    act(function () {
      var fs = $('pk-fmts').checked ? FMTS : [st.fmt], ss = $('pk-sets').checked ? [0, 1, 2] : [st.set];
      fs.forEach(function (f) { ss.forEach(function (s) { addTo(doc.sets[f][s], p, f); }); });
      st.psel = addTo(curItems(), p, st.fmt); st.sel = null;
    });
    setStatus(prodName(p) + ' added — drag it into place', 'ok');
  }
  function panelProd() {
    var it = st.psel != null ? curItems()[st.psel] : null;
    $('ps-t').textContent = it ? prodName(it.p) : 'No product selected';
    $('ps-h').textContent = it ? 'Drag to move · blue corner to resize · round handle to turn.' : 'Tap a product on the ad to move, resize or swap it.';
    document.querySelectorAll('[data-pneed]').forEach(function (n) { n.hidden = !it; });
    if (!it) return;
    setV(psP, it.p); setV($('ps-r'), it.r || 0); $('ps-rv').textContent = (it.r || 0) + '°'; setV($('ps-sh'), it.sh == null ? 55 : it.sh); setV($('ps-ss'), it.ss || 'soft');
  }
  function pItem() { return st.psel != null ? curItems()[st.psel] : null; }
  psP.onchange = function () { var it = pItem(); if (!it || !prodOk(psP.value)) return; act(function () { var h = it.w / aspectOf(it.p), cx = it.x + it.w / 2, by = it.y + h; it.p = psP.value; it.w = r2(h * aspectOf(it.p)); if (it.w > 45) it.w = 45; it.x = r2(cx - it.w / 2); it.y = r2(by - it.w / aspectOf(it.p)); }); };
  $('ps-r').oninput = function () { var it = pItem(); if (!it) return; begin(); it.r = num(this.value, -360, 360, 0); $('ps-rv').textContent = it.r + '°'; redrawStageOnly(); };
  $('ps-sh').oninput = function () { var it = pItem(); if (!it) return; begin(); it.sh = num(this.value, 0, 100, 55); redrawStageOnly(); };
  ['ps-r', 'ps-sh'].forEach(function (id) { $(id).onchange = function () { end(); }; });
  $('ps-ss').onchange = function () { var it = pItem(); if (it) act(function () { it.ss = $('ps-ss').value === 'sharp' || $('ps-ss').value === 'glow' ? $('ps-ss').value : 'soft'; }); };
  function grow(k) { var it = pItem(); if (!it) return; act(function () { var a = aspectOf(it.p), cx = it.x + it.w / 2, by = it.y + it.w / a; it.w = r2(Math.max(2, it.w * k)); it.x = r2(cx - it.w / 2); it.y = r2(by - it.w / a); }); }
  $('ps-sm').onclick = function () { grow(1 / 1.08); }; $('ps-bg').onclick = function () { grow(1.08); };
  $('ps-fr').onclick = function () { var l = curItems(); if (st.psel == null) return; act(function () { l.push(l.splice(st.psel, 1)[0]); st.psel = l.length - 1; }); };
  $('ps-del').onclick = removeSel;
  $('ps-reset').onclick = function () { var t = tplById[doc.tpl]; if (!t) { setStatus('This design has no template to reset from', 'warn'); return; } var src = fromTemplate(t); act(function () { doc.sets[st.fmt][st.set] = src.sets[st.fmt][st.set]; st.psel = null; }); };

  // ── pictures: products (white background taken off in this browser), images, backgrounds, logos ──
  var upCss = mk('style'); document.head.appendChild(upCss);
  function registerImage(r) {
    if (!IMGID.test(r.id) || !(DATAURL.test(r.src) || /^\/_blob\/[A-Za-z0-9][A-Za-z0-9_-]{5,63}$/.test(r.src))) return false;
    images[r.id] = { name: str(r.name, 60) || 'My picture', aspect: num(r.aspect, .05, 20, 1), kind: ['prod', 'bg', 'logo', 'img'].indexOf(r.kind) >= 0 ? r.kind : 'img', src: r.src };
    if (images[r.id].kind === 'prod') upCss.textContent += '.p-u-' + r.id + '{background-image:url("' + r.src + '")}';
    return true;
  }
  function cutBackground(cv) {
    var w = cv.width, h = cv.height, cx = cv.getContext('2d'), im = cx.getImageData(0, 0, w, h), d = im.data;
    if (d[3] < 250 || d[(w - 1) * 4 + 3] < 250 || d[((h - 1) * w) * 4 + 3] < 250) return;
    var c = [0, (w - 1), (h - 1) * w, h * w - 1].map(function (i) { return [d[i * 4], d[i * 4 + 1], d[i * 4 + 2]]; });
    var bg = [0, 1, 2].map(function (k) { return c.map(function (v) { return v[k]; }).sort(function (a, b) { return a - b; })[1]; });
    if (bg[0] + bg[1] + bg[2] < 600) return;
    var seen = new Uint8Array(w * h), stck = [], T = 22;
    function near(i) { return Math.max(Math.abs(d[i * 4] - bg[0]), Math.abs(d[i * 4 + 1] - bg[1]), Math.abs(d[i * 4 + 2] - bg[2])) <= T; }
    for (var x = 0; x < w; x++) stck.push(x, (h - 1) * w + x); for (var y = 0; y < h; y++) stck.push(y * w, y * w + w - 1);
    while (stck.length) { var i = stck.pop(); if (seen[i] || !near(i)) continue; seen[i] = 1; d[i * 4 + 3] = 0; var px = i % w; if (px > 0) stck.push(i - 1); if (px < w - 1) stck.push(i + 1); if (i >= w) stck.push(i - w); if (i < w * (h - 1)) stck.push(i + w); }
    for (var j = 0; j < w * h; j++) { if (seen[j]) continue; var p2 = j % w; if ((p2 > 0 && seen[j - 1]) || (p2 < w - 1 && seen[j + 1]) || (j >= w && seen[j - w]) || (j < w * (h - 1) && seen[j + w])) d[j * 4 + 3] = 150; }
    cx.putImageData(im, 0, 0);
  }
  function trim(cv) { var w = cv.width, h = cv.height, d = cv.getContext('2d').getImageData(0, 0, w, h).data, x0 = w, y0 = h, x1 = -1, y1 = -1;
    for (var y = 0; y < h; y++) for (var x = 0; x < w; x++) if (d[(y * w + x) * 4 + 3] > 8) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    if (x1 < 0) return cv; var o = mk('canvas'); o.width = x1 - x0 + 1; o.height = y1 - y0 + 1; o.getContext('2d').drawImage(cv, -x0, -y0); return o; }
  function loadImg(src) { return new Promise(function (res, rej) { var im = new Image(); im.onload = function () { res(im); }; im.onerror = function () { rej(new Error('That picture could not be opened')); }; im.src = src; }); }
  function readPicture(file, o) {
    if (!/^image\/(png|jpeg|webp)$/.test(file.type)) return Promise.reject(new Error('Choose a PNG, JPG or WebP picture'));
    if (file.size > 25e6) return Promise.reject(new Error('That picture is over 25 MB'));
    setStatus('Preparing ' + file.name + '…');
    var url = URL.createObjectURL(file);
    return loadImg(url).then(function (img) {
      URL.revokeObjectURL(url);
      var k = Math.min(1, o.max / Math.max(img.naturalWidth, img.naturalHeight)), cv = mk('canvas');
      cv.width = Math.max(1, Math.round(img.naturalWidth * k)); cv.height = Math.max(1, Math.round(img.naturalHeight * k));
      cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
      if (o.cut) { cutBackground(cv); cv = trim(cv); }
      var type = o.kind === 'bg' ? 'image/jpeg' : 'image/png';
      var name = file.name.replace(/\.[a-z0-9]+$/i, '').replace(/[-_]+/g, ' ').trim().slice(0, 60) || 'My picture', aspect = +(cv.width / cv.height).toFixed(4);
      return new Promise(function (res) { cv.toBlob(res, type, .9); }).then(function (blob) {
        return Store.putImage(blob, { name: name, aspect: aspect, kind: o.kind }).then(function (r) { registerImage({ id: r.id, src: r.src, name: name, aspect: aspect, kind: o.kind }); setStatus(name + ' added', 'ok'); return { id: r.id, name: name, aspect: aspect }; });
      });
    });
  }
  function pictureError(e) { setStatus('Not added: ' + ((e && e.message) || 'try again'), 'warn'); }
  $('up-file').onchange = function () { var f = this.files && this.files[0]; this.value = ''; if (!f) return;
    readPicture(f, { cut: $('up-bg').checked, max: 1000, kind: 'prod' }).then(function (r) { buildPk(); addProduct('u_' + r.id); }).catch(pictureError); };
  function dropUpload(p) {
    var id = p.slice(2); if (!confirm('Delete "' + prodName(p) + '" from the list? Designs that use it will no longer show it.')) return;
    Store.delImage(id).then(function () { act(function () { FMTS.forEach(function (f) { doc.sets[f] = doc.sets[f].map(function (l) { return l.filter(function (it) { return it.p !== p; }); }); }); st.psel = null; }); delete images[id]; buildPk(); draw(); setStatus('Deleted', 'ok'); })
      .catch(function (e) { setStatus('Not deleted: ' + ((e && e.message) || 'try again'), 'warn'); });
  }

  // ── Style tab: background + brand kit ──
  var patSel = $('bg-pat'); Object.keys(PATS).forEach(function (k) { var o = mk('option', null, PATS[k]); o.value = k; patSel.appendChild(o); });
  function panelStyle() {
    var b = doc.bg; setV($('bg-k'), b.k); setV($('bg-ang'), b.ang); setV($('bg-c1'), hex6(b.c1, '#FFFFFF')); setV($('bg-c2'), hex6(b.c2, '#EEEEEE'));
    setV(patSel, b.pat); setV($('bg-pc'), hex6(b.pc, '#000000')); setV($('bg-po'), b.po); setV($('bg-ov'), b.ov);
    $('bg-noimg').disabled = !b.img; $('bg-ov').closest('.row').hidden = b.k !== 'image';
    setV($('br-c1'), brand.c1); setV($('br-c2'), brand.c2); $('br-on').checked = brand.on; $('br-nologo').disabled = !brand.logo;
  }
  function bgSet(p, v) { begin(); var raw = Object.assign({}, doc.bg); raw[p] = v; doc.els && (doc.bg = cleanDoc({ els: [], bg: raw }, images).bg); redrawStageOnly(); }
  [['bg-k', 'k'], ['bg-ang', 'ang', 1], ['bg-c1', 'c1'], ['bg-c2', 'c2'], ['bg-pat', 'pat'], ['bg-pc', 'pc'], ['bg-po', 'po', 1], ['bg-ov', 'ov', 1]].forEach(function (q) {
    var n = $(q[0]); n.addEventListener('input', function () { if (q[1] === 'k' && n.value === 'image' && !doc.bg.img) { setStatus('Upload a picture first', 'warn'); panelStyle(); return; } bgSet(q[1], q[2] ? +n.value : (n.type === 'color' ? n.value.toUpperCase() : n.value)); });
    n.addEventListener('change', function () { end(); panelStyle(); });
  });
  $('bg-file').onchange = function () { var f = this.files && this.files[0]; this.value = ''; if (!f) return;
    readPicture(f, { cut: false, max: 2000, kind: 'bg' }).then(function (r) { act(function () { doc.bg.img = r.id; doc.bg.k = 'image'; }); }).catch(pictureError); };
  $('bg-noimg').onclick = function () { act(function () { doc.bg.img = ''; doc.bg.k = 'radial'; }); };
  function saveBrand() { return Store.putKV('brand', brand).catch(function () {}); }
  $('br-c1').onchange = function () { brand.c1 = this.value.toUpperCase(); saveBrand(); if (brand.on) thumbsDirty(); };
  $('br-c2').onchange = function () { brand.c2 = this.value.toUpperCase(); saveBrand(); if (brand.on) thumbsDirty(); };
  $('br-on').onchange = function () { brand.on = this.checked; saveBrand(); thumbsDirty(); setStatus(brand.on ? 'Templates now use your brand colours' : 'Templates use their own colours', 'ok'); };
  $('br-file').onchange = function () { var f = this.files && this.files[0]; this.value = ''; if (!f) return;
    readPicture(f, { cut: true, max: 800, kind: 'logo' }).then(function (r) { brand.logo = r.id; saveBrand(); thumbsDirty(); draw(); setStatus('Logo added to every template', 'ok'); }).catch(pictureError); };
  $('br-nologo').onclick = function () { brand.logo = ''; saveBrand(); thumbsDirty(); draw(); };
  $('br-apply').onclick = function () {
    var c1 = $('br-c1').value.toUpperCase(), c2 = $('br-c2').value.toUpperCase(), tx = contrast(c1);
    act(function () { doc.els.forEach(function (e) {
      if (e.cr === 'acc') { e.fill = c1; delete e.fill2; if (e.t === 'btn' || e.t === 'shape' || (e.t === 'text' && e.sh === 'rect')) e.col = tx; }
      if (e.cr === 'acc2' && e.fill && e.fill !== 'transparent') e.fill = c2 + (e.fill.length === 9 ? e.fill.slice(7) : '');
      if (e.tr === 'acc') { e.col = c1; if (e.sh === 'pill' && e.fill) e.fill = c1 + '1A'; }
      if (e.t === 'icon' && e.ic !== 'uae') e.col = c1;
      if (e.t === 'shape' && e.bc && !e.cr) e.bc = c1;
    }); });
    setStatus('Brand colours applied — Ctrl+Z to undo', 'ok');
  };

  // ── Motion tab + play ──
  function panelMotion() { var A = doc.anim; setV($('an-in'), A.in); setV($('an-idle'), A.idle); setV($('an-sec'), String(A.sec)); setV($('an-len'), String(A.len)); }
  ['an-in', 'an-idle', 'an-sec', 'an-len'].forEach(function (id) { $(id).onchange = function () { act(function () { var a = Object.assign({}, doc.anim); a[{ 'an-in': 'in', 'an-idle': 'idle', 'an-sec': 'sec', 'an-len': 'len' }[id]] = $(id).value; doc.anim = cleanDoc({ els: [], anim: a }, images).anim; }); }; });
  var playT = 0, playStart = 0;
  function animateProds() {
    var A = doc.anim, inA = { fade: 'a-fade .6s ease both', pop: 'a-pop .55s cubic-bezier(.3,1.4,.5,1) both', up: 'a-up .6s ease both', none: '' }[A.in];
    var idle = { float: 'a-float 2.6s ease-in-out infinite', sway: 'a-sway 3s ease-in-out infinite', none: '' }[A.idle];
    stage.querySelectorAll('.bp').forEach(function (n, i) { var p = []; if (inA) p.push(inA.replace(' both', ' ' + (i * .08).toFixed(2) + 's both')); if (idle) p.push(idle.replace(' infinite', ' ' + (i * .35).toFixed(2) + 's infinite')); n.style.animation = p.join(','); });
  }
  function stopPlay() { if (!playT) return; clearInterval(playT); playT = 0; stage.classList.remove('playing'); $('play').textContent = '▶ Play'; $('play2').textContent = '▶ Play'; draw(); }
  function play() {
    if (playT) { stopPlay(); return; }
    var A = doc.anim; st.sel = null; st.psel = null; st.set = 0; draw(); stage.classList.add('playing'); animateProds();
    $('play').textContent = '■ Stop'; $('play2').textContent = '■ Stop'; playStart = Date.now();
    playT = setInterval(function () {
      if ((Date.now() - playStart) / 1000 >= A.len) { stopPlay(); setStatus('Played ' + A.len + ' s — as the video will run', 'ok'); return; }
      st.set = (st.set + 1) % 3; var cv = renderCanvas(doc, st.fmt, st.set, 'edit'); stage.textContent = ''; stage.appendChild(cv); fitText(cv); chipsTop(); animateProds();
    }, A.sec * 1000);
  }
  $('play').onclick = play; $('play2').onclick = play;

  // ── templates browser ──
  var thumbsBuilt = false, tgEl = $('tg');
  function thumbsDirty() { thumbsBuilt = false; if (st.tab === 'tpl') buildThumbs(); }
  function buildCats() { var h = $('cats'); h.textContent = ''; KT.CATS.forEach(function (c) { var n = c[0] === 'all' ? KT.LIST.length : KT.LIST.filter(function (t) { return t.cat === c[0]; }).length; var b = mk('button', 'chip', c[1] + ' · ' + n); b.type = 'button'; b.setAttribute('aria-pressed', String(st.cat === c[0])); b.onclick = function () { st.cat = c[0]; buildCats(); filterThumbs(); }; h.appendChild(b); }); }
  function buildThumbs() {
    if (thumbsBuilt) return; thumbsBuilt = true; tgEl.textContent = '';
    KT.LIST.forEach(function (t) {
      var b = mk('button'); b.type = 'button'; b.dataset.id = t.id; b.dataset.cat = t.cat; b.dataset.q = (t.name + ' ' + t.tags + ' ' + t.cat + ' ' + t.fam).toLowerCase();
      b.title = t.name; b.setAttribute('aria-label', 'Use template: ' + t.name);
      var d = fromTemplate(t); b.appendChild(renderCanvas(d, 'post', 0, 'thumb')); b.appendChild(mk('span', 'nm', t.name));
      b.onclick = function () { useTemplate(t); };
      tgEl.appendChild(b);
    });
    fitText(tgEl); filterThumbs();
  }
  function filterThumbs() { var q = st.q.trim().toLowerCase(); tgEl.querySelectorAll('button').forEach(function (b) { b.hidden = !((st.cat === 'all' || b.dataset.cat === st.cat) && (!q || q.split(/\s+/).every(function (w) { return b.dataset.q.indexOf(w) >= 0; }))); b.setAttribute('aria-current', String(b.dataset.id === doc.tpl)); }); }
  $('tq').addEventListener('input', function () { st.q = this.value; filterThumbs(); });
  function useTemplate(t) {
    stopPlay();
    act(function () { var d = fromTemplate(t); d.id = doc.id; d.name = t.name; doc = d; st.sel = null; st.psel = null; st.set = 0; });
    $('pname').value = doc.name; filterThumbs(); setStatus(t.name + ' applied — Ctrl+Z to go back', 'ok');
  }

  // ── tabs ──
  function showTab(k) {
    st.tab = k;
    document.querySelectorAll('.tab').forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.tab === k)); });
    document.querySelectorAll('.pane').forEach(function (p) { p.hidden = p.dataset.pane !== k; });
    if (k === 'tpl') buildThumbs();
  }
  document.querySelectorAll('.tab').forEach(function (b) { b.onclick = function () { showTab(b.dataset.tab); }; });

  // ── saving ──
  $('pname').addEventListener('input', function () { begin(); doc.name = str(this.value, 60) || 'Untitled design'; dirtyMark(); });
  $('pname').addEventListener('change', end);
  function saveNow() {
    end(); setStatus('Saving…');
    var snap = JSON.stringify(doc);
    return Store.putProject(clone(doc)).then(function () { lastSaved = snap; dirtyMark(); scheduleDraft(); setStatus('Saved' + (Store.kind() === 'memory' ? ' for this visit only — use Export project to keep it' : ''), Store.kind() === 'memory' ? 'warn' : 'ok'); listMine(); })
      .catch(function (e) { setStatus('Not saved: ' + ((e && e.message) || 'try again') + '. Your work is still here.', 'warn'); });
  }
  $('save').onclick = saveNow;
  $('undo').onclick = undo; $('redo').onclick = redo;
  $('saveas').onclick = function () { end(); doc.id = uid('d'); doc.name = (doc.name + ' copy').slice(0, 60); $('pname').value = doc.name; saveNow(); };
  function listMine() {
    return Store.listProjects().then(function (l) {
      var h = $('mine'); h.textContent = ''; l.sort(function (a, b) { return b.updated - a.updated; }); $('mine-n').textContent = l.length ? '(' + l.length + ')' : '';
      if (!l.length) h.appendChild(mk('p', 'empty', 'Nothing saved yet. Save keeps the design on this computer.'));
      l.forEach(function (p) {
        if (!IMGID.test(p.id)) return;
        var row = mk('div', 'it'); row.appendChild(mk('span', null, (p.name || 'Untitled') + (p.id === doc.id ? ' (open)' : '')));
        var o = mk('button', 'btn sm', 'Open'); o.type = 'button'; o.onclick = function () { openProject(p.id); }; row.appendChild(o);
        var x = mk('button', 'btn sm', 'Delete'); x.type = 'button'; x.onclick = function () { if (!confirm('Delete "' + p.name + '"?')) return; Store.delProject(p.id).then(listMine); }; row.appendChild(x);
        h.appendChild(row);
      });
    }).catch(function () {});
  }
  function openProject(id) {
    Store.getProject(id).then(function (raw) {
      var d = cleanDoc(raw, images); if (!d) { setStatus('That design could not be read', 'warn'); return; }
      end(); begin(); doc = d; end(); lastSaved = JSON.stringify(doc); st.sel = null; st.psel = null; st.set = 0; $('pname').value = doc.name; draw(); listMine(); setStatus('Opened ' + doc.name, 'ok');
    });
  }
  window.addEventListener('beforeunload', function () { clearTimeout(draftT); Store.draft({ doc: doc, t: Date.now(), saved: lastSaved === JSON.stringify(doc) }); });

  // ── downloads: full HD, drawn from the same markup with the page's own embedded fonts ──
  var dlBusy = false, dlSt = $('dl-st'), dlExt = 'png';
  $('dl-fmt').addEventListener('click', function (ev) { var b = ev.target.closest('button'); if (!b) return; dlExt = b.dataset.v; this.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); }); });
  function fileBase() { return 'KBB-' + (doc.name || 'design').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); }
  function h2iOpts(f, transparent) { var W = PX[f][0], H = PX[f][1]; var o = { width: W, height: H, canvasWidth: W, canvasHeight: H, pixelRatio: 1, skipAutoScale: true, cacheBust: false, fontEmbedCSS: $('fontcss').textContent }; if (!transparent) o.backgroundColor = '#FFFFFF'; return o; }
  function host(f, cv) { var h = mk('div'); h.style.cssText = 'position:fixed;left:-' + (PX[f][0] + 300) + 'px;top:0;width:' + PX[f][0] + 'px;pointer-events:none'; h.appendChild(cv); document.body.appendChild(h); fitText(cv); return h; }
  function renderFile(f, set, ext) {
    var cv = renderCanvas(doc, f, set, 'file'), h = host(f, cv);
    var job = document.fonts.ready.then(function () { return ext === 'jpg' ? htmlToImage.toJpeg(cv, Object.assign({ quality: .95 }, h2iOpts(f))).then(function (u) { return fetch(u).then(function (r) { return r.blob(); }); }) : htmlToImage.toBlob(cv, h2iOpts(f)); });
    return job.then(function (b) { h.remove(); return b; }, function (e) { h.remove(); throw e; });
  }
  function busy(on) { dlBusy = on; document.querySelectorAll('.dl .btn').forEach(function (b) { b.disabled = on; }); }
  function runDownload(list, zipName) {
    if (dlBusy) return; stopPlay(); busy(true); var n = 0, zip = list.length > 1 ? new JSZip() : null, one = null;
    dlSt.className = 'st'; dlSt.textContent = 'Drawing 1 of ' + list.length + '…';
    list.reduce(function (p, j) { return p.then(function () { return renderFile(j[0], j[1], dlExt).then(function (b) { n++; dlSt.textContent = 'Drawing ' + Math.min(n + 1, list.length) + ' of ' + list.length + '…'; var nm = fileBase() + '-' + j[0] + '-set' + (j[1] + 1) + '.' + dlExt; if (zip) zip.file(nm, b); else one = [nm, b]; }); }); }, Promise.resolve())
      .then(function () { if (!zip) return Store.save(one[0], one[1]); dlSt.textContent = 'Packing ' + list.length + ' pictures…'; return zip.generateAsync({ type: 'blob', compression: 'STORE' }).then(function (z) { return Store.save(zipName, z); }); })
      .then(function () { dlSt.className = 'st ok'; dlSt.textContent = list.length > 1 ? list.length + ' pictures ready' : 'Picture ready'; })
      .catch(function (e) { dlSt.className = 'st warn'; dlSt.textContent = e && e.code === 'cancelled' ? 'Download cancelled' : 'Not downloaded: ' + ((e && (e.message || e.code)) || 'try again'); })
      .then(function () { busy(false); });
  }
  $('dl-one').onclick = function () { runDownload([[st.fmt, st.set]]); };
  $('dl-sets').onclick = function () { runDownload([0, 1, 2].map(function (s) { return [st.fmt, s]; }), fileBase() + '-' + st.fmt + '.zip'); };
  $('dl-all').onclick = function () { var l = []; FMTS.forEach(function (f) { [0, 1, 2].forEach(function (s) { l.push([f, s]); }); }); runDownload(l, fileBase() + '-all-sizes.zip'); };

  // ── video: the design drawn once as two layers (under / over the products), the products animated
  //    between them on a canvas, recorded with MediaRecorder. Offline, no library. ──
  function videoType() { if (!window.MediaRecorder || !HTMLCanvasElement.prototype.captureStream) return null;
    return ['video/mp4;codecs=avc1.42E01E', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'].filter(function (t) { try { return MediaRecorder.isTypeSupported(t); } catch (e) { return false; } })[0] || null; }
  if (!videoType()) { $('dl-vid').disabled = true; $('vid-note').textContent = 'This browser cannot record video. Chrome or Edge can.'; }
  function layerCanvas(f, part) { var cv = renderCanvas(doc, f, 0, 'file', part), h = host(f, cv); return document.fonts.ready.then(function () { return htmlToImage.toCanvas(cv, h2iOpts(f, part === 'over')); }).then(function (c) { h.remove(); return c; }, function (e) { h.remove(); throw e; }); }
  function recordVideo(seconds) {
    var type = videoType(); if (!type || dlBusy) return Promise.resolve(null);
    stopPlay(); busy(true); var f = st.fmt, W = PX[f][0], H = PX[f][1], A = doc.anim, len = seconds || A.len, u = W / 100;
    dlSt.className = 'st'; dlSt.textContent = 'Preparing the video…';
    var pr = doc.els.filter(function (e) { return e.t === 'prods'; })[0], showP = pr && !pr.hide && !doc.geo[f][pr.id].hd;
    var srcs = {}; doc.sets[f].forEach(function (l) { l.forEach(function (it) { srcs[it.p] = D.prods[it.p] ? D.prods[it.p].src : images[it.p.slice(2)].src; }); });
    var pimg = {};
    return Promise.all([layerCanvas(f, 'under'), layerCanvas(f, 'over')].concat(Object.keys(srcs).map(function (p) { return loadImg(srcs[p]).then(function (im) { pimg[p] = im; }); }))).then(function (r) {
      var under = r[0], over = r[1], c = mk('canvas'); c.width = W; c.height = H; var x = c.getContext('2d');
      var rec = new MediaRecorder(c.captureStream(30), { mimeType: type, videoBitsPerSecond: 8e6 }), chunks = [];
      rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      var acc = accentOf(doc);
      function drawSet(s, tIn, alphaOut) {
        if (!showP) return;
        doc.sets[f][s].forEach(function (it, i) {
          var im = pimg[it.p]; if (!im) return;
          var p = Math.max(0, Math.min(1, (tIn - i * .08) / .6)), a = alphaOut == null ? 1 : alphaOut, sc = 1, ty = 0, rot = it.r || 0;
          if (alphaOut == null) { if (A.in === 'fade') a = p; else if (A.in === 'pop') { a = Math.min(1, p / .7); sc = p < .7 ? .6 + .46 * p / .7 : 1.06 - .06 * (p - .7) / .3; } else if (A.in === 'up') { a = p; ty = (1 - p) * 6 * u; } }
          if (A.idle === 'float') ty += -1.2 * u * (.5 - .5 * Math.cos((tIn - i * .35) / 2.6 * Math.PI * 2));
          if (A.idle === 'sway') rot += 2 * Math.sin((tIn - i * .35) / 3 * Math.PI * 2);
          var w = it.w * u, h = w / aspectOf(it.p), sh = (it.sh == null ? 55 : it.sh) / 100;
          x.save(); x.globalAlpha = Math.max(0, Math.min(1, a)); x.translate(it.x * u + w / 2, it.y * u + h / 2 + ty); x.rotate(rot * Math.PI / 180); x.scale(sc, sc);
          if (sh) { if (it.ss === 'glow') { x.shadowColor = acc; x.shadowBlur = 2.2 * sh * u * 2; } else { x.shadowColor = 'rgba(0,0,0,' + ((it.ss === 'sharp' ? .55 : .42) * sh).toFixed(2) + ')'; x.shadowBlur = (it.ss === 'sharp' ? .5 : 3.2) * sh * u; x.shadowOffsetY = (it.ss === 'sharp' ? .5 : 1.4) * sh * u; } }
          x.drawImage(im, -w / 2, -h / 2, w, h); x.restore();
        });
      }
      return new Promise(function (res) {
        var t0 = 0, stopped = false;
        rec.onstop = function () { res(new Blob(chunks, { type: type.split(';')[0] })); };
        function frame(now) {
          if (stopped) return; if (!t0) t0 = now; var t = (now - t0) / 1000;
          var s = Math.floor(t / A.sec) % 3, local = t - Math.floor(t / A.sec) * A.sec;
          x.clearRect(0, 0, W, H); x.drawImage(under, 0, 0, W, H);
          if (t >= A.sec && local < .6 && A.in !== 'none') drawSet((s + 2) % 3, 99, 1 - local / .6);
          drawSet(s, A.in === 'none' ? 99 : local);
          x.drawImage(over, 0, 0, W, H);
          dlSt.textContent = 'Recording ' + Math.min(len, Math.ceil(t)) + ' / ' + len + ' s — keep this tab open';
          if (t >= len) { stopped = true; rec.stop(); return; }
          requestAnimationFrame(frame);
        }
        rec.start(250); requestAnimationFrame(frame);
      });
    }).then(function (blob) {
      var ext = /mp4/.test(type) ? 'mp4' : 'webm';
      return Store.save(fileBase() + '-' + f + '.' + ext, blob).then(function () { dlSt.className = 'st ok'; dlSt.textContent = 'Video ready (' + ext.toUpperCase() + ', ' + (blob.size / 1048576).toFixed(1) + ' MB)'; return blob; });
    }).catch(function (e) { dlSt.className = 'st warn'; dlSt.textContent = 'Video not made: ' + ((e && e.message) || 'try again'); return null; })
      .then(function (b) { busy(false); return b; });
  }
  $('dl-vid').onclick = function () { recordVideo(); };

  // ── project file: export / import (validated field by field; nothing imported is ever HTML) ──
  $('xp').onclick = function () {
    end(); setStatus('Packing your project…');
    Store.listProjects().then(function (l) { return Promise.all(l.filter(function (p) { return IMGID.test(p.id); }).map(function (p) { return Store.getProject(p.id); })); }).then(function (ds) {
      ds = ds.filter(Boolean); if (!ds.some(function (d) { return d.id === doc.id; })) ds.push(clone(doc)); else ds = ds.map(function (d) { return d.id === doc.id ? clone(doc) : d; });
      return Promise.all(Object.keys(images).map(function (id) { var im = images[id]; return Store.toData(im.src).then(function (data) { return { id: id, name: im.name, aspect: im.aspect, kind: im.kind, data: data }; }).catch(function () { return null; }); }))
        .then(function (ims) { var out = { app: 'kbb-ad-studio', v: 1, exported: new Date().toISOString(), brand: brand, projects: ds, images: ims.filter(Boolean) };
          return Store.save('KBB-Ad-Studio-project-' + new Date().toISOString().slice(0, 10) + '.json', new Blob([JSON.stringify(out)], { type: 'application/json' })).then(function () { setStatus('Project exported: ' + ds.length + ' design(s), ' + out.images.length + ' picture(s)', 'ok'); }); });
    }).catch(function (e) { setStatus('Not exported: ' + ((e && e.message) || 'try again'), 'warn'); });
  };
  function importProject(text) {
    var o; try { o = JSON.parse(text); } catch (e) { return Promise.reject(new Error('That file is not a project file')); }
    if (!o || o.app !== 'kbb-ad-studio' || !Array.isArray(o.projects)) return Promise.reject(new Error('That file is not a KBB Ad Studio project'));
    var map = Object.create(null), ims = Array.isArray(o.images) ? o.images.slice(0, 200) : [];
    return ims.reduce(function (p, im) {
      return p.then(function () {
        if (!im || !IMGID.test(im.id) || typeof im.data !== 'string' || im.data.length > 16e6 || !DATAURL.test(im.data)) return;
        return loadImg(im.data).then(function (el) {                                   // must decode as a picture
          var kind = ['prod', 'bg', 'logo', 'img'].indexOf(im.kind) >= 0 ? im.kind : 'img';
          return fetch(im.data).then(function (r) { return r.blob(); }).then(function (b) { return Store.putImage(b, { name: str(im.name, 60) || 'Picture', aspect: +(el.naturalWidth / el.naturalHeight).toFixed(4), kind: kind }); })
            .then(function (r) { map[im.id] = r.id; registerImage({ id: r.id, src: r.src, name: str(im.name, 60), aspect: el.naturalWidth / el.naturalHeight, kind: kind }); });
        }).catch(function () {});
      });
    }, Promise.resolve()).then(function () {
      function remap(raw) {
        var r = clone(raw);
        if (r && Array.isArray(r.els)) r.els.forEach(function (e) { if (e && e.t === 'img') e.img = map[e.img] || '#'; });
        if (r && r.bg && r.bg.img) r.bg.img = map[r.bg.img] || '';
        if (r && r.sets) FMTS.forEach(function (f) { if (Array.isArray(r.sets[f])) r.sets[f].forEach(function (l) { if (Array.isArray(l)) l.forEach(function (it) { if (it && typeof it.p === 'string' && it.p.indexOf('u_') === 0) it.p = map[it.p.slice(2)] ? 'u_' + map[it.p.slice(2)] : '#'; }); }); });
        return r;
      }
      var docs = o.projects.slice(0, 200).map(function (p) { return cleanDoc(remap(p), images); }).filter(Boolean);
      if (o.brand) { var b = cleanBrand(o.brand); b.logo = map[b.logo] || ''; brand = b; saveBrand(); }
      return docs.reduce(function (p, d) { return p.then(function () { return Store.putProject(d); }); }, Promise.resolve()).then(function () { return docs; });
    });
  }
  $('xp-in').onchange = function () {
    var f = this.files && this.files[0]; this.value = ''; if (!f) return;
    if (f.size > 200e6) { setStatus('That file is too large', 'warn'); return; }
    setStatus('Importing…');
    f.text().then(importProject).then(function (docs) {
      buildPk(); thumbsDirty(); listMine();
      if (docs.length) { end(); begin(); doc = docs[0]; end(); lastSaved = JSON.stringify(doc); $('pname').value = doc.name; st.sel = null; st.psel = null; draw(); }
      setStatus('Imported ' + docs.length + ' design(s)', 'ok');
    }).catch(function (e) { setStatus('Not imported: ' + ((e && e.message) || 'try again'), 'warn'); });
  };

  // ── start ──
  function where() {
    var k = Store.kind();
    $('mode').textContent = { claude: 'saved to claude.ai', browser: 'offline', local: 'offline', memory: 'not saving' }[k];
    $('where').textContent = { claude: 'Saving to this artifact on claude.ai.', browser: 'Saving inside this browser on this computer (works offline). Use Export project for a backup or to move to another computer.', local: 'Saving inside this browser (small storage). Use Export project for a backup.', memory: 'This browser does not allow saving from this file. Use Export project before you close it.' }[k];
  }
  Store.init().then(function () {
    where();
    return Store.listImages().then(function (l) { l.forEach(registerImage); }).catch(function () {})
      .then(function () { return Store.getKV('brand').then(function (b) { if (b) brand = cleanBrand(b); }).catch(function () {}); })
      .then(function () { return Store.readDraft(); });
  }).then(function (dr) {
    var d = dr && cleanDoc(dr.doc, images);
    if (d) { doc = d; lastSaved = dr.saved ? JSON.stringify(d) : ''; if (!dr.saved) setStatus('Your last session is back — press Save to keep it', 'warn'); }
    else { doc = fromTemplate(tplById['black-friday'] || KT.LIST[0]); lastSaved = ''; }
    $('pname').value = doc.name;
    buildPk(); buildCats(); undoBtns(); draw(); showTab('tpl'); listMine();
    document.documentElement.dataset.ready = '1';
  });

  // test hooks (read-only views + the same actions the buttons run)
  window.KBB_STUDIO = { get doc() { return doc; }, st: st, templates: KT.LIST, useTemplate: function (id) { useTemplate(tplById[id]); }, renderFile: renderFile, recordVideo: recordVideo, store: Store, images: images, saveNow: saveNow, cleanDoc: function (r) { return cleanDoc(r, images); }, draw: draw, brand: function () { return brand; } };
})();
