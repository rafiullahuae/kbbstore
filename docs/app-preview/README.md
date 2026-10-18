# K-Beauty Bliss app — interactive previews (Lane APV)

Phase 1 of `docs/MOBILE-APP-PLAN.md`: every screen of the **native** app, clickable, in
true-size device frames, English and Arabic, with real products and prices from
kbeautybliss.com. The owner picks the design here before any app code is written.

## Open it

- Double-click `docs/app-preview/index.html` (works from `file://`, no server), or open it
  in a phone browser. It is one file: vanilla JS, no framework, no build step. Only Google
  Fonts (Outfit, Cairo) and the live product photos come from the network.
- Every screen has a link: the address bar keeps the state, e.g.
  `index.html#s=product&os=android&dev=fold&fold=0&l=ar&d=B`.

## The switcher (top bar)

| Control | What it does |
|---|---|
| Design A / B / C | Only **Home** and **Product** have choices. A: editorial, full-bleed. B: soft boutique cards on blush. C: bold pink header (Home) / dark price card with express Apple Pay / Google Pay (Product). |
| English / العربية | Full right-to-left mirror. In Arabic a third button switches digits 123 ↔ ١٢٣ (default: Latin digits, as on the site and UAE receipts). |
| iOS / Android | iOS is the default. iOS: SF-style navigation bar, large titles, translucent tab bar, bottom sheets with grabber, iOS alerts, Face ID. Android: Material 3 top app bar, navigation bar with pill indicator, M3 sheets/dialogs, fingerprint; the back arrow plays the **predictive-back** shrink (also shown as a still: System → "Android predictive back"). |
| Device | iOS: iPhone 16 Pro 402×874 pt, iPhone SE 375×667 pt, iPad mini 744×1133 pt (+ Landscape 1133×744). Android: Pixel 9 412×915 dp, Galaxy Z Flip 412×915 dp (+ **Tabletop**), Galaxy Z Fold (cover 344×882 dp ↔ **Unfold** inner 673×841 dp — the screen, bag and scroll position are kept), Surface Duo 2 spanned (two 540×720 dp screens + hinge). |
| Screen | Jump to any of the 68 screens/states (grouped). Everything inside the phone is also tappable: tabs, carousels, concern tabs, gallery, qty, wishlist, add to bag, coupon, sheets, payment methods, toggles, search-as-you-type. |
| Fit / 100% | Fit scales the frame to the window; 100% is true size (1 CSS px = 1 pt/dp). |

Haptics are shown as a pill under the frame ("Haptic · success (UIFeedbackGenerator)" /
"CONFIRM (HapticFeedbackConstants)") when a tap would produce one.

## Size classes (one rule for every screen)

Each frame is a CSS container (`container: app` for the window, `container: pane` per pane),
so every frame lays out by its own width, not the browser's.

| Class | Width | Navigation | Content |
|---|---|---|---|
| Compact | < 600 | bottom tab bar (Home · Shop · Search · Bag · Account) | one pane; product grid 2 columns |
| Medium | 600–839 | navigation rail | one pane; grid 3 columns; product gallery beside its details; landscape banners |
| Expanded | ≥ 840 | navigation rail | two panes, list \| detail, where the screen has a pair (below) |

Two-pane pairs (expanded, and across the Duo hinge): Shop/Search/Wishlist → Product;
Account → Orders; Orders → Order; Bag → checkout step; Blog → Article; Contact ↔ FAQ;
Home → Product (Duo). Screens without a pair (splash, sign-in, order placed, update
required…) sit on one screen with a brand panel on the other — **nothing is ever under the
hinge**. Sheets and dialogs become centred dialogs from Medium up.

Foldables: Fold cover = compact, Fold inner = medium. Flip **tabletop** (half-folded): content
in the top half, controls in the bottom half (product: photo above; price, qty, thumbnails,
Add to bag below), sheets open in the bottom half; nothing crosses the fold. iPad uses the
same rules (portrait = medium, landscape = expanded). There is no foldable iPhone.

## Screens (68, each in English and Arabic)

- **Start**: splash; first-run language choice; notifications primer.
- **Shop**: Home (A/B/C) in the site's order — banner carousel, trust row, Shop by Concern
  (the owner's "Content 1": tabs, tip panel with photo, View all, two-row product rail),
  Best Sellers, Brands, Trending, Under AED 54, Blog; Shop grid; categories tree; filter sheet
  (concern, price, brand); sort sheet; endless scroll; no-match empty state; Search (recent &
  popular, instant results, no results); barcode scanner; Wishlist.
- **Product** (A/B/C): swipe gallery, zoom hint, thumbnails, price with discount and saving,
  Tabby/Tamara instalment line, rating, size, qty, sticky Add to bag, tabs (Description,
  Ingredients, How to use, Shipping, Returns), reviews with photo thumbnails, full-screen photo
  viewer, Bought together, You may also like; "Added to bag" toast.
- **Checkout**: Bag (free-delivery bar, coupon, qty, totals with VAT, upsell); address (saved);
  new address with validation errors; Emirate picker; delivery method; payment (Apple Pay on
  iOS / Google Pay on Android, card with Stripe-style fields, Tabby, Tamara, cash on delivery
  UAE-only + AED 10); Apple Pay / Google Pay sheet; 3-D Secure step; Tabby and Tamara in-app
  approval sheets; review; order placed; payment failed with retry — the bag is kept.
- **Account**: sign in, register, forgot password, Face ID / fingerprint unlock, account,
  profile, addresses, orders (+ empty), order detail with live status timeline and Reorder,
  notification settings per type, language (+ Arabic digits), delete account + confirm dialog.
- **Info**: Contact (in-app form; **the only WhatsApp button in the app**, as decided on 18
  October), FAQ, shipping & returns policy, About, Blog list, Article.
- **System**: loading skeletons (home, product), offline banner, offline screen, error with
  retry, empty states (wishlist, bag, orders), update required, toast/snackbar.

## Real data

`node tools/apv-data.cjs` reads the live public API at build time and writes `data.json`
(also inlined into `index.html` between the `APV-DATA` markers; `--inject` re-inlines without
fetching):

- 60 products picked from the 769-row catalogue (`/api/products`): the Shop by Concern
  products, the 12 most-reviewed (shown as Best Sellers — the API has no sales figure),
  Trending (catalogue order), Under AED 54. Prices are the live ones (fils ÷ 100).
- Brand and the **Arabic product names** come from the site's own search
  (`/api/search`, `/ar/api/search`): 60/60 products have an Arabic name.
- Every image URL was checked to answer 200 (`img-cache/400` cards, `/800` gallery, `/200`
  thumbs); blog covers use `img-cache/800`.
- Reviews from `/api/products/{slug}/reviews`; banners and the trust row from the homepage
  (EN + AR); blog from `/api/posts`; concern tabs and tips (EN + AR) from
  `docs/sbc-preview/concerns.json`; delivery AED 20 free over AED 199 (UAE), AED 150 free over
  AED 1,600 (Gulf), COD fee AED 10, VAT 5% — the live shipping zones and `/api/settings`.

## What is mocked

Payments (Apple Pay, Google Pay, Stripe card + 3-D Secure, Tabby, Tamara, COD — nothing is
charged and no SDK is loaded), push notifications, camera/barcode, Face ID / fingerprint,
haptics, share sheet, WhatsApp hand-off, the signed-in customer (Sara), her addresses and
orders, coupon `WELCOME10`, the "Express" delivery option, ingredients lists, return-policy
wording, review photos (the API has no review-photo field — gallery photos stand in).
Categories are derived from product names (the API has no category field). Blog posts have
no Arabic version on the site yet; the article screen says so and shows the English text.

## The checker

`node tools/apv-shots.cjs` (Playwright, Chromium at `/opt/pw-browsers`) visits every screen ×
device × locale (68 screens incl. design variants × 10 device states × 2 locales = 1,352
renders) and asserts: no screen root wider than its box; no text cut by a non-scrolling box
(designed ellipses/clamps excepted); no text over text, a photo or an icon; every control
≥ 44×44; nothing fixed in the status-bar / Dynamic Island or home-indicator bands; nothing
under the Duo hinge or across the Flip fold; icons keep their size; field values fit; text
contrast ≥ 3:1; no console error or failed request. It audits the whole scroll content, not
just what is on screen. Results: `shots/report.json`; contact sheets: `shots/sheet-<device>-<locale>.png`;
key pictures: `key/`. Individual PNGs (`shots/<screen>-<device>-<locale>.png`) are regenerated
by the script and not kept in git (≈110 MB).

`--dev=ip16,duo --lang=ar --only=home,product --no-shots` narrows a run; `--mutate='<css>'`
injects a defect to prove a check can fail (e.g. `.tab{min-height:30px}` → tap failures,
`.pc .rt{margin-top:-20px}` → overlaps, `.hhead{padding-top:4px}` → safe-area failures).
