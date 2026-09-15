# Dependency advisories — what is actually exposed

`CLAUDE.md` has said for a long time:

> Laravel 11 carries three open advisories (CRLF injection in the email rule,
> signed-URL path confusion). Fixing them means a 12.x upgrade. CI reports them
> without blocking.

That is accurate and it has never been examined. This file is the examination.
It was written against `laravel/framework v11.56.1` as locked on 2026-09-15,
with the store about to go live.

**The short answer: neither defect is exploitable in this application.** Not
"low risk", not "mitigated by obscurity" — the code path that turns each one
into an attack does not exist here, and each of those facts is now pinned by
`tests/Feature/DependencyAdvisoryExposureTest.php` rather than asserted here.

The rest of this file is the evidence, because a verdict of "not exposed" is
only worth anything if somebody can check the working.

---

## 1. The audit, in full

`composer audit --locked` against the committed `composer.lock`:

| # | Advisory id | CVE / GHSA | Package | Severity | Affected | Fixed in |
|---|---|---|---|---|---|---|
| 1 | `PKSA-3r5d-mb8f-1qw9` | GHSA-5vg9-5847-vvmq | laravel/framework | **high** | `<12.60.0`, `>=13.0.0 <=13.9.0` | 12.60.0 |
| 2 | `PKSA-mdq4-51ck-6kdq` | CVE-2026-48019 (same GHSA) | laravel/framework | unscored | `>=9.0.0 <12.60.0`, `>=13.0.0 <13.10.0` | 12.60.0 |
| 3 | `PKSA-m5cs-t1y6-qpcs` | GHSA-crmm-hgp2-wgrp | laravel/framework | medium | `<12.61.1`, `>=13.0.0 <13.12.0` | 12.61.1 |

**There are two defects here, not three.** #1 and #2 are the same CRLF bug
reported twice — once through GitHub's advisory database and once through
`FriendsOfPHP/security-advisories` — and Composer lists both because they arrive
from different sources with different ids. That is most of why "three open
advisories" has always sounded worse than it is.

Nothing else in the lockfile is affected. Every other installed package —
78 runtime, 44 dev, `symfony/*` 7.4.x, `guzzlehttp/guzzle` 7.15.5,
`nesbot/carbon` 3.13.2, `league/commonmark` 2.10.0, `ramsey/uuid` 4.9.3 — comes
back clean, and nothing is marked abandoned.

---

## 2. Advisory 1 & 2 — CRLF injection in the default `email` rule

**Real exposure: none.** Reachable in validation, unreachable at the sink.

### What the defect actually is

Laravel's `email` rule with no arguments delegates to
`Egulias\EmailValidator\Validation\RFCValidation`
(`vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php:909-932`).
RFC 5322 permits folding whitespace and quoted local parts, and a bare CR is
legal *inside* both. So the rule accepts addresses carrying a raw carriage
return.

This was measured, not assumed. Running every candidate shape past the installed
validator, exactly two get through:

| Input | `email` | `email:strict` | `email:filter` | `filter_var` |
|---|---|---|---|---|
| `"us\r\ner"@example.com` | **accepted** | rejected | rejected | rejected |
| `user\r\n @example.com` | **accepted** | rejected | rejected | rejected |
| `user@example.com\r\nBcc: victim@evil.test` | rejected | rejected | rejected | rejected |

The obvious payload — the one everybody reaches for — is **already rejected**.
Testing only that shape is how this advisory gets written off as unreachable for
the wrong reason. The real payloads are the two above.

### Where the vulnerable rule is used

Seven places validate an email with the framework's rule. Six are reachable
without authentication:

| Where | Line | Rule | Persists to |
|---|---|---|---|
| Registration | `app/Http/Controllers/Store/CustomerAuthController.php:91` | `email` | `customers.email` |
| Login | `app/Http/Controllers/Store/CustomerAuthController.php:36` | `email` | — (lookup only) |
| Checkout | `app/Http/Controllers/Store/CheckoutController.php:151` | `email` | `orders.email`, `customers.email` |
| Review form | `app/Http/Controllers/Store/ReviewController.php:100` | `email` | `reviews.author_email` |
| Newsletter | `app/Http/Controllers/Store/SubscribeController.php:31` | `email:rfc` | `subscribers.email` |
| Quiz API | `app/Http/Controllers/Api/QuizController.php:19` | `email` | quiz leads |
| Admin mail test | `app/Http/Controllers/Admin/MailApiController.php:158` | `email` | — (straight to the mailer) |

And a CRLF address really does get stored. Driven over real HTTP against the
mounted routes:

```
REGISTER  status=302  errors=null  customers.email   = ["\"us\r\ner\"@example.com"]
SUBSCRIBE status=200  ok:true      subscribers.email = ["\"us\r\ner\"@example.com"]
QUIZ      status=201  ok:true
```

So the first half of the advisory is genuinely live: **this application will
accept and persist an address containing a raw CRLF.**

### Why that is still not header injection

Because nothing writes it into a header.

**The sink is closed by `symfony/mime`.**
`Symfony\Component\Mime\Address::__construct`
(`vendor/symfony/mime/Address.php:53`) rejects `/[\x00-\x1F\x7F]/` in the address
outright, before any validator opinion is consulted and before a header is
composed:

```php
if (preg_match('/[\x00-\x1F\x7F]/', $this->address)) {
    throw new InvalidArgumentException('Email address contains control characters.');
}
```

Every Laravel mail path constructs one — `Mail::to()`, `Mail::raw()`, a
notification's `via('mail')`. Verified through the real mailer, not just the
value object: both payloads throw `InvalidArgumentException: Email address
contains control characters.`

**And this application barely sends mail at all.** There is no `Mailable`
anywhere in `app/`. Exactly three send paths exist:

1. `Customer::sendPasswordResetNotification()` — `app/Models/Customer.php:130`.
   The recipient is `$customer->email` read back out of the database, never the
   submitted string, and the form that triggers it validates with
   `App\Rules\StorefrontEmail`, not `email`
   (`app/Http/Controllers/Store/PasswordResetController.php:109`).
2. `Customer::sendEmailVerificationNotification()` — `app/Models/Customer.php:179`.
   Guarded at the point of sending: `EmailVerificationController.php:166` runs
   `StorefrontEmail::passes($customer->email)` over the **stored** address and
   refuses if it fails. A poisoned row cannot be mailed to.
3. `MailTester::send()` — `app/Services/Mail/MailTester.php:113`, admin-only,
   reached from `MailApiController::test()`. This is the one place a string
   validated by the vulnerable rule goes straight to the mailer with no database
   round-trip and no `StorefrontEmail` guard. It is still not exploitable —
   Symfony throws, `MailTester` catches `\Throwable` at line 68 and returns
   `ok:false` with the transport's message — but it is the thinnest margin in
   the application and it is admin-authenticated.

### What an attacker could actually do

Persist a malformed address in `customers`, `subscribers`, `orders`,
`reviews.author_email` or a quiz lead. That is the whole of it. The
consequences:

- **No header injection.** No attacker-chosen `Bcc:`, no use of the shop's SMTP
  credentials, no blacklisting of the sending domain. That is the harm the
  advisory describes and it cannot be reached.
- **Self-inflicted denial of service only.** A customer who registers with such
  an address can never be sent a verification link (the guard refuses) and can
  never trigger a password reset (the form's own rule rejects the address on
  input). The account is unrecoverable — by the attacker's own doing, to the
  attacker's own account.
- **Not CSV injection.** `fputcsv` quotes a field containing a newline, so the
  admin exports at `NewsletterApiController.php:77`,
  `CustomersApiController.php:322` and `OrdersApiController.php:244` stay
  well-formed.
- **Not request splitting.** `TabbyGateway.php:459` and `TamaraGateway.php:160`
  put `$order->email` into a JSON body; `json_encode` escapes control characters.
  No user email reaches an HTTP request or response header anywhere.

### The margin is thinner than the verdict suggests

The reason this is "none" rather than "none, comfortably" is that the defence is
a **transitive dependency nobody chose**. `symfony/mime` is not in
`composer.json`; it arrives under `laravel/framework` and its major is not
pinned by this repo. If that guard ever moved, the CRLF advisory would become
live here with no other change and no new audit line to announce it.

`tests/Feature/DependencyAdvisoryExposureTest.php` asserts that guard by name
for exactly that reason.

And the second reason: `lane/transactional-email` is in flight. **One
`Mail::to($order->email)` on a stored address is all it would take to reopen
this** — except that Symfony would then throw a 500 on a real order confirmation
rather than leak a header. Which is a different outage, not a breach, but it is
still an outage. See §4.

---

## 3. Advisory 3 — Temporary Signed URL path confusion

**Real exposure: none.** Unreachable by architecture.

GHSA-crmm-hgp2-wgrp is a mismatch between the path a temporary signed URL was
signed over and the path `hasValidSignature()` re-derives when checking it.

**This application mints no signed URLs and verifies none.** A scan of every
file in `app/` and `routes/`, with comments stripped so that prose about these
APIs is not mistaken for calls to them, finds zero uses of `signedRoute()`,
`temporarySignedRoute()`, `signedUrl()`, `hasValidSignature()`,
`hasValidRelativeSignature()` or `hasCorrectSignature()`, and no route carrying
the `signed` middleware.

The two flows that would normally use them do not:

- **Email verification** uses `App\Support\CustomerLinkSigner`
  (`app/Support/CustomerLinkSigner.php`), whose own header cites this advisory
  and `KBB_BASE_PATH` as the reasons. It HMACs a length-prefixed canonical
  string of `purpose | claims | expiry` — **containing no URL at all** — so
  there is no path to confuse. It also checks expiry after the MAC and folds
  both into one boolean, so a forged link is indistinguishable from a stale one.
- **Password reset** uses Laravel's token broker
  (`Password::broker('customers')`), which is a hashed database token, not a
  signed URL.

This is a fact a future lane could undo in a single line, so it is asserted over
the source tree rather than written down here and trusted.

---

## 4. What still needs doing — and who owns it

None of this is a vulnerability. All of it is margin, and the lanes that own
these files should decide, not Lane AC.

| # | Change | File (owner) |
|---|---|---|
| 1 | Registration should validate with `new StorefrontEmail` instead of `'email'` | `Store/CustomerAuthController.php:91` |
| 2 | Checkout should do the same for `billing_email` | `Store/CheckoutController.php:151` |
| 3 | Newsletter should do the same (it currently persists CRLF at `email:rfc`) | `Store/SubscribeController.php:31` |
| 4 | Review form should do the same for `author_email` | `Store/ReviewController.php:100` |
| 5 | Admin mail test should do the same for `to` — the thinnest margin | `Admin/MailApiController.php:158` |

Each is a one-line swap to a rule that already exists, is already used in
production on the forgot-password form, and already has its own test coverage.
`StorefrontEmail::passes()` rejects both measured payloads.

**Do not change the login lookup** (`CustomerAuthController.php:36`). Narrowing
validation there would lock out any imported customer whose stored address does
not fit the narrow shape, and it reaches no sink.

For `lane/transactional-email` specifically: send to an address that has been
through `StorefrontEmail::passes()`, the way `EmailVerificationController:166`
does. Not because a header could be injected — Symfony stops that — but because
a `\Throwable` from the mail transport in the middle of an order confirmation is
an outage, and the guard turns it into a logged refusal instead.

---

## 5. Should the 12.x upgrade happen now?

**No. It is not urgent, and shipping it before go-live would be the riskier
choice.** The reasoning is in `docs/LARAVEL-UPGRADE.md` §1, along with the
finding that matters most: **the update packager cannot deliver a framework
upgrade at all** — `vendor/` is in both `BuildPackage::NEVER_SHIP` and
`UpdateGuard::FORBIDDEN_PREFIXES` — so this is not a package, it is a manual
vendor replacement on a host with no shell.

Neither advisory is exploitable. The upgrade should be planned, scheduled and
done deliberately, not rushed on the strength of an audit line that turns out
not to apply.

---

## 6. How this stays true

- **`tests/Feature/DependencyAdvisoryExposureTest.php`** — blocks. Pins the
  security property (CR/LF can never reach a header, whichever layer stops it),
  names `symfony/mime` as today's defender, and fails if any lane reintroduces a
  Laravel signed URL.
- **`docs/dependency-advisories.json`** — the assessed-and-accepted ids.
- **The `Dependency advisories` CI step** — still non-blocking, now legible. It
  diffs the live audit against that file and labels each advisory `KNOWN`,
  `NEW` or `RESOLVED`. A fourth advisory appearing tomorrow is a red annotation
  and a job-summary entry instead of a fourth row nobody reads.
