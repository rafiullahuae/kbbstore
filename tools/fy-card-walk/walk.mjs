/*
 * The card form in a real browser — Lane FY.
 *
 * Pest covers the server and the markup. These scenarios cover the half Pest
 * cannot see: that three Elements go into three boxes, that the boxes are laid
 * out the way the owner's reference shows at a desktop width AND at 390px, that
 * the save-card row appears only when it can mean something, that Link is asked
 * for with disableLink, and — the regression that matters most — that a
 * correction made after a declined card still replaces the order rather than
 * shipping to the address the first attempt was placed against.
 *
 * Both halves of Stripe are stubbed: stripe-stub.js stands in for
 * js.stripe.com/v3 in the browser, preview-index.php fakes api.stripe.com on
 * the server. What this proves is that the page and the application agree with
 * each other; it does not prove Stripe agrees. See the last section of
 * docs/FY-CHECKOUT-CARD-AND-PREFILL.md.
 *
 * Running it: the same three steps as tools/fu-card-walk/README.md, with
 * /home/user/kbb-wt/fy in place of .../fu and port 8741.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';

const BASE = 'http://127.0.0.1:8741';
const HERE = '/home/user/kbb-wt/fy/tools/fy-card-walk';
const SHOTS = '/home/user/kbb-wt/fy/docs/fy-card-shots';
const STUB = fs.readFileSync(`${HERE}/stripe-stub.js`, 'utf8');
const PID = Number(process.argv[2] || 1);

fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0;
const calls = page => page.evaluate(() => { try { return JSON.parse(sessionStorage.getItem('__stripeCalls') || '[]'); } catch (e) { return []; } });
const ok = (c, m) => { console.log(`   ${c ? 'PASS' : 'FAIL'}  ${m}`); if (!c) failures++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

/* What the stubbed card did, so the preview's fake Stripe API answers the
   server's read of the intent the same way the browser's stub answered the
   page. Without it the two halves disagree and the abandon path cannot work. */
function outcome(kind) { fs.writeFileSync('/tmp/kbbfy-outcome', kind); }

async function open(stubAnswer, viewport = { width: 1280, height: 1000 }) {
  const context = await browser.newContext({ viewport });
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

async function toCheckout(page, { fill = true } = {}) {
  await page.goto(`${BASE}/product/preview-serum`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(async (pid) => {
    await fetch('/api/cart/add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
      body: JSON.stringify({ product_id: pid, quantity: 1 }),
    });
  }, PID);
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });

  if (fill) {
    await page.fill('#billing_email', 'buyer@example.com');
    await page.fill('#billing_first_name', 'Aisha');
    const last = await page.$('#billing_last_name');
    if (last) await last.fill('Khan');
    await page.fill('#billing_address_1', '12 Marina Walk');
    await page.fill('#billing_city', 'Dubai');
    await page.fill('#billing_state', 'Dubai');
  }

  /* The LABEL, not the input. kbb-checkout.css hides the radio itself
     (opacity:0, 1x1) and draws the control on the label, so a click on the
     input is intercepted — which is also how a real shopper selects it. */
  await page.click('label[for="payment_method_stripe"]');
  await page.waitForTimeout(250);
}

/* Sign in as the seeded customer, the way the sign-in form does. */
async function signIn(page) {
  await page.goto(`${BASE}/my-account/`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', 'aisha@example.com');
  await page.fill('input[name="password"]', 'preview-password');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(400);
}

/* ---------------------------------------- 1. three fields, three boxes, rows */
{
  console.log('\n=== 1. The card number, the expiry and the security code, each in its own box ===');
  const { context, page, strays, errors } = await open();
  await toCheckout(page);

  for (const id of ['kbb-card-number', 'kbb-card-expiry', 'kbb-card-cvc']) {
    ok(await page.isVisible(`#${id}`), `#${id} is on the page and visible under Credit / Debit Card`);
  }

  const created = (await calls(page)).filter(c => c.call === 'create').map(c => c.type);
  ok(JSON.stringify(created) === JSON.stringify(['cardNumber', 'cardExpiry', 'cardCvc']),
    `three individual Elements were created, in order (got ${created.join(', ')})`);

  const mounted = (await calls(page)).filter(c => c.call === 'mount').map(c => c.id);
  ok(mounted.includes('kbb-card-number') && mounted.includes('kbb-card-expiry') && mounted.includes('kbb-card-cvc'),
    'each went into its own box');

  // THE LAYOUT, measured rather than looked at: the number on its own row, and
  // the expiry and the security code sharing the next one.
  const box = async id => page.$eval(`#${id}`, el => { const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width }; });
  const n = await box('kbb-card-number'), e = await box('kbb-card-expiry'), c = await box('kbb-card-cvc');

  ok(e.y > n.y + 10, 'the expiry sits on a row below the card number');
  ok(Math.abs(e.y - c.y) < 2, 'the expiry and the security code share one row');
  ok(c.x > e.x + e.w - 2, 'and the security code is beside the expiry, not under it');
  ok(n.w > e.w + 20, 'the card number has the full width to itself');

  // Each has its own label above its own box.
  for (const [id, text] of [['number', 'CARD NUMBER'], ['expiry', 'EXPIRATION DATE'], ['cvc', 'SECURITY CODE']]) {
    const label = await page.$eval(`#kbb-card-${id}-label`, el => ({
      text: el.textContent.trim().toUpperCase(),
      transform: getComputedStyle(el).textTransform,
      y: el.getBoundingClientRect().y,
    }));
    const boxY = (await box(`kbb-card-${id}`)).y;
    ok(label.text === text, `the ${id} box is labelled ${text}`);
    ok(label.transform === 'uppercase', 'drawn uppercase by the stylesheet, not shouted in the translation');
    ok(label.y < boxY, 'and the label is above the box, not beside it');
  }

  // The one line, with a padlock, and the paragraph gone.
  const secure = await page.$eval('.kbb-card-secure', el => ({
    text: el.textContent.trim(),
    svg: !!el.querySelector('svg'),
    y: el.getBoundingClientRect().y,
  }));
  ok(secure.text === '100% secure & encrypted — Use any card', `the secure line reads "${secure.text}"`);
  ok(secure.svg, 'with a padlock drawn beside it');
  ok(secure.y < n.y, 'above the fields');
  const boxText = await page.$eval('.payment_box.payment_method_stripe', el => el.textContent);
  ok(!boxText.includes('Pay securely by card'), 'and the three-sentence paragraph is gone');

  await page.screenshot({ path: `${SHOTS}/desktop-1280.png`, clip: await page.$eval('.payment_box.payment_method_stripe', el => {
    const r = el.getBoundingClientRect();
    return { x: r.x - 12, y: r.y - 12, width: r.width + 24, height: r.height + 24 };
  }) });

  ok(strays.length === 0, `no request to any other Stripe host (${strays.length})`);
  ok(errors.length === 0, `no page errors (${errors.join('; ')})`);
  await context.close();
}

/* ------------------------------------------------------- 2. the same at 390 */
{
  console.log('\n=== 2. The same form at 390px ===');
  const { context, page, errors } = await open(null, { width: 390, height: 900 });
  await toCheckout(page);

  const box = async id => page.$eval(`#${id}`, el => { const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width }; });
  const e = await box('kbb-card-expiry'), c = await box('kbb-card-cvc');

  ok(Math.abs(e.y - c.y) < 2, 'expiry and security code still share a row at phone width');
  ok(c.x > e.x, 'in that order');

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  ok(!overflow, 'and nothing has pushed the page wider than the phone');

  await page.screenshot({ path: `${SHOTS}/phone-390.png`, clip: await page.$eval('.payment_box.payment_method_stripe', el => {
    const r = el.getBoundingClientRect();
    return { x: Math.max(0, r.x - 8), y: r.y - 8, width: Math.min(390, r.width + 16), height: r.height + 16 };
  }) });

  ok(errors.length === 0, `no page errors (${errors.join('; ')})`);
  await context.close();
}

/* ----------------------------------------------------- 3. Stripe Link is off */
{
  console.log('\n=== 3. Stripe Link is not offered ===');
  const { context, page } = await open();
  await toCheckout(page);

  const number = (await calls(page)).find(c => c.call === 'create' && c.type === 'cardNumber');
  ok(number && number.options && number.options.disableLink === true,
    `the card number element is created with disableLink (got ${number && number.options && number.options.disableLink})`);

  await context.close();
}

/* ------------------------------------------- 4. the save-card row, and who sees it */
{
  console.log('\n=== 4. "Save this card" is shown only where it can mean something ===');
  const { context, page } = await open();
  await toCheckout(page);

  ok(!(await page.isVisible('[data-kbb-card-save-row]')), 'a guest who is not making an account does not see it');

  // Ticking "create an account" two steps up the form reveals it.
  await page.click('label[for="create_account"]');
  await page.waitForTimeout(200);
  ok(await page.isVisible('[data-kbb-card-save-row]'), 'ticking "create an account" reveals it');

  await page.check('[data-kbb-card-save]');
  ok(await page.isChecked('[data-kbb-card-save]'), 'and it can be ticked');

  /*
   * THE COMPUTED STYLE, NOT THE DECLARED ONE.
   *
   * kbb-checkout.css line 248 is `.kbb-checkout .payment_box label{font-size:
   * 10.5px;display:block}` — 0,2,1 — and this row is a <label> inside a
   * .payment_box. Written as a bare `.kbb-card-save` the rule in the partial
   * scored 0,2,0 and lost EVERY declaration in it: measured here at
   * display:block, no gap, 10.5px, which is a checkbox jammed against its
   * words. Qualified to `label.kbb-card-save` it ties and wins on source order.
   * Nothing about that is visible in the markup, so it is measured.
   */
  const row = await page.$eval('[data-kbb-card-save-row]', el => {
    const cs = getComputedStyle(el);
    const box = el.querySelector('input').getBoundingClientRect();
    const words = el.querySelector('span').getBoundingClientRect();
    return { display: cs.display, fontSize: cs.fontSize, gap: words.x - (box.x + box.width), boxWidth: box.width };
  });
  ok(row.display === 'flex', `the row is laid out as a flex line (got ${row.display})`);
  ok(row.fontSize === '12px', `at the checkout's own 12px, not the payment box's 10.5px (got ${row.fontSize})`);
  ok(Math.abs(row.gap - 9) < 1.5, `with 9px between the box and the words (got ${row.gap.toFixed(1)})`);
  ok(Math.abs(row.boxWidth - 17) < 1, `and a 17px box, the same as every other tick on this page (got ${row.boxWidth})`);

  const secure = await page.$eval('.kbb-card-secure', el => {
    const cs = getComputedStyle(el);
    return { display: cs.display, marginBottom: cs.marginBottom };
  });
  ok(secure.display === 'flex', 'the secure line is a flex row, so the padlock sits on the text baseline');
  ok(secure.marginBottom === '3px', `and keeps its own margin against .payment_box p (got ${secure.marginBottom})`);

  await page.screenshot({ path: `${SHOTS}/save-card-row.png`, clip: await page.$eval('[data-kbb-card-save-row]', el => {
    const r = el.getBoundingClientRect();
    return { x: r.x - 8, y: r.y - 8, width: r.width + 16, height: r.height + 16 };
  }) });

  // Un-ticking the account hides it AND clears it: a hidden checkbox still
  // posts, and a save_card=1 from a control nobody can see is the state the
  // row exists to avoid.
  await page.click('label[for="create_account"]');
  await page.waitForTimeout(200);
  ok(!(await page.isVisible('[data-kbb-card-save-row]')), 'un-ticking hides it again');
  ok(!(await page.isChecked('[data-kbb-card-save]')), 'and clears it, so nothing can post from a hidden box');

  await context.close();
}

/* ------------------------------ 5. signed in: the boxes fill and the row shows */
{
  console.log('\n=== 5. A signed-in customer: prefilled details, and the tick offered ===');
  const { context, page, errors } = await open();
  await signIn(page);
  await toCheckout(page, { fill: false });

  const value = sel => page.$eval(sel, el => el.value);
  ok(await value('#billing_email') === 'aisha@example.com', 'the email box is filled in from the account');
  ok(await value('#billing_phone') === '0501234567', 'and the phone number');
  ok(await value('#billing_first_name') === 'Aisha Khan', 'and the name');
  // The seeded customer has a BILLING address and no shipping one, which is
  // the shape most of this shop's imported customers are in.
  ok(await value('#billing_address_1') === '12 Marina Walk', 'and the address, read off the billing row');
  ok(await value('#billing_city') === 'Dubai Marina', 'and the city');

  ok(await page.isVisible('[data-kbb-card-save-row]'), 'the save-card row is offered without anything being ticked');

  await page.screenshot({ path: `${SHOTS}/prefilled-checkout.png`, fullPage: false });

  // The whole card form as a signed-in shopper sees it: the secure line, the
  // three boxes, and the tick that is only offered to somebody it can serve.
  await page.screenshot({ path: `${SHOTS}/desktop-signed-in.png`, clip: await page.$eval('.payment_box.payment_method_stripe', el => {
    const r = el.getBoundingClientRect();
    return { x: r.x - 12, y: r.y - 12, width: r.width + 24, height: r.height + 24 };
  }) });

  // A prefilled field is still a field: changing it must stick.
  await page.fill('#billing_address_1', '99 Corrected Road');
  ok(await value('#billing_address_1') === '99 Corrected Road', 'and a prefilled box can still be typed over');

  ok(errors.length === 0, `no page errors (${errors.join('; ')})`);
  await context.close();
}

/* --------------------------------- 6. a saved card, end to end through the app */
{
  console.log('\n=== 6. Ticking it opens an intent that keeps the card ===');
  outcome('succeeded');
  const { context, page, posts, navs, strays } = await open({ paymentIntent: { id: 'pi_preview', status: 'succeeded' } });
  await signIn(page);
  await toCheckout(page, { fill: false });

  await page.check('[data-kbb-card-save]');
  await page.click('.place');
  await page.waitForURL(/checkout\/success/, { timeout: 15000 }).catch(() => {});

  const confirm = (await calls(page)).find(c => c.call === 'confirmCardPayment');
  ok(!!confirm, 'the page asked Stripe to confirm the card');
  ok(confirm && confirm.cardType === 'cardNumber', `and handed it the card NUMBER element (got ${confirm && confirm.cardType})`);

  ok(posts.includes('/checkout/place'), 'the order was placed');
  ok(navs.every(u => !/stripe\.com/.test(u)), 'and the shopper never left this site');
  ok(strays.length === 0, 'no request to any other Stripe host');

  await context.close();
}

/* ------------------- 7. the regression: a correction after a declined card */
{
  console.log('\n=== 7. A correction after a decline still replaces the order ===');
  outcome('failed');
  const { context, page, posts } = await open({ error: { message: 'Your card was declined.' } });
  await toCheckout(page);

  await page.click('.place');
  await page.waitForTimeout(1200);

  ok(await page.isVisible('[data-kbb-card-error]'), 'Stripe\'s own sentence is shown beside the fields');
  ok((await page.textContent('[data-kbb-card-error]')).includes('declined'), 'and it is Stripe\'s wording, not ours');
  ok(await page.isVisible('[data-kbb-card-bail]'), 'and the way back to the basket is offered');

  const before = posts.filter(u => u === '/checkout/card/abandon').length;

  /*
   * THE DEFECT 2.60.215 FIXED, WALKED AGAIN because this lane added two more
   * controls to this form. The order was written from the fields as they stood
   * at the first press; changing one now has to release it, or the goods go to
   * the address nobody corrected.
   */
  await page.fill('#billing_address_1', '99 Corrected Road');
  await page.locator('#billing_city').click();
  await page.waitForTimeout(600);

  const after = posts.filter(u => u === '/checkout/card/abandon').length;
  ok(after > before, 'correcting the address released the order that had already been placed');

  await context.close();
}

/* ------------- 8. the fragment swap: the form survives a quantity tap */
{
  console.log('\n=== 8. A quantity change does not lose the fields or the tick ===');
  const { context, page, errors } = await open();
  await signIn(page);
  await toCheckout(page, { fill: false });

  await page.check('[data-kbb-card-save]');

  const before = (await calls(page)).filter(c => c.call === 'create').length;

  // #payment is replaced wholesale by the fragment refresh, which takes the
  // three mount boxes and the tick with it.
  await page.click('.co-q[data-d="1"]');
  await page.waitForTimeout(900);

  for (const id of ['kbb-card-number', 'kbb-card-expiry', 'kbb-card-cvc']) {
    ok(await page.isVisible(`#${id}`), `#${id} is back after the swap`);
  }

  const after = (await calls(page)).filter(c => c.call === 'create').length;
  ok(after === before && before === 3,
    `the same three Elements were re-mounted, not re-created (created ${after} in total)`);

  ok(await page.isChecked('[data-kbb-card-save]'),
    'and the save-card tick the shopper had set is still set');

  ok(errors.length === 0, `no page errors (${errors.join('; ')})`);
  await context.close();
}

console.log(`\n${failures === 0 ? 'ALL PASS' : failures + ' FAILURES'}\n`);
await browser.close();
process.exit(failures === 0 ? 0 : 1);
