# KBB Storefront — working notes

Laravel 11 port of the kbeautybliss.com WooCommerce store. Read
`KBB-Master-Plan.md` for project history and `docs/REPO-STATE.md` for what the
repo does and does not track.

## How this ships

**Not a git deploy.** Changes reach the server as zip packages applied through
Store → Core Updates in the admin panel. This repo is the durable record of
server state; the packages are how code actually moves.

**The live shop is `extrabeauty.ae`, on Cloudways, and IT HAS A SHELL.** Both
halves of that sentence were wrong in this file until 24 September 2026, and
both cost real time:

- `KBB_BASE_PATH` is **empty** there, not `/kbb-upgrade`, and the app root is
  `/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app` with the
  web root at `../public_html`. `docs/CUTOVER-EXTRABEAUTY.md` is the authority
  on that layout; the `easywebsol.com/kbb-upgrade` paths still named further
  down this file are the OLD Hostinger box. Do not hand the owner a URL built
  from them.
- Cloudways provides SSH (**Servers → Launch SSH Terminal**, or Master
  Credentials). `php artisan migrate --force`, `migrate:status`, `config:clear`
  and reading `storage/logs/laravel.log` are all available. Ask before
  designing around their absence.

**BUILD PACKAGES WITH `php artisan kbb:package <version> --since=<ref>`, ALWAYS.**
Never hand-roll a builder. `update.json` needs six keys and `UpdateRunner` keys
off two of them that a hand-written script will not think of: `migrations`,
which is the ONLY thing that decides whether migrations run at all
(`hasMigrations()` never looks at the files), and `signature`. Five packages
built by a scratch script in one afternoon shipped eight migrations that were
copied to the live server and never ran. `UpdatePackage::verify()` now refuses a
package that carries migrations without declaring them, so the mistake is caught
at the door rather than silently — but do not rely on the door.

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
- **Never `pkill` by pattern on this machine.** Three lanes run at once and
  `pkill -f 'php vendor/bin/pest'` kills the other two mid-suite. It happened:
  one lane cleared its own run and cost another lane a full re-run, and the
  failures it produced in a third looked exactly like flake. Match on your own
  worktree path, or kill the PID you started.
- **Commit as Claude, not as the owner.** A lane's first act in a new worktree
  is `git config user.email noreply@anthropic.com && git config user.name Claude`.
  Five lane commits reached the integrator authored `rite2rafi2@gmail.com`,
  which GitHub shows as **Unverified** on every one of them, and the fix is
  history rewriting — blocked here, and destructive once the branch is pushed.
  Getting it right at `git worktree add` time costs nothing; getting it wrong
  costs a rewrite of somebody else's merge commits.
- Stay inside the directories your lane owns. If a change needs a file another
  lane owns, say so rather than editing it.

## What every lane owes, every time

Set by the owner and not negotiable. A lane that skips one of these has not
finished, however green its suite is.

**1. Nothing that already works may change.** A lane fixes or adds the thing it
was given and leaves the rest of the shop byte-identical. `Storefront-
EnglishUnchangedTest` is the instrument: if it goes red, read the diff and
either revert the accident or advance the pin for the change you meant — never
both at once, and never without looking. Any NEW setting ships at the value the
page already has, so applying the package moves nothing until somebody moves a
slider. The only exceptions are a default the owner asked for in as many words,
and those get called out in the commit rather than buried.

**2. Every patch arrives with a picture.** Not "it works" — a screenshot of the
thing, taken in Chromium at 390px and at 1280px, plus the measured numbers that
matter (heights, widths, font sizes, `document.documentElement.scrollWidth`).
If it moves, show it moving; if it is a control, show the before and the after.
A claim with no picture behind it is a claim nobody can check.

**3. Say where it sits in the admin.** Every patch that adds or changes a
control names its exact path — `Appearance → Checkout page → Mobile · Text
sizes`, not "the checkout settings". The owner should never have to hunt for
what a lane just built.

**4. Fast, and measured rather than asserted.** No N+1s; `StorefrontQuery-
BudgetTest` is a budget, not a suggestion, and a lane that needs one more query
raises it deliberately or finds another way. No JavaScript that measures layout
— this project sizes with `calc()` for a reason, and two tests forbid the
element-measuring APIs by name. Prefer a rendered-once CSS answer to a scripted
one.

**5. Secure by construction, not by intention.** `/api/*` is unauthenticated:
allowlist what a model returns, never the model. Anything printed unescaped is
a constant, never a setting. A select stores one of its own options or the
default. A URL from a setting is scheme-checked before it becomes an `href`.
Every new admin endpoint gets its own capability and fails closed.

**6. No bugs, and the proof is a test that would have caught it.** Every fix
ships with the test that goes red without it, and the test says in its own
comment what the defect looked like on the shop. A mutation note — "change X
back and this is red" — is the shortest way to prove a test asserts anything.

**7. Work at full capacity.** Take the whole task, not the easy half. If part
of it is blocked, finish everything else and say exactly what is left and why.
`docs/LANE-BRIEFS.md` carries the current assignments.

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
- **A lane running composer can rewrite `origin` for everybody.** Worktrees
  share `.git/config` with the main checkout, so a composer operation inside
  one is not isolated from it. After a lane finished, `git remote -v` in the
  main checkout read `origin fetch https://github.com/sebastianbergmann/diff`
  and `origin push git@github.com:mockery/mockery`, plus a stray `composer`
  remote. The push failed with *"mockery/mockery is not in this session's
  authorized repository set"*, which reads like a permissions problem and is
  not one. Nothing leaked — the egress proxy refused the unauthorised host,
  which is the only reason this was a nuisance and not an incident. Check
  `git remote -v` before blaming a push failure on credentials, and restore
  with `git remote set-url origin https://github.com/rafiullahuae/kbbstore`
  (and `git config --unset remote.origin.pushurl`).

- **One swallowed exception bricked the updater for three hours.**
  `UpdateRunner::recordManifest()` wrote a column with `$release->update()`
  inside a try/catch, and its docblock claimed that made it incapable of failing
  an update. Eloquent's `update()` is `fill()` then `save()`: `fill()` puts the
  attribute on the model FIRST, and only then does the save throw. The catch
  swallowed the throw and left the attribute dirty, so every later `save()` on
  that instance re-sent it — including `rollback()`'s status write and the
  `['status' => 'failed']` inside `rollback()`'s own catch, which is the third
  throw and the one nothing catches. It escaped `apply()` and became a bare
  "Server Error" on Core Updates, on every package, down to an 18 KB one.
  **A guarded write that leaves state behind does not contain a failure, it
  seeds one.** Every write to `update_releases` now goes through one private
  writer that cannot dirty the model and cannot throw. The column was missing in
  the first place because of the `migrations` flag above — two defects, and the
  second made the first unrecoverable, because the fix could only travel through
  the updater it had broken.

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
