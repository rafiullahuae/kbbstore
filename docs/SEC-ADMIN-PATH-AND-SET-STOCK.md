# Lane SEC — the leaked admin address, and the basket that could not be paid for

Two defects the owner reported from `extrabeauty.ae`. Both are fixed; the
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

## Found and NOT fixed — the owner's call

**398 admin-guarded endpoints live at fixed, public addresses, and each one
still names the admin login to a stranger.** 397 of them are `admin-api/*` and
the last is `api/cart/debug`. `admin-api` is not the secret path — it is a fixed
prefix anyone can guess — so:

```
curl -sI https://extrabeauty.ae/admin-api/security
Location: https://extrabeauty.ae/mr-cool/login
```

reveals `admin_path` to anybody who asks, which defeats the point of a secret
admin address by a different door from the one just closed.

It is **not fixed here** because the fix — refuse to name the admin login unless
the request is already under the admin path — changes where a logged-out
administrator lands on 397 endpoints, and the admin console's own session-expiry
handling follows that redirect. That is a decision about the back office, not
about the storefront, and the owner should make it.

The set is pinned by name in `AdminPathNeverLeaksTest`, so it cannot grow
quietly: mounting a new admin-guarded endpoint at a public address is a red
suite.

**And one latent defect in `StockClaim::perShelf()`.** It keys demand on
`variant:<id>` whenever a line carries a variant, but `claimOne()` decides the
actual shelf *afterwards* — a variant that does not manage its own stock comes
off the parent product's shelf. So two lines that share one physical shelf can
be checked separately, which produces a wrong refusal message (the second claim
reports "sold out" against a shelf the first one just emptied). It cannot
oversell — the decrement is a conditional `UPDATE` — and `SetStockReconciler`
resolves shelves correctly for the basket, so the owner's case is fixed either
way. Fixing `perShelf()` itself means loading products and variants before the
sum, which is a query-budget decision worth its own lane.
