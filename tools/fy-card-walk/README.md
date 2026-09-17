# Walking the rebuilt card form in a real browser

Development tooling. `tools/` is not on `UpdateGuard::ALLOWED_PREFIXES`, so
nothing here can reach the server in a package — which is the point: the fake
Stripe API below must never run anywhere near a real shop.

This is Lane FU's walk rebuilt for a form that has three Stripe Elements instead
of one. **Lane FU's directory is left exactly as it was**: it is the record of
what was proved for 2.60.215, and its stub knows only `create('card')`, so
running it against this tree would report a failure that is not one.

Pest covers the server and the markup. These seven scenarios cover the half Pest
cannot see: that three Elements go into three boxes; that the number sits on its
own row with the expiry and the security code sharing the next one, **measured**
at 1280 and again at 390; that Stripe Link is asked for with `disableLink`; that
the save-card row appears only when it can mean something and clears itself when
it stops meaning anything; that a signed-in shopper's details arrive in the boxes
and can still be typed over; that ticking the box opens an intent which carries
`setup_future_usage`; and — the regression that matters most — that a correction
made after a declined card still releases the order rather than shipping to the
address the first attempt was placed against.

**Every Stripe host is blocked by this sandbox's egress proxy, and on a developer
machine you do not want real ones anyway.** So both halves are stubbed:
`stripe-stub.js` stands in for `js.stripe.com/v3` in the browser, and
`preview-index.php` fakes `api.stripe.com` on the server with `Http::fake()`.
What this proves is that the page and the application agree with each other and
behave correctly; it does not prove Stripe agrees. See the last section of
`docs/FY-CHECKOUT-CARD-AND-PREFILL.md` for what still needs one real test-mode
payment.

## Running it

Paths are absolute and assume the worktree is at `/home/user/kbb-wt/fy`; edit
the constants at the top of each file if yours is elsewhere. The port is 8741,
one along from Lane FU's, so both previews can run at once.

```bash
# 1. a preview database and a shop with Stripe configured
cp .env .env.preview
#    in .env.preview: APP_ENV=local, DB_DATABASE=/tmp/kbbfy-preview.sqlite,
#    APP_URL=http://127.0.0.1:8741, SESSION_DRIVER=file, CACHE_STORE=file,
#    KBB_BASE_PATH= (empty)
#    (SESSION_DRIVER=array gives every request a new session, so every CSRF
#    token mismatches and nothing can be posted at all.)
: > /tmp/kbbfy-preview.sqlite
KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview migrate --force
KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview tinker \
  --execute="require 'tools/fy-card-walk/seed.php';"

# 2. the preview server. KBB_PUBLIC_PATH is the override bootstrap/app.php
#    reads so the built assets resolve off this machine rather than the host's.
mkdir -p /tmp/fy-preview
cp tools/fy-card-walk/preview-index.php  /tmp/fy-preview/index.php
cp tools/fy-card-walk/preview-router.php /tmp/fy-preview/router.php
APP_ENV=preview KBB_PUBLIC_PATH="$PWD/public" \
  php -S 127.0.0.1:8741 -t /tmp/fy-preview /tmp/fy-preview/router.php &

# 3. the walk. The argument is the seeded product's id, which step 1 printed.
node tools/fy-card-walk/walk.mjs 25
```

Screenshots land in `docs/fy-card-shots/`.

## What this lane added to the fake, and why each one is load bearing

- **`POST /v1/customers`.** A saved card has to hang off a Stripe Customer, and
  `setup_future_usage` without one is an error at Stripe. Unfaked, the gateway
  takes its documented fallback — an ordinary payment with nothing saved — and
  every save-card scenario would have passed while proving the fallback rather
  than the feature.
- **`customer` and `setup_future_usage` echoed back on the created intent.**
  The walk reads `/tmp/kbbfy-intent.json.*` afterwards to check what was
  actually opened, and `reusableIntent()` compares both fields on a second
  attempt; an intent that answers null to them is one the gateway will never
  reuse, which would have hidden a reuse bug behind a fresh intent every time.
- **A seeded customer with a password and a BILLING address.** The prefill this
  lane fixes is specifically the case where a customer has no shipping row,
  which is the shape most of this shop's imported customers are in. A seed with
  a shipping address would have passed against the old code too.
- **`cardType` recorded from `confirmCardPayment`.** With individual Elements,
  the one handed over must be `cardNumber` — Stripe finds the expiry and the CVC
  through the shared `elements()` instance. Handing it the CVC element instead
  is a mistake a boolean "was a card passed" cannot see.

## And the three traps Lane FU wrote down, which are all still true

- **`return false` in the router does not work.** It makes the built-in server
  serve from its own document root, which is the preview directory and not the
  app's `public/`, so every built asset 404s — and with `kbb-checkout.css`
  missing, the `.payment_box` reveal is absent and the card fields look like
  they are ignoring which option is selected. The router reads and sends the
  files itself.
- **Playwright gives the LAST matching `page.route` precedence.** Register the
  catch-all that blocks Stripe hosts *before* the `js.stripe.com` stub, or the
  stub never runs and every scenario fails for a reason that has nothing to do
  with the checkout.
- **Click the `<label>`, not the radio.** `kbb-checkout.css` hides the input
  (`opacity:0`, 1×1) and draws the control on the label, so a click on the input
  is intercepted — which is also how a real shopper selects it. The same applies
  to "create an account", whose tick this walk uses to reveal the save-card row.

`preview-index.php` also registers `routes/checkout-card.php` itself, standing
in for the one line the integrator adds to `routes/web.php`. Without it the two
card endpoints answer 405, and scenario 7's "the order was released" assertion
would pass on a request the server rejected.
