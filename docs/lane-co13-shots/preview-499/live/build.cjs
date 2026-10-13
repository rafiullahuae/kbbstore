/* Builds the 2.60.499 live preview page: shell.html with the two captures
   from tools/co499-live.cjs inlined. node docs/lane-co13-shots/preview-497/live/build.cjs <out.html> */
const fs = require('fs'), path = require('path');
const D = __dirname;
const js = (v) => JSON.stringify(v).replace(/<\//g, '<\\/').replace(/<!--/g, '<\\!--').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
const cap = { closed: fs.readFileSync(`${D}/closed.html`, 'utf8'), open: fs.readFileSync(`${D}/open.html`, 'utf8') };
let out = fs.readFileSync(`${D}/shell.html`, 'utf8').replace('/*CAPTURES*/null', () => js(cap));
out = out.split(/(<script\b[\s\S]*?<\/script>)/).map((part, i) => part.replace(/[^\x00-\x7f]/gu, (ch) => {
  const cp = ch.codePointAt(0);
  if (i % 2 === 0) return '&#x' + cp.toString(16) + ';';
  return cp > 0xffff ? ch.split('').map((u) => '\\u' + u.charCodeAt(0).toString(16).padStart(4, '0')).join('') : '\\u' + cp.toString(16).padStart(4, '0');
})).join('');
fs.writeFileSync(process.argv[2], out);
console.log((out.length / 1048576).toFixed(2) + ' MB');
