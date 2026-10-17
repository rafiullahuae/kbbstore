# Lane HL: header language switch, and a Wishlist tab in the cart panel (previews only)

The owner asked (17 October 2026): *"add language switch in the header. suggest me the area. and in mobile the wishlist will be move to the cart panel beside browsed tab. and adjust the language switch in mobile header too. i need the previews for now for desktop and mobile. give me multiple suggestions to choose from."*

**These are previews only. Nothing ships.** The header is frozen (CLAUDE.md, 12 October), so no shop view, stylesheet, setting or test was changed. Each option was injected into a rendered page of the `tools/arr-preview.sh` preview (Arabic on, mirrored) by `tools/hl-lang-previews.cjs` in Chromium, and the picture carries a "PREVIEW ONLY" ribbon. The switch's link is taken from the footer switch (`partials/lang-switch` → `Seo::readerAlternatePaths`), so it goes to the same page in the other language. A real click on every option went `/` → `/ar/` and `/ar/` → `/`.

The preview database has no product photos, so the thumbnails in the cart panel are blank. The real Browsed tab shows them blank too in this preview.

## Desktop (1280; also measured at 1024)

| | Placement | Pictures |
|---|---|---|
| **A** | A small "العربية" pill (or "English" on /ar/) just before the account icon | `A-1280.png`, `A-1280-ar.png` |
| **B** | A globe with "EN"/"AR" and a chevron. Clicking it opens a two-line menu. Built with `<details>`, so it needs no script | `B-1280.png`, `B-1280-open.png` (+ `-ar`) |
| **C** | A segmented "English \| العربية" control next to the WhatsApp support block | `C-1280.png`, `C-1280-ar.png` |
| **D** | A small "EN \| ع" toggle directly after the search field | `D-1280.png`, `D-1280-ar.png` |

The shop has no announcement or utility bar above the header on desktop. The flag bar is off on desktop, and the delivery strip is below the header on phones only. So there was no "top bar" option to show without first turning another strip on.

## Phone (390; also 360 and 430). The wishlist heart is removed from the header in every option

| | Placement | Pictures |
|---|---|---|
| **E** | A "ع" circle (or "EN" on /ar/) in the heart's old place | `E-360/390/430.png` (+ `-ar`) |
| **F** | A globe with a small "AR"/"EN" under it, in the heart's old place | `F-360/390/430.png` (+ `-ar`) |
| **G** | Nothing in the header. An "English \| العربية" toggle at the top of the menu sheet | `G-<w>.png` (header), `G-<w>-menu.png` (sheet open) (+ `-ar`) |

The current header, for comparison: `current-360/390/430/1280.png` (+ `-ar`).

## Cart panel on the phone: a Wishlist tab

| | Variant | Pictures |
|---|---|---|
| **1** | Cart · Browsed · **Wishlist** (last, with the same count badge as Cart) | `cart-1-empty-390.png`, `cart-1-filled-390.png` (+ `-ar`) |
| **2** | Cart · **♥ Wishlist** · Browsed (next to Cart, with a heart and a count) | `cart-2-empty-390.png`, `cart-2-filled-390.png` (+ `-ar`) |

A wishlist row looks like a Browsed row, plus a filled heart (remove) beside the + (add to bag). The empty state reads "Your wishlist is empty / Tap ♥ on any product to save it here."

## Measurements (all values from `previews/measurements.json`)

Every option was checked at 320, 360, 390, 430, 768, 900, 1024 and 1280, in English and in Arabic, before and after injection.

| Option | Logo box and lotus (before → after) | Search row top/height | Search width | scrollWidth = width | Switch tap box | Every header link hit by `elementFromPoint` |
|---|---|---|---|---|---|---|
| A | unchanged at all 8 widths | unchanged | 1280: 593→516 (ar 614→530); 1024: 337→260 | yes, all | 64×32 (ar 70×32) | yes (18/18) |
| B | unchanged | unchanged | 593→511; 1024: 337→255 | yes | 72×40 | yes |
| C | unchanged | unchanged | 593→428; 1024: 337→**172** | yes | 56×28 (ar 63×28) | yes |
| D | unchanged | unchanged | 593→496; 1024: 337→243 | yes | **27×28** (ar 36×28) | yes |
| E | unchanged at all widths (320: 128×44 / 27×20; 390: 168×44 / 35×26) | unchanged (70/46; 320: 68/46) | unchanged | yes | 44×44 (320: 40×40, the same as the icons beside it) | yes (5/5) |
| F | unchanged | unchanged | unchanged | yes | 44×44 (320: 40×40) | yes |
| G | **changes**: one icon fewer gives the logo 44px more room. 320: 128×44 / 27×20 → 168×44 / 35×26; 390: 168→212 wide (lotus already at 35×26) | unchanged | unchanged | yes | 40px tall inside the sheet (94–164 wide); the sheet's × is still hit | yes (4/4) |
| Cart 1 / 2 | n/a | n/a | n/a | 390 = 390 | tabs 44px tall, × 44×44 | every tab hit |

Desktop options leave the phone header unchanged at ≤900, and phone options leave the desktop header unchanged at ≥1024 (both checked). No console errors appeared, except image 404s that the preview database has with or without the options.

The desktop options cannot avoid taking width from the search field. The field is the flexible item in that row. The logo and the field's height do not move.

## Recommendation

- **Desktop: A.** It is the most direct choice: the word "العربية" in its own script, one click, nothing to open. It costs the search field the least of the options that are easy to click (−77px at 1280), and it sits with the other shopper controls. B is the choice if the owner wants an icon look, or expects a third language. But it takes two clicks, and its open menu covers the category bar, which is the hover/overlay trap that 2.60.416 paid for. C takes too much from the search field at 1024 (down to 172px). D's target is too small (27px).
- **Phone: E.** It goes exactly in the heart's slot and matches its size, so the logo box and the search row are byte-for-byte the same at every width. It is also the only option a shopper reads without knowing what a globe means. F is the same placement with an icon. G changes the logo size (it grows, staying within its caps), and it hides the switch where most visitors won't look.
- **Cart panel: 1** (Wishlist last). Cart and Browsed stay where shoppers already know them, the three tabs share the width evenly (75–88px each at 390), and the count badge already exists. Variant 2's heart makes its tab 108px wide and squeezes the other two to 66px.

## The admin controls I would add

- **Appearance → Header → Icons → Language switch**: Off / Phone / Computer / Both (default: Both, because the owner asked for it). It stays off and draws nothing while Arabic is off, like the footer switch.
- **Appearance → Header → Icons → Language switch style (computer)**: Word pill (A) / Globe menu (B), default A.
- **Appearance → Header → Icons → Language switch style (phone)**: Letter circle (E) / Globe (F), default E.
- **Appearance → Header → Icons → Wishlist on phones**: on/off, default off (the owner asked to move it). The desktop's existing `Wishlist` switch keeps its meaning.
- **Appearance → Cart panel → Content → Wishlist tab**: on/off, default on. **Appearance → Cart panel → Wording → Wishlist tab** / **Wishlist tab · Arabic** / **Wishlist empty**, following `txt_tab_browsed` and `txt_tab_browsed_ar`.

## What the real implementation must touch, including the locked files

1. **Locked (the header freeze):**
   - `partials/header.blade.php`: the switch markup inside `.hact`, built from the footer's `Seo::readerAlternatePaths` logic. The best approach is to move that logic into a small `LangSwitch` support class that both partials call.
   - `App\Services\HeaderSettings`: 4 new keys in the `icons` group.
   - `kbb.css`: one rule for the pill or circle, and one ≤900px rule hiding the wishlist `.ib`.
   - `HeaderLayoutLockTest`: its fingerprints must be advanced deliberately.

   Per CLAUDE.md, that lane owes the full 320–1024 sweep every 20px over 40 frames, the same sweep with round()/color-mix() renamed out and with the stylesheet blocked, and pictures at 390/600/1280. `StorefrontEnglishUnchangedTest` will go red on every English page, because the switch is new markup the owner asked for. Advance it then, and only for that.
2. **Not locked:**
   - `partials/cart-drawer.blade.php`: the third tab and the `#kcWish` pane.
   - `resources/js/kbb/cart.js`: `showTab()` knows only cart/browsed today, and needs a third value.
   - `wishlist.js`: refresh the pane on toggle. It already updates the `#kbbWishCt` badges.
   - `CartDrawerComposer`: the wishlist products. **Fold the ids into the existing Browsed product query** (one `whereIn` over both id lists) so the cart-panel fragment adds no query. Otherwise `StorefrontQueryBudgetTest` needs a deliberate +1.
   - `CartPanel` keys, and the admin preview in `admin/partials/cart-panel-screen.blade.php`.
3. **Not touched:** speed-frozen files and payment files. No new script, no new request (the switch is a plain link, and B's menu is `<details>`), and no layout-measuring JS.

## Open questions for the owner

1. Which desktop letter (A–D), which phone letter (E–G), and which cart panel variant (1/2)?
2. On desktop, should the heart stay in the header (as now), or also move to the cart panel?
3. Should the cart panel's Wishlist tab also show on desktop, or only on phones?
