# K-Beauty Bliss app — live previews, English and Arabic (Lanes APV2, APV3)

## What changed in APV4 (19 October 2026): one header, one bag

Inner screens (a category, a brand, a product, the wishlist…) used to show the site's header and
then a second app bar under it ("← Face Serums" with a second bag icon and badge). Now there is
one header and one bag. Under the site's header there is a slim back row in the place and style of
the site's breadcrumb: the app's back arrow (← in English, → on the right in Arabic) and the
site's own trail, as kbeautybliss.com shows it at the same widths (12.5px, #756C74, "Home" in bold):

- Category: Home / Shop / Skincare / Face Serums (Arabic: الرئيسية / التسوق / العناية بالبشرة / سيروم الوجه)
- Brand: Home › Brands › Medicube (Arabic: الرئيسية › الماركات › ميديكوب)
- Product: Home / <its category> / <the product's full name>
- Other screens: Home / <the screen's title>

A share button stays at the end of the row where the screen has one. Screens without the site's
header (the checkout, the after-Pay-now screens, sheets, the Duo's second screen) keep their own
bar. The checker has a new rule, `cart-icons`: at most one cart or bag icon in the top area of
every screen (the site's header plus any bar under it). See `key/category-fold-inner-en.png` and
`-ar.png`.

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

## Screens (80 entries in the jump list, each in both apps)

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

`node tools/apv-shots.cjs` drives both apps in Chromium. It renders every jump-list entry (Home
and Product in A, B and C) × 10 device states × 2 languages by deep link. On top of that it renders
**bottom bars B–E** on iPhone 16 Pro, iPhone SE, Pixel 9 and Fold cover (Home, Category, Product,
Bag, Account, Search results, Brand, Wishlist), and **"Tablet menu: menu bar"** on the Fold inner
and iPad portrait. Then it runs the flows by real clicks and typing:

- **checkout walk** (iPhone 16 Pro, Pixel 9, Fold inner, Duo; both languages):
  1. Add from a home card, then the bag icon.
  2. The site's cart page, then Proceed to checkout. Here the checker **counts the screens before
     Pay now and fails above 2**.
  3. The summary bar, with a wrong coupon (error), then WELCOME10.
  4. Pay now with an empty page: at least five inline errors on the one screen.
  5. Fill in email, name, phone (`50 123 4567`), building and area, and pick an emirate from the
     sheet.
  6. Choose the card. An empty card shows its errors; then `4242 4242 4242 4242` and `12 / 29`.
  7. Pay now → 3-D Secure → Order placed (the bag is emptied and an order added).
  8. Repeat from the filled checkout with Apple / Google Pay, Tabby, Tamara and cash on delivery.
     Each must end in the order placed.
  9. A declined card → Payment failed, with the bag kept → Try again → back to the checkout with
     the card chosen.
- **the website's parts** (8 devices, both languages): the header's account, bag, logo, search
  box (it becomes the search field and finds as you type), badge count and language link.
  - The ☰ menu: every top-level item, every sub-level opened with its first item clicked, every
    chip, Sign in, the language link, close by × and by the scrim, and "Search the menu…".
  - The menu bar on iPad landscape (and with "menu bar" on the tablets): every item, every mega
    panel opened, and its first link clicked.
  - Every **bottom bar A–E** on iPhone 16 Pro and Pixel 9: each tab clicked and shown as active,
    every More tile, and the bag and wishlist badges.
- **navigation smoke:** as before. On frames without a bottom bar, the header's icons are the tabs.

Per render and per flow step it asserts overflow, clipped text, overlapping text, icon sizes,
contrast (3:1), tap targets ≥ 44, safe areas, hinge and fold, console errors and the floating
labels. In the Arabic app it also asserts **no English** (exceptions marked `data-x`, counted in
`report.json`). New in APV3:

- `site`: the website's header equals the live site at the frame's width. That covers height ±1,
  search box height ±1 and width ±2, corners, placeholder, logo, wordmark (Arabic in the Arabic
  app), language switch, ☰ and menu bar.
- `rail`: no navigation rail is drawn.
- `cart-icons` (APV4): at most one cart or bag icon in the top area of each screen: the site's
  header plus every pane's bar. A second bag in a bar under the header fails.
- `cart` and `checkout`: on phones, the bag and the checkout equal the site's cart page and
  one-page checkout. That covers section order, button heights and corners, titles, the field
  order by the site's ids, the payment methods and the number of sections.

The site's own parts are held to the site's sizes and colours, not to the app's 44px and 3:1
rules. Where the site itself falls short, `report.json` → `site_findings` says so.

Options: `--lang=ar --dev=ip16,duo --only=<label or group> --no-shots --flows-only --no-flows
--workers=4`. `--mutate='<css>'` injects a defect to prove a check fails, for example:
`--mutate='.kh .search-in{height:40px!important}'` fails `site` ("search box 40.0px high, the
site's is 34px"), and `--mutate='.kc .kbb-mobile-order .place{height:40px!important}'` fails
`checkout` ("Pay now 40px, 99px; the site's is 59px, 99px"). `--mutate='js:<code>'` runs code in the
app instead, for an engine defect: `--mutate="js:const _c = crumbRow; crumbRow = (o) => _c(o).replace('</nav>', '</nav>' + bagBtn());"`
puts the second bag back in the back row and fails `cart-icons` ("2 cart/bag icons in the top
area…") on every inner screen under the site's header.

Output: `shots/en/`, `shots/ar/` and `shots/site/` (renders and the site's own pictures; not kept),
`shots/sheet-<device>-<lang>.jpg` (contact sheets, kept), `shots/report.json`, and `key/`:

- `header-phone-*`, `header-tablet-*`, `menu-phone-*`, `menu-tablet-*`, `bottom-bars-*`
- `en|ar-cart`, `en|ar-checkout`, `en|ar-checkout-flow`
- `category-fold-inner-*` (the owner's screen), `brand-fold-inner-*`, `product-duo-*`,
  `ipad-landscape-*`, `home-*`
- `floating-label-before/after`

**Last full run (19 October 2026):** 0 renders and 0 flow steps with an issue, and 0 failed assertions.

- **Renders:** 1,952. That is 80 screens + Design B/C of Home and Product (84 per language), × 10 device states × 2
  languages, plus 256 bottom-bar renders (B–E) and 16 tablet "menu bar" renders.
- **Flow steps:** 237, from the checkout walk on 4 devices and the site's menus, header and
  bottom bars on 8 devices, in both languages.
- **screens.html:** 84 live cards per language, every one a deep link, no console error.

Defects the walk caught that a screen grid could not: the outline "ghost" buttons (Add a new
address, Choose another method, Create an account…) took no clicks because a transition class
shared their name; choosing an emirate did nothing; a toast closing re-drew the page and threw
away what was being typed; dragging from the edge over a photo started an image drag instead of
going back.
