# Changelog

Versions are the numbers used by the Core Updates screen. Each entry lists the
files it touched, so a diff can be checked against it.

## 2.60.257
The third and last cause of the notch beside the phone logo, found by the owner
in DevTools: `kbb.css:1612` carries a bare `.logo{flex:1;text-align:center}`
inside its own 900px media query -- the site header's mobile centring, which the
checkout's logo inherited. It is also why two rounds of measuring said "fixed":
`flex:1` stretches the ELEMENT and `text-align:center` moves the GLYPHS inside
it, so the box's left edge stayed correct. Measured at 390: box left 20, first
letter 47.2. After: letters at 20, and at 140 at 1280 -- both equal to the
page's own edge.

The back-to-top arrow was knocked off centre by a rule shipped in 2.60.255: the
ruled-rows shape treated it as a row. Button centre x 355 y 2312.6 against glyph
centre x 348.5 y 2316.1; now the same point. It also waits until the page has
been scrolled, via a sentinel and an observer rather than a scroll handler.

And both presets now move only the tab in front of you, on the checkout screen
and on the footer screen -- a preset that reaches past the screen changes numbers
nobody can see.
`css/kbb/kbb-checkout.css`, `partials/slim-footer.blade.php`,
`admin/partials/checkout-page-screen.blade.php`,
`admin/partials/slim-footer-screen.blade.php`, `Services/CheckoutPage.php`,
`2026_12_11_000000_clear_caches_logo_arrow_and_per_tab_presets.php`

## 2.60.256
The floating Place order bar now waits for an order that can actually be placed:
it asks `form.querySelector(':invalid')` -- the same test the button itself uses
-- plus the address, delivery and payment, which constraint validation cannot
see because the chosen address lands in hidden inputs and a hidden input is
never `:invalid`. Driven at 390px: hidden on an empty form, hidden with the
fields filled but no address, shown once the address is chosen, hidden again the
moment the in-page button scrolls back or a field is cleared.

The notch beside the phone logo had a SECOND cause, found by sweeping 36
combinations rather than reasoning about it: `--cop-headpadx` is a control
separate from the page's `--cop-padx` and defaults to the same 20 only by
coincidence. In "lined up with the page" mode the header now takes the page's
padding -- 0 of 36 combinations misaligned, against several before.

And the footer lines up with the page: `width_mode` defaults to `page` and takes
the checkout's own inherited `--cop-d-max`, so the two cannot drift. The two
policy links move under the wordmark, in the markup rather than with `order` --
flexbox cannot put one sibling inside another's column.
`Services/SlimFooter.php`, `css/kbb/kbb-checkout.css`,
`store/checkout.blade.php`, `partials/slim-footer.blade.php`,
`admin/partials/checkout-page-screen.blade.php`,
`2026_12_10_000000_clear_caches_float_gate_and_footer_align.php`

## 2.60.255
The checkout footer wears the site header's own wordmark. It was drawing
`brand`, a flat text box shipping "K-BEAUTY BLISS" in capitals; it now reads
Appearance -> Header's Wordmark, Accent word and two colours, live, on every
render -- so "K-Beauty" in the header's ink and "Bliss" in the header's accent,
and renaming the shop stays one edit in one place. Not a second colour box: two
copies drift, and the one that drifts is whichever nobody looks at. The two
colours are compared against the HEADER's defaults, so a shop that has touched
neither screen still renders a footer with no style attribute, and they are safe
in a declaration because HeaderSettings::cast() answers a `colour` row with a
six-digit hex or the shipped default. `The wordmark` -> `The typed brand name`
restores the old box.
It also gains the spacing controls it was missing: space above the bar (a margin
and not padding, so the page's own ground shows through it rather than the bar's
tone), padding inside each ruled row and a floor under every row -- the rows used
to derive their padding from the block gap, so the only way to open them was to
open every gap in the bar at once -- plus "Squeeze the bar" and "Back to
defaults". Every one ships at what the bar was already doing; measured, 55px on
a desktop and 187.8px on a phone before and after.
`Services/SlimFooter.php`, `partials/slim-footer.blade.php`,
`Admin/SlimFooterApiController.php`, `admin/partials/slim-footer-screen.blade.php`,
`2026_12_09_000000_clear_caches_footer_wordmark_and_spacing.php`

## 2.60.254
Three selects that were rendering as sliders, the notch beside the phone logo,
the reviews line's wording, and the footer's own shape on a phone.

`checkout-page-screen.blade.php` handled `bool` and returned an
`<input type="range">` for everything else, so `ph_tone` and `ph_weight`
(2.60.252) and `m_float` (2.60.253) each drew as a slider with no scale showing
a value it could not represent, and saved as `NaN`. The screen now draws
`select` and `text`, and the handler branches on the schema's type rather than
on the DOM element's.

`.co-head .in` centres a band narrower than the window. Measured at 390px with
the mobile header width at 280: the band ran 55…335, the logo started at 75 and
the page's own "Back to shop" started at 20, while the badge overflowed to 370
— the right edge flush, the left notched. The phone now takes the page's own
edges by default; the class restores the centring.

"Go back to cart" gets its own tab, because the controls shipped under ten
spacing sliders and could not be found. The reviews line gets its wording back
without getting its figures back: `{rating}` and `{count}` are substituted from
approved reviews and a template carrying any other digit is refused. And the
footer gets a phone shape of its own — ruled rows on a phone, spread-to-both-
edges on a desktop — with the WhatsApp mark in WhatsApp green.
`Services/CheckoutPage.php`, `Services/SlimFooter.php`,
`css/kbb/kbb-checkout.css`, `partials/checkout/reassurance.blade.php`,
`partials/slim-footer.blade.php`, `admin/partials/checkout-page-screen.blade.php`,
`2026_12_08_000000_clear_caches_checkout_selects_and_footer_phone.php`

## 2.60.253
The band above the checkout header, and two controls under it. `kbb.css` carries
a bare `section{padding:52px 0}` and `.kbb-checkout` IS a `<section>`, so every
checkout inherited 52px of page background above the secure-checkout bar and
52px of nothing below the last block — measured in Chromium, `.co-head`'s own
top read 52 at 1280 AND at 390. The section now declares its own padding, from
two controls that ship at 0. "Go back to cart" gains a size, an arrow size and a
corner radius per surface, plus a tap height on mobile that starts at the 44px
Lane BM measured and raised it to. And the phone-only Place order bar now waits
for the in-page button to leave the viewport and goes the moment it returns —
IntersectionObserver, not a scroll handler, because the button moves as an
address is chosen. Two defaults change the page on purpose, both asked for: the
band is removed, and the floating bar is drawn where it was not.
`Services/CheckoutPage.php`, `css/kbb/kbb-checkout.css`,
`store/checkout.blade.php`, `2026_12_07_000000_clear_caches_checkout_shell_and_float.php`

## 2.60.107
Corrects 2.60.102–.106. Three files had been edited against a stale base — my
working copy of the server was the 2.60.71 snapshot with the 2.60.98 package
overlaid, and files changed in 2.60.72–.74 are not in that package. Applying
.102 or later would have reverted the 2.60.74 seoCtx closure fix (500 on every
product page), the Quick view button and its module gate, and the quick-view
modal shell. All three rebuilt on the correct base with the .102/.103 changes
reapplied.
`Store/ProductController.php`, `components/product-card.blade.php`,
`layouts/store.blade.php`

## 2.60.106
Reviews. The admin Reviews screen read product_slug, author, body and likes —
none of which is a column on `reviews`; the real ones are product_id,
author_name, content and helpful, so every review rendered blank. Both API
review endpoints keyed on the same phantom column and had never worked: the
read raised SQLSTATE 42S22, the write failed at the insert. GET /api/reviews
returned whole models with no status filter, exposing author_email and ip plus
unmoderated rows; now approved-only, named columns, capped, with author_email
and ip added to the model's $hidden. Posts endpoints given named columns.
`AdminController.php`, `Api/ProductController.php`, `Api/ReviewController.php`,
`Api/PostController.php`, `Models/Review.php`

## 2.60.105
POST /api/checkout/session — the one public endpoint that creates an order.
No visibility filter, so hidden products could be bought; no stock check, so
out-of-stock items were sold; `sale_price ?? price` ignored sale_starts_at and
sale_ends_at, so expired sales kept charging the sale price and scheduled ones
sold early; and 'brand' stored the belongsTo relation instead of its name.
`Api/CheckoutController.php`

## 2.60.104
Security headers were absent from every /api response — SecurityHeaders is
appended to the web group, and api routes do not inherit it. robots.txt let
crawlers into /cart, /my-account, /my-wishlist, /wishlist, /track-my-order. The
web-root deletion migration now uses public_path(), which bootstrap/app.php
sets to the real directory.
`routes/api.php`, `SeoFilesController.php`, `2026_09_13_150000_*.php`

## 2.60.103
og:image, twitter:image and the schema image were relative paths — Url::media()
returns root-relative, which Facebook, WhatsApp and X drop silently and Google
reports as invalid. Absolutised. priceValidUntil added to every Offer.
`Support/Seo.php`, `Store/ProductController.php`

## 2.60.102
AddToCart was missing from every pixel, so the funnel jumped ViewContent ->
InitiateCheckout. Added for Meta, GA4 and TikTok as one delegated listener,
with price from effectivePrice() on the button.
`MarketingPixels.php`, `layouts/store.blade.php`, `product-card.blade.php`,
`product-grid.blade.php`, `partials/home/grid.blade.php`, `quick-view.blade.php`

## 2.60.101
Deletes the four unauthenticated web-root scripts (kbb-patch-file, kbb-fix-now,
kbb-unstick, kbb-check-schema) via a migration, since the web root is outside
the paths the update system writes to. doctor and recover are kept — both are
token-checked.
`2026_09_13_150000_remove_ungated_webroot_scripts.php`

## 2.60.100
Eighteen further unescaped sites across every admin screen, found by sweeping
rather than by looking where the last bug was: review title and body, the reply
modal, Quiz Leads, Subscribers and their three modals, Customers, order line-item
brands, dashboard top-product brands, the redirects code pill. Two flagged sites
left alone after reading them — a search filter comparing text, and an HTTP
status in an Error string. Also folds in 2.60.99.
`admin/app.blade.php`

## 2.60.99
Core Updates reported "Version 1.0.0" on a server at 2.60.98. Three call sites
read config('kbb.version') -> env('KBB_VERSION'), never set here. New
InstalledVersion reads the newest 'applied' row from update_releases. Not
cosmetic: UpdatePackage compares requires_version against the same value, so any
package declaring a prerequisite above 1.0.0 would be refused by a server that
already exceeded it — which is why no package here has ever set one.
`InstalledVersion.php`, `UpdatePackage.php`, `UpdateRunner.php`,
`UpdateController.php`, `UpdateApiController.php`

## 2.60.98
Stored XSS in the admin console. Seven places concatenated customer-supplied
strings into innerHTML unescaped: the customer name on the dashboard feed, the
orders table, the order detail address block and line items, the review author
in the reviews table and reply modal, and top-product names. An order name or a
review author is whatever an anonymous visitor typed, and it executed with the
admin's session on the origin where /admin-api answers. All now pass through
sesc(), which the file already defined and used in ~90 other places.
`admin/app.blade.php`

## 2.60.97
SVG uploads could carry script. MediaUploadController allowed svg, which is XML
and can hold <script>, on* handlers, javascript: URLs, foreignObject and entity
declarations. Written to the public web root, that is stored XSS on this origin.
Eight markers now checked and rejected by name; content is entity-decoded first
and read as text, never parsed. Bitmaps unaffected.
`MediaUploadController.php`

## 2.60.96
The review captcha was bypassable. /reviews/submit had a honeypot, a captcha and
a 5/hour limit; POST /api/products/{slug}/reviews reached the same table with
none of them, and routes/api.php had no throttling at all. Controller-level
limit and honeypot added, sharing one key with the storefront form, plus
throttles on all four public POSTs.
`routes/api.php`, `Api/ProductController.php`

## 2.60.95
Public API was leaking. GET /api/settings returned the entire settings table
unauthenticated, including admin_path and indexnow_key; now allowlisted to
eleven presentational keys. /api/products/{slug} and /api/posts/{slug} had no
visibility filter, serving drafts and hidden products by slug. /api/products
filtered on status 'active', which products never use, so it returned nothing —
the bug that hid the missing access rules.
`Api/SettingController.php`, `Api/ProductController.php`, `Api/PostController.php`

## 2.60.94
The sitemap contained zero products. It filtered status 'active'; products use
'publish'. is_visible and deleted_at were unchecked too. Categories were listed
under /category/{slug}, which has never been a route. Product URLs lacked the
trailing slash and so pointed at redirects.
`SeoFilesController.php`

## 2.60.93
The journal was published at one URL and served at another. Routed at /blog and
/post/{slug}; four places published /skincare-guide/ and nothing matched it.
Canonical tags, breadcrumb schema and IndexNow all submitted /post/{slug}, which
the site never linked. /skincare-guide/ and /skincare-guide/{slug}/ are now the
real routes with 301s from the old pair.
`routes/web.php`, `PageController.php`, `AppServiceProvider.php`,
`blog.blade.php`, `post.blade.php`, `admin/app.blade.php`, clear_caches migration

## 2.60.92
Every brand URL was a 404. The homepage linked /brands/ twice, MenuDemo built
/korean-skincare-brands/ and /brand/{slug}/, and none had a route. /brands/ is
now a real A-Z index; the other two 301 to it and to the filtered listing.
`BrandController.php`, `brands.blade.php`, `routes/web.php`, clear_caches migration

## 2.60.91
Module statuses corrected. `product_sorting` and `seo_engine` were marked
`todo` and are both built; verified before changing.
`app/Services/ModuleRegistry.php`

## 2.60.90
Autocomplete now expands synonyms like the results page has since 2.60.77.
Per-order `gift_fee` column records what wrapping actually cost, rather than
recomputing it from the current setting. `AdminPathService` cache leak closed.
`SearchController.php`, `CheckoutController.php`, `AdminOrderController.php`,
`Order.php`, `AdminPathService.php`, `admin/app.blade.php`,
`2026_09_13_120000_add_gift_fee_to_orders.php`

## 2.60.89
Gift settings never saved: `gift_enabled` and `gift_fee` were absent from the
settings endpoint's allowlist, which skips unknown keys and returns ok anyway.
Separately, that endpoint wrote with `Setting::updateOrCreate()` and cleared
neither settings cache, so every value saved from Business Details reached the
database and was then ignored by the storefront.
`AdminController.php`, `admin/app.blade.php`

## 2.60.88
Gift setting given one source of truth: a migration seeds the row, and nothing
guesses a default in two places any more.
`2026_09_13_110000_seed_gift_settings.php`, `admin/app.blade.php`,
`CheckoutController.php`, `checkout.blade.php`, `order-block.blade.php`

## 2.60.87
Gift tick was hidden (default off), the checkbox sat on the text baseline
(`.form-row label{display:block}` outranked the rule meant to override it), and
the summary row showed at 0 (`.sumrow{display:flex}` outranks `[hidden]`).
`checkout.blade.php`, `order-block.blade.php`, `CheckoutController.php`,
`admin/app.blade.php`

## 2.60.86
Checkout 500. `...gift@if (...)` — Blade's directive pattern starts `\B@`, so a
directive whose `@` follows a word character is never compiled, while its
partner is. The compiled view held `endif;` with no `if:`.
`checkout.blade.php`

## 2.60.85
Cache-clear migration for the new route (stale `bootstrap/cache/routes-*.php`
overrides `routes/web.php`), and the Gift wrapping tab made scope-independent —
it read `SETTINGS` from another script scope, which killed the whole Delivery &
Shipping screen.
`2026_09_13_100000_clear_caches_2_60_85.php`, `admin/app.blade.php`

## 2.60.84
Gift settings moved to Store → Delivery & Shipping as a third tab; the card
added to Business Details in 2.60.82 removed.
`admin/app.blade.php`

## 2.60.83
Admin sidebar clipped: Store has 20 entries, `.nav-sub` opened to 560px with
`overflow:hidden`, so Business Details, Customers, Quiz Leads and Content &
Pages were unreachable.
`admin/app.blade.php`

## 2.60.82
Gift wrapping as a priced, merchant-controlled option. Fee read from settings,
never from the request; choice held in the session so every totals path agrees.
`CheckoutController.php`, `checkout.blade.php`, `order-block.blade.php`,
`routes/web.php`, `admin/app.blade.php`

## 2.60.79–.81
Checkout layout. The account, notes and gift blocks had been inserted inside
`<div class="row2">`, a two-column grid, so they became grid children and split
Email from Phone. Moved below the grid; notes and gift then moved into the
Delivery section; checkbox metrics matched to the existing WhatsApp row.
`checkout.blade.php`

## 2.60.78
Gift notes end to end, and `customer_note` — on the orders table since the
original schema, returned by the admin API, never captured and never rendered.
`2026_09_13_090000_add_gift_and_order_notes.php`, `Order.php`,
`AdminOrderController.php`, `CheckoutController.php`, `checkout.blade.php`,
`admin/app.blade.php`

## 2.60.76–.77
Guest checkout → account creation. The password is only ever written into a
blank, never over an existing one, and `legacy_password` counts as set, so the
3,712 imported customers cannot be trampled. Search synonyms via
`App\Support\SearchTerms`, with over-broad entries removed and a four-character
floor on expansion.
`SearchTerms.php`, `ShopController.php`, `CheckoutController.php`,
`checkout.blade.php`

## 2.60.75
Admin Catalog 500'd selecting a `category` column that has never existed on
`products` — categories and brands are foreign keys. Also removed an N+1 of
roughly 1,300 queries per screen load.
`AdminController.php`

## 2.60.74
Every product page 500'd. `ProductController::show()` builds `$summary` and the
`seoCtx` closure imported only `$product`.
`ProductController.php`

## 2.60.73
Module switches for quick view and the address book, registered in
`ModuleRegistry` with per-device visibility. Off means gone: no button, no
shell, endpoints 404.

## 2.60.72
Account address book (list, add, edit, delete, default per type) and product
quick view. No schema change — the `addresses` table and its model already
existed; the page was a 12-line stub that printed "No addresses saved yet"
without ever querying anything.
