# The security module, part one — the record and the report

Phase 18, items 6 and 7. Lane C. **Nothing in this round blocks anything.**

## Where it sits

**Store → Security** — the sidebar row sits between *Payments* and *Analytics*
in the Store group. Its controls are three tabs at the foot of the screen:

| Tab | What is on it |
|---|---|
| `Store → Security → What is recorded` | the three recording switches, and the window that collapses repeated rate-limit trips |
| `Store → Security → The verdict line` | the hours the verdict counts, and the two thresholds that decide whether it says "nothing to act on" or "worth a look" |
| `Store → Security → Evidence & retention` | rows per list, how many days evidence is kept, and whether an address is stored with its last part masked |

Every one of those is a row in `App\Services\SecurityModule::SCHEMA`, which is
the same shape `CartPage`, `CheckoutPage` and `SlimFooter` use — so a new option
is a line in that array and not a new screen.

## What is recorded, and where it is hooked

| Event | Hook | Carries |
|---|---|---|
| `setting.changed` | `Setting::saved` / `::deleted` | key, before, after |
| `admin.account` | `AdminUser::created/updated/deleted` | email, role change, *that* the password changed |
| `package.release` | `UpdateRelease::created/updated` | version, status before and after |
| `signin.ok` / `signin.out` | Laravel's `Login` / `Logout` events, admin guard only | actor, IP |
| `signin.failed` | Laravel's `Failed` event, admin guard only | the email tried — never the password |
| `signin.blocked` | one explicit call in `AdminAuthController::login()` | the email, the cooldown |
| `ratelimit.trip` | one listener on `RequestHandled`, on a 429 | path, IP, and a count |

Registered from `AppServiceProvider::boot()` by `SecurityModule::listen()`,
beside the two `::listen()` calls already there. **Not** in `bootstrap/app.php`:
that directory is on `BuildPackage::NEVER_SHIP` and `UpdateGuard`'s forbidden
list, so a registration there could never reach the live server in a package.

A model hook only writes a row **while an admin is signed in**
(`Auth::guard('admin')->hasUser()`, a property read). So the storefront writes
nothing, and `php artisan migrate` writes nothing either.

## What this round deliberately does NOT build

Report before enforce. In the plan's own words, a module that starts blocking on
day one blocks the owner, the payment provider's webhooks and Google's crawler,
gets switched off, and leaves the shop worse off than before because everyone
now believes it is protected. So this round ships **no** request gate, **no**
block list, **no** CSP and **no** file-integrity enforcement. Those are Phase 18
items 1–5 and belong to later rounds, in the order the plan sets out:

    audit trail and reporting screen  ← this round
      → integrity checking, report-only
      → CSP, report-only
      → the request gate in observe mode
      → enforcement, one rule at a time

`tests/Feature/SecurityModuleTest.php` pins that negatively as well as
positively: a storefront walk with everything recording is compared against the
same walk with every switch off, and `SecurityModule`'s source may not name a
middleware registration API at all.

## Wiring left for the integrator

`routes/security-admin.php` is declared and **not required from
`routes/web.php`** — that file is the integrator's. It belongs inside the
existing `admin-api` group, beside `slim-footer-admin.php`:

```php
require __DIR__.'/security-admin.php';
```

The capability (`security.view`, owner-only) is already mapped in
`App\Support\AdminCapabilities`, and the package ships
`2026_12_11_000001_clear_caches_security_module.php` so the compiled route table
is dropped when it applies.

## Retention

Rows older than `keep_days` (90 by default, floor 7) are deleted when the screen
is opened. There is no cron and no queue worker on this host, so a schedule
would be a schedule that never runs; a shop nobody opens the screen on keeps its
rows, which is the safe direction for evidence to fail in.

▲ **Half an answer, and part two says so and fixes the other half.** A shop
nobody opens the screen on keeps its rows *for ever*, and failed sign-ins are
written by anybody who can reach the login form. See "Retention ran only when
the screen was opened" under part two: the calendar half is unchanged and is
still the only honest one, and there is now a row ceiling enforced on the write
path beside it.

## Pictures

`docs/security-shots/` — the screen at 390px and 1280px with a trail in it, and
the same two on a shop where nothing has happened yet.

| | 390px | 1280px |
|---|---|---|
| `#content` scrollWidth / clientWidth | 390 / 390 | 1032 / 1032 |
| `document.documentElement.scrollWidth` | 390 | 1280 |
| verdict font-size | 15.5px | 17px |

---

# Part two — integrity checking, report only

Phase 18, item 3, **in report-only mode**, plus the two things part one named and
left open. Phase 18's sequencing governs this and is not a lane's to reorder:

    audit trail and reporting screen              part one, shipped 2.60.258
      -> integrity checking, REPORT ONLY          <- this round
      -> CSP report-only
      -> the request gate in observe mode
      -> enforcement, one rule at a time

**Nothing in this round blocks anything and nothing in this round restores
anything.**

## Where it sits

| | |
|---|---|
| The findings | `Store → Security` — a card headed **Integrity of the files packages installed**, between the verdict line and *Failed sign-ins* |
| Run it now | `Store → Security → Integrity of the files packages installed → Check now` |
| The switches | `Store → Security → File integrity` — the third of four tabs at the foot of the screen |
| The ceiling on the trail | `Store → Security → Evidence & retention → Never keep more than` |

## What it compares, and against what

Every package carries `update.json` with a **SHA-256 per path**, and
`UpdatePackage::checkChecksums()` already verifies each file against it before a
byte is written. That manifest was then thrown away: `update_releases` kept a
file *count*. So the shop could say that 23 files landed and nothing about
which 23 — on a host with **no shell**, where the owner cannot diff, list or
hash anything himself.

Two sources now, in this order:

1. **`update_releases.manifest`** — a new nullable `longText` column, written by
   `UpdateRunner::recordManifest()` as a package applies. Guarded and logged
   rather than thrown, exactly as `archivePackage()` already is, and called
   *outside* the create() so a column this server's migrations have not added
   yet cannot 500 the update that was installing them.
2. **the archived zip** at `update_releases.archive_path`, for every release
   applied before that column existed — which is all of them, including the one
   running now. `UpdateRunner` has kept those zips, so the shop can speak about
   its own past. A manifest recovered that way is written back into the column,
   so a zip is opened once ever.

`IntegrityChecker::expected()` overlays applied releases **oldest version
first**, so a file shipped twice is compared against the *newest* hash — without
that, every package after the first would light up the whole screen. Only
`status = 'applied'`: a rolled-back release had its files put back, and counting
it would report the shop's own safety net as an intrusion.

`targetFor()` restates `UpdateRunner::targetFor()` rather than sharing it, and
`SecurityIntegrityTest` reflects the private original and asserts the two agree
on every shape of path. **That is the one that would have broken the live server
and nothing else**: `public/` is a different directory from the application root
here, so a checker that joined every path onto `base_path()` would report the
whole of `public/` as missing — on the server only, on the screen whose job is
to say when something is wrong.

## What it cannot see, printed on the screen and not only here

- **Only files a package installed.** The original port was not applied as a
  package, so most of the tree has no entry. `storage/`, uploads and anything
  hand-created are outside it.
- **It cannot see a file that was ADDED.** There is no declared hash to miss. A
  dropped-in `shell.php` is invisible to this check. The owner's ask was "no bot
  can inject code anywhere", and a screen that let him believe otherwise would
  be worse than no screen — so this sentence is in the card, beside the
  findings, not buried in a help text.
- **A deliberate hand-edit reads as a finding**, because from here the two are
  the same event. That is the design.

## The open question is still open

Phase 18 lists **"Restore automatically, or alert and wait?"** under *Open, for
the owner*, with a recommendation of alert-by-default. This round builds the
alert half and leaves a seam, and does not take the decision:

- `Store → Security → File integrity → When a file does not match` is a select
  with **exactly one option**, `Alert me and wait`. `SecurityModule::cast()`
  stores a select value only when it is one of that field's own options, so a
  hand-rolled POST of `restore` is stored as `alert` — the control cannot be
  moved to a behaviour that does not exist.
- `IntegrityChecker` may not name `copy(`, `file_put_contents(`, `unlink(`,
  `rename(`, `fwrite(`, `chmod(`, `mkdir(` or `rmdir(` **in its code**. The test
  strips comments with `token_get_all` before searching, because the class
  docblock names the same calls in prose. A future round that adds restore has
  to delete that assertion by hand.

## Cost, and where it runs

Reachable from `SecurityController` alone — never a middleware, never a model
event, never a provider, and `SecurityIntegrityTest` reads
`AppServiceProvider`, `SecurityModule` and every file in `app/Http/Middleware/`
as text to keep it that way. That is also what keeps it clear of part one's own
trap: nothing here can read a setting before an admin is signed in.

Opening the screen runs it at most once every `integrity_hours` (6). "Check now"
is a separate endpoint, `throttle:6,1`, behind its own capability. Ceilings:
`MAX_FILES` 6000, `MAX_BYTES` 24 MB per file, `MAX_FINDINGS` 50 rows per scan.
A repeat finding bumps `hits` on the row that already says so rather than
writing a new one — without that, an owner who opens this screen every morning
would have thirty rows for one file by the end of the month.

The last scan's summary lives in the **cache**, not a table and not a setting:
not a table because a summary overwritten every scan is not evidence (the
findings are rows), and not a setting because `settings` is the table this
module audits — writing there would file a row saying the Security screen had
been opened, every time it was opened.

## A finding names no actor

`record()` gains an `anonymous` option: no actor, no address, no request path.
The admin who opened the screen is not the person who changed the file — that is
the entire reason the check exists — and a row reading "by \<owner\>, from
127.0.0.1" would put the one person the check can prove innocent in the *by*
column of an alert. The screen refuses to print one even if a row carried it.

## Capability

`security.integrity`, owner-only, **its own and not `security.view`**. Reading a
report that is already written and making a shared plan hash every file a
package installed are different acts with different costs; the day a manager may
read this screen, that must not hand them the button. Its rule is listed **above**
the `admin-api/security/**` wildcard in `AdminCapabilities::RULES`, which is
first-match-wins. Unmapped admin routes resolve to null and
`EnforceAdminCapability` turns null into 403 for everyone but the owner, so it
fails closed twice over.

## The two things part one left open

**1. `ModuleToggle` writes were not audited.** `module_toggles` is its own table
and not a row in `settings`, so the `Setting::saved` hook never saw it — and
`Store → Modules` is where `mega_menu`, `marketing_pixels`, `cart_coupon_field`
and `pay_ship_rules` are switched on and off, every one of which changes what a
visitor is served. Now `ModuleToggle::saved` / `::deleted`, event
`module.toggled`, on the model rather than in `SettingsService::setModule()` for
the same reason the Setting hook is on the model. `ModuleSeeder` runs with
nobody signed in, so a fresh install still arrives with an empty trail.

**2. Retention ran only when the screen was opened.** *That is still the only
honest answer for the calendar half, and it is written down here rather than
left as a silent property:* **this host has no cron and no queue worker, so a
schedule would be a schedule that never runs.** `prune()` is still called from
`SecurityController::show()`.

But "a shop nobody opens the screen on keeps its rows" is only safe until you
ask *which* rows. Administrative rows need a signed-in admin and are bounded by
how much work a person does. **Failed sign-ins are not**: the login throttle
allows five a minute, which is 7,200 rows a day for as long as somebody cares to
keep trying, into a table nothing was trimming.

So there is now a second, independent bound. `Store → Security → Evidence &
retention → Never keep more than` (20,000, floor 1,000), enforced by
`SecurityModule::enforceCap()` **on the write path** — one write in
`CAP_EVERY` (100), keyed on the new row's own id so there is no counter to keep
and no static to leak into the next test in the process. Two queries: find the
id of the first row past the ceiling, delete everything at or below it. A shop
nobody ever opens this screen on now cannot grow the table without bound.

## Settings, and the two that move something

`sec_integrity_hours` (6) and `sec_integrity_action` (`alert`) are new controls
for new behaviour and move nothing that existed. Two are deliberate departures
from "a new setting ships at the value the page already has", called out in the
commit and in `2026_12_12_000001_clear_caches_security_integrity.php`:

- **`sec_integrity_on` ships ON.** The owner asked for exactly this in Phase 18
  item 3. A check that ships off checks nothing until somebody finds the switch.
  It runs only on an owner-only screen, refuses nothing and writes nothing
  outside `audit_events`.
- **`sec_max_rows` ships at 20,000**, where the old behaviour was unbounded. On
  this shop the table is days old and holds far fewer, so applying the package
  deletes nothing.

## Pictures

`docs/security-shots/` — the "before" is part one's `security-*.png`, taken
before the integrity card existed.

| | 390px | 1280px |
|---|---|---|
| `integrity-*.png` — two findings, one changed and one missing | 390 | 1280 |
| `integrity-clean-*.png` — the same shop after the files were put back, via **Check now** | 390 | 1280 |
| `integrity-tab-*.png` — `Store → Security → File integrity`, the select with its one option | 390 | 1280 |

| | 390px | 1280px |
|---|---|---|
| `document.documentElement.scrollWidth` | 390 | 1280 |
| `#content` scrollWidth / clientWidth | 390 / 390 | 1032 / 1032 |
| verdict font-size | 15.5px | 17px |
| `.sx-card` padding | 13px | 16px |
| scan of 4 files, measured | — | 16ms cold, 1ms warm |

Taken against a local front controller under `/tmp` (this repo has no
`public/index.php` — `bootstrap/app.php` pins `usePublicPath()` to the
production web root, and that line is left alone). `.env` was pointed at a
scratch sqlite database with file sessions for the walk and **restored
afterwards**; `git status` shows no stray file and `routes/web.php` untouched.
