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
  // ── built-in art (flags, country outlines, landmark silhouettes, company marks): constant SVG markup made at
  //    build time, packed gzip + base64 (≈140 KB instead of 370), unpacked once at start. An element names a piece
  //    by id ("flag:gb"); nothing stored or imported is ever used as markup. ──
  var ART = Object.create(null), artMemo = Object.create(null), ARTID = /^(flag|map|lm|mark|fig|oi):[a-z0-9-]{2,24}$/;
  function loadArt() {
    try {
      if (!D.art || !window.DecompressionStream) return Promise.resolve();
      var bin = atob(D.art), u = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
      return new Response(new Blob([u]).stream().pipeThrough(new DecompressionStream('gzip'))).text().then(function (t) { var o = JSON.parse(t); Object.keys(o).forEach(function (k) { if (ARTID.test(k) && typeof o[k] === 'string') ART[k] = o[k]; }); }).catch(function () {});
    } catch (e) { return Promise.resolve(); }
  }
  // mono art (outlines, landmarks) is drawn in #0b2545 and recoloured here; colours are validated hex only
  function artSrc(id, c) {
    var k = id + '|' + (c || ''); if (artMemo[k]) return artMemo[k];
    var svg = ART[id]; if (!svg) return '';
    if (c && /^#[0-9a-fA-F]{6}/.test(c) && /^(map|lm|oi):/.test(id)) svg = svg.split('#0b2545').join(c.slice(0, 7));
    return (artMemo[k] = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg))));
  }
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
  var FONTS = { inter: 'Inter', poppins: 'Poppins', montserrat: 'Montserrat', playfair: 'Playfair Display', dmserif: 'DM Serif Display', bebas: 'Bebas Neue', dancing: 'Dancing Script', afacad: 'Afacad', cairo: 'Cairo' };
  var SHAPES = { none: 'None', rect: 'Rectangle', pill: 'Pill', circle: 'Circle', ellipse: 'Oval', ring: 'Ring', arch: 'Arch', burst: 'Burst', star: 'Star', heart: 'Heart', banner: 'Banner', ticket: 'Ticket', blob: 'Blob', glow: 'Soft glow', petal: 'Petal', silk: 'Silk ribbon' };
  var BORDERED = { rect: 1, pill: 1, circle: 1, ellipse: 1, ring: 1, arch: 1 };
  var TYPES = { text: 'Text', btn: 'Button', shape: 'Shape', icon: 'Icon', img: 'Picture', art: 'Flag / landmark / map', pay: 'tabby / tamara', logo: 'Logo', prods: 'Products' };
  var PATS = { none: 'None', dots: 'Dots', grid: 'Grid', stripes: 'Stripes', rays: 'Rays', lattice: 'Lattice', waves: 'Waves', sparkle: 'Sparkles', petals: 'Petals', globe: 'Globe lines', routes: 'Flight routes' };
  var BGK = ['none', 'solid', 'linear', 'radial', 'image'], FITS = ['cover', 'contain', 'tile'];
  var HEX = /^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/, ID = /^[a-z][a-z0-9_]{0,31}$/i, IMGID = /^[A-Za-z0-9][A-Za-z0-9_-]{5,63}$/;
  var DATAURL = /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/]+=*$/;
  function poly(n, r1, r2_, rot) { var p = []; for (var i = 0; i < n * 2; i++) { var a = (i / (n * 2)) * Math.PI * 2 + (rot || 0), r = i % 2 ? r2_ : r1; p.push((50 + Math.sin(a) * r).toFixed(1) + '% ' + (50 - Math.cos(a) * r).toFixed(1) + '%'); } return 'polygon(' + p.join(',') + ')'; }
  function heartPoly() { var p = []; for (var i = 0; i < 48; i++) { var t = i / 48 * Math.PI * 2, x = 16 * Math.pow(Math.sin(t), 3), y = 13 * Math.cos(t) - 5 * Math.cos(2 * t) - 2 * Math.cos(3 * t) - Math.cos(4 * t); p.push((50 + x * 3.05).toFixed(1) + '% ' + (44 - y * 3.05).toFixed(1) + '%'); } return 'polygon(' + p.join(',') + ')'; }
  function blobPoly() { var p = []; for (var i = 0; i < 48; i++) { var a = i / 48 * Math.PI * 2, r = 46 * (1 + .07 * Math.sin(3 * a + .6) + .05 * Math.cos(5 * a)); p.push((50 + Math.cos(a) * r).toFixed(1) + '% ' + (50 + Math.sin(a) * r).toFixed(1) + '%'); } return 'polygon(' + p.join(',') + ')'; }
  // a satin ribbon: a band whose centre line is one sine wave and whose width swells and narrows like a fold
  function silkPoly() { var t = [], b = []; for (var i = 0; i <= 40; i++) { var x = i * 2.5, a = x / 100 * Math.PI * 2, c = 50 + 30 * Math.sin(a), w = 13 + 7 * Math.cos(a * 1.5 + .8); t.push(x + '% ' + (c - w).toFixed(1) + '%'); b.unshift(x + '% ' + (c + w).toFixed(1) + '%'); } return 'polygon(' + t.concat(b).join(',') + ')'; }
  var CLIP = { burst: poly(14, 50, 41), star: poly(5, 50, 21), heart: heartPoly(), blob: blobPoly(), silk: silkPoly(),
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
    // study / visa / travel (Planet Careers, 16 Nov 2026)
    plane: ['Plane', '<path fill="currentColor" d="M21.5 15.2v-1.9L13.6 8.4V3.2a1.6 1.6 0 0 0-3.2 0v5.2l-7.9 4.9v1.9l7.9-2.4v5.1l-2.2 1.6v1.5l3.8-1.1 3.8 1.1v-1.5l-2.2-1.6v-5.1z"/>'],
    passport: ['Passport', '<path fill="currentColor" fill-rule="evenodd" d="M5.5 2h12A1.5 1.5 0 0 1 19 3.5v17a1.5 1.5 0 0 1-1.5 1.5h-12A1.5 1.5 0 0 1 4 20.5v-17A1.5 1.5 0 0 1 5.5 2zM11.5 6.2a4.3 4.3 0 1 0 0 8.6 4.3 4.3 0 0 0 0-8.6zm0 1.4c.5.7.9 1.7 1 2.9H10.5c.1-1.2.5-2.2 1-2.9zm-1.6.3a6.4 6.4 0 0 0-.7 2.6H7.9a3 3 0 0 1 2-2.6zm3.2 0a3 3 0 0 1 2 2.6h-1.3a6.4 6.4 0 0 0-.7-2.6zM7.9 11.6h1.3c.1 1 .3 1.9.7 2.6a3 3 0 0 1-2-2.6zm2.6 0h2c-.1 1.2-.5 2.2-1 2.9-.5-.7-.9-1.7-1-2.9zm3.3 0h1.3a3 3 0 0 1-2 2.6c.4-.7.6-1.6.7-2.6zM8 17h7v1.4H8z"/>'],
    globe: ['Globe', '<g' + SW + ' stroke-width="1.8"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.7 2.6 4 5.8 4 9.5s-1.3 6.9-4 9.5c-2.7-2.6-4-5.8-4-9.5s1.3-6.9 4-9.5zM4.2 7h15.6M4.2 17h15.6"/></g>'],
    grad: ['Graduation cap', '<path fill="currentColor" d="M12 3 1 8.5 12 14l9-4.5V16h2V8.5z"/><path fill="currentColor" d="M5.5 12.2v4.3c0 1.9 2.9 3.5 6.5 3.5s6.5-1.6 6.5-3.5v-4.3L12 15.5z"/>'],
    brief: ['Briefcase', '<path fill="currentColor" fill-rule="evenodd" d="M9 3h6a1.5 1.5 0 0 1 1.5 1.5V6H20a2 2 0 0 1 2 2v4.2a3 3 0 0 1-2 2.8l-6 1.6v-.8a1 1 0 0 0-1-1h-2a1 1 0 0 0-1 1v.8L4 15a3 3 0 0 1-2-2.8V8a2 2 0 0 1 2-2h3.5V4.5A1.5 1.5 0 0 1 9 3zm.5 1.8V6h5V4.8zM2 15.7c.5.4 1.2.7 1.9.9l6.1 1.6V19a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1v-.8l6.1-1.6c.7-.2 1.4-.5 1.9-.9V19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"/>'],
    home: ['Home', '<path fill="currentColor" d="M12 2.6 1.5 11.2l1.3 1.6L4 11.8V21h6v-6h4v6h6v-9.2l1.2 1 1.3-1.6z"/>'],
    book: ['Book', '<g' + SW + '><path d="M3 4.5C6 3.6 9 3.8 12 6c3-2.2 6-2.4 9-1.5v14.4c-3-.9-6-.7-9 1.5-3-2.2-6-2.4-9-1.5zM12 6v14.4"/></g>'],
    cal: ['Calendar', '<g' + SW + '><rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/></g><path fill="currentColor" d="M7 12.5h3v3H7z"/>'],
    mic: ['Microphone', '<g' + SW + '><rect x="9" y="2.5" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5v4M8.5 21.5h7"/></g>'],
    pin: ['Map pin', '<path fill="currentColor" fill-rule="evenodd" d="M12 1.8a7.6 7.6 0 0 1 7.6 7.6c0 5.4-7.6 12.8-7.6 12.8S4.4 14.8 4.4 9.4A7.6 7.6 0 0 1 12 1.8zm0 4.4a3.2 3.2 0 1 0 0 6.4 3.2 3.2 0 0 0 0-6.4z"/>'],
    phone: ['Phone', '<path fill="currentColor" d="M6.6 2.5 9.4 6c.5.6.5 1.4 0 2L8 9.6a12.3 12.3 0 0 0 6.4 6.4l1.6-1.4c.6-.5 1.4-.5 2 0l3.5 2.8c.6.5.7 1.4.2 2l-1.5 2c-.6.8-1.6 1.1-2.6.9C9.9 20.6 3.4 14.1 1.7 6.4c-.2-1 .1-2 .9-2.6l2-1.5c.6-.5 1.5-.4 2 .2z"/>'],
    chat: ['Chat', '<path fill="currentColor" d="M12 2.5C6.5 2.5 2 6.4 2 11.3c0 2.6 1.3 4.9 3.3 6.5L4.5 21.5l4.3-2.2c1 .3 2.1.4 3.2.4 5.5 0 10-3.9 10-8.4S17.5 2.5 12 2.5z"/>'],
    mail: ['Email', '<g' + SW + '><rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 7 9-7"/></g>'],
    doc: ['Document', '<g' + SW + '><path d="M6 2.5h8l5 5v14H6zM14 2.5v5h5M9 12h7M9 15.5h7M9 19h4"/></g>'],
    award: ['Award', '<g' + SW + '><circle cx="12" cy="9" r="6.5"/><path d="m8.5 14.5-1.8 7 5.3-2.6 5.3 2.6-1.8-7"/></g><path fill="currentColor" d="m12 5.6 1 2.1 2.3.3-1.7 1.6.4 2.3-2-1.1-2 1.1.4-2.3L8.7 8l2.3-.3z"/>'],
    lang: ['Language', '<g' + SW + '><path d="M2.5 5h9M7 3v2c0 4.5-2 7.6-4.5 9M4.8 8.6c1.2 2.4 3.4 4.3 6.2 5.4M12 21l4.5-11 4.5 11M13.6 17h5.8"/></g>'],
    shield: ['Shield check', '<path fill="currentColor" fill-rule="evenodd" d="M12 1.5 3.5 4.8v6.1c0 5.3 3.6 10 8.5 11.6 4.9-1.6 8.5-6.3 8.5-11.6V4.8zm4.4 7.3-5.6 6.3-3.3-3.1 1.3-1.4 1.9 1.8 4.3-4.9z"/>'],
    xmark: ['Cross', '<g' + SW + ' stroke-width="2.6"><path d="M6 6l12 12M18 6 6 18"/></g>'],
    route: ['Route', '<g' + SW + ' stroke-dasharray="2.2 2.4"><path d="M5 19C5 12 19 13 19 6"/></g><circle cx="5" cy="19" r="2.4" fill="currentColor"/><path fill="currentColor" d="M19 1.5a3.6 3.6 0 0 1 3.6 3.6c0 2.6-3.6 6.2-3.6 6.2s-3.6-3.6-3.6-6.2A3.6 3.6 0 0 1 19 1.5z"/>'],
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
    ['fi', 'up', 'hide', 'lock', 'nw', 'ph'].forEach(function (b) { if (e[b] === true) o[b] = true; });
    NUMP.forEach(function (q) { if (e[q[0]] != null) { var n = num(e[q[0]], q[1], q[2]); if (n !== undefined) o[q[0]] = n; } });
    ['col', 'fill', 'fill2', 'bc', 'shc'].forEach(function (c) { var v = col(e[c]); if (v) o[c] = v; });
    if (typeof e.gp === 'string' && ID.test(e.gp) && e.t !== 'prods') o.gp = e.gp;
    if (e.al === 'l' || e.al === 'c' || e.al === 'r') o.al = e.al;
    if (has(SHAPES, e.sh)) o.sh = e.sh;
    if (e.bs === 'solid' || e.bs === 'dashed' || e.bs === 'dotted') o.bs = e.bs;
    if (has(ICONS, e.ic)) o.ic = e.ic;
    if (e.pay === 'both' || e.pay === 'tabby' || e.pay === 'tamara') o.pay = e.pay;
    if (e.cr === 'acc' || e.cr === 'acc2' || e.cr === 'card') o.cr = e.cr;
    if (e.tr === 'acc') o.tr = e.tr;
    if (e.t === 'img') { if (!IMGID.test(e.img) || (imgs && !has(imgs, e.img))) return null; o.img = e.img; }
    if (e.t === 'art') { if (typeof e.art !== 'string' || !ARTID.test(e.art)) return null; o.art = e.art; }
    if (['c1', 'c2', 'c3', 'ca'].indexOf(e.cy) >= 0) o.cy = e.cy;                 // takes the country's flag colour
    if (typeof e.cyk === 'string' && /^[nda]{1,3}$/.test(e.cyk)) o.cyk = e.cyk;     // holds the country's name
    // Work Abroad: wk = a worker photo slot; ock = holds the occupation's words (o title, r Arabic, l "a welder", p / q plural,
    // 0-2 tool names); oti = shows the occupation's tool icon n; oy = takes the occupation's colour
    if (e.wk === true) o.wk = true;
    if (typeof e.ock === 'string' && /^[orlpq012]{1,8}$/.test(e.ock)) o.ock = e.ock;
    if (e.t === 'art' && (e.oti === 0 || e.oti === 1 || e.oti === 2)) o.oti = e.oti;
    if (['u', 't', 'p', 'a'].indexOf(e.oy) >= 0) o.oy = e.oy;
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
    var d = { v: 1, id: typeof raw.id === 'string' && IMGID.test(raw.id) ? raw.id : uid('d'), name: str(raw.name, 60) || 'Untitled design', tpl: typeof raw.tpl === 'string' && /^[a-z0-9-]{1,40}$/.test(raw.tpl) ? raw.tpl : '', els: [], geo: {}, sets: {} };
    if (typeof raw.co === 'string' && /^[a-z0-9-]{1,40}$/.test(raw.co)) d.co = raw.co;
    if (typeof raw.cty === 'string' && KT.CTRY[raw.cty]) d.cty = raw.cty;
    if (typeof raw.occ === 'string' && has(KT.OCC, raw.occ)) d.occ = raw.occ;
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
    d.bg = cleanBg(raw.bg, imgs);
    // groups: members carry gp; a group needs two members and its members sit together in the stack
    var rg = raw.groups && typeof raw.groups === 'object' && !Array.isArray(raw.groups) ? raw.groups : {};
    d.groups = {};
    d.els.forEach(function (e) { if (e.gp && !has(d.groups, e.gp)) d.groups[e.gp] = { name: str(has(rg, e.gp) && rg[e.gp] ? rg[e.gp].name : '', 40) || 'Group' }; });
    normGroups(d);
    var a = raw.anim || {};
    d.anim = { in: ['fade', 'pop', 'up', 'none'].indexOf(a.in) >= 0 ? a.in : 'fade', idle: ['none', 'float', 'sway'].indexOf(a.idle) >= 0 ? a.idle : 'none', sec: [1.5, 2, 2.5, 3, 4, 5, 6].indexOf(+a.sec) >= 0 ? +a.sec : 3, len: [5, 10, 15, 20, 30].indexOf(+a.len) >= 0 ? +a.len : 10 };
    return d;
  }
  // a background: kind + colours, gradient stops, radial centre, picture fit/position/scale/filters/overlay, pattern
  function cleanBg(b, imgs) {
    b = b && typeof b === 'object' ? b : {};
    var o = { k: BGK.indexOf(b.k) >= 0 ? b.k : 'radial', c1: col(b.c1, '#FFFFFF'), c2: col(b.c2, '#EEEEEE'), ang: num(b.ang, 0, 360, 160), pat: has(PATS, b.pat) ? b.pat : 'none', pc: col(b.pc, '#000000'), po: num(b.po, 0, 100, 12), img: IMGID.test(b.img) && (!imgs || has(imgs, b.img)) ? b.img : '', ov: num(b.ov, 0, 90, 0),
      rx: num(b.rx, 0, 100, 50), ry: num(b.ry, 0, 100, 38), fit: FITS.indexOf(b.fit) >= 0 ? b.fit : 'cover', ix: num(b.ix, 0, 100, 50), iy: num(b.iy, 0, 100, 50), isc: num(b.isc, 20, 400, 100),
      blur: num(b.blur, 0, 20, 0), bri: num(b.bri, 0, 200, 100), con: num(b.con, 0, 200, 100), sat: num(b.sat, 0, 200, 100), oc: col(b.oc, '') };
    if (Array.isArray(b.stops)) { var s = b.stops.slice(0, 8).map(function (x) { return x && HEX.test(x.c) ? { c: x.c, p: num(x.p, 0, 100, 0) } : null; }).filter(Boolean).sort(function (a, c) { return a.p - c.p; }); if (s.length >= 2) o.stops = s; }
    if (o.k === 'image' && !o.img) o.k = 'radial';
    return o;
  }
  // a group's members sit together in the stack (at the place of its top member); lone or empty groups dissolve
  function normGroups(d) {
    if (!d.groups || typeof d.groups !== 'object') d.groups = {};
    var cnt = Object.create(null);
    d.els.forEach(function (e) { if (e.gp) cnt[e.gp] = (cnt[e.gp] || 0) + 1; });
    d.els.forEach(function (e) { if (e.gp && (cnt[e.gp] < 2 || e.t === 'prods')) delete e.gp; });
    Object.keys(d.groups).forEach(function (g) { if (!(cnt[g] >= 2)) delete d.groups[g]; });
    d.els.forEach(function (e) { if (e.gp && !has(d.groups, e.gp)) d.groups[e.gp] = { name: 'Group' }; });
    var out = [], seen = Object.create(null);
    for (var i = d.els.length - 1; i >= 0; i--) {
      var e = d.els[i]; if (!e.gp) { out.push(e); continue; } if (seen[e.gp]) continue; seen[e.gp] = 1;
      var m = d.els.filter(function (x) { return x.gp === e.gp; }); for (var j = m.length - 1; j >= 0; j--) out.push(m[j]);
    }
    d.els = out.reverse();
  }
  function coOf(v) { return typeof v === 'string' && /^[a-z0-9-]{1,40}$/.test(v) ? v : 'kbb'; }
  function cleanBrand(b) { b = b || {}; return { c1: HEX.test(b.c1) ? b.c1.slice(0, 7) : '#E8306F', c2: HEX.test(b.c2) ? b.c2.slice(0, 7) : '#1F1B1E', logo: IMGID.test(b.logo) ? b.logo : '', on: b.on === true }; }

  // ── state ──
  var tplById = {}; KT.LIST.forEach(function (t) { tplById[t.id] = t; });
  var images = Object.create(null);   // id -> {name, aspect, kind: prod|bg|logo|img, src} (no prototype: ids come from storage)
  var brand = cleanBrand({});
  var doc = null, st = { fmt: 'post', set: 0, sel: null, psel: null, ms: [], grp: null, pick: false, alto: 'sel', gopen: Object.create(null), gsi: 0, bgv: null, tab: 'tpl', cat: 'all', q: '' };
  var lastSaved = '', hist = [], fut = [], pending = null;

  // ── companies (the owner, 16 Nov 2026: "create an environment 'Planet Careers' ... allow me options to create any
  //    new company etc, and i will be able to switch between one to another"). A company is a brand kit in the
  //    shape of studio/reel-studio/brands/kit.schema.json, plus what this studio keeps for it (studio: its
  //    template set, favourite templates and brand-kit switches). KBB and Planet Careers are seeded from their
  //    kit.json; every company's designs carry its id (doc.co). Stored in kv 'companies' / 'company'. ──
  var SLUG = /^[a-z0-9-]{1,40}$/, TSETS = { kbb: 'Beauty & retail (KBB)', 'planet-careers': 'Study & visa consultancy (Planet Careers)' };
  function fontKey(f, d) { var fam = String(f && f.family || f || '').toLowerCase(); var k = Object.keys(FONTS).filter(function (x) { return FONTS[x].toLowerCase() === fam || x === fam; })[0]; return k || d; }
  function strs(a, n, len) { return (Array.isArray(a) ? a : []).filter(function (x) { return typeof x === 'string'; }).slice(0, n).map(function (x) { return x.slice(0, len); }); }
  function cleanCompany(raw, base) {
    raw = raw && typeof raw === 'object' ? raw : {}; base = base || {};
    var c = raw.colors || {}, bc = base.colors || {}, ec = raw.endCard || {}, ct = raw.contact || {}, stu = raw.studio || {}, fo = raw.fonts || {};
    function hx(v, d) { return HEX.test(v) ? v.slice(0, 7).toUpperCase() : d; }
    var o = { v: 1, id: SLUG.test(raw.id) ? raw.id : base.id || uid('co').toLowerCase(), name: str(raw.name, 40).trim() || base.name || 'New company', fullName: str(raw.fullName, 60).trim(), industry: str(raw.industry, 80), industries: strs(raw.industries, 8, 30),
      colors: { primary: hx(c.primary, bc.primary || '#002768'), secondary: hx(c.secondary, bc.secondary || '#BE0B32'), accent: hx(c.accent, bc.accent || '#BE0B32'), text: hx(c.text, '#FFFFFF'), bg: hx(c.bg, hx(c.primary, '#002768')), soft: hx(c.soft, bc.soft || '#EFF4FA'), ink: hx(c.ink, bc.ink || '#002768'), slate: hx(c.slate, bc.slate || '#556171') },
      fonts: { heading: { family: FONTS[fontKey(fo.heading, 'afacad')] }, body: { family: FONTS[fontKey(fo.body, 'afacad')] }, arabic: { family: 'Cairo' } },
      logos: { mark: typeof (raw.logos || {}).mark === 'string' && ARTID.test(raw.logos.mark) && raw.logos.mark.indexOf('mark:') === 0 ? raw.logos.mark : '' },
      contact: {}, contactLine: str(raw.contactLine, 120),
      endCard: { title: str(ec.title, 60), sub: str(ec.sub, 120), cta: strs(ec.cta, 5, 60), ctaAr: strs(ec.ctaAr, 5, 60), payments: strs(ec.payments, 4, 60).map(function (p) { return /tamara/i.test(p) ? 'tamara' : /tabby/i.test(p) ? 'tabby' : ''; }).filter(function (p, i, a) { return p && a.indexOf(p) === i; }) },
      defaults: { aspect: ['9:16', '1:1', '4:5', '16:9'].indexOf((raw.defaults || {}).aspect) >= 0 ? raw.defaults.aspect : '4:5' },
      studio: { tplSet: has(TSETS, stu.tplSet) ? stu.tplSet : base.studio ? base.studio.tplSet : 'planet-careers', favs: strs(stu.favs, 200, 40).filter(function (id) { return has(tplById, id); }), brand: cleanBrand(stu.brand || base.studio && base.studio.brand), builtin: !!base.builtin } };
    if (raw.gradient) o.gradient = strs(raw.gradient, 4, 9).filter(function (x) { return HEX.test(x); });
    ['website', 'phone', 'whatsapp', 'email', 'instagram', 'tiktok', 'address'].forEach(function (k) { var v = str(ct[k], 80).trim(); if (v) o.contact[k] = v; });
    o.mark = o.logos.mark; return o;
  }
  // the two seeds, from their kit.json (KBB: the lotus logo and tabby / tamara; Planet Careers: its mark, no pay badges)
  var SEEDS = {};
  (function () {
    var k = D.kits || {};
    SEEDS.kbb = cleanCompany(Object.assign({}, k.kbb || { id: 'kbb', name: 'KBB', fullName: 'K-Beauty Bliss' }, { studio: { tplSet: 'kbb', brand: {} } }), { id: 'kbb', builtin: true, studio: { tplSet: 'kbb' } });
    SEEDS.kbb.studio.builtin = true;
    var pc = Object.assign({}, k['planet-careers'] || { id: 'planet-careers', name: 'Planet Careers' }); pc.logos = { mark: 'mark:pc' }; pc.endCard = Object.assign({}, pc.endCard, { payments: [] });
    pc.studio = { tplSet: 'planet-careers', brand: { on: false } };
    SEEDS['planet-careers'] = cleanCompany(pc, { id: 'planet-careers', builtin: true, studio: { tplSet: 'planet-careers' } }); SEEDS['planet-careers'].studio.builtin = true;
  })();
  var cos = [SEEDS.kbb, SEEDS['planet-careers']], co = SEEDS.kbb;
  function coById(id) { return cos.filter(function (c) { return c.id === id; })[0] || null; }
  function saveCos() { return Store.putKV('companies', cos.map(function (c) { var o = clone(c); if (c.id === 'kbb') delete o.studio.brand; return o; })).catch(function () {}); }

  // ── storage adapter ──
  var Store = (function () {
    var cdb = null, cas = null, cdl = null, idb = null, mem = { projects: {}, images: {}, kv: {} }, kind = 'memory';
    // inside KBB Studio (src/kbbdisk.js): the owner's data folder through the engine is where things live; IndexedDB
    // is kept as a cache (written alongside, never read while the disk answers)
    var dk = null, dkMeta = null;   // dkMeta: the saved designs' names / dates / companies, read from disk once per visit
    function cache(store, fn) { if (idb) tx(store, 'readwrite', fn).catch(function () {}); }
    function dkAll(dir) { return dk.list(dir).then(function (a) { return Promise.all(a.filter(function (e) { return /\.json$/.test(e.name); }).map(function (e) { return dk.getJSON(dir, e.name).catch(function () { return null; }); })); }).then(function (a) { return a.filter(Boolean); }); }
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
          return cdb || !window.KBB_DISK ? kind : window.KBB_DISK.init().then(function (on) { if (on) { dk = window.KBB_DISK; kind = 'disk'; } return kind; });
        });
      },
      listProjects: function () {
        if (cdb) return cdb.collection('projects').get().then(function (q) { return q.docs.map(function (s) { var v = s.data() || {}; return { id: s.id, name: str(v.name, 60), updated: +v.updated || 0, co: coOf(v.co) }; }); });
        if (dk) {
          dkMeta = dkMeta || dkAll('projects').then(function (a) { var m = Object.create(null); a.forEach(function (p) { if (IMGID.test(p.id)) m[p.id] = { id: p.id, name: str(p.name, 60), updated: +p.updated || 0, co: coOf(p.co || p.doc && p.doc.co) }; }); return m; }).catch(function (e) { dkMeta = null; throw e; });
          return dkMeta.then(function (m) { return Object.keys(m).map(function (k) { return Object.assign({}, m[k]); }); });
        }
        if (idb) return tx('projects', 'readonly', function (s) { return s.getAll(); }).then(function (a) { return (a || []).map(function (p) { return { id: p.id, name: str(p.name, 60), updated: +p.updated || 0, co: coOf(p.co || p.doc && p.doc.co) }; }); });
        var src = kind === 'local' ? (lsGet('projects') || {}) : mem.projects;
        return Promise.resolve(Object.keys(src).map(function (k) { return { id: k, name: str(src[k].name, 60), updated: +src[k].updated || 0, co: coOf(src[k].co || src[k].doc && src[k].doc.co) }; }));
      },
      getProject: function (id) {
        if (cdb) return cdb.doc('projects/' + id).get().then(function (s) { if (!s.exists) return null; try { return JSON.parse(s.data().json); } catch (e) { return null; } });
        if (dk) return IMGID.test(id) ? dk.getJSON('projects', id + '.json').then(function (p) { return p ? p.doc : null; }) : Promise.resolve(null);
        if (idb) return tx('projects', 'readonly', function (s) { return s.get(id); }).then(function (p) { return p ? p.doc : null; });
        var src = kind === 'local' ? (lsGet('projects') || {}) : mem.projects; return Promise.resolve(src[id] ? src[id].doc : null);
      },
      putProject: function (d) {
        var rec = { id: d.id, name: d.name, updated: Date.now(), co: coOf(d.co) };
        if (cdb) return cdb.doc('projects/' + d.id).set({ name: d.name, updated: rec.updated, co: rec.co, json: JSON.stringify(d) });
        if (dk) { if (!IMGID.test(d.id)) return Promise.reject(new Error('That design has no proper id')); Object.assign(rec, { doc: d }); return dk.put('projects', d.id + '.json', rec).then(function () { if (dkMeta) dkMeta.then(function (m) { m[d.id] = { id: d.id, name: str(d.name, 60), updated: rec.updated, co: rec.co }; }, function () {}); cache('projects', function (s) { return s.put(rec); }); }); }
        if (idb) return tx('projects', 'readwrite', function (s) { return s.put(Object.assign(rec, { doc: d })); });
        if (kind === 'local') { var all = lsGet('projects') || {}; all[d.id] = Object.assign(rec, { doc: d }); if (!lsSet('projects', all)) return Promise.reject(new Error('This browser is out of space')); return Promise.resolve(); }
        mem.projects[d.id] = Object.assign(rec, { doc: d }); return Promise.resolve();
      },
      delProject: function (id) {
        if (cdb) return cdb.doc('projects/' + id).delete();
        if (dk) return (IMGID.test(id) ? dk.del('projects', id + '.json') : Promise.resolve()).then(function () { if (dkMeta) dkMeta.then(function (m) { delete m[id]; }, function () {}); cache('projects', function (s) { return s.delete(id); }); });
        if (idb) return tx('projects', 'readwrite', function (s) { return s.delete(id); });
        if (kind === 'local') { var all = lsGet('projects') || {}; delete all[id]; lsSet('projects', all); } else delete mem.projects[id];
        return Promise.resolve();
      },
      listImages: function () {
        var metas;
        if (cdb && cas) return cdb.collection('images').get().then(function (q) { return q.docs.filter(function (s) { return IMGID.test(s.id); }).map(function (s) { var v = s.data() || {}; return { id: s.id, name: str(v.name, 60), aspect: num(v.aspect, .05, 20, 1), kind: v.kind, src: '/_blob/' + s.id }; }); });
        if (dk) return dkAll('images').then(function (a) { return a.filter(function (r) { return IMGID.test(r.id) && typeof r.data === 'string' && DATAURL.test(r.data); }).map(function (r) { return { id: r.id, name: str(r.name, 60), aspect: num(r.aspect, .05, 20, 1), kind: r.kind, src: r.data }; }); });
        if (idb) return tx('images', 'readonly', function (s) { return s.getAll(); }).then(function (a) {
          return Promise.all((a || []).map(function (r) { return (r.blob instanceof Blob ? blobToData(r.blob) : Promise.resolve(r.data)).then(function (src) { return { id: r.id, name: str(r.name, 60), aspect: num(r.aspect, .05, 20, 1), kind: r.kind, src: src }; }); }));
        });
        metas = kind === 'local' ? (lsGet('images') || {}) : mem.images;
        return Promise.resolve(Object.keys(metas).map(function (k) { var r = metas[k]; return { id: k, name: str(r.name, 60), aspect: num(r.aspect, .05, 20, 1), kind: r.kind, src: r.data }; }));
      },
      putImage: function (blob, meta) {
        if (cdb && cas) return cas.upload(blob).then(function (r) { return cdb.doc('images/' + r.id).set({ name: meta.name, aspect: meta.aspect, kind: meta.kind, added: Date.now() }).then(function () { return { id: r.id, src: '/_blob/' + r.id }; }); });
        var id = meta.id && IMGID.test(meta.id) ? meta.id : uid('i');      // a backup keeps its picture ids (no duplicates)
        return blobToData(blob).then(function (data) {
          if (dk) return dk.put('images', id + '.json', { id: id, name: meta.name, aspect: meta.aspect, kind: meta.kind, data: data }).then(function () { cache('images', function (s) { return s.put({ id: id, name: meta.name, aspect: meta.aspect, kind: meta.kind, blob: blob }); }); return { id: id, src: data }; });
          if (idb) return tx('images', 'readwrite', function (s) { return s.put({ id: id, name: meta.name, aspect: meta.aspect, kind: meta.kind, blob: blob }); }).then(function () { return { id: id, src: data }; });
          if (kind === 'local') { var all = lsGet('images') || {}; all[id] = { name: meta.name, aspect: meta.aspect, kind: meta.kind, data: data }; if (!lsSet('images', all)) return Promise.reject(new Error('This browser is out of space')); return { id: id, src: data }; }
          mem.images[id] = { name: meta.name, aspect: meta.aspect, kind: meta.kind, data: data }; return { id: id, src: data };
        });
      },
      delImage: function (id) {
        if (cdb && cas) return cdb.doc('images/' + id).delete().then(function () { return cas.delete(id); });
        if (dk) return (IMGID.test(id) ? dk.del('images', id + '.json') : Promise.resolve()).then(function () { cache('images', function (s) { return s.delete(id); }); });
        if (idb) return tx('images', 'readwrite', function (s) { return s.delete(id); });
        if (kind === 'local') { var all = lsGet('images') || {}; delete all[id]; lsSet('images', all); } else delete mem.images[id];
        return Promise.resolve();
      },
      getKV: function (k) {
        if (cdb) return cdb.doc('kv/' + k).get().then(function (s) { if (!s.exists) return null; try { return JSON.parse(s.data().json); } catch (e) { return null; } });
        if (dk) return dk.getJSON('kv', dk.key(k)).then(function (r) { return r ? r.v : null; });
        if (idb) return tx('kv', 'readonly', function (s) { return s.get(k); }).then(function (r) { return r ? r.v : null; });
        return Promise.resolve(kind === 'local' ? lsGet('kv:' + k) : mem.kv[k] || null);
      },
      putKV: function (k, v) {
        if (cdb) return cdb.doc('kv/' + k).set({ json: JSON.stringify(v) });
        if (dk) return dk.put('kv', dk.key(k), { v: v }).then(function () { cache('kv', function (s) { return s.put({ id: k, v: v }); }); });
        if (idb) return tx('kv', 'readwrite', function (s) { return s.put({ id: k, v: v }); });
        if (kind === 'local') lsSet('kv:' + k, v); else mem.kv[k] = v; return Promise.resolve();
      },
      // a draft of the design on screen: this browser only, survives a reload before Save
      // (one draft per company: KBB keeps the original key)
      draft: function (d, c) { var k = !c || c === 'kbb' ? 'draft' : 'draft:' + c; if (dk) dk.put('kv', dk.key(k), { v: d }).catch(function () {}); try { localStorage.setItem('kbbas:' + k, JSON.stringify(d)); } catch (e) { if (idb) tx('kv', 'readwrite', function (s) { return s.put({ id: k, v: d }); }).catch(function () {}); } },
      readDraft: function (c) {
        var k = !c || c === 'kbb' ? 'draft' : 'draft:' + c, d = null; try { d = JSON.parse(localStorage.getItem('kbbas:' + k)); } catch (e) {}
        if (!d && dk) return dk.getJSON('kv', dk.key(k)).then(function (r) { return r ? r.v : null; }).catch(function () { return null; });
        if (d || !idb) return Promise.resolve(d);
        return tx('kv', 'readonly', function (s) { return s.get(k); }).then(function (r) { return r ? r.v : null; }).catch(function () { return null; });
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
  function aspectOf(p) { return D.prods[p] ? D.prods[p].a : (images[p.slice(2)] ? images[p.slice(2)].aspect : .5); }
  function itemFromSlot(s, p) { var a = aspectOf(p), h = s.h, w = h * a; if (w > s.mw) { w = s.mw; h = w / a; } return { p: p, x: r2(s.cx - w / 2), y: r2(s.by - h), w: r2(w), r: s.r || 0, sh: 55, ss: 'soft' }; }
  function brandPal(P) {
    if (!brand.on) return P;
    var Q = Object.assign({}, P); Q.acc = brand.c1; Q.acc2 = brand.c2; Q.rib = brand.c1; Q.badge = brand.c1; Q.btnTxt = contrast(brand.c1); Q.badgeTxt = contrast(brand.c1); Q.bandTxt = contrast(brand.c1); Q.ribTxt = contrast(brand.c1);
    Q.disc = brand.c1 + '2E'; return Q;
  }
  function contrast(hex) { var n = parseInt(hex.slice(1, 7), 16), r = n >> 16 & 255, g = n >> 8 & 255, b = n & 255; return (r * 299 + g * 587 + b * 114) / 1000 > 160 ? '#111111' : '#FFFFFF'; }
  // c = the company it is made for (default: the one open), cty = the country (country templates only)
  function fromTemplate(t, c, cty, occ) {
    c = c || co;
    var kit = t.co === 'pc', C = kit ? KT.CTRY[cty] || KT.CTRY[t.cty] || null : null;
    var OC = kit && t.occ ? (has(KT.OCC, occ) ? KT.OCC[occ] : KT.OCC[t.occ]) : null;     // Work Abroad: the occupation
    var P = kit ? KT.kitPal(c.colors, fontKey(c.fonts.heading, 'afacad'), fontKey(c.fonts.body, 'afacad')) : c.id === 'kbb' ? brandPal(t.P) : brandPal(t.P);
    var d = { v: 1, id: uid('d'), name: t.name, tpl: t.id, els: [], geo: {}, sets: {}, anim: { in: 'fade', idle: 'none', sec: 3, len: 10 } };
    FMTS.forEach(function (f, fi) {
      var r = KT.FAM[t.fam](f, t.T, P, t.O, C, OC);
      if (fi === 0) { d.bg = r.bg; d.els = r.els.map(function (e) { var x = Object.assign({}, e); delete x.g; return x; }); }
      d.geo[f] = {};
      r.els.forEach(function (e, i) {
        var g = Object.assign({}, e.g), p = d.els[i];       // a family may size or align one element differently per size
        if (p && p.id === e.id) { if (e.fs && p.fs && e.fs !== p.fs) g.k = (g.k || 1) * e.fs / p.fs; if ((e.al || 'c') !== (p.al || 'c')) g.al = e.al || 'c'; }
        d.geo[f][e.id] = g;
      });
      var n = r.slots.length;
      d.sets[f] = [0, 1, 2].map(function (s) { return r.slots.map(function (sl, j) { return itemFromSlot(sl, t.pp[(s * n + j) % t.pp.length]); }); });
    });
    if (kit) { if (C) d.cty = C.code; if (OC) d.occ = OC.id; d.els.forEach(function (e) { if (OC) KT.occTokens(e, OC); KT.tokens(e, C, c); }); }
    if (c.id !== 'kbb') {
      // another company: its name in the logo spot, its contact line, no pay badges unless its kit has them
      d.els.forEach(function (e) {
        if (e.t === 'logo') { e.txt = c.fullName || c.name; if (!kit) e.fn = fontKey(c.fonts.heading, e.fn); }
        if (e.t === 'pay') { var py = c.endCard.payments; if (!py.length) e.hide = true; else e.pay = py.length > 1 ? 'both' : py[0]; }
      });
    }
    d.co = c.id;
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
      case 'petals': return ['url("data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><g fill="' + c + '" transform="translate(20 20)">' + [0, 72, 144, 216, 288].map(function (a) { return '<ellipse rx="3.4" ry="7.2" cy="-7.6" transform="rotate(' + a + ')"/>'; }).join('') + '</g></svg>') + '")', '9cqw 9cqw'];
      case 'globe': return ['url("data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><g fill="none" stroke="' + c + '" stroke-width=".7"><circle cx="100" cy="100" r="96"/><ellipse cx="100" cy="100" rx="40" ry="96"/><ellipse cx="100" cy="100" rx="74" ry="96"/><path d="M100 4v192M4 100h192M14 52h172M14 148h172"/></g></svg>') + '")', '60cqw 60cqw'];
      case 'routes': return ['url("data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><g fill="none" stroke="' + c + '" stroke-width=".9" stroke-dasharray="3 4"><path d="M10 160C60 60 140 40 190 70"/><path d="M20 30C80 70 120 150 195 140"/></g><g fill="' + c + '"><circle cx="10" cy="160" r="2.5"/><circle cx="190" cy="70" r="2.5"/><circle cx="20" cy="30" r="2.5"/><circle cx="195" cy="140" r="2.5"/></g></svg>') + '")', '50cqw 50cqw'];
      case 'sparkle': return ['radial-gradient(circle at 12% 18%,' + c + ' .35cqw,transparent .5cqw),radial-gradient(circle at 78% 9%,' + c + ' .5cqw,transparent .7cqw),radial-gradient(circle at 88% 62%,' + c + ' .3cqw,transparent .45cqw),radial-gradient(circle at 30% 76%,' + c + ' .45cqw,transparent .6cqw),radial-gradient(circle at 55% 40%,' + c + ' .25cqw,transparent .4cqw),radial-gradient(circle at 6% 52%,' + c + ' .3cqw,transparent .45cqw),radial-gradient(circle at 64% 90%,' + c + ' .35cqw,transparent .5cqw)', '100% 60cqw'];
    }
    return null;
  }
  function gradList(bg) { return bg.stops && bg.stops.length > 1 ? bg.stops.map(function (s) { return s.c + ' ' + s.p + '%'; }).join(',') : bg.c1 + ',' + bg.c2; }
  function gradStops(bg) { return bg.stops && bg.stops.length > 1 ? bg.stops : [{ c: bg.c1, p: 0 }, { c: bg.c2, p: 100 }]; }
  function bgPaint(layer, bg) {
    var s = layer.style;
    if (bg.k === 'image' && images[bg.img]) {
      s.backgroundColor = bg.c1;
      var im = mk('i', 'bgi'), q = im.style, f = [];
      q.backgroundImage = 'url("' + images[bg.img].src + '")'; q.backgroundPosition = bg.ix + '% ' + bg.iy + '%';
      if (bg.fit === 'tile') { q.backgroundSize = (bg.isc * .3).toFixed(2) + 'cqw auto'; q.backgroundRepeat = 'repeat'; }
      else { q.backgroundSize = bg.fit; q.backgroundRepeat = 'no-repeat'; if (bg.isc !== 100) { q.transform = 'scale(' + bg.isc / 100 + ')'; q.transformOrigin = bg.ix + '% ' + bg.iy + '%'; } }
      if (bg.blur) { f.push('blur(' + (bg.blur * .1).toFixed(2) + 'cqw)'); q.inset = (-bg.blur * .3).toFixed(2) + 'cqw'; }
      if (bg.bri !== 100) f.push('brightness(' + bg.bri + '%)'); if (bg.con !== 100) f.push('contrast(' + bg.con + '%)'); if (bg.sat !== 100) f.push('saturate(' + bg.sat + '%)');
      if (f.length) q.filter = f.join(' ');
      layer.appendChild(im);
      var oc = bg.oc && bg.oc !== 'transparent' ? bg.oc : bg.ov ? 'rgba(0,0,0,' + bg.ov / 100 + ')' : '';
      if (oc) { var o = mk('i', 'bgo'); o.style.background = oc; layer.appendChild(o); }
    }
    else if (bg.k === 'none') s.background = 'transparent';
    else if (bg.k === 'solid') s.background = bg.c1;
    else if (bg.k === 'linear') s.background = 'linear-gradient(' + bg.ang + 'deg,' + gradList(bg) + ')';
    else s.background = 'radial-gradient(120% 90% at ' + bg.rx + '% ' + bg.ry + '%,' + gradList(bg) + ')';
  }
  function rgbaOf(c, a) { var n = parseInt(c.slice(1, 7), 16), k = c.length === 9 ? parseInt(c.slice(7), 16) / 255 : 1; return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + (a * k).toFixed(2) + ')'; }
  function shadowCss(a, c) { a = a / 100; return a ? 'drop-shadow(0 ' + (1.2 * a).toFixed(2) + 'cqw ' + (1.8 * a).toFixed(2) + 'cqw ' + (c && HEX.test(c) ? rgbaOf(c, .38 * a) : 'rgba(0,0,0,' + (.38 * a).toFixed(2) + ')') + ')' : ''; }
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
      else if (k === 'glow') sf.style.background = e.fill === 'transparent' ? 'transparent' : 'radial-gradient(closest-side,' + e.fill + ',' + (e.fill2 || e.fill.slice(0, 7) + '00') + ')';
      else if (k === 'petal') sf.style.borderRadius = '100% 0';
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
    if (e.t === 'img') { var im = mk('img', 'ii'); im.alt = ''; im.draggable = false; if (images[e.img]) im.src = images[e.img].src; if (e.sh && e.sh !== 'none') crop(im, e, g); n.appendChild(im); }
    if (e.t === 'art') { var ai = mk('img', 'ii'); ai.alt = ''; ai.draggable = false; var as = artSrc(e.art, e.col); if (as) ai.src = as; if (e.sh && e.sh !== 'none' || e.art.indexOf('flag:') === 0) crop(ai, e, g); n.appendChild(ai); }
    if (e.t === 'pay') {
      var py = mk('div', 'py'); py.style.gap = (g.h * .35) + 'cqw';
      if (e.pay !== 'tamara') { var a1 = mk('img'); a1.src = D.pay.tabby; a1.alt = 'tabby'; py.appendChild(a1); }
      if (e.pay !== 'tabby') { var a2 = mk('img'); a2.src = D.pay.tamara; a2.alt = 'tamara'; py.appendChild(a2); }
      n.appendChild(py);
    }
    if (e.t === 'logo') {
      var lg = mk('div', 'lg'), al2 = g.al || e.al || 'c'; lg.style.justifyContent = al2 === 'l' ? 'flex-start' : al2 === 'r' ? 'flex-end' : 'center';
      if (brand.logo && images[brand.logo]) { var li = mk('img'); li.src = images[brand.logo].src; li.alt = ''; li.style.height = '2.4em'; lg.appendChild(li); }
      else if (co.id !== 'kbb') {
        // a company with its own full logo (Planet Careers: the site's logo, white-on-dark version on dark text colours) draws
        // that picture alone -- the name is already in it; otherwise its mark beside the name in the heading font
        var lt_ = lum6(e.col) > .6, full = co.mark ? artSrc(co.mark + '-full' + (lt_ ? '-light' : '')) : '';
        if (full) { var fi = mk('img'); fi.src = full; fi.alt = e.txt || co.fullName || co.name; fi.style.height = '2.8em'; lg.appendChild(fi); }
        else { var mk_ = co.mark ? artSrc(co.mark + (lt_ ? '-light' : '')) || artSrc(co.mark) : ''; if (mk_) { var mi = mk('img'); mi.src = mk_; mi.alt = ''; mi.style.height = '1.9em'; lg.appendChild(mi); } lg.appendChild(mk('span', null, e.txt || co.fullName || co.name)); }
      }
      else { var sv = mk('span'); sv.innerHTML = iconSvg('lotus'); var svg = sv.firstChild; svg.setAttribute('viewBox', '0 0 24 18'); lg.appendChild(svg); lg.appendChild(mk('span', null, e.txt || 'K-Beauty Bliss')); }
      n.appendChild(lg);
    }
    if (e.op != null && e.op < 100) n.style.opacity = e.op / 100;
    if (e.shd) n.style.filter = shadowCss(e.shd, e.shc);
    if (e.lock && mode === 'edit') n.classList.add('lk');
    placeEl(n, e, g);
    return n;
  }
  // a flag, landmark or photo cropped to its shape: fills its box (cover), round / arch / rounded corners or a clip
  function crop(im, e, g) {
    var q = im.style, k = e.sh || 'rect'; q.objectFit = 'cover';
    if (e.wk) q.objectPosition = '50% 18%';            // a worker photo keeps the head and the uniform when the slot crops it
    if (k === 'circle' || k === 'ellipse') q.borderRadius = '50%';
    else if (k === 'arch') q.borderRadius = (g.w / 2) + 'cqw ' + (g.w / 2) + 'cqw ' + (e.rad || 0) + 'cqw ' + (e.rad || 0) + 'cqw';
    else if (k === 'rect' || k === 'pill') q.borderRadius = k === 'pill' ? '999px' : (e.rad || 0) + 'cqw';
    else if (CLIP[k]) q.clipPath = CLIP[k];
    if (e.bw && !CLIP[k]) q.border = (e.bw / 10) + 'cqw ' + (e.bs || 'solid') + ' ' + (e.bc || '#FFFFFF');
  }
  function lum6(c) { if (!c || !HEX.test(c)) return 0; var n = parseInt(c.slice(1, 7), 16); return ((n >> 16 & 255) * 299 + (n >> 8 & 255) * 587 + (n & 255) * 114) / 255000; }
  // mode: 'edit' | 'thumb' | 'file' ; part: undefined (all) | 'under' | 'over' (video layers)
  function renderCanvas(d, f, set, mode, part) {
    var cv = mk('div', 'cv'); cv.style.aspectRatio = PX[f][0] + '/' + PX[f][1];
    if (mode === 'edit' && d.bg.k === 'none') cv.classList.add('clear');      // a see-through background shows as a checkerboard while editing
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
    stage.style.setProperty('--sw', st.fmt === 'story' ? '360px' : st.fmt === 'square' ? '520px' : '470px');   // phones (one column)
    zoomFmt();
    var cv = renderCanvas(doc, st.fmt, st.set, 'edit');
    stage.textContent = ''; stage.appendChild(cv); fitText(cv);
    markSel();
    chipsTop(); panelSel(); panelProd(); layers(); abarSync(); panelStyle(); panelMotion(); drawPk(); dirtyMark(); ctyBar();
    $('dl-size').textContent = PX[st.fmt][0] + ' × ' + PX[st.fmt][1] + ' px';
  }
  function chipsTop() {
    var fh = $('fmts'); fh.textContent = '';
    FMTS.forEach(function (f) { var b = mk('button', 'chip', FLAB[f]); b.type = 'button'; b.setAttribute('aria-pressed', String(f === st.fmt)); b.onclick = function () { stopPlay(); st.fmt = f; dropProdSel(); draw(); }; fh.appendChild(b); });
    var sh = $('sets'); sh.textContent = '';
    [0, 1, 2].forEach(function (i) { var b = mk('button', 'chip', 'Set ' + (i + 1)); b.type = 'button'; b.setAttribute('aria-pressed', String(i === st.set)); b.onclick = function () { stopPlay(); st.set = i; dropProdSel(); draw(); }; sh.appendChild(b); });
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
  function dropProdSel() { setSel(selKeys().filter(function (k) { return !isPk(k); })); }
  function afterJump() { if (st.grp && !members(st.grp).length) st.grp = null; dropProdSel(); $('pname').value = doc.name; draw(); undoBtns(); scheduleDraft(); }
  function undoBtns() { $('undo').disabled = !hist.length; $('redo').disabled = !fut.length; }
  var draftT = 0;
  function scheduleDraft() { clearTimeout(draftT); draftT = setTimeout(function () { Store.draft({ doc: doc, t: Date.now(), saved: lastSaved === JSON.stringify(doc) }, co.id); }, 500); }
  function dirtyMark() { var dirty = JSON.stringify(doc) !== lastSaved; $('save').textContent = dirty ? 'Save •' : 'Saved'; }

  // ── selection: one item or many. A key is an element id, or 'p:<i>' for product i of the set on show.
  //    st.sel / st.psel hold a single pick (what the panels edit); st.ms holds a multi-pick (2+ keys). ──
  function isPk(k) { return k.indexOf('p:') === 0; }
  function elById(id) { for (var i = 0; i < doc.els.length; i++) if (doc.els[i].id === id) return doc.els[i]; return null; }
  function keyOk(k) { return isPk(k) ? /^p:\d{1,3}$/.test(k) && !!curItems()[+k.slice(2)] : !!elById(k); }
  function selKeys() { if (st.ms.length > 1) return st.ms.slice(); if (st.sel) return [st.sel]; if (st.psel != null) return ['p:' + st.psel]; return []; }
  function setSel(keys) {
    var seen = Object.create(null), l = [];
    (keys || []).forEach(function (k) { if (typeof k === 'string' && !seen[k] && keyOk(k)) { seen[k] = 1; l.push(k); } });
    if (l.length > 1) l = l.filter(function (k) { return k !== 'prods'; });            // the Products layer is picked on its own
    st.ms = l.length > 1 ? l : []; st.sel = null; st.psel = null;
    if (l.length === 1) { if (isPk(l[0])) st.psel = +l[0].slice(2); else st.sel = l[0]; }
  }
  function selEls() { return selKeys().filter(function (k) { return !isPk(k); }).map(elById).filter(Boolean); }
  function members(gp) { return doc.els.filter(function (e) { return e.gp === gp; }); }
  function ids(l) { return l.map(function (e) { return e.id; }); }
  // tapping an item picks its whole group -- unless that group is open for editing (double-click)
  function expandKey(k) { if (isPk(k)) return [k]; var e = elById(k); if (!e || !e.gp || e.gp === st.grp) return [k]; return ids(members(e.gp)); }
  // the group the selection is exactly (all of one group's members), or ''
  function selGroup() { var els = selEls(), g = els.length > 1 && els[0].gp; if (!g || selKeys().length !== els.length) return ''; return els.every(function (e) { return e.gp === g; }) && members(g).length === els.length ? g : ''; }
  function geoOf(k) { return isPk(k) ? curItems()[+k.slice(2)] : doc.geo[st.fmt][k]; }
  function nodeOf(k) { return isPk(k) ? stage.querySelector('.bp[data-i="' + (+k.slice(2)) + '"]') : stage.querySelector('.el[data-id="' + k + '"]'); }
  function cvW() { var c = stage.querySelector('.cv'); return c ? c.clientWidth || 1 : 1; }
  function r3(n) { return Math.round(n * 1000) / 1000; }
  // an item's box in canvas units (cqw): x / y top-left before turning, w, h, r
  function itemBox(k) {
    if (isPk(k)) { var it = curItems()[+k.slice(2)]; return { x: it.x, y: it.y, w: it.w, h: it.w / aspectOf(it.p), r: it.r || 0 }; }
    var g = doc.geo[st.fmt][k], h = g.h;
    if (!h) { var n = nodeOf(k); h = n ? n.offsetHeight / cvW() * 100 : 0; }
    return { x: g.x, y: g.y, w: g.w, h: h, r: g.r || 0 };
  }
  function aabb(b) { var a = b.r * Math.PI / 180, c = Math.abs(Math.cos(a)), s = Math.abs(Math.sin(a)), cx = b.x + b.w / 2, cy = b.y + b.h / 2, hw = (b.w * c + b.h * s) / 2, hh = (b.w * s + b.h * c) / 2; return { x0: cx - hw, y0: cy - hh, x1: cx + hw, y1: cy + hh }; }
  function union(l) { return l.reduce(function (u, a) { return u ? { x0: Math.min(u.x0, a.x0), y0: Math.min(u.y0, a.y0), x1: Math.max(u.x1, a.x1), y1: Math.max(u.y1, a.y1) } : a; }, null); }
  function boxOfKeys(keys) { return union(keys.map(function (k) { return aabb(itemBox(k)); })); }
  function placeKey(k) { var n = nodeOf(k); if (!n) return; if (isPk(k)) placeProd(n, curItems()[+k.slice(2)], accentOf(doc)); else placeEl(n, elById(k), doc.geo[st.fmt][k]); }
  function posBox(b, u) { b.style.left = u.x0 + 'cqw'; b.style.top = u.y0 + 'cqw'; b.style.width = (u.x1 - u.x0) + 'cqw'; b.style.height = (u.y1 - u.y0) + 'cqw'; }
  // the units an arrange command moves: a whole group counts as one
  function selUnits() {
    var us = [], byG = Object.create(null);
    selKeys().filter(function (k) { return k !== 'prods'; }).forEach(function (k) {
      var e = !isPk(k) && elById(k);
      if (e && e.gp && e.gp !== st.grp) { if (!byG[e.gp]) { byG[e.gp] = []; us.push(byG[e.gp]); } byG[e.gp].push(k); } else us.push([k]);
    });
    return us.map(function (ks) { return { ks: ks, b: boxOfKeys(ks) }; });
  }
  function movable(keys) { return keys.filter(function (k) { if (k === 'prods') return false; var e = !isPk(k) && elById(k); return !e || !e.lock; }); }

  function markSel() {
    stage.querySelectorAll('.sel,.msel').forEach(function (n) { n.classList.remove('sel'); n.classList.remove('msel'); n.querySelectorAll('.hd,.hw,.rh').forEach(function (h) { h.remove(); }); });
    stage.querySelectorAll('.msbox,.gbox').forEach(function (n) { n.remove(); });
    var cv = stage.querySelector('.cv'); if (!cv) return;
    if (st.grp) { var gm = members(st.grp).filter(function (e) { return nodeOf(e.id); }); if (gm.length) { var gb = mk('div', 'gbox'); posBox(gb, boxOfKeys(ids(gm))); cv.appendChild(gb); } else st.grp = null; }
    var keys = selKeys();
    if (keys.length > 1) {
      var vis = keys.filter(function (k) { return nodeOf(k); }); if (!vis.length) return;
      vis.forEach(function (k) { nodeOf(k).classList.add('msel'); });
      var b = mk('div', 'msbox'); posBox(b, boxOfKeys(vis));
      ['nw', 'ne', 'sw', 'se'].forEach(function (h) { var x = mk('span', 'mh'); x.dataset.h = h; b.appendChild(x); });
      var r = mk('span', 'mr'); r.dataset.h = 'mr'; b.appendChild(r); cv.appendChild(b);
      return;
    }
    var n = null, isText = false;
    if (st.psel != null) n = stage.querySelector('.bp[data-i="' + st.psel + '"]');
    else if (st.sel) { n = stage.querySelector('.el[data-id="' + st.sel + '"]'); var e = selEl(); isText = e && !doc.geo[st.fmt][e.id].h && (e.t === 'text' || e.t === 'logo'); }
    if (!n) return;
    n.classList.add('sel');
    var hd = mk('span', 'hd'); hd.dataset.h = 'c'; n.appendChild(hd);
    if (isText) { var hw = mk('span', 'hw'); hw.dataset.h = 'w'; n.appendChild(hw); }
    var rh = mk('span', 'rh'); rh.dataset.h = 'r'; n.appendChild(rh);
  }
  function afterSel() { markSel(); panelSel(); panelProd(); layers(); abarSync(); }

  // ── pointer: select (tap, Shift/Ctrl/Cmd+tap, marquee), move, resize, rotate -- one item or many
  //    (this is the editor, not a shop page: measuring is fine here) ──
  var drag = null, guides = [], lastTap = { k: '', t: 0 };
  function cvRect() { return stage.querySelector('.cv').getBoundingClientRect(); }
  function clearGuides() { guides.forEach(function (g) { g.remove(); }); guides = []; }
  function guide(cls, pos) { var g = mk('div', 'guide ' + cls); if (cls === 'v') g.style.left = pos + 'cqw'; else g.style.top = pos + 'cqw'; stage.querySelector('.cv').appendChild(g); guides.push(g); }
  // snap targets: the artboard's edges and centre, and every other item's edges and centre
  function snapTargets(keys) {
    var skip = Object.create(null), H = HU(st.fmt), tx = [0, 50, 100], ty = [0, H / 2, H]; keys.forEach(function (k) { skip[k] = 1; });
    var add = function (k) { var a = aabb(itemBox(k)); tx.push(a.x0, (a.x0 + a.x1) / 2, a.x1); ty.push(a.y0, (a.y0 + a.y1) / 2, a.y1); };
    stage.querySelectorAll('.cv .el').forEach(function (n) { var id = n.dataset.id; if (!skip[id] && doc.geo[st.fmt][id]) add(id); });
    stage.querySelectorAll('.cv .bp').forEach(function (n) { var k = 'p:' + n.dataset.i; if (!skip[k] && curItems()[+n.dataset.i]) add(k); });
    return { x: tx, y: ty };
  }
  function snap1(edges, targets, thr) { var best = null; edges.forEach(function (e) { targets.forEach(function (t) { var d = t - e; if (Math.abs(d) < thr && (!best || Math.abs(d) < Math.abs(best.d))) best = { d: d, t: t }; }); }); return best; }
  function snapshot(k) { var b = itemBox(k); if (!isPk(k)) { var g = doc.geo[st.fmt][k]; b.k = g.k || 1; b.gh = g.h; } return b; }
  stage.addEventListener('pointerdown', function (ev) {
    if (playT || !stage.querySelector('.cv') || ev.button > 0) return;
    var t = ev.target, h = t.dataset && t.dataset.h, cvr = cvRect(), px = 100 / cvr.width;
    var add = ev.shiftKey || ev.ctrlKey || ev.metaKey || st.pick;
    if (h && t.closest('.msbox')) {                                  // the selection box: scale / turn everything in it
      var keys = movable(selKeys()); if (!keys.length) return;
      begin(); var start = {}; keys.forEach(function (k) { start[k] = snapshot(k); });
      var u = union(keys.map(function (k) { return aabb(start[k]); })), cx = (u.x0 + u.x1) / 2, cy = (u.y0 + u.y1) / 2;
      drag = { kind: 'x', h: h, keys: keys, start: start, u: u, px: px, cvr: cvr, cx: cx, cy: cy, a0: Math.atan2((ev.clientY - cvr.top) * px - cy, (ev.clientX - cvr.left) * px - cx) };
      stage.setPointerCapture(ev.pointerId); ev.preventDefault(); return;
    }
    var bp = t.closest('.bp'), en = t.closest('.el');
    if (h) {                                                         // a single item's own handles
      begin();
      if (st.psel != null) {
        var it = curItems()[st.psel], node = stage.querySelector('.bp[data-i="' + st.psel + '"]'), rb = node.getBoundingClientRect();
        drag = { kind: 'p', h: h, it: it, node: node, sx: ev.clientX, sy: ev.clientY, x: it.x, y: it.y, w: it.w, px: px, cx: rb.left + rb.width / 2, cy: rb.top + rb.height / 2 };
      } else {
        var e = selEl(); if (!e) return; var g = doc.geo[st.fmt][e.id], n = stage.querySelector('.el[data-id="' + e.id + '"]'), r = n.getBoundingClientRect();
        drag = { kind: 'e', h: h, e: e, g: g, node: n, sx: ev.clientX, sy: ev.clientY, x: g.x, y: g.y, w: g.w, gh: g.h, k: g.k || 1, px: px, cx: r.left + r.width / 2, cy: r.top + r.height / 2, nh: n.offsetHeight * px };
      }
      stage.setPointerCapture(ev.pointerId); ev.preventDefault(); return;
    }
    var key = bp ? 'p:' + bp.dataset.i : en ? en.dataset.id : null;
    if (!key) {                                                      // empty canvas: draw a marquee
      var cv = stage.querySelector('.cv'), q = mk('div', 'mq'); cv.appendChild(q);
      drag = { kind: 'q', q: q, add: add, base: add ? selKeys() : [], x: (ev.clientX - cvr.left) * px, y: (ev.clientY - cvr.top) * px, px: px, cvr: cvr, rect: null };
      stage.setPointerCapture(ev.pointerId); ev.preventDefault(); return;
    }
    var ke = !isPk(key) && elById(key), now = Date.now(), dbl = !add && ke && ke.gp && lastTap.k === key && now - lastTap.t < 450;
    lastTap = { k: key, t: now };
    if (dbl) { st.grp = ke.gp; setStatus('Editing inside the group — Esc leaves it', 'ok'); }   // double-tap a group: edit one of its items
    else if (!ke || ke.gp !== st.grp) st.grp = null;                                              // a tap outside the open group closes it
    var ks = expandKey(key), cur = selKeys(), collapse = null;
    if (dbl) setSel([key]);
    else if (add) {
      if (ks.every(function (k) { return cur.indexOf(k) >= 0; })) { setSel(cur.filter(function (k) { return ks.indexOf(k) < 0; })); afterSel(); ev.preventDefault(); return; }
      setSel(cur.concat(ks));
    } else if (cur.length > 1 && ks.every(function (k) { return cur.indexOf(k) >= 0; })) collapse = ks;   // keep the pick to drag it; a plain tap narrows it
    else setSel(ks);
    var mk_ = movable(selKeys()), st0 = {}; mk_.forEach(function (k) { st0[k] = itemBox(k); });
    begin();
    drag = { kind: 'm', keys: mk_, start: st0, u: mk_.length ? union(mk_.map(function (k) { return aabb(st0[k]); })) : null, tg: snapTargets(mk_), sx: ev.clientX, sy: ev.clientY, px: px, moved: false, collapse: collapse };
    var one = selKeys().length === 1;
    if (one && st.psel != null) { if (st.tab !== 'prod') showTab('prod'); } else if (st.tab !== 'edit') showTab('edit');
    afterSel();
    stage.setPointerCapture(ev.pointerId); ev.preventDefault();
  });
  stage.addEventListener('pointermove', function (ev) {
    if (!drag) return;
    if (drag.kind === 'm') return moveDrag(ev);
    if (drag.kind === 'q') return marqueeDrag(ev);
    if (drag.kind === 'x') return xfDrag(ev);
    var dx = (ev.clientX - drag.sx) * drag.px, dy = (ev.clientY - drag.sy) * drag.px;
    if (drag.h === 'r') {
      var ang = Math.atan2(ev.clientY - drag.cy, ev.clientX - drag.cx) * 180 / Math.PI + 90; ang = ((ang + 540) % 360) - 180;
      if (ev.shiftKey) ang = Math.round(ang / 15) * 15; ang = Math.round(ang);
      if (drag.kind === 'p') { drag.it.r = ang; placeProd(drag.node, drag.it, accentOf(doc)); } else { drag.g.r = ang; placeEl(drag.node, drag.e, drag.g); }
      return;
    }
    if (drag.kind === 'p') { var it = drag.it, a = aspectOf(it.p); it.w = r2(Math.max(2, drag.w + Math.max(dx, dy * a))); placeProd(drag.node, it, accentOf(doc)); return; }
    var g = drag.g, e = drag.e;
    if (drag.h === 'c') {
      if (!drag.gh && (e.t === 'text' || e.t === 'logo')) { var s = Math.max(.15, (drag.w + dx) / drag.w); g.w = r2(drag.w * s); g.k = r2(Math.max(.2, Math.min(5, drag.k * s))); }
      else if (e.t === 'icon' || e.t === 'img' || e.t === 'art' || e.t === 'pay' || ev.shiftKey || e.sh === 'circle') { var s2 = Math.max(.1, (drag.w + Math.max(dx, dy * drag.w / (drag.gh || 1))) / drag.w); g.w = r2(drag.w * s2); g.h = r2(drag.gh * s2); }
      else { g.w = r2(Math.max(1, drag.w + dx)); g.h = r2(Math.max(.5, drag.gh + dy)); }
    } else if (drag.h === 'w') g.w = r2(Math.max(3, drag.w + dx));
    placeEl(drag.node, e, g); fitText(drag.node.parentNode);
  });
  // move: everything picked, with smart guides to the artboard and to every other item (Alt = no snapping)
  function moveDrag(ev) {
    if (!drag.moved && Math.abs(ev.clientX - drag.sx) + Math.abs(ev.clientY - drag.sy) < 3) return;
    drag.moved = true; if (!drag.u) return;
    var dx = (ev.clientX - drag.sx) * drag.px, dy = (ev.clientY - drag.sy) * drag.px, u = drag.u;
    clearGuides();
    if (!ev.altKey) {
      var thr = 6 * drag.px, sx = snap1([u.x0 + dx, (u.x0 + u.x1) / 2 + dx, u.x1 + dx], drag.tg.x, thr), sy = snap1([u.y0 + dy, (u.y0 + u.y1) / 2 + dy, u.y1 + dy], drag.tg.y, thr);
      if (sx) { dx += sx.d; guide('v', sx.t); } if (sy) { dy += sy.d; guide('h', sy.t); }
    }
    drag.keys.forEach(function (k) { var b = drag.start[k], o = geoOf(k); o.x = r3(b.x + dx); o.y = r3(b.y + dy); placeKey(k); });
    var mb = stage.querySelector('.msbox'); if (mb) posBox(mb, { x0: u.x0 + dx, y0: u.y0 + dy, x1: u.x1 + dx, y1: u.y1 + dy });
    var gb = stage.querySelector('.gbox'); if (gb && st.grp) posBox(gb, boxOfKeys(ids(members(st.grp))));
  }
  function marqueeDrag(ev) {
    var x = (ev.clientX - drag.cvr.left) * drag.px, y = (ev.clientY - drag.cvr.top) * drag.px;
    if (Math.abs(x - drag.x) + Math.abs(y - drag.y) < 3 * drag.px) return;
    drag.rect = { x0: Math.min(x, drag.x), y0: Math.min(y, drag.y), x1: Math.max(x, drag.x), y1: Math.max(y, drag.y) }; posBox(drag.q, drag.rect);
  }
  function marqueeEnd(d) {
    if (!d.rect) { if (!d.add) { st.grp = null; setSel([]); } return; }
    var hit = [], R = d.rect; st.grp = null;
    var touch = function (k) { var a = aabb(itemBox(k)); return a.x0 < R.x1 && a.x1 > R.x0 && a.y0 < R.y1 && a.y1 > R.y0; };
    stage.querySelectorAll('.cv .el:not(.lk)').forEach(function (n) { if (touch(n.dataset.id)) hit = hit.concat(expandKey(n.dataset.id)); });
    stage.querySelectorAll('.cv .bp').forEach(function (n) { if (touch('p:' + n.dataset.i)) hit.push('p:' + n.dataset.i); });
    setSel(d.base.concat(hit));
    if (selKeys().length && st.tab !== 'edit' && !(selKeys().length === 1 && st.psel != null)) showTab('edit');
  }
  // the selection box's handles: corners scale everything about the opposite corner, the round one turns it about its centre
  function xfDrag(ev) {
    var P = { x: (ev.clientX - drag.cvr.left) * drag.px, y: (ev.clientY - drag.cvr.top) * drag.px }, u = drag.u;
    if (drag.h === 'mr') {
      var ang = (Math.atan2(P.y - drag.cy, P.x - drag.cx) - drag.a0) * 180 / Math.PI; if (ev.shiftKey) ang = Math.round(ang / 15) * 15;
      var ca = Math.cos(ang * Math.PI / 180), sa = Math.sin(ang * Math.PI / 180);
      drag.keys.forEach(function (k) {
        var b = drag.start[k], o = geoOf(k), cx = b.x + b.w / 2 - drag.cx, cy = b.y + b.h / 2 - drag.cy, nx = drag.cx + cx * ca - cy * sa, ny = drag.cy + cx * sa + cy * ca;
        o.r = Math.round((((b.r + ang) % 360) + 540) % 360 - 180); o.x = r3(nx - b.w / 2); o.y = r3(ny - b.h / 2); placeKey(k);
      });
    } else {
      var A = { x: drag.h.indexOf('w') >= 0 ? u.x1 : u.x0, y: drag.h.indexOf('n') >= 0 ? u.y1 : u.y0 }, C = { x: drag.h.indexOf('w') >= 0 ? u.x0 : u.x1, y: drag.h.indexOf('n') >= 0 ? u.y0 : u.y1 };
      var vx = C.x - A.x, vy = C.y - A.y, s = Math.max(.05, ((P.x - A.x) * vx + (P.y - A.y) * vy) / (vx * vx + vy * vy || 1));
      drag.keys.forEach(function (k) {
        var b = drag.start[k], o = geoOf(k), cx = A.x + (b.x + b.w / 2 - A.x) * s, cy = A.y + (b.y + b.h / 2 - A.y) * s, w = Math.max(.3, b.w * s);
        o.w = r3(w); o.x = r3(cx - w / 2); o.y = r3(cy - b.h * s / 2);
        if (!isPk(k)) { if (b.gh) o.h = r3(b.gh * s); o.k = r3(Math.max(.2, Math.min(5, b.k * s))); }
        placeKey(k);
      });
      fitText(stage.querySelector('.cv'));
    }
    var mb = stage.querySelector('.msbox'); if (mb) posBox(mb, boxOfKeys(drag.keys));
  }
  function dragEnd() {
    if (!drag) return; var d = drag; drag = null; clearGuides();
    if (d.kind === 'q') { d.q.remove(); marqueeEnd(d); afterSel(); return; }
    if (d.kind === 'm' && !d.moved && d.collapse) setSel(d.collapse);
    end(); draw();
  }
  stage.addEventListener('pointerup', dragEnd); stage.addEventListener('pointercancel', dragEnd);

  // ── commands on the selection: delete, duplicate, copy / paste, group, order, lock / hide, align, distribute, match ──
  var clip = null, pasteN = 0;
  function takeSel() {           // the picked elements (with all 3 sizes' places) and products, ready to paste
    var els = selEls().filter(function (e) { return e.t !== 'prods'; }), its = selKeys().filter(isPk).map(function (k) { return clone(curItems()[+k.slice(2)]); });
    if (!els.length && !its.length) return null;
    var whole = Object.create(null), names = {};
    els.forEach(function (e) { if (e.gp && members(e.gp).every(function (m) { return els.indexOf(m) >= 0; })) { whole[e.gp] = 1; names[e.gp] = doc.groups[e.gp] ? doc.groups[e.gp].name : 'Group'; } });
    var geo = {}; FMTS.forEach(function (f) { geo[f] = {}; els.forEach(function (e) { geo[f][e.id] = clone(doc.geo[f][e.id]); }); });
    return { els: els.map(function (e) { var c = clone(e); if (c.gp && !whole[c.gp]) delete c.gp; return c; }), keep: els.filter(function (e) { return e.gp && !whole[e.gp]; }).map(function (e) { return e.id; }), geo: geo, its: its, names: names };
  }
  // put copies in: off = shift in canvas units; at = stack position (null = on top); partial-group copies stay in their group
  function putCopies(c, off, at, rename) {
    var keys = [], gmap = Object.create(null), add = [];
    c.els.forEach(function (c0) {
      var e = clone(c0), old = e.id; e.id = uid('e'); if (rename) e.name = ((c0.name || TYPES[c0.t]) + ' copy').slice(0, 40);
      if (e.gp) { if (!gmap[e.gp]) { gmap[e.gp] = uid('g'); doc.groups[gmap[e.gp]] = { name: str(c.names[e.gp], 40) || 'Group' }; } e.gp = gmap[e.gp]; }
      else if (at != null && c.keep.indexOf(old) >= 0) { var src = elById(old); if (src && src.gp) e.gp = src.gp; }
      FMTS.forEach(function (f) { var g = clone(c.geo[f][old]); g.x = r3(g.x + off); g.y = r3(g.y + off); doc.geo[f][e.id] = cleanGeo(g); });
      add.push(e); keys.push(e.id);
    });
    if (at == null) doc.els = doc.els.concat(add); else Array.prototype.splice.apply(doc.els, [at, 0].concat(add));
    var l = curItems(); c.its.forEach(function (it) { var n = clone(it); n.x = r3(n.x + off); n.y = r3(n.y + off); if (prodOk(n.p) && l.length < 16) { l.push(n); keys.push('p:' + (l.length - 1)); } });
    normGroups(doc); st.grp = null; setSel(keys);
  }
  function copySel() { var c = takeSel(); if (!c) return false; clip = c; pasteN = 0; setStatus('Copied ' + (c.els.length + c.its.length) + ' item(s) — Ctrl+V pastes', 'ok'); return true; }
  function paste() { if (!clip) return; pasteN++; var c = clip; act(function () { putCopies(c, 2 * pasteN, null, false); }); }
  function removeSel() {
    var keys = selKeys(), pis = keys.filter(isPk).map(function (k) { return +k.slice(2); }).sort(function (a, b) { return b - a; }), del = keys.filter(function (k) { return !isPk(k) && k !== 'prods'; });
    if (!pis.length && !del.length) return;
    act(function () { var l = curItems(); pis.forEach(function (i) { l.splice(i, 1); }); doc.els = doc.els.filter(function (x) { return del.indexOf(x.id) < 0; }); FMTS.forEach(function (f) { del.forEach(function (id) { delete doc.geo[f][id]; }); }); normGroups(doc); setSel([]); });
  }
  function duplicate() {
    var c = takeSel(); if (!c) return;
    var top = -1; c.els.forEach(function (e) { top = Math.max(top, doc.els.indexOf(elById(e.id))); });
    act(function () { putCopies(c, 2, c.els.length ? top + 1 : null, true); });
  }
  function groupSel() {
    var els = selEls().filter(function (e) { return e.t !== 'prods'; });
    if (els.length < 2) { setStatus('Pick two or more items to group (products stay on the Products layer)', 'warn'); return; }
    act(function () { var gid = uid('g'); doc.groups[gid] = { name: 'Group ' + (Object.keys(doc.groups).length + 1) }; els.forEach(function (e) { e.gp = gid; }); normGroups(doc); st.grp = null; setSel(ids(els)); });
    setStatus('Grouped ' + els.length + ' items — Shift+Ctrl+G ungroups', 'ok');
  }
  function ungroupSel() {
    var gs = []; selEls().forEach(function (e) { if (e.gp && gs.indexOf(e.gp) < 0) gs.push(e.gp); }); if (!gs.length) return;
    act(function () { doc.els.forEach(function (e) { if (gs.indexOf(e.gp) >= 0) delete e.gp; }); gs.forEach(function (g) { delete doc.groups[g]; }); st.grp = null; });
    setStatus('Ungrouped', 'ok');
  }
  // forward / back by one: a whole group moves as one; inside an open group, only among its own items
  function zMove(d) {
    var sel = ids(selEls()); if (!sel.length) return;
    act(function () {
      var inG = st.grp && sel.every(function (id) { return elById(id).gp === st.grp; }), units = [];
      doc.els.forEach(function (e) { var last = units[units.length - 1]; if (!inG && e.gp && last && last[0].gp === e.gp) last.push(e); else units.push([e]); });
      var on = function (u) { return u.some(function (e) { return sel.indexOf(e.id) >= 0; }); }, ok = function (u) { return !inG || u[0].gp === st.grp; };
      var sw = function (i, j) { var t = units[i]; units[i] = units[j]; units[j] = t; };
      if (d > 0) { for (var i = units.length - 2; i >= 0; i--) if (on(units[i]) && !on(units[i + 1]) && ok(units[i + 1])) sw(i, i + 1); }
      else { for (var j = 1; j < units.length; j++) if (on(units[j]) && !on(units[j - 1]) && ok(units[j - 1])) sw(j, j - 1); }
      doc.els = [].concat.apply([], units); normGroups(doc);
    });
  }
  function toggleAll(p) { var els = selEls(); if (!els.length) return; var on = els.some(function (e) { return !e[p]; }); act(function () { els.forEach(function (e) { if (on) e[p] = true; else delete e[p]; }); }); }
  function selectAll() {
    var keys = doc.els.filter(function (e) { return e.t !== 'prods' && !e.hide && !e.lock && !doc.geo[st.fmt][e.id].hd; }).map(function (e) { return e.id; });
    var pr = doc.els.filter(function (e) { return e.t === 'prods'; })[0]; if (pr && !pr.hide && !doc.geo[st.fmt][pr.id].hd) curItems().forEach(function (_, i) { keys.push('p:' + i); });
    st.grp = null; setSel(keys); if (st.tab !== 'edit') showTab('edit'); afterSel();
  }
  function nudge(dx, dy) { var keys = movable(selKeys()); if (!keys.length) return; act(function () { keys.forEach(function (k) { var o = geoOf(k); o.x = r3(o.x + dx); o.y = r3(o.y + dy); }); }); }
  function alignSel(how) {
    var us = selUnits(); if (!us.length) return;
    var H = HU(st.fmt), ref = st.alto === 'art' || us.length === 1 ? { x0: 0, y0: 0, x1: 100, y1: H } : union(us.map(function (u) { return u.b; }));
    act(function () { us.forEach(function (u) {
      var b = u.b, dx = 0, dy = 0;
      if (how === 'l') dx = ref.x0 - b.x0; else if (how === 'c') dx = (ref.x0 + ref.x1 - b.x0 - b.x1) / 2; else if (how === 'r') dx = ref.x1 - b.x1;
      else if (how === 't') dy = ref.y0 - b.y0; else if (how === 'm') dy = (ref.y0 + ref.y1 - b.y0 - b.y1) / 2; else if (how === 'b') dy = ref.y1 - b.y1;
      movable(u.ks).forEach(function (k) { var o = geoOf(k); o.x = r3(o.x + dx); o.y = r3(o.y + dy); });
    }); });
  }
  function distribute(ax) {
    var us = selUnits(); if (us.length < 3) { setStatus('Pick three or more items to space them evenly', 'warn'); return; }
    var a0 = ax === 'h' ? 'x0' : 'y0', a1 = ax === 'h' ? 'x1' : 'y1';
    us.sort(function (p, q) { return p.b[a0] + p.b[a1] - q.b[a0] - q.b[a1]; });
    var total = us.reduce(function (s, u) { return s + u.b[a1] - u.b[a0]; }, 0), gap = (us[us.length - 1].b[a1] - us[0].b[a0] - total) / (us.length - 1), pos = us[0].b[a0];
    act(function () { us.forEach(function (u) { var d = pos - u.b[a0]; pos += u.b[a1] - u.b[a0] + gap; movable(u.ks).forEach(function (k) { var o = geoOf(k); if (ax === 'h') o.x = r3(o.x + d); else o.y = r3(o.y + d); }); }); });
  }
  function matchSize(dim) {           // to the first item picked
    var keys = movable(selKeys()); if (keys.length < 2) return; var ref = itemBox(keys[0]);
    act(function () { keys.slice(1).forEach(function (k) {
      if (isPk(k)) { var it = curItems()[+k.slice(2)]; it.w = r3(dim === 'w' ? ref.w : ref.h * aspectOf(it.p)); return; }
      var g = doc.geo[st.fmt][k]; if (dim === 'w') g.w = r3(ref.w); else if (g.h) g.h = r3(ref.h);
    }); });
  }

  document.addEventListener('keydown', function (ev) {
    if (document.body.dataset.mode === 'video') return;           // Video mode has its own shortcuts
    var inField = /input|select|textarea/i.test((document.activeElement || {}).tagName || '');
    var mod = ev.ctrlKey || ev.metaKey, k = ev.key.toLowerCase();
    if (mod && k === 's') { ev.preventDefault(); saveNow(); return; }
    if (ev.key === 'Escape' && CC.isOpen()) { CC.close(); return; }
    if (inField) return;
    if (mod && k === 'z' && !ev.shiftKey) { ev.preventDefault(); undo(); return; }
    if (mod && (k === 'y' || (k === 'z' && ev.shiftKey))) { ev.preventDefault(); redo(); return; }
    if (mod && k === 'a') { ev.preventDefault(); selectAll(); return; }
    if (mod && k === 'c') { if (copySel()) ev.preventDefault(); return; }
    if (mod && k === 'x') { if (copySel()) { ev.preventDefault(); removeSel(); } return; }
    if (mod && k === 'v') { if (clip) { ev.preventDefault(); paste(); } return; }
    if (mod && k === 'g') { ev.preventDefault(); if (ev.shiftKey) ungroupSel(); else groupSel(); return; }
    if (mod && k === 'd') { ev.preventDefault(); duplicate(); return; }
    if (mod && (ev.key === ']' || ev.key === '[')) { ev.preventDefault(); zMove(ev.key === ']' ? 1 : -1); return; }
    if (ev.key === 'Delete' || ev.key === 'Backspace') { if (selKeys().length) { ev.preventDefault(); removeSel(); } return; }
    if (ev.key === 'Escape') { if (st.grp) { var g = st.grp; st.grp = null; setSel(ids(members(g))); } else setSel([]); draw(); return; }
    var d = (ev.shiftKey ? 10 : 1) * 100 / PX[st.fmt][0], m = { ArrowLeft: [-d, 0], ArrowRight: [d, 0], ArrowUp: [0, -d], ArrowDown: [0, d] }[ev.key];   // 1 px, Shift = 10 px of the full-HD file
    if (!m || !selKeys().length) return;
    ev.preventDefault(); nudge(m[0], m[1]);
  });

  // ── Edit tab: selected element ──
  var fnSel = $('p-fn'); Object.keys(FONTS).forEach(function (k) { var o = mk('option', null, FONTS[k]); o.value = k; fnSel.appendChild(o); });
  var shSel = $('p-sh'); Object.keys(SHAPES).forEach(function (k) { var o = mk('option', null, SHAPES[k]); o.value = k; shSel.appendChild(o); });
  var icSel = $('p-ic'); Object.keys(ICONS).forEach(function (k) { if (k === 'lotus') return; var o = mk('option', null, ICONS[k][0]); o.value = k; icSel.appendChild(o); });
  function show(sel, on) { document.querySelectorAll(sel).forEach(function (n) { n.hidden = !on; }); }
  function setV(n, v) { if (n.type === 'checkbox') n.checked = !!v; else if (String(n.value) !== String(v)) n.value = v; }
  function hex6(c, d) { return c && HEX.test(c) ? c.slice(0, 7) : d; }
  // a colour swatch button shows its colour over a checkerboard (so see-through reads as see-through)
  function paintSw(b, v) { var ok = typeof v === 'string' && (HEX.test(v) || v === 'transparent'); b.firstElementChild.style.setProperty('--c', ok ? v : 'transparent'); b.title = ok ? v : 'None'; }
  var DEFCOL = { col: '#111111', fill: 'transparent', fill2: 'transparent', bc: '#000000', shc: '#000000' };
  function panelSel() {
    var keys = selKeys(), multi = keys.length > 1, e = multi ? null : selEl(), props = $('props');
    $('multi').hidden = !multi;
    if (multi) {
      var gs = selGroup(), els = selEls();
      $('sel-t').textContent = gs ? (doc.groups[gs].name || 'Group') + ' · group of ' + keys.length : keys.length + ' items selected';
      $('sel-empty').hidden = true;
      props.querySelectorAll('[data-need],[data-show]').forEach(function (n) { n.hidden = true; });
      $('m-grp').disabled = els.length < 2 || !!gs; $('m-ungrp').disabled = !els.some(function (x) { return x.gp; });
      $('m-lock').textContent = els.length && els.every(function (x) { return x.lock; }) ? 'Unlock' : 'Lock';
      $('m-hide').textContent = els.length && els.every(function (x) { return x.hide; }) ? 'Show' : 'Hide';
      setV($('m-op'), els.length ? Math.round(els.reduce(function (a, x) { return a + (x.op == null ? 100 : x.op); }, 0) / els.length) : 100);
      var fc = els.filter(function (x) { return x.col; })[0], ff = els.filter(function (x) { return x.fill; })[0];
      paintSw(props.querySelector('[data-mp="col"]'), fc ? fc.col : '#111111'); paintSw(props.querySelector('[data-mp="fill"]'), ff ? ff.fill : 'transparent');
      return;
    }
    $('sel-t').textContent = e ? (e.name || TYPES[e.t]) + (e.t === 'prods' ? '' : ' · ' + TYPES[e.t]) + (e.gp && doc.groups[e.gp] ? ' (in ' + doc.groups[e.gp].name + ')' : '') : 'Nothing selected';
    $('sel-empty').hidden = !!e;
    props.querySelectorAll('[data-need]').forEach(function (n) { n.hidden = !e || (n.dataset.need === 'geo' && e.t === 'prods'); });
    $('ph-row').hidden = true;
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
    props.querySelectorAll('[data-cp]').forEach(function (b) { paintSw(b, e[b.dataset.cp] || DEFCOL[b.dataset.cp]); });
    $('v-fs').textContent = (e.fs || 4).toFixed(1);
    props.querySelectorAll('[data-g]').forEach(function (n) { setV(n, g[n.dataset.g] || 0); });
    $('v-r').textContent = (g.r || 0) + '°';
    $('p-hd').checked = !!g.hd;
    $('p-al').querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', String((g.al || e.al || 'c') === b.dataset.v)); });
    $('del').disabled = e.t === 'prods'; $('dup').disabled = e.t === 'prods';
    $('ph-row').hidden = !e.ph;
    $('ph-cut').hidden = !(e.wk && e.t === 'img');
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
  // colour swatches: the one colour control (hex, opacity, eyedropper, recent, this design's colours, brand)
  $('props').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-cp],[data-mp]'); if (!b) return;
    if (b.dataset.cp) {
      var e = selEl(), p = b.dataset.cp; if (!e) return;
      CC.open(b, e[p] || (p === 'fill2' ? e.fill || '#FFFFFF' : DEFCOL[p]), function (v, fin) {
        begin(); var raw = {}; raw[p] = v; var c = cleanEl(Object.assign({}, e, raw), images); if (c && c[p]) e[p] = c[p];
        if (p === 'fill' && e.t === 'text' && (!e.sh || e.sh === 'none')) e.sh = 'rect';
        redrawStageOnly(); paintSw(b, e[p]); if (fin) { end(); panelSel(); layers(); }
      });
      return;
    }
    var els = selEls(), mp = b.dataset.mp, first = els.filter(function (x) { return x[mp]; })[0];
    CC.open(b, first ? first[mp] : mp === 'col' ? '#111111' : '#FFFFFF', function (v, fin) {
      begin();
      els.forEach(function (x) {
        if (mp === 'col' && /^(text|btn|shape|logo|icon)$/.test(x.t)) x.col = v;
        if (mp === 'fill' && (x.t === 'shape' || x.t === 'btn' || (x.t === 'text' && x.sh && x.sh !== 'none'))) { x.fill = v; delete x.fill2; }
      });
      redrawStageOnly(); paintSw(b, v); if (fin) { end(); panelSel(); }
    });
  });
  // a multi-pick: the same commands, for all of it
  $('m-grp').onclick = groupSel; $('m-ungrp').onclick = ungroupSel; $('m-dup').onclick = duplicate; $('m-del').onclick = removeSel;
  $('m-lock').onclick = function () { toggleAll('lock'); }; $('m-hide').onclick = function () { toggleAll('hide'); };
  $('m-fwd').onclick = function () { zMove(1); }; $('m-bwd').onclick = function () { zMove(-1); };
  $('m-op').addEventListener('input', function () { var v = num(this.value, 0, 100, 100); begin(); selEls().forEach(function (x) { if (v >= 100) delete x.op; else x.op = v; }); redrawStageOnly(); });
  $('m-op').addEventListener('change', function () { end(); });
  $('p-fill0').onclick = function () { var e = selEl(); if (e) act(function () { e.fill = 'transparent'; delete e.fill2; }); };
  $('p-fill20').onclick = function () { var e = selEl(); if (e) act(function () { delete e.fill2; }); };
  $('dup').onclick = duplicate; $('del').onclick = removeSel; $('fwd').onclick = function () { zMove(1); }; $('bwd').onclick = function () { zMove(-1); };
  $('match').onclick = function () { var e = selEl(); if (!e) return; act(function () { var g = doc.geo[st.fmt][e.id], H = HU(st.fmt); FMTS.forEach(function (f) { if (f === st.fmt) return; var o = clone(g); o.y = r2((g.y + (g.h || 0) / 2) / H * HU(f) - (g.h || 0) / 2); doc.geo[f][e.id] = cleanGeo(o); }); }); setStatus('Placed the same way in all 3 sizes', 'ok'); };
  $('eres').onclick = function () { var e = selEl(), t = tplById[doc.tpl]; if (!e || !t) return; var src = fromTemplate(t), o = src.els.filter(function (x) { return x.id === e.id; })[0];
    if (!o) { setStatus('This item is not part of the template', 'warn'); return; }
    act(function () { var i = doc.els.indexOf(e); if (e.gp) o.gp = e.gp; doc.els[i] = o; FMTS.forEach(function (f) { doc.geo[f][o.id] = src.geo[f][o.id]; }); normGroups(doc); }); };

  // ── Edit tab: add ──
  function addEl(e, gw, gh) {
    act(function () {
      e.id = uid('e'); var top = doc.els.length;
      doc.els.splice(top, 0, e);
      FMTS.forEach(function (f) { doc.geo[f][e.id] = { x: r2(50 - gw / 2), y: r2(HU(f) / 2 - (gh || 6) / 2), w: gw, h: gh || 0, r: 0, k: 1 }; });
      st.grp = null; setSel([e.id]);
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
      case 'logo': addEl({ t: 'logo', name: 'Logo', txt: co.id === 'kbb' ? 'K-Beauty Bliss' : co.fullName || co.name, fn: co.id === 'kbb' ? 'playfair' : fontKey(co.fonts.heading, 'montserrat'), fw: 700, fs: 3, col: '#111111', al: 'c' }, 40); break;
      case 'pay': addEl({ t: 'pay', name: 'Tabby / Tamara', pay: 'both' }, 38, 3.4); break;
    }
  }; });
  $('add-badge').onchange = function (ev) {
    var k = ev.target.value, a = accNow(); ev.target.value = ''; if (!k) return;
    if (k === 'ribbon') { act(function () { var e = { id: uid('e'), t: 'text', name: 'Corner ribbon', txt: 'K-Beauty Bliss ♥', fn: 'poppins', fw: 800, fs: 2.4, ls: 4, col: contrast(a), sh: 'rect', fill: a, shd: 30, cr: 'acc' }; doc.els.push(e); FMTS.forEach(function (f) { doc.geo[f][e.id] = { x: -20, y: 13.9, w: 74, h: 6.2, r: -45, k: 1 }; }); st.grp = null; setSel([e.id]); }); return; }
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
  // flags, country outlines and landmark silhouettes (and the company marks) -- by name from the built-in list
  function occName(id) { return has(KT.OCC, id) ? KT.OCC[id].t[0] : id; }
  function toolName(id) { var n = ''; KT.OCCS.some(function (o) { return o.tools.some(function (t) { if (t[0] === id) { n = t[1]; return true; } }); }); return n || id; }
  function artName(id) {
    if (id.indexOf('fig:') === 0) return 'Worker · ' + occName(id.slice(4));
    if (id.indexOf('oi:') === 0) return 'Tool · ' + toolName(id.slice(3));
    var m = /^(flag|map|lm|mark):(.+)$/.exec(id) || [], c = KT.CTRY[m[2]]; if (m[1] === 'lm') { var l = KT.CTRY_LIST.filter(function (x) { return x.lm === m[2]; })[0]; return 'Landmark · ' + (l ? l.lmName : m[2]); } return (m[1] === 'flag' ? 'Flag · ' : m[1] === 'map' ? 'Outline · ' : 'Mark · ') + (c ? c.name : m[2]); }
  function artOptions(sel, first) {
    if (first) { var o0 = mk('option', null, first); o0.value = ''; sel.appendChild(o0); }
    [['flag', 'Flags'], ['map', 'Country outlines'], ['lm', 'Landmarks']].forEach(function (g) {
      var og = document.createElement('optgroup'); og.label = g[1];
      KT.CTRY_LIST.forEach(function (c) { var id = g[0] + ':' + (g[0] === 'lm' ? c.lm : c.code), o = mk('option', null, artName(id).split(' · ')[1]); o.value = id; og.appendChild(o); });
      sel.appendChild(og);
    });
    // Work Abroad: a worker in each occupation's uniform (a photo slot) and the occupations' tool icons
    var ow = document.createElement('optgroup'); ow.label = 'Workers (photo slots)';
    KT.OCCS.forEach(function (o) { var x = mk('option', null, o.t[0]); x.value = 'fig:' + o.id; ow.appendChild(x); });
    sel.appendChild(ow);
    var ot = document.createElement('optgroup'), seenT = {}; ot.label = 'Tool icons';
    KT.OCCS.forEach(function (o) { o.tools.forEach(function (t) { if (seenT[t[0]]) return; seenT[t[0]] = 1; var x = mk('option', null, t[1]); x.value = 'oi:' + t[0]; ot.appendChild(x); }); });
    sel.appendChild(ot);
  }
  artOptions($('add-art'), 'Add a flag, outline or landmark…'); artOptions($('p-art'));
  $('add-art').onchange = function (ev) {
    var id = ev.target.value; ev.target.value = ''; if (!ARTID.test(id)) return;
    var fl = id.indexOf('flag:') === 0;
    if (id.indexOf('fig:') === 0) { addEl({ t: 'art', name: 'Worker photo (tap → Put a photo here)', art: id, wk: true, ph: true }, 36, 45); return; }
    if (id.indexOf('oi:') === 0) { addEl({ t: 'art', name: artName(id), art: id, col: co.colors.primary }, 14, 14); return; }
    addEl({ t: 'art', name: artName(id), art: id, col: fl ? undefined : co.colors.primary, sh: fl ? 'rect' : undefined, rad: fl ? 1 : undefined }, fl ? 24 : 36, fl ? 18 : 36);
  };

  // ── layers: top of the stack first; groups fold open / shut; Shift = a range, Ctrl/Cmd = add or remove;
  //    drag the ⠿ grip to reorder (into or out of a group); double-click a name to rename ──
  function rowKeys(k) { return k.indexOf('g:') === 0 ? ids(members(k.slice(2))) : [k]; }
  function layers() {
    var host = $('layers'); host.textContent = '';
    var on = Object.create(null); selKeys().forEach(function (k) { on[k] = 1; });
    var done = Object.create(null);
    function acts(row, label, list) {
      var a = mk('span', 'a');
      function b(txt, title, pressed, fn) { var x = mk('button', null, txt); x.type = 'button'; x.title = title; x.setAttribute('aria-label', title + ': ' + label); if (pressed != null) x.setAttribute('aria-pressed', String(pressed)); x.onclick = function (ev) { ev.stopPropagation(); fn(); }; a.appendChild(x); }
      var hid = list.every(function (e) { return e.hide; }), lk = list.every(function (e) { return e.lock; });
      b(hid ? '◌' : '●', hid ? 'Show' : 'Hide', !hid, function () { act(function () { list.forEach(function (e) { if (hid) delete e.hide; else e.hide = true; }); }); });
      b(lk ? '🔒' : '🔓', lk ? 'Unlock' : 'Lock', lk, function () { act(function () { list.forEach(function (e) { if (lk) delete e.lock; else e.lock = true; }); }); });
      b('▲', 'Bring forward', null, function () { setSel(ids(list)); zMove(1); });
      b('▼', 'Send backward', null, function () { setSel(ids(list)); zMove(-1); });
      row.appendChild(a);
    }
    function grip(row) { var gr = mk('span', 'gr', '⠿'); gr.title = 'Drag to reorder'; gr.setAttribute('aria-hidden', 'true'); gr.addEventListener('pointerdown', function (ev) { layerDrag(ev, row); }); row.insertBefore(gr, row.firstChild); }
    function elRow(e, child) {
      var g = doc.geo[st.fmt][e.id], row = mk('div', 'ly' + (child ? ' ch' : '') + (on[e.id] ? ' on' : '')); row.dataset.k = e.id;
      var label = e.name || TYPES[e.t]; if (e.txt && e.t !== 'logo') label += ' — ' + e.txt.replace(/\s+/g, ' ').slice(0, 22);
      var nm = mk('span', 'n' + (e.hide || g.hd ? ' off' : ''), label); nm.title = 'Double-click to rename'; row.appendChild(nm);
      acts(row, label, [e]); grip(row);
      row.onclick = function (ev) { rowClick(ev, row, child ? e.gp : null); };
      host.appendChild(row);
    }
    doc.els.slice().reverse().forEach(function (e) {
      if (!e.gp) { elRow(e, false); return; }
      if (done[e.gp]) return; done[e.gp] = 1;
      var gid = e.gp, list = members(gid), open = st.gopen[gid] !== false, gname = (doc.groups[gid] && doc.groups[gid].name) || 'Group';
      var row = mk('div', 'ly grp' + (list.every(function (x) { return on[x.id]; }) ? ' on' : '')); row.dataset.k = 'g:' + gid;
      var tg = mk('button', 'gt', open ? '▾' : '▸'); tg.type = 'button'; tg.setAttribute('aria-expanded', String(open)); tg.setAttribute('aria-label', (open ? 'Fold ' : 'Unfold ') + gname);
      tg.onclick = function (ev) { ev.stopPropagation(); st.gopen[gid] = !open; layers(); };
      var nm = mk('span', 'n', gname + ' · ' + list.length); nm.title = 'Double-click to rename'; row.appendChild(tg); row.appendChild(nm);
      acts(row, gname, list); grip(row);
      row.onclick = function (ev) { rowClick(ev, row, null); };
      host.appendChild(row);
      if (open) list.slice().reverse().forEach(function (m) { elRow(m, true); });
    });
  }
  var lastRow = { k: '', t: 0 };
  function startRename(k) {
    var nm = $('layers').querySelector('.ly[data-k="' + k + '"] .n'); if (!nm) return;
    if (k.indexOf('g:') === 0) { var gid = k.slice(2); rename(nm, (doc.groups[gid] && doc.groups[gid].name) || 'Group', function (v) { if (doc.groups[gid]) doc.groups[gid].name = v; }); }
    else { var e = elById(k); if (e) rename(nm, e.name || TYPES[e.t], function (v) { e.name = v; }); }
  }
  function rowClick(ev, row, grp) {
    var k = row.dataset.k, ks = rowKeys(k), cur = selKeys(), now = Date.now(), mods = ev.shiftKey || ev.ctrlKey || ev.metaKey;
    if (!mods && lastRow.k === k && now - lastRow.t < 450 && ev.target.closest('.n')) { lastRow = { k: '', t: 0 }; startRename(k); return; }
    lastRow = { k: k, t: now };
    if (ev.shiftKey && st.lanchor) {
      var order = Array.prototype.map.call($('layers').querySelectorAll('.ly'), function (r) { return r.dataset.k; }), i = order.indexOf(st.lanchor), j = order.indexOf(k);
      if (i >= 0 && j >= 0) { var l = []; order.slice(Math.min(i, j), Math.max(i, j) + 1).forEach(function (x) { l = l.concat(rowKeys(x)); }); st.grp = null; setSel(l); afterSel(); return; }
    }
    if (ev.ctrlKey || ev.metaKey) { st.grp = null; setSel(ks.every(function (x) { return cur.indexOf(x) >= 0; }) ? cur.filter(function (x) { return ks.indexOf(x) < 0; }) : cur.concat(ks)); }
    else { st.grp = grp; setSel(ks); }
    st.lanchor = k; afterSel();
  }
  function rename(nm, old, set) {
    var inp = mk('input'); inp.type = 'text'; inp.maxLength = 40; inp.value = old; inp.className = 'rn'; inp.setAttribute('aria-label', 'New name');
    nm.textContent = ''; nm.appendChild(inp); inp.focus(); inp.select();
    var fin_ = false; function fin(keep) { if (fin_) return; fin_ = true; var v = str(inp.value, 40).trim(); if (keep && v && v !== old) act(function () { set(v); }); else layers(); }
    inp.onkeydown = function (ev) { ev.stopPropagation(); if (ev.key === 'Enter') fin(true); if (ev.key === 'Escape') fin(false); };
    inp.onblur = function () { fin(true); }; inp.onclick = function (ev) { ev.stopPropagation(); };
  }
  // reorder by dragging a row's grip: above / below the row under the pointer; onto a group's item = into that group
  function layerDrag(ev, src) {
    ev.preventDefault(); ev.stopPropagation();
    var host = $('layers'), ld = { src: src, target: null, above: false };
    src.classList.add('drag');
    function clear() { host.querySelectorAll('.dropa,.dropb').forEach(function (r) { r.classList.remove('dropa'); r.classList.remove('dropb'); }); }
    function mv(e) {
      var hit = document.elementFromPoint(e.clientX, e.clientY), t = hit && hit.closest && hit.closest('#layers .ly'); clear(); ld.target = null;
      if (!t || t === src) return; var r = t.getBoundingClientRect(); ld.above = e.clientY < r.top + r.height / 2; ld.target = t; t.classList.add(ld.above ? 'dropb' : 'dropa');
    }
    function up() { document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up); document.removeEventListener('pointercancel', up); clear(); src.classList.remove('drag'); if (ld.target) dropLayer(ld); }
    document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up); document.addEventListener('pointercancel', up);
  }
  function dropLayer(ld) {
    var sk = ld.src.dataset.k, isG = sk.indexOf('g:') === 0, moving = rowKeys(sk), t = ld.target, tk = t.dataset.k;
    if (isG && t.classList.contains('ch')) { var te0 = elById(tk); if (te0 && te0.gp) tk = 'g:' + te0.gp; }      // a group drops beside a group, never inside one
    if (rowKeys(tk).some(function (k) { return moving.indexOf(k) >= 0; })) return;
    act(function () {
      var mvEls = doc.els.filter(function (e) { return moving.indexOf(e.id) >= 0; }), rest = doc.els.filter(function (e) { return moving.indexOf(e.id) < 0; }), idx, join = null;
      if (tk.indexOf('g:') === 0) { var m = rest.filter(function (e) { return e.gp === tk.slice(2); }); if (!m.length) return; idx = ld.above ? rest.indexOf(m[m.length - 1]) + 1 : rest.indexOf(m[0]); }
      else { var te = elById(tk); if (!te) return; idx = rest.indexOf(te) + (ld.above ? 1 : 0); if (te.gp && t.classList.contains('ch')) join = te.gp; }
      if (!isG) mvEls.forEach(function (e) { if (join && e.t !== 'prods') e.gp = join; else delete e.gp; });
      Array.prototype.splice.apply(rest, [idx, 0].concat(mvEls)); doc.els = rest; normGroups(doc);
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
      setSel(['p:' + addTo(curItems(), p, st.fmt)]);
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
  $('ps-fr').onclick = function () { var l = curItems(); if (st.psel == null) return; act(function () { l.push(l.splice(st.psel, 1)[0]); setSel(['p:' + (l.length - 1)]); }); };
  $('ps-del').onclick = removeSel;
  $('ps-reset').onclick = function () { var t = tplById[doc.tpl]; if (!t) { setStatus('This design has no template to reset from', 'warn'); return; } var src = fromTemplate(t); act(function () { doc.sets[st.fmt][st.set] = src.sets[st.fmt][st.set]; dropProdSel(); }); };

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
    Store.delImage(id).then(function () { act(function () { FMTS.forEach(function (f) { doc.sets[f] = doc.sets[f].map(function (l) { return l.filter(function (it) { return it.p !== p; }); }); }); dropProdSel(); }); delete images[id]; buildPk(); draw(); setStatus('Deleted', 'ok'); })
      .catch(function (e) { setStatus('Not deleted: ' + ((e && e.message) || 'try again'), 'warn'); });
  }

  // ── colours: the design's own colours, the brand palette, and the one colour control every swatch opens ──
  var COLP = ['col', 'fill', 'fill2', 'bc', 'shc'];
  function docColours(d) {
    var n = Object.create(null), see = function (v) { if (typeof v === 'string' && HEX.test(v)) { var k = v.slice(0, 7).toUpperCase(); n[k] = (n[k] || 0) + 1; } };
    d.els.forEach(function (e) { COLP.forEach(function (p) { see(e[p]); }); });
    var b = d.bg; if (b.k !== 'none') { if (b.k === 'linear' || b.k === 'radial') gradStops(b).forEach(function (s) { see(s.c); }); else see(b.c1); }
    if (b.pat !== 'none' && b.po) see(b.pc); if (b.k === 'image') see(b.oc);
    return Object.keys(n).sort(function (a, c) { return n[c] - n[a]; }).map(function (c) { return { c: c, n: n[c] }; });
  }
  var KBB_PAL = ['#E8306F', '#1F1B1E', '#FFFFFF', '#F7D6DF', '#C9A24A', '#F3EDE6', '#00843D', '#EF3340', '#000000'];
  function brandPalette() { var l = [brand.c1, brand.c2]; KBB_PAL.forEach(function (c) { if (l.indexOf(c) < 0) l.push(c); }); return l; }
  // replace one colour (any opacity of it) everywhere -- or only in the picked items -- keeping each use's own opacity
  function replaceIn(d, from, to, only) {
    var n = 0, f6 = from.slice(0, 7).toUpperCase();
    var sw = function (v) { if (typeof v !== 'string' || !HEX.test(v) || v.slice(0, 7).toUpperCase() !== f6) return v; n++; return to.length === 9 ? to : to.slice(0, 7) + v.slice(7); };
    d.els.forEach(function (e) { if (only && !only[e.id]) return; COLP.forEach(function (p) { if (e[p]) e[p] = sw(e[p]); }); });
    if (!only) { var b = d.bg; b.c1 = sw(b.c1); b.c2 = sw(b.c2); b.pc = sw(b.pc); if (b.oc) b.oc = sw(b.oc); if (b.stops) b.stops.forEach(function (s) { s.c = sw(s.c); }); }
    return n;
  }
  var CC = (function () {
    var pop = $('ccp'), pick = $('cc-pick'), hexIn = $('cc-hex'), al = $('cc-a'), cur = { h: '#FFFFFF', a: 100 }, cb = null, anchor = null, live = false, recent = [];
    try { recent = (JSON.parse(localStorage.getItem('kbbas:recent')) || []).filter(function (c) { return typeof c === 'string' && HEX.test(c); }).slice(0, 18); } catch (e) { recent = []; }
    function parse(v) { return typeof v === 'string' && HEX.test(v) ? { h: v.slice(0, 7).toUpperCase(), a: v.length === 9 ? Math.round(parseInt(v.slice(7), 16) / 2.55) : 100 } : { h: '#FFFFFF', a: v === 'transparent' ? 0 : 100 }; }
    function val() { return cur.h + (cur.a >= 100 ? '' : ('0' + Math.round(cur.a * 255 / 100).toString(16)).slice(-2).toUpperCase()); }
    function sync(keepHex) { pick.value = cur.h.toLowerCase(); if (!keepHex) hexIn.value = val(); al.value = cur.a; $('cc-av').textContent = cur.a + '%'; pop.style.setProperty('--c', cur.h); }
    function emit(fin) { if (!cb) return; live = !fin; cb(val(), fin); if (fin) remember(val()); }
    function remember(v) { recent = [v].concat(recent.filter(function (x) { return x !== v; })).slice(0, 18); try { localStorage.setItem('kbbas:recent', JSON.stringify(recent)); } catch (e) {} fill(); }
    function sws(host, list) {
      host.textContent = ''; host.previousElementSibling.hidden = !list.length;
      list.forEach(function (c) { var b = mk('button'); b.type = 'button'; b.title = c; b.setAttribute('aria-label', 'Use ' + c); b.style.setProperty('--c', c); b.onclick = function () { cur = parse(c); sync(); emit(true); }; host.appendChild(b); });
    }
    function fill() { sws($('cc-rec'), recent); sws($('cc-doc'), doc ? docColours(doc).map(function (x) { return x.c; }).slice(0, 18) : []); sws($('cc-brand'), brandPalette()); }
    function place() {
      if (window.innerWidth <= 600) { pop.style.left = ''; pop.style.top = ''; return; }        // phones: a sheet along the bottom (CSS)
      var r = anchor.getBoundingClientRect(), W = pop.offsetWidth, H = pop.offsetHeight, x = Math.min(window.innerWidth - W - 8, Math.max(8, r.left)), y = r.bottom + 6;
      if (y + H > window.innerHeight - 8) y = Math.max(8, r.top - H - 6);
      pop.style.left = x + 'px'; pop.style.top = y + 'px';
    }
    function close() { if (pop.hidden) return; pop.hidden = true; if (live) emit(true); cb = null; if (anchor) anchor.setAttribute('aria-expanded', 'false'); anchor = null; }
    pick.addEventListener('input', function () { cur.h = pick.value.toUpperCase(); sync(); emit(false); });
    pick.addEventListener('change', function () { emit(true); });
    hexIn.addEventListener('input', function () { var v = hexIn.value.trim(); if (v[0] !== '#') v = '#' + v; if (/^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(v)) { cur = parse(v.toUpperCase()); sync(true); emit(false); } });
    hexIn.addEventListener('change', function () { sync(); emit(true); });
    hexIn.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); sync(); emit(true); } });
    al.addEventListener('input', function () { cur.a = num(al.value, 0, 100, 100); sync(); emit(false); });
    al.addEventListener('change', function () { emit(true); });
    var eye = $('cc-eye');
    if (!window.EyeDropper) eye.hidden = true;
    else eye.onclick = function () { try { new window.EyeDropper().open().then(function (r) { var v = String(r && r.sRGBHex || '').toUpperCase(); if (/^#[0-9A-F]{6}$/.test(v)) { cur = { h: v, a: cur.a }; sync(); emit(true); } }).catch(function () {}); } catch (e) {} };
    $('cc-x').onclick = close;
    document.addEventListener('pointerdown', function (ev) { if (!pop.hidden && !pop.contains(ev.target) && !(anchor && anchor.contains(ev.target))) close(); }, true);
    return {
      open: function (a, v, fn) { if (anchor === a && !pop.hidden) { close(); return; } close(); anchor = a; cb = fn; cur = parse(v); live = false; sync(); fill(); pop.hidden = false; place(); a.setAttribute('aria-expanded', 'true'); },
      close: close, isOpen: function () { return !pop.hidden; }
    };
  })();

  // ── Style tab: background (none / solid / linear / radial / picture, + a pattern on top) + colours + brand kit ──
  var patSel = $('bg-pat'); Object.keys(PATS).forEach(function (k) { var o = mk('option', null, PATS[k]); o.value = k; patSel.appendChild(o); });
  var gbar = $('gbar');
  function panelStyle() {
    var b = doc.bg, kv = st.bgv || b.k, stops = gradStops(b);
    if (st.gsi >= stops.length) st.gsi = stops.length - 1;
    $('bg-kind').querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x.dataset.v === kv)); });
    document.querySelectorAll('[data-bgk]').forEach(function (n) { n.hidden = n.dataset.bgk.split(' ').indexOf(kv) < 0; });
    document.querySelectorAll('[data-bgc]').forEach(function (s) { var p = s.dataset.bgc; paintSw(s, p === 'stop' ? stops[st.gsi].c : p === 'oc' ? (b.oc || (b.ov ? '#000000' + ('0' + Math.round(b.ov * 255 / 100).toString(16)).slice(-2).toUpperCase() : 'transparent')) : b[p]); });
    document.querySelectorAll('[data-bg]').forEach(function (n) { setV(n, b[n.dataset.bg]); });
    $('bg-angv').textContent = b.ang + '°';
    drawGbar(stops);
    setV($('gs-p'), stops[st.gsi].p); $('gs-pv').textContent = Math.round(stops[st.gsi].p) + '%'; $('gs-del').disabled = stops.length <= 2;
    $('bg-fit').querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x.dataset.v === b.fit)); });
    setV(patSel, b.pat);
    $('bg-noimg').disabled = !b.img;
    dcols();
    setV($('br-c1'), brand.c1); setV($('br-c2'), brand.c2); $('br-on').checked = brand.on; $('br-nologo').disabled = !brand.logo;
  }
  function drawGbar(stops) {
    gbar.style.setProperty('--g', 'linear-gradient(90deg,' + stops.map(function (s) { return s.c + ' ' + s.p + '%'; }).join(',') + ')');
    gbar.textContent = '';
    stops.forEach(function (s, i) { var m = mk('button', 'gs' + (i === st.gsi ? ' on' : '')); m.type = 'button'; m.dataset.i = i; m.style.left = s.p + '%'; m.style.setProperty('--c', s.c); m.setAttribute('aria-label', 'Colour stop ' + (i + 1) + ' at ' + Math.round(s.p) + '%'); gbar.appendChild(m); });
  }
  // every background write: a whole cleaned background, so a bad value can never reach the canvas
  function bgSet(patch, fin) { begin(); doc.bg = cleanBg(Object.assign({}, doc.bg, patch), images); redrawStageOnly(); if (fin) { end(); panelStyle(); } }
  function setStops(list, selObj, fin) {
    list = list.slice().sort(function (a, c) { return a.p - c.p; }); st.gsi = Math.max(0, list.indexOf(selObj));
    bgSet({ stops: list.map(function (s) { return { c: s.c, p: Math.round(s.p * 10) / 10 }; }), c1: list[0].c, c2: list[list.length - 1].c }, fin);
  }
  function mixHex(a, c, t) { var x = parseInt(a.slice(1, 7), 16), y = parseInt(c.slice(1, 7), 16), m = function (s) { return Math.round((x >> s & 255) * (1 - t) + (y >> s & 255) * t); }; return '#' + ((1 << 24) + (m(16) << 16) + (m(8) << 8) + m(0)).toString(16).slice(1).toUpperCase(); }
  $('bg-kind').addEventListener('click', function (ev) {
    var b = ev.target.closest('button'); if (!b) return; var v = b.dataset.v;
    if (v === 'image' && !doc.bg.img) { st.bgv = 'image'; panelStyle(); setStatus('Upload a picture for the background', 'warn'); return; }
    st.bgv = null; act(function () { doc.bg = cleanBg(Object.assign({}, doc.bg, { k: v }), images); });
  });
  gbar.addEventListener('pointerdown', function (ev) {
    var r = gbar.getBoundingClientRect(), p = Math.max(0, Math.min(100, (ev.clientX - r.left) / r.width * 100)), list = gradStops(doc.bg).map(function (s) { return { c: s.c, p: s.p }; }), m = ev.target.closest('.gs'), o;
    if (m) { o = list[+m.dataset.i]; st.gsi = +m.dataset.i; }
    else {                                                         // a new stop, in the colour the gradient already has there
      if (list.length >= 8) { setStatus('Up to 8 colour stops', 'warn'); return; }
      var i = 0; while (i < list.length - 1 && list[i + 1].p < p) i++; var a = list[i], c = list[Math.min(i + 1, list.length - 1)];
      o = { c: p <= a.p ? a.c : p >= c.p ? c.c : mixHex(a.c, c.c, (p - a.p) / ((c.p - a.p) || 1)), p: p }; list.push(o); setStops(list, o, false);
    }
    drawGbar(gradStops(doc.bg)); setV($('gs-p'), o.p); $('gs-pv').textContent = Math.round(o.p) + '%';
    paintSw(document.querySelector('[data-bgc="stop"]'), o.c);
    gbar.setPointerCapture(ev.pointerId); ev.preventDefault();
    var mv = function (e) { o.p = Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)); setStops(list, o, false); drawGbar(gradStops(doc.bg)); $('gs-pv').textContent = Math.round(o.p) + '%'; };
    var up = function () { gbar.removeEventListener('pointermove', mv); gbar.removeEventListener('pointerup', up); gbar.removeEventListener('pointercancel', up); end(); panelStyle(); };
    gbar.addEventListener('pointermove', mv); gbar.addEventListener('pointerup', up); gbar.addEventListener('pointercancel', up);
  });
  $('gs-p').addEventListener('input', function () { var list = gradStops(doc.bg).map(function (s) { return { c: s.c, p: s.p }; }), o = list[st.gsi]; o.p = num(this.value, 0, 100, 0); setStops(list, o, false); drawGbar(gradStops(doc.bg)); $('gs-pv').textContent = Math.round(o.p) + '%'; });
  $('gs-p').addEventListener('change', function () { end(); panelStyle(); });
  $('gs-del').onclick = function () { var list = gradStops(doc.bg).map(function (s) { return { c: s.c, p: s.p }; }); if (list.length <= 2) return; list.splice(st.gsi, 1); setStops(list, list[Math.max(0, st.gsi - 1)], true); };
  $('gs-rev').onclick = function () { var list = gradStops(doc.bg).map(function (s) { return { c: s.c, p: 100 - s.p }; }); setStops(list, list[0], true); };
  document.querySelectorAll('[data-bg]').forEach(function (n) {
    n.addEventListener('input', function () { var o = {}; o[n.dataset.bg] = n.tagName === 'SELECT' ? n.value : +n.value; if (n.dataset.bg === 'ang') $('bg-angv').textContent = n.value + '°'; bgSet(o, false); });
    n.addEventListener('change', function () { end(); panelStyle(); });
  });
  $('bg-fit').addEventListener('click', function (ev) { var b = ev.target.closest('button'); if (b) bgSet({ fit: b.dataset.v }, true); });
  $('style-pane').addEventListener('click', function (ev) {
    var s = ev.target.closest('[data-bgc]'); if (!s) return; var p = s.dataset.bgc, b = doc.bg;
    var v0 = p === 'stop' ? gradStops(b)[st.gsi].c : p === 'oc' ? b.oc || (b.ov ? '#000000' + ('0' + Math.round(b.ov * 255 / 100).toString(16)).slice(-2).toUpperCase() : '#00000066') : b[p];
    CC.open(s, v0, function (v, fin) {
      if (p === 'stop') { var list = gradStops(doc.bg).map(function (x) { return { c: x.c, p: x.p }; }), o = list[st.gsi]; o.c = v; setStops(list, o, fin); }
      else { var o2 = {}; o2[p] = v; if (p === 'oc') o2.ov = 0; bgSet(o2, fin); }
      paintSw(s, v); if (!fin) drawGbar(gradStops(doc.bg));
    });
  });
  $('bg-file').onchange = function () { var f = this.files && this.files[0]; this.value = ''; if (!f) return;
    readPicture(f, { cut: false, max: 2000, kind: 'bg' }).then(function (r) { st.bgv = null; act(function () { doc.bg.img = r.id; doc.bg.k = 'image'; }); }).catch(pictureError); };
  $('bg-noimg').onclick = function () { st.bgv = null; act(function () { doc.bg.img = ''; doc.bg.k = 'radial'; }); };
  // "Colours in this design": tap one, pick its new colour -- everywhere, or only in the picked items
  function dcols() {
    var h = $('dcols'); h.textContent = '';
    docColours(doc).forEach(function (x) { var b = mk('button'); b.type = 'button'; b.dataset.c = x.c; b.title = x.c + ' · used ' + x.n + '×'; b.setAttribute('aria-label', 'Replace ' + x.c + ' (used ' + x.n + ' times)'); b.style.setProperty('--c', x.c); h.appendChild(b); });
  }
  $('dcols').addEventListener('click', function (ev) {
    var b = ev.target.closest('button'); if (!b) return; var from = b.dataset.c, only = null;
    if ($('rc-sel').checked) { only = Object.create(null); selEls().forEach(function (e) { only[e.id] = 1; if (e.gp && e.gp !== st.grp) members(e.gp).forEach(function (m) { only[m.id] = 1; }); }); if (!Object.keys(only).length) { setStatus('Pick items on the ad first, or untick "Only in the selection"', 'warn'); return; } }
    CC.open(b, from, function (v, fin) {
      begin(); var base = JSON.parse(pending), n = replaceIn(base, from, v, only); doc = base; redrawStageOnly();
      if (fin) { end(); draw(); setStatus('Changed ' + n + ' use' + (n === 1 ? '' : 's') + ' of ' + from + ' to ' + v + ' — Ctrl+Z to undo', 'ok'); }
    });
  });

  // ── the arrange bar (over the ad) and the touch "Select" toggle ──
  function abarSync() {
    var keys = selKeys().filter(function (k) { return k !== 'prods'; }), us = keys.length ? selUnits() : [], els = selEls().filter(function (e) { return e.t !== 'prods'; });
    $('abar').querySelectorAll('button').forEach(function (b) { b.disabled = !keys.length; });   // always on show (no jump under the pointer), idle with nothing picked
    if (!keys.length) return;
    var one = us.length < 2;
    $('alto').querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.v === (one ? 'art' : st.alto))); b.disabled = one; });
    $('abar').querySelectorAll('[data-ds]').forEach(function (b) { b.disabled = us.length < 3; });
    $('abar').querySelectorAll('[data-mt]').forEach(function (b) { b.disabled = keys.length < 2; });
    $('ab-grp').disabled = els.length < 2 || !!selGroup(); $('ab-ungrp').disabled = !els.some(function (e) { return e.gp; });
  }
  $('abar').addEventListener('click', function (ev) {
    var b = ev.target.closest('button'); if (!b || b.disabled) return;
    if (b.dataset.al) alignSel(b.dataset.al); else if (b.dataset.ds) distribute(b.dataset.ds); else if (b.dataset.mt) matchSize(b.dataset.mt);
    else if (b.parentNode.id === 'alto') { st.alto = b.dataset.v; abarSync(); }
    else if (b.id === 'ab-grp') groupSel(); else if (b.id === 'ab-ungrp') ungroupSel();
  });
  $('pick').onclick = function () { st.pick = !st.pick; this.setAttribute('aria-pressed', String(st.pick)); this.textContent = st.pick ? '☑ Select: on' : '☐ Select'; setStatus(st.pick ? 'Tap items to add or remove them · tap the empty ad to clear' : '', 'ok'); };
  function saveBrand() { if (co.id !== 'kbb') { co.studio.brand = brand; return saveCos(); } return Store.putKV('brand', brand).catch(function () {}); }
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
    var A = doc.anim; st.grp = null; setSel([]); st.set = 0; draw(); stage.classList.add('playing'); animateProds();
    $('play').textContent = '■ Stop'; $('play2').textContent = '■ Stop'; playStart = Date.now();
    playT = setInterval(function () {
      if ((Date.now() - playStart) / 1000 >= A.len) { stopPlay(); setStatus('Played ' + A.len + ' s — as the video will run', 'ok'); return; }
      st.set = (st.set + 1) % 3; var cv = renderCanvas(doc, st.fmt, st.set, 'edit'); stage.textContent = ''; stage.appendChild(cv); fitText(cv); chipsTop(); animateProds();
    }, A.sec * 1000);
  }
  $('play').onclick = play; $('play2').onclick = play;

  // ── templates browser ──
  // ── templates browser: this company's set (or another, or all) · category · favourites · country · size · search ──
  var thumbsBuilt = '', tgEl = $('tg');
  st.tset = ''; st.tcty = ''; st.tsize = 'post';
  function setOf() { return st.tset || co.studio.tplSet; }
  function inSet(t) { var s = setOf(); return s === 'all' || (s === 'planet-careers' ? t.co === 'pc' : t.co !== 'pc'); }
  function visList() { return KT.LIST.filter(function (t) { return inSet(t) && (!st.tcty || !!t.cty); }); }
  function isFav(id) { return co.studio.favs.indexOf(id) >= 0; }
  function thumbsDirty() { thumbsBuilt = ''; if (paneOn('tpl')) buildThumbs(); }
  function buildCats() {
    var h = $('cats'), l = visList(), s = setOf(); h.textContent = '';
    var cs = [['all', 'All']].concat(s === 'kbb' ? KT.CATS.slice(1) : s === 'planet-careers' ? KT.PCCATS : KT.CATS.slice(1).concat(KT.PCCATS), [['fav', '★ Favourites']]);
    function count(c) { return c === 'all' ? l.length : c === 'fav' ? l.filter(function (t) { return isFav(t.id); }).length : l.filter(function (t) { return t.cat === c; }).length; }
    if (!cs.some(function (c) { return c[0] === st.cat && (count(c[0]) || c[0] === 'all' || c[0] === 'fav'); })) st.cat = 'all';
    cs.forEach(function (c) { var n = count(c[0]); if (!n && c[0] !== 'all' && c[0] !== 'fav') return;
      var b = mk('button', 'chip', c[1] + ' · ' + n); b.type = 'button'; b.dataset.cat = c[0]; b.setAttribute('aria-pressed', String(st.cat === c[0])); b.onclick = function () { st.cat = c[0]; buildCats(); filterThumbs(); }; h.appendChild(b); });
    $('tq').placeholder = 'Search ' + l.length + ' templates — ' + (s === 'planet-careers' ? 'study visa, Canada, IELTS…' : 'Eid, SPF, flash sale…');
  }
  function buildThumbs() {
    var key = [co.id, setOf(), st.tcty, st.tsize, JSON.stringify(brand), JSON.stringify(co.colors), co.name, co.contactLine].join('|');
    if (thumbsBuilt === key) { filterThumbs(); return; } thumbsBuilt = key; tgEl.textContent = '';
    tgEl.dataset.size = st.tsize;
    var allC = KT.CTRY_LIST.map(function (c) { return c.name + ' ' + c.dn; }).join(' ');
    visList().forEach(function (t) {
      var C = KT.CTRY[st.tcty || t.cty], b = mk('button'); b.type = 'button'; b.dataset.id = t.id; b.dataset.cat = t.cat;
      b.dataset.q = (t.name + ' ' + t.tags + ' ' + t.cat + ' ' + t.fam + (t.cty ? ' country ' + allC : '')).toLowerCase();
      b.title = t.name + (C ? ' · ' + C.name : ''); b.setAttribute('aria-label', 'Use template: ' + t.name);
      var d = fromTemplate(t, co, st.tcty || t.cty); b.appendChild(renderCanvas(d, st.tsize, 0, 'thumb')); b.appendChild(mk('span', 'nm', (isFav(t.id) ? '★ ' : '') + t.name));
      b.onclick = function () { useTemplate(t, st.tcty); };
      tgEl.appendChild(b);
    });
    fitText(tgEl); filterThumbs();
  }
  function filterThumbs() { var q = st.q.trim().toLowerCase(); tgEl.querySelectorAll('button').forEach(function (b) { b.hidden = !((st.cat === 'all' || (st.cat === 'fav' ? isFav(b.dataset.id) : b.dataset.cat === st.cat)) && (!q || q.split(/\s+/).every(function (w) { return b.dataset.q.indexOf(w) >= 0; }))); b.setAttribute('aria-current', String(b.dataset.id === doc.tpl)); }); favBtn(); }
  $('tq').addEventListener('input', function () { st.q = this.value; filterThumbs(); });
  function favBtn() { var t = doc && tplById[doc.tpl], b = $('fav'); b.disabled = !t; b.textContent = t && isFav(t.id) ? '★ Favourite' : '☆ Add to favourites'; b.setAttribute('aria-pressed', String(!!t && isFav(t.id))); }
  $('fav').onclick = function () {
    var t = tplById[doc.tpl]; if (!t) return; var l = co.studio.favs, i = l.indexOf(t.id);
    if (i >= 0) l.splice(i, 1); else l.push(t.id);
    saveCos(); thumbsBuilt = ''; buildCats(); buildThumbs(); setStatus(i >= 0 ? t.name + ' removed from ' + co.name + "'s favourites" : t.name + ' is one of ' + co.name + "'s favourites", 'ok');
  };
  (function () {
    var s = $('tf-set'); [['', "This company's templates"], ['kbb', 'KBB · beauty & retail'], ['planet-careers', 'Planet Careers · study & visas'], ['all', 'All templates']].forEach(function (o) { var x = mk('option', null, o[1]); x.value = o[0]; s.appendChild(x); });
    s.onchange = function () { st.tset = this.value; st.cat = 'all'; buildCats(); thumbsDirty(); };
    var c = $('tf-cty'); var x0 = mk('option', null, 'Any country'); x0.value = ''; c.appendChild(x0);
    KT.CTRY_LIST.forEach(function (k) { var x = mk('option', null, k.name); x.value = k.code; c.appendChild(x); });
    c.onchange = function () { st.tcty = this.value; buildCats(); thumbsDirty(); };
    var z = $('tf-size'); FMTS.forEach(function (f) { var x = mk('option', null, FLAB[f]); x.value = f; z.appendChild(x); });
    z.onchange = function () { st.tsize = this.value; thumbsDirty(); };
  })();
  function useTemplate(t, cty) {
    stopPlay();
    act(function () { var d = fromTemplate(t, co, cty || (doc && doc.tpl === t.id && doc.cty) || '', (doc && doc.tpl === t.id && doc.occ) || ''); d.id = doc.id; d.name = t.name; doc = d; st.grp = null; setSel([]); st.set = 0; });
    $('pname').value = doc.name; filterThumbs(); setStatus(t.name + ' applied — Ctrl+Z to go back', 'ok');
  }

  // ── country picker: one click swaps the flag, outline and landmark, the flag colours and the country's name
  //    (English and Arabic) everywhere in the design -- the owner's own edits to everything else stay ──
  function swapCountry(code) {
    var A = KT.CTRY[doc.cty], B = KT.CTRY[code]; if (!B || !A || A === B) return;
    var pairs = { n: [A.name, B.name], d: [A.dn, B.dn], a: [A.ar, B.ar] };
    doc.els.forEach(function (e) {
      if (e.t === 'art') { var m = /^(flag|map):([a-z]{2})$/.exec(e.art); if (m && m[2] === A.code) e.art = m[1] + ':' + B.code; if (e.art === 'lm:' + A.lm) e.art = 'lm:' + B.lm; }
      if (e.cy) { var c = e.cy === 'ca' ? B.acc : B.colors[+e.cy.slice(1) - 1]; if (c) { var k = e.t === 'shape' || e.t === 'btn' ? 'fill' : 'col'; var old = e[k]; e[k] = c + (old && old.length === 9 ? old.slice(7) : ''); } }
      if (typeof e.txt === 'string') {
        var t = e.txt;
        (e.cyk || '').split('').forEach(function (k) { if (pairs[k]) t = t.split(pairs[k][0]).join(pairs[k][1]); });
        if (t.indexOf(A.ar) >= 0) t = t.split(A.ar).join(B.ar);               // Arabic names cannot be mistaken for anything else
        e.txt = t.slice(0, 200);
      }
    });
    doc.cty = B.code;
  }
  function setCountry(code) { if (!doc.cty || !KT.CTRY[code] || code === doc.cty) return; var from = KT.CTRY[doc.cty].name; act(function () { swapCountry(code); }); setStatus(from + ' → ' + KT.CTRY[code].name + ' — Ctrl+Z to go back', 'ok'); }
  (function () { var s = $('cty'); KT.CTRY_LIST.forEach(function (k) { var x = mk('option', null, k.name); x.value = k.code; s.appendChild(x); }); s.onchange = function () { setCountry(this.value); }; })();
  function ctyBar() { var on = !!(doc && doc.cty); $('ctyw').hidden = !on; if (on) setV($('cty'), doc.cty); occBar(); }

  // ── occupation picker (Work Abroad): one click swaps the worker figure (a photo the owner put in stays), the
  //    occupation's name in English and Arabic ("Welders", "a welder", "لحّام"), the tool icons and names and the accent
  //    colours -- everything else he edited stays; one Ctrl+Z undoes it ──
  function swapOccupation(id) {
    var A = KT.OCC[doc.occ], B = KT.OCC[id]; if (!A || !B || A === B) return;
    var pairs = { l: [A.l, B.l], p: [A.pl[0], B.pl[0]], q: [A.pl[1], B.pl[1]], o: [A.t[0], B.t[0]], r: [A.t[1], B.t[1]],
      0: [A.tools[0][1], B.tools[0][1]], 1: [A.tools[1][1], B.tools[1][1]], 2: [A.tools[2][1], B.tools[2][1]] };
    doc.els.forEach(function (e) {
      if (e.t === 'art' && e.art === 'fig:' + A.id) e.art = 'fig:' + B.id;
      if (e.t === 'art' && e.oti != null) e.art = 'oi:' + B.tools[e.oti][0];
      if (e.oy) { var c = B.c[{ u: 0, t: 1, p: 2, a: 3 }[e.oy]], k = e.t === 'shape' ? (e.fill2 ? 'fill2' : 'fill') : e.t === 'btn' ? 'fill' : 'col', old = e[k]; if (c) e[k] = c + (old && old.length === 9 ? old.slice(7) : ''); }
      if (typeof e.txt === 'string' && e.ock) {
        var t = e.txt;
        'lpqor012'.split('').forEach(function (k) { if (e.ock.indexOf(k) >= 0 && pairs[k][0]) t = t.split(pairs[k][0]).join(pairs[k][1]); });
        e.txt = t.slice(0, 200);
      }
    });
    doc.occ = B.id;
  }
  function setOccupation(id) { if (!doc.occ || !has(KT.OCC, id) || id === doc.occ) return; var from = KT.OCC[doc.occ].t[0]; act(function () { swapOccupation(id); }); setStatus(from + ' → ' + KT.OCC[id].t[0] + ' — Ctrl+Z to go back', 'ok'); }
  (function () {
    var s = $('occ'), by = {};
    KT.OCCS.forEach(function (o) { (by[o.s] = by[o.s] || []).push(o); });
    KT.OSECT.forEach(function (q) { if (!by[q[0]]) return; var g = document.createElement('optgroup'); g.label = q[1]; by[q[0]].forEach(function (o) { var x = mk('option', null, o.t[0]); x.value = o.id; g.appendChild(x); }); s.appendChild(g); });
    s.onchange = function () { setOccupation(this.value); };
  })();
  function occBar() { var on = !!(doc && doc.occ); $('occw').hidden = !on; if (on) setV($('occ'), doc.occ); }

  // ── tabs ──
  // two panels (18 Nov 2026): the left holds tpl · el (Add + Layers) · prod, the right edit (the picked item's
  // properties) · style · motion · export. A tab switches its own panel only; Properties brings Elements (Add + Layers)
  // up on the left, as the one Edit tab showed all three together before.
  function paneOn(k) { var p = document.querySelector('.pane[data-pane="' + k + '"]'); return !!p && !p.hidden; }
  function tabIn(k) {
    var pane = document.querySelector('.pane[data-pane="' + k + '"]'), box = pane && pane.parentNode; if (!box) return;
    box.querySelectorAll('.tab').forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.tab === k)); });
    box.querySelectorAll('.pane').forEach(function (p) { p.hidden = p.dataset.pane !== k; });
  }
  function showTab(k) {
    tabIn(k); if (k === 'edit' && !paneOn('el')) tabIn('el');
    st.tab = k;
    if (k === 'tpl') buildThumbs();
  }
  document.querySelectorAll('.tab').forEach(function (b) { b.onclick = function () { showTab(b.dataset.tab); }; });

  // ── the shell (18 Nov 2026, "fit to screen, same like video editor"): panel widths and shut panels, remembered in this
  //    browser (kbbas:layout: lw / rw / vw px, lc / rc / vc shut); the zoom. The fit itself is CSS (container units on
  //    .stagewrap); the one measurement here is the host's size, to say what Fit is in % and to step from it. ──
  var LAY = (function () { try { var v = JSON.parse(localStorage.getItem('kbbas:layout')); return v && typeof v === 'object' ? v : {}; } catch (e) { return {}; } })();
  function laySave() { try { localStorage.setItem('kbbas:layout', JSON.stringify(LAY)); } catch (e) {} }
  var PANELS = { lw: [$('ads-main'), 'lc', $('lp-tg')], rw: [$('ads-main'), 'rc', $('rp-tg')], vw: [$('vgrid'), 'vc', $('vp-tg')] };
  // under 1180 px the left panel starts shut (the design keeps its room) until he opens or shuts it himself
  var narrow = window.matchMedia ? window.matchMedia('(min-width:900px) and (max-width:1179px)') : { matches: false };
  function shut(c) { return typeof LAY[c] === 'boolean' ? LAY[c] : c === 'lc' && narrow.matches; }
  function layApply() {
    Object.keys(PANELS).forEach(function (k) {
      var P = PANELS[k], w = +LAY[k]; if (!P[0]) return;
      if (w >= 200 && w <= 640) P[0].style.setProperty('--' + k, Math.round(w) + 'px'); else P[0].style.removeProperty('--' + k);
      P[0].classList.toggle(P[1], shut(P[1])); if (P[2]) P[2].setAttribute('aria-pressed', String(!shut(P[1])));
    });
  }
  if (narrow.addEventListener) narrow.addEventListener('change', layApply);
  Object.keys(PANELS).forEach(function (k) {
    var P = PANELS[k]; if (P[2]) P[2].addEventListener('click', function () { LAY[P[1]] = !shut(P[1]); layApply(); laySave(); });
  });
  document.querySelectorAll('.rz').forEach(function (h) {
    h.addEventListener('pointerdown', function (ev) {
      if (ev.button !== 0) return; ev.preventDefault();
      var k = h.dataset.rz, panel = h.parentNode, w0 = panel.getBoundingClientRect().width, x0 = ev.clientX, dir = k === 'lw' ? 1 : -1;
      h.setPointerCapture(ev.pointerId); h.classList.add('on');
      function mv(e) { LAY[k] = Math.max(200, Math.min(640, Math.round(w0 + (e.clientX - x0) * dir))); layApply(); }
      function up() { h.classList.remove('on'); h.removeEventListener('pointermove', mv); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up); laySave(); }
      h.addEventListener('pointermove', mv); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
    });
    h.addEventListener('dblclick', function () { delete LAY[h.dataset.rz]; layApply(); laySave(); });   // back to the default width
  });
  layApply();

  // zoom: 'fit' (CSS: the whole design in its room) or a % of the full-HD file (1080 px wide = 100 %)
  var ZS = [50, 75, 100, 125, 150, 200, 300, 400], zoom = 'fit', fitPct = 0, swrap = $('stagewrap');
  function fitNow() { if (!swrap || !swrap.clientWidth) return fitPct; var cs = getComputedStyle(swrap), w = swrap.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight), h = swrap.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
    fitPct = Math.max(1, Math.round(Math.min(w, h * PX[st.fmt][0] / PX[st.fmt][1]) / PX[st.fmt][0] * 100)); return fitPct; }
  function zoomLabel() { var v = $('zm-v'); if (v) v.textContent = (zoom === 'fit' ? fitNow() : zoom) + '%'; }
  function zoomFmt() {
    if (!swrap) return;
    stage.style.setProperty('--ar', String(PX[st.fmt][0] / PX[st.fmt][1]));
    if (zoom === 'fit') stage.style.removeProperty('--zw'); else stage.style.setProperty('--zw', PX[st.fmt][0] * zoom / 100 + 'px');
    if ($('zm-sel')) $('zm-sel').value = String(zoom); zoomLabel();
  }
  function setZoom(z) {
    if (!swrap) return; var cx = (swrap.scrollLeft + swrap.clientWidth / 2) / (swrap.scrollWidth || 1), cy = (swrap.scrollTop + swrap.clientHeight / 2) / (swrap.scrollHeight || 1);
    zoom = z === 'fit' || ZS.indexOf(+z) < 0 ? 'fit' : +z; zoomFmt();
    swrap.scrollLeft = cx * swrap.scrollWidth - swrap.clientWidth / 2; swrap.scrollTop = cy * swrap.scrollHeight - swrap.clientHeight / 2;   // keep the middle in the middle
  }
  function zoomStep(d) { var cur = zoom === 'fit' ? fitNow() : zoom, l = ZS.filter(function (z) { return d > 0 ? z > cur : z < cur; }); if (l.length) setZoom(d > 0 ? l[0] : l[l.length - 1]); }
  if (swrap) {
    $('zm-sel').addEventListener('change', function () { setZoom(this.value); this.blur(); });
    $('zm-in').onclick = function () { zoomStep(1); }; $('zm-out').onclick = function () { zoomStep(-1); }; $('zm-fit').onclick = function () { setZoom('fit'); };
    if (window.ResizeObserver) new ResizeObserver(zoomLabel).observe(swrap);
    // Ctrl/Cmd + wheel (and a trackpad pinch) zooms the design, not the page
    swrap.addEventListener('wheel', function (ev) { if (!(ev.ctrlKey || ev.metaKey)) return; ev.preventDefault(); zoomStep(ev.deltaY < 0 ? 1 : -1); }, { passive: false });
    // hold Space (or the middle button) and drag to pan when the design is bigger than its room
    var panKey = false, pan = null;
    swrap.addEventListener('pointerdown', function (ev) {
      if (!(panKey && ev.button === 0) && ev.button !== 1) return;
      ev.preventDefault(); ev.stopPropagation(); pan = { x: ev.clientX, y: ev.clientY, l: swrap.scrollLeft, t: swrap.scrollTop }; swrap.classList.add('on'); swrap.setPointerCapture(ev.pointerId);
    }, true);
    swrap.addEventListener('pointermove', function (ev) { if (!pan) return; swrap.scrollLeft = pan.l - (ev.clientX - pan.x); swrap.scrollTop = pan.t - (ev.clientY - pan.y); });
    swrap.addEventListener('pointerup', function () { pan = null; swrap.classList.remove('on'); });
    swrap.addEventListener('pointercancel', function () { pan = null; swrap.classList.remove('on'); });
    document.addEventListener('keyup', function (ev) { if (ev.key === ' ' && panKey) { panKey = false; swrap.classList.remove('pan'); } });
    window.addEventListener('blur', function () { panKey = false; swrap.classList.remove('pan'); });
    document.addEventListener('keydown', function (ev) {
      if (document.body.dataset.mode === 'video' || /input|select|textarea/i.test((document.activeElement || {}).tagName || '')) return;
      if ((ev.ctrlKey || ev.metaKey) && !ev.altKey) {
        if (ev.key === '=' || ev.key === '+') { ev.preventDefault(); zoomStep(1); } else if (ev.key === '-' || ev.key === '_') { ev.preventDefault(); zoomStep(-1); } else if (ev.key === '0') { ev.preventDefault(); setZoom('fit'); }
        return;
      }
      if (ev.key === ' ' && !ev.repeat && (swrap.scrollWidth > swrap.clientWidth || swrap.scrollHeight > swrap.clientHeight)) { ev.preventDefault(); panKey = true; swrap.classList.add('pan'); }
    });
  }

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
      l = l.filter(function (p) { return p.co === co.id; });              // each company sees its own designs
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
      end(); begin(); doc = d; end(); lastSaved = JSON.stringify(doc); st.grp = null; setSel([]); st.set = 0; $('pname').value = doc.name; draw(); listMine(); setStatus('Opened ' + doc.name, 'ok');
    });
  }
  window.addEventListener('beforeunload', function () { clearTimeout(draftT); Store.draft({ doc: doc, t: Date.now(), saved: lastSaved === JSON.stringify(doc) }, co.id); });

  // ── downloads: full HD, drawn from the same markup with the page's own embedded fonts ──
  var dlBusy = false, dlSt = $('dl-st'), dlExt = 'png';
  $('dl-fmt').addEventListener('click', function (ev) { var b = ev.target.closest('button'); if (!b) return; dlExt = b.dataset.v; this.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); }); });
  function fileBase() { return 'KBB-' + (doc.name || 'design').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); }
  function h2iOpts(f, transparent) { var W = PX[f][0], H = PX[f][1]; var o = { width: W, height: H, canvasWidth: W, canvasHeight: H, pixelRatio: 1, skipAutoScale: true, cacheBust: false, fontEmbedCSS: $('fontcss').textContent }; if (!transparent) o.backgroundColor = '#FFFFFF'; return o; }
  function host(f, cv) { var h = mk('div'); h.style.cssText = 'position:fixed;left:-' + (PX[f][0] + 300) + 'px;top:0;width:' + PX[f][0] + 'px;pointer-events:none'; h.appendChild(cv); document.body.appendChild(h); fitText(cv); return h; }
  function renderFile(f, set, ext) {
    var cv = renderCanvas(doc, f, set, 'file'), h = host(f, cv);
    var job = document.fonts.ready.then(function () { return ext === 'jpg' ? htmlToImage.toJpeg(cv, Object.assign({ quality: .95 }, h2iOpts(f))).then(function (u) { return fetch(u).then(function (r) { return r.blob(); }); }) : htmlToImage.toBlob(cv, h2iOpts(f, doc.bg.k === 'none')); });   // 'None' background = a see-through PNG
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
        .then(function (ims) { var out = { app: 'kbb-ad-studio', v: 1, exported: new Date().toISOString(), brand: brand, projects: ds, images: ims.filter(Boolean) }, day = new Date().toISOString().slice(0, 10);
          // video projects carry their clips as files, so a project with any of them travels as a .zip (project.json + media/)
          return (window.KBB_VIDEO ? window.KBB_VIDEO.exportPart() : Promise.resolve(null)).then(function (vx) {
            if (!vx || !vx.projects.length) return Store.save('KBB-Ad-Studio-project-' + day + '.json', new Blob([JSON.stringify(out)], { type: 'application/json' })).then(function () { setStatus('Project exported: ' + ds.length + ' design(s), ' + out.images.length + ' picture(s)', 'ok'); });
            out.video = { v: 1, projects: vx.projects, media: vx.media.map(function (m) { return m.meta; }) };
            var z = new JSZip(); z.file('project.json', JSON.stringify(out)); vx.media.forEach(function (m) { z.file(m.meta.file, m.blob); });
            setStatus('Packing ' + vx.media.length + ' clip(s)…');
            return z.generateAsync({ type: 'blob', compression: 'STORE' }).then(function (b) { return Store.save('KBB-Ad-Studio-project-' + day + '.zip', b); })
              .then(function () { setStatus('Project exported: ' + ds.length + ' design(s), ' + out.images.length + ' picture(s), ' + vx.projects.length + ' video(s) with ' + vx.media.length + ' clip(s)', 'ok'); });
          }); });
    }).catch(function (e) { setStatus('Not exported: ' + ((e && e.message) || 'try again'), 'warn'); });
  };
  function importProject(text, noBrand) {
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
      if (o.brand && !noBrand) { var b = cleanBrand(o.brand); b.logo = map[b.logo] || ''; brand = b; saveBrand(); }
      return docs.reduce(function (p, d) { return p.then(function () { return Store.putProject(d); }); }, Promise.resolve()).then(function () { docs.imap = map; return docs; });
    });
  }
  $('xp-in').onchange = function () { var f = this.files && this.files[0]; this.value = ''; if (!f) return;
    if (/\.zip$/i.test(f.name)) { importProjectFile(f); return; }
    f.slice(0, 200).text().then(function (h) { if (/"app"\s*:\s*"kbb-ad-studio-backup"/.test(h)) importBackupFile(f); else importProjectFile(f); });
  };
  function importProjectFile(f) {
    var isZip = /\.zip$/i.test(f.name) || /zip/.test(f.type);
    if (f.size > (isZip ? 4e9 : 200e6)) { setStatus('That file is too large', 'warn'); return; }
    setStatus('Importing…'); var nv = 0;
    var job = !isZip ? f.text().then(importProject) : JSZip.loadAsync(f).then(function (z) {
      var pj = z.file('project.json'); if (!pj) throw new Error('That .zip is not a KBB Ad Studio project');
      return pj.async('string').then(function (text) {
        if (text.length > 200e6) throw new Error('That file is too large');
        return importProject(text).then(function (docs) { var o = JSON.parse(text); return (window.KBB_VIDEO && o.video ? window.KBB_VIDEO.importPart(o.video, z) : Promise.resolve(0)).then(function (n) { nv = n; return docs; }); });
      });
    });
    job.then(function (docs) {
      buildPk(); thumbsDirty(); listMine();
      if (docs.length) { end(); begin(); doc = docs[0]; end(); lastSaved = JSON.stringify(doc); $('pname').value = doc.name; st.grp = null; setSel([]); draw(); }
      setStatus('Imported ' + docs.length + ' design(s)' + (nv ? ' and ' + nv + ' video project(s) — see Video' : ''), 'ok');
    }).catch(function (e) { setStatus('Not imported: ' + ((e && e.message) || 'try again'), 'warn'); });
  }

  // ── Backup everything (.json) / Import backup (18 Nov 2026): the Ad Studio moved into KBB Studio, and the owner's
  //    designs live in the old file's browser storage (file://), which KBB Studio's page cannot read. One file carries
  //    all of it -- designs, pictures, companies (favourites and brand kits are in them), KBB's brand kit, the picked
  //    company, every company's unsaved draft, recent colours and the video projects. Import keeps every id, so the
  //    same backup imported twice adds nothing the second time; a design that exists on both sides keeps the newer. ──
  var savedCos = {};   // companies this storage already had saved (an untouched seed takes the backup's version)
  function backupAll() {
    end(); setStatus('Packing everything…');
    var out = { app: 'kbb-ad-studio-backup', v: 1, exported: new Date().toISOString(), from: location.protocol === 'file:' ? 'file' : 'kbb-studio', projects: [], images: [], kv: {}, drafts: {}, ls: {} };
    return Store.listProjects().then(function (l) {
      return Promise.all(l.filter(function (p) { return IMGID.test(p.id); }).map(function (p) { return Store.getProject(p.id).then(function (d) { return d ? { id: p.id, name: p.name, updated: p.updated, co: p.co, doc: d } : null; }).catch(function () { return null; }); }));
    }).then(function (ps) {
      out.projects = ps.filter(Boolean);
      return Promise.all(Object.keys(images).map(function (id) { var im = images[id]; return Store.toData(im.src).then(function (data) { return { id: id, name: im.name, aspect: im.aspect, kind: im.kind, data: data }; }).catch(function () { return null; }); }));
    }).then(function (ims) {
      out.images = ims.filter(Boolean);
      return Promise.all(['companies', 'company', 'brand'].map(function (k) { return Store.getKV(k).then(function (v) { if (v != null) out.kv[k] = v; }).catch(function () {}); }));
    }).then(function () {
      if (doc) Store.draft({ doc: doc, t: Date.now(), saved: lastSaved === JSON.stringify(doc) }, co.id);
      return Promise.all(cos.map(function (c) { return Store.readDraft(c.id).then(function (d) { if (d && d.doc) out.drafts[c.id] = d; }).catch(function () {}); }));
    }).then(function () {
      try { var r = localStorage.getItem('kbbas:recent'); if (r) out.ls.recent = JSON.parse(r); } catch (e) {}
      return window.KBB_VIDEO && window.KBB_VIDEO.backupPart ? window.KBB_VIDEO.backupPart().catch(function () { return null; }) : null;
    }).then(function (vx) {
      var media = vx ? vx.media : [], day = new Date().toISOString().slice(0, 10);
      out.counts = { designs: out.projects.length, pictures: out.images.length, companies: (out.kv.companies || []).length, drafts: Object.keys(out.drafts).length, videos: vx ? vx.projects.length : 0, clips: media.length, clipsLeftOut: vx ? vx.left : 0 };
      if (vx) out.video = { v: 1, projects: vx.projects };
      // the clips go in as separate pieces of the file, so no single giant string is ever built
      var head = JSON.stringify(out), parts = [head.slice(0, -1)];
      if (vx) { parts.push(',"videoMedia":['); media.forEach(function (m, i) { parts.push((i ? ',' : '') + JSON.stringify(m)); }); parts.push(']'); }
      parts.push('}');
      return Store.save('KBB-Ad-Studio-backup-' + day + '.json', new Blob(parts, { type: 'application/json' })).then(function () {
        var c = out.counts; setStatus('Backup saved: ' + c.designs + ' design(s), ' + c.pictures + ' picture(s), ' + c.companies + ' compan' + (c.companies === 1 ? 'y' : 'ies') + (c.videos ? ', ' + c.videos + ' video(s)' : '') + (c.clipsLeftOut ? ' — ' + c.clipsLeftOut + ' large clip(s) left out: use Export project (.zip) for those' : ''), 'ok');
        return out.counts;
      });
    }).catch(function (e) { setStatus('Backup not made: ' + ((e && e.message) || 'try again'), 'warn'); return null; });
  }
  function importBackup(text) {
    var o; try { o = JSON.parse(text); } catch (e) { return Promise.reject(new Error('That file is not a backup')); }
    if (!o || o.app !== 'kbb-ad-studio-backup' || !Array.isArray(o.projects)) return Promise.reject(new Error('That file is not a KBB Ad Studio backup'));
    var res = { added: 0, same: 0, newer: 0, pictures: 0, companies: 0, videos: 0 };
    var ims = Array.isArray(o.images) ? o.images.slice(0, 2000) : [];
    return ims.reduce(function (p, im) {                                    // 1. pictures, ids kept: one already here is skipped
      return p.then(function () {
        if (!im || !IMGID.test(im.id) || images[im.id] || typeof im.data !== 'string' || im.data.length > 16e6 || !DATAURL.test(im.data)) return;
        return loadImg(im.data).then(function (el) {
          var kind = ['prod', 'bg', 'logo', 'img'].indexOf(im.kind) >= 0 ? im.kind : 'img', a = +(el.naturalWidth / el.naturalHeight).toFixed(4);
          return fetch(im.data).then(function (r) { return r.blob(); }).then(function (b) { return Store.putImage(b, { id: im.id, name: str(im.name, 60) || 'Picture', aspect: a, kind: kind }); })
            .then(function (r) { res.pictures++; registerImage({ id: r.id, src: r.src, name: str(im.name, 60), aspect: a, kind: kind }); });
        }).catch(function () {});
      });
    }, Promise.resolve()).then(function () {                               // 2. companies: new ones added; favourites merged
      var kv = o.kv && typeof o.kv === 'object' ? o.kv : {};
      (Array.isArray(kv.companies) ? kv.companies.slice(0, 50) : []).forEach(function (r) {
        if (!r || !SLUG.test(r.id)) return;
        var old = coById(r.id);
        if (old && savedCos[r.id]) {                                        // edited here too: keep it, add the favourites
          var f = (old.studio.favs || []).slice(); ((r.studio || {}).favs || []).forEach(function (x) { if (typeof x === 'string' && f.indexOf(x) < 0 && has(tplById, x)) f.push(x); }); old.studio.favs = f.slice(0, 200); return;
        }
        var c = cleanCompany(r, old ? { id: old.id, builtin: true, studio: old.studio, colors: old.colors } : null);
        c.studio.builtin = !!old; if (old) { if (old.mark) c.logos.mark = c.mark = old.mark; if (co === old) co = c; cos[cos.indexOf(old)] = c; } else { cos.push(c); res.companies++; }
        savedCos[c.id] = 1;
      });
      saveCos();
      return Store.getKV('brand').then(function (b) { if (!b && kv.brand) { var nb = cleanBrand(kv.brand); if (co.id === 'kbb') brand = nb; return Store.putKV('brand', nb); } }).catch(function () {});
    }).then(function () { return Store.listProjects(); }).then(function (have) {   // 3. designs: ids kept, newer wins
      var by = {}; have.forEach(function (p) { by[p.id] = p; });
      return o.projects.slice(0, 2000).reduce(function (p, raw) {
        return p.then(function () {
          if (!raw || !IMGID.test(raw.id) || !raw.doc) return;
          var d = cleanDoc(Object.assign({}, raw.doc, { id: raw.id }), images); if (!d) return;
          if (!d.co && raw.co && SLUG.test(raw.co)) d.co = raw.co;
          var h = by[d.id];
          if (h && !((+raw.updated || 0) > h.updated)) { res.same++; return; }
          return (h ? Store.getProject(d.id) : Promise.resolve(null)).then(function (cur) {
            if (cur && JSON.stringify(cleanDoc(cur, images)) === JSON.stringify(d)) { res.same++; return; }
            if (h) res.newer++; else res.added++; by[d.id] = { id: d.id, updated: Date.now() }; return Store.putProject(d);
          });
        });
      }, Promise.resolve());
    }).then(function () {                                                  // 4. unsaved drafts, recent colours
      var dr = o.drafts && typeof o.drafts === 'object' ? o.drafts : {};
      return Object.keys(dr).filter(function (k) { return SLUG.test(k) && coById(k); }).reduce(function (p, k) {
        return p.then(function () { return Store.readDraft(k).then(function (cur) { if (!cur && dr[k] && cleanDoc(dr[k].doc, images)) Store.draft({ doc: cleanDoc(dr[k].doc, images), t: +dr[k].t || Date.now(), saved: !!dr[k].saved }, k); }); });
      }, Promise.resolve()).then(function () {
        try { if (o.ls && Array.isArray(o.ls.recent) && !localStorage.getItem('kbbas:recent')) localStorage.setItem('kbbas:recent', JSON.stringify(o.ls.recent.filter(function (c) { return typeof c === 'string' && HEX.test(c); }).slice(0, 18))); } catch (e) {}
      });
    }).then(function () {                                                  // 5. video projects and their clips
      if (!o.video || !window.KBB_VIDEO || !window.KBB_VIDEO.restorePart) return;
      return window.KBB_VIDEO.restorePart({ projects: o.video.projects, media: o.videoMedia }).then(function (v) { res.videos = v.added; res.same += v.same; });
    }).then(function () {
      buildPk(); thumbsDirty(); coBar(); listMine(); moveBanner();
      return res;
    });
  }
  function backupSummary(r) {
    return 'Backup imported: ' + r.added + ' new design(s)' + (r.newer ? ', ' + r.newer + ' updated' : '') + (r.same ? ', ' + r.same + ' already here' : '') + (r.pictures ? ', ' + r.pictures + ' picture(s)' : '') + (r.companies ? ', ' + r.companies + ' compan' + (r.companies === 1 ? 'y' : 'ies') : '') + (r.videos ? ', ' + r.videos + ' video project(s)' : '');
  }
  function importBackupFile(f) {
    if (f.size > 1500e6) { setStatus('That file is too large', 'warn'); return Promise.resolve(null); }
    setStatus('Importing your backup…');
    return f.text().then(importBackup).then(function (r) { setStatus(backupSummary(r), 'ok'); return r; })
      .catch(function (e) { setStatus('Not imported: ' + ((e && e.message) || 'try again'), 'warn'); return null; });
  }
  $('bk-all').onclick = function () { backupAll(); };
  $('bk-in').onchange = function () { var f = this.files && this.files[0]; this.value = ''; if (f) importBackupFile(f); };
  // the banner: in the old standalone file (with designs in it) -> back up; inside KBB Studio with nothing yet -> import
  function moveBanner() {
    var b = $('mv'), inside = !!(window.KBB_DISK && window.KBB_DISK.inside);
    try { if (localStorage.getItem('kbbas:mv-x') === (inside ? 'in' : 'out')) { b.hidden = true; return; } } catch (e) {}
    Store.listProjects().then(function (l) {
      var n = l.length;
      if (inside) { b.hidden = n > 0; $('mv-t').textContent = 'Moving from the old Ad Studio file? Open it once, press Backup everything, then import that file here.'; $('mv-b').textContent = 'Import backup…'; $('mv-b').onclick = function () { $('bk-in').click(); }; }
      else { b.hidden = n === 0; $('mv-t').textContent = 'Ad Studio now lives inside KBB Studio. Click Backup everything, then in KBB Studio → Ad Studio → Import backup.'; $('mv-b').textContent = 'Backup everything'; $('mv-b').onclick = function () { backupAll(); }; }
    }).catch(function () {});
  }
  $('mv-x').onclick = function () { $('mv').hidden = true; try { localStorage.setItem('kbbas:mv-x', window.KBB_DISK && window.KBB_DISK.inside ? 'in' : 'out'); } catch (e) {} };
  // a backup or a project .json / .zip dropped anywhere on the Ads screen imports it (the same as the buttons)
  document.addEventListener('dragover', function (e) { if (document.body.dataset.mode === 'video' || !e.dataTransfer || [].indexOf.call(e.dataTransfer.types || [], 'Files') < 0) return; e.preventDefault(); });
  document.addEventListener('drop', function (e) {
    if (document.body.dataset.mode === 'video') return;
    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (!f || !/\.(json|zip)$/i.test(f.name)) return;
    e.preventDefault();
    if (/\.zip$/i.test(f.name)) { importProjectFile(f); return; }
    f.slice(0, 200).text().then(function (h) { if (/"app"\s*:\s*"kbb-ad-studio-backup"/.test(h)) importBackupFile(f); else importProjectFile(f); });
  });

  // ── companies: the switcher in the top bar, + New company, Edit company, export / import one company as .json ──
  function firstTpl(c) { var s = c.studio.tplSet; return KT.LIST.filter(function (t) { return s === 'planet-careers' ? t.co === 'pc' : t.id === 'black-friday'; })[0] || KT.LIST[0]; }
  function coBar() {
    var s = $('co-sel'); s.textContent = '';
    cos.forEach(function (c) { var o = mk('option', null, c.name); o.value = c.id; s.appendChild(o); });
    var n = mk('option', null, '＋ New company…'); n.value = '__new'; s.appendChild(n);
    var im = mk('option', null, 'Import company…'); im.value = '__imp'; s.appendChild(im);
    s.value = co.id; document.documentElement.dataset.co = co.id;
    $('br-nologo').textContent = co.id === 'kbb' ? 'Use the lotus logo' : co.mark ? 'Use the built-in mark' : 'No logo picture';
  }
  function setCompany(id, quiet) {
    var c = coById(id); if (!c) return Promise.resolve(false);
    if (c === co && doc) { coBar(); return Promise.resolve(true); }
    stopPlay(); end(); clearTimeout(draftT);
    if (doc) Store.draft({ doc: doc, t: Date.now(), saved: lastSaved === JSON.stringify(doc) }, co.id);
    co = c; Store.putKV('company', c.id).catch(function () {}); if (window.KBB_DISK) window.KBB_DISK.setStudioCompany(c.id);
    var b = c.id === 'kbb' ? Store.getKV('brand').then(function (x) { brand = cleanBrand(x || {}); }).catch(function () { brand = cleanBrand({}); }) : Promise.resolve(brand = c.studio.brand);
    return b.then(function () { return Store.readDraft(c.id); }).then(function (dr) {
      var d = dr && cleanDoc(dr.doc, images); if (d && (d.co || 'kbb') !== c.id) d = null;
      hist = []; fut = []; pending = null;
      if (d) { doc = d; lastSaved = dr.saved ? JSON.stringify(d) : ''; } else { doc = fromTemplate(firstTpl(c)); lastSaved = ''; }
      st.grp = null; setSel([]); st.set = 0; st.tset = ''; st.cat = 'all'; setV($('tf-set'), '');
      $('pname').value = doc.name; coBar(); buildCats(); thumbsBuilt = ''; undoBtns(); draw(); if (paneOn('tpl')) buildThumbs(); listMine();
      if (!quiet) setStatus('Now working on ' + c.name, 'ok');
      return true;
    });
  }
  $('co-sel').onchange = function () {
    var v = this.value; this.value = co.id;
    if (v === '__new') return openCo(null);
    if (v === '__imp') return $('co-in').click();
    setCompany(v);
  };
  $('co-edit').onclick = function () { openCo(co); };
  // the company form (new or edit): the brand kit fields of kit.schema.json the ads use
  var coEditing = null;
  (function () { ['co-fh', 'co-fb'].forEach(function (id) { Object.keys(FONTS).forEach(function (k) { if (k === 'cairo') return; var o = mk('option', null, FONTS[k]); o.value = k; $(id).appendChild(o); }); });
    Object.keys(TSETS).forEach(function (k) { var o = mk('option', null, TSETS[k]); o.value = k; $('co-set').appendChild(o); }); })();
  function openCo(c) {
    coEditing = c; var base = c || cleanCompany({ name: 'New company', studio: { tplSet: 'planet-careers' } });
    $('co-h').textContent = c ? 'Edit company · ' + c.name : 'New company';
    setV($('co-name'), c ? c.name : ''); setV($('co-full'), base.fullName); setV($('co-ind'), base.industry);
    ['primary', 'secondary', 'accent', 'soft', 'ink'].forEach(function (k) { setV($('co-c-' + k), base.colors[k].toLowerCase()); });
    setV($('co-fh'), fontKey(base.fonts.heading, 'afacad')); setV($('co-fb'), fontKey(base.fonts.body, 'afacad'));
    setV($('co-cl'), base.contactLine); ['website', 'phone', 'whatsapp', 'email', 'instagram'].forEach(function (k) { setV($('co-' + k), base.contact[k] || ''); });
    setV($('co-cta'), base.endCard.cta[0] || ''); setV($('co-cta-ar'), base.endCard.ctaAr[0] || '');
    $('co-tabby').checked = base.endCard.payments.indexOf('tabby') >= 0; $('co-tamara').checked = base.endCard.payments.indexOf('tamara') >= 0;
    setV($('co-set'), base.studio.tplSet);
    $('co-del').hidden = !c || c.studio.builtin; $('co-xp').hidden = !c;
    $('co-st').textContent = ''; $('codlg').hidden = false; $('co-name').focus();
  }
  function closeCo() { $('codlg').hidden = true; coEditing = null; }
  $('co-cancel').onclick = closeCo;
  $('codlg').addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { ev.stopPropagation(); closeCo(); } });
  $('co-save').onclick = function () {
    var name = $('co-name').value.trim(); if (!name) { $('co-st').textContent = 'Give the company a name'; $('co-name').focus(); return; }
    var old = coEditing, id = old ? old.id : name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 32) || 'company';
    if (!old) { var id0 = id, n = 2; while (coById(id)) id = id0 + '-' + n++; }
    var raw = { id: id, name: name, fullName: $('co-full').value, industry: $('co-ind').value, colors: {}, fonts: { heading: { family: FONTS[$('co-fh').value] }, body: { family: FONTS[$('co-fb').value] } },
      logos: { mark: old ? old.logos.mark : '' }, contactLine: $('co-cl').value, contact: {}, gradient: old && old.gradient,
      endCard: { title: old ? old.endCard.title : '', sub: old ? old.endCard.sub : '', cta: [$('co-cta').value].concat(old ? old.endCard.cta.slice(1) : []).filter(Boolean), ctaAr: [$('co-cta-ar').value].concat(old ? old.endCard.ctaAr.slice(1) : []).filter(Boolean), payments: ['tabby', 'tamara'].filter(function (p) { return $('co-' + p).checked; }) },
      studio: { tplSet: $('co-set').value, favs: old ? old.studio.favs : [], brand: old ? old.studio.brand : { c1: $('co-c-primary').value, c2: $('co-c-secondary').value, on: $('co-set').value === 'kbb' } } };
    ['primary', 'secondary', 'accent', 'soft', 'ink'].forEach(function (k) { raw.colors[k] = $('co-c-' + k).value; });
    raw.colors.bg = raw.colors.primary;
    ['website', 'phone', 'whatsapp', 'email', 'instagram'].forEach(function (k) { raw.contact[k] = $('co-' + k).value; });
    var c = cleanCompany(raw, old ? { id: old.id, builtin: old.studio.builtin, studio: old.studio, colors: old.colors } : null);
    c.studio.builtin = old ? old.studio.builtin : false;
    if (old) { cos[cos.indexOf(old)] = c; if (old === co) { co = c; if (c.id !== 'kbb') brand = c.studio.brand; } } else cos.push(c);
    saveCos(); closeCo();
    if (old && co === c) { coBar(); thumbsDirty(); buildCats(); draw(); setStatus(c.name + ' saved — new designs and the template previews use it', 'ok'); }
    else setCompany(c.id).then(function () { setStatus(c.name + ' created — pick a template to start', 'ok'); });
  };
  $('co-del').onclick = function () {
    var c = coEditing; if (!c || c.studio.builtin) return;
    if (!confirm('Delete ' + c.name + ' and its saved designs from this computer? Export it first to keep a copy.')) return;
    Store.listProjects().then(function (l) { return Promise.all(l.filter(function (p) { return p.co === c.id; }).map(function (p) { return Store.delProject(p.id); })); })
      .then(function () { cos.splice(cos.indexOf(c), 1); saveCos(); closeCo(); co = null; return setCompany('kbb'); }).then(function () { setStatus(c.name + ' deleted', 'ok'); });
  };
  // one company -> one .json: its kit (kit.schema.json fields), its studio settings, logo, saved designs and their pictures
  function exportCompany(c) {
    end(); var used = Object.create(null);
    return Store.listProjects().then(function (l) { return Promise.all(l.filter(function (p) { return p.co === c.id && IMGID.test(p.id); }).map(function (p) { return Store.getProject(p.id); })); }).then(function (ds) {
      ds = ds.filter(Boolean).map(function (d) { return d.id === doc.id ? clone(doc) : d; });
      if (doc && (doc.co || 'kbb') === c.id && !ds.some(function (d) { return d.id === doc.id; })) ds.push(clone(doc));
      ds.forEach(function (d) { (d.els || []).forEach(function (e) { if (e.t === 'img') used[e.img] = 1; }); if (d.bg && d.bg.img) used[d.bg.img] = 1; FMTS.forEach(function (f) { ((d.sets || {})[f] || []).forEach(function (l) { l.forEach(function (it) { if (it.p.indexOf('u_') === 0) used[it.p.slice(2)] = 1; }); }); }); });
      var b = c.id === co.id ? brand : c.studio.brand; if (b.logo) used[b.logo] = 1;
      return Promise.all(Object.keys(used).filter(function (id) { return images[id]; }).map(function (id) { var im = images[id]; return Store.toData(im.src).then(function (data) { return { id: id, name: im.name, aspect: im.aspect, kind: im.kind, data: data }; }).catch(function () { return null; }); })).then(function (ims) {
        var kit = clone(c); delete kit.studio; delete kit.mark;
        var out = { app: 'kbb-studio', kind: 'company', v: 1, exported: new Date().toISOString(), kit: kit, studio: { tplSet: c.studio.tplSet, favs: c.studio.favs, brand: b }, projects: ds, images: ims.filter(Boolean) };
        return Store.save('KBB-Studio-company-' + c.id + '.json', new Blob([JSON.stringify(out, null, 1)], { type: 'application/json' })).then(function () { setStatus(c.name + ' exported: kit, ' + ds.length + ' design(s), ' + out.images.length + ' picture(s)', 'ok'); return out; });
      });
    }).catch(function (e) { setStatus('Not exported: ' + ((e && e.message) || 'try again'), 'warn'); });
  }
  $('co-xp').onclick = function () { if (coEditing) exportCompany(coEditing); };
  function importCompany(text) {
    var o; try { o = JSON.parse(text); } catch (e) { return Promise.reject(new Error('That file is not a company file')); }
    if (!o || o.app !== 'kbb-studio' || o.kind !== 'company' || !o.kit || typeof o.kit !== 'object') return Promise.reject(new Error('That file is not a KBB Studio company'));
    var old = SLUG.test(o.kit.id) ? coById(o.kit.id) : null, st0 = o.studio && typeof o.studio === 'object' ? o.studio : {};
    var c = cleanCompany(Object.assign({}, o.kit, { studio: { tplSet: st0.tplSet, favs: st0.favs, brand: st0.brand } }), old ? { id: old.id, builtin: old.studio.builtin, studio: old.studio } : null);
    c.studio.builtin = old ? old.studio.builtin : false; if (old && old.studio.builtin) c.logos.mark = c.mark = old.mark;
    var projects = (Array.isArray(o.projects) ? o.projects : []).map(function (p) { var q = p && typeof p === 'object' ? Object.assign({}, p) : {}; q.co = c.id; return q; });
    return importProject(JSON.stringify({ app: 'kbb-ad-studio', v: 1, projects: projects, images: o.images || [] }), true).then(function (docs) {
      var lg = st0.brand && st0.brand.logo; c.studio.brand.logo = lg && docs.imap[lg] || '';
      if (old) cos[cos.indexOf(old)] = c; else cos.push(c);
      if (c.id === 'kbb') { Store.putKV('brand', c.studio.brand).catch(function () {}); }
      if (co === old) co = null;
      saveCos(); return setCompany(c.id, true).then(function () { setStatus('Imported ' + c.name + ' with ' + docs.length + ' design(s)', 'ok'); return c; });
    });
  }
  $('co-imp2').onclick = function () { $('co-in').click(); };
  $('co-in').onchange = function () { var f = this.files && this.files[0]; this.value = ''; if (!f) return; closeCo();
    if (f.size > 200e6) { setStatus('That file is too large', 'warn'); return; }
    f.text().then(importCompany).catch(function (e) { setStatus('Not imported: ' + ((e && e.message) || 'try again'), 'warn'); }); };
  // ✂ Cut out (worker photos): the person stays, the background goes -- MediaPipe's selfie segmenter (Apache-2.0, the
  // same model KBB Studio's Look tab uses), run in this browser, offline. Its runtime and model (≈4 MB) live in
  // ad-studio-cutout.js NEXT TO this file and load only on the first cut-out, so the studio itself stays light.
  var cutP = null;
  function cutoutLib() {
    if (window.KBB_CUTOUT) return Promise.resolve(window.KBB_CUTOUT);
    return cutP || (cutP = new Promise(function (res, rej) {
      var s = document.createElement('script'); s.src = new URL('ad-studio-cutout.js', location.href).href;
      s.onload = function () { window.KBB_CUTOUT ? res(window.KBB_CUTOUT) : rej(new Error('cut-out file is damaged')); };
      s.onerror = function () { cutP = null; rej(new Error('Cut-out needs the file ad-studio-cutout.js next to this one (it comes in the same folder)')); };
      document.head.appendChild(s);
    }));
  }
  function cutOutPhoto(e) {
    if (!e || e.t !== 'img' || !images[e.img]) return Promise.resolve();
    setStatus('Cutting out the person… (the first time takes a few seconds)');
    return Promise.all([cutoutLib(), loadImg(images[e.img].src)]).then(function (r) {
      var img = r[1], k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight)), cv = mk('canvas');
      cv.width = Math.max(1, Math.round(img.naturalWidth * k)); cv.height = Math.max(1, Math.round(img.naturalHeight * k));
      cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
      return r[0].cut(cv).then(function () {
        return new Promise(function (res) { cv.toBlob(res, 'image/png'); }).then(function (blob) {
          var name = (images[e.img].name || 'Photo') + ' (cut out)';
          return Store.putImage(blob, { name: name, aspect: +(cv.width / cv.height).toFixed(4), kind: 'img' }).then(function (q) {
            registerImage({ id: q.id, src: q.src, name: name, aspect: +(cv.width / cv.height).toFixed(4), kind: 'img' });
            act(function () { var i = doc.els.indexOf(e); if (i >= 0) { e.img = q.id; e.name = 'Worker photo (cut out)'; delete e.bw; } });
            setStatus('Cut out — Ctrl+Z brings the background back', 'ok');
          });
        });
      });
    }).catch(function (x) { setStatus('Not cut out: ' + ((x && x.message) || 'try again'), 'warn'); });
  }
  $('ph-cut').onclick = function () { cutOutPhoto(selEl()); };
  // a photo slot (success stories, testimonials, the team): pick a picture and it takes the slot's place and shape
  $('ph-file').onchange = function () { var f = this.files && this.files[0]; this.value = ''; var e = selEl(); if (!f || !e || !e.ph) return;
    readPicture(f, { cut: false, max: 1400, kind: 'img' }).then(function (r) {
      act(function () {
        var i = doc.els.indexOf(e); if (i < 0) return;
        var o = { id: e.id, t: 'img', name: 'Photo', img: r.id, sh: e.sh === 'arch' || e.sh === 'rect' || e.sh === 'pill' ? e.sh : 'circle', rad: e.rad, bw: e.bw, bc: e.bc, bs: e.bs, shd: e.shd };
        // a worker slot: the photo fills the slot's box (cover, the head kept), and stays a slot -- replace it again, or cut it out
        if (e.wk) Object.assign(o, { name: 'Worker photo', sh: e.sh === 'arch' || e.sh === 'rect' || e.sh === 'pill' || e.sh === 'circle' ? e.sh : 'rect', rad: e.rad || 0, wk: true, ph: true });
        if (e.gp) o.gp = e.gp; doc.els[i] = cleanEl(o, images);
      });
      setStatus('Photo placed — drag the picture to move it', 'ok');
    }).catch(pictureError); };

  // ── start ──
  function where() {
    var k = Store.kind();
    $('mode').textContent = { claude: 'saved to claude.ai', disk: 'in KBB Studio', browser: 'offline', local: 'offline', memory: 'not saving' }[k];
    if (k === 'disk') { $('where').textContent = 'Saving to ' + ((window.KBB_DISK.info || {}).data || 'KBB Studio Data') + '/adstudio on this Mac: your designs stay through updates, a browser reset or a different browser.'; return; }
    if (window.KBB_DISK && window.KBB_DISK.inside) { $('where').textContent = 'KBB Studio’s engine is older than this Ad Studio, so designs are saved in this browser only. Close every KBB Studio Terminal window and start KBB Studio again.'; return; }
    $('where').textContent = { claude: 'Saving to this artifact on claude.ai.', browser: 'Saving inside this browser on this computer (works offline). Use Export project for a backup or to move to another computer.', local: 'Saving inside this browser (small storage). Use Export project for a backup.', memory: 'This browser does not allow saving from this file. Use Export project before you close it.' }[k];
  }
  Store.init().then(function () {
    where();
    return Promise.all([loadArt(), Store.listImages().then(function (l) { l.forEach(registerImage); }).catch(function () {})])
      .then(function () { return Store.getKV('companies').then(function (l) { (Array.isArray(l) ? l : []).slice(0, 50).forEach(function (r) {
        var old = r && SLUG.test(r.id) ? coById(r.id) : null, c = cleanCompany(r, old ? { id: old.id, builtin: true, studio: old.studio, colors: old.colors } : null);
        c.studio.builtin = !!old; if (old) { if (old.mark) c.logos.mark = c.mark = old.mark; cos[cos.indexOf(old)] = c; } else if (!coById(c.id)) cos.push(c);
      }); }).catch(function () {}); })
      .then(function () { return Store.getKV('company').then(function (id) { co = coById(id) || cos[0]; }).catch(function () {}); })
      // inside KBB Studio: open on the company picked there (the same ids: kbb, planet-careers, …)
      .then(function () { return window.KBB_DISK && window.KBB_DISK.on ? window.KBB_DISK.studioCompany().then(function (sc) { if (sc && coById(sc)) co = coById(sc); }) : null; })
      .then(function () { return Store.getKV('companies').then(function (l) { savedCos = {}; (Array.isArray(l) ? l : []).forEach(function (r) { if (r && SLUG.test(r.id)) savedCos[r.id] = 1; }); }).catch(function () {}); })
      .then(function () { if (co.id !== 'kbb') { brand = co.studio.brand; return; } return Store.getKV('brand').then(function (b) { if (b) brand = cleanBrand(b); }).catch(function () {}); })
      .then(function () { return Store.readDraft(co.id); });
  }).then(function (dr) {
    var d = dr && cleanDoc(dr.doc, images); if (d && (d.co || 'kbb') !== co.id) d = null;
    if (d) { doc = d; lastSaved = dr.saved ? JSON.stringify(d) : ''; if (!dr.saved) setStatus('Your last session is back — press Save to keep it', 'warn'); }
    else { doc = fromTemplate(firstTpl(co)); lastSaved = ''; }
    $('pname').value = doc.name;
    coBar(); buildPk(); buildCats(); undoBtns(); draw(); showTab('tpl'); listMine(); moveBanner();
    document.documentElement.dataset.ready = '1';
  });

  // test hooks (read-only views + the same actions the buttons run)
  window.KBB_STUDIO = { shared: { Store: Store, D: D, ICONS: ICONS, iconSvg: iconSvg, FONTS: FONTS, images: images, brand: function () { return brand; }, IMGID: IMGID }, stopPlay: function () { stopPlay(); },
    get doc() { return doc; }, st: st, templates: KT.LIST, useTemplate: function (id, cty) { useTemplate(tplById[id], cty); },
    setCompany: setCompany, company: function () { return co; }, companies: function () { return cos; }, setCountry: setCountry, countries: KT.CTRY_LIST, setOccupation: setOccupation, occupations: KT.OCCS, cutOut: function () { return cutOutPhoto(selEl()); }, hasArt: function (id) { return !!ART[id]; }, exportCompany: function (id) { return exportCompany(coById(id) || co); }, importCompany: importCompany, backupAll: backupAll, importBackup: importBackup, art: function () { return Object.keys(ART).length; }, fromTemplate: function (id, cty, occ) { return fromTemplate(tplById[id], co, cty, occ); }, renderFile: renderFile, recordVideo: recordVideo, store: Store, images: images, saveNow: saveNow, cleanDoc: function (r) { return cleanDoc(r, images); }, draw: draw, brand: function () { return brand; },
    selKeys: selKeys, setSel: function (k) { setSel(k); afterSel(); }, itemBox: itemBox, aabb: aabb, docColours: function () { return docColours(doc); } };
})();
