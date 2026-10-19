# K-Beauty Bliss app — live previews, English and Arabic (Lane APV2)

Two separate apps, one shared engine. Open from this folder (`file://` works) or from a phone:

| | App | Every screen, live |
|---|---|---|
| English (LTR, English only) | `en/index.html` | `en/screens.html` |
| Arabic (RTL, Arabic only, Cairo font) | `ar/index.html` | `ar/screens.html` |

`index.html` here only offers the two. The first single-page prototype is kept as
`v1/index.html` for reference.

## Using the app

It behaves like the app, not like a slide: tap, scroll, type.

- **Navigation stack per tab** (Home · Shop · Search · Bag · Account): every screen pushes, Back
  pops, switching tabs keeps each tab where you left it. iOS: swipe from the left edge (right edge
  in Arabic) to go back; Android: predictive back from either edge (the page shrinks while you
  drag). Push, pop and tab transitions, mirrored in Arabic; off when the system asks for reduced
  motion.
- **Scrolling:** sticky bars, iOS large titles that fold into the bar, a sticky sort/filter bar on
  listings, pull down at the top of a list to refresh, endless scroll on listings (12 at a time).
- **Shopping:** Add to bag updates the badge and the bag (toast with "View bag"); the wishlist
  heart is kept for the session (a reload keeps it); live search (instant results, recent and
  popular searches; the Arabic app searches in Arabic); sort and filter sheets that really sort
  and filter (concern, price, on sale, brand), with a filter side panel from 840 wide.
- **Bag:** quantity steppers update the totals, the free-delivery bar (AED 199) fills, coupon
  `WELCOME10` gives 10 %, any other code shows the error state.
- **Every card navigates:** product, brand, category, concern, blog, order.

Top bar (outside the phone): System (iOS / Android), Device (iPhone 16 Pro, iPhone SE, iPad mini
with portrait/landscape; Pixel 9, Galaxy Z Flip flat/tabletop, Galaxy Z Fold cover/inner with
Fold ↔ Unfold, Surface Duo 2 spanned), Design A/B/C (Home and Product), **Jump to** (every screen,
grouped), Fit / 100 %, a link to the screens grid and a link to the other language's app. There
is no language toggle: the language is the app. The 123 / ١٢٣ digits choice is only in the
Arabic app, Account → Language & region.

**Every screen has a link:** `index.html#/route?device=<key>` — e.g.
`ar/index.html#/checkout/card?filled=1&device=fold-cover`. Device keys: `ip16 ipse ipad
ipad-land px9 flip flip-tabletop fold-cover fold-inner duo`; extra keys: `d=B|C` (design),
`dg=arab` (Arabic digits), `sheet=filter|sort|emirate|area|bnpl|delconfirm`, `viewer=0`,
`user=0` (signed out), `embed=1` (the phone only, as screens.html uses it), `z=100`.

## Screens (92 entries in the jump list, each in both apps)

- **Home** A/B/C, loading, offline, splash, first-run notifications.
- **Shop:** all categories, all brands (A–Z, searchable), shop all, Best Sellers, Under AED 54.
- **Product** A/B/C (gallery, zoom viewer, tabs or accordions, reviews with photos, bought
  together, pay-in-4 sheet, express Apple Pay / Google Pay on C), on sale, loading, error.
- **Category** (banner, about, sub-category chips, grid, sort/filter; concern pages), filter,
  sort, no matches, loading. **Brand** (banner, logo, about, grid), filter.
- **Search** empty / results / no results, barcode scanner.
- **Bag & checkout — every step its own screen**, with the step indicator, Back and validation:
  1 Bag review · 2 Contact (signed in, or guest with email) · 3 Delivery address: saved list →
  new address (full name, mobile +971, Emirate picker, Area picker filtered by the emirate and
  searchable, building/villa, street, apartment/floor, landmark, Home/Work/Other, default) in
  empty / filled / error states · 4 Delivery method (standard, express, GCC) and gift message ·
  5 Payment (Apple Pay on iOS / Google Pay on Android, card, Tabby, Tamara, cash on delivery —
  UAE only), instalment amounts and small print · 6 the method's own step: card form (brand
  detection, grouping, expiry, CVC, name) → 3-D Secure, the wallet sheet, Tabby approval,
  Tamara approval, COD confirmation · 7 Review & place order (terms) · 8 Order placed · 9 Payment
  failed → Try again or another method, the bag kept.
- **Account:** signed in / out, sign in, create account (password meter), forgot password,
  Face ID / fingerprint unlock, profile, addresses (add, edit, delete), orders, order on the way /
  delivered (timeline, reorder), wishlist, notification settings, language & region, delete
  account + confirm.
- **Content:** Contact (the WhatsApp button is here and nowhere else — never floating), FAQ,
  policies and each policy, About, Blog list and article.
- **States:** skeletons (home, listing, product, bag, orders), offline banner and screen, error,
  empty (bag, wishlist, orders), update required.

## Fields: the website's floating labels

Every form (checkout, sign in, register, address, contact, card, coupon, 3-D Secure code) uses
the website checkout's `.fld.kbb-fl` (resources/css/kbb/kbb-checkout.css): the label sits inside
the box as the placeholder, centred; on focus or once filled it lifts to a small pink heading in
the top-start corner (left in English, right in Arabic) and the value sits under it. Same 12 px
corners, 1.5 px border, pink focus ring, red error state with the message under the field,
leading icons, `+971` that appears once the label has moved, hints that wait for focus. Prefilled
fields show the lifted state. Phone, card and code digits are left-to-right inside the Arabic
app. Pure CSS (`:focus`, `:not(:placeholder-shown)`), no measuring. Inline validation when you
leave a field, all fields on Continue (with a summary and the first error focused), `autocomplete`,
`inputmode` and `enterkeyhint` on each field.

## Size classes and two panes

Each frame is a CSS container, so it lays out by its own width (system design §3.9):
Compact < 600 (bottom tabs, one pane, 2-column grid) · Medium 600–839 (navigation rail, 3
columns, gallery beside details, checkout centred at 600) · Expanded ≥ 840 (rail, list | detail
pairs: Shop → category, Brands → brand, Account → its sections, Orders → order, FAQ → contact,
Policies → policy, Blog → article; filter side panel; checkout with the order summary beside it
from 1000). Surface Duo spanned: list on one screen and detail on the other (a tap fills the
other screen; nothing is pushed), Product gallery | details, Bag lines | summary, checkout step |
order summary, Home flowing across both; nothing ever sits under the hinge. Flip tabletop:
content above the fold, controls below. Fold cover = compact, Fold inner = medium; iPad portrait =
medium, landscape = expanded.

## Real data

`node tools/apv-data.cjs` reads the live site at build time and writes `data.json`,
`en/data.js` and `ar/data.js` (`--split` rewrites the two from `data.json` without the network).
366 products (all in the categories and brands shown), 45 brands, 21 categories with their
banners and about text, live prices, photos, stock, ratings and reviews with photos. The Arabic
app shows the site's own Arabic names for every product, brand, category and concern, its Arabic
descriptions where the site has them (215 of 366; the others say the description is being
translated and show the key facts), and the Arabic banners and trust row. The English data file
holds no Arabic.

The interface strings of the Arabic app are in `ar/i18n.js` (a missing one is a console error,
which the checker fails on). The site has its blog in English only: the Arabic app shows its own
Arabic summary of each article and says so.

## What is mocked

Payments (nothing is charged, no SDK is loaded; "Simulate a declined card / application" shows
the failure path), push, camera, Face ID / fingerprint, haptics (shown under the phone), share
sheet, WhatsApp hand-off, the signed-in customer (Sara), her addresses and orders, `WELCOME10`,
Express delivery, review photos where the API has none.

## Code

`shared/` holds the engine: `core.js` (data, i18n, state, router), `ui.js` (cards, fields,
validation, formatting), `shell.js` (devices, panes, rail/tab bar, render, transitions),
`screens-shop.js`, `screens-checkout.js`, `screens-account.js`, `events.js` (jump list,
clicks, typing, gestures, deep links, the test hook `window.APV`), `screens.js` (the screens
grid), `app.css`, `screens.css`, `icons.js`. Each language folder has its `index.html`,
`screens.html` and `data.js`; the Arabic one adds `i18n.js`.

## The checker

`node tools/apv-shots.cjs` drives both apps in Chromium: every jump-list entry (Home and Product
in A, B and C) × 10 device states × 2 languages by deep link, then the flows by real clicks and
typing on iPhone 16 Pro, Pixel 9, Fold inner and Duo in both languages:

- **checkout walk:** add from a home card → Bag tab → wrong coupon (error) → WELCOME10 → steps 1 to
  8 by card, with each step's validation (empty email, empty address with summary, empty card),
  typed values and their formatting (`50 123 4567`, `4242 4242 4242 4242`, `12 / 29`), the emirate
  sheet and the area sheet filtered by typing; then the wallet, Tabby, Tamara and COD each back to
  Review approved; a declined card → Payment failed with the bag kept → Try again.
- **navigation smoke:** product → Back → Home; tab switch keeps each tab's stack; the wishlist
  survives a reload; the edge swipe goes back; the search query survives product → Back; sort
  really sorts, the price filter really filters; endless scroll loads more; pull to refresh; on the
  Duo a list tap fills the detail screen without pushing.

Per render and per flow step it asserts overflow, clipped text, overlapping text, icon sizes,
contrast (3:1), tap targets ≥ 44, safe areas, hinge and fold, console errors, plus the
**floating labels** (inside the box; centred at rest; lifted to the top-start corner, small, and
never over the value — in both directions) and, in the Arabic app, **no English**: any Latin word
in text, `aria-label`, `placeholder`, `title` or `alt` fails unless the element is marked
`data-x="<why>"`. The allowed exceptions are counted by reason in `shots/report.json`
(`arabic_exceptions`): `brand` (brand names the site has only in Latin), `term` (Latin product
terms inside the site's own Arabic copy: PDRN, SPF50+, BHA…), `ugc` (reviews written in English,
shown as written), `inci` (ingredient lists), `pay` (Visa, Mastercard, Apple/Google Pay marks,
Tabby/Tamara logos, App Store), `code` (order and error codes, WELCOME10), `email`, `logo` (the
K-BEAUTY BLISS wordmark), `dev` (device names in the preview chrome), `typed` (text the shopper
typed in Latin), `lang` (the word "English" on the button that opens the English app). Digits
are allowed.

Options: `--lang=ar --dev=ip16,duo --only=<label or group> --no-shots --flows-only --no-flows
--workers=4`, and `--mutate='<css>'` to inject a defect and prove the checks fail, e.g.
`--mutate='.fl>input{padding-top:8px}'` turns the label check red.

Output: `shots/en/`, `shots/ar/` (one JPEG per render and per flow step; not in git),
`shots/sheet-<device>-<lang>.jpg` (contact sheets, in git), `shots/report.json`, and `key/`:
`home-en/ar`, `category-fold-inner-en/ar`, `brand-fold-inner-en/ar`, `product-duo-en/ar`,
`ipad-landscape-en/ar`, `en-checkout-steps`, `ar-checkout-steps` (every step, in order),
`floating-label-before`, `floating-label-after`.

**Last full run (19 October 2026):** 1,840 renders (92 screens + Design B/C of Home and Product
= 92 per language × 10 device states × 2 languages), 0 with an issue; 224 flow steps on 4
devices × 2 languages, 0 with an issue, 0 failed assertions; both screens.html pages: 92 live
cards, every one a deep link, no console error. Mutation check: `--mutate='.fl>input{padding-top:8px}'`
fails the label check ("lifted label overlaps the value by 9.3px").

Defects the walk caught that a screen grid could not: the outline "ghost" buttons (Add a new
address, Choose another method, Create an account…) took no clicks because a transition class
shared their name; choosing an emirate did nothing; a toast closing re-drew the page and threw
away what was being typed; dragging from the edge over a photo started an image drag instead of
going back.
