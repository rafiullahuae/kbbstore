# Apple Pay and Google Pay — what was built, and the six things only you can do

Lane WAL. Read the numbered list in **§1** first; it is the whole of your part
and it takes about ten minutes.

---

## 0. What the shop did before this, in one paragraph

The shop advertised Apple Pay and Google Pay in six places and could take
neither payment. The checkout carried two buttons marked *Apple Pay* and
*Google Pay* with nothing behind them — no listener, no gateway, no code; they
were drawn with `aria-hidden="true"` so a screen reader would skip them, which
is a fair description of what they were. The footer, the basket and the product
page each printed the words *Apple Pay* on every page load. The basket's trust
row and the slim footer drew the marks from two switches that gated nothing
real. `GatewayRegistry` knew four payment gateways — cash on delivery, Tabby,
Tamara and Stripe — and there was no Apple Pay or Google Pay file anywhere in
the build.

**That is fixed.** Both wallets are now real payments that ride your existing
Stripe account, and nothing draws a logo for a payment this shop cannot take.

---

## 0b. Before the six steps — apply the package and check it landed

You have a shell on this server and it is the fastest way to be certain. If you
would rather not use it, every check below has a browser equivalent and the six
steps work without it; this section is about *proving* the package is in rather
than assuming.

**The two directories, because they are not the same one.**

| | Path |
|---|---|
| Application root (artisan, `.env`, `storage/logs`) | `/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app` |
| Web root (what a browser actually reaches) | `/home/1672906.cloudwaysapps.com/yjmakdgtjs/public_html` |

`KBB_BASE_PATH` is **empty** on this server, so every URL in this document is
`https://extrabeauty.ae/...` with nothing between the domain and the path. If
you ever see `/kbb-upgrade/` in a URL here, the setting has been filled in by
mistake — that prefix belongs to the old Hostinger box and nothing on
extrabeauty.ae.

**1. Apply the package** the ordinary way: **Store → Core Updates**, choose the
zip, apply. Wait for it to report success.

**2. Open a shell.** Cloudways → **Servers → Launch SSH Terminal** (or your own
terminal with the Master Credentials).

```bash
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
```

**3. Confirm the migrations in the package actually ran.**

```bash
php artisan migrate:status | tail -20
```

You are looking for `2027_05_06_000000_clear_caches_wallet_domain` with **Ran**
beside it. That migration is what clears the compiled route cache, and until it
has run **the new addresses do not exist** however correctly the files were
copied — a route this shop adds is invisible until that cache is cleared.

If it says **Pending**, run it:

```bash
php artisan migrate --force
```

`--force` is required because the server is in production mode. It is not a
destructive flag; it only stops artisan asking for confirmation on a terminal
that may not be interactive.

**4. Clear the caches by hand as well.** Harmless to repeat, and it costs
nothing:

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

**5. Prove the new addresses answer.** Run from the shell or from your own
machine — the answers are the same:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://extrabeauty.ae/.well-known/apple-developer-merchantid-domain-association
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://extrabeauty.ae/checkout/wallet/amount
```

How to read those two numbers:

| Line | Good answer | What a different answer means |
|---|---|---|
| 1st (Apple's file) | `404` **before** step 4, `200` after it | A `500` means the package is in but something is wrong — read the log below |
| 2nd (the wallet total) | `419` or `422` | **`405` or `404` means the routes are not live.** Go back to step 3: the clear-caches migration has not run |

The rule for the second line, stated so there is nothing to judge: **anything
that is not `404` and not `405` means the address exists and the routes are
live.** A `419` is the CSRF check refusing a bare `curl` that carries no
session, and a `422` is the endpoint itself saying the basket is empty — both
are the endpoint answering, which is all this check is asking.

**6. If anything above looked wrong, read the log rather than guessing.**

```bash
tail -n 100 storage/logs/laravel.log
```

It is in the **application** root, not the web root — that is the `cd` at step
2. If the file is large, `grep -i 'stripe\|wallet' storage/logs/laravel.log | tail -40`
narrows it to this feature.

---

## 1. Your six steps

You will need: the Stripe dashboard, and the shop's admin. Steps 4 and 5 need
SSH only if you would rather place a file than paste one — there is a way to do
it without a shell.

### Step 1 — make sure Stripe is live and on

**Shop → Store → Payments → Credit or debit card.**

- *Mode* must read **Live** (not Sandbox) for either wallet to take real money.
- **Publishable key** and **Secret key** must both be the `pk_live_…` /
  `sk_live_…` pair. Google Pay in particular will not appear on a test key in a
  normal browser session.
- The gateway's own **Enabled** switch must be on.

Both wallets ride this one account. If the card gateway is off, the wallets are
off — they are the same money path.

### Step 2 — Google Pay: switch it on, and that is all

**Shop → Store → Payments → Credit or debit card → How this shop uses it →
Google Pay → On**, then **Save**.

Google Pay needs **no registration with anybody**. What it does need, and this
is worth saying plainly rather than leaving you to discover it:

- **HTTPS.** extrabeauty.ae is already served over HTTPS, so this is satisfied.
  It will never appear on a plain `http://` address or on `localhost`.
- **Live keys.** On a test key Google Pay shows only to accounts allow-listed in
  your own Google Pay console; on a live key it shows to any Chrome or Android
  shopper with a card saved to Google.
- Nothing else. No domain file, no Google account, no review.

Open extrabeauty.ae/checkout in **Chrome, signed in to a Google account with a
card saved**. The Google Pay button appears above the payment options. On a
browser with no Google Pay the row is not drawn at all — no empty box, no dead
button.

### Step 3 — Apple Pay: register the domain with Apple, through Stripe

In the **Stripe dashboard**:

1. **Settings → Payments → Payment method domains** (in older dashboards:
   *Settings → Payment methods → Apple Pay → Web domains*).
2. **Add a new domain** and type `extrabeauty.ae` exactly — no `https://`, no
   `www.` unless that is the host shoppers actually reach.
3. Stripe offers a file to **download**, named
   `apple-developer-merchantid-domain-association` (no extension). Download it.
   **Do not rename it and do not open-and-resave it in a word processor** — it
   is compared byte for byte at Apple's end.

Leave the Stripe page open; you come back to it in step 5.

### Step 4 — put that file where Apple will look for it

Apple fetches exactly this address and nothing else:

```
https://extrabeauty.ae/.well-known/apple-developer-merchantid-domain-association
```

**Either** of these two works. Do one, not both.

#### 4a. The no-shell way — paste it into the admin (recommended)

1. Open the downloaded file in a plain text editor (TextEdit in plain-text
   mode, Notepad, or VS Code). It is one long run of characters.
2. Select all, copy.
3. **Shop → Store → Payments → Credit or debit card → How this shop uses it →
   Apple Pay domain file.** Paste it in. **Save.**

The shop now serves that exact text at the address above. Nothing else is
needed, and a package update never overwrites it — it lives in the database.

#### 4b. The SSH way — place the real file

Cloudways → **Servers → Launch SSH Terminal** (or use the Master Credentials
over your own terminal), then:

```bash
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/public_html
mkdir -p .well-known
nano .well-known/apple-developer-merchantid-domain-association
# paste the file's contents, then Ctrl-O, Enter, Ctrl-X
chmod 644 .well-known/apple-developer-merchantid-domain-association
```

Note the directory: **`public_html`, not `private_html/kbb-app`.** On this
server the web root and the application root are different directories — the
app lives in `private_html/kbb-app` and the web server serves its sibling
`public_html`. A file put in the application's own `public/` folder is not
served by anything.

A real file placed here **wins over the pasted value**, automatically. The web
server hands it over before the request ever reaches the shop's code, so if you
do both, the file is what Apple sees.

### Step 5 — tell Stripe to verify, and check it worked

Back on the Stripe **Payment method domains** page, press **Verify** (or
**Check again**) beside `extrabeauty.ae`. It should turn green within a few
seconds.

**Check it yourself first**, from anywhere:

```bash
curl -i https://extrabeauty.ae/.well-known/apple-developer-merchantid-domain-association
```

What a working setup looks like:

```
HTTP/2 200
content-type: text/plain; charset=utf-8
x-content-type-options: nosniff
…then one long line of characters, and nothing else…
```

What each failure means:

| What you see | What it means | What to do |
|---|---|---|
| `HTTP/2 404` | Nothing is stored and no file is placed | Redo step 4. If you pasted, check you pressed Save |
| `HTTP/2 404` after pasting | The pasted text contains `<` or `>`, or is over 8 KB | You pasted the wrong thing — re-download from Stripe and copy as **plain text** |
| `content-type: text/html` | A file is being served by something else, or you hit a redirect | Check for a redirect rule on `.well-known` |
| 200 but Stripe still fails | The bytes differ — usually a smart-quote or a line break added by a word processor | Redo step 4 from a fresh download, in a plain-text editor |

### Step 6 — switch Apple Pay on

Only once Stripe shows the domain **verified**:

**Shop → Store → Payments → Credit or debit card → How this shop uses it →
Apple Pay → On**, then **Save**.

Then open extrabeauty.ae/checkout on **an iPhone or on Safari on a Mac with
Apple Pay set up**. The Apple Pay button appears above the payment options.

---

## 2. Where everything sits in the admin

Every control this round added, in one place:

**Store → Payments → Credit or debit card → How this shop uses it**

| Control | What it does | Ships as |
|---|---|---|
| **Apple Pay** | The Apple Pay button on the checkout, and the Apple Pay mark in the footer, the basket and the product page | **Off** |
| **Google Pay** | The Google Pay button on the checkout, and the Google Pay mark in the same three places | **Off** |
| **Apple Pay domain file** | The blob Stripe hands you in step 3, served at the `.well-known` address | Empty |

Nothing else moved. **Applying this package changes nothing on the shop until
you switch one of those two on** — that is deliberate, and it is the rule this
project works to.

One thing does change the moment the package lands, and it is the point of the
round: **the Apple Pay wording disappears from the footer, the basket and the
product page** until you switch Apple Pay on. It was a claim the shop could not
honour. Switch the wallet on and it comes back — and Google Pay appears beside
it, which it never did before.

---

## 3. How it works, for whoever reads this next

**There is no Apple Pay gateway and no Google Pay gateway, on purpose.**

Apple Pay and Google Pay are *card wallets*. The payment method Stripe hands
back from either sheet has `type: card` and carries `card.wallet.type =
apple_pay` or `google_pay`. The charge goes through the same PaymentIntent a
typed card goes through.

So:

- `StripeGateway::openIntent()` is **unchanged**, including its
  `payment_method_types: ['card']`. That list already admits both wallets, and
  the two reasons the comment there gives for naming cards explicitly still
  hold. Widening it, or moving to `automatic_payment_methods`, would have
  bought nothing and would have handed the card box to whatever is switched on
  in a Stripe dashboard setting nobody here can see.
- `orders.payment_method` on a wallet order is `stripe`. The capture screen,
  the refund button, the void path, the payment ledger and the reconciler all
  work on day one, because to them nothing happened.
- The money is integer fils from the basket to Stripe, with no float anywhere.

**The pieces:**

| File | What it is |
|---|---|
| `app/Services/Payments/Wallets.php` | The one answer to "can this shop take it". Four gates: the card row is enabled, the secret key is present, the publishable key is present, the merchant's switch is on |
| `app/Support/PaymentChips.php` | The text chips in the footer, the basket and the product page, filtered through `Wallets` |
| `resources/views/partials/checkout/express-wallets.blade.php` | Stripe's Express Checkout Element, and the rule that it is never drawn dead |
| `app/Http/Controllers/Store/AppleDomainController.php` | The `.well-known` file |
| `routes/wallet-domain.php`, `routes/wallet-checkout.php` | Their routes |
| `app/Services/Security/ContentSecurityPolicy.php` | `https://pay.google.com` on `script-src` and `frame-src` |

**Two rules the checkout keeps, which are worth knowing if you change it:**

1. **Never a dead button.** The express row renders `hidden` and is revealed
   only by Stripe's own `ready` event reporting a wallet this browser can
   actually use. If there is none — or Stripe.js fails to load — the row and
   its "or pay with" divider are removed from the page. A shopper offered Apple
   Pay on a browser that cannot do it is worse off than one never offered it.
2. **The browser never chooses the amount.** The sheet's figure comes from
   `POST /checkout/wallet/amount`, computed server-side by the same arithmetic
   that writes `orders.total`. And before anything is charged, the figure the
   sheet showed is compared against the order's real total; if they differ the
   payment is abandoned, the basket is restored and the shopper is told the
   total moved. Nothing is charged on a number nobody agreed to.

---

## 4. What could not be proved from here

Stated plainly, because a claim with no evidence behind it is not a claim:

- **That an Apple device draws the sheet.** No Apple hardware and no verified
  domain exist in this environment. Everything up to the sheet is tested
  against a faked Stripe; the sheet itself is Safari's, and Safari will not
  draw it until step 5 above has gone green.
- **That Google actually offers the button.** Same reason — Chromium here has
  no Google account and no saved card, so `canMakePayment()` is false, which
  is the "draw nothing" branch. That branch **is** tested and photographed.
- **That Stripe accepts the confirmation.** Every Stripe host is unreachable
  from this sandbox. What is proved is the exact shape of what the shop sends
  and what it does with every answer.

The first real test-mode wallet payment is the thing that closes all three, and
it costs one tap on your own phone once step 6 is done.

### One thing that is not a wallet problem but will look like one

**Until the package that wires `routes/checkout-card.php` reaches this server,
a payment that FAILS does not give the basket back.**

Two addresses — `/checkout/card/paid` and `/checkout/card/abandon` — are the
ones the checkout reports to when a payment succeeds or is declined. They ship
in a file that was never added to the shop's route list, so today they answer
`405`. This is not new and it is not about wallets: the typed card form has used
the same two addresses since it landed, and the same thing happens to it.

What you would see, on a card or on a wallet: the shopper's payment is declined,
they are told so correctly, **and their bag is empty**. The stock stays reserved
against an order that was never paid for, and the abandoned payment is left open
at Stripe rather than cancelled.

What you would NOT see: a payment taken and lost. Successful payments are still
marked paid, because Stripe's webhook does that independently and is the
authority either way. **No money is at risk from this** — it costs a basket, not
a payment.

The fix is one line in `routes/web.php` and it is with the integrator. The check
for it is the second `curl` in section 0b: run it against
`https://extrabeauty.ae/checkout/card/abandon` and a `405` means the line has
not shipped yet, while anything else means it has.

---

## 5. The pictures, and what each one is evidence of

All in `docs/lane-wal-shots/`, taken in Chromium 1194 at **390 × 844** and
**1280 × 900**, device scale 2, against the preview this branch carries
(`sh tools/wal-preview.sh` then `node tools/wal-shots.cjs docs/lane-wal-shots 390 844`).
The numbers beside them are read in the browser from the finished page and are
in `measurements-390.json` and `measurements-1280.json`.

| File | The state it shows | What it proves |
|---|---|---|
| `checkout-wallets-off-*` | Both wallets Off in the admin — **what this package ships as** | No row, no divider, no gap. The payment step is byte-for-byte the step that is on the shop today |
| `checkout-wallets-on-no-stripe-*` | Both On, `js.stripe.com` unreachable | The row removes itself after five seconds rather than leaving a button nothing can answer |
| `checkout-stub-no-wallet-*` | Both On, Stripe reports **no** available wallet | The `canMakePayment() === false` branch: removed, not hidden |
| `checkout-stub-wallets-*` | Both On, Stripe reports Apple Pay and Google Pay available | The row present, in place, above the divider and the payment list |
| `admin-wallet-switches-*` | Store → Payments → Credit or debit card → How this shop uses it | The two switches and the domain-file box, with their help text |
| `footer-wallets-on-*` / `footer-wallets-off-*` | The footer chip row, both ways | The marks follow capability |
| `checkout-ar-stub-wallets-*` | The same wallet row, **in Arabic** (`/ar/checkout`) | The row mirrors with the page — Apple Pay to the right, Google Pay to the left — at the same 316 × 48 and 560 × 48 it has in English, with no horizontal scroll |
| `checkout-ar-wallets-off-*` | Arabic with both wallets Off | The row is absent in Arabic exactly as it is in English |

The Arabic pair carries `lang` and `dir` in `measurements-*.json` beside every
other number, because an "Arabic" screenshot that is quietly the English page is
the easiest wrong evidence there is to produce. Both read `lang=ar` and
`dir=rtl`.

The English wording in the Arabic shots is not a defect: the preview has no
Arabic translations entered. Every string the wallet row adds is registered in
the translation console — `checkout.wallet_details_first`,
`checkout.wallet_total_moved`, `checkout.wallet_failed`,
`checkout.wallet_working`, and the pre-existing `checkout.or_pay_with` — so they
are translated at **Store → Translations** like every other string on the shop.
"Apple Pay" and "Google Pay" are deliberately NOT translatable: they are company
names, and Stripe localises its own button artwork.

**The stub, said plainly.** The two `stub` shots install a fake `window.Stripe`
before the page loads, because this container has no wallet, no Apple hardware
and no route to any Stripe host. They are **not** a picture of Stripe's real
Apple Pay button — they are a picture of the row's own geometry and of this
page's reveal/remove logic. The real button is Stripe's artwork and appears the
moment a shopper with a wallet opens the page.

### The measured numbers

| | 390 | 1280 |
|---|---|---|
| `document.documentElement.scrollWidth` (every state) | **390** | **1280** |
| `clientWidth` | 390 | 1280 |
| Express row, when present | 316 × 48 | 560 × 48 |
| Payment list below it | 316 wide | 560 wide |
| Payment options offered | 2 | 2 |
| Express row in Arabic, when present | 316 × 48 | 560 × 48 |
| `scrollWidth` in Arabic (both states) | **390** | **1280** |
| Dead `.xbtn` buttons anywhere | **0** | **0** |
| Footer chips, wallets on | Tabby · Tamara · Visa · Mastercard · Apple Pay · Google Pay · COD | same |
| Footer chips, wallets off | Tabby · Tamara · Visa · Mastercard · COD | same |

No horizontal scroll at either width in any of the six checkout states, English
or Arabic — `scrollWidth` equals `clientWidth` everywhere.

**Queries on `/checkout`: 11 before this branch, 11 after.** Measured with the
same warm-then-count method `StorefrontQueryBudgetTest` uses, on a one-line
basket with both wallets switched on. `PageCostBudgetTest` — which flushes every
cache before each page, so it measures a first visitor — is unchanged on /shop,
a category and the product page. That is why the wallet answer is a settings row
rather than a query: the footer draws payment marks on every page of the shop.
