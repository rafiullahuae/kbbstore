#!/usr/bin/env node
/*
 * Lane APV3 — the website's own CART page and ONE-PAGE CHECKOUT, captured for the app previews.
 *
 *   node tools/apv-site-co.cjs                 boots this repo's shop locally, captures, stops it
 *   node tools/apv-site-co.cjs --url=<base>    captures from a shop that is already running
 *
 * Why locally: kbeautybliss.com answers its cart and checkout to automated browsers from a
 * data-centre connection with "We can't take orders from this connection" (its own guard), so
 * those two pages are rendered from THIS repository instead, with the migrations' own settings —
 * which include the "13 October" checkout layout the shop runs (one step, summary bar, coupon in
 * the summary, Pay now). Nothing is ordered: two products are put in the bag and the pages are read.
 *
 * Needs vendor/ (a symlink to the main checkout's is enough) and php with sqlite. Everything it
 * makes lives in storage/apv-site/ (ignored by git) and the server it starts is stopped at the end.
 *
 * Writes:
 *   docs/app-preview/en|ar/site-co.css   the site's rules for the cart page and the checkout,
 *                                        scoped to .kc, @media widths -> @container app
 *   docs/app-preview/en|ar/site-co.js    the site's own markup of both pages (window.APV_SITE_CO):
 *                                        the app builds its bag and checkout from it, so every
 *                                        label, icon and logo is the site's, in both languages
 *   docs/app-preview/site-measure.json   adds `co`: measured sizes of both pages at 390
 *   docs/app-preview/shots/site/<lang>-cart.png, <lang>-checkout.png (full length, not in git)
 */
'use strict';
const pw = require('/opt/node22/lib/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const {spawn, spawnSync} = require('child_process');
const {extractRules, buildCss} = require('./apv-site.cjs');

const ROOT = path.resolve(__dirname, '..');
const APP = path.join(ROOT, 'docs/app-preview');
const WORK = path.join(ROOT, 'storage/apv-site');
const SHOTS = path.join(APP, 'shots/site');
const urlArg = (process.argv.find((x) => x.startsWith('--url=')) || '').slice(6);
const PORT = 8765;
const BASE = urlArg || `http://127.0.0.1:${PORT}`;
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

const ROUTER = `<?php
$root = ${JSON.stringify(ROOT)};
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($uri !== '/' && is_file($root.'/public'.$uri)) { return false; }
define('LARAVEL_START', microtime(true));
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->handleRequest(Illuminate\\Http\\Request::capture());
`;
const SEED = `<?php
require ${JSON.stringify(ROOT)}.'/vendor/autoload.php';
$app = require ${JSON.stringify(ROOT)}.'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use App\\Models\\{Product, PaymentProvider, ShippingMethod, ShippingZone, ShippingZoneLocation};
app(App\\Services\\SettingsService::class)->set(App\\Support\\Locale::SETTING_ENABLED, true);
$z = ShippingZone::firstOrCreate(['name' => 'UAE'], ['position' => 0]);
ShippingZoneLocation::firstOrCreate(['shipping_zone_id' => $z->id, 'type' => 'country', 'code' => 'AE']);
ShippingMethod::firstOrCreate(['shipping_zone_id' => $z->id, 'type' => 'flat_rate'], ['title' => 'Express Delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
ShippingMethod::firstOrCreate(['shipping_zone_id' => $z->id, 'type' => 'free_shipping'], ['title' => 'Free Express Delivery', 'cost' => 0, 'enabled' => true, 'position' => 1, 'min_amount' => 19900]);
foreach ([['stripe', 'Credit / Debit Card', ['publishable_key' => 'pk_test_apv', 'secret_key' => 'sk_test_apv']], ['tabby', 'Tabby', ['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'merchant_code' => 'kbb']], ['tamara', 'Tamara', ['api_token' => 'x', 'notification_token' => 'y']], ['cod', 'Cash on delivery', []]] as $i => [$id, $title, $cfg]) {
  $row = PaymentProvider::find($id) ?: new PaymentProvider();
  $row->forceFill(['id' => $id, 'title' => $title, 'enabled' => true, 'mode' => 'test', 'position' => $i]);
  $row->config = $cfg; $row->save();
}
app(App\\Services\\Payments\\GatewayCredentials::class)->forget();
$ids = [];
foreach (json_decode(file_get_contents(__DIR__.'/bag.json'), true) as $p) {
  $ids[$p['slug']] = Product::updateOrCreate(['slug' => $p['slug']], array_filter(['name' => $p['name'], 'status' => 'publish', 'is_visible' => true, 'price' => $p['price'], 'sale_price' => $p['sale'] ?? null, 'stock_status' => 'instock', 'image' => $p['img']], fn ($v) => $v !== null))->id;
}
echo json_encode($ids);
`;

function env() {
  return Object.assign({}, process.env, {APP_ENV:'local', APP_DEBUG:'false', APP_KEY:'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=', APP_URL:BASE, KBB_BASE_PATH:'', KBB_PUBLIC_PATH:path.join(ROOT, 'public'), DB_CONNECTION:'sqlite', DB_DATABASE:path.join(WORK, 'site.sqlite'), SESSION_DRIVER:'file', CACHE_STORE:'file', QUEUE_CONNECTION:'sync', MAIL_MAILER:'log', LOG_LEVEL:'error', SESSION_SECURE_COOKIE:'false'});
}
/* the app's own seed bag (shared/core.js seed()): the 2nd and 5th best-selling/trending products, 1 and 2 of them */
function bagItems() {
  global.window = {};
  eval(fs.readFileSync(path.join(APP, 'en/data.js'), 'utf8'));
  const PL = global.window.APV_DATA.products;
  const IC = (w, p) => p ? (/^https?:/.test(p) ? p : `https://kbeautybliss.com/img-cache/${w}${p}`) : null;
  const pick = (n) => PL.filter((p) => p.tags.includes('best') || p.tags.includes('trending'))[n];
  return [pick(1), pick(4)].map((p, i) => ({slug:p.slug, name:p.name, price:Math.round(p.price * 100), sale:p.sale != null ? Math.round(p.sale * 100) : null, img:p.img || IC(400, p.im), qty:i + 1}));
}
async function boot() {
  if (urlArg) return null;
  if (!fs.existsSync(path.join(ROOT, 'vendor/autoload.php'))) throw new Error('vendor/ is missing: link the main checkout\'s (ln -s <main>/vendor vendor)');
  fs.mkdirSync(WORK, {recursive:true});
  fs.writeFileSync(path.join(WORK, 'router.php'), ROUTER);
  fs.writeFileSync(path.join(WORK, 'seed.php'), SEED);
  fs.writeFileSync(path.join(WORK, 'bag.json'), JSON.stringify(bagItems()));
  const db = path.join(WORK, 'site.sqlite');
  if (!fs.existsSync(db)) fs.writeFileSync(db, '');
  const m = spawnSync('php', ['artisan', 'migrate', '--force'], {cwd:ROOT, env:env(), encoding:'utf8'});
  if (m.status) throw new Error('migrate failed: ' + (m.stderr || m.stdout).slice(-400));
  const sd = spawnSync('php', [path.join(WORK, 'seed.php')], {cwd:ROOT, env:env(), encoding:'utf8'});
  if (sd.status) throw new Error('seed failed: ' + (sd.stderr || sd.stdout).slice(-400));
  const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', 'public', path.join(WORK, 'router.php')], {cwd:ROOT, env:Object.assign(env(), {PHP_CLI_SERVER_WORKERS:'4'}), stdio:'ignore'});
  for (let i = 0; i < 60; i++) { try { const r = await fetch(BASE + '/'); if (r.status < 500) break; } catch {} await new Promise((r) => setTimeout(r, 500)); }
  return srv;
}

/* in the page: sizes the parity table and the checker compare */
function measureCo(kind) {
  const q = (s) => document.querySelector(s);
  const R = (e) => { if (!e) return null; const r = e.getBoundingClientRect(); const c = getComputedStyle(e); return {h:+r.height.toFixed(1), w:+r.width.toFixed(1), y:Math.round(r.top + scrollY), rad:c.borderRadius, bg:c.backgroundColor, fs:c.fontSize, fw:c.fontWeight, color:c.color}; };
  if (kind === 'cart') return {title:R(q('.kbb-cartpage h1')), line:R(q('.kbb-cartpage .ci')), qty:R(q('.kbb-cartpage .qty')), lines:R(q('.kbb-cartpage .items')), sum:R(q('.kbb-cartpage aside.sum')), btn:R(q('.kbb-cartpage a.cobtn')), order:[...document.querySelectorAll('.kbb-cartpage .wrap > *, .kbb-cartpage .grid > *, .kbb-cartpage aside.sum > *')].filter((e) => e.getBoundingClientRect().height).map((e) => (e.tagName + '.' + String(e.className).split(' ')[0]).toLowerCase())};
  const sec = [...document.querySelectorAll('.kbb-checkout .formbox > .sec h2, .kbb-checkout .sec > h2')].map((h) => h.textContent.replace(/\s+/g, ' ').trim());
  return {head:R(q('.co-head')), sumRow:R(q('#kbbSumRow')), h2:R(q('.kbb-checkout .sec h2')), field:R(q('#billing_email_field .fld')), place:R(q('.kbb-mobile-order .place')), method:R(q('.wc_payment_method')), delivery:R(q('.woocommerce-shipping-methods li')), sections:sec,
    fields:[...document.querySelectorAll('.kbb-checkout #customer_details .form-row')].filter((e) => e.getBoundingClientRect().height).map((e) => e.id), methods:[...document.querySelectorAll('.wc_payment_method input')].map((i) => i.value), page:document.documentElement.scrollHeight};
}

(async () => {
  const srv = await boot();
  try {
    const browser = await pw.chromium.launch({executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome'});
    const bag = JSON.parse(fs.readFileSync(path.join(WORK, 'bag.json'), 'utf8'));
    const out = {}, measure = {};
    fs.mkdirSync(SHOTS, {recursive:true});
    for (const lang of ['en', 'ar']) {
      const ctx = await browser.newContext({viewport:{width:390, height:844}, isMobile:true, hasTouch:true, userAgent:UA});
      const page = await ctx.newPage();
      const pre = lang === 'ar' ? '/ar' : '';
      for (const it of bag) for (let k = 0; k < it.qty; k++) { await page.goto(`${BASE}${pre}/product/${it.slug}/`, {waitUntil:'domcontentloaded'}); await page.click('#mainAdd'); await page.waitForTimeout(1200); }
      const caps = [];
      /* two pages: the checkout's rules are numbered after the cart's, so the union keeps both (and each page's own order) */
      const shift = (c) => { c.rules.forEach((r) => { r.i += 1e6; }); return c; };
      /* the cart page */
      await page.goto(`${BASE}${pre}/cart/`, {waitUntil:'networkidle'}); await page.waitForTimeout(600);
      caps.push(await page.evaluate(extractRules, ['.kbb-cartpage']));
      const cart = await page.evaluate(() => document.querySelector('.kbb-cartpage').outerHTML);
      measure[`${lang}-cart`] = await page.evaluate(measureCo, 'cart');
      await page.screenshot({path:path.join(SHOTS, `${lang}-cart.png`), fullPage:true});
      /* the checkout, in each state the shopper can put it in, so every state's rules are kept */
      await page.goto(`${BASE}${pre}/checkout/`, {waitUntil:'networkidle'}); await page.waitForTimeout(800);
      const checkout = await page.evaluate(() => document.querySelector('section.kbb-checkout').outerHTML);
      const words = await page.evaluate(() => { const m = document.body.innerHTML.match(/WORDS = (\{[^;]*\});/); return m ? JSON.parse(m[1]) : null; });
      measure[`${lang}-checkout`] = await page.evaluate(measureCo, 'checkout');
      await page.screenshot({path:path.join(SHOTS, `${lang}-checkout.png`), fullPage:true});
      caps.push(shift(await page.evaluate(extractRules, ['section.kbb-checkout'])));
      for (const act of ['#kbbSumRow', '.kbb-mobile-order .co-ta', '#payment_method_tabby', '#create_account', '#is_gift']) {
        await page.locator(act).first().click({force:true}).catch(() => {});
        await page.waitForTimeout(350);
        caps.push(shift(await page.evaluate(extractRules, ['section.kbb-checkout'])));
      }
      await page.screenshot({path:path.join(SHOTS, `${lang}-checkout-open.png`), fullPage:true});
      /* a refused submit: the site's own inline errors */
      await page.evaluate(() => { const b = document.querySelector('.kbb-mobile-order .place'); if (b) { b.scrollIntoView(); } });
      out[lang] = {cart, checkout, words};
      fs.writeFileSync(path.join(APP, lang, 'site-co.css'), buildCss(caps, lang, 'kc', 'cart page and one-page checkout (13 October layout)', 'this repository\'s shop (resources/views/store/cart.blade.php, checkout.blade.php), rendered at 390'));
      await ctx.close();
    }
    await browser.close();
    const clean = (h) => h.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<dialog[\s\S]*?<\/dialog>/g, '').replace(/<input type="hidden"[^>]*>/g, '').replace(/\n\s*\n/g, '\n').replace(/>\s+</g, '> <').trim();
    for (const lang of ['en', 'ar']) fs.writeFileSync(path.join(APP, lang, 'site-co.js'), `/* Generated by tools/apv-site-co.cjs: the shop's own cart page and one-page checkout markup (${lang}), read by shared/checkout.js. */\nwindow.APV_SITE_CO = ${JSON.stringify({cart:clean(out[lang].cart), checkout:clean(out[lang].checkout), words:out[lang].words})};\n`);
    const mf = path.join(APP, 'site-measure.json');
    const M = JSON.parse(fs.readFileSync(mf, 'utf8'));
    M.co = {source:'this repository, rendered locally at 390 (the live cart and checkout refuse automated browsers)', captured:new Date().toISOString(), measured:measure};
    fs.writeFileSync(mf, JSON.stringify(M, null, 1));
    console.log(JSON.stringify(Object.fromEntries(Object.entries(measure).map(([k, v]) => [k, v.sections || v.order]))));
  } finally {
    if (srv) srv.kill();
  }
})().catch((e) => { console.error(e); process.exit(1); });
