# KBB Master Plan — live tracking

**As at version 2.60.107.** Tick items as they ship; add the version that shipped them.

**Mail provider is deferred, not pending.** Password reset, newsletter double opt-in, `abandoned_cart` and `back_in_stock` all wait on it. Nothing else depends on them, so that group is parked rather than blocking.

**Visual progress dashboard**: `KBB-Progress-Dashboard.html`, alongside this file — a colored,
per-phase progress view computed directly from the checkboxes below, not a separately maintained
number. Regenerate it after any batch of updates to this file so the two never drift apart.

> A full handover accompanies this update — see `KBB-Handover-Complete.md` for
> the codebase snapshot, backups, gate tools, and complete build/recovery
> procedure. This file remains the living plan; that one is the point-in-time
> package around it.

Legend: **[x]** done · **[~]** partly done · **[ ]** not started · **▲** blocked or risky

> **This file is a summary view.** The authoritative plan is
> `KBB-Port-Parity-Ledger-v2.md` — 305 items. Phase numbers below are this file's
> own and **do not match the ledger's**; where they conflict, the ledger wins.

---

## Progress

| | |
|---|---|
| Ledger completion at last count | **36%** (110 Fn ☑ / 94 Dsp ☑ of 305) |
| Estimated now | **~49%** — see the note below |
| Releases shipped this stretch | 2.38.0 → 2.60.41 *built*, **2.60.36 actually verified live on the server** — see the note directly below. Every applied patch from 2.60.41 onward is archived and downloadable directly from the server — see Core Updates → history table |
| **Server drift, found 2026-09-10** | Rafi uploaded the real app directly from the server for a GitHub sync. Comparison showed the live server is genuinely at **2.60.36** — 2.60.37 through 2.60.41 (SEO description quality, the Core Updates escaping/session bugs, and the patch archive system) were built, packaged, and handed over, but never actually applied — almost certainly because the server got stuck exactly at 2.60.36, which is the version whose own release notes caused the Core Updates screen to break. GitHub now reflects the verified 2.60.36 state, not the assumed 2.60.41 one. **2.60.41 (a superset of everything since) still needs to be applied to catch the live site up** |
| Modules identified | **31** — 29 from the plugin + 2 native to this app |
| Modules registered in the framework | **31** — *2.44.0* |
| Modules with a working switch | **15 live**, **6 answered by an existing screen**, **10 still to port** |
| Build gates | **6 run on every package** (`js_check`, `blade_lint`, `closure_check`, `state_check`, `markup_check`, `shipped_check`) + 2 situational (`hooks_bound`, `settings_wired`). `kbb-doctor.php`, standalone at the public web root, is the first thing to check for any live-site bug report — reads the real error log directly, works even if Laravel itself won't boot |
| Dead hooks outstanding | **2**, re-run fresh at 2.60.0 — down from 23 at the start of Phase 17. Both remaining are false positives (checked directly, not assumed): inert markup and a CSS-only attribute the tool can't see. Phase 17 is functionally complete |

**The estimate is mine, not the ledger's.** The ledger has not been re-scored
item by item since the 36% count, and doing that honestly takes a pass through
all 305 rows. The ~44% comes from the phases below. Treat it as a working figure
until the ledger is re-scored — itself a half-day job worth doing before the next
planning conversation.

### What shipped since the handover

| Area | Result |
|---|---|
| Cart panel | Live quantity, remove, coupons, Browsed tab. Every add-to-cart path repaints it. 32 settings, all wording editable |
| Homepage | Section frames removed on phones — 11% more width for the grid; corner-tick dividers with per-section scope and two random modes |
| Mobile header | Screen for side/row spacing, search field shape and colours, divider above search |
| Product names | Now shown in full — the setting had never reached the storefront |
| Newsletter | Real `subscribers` table; signups had been overwriting a single row |
| Product page | Sticky add-to-cart restored and made configurable |
| Module framework | Registry of 31 modules with per-device visibility, a working eye-hover preview of where each shows, and a link straight to its settings screen |
| Product Labels | Ported from the plugin — sold-out/sale/new/bestseller badges, one at a time, in precedence order |
| Payment & Shipping Rules | Ported — COD hidden by order-value window, enforced at all three entry points (page, form submit, API) |
| Delivery & Shipping | The zone charges (20/199 etc.) existed in the database with no screen; now editable, with a live preview |
| Extended delivery | Optional per-country charges + detection (header → time zone → default), off by default, for reselling this app to another store |
| Checkout bug | A VAT-line and three price-card fields were printing raw HTML tags instead of formatted prices — fixed in all four places |
| Admin | Cart panel, Section dividers, Mobile Header, Modules, Product Labels, Payment & Shipping Rules, Delivery & Shipping — seven new real screens; two pre-existing **mock** screens (Modules, Product Labels) found and replaced |
| Gates | `settings_wired`, `blade_lint` rewritten; `closure_check`, `state_check`, `hooks_bound`, `js_check`, `markup_check` written |
| Marketing Pixels | Meta, GA4, TikTok — ported, hardened against order-guessing and a race condition after a second review pass |
| Wishlist heart | Was decorative on every product, everywhere — never wired to a click. Fixed, then fixed again for a CSRF header bug caught live |
| Checkout "Add" button | Recently-viewed items on the checkout page — same class of bug as the wishlist heart, same fix shape |
| Customer reviews | The product page was rendering the wrong template — the plugin's real form existed, ported verbatim, its CSS already loading, never actually included. Swapped it in and built the submission backend from scratch: captcha, rate limiting, honeypot, secure photo upload, moderation queue |

### What shipped 2.60.22 → 2.60.35

This file's own direct visibility resumes at 2.60.22 — see the progress-table note above. Everything below is from that point forward, verified against real rendering and real data before each package, not assumed.

| Area | Result |
|---|---|
| `header.blade.php` critical fix | Had its own separate hard-coded nav loop, never wired to the real `nav-bar.blade.php` — the reason horizontal scroll never went away, Super Sale highlight never showed, and dropdowns never appeared on the live site despite working correctly in every isolated preview. Fixed by including the real partial; verified through the full view-composer chain, not the partial in isolation — the same lesson as ▲17 below, recurring in a new place |
| Mobile menu | SVG chevron arrows, module-toggle autosave, nested-bar visual distinction, two-column grid preserved when a brand gains a sub-item, drag-and-drop nesting fixed (root cause was event bubbling between a row's own handler and its parent's) |
| Mega menu, desktop columns | Per-item manual column count, or automatic — roughly 10 rows per column, rounded up — instead of one fixed column count regardless of how many items a panel holds. Chunking done explicitly in PHP rather than relying on CSS multi-column auto-balance, which had been producing uneven, gappy columns |
| Mega menu, panel styling | Full round of design iteration with Rafi — column dividers, row dividers between sub-items (settled on a left-anchored fade), no divider inside a brand's own sub-list, tighter row spacing there, and a wipe-in hover effect. **Two real bugs caught mid-round**: sub-item links were bold when the approved single-column style was not (a stray `font-weight:600` nobody had noticed), and a column divider shown and approved in every preview was never actually carried into the shipped CSS across two releases — found only when Rafi compared the live result against the preview directly |
| Header search — wrong panel on first use | Traced precisely: focusing the field starts a request for the "before typing" panel (Trending, Popular Brands); if a real query resolves before that request does, the results panel never got the layout class the starter would otherwise have set, so the right-hand column silently stayed empty or, worse, showed stale content next to unrelated results. First fix wrongly cleared that column outright, based on a mistaken guess at the intended design — corrected once Rafi supplied real screenshots showing the column is meant to persist through a search, not reset. Final fix makes results actively fill that column if the starter hasn't yet, rather than either clearing or ignoring it |
| Header search — iOS zoom | The mobile search field was 13.5px; iOS Safari auto-zooms the whole page on focusing any input under 16px. Set to 16px on mobile only |
| Extended Search Results | New, off by default, admin-controlled. A query recognised as starting or ending with a real brand name narrows to that brand — "Medicube" alone returns only Medicube's own products, never padded with another brand's bestsellers just to fill the panel, which is what the *default*, unextended search still does on purpose. "Medicube Serum" splits into the brand and the leftover word, matches that brand's serums first, then widens to other brands' serums if the relevant setting is on. Handles reversed word order, partial typing, and multi-word brand names (`Beauty of Joseon`) |
| Site Search admin screen | Was a nav item pointing at an iframe file that did not exist — clicking it showed a blank page. Replaced with a real, three-tab screen (Search / Extended Search Results / Search styles & colors), and the pre-existing Search tab under Appearance → Header — itself fully built but apparently never noticed — moved here rather than duplicated |
| Settings save bug | `HeaderSettings::save()` replaced its entire stored blob with only whatever the calling screen submitted, rather than merging. Harmless while only one screen ever wrote to it; became a real data-loss risk the moment a second screen (Site Search) started writing to the same store — saving either screen could have silently reset the other's settings back to defaults. Fixed to merge; confirmed directly with both screens saving independently in sequence |
| SQL bug, category/brand search counts | `withCount` + `having` on SQLite requires an explicit `GROUP BY` that was missing; any query matching a category or brand name was throwing a real, reproducible SQL error, independent of and unrelated to the Extended Search work that surfaced it. Fixed in the same pass |

### What shipped 2.60.36 → 2.60.41

| Area | Result |
|---|---|
| `seo_engine` wiring | The SEO & Meta admin screen and `App\Support\Seo::render()` were both already fully built, using identical setting keys — nothing had ever called the render method. Real `<head>` was a bare `<title>` tag only. Wired into the shared layout for home, product, and shop/category, `title_is_final` preserving every page's exact existing title. Found six view files with a matching `$seo` placeholder, all completely orphaned — dead code, left untouched. Same SQLite `groupBy` bug from above, independently present in two more controllers (`HomeController`, `ShopController`) — was blocking the homepage entirely, fixed in the same pass. Caught a real unit-conversion bug before shipping: prices are stored as fils (AED ×100); the product schema's price field first used that raw value directly, which would have told every search engine a 126 AED serum cost 12,600 — *2.60.36* |
| SEO description quality | Researched current (2026) meta-description guidance and applied it two ways: rewrote the sitewide default to fit real length/structure guidance (148 characters, front-loaded keyword + location), and — the larger fix — gave every category page, the shop-all page, and search results their own distinct, dynamically-built description using live data (category name, real product count) instead of every page falling back to the same sitewide text, which current guidance flags as a genuine quality-signal problem, not just a missed opportunity — *2.60.38* |
| Core Updates screen — broken by my own release notes | 2.60.36's own notes described the storefront's `<head>` and `<title>` in plain words with angle brackets; the pending-update card inserted release notes as raw, unescaped HTML instead of plain text, so the browser parsed those words as real elements and cut the visible card off mid-sentence. Since the upload form only shows when nothing is pending, this also blocked uploading anything afterward. Fixed at the root — notes, error messages, and package names now properly escaped everywhere they're inserted into that screen — *2.60.39* |
| Core Updates screen — no way out | Deeper cause found while fixing the above: the main console and the standalone fallback page tracked pending updates under two entirely separate, disconnected session keys, so a package uploaded through one was invisible to the other. The fallback page — built specifically for when the main console breaks — also had no cancel option on it at all, despite its own code comments referencing Cancel as the expected way out. Both screens now see and can clear a pending package regardless of which one it came from, and the fallback page has a real, working cancel button — *2.60.40*. **Immediate recovery paths that don't depend on any patch being applied first**, for future reference: logging out fully invalidates the session and clears both stuck values; a standalone `kbb-unstick.php` recovery script (handed to Rafi directly, not shipped as part of the app) clears every session via direct DB/file access, no Laravel bootstrap or admin login required |
| Permanent patch archive | Every applied patch's zip was being deleted immediately after applying, success or not — no way to come back for a specific past patch, from this server or anywhere else. Now copied into `storage/app/kbb-patch-archive/` on successful apply, named descriptively from its own release notes rather than a bare version number, with a real Download button on every history row in both the main console and the fallback page. Added a `superseded_by` field so a release that turned out to be a mistake (2.60.33, replaced by 2.60.34; the mega-menu divider took until 2.60.32 to actually land) can be marked as such directly in the history table, not just discoverable by reading notes. Found and fixed a real bug during testing: the first version wrote the archive to a raw filesystem path that didn't match where Laravel's `Storage` facade actually resolves on this app (`storage/app/private`, not `storage/app`) — confirmed the mismatch directly against a real signed test package run through the actual apply flow, not a simulation, before fixing it — *2.60.41* |

**On GitHub**: proposed as the durable, diffable complement to the zip archive above — the zip archive is what your hosting can actually deploy (no shell access, hence zip-based updates at all), a git repo would be the full source history underneath it, readable by any future session without needing an upload. Awaiting a private repo + a repo-scoped access token from Rafi before the first push happens.

---

## The plugin, inventoried — *v2.39.0, verified 2.44.0*

7,359 lines, one file. **29 modules in 8 groups. 13 on by default, 16 off.**

Where the earlier count of 24 came from: **16 modules own their settings page,
12 defer to a settings screen that already exists in this app, and 1 has no
settings.** 16 + 8 assumed tools = 24. The twelve that link out were missed.

**Admin tools: 5, not 8** — Store Pulse, Launch Check, Import/Export, Cart
Import, Handbook. *Catalogue Audit does not exist in this build.* Schema
Inspector and Yoast live inside the SEO Engine module.

| Group | Modules | On by default |
|---|---|---|
| Checkout | freeship_bar, vat_line, cod_fee, delivery_line, coupon_hint, legal_notice, reassurance, checkout_thumbs, address_autocomplete, inline_validation, single_name | all 11 |
| Cart & mini-cart | minicart_promo, back_to_cart | both |
| Store & content | banners, mega_menu, notification_bar, product_labels | none |
| Payments & shipping | pay_ship_rules | none |
| Catalogue | product_sorting, brands, wishlist, recently_viewed, frequently_bought | none |
| Marketing | marketing_pixels, abandoned_cart, back_in_stock, newsletter | none |
| Performance | performance | none |
| SEO | seo_engine | none |

**Most of these already exist in the Laravel app as plain features**, built
directly rather than as modules. Phase 3 is therefore mostly *registering and
gating what is there*, not writing 31 features from nothing.

### The 10 still to port, as at 2.56.1 — every one checked, not assumed

Checked every remaining module against the plugin source and this codebase
before picking what to build next, rather than start on the first one on
the list. The result changes what "next" should be.

**Three are blocked on mail, which this app has never sent.** `abandoned_cart`
and `back_in_stock` both fundamentally require emailing a customer — a
recovery link, a restock notice — "via your normal mailer," per the plugin's
own description. This app has no `MAIL_MAILER` configured and has never
called Laravel's Mail facade anywhere. This is not new — it's risk ▲8,
already blocking password reset and newsletter double opt-in. Building
either module's *signup* half (a form, a database table) without the
*sending* half would be exactly the kind of switch that looks like it
works and doesn't, which this project has spent several releases finding
and fixing elsewhere. Not doing that here on purpose.

**Three are blocked on a missing source.** `legal_notice`, `inline_validation`,
and `address_autocomplete` each have zero implementation in the plugin —
every one is only a settings link pointing at **kbb-theme**, which was
never supplied. `address_autocomplete` additionally needs a Google Places
API key, which nobody has provided either.

**One looked like a quick registration and wasn't — now done.** `mega_menu`'s
panel already rendered correctly (`NavigationService`, `nav-bar.blade.php`),
but there was no admin screen to manage menu content. Built in 2.60.12: Store
→ Mega Menu, add/edit/delete/reorder across all three levels (top item,
column, link), with the mega-panel-vs-simple-dropdown distinction driven by
tree shape rather than a stored type field. Confirmed the mobile slide-in
overlay needed no changes — it already reads the same navigation data.
`brands` is still blocked on the outstanding `/brands/` URL decision already
flagged in the handover.

**`seo_engine`'s admin screen ("SEO & Meta") is real and reachable** — corrected
after ▲14 turned out to be wrong about this one — but the storefront never
reads anything it saves (checked directly: none of `seo_title_template`,
`seo_home_title`, `og_default_image` or the rest appear anywhere in
`layouts/store.blade.php`). It's a working form writing into a void. The
plugin's real module is also much larger — redirects, a Yoast importer, a
schema inspector, a catalogue audit — so this needs a dedicated pass to wire
the storefront up properly, not a quick fix squeezed in here.

**`product_sorting`** is WordPress's own `menu_order` mechanism baked into
"Default sorting" — this app has no manual product-ordering concept at all
yet, so this is a real build (a position field, a drag-reorder admin UI),
not a gate.

**`performance` doesn't port at all.** The plugin's version is entirely
WordPress-specific — throttling the WP heartbeat API, dequeuing WP asset
bloat — none of which exists in this app to strip out. What performance
work makes sense here (lazy-loading, preconnect hints) is already done in
several places, unconditionally, not behind a module switch; porting a
WordPress-shaped toggle for a WordPress-shaped problem this app doesn't
have would be inventing a module, not building one.

**Net: nothing here is safely buildable without either new information
from Rafi (mail provider credentials, the kbb-theme source, the `/brands/`
URL, a Google Places key) or a properly scoped session of its own
(`product_sorting`, `seo_engine`).** Flagging
this plainly rather than picking the least-bad option and building
something half-right.

---

## Phase 1 — Foundation

- [x] Laravel skeleton, routing, layouts · versioned ZIP updater · settings service pattern
- [x] Demo content system — *2.19.0*
- [x] Build gate suite — **21 gates** — *ongoing*
- [x] Admin console never cached — *2.42.2*

## Phase 2 — Storefront chrome

- [x] Header, 58 settings, 7 tabs — *2.27.0 → 2.34.0*
- [x] Mobile header: spacing, search field, divider — 21 settings — *2.42.0 → 2.43.1*
- [x] Mobile menu sheet, 26 settings · footer · menu icon
- [x] Announcement bar — exists; **to be registered as `notification_bar`**
- [x] Mega menu — `NavigationService` renders it correctly; registered as `mega_menu`
  and given a real admin screen (Store → Mega Menu) in 2.60.12 — add/edit/delete/reorder
  across all three levels, mega-panel-vs-dropdown driven by tree shape, not a stored type
  field. Mobile slide-in overlay confirmed to need no changes — reads the same data already
- [x] `header.blade.php` was never actually wired to the real nav-bar partial it looked like
  it used — a separate, hard-coded, scrollable nav loop had been live the whole time. This is
  why horizontal scroll, missing dropdowns, and a missing Super Sale highlight all persisted
  on the real site despite every isolated preview of `nav-bar.blade.php` looking correct —
  *2.60.24*
- [x] Desktop mega panel: manual or automatic column count per item (roughly 10 rows per
  column, rounded up, instead of one fixed count for every panel), explicit chunking in PHP
  rather than CSS auto-balance, plus a full styling round with Rafi — column and row dividers,
  hover state, spacing, font weight matched to the approved single-column style — *2.60.29 →
  2.60.32*

## Phase 3 — Module framework ← **in progress**

- [x] Module registry — 31 modules (29 plugin + 2 native), 9 groups — *2.44.0*
- [x] Per-module on/off and per-device visibility — *2.44.0*
- [x] Admin screen: Store → Modules, filter, bulk actions, two-column layout — *2.44.0 → 2.44.2*
- [x] Rewired onto the pre-existing `module_toggles` table instead of a parallel store — *2.45.0*
- [x] Status per module (`live` / `elsewhere` / `todo`) so the screen never offers a dead switch — *2.46.0*
- [x] Eye-hover preview: which page, which strip, in one sentence, per module — *2.44.1*
- [x] Direct link from a module row to its settings screen — *2.44.3*
- [x] **Product Labels** ported — sold-out/sale/new/bestseller, precedence order — *2.47.0*
- [x] **Payment & Shipping Rules** ported — COD window, enforced at 3 entry points — *2.48.0*
- [x] **Delivery & Shipping** screen for the zone charges — *2.49.0*
- [x] **Extended delivery** — per-country rates + detection, off by default — *2.50.0 → 2.50.1*
- [x] **vat_line**, **cod_fee**, **single_name** wired to Store → Ecommerce → Checkout — *2.53.0*.
  `single_name` was a broken control before this: the toggle saved but nothing read it, and the
  checkout always rendered one Full-name field regardless. The backend's own `splitName()` had
  quietly supported both shapes all along — only the form never offered the other one.
- [x] **marketing_pixels** ported — Meta Pixel, GA4, TikTok, all four hook sites (page load, product
  view, begin checkout, purchase) — *2.56.0*. Purchase fires exactly once per order, guarded by a
  new `pixels_fired_at` column, matching the plugin's own meta-flag idea. Built as its own screen
  rather than folded into the existing "Meta & Facebook" screen, which is unrelated design-preview
  work for a larger Conversions-API/catalog-feed feature and was left untouched.
- [ ] Port the remaining 10 `todo` modules — see the list in §2 below
- [ ] Per-module settings schema shared with the admin renderer, for the modules that own theirs

## Phase 4 — Search

- [x] Suggestion panel, three layouts, trending, term counting, density
- [x] Panel close no longer clicks what is underneath — *2.43.2*
- [x] Fixed a real bug in the panel's two-column layout: the right-hand column (Trending /
  Popular Brands) is meant to persist through a search unchanged, but the results panel had
  no reliable way to inherit it if the starter's own request hadn't resolved yet, so it could
  render empty or, briefly, with stale content — *2.60.33 → 2.60.34*
- [x] iOS Safari auto-zoom on focusing the mobile search field — was 13.5px, under the 16px
  iOS requires — *2.60.33*
- [x] **Extended Search Results** — recognises a real brand name in the query and narrows or
  splits matching accordingly, off by default, four settings (strict brand-only mode, partial
  multi-word brand matching, widen-to-other-brands for a brand+word query). New **Site Search**
  admin screen (Search / Extended Search Results / Search styles & colors), replacing a nav
  item that had pointed at a non-existent iframe file the whole time — *2.60.35*
- [~] Search results — `/shop/?s=` works; **decide whether SE-05 wants a distinct page**
- [x] Filters and sorting on the results page — was already built and never ticked. `SearchController::page()` delegates to `ShopController::index()`, so the results page *is* the shop page: `Facets` handles cat, brand, price, sale, instock and orderby, and `shop.blade.php` renders the sidebar, chips and sort control — *verified 2.60.91*
- [x] Synonyms and misspelling tolerance — `App\Support\SearchTerms` expands a query before matching, on the results page and in the autocomplete dropdown. Narrow by design: spacing, hyphens, a plural trim, no edit distance, and nothing under four characters is expanded to — *2.60.77, autocomplete 2.60.90*. Partially covered by Extended Search's brand
  splitting, but not general misspelling tolerance

## Phase 5 — Product cards and grids

- [x] 28 skins · 34 settings · columns per device · shortcode builder
- [x] Full product titles — *2.40.3*
- [x] Card skin preview inside the shortcode builder — was already built and never ticked. `#scPrev` renders `skinCard(SC.skin ?? grid_skin)` under a "Card style used" note — *verified 2.60.91*
- [x] Quick view — modal from any grid card with image, brand, price including sale rules, stock, short description and add-to-cart. Server-rendered fragment so money formatting cannot drift from the card; hidden on touch devices — *2.60.72, module switch 2.60.73*

## Phase 6 — Product page

- [x] Gallery, tabs, badges, price, bundles, 17 sections
- [x] Sticky add-to-cart — *2.38.0 → 2.39.0*
- [x] **Reviews submit flow — stale note, already fully built and working.** Checked
  directly rather than trusting the old claim: `reviews.js` is imported and called in
  `app.js`, and `Store\ReviewController` has a complete submit endpoint — rate
  limiting, a honeypot, a real signed-token arithmetic captcha, validation, photo
  upload. Verified with a genuine end-to-end submission (real captcha token, correct
  answer, real review content) — a Review row was actually created, response was the
  real "awaiting approval" message. Must have been built in a session this plan was
  never updated to reflect
- [x] Related and recently viewed — was already registered and never ticked. Present in `ModuleRegistry`, settings at Appearance → Cart panel — *verified 2.60.91*

## Phase 7 — Account area

- [x] Sign in, register, dashboard, orders, addresses, forgot, track order
- [x] Field component, password strength, autofill, sum guard, account panel
- [x] ▲ **Password reset actually sends an email — and the plan was contradicting
      itself about it.** This blocker sat here while the very next section ticked
      "Password reset and email verification — 2.60.116" off. Both halves had
      worked since then. What had genuinely never existed was *evidence*: no
      Message-ID, no record the owner could read, and — the real gap — **no
      unsubscribe path anywhere in the codebase at all**. Closed at 2.60.199
- [x] Address book: add, edit, delete, default — the `addresses` table, the model and `Customer::addresses()` already existed; the page was a 12-line stub that printed "No addresses saved yet" without ever querying. One default per type, enforced in two statements; deleting a default hands it on — *2.60.72, module switch 2.60.73*
- [x] **Order detail page with line items and status history — plus a much bigger find
  along the way.** Checking this surfaced that the entire `/my-account/*` route group
  was gated behind `guest:customer` middleware, meaning every logged-in customer was
  redirected away from their own account pages — confirmed directly with a real
  authenticated request to `/my-account/orders` before touching anything: 302 to the
  homepage, not the order list. `index()` itself already branched on auth state
  internally to show dashboard vs. login form, which is what exposed the mismatch: the
  route middleware was fighting the controller's own logic. Restructured into three
  correctly-scoped groups (open to everyone, `auth:customer`, `guest:customer` — only
  login/register belong in the last one) and verified all three cases individually.
  Also found `$order->number` doesn't exist anywhere in the schema (it's
  `order_number`) on both the dashboard and the orders list, so every customer was
  seeing their internal database id instead of their real order number — fixed in both
  places. Built the detail page itself: real line items, a real price breakdown
  (subtotal/discount/shipping/fees/total), real delivery address, strictly scoped to
  the logged-in customer's own orders — verified with two real seeded customers that
  one cannot view the other's order (404, not a data leak) — *2.60.57*
- [x] Track-my-order against real orders — was a pure stub: the form existed and
  submitted, but the controller returned no data regardless of what was searched for.
  Wired to a real lookup requiring order number **and** email together, deliberately
  never number alone, so it cannot be used to enumerate other customers' order statuses
  by guessing. Verified: correct number+email shows the real order; a mismatched email
  against a real order number shows "not found" with no order data anywhere in the
  response, only the safely-echoed input the visitor typed; an empty, unsubmitted form
  shows no error — *2.60.57*

- [x] **Account area repaired** — `/my-account/` was serving the **login form to signed-in
      customers**: both controllers resolved the shopper through `auth()->guard()`, the
      *default* guard, which is `web` (the admin users table). Inside the `auth:customer` group
      it resolved correctly by accident, because Laravel's middleware calls `shouldUse()` on the
      guard it matched; `/my-account` carries no middleware, so there it did not. `actingAs()`
      masks this exactly, which is why no test caught it. Order detail also printed address keys
      checkout never writes, so the **recipient's name was blank on every order**, and read
      orders through the query builder, so a trashed order was still served. Track order was an
      order-number oracle — unthrottled, with distinguishable answers — *2.60.116*
- [x] **Password reset and email verification** — the forgot form had been posting to a route
      that did not exist for as long as the view has existed. A reset clears `legacy_password`
      in the same save, without which the leaked WordPress password keeps working. The customers
      broker pointed at a table keyed by email alone, so a customer and an admin sharing an
      address could redeem each other's token. Both open Laravel advisories are mitigated rather
      than relied on: the public form does not use the `email` rule, and links carry an HMAC over
      a claim containing no URL instead of a Laravel signed URL — *2.60.116*

## Admin — Order detail page  *(new — built from Rafi's own WooCommerce reference screenshot)*

- [x] **Status list expanded** to `draft`/`pending`/`processing`/`onhold`/`shipped`/
  `completed`/`cancelled`/`refunded`/`failed`, matching what Rafi actually needs to see.
  Adding `shipped` had a real, easy-to-miss consequence: a shipped-but-not-yet-completed
  order is genuinely a paid, real order, so it now counts toward revenue and order-count
  totals the same way `processing`/`onhold` already did — verified directly, not assumed
  — *2.60.58*
- [x] **The detailed order page itself.** A genuinely important discovery mid-build: a
  full, separate backend (`AdminOrderController`) already existed, built from this exact
  reference screenshot — real notes, a real `refunds` table (not a bolt-on total
  column), soft-delete, address editing, the honest "not tracked yet" attribution
  panel, and the same real-vs-placeholder action split already agreed. Caught **before**
  shipping anything, but only after already starting to duplicate it — a redundant
  `customer_ip` column had been added when the real one (`ip_address`) already existed
  and was already being captured at checkout. Stopped, reverted every duplicate/
  conflicting change (deleted the redundant migration, reverted the model and checkout
  edits, restored the method that would otherwise have been clobbered), then thoroughly
  tested the *existing* backend before trusting it — every endpoint (order detail,
  notes, partial refund, over-refund rejection, placeholder-action rejection, cancel,
  duplicate, trash, restore, address update) verified individually with real seeded
  data matching the reference screenshot (Nina Zandnia, order #32187, Tabby payment).
  Built the missing other half: the actual card-stack page, every section open by
  default (Rafi's choice, collapsible but not collapsed) — order details/billing/
  shipping, items with real images and totals, notes with add, order attribution
  (honestly empty, not faked), order actions (real actions run, placeholder actions
  refuse with the real reason, never a fake success), customer history, and Invoice/
  Packing with visible-but-not-wired PDF placeholders per the agreed scope. Verified
  interactively end to end, not just that it renders: refund form submits the real
  amount and reason; a note save sends real content; a status change sends the real new
  status; a placeholder action shows the real, honest backend rejection message in the
  actual toast; trash requires and respects a confirm dialog — *2.60.59*
- [ ] Downloadable-product permissions — deliberately skipped, not relevant to a
  physical-skincare shop
- [ ] Order source/device/session-page-view tracking — deliberately deferred; the panel
  shows "Not tracked yet" honestly rather than fabricated numbers
- [ ] Real PDF generation for Invoice/Packing slip/Delivery note/Shipping Label/
  Dispatch Label — buttons are real, visible placeholders; wiring actual document
  generation is a separate, later piece of work
- [x] **Visual revision, requested after first review** — billing and shipping split
  into their own explicit two-column card (previously a 3-column grid mixing them
  with order status/customer). A genuine color system replaces the flat, single-accent
  admin theme: each card gets its own accent (indigo for overview, violet for
  addresses, emerald for items, amber for notes, cyan for attribution, rose for
  actions, blue for customer history, slate for invoice/packing), shown as a left
  border and a small colored dot next to the heading — restrained rather than a full
  gradient wash per card, keeping the boldness in one clear signal instead of
  decorating everything. Also dropped the ALL-CAPS field labels throughout in favor of
  sentence case, per the same "commonest AI-generated tell" the design skill itself
  flags. Customer history now reads like a real KPI panel — large, bold, colored
  numbers rather than small uniform text. Full regression-tested after the rewrite,
  not just visually reviewed: refund, note-add, status update, and address-edit all
  reconfirmed working with real data before shipping — *2.60.60*
- [x] **Layout — final direction chosen and shipped live.** Went through several real
  rounds after 2.60.60, each with actual working HTML previews rather than
  descriptions: three grid-alignment directions, then five genuinely different
  structural concepts at Rafi's request (a status-stepper pipeline, a receipt/ledger,
  a dark ops console, a chronological timeline, an editorial big-number layout) after
  he rejected the first round outright, then back to matching his own original
  WooCommerce reference screenshot exactly once he clarified that was the actual
  target — three header/divider treatments on that matched layout, then one refined
  further (real SVG icons instead of text glyphs, softer shadows) at his request, then
  three more resolving his specific asks (real column dividers, restrained font sizes,
  light-colored box headers), and finally the chosen one (amber headers) with its
  input fields deliberately strengthened (border, tint, inset shadow) so they read as
  real interactive fields. **This final design is now live**, not just a preview —
  rebuilt directly into `AdminOrderController`/`app.blade.php`, replacing the previous
  rainbow-left-border card system with the approved amber-header, column-divided,
  prominent-field treatment. Every existing interaction re-verified working after the
  rewrite: status update, refund, add-note, add/edit/remove item, actions, trash —
  none silently broke in the visual rebuild
- [x] **VAT line added to the order summary — inclusive, display-only, matching
  checkout exactly.** Checked first rather than guessing at a formula: a real,
  already-built `VatDisplay` service exists precisely for this (decision D-64 — VAT is
  a display line only, 5% inclusive, computed as `total × rate ÷ (100 + rate)`, never
  stored on the order and never added to the total). Reused that exact service rather
  than reimplementing the math, so the admin figure can never quietly drift from what
  the checkout page itself shows. Verified the calculation directly against the
  documented formula (AED 239 → AED 11.38, matching exactly) and confirmed the line
  correctly disappears when VAT is turned off in settings
- [x] **Checked the checkout page for anything missing from the order summary.**
  Read through the real checkout summary partial line by line: subtotal, coupon/
  discount, delivery (or free), a conditional COD fee, total, then the VAT line. Every
  one of those already had a place in the admin order summary except VAT, which is
  the piece just added above — nothing else is missing
- [x] **Edit order — add products, adjust pricing, partial refund, admin status
  update.** Checked first rather than assuming: partial refund and admin status
  update were already fully built and tested in the 2.60.59 pass — refund already
  supports any amount up to the remaining balance via a real `refunds` sum, and the
  status dropdown already writes real changes. The genuinely new piece was editing
  order contents: add a product (search reuses the existing catalog-search endpoint,
  no new search built), adjust an item's quantity or price inline, remove a line item
  (blocked below one remaining item), all recalculating the order's real subtotal/
  total server-side rather than trusting anything sent from the browser. Editing is
  deliberately gated to orders that haven't shipped yet (`draft`/`pending`/
  `processing`/`onhold`) — once packed, changing line items doesn't reflect reality;
  a shipped or closed-out order shows a plain, honest "no longer editable" message
  instead, with no edit controls rendered at all. Verified every path with real data:
  add, quantity change, price change, remove, blocked last-item removal, and blocked
  editing on a shipped order — all individually confirmed correct before the frontend
  was even built, then the frontend itself was retested end to end afterward — *2.60.62*
- [x] **Alignment fixes — Items and Order notes specifically, exactly as reported.**
  Measured actual pixel positions rather than eyeballing a screenshot to find the real
  causes, not just something that looked plausible. Two separate, genuine bugs: the
  `.pad` padding class only ever applied as the compound selector `.card.pad`, but
  these two cards' inner wrapper used the plain `.pad` class alone — so it silently
  matched nothing and both cards had zero internal padding, content touching the card
  edge directly. The sidebar cards were never affected only because each one happened
  to carry its own inline padding override already; Items and Notes were the two that
  didn't, which is exactly what was reported. Second, separate bug: the order-totals
  summary was right-aligned to the full table width, including the Remove-item
  button's column, so it visually overshot past where the table's own "Total" column
  sits above it. Fixed both, then measured again rather than trusting the fix by eye —
  confirmed real padding now present (20px/22px) and the totals block's right edge
  now lines up with the Total column's right edge instead of the far edge of the
  Remove column. Pure CSS; re-confirmed no backend or interactivity regression — *2.60.63*
- [x] **Demo Content — a new Safety screen for testing with real, working sample
  data instead of an empty store.** Requested because there was no demo order to
  actually try the order detail page against. Designed first and shown before
  building anything: a light-hearted grid of per-type cards (Orders, Customers,
  Products, Pages, Blog Posts, Reviews, Mega Menu) each with its own Import/Remove,
  plus Import All / Remove All at the top — approved before a line of backend code
  was written. Every sample record is tracked in a new `demo_seed_log` table the
  moment it's created, so Remove deletes exactly those records and nothing else —
  it can never touch a real order, customer, or product even sitting side by side
  with sample data. Checked first rather than assuming a blank slate: the app
  already had a separate, pre-existing placeholder catalogue from initial setup
  (`DemoCatalogueSeeder`, a handful of static pages, a default menu) — confirmed
  this new system correctly leaves all of that alone rather than colliding with it.
  Tested hard, not just once: two full import-then-remove-then-import-again cycles
  confirmed the store returns to its exact original state every time. That testing
  caught two real bugs before shipping — removing certain sample records only
  soft-hid them (they use SoftDeletes) rather than truly deleting them, which
  silently blocked a second import with a duplicate-entry error on re-use of the
  same email/slug; and a removal count under-reported itself when the database
  cascade-deleted related rows on its own (a product's reviews) before the count
  got to them, even though the data itself was genuinely fully gone. Both fixed
  and re-verified. Also caught and fixed two build-time mistakes during the
  frontend wire-up before they ever reached testing: a duplicate declaration of
  the panel's shared icon helper that would have broken the entire admin console,
  and an editing accident that deleted an unrelated function's own declaration
  line while inserting this one. Verified the whole thing end to end through the
  real web address and full HTTP stack — routing, session, CSRF — not only through
  internal checks, and confirmed a sample order renders correctly on the order
  detail page, the original reason this was asked for — *2.60.64*
- [x] **Fixed: every Import button failed with "Could not import that" on the
  real server, immediately after 2.60.64 went live.** "When I hit anything"
  was the key detail — every type failing identically pointed at something
  hit before any type-specific logic runs, not a bug in one generator.
  Diagnosis: if the demo_seed_log migration was ever skipped or failed
  silently during deploy, every endpoint would throw on the missing table,
  and on the real server (`APP_DEBUG` off) Laravel returns an HTML error
  page for an uncaught exception rather than JSON — the frontend's response
  parsing then throws its own separate error, and the generic catch message
  swallowed that entirely, hiding the real cause behind an unhelpful "could
  not import" for every single button. Fixed on both ends: the controller
  now self-heals the table if it's missing, the same pattern already used
  in `MegaMenuApiController::ensureColumns`, and every endpoint is wrapped
  so a genuine failure always comes back as real JSON with an actual
  message rather than an HTML error page; the frontend now shows that real
  message instead of a canned one. Reproduced the exact reported scenario
  before believing the fix — a fresh database with every migration applied
  except this one — confirmed import correctly self-heals and succeeds
  where it previously would have failed, then re-ran two full import/
  remove cycles against that same recovered state to confirm nothing else
  regressed — *2.60.65*
- [x] **Fixed: 2.60.65's improved error message correctly revealed the real
  problem — "The route admin-api/demo-content/orders/import could not be
  found."** A genuine 404, on a route confirmed present in both the 2.60.64
  and 2.60.65 packages themselves (checked byte-for-byte inside the actual
  delivered .zip files, not just the local working copy) — meaning the
  route genuinely exists in `routes/web.php` on disk but wasn't being
  matched at runtime. This codebase already has documented history with
  exactly this failure mode: a compiled route cache file silently takes
  absolute priority over the real `routes/web.php` the instant one exists
  on disk, which is why `route:cache` was removed from the update process
  entirely after it caused a real outage once before (see the doc comment
  on `UpdateRunner::down()` and on `2026_09_10_100000_clear_caches_2_60_48`).
  The update process already runs `route:clear` on every release, so this
  should not be reachable through the normal path — but a second,
  independent migration now force-clears the compiled route cache (and
  config/events/services/view caches alongside it) as a defensive backstop,
  matching the codebase's own established pattern for this exact class of
  problem rather than inventing a new one. Verified directly: created a
  real stale route cache file, ran this migration against it, confirmed
  the file is gone afterward — *2.60.66*
- [x] **Resolved — see the entry below.** This line is a step in the diagnosis, not an outstanding task; the `[x]` beneath it closes it. Left ticked open, it read as a live bug for weeks — *verified 2.60.91*. Rafi
  fetched the real server log directly — an old, unrelated MegaMenu column
  error and update-archiving warning from Sept 9–10, nothing from the actual
  failure, which is expected (Laravel doesn't log plain 404s by default) but
  ruled out one theory without confirming another. Stopped guessing and
  shipped a direct diagnostic instead: a new browser-visitable route,
  `/{admin path}/kbb-route-check?search=demo-content`, that lists exactly
  what `Route::getRoutes()` has registered right now — the real, live route
  table Laravel is actually dispatching against, not a reading of the
  source file that could disagree with what's really loaded. No SSH or
  file access required, matching the existing `kbb-health-log` pattern.
  Verified it correctly lists all 5 demo-content routes including the
  failing POST import route in a clean local test — awaiting what it shows
  on the real server, which will say definitively whether the route is
  missing there or something else is happening — *2.60.67*
- [x] **Found and fixed the real root cause.** Rafi's diagnostic-page result was
  decisive: all 5 demo-content routes were genuinely registered on the real
  server, identical to a clean local test. That ruled out registration and
  caching entirely and pointed at something in the actual request path itself.
  Traced Laravel's exact error text to its source in the framework
  (`AbstractRouteCollection`) — confirmed it is the genuine, standard message
  for "no route at all matches this request," which only makes sense if the
  browser's real request path differed from what was assumed. The live site
  runs at a subdirectory (`easywebsol.com/kbb-upgrade/`), not the domain
  root — and the new code used a hardcoded, leading-slash path
  (`/admin-api/demo-content/...`), which resolves against the domain root
  and silently points at a URL with no route at all on a subdirectory
  deployment. The rest of the admin panel already had the correct pattern
  for this exact problem (`pApiBase()`, computing the base from the current
  page's own path rather than assuming the domain root) — matched it exactly
  instead of inventing a new approach. Verified the underlying logic
  directly against the real deployment shape (`/kbb-upgrade/admin` →
  `/kbb-upgrade/admin-api`, confirmed correct) and confirmed the full click-
  to-import flow now builds the correct, complete request path end to end
  — *2.60.68*
- [x] **The subdirectory-path bug was bigger than Demo Content — it was the
  entire order detail page.** Rafi reported Demo Orders imported successfully
  but the real Orders list showed completely empty, no error. Traced it: the
  Orders list's own fetch call had the exact same hardcoded-path bug just
  fixed in Demo Content, except it silently swallows any failure into an
  empty list (`catch(e){ ORD=[]; }`) rather than showing an error — explaining
  why it looked empty instead of broken. Checking further found this wasn't
  isolated: `api()`, the shared helper used throughout the entire order
  detail page, had the same flaw, plus 7 more raw `fetch()` calls inside
  it specifically (address editing, item add/update/remove, refund, actions)
  that bypass `api()` entirely and each had their own copy of the same bug.
  In effect, every interactive feature built into the order detail page
  this session — not just Demo Content — has likely never actually worked
  on the real, subdirectory-deployed site, only in local testing where no
  subdirectory was ever in play. Fixed at the root: one shared helper
  (`fixAdminApiUrl`), matching the exact pattern already proven correct
  elsewhere in this file (`pApiBase()` and its siblings), applied inside
  `api()` itself so every existing caller is corrected automatically, and
  explicitly added to each of the 7 raw `fetch()` calls that don't go
  through `api()`. Verified the list, detail, and refund calls all now
  build genuinely prefixed URLs instead of bare `/admin-api/...` ones — *2.60.69*
- [x] **Fixed: Demo Blog Posts 404'd on the actual public blog.** Checked the
  real controllers rather than guessing: both `PageController::blog()` and
  `::post()` correctly filter strictly to `status = 'published'` — draft
  posts don't appear on the listing and 404 if visited directly. The demo
  post generator created its posts as `draft`, which directly contradicts
  what the Demo Content card itself promises ("so the blog is not empty") —
  a genuine mismatch between the card's stated purpose and what the code
  actually did, not intended behavior. `PageController::show()` has the
  identical filter for pages, so Demo Pages had the same latent bug even
  though it hadn't been hit yet. Fixed both generators to create their
  content as `published`. Verified against the real, unmodified public
  controllers, not just the database rows: the blog listing now returns
  the 4 demo posts instead of zero, and both a demo post and a demo page
  render successfully where they previously threw a 404. Note for next
  session: existing demo posts/pages already imported before this fix are
  still sitting in the database as `draft` — Rafi will need to Remove and
  re-Import Demo Blog Posts (and Demo Pages) once this version is live for
  the fix to take effect on that already-created data — *2.60.70*
- [x] **Found and fixed a completely separate, pre-existing issue — the
  admin's own "Blog" and "Posts" screens were never built.** Rafi's 404
  screenshot showed this happening inside the admin panel itself, not on
  the public site — the 2.60.70 fix was correct but irrelevant to this.
  Traced it: the sidebar's Blog and Posts links load an iframe pointing at
  a standalone file (`kbb-admin-blog.html`) that doesn't exist anywhere in
  the codebase — confirmed every other `FRAME_SRC` entry (Orders, Payments,
  Analytics, Settings, Customers, SEO, Media, eight in total) is the same
  kind of stub, left over from an earlier design phase. Orders, Customers,
  and a few others already got real screens at some point, but not through
  editing that original dispatch table directly — found a second, later
  `window.go` override in the file that intercepts specific ids and
  replaces the dead iframe with a real screen immediately after. Built the
  same way: a new read-only `PostsApiController`, and a `renderPosts()`
  screen listing every post with its status and a working Preview link to
  the real, live page — added to that exact same interception pattern
  rather than touching the original, riskier dispatch code. Verified in a
  real browser that both the Blog and Posts sidebar links now show the
  real table with no iframe and no 404 — *2.60.71*

## Phase 8 — Cart and checkout

- [x] Cart drawer, live throughout — *2.39.x → 2.40.1*
- [x] Cart page: quantity, remove, coupon, totals — *2.39.x*
- [ ] ▲ **Checkout styling** — agreed to move checkout to `<x-field>`, **requires D-35
      amended**, and to be tested against a real order
- [x] Coupons and gift notes end to end — coupons were already complete including removal (`CartController::coupon` handles both paths) and never ticked. Gift notes added with their own column, a priced gift-wrap option controlled at Store → Delivery & Shipping → Gift wrapping, and the dead `customer_note` path wired at both ends — *coupons verified 2.60.91, gift notes 2.60.78, pricing 2.60.82*
- [x] Guest checkout → account creation — checkout already made a `Customer` row for every guest; it had no password. An optional tick sets one, written only into a blank and never over an existing password, with `legacy_password` counting as set so the 3,712 imported customers cannot be trampled. Declines silently to avoid an enumeration oracle. No email, so it does not wait on the mail decision — *2.60.76*
- [x] **Placing an order works again** — it had not since 2.60.85. The `orders` table was
      missing `is_gift`, `gift_note` and `gift_fee`, so every submission failed on the insert
      with a 500. The columns were missing because **no update package has ever run a
      migration**: `UpdateRunner` gates them on a `migrations` flag in `update.json` that
      `hasMigrations()` reads and nothing ever set, so migration files were copied to the
      server and left on disk for this project's entire history. Repaired across all nine
      tables that a broken `->after()` chain could have left incomplete, and the builder now
      sets the flag — *2.60.111 → .114*. First confirmed live order: **#10009**
- [x] **Order-received page — full redesign** — sign in or your orders, track the order, go
      home, and the full itemised summary with show/hide past three lines (a plain `<details>`,
      so the toggle cannot break). A guest is asked to set a password rather than offered a
      sign-in they cannot use, and the card replaces that step once they finish — driven by the
      session, never by whether the address already has an account, which would be an
      enumeration oracle. Rebuilding it also closed a live leak: the page looked orders up by
      number from the query string with **no ownership check at all**, and numbers are
      sequential — *2.60.115*
- [x] **Mobile checkout: "View full summary" and the Browsed tab** — both dead. The handler set
      `open` on `#kbbPanels` while the CSS keys the expansion off `.summary.open`, so the class
      landed on an element no rule matches and the panel stayed clamped at its 148px peek. One
      root cause for both — *2.60.115*
- [x] **Add from Browsed, silently** — no drawer, no tab switch, a small confirmation, and the
      row goes. Summary, payment options, mobile bag strip with its free-delivery bar, and the
      browsed count all update from one request, because `PayShipRules` measures its COD window
      against the total and a single add can withdraw the selected method — *2.60.116*

## Phase 8b — Admin screens, engine parity and the import foundation *(2.60.117 → .125)*

- [x] **Payments: capture and refunds, all four gateways** — per-gateway capture windows
      (Tabby 30d, Tamara 180d, Stripe 7d, COD none), each honoured by reading the provider's
      live status before acting rather than trusting a stored one. Refund ceiling computed
      inside a locked transaction from our own columns, never from the request. A *sequential*
      double-refund gap was found by its own test and closed with a recency guard — that would
      have been live money, twice — *2.60.117*
- [x] **Public API sweep** — quiz leads were editable by counting ids, unmoderated reviews were
      discoverable, review votes could be stuffed without limit, and hidden products could be
      reviewed. All closed — *2.60.117*
- [x] **`GET /api/cart/debug` was world-readable** and returned the five most recently active
      carts **site-wide** with their ids, `customer_id`s and token prefixes. Its own doc comment
      said "nothing sensitive" while doing it, which is how it survived review. Gated on the
      route, so an edit to the controller cannot drop it — *2.60.117*
- [x] **`/skin-quiz/` and `/reviews/` returned 500 to every visitor** — both routes pointed at
      controller methods that were never written. The Blade views existed the whole time, so
      nothing in the tree looked broken — *2.60.117*
- [x] **SEO Engine and Product Sorting switches made real** — both were marked `live` in the
      module registry while **nothing in the codebase read either one**. Verified independently
      before merging, which made the accompanying migration protective: without it, adding the
      reads would have stripped every canonical, OG tag and JSON-LD node off the live catalogue
      and reordered every listing, silently, on apply — *2.60.117*
- [x] **Cart drawer empty on the first add of a session** — the drawer's view composer decided
      "no cart" from a cookie that `CartService::create()` only queues onto the *response*, then
      painted the empty state over the correct contents the controller had already built. The
      same overwrite was silently re-breaking the Browsed list on every add — *2.60.118*
- [x] **Checkout quantity and discount code apply in place** — both did a full page reload,
      losing every field already typed, the scroll position and the chosen country. They posted
      to the generic cart endpoint, which renders the mini-cart and the cart page — neither on
      screen at checkout — so a reload was the only way to show new figures — *2.60.118, .122*
- [x] **Mobile checkout scrolled sideways by 7px** — two free-delivery confetti particles fly
      past the edge and, finishing at `opacity:0` rather than removed, kept occupying space for
      the life of the page. Measured by two lanes independently — *2.60.118*
- [x] **Cart heading count floated in dead space** — its `lead` class collides with the global
      form-field icon rule (`position:absolute`). The fourth layout bug in this project caused
      by a generic class name; fixed by renaming, not by overriding — *2.60.118*
- [x] **Store → Customers, rebuilt** — the old screen had no search, filters, sorting or paging,
      and its "Emirate" column read a field that has never existed on the table, so it was blank
      on every install. Now aggregated in SQL at a fixed query count, with guest and imported
      rows handled, and orders not linked to any customer counted in a banner rather than
      dropped — *2.60.118*
- [x] **Orders record the payment method's name** — nothing ever wrote `payment_method_title`
      except the demo seeder, so every real order printed the raw gateway id and the account
      page showed customers `cod` — *2.60.118*
- [x] **Floating bottom menu behind a switch**, off by default, at Store → Modules → Store &
      content — *2.60.119*
- [x] **Real MySQL in CI, and the three faults only it could see.** The suite had always run
      SQLite while production runs MySQL, so an entire class of bug was invisible. Running it
      against MySQL 8 failed **110 tests**. Fixed: `payment_providers.config` and
      `mail_credentials.config` are JSON columns carrying encrypted values, which are not valid
      JSON — **every save of a gateway key or SMTP password failed outright on the live server**;
      the Customers summary inherited the page offset and read zero from page two on (wrong on
      every engine); and MySQL 8's microsecond datetimes made every never-ordered customer show
      1 Jan 1970. MariaDB passed a build MySQL failed — it is not a stand-in — *2.60.123*
- [x] **Store → Orders, rebuilt** to the Customers standard: chips with counts over the real
      status vocabulary (an imported `wc-tamara-p-failed` gets its own chip rather than being
      prettified away), date and value ranges, CSV export, and bulk actions that refuse to take
      an order out of revenue without naming each one. `refunded` cannot be set by hand at all,
      because `PaymentRefunder` writes it when money actually moves — *2.60.124*
- [x] **The admin console scrolled sideways on every screen at phone width** — the shared top
      bar is one non-wrapping flex row ~540px wide, so the untouched dashboard overflowed by
      164px and no individual screen could have fixed it — *2.60.124*
- [x] **Import foundation** — `docs/IMPORT-READINESS.md`, external-id columns, and the unique
      constraints a re-run depends on. The repair migrations had re-added `wc_order_id` and
      `wp_user_id` as **bare columns with no unique index**, so a readiness check would answer
      "yes" while the importer's second pass inserted a complete duplicate of every order.
      `customers.orders_count` / `total_spent` / `last_order_at` are retired in place as
      deliberately unmaintained. `kbb:import-catalog` does nothing and never did; its 60 lines
      of dead code would have written AED 99.50 as 99 fils — *2.60.124*
- [x] **One shared aggregate helper** (`App\Support\AggregatesQueries`) — the same defect
      shipped to production twice. `selectRaw()` appends rather than replaces, and
      `applySort()`/`forPage()` mutate the builder, so a summary built from the same instance as
      the page inherits its columns, its ORDER BY and its OFFSET. Three failures from one cause:
      MySQL 1140 twice over, and a surviving offset that makes every summary tile read zero from
      page two — wrong on every engine, and silent — *2.60.125*

### What this phase cost, and the rules that came out of it

- **A cumulative package that runs no migration resets no OPcache.** 2.60.121 shipped the
  correct fix and the server kept executing the previous compiled class, making a correct fix
  look like a wrong one. Every package that changes a PHP class now ships a `clear_caches_*`
  migration, not only the ones that add routes — and the packager warns when one is missing.
- **A diagnostic beats a guess.** Two releases were spent on a 500 that reported only "Server
  Error". The endpoint now returns the driver's own message, and the controller carries a BUILD
  constant so "the fix is wrong" and "the fix is not running" can be told apart from a
  screenshot.
- **Judge the SQL a request issues, not the answer it returns.** `tests/Support/SqlShape.php`
  asserts statement shape via `DB::listen`, which is dialect-independent and therefore catches
  MySQL-only faults from the SQLite suite.
- **A guard is decor until it fails.** Every guard added in this phase was verified by reverting
  the fix and watching it go red.

## Phase 8c — Telling the truth  *(2.60.126 → .185)*

Sixty packages, and they nearly all share one shape: **a screen or a document
confidently stating something the code does not do.** None of these were
crashes. Crashes get reported; these were found by looking for disagreements
between two things that must agree — a control and its writer, a renderer and
its feed, a claim and its mechanism, a setting and its reader, a route and its
capability.

### Money that was wrong

- [x] **The shop could sell the same jar twice.** Nothing re-checked stock between
      the basket and the payment, and **no order path had ever reduced a stock
      count**. `CartService::claimStock()` now takes the units at the instant the
      order is written, under a row lock with the condition repeated in the UPDATE
      and the affected row count checked. Refuses the whole order, by name, before
      any gateway is touched — *2.60.179*
- [x] **The unauthenticated `/api` order endpoint was the remaining way to
      oversell** — it checked the sold-out flag and never the count. Extracted to
      `App\Services\StockClaim` so both checkouts call one routine rather than
      keeping two copies — *2.60.182*
- [x] **Cancelled orders never returned their stock.** `order_stock_claims`
      records which shelf lost how many units for which order, so a return credits
      exactly what was taken and an imported or back-office order gets nothing
      invented for it. `released_at`, claimed under an affected-row check, makes a
      double cancellation credit once — *2.60.182*
- [x] **Coupon redemptions were never released.** Cancel, decline or refund in
      full and the customer had still "used" their code — a one-per-customer code
      gone for good on a sale that never happened. Nine status-writing sites now
      go through `App\Services\Orders\OrderStatus` — *2.60.183*
- [x] **Demo orders counted as real revenue.** Switching Demo Content on added
      roughly AED 2,000 of invented sales to the dashboard, analytics, AOV and
      customer lifetime spend, and switching it off took them away again with
      nothing saying why. `App\Support\DemoSeed` excludes them from every figure
      while leaving them on the lists, marked — *2.60.178*
- [x] **Editing an order added `tax_total` unconditionally**, which is right for an
      exclusive order and wrong for an inclusive one, so an inclusive order's first
      edit inflated its total by the tax already inside the price. Re-prices from
      the rate recorded **on the order** — *2.60.186*

### The shop's own clock

- [x] **Every date in the admin was a UTC day**, four hours behind Dubai, so any
      order placed between midnight and 4am was filed under the previous day — on
      the dashboard, on each chart bar, on the invoice and on the customer's
      confirmation email. `App\Support\StoreTime` converts for display and
      **never reinterprets what is stored**; `APP_TIMEZONE` stays UTC, because
      moving it would shift every historical order rather than convert it. The zone
      is a setting, Store → Business Details, default Asia/Dubai — *2.60.178*
- [x] Date filters on Customers and Orders still matched a typed date against the
      UTC calendar day while the rows beside them were already shown on Dubai time,
      so the screen disagreed with itself — *2.60.183*

### Promises made to the wrong country

Each of these was found and removed separately, and the last one is the general
lesson: **a single global sentence is structurally incapable of being
country-aware.**

- [x] The checkout told a Saudi shopper, in writing, that their order arrives in
      one to three days anywhere in the UAE — *earlier*
- [x] The home page said it to **every visitor in the world** — *2.60.181*
- [x] The product page's trust chip was loaded and waiting: blank, but the settings
      screen suggested UAE wording that every country would then have read.
      Re-sourced from `delivery_texts` and the global setting deleted — *2.60.183*
- [x] The product page's dispatch countdown gave every visitor an arrival date
      built from `dispatch_days`, a UAE transit time. It keeps the dispatch half —
      true wherever the parcel goes — and drops the arrival half outside the store
      country — *2.60.183*
- [x] "Free delivery over AED 199" was shown site-wide while the Gulf zone already
      carried AED 1,600, and three home-page places had the figure **typed into the
      page** so they would have gone on advertising the old one to UAE shoppers
      too. Five meta descriptions promised next-day UAE delivery to every visitor
      and to Google — *2.60.183*
- [x] `App\Support\ShopperCountry` is now the only answer to "where is this
      shopper": explicit choice, session, geo signal, `store_country`, returning
      the code *and* which tier answered — *2.60.181*

### Claims with nothing behind them

- [x] "Easy 14-day returns" — no such policy exists anywhere in this shop — *2.60.179*
- [x] "4.8 · loved by 2,300+ UAE customers" at the payment step, typed into the
      code, on a shop with no reviews — *2.60.179*
- [x] GLOW30 advertised whether or not the coupon existed, and clickable, so
      tapping it returned "That code is not valid" from the page that had just
      recommended it — *2.60.179*
- [x] The cancellation email promised every customer their refund was on its way
      and that a second email would follow. Cancelling starts no refund here. It
      now looks the order up and says which of three things is true of it — *2.60.184*
- [x] The emailed invoice said "Paid by" on **every** invoice, including the
      cash-on-delivery ones sent before the courier collects — *2.60.184*
- [x] A dispatch email promised tracking this shop does not have; every footer
      invited a reply to an address nothing sets — *2.60.184*
- [x] **The Live / Sandbox switch** told the owner changes were held back from the
      live shop until he deployed. There is one shop; every save went straight into
      it. **Sandbox & Deploy** showed five passing safety checks, a Deploy button, a
      Rollback button and a promised database backup — the checks were string
      literals and `deploy()` was a `setTimeout` setting a boolean — *2.60.186*
- [x] The dashboard's "live feed" printed three invented events stamped "just now"
      for ever on a shop with no orders — *2.60.186*
- [x] Pinterest and Baidu verification boxes saved a token and emitted no tag, so
      neither service could ever verify the shop — *2.60.186*

### Built because the owner asked

- [x] **Analytics date filters** — All time / Today / This week / This month /
      This year / Custom range, with every box on the screen following the same
      range and each card printing the period it covers. `App\Support\AnalyticsRange`
      owns every boundary and bucket — *2.60.179*
- [x] **Per-country VAT**, then **a real tax engine**: a default rate and basis
      plus per-country exceptions each carrying **its own rate and its own basis**
      (inclusive, exclusive or printed-only), behind a `tax_mode` switch that ships
      **off** so applying it changes nothing. The rate and basis are recorded on
      the order, never looked up at print time — *2.60.180, .183*
- [x] **Per-country delivery lines** with a screen to write them in, and one-click
      Gulf presets carrying the owner's own figures — *2.60.181, .183*
- [x] **A link into the admin opens the screen it names.** Seven screens answered a
      bookmark with "the admin script did not finish starting up" — *2.60.185*

### Performance, measured not assumed

- [x] The home page's review wall sorted all 7,650 approved reviews to show four —
      41ms, growing with every review collected. Indexed: **41ms → 0.3ms**, and
      constant. The brand index spent four of its six queries asking the database to
      describe itself — *2.60.183*

## Phase 8d — What the shop publishes  *(2.60.186 → .192)*

Phase 8c was about screens that described machinery they did not have. This
run moved outward to the pages **a customer and a search engine actually
see**, and to the shop's own identity on the documents it sends out.

### The shop's own details, on the shop's own documents

- [x] **The owner can put his own business on his own invoices.** Name, phone,
      email, address and registration number were literals in the invoice and
      order-email templates; they are settings now, and the templates read them
      — *2.60.186*
- [x] **A blank box no longer refuses the whole Save.** Clearing a field wrote
      `''` and tripped a validation rule written for a missing row, so one empty
      input rejected every other change on the screen with it — *2.60.187*

### Screens that answer for themselves

- [x] **A health panel that checks**, rather than three screens reporting a
      status nothing measured — *2.60.187*
- [x] **A link to a screen opens that screen**, not the dashboard — the
      deep-link fix from 8c, finished for the screens it had missed — *2.60.188*
- [x] **Users & Roles shows the real staff**, and no longer claims a second
      factor this install does not have. The fake list was dead code; the live
      defect was that a non-owner was told "No users" — *2.60.189*

### Claims the shop cannot back

- [x] **The front page stops advertising a discount code the shop has not
      got.** The hero offered a coupon; no such coupon existed — *2.60.190*
- [x] **A phone downloads a phone-sized photograph.** GD-only responsive
      variants, cached under `public/img-cache/` (gitignored, never shipped),
      with an admin screen for the sizes — *2.60.191*
- [x] ▲ **`/reviews` published twelve invented customers, and the sitemap
      submitted the page to Google.** The route was live, the twelve names were
      a literal `var REVIEWS=[…]` in the template, the controller passed no
      data, and `SeoFilesController` added the URL to the sitemap
      unconditionally. Rebuilt on real approved rows. Flagged to the owner as a
      likely legal exposure rather than a bug — *2.60.192*

### The clock, the tax and the delivery promise  *(landed across .183–.185, completed here)*

- [x] **Dubai time.** `App\Support\StoreTime` is the display-layer clock;
      storage stays UTC and conversion happens with `setTimezone()`, never
      `shiftTimezone()`. The zone is a setting, defaulting to `Asia/Dubai`
- [x] **Tax that is charged: a rate and a basis per country**, inclusive,
      exclusive or printed-only, on its own Tax tab, off until the owner says
      otherwise
- [x] **A delivery promise the shop can back for this visitor**, detected by
      geography and re-resolved when the shopper changes country, with
      one-click Gulf presets whose sentence follows the country name
- [x] **Demo rows stop moving the figures.** `App\Support\DemoSeed` settles the
      rule: **figures exclude demo content; lists show it and mark it**

### Five lanes at once  *(2.60.193)*

The largest single package of the project: five lanes merged together, 2,803
tests to **2,936**, every file byte-compared against the repo before it shipped.

- [x] ▲ **`/app` was a second storefront.** A public URL answering 200 with the
      shop's own logo, nav and payment badges, a hard-coded catalogue of 24
      invented products at invented prices, and **two discount codes the shop
      has never had** — `GLOW30` and `KBB10` — offered in the hero, on the
      product view, and in a coupon box that accepted them and showed the money
      coming off. 2.60.190 had shipped to stop the *front page* advertising
      `GLOW30`; nobody had checked this page. Now admin-only, gated in the
      controller rather than the route, because the compiled route cache is not
      cleared until a package says so and a controller check is live the moment
      the file lands. 404 and not 403: a 403 confirms the page is there
- [x] **Five links in the chrome went nowhere.** Four in the footer of every
      page — Shipping & Delivery, Returns Information, FAQs, Contact us — and
      About on the home page. One mechanism: `routes/web.php` hard-codes seven
      content slugs at `PageController::show()`, which `firstOrFail()`s on a
      `pages` row, and only two of the seven had ever been seeded. Now editable
      rows with deliberately claim-free placeholder copy. A walker test follows
      every internal href the rendered footer, header and mobile chrome emit,
      and a second, generic form reads the router: any route with a fixed
      `defaults('slug')` and no published row fails the first time the suite runs
- [x] **Every trust claim became the owner's.** "100% original", "24/7 support",
      "100% authentic", "Korean brands, all sourced direct." were literals in
      Blade files — which, on a host with no shell, meant the owner could not
      change or withdraw one of them without a signed package. `TrustClaims`
      makes each a setting defaulting to the shipped wording, and a **Claims
      tab** on Business Details edits them. An empty box removes the claim and
      its element. Counts are deliberately excluded: a brand count an owner can
      type is a brand count that can drift
- [x] ▲ **The Claims tab nearly shipped the defect it was built to fix.**
      `Setting::map()` is the settings *table*; a claim nobody has edited has no
      row, while the storefront prints its default happily. Sent raw, the tab
      would have opened with seven empty boxes on a shop whose pages all say
      "100% original" — and since an empty box on that screen *means* remove the
      claim, the owner's first Save would have stripped every claim off his own
      site without him typing a character. Caught before shipping, closed by
      resolving on the read side, and pinned by a test that fails if the resolve
      is removed
- [x] **Google was told about every visit twice.** Two boxes for one Analytics
      ID — one on SEO & Meta, one on Marketing Pixels — and the storefront read
      both, so a shop with both filled reported every page load as two. Traffic
      looks twice as large; everything measured *per visit* looks half as good.
      `App\Services\Analytics` is now the only emitter, claiming a flag on the
      **Request's own attribute bag** — not a static, not a singleton, with no
      reset method, so a second call in the same request is `''` by construction
      and a new include is safe by default. Found alongside it: `add_to_cart` had
      **never** been sent to GA4 (it tested a key no screen writes), and the blog,
      quiz, reviews and preview pages reported to no pixel at all
- [x] ▲ **Demo content was two fabrication mechanisms, and only one was behind
      the switch.** The fixtures claimed **12,481 reviews at 4.8 stars** over four
      named people who do not exist, each labelled a verified buyer, and 671
      products and 93 brands regardless of the real figures. Separately — and
      *whatever the switch was set to* — seeded rows in `reviews` were being
      counted into `products.rating`, into the score beside the pay button, and
      into the `AggregateRating` published to Google. Two provenance mechanisms,
      each blind to the other; `DemoReviews` asks both. The migration is
      load-bearing: `products.rating` is a **stored column**, so a shop that had
      the switch on keeps its invented stars for ever unless they are recomputed
- [x] **One tax answer per destination, whichever door the order came through.**
      `customer.country` is nullable on the public `/api/checkout/session`, and
      every other figure resolved an absent country to AE while the tax quote
      alone got the raw `null` — which answers the *global default* rule, not
      AE's row. Same basket, same destination: AED 231.00 through the storefront,
      AED 220.00 through the API, on an order stamped `country: AE`. Verified by
      reverting the fix and watching exactly one test fail
- [x] **A phone downloads a phone-sized photograph**, and the accent colour of
      the whole storefront became a box on Business Details — a setting the shop
      had read since the baseline with nothing anywhere able to write it, while
      two admin screens told the owner no colour could be changed

### Four more lanes  *(2.60.194)*

- [x] ▲ **Fourteen links in the main menu went to a page that does not exist.**
      Toners, Sunscreens, Moisturizers, Lip Care, Hair Care, Skincare Sets,
      Beauty Devices and seven more still carried the WooCommerce-era flat
      addresses, and this application serves categories at
      `/product-category/{path}/` — so the root catch-all took them and looked
      each one up as a **blog post**. Three of the six Build-your-routine steps
      on the front page were dead too, for a second reason hiding behind the
      first: one wrong slug drove both the link and the product pick, so the
      step rendered as an empty card that read as an empty catalogue. Slugs are
      preserved rather than remapped — resolving at apply time would bake a
      placeholder category in for ever on a server whose import has not run,
      while an honest 404 self-heals the moment the real category lands
- [x] ▲ **The shop promised a search engine what it does not do.** The sitewide
      meta description — read on the home page, every content page, every brand
      page, in every shared-link preview, and as the `Product` description of
      anything without a short description — said **"next-day delivery"** while
      the shop tells every customer 1–3 days in the UAE and 3–5 across the
      Gulf, **"glowing skin guaranteed"** (a regulated claim in the UAE, the EU
      and the UK), and **"100% genuine"**, a fifth spelling of a claim that now
      has one box. Also fixed: the sitelinks searchbox advertised an address
      that threw the shopper's query away, and the `Offer` published a bare
      shelf price with no statement of whether VAT was in it
- [x] ▲ **A filed document could reprint itself at today's rate.** An order with
      no tax snapshot — every order this shop has placed, the engine being off
      — had its VAT note computed fresh on each render. And the fallback was
      **not the tax engine's arithmetic**: it taxed the whole order total, gift
      wrapping and COD surcharge included, which the rules deliberately exclude
      — about **7% too high** on any order carrying a fee, with no settings
      change involved at all. Silence where there is no record; nothing is
      invented, because nothing ever stored what the rate was on a past day
- [x] **The order-received page states the tax** the email and invoice already
      stated, in the shopper's wording rather than the accountant's; the product
      page's authenticity chip joined the Claims tab with its **own** key, so
      clearing it cannot silently strip the chip beside Place order; and the
      admin's revenue figures now say whether they include VAT rather than
      leaving it to be discovered the day the tax engine is switched on

### The suite stopped disagreeing with itself  *(2.60.194)*

- [x] ▲ **A test suite that gave a different answer each run, finally named.**
      Assertion counts had drifted between identical runs for the whole
      project's life and roughly one run in four failed somewhere. The demo
      catalogue is seeded by a **migration**, so under `RefreshDatabase` it is
      drawn once per process inside the first test — from an **unseeded
      `mt_rand()`**. Every run therefore rendered a different 24 products, and
      one test asserts once per rendered image on a page whose contents depend
      on the prices drawn. The seam matters and is worth recording: Pest's
      `beforeEach` runs *after* `setUpTraits()` has already migrated, so
      reseeding there changes nothing; `createApplication()` is early enough.
      Five consecutive runs on each engine now give an identical count, and
      the drift is zero per-testcase. Two latent product defects surfaced on
      the way — the home page's two best-seller rails are separate queries on
      a tie-prone sort with no tie-breaker, so the same product can appear in
      both or vanish from both

### Five more lanes  *(2.60.195 – .197)*

- [x] ▲ **Nine brands in the menu told shoppers the shop stocks nothing by
      them.** The menu built each brand's filter value by squashing its label
      instead of looking the brand up, so `dralthea` asked for a brand recorded
      as `dr-althea`. The page then loads perfectly and says "No products match
      those filters" — an empty shelf rather than an error, which reads worse
      and is harder to notice. Measured: 24 products on the bare shop, 3 for a
      brand the shop carries, **0** for one of the nine. `MenuDemo` now reads
      the brands table and omits what it cannot reach; the tree is rebuilt per
      request, so an imported brand reappears on its own
- [x] ▲ **The product page's tax sentence was a literal.** `"Inclusive of
      {rate}% VAT"` never consulted `vat_basis`, and the rate was the global
      default rather than the country's. Setting Saudi Arabia to 15% exclusive
      — the feature the owner asked for by name — would have told a Riyadh
      shopper the tax was already in the price, at the wrong rate, and then
      charged 15% on top at checkout. Nothing would have errored. The same line
      carried "Authentic, sourced direct" welded into the code, so clearing the
      product page's authenticity box removed the chip and left the claim
      printing under the price: a claim withdrawn and unremovable
- [x] **A list that is sliced is ordered all the way down.** Thirty-odd query
      sites now end their ORDER BY on `id`. The correction worth recording: the
      home page's `best2` rail is **built and never rendered**, so the duplicate
      was never shopper-visible there — the real instances were `/best-sellers`'
      pagination and the shop's `?orderby=` modes, which nobody had flagged. The
      worst was CatalogReorder's `autoSort`, which wrote `position` from an
      arbitrary read order and turned a tie into the shop's persisted curated
      order
- [x] **Best Sellers measures what it claims.** The page said "The products our
      customers keep coming back for" while ranking by units sold, which cannot
      tell three hundred buyers of one jar from one buyer of three hundred.
      `App\Support\RepeatPurchase` counts distinct orders per (product, buyer),
      and the loyalty sentence is printed only when the history supports it
- [x] ▲ **The shop on a phone, walked for the first time.** Every text field
      except the header search was under iOS's 16px threshold, so tapping any of
      them — the whole checkout, every field — zoomed the page and did not zoom
      back. The cart drawer's scroll lock had **never worked**: `overlay.js` adds
      and removes `body.kbb-locked` and no stylesheet in the repo ever defined
      it. Four `font: <weight> <size> inherit` shorthands were invalid CSS and
      dropped whole, so the sign-in page rendered in Arial at the browser
      default. The shop and category grids had no side gutter, their cards on
      pixel 0. All repairs scoped to phone widths; no desktop rule moved

### Two suites, one checkout  *(2.60.197)*

- [x] ▲ **`config:cache` writes `bootstrap/cache/config.php` with
      `file_put_contents` — no lock, no rename**, so the file is truncated and
      refilled in place. A suite booting inside that window gets a `ParseError`
      rather than a test failure. Measured on this checkout: the window is the
      whole ~27µs of the write, and **22% of boots that found a file while a
      writer ran** died on it. Compiled-cache paths are per-process now, preview
      children get their own, and `discard()` cleans both locations because the
      237 `clear_caches_*` migrations only know the shared one. The blanket
      guarantee in `tests/bootstrap.php` was false on two counts and was rewritten
      to the narrower true one rather than left standing
- [x] **A guard that could not see the query it was about.** `StableOrderingTest`
      matched `"id"` in SQLite's double quotes; MySQL — what the shop runs —
      returns backticks, so on that engine the pattern matched nothing: every
      correctly ordered query was reported as unsettled, the home-rail counter
      stayed at zero, and the offset sweep was blind. Both engines are now green
      (SQLite 3063, MySQL 3068). **A test that is engine-specific and silent
      about it is reported as coverage, which is worse than no test**

## Phase 9 — Content pages

- [x] Privacy, terms, New In, Best Sellers, Super Sale, Under 54 AED, Wishlist
- [x] `/subscribe` — *2.38.0*
- [x] ▲ `/brands/` — **URL conflict, closed.** The owner confirmed
      `/korean-skincare-brands/` is the live address in 2.60.109; `/brands/` now
      answers 301 to it, and only the canonical address is in the sitemap.
      Verified against a running server, not read off the router — *2.60.109*
- [ ] ▲ `/skincare-guide/` — a permalink structure, not one page: the homepage builds
      `/skincare-guide/{slug}/`, the router serves `/blog` and `/post/{slug}`
- [x] **Blog — was a complete, silent outage.** Checked directly rather than assuming
  from the plan's own listing: both `/blog` and every `/post/{slug}` returned a real
  500, not a placeholder — `PageController::blog()` and `::post()` were called by the
  routes but simply didn't exist (only `show()`, for the unrelated static-page model,
  was there). The views themselves (`store.blog`, `store.post`) turned out to be a
  second, deeper problem once the 500 was traced: leftover, unfinished client-side-only
  mockups that read the slug from a query string instead of the URL path, fetched an
  `/api/posts` endpoint that was never built, and silently fell back to hardcoded demo
  content for every request that didn't happen to match — meaning even a minimal fix of
  just the missing methods would still have shown the same wrong content for every real
  post. Rebuilt both views server-rendered, matching the same pattern already working
  for products and categories: real posts, real path-based links, real excerpt/body/
  cover/date, related posts, and — for free, since the SEO renderer already had Article
  and BreadcrumbList support built and simply never had real data reach it — genuine
  Article schema and breadcrumbs on every post. Caught and fixed a real bug of my own
  before shipping: an early attempt to conditionally hide the "more" section produced
  visible, un-compiled Blade text leaking straight into the page — caught only because
  the fix was checked with a real screenshot, not just a passing lint. Verified end to
  end: draft posts correctly stay invisible, a nonexistent slug 404s instead of 500ing,
  and the tag filter on the listing page is a real, working interaction over the
  server-rendered cards. **Marked [x] for "no longer 500s," not "done" — Rafi flagged
  this needs a lot more work (visual design, content richness, likely more features)
  before it's actually where he wants it. Revisit later, deliberately not now.**
- [x] Desktop nav drops the base path — checked directly rather than trusting the old
  note: already fixed, most likely as a side effect of the Mega Menu rebuild in an
  earlier session, which correctly routes every nav link through `Url::to()`. Verified
  with a real request and `kbb.base_path` configured — every generated link correctly
  carries the prefix. Stale note, not a live bug

## Phase 10 — Build my routine  *(designed, not built)*

- [ ] Routines by concern · manual selection · front-end list · offer strip
- [ ] **Open:** fixed steps or a free list? One offer strip or one per routine?

## Phase 11 — Payments

- [ ] COD → Tabby → Tamara → Stripe (order per D-39)

## Phase 12 — Performance and SEO

- [~] `seo_engine` — the admin screen and `Seo::render()` were both already fully built
  and using identical setting keys, but nothing had ever called the render method; the
  real `<head>` was a bare `<title>` tag. Wired into the shared layout for every page
  using it (home, product, shop/category) — *2.60.36*. `title_is_final` preserves every
  page's exact existing title; what's new is meta description, robots, canonical, Open
  Graph, Twitter cards, and JSON-LD (sitewide Organization/WebSite, full Product schema
  with real price/stock/brand/SKU on product pages). Caught a real unit bug before
  shipping — prices are stored as fils (AED ×100), and the schema's price field was
  first wired using that raw value directly, which would have told every search engine
  a 126 AED serum cost 12,600. **Not yet done:** Yoast migration, the six orphaned view
  files with a matching `$seo` placeholder that turned out to be dead code, and the
  `performance` half, which the plan already notes doesn't port at all

**Full feature audit done 2026-09-10** — read the WordPress plugin's actual `seo_engine`
module source directly (not secondhand) plus dedicated research on Yoast itself, since
Rafi confirmed Yoast is the real plugin in use. Full writeup: `KBB-SEO-Feature-List.md`.
Broken out below by whether each piece is buildable now or blocked on the product edit
page (Phase 6/`openProduct()` is still a complete mockup — "Update"/"Publish" just shows
a fake success toast and saves nothing).

- [x] Site-wide SEO settings admin screen — **already real, found on inspection** (was
  wrongly listed as "not built" on first pass through this list — corrected after
  actually reading the code instead of assuming from the feature audit alone). Title
  templates, OG/Twitter, Organization schema, Google + Bing verification, sitemap
  toggle, robots.txt editor — *2.60.36*
- [x] XML sitemap (`/sitemap.xml`) — **already real**, same correction as above. Covers
  static pages, products, categories, blog posts with lastmod/priority/changefreq.
  Missing brand archive URLs specifically — small gap, not a rebuild
- [x] `robots.txt` — **already real**, sensible default + admin override
- [x] Missing verification fields — Pinterest, Baidu — *2.60.50*
- [x] IndexNow — auto-submit new/updated URLs to Bing/Yandex/Naver/Seznam/Yep on
  publish, with the required key-file verification route. Caught and fixed two real
  bugs before shipping: the key was regenerating on every call instead of persisting,
  and the submitted URL was relative instead of the absolute URL the protocol requires
  — *2.60.50*
- [x] `llms.txt` for AI crawlers — *2.60.50*
- [x] Real image upload for the share-image/logo fields — the upload endpoint already
  existed (built for exactly this) but was never actually wired into the form; fixed,
  not built new — *2.60.51*
- [x] Social profile links (Facebook/Instagram/TikTok/Pinterest/LinkedIn/YouTube) →
  Organization schema `sameAs` — *2.60.51*
- [x] Crawl-budget cleanup — researched current (2026) guidance directly before building
  rather than guessing: for a catalogue this size (~650 products, not the tens-of-
  thousands where crawl budget becomes a genuine crisis), the safe, low-risk approach
  research consistently recommends is canonical-only — never noindex or robots.txt
  blocking, both of which carry real risk if sequenced wrong (a robots.txt block on an
  already-indexed URL can trap it as indexed-but-invisible, since Google can no longer
  see the noindex or canonical telling it otherwise). Filtered/sorted listing views
  (brand, price, in-stock, sort order) now canonicalize to the clean category URL;
  paginated pages keep their own self-referencing canonical rather than collapsing to
  page 1, since current guidance is that doing so risks Google simply never discovering
  products that only appear on later pages. New `crawl_clean` toggle (default on).
  Found and fixed a real, pre-existing bug while building this: canonical for every
  shop/category page was defaulting to the site root regardless of what page you were
  actually on, telling Google every category page duplicates the homepage — fixed
  unconditionally, not gated behind the new toggle, since having *some* correct
  canonical is a correctness fix, not an opinionated strategy choice. Caught and fixed
  a `Url::to()` misuse before shipping: that helper is deliberately root-relative
  (correct for `<a href>` links, the one thing it's for) — using it to build a
  canonical tag produced a domain-less, broken URL; fixed to build the absolute URL
  from `site_url` directly, the same pattern already established for IndexNow and the
  media uploader. Verified via a real HTTP request through the full kernel with the
  actual rendered `<head>` parsed for the canonical tag, not just a direct method call
  — *2.60.53*
- [x] Redirects & 404 manager — manual 301/302s (from the SEO & Meta screen's new
  "Redirects & 404s" tab), **auto-301 on published slug change with chain collapsing**
  (A→B→C collapses to A→C, B→C rather than left chaining), 404 hit-logging with noise
  filtering and a table-size cap, one-click "resolve a 404 into a redirect." Found the
  `redirects` table already existed from the original schema migration with different
  column names (`source`/`target`/`code`) than first assumed — corrected by actually
  testing against it rather than trusting the assumption. Caught and fixed three more
  real bugs before shipping: the matching logic was stripping the trailing slash this
  site's URLs deliberately keep, which would have made every auto-created redirect
  silently never match a real request; dismissing a 404 left the visible count stale
  since it only removed the DOM row without refreshing the summary label; and — the
  significant one — the original design (redirect-checking as real HTTP middleware,
  registered from a service provider) looked correct and passed an early test, but
  testing it against the actual HTTP kernel rather than trusting it showed it silently
  never ran for real requests. Laravel 11's middleware configuration turns out to be
  fully resolved during `bootstrap/app.php`'s own bootstrap, before any service
  provider runs, so a group modified afterward never reaches a real request — and
  `bootstrap/app.php` itself is outside what the update system's own file guard
  permits writing, so it can't be fixed there either. Rebuilt around the exception
  handler instead, which reliably catches every 404 regardless of source (a completely
  unmatched old WordPress URL, or a real route pattern like `/product/{slug}` whose
  controller can't find the underlying product) — verified end to end with the actual
  core scenario: created a product, renamed its slug, confirmed the auto-redirect, then
  confirmed a real request to the old URL genuinely returns 301. **Known, disclosed
  limitation**: redirecting a page that still genuinely works (e.g. manually
  redirecting `/shop` elsewhere while `/shop` itself still loads fine) does not work
  with this architecture, since nothing ever throws an exception for a route that
  succeeds — noted directly on the "Add a redirect" form rather than left as a silent
  gap. The two things that actually matter here — auto-redirect on slug change, and
  catching dead/broken URLs — both work correctly — *2.60.52*
- [ ] **Per-product SEO editor** ▲ *blocked on the product edit page* — focus keyphrase,
  SEO title/description with live counts + SERP preview, live SEO + readability
  traffic-light checks, per-page OG override, canonical/noindex/nofollow/cornerstone
  flags, internal-link suggestions. This has to live inside the real product editor —
  building it standalone now means rebuilding it once that editor exists. **Do this
  once Phase 6's product edit page is real, not before.**
- [~] Structured data expansion — done: **BreadcrumbList** (the render logic already
  existed in `Seo.php` and had from the start, but no controller had ever actually fed
  it data — same "built, never wired up" pattern found a few times this session; wired
  into both `ShopController` and `ProductController`, verified real output: Home →
  Shop → Cleansers → the product's actual name, correct URLs at every step),
  **merchant-listing offer fields** (condition, shipping cost/threshold, return
  policy — off by default, admin has to confirm the numbers before they ship to search
  engines; verified the free-shipping-threshold math both above and below the
  cutoff, and confirmed zero regression when off — offer schema byte-identical to
  before), and a **Schema Inspector** tab (enter a product/category slug, see the real
  JSON-LD that page would actually output, plus plain-language warnings — calls the
  exact same renderer every real page uses via a new `Seo::inspect()` wrapper, so nothing
  shown can drift from what actually ships; tested against a real product, a
  nonexistent slug, and a category). Found and fixed a significant bug while wiring
  the canonical URL in for breadcrumbs: **every single product page** (650+, not just
  the handful of category pages the earlier canonical fix covered) had the same
  canonical-defaults-to-site-root bug — confirmed and fixed the same way, a real HTTP
  request through the full kernel with the actual rendered `<head>` parsed, not a
  variable checked in isolation. **Still open: GTIN and variant-level offers** —
  genuinely blocked, no GTIN/barcode column exists anywhere in the schema and there's
  no existing source of truth for it, unlike everything else in this item which either
  already had the right data or needed only site-wide settings — *2.60.54*
- [~] Per-product SEO storage — a genuinely major find while starting this: `products`
  already has a `seo` json column, from the original schema, commented **"Yoast import
  target"** — deliberately built for exactly this, then never once read from. Same
  "built, never wired up" pattern found a few times this session, just discovered
  before building a duplicate rather than after. Wired directly into
  `ProductController`: title/description/canonical/noindex overrides now genuinely
  take effect on the real rendered page the moment anything writes to the column — by
  hand, by a future importer, or eventually by the per-product editor. Verified with a
  real product set to a full override: exact title (no template applied), exact
  description, exact canonical, correct `noindex, nofollow` — all confirmed via a real
  HTTP request through the full kernel, and confirmed a product with *no* override
  behaves exactly as before, no regression. This is the schema the Yoast importer and
  the audit tooling below both explicitly needed to exist first
- [x] Site-wide audit tooling — **Catalogue Audit** tab: scans every visible product,
  reports counts plus the worst offenders for missing meta description (checking the
  same `seo` column now actually read, not a duplicate check), missing image, and a
  short description too thin to build a real fallback from. Deliberately read-only for
  this first version — reports, does not bulk-fix; that is the natural next step once
  the counts it reports are trusted. Verified with real, deliberately varied seed
  data covering every branch: a product with nothing set (correctly flagged for both
  missing description and missing image), a product with a real override description
  (correctly excluded), a product with a thin 2-word description (correctly flagged),
  and a product with a genuinely long description (correctly excluded from both
  checks) — *2.60.55*
- [ ] Yoast data importer — field-by-field mapping (`_yoast_wpseo_*` → the `seo` column
  above, now that it is real). Storage side is done; the import itself is only worth
  running once there's confirmation of which Yoast tier (free / Premium / +WooCommerce
  SEO add-on) was actually in use — that decides whether there's product-schema data
  to import at all
- [~] Structured data — sitewide, product, BreadcrumbList, and article now real (article
  landed as a side effect of the blog outage fix above — the renderer already supported
  it, it just never had real data reach it before). Category pages still use plain
  `website` type rather than a proper `CollectionPage`/`ItemList` — the one piece left
- [ ] Image pipeline · cache strategy

## Phase 13 — Data migration  *(one-time, idempotent Artisan command)*

- [ ] Products **671** · Orders **4,159** · Customers **3,712**
- [ ] Count-based verification after each bucket
- [ ] Three-bucket classification: migrate / discard / ask — **Rafi approves any discard list**
- [ ] Media and image paths · URL redirect map — **needs the two URL decisions in Phase 9**

## Phase 14 — Licensing console  *(separate application)*

- [ ] 22 items · offline token verification · plan-gated modules
- [ ] **A licence must never take a shop offline**

## Phase 15 — Page builder

- [ ] Live editing of homepage sections, reusing the settings schemas

## Phase 16 — Growth & Marketing

- [x] Newsletter screen, `subscribers` table, CSV export — *2.39.0 → 2.41.1*
- [x] **Newsletter double opt-in, and an unsubscribe that needs no login.** Signup
      wrote `subscribed` the instant a stranger typed an address into the public
      box, so anyone could put anyone on this shop's marketing list — *2.60.199*
- [ ] Product Labels · Meta & Facebook — existing screens, not yet checked for parity

## Phase 17 — Dead interface  *(new — found by `hooks_bound`)*

- [x] ▲ Reviews: 10 hooks, no JavaScript, no submit endpoint — *2.60.0, corrected in
  2.60.1 after a real 500 on every product page.* Bigger than a wiring job: the
  product page was rendering the *wrong* review template the whole time.
  `reviews.blade.php` — the plugin's real form, ported verbatim — was the
  correct one; `product-reviews.blade.php` (an earlier, mismatched port) was
  live instead. Swapped the include, then built what the plugin source couldn't
  supply (no `class-sr-frontend.php` submit handler was available to port): a
  stateless signed-token captcha (no extra table), rate limiting (5/hour/IP),
  honeypot handling that fakes success rather than revealing itself, secure photo
  upload (validated by real file content, not the claimed MIME type — proven with
  a PHP file disguised as a `.jpg`, correctly rejected), one helpful-vote per
  browser, and full moderation queuing (`status: pending`). Photos write directly
  to `public/uploads/`, not the storage-symlink path Laravel defaults to — this
  app deploys via a signed ZIP updater that never runs `artisan storage:link`,
  so that path is a silent, hard-to-diagnose failure risk avoided on purpose.
  Verified through the real HTTP kernel with middleware active throughout, per
  the CSRF lesson two releases earlier — not the controller in isolation.

  **What still went wrong, and the fix.** `reviews.blade.php` and its stylesheet
  existed in the build sandbox and had for some time — a claim in the original
  2.60.0 notes that the CSS was "already loading on every product page" was
  checked against that sandbox, not against what had actually been shipped, and
  was wrong. Neither file had ever been included in any package. Every test
  before release rendered correctly, because the sandbox has the file the live
  server doesn't — so the gap was invisible until the real request hit a real
  server missing it. Reproduced properly for 2.60.1 by simulating the live
  server's actual state (a copy of the source with both files deliberately
  removed) rather than testing the sandbox again: `View [partials.reviews] not
  found`, the exact error. Fixed by shipping both files, confirmed by re-running
  the same simulation with them restored. See ▲17 for the permanent gate this
  became — `shipped_check.py`, which checks every file reference across the
  whole codebase against every package ever shipped, not just the ones that
  changed. It also caught three older, unrelated references never resolved
  either way; shipped in the same release.
- [x] Wishlist heart on every product card — routes exist, no click handler — *2.58.0,
  fixed again in 2.58.1*. First pass shipped a real bug: the CSRF header didn't match
  the token type sent (`X-XSRF-TOKEN` with the raw session token — Laravel wants that
  header paired with the *encrypted cookie* value, or the raw token under
  `X-CSRF-TOKEN`, never mixed). Every other working AJAX call in the storefront uses
  the second pairing; this one didn't, and Laravel correctly rejected it with a 419.
  Caught because Rafi hit it live, not by testing beforehand — the original test called
  the controller directly, which bypasses CSRF checking entirely. Re-verified 2.58.1
  through the real HTTP kernel with CSRF middleware active this time, not the
  controller in isolation: the old header reproduces the exact 419, the fixed one
  returns 200.
- [x] Checkout browsed-item "Add" button — *2.59.0*. Carried `data-product-id`, an
  attribute nothing has ever read; every product card's working add-to-cart button
  carries `data-kbb-add` instead, which `cart.js`'s existing handler already listens
  for site-wide. One attribute rename reuses that handler rather than needing new
  JS — the same fix shape as the wishlist heart, cheaper.
- [x] Review badge → section link — *2.60.5*. The star-rating badge near the top of
  the product page linked to `#reviews`; the section's real id, matching the
  plugin's own naming throughout the rest of the module, is `#sr`. Two links fixed.
- [x] Reviews verified at real scale — *2.60.6*. Proposed as a follow-up after
  shipping the module, then actually tested rather than assumed: seeded 50 real
  approved reviews and found two real bugs the small manual tests before hadn't
  hit. **The query never selected `images` or `helpful`** — every real review's
  photos and helpful-vote count silently showed as empty/zero regardless of the
  actual data, on every product, the whole time reviews had been live. **The
  20-row query limit made "Load more" a dead end past the first 20** — it reveals
  rows already sent, not an AJAX fetch, so a product's 21st review and beyond were
  permanently unreachable no matter how many times the button was clicked, while
  the summary count directly above it correctly, misleadingly said "50 reviews."
  Both fixed: the missing columns added, the limit raised to a deliberate 200 with
  a comment explaining why that number and not "unlimited." Confirmed with the
  same 50-review seed: all 50 now render, the button correctly reveals all of them,
  a review's real photo and real helpful count both display correctly.
- [x] `hooks_bound` re-run at 2.60.0: **21 → 2, and both remaining are false positives,
  not bugs.** `data-depth` (mobile menu) — checked against both the JS and CSS;
  nothing reads it anywhere, but nothing fails silently either. Inert leftover
  markup, not a broken feature. `data-more` (review photo count) — not a JS hook
  at all; read by CSS (`content: attr(data-more)`) for the "+3 more photos"
  overlay, which `hooks_bound` has no way to see since it only checks JS
  bindings. **Phase 17 is functionally complete** — nothing left renders a
  control that silently does nothing when touched.

---

## Translation Module — Arabic  *(owner-approved 2.60.199; foundation in flight)*

The whole **storefront** in Arabic as well as English, translated by the owner
rather than by a browser plugin, at `/ar`. **Greenfield**: no `lang/` directory,
**zero** uses of `__()`, `@lang` or `trans()` anywhere in the tree, stock
`config/app.php`.

### Decisions the owner has taken

| | Decision | Why it went this way |
|---|---|---|
| Scope | **Storefront now, admin console later, as its own phase** | The shop is what customers and Google read; the console is one 1.1 MB file read only by him and his staff, worth nothing in search. Mixing them would let the bigger, unpaid job delay the one that earns |
| Where translation happens | **Beside the English field, in the editor, at the moment of creation** — not on a separate screen afterwards | His requirement. A separate screen means every new product ships English-only until someone remembers to go back, which is how a half-Arabic shop happens |
| Cost | **Free to operate.** Typing translations needs no key, no account, no external call | His requirement, stated plainly |
| URLs | **English stays unprefixed, Arabic at `/ar/…`, `/en/…` a 301 alias** | Prefixing both moves every URL a second time, after the WooCommerce migration already moved them once — taking the redirect map, the sitemap and the rankings with it |
| Store | **Database, not `lang/*.json`** | Decided by the host, not by taste: no shell, so a file could only change by shipping a signed zip, and he must be able to fix a typo himself |
| Machine translation | **A draft he approves, never a publish.** His own key, character count and cost shown first | Makes "if we find anything incorrect we correct it manually" true rather than discovered after customers read it. ~700k characters across the shop; Google's free monthly allowance is ~500k, so two batches across two months costs nothing |
| RTL | **Proper** — real `dir="rtl"`, mirrored layout, an Arabic face, since Poppins carries no Arabic glyphs | A flip is a plugin's answer, not a shop's |
| Never mirrored | **Prices stay `AED 199`, photographs keep their orientation, the logo is unchanged** | Told to the owner as part of the approval |
| Control | **A `Translation` parent menu in the admin. Arabic and RTL are separate switches, both off by default** | His requirement. Separate, because he may want to publish Arabic before the mirrored layout is finished — and because a single switch would make "turn the layout back" mean "take Arabic down" |
| Never translated | SKUs, coupon codes, order numbers, **and slugs** | One slug per row keeps the redirect map intact and halves the URL surface |

### The surface, measured

Three different things needing three different homes: **95 Blade files** of
hardcoded English (storefront, partials, emails, invoices); **database content**
(`products` — 671 on the live shop — plus `categories`, `brands`, `pages`,
`posts`, `menu_items`); and **English defaults held in PHP classes**
(`TrustClaims::CLAIMS`, the delivery lines, `VatDisplay`'s notes, module
descriptions, validation messages).

### Queued, in dependency order

- [ ] **T1 · Foundation** — locale resolution, the database store, the fallback
      chain, the admin editing surface, proved end to end on a handful of real
      strings. **In flight.** Everything below waits on the shape it settles
- [ ] ▲ **T1b · A `Translation` section in the admin, with real switches** —
      owner's requirement: a **parent menu of its own** in the sidebar, beside
      Store and Content, rather than settings scattered across other screens.
      Everything **off by default**, so applying the package changes nothing on
      the live shop — the pattern `legal_notice` and `brands` already follow.

      The controls, and two of them are deliberately independent:

      - **Arabic storefront — off / on.** Off means `/ar` does not exist at all:
        no routes, nothing in the sitemap, no language switcher. This is the
        master switch, and it stays off until he says the translation is good
        enough to show a customer
      - **Right-to-left — off / on, separately from Arabic.** He asked for RTL
        to be fully switchable. Worth stating what "Arabic on, RTL off" means:
        Arabic text in a left-to-right layout, which is legitimate during a
        rollout and wrong to an Arabic reader afterwards. The screen says so
        rather than letting him discover it
      - **Default language**, and what a bare `/` serves
      - **Progress** — how much of each area is translated, counted rather than
        claimed: interface strings, products, categories, brands, pages, posts.
        Only answerable because blank means untranslated rather than
        "same as English" (see T4b)
      - **Machine translation** — provider, his own key, the character count and
        estimated cost, and the batch run. Absent key must leave every manual
        path working

- [ ] **T2 · Interface strings** — the 95 Blade files. The bulk of the work and
      almost entirely mechanical once T1 lands. An Arabic page silently half in
      English is a designed-for outcome, not an accident: the fallback has to be
      honest about what is untranslated
- [ ] ▲ **T3 · The order records the shopper's language** — without it an Arabic
      customer gets an English invoice, and an English "your order has shipped"
      email three weeks later. A column and a decision about what the admin sees.
      Small, and painful to retrofit, so it goes early
- [ ] **T4 · Content translations** — a polymorphic table over `name_ar` columns,
      so "what is still untranslated" is one query and a new field needs no
      schema change. Must not become an N+1 on a product grid; measure it
- [ ] ▲ **T4b · An Arabic box beside every field, in every editor** — owner's
      requirement, and it decides where translation actually happens. Adding a
      product, a post, a category, a brand or a page must offer the Arabic
      alongside the English **at the moment of creation**, not on a separate
      screen visited afterwards. Every translatable field in every admin editor
      gains its Arabic counterpart, carried in the same save, validated by the
      same rules, and blank-means-untranslated rather than blank-means-English.
      Touches `resources/views/admin/app.blade.php` and
      `admin/partials/product-editor-screen.blade.php`.

      **This is not T8.** T8 is translating the console's own labels into Arabic
      and stays deferred. T4b is adding Arabic *input boxes* to a console that
      keeps speaking English — the owner needs those to enter anything at all,
      so it is required work, not the deferred kind. The per-field Translate
      button from T5 sits beside each box
- [ ] **T5 · The translate-from-Google accelerator** — pluggable provider, his
      own key, batched, cost shown before it runs, output as a draft. The manual
      path must keep working with no key at all
- [ ] ▲ **T6 · RTL** — the stylesheet is full of `margin-left` / `padding-right` /
      `text-align:left`; CSS logical properties let one sheet serve both
      directions. Plus an Arabic face (Cairo or Tajawal alongside Poppins).
      **Audit first, rewrite second**; the storefront CSS is contended
- [ ] **T7 · SEO** — `hreflang` both ways, per-language canonical, per-language
      sitemap. Builds on the `Seo` class rather than beside it
- [ ] **T8 · Admin console in Arabic** — **deferred by the owner.** Its own phase,
      after the storefront is live and earning. Listed so it is a decision on
      record rather than an omission

## Approximate timeline

Measured in **working sessions like the ones so far** — a session being a focused
stretch producing one to three packages. Revisions are included in each line, at
roughly a third on top, because that has been the actual rate: most items have
taken one build and one correction.

| Phase | Sessions | Notes |
|---|---|---|
| 3 · Register and gate the remaining 22 modules | 4–6 | Mechanical, but each needs checking against the plugin |
| 3 · Port the 4–8 modules with no equivalent | 4–6 | `product_labels` and `pay_ship_rules` are the substantial ones |
| 3 · Per-module settings for the 16 that own theirs | 5–7 | The largest single block in the port |
| ~~17 · Reviews and `data-depth`~~ | — | **Done — 2.60.0.** Reviews needed a template swap too, not just wiring |
| 7 · Account: address book, order detail, track order | 3–4 | |
| 7 · Mail — reset, verification, opt-in | 1–2 | **Blocked until sending is proven on the host** |
| 8 · Checkout styling and coupons end to end | 2–3 | Needs a real order to test |
| 9 · Brands, blog, nav paths | 2–3 | **Blocked on the two URL decisions** |
| 4/5 · Search results, filters, quick view | 3–4 | |
| 11 · Payments — four gateways | 5–8 | Sandbox credentials needed for each |
| 12 · SEO and performance modules | 3–4 | |
| 13 · Data migration | 4–6 | Plus a rehearsal run and a verified rollback |
| 14 · Licensing console | 8–12 | Separate application |
| 15 · Page builder | 6–10 | |
| **Storefront complete** *(phases 3–13)* | **~35–50** | |
| **Everything, including console and builder** | **~50–70** | |

### In calendar time

At one session a day: **7–10 weeks** to a complete storefront, **10–14 weeks**
for everything. At two or three sessions a week: **12–17 weeks** and **17–23
weeks**.

### What would move those numbers

- **Payments and mail are gated on things outside this work** — sandbox
  credentials, and proving the host can send. Neither is a coding problem, and
  both sit on the critical path to launch.
- **The two URL decisions** hold up brands, the blog and the redirect map, which
  in turn holds up the end of the migration.
- **Data migration is the only item that cannot be revised afterwards.** It
  deserves a rehearsal on a copy and a verified rollback before the real run; the
  estimate assumes that.
- **The licensing console and the page builder are not needed to launch.**
  Excluding them saves roughly a third of the total.

---

## Decisions on record

The ledger's D-series is authoritative; D-74…D-78 are to be renumbered into it
and remain binding meanwhile.

| # | Decision | Status |
|---|---|---|
| D-34 | WordPress is retired completely | locked |
| D-35 | Storefront design = the WordPress theme, ported close to verbatim | **amendment pending** — required before checkout is restyled |
| D-38 | Stack: Blade + Alpine.js + Vite | **amended** — no Alpine exists or ever did; vanilla JS bound by `data-` attributes is the decision |
| D-39 | Payments order: COD → Tabby → Tamara → Stripe | locked |
| D-41 | Credentials never pasted into chat | **violated** — see ▲1 |
| D-74 | Every delivery ships a preview built from real output | binding |
| D-75 | Build order: module framework → search → my account → migration → licensing | binding |
| D-76 | Quantity bundles are generated, not authored | binding |
| D-77 | The licensing console is a separate application | binding |
| D-78 | A licence must never take a shop offline | binding |
| **D-79** | A settings screen's loader must not depend on a table existing | **proposed** |
| **D-80** | When fixing one thing, disturb nothing else — constrain the change up front rather than checking for damage after | **proposed** |
| **D-81** | A module is an independent switch; the storefront must render with all 29 off | **proposed**, carried from the plugin |

## Risk register

**A correction, not a new risk — ▲14 below was wrong when first written, and
staying wrong would have been worse than the original mistake.** It claimed
Orders and Payments had real, working code sitting unreachable behind a
static-mockup map (`FRAME_SRC`). That was tested by calling the navigation
function directly, in isolation — which is not how the real page loads.

The admin console is two `<script>` tags, not one. The second one ends with
exactly the fix this risk was about to propose: it saves the original
navigation function, then replaces it with a version that intercepts Orders,
Customers, Quiz Leads, Analytics, Business Details and SEO & Meta
specifically, rendering the real screen after the original runs. That
replacement installs itself unconditionally, on every page load — so by the
time anyone actually clicks a sidebar item, it has already been in place for
as long as the page has been open. Confirmed by testing the *unmodified* file
with both script tags loaded together, the way a browser actually loads them:
all six render correctly, right now, with zero changes.

A fix was written for this before the mistake was caught — removing those
keys from `FRAME_SRC` and wiring them into the first script's own dispatcher.
Building it surfaced a second, real problem: the two script tags do not share
a scope, so the fix would have thrown a `ReferenceError` on every single page
load, breaking the entire console. Caught before packaging, by testing with
both tags present rather than the one being edited — and once both mistakes
were visible together, testing what the *original*, untouched file actually
does made clear neither fix was needed at all. Reverted in full; `app.blade.php`
carries zero net change from this investigation.

Six screens remain genuinely without any backend — Payments, Site Search,
Blog, Posts, HTML Blocks, Media — and stay flagged as such below.

| ▲1 | **Secrets to rotate, overdue.** The HMAC signing secret and doctor token sit in `KBB-Handover.md` §7 in plain text and have appeared in chat. The signing secret authorises a package to be applied to the live server |
| ▲2 | ~~Mobile sticky add-to-cart may be dead~~ — closed, 2.39.0 |
| ▲3 | `/brands/` and `/skincare-guide/` still have no route, and the desktop nav drops the base path |
| ▲4 | Checkout and account forms use different field styling — needs D-35 amended |
| ▲5 | Ledger completion is stale at 36%; the ~44% above is an estimate, not a count |
| ▲6 | ~~The plugin ZIP has never been sent~~ — **closed.** Received and inventoried, 2.44.0 |
| ▲7 | Stated file counts did not match the archive three times running; now resolved by verifying each package against its own manifest before signing |
| ▲8 | No outbound mail has been demonstrated. Blocks password reset, email verification, double opt-in, and now also Abandoned Cart Recovery and Back-in-Stock Alerts — two more Phase 3 modules than before, since both fundamentally require emailing a customer |
| ▲9 | **23 rendered hooks have no JavaScript** at last audit, including the whole review interface — due a re-run since several modules have shipped since |
| ▲10 | Building against a reconstructed copy rather than the live source caused six releases to change nothing. Every package is now built from the source last sent, and a fresh copy is requested whenever the server may have moved ahead |
| ▲11 | **Escaped price markup reached the live checkout** — `Money::format()` returns HTML and must be printed with `{!! !!}`; four places used `{{ }}` and showed raw tags under the order total. Fixed in 2.50.0; `markup_check` gate added |
| ▲12 | **A duplicate JS declaration blanked the entire admin console** for one release (2.48.0). Fixed in 2.48.1; `js_check` gate added; recovery procedure for this failure mode is now documented in the handover |
| ▲13 | Two admin screens were mocks with no backing data (Modules, Product Labels) — found and replaced. Worth a deliberate sweep of the remaining screens to check none of the others are the same |
| ▲14 | **Corrected after further checking — see the note below the table.** Six of twelve `FRAME_SRC`-mapped screens (Orders, Customers, Quiz Leads, Analytics, Business Details, SEO & Meta) already work correctly via an override block1 installs on page load; only six (Payments, Site Search, Blog, Posts, HTML Blocks, Media) are genuinely iframe-only with no backend behind them |
| ▲15 | **The JSON checkout API (`Api\CheckoutController`) redirects to the success page with the order's `id`, not its `order_number`** — `?order={$order->id}` vs. the success page's lookup by `order_number`, which is `10000 + id`, not the same value. Any order placed through that endpoint likely never resolves on the success page ("Order not found"). Found while tracing Marketing Pixels' injection points; not fixed, since it's a different controller's separate concern and deserves its own look — worth checking what actually calls this API path in production |
| ▲16 | **A shipped feature (2.58.0's wishlist heart) had a live CSRF bug — caught by Rafi using it, not by testing.** Sent `X-XSRF-TOKEN` paired with the raw session token; Laravel wants that header paired with the encrypted cookie value specifically, or the raw token under `X-CSRF-TOKEN` — never mixed. The test written for this called the controller directly, which bypasses CSRF middleware entirely, so the mismatch was invisible until a real browser hit it. Fixed in 2.58.1, re-verified through the actual HTTP kernel with middleware active. **Every future endpoint doing its own `fetch()` POST should be tested the same way** — through the kernel, not the controller in isolation — or this exact class of bug can recur silently |
| ▲17 | **2.60.0 gave every product page a 500 — caught by Rafi, not by testing, again.** `product.blade.php` was changed to `@include('partials.reviews')`. That file was real, correct, and sitting right there in the build sandbox — and had never once been shipped in any package. Every prior test rendered fine because the sandbox has the file; the live server never received it. Same root failure as ▲16 in spirit: testing against the wrong copy of reality. **New permanent gate: `shipped_check.py`**, run against the full source tree before every package from now on — it finds every `@include()` and `resource_path()` reference across all Blade views and controllers and flags any target that has never appeared in any previously-shipped package zip. Run on the current tree immediately after this fix, it also caught three more pre-existing references (`footer.blade.php`, two checkout partials) never resolved either way — shipped in the same release rather than left as an open question. **This gate now runs before every package, no exceptions** |
| ▲18 | **`shipped_check.py` (▲17) only checked Blade `@include()` — the same shape of bug recurred one layer down.** Wired a new admin diagnostic route to `HealthApiController::class` in 2.60.2; the controller file itself had never shipped either, same root cause as ▲17, just via a PHP class reference in a route instead of a Blade include. Extended the gate to also parse `Class::class` references and `use` statements against the shipped-file history. Attempting to run the extended gate against the *whole* codebase immediately produced ~250 false positives — files like the base `Controller` class and `HomeController` that have obviously been live for the entire engagement, never once shipped as an incremental patch because they predate the first one. **The gate needs a real baseline (the original bulk deployment, not just incremental package history) before the PHP-class check is trustworthy for anything but a single new file added by the current diff** — noted as unfinished, not resolved |
| ▲19 | **Diagnosis without real production access wasted an entire session's worth of turns.** A product-page 500 (missing `id` on demo-review fixture data) took five released packages and multiple wrong hypotheses to actually find, because every fix was verified against a sandbox that could not reproduce the real server's state. The turning point was `kbb-doctor.php` — a standalone, pre-existing diagnostic script at the public web root that reads the real error log directly, independent of whether Laravel itself can even boot. **This should be the first thing checked for any live-site bug report, before any hypothesis-driven sandbox work**, not a last resort reached after several failed guesses. Later extended (during the 405 investigation below) to also list the contents of the public web root itself — a different directory from the app it reports on by default, and worth checking whenever the app's own error log stays suspiciously silent |
| ▲20 | **A deep, real bug found by testing the updater's own failure path, not by assumption.** Every update runs `config:clear`/`cache:clear` mid-request via `Artisan::call()`; running Artisan commands inside a live HTTP request can rebind the container's session service to a second, disconnected instance. The code clearing the "pending update" flag used the bare `session()` helper, which after that rebind pointed at an object never actually saved when the request finished — so a successful apply still left the UI stuck showing the same package as pending, forever, on every refresh. Confirmed directly inside one continuous request (no multi-request test-harness risk) before and after the fix. **Every place session data has to survive to the end of a request should use `$request->session()` explicitly, never the bare helper, in any code path that also runs Artisan commands.** A second, permanent safety net was added alongside it: any pending record whose version already shows `applied` in release history is now self-healing, auto-cleared on the next status check regardless of cause — closing the entire category of "it says applied but still shows pending," not just this one instance |
| ▲21 | **A self-benchmarked zero-benefit change caused a real production outage.** 2.60.4 added automatic `route:cache`/`config:cache` after every update, reasoning an uncached app had a real if small cost. Benchmarked before shipping and found no measurable difference either way — shipped anyway on the theory the downside was zero. It broke the live homepage with a 405 for reasons that never reproduced in any sandbox: a cached routes file takes total priority over the real source the moment it exists, and can be stale or wrong in ways sandbox testing won't surface. **The fix was to remove the optimisation, not debug its specific failure mode** — a proven-zero-benefit change causing a real incident is reason enough to revert outright. Turned out, after reverting, the 405 persisted anyway and traced to a stale browser-cached POST resubmission on the client side, unrelated to the server entirely — but the caching removal stands regardless, since it was never earning its risk. **Lesson for future infrastructure changes to the updater itself specifically: require a proven need, not a theoretical one, before touching anything in the apply path** — that code runs unattended, on production, with no easy rollback if it's the thing that's broken |

| ▲22 | **`header.blade.php` had a separate, hard-coded nav loop that was never replaced when `nav-bar.blade.php` was built** — the reason horizontal scroll, missing dropdowns, and a missing Super Sale highlight all persisted on the real site across many releases, despite every isolated preview of the real partial rendering correctly. Same root shape as ▲17 and ▲19: testing a component in isolation proves the component works, not that the page actually uses it. Fixed in 2.60.24 by including the real partial and re-verifying through the full view-composer chain at multiple screen widths, not the partial alone |
| ▲23 | **`HeaderSettings::save()` replaced its entire stored settings blob with only what the calling screen submitted, instead of merging.** Invisible for months because only one screen (Header) ever wrote to that store. Became a live data-loss risk the moment Site Search started writing to the same underlying storage in 2.60.35 — saving either screen could have silently reset the other's settings to their defaults on its next save. Fixed to merge into the existing saved values; confirmed directly by saving both screens in sequence and checking neither's fields were disturbed by the other's save. **Any future settings screen sharing storage with an existing one should assume the same risk exists until checked** |
| ▲24 | **A `withCount` + `having` query against SQLite was throwing a real SQL error on any category or brand name search — unrelated to and pre-dating the Extended Search Results work that happened to surface it.** SQLite requires an explicit `GROUP BY` in this shape that Laravel's query builder doesn't add on its own. Reproduced independently of the new feature before fixing, to confirm it wasn't something the new code had caused. Fixed with an explicit `groupBy()` on both the category and brand match queries |
| ▲25 | **Design work approved in preview was not fully carried into the shipped code, twice, on the same feature.** A vertical divider between mega-menu columns was shown, iterated on, and approved across several rounds of real preview files — and was never actually present in either of the two packages that followed. Caught only because Rafi compared the live result directly against the preview file rather than trusting the release notes. No systemic fix proposed yet beyond the general lesson: a preview being real and interactive (per the standing rule) proves the *design* was tested, not that it was *shipped* — those need to be checked separately, especially when a change spans several back-to-back small packages |
| ▲26 | **A package being built, packaged, signed, and handed over is not the same as it being applied — and nothing was checking for the difference.** Five packages (2.60.37 through 2.60.41) were delivered as finished work over several turns; the live server was genuinely still at 2.60.36 the entire time, stuck exactly where the Core Updates screen broke (▲ entries above). Every status claim in between assumed success from a successful *handover*, not a verified *install*. Only surfaced because Rafi separately uploaded the real server files for a GitHub sync, and a direct file comparison caught it — not anything in the update flow itself. **No fix shipped yet.** The real gap: nothing after delivering a zip ever asks "did this actually land," and for a project shipping this many incremental patches, that assumption compounding silently for five versions in a row is exactly the failure mode to design against next — a version number visibly confirmed post-apply, not just a package handed over, should probably gate any packages after it |
| ▲27 | **Two live, working secrets — `kbb-doctor.php`'s access token and a second one in `kbb-recover.php` that hadn't been directly confirmed before — were captured in plain text the moment those files were actually read**, during the same file comparison that found ▲26. Redacted before anything touched GitHub, private repo or not — a credential live on the production server right now has no business sitting in git history in the clear, regardless of who can see the repo. Doesn't change the standing rotation item already in this register; confirms it's still live and now shows exactly where the second token lives too |

| ▲28 | **The defect this project actually has is a screen stating what the code does not do.** Twenty-odd separate instances were found across 2.60.126–.185 by one method, and it is worth naming as a method rather than a run of luck: look for **disagreements between two things that must agree** — a control and the code that saves it, a template field and the endpoint that feeds it, a sentence of help text and the mechanism it describes, a setting and its reader, a route and its capability entry. Every one of these was invisible to normal use: nothing crashed, nothing errored, and several had been wrong since the day they shipped. A badge the Orders table had rendered since Demo Content launched, fed by an endpoint that never sent the field, had **never once appeared**. Cross-check mechanically wherever possible — a script listing every `id="set_*"` against every key in the save payload finds things reading never will |
| ▲29 | **`public/build` is tracked as the record of server state, and `npx vite build` empties it.** A rebuild deletes ~34 historical hashed assets git knows about, and a package that deletes files is exactly what took the product pages down in 2.60.102–.106. The discipline: rebuild, then `git checkout --` every path showing `D`, so the commit adds the new bundle and moves one line of the manifest. Separately, **running the full test suite has been observed to delete a tracked asset from that directory** — check `git status --short public/build` before every commit and never `git add -A` without looking |
| ▲30 | **`bootstrap/` cannot reach the server, so a fix that needs it does not exist in production.** `BuildPackage::NEVER_SHIP` excludes it because UpdateGuard forbids it — a bad `bootstrap/app.php` stops the application booting and would leave the updater unable to roll itself back — and the host has no shell. This was found the expensive way: the `kbb_tz` cookie-encryption exemption is correct, shippable nowhere, and would have tested green for ever because the suite boots the real `bootstrap/app.php`. The fix that works reads `$_COOKIE` directly, and there is a test that fails if anyone reverts to the version that only works locally |
| ▲31 | **A regex guard reads comments and quoted strings as code.** Six separate lanes wrote a guard that then failed on its own explanatory prose — the file describes the bug it fixed, and the guard finds the description. Strip `T_COMMENT`/`T_DOC_COMMENT` with `token_get_all()`, or assert on rendered output where a comment cannot reach. Related: **a class-name search of rendered admin HTML also matches the page's inlined CSS**, so assert on elements with `preg_match_all`, never on a bare class name |
| ▲32 | **Verify a lane's headline claim independently before merging it.** Counts and diagnoses arrive confidently and are sometimes wrong in ways that change the remedy: "sixteen screens cannot be deep-linked" was seven; "149 hand-typed colours need fixing" was thirteen, and most of the rest were *correct* colours whose conversion would have made text unreadable; "two screens hold duplicate delivery wording" was two tabs of one screen holding two different kinds of value, where no country could have used one field for both. Each of those was caught by the next lane checking rather than inheriting. A confident report is a hypothesis |
| ▲33 | **A preview that serves stale bytes will send you chasing a defect that does not exist.** Several hours went into an admin screen that "had not changed", which was a dev server started before the merge, on a port a kill had not actually freed. Before trusting any preview: confirm the listening PID is the process you started (`ss -ltnp`, then `/proc/<pid>/cwd`), clear compiled views, and check one string you know changed. Never `pkill` broadly — one lane killed another lane's server that way |
| ▒34 | **Invented content is a publishing decision, not a rendering detail, and `noindex` is not the remedy.** `/reviews` shipped twelve fabricated customers with a sitemap entry; `/app` still answers a public URL with a hard-coded catalogue at prices that are not real; Demo Content's `reviewSummary()` returns 12,481 reviews at 4.8 stars, and its seeded samples are written `'verified' => true`. Each was reachable by a customer. A crawler directive keeps a page out of a result list and does nothing about a bookmark, a shared link, or a screenshot. The test to write is not "is it labelled" but **"can a logged-out visitor see a figure that is not in the database"** — and it should be asserted on rendered output, per ▒31 |
| ▒35 | **The container restarts, and everything not committed is gone.** A restart mid-session took two lanes' unfinished work with it; nothing in the repository was lost because every merged lane had already been committed and pushed. The discipline that saved it: merge and push each lane as it lands rather than batching several, and keep the integrator branch's working tree clean between merges, so the worst case is re-dispatching a brief rather than reconstructing a diff |
| ▒36 | **A guard is only as wide as the thing it can see, and silence is its failure mode.** Four separate instruments in this suite were found checking nothing while being counted as coverage: a mobile-drawer walker whose selector named an element that does not exist (36 links unchecked); a tab-bar selector looking for a class `tb` on an element classed `tabbar`, which had never matched on any run; an admin-nav walk whose empty dispatch map excused every screen; and an ordering guard written in SQLite's quoting that reported nothing on MySQL. The shape is always the same — a parse or a match whose failure returns **nothing** rather than failing. Every extractor must assert it found something before asserting anything about what it found, every floor must be per-region so one section cannot mask another, and any test that reads compiled SQL must accept both engines' quoting |
| ▒37 | **The exclusive-VAT feature would have shipped a contradiction the day it was switched on, and no test would have failed.** The product page printed "Inclusive of {rate}% VAT" as a literal at the global rate; the checkout charged the country's rate on the country's basis. Both were correct in isolation and disagreed only in a configuration nobody had yet selected. The lesson generalises past tax: **a feature that adds a new configuration adds a new set of screens that must be re-read in that configuration**, and "it is latent today" is a statement about the current settings, not about the code |

## Questions still unanswered

1. Which WordPress plugins does the live site depend on?
2. ~~Frontend stack~~ — answered by the amendment to D-38.
3. What user roles exist in the system?
4. Which is the live WordPress address for brands, and for the blog?
5. Does SE-05 want a distinct search results page, or is `/shop/?s=` the answer?
6. **Mail provider** — what should send outbound email (SMTP credentials, or a
   transactional service like Postmark/SES)? This has quietly grown from
   blocking password reset alone to blocking five separate things, two of
   them full Phase 3 modules (Abandoned Cart, Back-in-Stock).
7. **kbb-theme source** — needed to build `legal_notice` and
   `inline_validation` faithfully; both are currently only a settings link
   in the plugin with no implementation to port.
8. **Google Places API key** — needed for `address_autocomplete`, on top of
   the same kbb-theme source requirement.
9. **Orphaned `build 5/` folder** at the public web root, alongside the real
   `build/` — nothing references it, almost certainly a leftover from a
   manual ZIP upload before the automated updater existed. Low priority,
   but flag before delete: confirm with Rafi it's safe to remove rather
   than assuming.
