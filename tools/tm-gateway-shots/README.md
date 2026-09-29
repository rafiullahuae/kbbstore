# Photographing Store → Gateway webhooks, and driving all seven endpoints

Development tooling. `tools/` is not on `UpdateGuard::ALLOWED_PREFIXES` and
`BuildPackage::NEVER_SHIP` blocks it twice over, which is the point: the fake
provider APIs below must never run anywhere near a real shop.

Pest covers the server (`tests/Feature/TamaraConsoleReachTest.php`, 22 cases,
plus `TamaraGatewayParityTest`'s 75). This covers the half Pest cannot see: that
the screen renders at 390px and 1280px, that pressing each of its six buttons
round-trips through the real endpoints, and that what comes back is visible in
the boxes afterwards.

**api-sandbox.tamara.co and api.tabby.ai are both refused by the sandbox's
egress proxy**, so `preview-index.php` fakes them with `Http::fake()`. What that
proves is that the screen and the application agree with each other; it does not
prove Tamara or Tabby agree. One real sandbox merchant on each is still owed.

`preview-index.php` also **appends the partial itself**, standing in for the one
line the integrator adds to `resources/views/admin/app.blade.php`:

```blade
@include('admin.partials.tamara-connection-screen')
```

## Running it

Paths are absolute and assume the worktree is at `/home/user/lane-tm`; edit the
constants at the top of each file if yours is elsewhere.

```bash
cp .env .env.preview
#  in .env.preview: APP_ENV=preview, APP_URL=http://127.0.0.1:8952,
#  DB_CONNECTION=sqlite, DB_DATABASE=<worktree>/storage/tm-logs/tm-preview.sqlite,
#  SESSION_DRIVER=file, CACHE_STORE=file
#  (SESSION_DRIVER=array gives every request a new session, so every CSRF token
#  mismatches and nothing can be posted at all.)

: > storage/tm-logs/tm-preview.sqlite
KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview migrate --force
KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview tinker \
  --execute="require 'tools/tm-gateway-shots/seed.php';"

mkdir -p /tmp/tm-preview
cp tools/tm-gateway-shots/preview-index.php  /tmp/tm-preview/index.php
cp tools/tm-gateway-shots/preview-router.php /tmp/tm-preview/router.php
APP_ENV=preview KBB_PUBLIC_PATH="$PWD/public" \
  php -S 127.0.0.1:8952 -t /tmp/tm-preview /tmp/tm-preview/router.php &
ps -eo pid,args | grep 127.0.0.1:8952 | grep -v grep | awk '{print $1}' \
  > /tmp/tm-preview/server.pid     # kill by PID, never by pattern

# One viewport per invocation, RE-SEEDING IN BETWEEN.
mkdir -p docs/tm-shots
for pair in "1280 desktop-1280" "390 mobile-390"; do set -- $pair
  KBB_PUBLIC_PATH="$PWD/public" php artisan --env=preview tinker \
    --execute="require 'tools/tm-gateway-shots/seed.php';" >/dev/null
  node tools/tm-gateway-shots/shots.mjs $1 $2 > storage/tm-logs/tm-$1.json
done

kill "$(cat /tmp/tm-preview/server.pid)"
```

`?tmoff=1` on any admin URL renders the console **without** this lane's partial,
which is how the before/after comparison in the report was taken.

## Four things that cost time here, so they are written down

- **Inject at the LAST `</body>`, not with `str_replace`.**
  `banners-screen.blade.php` builds an iframe document in a JavaScript string
  and that string contains `</body>`. A `str_replace` put the partial *inside*
  that script, which closed it early: banners' own JavaScript rendered as
  visible text at the foot of every admin page and the console threw
  `SyntaxError: Invalid or unexpected token`. It read exactly like a defect in
  the new partial and was a defect in this harness. Blade's own `@include`,
  which is what actually ships, appends at one known place and cannot do this.
  Measured after the fix: dashboard `document.body.innerText` is 1,947
  characters without the partial and 1,964 with it — the difference is the
  sidebar row and nothing else.

- **Re-seed between viewports, or the second BEFORE is the first AFTER.** The
  script's whole job is a before/after sequence, and the first run registers the
  webhook, stores the limits and settles the seeded order in the shared preview
  database.

- **`deviceScaleFactor: 2` with `fullPage: true` cost 7 MB a frame**, 116 MB for
  the set. The admin sets `body{overflow:hidden}` and scrolls inside `#content`,
  so a "full page" shot is the viewport anyway; scale 1 and `fullPage: false`
  give the same picture at 3.8 MB for sixteen.

- **Kill the server by PID.** CLAUDE.md forbids `pkill` by pattern on this
  machine: three lanes run at once and a pattern match takes the other two down
  with it.

## What the measurements said

| | 390px | 1280px |
|---|---|---|
| viewport width | 390 | 1280 |
| `document.documentElement.scrollWidth` | 403 | 1296 |
| `#content` scrollWidth / clientWidth | 390 / 390 | 1032 / 1032 |
| horizontal overflow inside the scroller | none | none |
| `.tmw-card` count | 2 | 2 |
| button heights | 35–37px | 35–37px |
| button font size | 13px | 13px |

The 403 and 1296 are the console's own chrome and are **identical on the
dashboard, on Store → Payments and on Platform → Cache**, measured on the same
shop in the same run. `#content` is the element that scrolls, and it does not
overflow at either width.

Before: webhook **not registered**, basket limits **empty** — which is the live
shop's state today, because nothing in the console could ever register one.

After the four buttons: `webhook_id` `wh_preview_a1b2c3`, limits
`100.00 – 5000.00` for `PAY_BY_LATER` in `AE`, Tabby registered in five markets,
and the sweep's own answer against the seeded order:

    Checked 1 order(s) with Tamara. Marked paid: 1. Closed as declined or
    expired: 0. Still waiting: 0. Could not check: 0.

    TM-PREVIEW-MISSED — settled. payment applied

That order was seeded `pending` with no `paid_at`, three hours old — the exact
shape of an approval whose notification never arrived.
