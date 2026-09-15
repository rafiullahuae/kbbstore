# Upgrading to Laravel 12

Written by Lane AC on 2026-09-15 after actually doing it. The upgrade was
performed on `lane/laravel-12-spike`, which is pushed and should **not** be
merged — its value is the evidence, and the numbers below are measurements from
that branch rather than predictions.

**Read `docs/DEPENDENCY-ADVISORIES.md` first.** It establishes that neither open
advisory is exploitable here, which is why this is a planned upgrade and not an
emergency.

---

## 1. The headline, so nobody plans around the wrong risk

**The application code does not break.** On `laravel/framework v12.69.2`:

| | Laravel 11.56.1 | Laravel 12.69.2 |
|---|---|---|
| SQLite suite | 667 passed | **666 passed, 1 failed** |
| MySQL 8.0.46 suite (strict `sql_mode`) | 667 passed | **666 passed, 1 failed** |
| `composer audit --locked` | 3 advisories | **clean** |
| `php -l` over `app database routes tools` | clean | clean |

And the one failure was **this lane's own test**, asserting an exception class
too specifically — not application code. It has since been rewritten to assert
the behaviour instead, so a future upgrade branch inherits a fully green suite.

So the difficulty is not the framework. It is delivery. Which brings us to the
finding that should shape the entire plan:

> ### A framework upgrade cannot ship as a Core Update package.
>
> `vendor/` is in `BuildPackage::NEVER_SHIP` (`app/Console/Commands/BuildPackage.php:41`)
> **and** in `UpdateGuard::FORBIDDEN_PREFIXES` (`app/Services/Update/UpdateGuard.php:42`).
> `composer.lock` is in `NEVER_SHIP` too, and `bootstrap/` is forbidden outright
> — deliberately, because a bad `bootstrap/app.php` would stop the application
> booting and leave the updater unable to roll itself back.
>
> There is no path by which the packager puts a new framework on the server. The
> upgrade is a **manual `vendor/` replacement on shared hosting with no shell**,
> and **the updater cannot roll it back.** `BackupService` protects the paths a
> package may write; it does not protect `vendor/`.

Everything below is arranged around that.

---

## 2. What 12.x requires

- **PHP ^8.2.** The host runs PHP 8.3.x (`.github/workflows/ci.yml` pins 8.3 and
  says it matches Hostinger). No PHP move is needed — *provided* §3 is done.
- `laravel/tinker ^2.9` is already compatible; it resolved to v2.11.1 unchanged.
- Pest 4.7 / PHPUnit 12.5 needed no change. The dev dependencies resolved
  cleanly against 12.x with no version bumps at all.
- Nothing in `config/`, `bootstrap/app.php` or the migration set had to be
  touched to get the suite green.

---

## 3. The trap that a green suite will not catch

`composer.json` declares `"php": "^8.2"` and — as of today — sets **no
`config.platform.php`**. Composer therefore resolves against whatever PHP runs
the command, not against the host.

Resolving 12.x on a PHP 8.4 machine pulls in five Symfony 8 components that
require **PHP >= 8.4.1**:

```
symfony/clock             v8.1.0
symfony/css-selector      v8.1.6
symfony/event-dispatcher  v8.1.5
symfony/string            v8.1.2
symfony/translation       v8.1.5
```

That lockfile **cannot run on the production host.** And the failure mode is the
worst available one: the suite is green, `composer audit` is clean, and nothing
anywhere says the artefact is wrong. It would be discovered on the server, on a
host with no shell, against an updater that cannot replace `vendor/` anyway.

**The fix is one key**, and it is verified — adding it and re-resolving
downgraded all five to `7.4.x`, kept `laravel/framework` at v12.69.2, kept the
audit clean and kept both suites at 666/667:

```json
"config": {
    "platform": { "php": "8.3.999" }
}
```

Do this **before** the upgrade, not during it. It is a safe, independently
reviewable change on 11.x that makes the 11.x lockfile honest too, and it
removes the largest single source of "it worked in CI" from the upgrade itself.

CI should also assert it. Something as simple as failing when any locked package
requires a PHP above the host's is enough; a green MySQL job proves the dialect,
not the platform.

---

## 4. The patterns that were flagged as risky, and what actually happened

Each was exercised on the spike branch. All of them survived.

| Pattern | Where | Result on 12.69.2 |
|---|---|---|
| `usePublicPath(getenv('KBB_PUBLIC_PATH') ?: …)` | `bootstrap/app.php` | Boots and serves. `artisan` runs. No change needed. **Do not touch this line** — and note the packager cannot ship `bootstrap/` anyway. |
| Update packager | `app/Console/Commands/BuildPackage.php` | `php artisan kbb:package` still builds and manifest-verifies a zip. |
| `RouteRegistrar` / `RouteCollection` surgery | `tests/Support/Phase9Routes.php`, `OrdersAdminRoutes.php`, `CustomersAdminRoutes.php` — `setRoutes()`, `refreshNameLookups()`, `refreshActionLookups()`, copying out of a `CompiledRouteCollection` | All green. |
| `encrypted:array` casts | `app/Models/MailCredential.php:27`, `app/Models/PaymentProvider.php:20` | Round-trips on both engines, including the widened ciphertext columns on MySQL. `MailSecretsTest` and `PaymentSecretsTest` pass. |
| SQLite / MySQL split | `phpunit.xml` vs `phpunit-mysql.xml` | `migrate:fresh` runs the whole migration set on MySQL 8.0.46 at the strict `sql_mode` with no dialect error. Both suites green. |
| File-backed SQLite (never `:memory:`) | `CLAUDE.md` | Unchanged; the migration-order constraint is ours, not the framework's. |

### The one real behaviour change

Laravel 12 tightened the default `email` rule and added a mailer-level guard:

```
11.56.1   email rule ACCEPTS  "us\r\ner"@example.com  and  user\r\n @example.com
12.69.2   email rule REJECTS  both
11.56.1   Mail::raw()->to($crlf) -> Symfony\…\InvalidArgumentException
                                    "Email address contains control characters."
12.69.2   Mail::raw()->to($crlf) -> \InvalidArgumentException
                                    "Email addresses may not contain line break characters."
```

Two consequences:

1. The CRLF advisory is genuinely **fixed**, not merely unreachable.
2. Any test that pins the *class* of a rejected-address exception will break.
   Assert the behaviour. (This is exactly what the one failing test did, and it
   has been fixed on `lane/dependency-advisories`.)

---

## 5. The ordered sequence

Each step is independently reviewable and independently revertable. Do not
collapse them — the point is that if something goes wrong you know which step
did it.

### Step 0 — prerequisites (do these on 11.x, days before)

1. **Pin the platform.** Add `config.platform.php = "8.3.999"` to
   `composer.json`, re-resolve, commit the lockfile.
   *Verify:* no locked package requires PHP above 8.3; both suites unchanged;
   `composer audit` still reports the same three advisories and no others.
2. **Confirm the host's PHP.** Read it from the live server rather than from the
   CI comment. If it is not 8.3.x, step 0.1 used the wrong number and
   everything downstream is wrong.
3. **Answer the delivery question before writing any code.** How will `vendor/`
   (~50 MB, tens of thousands of files) reach the host, and how will it be put
   back if the site 500s? hPanel file manager upload of a zip, extracted
   server-side, is the realistic answer. **Write down the rollback** — a second
   zip of the *current* `vendor/`, uploaded first and left in place. If that
   cannot be arranged, stop here; the rest is academic.
4. **Land the outstanding packages.** The server is at 2.60.98 with 2.60.99–.107
   packaged and unapplied (`docs/REPO-STATE.md`). Do not stack a framework
   upgrade on top of a nine-package drift — if the site breaks you will not know
   which change did it.

### Step 1 — the composer change

```bash
composer require "laravel/framework:^12.0" --update-with-all-dependencies
```

*Verify:* `php artisan --version` reports 12.x; **re-check §3** — no locked
package requires PHP above 8.3; `composer audit --locked` is clean.

### Step 2 — the suites

```bash
vendor/bin/pest --compact
vendor/bin/pest -c phpunit-mysql.xml --compact
find app database routes tools -name '*.php' -print0 | xargs -0 -n1 php -l
```

*Verify:* both green. On the spike this needed **no application changes at
all**. If a test fails, fix the test or the app — do not relax the assertion to
get past it.

### Step 3 — the things the suite cannot see

The suite does not exercise the host. Check by hand, on staging:

- A storefront page, a product page, `/shop`, checkout, `/my-account`.
- Admin login, the Customers screen (the 1140 that CI now pins), Orders, the
  mail test, a payment settings save (the `encrypted:array` path).
- **`public/build/` assets.** `package.json` defines no `build` script and CI
  does not build assets, so run `npx vite build` and diff the output. Compiled
  assets do not exist in the app folder on the server — see `CLAUDE.md`.
- A `clear_caches_*` migration, because the framework upgrade changes PHP
  classes. Follow the existing convention; several are already in
  `database/migrations/` to copy.

### Step 4 — delivery (the actual risk)

1. Upload a zip of the **current** `vendor/` and leave it there. This is the
   rollback and it must exist before anything is replaced.
2. Upload and extract the new `vendor/`.
3. Apply the ordinary Core Update package for the app-code half (`composer.json`
   is in `UpdateGuard::ALLOWED_FILES`, so it can ship; `composer.lock` cannot and
   does not need to — the server does not run Composer).
4. **Diff the package contents against the repo before applying it.** Packages
   2.60.102–.106 were withdrawn for being built against a stale tree and applied
   anyway, reverting three files and 500ing every product page. Never trust a
   file list.
5. Clear the compiled caches — that is what the step-3 migration is for.

*Verify after each of 2, 3 and 5:* the site loads, a product page renders, admin
login works. If any of them fails, restore the old `vendor/` from step 1.

### Step 5 — afterwards

- Delete the three entries from `docs/dependency-advisories.json`. The CI step
  will report them as `RESOLVED` until you do, which is the reminder.
- Update the "Known gaps" bullet in `CLAUDE.md` (integrator's call — Lane AC
  does not own that file).
- `tests/Feature/DependencyAdvisoryExposureTest.php` should pass unchanged. It
  was written to survive this. If it does not, read its comments before editing
  it: the signed-URL assertion in particular is still worth keeping on 12.x,
  since `CustomerLinkSigner` exists for `KBB_BASE_PATH` reasons as well as
  advisory ones.

---

## 6. Is it urgent?

**No.** And doing it before go-live would be the riskier choice.

- Neither advisory is exploitable in this application. That is established with
  evidence in `docs/DEPENDENCY-ADVISORIES.md` and pinned by a blocking test.
- The upgrade's own risk is not code, it is a hand-delivered `vendor/` on a
  shell-less host that the updater cannot roll back.
- The server is nine packages behind the repo. That drift should be closed
  first, on its own, so that a later problem has one candidate cause.

The right time is a quiet window after go-live, with step 0 already done. Step
0.1 — the platform pin — is the exception and should be done **now**: it is
small, it is reviewable on 11.x, it fixes a real latent defect in the current
lockfile, and it removes the upgrade's largest silent failure mode in advance.
