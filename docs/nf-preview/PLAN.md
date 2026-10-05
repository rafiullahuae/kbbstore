# Lane NF — 404 page, Phase 2 plan (after the owner picks A/B/C/D)

> **Built, 5 October.** The owner picked B and asked for all four to stay
> selectable with full controls under Safety → 404 page (Desktop / Mobile +
> Global). As planned below, plus: `App\Support\NotFoundPage` holds the
> settings (one row, `not_found_page`), `resources/css/kbb/kbb-404.css` is the
> one sheet the shop and the admin preview share, the four illustrations are
> `resources/views/store/not-found/art-{a,b,c,d}.blade.php`, the admin screen is
> `resources/views/admin/partials/not-found-page-screen.blade.php` behind
> `notfoundpage.manage` (owner, manager), wired by `php tools/nf-wire.php`
> (docs/nf-wiring.json). Screenshots and numbers: docs/nf-shots/.

## Ground truth (read, not assumed)

- There is no `resources/views/errors/`404 view. `AppServiceProvider` (~l.587)
  registers a `NotFoundHttpException` renderable: a stored redirect wins
  (`CheckRedirects::findMatch` → `Url::redirect`), otherwise a GET outside
  `admin*`, `admin-api*`, `api*` is logged by `NotFoundLogger::record()`, and it
  returns `null` — so Laravel's default "404 | Not Found" page is what shoppers see.
- Every shopper 404 already reaches that closure as a `NotFoundHttpException`:
  unknown paths via `Route::fallback(fn () => abort(404))` (web.php ~l.1289);
  missing products/brands via `firstOrFail()` (`ProductController:64`,
  `BrandController:393/844`) — Laravel's `prepareException()` turns
  `ModelNotFoundException` into a `NotFoundHttpException` *before* the
  renderable callbacks run; categories/pages/collections via `abort(404)`.
- `/ar/…` is not a route prefix: `SetLocaleFromPath` (global, prepended) strips
  it and sets the locale, so `/ar/no-such-thing` arrives as an ordinary 404 in
  Arabic. One view covers both languages through `__()`.
- The owner app's catch-all (`AppController::missing`) *returns* a JSON 404; it
  never throws, so the closure never sees it. API 404s are JSON (expectsJson / `api*`).

## The one design decision: do NOT create `errors/404.blade.php`

Laravel renders `errors::404` for **every** HTML 404 in the application, the
admin's included. Creating that file would change the admin's and any other
non-shop 404 — the opposite of "untouched". Instead:

1. New view `resources/views/store/not-found.blade.php`, `@extends('layouts.store')`
   (header, menu, cart drawer and footer exactly as every other shop page).
2. New `app/Support/NotFoundPage.php` with one static `respond(Request): ?Response`.
   It answers only when **all** hold: method is GET/HEAD; path is not
   `admin*`, `admin-api*`, `api*` or the owner-app prefix; the request does not
   `expectsJson()`. Otherwise it returns `null` and Laravel's default runs,
   byte-identical to today.
3. The existing closure's final `return null;` becomes
   `return \App\Support\NotFoundPage::respond($request);` — a one-line edit, after
   the redirect check and after `NotFoundLogger::record()`, so **stored redirects
   still win first and the log still records**, in the same order as now.
4. Rendering is done to a string inside `respond()`; if the store layout throws
   (e.g. a DB outage), it returns `null` and the plain default page is served.
   Nothing is left half-built — the landmine in CLAUDE.md about guarded writes
   that leave state behind does not apply, because nothing is written.

## The page

- Real **HTTP 404**, never a soft 200: a 200 tells Google the address is a real
  page, so every dead link becomes an indexed thin duplicate ("soft 404" in
  Search Console) and crawl budget is spent on it. Plus
  `<meta name="robots" content="noindex">` (through whatever head slot the layout exposes — to be read, not assumed). And
  `Cache-Control: no-store` is NOT added — a 404 is cacheable by the CDN like today.
- Chosen illustration inlined as SVG (A 5.1 KB, B 4.6 KB, C 3.7 KB, D 4.8 KB
  measured in the preview, before minifying; target ≤ 5 KB). `aria-hidden` /
  `role="img"` with a translated label. Fixed `viewBox` + `width:100%;height:auto`
  ⇒ the box is reserved from the first paint: **zero layout shift**.
- CSS lives in the view's own `@push('styles')` block (only on 404s), using the
  shop tokens (`--pink`, `--ink`, `--cream`, `--sans`, Cairo on Arabic). No new
  JS — motion is CSS keyframes, all off under `prefers-reduced-motion`.
- Copy in `lang/en/store.php` + `lang/ar/store.php` under `store.not_found.*`.
- Search: `GET {{ Url::to('/shop/') }}?s=` — the header search's own form.
- Links: home, `/shop/`, `/super-sale/`, `/best-sellers/`, WhatsApp
  (`SupportContact::whatsappDigits()`, digits only, so the `href` cannot carry a
  scheme). All via `Url::to()` so `/ar/` readers stay in Arabic.
- Trending strip: top 4 by `total_sales` from the same query shape
  `CollectionController` uses for best sellers, with the column allowlist,
  `Cache::remember('nf.trending', 3600)` ⇒ **1 query cold, 0 warm**, rendered with
  the shop's own product card partial. Admin toggle only if the owner wants one
  (Appearance → 404 page) — otherwise none, nothing new to configure.

## Tests (each with its mutation note)

- `tests/Feature/NotFoundPageTest.php`
  - unknown URL → 404, store layout markers (header + footer), `noindex`, the
    illustration, the search form; **not** 200 (mutation: return the view with 200).
  - missing product / brand / category / page / collection slug → same page, 404.
  - `/ar/no-such-thing` → 404, `lang="ar" dir="rtl"`, Arabic headline.
  - a stored redirect for the path still 301s (mutation: call `respond()` first).
  - `not_found_log` still gets the row (mutation: move the `record()` call below).
  - `/admin/no-such`, `/admin-api/x`, `/api/x`, owner-app catch-all: response
    body/headers identical to before (snapshot of the default response).
  - POST to an unknown path → default response, no store layout.
  - query budget: 404 with a warm cache costs the same as the layout alone;
    3 products vs 40 products → same count (`BrandPageOwnerAsksTest` shape).
  - layout throws → plain default 404, still status 404.
- Full suite with `KBB_WP_DB=kbb_wp_nf`, plus the MySQL lane.
- Screenshots in Chromium: 390 and 1280, English and Arabic, from the running
  app (`php artisan serve`), with `scrollWidth`, CLS (0) and SVG bytes measured.

## Shipping

- Files: new view, new `NotFoundPage`, lang keys, test; one-line change in
  `AppServiceProvider`. No route added, so no `clear_caches_*` migration is
  needed, but `view:clear` runs on apply anyway. Built with `kbb:package`.
- Admin path for the owner: none needed unless he asks for the toggle.
