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

---

# Round 2 (17 October 2026): the owner's picks, refined. Previews only, nothing ships

The owner said: *"for wishlist, i need only the heart icon … at the end of the cart panel, same for arabic … language switch this option is okay, but make sure the … arabic alphabet must be middle center align in the circle. give me also the capsule … for desktop this is okay."*

Pictures are in `round2/` and the numbers are in `round2/measurements.json`. Both were made by `tools/hl2-previews.cjs` on the `tools/arr-preview.sh` preview, and every picture carries the PREVIEW ONLY ribbon. No shop view, stylesheet, setting or test was changed.

## What is in round 2

| | What | Pictures |
|---|---|---|
| **E2** | E with the letter centred. "ع" on English pages, "EN" on Arabic pages | `E2-360/390/430[-ar].png`, 4x before/after crop `E2-zoom[-ar].png` |
| **H1** | A capsule in the heart's slot that names the language you switch **to**. English pages show "العربية", Arabic pages show "English" | `H1-320/360/390/430[-ar].png` |
| **H2** | A capsule that names the **current** language. English pages show "English", Arabic pages show "العربية" | `H2-320/360/390/430[-ar].png` |
| H1/H2 wide | The same capsules at a comfortable 12.5px, shown so the owner can see what larger text costs | `H1-/H2-<w>-wide[-ar].png` |
| **D2** | Desktop D with bigger tap areas: 36px tall, 40px per half, same pill and colours | `D2-1024/1280[-ar].png` |
| **Cart** | Cart · Browsed · ♥. The heart is the last tab, at the inline end on Arabic pages. One version has a count and one has none. The heart is outlined when the list is empty and filled when it has items | `cart-heart-{badge,nobadge}-{empty,filled}-390[-ar].png` |

## How the letter is centred, and why "ع" is drawn as a picture rather than typed

The offset is the centre of the glyph's ink box minus the centre of the circle. The ink box was measured from a DPR-4 screenshot with the ring made transparent. The table shows the worst case over 360/390/430 and DPR 2/3/4, in px.

| | Round 1 (before) | E2 (after) |
|---|---|---|
| "ع" on English pages | x **+0.50**, y **+3.13** (the letter sat low) | x +0.13, y 0.00 |
| "EN" on Arabic pages | x −0.25, y +0.17 | x 0.00, y +0.17 |
| H capsule text (all four words, both languages) | n/a | at most x 0.26, y 0.25 |

English pages do not load Cairo. There, a typed "ع" uses whatever Arabic face the phone has. Flex centring with `line-height:1` still left it 3.9 to 4.6px low. A pixel tweak only holds for one face. With no tweak, the offsets were DejaVu x +0.25 / y +3.88, Cairo x −0.25 / y +4.63, and FreeSerif x +0.38 / y +4.00. A middle tweak (−0.283em) brings these three within 0.38px. iPhone and Android faces could not be measured here.

So E2 draws the "ع" as an inline SVG. It is made from the shop's own Cairo 700 by `tools/hl2-glyphs.py`, with the viewBox set to the ink box. It is centred by construction on any device, needs no font request, and costs 526 bytes. It is 8×12 with `width`/`height` attributes. The box must differ from the circle by an even number of pixels, because a 7px box was snapped by Chromium and measured +0.5px off on both axes.

The word "العربية" on English pages (H1) is drawn the same way and costs about 2.5 KB. "EN"/"English" (Outfit) and "العربية" on Arabic pages (Cairo) are web fonts that every device gets, so they stay as text. They use a measured em tweak: English −0.04em, Arabic text +0.01em/−0.058em, EN 0. The tweak is tuned for the 10px capsule. The wide capsules were not tuned.

## The header lock: before vs after, at 320/360/390/430/768/900/1024/1280, English and Arabic

| Option | Logo box + lotus | Search row (top/height) | Header height | scrollWidth = width | Tap target | Every header link hit |
|---|---|---|---|---|---|---|
| E2 | unchanged at all 16 | unchanged | unchanged (124/126) | yes | 44×44 (40×40 at 320). The ring is 30 | yes |
| H1 / H2 | **unchanged at all 16** | unchanged | unchanged | yes | 44×44 (40×40 at 320). The visible pill is 42–45×26 | yes |
| H1/H2 wide | **shrinks** (below) | unchanged | unchanged | yes | 70–74 × 44 | yes |
| D2 | unchanged | unchanged | 46 → 46 | yes | **40×36 per half** (round 1: 27×28 / 36×28) | yes |

**The capsule at 320.** The capsule fits in the heart's own 40px box (44px from 360 up), so the logo cannot move. This works only with 10px bold text and a 26px-tall pill that is 42–45px wide. The pill overhangs its box by 1–3px each side.

- Clearance to the neighbouring icon glyphs at 320 is 4.3–7.5px. At 360 and up it is 10.3–12.5px.
- At 320, 39–40 of the pill's 42–45 px columns open the switch. The outermost 2–3px fall into the account and cart boxes.
- The tap box itself is 40×40 at 320 and 44×44 above. That is the same as the icons beside it, and at least the 40px asked for.

At a more comfortable size (12.5px, 61–66px pill), the capsule does **not** fit without shrinking the logo, at any width:

| Width | Logo box | Lotus |
|---|---|---|
| 320 | 128 → 94–98 | 27×20 → 20×15 (ar H2 22×16) |
| 360 | 138 → 108–112 | 30×22 → 23×17 (ar H2 24×18) |
| 390 | 168 → 138–142 | 35×26 → 30×22 |
| 430 | 208 → 178–182 | stays 35×26 |

**D2 search width cost:**

| Width | Today | Round-1 D | D2 |
|---|---|---|---|
| 1280 | 593 | 496 | **481** (−112) |
| 1280, Arabic | 614 | 517 | 502 |
| 1024 | 337 | 243 | **225** (−112) |
| 1024, Arabic | 358 | 261 | 246 |

So D2 costs 15px more than round-1 D. The logo and the field's height do not move.

**Real clicks** went `/` → `/ar/` and `/ar/` → `/` on E2, H1, H2 and D2. No console errors.

**Cart panel at 390** (`round2/measurements.json` → `cart`):

| | Heart tab | Cart and Browsed tabs |
|---|---|---|
| With count | 68–69×44 | 85px each |
| No count | 52×44 | 93px each |

The × is 44×44, every tab is hit, and scrollWidth is 390. On Arabic pages the heart is the left-most tab, next to the ×. With a count, an empty list shows "0", the same as the Cart tab does today.

## Recommendation

- **Phone: E2.** It is centred to within 0.13px and leaves the header unchanged.
- **H1 is the capsule to choose if the owner wants a word rather than a letter.** H1 names the language you switch to, which is what a shopper is looking for. It fits only at 10px text, which is legible but small. The 12.5px capsule costs the logo 26–34px at every phone width, and the logo is locked.
- **Desktop: D2.**
- **Cart: the heart with no count.** It gives the text tabs the most room (93px each), and the filled heart already shows that the list has something in it. Use the count version if the owner wants it to match Cart.

The implementation notes from round 1 still apply. They cover the locked files and the full sweep owed, with one addition: the "ع" and "العربية" SVGs are constants printed into the header partial (allowed, because they are constants), and each has width/height attributes.
