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

## Pictures

`docs/security-shots/` — the screen at 390px and 1280px with a trail in it, and
the same two on a shop where nothing has happened yet.

| | 390px | 1280px |
|---|---|---|
| `#content` scrollWidth / clientWidth | 390 / 390 | 1032 / 1032 |
| `document.documentElement.scrollWidth` | 390 | 1280 |
| verdict font-size | 15.5px | 17px |
