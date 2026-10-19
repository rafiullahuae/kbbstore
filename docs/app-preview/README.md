# K-Beauty Bliss app — live previews, English and Arabic (Lanes APV2, APV3)

## What changed in APV3 (19 October 2026)

The owner asked for five things:

> "on tablets, i need the proper menu … i don't want to disturbed the menu in mobiles … the header
> style and height etc i need exactly the same as the site … with language switch same … same
> search box … can you suggest something more in the bottom bar menu items"
>
> "we need the same one step as in site mobile version. or max two steps." / "same for cart page."

- **The header is the website's own**, on every screen and every device: the same markup, the same
  CSS and the same measured sizes. It has the same language switch (the capsule on phones, the
  "EN | ع" pill on wide screens), the same search box and the Arabic wordmark in the Arabic app.
  The numbers are in `HEADER-PARITY.md`.
- **The website's menus.** Phones and the Fold inner / iPad portrait get the site's ☰ menu, just as
  the site shows it at those widths. iPad landscape gets the site's menu bar with its mega panels.
  The navigation rail is gone, and nothing sits under the Duo hinge.
- **Bottom bar: five options, A to E**, on phones. A is the current bar; you pick a letter (below).
- **Bag and checkout as on the site**: the bag is the site's cart page, then the checkout is the
  site's **one page**. That's two screens before Pay now, and the nine step screens are gone. The
  comparison is in `CHECKOUT-PARITY.md`.

### Choosing the bottom bar

Beside the phone, on `index.html` and `screens.html` in both languages, there is a small
**"Bottom bar (phones): A B C D E"** chooser. It sits outside the device and changes every screen
at once. The browser remembers the choice (localStorage), and a deep link can set it with `&bb=B`.

| | Items |
|---|---|
| **A** (current) | Home · Shop · Search · Bag · Account |
| **B** | Home · Categories · Brands · Offers · Account (the bag stays in the header) |
| **C** | Home · Shop · Wishlist · Bag · Account (search is the header's box) |
| **D** | Home · Categories · **raised Bag** · Wishlist · Account |
| **E** | Home · Shop · Offers · Bag · **More**. More opens a sheet with Brands, Wishlist, Orders, Skin concerns, Blog, Contact us, Language & region and Settings. |

Every option has badges for the bag and wishlist counts, shows the active tab in #C13E63, uses
Arabic labels in the Arabic app and keeps clear of the home indicator. You can compare them in
`key/bottom-bars-en.png` and `key/bottom-bars-ar.png`.

The same chooser has **Tablet menu: As the site / Menu bar**. "Menu bar" shows the site's menu bar
on the Fold inner and iPad portrait too, for comparison (`&tm=bar`).

### Refreshing from the site

- `node tools/apv-site.cjs` reads the live site and rewrites the header, the menus and their
  measurements: `en|ar/site.js`, `en|ar/site.css` and `site-measure.json`.
- `node tools/apv-site-co.cjs` reads the cart page and the one-page checkout. The live site
  refuses those pages to automated browsers, so the tool renders them from this repository's own
  shop, which it starts locally and stops again. It writes `en|ar/site-co.js` and
  `en|ar/site-co.css`. It needs `vendor/`; see the tool's header.


Two separate apps, one shared engine. Open from this folder (`file://` works) or from a phone:

| | App | Every screen, live |
|---|---|---|
| English (LTR, English only) | `en/index.html` | `en/screens.html` |
| Arabic (RTL, Arabic only, Cairo font) | `ar/index.html` | `ar/screens.html` |

`index.html` here only offers the two. The first single-page prototype is kept as
`v1/index.html` for reference.

## Using the app

It behaves like the app, not like a slide: tap, scroll, type.

- **The website's header and menus on top** (see above). The ☰ opens the site's menu. Its levels
  open in place, the "Search the menu…" field filters it, and every item opens its screen in the
  app. On iPad landscape the menu bar's panels open with a tap.
- **Navigation stack per tab** (the tabs of the chosen bottom bar): every screen pushes, Back
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
- **Bag:** the site's cart page. Quantity steppers and Remove update it, and "Proceed to checkout"
  opens the one-page checkout, where the coupon is (`WELCOME10` gives 10%; any other code shows the
  error state).
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
- **Bag & checkout, as on the site: two screens.** The bag is the site's cart page. Then comes
  the site's one-page checkout: the Order summary bar (lines, promo code, totals), then
  ① Delivery Information (email, full name, phone, building, area / street, emirate picker,
  country, create an account, WhatsApp updates), ② Delivery (express, free over AED 199, gift
  +AED 15) and ③ Payment (Apple Pay on iOS / Google Pay on Android, card fields in the page,
  Tabby, Tamara, cash on delivery). Then Total, Pay now and the trust lines. Variants: guest,
  filled, errors inline, summary open, emirate picker, Tabby chosen, cash on delivery. After Pay
  now (these aren't steps): 3-D Secure, the wallet sheet, Tabby / Tamara approval, the order
  placed, and a failed payment with the bag kept and Try again.
- **Account:** signed in / out, sign in, create account (password meter), forgot password,
  Face ID / fingerprint unlock, profile, addresses (add, edit, delete), orders, order on the way /
  delivered (timeline, reorder), wishlist, notification settings, language & region, delete
  account + confirm.
- **Content:** Contact (the WhatsApp button is here and nowhere else — never floating), FAQ,
  policies and each policy, About, Blog list and article.
- **States:** skeletons (home, listing, product, bag, orders), offline banner and screen, error,
  empty (bag, wishlist, orders), update required.

## Fields: the website's floating labels

The checkout's fields are the site's own (`.fld.kbb-fl`, drawn by `site-co.css`). The label sits in
the box until the field has a value or is focused, then it lifts to a small pink heading. The
account forms (sign in, register, addresses, contact, profile) keep the app's copy of the same
design (`.fl`). The phone, card and code digits stay left-to-right in the Arabic app. Messages
appear under each field when you leave it, and for every field when Pay now is tapped.

## Size classes and two panes

Each frame is a CSS container, so it lays out by its own width (system design §3.9):
Compact < 600 (the site's phone header and ☰ menu, bottom tabs, one pane, 2-column grid) · Medium
600–839 (the site's header and ☰ menu as the site shows them at that width, no rail, 3 columns,
gallery beside details) · Expanded ≥ 840 (the site's computer header with the menu bar, list | detail
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
validation, formatting), `shell.js` (devices, panes, tab bar, render, transitions), `header.js`
(the website's header and menus wired to the app, the bottom-bar variants, the preview chooser),
`screens-shop.js`, `screens-checkout.js`, `screens-account.js`, `events.js` (jump list,
clicks, typing, gestures, deep links, the test hook `window.APV`), `screens.js` (the screens
grid), `app.css`, `screens.css`, `icons.js`. Each language folder has its `index.html`,
`screens.html` and `data.js`, plus the site's own parts: `site.js` / `site.css` (header and menus)
and `site-co.js` / `site-co.css` (cart and checkout). The Arabic folder adds `i18n.js`. `app.css`
keeps its rules off the site's parts (`:not(:where(.kc *,.kh *))`), so the site's CSS draws them
alone.

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
