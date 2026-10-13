/* Builds docs/checkout-preview/checkout-preview.html: the shell, the "after"
   layer and the real captures (cart / checkout: the bag, the bag with a coupon, a small bag)
   in one self-contained page. node tools/cpv-build.cjs */
const fs = require('fs'), path = require('path');
const D = path.resolve(__dirname, '../docs/checkout-preview');
const js = (v) => JSON.stringify(v).replace(/<\//g, '<\\/').replace(/<!--/g, '<\\!--').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
const cap = {};
for (const n of ['cart', 'checkout', 'cart-coupon', 'checkout-coupon', 'cart-small', 'checkout-small']) cap[n] = fs.readFileSync(`${D}/captured/${n}.html`, 'utf8');
let out = fs.readFileSync(`${D}/src/shell.html`, 'utf8');
out = out.replace('/*CAPTURES*/null', () => js(cap))
  .replace("/*AFTER_JS*/''", () => js(fs.readFileSync(`${D}/src/after.js`, 'utf8')))
  .replace("/*AFTER_CSS*/''", () => js(fs.readFileSync(`${D}/src/after.css`, 'utf8')));
// Pure ASCII out, so no viewer can mis-guess the encoding and garble "−", "…" or "✕":
// inside <script> a \u escape, elsewhere an HTML character reference.
out = out.split(/(<script\b[\s\S]*?<\/script>)/).map((part, i) => part.replace(/[^\x00-\x7f]/gu, (ch) => {
  const cp = ch.codePointAt(0);
  if (i % 2 === 0) return '&#x' + cp.toString(16) + ';';
  return cp > 0xffff ? ch.split('').map((u) => '\\u' + u.charCodeAt(0).toString(16).padStart(4, '0')).join('') : '\\u' + cp.toString(16).padStart(4, '0');
})).join('');
fs.writeFileSync(`${D}/checkout-preview.html`, out);
console.log((out.length / 1048576).toFixed(2) + ' MB');
