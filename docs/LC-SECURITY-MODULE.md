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

---

# Part three — Content-Security-Policy, report only

Phase 18, item 5, **in report-only mode**. Phase 18's sequencing governs this
and is not a lane's to reorder:

    audit trail and reporting screen              part one, shipped 2.60.258
      -> integrity checking, report only          part two
      -> CSP REPORT-ONLY                          <- this round
      -> the request gate in observe mode
      -> enforcement, one rule at a time

**Nothing in this round blocks anything, and this round ships switched off.**

The plan's own words about why this part in particular wants a report-only
phase: CSP "is the single most effective control against injected script
actually executing, and it is also the one most likely to break a working page,
so it wants a report-only phase first with violations collected to the same
report screen." Both halves of that are true of this shop, and the second half
is measured below rather than warned about.

## Where it sits

| | |
|---|---|
| The findings | `Store → Security` — a card headed **Content security policy**, between the integrity card and *Failed sign-ins* |
| The switches | `Store → Security → Content security policy` — the fourth of five tabs at the foot of the screen |
| The switch itself | `Store → Security → Content security policy → Send the report-only content-security policy` — **ships off** |
| The seam | `Store → Security → Content security policy → What the policy does` — a select with one option |
| The ceiling | `Store → Security → Content security policy → Never keep more than` |

## It cannot become the enforcing header

Not a setting, not a mode, not a branch. The string `Content-Security-Policy` —
without `-Report-Only` after it — **does not occur anywhere under `app/`**.
`SecurityCspTest` strips comments with `token_get_all` and searches every PHP
file in the tree for it, because `ContentSecurityPolicy`'s own docblock names
the enforcing header in prose to explain why it is not there and a naive grep
would find its own prohibition.

Beside that, `csp_mode` is a select holding exactly one option, the same seam
`integrity_action` is: `SecurityModule::cast()` stores a select value only when
it is one of that field's own options, so a hand-rolled POST of `enforce` is
stored as `report`. A future round that enforces has to add the string and
delete the assertion by hand.

## The policy, read out of the shop rather than out of a template

```
default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self';
form-action 'self';
script-src 'self' https://js.stripe.com https://www.googletagmanager.com
           https://connect.facebook.net https://analytics.tiktok.com;
style-src 'self' https://fonts.googleapis.com;
font-src 'self' https://fonts.gstatic.com data:;
img-src 'self' data: https:;
connect-src 'self' https://www.google-analytics.com https://*.google-analytics.com
            https://*.analytics.google.com https://connect.facebook.net
            https://analytics.tiktok.com https://api.stripe.com;
frame-src https://js.stripe.com https://hooks.stripe.com;
media-src 'self'; worker-src 'self' blob:; manifest-src 'self';
report-uri /api/csp-report
```

Every source is there because a page reads it:

| Source | What needs it |
|---|---|
| `fonts.googleapis.com` | Poppins and Cairo in `layouts/store.blade.php`, plus the eight display faces `AccountPanel::fontHref()` picks between |
| `fonts.gstatic.com` | the face files those stylesheets then fetch |
| `'self'` for script and style | the Vite bundle. There is no CDN in this tree and **no external host named in `resources/js` at all** — measured, not assumed |
| `www.googletagmanager.com` | `App\Services\Analytics`, Google's gtag loader |
| `connect.facebook.net` | `App\Services\Analytics`, Meta's `fbevents.js` — injected by an inline loader, so it appears in no `<script src>` in the tree |
| `analytics.tiktok.com` | `App\Services\Analytics`, TikTok's `events.js`, same shape |
| `js.stripe.com`, `api.stripe.com`, `hooks.stripe.com` | `partials/checkout/stripe-elements.blade.php` — the script, the tokenising call and 3-D Secure's own frame, on three directives rather than left to `default-src`, because card entry is the one feature a wrong policy takes down for money |
| `img-src https:` | `products.image` can hold an absolute URL left over from the WooCommerce import. An image cannot execute; narrowing this belongs to the round that enforces, with the list in hand |

The **payment marks are inline `<svg>` elements** out of
`App\Support\PaymentMarkArt`, not image loads. An `<svg>` written into the
document is not a fetch and no directive applies to it — worth saying, because
"the payment marks will break" is the first thing anybody assumes on seeing
`img-src` on a checkout page.

`SecurityCspTest` reads the hosts back out of `Analytics.php` and requires each
to be in the policy, so a fourth network added there and not here is red.

**No `'unsafe-inline'` and no `'unsafe-eval'`.** An injected `<script>` *is*
inline, so a policy carrying `'unsafe-inline'` would report nothing and protect
nothing — the "everyone now believes it is protected" failure Phase 18 names.

## What enforcement would break today — measured, not predicted

A real Chromium was pointed at a real copy of this shop with the policy on, and
these are the reports that came back. **Every single violation is inline script
or inline style. Not one external host was refused** — the allowlist above is
correct as it stands.

| Page | Directive | What | Reports in one page view |
|---|---|---|---|
| `/` | `style-src-attr` | inline `style=""` | 56 |
| `/` | `script-src-elem` | inline `<script>` | 4 |
| `/shop` | `style-src-attr` | inline `style=""` | 56 |
| `/shop` | `script-src-elem` | inline `<script>` | 4 |
| `/product/{slug}` | `style-src-attr` | inline `style=""` | 30 |
| `/product/{slug}` | `script-src-elem` | inline `<script>` | 8 |
| `/product/{slug}` | `style-src-elem` | inline `<style>` | 2 |
| `/cart`, `/checkout`, `/my-wishlist` | `style-src-attr` | inline `style=""` | 13 each |
| `/cart`, `/checkout`, `/my-wishlist` | `script-src-elem` | inline `<script>` | 6 each |
| `/cart`, `/checkout`, `/my-wishlist` | `style-src-elem` | inline `<style>` | 1 each |

So, plainly: **enforcing this policy today takes the shop apart.** Every page
loses its inline script — the cart, the header, the quiz, `window.KBB`, the
three analytics loaders' bootstraps — and every inline `style=""` attribute
stops applying. The storefront views hold 24 inline `<script>` blocks, 124
inline `on*` handlers, 29 inline `<style>` blocks and 210 inline `style=""`
attributes, and the numbers above are what those look like from a browser.

The way out is a per-request nonce on every inline block and the removal of the
`on*` handlers and `style=""` attributes, which is a job across ninety-five
Blade files owned by five different lanes. It is not this round's job and it is
not one round's job. What this round buys is the number: **it is the inline
markup and nothing else**, so nobody has to go host-hunting first.

### And what it costs to leave it on

| Page | Violation reports posted per page view |
|---|---|
| `/` | **158** |
| `/shop` | **164** |
| `/product/{slug}` | 40 |
| `/cart`, `/checkout`, `/my-wishlist` | 20 each |

One page view is up to 164 extra POSTs. That is the reason `sec_csp_on` ships
**off**: it is the rule ("a new setting ships at the value the page already
has" — the shop sends no policy header today), and it is also a real bill on a
shared plan. Turn it on for a few days, read the card, turn it off.

The route's `throttle:60,1` sheds the rest, and the card says so on its face
rather than presenting a sample as a count.

### One defect this found, and only running it could have

The first screenshot taken of the card had the verdict line at the top of
`Store → Security` reading:

> **Worth a look: 687 requests refused as too many in the last 24 hours.**

Every one of those was this module's own endpoint answering this module's own
policy. A page view posts 158 reports, the throttle allows 60 a minute, and
part one's `RequestHandled` listener faithfully recorded each shed one as a
rate-limit trip. Neither half was wrong alone; together they made the shop's
own security screen report the owner's own visitors as an attack the first time
he switched the policy on — the "believed protection" failure running backwards.

A 429 on the report endpoint is now filed under its own event, `csp.shed`. It
is still recorded, because reports really were lost and that is worth knowing;
it is counted on the policy card as *reports turned away*; and it is out of both
the "requests refused as too many" list and the threshold that fires the
verdict. That list means *somebody is hammering the shop*, and this is not that.

## The report endpoint is somebody else's data

`POST /api/csp-report`, public, and it has to be: a browser posts it with no
session and the spec gives it no signature. So it gets the `/api/*` treatment.
Five bounds, in the order they apply:

1. **The route's throttle** — `throttle:60,1`, per address.
2. **The body cap** — 16 KB, and a body over it is dropped whole rather than
   truncated (a truncated JSON document does not parse anyway).
3. **The field allowlist** — four values read by name, out of either report
   shape (`application/csp-report` and the Reporting API's
   `application/reports+json`; only the first report of a batch is read, so one
   POST cannot write many rows).
4. **The shape of each** — a directive off the allowlist is stored as
   `unknown`; a URI is cut to scheme, host and path, so a per-request query
   string cannot defeat the collapse and nothing a query string held reaches a
   row; control characters are stripped; everything is clipped.
5. **The row ceiling** — below.

It answers **204 to everything** and echoes nothing: stored, dropped, malformed,
switch off — one answer, so a prober learns nothing from the difference. The
same rule `Api\QuizController::expertRequest` follows for a forged token.

A violation row carries **no actor**. `record()` gains `no_actor`, the weaker
half of `anonymous`: an integrity finding knows nothing about who, while a
violation knows the address and the page and those *are* the evidence — but the
owner browsing his own shop with an admin session open must not find his email
in the *by* column of a row a stranger's browser wrote.

## A flood of these cannot cost the owner his audit trail

This is the sharpest thing in the round. `audit_events` already had a ceiling —
`max_rows`, 20,000, enforced on the write path, which deletes the **oldest**
rows. That is the right rule for a table only signed-in admins and the shop's
own throttle can write to, and it becomes a weapon the moment an unauthenticated
stranger can write to the same table: enough posted violations and the module
deletes the owner's sign-ins, setting changes and package installs to make room,
on the flooder's schedule.

So policy rows are bounded among themselves. `sec_csp_rows` (200, floor 20),
`WHERE event IN (csp.violation, csp.shed)` on both queries of the sweep, run on
every row the endpoint causes. Violations can occupy at most 200 of the 20,000
rows, and **19,800 of them are not reachable from that endpoint at all.**
`SecurityCspTest` floods 90 distinct violations past a ceiling of 20 with the
throttle turned off and asserts an administrative row written first is still
there; removing the `where('event', …)` from either sweep query turns it red.

## Wiring left for the integrator

**Not the admin group.** One line in `routes/api.php`, inside the existing
`SecurityHeaders` group, beside `payments-webhooks.php`:

```php
require __DIR__.'/security-csp.php';
```

`routes/api.php` specifically, and for the reason that file's own header gives
for the webhooks: the web group's CSRF check would answer 419 to every report,
because a browser has no token to send.

**The middleware needs nothing.** `AppServiceProvider::boot()` appends
`App\Services\Security\CspHeaders` to the `web` group beside `CacheHeaders`,
because `bootstrap/app.php` is on `BuildPackage::NEVER_SHIP` and a registration
there could never reach the live server in a package. It is in
`app/Services/Security/` rather than `app/Http/Middleware/` because that
directory belongs to another lane this round; a middleware is a class with a
`handle()` method and the group does not care where it lives.

The package ships `2026_12_13_000001_clear_caches_security_csp.php` so the
compiled route table and the compiled Blade are dropped when it applies.

### What the integration round moved under this lane, and what it cost

Both checked, both now pinned by `SecurityCspTest`:

- `/sitemap.xml`, `/robots.txt` and `/llms.txt` now run inside
  `Route::withoutMiddleware(SeoFilesController::STATELESS)`. `CspHeaders` is
  **not** in that list, so it still runs on them — and still declines, because
  they are XML and plain text and the policy is keyed on `text/html`. Measured:
  those three answer 200 with `X-Content-Type-Options: nosniff` and **no**
  policy header. A policy on a document no browser renders is a header a shared
  cache would store for an hour for nothing.
- `/concern/{concern}/` is a new public storefront page and is covered **by
  construction**, which is the property worth pinning: keying on the content
  type rather than on a list of paths is what makes a page added after this
  lane carry the policy without anybody remembering.

## The round-two conditional, answered

Round two said the live server would report `expected: 0` "unless older releases
still have archived zips". **They do.** Not a conditional:

- `UpdateRunner::archivePackage()` copies every package's zip into
  `storage/app/private/kbb-patch-archive/` on every successful apply and writes
  `update_releases.archive_path`. It has done so since **2.60.41** — the master
  plan records the feature and the path bug fixed in the same release.
- **Nothing anywhere deletes one.** `kbb-patch-archive` has exactly one writer
  in the tree and no reader that unlinks, no console command, no retention
  sweep, no prune.
- The owner can confirm it without a shell in one look:
  `UpdateApiController` sends `has_archive` per release, so
  `Store → Core Updates` shows a **Download** button on exactly the rows whose
  zip is present.

The server was at 2.60.98 at the last repo sync and is far past that now, so
effectively every applied release from 2.60.41 onward has a manifest source.
`IntegrityChecker` reads the zip once, writes the recovered manifest into the
new `manifest` column, and never opens it again.

What is genuinely outside it is the releases applied **before 2.60.41**, and the
original WooCommerce port, which was never applied as a package at all. The
screen no longer leaves that as a conditional either: `survey()` now returns
`applied` beside `sources`, the card reads "from **N** of **M** installed
packages", and when the two differ it names the difference and says why. The
`expected === 0` branch now describes the only case that can actually produce
it — no package applied, or the copies gone from storage — instead of implying
that is the normal state.

## Cost

| | |
|---|---|
| The header | one settings read on a storefront HTML response, no query. `SecurityModule::get()` now reads ONE key instead of building the whole schema — it was `$this->all()[$key]`, twenty-odd cached reads for one switch. Pinned: the home page costs exactly the same number of queries with the policy on as with it off |
| The report | one query per report, either an UPDATE on the collapsed row or an INSERT, plus two for the ceiling on an insert |
| The screen | one extra count query for "violation rows kept", added deliberately: the list is LIMITed to 40 and the ceiling is 200, so counting the drawn rows would understate it fivefold |
| `IntegrityChecker` | one query and one JSON decode per applied release **fewer** than before, from folding `expected()` and `sourceCount()` into one `survey()` pass |

## Pictures

`docs/security-shots/` — taken in Chromium against a local front controller
under the scratchpad (this repo has no `public/index.php`; `bootstrap/app.php`
pins `usePublicPath()` to the production web root and that line is left alone),
against a scratch sqlite database with file sessions, **restored afterwards**.
The report route was registered exactly as `routes/api.php` will carry it.

| | 390px | 1280px |
|---|---|---|
| `csp-*.png` — the whole screen with the policy on and real violations collected | 390 | 1280 |
| `csp-card-*.png` — the policy card on its own: the policy in full, the shed-report line, the findings | 390 | 1280 |
| `csp-tab-*.png` — `Store → Security → Content security policy`, the select with its one option | 390 | 1280 |

| | 390px | 1280px |
|---|---|---|
| `document.documentElement.scrollWidth` | 390 | 1280 |
| `#content` scrollWidth / clientWidth | 390 / 390 | 1032 / 1032 |
| verdict font-size | 15.5px | 17px |
| `.sx-card` padding | 13px | 16px |
| violation rows drawn | 18 | 17 |

Unchanged from parts one and two at both widths, which is the point: no
horizontal overflow and nothing else on the screen moved.
