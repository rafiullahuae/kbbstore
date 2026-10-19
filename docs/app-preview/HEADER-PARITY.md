# Header parity: the website and the app (Lane APV3, 19 October 2026)

The owner asked for the header style, height, language switch and search box to be exactly the
site's. The app doesn't redraw them. `tools/apv-site.cjs` opens https://kbeautybliss.com/ and
`/ar/` in Chromium and takes three things from the live page:

- the site's own **markup** for the header, the ☰ menu and the menu bar with its mega panels
  (`en|ar/site.js`)
- every **stylesheet rule** that reaches them (`en|ar/site.css`). The rules are scoped to `.kh`.
  The site's `@media` widths become `@container app`, so each device frame lays out as a browser
  window of its own width would.
- the **measurements** in `site-measure.json`, taken at the widths in the brief (390, 600, 768,
  900, 1024, 1280) and at every app device width.

`tools/apv-shots.cjs` measures the app's header in every render on every device. It fails if the
header height, search-box height or the logo differ from the site's at that width by more than
1px, or if the search-box width differs by more than 2px. It also fails if the corner radius,
placeholder, wordmark, language switch, ☰ or menu bar differ from the site.

## Measured: the site (live, 19 October 2026)

| Width | Header height | Side padding | ☰ | Logo icon | Wordmark | Search box (h × w, corners, fill) | Header icons | Language switch | Menu bar |
|---|---|---|---|---|---|---|---|---|---|
| 390 | 86 | 12 | 46 × 46 | 35 × 26 | 14px / 650 | 34 × 366, 12px, #FCFCFC | 21 | capsule 44 × 44 | — |
| 600 | 86 | 12 | 46 × 46 | 35 × 26 | 14px / 650 | 34 × 576, 12px | 21 | capsule 44 × 44 | — |
| 768 | 86 | 12 | 46 × 46 | 35 × 26 | 14px / 650 | 34 × 744, 12px | 21 | capsule 44 × 44 | — |
| 900 | 86 | 12 | 46 × 46 | 35 × 26 | 14px / 650 | 34 × 876, 12px | 21 | capsule 44 × 44 | — |
| 1024 | 93.5 | 22 | — | 51.1 × 38 | 22px / 650 | 34 × 255, 99px, rgba(42,34,40,.06) | 21 | pill “EN \| ع” 90.3 × 30 | 46.5 |
| 1280 | 93.5 | 22 | — | 51.1 × 38 | 22px / 650 | 34 × 511, 99px | 21 | pill 90.3 × 30 | 46.5 |

Up to 900px the site has two rows: ☰, the logo, the language capsule, account and bag, with the
search field as its own row underneath. The phone search text is 16px pink with the placeholder
“Search skincare, brands…” (Arabic “ابحثي عن منتجات العناية والعلامات…”). From 1000px the header is
one row with the support block, plus the menu bar under it. The site shows no menu bar between 901
and 1000px. The Arabic site mirrors all of this and uses the Arabic wordmark “كي بيوتي بليس”.

## Measured: site and app at the app's device widths

The header height doesn't include the phone's status bar.

| Device (width) | Site header | App header | Site search | App search | Logo icon (site = app) | Language switch (site = app) | ☰ | Menu bar |
|---|---|---|---|---|---|---|---|---|
| Galaxy Z Fold cover (344) | 86 | 86 | 34 × 320 | 34 × 320 | 29.6 × 22 | 44 × 44 | 46 × 46 | — |
| iPhone SE (375) | 86 | 86 | 34 × 351 | 34 × 351 | 35 × 26 | 44 × 44 | 46 × 46 | — |
| iPhone 16 Pro (402) | 86 | 86 | 34 × 378 | 34 × 378 | 35 × 26 | 44 × 44 | 46 × 46 | — |
| Pixel 9 / Z Flip (412) | 86 | 86 | 34 × 388 | 34 × 388 | 35 × 26 | 44 × 44 | 46 × 46 | — |
| Surface Duo, one screen (540) | 86 | 86 | 34 × 516 | 34 × 516 | 35 × 26 | 44 × 44 | 46 × 46 | — |
| Galaxy Z Fold inner (673) | 86 | 86 | 34 × 649 | 34 × 649 | 35 × 26 | 44 × 44 | 46 × 46 | — |
| iPad mini portrait (744) | 86 | 86 | 34 × 720 | 34 × 720 | 35 × 26 | 44 × 44 | 46 × 46 | — |
| iPad mini landscape (1133) | 93.5 | 93.5 | 34 × 364 (AR 385.2) | 34 × 364 (AR 383.8) | 51.1 × 38 | 90.3 × 30 | — | 46.5 = 46.5 |

The English and Arabic apps give the same numbers as the English and Arabic sites. The Arabic
wordmark is the site's own (`كي بيوتي` / `بليس`).

## Deliberate differences

1. **The status bar.** The site's header starts at the top of the browser's page. In the app it
   starts under the clock and battery, so it gets `padding-top` equal to the phone's safe area
   (62pt on iPhone 16 Pro, 40dp on Pixel 9). Everything under that line is the site's.
2. **The ☰ menu opens under the status bar too**, and its scrim covers the status bar. On the
   site the drawer starts at the top of the page.
3. **Arabic search box on iPad landscape: 383.8 vs 385.2px wide (1.4px).** It is the leftover
   space after the wordmark, and the app's Cairo (Google Fonts) sets the Arabic wordmark 1.4px
   wider than the site's own copy does. Heights match.
4. **The links lead into the app.** The language switch opens the other app on the same screen.
   Account and the bag open their tabs. The search box becomes the app's search field, with
   results as you type. The WhatsApp block in the computer header opens WhatsApp.
5. **The app's 44px tap rule and 3:1 contrast rule don't apply to the site's own parts** (header,
   menus, cart, checkout). Those parts are held to the site's sizes instead. There are two places
   where the site itself falls short, and the app copies it as asked:
   - the phone search field is 34px high, under 44px
   - the delivery strip is white on #E991AE, which is 2.3:1 contrast

   Both are listed in `shots/report.json` → `site_findings`.

## Tablets: what the menu is

| Device | What the site shows at that width, and so what the app shows |
|---|---|
| Fold inner (673), iPad portrait (744) | The phone header with ☰. The ☰ opens the site's menu: English \| العربية, "Search the menu…", the chips (Super Sale, All Skincare, Best Sellers, Under 54 AED, Brands A–Z), Trending Brands (14), All Brands (94), Skincare (9), Shop by Skin Concern (7), Super Sale, Skincare Sets, Hair Care, Body Care, Beauty Devices, Everything Under 54 AED, and Sign in. |
| iPad landscape (1133) | The computer header with the **menu bar**: Trending Brands ▾, All Brands ▾, Skincare ▾, Shop by Skin Concern ▾, Super Sale, Skincare Sets, Hair Care, Body Care, Beauty Devices, Everything Under 54 AED. A tap opens the mega panels. |
| Surface Duo | The phone header on the first screen. Nothing sits under the hinge. |

The navigation rail is gone. For comparison, the chooser beside the device has a **Tablet menu:
Menu bar** option, which shows the site's menu bar on the Fold inner and iPad portrait too. The
bar wraps onto a second line where the items don't fit.

## Pictures (`key/`)

- `header-phone-en.png`, `header-phone-ar.png`: site and app at 402 and 412
- `header-tablet-en.png`, `header-tablet-ar.png`: site and app at 673, 744 and 1133
- `menu-phone-en.png`, `menu-phone-ar.png`: the ☰ menu, closed levels and one level open, site and app
- `menu-tablet-en.png`, `menu-tablet-ar.png`: the iPad landscape mega panel (site and app), the Fold inner menu (site and app) and the "menu bar" option
- `category-fold-inner-en.png`, `category-fold-inner-ar.png`: the owner's own screen (Category · Face Serums on the Fold inner), as it is now

Checkout and cart parity: `CHECKOUT-PARITY.md`.
