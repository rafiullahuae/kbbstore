# Lane FU — card fields on our own checkout

The card is typed on `/checkout/`. There is no redirect to Stripe, in the
success case or any other. The fields are Stripe Elements: cross-origin iframes
served by `js.stripe.com` and mounted into our page, so the card number lives in
Stripe's document, is posted from there straight to Stripe, and never enters
this application's DOM or reaches this server.

One consequence, stated once because it changes an obligation rather than a
preference: serving the page the card is entered on moves this integration from
**SAQ-A to SAQ-A-EP**. Elements is the arrangement that keeps it as close to
SAQ-A as an on-site form can be, and it is what WooCommerce's own Stripe plugin
uses for its inline mode. Nothing in this lane builds a raw `<input>` for a card
number, and nothing posts one here.

---

## The integrator's one line

`routes/web.php` is on this lane's do-not-edit list. The two endpoints the card
form reports to live in `routes/checkout-card.php`, which needs one `require`.

**Anchor** — exactly this, and it appears once in `routes/web.php`:

```php
/*
 * Changing a line's quantity from the checkout summary, in place. Same group
 * and the same reasoning as the file above: posted by a shopper, so it needs
 * the session and CSRF, and it must come before the Phase 9 catch-all.
 */
require __DIR__.'/checkout-line.php';
```

**Replacement** — the same block with one paragraph and one line added:

```php
/*
 * Changing a line's quantity from the checkout summary, in place. Same group
 * and the same reasoning as the file above: posted by a shopper, so it needs
 * the session and CSRF, and it must come before the Phase 9 catch-all.
 */
require __DIR__.'/checkout-line.php';

/*
 * The two reports the card form on /checkout/ makes after a payment: that the
 * bank approved it, and that the shopper gave up on it. Same group and the same
 * reasoning again, and here the session is not merely convenient — it is the
 * only thing that authorises either call. Both are refused outright on a
 * request whose session does not name the order being asked about, so a group
 * without session middleware would refuse every one of them.
 */
require __DIR__.'/checkout-card.php';
```

Anywhere in that run of top-level requires works. What matters is that it stays
**above** `require __DIR__ . '/kbb-brands-blog.php';`, which ends in a catch-all
single-root-segment route.

Until this lands, `/checkout/card/paid` and `/checkout/card/abandon` answer 405.
The card still works: the order is placed, the intent is confirmed in the
browser and the webhook marks the order paid a few seconds later. What is lost
is the immediate confirmation and, more seriously, the ability of a shopper
whose card was declined to get their basket back — so this is not optional.

`tests/Feature/CheckoutCardFormTest.php` registers the same file in its
`beforeEach` and says why, so the endpoints are exercised either way.

## The cache migration

`database/migrations/2026_09_17_000000_clear_caches_checkout_card_fields.php`
ships with this. It is not optional and it is not only about the routes: the
compiled Blade for `store/checkout.blade.php` is keyed by path with a filemtime
check, and an unzip does not reliably land a newer timestamp. Without it the
owner applies the package and sees the payment box he has now — no card fields
— with the fix apparently applied.

---

## Assets

**No asset rebuild is required for this lane, and that is deliberate.**

The storefront serves BUILT assets: `@vite` resolves through
`public/build/manifest.json` to hashed files, and anything added under
`resources/css/**` or `resources/js/**` ships **inert** until somebody rebuilds
the bundle off-server. The card form cannot tolerate that gap — without its
script there are no fields at all — so:

- the script is `resources/views/partials/checkout/stripe-elements.blade.php`,
  not a module under `resources/js/`;
- the styles are an inline `<style>` in
  `resources/views/partials/checkout/stripe-card.blade.php`, not a rule added to
  `resources/css/kbb/kbb-checkout.css`.

Views travel in the package and take effect as soon as the compiled cache is
cleared, which the migration above does.

One rule this lane **depends on** and did not write: the reveal in
`kbb-checkout.css`

```css
.kbb-checkout #payment .wc_payment_method:has(input:checked) div.payment_box{display:block}
```

is what shows the card fields when Credit / Debit Card is selected and hides
them otherwise. It was checked against the **built** asset currently in the
repo — `public/build/assets/kbb-checkout-XzNUqgsL.css`, the file
`manifest.json` points at — and the id-qualified reveal is present there. So the
behaviour works on the shipped bundle with no rebuild. If a future rebuild drops
it, the card fields render with `display:none` and the checkout silently stops
being able to take a card.

---

## What changed, and what did not

**Changed**

| File | What |
| --- | --- |
| `app/Services/Payments/Gateways/StripeGateway.php` | `start()` opens a **PaymentIntent** instead of a Checkout Session and returns `PaymentStart::confirm()`. Reuses a live intent the order already has. Webhook gains `payment_intent.succeeded` / `.canceled` and stops failing an order on a retryable decline. Adds `confirmFromBrowser()` and `abandonIntent()`. |
| `app/Services/Payments/PaymentStart.php` | New `confirm($clientSecret, $providerRef)` outcome. |
| `app/Services/Payments/StripeConnect.php` | `EVENTS` subscribes to the two `payment_intent` events. |
| `app/Http/Controllers/Store/CheckoutController.php` | `place()` answers JSON when asked; new `cardConfirmed()` / `cardAbandoned()`. |
| `resources/views/partials/checkout/payment-methods.blade.php` | Includes the card mount inside the card option's own `.payment_box`. |
| `resources/views/store/checkout.blade.php` | One `@include`, appended to the existing scripts push. |
| `app/Services/Translation/InterfaceStrings.php` | Five new `store.checkout.card_*` keys. |

**New**: `resources/views/partials/checkout/stripe-card.blade.php`,
`resources/views/partials/checkout/stripe-elements.blade.php`,
`routes/checkout-card.php`, the clear-caches migration, and two test files.

**Not changed, and checked**: `PaymentConfirmer`, `PaymentCapturer`,
`PaymentRefunder`, `PaymentLedger`, the reconciliation reader, and the whole
`checkout.session.*` webhook path. Capture and refund read
`StripeGateway::paymentIntentId()`, which now finds a `pi_...` in
`orders.transaction_id` from the moment the order is placed instead of only
after the webhook lands — strictly earlier than before. The `cs_...` arm of that
method is kept and is not dead code: every order placed through the previous
hosted flow carries a Checkout session id and must stay refundable.

---

## The thing to do at go-live

**Reconnect Stripe, or add two events to the webhook endpoint by hand.**

Every shop already connected is subscribed to the hosted flow's events and to
nothing else. A card taken on the new checkout produces **no Checkout session**,
so `checkout.session.completed` never arrives — and without
`payment_intent.succeeded` the shop takes the money and never hears about it.

Store → Payments → Set up Stripe reconnects and adds the missing events to the
existing endpoint without creating a second one; `StripeConnectTest` pins that
it does. Doing nothing is the one option that loses money.

---

## What is proved against a fake, and what needs one real payment

**Every Stripe host is blocked by the sandbox's egress proxy.** Nothing here has
spoken to Stripe.

Proved against `Http::fake()` and a stubbed `js.stripe.com`
(`tests/Feature/StripeCardFieldsTest.php`, `tests/Feature/CheckoutCardFormTest.php`,
and a six-scenario Chromium walk):

- the exact request that opens a PaymentIntent — endpoint, form encoding,
  integer minor units, `payment_method_types[0]=card`, the `order_number`
  metadata, the `Idempotency-Key`;
- that `start()` never returns a redirect URL;
- intent reuse, and the three cases where a fresh intent is opened instead;
- every webhook branch, including that a decline on a still-payable intent does
  **not** fail the order and that a `canceled` one does;
- that the browser's report is verified server-side and cannot mark an order
  paid on its own;
- that the browser's report and the webhook cannot both apply;
- that the card fields render inside the card option, that Stripe.js is booted
  with the publishable key and nothing else, and that the secret key is not on
  the page;
- in Chromium, seven scenarios end to end against a running app: the fields
  mounting inside the card option and hiding again under Cash on delivery; a
  successful payment with **no navigation to any Stripe host**; a decline
  showing Stripe's own sentence beside the fields with the basket untouched and
  Place order live again; a delayed challenge locking Place order without
  leaving the page; one order from three presses; the basket restored on
  give-up; and a corrected address after a decline releasing the first order and
  placing a fresh one that carries the correction. The database was read
  afterwards each time — the paid orders are `processing` with a `payments` row,
  the declined one is `pending`, the released ones are `failed`, and the
  corrected order carries `99 Corrected Road` while the order it replaced still
  carries the old line.

**Needs one real test-mode payment from the owner**, because a fake cannot
answer for Stripe:

1. **That Stripe accepts the intent payload as sent.** The parameters are right
   as far as the documentation goes; only Stripe can say so.
2. **That the real `js.stripe.com/v3` mounts the card element into
   `#kbb-card-element` and looks right.** The stub proves the page calls the
   right methods in the right order, not that the real widget renders well in
   this box.
3. **That a genuine 3-D Secure challenge behaves as a modal over the page.** Use
   a UAE-issued test card or Stripe's `4000 0027 6000 3184`. This is the single
   most valuable of the three: it is the one step that could still take a
   shopper off the page, and it is the one the sandbox cannot imitate.
4. **That `payment_intent.succeeded` actually arrives.** Watch Store → Orders:
   the order should be `processing` immediately (the browser's report) and the
   webhook delivery should show 200 in Stripe's dashboard a moment later.
5. **That a real decline reads well.** Stripe's `4000 0000 0000 0002`.

A redirect-based 3-D Secure challenge — a minority of issuers — returns the
shopper to `/checkout/success?order=…` and the order is marked paid by the
webhook rather than by the browser. That path is not exercised by the walk.

---

## Two behaviours worth knowing about before they surprise somebody

**A declined card does not place a second order.** Stripe leaves the intent
payable after a decline, so the next press reuses the order and the intent that
already exist. That is Stripe's own model for a retry and it is what keeps a
shopper from burning an order number, a stock claim and a coupon use per
attempt.

**But any change to the form after a failed attempt releases that order.** The
order was written from the fields as they stood at the first press and nothing
re-posts them, so a corrected address would otherwise be silently discarded and
the goods would go to the old one — a failure that succeeds. So a `change` on
any field of the checkout cancels the intent at Stripe, fails the order (which
returns the stock and the coupon through `OrderStatus`), restores the basket,
and lets the next press place a fresh order from the fields as they now read.
Switching to another payment method does the same thing, for the other half of
the same reason: two live orders would hold this basket's stock twice.

The visible consequence is that a shopper who corrects something after a decline
gets a new order number. That is the intended trade.
