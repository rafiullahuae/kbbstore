# Security review — KBB Storefront (October 2026)

A defensive, **read-only** code audit of the whole application: public API and
storefront routes, customer authorization and signed links, payments, the
admin surface and in-app updater, file handling and SSRF, injection/XSS/mass
assignment, and the transport/infrastructure layer. Nothing was attacked on
the live site, and no code was changed — this is a find-and-list review.

## Headline

**No Critical and no High-severity issue was found.** Every high-risk area the
review targeted is defended, usually with layered controls and a regression
test that pins the control in place. The items below are hardening and
defence-in-depth; none is an outsider-exploitable hole on the current code.

Severity key: **Medium** = fix when convenient; **Low** = hardening;
**Info** = awareness / maintenance caveat.

---

## Findings, most important first

### M1 · No HSTS header (Strict-Transport-Security) — Medium
The baseline response headers (`app/Http/Middleware/SecurityHeaders.php`) set
`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
`Permissions-Policy` and `X-Permitted-Cross-Domain-Policies`, but **not**
`Strict-Transport-Security`. The app forces `https://` links
(`AppServiceProvider` `URL::forceScheme('https')`), but without HSTS a browser
will still try plain HTTP on the first visit or a typed/linked `http://` URL,
leaving a window for an HTTPS-downgrade (SSL-strip) man-in-the-middle. On a
shop that takes card and wallet payments this is worth closing.
*Fix:* add `Strict-Transport-Security: max-age=31536000; includeSubDomains`
(consider `preload`) to the security-headers middleware, on HTTPS responses.

### L1 · Unsigned update packages accepted by default — Low (config)
`KBB_UPDATE_SECRET` is unset, so `SigningMode` is permissive and the updater
accepts an unsigned package. This is documented in `CLAUDE.md` as a deliberate
choice while the site is a test deployment. The real control is that applying a
package is **owner-only** (capability `updates.manage`), and the path allowlist
+ zip-slip/symlink guards + checksum binding all hold regardless of signing — so
this is not remotely exploitable. But applying a package runs arbitrary
migrations (arbitrary SQL/PHP), so for a production posture this is the single
highest-impact config gap.
*Fix (for production):* set `KBB_UPDATE_SECRET` (or enable the Ed25519 signing
chain, which is already implemented in `PackageSignature`) so a package must be
signed as well as owner-applied.

### L2 · CSP is report-only on the storefront — Low (defence in depth)
The owner app enforces a Content-Security-Policy (`OwnerAppHeaders`), but the
storefront only sends `Content-Security-Policy-Report-Only`
(`app/Services/Security/ContentSecurityPolicy.php`). No stored or reflected XSS
was found (see below), but if one ever slipped through there is no CSP on the
shop to blunt it. This looks intentional for now (the storefront can't satisfy
a strict policy yet); noted so it isn't forgotten.
*Fix (later):* work the storefront toward an enforceable `script-src`
(nonce/hash based) and flip report-only to enforcing.

### L3 · Session payloads not encrypted at rest — Low
`SESSION_ENCRYPT` defaults off and sessions are stored in the database, so a
read of the `sessions` table would expose session contents (auth id, CSRF
token) in plaintext. Requires database access to matter.
*Fix:* set `SESSION_ENCRYPT=true` in the live environment.

### L4 · Tamara webhook: decline/expire branch trusts the signed body — Low
`app/Services/Payments/Gateways/TamaraGateway.php` (handleWebhook). The
**approval** path refuses to trust the webhook body and does an authenticated
`GET /merchants/orders/{id}` to read the real total before confirming. The
**decline/expire** path calls `confirmer->fail(...)` straight from the signed
body's `event_type`, without that re-fetch. To forge a decline an attacker
needs a valid HS256 JWT, i.e. Tamara's private `notification_token`, so this is
not outsider-reachable; and `confirmer->fail()` won't downgrade a paid or
dispatched order. Worst case with the signing key: releasing stock/coupon on a
still-pending order. Asymmetry worth closing or documenting.
*Fix (hardening):* re-fetch on the fail branch too, or add a note explaining
why it's safe not to.

### L5 · Tamara JWT replay window only enforced when `exp` is present — Low
`app/Services/Payments/Signature.php` (`verifyJwtHs256`) checks `exp`/`nbf`
only if present; Stripe enforces an unconditional 300s tolerance
(`StripeGateway`). A captured valid Tamara webhook with no `exp` could in
principle be replayed. Neutralised in practice because the approval path
re-fetches the live order and `PaymentConfirmer` is idempotent (on `paid_at`
and the `(provider, provider_ref)` unique index), so a replay can't double-pay
or pay a mutated order.
*Fix (hardening):* enforce a timestamp window unconditionally, or dedupe on
event id, to match Stripe.

### L6 · Media sideloader — DNS-rebinding TOCTOU — Low
`app/Services/Import/MediaSideloader.php`. `hostRefusal()` resolves the host and
rejects private/reserved IPs (and the decimal-IP trick, and non-http schemes,
and cross-host redirects), then `fetchOne()` issues `Http::get()` **by
hostname**, re-resolving DNS at connect time rather than pinning the validated
IP. A host could pass validation then resolve to a private IP a moment later.
Not practically exploitable here because the fetch hosts come from the shop's
**own catalogue image columns**, never from the request — an attacker would
already need to control DNS for one of your picture hosts.
*Fix (hardening):* resolve once, connect to the pinned IP with a `Host` header.

### L7 · `api/cart/debug` sensitive data assembled in the handler — Low
`app/Http/Controllers/Store/CartController.php` (`debug()`). The route is
correctly behind `auth:admin` (`routes/web.php`), and `ApiSecurityTest` pins
both the guard and that the public gets no `recent_active_carts`. But the
handler still **builds** the site-wide list of recent carts (ids, customer_ids,
token prefixes); the protection rests entirely on the route middleware line —
which is exactly how this leaked once before.
*Fix (defence in depth):* drop the `recent_active_carts` block from the handler
so the guarantee doesn't depend on route wiring alone.

### I1 · `/api/search` output safety is "by construction," not pinned — Info
`app/Http/Controllers/Store/SearchController.php` (`productRow()`). Unlike the
product and review feeds — which have tests asserting specific sensitive
columns are absent — the search panel's safety rests on `productRow()` emitting
only 7 presentational fields. `CARD_COLUMNS` *selects* `total_sales` (for
ordering) but `productRow()` happens not to output it. A future edit that
spreads the model or adds a field wouldn't be caught by a test.
*Fix:* add a `SearchController` test asserting `total_sales` / `sku` / `wc_id`
are absent from a populated response.

### I2 · `/api/search` writes on a GET — Info
`SearchController::suggest` increments a search counter (behind a 5-min cache)
on `?log=1`. A side-effecting GET on an unauthenticated route; not meaningfully
abusable. Awareness only.

### I3 · Webhook URL carries a per-gateway secret in the path — Info
`routes/payments-webhooks.php` — `/payments/webhook/{gateway}/{secret}`. The
secret can land in proxy/access logs or a Referer. Acceptable because it is
only the first of two factors: Stripe also verifies its HMAC header over the
raw body, Tamara an HS256 JWT, Tabby ignores the body and re-fetches over
authenticated API. Leaking the URL secret alone cannot forge a "paid" event.

### I4 · Mass-assignment safety rests on controller discipline — Info
~55 models use `protected $guarded = []`. No controller passes a raw request
array to `create()/update()/fill()` today (every sink builds an explicit
validated array, and `customer_id` is set via the relation, never from input).
But a future `Model::create($request->all())` on `Order`/`Customer`/`Review`
would be an instant privilege-escalation bug. Maintenance caveat.
*Optional:* set real `$fillable` on the sensitive models for a second layer.

### I5 · Rich-text display safety rests on every write calling `RichText` — Info
Imported/admin HTML (brand/category/page/product-tab bodies) is safe because
every write path runs `RichText::clean` (a real allowlist DOM sanitiser) and
display runs `RichText::forDisplay`. A new write path that skips it would create
stored XSS. Maintenance caveat.

### I6 · `env.staging.txt` committed — Info (hygiene)
It is tracked (allowlisted in `.gitignore`) but its secret fields are **blank
placeholders** (`APP_KEY=`, `STRIPE_SECRET=`, `DB_PASSWORD=`, …), and the app
reads real payment/mail credentials from the database, encrypted at rest
(`encrypted:array` cast). No secret is leaked today. Keep it that way: never
let the real `.env` be committed.

### I7 · Laravel 11 open advisories — Info (known, tracked)
Three framework advisories (CRLF injection in the email rule, signed-URL path
confusion) remain open; fixing them means a 12.x upgrade. Already tracked: CI
reports them without blocking, exposure analysis is in
`docs/DEPENDENCY-ADVISORIES.md`, and the app deliberately avoids the vulnerable
signed-URL primitive (it uses its own `CustomerLinkSigner` HMAC). The CRLF
vector is mitigated by the `StorefrontEmail` validation rule on every
email-taking form.

---

## What was checked and found strong (no finding)

- **Public `/api/*` and storefront**: every read endpoint uses an explicit
  column allowlist (`Product::toApi`, `SettingController::PUBLIC_KEYS`,
  `Review::PUBLIC_COLUMNS`), never a bare model; `admin_path`, `indexnow_key`,
  `sku`, `wc_id`, `total_sales`, `author_email`, `ip` are never served; lists
  are capped; LIKE is parameterised with escaped wildcards. Pinned by
  `ApiSecurityTest`.
- **Authorization / IDOR**: orders, addresses, profile and wishlist are always
  scoped through the authenticated customer (`$customer->orders()->findOrFail`),
  never a raw request id. Another customer's id returns 404. Guard is the named
  `auth('customer')`, fail-closed.
- **Signed links** (order track / pay / phone, newsletter unsubscribe, review
  request, quiz expert-request, email verify, password reset, account invite):
  HMAC-SHA256 over purpose + subject + expiry, compared with `hash_equals`,
  with forged/wrong-subject/expired all collapsed to one 404 (no oracle). The
  former quiz id-oracle fix holds.
- **Payments**: one `PaymentConfirmer` checks amount **and** currency against
  the server-side order total before marking paid, and applies at most once
  (idempotent on `paid_at` + a `(provider, provider_ref)` unique index, under
  `SELECT … FOR UPDATE`). Stripe verifies its HMAC over the raw body with a 300s
  window; Tamara an HS256 JWT plus an authenticated re-fetch; Tabby ignores the
  body and re-fetches over authenticated API. No query-param "paid" state is
  trusted. Gateway secrets live encrypted in the DB, never in a response or log.
  Refund/void/capture/reconcile/settlement are all admin + capability gated.
- **Admin & updater**: capability enforcement is default-deny (an unmapped
  admin route is owner-only); role changes can't escalate past the actor's own
  set, can't touch a higher-privileged account, and can't remove the last Full
  Admin. The updater blocks zip-slip, symlinks, path traversal and out-of-tree
  writes via `UpdateGuard`, and binds files to the manifest by SHA-256 in both
  directions.
- **Uploads**: type is taken from file content (`finfo`), not the client name;
  SVGs are script-scanned and rejected; the destination folder and filename are
  constrained, so no `.php` can be stored/served and no traversal is possible.
- **SSRF**: the only URL-fetching endpoint (media sideloader) takes hosts from
  the catalogue, not the request, and enforces scheme/port/private-IP/redirect
  guards (see L6 for the one residual TOCTOU note). All other outbound calls use
  constant endpoints.
- **Injection / XSS / mass assignment**: no raw SQL interpolates request data
  (sorts go through allowlists, LIKE is bound and escaped); all ~700 `{!! !!}`
  sites output constants, numbers, translations, safely-encoded JSON, or
  `RichText`-sanitised HTML; user content is escaped in every HTML context; no
  controller mass-assigns a raw request array.
- **Transport**: `https` is forced for generated URLs; session cookie is
  `http_only`, `same_site=lax`, and `SESSION_SECURE_COOKIE=true` in the staging
  env; payment/mail secrets encrypted at rest; `.env` not tracked; `APP_DEBUG`
  defaults false and is `false` in the committed env.

## Suggested order of action

1. **M1** — add the HSTS header (small, clear win on a payment site).
2. **L3** — set `SESSION_ENCRYPT=true` on live (one env line).
3. **L1** — for a production posture, turn on update-package signing.
4. **L4 / L5** — tighten the Tamara webhook (re-fetch on decline, unconditional
   replay window). These touch frozen payment code, so only with the owner's
   say-so and the full payment suite + a real test order afterwards.
5. **L6 / L7 / I1** — pin the sideloader IP, drop the `recent_active_carts`
   block, add the `/api/search` column test. Low-risk cleanups.
6. **I-notes** — keep the maintenance caveats (I4/I5) in mind for future lanes.

*This review read code only; it did not run against the live site. Re-run it
after any change to the payment, updater, admin-capability, or auth code.*
