import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';

const BASE = 'http://127.0.0.1:8731';
const SP = '/tmp/claude-0/-home-user-kbbstore/719ff49f-5d63-5981-9f03-fffb8cde43b0/scratchpad';
const STUB = fs.readFileSync(`${SP}/stripe-stub.js`, 'utf8');
const PID = Number(process.argv[2] || 25);

let failures = 0;
const calls = page => page.evaluate(() => { try { return JSON.parse(sessionStorage.getItem('__stripeCalls') || '[]'); } catch (e) { return []; } });
const ok  = (c, m) => { console.log(`   ${c ? 'PASS' : 'FAIL'}  ${m}`); if (!c) failures++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

/* What the stubbed card did, so the preview's fake Stripe API answers the
   server's read of the intent the same way the browser's stub answered the
   page. Without it the two halves disagree and the abandon path cannot work. */
function outcome(kind) { fs.writeFileSync('/tmp/kbbfu-outcome', kind); }

async function open(stubAnswer) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const posts = [], navs = [], strays = [], errors = [];

  /* Order matters: Playwright gives the LAST matching route precedence, so the
     catch-all is registered FIRST and the js.stripe.com stub after it. The
     other way round the stub never runs and every scenario fails for a reason
     that has nothing to do with the checkout. */
  await page.route('**stripe.com/**', r => { strays.push(r.request().url()); r.abort(); });
  await page.route('**js.stripe.com/**', r =>
    r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));

  page.on('request', r => { if (r.method() === 'POST') posts.push(r.url().replace(BASE, '')); });
  page.on('framenavigated', f => { if (f === page.mainFrame()) navs.push(f.url().replace(BASE, '')); });
  page.on('pageerror', e => errors.push(e.message));

  if (stubAnswer) await page.addInitScript(a => { window.__stripeStub = a; }, stubAnswer);

  return { context, page, posts, navs, strays, errors };
}

async function toCheckout(page) {
  await page.goto(`${BASE}/product/preview-serum`, { waitUntil: 'domcontentloaded' });
  const added = await page.evaluate(async (pid) => {
    const r = await fetch('/api/cart/add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
      body: JSON.stringify({ product_id: pid, quantity: 1 }),
    });
    return r.status + ' ' + (await r.text()).slice(0, 120);
  }, PID);
  if (process.env.WALK_DEBUG) console.log('   add ->', added);
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  if (process.env.WALK_DEBUG) console.log('   checkout url ->', page.url());

  await page.fill('#billing_email', 'buyer@example.com');
  await page.fill('#billing_first_name', 'Aisha');
  const last = await page.$('#billing_last_name');
  if (last) await last.fill('Khan');
  await page.fill('#billing_address_1', '12 Marina Walk');
  await page.fill('#billing_city', 'Dubai');
  await page.fill('#billing_state', 'Dubai');
  /* The LABEL, not the input. kbb-checkout.css hides the radio itself
     (opacity:0, 1x1) and draws the control on the label, so a click on the
     input is intercepted — which is also how a real shopper selects it. */
  await page.click('label[for="payment_method_stripe"]');
  await page.waitForTimeout(200);
}

/* ------------------------------------------------- 1. the fields are there */
{
  console.log('\n=== 1. The card fields render on our own checkout ===');
  const { context, page, strays, errors } = await open();
  await toCheckout(page);

  const mount = await page.$('#kbb-card-element');
  ok(!!mount, 'the card mount box is on the checkout page');

  const visible = await page.isVisible('#kbb-card-element');
  ok(visible, 'it is visible once Credit / Debit Card is selected');

  const mounted = (await calls(page)).some(c => c.call === 'mount');
  ok(mounted, 'Stripe.js mounted an element into it');

  const created = (await calls(page)).find(c => c.call === 'create');
  ok(created && created.type === 'card', `the element created is a card element (got ${created && created.type})`);

  const key = (await calls(page)).find(c => c.call === 'Stripe');
  ok(key && key.key === 'pk_test_previewkey', 'booted with the shop\'s publishable key');
  ok(key && key.args === 1, 'and with nothing else handed to it');

  // Hidden again when another method is chosen — the CSS reveal, not JS.
  await page.click('label[for="payment_method_cod"]');
  await page.waitForTimeout(200);
  ok(!(await page.isVisible('#kbb-card-element')), 'and hidden again under Cash on delivery');

  ok(strays.length === 0, `no request to any other Stripe host (${strays.length})`);
  ok(errors.length === 0, `no page errors (${errors.join('; ')})`);
  await context.close();
}

/* ------------------------------------------------ 2. a payment that works */
{
  console.log('\n=== 2. A card that works: no redirect, order received ===');
  outcome('succeeded');
  const { context, page, posts, navs, strays } = await open({ paymentIntent: { id: 'pi_preview', status: 'succeeded' } });
  await toCheckout(page);

  await page.click('.place');
  await page.waitForURL(/checkout\/success/, { timeout: 15000 }).catch(() => {});

  const confirm = (await calls(page)).find(c => c.call === 'confirmCardPayment');
  ok(!!confirm, 'the page asked Stripe to confirm the card');
  ok(confirm && /^pi_preview_\d+_secret_/.test(confirm.clientSecret), 'with this order\'s client secret');
  ok(confirm && confirm.hasCard, 'and the mounted card element, not a value read out of the DOM');
  ok(confirm && confirm.billing && confirm.billing.email === 'buyer@example.com', 'billing details came from our own form');

  ok(posts.includes('/checkout/place'), 'the order was placed through /checkout/place');
  ok(posts.includes('/checkout/card/paid'), 'and the shop was told the payment succeeded');

  ok(navs.every(u => !/stripe\.com/.test(u)), 'the browser never navigated to a Stripe page');
  ok(strays.length === 0, 'and never tried to');
  ok(/\/checkout\/success/.test(page.url()), `it ended on the order-received page (${page.url()})`);
  await context.close();
}

/* ------------------------------------------------------- 3. a declined card */
{
  console.log('\n=== 3. A declined card: Stripe\'s own words, basket intact ===');
  outcome('open');
  const { context, page, navs } = await open({ error: { code: 'card_declined', message: 'Your card was declined.' } });
  await toCheckout(page);

  const before = await page.textContent('.co-items');
  // Only navigations AFTER the press count: getting to the checkout took two
  // of its own, and counting those would make this assertion vacuous.
  const navsBefore = navs.length;
  await page.click('.place');
  await page.waitForTimeout(1200);

  const shown = (await page.textContent('#kbb-card-error') || '').trim();
  ok(shown === 'Your card was declined.', `Stripe's own sentence is on the page ("${shown}")`);
  ok(await page.isVisible('#kbb-card-error'), 'and it is visible, beside the card fields');

  ok(!/checkout\/success/.test(page.url()), `the shopper is still on the checkout (${page.url()})`);
  ok(navs.length === navsBefore, `nothing navigated away (${navs.length - navsBefore} navigations after the press)`);

  const after = await page.textContent('.co-items');
  ok(before === after, 'the basket is exactly as it was');

  const enabled = await page.isEnabled('.place');
  ok(enabled, 'Place order is live again, so another card can be tried');

  const bail = await page.isVisible('[data-kbb-card-bail]');
  ok(bail, 'and a way back to the basket is offered');
  await context.close();
}

/* ------------------------------------------------------- 4. 3-D Secure */
{
  console.log('\n=== 4. A 3-D Secure challenge: handled, not redirected ===');
  outcome('succeeded');
  const { context, page, navs, strays } = await open({
    __delay: 900,                       // the bank's modal, open for a moment
    paymentIntent: { id: 'pi_preview', status: 'succeeded' },
  });
  await toCheckout(page);

  await page.click('.place');
  await page.waitForTimeout(350);

  // While the challenge is open the shopper cannot press Place order again.
  ok(!(await page.isEnabled('.place')), 'Place order is locked while the bank is asking');
  ok(!/stripe\.com/.test(page.url()), 'and the page has not left the shop');

  await page.waitForURL(/checkout\/success/, { timeout: 15000 }).catch(() => {});
  ok(/\/checkout\/success/.test(page.url()), 'after it is answered, the order is received');
  ok(navs.every(u => !/stripe\.com/.test(u)), 'with no navigation to Stripe at any point');
  ok(strays.length === 0, 'and no request to a Stripe host but js.stripe.com');
  await context.close();
}

/* ---------------------------------------------- 5. double-press one button */
{
  console.log('\n=== 5. Place order pressed twice ===');
  const { context, page, posts } = await open({ __delay: 700, paymentIntent: { id: 'pi_preview_1', status: 'succeeded' } });
  await toCheckout(page);

  await page.click('.place');
  await page.waitForTimeout(80);
  await page.click('.place', { force: true }).catch(() => {});
  await page.waitForTimeout(80);
  await page.click('.place', { force: true }).catch(() => {});
  await page.waitForURL(/checkout\/success/, { timeout: 15000 }).catch(() => {});

  const places = posts.filter(u => u === '/checkout/place').length;
  ok(places === 1, `one order was placed, not three (${places} POSTs to /checkout/place)`);

  const confirms = (await calls(page)).filter(c => c.call === 'confirmCardPayment').length;
  ok(confirms <= 1, `the card was sent to Stripe once (${confirms})`);
  await context.close();
}

/* ------------------------------------------------ 6. giving up on the card */
{
  console.log('\n=== 6. Giving up: the basket comes back ===');
  outcome('open');
  const { context, page, posts, navs } = await open({ error: { code: 'card_declined', message: 'Your card was declined.' } });

  /* The ANSWER is captured, not just the request. Asserting that the browser
     POSTed proves nothing about what the server did with it: the first run of
     this scenario passed while the endpoint was answering 409 and the order
     stayed `pending`, because the page it checked afterwards was the page it
     had never left.
     The STATUS only — the body is gone by the time it can be read, because the
     page navigates the moment the answer arrives. That navigation is itself
     the second signal, since the script only makes it on ok:true. */
  let abandon = null;
  page.on('response', r => {
    if (r.url().endsWith('/checkout/card/abandon')) abandon = { status: r.status() };
  });

  await toCheckout(page);
  await page.click('.place');
  await page.waitForTimeout(1200);
  const navsBeforeBail = navs.length;
  await page.click('[data-kbb-card-bail]');
  await page.waitForTimeout(2000);

  ok(posts.includes('/checkout/card/abandon'), 'the shop was told the payment was abandoned');
  ok(abandon && abandon.status === 200, `and it accepted (${abandon && abandon.status})`);
  ok(navs.length > navsBeforeBail, 'and the page moved on, which it only does when the shop says the basket is back');

  /* A FULL RELOAD, which is the only way to tell a restored basket from a page
     that simply never navigated: /checkout/ bounces an empty bag to /cart/. */
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  ok(/\/checkout\/?$/.test(page.url().split('?')[0]), `the checkout still loads (${page.url()})`);
  ok(!!(await page.$('#kbb-card-element')), 'with the basket in it, so the shopper can pay another way');
  await context.close();
}

/* ------------------------------- 7. correcting a field after a decline */
{
  console.log('\n=== 7. Correcting the address after a decline ===');
  outcome('open');
  const { context, page, posts } = await open({ error: { code: 'card_declined', message: 'Your card was declined.' } });
  await toCheckout(page);

  await page.click('.place');
  await page.waitForTimeout(1200);

  const placedFirst = posts.filter(u => u === '/checkout/place').length;
  ok(placedFirst === 1, 'the first press placed one order');

  /* The order was written from the fields as they were at that press, and a
     retry reuses it rather than re-posting them. So a correction has to
     release it, or the goods go to the address the shopper has just fixed. */
  await page.fill('#billing_address_1', '99 Corrected Road');
  await page.click('#billing_city');           // blur, so `change` fires
  await page.waitForTimeout(1200);

  ok(posts.includes('/checkout/card/abandon'), 'correcting the address released the order');

  outcome('succeeded');
  await page.evaluate(() => { window.__stripeStub = { paymentIntent: { id: 'pi_preview', status: 'succeeded' } }; });
  await page.click('.place');
  await page.waitForURL(/checkout\/success/, { timeout: 15000 }).catch(() => {});

  const placedAgain = posts.filter(u => u === '/checkout/place').length;
  ok(placedAgain === 2, `the retry placed a fresh order carrying the correction (${placedAgain} places)`);
  ok(/\/checkout\/success/.test(page.url()), `and it went through (${page.url()})`);
  await context.close();
}

await browser.close();
console.log(`\n${failures === 0 ? 'ALL CHECKS PASSED' : failures + ' CHECK(S) FAILED'}`);
process.exit(failures === 0 ? 0 : 1);
