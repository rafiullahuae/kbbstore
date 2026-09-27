# Photographing the Tamara settings, and driving its two buttons

Development tooling. `tools/` is not on `UpdateGuard::ALLOWED_PREFIXES`, so
nothing here can reach the server in a package — which is the point: the fake
Tamara API below must never run anywhere near a real shop.

Pest covers the server (`tests/Feature/TamaraGatewayParityTest.php`, 48 cases).
This covers the half Pest cannot see: that the five new settings actually
render on Store → Ecommerce → Payments → Tamara, at 390px and at 1280px, that
they cause no horizontal overflow, and that the two provider-side buttons
round-trip through the real endpoints and come back visible in the boxes.

**api-sandbox.tamara.co is blocked by the sandbox's egress proxy**, so
`preview-index.php` fakes it with `Http::fake()` — `POST /webhooks`,
`DELETE /webhooks/{id}` and `GET /checkout/payment-types`. What this proves is
that the screen and the application agree with each other; it does not prove
Tamara agrees. See the report for what still needs one real sandbox merchant.

`preview-index.php` also mounts `routes/payments-tamara.php` itself, standing in
for the one line the integrator adds to `routes/web.php`. Without it the five
endpoints answer 404 and the buttons would fail for a reason that has nothing to
do with this lane's code.

## Running it

Paths are absolute and assume the worktree is at
`/home/user/kbbstore/.claude/worktrees/lane-pg1`; edit the constants at the top
of each file if yours is elsewhere.

```bash
# 1. a preview database and a shop with Tamara configured but its five NEW
#    settings empty -- which is how the package ships them.
cp .env .env.preview
#    in .env.preview: APP_ENV=preview, APP_URL=http://127.0.0.1:8941,
#    DB_CONNECTION=sqlite, DB_DATABASE=/tmp/kbbpg1-preview.sqlite,
#    SESSION_DRIVER=file, CACHE_STORE=file
#    (SESSION_DRIVER=array gives every request a new session, so every CSRF
#    token mismatches and nothing can be posted at all.)
: > /tmp/kbbpg1-preview.sqlite
KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview migrate --force
KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview tinker \
  --execute="require 'tools/pg1-tamara-shots/seed.php';"

# 2. the preview server. KBB_PUBLIC_PATH is the override bootstrap/app.php
#    reads so the built assets resolve off this machine rather than the host's.
mkdir -p /tmp/pg1-preview
cp tools/pg1-tamara-shots/preview-index.php  /tmp/pg1-preview/index.php
cp tools/pg1-tamara-shots/preview-router.php /tmp/pg1-preview/router.php
APP_ENV=preview KBB_PUBLIC_PATH="$PWD/public" \
  php -S 127.0.0.1:8941 -t /tmp/pg1-preview /tmp/pg1-preview/router.php &
echo $! > /tmp/pg1-preview/server.pid      # kill by PID, never by pattern

# 3. one viewport per invocation, RE-SEEDING IN BETWEEN.
mkdir -p /tmp/pg1-shots
for pair in "390 mobile-390" "1280 desktop-1280"; do set -- $pair
  KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview tinker \
    --execute="require 'tools/pg1-tamara-shots/seed.php';" >/dev/null
  (cd tools/pg1-tamara-shots && node shots.mjs $1 $2 > /tmp/pg1-shots/$2.json)
done

kill "$(cat /tmp/pg1-preview/server.pid)"
```

## Three things that cost time here, so they are written down

- **Re-seed between viewports, or the second BEFORE is the first AFTER.** The
  script's whole job is a before/after pair, and the first run registers the
  webhook and stores the limits in the shared preview database. Running both
  widths in one process produced four screenshots of which two were
  byte-identical to the other two, and a "before" that already showed the
  change. That is why this takes one viewport per invocation.

- **`return false` in the router does not work.** It makes the built-in server
  serve from its own document root — the preview directory, not the app's
  `public/` — so every built asset 404s and the admin renders unstyled. The
  router reads and sends the files itself. (Inherited from
  `tools/fu-card-walk`, and rediscovered here.)

- **Kill the server by PID.** CLAUDE.md forbids `pkill` by pattern on this
  machine: three lanes run at once and a pattern match takes the other two down
  with it. The PID is written to `/tmp/pg1-preview/server.pid` for that reason.

## What the measurements said

| | 390px | 1280px |
|---|---|---|
| `document.documentElement.scrollWidth` | 390 | 1280 |
| horizontal overflow | none | none |
| Tamara setting inputs | 9 | 9 |
| new input box | 328 × 33px, 13px | 520 × 33px, 13px |

Before: `payment_type`, `instalments`, `min_limit`, `max_limit` and
`webhook_id` all empty — which is the shipped state, and the state in which
nothing about the shop's behaviour differs from before this package.
After pressing the two buttons: `min_limit` 100.00, `max_limit` 5000.00,
`webhook_id` wh_preview_a1b2c3, and `payment_type` still empty because
PAY_BY_LATER is the default and an empty box means it.
