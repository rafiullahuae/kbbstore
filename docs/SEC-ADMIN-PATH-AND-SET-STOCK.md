# Lane SEC — the leaked admin address, and the basket that could not be paid for

Two defects the owner reported from `extrabeauty.ae`, and three follow-ups the
first round found. All five are fixed; the
pictures below were taken in real Chromium at **390** and **1280** against a
preview of this checkout whose admin path is **`mr-cool`** — the one he named —
so a leak is visible in the address bar rather than argued about.

`docs/sec-shots/`, and `tests/browser/sec-shots.mjs` is what took them.

---

## 1 · The admin address was handed to every logged-out shopper

> "on the tracking result page, the login link is going to administration
> login, which is only for ME. Please make sure all the login, account etc url
> must go to the user login page. /mr-cool link or any administration (my
> links) must not show anywhere."

### What it was

`bootstrap/app.php:105` carried one line whose comment named a scope the call
does not have:

```php
// Unauthenticated back-office requests go to the admin login, not /login.
$middleware->redirectGuestsTo(fn () => route('admin.login'));
```

`redirectGuestsTo()` is application-wide. It sets three statics — on
`Authenticate`, `AuthenticateSession` and `AuthenticationException` — so **every**
unauthenticated request to anything behind any guard answered with the admin
login. The link in `resources/views/store/account/track.blade.php:107` was never
wrong: it points at `/my-account/orders/`, which is behind `auth:customer`, and
the redirect is what turned it into the back-office address.
`routes/auth-customer.php:120` already recorded the defect and handed it to
whoever owns `bootstrap/app.php`.

It is a leak and not a broken link. `admin_path` is treated as a secret
everywhere else in this codebase — `CLAUDE.md` names it among the columns that
must never leave `settings` through `/api/*`, `Api\SettingController` keeps it
out of `PUBLIC_KEYS`, and `Mail\NewOrderAlert` refuses to print it into an
email. This redirect put it in a stranger's address bar, their browser history
and the `Referer` of whatever they clicked next.

### Measured, on the running preview

| | `GET /my-account/orders`, logged out |
|---|---|
| before | `302 → http://…/mr-cool/login` |
| after | `302 → http://…/my-account` |
| `GET /mr-cool` (back office), before **and** after | `302 → http://…/mr-cool/login` |

### The pictures

| | 390 | 1280 |
|---|---|---|
| the tracking result and its link | `390-01-tracking-result.png` | `1280-01-tracking-result.png` |
| **before** — where it landed | `390-00-BEFORE-lands-on-the-admin-login.png` | `1280-00-BEFORE-lands-on-the-admin-login.png` |
| **after** — where it lands | `390-02-lands-on-customer-login.png` | `1280-02-lands-on-customer-login.png` |

The "before" shot is the shop's own **Store administration — Protected area ·
authorised staff only** card, at `/mr-cool/login`, shown to a guest who clicked
"Your orders". The "after" shot is **Welcome back — Sign in to see your orders
and wishlist**, at `/my-account`.

`document.documentElement.scrollWidth` equals `clientWidth` on every page shot:
390/390 and 1280/1280. Nothing overflows.

### How it decides

`App\Support\GuestRedirect` reads the **guard** off the matched route's
middleware — `auth:admin` is the back office, everything else is a shopper — and
never a string match on the path, because `admin_path` is configurable
(`KBB_ADMIN_PATH`, or the settings row) and a shop that moved its admin would
fall through to the wrong branch. It fails towards the storefront: no matched
route, or a guard it has never heard of, answers with the customer login.

The intended URL survives: `redirect()->guest()` stores it and
`Store\CustomerAuthController::login()` already ends in
`redirect()->intended('/my-account/')`.

### Where it is registered, and why in two places

`bootstrap/` is on `BuildPackage::NEVER_SHIP` and `UpdateGuard`'s forbidden
list. This repo has already had three fixes — `SetLocaleFromPath`, the redirect
table and the cache headers — sit complete and **dead on the live server**
because their only registration was in that file. So
`AppServiceProvider::boot()` sets the same three statics to the same closure,
from a file that does ship, and its call lands after the bootstrap one (the
`withMiddleware()` callback runs when the HTTP kernel is resolved, which is
before providers boot). Both together are safe: same closure, same statics.
`bootstrap/app.php`'s closing `usePublicPath(...)` is untouched and pinned.

### The sweep, and what it found

`tests/Feature/AdminPathNeverLeaksTest.php` walks **every registered storefront
GET route** logged out and fails if the admin login URL or the admin dashboard
URL appears in the body or in any response header. 52 addresses swept, green.

Sweeping the views and services found nothing else: no `route('admin.*')` in any
storefront Blade, `AdminPathService` is read only by `Url`, `Locale`,
`PageController::RESERVED_SLUGS` and the `admin.updates` view composer, and
`emails/new-order-alert.blade.php` carries a comment explaining why it prints no
admin link.

**One thing found and NOT fixed** — see below.

---

## 2 · A set and the same product loose made the order unplaceable

> "along with set, the order is not placing, i don't know why. please make sure
> about the stock quantity, if any product is in the set, and also user added in
> the cart too. and the stock qnty is 1, then the individual product will be
> removed from the cart, and set will remain there."

### The mechanism, established before anything was designed

Nothing is applied twice and nothing is double-counted by mistake. It is the
rule working as written against a basket it had no answer for:

1. `StockSetRule::expand()` (MODE_MEMBERS, the shipped default since the owner's
   decision of 29 September) adds one claim line per member.
2. `StockClaim::perShelf()` sums the member's line and the shopper's own loose
   line, which share the toner's shelf, into a demand of **two**.
3. `takeFromShelf()` finds one, refuses, and rolls the whole order back.

Reproduced through the real checkout with a one-unit shelf: `302` back to the
checkout, the refusal *"Only 1 of 1025 Dokdo Toner is left. Please reduce the
quantity in your basket to continue."*, **zero orders written** and the stock
untouched. The owner's screenshot says *"… is sold out"* instead, which is the
same refusal on an empty shelf rather than a short one.

What was missing was the decision about which claim loses. The owner has made
it: **the loose line goes and the set stays.**

### What was built

`App\Services\SetStockReconciler`, called from `Store\CartController::loadCart()`
and `Store\CheckoutController::page()` — while the shopper is looking at the
basket, **never inside `place()`**. `CartService::claimStock()`'s own comment
refuses to drop a line at payment time ("the shopper then pays for a basket they
never agreed to, at a total they never saw"), and that rule is followed rather
than replaced. Reconciling at render is also what makes the totals, the item
count and the free-delivery bar follow: they are read off the basket after the
line has gone.

**Nothing here lets a set be oversold.** The class only ever *removes* demand;
`StockClaim` is still the only thing that decides whether units may leave a
shelf, unchanged, inside the placing transaction, under the same lock.

### Measured, at both widths

| | before | after |
|---|---|---|
| basket heading | `(2 items)` | `(1 item)` |
| basket rows | 2 | 1 |
| subtotal | AED 289 | AED 199 |
| order | refused, 0 written | placed — `#10004` at 390, `#10005` at 1280, AED 219 |

The sentence the shopper reads, captured from the rendered page at both widths:

> 1025 Dokdo Toner has been taken out of your bag: the last of it is inside the
> Medicube booster set you are buying, so it cannot be bought separately as well.

`scrollWidth` equals `clientWidth` on every one: 390/390 and 1280/1280.

### The pictures

| | 390 | 1280 |
|---|---|---|
| the basket, set + the same product loose, shelf of 5 | `390-03-…` | `1280-03-…` |
| the shelf down to 1: the loose line gone, the set kept, the sentence | `390-04-loose-line-gone-set-stays.png` | `1280-04-…` |
| the checkout carrying the same sentence | `390-05-…` | `1280-05-…` |
| **the order placing** | `390-06-order-placed.png` | `1280-06-order-placed.png` |

### Performance

A basket with **no set in it costs nothing at all** — one cached settings read
and an in-memory scan of lines the page had already loaded, and no statement —
so `StorefrontQueryBudgetTest` does not move. With a set in the bag it is at
most two batched statements, and the test measures a nine-member set against a
three-member one to prove it does not scale with the members.

Two things this got wrong on the way, both now pinned by their own case:

* Neither controller selects `manage_stock` or `stock` on a cart line, so the
  shelves are fetched in one batched pair of statements rather than read off the
  loaded models. Read off the models, no collision is ever seen and the class
  silently does nothing.
* The member's name comes out of that same batch and not off `setItems.member`,
  which lazy-loaded one query per member inside a page render — measured at nine
  statements for a nine-member set against three for a three-member one.

---

## Where it sits in the admin

Neither fix adds a control. The one existing switch both depend on is

> **Catalog → Sets → Stock · When a set is sold**

which ships at `members` (the owner's decision of 29 September) and is where the
whole behaviour is turned off if he ever wants a set to carry only its own
stock. `Store → Settings → Admin address` still governs where the back office
lives, and the point of Task 1 is that moving it changes nothing about which
login a shopper is sent to.

---

## Round two — the second door, the shelf key, and the drawer

### 4 · The 398 public admin addresses are closed

Round one reported these as the owner's call. They were not; they are closed.

**Measured before anything was designed**, because the measurement decided the
shape. `Handler::unauthenticated()` answers a request that expects JSON with a
bare 401 and NO Location, and only falls through to `redirect()->guest()`
otherwise:

| request | before | after |
|---|---|---|
| `Accept: application/json` | `401`, no Location | `401`, no Location — **unchanged** |
| `X-Requested-With: XMLHttpRequest` | `401`, no Location | `401`, no Location — **unchanged** |
| a browser's own `Accept` | `302 → …/mr-cool/login` | **`404`, no Location** |
| the console itself, `/mr-cool` | `302 → …/mr-cool/login` | unchanged |
| the storefront, `/my-account/orders` | `302 → /my-account` | unchanged |

**Every one of the console's 47 programmatic calls to `admin-api` expects
JSON** — 43 `fetch()` (the wrapper at `admin/app.blade.php:12209` plus ~20
screen partials with their own) and 4 `XMLHttpRequest` uploads, which are
exactly where a wrapper usually gets bypassed and here do not. Confirmed from
the browser as well as the source: signing in fired 6 admin-api calls, all
expecting JSON, all 200.

**So the 401 is left exactly as it is, deliberately.** Turning it into a 404
would be a worse defect than the one being fixed, and the console says so
itself: **33 screens** read a 404 from their own endpoints as *"the endpoints
are not in this server's compiled route table yet — clear the route cache"*
(53 occurrences), because a package applied by hand without its
`clear_caches_*` migration is a real and frequent fault here; **3 screens**
already read 401 as *"your admin session has expired — sign in again"*.
Collapsing them would send the owner to clear his caches over an expired login.

The decision is the **guard** plus whether the address already carries the
secret, compared **segment by segment** so `admin-api` is not mistaken for
something under `admin` and a `KBB_BASE_PATH` prefix cannot shift it. Registered
as a render callback from `AppServiceProvider::boot()`, because the obvious home
— `withExceptions()` in `bootstrap/app.php` — cannot ship. It re-renders a real
`NotFoundHttpException` so the answer is byte-identical to a genuine 404.

**The console's pictures**, which are the ones that matter here because the
shopper-facing half is invisible:

| | 390 | 1280 |
|---|---|---|
| signed in, reading from `admin-api` | `390-console-01-signed-in.png` | `1280-console-01-…` |
| the session gone, something pressed | `390-console-02-session-gone.png` | `1280-console-02-…` |
| **before** — a stranger at `admin-api/security` | `390-console-00-BEFORE-…` | `1280-console-00-…` |
| **after** — the same stranger | `390-console-03-stranger-gets-404.png` | `1280-console-03-…` |

The "before" shot is the shop's own **Store administration · authorised staff
only** card, served at 200 to anyone who typed `admin-api/security`. The "after"
is a bare **404 · NOT FOUND**, and the body does not contain `mr-cool`.

**No session-expiry defect was created, and that is measured rather than
argued.** The expired-session frame was shot twice — once with the change live
and once with it neutralised — and the two PNGs are **byte-identical**
(`md5 21ba9d80…`). The console received 401 before and receives 401 now.

**Four pins advanced deliberately**, each with its old value and reason at the
site: `SecurityModuleTest`, `MailRoutesTest`, `AdminRoleEnforcementTest` and
`StorefrontRouteWalkTest`'s `api/cart/debug`.

### 5 · `perShelf()` now sums by the shelf the units actually leave

`perShelf()` keyed demand on the variant id whenever a line carried one, while
`claimOne()` picked the shelf afterwards by a different rule. A variant with
`manage_stock` off shares its parent's stock figure — which is every variant
this shop imported — so that variant and the parent as a plain line asked for
one jar twice and were checked one at a time against a shelf they both came off.

It could not oversell, so it was a **wrong sentence**, and the sentence is what
a shopper acts on. Measured on the fixture:

> before  "Hydrating Serum **is sold out**. Please **remove it** from your basket to continue."
> after   "**Only 1 of** Hydrating Serum is left. Please **reduce the quantity** in your basket to continue."

A shopper told to remove a line removes it, and the shop loses the sale of the
unit it did have.

The correction is that the two questions are not one question. **How many units
leave** is a fact about the shelf and is summed; **whether this may be sold at
all** is a fact about the line, because a variant can be delisted by hand while
its parent is still selling, so it is asked once per distinct
product-and-variant pair.

**Cheaper, not dearer, and measured.** Every row is locked in one statement per
table, up front, in id order — which is also the safer lock order against
deadlocks.

| basket | before | after |
|---|---|---|
| two lines sharing a shelf | 8 statements | 4 |
| six lines sharing a shelf | 19 statements | 4 |

`StorefrontQueryBudgetTest` is unmoved and needed no raising.

### 6 · The drawer says it too

The cart page and the checkout carried the sentence; the drawer — the thing a
shopper is looking at when they press Add to bag — showed the line simply gone.

**Not in `.kc-ship`**, which was the obvious band and the wrong one:
`.cp-noship .kc-ship{display:none}` hides that element outright on a shop that
has switched the free-delivery bar off in `Appearance → Cart panel`, so a notice
wearing that class would be invisible on exactly the shops that turned one
control off. It sits inside `.dbody`, which already carries the panel's own
padding at both widths — no new rule in a stylesheet another lane owns, and no
media query of its own.

| | 390 | 1280 |
|---|---|---|
| the drawer, after Add to bag | `390-drawer-notice.png` | `1280-drawer-notice.png` |

Measured: the badge reads **1**, the subtotal **AED 199**, the notice is visible
at both widths, and `scrollWidth` equals `clientWidth` (390/390, 1280/1280).

---

## Found and NOT fixed — the integrator's file

**Nine admin-api endpoints are reached by a browser NAVIGATION rather than by
`fetch()`, and every one is a download.** With an expired session they used to
land on the admin login and now land on a 404. That is the price of closing the
door; it is paid by the administrator and never by a shopper. Six call sites,
all in `resources/views/admin/app.blade.php`, which is the integrator's file:

```
:13031  window.open   /admin-api/orders-bulk-documents
:13215  location.href /admin-api/orders-export
:14646  location.href /admin-api/customers/export
:15745  location.href /admin-api/reviews/export
:19750  location.href /admin-api/catalog-products-export
:14082  window.open(url)  where url is the SERVER's own
        /admin-api/orders/{id}/invoice, /packing-slip, /delivery-note and
        /shipping-label — Admin\InvoiceController::invoiceUrl() and friends
```

▲ That last one is the one a scan for `admin-api` in the console does **not**
find, because the address never appears in the console's source at all. It was
found by following `o.invoice_url` back to the controller, and it is four of the
nine.

**The fix**, for whoever owns that file: fetch them through the console's own
`api()` and hand the blob to the browser —

```js
const r = await api(url, { headers: { Accept: 'application/octet-stream' } });
// …or fetch() the same URL with Accept: application/json on the error path,
// then URL.createObjectURL(blob) and click a synthetic <a download>.
```

That puts them on the JSON path, so an expired session answers 401 and the
console can say what it already says on three other screens. The count is pinned
in `AdminPathNeverLeaksTest`, so a seventh navigation is a red suite.

**And a second, smaller one in the same file.** The Catalog screen's failure
message (`app.blade.php:19323`) reads *"If this is a fresh deployment, the
Catalog → Products routes may not be wired into routes/web.php yet"* for **any**
error, including the 401 an expired session produces — pictured above. It is
**not a regression from this work** (the byte-identical comparison proves the
frame is unchanged), but it is the same "two faults, one message" shape, and
`product-editor-screen.blade.php:1021` already shows the one-line remedy:

```js
if (e && (e.status === 401 || e.status === 419)) return 'Your session has ended. Sign in again.';
```

---

## The suite

**7,966 passed, 22 skipped, 1 failed**, on a clean run with nothing in flight.

The one failure is `PackageSigningTest > it holds no private key in this
repository`, and it is not this lane's: it fails on `12ef037` — round one's own
merge, with none of round two's work in the tree, checked out detached and run —
tripping on Lane PERF's four `docs/perf-reports/*.html`. It passes in the
integrator's newer checkout, so it is already fixed above this branch.

Round one's two base-level failures (`ModuleSchemaEquivalenceTest`,
`ModuleScreenPayloadTest`) are gone, fixed by the rebase exactly as predicted.

**Seventeen pins were advanced in this round**, every one with its old value and
the reason written at the site: four in the first commit (`SecurityModuleTest`,
`MailRoutesTest`, `AdminRoleEnforcementTest`, `StorefrontRouteWalkTest`) and
thirteen more found by the full run. All thirteen asserted the same thing — that
an `admin-api` address answers a signed-out browser with a 302 to the admin
login — and that redirect is the door this round closed.
