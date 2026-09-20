# Selling KBB as a product · the vendor console, licensing, and the update pipeline

The plan for turning this shop into a licensed product with two tiers, a vendor
console you own, and a WordPress-style update flow. Written against the code as
it stands on `2.60.223`, with file and line references throughout — nothing here
is assumed.

**Read §11 before you commit to anything.** It is the part about what licensing
can and cannot actually guarantee, and it changes where you should spend effort.

---

## 1. What you are building

Two applications, not one.

```
console.<your-vendor-domain>       THE CONSOLE — yours alone
                                   Ed25519 PRIVATE key, licences, releases,
                                   customers, invoices, upgrade approvals.
                                   No customer ever receives this code.

kbeautybliss.com                   THE PRODUCT — this repo
customer-a.com                     Every install is the same code.
customer-b.ae                      Ed25519 PUBLIC key compiled in.
```

The console is a **new, small Laravel app** — perhaps fifteen tables and ten
screens. It is not this repo with a flag. You chose that, and it is right: with a
flag, the licence-issuing and package-signing code physically sits on every
customer's server, and a `.env` edit is all that separates a customer from your
console. With a separate app there is nothing on their server to find.

Your own shop, `kbeautybliss.com`, becomes **a customer install like any other**,
holding licence #1. That is deliberate: it means you run the same code path your
customers run, and you find the licensing bugs before they do.

---

## 2. The three decisions you made

| decision | what you chose | consequence |
|---|---|---|
| where the console lives | separate app, your own domain | customers hold no vendor code; nothing to reverse |
| what an invalid licence does | storefront stays up, admin locks to the licence screen, updates stop | you never take a paying shop offline by accident |
| plus a manual lever | super admin can **suspend** a store for non-payment | storefront *does* go down, but only when you press it |
| how honest to be | build it properly, state the limits | §11 |

The distinction in rows two and three is the most important design decision in
this document. **Expiry is automatic and gentle. Suspension is manual and
harsh.** A licence lapsing because a card expired must never take a shop
offline on its own — that is a 3 a.m. support call and a refund. Turning a shop
off is a decision you make, on a screen, with the customer's name in front of
you.

---

## 3. The trust chain — the security core

Everything below rests on **one Ed25519 key pair**. The private half exists only
on the console. The public half is a constant in the shipped code.

I verified this works here: PHP 8.4.19 has `sodium_crypto_sign_verify_detached()`
**in core** — no PECL extension, no shell, nothing for a shared host to be
missing. That matters, because your customers are on the same kind of cheap
shared hosting you are.

Three separate things get signed, and they are all verified with the same public
key:

**1 · The licence itself.** A signed blob the console issues, carrying licence
key, bound domain, plan, the feature list, expiry, install id, billing status.
The app verifies it offline. This is what makes an offline grace period safe —
the app is not trusting its own database, it is trusting a signature it cannot
forge.

**2 · The heartbeat response.** Signed, and carrying back the random `nonce` the
app just generated plus the `install_id` it belongs to. Without the nonce a
customer can record one "you are fine" response and replay it forever after you
revoke them. Without the install id, a licence answer for install A can be
replayed onto install B.

**3 · The update package.** `update.json`'s signature becomes Ed25519 over the
manifest, replacing the dead HMAC in §4.

### 3.1 The rule that makes or breaks this

> **The app must derive plan and features by verifying the signed blob — never
> by reading a plain column.**

Cache the *signed licence* and re-derive from it. The moment any code reads
`licenses.plan` as a column and trusts it, a customer with database access — and
they all have database access, it is their server — sets it to `pro` and you
have nothing. Store the blob; verify it; read the verified result. A test should
pin this: mutate the cached plan column, assert the app still reports standard.

### 3.2 Short licences, renewed often

The signed licence carries a **short** `not_after` — seven days is a good
default. Every heartbeat re-issues it.

This is the whole answer to "what stops someone unplugging the network and
running forever". They can: for seven days. Then the signature they hold is
past its `not_after` and the app degrades. Make the licence long-lived and you
hand out a permanent key; make it too short and a weekend outage at your end
locks out paying customers. Seven days with a daily heartbeat gives six failed
attempts before anyone notices anything.

### 3.3 Clock tampering

Server time is under the customer's control, so `not_after` alone is not enough
— they can set the clock back. Store the highest timestamp ever seen from a
verified console response and refuse to accept a system time earlier than it. A
naive rollback then fails; a sophisticated one still works, which is §11's
point.

---

## 4. What exists today, and the one thing that is broken

Good news first — **the hard half of the updater is already built and battle-
tested**:

| piece | file | state |
|---|---|---|
| path allow-list | `app/Services/Update/UpdateGuard.php` | solid, allow-list not block-list |
| manifest + per-file SHA-256 | `app/Services/Update/UpdatePackage.php` | solid |
| backup, apply, health check, auto-rollback | `app/Services/Update/UpdateRunner.php` | solid |
| what version is installed | `app/Services/Update/InstalledVersion.php` | reads `update_releases`, not env |
| build a package | `app/Console/Commands/BuildPackage.php` | works |
| roles and capabilities | `app/Support/AdminCapabilities.php` | closed-by-default, `owner` short-circuits |

**The broken thing:** packages have never been signed, and the scheme that
exists is the wrong shape.

`BuildPackage.php:199` writes `'signature' => ''` unconditionally — every
package this project has ever produced is unsigned. And
`UpdatePackage.php:162-191` verifies with `hash_hmac('sha256', …, $secret)`
against `KBB_UPDATE_SECRET`, which is **symmetric**. To verify a package, a
customer's install needs that secret in its `.env`. They can read it. With it
they can forge a package your own updater will accept as genuine — and if you
reuse the mechanism for licences, forge a licence too.

So: **replace the HMAC with Ed25519 before a single customer install exists.**
Keep the HMAC path for one release as a fallback if you like, but the public-key
check is what ships.

This is not a criticism of the original choice — an HMAC is exactly right when
the only person building and applying packages is you. It stops being right the
moment someone else runs the code.

---

## 5. The console

### 5.1 Tables

```
customers        name, email, company, stripe_customer_id
licences         key, customer_id, plan, status, issued_at, expires_at,
                 billing_status, suspended_at, suspended_reason, notes
installs         licence_id, install_id, domain, is_primary, is_staging,
                 app_version, php_version, first_seen_at, last_seen_at, ip
activations      licence_id, install_id, domain, result, reason, created_at
transfers        licence_id, from_domain, to_domain, status, approved_by
releases         version, channel, notes, file_path, sha256, signature,
                 min_version, published_at, yanked_at
release_targets  release_id, plan            (a release may be Pro-only)
upgrade_requests licence_id, from_plan, to_plan, stripe_payment_intent,
                 status, requested_at, decided_at, decided_by
invoices         licence_id, amount, currency, status, stripe_invoice_id
audit_log        actor, action, subject, before, after, ip, created_at
```

`audit_log` is not optional. Every licence issue, revoke, suspend, plan change
and release publish writes a row. When a customer says "you turned my shop off
and I had paid", that table is the answer.

### 5.2 Screens

Matching your mockup:

- **Licence Keys** — the table you drew: key, customer, domain bound, plan,
  expiry, status, and Revoke / Re-issue. Plus **Suspend store** and **Change
  plan**, which your mockup does not have yet and needs.
- **App Updates** — upload a package, sign it, promote `draft → staging →
  published`, and **Yank**. §8.
- **Console Backend** — your own settings, the key pair, Stripe keys.
- **Preview (Test App / Test Console)** — your staging installs, flagged
  `is_staging`, which are the only ones that see a staging release.

Add two your mockup is missing: **Upgrade requests** (the approval queue from
§9) and **Audit log**.

### 5.3 The public API the installs call

Four endpoints, all rate-limited, all answering as little as possible:

```
POST /api/v1/activate    key + email + domain + install_id  -> signed licence
POST /api/v1/heartbeat   install_id + nonce                 -> signed licence
GET  /api/v1/updates     install_id + current version       -> release or none
GET  /api/v1/download    install_id + version               -> the signed zip
```

Follow this repo's own `/api/*` landmine rule — **explicit allow-list of what
comes back**. These endpoints must never echo a customer record, another
install's domain, or a licence key. `activate` answers the same way for a key
that was never issued and a key bound elsewhere, compared with `hash_equals`,
exactly as `QuizSubmission::findByPublicToken()` already does here.

---

## 6. The product side

### 6.1 First run — the setup wizard

A fresh install with no licence has exactly one reachable screen. Everything
else, admin and storefront alike, redirects to it.

```
1. Welcome            what this is, what you need
2. Database           they have already done .env; verify and migrate
3. Licence            licence key + email  -> POST /api/v1/activate
4. Owner account      create the first admin (the site owner)
5. Done               into the admin
```

Step 3 binds the licence to `APP_URL`'s host. Get this wrong and every customer
with a `www` and a non-`www` becomes a support ticket, so **normalise the host**
— lowercase, strip `www.`, strip the port — and allow **one extra non-production
domain** per licence flagged `is_staging`, because your customers will have a
staging site and you do not want that conversation every time.

### 6.2 The degrade ladder

This is the whole enforcement model in one table:

| state | storefront | admin | updates |
|---|---|---|---|
| no licence (fresh) | setup wizard | setup wizard | — |
| valid, billing current | full, per plan | full, per plan | yes |
| valid, **billing due** | full | full **+ payment banner** | yes |
| valid, **suspended by you** | **notice page** | **Billing screen only** | no |
| expired / past grace | **full, still trading** | Activate screen only | no |
| revoked | **full, still trading** | Activate screen only | no |

Read the bottom two rows carefully: an expired or revoked licence does **not**
take the storefront down. Only your explicit suspend does. That is the choice
you made and it is the right one — the commercial lever is that they are frozen
forever at the version they have and get no fixes, not that you broke their
shop.

### 6.3 Where the gate lives

A new middleware, `EnforceLicence`, registered **before** `EnforceAdminCapability`
and also on the storefront group. It reads one verified licence state and
decides which row of the table above applies.

One warning from this repo's own history: when you add the route, remember
**every package that adds a route ships a `clear_caches_*` migration** — the
route table is compiled on the server and there is no shell. And if the licence
screen sits at a single first path segment, it must join
`PageController::RESERVED_SLUGS`, because articles live at the site root. Lane
GO was caught by `RootSlugCollisionTest` within a minute of mounting
`/import-chain`; the same test will catch this.

---

## 7. The two plans

**Standard — $50.** Everything except the six below.
**Pro — $79.** Everything.

Withheld from Standard, with where each one actually lives:

| feature | flag | admin | storefront |
|---|---|---|---|
| Import / export | `import_export` | `routes/import-*.php`, `routes/urls-media-admin.php`, the WP exporter download | — |
| Skin quiz | `quiz` | `routes/quiz-leads-admin.php` | `routes/web.php:157` `/skin-quiz` |
| Multiple taxes | `multi_tax` | tax settings, `routes/invoices-admin.php` | tax **calculation** must fall back to a single rate |
| Translation module | `translation` | `routes/translations-admin.php` | the whole `/ar` layer — `SetLocaleFromPath` |
| UGC video section | `ugc_videos` | — | *coming soon* |
| Re-order products | `reorder` | `admin-api/catalog/reorder/*` | see the open question in §13 |

**Three of these reach the storefront**, which is the thing to get right. Gating
only the admin leaves `/skin-quiz` and `/ar/...` live and indexable on a
Standard site. `translation` is the sharpest case: the `/ar` prefix is applied
by middleware we registered in `AppServiceProvider` only last package, so on a
Standard licence that middleware must not register at all — and the Arabic URLs
must then 404 rather than 500.

### 7.1 How to map it, and the one asymmetry

Build `PlanFeatures` beside `AdminCapabilities`, same shape, same file-not-
database reasoning. But invert the default, deliberately:

- `AdminCapabilities` is **closed** by default — an unmapped route is owner-only.
  Right, because an unmapped route is usually sensitive.
- `PlanFeatures` must be **open** by default — an unmapped route is available on
  every plan. Right, because otherwise every route any lane adds tomorrow
  silently breaks for every Standard customer, and the failure looks like a bug
  in an unrelated feature.

That asymmetry is safe only if the premium list cannot silently lose an entry —
so pin it with a census test in this repo's existing style: enumerate every
route under the quiz, translations, import and reorder controllers and assert
each is mapped. A new Quiz route that nobody gated then fails a test by name,
which is how `GqMigrationCensusTest` already works.

### 7.2 The upgrade screen

Your ask: the withheld features "mentioned beautifully" with an Upgrade button.
Build it from the same `PlanFeatures` map that does the gating — one source, so
the screen cannot advertise a feature the gate does not actually unlock, or miss
one it does. Show `ugc_videos` as *Coming soon* and do not let it be a reason to
have paid.

---

## 8. Updates: draft → staging → published

Exactly the WordPress rhythm you asked for.

```
you build            php artisan kbb:package 2.60.224 --since <sha> --notes "..."
upload to console    -> channel: draft
console signs it     Ed25519, private key never leaves the console
promote to staging   -> only installs flagged is_staging can see it
                        (your Test App / Test Console, plus any customer
                         who opted in)
you check staging    the real thing, on a real install
PUBLISH              -> every licensed install sees it in Core Updates
```

On the customer's side, `Store → Core Updates` stops being an upload form and
becomes what WordPress shows:

> **Update available — 2.60.224**  ·  *What's new* · **Update now**

One click: download from the console, verify the Ed25519 signature and every
per-file SHA-256, apply through the existing `UpdateRunner`, health-check,
auto-rollback if the site fails to boot. **All of that already exists.** The
only new parts are fetching instead of uploading, and a signature that is real.

### 8.1 Yank

Add it on day one. This project has already been burned exactly here: packages
**2.60.102–.106 were withdrawn for being built against a stale tree, then
applied anyway** — they reverted three files and 500'd every product page. With
fifty customers instead of one, that is fifty broken shops and fifty phone
calls.

Yank must (a) stop the release being offered, (b) mark installs that already
took it, and (c) show you that list. The customer-side rollback already works;
what is missing today is you knowing who needs it.

### 8.2 `min_version`

`UpdatePackage` already honours `requires_version`, and
`InstalledVersion.php`'s comment explains why no package has ever dared set it —
`config('kbb.version')` was lying. It no longer is. With many installs at
different versions, set `min_version` on every release and let the console
refuse to offer a package to an install too far behind, rather than letting it
apply and fail halfway.

---

## 9. The upgrade and payment flow

Your flow: Upgrade button → card popup → request goes to super admin → you
approve → features unlock.

### 9.1 Do not put card fields on your server

You said "payment popup with card details fields". Build that popup with
**Stripe Elements**, so the card number never touches your PHP. If raw PAN
fields post to your own server you are in PCI-DSS scope, which for a shared host
means an audit burden you do not want and probably cannot pass. Elements looks
identical to the customer and keeps you out of scope entirely.

Note this is a **different Stripe account** from the one in this repo. The
existing `StripeConnect` work is the *shop* taking money from *shoppers*. This
is *you* taking money from *site owners*. Two accounts, two key sets, no shared
code.

### 9.2 Authorise, then capture on approval

The clean way to make "request sent to super admin to approve" work without
holding somebody's money for a service they might not get:

```
customer submits card  -> PaymentIntent, manual capture  (authorised, not taken)
                       -> upgrade_requests row, status = pending
you review in console  -> Approve  : capture the payment, re-issue the licence
                                     with plan = pro
                       -> Decline  : cancel the intent, nothing is charged
customer's next heartbeat (or "Refresh licence") unlocks the features
```

An authorisation holds for about seven days, which is your window to decide. If
you will usually approve immediately, the simpler alternative is to auto-approve
on successful payment and keep manual approval as an override — but then a
decline means a refund, and refunds are a worse conversation than a
cancellation.

Give the customer a **Refresh licence** button so an approval takes effect in
ten seconds rather than at the next daily heartbeat. Without it your first
support question will be "I paid, where is it".

### 9.3 Suspension and payment-due

`billing_status` drives §6.2's ladder:

- `current` — nothing shown.
- `due` — payment banner in the admin, everything still works. This is the
  grace state, and it should have a configurable number of days.
- `suspended` — **you pressed the button.** Storefront serves a notice, admin
  is locked to Billing, and the only thing the site owner can do is pay.

Three safeguards on suspend, because this one takes a real business offline:
require a typed reason, write it to `audit_log`, and make un-suspending
instant and one click. Consider a scheduled suspend ("in 7 days unless paid")
that emails the customer first — the version where they are warned is the one
that does not produce a chargeback.

---

## 10. Moving to the final domain

Your first ask, and it is independent of everything above — do it first, on its
own, before any licensing work lands.

**You have not told me the domain yet.** Everything below is correct whatever it
is; fill it in at step 1.

### 10.1 What the code already does right

The app builds its own URLs from the request root, so most of a domain move is
configuration rather than code. Lane GP proved that last package:
`UrlGenerator` takes the base path from the request, which is why the redirect
fix needed no path surgery.

### 10.2 The checklist

1. **`APP_URL`** → the final domain, with scheme. Every redirect this shop now
   writes is built from it — that is new as of 2.60.223 and it is the single
   value most likely to bite you.
2. **`KBB_BASE_PATH`** → **unset it.** It is `/kbb-upgrade` on staging and it
   prefixes every route. At the final domain the app owns the root.
3. **`bootstrap/app.php`'s `usePublicPath(...)`** → point at the new web root.
   ⚠ `bootstrap/` is on `BuildPackage::NEVER_SHIP` and `UpdateGuard`'s forbidden
   list, so **no package can do this for you** — it is a hand edit on the
   server, exactly like it was the first time.
4. **Clear the compiled caches.** Route and config caches are compiled and there
   is no shell, so ship a `clear_caches_*` migration with the cutover package.
   Until it runs, the old base path is still baked in.
5. **`resources/views/store/app.blade.php:695`** — `const U =
   'https://kbeautybliss.com/wp-content/uploads/'`. This is the one genuine
   hard-coded self-reference I found in shippable code. It is the demo media
   base; point it at the new host or at `config('kbb.media_root')`.
6. **Do not touch the other `kbeautybliss.com` references.** `RedirectMap`,
   `MediaRewrite`, `MediaIndex`, `LegacyCategoryUrls` and the seeded redirects
   all name the **old** site on purpose — they are what makes old URLs land.
   Changing them breaks the migration you just finished.
7. **Session and cookie domain**, **Stripe webhook URLs** (both accounts),
   **IndexNow key**, **sitemap and robots**, **canonical tags** — all follow
   `APP_URL`, but the webhooks must be re-pointed in Stripe's dashboard by hand.
8. **Redirect the old address.** A 301 from wherever it lives now, kept
   permanently.
9. **Re-run the URL map** after cutover. §10 of `docs/GP-ADDRESSES-LAND.md` says
   why: the map resolves against rows this shop carries, and a published article
   at a root slug silently disables a category redirect.

### 10.3 Order of operations

Move the domain first and let it settle for a week. Licensing is a large change
and you do not want to be debugging a signature failure and a base-path failure
at the same time.

---

## 11. What this can and cannot guarantee

You asked me to build it properly and state the limits, so here it is plainly.

**Shipped PHP source can always be edited by whoever holds it.** A customer with
your code on their server can find `EnforceLicence`, delete the check, and run
it. No amount of signing prevents that, because the signature verification is
itself code on their machine. Anyone who tells you otherwise is selling
something.

What the design above actually achieves — and it is a great deal:

- **Nobody can forge a licence or an update.** Ed25519 with the private key on
  your console makes that arithmetic, not policy. This is airtight.
- **Nobody can use your code casually.** Downloading it and running it does
  nothing; it stops at the setup wizard. Every honest user, every curious user
  and every "my developer friend set it up" user is stopped.
- **A patched copy is frozen forever.** This is the real lever and it is why the
  §6.2 ladder deliberately leaves the storefront running. A cracked install gets
  no updates, no security fixes, no new features, no support — and your product
  moves. Within a year it is running something genuinely old. This is precisely
  how the commercial WordPress plugin market works, and it works.
- **You can see everything.** Every install that phones home tells you its
  domain, version and PHP. An install that stops phoning home is visible as a
  gap. You will know.

What it does not achieve: a determined developer who wants your code and is
willing to maintain a fork of it permanently will have it. Price that in as a
cost of doing business rather than engineering against it, because engineering
against it has no end.

**If you want more than this**, the only real step up is encoding the source
with IonCube or SourceGuardian. It genuinely raises the bar — but it needs a
loader installed on the customer's host, and your customers are on exactly the
cheap shared hosting where that loader is most often absent. You would be
trading a meaningful share of your addressable market for protection against a
small number of people. My advice is not to, at least not until you have enough
customers that the maths changes.

---

## 12. Build order

Three lanes at a time and batched releases, per `CLAUDE.md`.

**Phase 0 — domain.** §10, on its own, one package. Nothing below starts until
this is settled.

**Phase 1 — the crypto floor.** Ed25519 key pair; `BuildPackage` signs for real;
`UpdatePackage` verifies with the public key; the HMAC path retired. Entirely
inside this repo, entirely testable, and it is the foundation everything else
stands on. Do not build the console first.

**Phase 2 — the console, skeleton.** New app, the tables in §5.1, Licence Keys
and App Updates screens, the four API endpoints. No payments yet.

**Phase 3 — the product side.** Setup wizard, `EnforceLicence`, the degrade
ladder, the licence screen, heartbeat. Your own shop becomes licence #1 and runs
on it.

**Phase 4 — plans.** `PlanFeatures`, the six gates including the three
storefront ones, the census test, the upgrade screen.

**Phase 5 — money.** Stripe Elements, authorise-and-capture, the approval queue,
billing states, suspend.

**Phase 6 — the update pipeline end to end.** Staging channel, publish, yank,
`min_version`, and Core Updates becoming a one-click screen.

Phases 1 and 2 can run in parallel — they share only the key format. Phase 3
needs both.

---

## 13. Open questions

1. **The domain.** Still unnamed. Both of them, in fact: the shop's final
   domain, and the vendor domain the console will live on.
2. **"Re-order products" — which one?** There are two readings and they are
   different features. The admin one is `admin-api/catalog/reorder/*`, which
   reorders products within a category or brand. The storefront one would be a
   shopper's *buy again* from a past order. The rest of your withheld list is
   mixed admin and storefront, so the list does not settle it.
3. **Renewal.** $50 and $79 — per month, per year, or one-off with a support
   window? The whole expiry design assumes a recurring term; a perpetual licence
   with optional renewals is a different ladder.
4. **Staging installs.** Should every customer get a free staging licence
   automatically, or is it something you grant? Free-and-automatic is friendlier
   and is one more domain per licence to track.
5. **Existing account.** Your own shop's current admin is an `owner`. When it
   becomes licence #1, does anything change for it, or does it simply activate
   like any other install? (Recommend: like any other install — no special case
   is exactly what keeps the path tested.)
