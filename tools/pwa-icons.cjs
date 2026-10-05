/*
 * Lane PW: draw the shop's Home Screen app icon (option 1, the owner's pick:
 * white "KB" on the shop's pink gradient) at every size the app needs.
 *
 *   node tools/pwa-icons.cjs
 *
 * The mark is the same SVG docs/pw-preview/index.html shows the owner, set in
 * the shop's own Outfit 800 (resources/fonts/outfit, loaded from disk, so the
 * PNGs never depend on a font host). Written to resources/site-app/icons/,
 * committed, and served by SiteAppController with a content hash in the URL.
 *
 *   icon-192.png, icon-512.png   purpose "any": rounded square, clear corners
 *   maskable-512.png             purpose "maskable": full bleed, the mark kept
 *                                inside the 80% safe circle Android may crop to
 *   apple-180.png                apple-touch-icon: full bleed and OPAQUE (iOS
 *                                paints transparency black and rounds it itself)
 */
const { chromium } = require('playwright');
const { probeFamily } = require('./font-probe.cjs');
const path = require('path');
const fs = require('fs');

const FONT = path.resolve(__dirname, '../resources/fonts/outfit/outfit-latin.woff2');
const OUT = path.resolve(__dirname, '../resources/site-app/icons');

function svg(radius, scale) {
  return '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 100 100">'
    + '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F07A99"/><stop offset="1" stop-color="#C13E63"/></linearGradient>'
    + '<clipPath id="c"><rect width="100" height="100" rx="' + radius + '"/></clipPath></defs>'
    + '<g clip-path="url(#c)"><rect width="100" height="100" fill="url(#g)"/>'
    + '<g transform="translate(50 50) scale(' + scale + ') translate(-50 -50)">'
    + '<text x="50" y="64.5" text-anchor="middle" font-family="KBOutfit" font-weight="800" font-size="44" letter-spacing="-1.5" fill="#fff">KB</text>'
    + '</g></g></svg>';
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const font = fs.readFileSync(FONT).toString('base64');
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const jobs = [
    ['icon-192.png', 192, 22.5, 1, true],
    ['icon-512.png', 512, 22.5, 1, true],
    ['maskable-512.png', 512, 0, 0.82, false],
    ['apple-180.png', 180, 0, 1, false],
  ];
  for (const [file, px, radius, scale, clear] of jobs) {
    const page = await browser.newPage({ viewport: { width: px, height: px }, deviceScaleFactor: 1 });
    await page.setContent('<!doctype html><style>@font-face{font-family:KBOutfit;font-weight:100 900;src:url(data:font/woff2;base64,' + font + ') format("woff2")}'
      + 'html,body{margin:0;width:' + px + 'px;height:' + px + 'px;background:transparent;overflow:hidden}</style>' + svg(radius, scale));
    // Measured against a family that cannot exist (tools/font-probe.cjs): a
    // fallback face here would draw an icon nobody chose.
    const probe = await probeFamily(page, 'KBOutfit', [800]);
    if (!probe.rendered[800]) throw new Error('Outfit 800 is not what renders; refusing to draw the icon in a fallback face');
    await page.screenshot({ path: path.join(OUT, file), omitBackground: clear });
    await page.close();
    console.log(file, fs.statSync(path.join(OUT, file)).size, 'bytes');
  }
  await browser.close();
  // Chromium writes uncompressed-ish PNGs; GD at level 9 halves them, lossless.
  require('child_process').execFileSync('php', ['-r',
    'foreach (glob($argv[1]."/*.png") as $p) { $i = imagecreatefrompng($p); imagesavealpha($i, true); imagepng($i, $p, 9); clearstatcache(); echo basename($p), " ", filesize($p), " bytes (gd)\\n"; }', OUT], { stdio: 'inherit' });
})();
