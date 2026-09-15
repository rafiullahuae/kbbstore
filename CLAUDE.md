# KBB Storefront — working notes

Laravel 11 port of the kbeautybliss.com WooCommerce store. Read
`KBB-Master-Plan.md` for project history and `docs/REPO-STATE.md` for what the
repo does and does not track.

## How this ships

**Not a git deploy.** The host is shared hosting with no shell access. Changes
reach the server as signed zip packages applied through Store → Core Updates in
the admin panel. This repo is the durable record of server state; the packages
are how code actually moves.

Consequences that matter when you change something:

- A route added in `routes/web.php` will not take effect until the compiled
  route cache is cleared, so every package that adds a route also ships a
  `clear_caches_*` migration. Follow that convention.
- `bootstrap/app.php` ends with `usePublicPath('/home/.../public_html/kbb-upgrade')`.
  The web root is a **different directory** from the application root, which is
  why compiled assets under `public/build/` do not exist in the app folder on
  the server. Leave that line alone.
- `env.staging.txt` sets `KBB_BASE_PATH=/kbb-upgrade`, which prefixes every
  route. Do not use it as a test env — see `.github/workflows/ci.yml`.

## Commands

```bash
composer install
vendor/bin/pest                  # test suite
vendor/bin/pest --compact
find app database routes -name '*.php' -print0 | xargs -0 -n1 php -l
```

Tests run on file-based SQLite. **Do not switch them to `:memory:`** — the
migration set fails partway through on an in-memory database (`menu_items`
disappears before `add_menu_item_options` runs). File-backed SQLite is fine and
production uses MySQL regardless.

## Rules for parallel work

Several agents work this repo at once. Conflicts are avoided by ownership, not
by luck:

- **Do not edit `routes/web.php` directly.** Add routes in your own file and
  have the integrator wire them up.
- **Do not edit `KBB-Master-Plan.md` or `KBB-Progress-Dashboard.html`.** Note
  what you did in the PR body; the integrator merges the plan.
- Stay inside the directories your lane owns. If a change needs a file another
  lane owns, say so rather than editing it.

## Landmines, each one already paid for

- **Packages 2.60.102–.106 were withdrawn** for being built against a stale
  tree, then applied anyway. They reverted three files and 500'd every product
  page. Before shipping a package, diff its contents against the repo — never
  trust a file list.
- **`/api/*` is unauthenticated.** Every endpoint there is public. Before
  returning a model, check what columns it carries: `products` has `wc_id`,
  `sku` and `total_sales`; `reviews` has `author_email` and `ip`; `settings`
  has `admin_path` and `indexnow_key`. Use an explicit allowlist — see
  `Product::toApi()` and `SettingController::PUBLIC_KEYS`. The tests in
  `tests/Feature/ApiSecurityTest.php` pin all of it; they exist because each
  case leaked in production.
- **A broken filter can hide a second bug.** `Api\ProductController` called
  `Product::toApi()`, which did not exist, for months — its `status` filter
  matched no rows, so the closure never ran. Fixing the filter turned it into a
  500.
- **`Setting::map()` memoises in a process-level static** as well as the cache.
  Within one long-lived process it will not see writes made after the first
  call. Fine under PHP-FPM, a trap in tests and queue workers.

## Known gaps

- Laravel 11 carries three open advisories (CRLF injection in the email rule,
  signed-URL path confusion). Fixing them means a 12.x upgrade. CI reports them
  without blocking.
- `QuizController::expertRequest` takes a bare `{id}` with no ownership check —
  any lead can be modified by enumerating IDs.
- `package.json` defines no `build` script, so asset builds are manual
  (`npx vite build`). CI does not build assets.
- Unsigned update packages are accepted (`KBB_UPDATE_SECRET` unset) — a
  deliberate choice while the site is a test deployment.
