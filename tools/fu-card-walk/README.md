# Walking the card form in a real browser

Development tooling. `tools/` is not on `UpdateGuard::ALLOWED_PREFIXES`, so
nothing here can reach the server in a package — which is the point: the fake
Stripe API below must never run anywhere near a real shop.

Pest covers the server. These seven scenarios cover the half Pest cannot see:
that the fields mount, that a decline shows Stripe's own sentence beside them,
that a 3-D Secure challenge does not take the shopper off the page, that three
presses place one order, that giving up restores the basket, and that a
correction after a decline replaces the order rather than shipping to the old
address.

**Every Stripe host is blocked by the sandbox's egress proxy, and on a developer
machine you do not want real ones anyway.** So both halves are stubbed:
`stripe-stub.js` stands in for `js.stripe.com/v3` in the browser, and
`preview-index.php` fakes `api.stripe.com` on the server with `Http::fake()`.
What this proves is that the page and the application agree with each other and
behave correctly; it does not prove Stripe agrees. See the last section of
`docs/FU-STRIPE-CARD-FIELDS.md` for what still needs one real test-mode payment.

## Running it

Paths are absolute and assume the worktree is at `/home/user/kbb-wt/fu`; edit
the constants at the top of each file if yours is elsewhere.

```bash
# 1. a preview database and a shop with Stripe configured
cp .env .env.preview
#    in .env.preview: APP_ENV=local, DB_DATABASE=/tmp/kbbfu-preview.sqlite,
#    APP_URL=http://127.0.0.1:8731, SESSION_DRIVER=file, CACHE_STORE=file
#    (SESSION_DRIVER=array gives every request a new session, so every CSRF
#    token mismatches and nothing can be posted at all.)
: > /tmp/kbbfu-preview.sqlite
KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview migrate --force
KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview tinker \
  --execute="require 'tools/fu-card-walk/seed.php';"

# 2. the preview server. KBB_PUBLIC_PATH is the override bootstrap/app.php
#    reads so the built assets resolve off this machine rather than the host's.
mkdir -p /tmp/fu-preview
cp tools/fu-card-walk/preview-index.php  /tmp/fu-preview/index.php
cp tools/fu-card-walk/preview-router.php /tmp/fu-preview/router.php
APP_ENV=preview KBB_PUBLIC_PATH="$PWD/public" \
  php -S 127.0.0.1:8731 -t /tmp/fu-preview /tmp/fu-preview/router.php &

# 3. the walk. The argument is the seeded product's id.
node tools/fu-card-walk/walk.mjs 25
```

## Three things that cost time here, so they are written down

- **`return false` in the router does not work.** It makes the built-in server
  serve from its own document root, which is the preview directory and not the
  app's `public/`, so every built asset 404s — and with `kbb-checkout.css`
  missing, the `.payment_box` reveal is absent and the card fields look like
  they are ignoring which option is selected. The router reads and sends the
  files itself.
- **Playwright gives the LAST matching `page.route` precedence.** Register the
  catch-all that blocks Stripe hosts *before* the `js.stripe.com` stub, or the
  stub never runs and all seven scenarios fail for a reason that has nothing to
  do with the checkout.
- **Click the `<label>`, not the radio.** `kbb-checkout.css` hides the input
  (`opacity:0`, 1×1) and draws the control on the label, so a click on the input
  is intercepted — which is also how a real shopper selects it.

`preview-index.php` also registers `routes/checkout-card.php` itself, standing
in for the one line the integrator adds to `routes/web.php`. Without it the two
card endpoints answer 405, and the walk's "the shop was told" assertions pass on
requests the server rejected — which is how the abandon scenario first went
green while the order stayed `pending`.
