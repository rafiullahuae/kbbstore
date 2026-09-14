# KBB Storefront — status correction

**As at 2.60.107.** This supersedes the progress claims in `KBB-Master-Plan.md`, which is accurate to 2.60.41 and has proved wrong on seven checkboxes since. Everything below was verified against the code on the live server, not inferred from the plan.

---

## 1. Checkboxes that were already built

Seven items are ticked open in the master plan and are, in fact, complete. Each was checked before any work started, which is the only reason none of them got built twice.

| Plan item | Reality | Evidence |
|---|---|---|
| Filters and sorting on the results page | Built | `SearchController::page()` is a one-line delegate to `ShopController::index()` — the results page *is* the shop page, with `Facets` handling cat, brand, price, sale, instock, orderby, and `shop.blade.php` rendering the sidebar, chips and sort control |
| Coupons end to end | Built, including removal | `CouponService` validates and records; `CartService` applies; `CartController::coupon` handles apply *and* remove; checkout persists `coupon_code` |
| Related and recently viewed → register as `recently_viewed` | Already registered | Present in `ModuleRegistry` with status `elsewhere`, settings at Appearance → Cart panel |
| Card skin preview inside the shortcode builder | Built | `#scPrev` renders `skinCard(SC.skin ?? grid_skin)` under a "Card style used" note |
| `product_sorting` module | Built, status was wrong | Corrected to `live` in 2.60.91 |
| `seo_engine` module | Built, status was wrong | Corrected to `live` in 2.60.91 |
| "Still open: the route-cache fix in 2.60.66 didn't resolve it" | Resolved | Not an open task — a chronological log entry, closed by the `[x]` immediately below it in the plan |

**Recommendation:** tick these before choosing any further work. A plan that overstates what's left costs more than the work it describes.

---

## 2. What actually shipped, 2.60.72 → 2.60.91

Applied to the server earlier in the session:

- **2.60.72** — Address book CRUD, product quick view
- **2.60.73** — Module switches for both, registered in `ModuleRegistry`
- **2.60.74** — Hotfix: every product page 500'd. `ProductController::show()` built `$summary` then never imported it into the `seoCtx` closure
- **2.60.75** — Fix: admin Catalog 500'd selecting a `category` column that has never existed on `products`; also fixed an N+1 of ~1,300 queries per screen load

Pending, all folded into the current package:

- **2.60.76–.77** — Guest checkout → account creation; search synonyms (`SearchTerms`), with over-broad entries removed and a four-character floor
- **2.60.78** — Gift notes end to end; the dead `customer_note` path wired at both ends
- **2.60.79–.81** — Checkout layout: fields moved out of the `row2` grid, notes and gift moved into Delivery, checkbox alignment
- **2.60.82–.84** — Priced gift wrapping with merchant control; admin sidebar clipping fixed; settings moved to a Gift wrapping tab
- **2.60.85–.89** — Five rounds of fixes to that feature (see §4)
- **2.60.90** — Autocomplete synonyms; per-order `gift_fee` column; `AdminPathService` cache leak
- **2.60.91** — Module statuses corrected
- **2.60.92 – .98** — Security and correctness sweep; see §6

**Upload 2.60.91 only.** It is a verified superset of 2.60.76 onward.

---

## 3. Everything that remains, and the single thing blocking each

Nothing below is buildable today. Not one item is waiting on engineering time.

### Deferred by decision — mail provider

**Status: parked, not blocked.** Rafi has deferred the mail decision; these four items wait until it is taken. Nothing else in the project depends on them, so they can sit here indefinitely without holding anything up.


| Item | Detail |
|---|---|
| `abandoned_cart` module | Unbuilt |
| `back_in_stock` module | Unbuilt |
| Password reset actually sends an email | No outbound mail has ever been sent from this app |
| Newsletter double opt-in | Waiting on the same |

When it is picked up, one answer unblocks all four: either SMTP credentials on the existing host, or a transactional service. Until then, treat this section as closed rather than pending.

### Blocked on the kbb-theme source

`legal_notice` and `inline_validation` exist in the plugin as a settings link with no implementation. There is nothing to port *from*, so these cannot start.

### Blocked on both the above plus a Google Places API key

`address_autocomplete`.

### Blocked on decisions recorded in the plan

- **Checkout restyle to `<x-field>`** — requires the D-35 amendment
- **Per-product SEO editor** — requires the product edit page
- **`/brands/`** — the homepage and `MenuDemo` disagree on this URL
- **`/skincare-guide/`** — a permalink structure, not one page

Those last two gate the Phase 13 redirect map, which gates migrating **671 products, 4,159 orders, 3,712 customers**.

### Does not port

`performance` — a WordPress-specific concern with no equivalent here.

---

## 4. Faults introduced during this session, and what they teach

Recorded because the pattern matters more than the individual bugs.

| Fault | Root cause |
|---|---|
| Checkout Contact box scrambled | Inserted fields into `<div class="row2">`, a two-column grid, so they became grid children. `form-row-wide` is a WooCommerce class this stylesheet never implements |
| Checkout 500 | `<span>This order is a gift@if (...)` — Blade's directive pattern starts `\B@`, so an `@` after a word character is never compiled. The matching `@endif` *was*, producing `endif;` with no `if:` |
| Delivery & Shipping stuck on "Loading…" | The gift tab read `SETTINGS`, which belongs to a different script scope. Reading an undeclared identifier is a ReferenceError, which killed the whole screen |
| Checkbox sat on the text baseline | `.kbb-checkout .form-row label{display:block}` scores 0,2,1 and outranked my 0,2,0 selector, so `display:flex` never applied and two successive alignment fixes did nothing |
| Gift wrapping row showed at 0 | `.sumrow{display:flex}` outranks the browser's `[hidden]{display:none}` |
| Gift settings never saved | `gift_enabled` and `gift_fee` were not in the settings endpoint's 27-key allowlist. It skips unknown keys and returns `ok: true` regardless |
| Admin tab said Off while checkout showed the tick | The default lived in PHP *and* was guessed again in JavaScript. Two defaults for one setting |

**Two of these were pre-existing and affect more than gift wrapping:**

The settings endpoint wrote with `Setting::updateOrCreate()`, which clears neither `SettingsService::all()`'s `rememberForever` cache nor `Setting::map()`. **Every** value saved from Business Details — COD fee, VAT rate, free-shipping threshold, store name — reached the database and was then ignored by the storefront. Fixed in 2.60.89. **Re-check anything you set on that screen and assumed had applied.**

`AdminPathService` had the same shape. Fixed in 2.60.90 by sweeping for the pattern rather than waiting for it to surface.

---

## 5. Two housekeeping items

**`build 5/`** at the public web root — orphaned, sitting beside the real `build/`, referenced by nothing in the manifest. This answers open question 9 in the plan: safe to delete.

**Business Details overlaps Delivery & Shipping.** Free-shipping threshold, flat delivery fee and COD fee are editable in both places. Two editors for one value is a real hazard. Consolidating means moving VAT and currency to Platform → Settings, moving the shipping fields into Zones, then retiring the screen. That is a deliberate decision about the admin's shape, not something to fold into a feature patch.


---

## 6. Security and correctness sweep, 2.60.92 – .98

Run after the feature backlog emptied. Each item was found by looking for a
pattern rather than waiting for a symptom, which is why several had been live
for a long time without anyone noticing.

### Found and fixed

| Version | Fault | Reachable by |
|---|---|---|
| 2.60.92 | Every brand URL 404'd — `/brands/` (homepage, twice), `/korean-skincare-brands/` and `/brand/{slug}/` (mega menu, 93 leaves). No route existed for any of them | Any visitor |
| 2.60.93 | The journal was published at `/skincare-guide/` by four separate places and served at `/blog` + `/post/{slug}`. Canonical tags, breadcrumb schema and IndexNow all submitted a URL the site never linked, while every real link 404'd | Any visitor; search engines |
| 2.60.94 | `sitemap.xml` contained **zero products** — filtered `status = 'active'`, products use `'publish'`. Categories listed under `/category/`, never a route | Search engines |
| 2.60.95 | `GET /api/settings` returned the **entire settings table** unauthenticated, including `admin_path` and `indexnow_key`. `/api/products/{slug}` and `/api/posts/{slug}` served drafts and hidden products by slug | Anyone on the internet |
| 2.60.96 | The review captcha was bypassable — the API path reached the same table with no honeypot, captcha or rate limit, and `routes/api.php` had no throttling at all | Anyone |
| 2.60.97 | SVG uploads could carry `<script>`, `on*` handlers and entity declarations, served from the site's own origin | Anyone with media-upload access |
| 2.60.106 | Admin Reviews screen read four columns that do not exist, so every review showed blank; both API review endpoints keyed on the same phantom column and had never worked; `GET /api/reviews` exposed `author_email` and `ip` plus unmoderated rows | Anyone |
| 2.60.105 | The public order endpoint sold hidden products, sold out-of-stock items, ignored the sale window in both directions, and stored a model where a brand name belongs | Anyone |
| 2.60.104 | No security headers on any `/api` response; robots.txt allowed crawling of cart, account and wishlist | — |
| 2.60.103 | `og:image`, `twitter:image` and the schema image were relative, so every shared product link showed no picture and Google saw an invalid image | — |
| 2.60.102 | `AddToCart` missing from every pixel — the middle of the funnel was invisible | — |
| 2.60.101 | Four unauthenticated scripts in the public web root, reading `.env` and writing files | Anyone |
| 2.60.100 | **Eighteen further unescaped sites** across every admin screen — review title and body, Quiz Leads, Subscribers and their modals, Customers, line-item brands, the redirects pill. 2.60.98 had found seven by looking where the last bug was; sweeping every screen found the rest | Anyone who reviews, takes the quiz or subscribes |
| 2.60.99 | Core Updates reported version 1.0.0 on a 2.60.98 server, and `requires_version` was therefore unusable as a guard | — |
| 2.60.98 | **Stored XSS in the admin console.** Seven sites wrote customer-supplied strings into `innerHTML` unescaped. An order name or review author — both settable by an anonymous visitor — executed with the admin's session on the origin where `/admin-api` answers | Anyone who places an order or posts a review |

2.60.95 and 2.60.98 compound: one hands out the admin path, the other runs code
with the admin's session.

### Checked and clean

- `/admin-api` is nested inside the `auth:admin` group — every admin endpoint authenticated
- Order detail scoped to `customer_id`; order tracking requires order number **and** email together, so sequential numbers cannot be enumerated
- Upload filenames generated, never client-supplied; folder names regex-constrained
- Customer auth regenerates the session on login and register, invalidates on logout, and is rate limited
- Storefront unescaped Blade output is admin-authored content only; reviews render escaped
- Eager loading is correct on the shop and homepage grids — `with('brand:id,name,slug')` plus explicit column selection
- Index coverage on `products` and `orders` is reasonable for the query shapes in use
- `kbb-doctor.php` and `kbb-recover.php` check a token with `hash_equals`, and both tokens are long random strings

### Outside this repository — needs manual action

Four scripts in `public_html/kbb-upgrade/` have **no authentication of any
kind**, read `.env`, open database connections and write or delete files:
`kbb-patch-file.php`, `kbb-fix-now.php`, `kbb-unstick.php`,
`kbb-check-schema.php`.

They were one-time repairs for the 2.60.36–.48 period and none of those
conditions still exists. They are not in this repository and not reachable by
the update system, so they must be deleted by hand.
