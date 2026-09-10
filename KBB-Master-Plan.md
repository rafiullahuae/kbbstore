# KBB Master Plan — live tracking

**As at version 2.60.41.** Tick items as they ship; add the version that shipped them.

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
| Releases shipped this stretch | 2.38.0 → 2.60.41, 63+ packages — **2.60.13 → 2.60.21 shipped in a prior session not summarised here; this file's own direct visibility resumes at 2.60.22.** Nothing in that gap is claimed below beyond the version number itself. Every applied patch from 2.60.41 onward is archived and downloadable directly from the server — see Core Updates → history table |
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
- [ ] Filters and sorting on the results page
- [ ] Synonyms and misspelling tolerance — partially covered by Extended Search's brand
  splitting, but not general misspelling tolerance

## Phase 5 — Product cards and grids

- [x] 28 skins · 34 settings · columns per device · shortcode builder
- [x] Full product titles — *2.40.3*
- [ ] Card skin preview inside the shortcode builder
- [ ] Quick view

## Phase 6 — Product page

- [x] Gallery, tabs, badges, price, bundles, 17 sections
- [x] Sticky add-to-cart — *2.38.0 → 2.39.0*
- [ ] ▲ **Reviews: the entire customer side is dead markup.** Ten hooks rendered,
      no `reviews.js` in the build, no submit endpoint. Shoppers cannot leave a review
- [ ] Related and recently viewed — exists; to be registered as `recently_viewed`

## Phase 7 — Account area

- [x] Sign in, register, dashboard, orders, addresses, forgot, track order
- [x] Field component, password strength, autofill, sum guard, account panel
- [ ] ▲ **Password reset actually sends an email.** No outbound mail has been
      demonstrated. Blocks email verification and newsletter double opt-in
- [ ] Address book: add, edit, delete, default
- [ ] Order detail page with line items and status history
- [ ] Track-my-order against real orders

## Phase 8 — Cart and checkout

- [x] Cart drawer, live throughout — *2.39.x → 2.40.1*
- [x] Cart page: quantity, remove, coupon, totals — *2.39.x*
- [ ] ▲ **Checkout styling** — agreed to move checkout to `<x-field>`, **requires D-35
      amended**, and to be tested against a real order
- [ ] Coupons and gift notes end to end
- [ ] Guest checkout → account creation

## Phase 9 — Content pages

- [x] Privacy, terms, New In, Best Sellers, Super Sale, Under 54 AED, Wishlist
- [x] `/subscribe` — *2.38.0*
- [ ] ▲ `/brands/` — **URL conflict**: the homepage uses `/brands/`, `MenuDemo` uses
      `/korean-skincare-brands/`. Which is the live address?
- [ ] ▲ `/skincare-guide/` — a permalink structure, not one page: the homepage builds
      `/skincare-guide/{slug}/`, the router serves `/blog` and `/post/{slug}`
- [ ] Blog
- [ ] Desktop nav drops the base path — Home, New In, Best Sellers, Shop point at the
      domain root instead of `/kbb-upgrade/`

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
- [ ] Structured data — sitewide + product now real; category, blog, and article
  schema still open
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
- [ ] Newsletter double opt-in — waits on outbound mail
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
