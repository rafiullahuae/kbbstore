/* Builds docs/checkout-preview/checkout-preview.html: the shell, the "after"
   layer and the four real captures (cart / checkout, with and without a coupon)
   in one self-contained page. node tools/cpv-build.cjs */
const fs = require('fs'), path = require('path');
const D = path.resolve(__dirname, '../docs/checkout-preview');
const js = (v) => JSON.stringify(v).replace(/<\//g, '<\\/').replace(/<!--/g, '<\\!--').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
const cap = {};
for (const n of ['cart', 'checkout', 'cart-coupon', 'checkout-coupon']) cap[n] = fs.readFileSync(`${D}/captured/${n}.html`, 'utf8');
let out = fs.readFileSync(`${D}/src/shell.html`, 'utf8');
out = out.replace('/*CAPTURES*/null', () => js(cap))
  .replace("/*AFTER_JS*/''", () => js(fs.readFileSync(`${D}/src/after.js`, 'utf8')))
  .replace("/*AFTER_CSS*/''", () => js(fs.readFileSync(`${D}/src/after.css`, 'utf8')));
fs.writeFileSync(`${D}/checkout-preview.html`, out);
console.log((out.length / 1048576).toFixed(2) + ' MB');
