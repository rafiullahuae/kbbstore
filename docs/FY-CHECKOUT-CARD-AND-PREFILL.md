# Lane FY — the card form as the owner asked for it, and a checkout that knows who is signed in

The on-site card fields shipped in 2.60.215 and the owner has put a real
test-mode order through them. This lane is his follow-up list.

1. The paragraph above the fields is gone; one line with a padlock stands in its
   place.
2. The single combined Stripe element is three: card number on its own row,
   expiry and security code side by side beneath it, each labelled, each in its
   own box.
3. A "save this card for future purchases" tick, which genuinely saves the card
   — and is shown only to somebody a saved card could ever be offered back to.
4. Stripe Link is off, and off by a setting in Stripe's own gateway config
   rather than by a line of code.
5. A signed-in shopper's details arrive in the boxes.

Nothing about where a card number goes has changed. The fields are still
cross-origin iframes served by `js.stripe.com`; splitting one element into three
changes whose document draws the BOX, not whose document holds the VALUE. There
is exactly one `<input>` inside the card form and it is the save-card tick —
`CheckoutCardFormLayoutTest` counts them.

---

## The integrator's two edits

### 1. `resources/views/admin/app.blade.php` — a switch drawn as a switch

`StripeGateway::configSchema()` gains `link_enabled`, whose type is `bool`. It
is the first entry in any gateway's schema that is a setting rather than a
credential. **Everything works without this edit**: `payField()` falls through to
its text branch, so the operator sees a box that takes `1` or nothing, and
`PaymentsApiController` stores and returns it correctly either way. What the
edit buys is an operator who does not have to be told what to type.

It needs nothing else: a `<select>` carrying `data-payg` / `data-payf` is read by
`paySnapshot()`, written by `payRestorePending()` and collected by `paySave()`
through `el.value`, exactly as the text inputs are, so the unsaved-change
indicator and the save both work with no further change.

**Anchor** — the last statement of `payField()`, which appears once:

```js
    return '<div class="ecopt wide"><div class="ecom">'+head+'</div>'+
      '<div class="ecctl"><input type="text" class="inp" id="'+sesc(id)+'" data-payg="'+sesc(gid)+'" data-payf="'+sesc(f.key)+'"'+
      ' spellcheck="false" value="'+sesc(f.value)+'"></div></div>';
  }
```

**Replacement** — the same statement with one branch in front of it:

```js
    /* A SETTING, NOT A CREDENTIAL. `bool` fields are stored in the same config
       blob as the keys and travel the same way, but there is nothing to paste
       into them: the value is '1' or empty. Drawn as a select rather than a
       checkbox deliberately — everything on this screen is read and written
       through el.value (paySnapshot, payRestorePending, paySave), and a
       checkbox's .value does not change with its checked state, so a checkbox
       would need three other functions taught about it and would save the wrong
       thing until they were. */
    if(f.type==='bool'){
      return '<div class="ecopt wide"><div class="ecom">'+head+'</div>'+
        '<div class="ecctl"><select class="inp" id="'+sesc(id)+'" data-payg="'+sesc(gid)+'" data-payf="'+sesc(f.key)+'">'+
        '<option value=""'+(f.value==='1'?'':' selected')+'>Off</option>'+
        '<option value="1"'+(f.value==='1'?' selected':'')+'>On</option>'+
        '</select></div></div>';
    }

    return '<div class="ecopt wide"><div class="ecom">'+head+'</div>'+
      '<div class="ecctl"><input type="text" class="inp" id="'+sesc(id)+'" data-payg="'+sesc(gid)+'" data-payf="'+sesc(f.key)+'"'+
      ' spellcheck="false" value="'+sesc(f.value)+'"></div></div>';
  }
```

### 2. `routes/web.php` — nothing

This lane adds no route. Lane FU's one `require __DIR__.'/checkout-card.php';`
is still needed and is still the thing that makes `/checkout/card/paid` and
`/checkout/card/abandon` resolve; if it has not landed yet, it should land with
this package. Nothing here changes that instruction.

---

## The two migrations, and why neither is optional

`2026_11_20_000000_add_stripe_customer_ids_to_customers` adds
`customers.stripe_customer_ids`. "Save this card" cannot be honoured without a
Stripe Customer — `setup_future_usage` is refused outright without one — and
this column is the shop's record of which Stripe Customer belongs to which of
its own.

It is a **map keyed by mode**, `{"test": "cus_…", "live": "cus_…"}`, and that is
not tidiness. A `cus_…` minted with test keys does not exist to an account using
live keys. Held as one value, the first real order from a customer who had also
ordered while the shop was in test mode would send Stripe a customer it has
never heard of — and Stripe refuses the whole PaymentIntent, not merely the
saving of the card, so that shopper simply could not pay.

`2026_11_20_000001_clear_caches_checkout_card_form` clears the compiled views.
This package adds no route, which makes it easy to think the migration is
optional. It is not: everything on the storefront that this lane changes is a
Blade template, compiled Blade is keyed by path with a filemtime check, and an
unzip does not reliably land a newer timestamp. The worst case is not a stale
form — it is `payment-methods.blade.php` recompiling while `stripe-card` does
not, which is a payment box whose description has gone and whose card fields
have gone with it.

---

## What changed

| File | What |
| --- | --- |
| `resources/views/partials/checkout/stripe-card.blade.php` | Three labelled mount boxes instead of one; the padlock line; the save-card row. |
| `resources/views/partials/checkout/stripe-elements.blade.php` | `cardNumber` / `cardExpiry` / `cardCvc` from one `elements()` instance; `disableLink` from the gateway's setting; `syncSave()`, which governs the save-card row. |
| `resources/views/partials/checkout/payment-methods.blade.php` | The `.payment_box` is drawn for the card gateway whether or not it has a description. |
| `app/Services/Payments/Gateways/StripeGateway.php` | `description()` returns null; `link_enabled` in `configSchema()` and `linkEnabled()`; `startAndSaveCard()`, `stripeCustomerFor()`, and `reusableIntent()` taught that an intent's `setup_future_usage` and `customer` are part of what makes it reusable. |
| `app/Services/Payments/GatewayPreflight.php` | A `bool` entry is a switch, not a credential still to be pasted in. |
| `app/Http/Controllers/Store/CheckoutController.php` | `save_card` validated; the guard that decides whether it is honoured; `prefill()`; the address book asked for shipping **and then billing**. |
| `app/Models/Customer.php` | `stripeCustomerId()` / `rememberStripeCustomerId()`, the cast, and the column hidden from serialisation. |
| `app/Services/Translation/InterfaceStrings.php` | Five new `store.checkout.card_*` keys; `card_secure_note` removed with the note it named. |

**New**: the two migrations, `tests/Feature/CheckoutCardFormLayoutTest.php`,
`tests/Feature/CheckoutPrefillTest.php`, and `tools/fy-card-walk/`.

**Changed tests**: `CheckoutCardFormTest` (the mount box is three boxes now) and
`CheckoutPaymentOptionsTest` (the description it pinned has been withdrawn, so
it pins the absence and the box that has to survive it).

**Not changed, and checked**: `PaymentConfirmer`, `PaymentCapturer`,
`PaymentRefunder`, `PaymentLedger`, the webhook paths, and the release-and-
re-place behaviour that 2.60.215 built. That last one is walked again in
Chromium, because this lane added two new controls to the form it watches.

---

## The save-card decision, stated plainly

The brief allowed two answers: build both halves, or build the save half and
withhold the tick from anybody it cannot serve. **This lane took the second**,
and the reasoning is the size of the first: offering a saved card back means
listing a customer's payment methods, rendering them as a choice on the
checkout, confirming against a saved `pm_…` with its own 3-D Secure path, and a
way to remove one — a lane's worth of work on the page this lane was already
rebuilding twice over.

So:

- The card is genuinely saved. The PaymentIntent carries `setup_future_usage` and
  a Stripe Customer, and that Customer's id is written against the shop's own
  customer row. Nothing is pretended.
- The tick is shown **only to a signed-in customer, or to a guest who is creating
  an account in this same checkout** — revealed by the script the moment
  "create an account" is ticked, hidden and **cleared** if it is un-ticked.
- The server decides independently of the tick. A hidden checkbox still posts,
  and `place()` attaches an order to an existing customer row whenever a guest
  types the email address of one, so "this order has a customer" is not the same
  question as "this shopper has an account". Without the second question, anyone
  who knows an address could attach their card to a stranger's account.

**Reuse is the follow-up.** Until it lands, a saved card is a card Stripe holds
and this shop does not yet offer back. The tick's words are true and the work it
implies is not finished.

`setup_future_usage` is **`on_session`**, not `off_session`. It states that the
card will be reused with the shopper present at a checkout they are looking at,
which is what the tick offers and all this shop will ever do. `off_session`
claims the right to charge while they are away and asks the issuer for the
authentication that goes with that claim — a larger promise than the box makes.

A Stripe Customer that cannot be created **does not refuse the sale**. The
payment goes through exactly as it would have without the tick, nothing is
saved, and it is logged. Losing an order because a convenience failed is much
the worse of the two outcomes.

---

## Prefill: what it now does, and what it deliberately does not

The page has had a `$prefill` array since 2.60.41. What it did not have was an
answer for this shop's own data.

**The bug.** `Customer::defaultAddress()` defaults to `shipping` and stops.
WooCommerce has no addresses table — billing and shipping are loose usermeta —
so `Import\AddressWriter` writes only the rows the export carried, and a Woo
customer who only ever filled in billing (which is most of them: a shop
delivering to the billing address never asks for a second one) has exactly one
row, of type `billing`. For them the checkout filled in nothing at all. It now
asks for shipping and then billing, which is the order `AdminOrderController`
has used since Store → Orders gained a customer picker.

**The rule.** An empty box beats a wrong guess. A prefilled field is a field that
gets skipped, so everything in it has to be something the account actually
holds. Two values fall back to the saved address — the phone number and the name
— and both are the shopper's own details read from the other place this shop
writes them down.

**What was removed.** `displayName()` ends `?: $this->email`, which is right for
"who is this" on an order screen and quite wrong for a box labelled Full name: an
imported customer with no name would have found their email address sitting in
it, and a shopper who did not look would have had it printed on the parcel.

**Nothing overrides the shopper.** Every field reads `old()` first. And a
correction made after a declined card never goes through this path at all: that
one releases the order and places a fresh one from the fields as they now stand,
which is the behaviour 2.60.215 built and which scenario 7 of the walk exercises
again.

---

## What is proved against a fake, and what needs one real payment

**Every Stripe host is blocked by the sandbox's egress proxy. Nothing here has
spoken to Stripe.**

Proved against `Http::fake()` and a stubbed `js.stripe.com`
(`CheckoutCardFormLayoutTest`, `CheckoutPrefillTest`, the existing
`CheckoutCardFormTest` and `StripeCardFieldsTest`, and a seven-scenario Chromium
walk): the three mounts and their labels; the padlock line above them and the
paragraph gone; the box that has to survive a gateway with no description; that
there is one input in the form and it is the tick; `disableLink` on by default
and off when the shop switches Link on; that the switch is not reported as a
missing credential; who sees the save-card row; that the tick is honoured for a
signed-in customer and for a guest creating an account, and refused for a guest
typing somebody else's address; that a customer id is remembered per mode and
reused; that a failed customer still takes the payment; that an intent is not
reused when its `setup_future_usage` or its `customer` is not the one now
wanted; the prefill cases above; and, in Chromium at 1280 and 390, the measured
layout, the save row appearing and clearing, a prefilled checkout that can still
be typed over, a payment with no navigation to any Stripe host, and a correction
after a decline releasing the order.

**Needs one real test-mode payment from the owner**, because a fake cannot
answer for Stripe:

1. **That the real `js.stripe.com/v3` mounts three elements into these three
   boxes and they look right.** The stub proves the page calls the right methods
   with the right options in the right order; it does not prove Stripe's own
   iframes sit well in boxes 44px tall. This is the most valuable of the five:
   it is the one thing the pictures in `docs/fy-card-shots/` cannot settle.
2. **That `disableLink: true` actually removes the Link prompt.** It is the
   documented option for the `cardNumber` element; only Stripe can confirm the
   prompt is gone.
3. **That Stripe accepts `setup_future_usage=on_session` with this customer**,
   and that the card afterwards appears against that customer in the Stripe
   dashboard. Tick the box, pay with `4242 4242 4242 4242`, then look at
   Customers → the customer → Payment methods. A card that is not there means
   the save half is not working, however green this repository is.
4. **That a 3-D Secure challenge still behaves as a modal over the page now that
   the fields are three elements.** `4000 0027 6000 3184`.
5. **That a real decline still reads well beside the new layout** — one message
   box under three fields rather than one. `4000 0000 0000 0002`.

A redirect-based 3-D Secure challenge — a minority of issuers — returns the
shopper to `/checkout/success?order=…` and the order is marked paid by the
webhook rather than by the browser. That path is not exercised by the walk, and
was not by Lane FU's either.

**And the thing to do at go-live is still Lane FU's:** reconnect Stripe, or add
`payment_intent.succeeded` and `payment_intent.canceled` to the webhook endpoint
by hand. Nothing in this lane changes that.
