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

# The WordPress-exporter harness builds its own MySQL database and DROPS every
# table it uses. Two lanes running the suite at once tore it down under each
# other -- three tests failed with "Table 'kbb_ge_wp.wp_options' doesn't exist"
# and passed alone immediately before and after, which reads exactly like flake.
# Name it per lane, the way KBB_TEST_DB already is:
KBB_WP_DB=kbb_wp_<lane> KBB_TEST_DB=kbb_<lane> vendor/bin/pest -c phpunit-mysql.xml
```

Tests run on file-based SQLite. **Do not switch them to `:memory:`** — the
migration set fails partway through on an in-memory database (`menu_items`
disappears before `add_menu_item_options` runs). File-backed SQLite is fine and
production uses MySQL regardless.

## Rules for parallel work

**Three lanes at a time, and batch the releases.** The owner set this cadence
deliberately after a stretch at five: five parallel lanes is the single largest
cost in this project, the integrator is the merge bottleneck anyway, and ten
packages in a day is a lot of applying for someone with no shell. Three lanes
and two or three packages per round is roughly 40% cheaper and barely slower.
Ship a package when a round of merges is done, not when each report lands.

Several agents work this repo at once. Conflicts are avoided by ownership, not
by luck:

- **Do not edit `routes/web.php` directly.** Add routes in your own file and
  have the integrator wire them up.
- **Do not edit `KBB-Master-Plan.md` or `KBB-Progress-Dashboard.html`.** Note
  what you did in the PR body; the integrator merges the plan.
- **Commit as Claude, not as the owner.** A lane's first act in a new worktree
  is `git config user.email noreply@anthropic.com && git config user.name Claude`.
  Five lane commits reached the integrator authored `rite2rafi2@gmail.com`,
  which GitHub shows as **Unverified** on every one of them, and the fix is
  history rewriting — blocked here, and destructive once the branch is pushed.
  Getting it right at `git worktree add` time costs nothing; getting it wrong
  costs a rewrite of somebody else's merge commits.
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
- **A full disk looks exactly like a transaction bug.** `ImportAtVolumeTest > it
  resumes onto exactly the rows it had not done` failed about one run in two with
  `SQLSTATE[HY000]: General error: 1 no such savepoint: trans3`, and passed every
  time in isolation. It is not the importer and it is not test pollution: when
  SQLite cannot write it aborts the transaction, which destroys every savepoint
  inside it, and Laravel then rolls back to a savepoint that is no longer there.
  The row it blames moves between runs, which is the tell — a real data bug
  blames the same row. `df -h /` read **26 MB free, 100% used**, from ~14 GB of
  finished lane worktrees each carrying its own copied `vendor/`. Removing them
  (`git worktree remove --force`, which keeps the branch) freed 19 GB and the
  test went six for six. **So: check `df -h /` before debugging any intermittent
  database error, and remove a lane's worktree when its branch is merged.**
- **`Setting::map()` memoises in a process-level static** as well as the cache.
  Within one long-lived process it will not see writes made after the first
  call. Fine under PHP-FPM, a trap in tests and queue workers.

## Known gaps

- Laravel 11 carries three open advisories (CRLF injection in the email rule,
  signed-URL path confusion). Fixing them means a 12.x upgrade. CI reports them
  without blocking.
- `Api\QuizController::expertRequest` used to take a bare `{id}` with no
  ownership check. It now takes the signed handle `QuizSubmission::publicToken()`
  issues at capture time; `findByPublicToken()` looks the row up *before* it
  checks the signature and compares with `hash_equals`, so a forged token and an
  id that was never issued do the same work and return the same 404. Keep both
  halves — branching differently on the two restores the id oracle.
- `package.json` defines no `build` script, so asset builds are manual
  (`npx vite build`). CI does not build assets.
- Unsigned update packages are accepted (`KBB_UPDATE_SECRET` unset) — a
  deliberate choice while the site is a test deployment.
