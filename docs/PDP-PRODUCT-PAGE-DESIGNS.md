# Five product pages to choose from — Lane PDP

> *"For product page also i want a dedicated lane. to redesign almost fully. i
> want the mobile version like this, image, then beautiful gallery, then small
> brand name with link, then product name, and right side cut price and actual
> price beautifully present. and then small thin rating bar, and then 2-3 lines
> short description with fade read more. and then bundles purchase bars (we have
> that already on the product page), and then quantity + add to cart button row.
> and then product tabs like Description, Ingredients, How to use etc, but i want
> these tabs in same row with opened promient, and other slightely faded by
> default, and can be scroll left to right between tags and and can directly
> click on any tab, it will open. i want a nice idea for this. then Authenticity
> line, delivery line , and payment icons. keep the font, boldness of text etc
> same as in screenshot. give me 5 different ideas surrounding related to the
> attachment to choose from. for desktop and mobile, both 5 each designs."*

**Nothing here is switched on.** The shop serves exactly the page it served
yesterday — byte for byte, and there is a test that says so. These five are
whole pages at their own addresses, drawn on the real catalogue, behind the
admin login.

## The two pictures to look at first

| | |
|---|---|
| **Mobile, all five side by side** | `docs/lane-pdp-shots/sheet-390.png` |
| **Desktop, all five side by side** | `docs/lane-pdp-shots/sheet-1280.png` |

And four more that answer the questions the whole-page sheets cannot:

| | |
|---|---|
| **The five tab ideas, close up** | `sheet-tabs.png` (390) · `sheet-tabs-1280.png` |
| **What follows you down the page** | `sheet-sticky-390.png` · `sheet-sticky-1280.png` |
| **The same five on a SET** | `sheet-set-390.png` |
| **Arabic, mirrored** | `sheet-ar-390.png` |

## Where they are

**Catalog → Product page → Design previews.**

    /admin-api/catalog/pdp-preview                              the chooser
    /admin-api/catalog/pdp-preview/{design}/{product-slug}       one drawing
    …?lang=ar                                                    the same in Arabic

`{design}` is one of `ledger`, `dossier`, `counter`, `deck`, `marquee`. Every
drawing carries a bar at the top with the other four on it, so you can flick
between all five on the same product without going back to the list.

> ▲ **The integrator has to add one line before these URLs work.**
> `routes/web.php` is his file. Inside the existing `admin-api` group, beside
> the other catalog route files (today that is line 692):
>
>     require __DIR__.'/catalog-admin.php';
>     require __DIR__.'/pdp-preview-admin.php';      // <- this
>
> The package that ships it also needs a `clear_caches_*` migration, because
> `routes/web.php` is compiled on the server and these routes do not exist until
> `bootstrap/cache/routes-*.php` is gone.
>
> **Two tests are red until that line lands, and nothing else is.** `PdpPreview-
> Test > it requires routes/pdp-preview-admin.php exactly once` and the
> repo-wide `EverythingIsMountedOnceTest`. Both pin the FINISHED state, which is
> what CLAUDE.md asks for — `0` is the "built, never wired up" shape this repo
> keeps finding, `2` mounts every path twice and Laravel keeps the last. Checked
> rather than assumed: with that one line added, `EverythingIsMountedOnceTest`,
> `AdminCapabilityMapTest` and all fourteen cases in `PdpPreviewTest` pass — 32
> passed, 0 failed — and the line was then taken back out, because `routes/web.php`
> is not this lane's file.

---

# The five

Read the sheet first. These are the arguments behind it.

## A · Ledger — *there is not a single box on this page*

The photograph runs to both edges of the phone with no frame and no corner
radius; the thumbnails float on its bottom edge; everything below is separated
by hairline rules. Nothing is elevated, nothing is tinted, nothing has a border.
The only strong marks left on the page are the picture, the price and the button.

**Tabs.** An underline row — one rule under the open title, the rest at **38%**.
"There is more to the right" is said by a **mask**: the row bleeds to the screen
edge and its last 34px dissolve into the page, so a tab is visibly *cut* rather
than visibly ending. No arrow, no width spent.

**Desktop.** Two columns; the photograph keeps its bled edge on the outside.
Title **30px** — the largest of the five.

**Gives up.** It is the quietest and the least "designed". A shopper who wants to
be told where to look is not told.

## B · Dossier — *one object holds the whole decision*

The opposite move. Brand, name, price, rating, blurb, bundle bars, quantity and
Add to cart all go into **one white card with a shadow**, and that card starts
34px *up* the photograph, overlapping it. Nothing else on the page is a card, and
that is what makes the card mean something.

**Tabs.** A pill row that **pins under the site header** as you read, so the
other tabs are reachable from the middle of a long ingredients list. Open pill is
solid ink on white; the rest are cream at **50%**. "More to the right" is said by
the **peek** — the track stops 40px short of the edge so the next pill is always
half visible.

**Desktop.** The card **follows you down the page** (the behaviour the shipped
page currently gives to the *gallery*, handed to the half with a button in it).

**Gives up.** It is the most conventional of the five — you have seen this card
on fifty shops — and a card with this much in it is tall.

## C · Counter — *the price is the loudest thing on the page*

On a phone the price leaves the name row and takes a **tinted band of its own**,
edge to edge, at **29px — bigger than the product name**. On a desktop that band
becomes a **third column**: a price head and a **buy rail that stays on screen**
while you read, beside a middle column carrying the reading matter.

**Three columns, not two — the only one of the five that changes the page's
skeleton when it gets wide.** And the order you asked for survives both: the
desktop is made by *grid placement*, not by moving anything, so a phone still
reads brand → name → price → rating → blurb → bundles → quantity + Add to cart.

**Tabs.** A **segmented control** in a cream well, the way a phone's own controls
look, with a white fill that **slides** to the open one. The panel opens in a
matching cream well below, so strip and contents are visibly one object. "More to
the right" is said by the **well**: a segment is always clipped by its rounded
end.

**Gives up.** Warmth, and some of the photograph's authority — a 29px price on a
pink band is a supermarket gesture, and this is a beauty shop. Three columns at
1280 also means the narrowest picture of the five (**425px** against 618–640).

## D · Deck — *the tab row **is** the panel*

**This is the answer to the thing you asked for an idea about.**

Books on a shelf. Every tab is a card in one horizontal, snapping row. The open
one is a **spread** — full width, title at the top, its text inside it. The
closed ones are **spines**: 44px wide, titles stood on end, at 50%. Tap a spine
and it becomes the spread.

All four of your requirements are met by *the same object* rather than by four
mechanisms — one row, open prominent and the rest faded, scrolls left to right,
tap any tab and it opens. **And the "more to the right" problem solves itself:**
there is no arrow because there is nothing to point at. A spine is always
standing at the edge of the screen.

The rest of the page stays out of its way — no cards, no tints, no bands, because
two strong objects on one page is one too many. The one other move is the
**dock**: the quantity and Add to cart row stays at the bottom of the screen while
you are in the part of the page that is about buying, and releases when you
scroll past it. Not a second floating bar with a second copy of the price — the
*same* button, in the same place in the reading order.

**Gives up.** Vertical titles are a device, and a device can wear out — a shop
with eight tabs would show a picket fence. It reads less like a standard product
page than the other four, which is either the reason to choose it or the reason
not to.

## E · Marquee — *a page of full-width bands, and one of them is dark*

The page is made of **stripes**: each group runs edge to edge and is told from its
neighbours by its ground — white for the photograph and the name, pink for the
bundle bars, white for the buy row, **ink for the tabs**, pink again for the
assurances. Scrolling fast, you know which band you are in from the colour alone.

**Tabs.** A full-bleed **ink band** with the open tab in solid white and the rest
at 45% — the highest contrast between open and closed of the five, and the most
literal reading of *"opened prominent, and other slightly faded"*. The panel opens
as a white sheet directly beneath it. "More to the right" is said by a
**proportional hairline**: two pixels of pink, one fifth of the band wide, sitting
one fifth along — it says how many tabs there are *and* which one you are on, and
costs no width at all.

**Desktop turns twice.** The band stands up into a **210px vertical rail** of tab
titles down the side of the panel, pinned as you read — the only one of the five
whose tab strip is not horizontal at 1280. And the **thumbnails stand up** in a
72px column beside the photograph.

**Gives up.** A dark band across a beauty shop is a strong statement and is the
one thing here that could date. The stripes also make the page feel longer than it
is, because every seam is drawn rather than implied.

---

# The numbers, measured

Chromium, 390×844 and 1280×900, on `pdp-heartleaf-toner` (main shot + 3 gallery
shots, on sale, 5 real reviews, 3 bundle bars, 5 tabs). Everything below is in
`docs/lane-pdp-shots/MEASUREMENTS.json`.

| | A Ledger | B Dossier | C Counter | D Deck | E Marquee |
|---|---|---|---|---|---|
| **390** page height | 2761 | **2612** | 2675 | 2705 | 2698 |
| **390** Add to cart, in flow | 1401 | **1291** | 1335 | 1281 | 1328 |
| **390** button on screen while choosing | ✓ | ✓ | ✓ | **✓ (docked)** | ✓ |
| **390** title / price | 21 / 22px | 20 / 21px | 19 / **29px** | 21 / 21px | 20 / 21px |
| **1280** page height | 1654 | **1580** | **1433** | 1729 | 1653 |
| **1280** Add to cart | 817 | 691 | **317** | 663 | 745 |
| **1280** title / price | **30** / 22px | 27 / 26px | 24 / **33px** | 28 / 26px | 28 / 26px |
| **1280** picture width | **640** | 618 | 425 | 618 | 534 |
| closed-tab opacity | 0.38 | 0.50 | 0.45 | 0.50 | 0.45 |
| tab row overflow at 390 | 240px | 333px | 282px | 125px | 214px |

True of all five, at both widths:

- `document.documentElement.scrollWidth` = the viewport (**390 / 1280**). No
  horizontal page scroll anywhere, in either language.
- Gallery frame `aspect-ratio: 1 / 1` and **4 thumbnails**. *The main image is
  square in any case — your words, and not re-litigated in any of the five.*
- **5 tabs**, the row really overflows at 390 (so "scroll left to right" is a
  behaviour and not a claim), **exactly one panel open**, and clicking the fourth
  tab opens the fourth panel and no other — asserted by a real click in the
  harness, per design, at both widths.
- Blurb clamped to **3 line-boxes (66px)** with a real `mask-image` fade and a
  "Read more" that opens it.
- **3 bundle bars** on an ordinary product; **0 bundle bars and 3 contents rows**
  on a set.
- 2 assurance lines + 5 payment marks.
- **0 `<script>` elements** inside any design.

## Arabic

`sheet-ar-390.png`, on A, C and E — the three whose strips are most
direction-sensitive. `dir=rtl`, `scrollWidth` 390, the tab row scrolls the other
way (overflow 133–282px), the rating bar fills **from the right**, C's segmented
fill sits on the right and E's hairline runs the other way. All of it by
`inline-size` and `inset-inline-start`; the one `[dir="rtl"]` rule in the whole
sheet is A's edge mask, because `mask-image` gradients have no logical form.

*Known and deliberate:* the bundle tier labels ("1 unit", "2-pack bundle") and
"Save more with bundles" are still English — they come from the shop's own
`bundle_tiers` setting and the shipped template's own literal, neither of which
this lane may change. Fixture only; nothing to fix in a design.

---

# How this is built, and why it costs nothing to throw away

Four of these five are going to be deleted. That shaped every decision.

- **There is no `?layout=` switch on the product page, and there will not be
  one.** That is what the previous round did: a three-entry map inside
  `resources/views/store/product.blade.php`, none of the three chosen, and then
  ~240 lines of dead CSS for another lane to delete
  (`docs/PP-PRODUCT-PAGE-PROPOSALS.md` carries the banner). **This lane does not
  touch that template at all.** `PdpPreviewTest` fetches `/product/{slug}/` with
  `?layout=ledger`, `?layout=focus`, `?pv=deck`, `?candidate=counter` and
  `?lang=ar` on it and requires all five to be **byte-identical** to the page
  with no query string.
- **The data is the real page's data.** `Admin\PdpPreviewController` calls
  `Store\ProductController::show()` and hands its View's data to a candidate
  template. Real gallery, real `BundleService` bars, real `ProductTabs`, real
  `TrustClaims` and `PaymentChips`, real `SetContents` panel. There is no query
  in the preview controller, so no N+1 can hide in it.
- **The stylesheet is inline**, in `resources/views/store/pdp-preview/_css.blade.php`.
  A file under `resources/css/kbb/` would need a Vite entry, a hand-run
  `npx vite build` and `public/build` committed — a new manifest key on the live
  shop for a page the live shop cannot reach, and a second commit to take it
  away. `kbb-product.css` is not touched, so its manifest hash does not move.
- **Deleting all five** is `git rm` of one directory, one controller, one route
  file and one test. **Deleting four** is deleting four blades and four blocks of
  that stylesheet.

## No JavaScript at all

Not "no JavaScript that measures layout" — **none**. The tab switch is a radio
group and `:checked ~`; the read-more is a checkbox; the scrolling is
`overflow` + `scroll-snap`; the fade is `mask-image`; the sliding fill and the
progress hairline are `calc()` on the tab's index and the tab count, which the
server already knows. They are keyboard-operable for free and work with
JavaScript off.

`PdpPreviewTest` scans every file under `resources/views/store/pdp-preview/`
(comments stripped first, since several of them *name* these APIs in order to
explain why they are not used) for `getBoundingClientRect`, `offsetWidth`,
`offsetHeight`, `offsetTop`, `offsetLeft`, `clientWidth`, `clientHeight`,
`scrollWidth`, `scrollHeight`, `getComputedStyle`, `ResizeObserver`,
`IntersectionObserver`, `<script`, `onclick=` and `addEventListener`, and
separately counts `<script>` in the rendered answer.

## Gated

`admin-api/catalog/**` is already governed by
`['GET', 'admin-api/catalog/**', 'catalog.view']` in
`App\Support\AdminCapabilities::RULES`, so these two GETs are readable by exactly
the roles that may already look at the catalogue and by nobody else. A prefix of
this lane's own would fall through to the closed owner-only default. Signed out
is refused. The candidate name is checked against
`PdpPreviewController::CANDIDATES` before it names a view file; `?lang=` is
checked against `Locale::isSupported()` before it reaches `setLocale()`.

---

# Mutations, all run

| # | Change | Result |
|---|---|---|
| M1 | `@if (request('layout') === 'ledger')<i>x</i>@endif` in `store/product.blade.php` | **red** — *byte for byte whatever query string is on it* |
| M2 | drop `Locale::isSupported($lang)` from the controller | **red** — locale reads `../../etc/passwd` |
| M3 | drop the `array_key_exists` candidate guard | **red** — `focus` 500s instead of 404s |
| M4 | remove `@include('partials.set-contents-panel')` from the options slot | **red** |
| M5 | add `<script>…getBoundingClientRect()</script>` to `parts/tabs.blade.php` | **red** in both the source scan and the rendered count |
| M6 | move the buy row above the bundle bars in D | **red** — names the pair that is out of order |
| M7 | replace `@if ($rcount)` with `@if (true)` in `parts/rating.blade.php` | **red** — an unreviewed product draws a 0.0 bar |

## What the rest of the suite said

`KBB_WP_DB=kbb_wp_pdp vendor/bin/pest --compact` — **7781 passed, 22 skipped**,
and five reds, every one of them acted on:

| Red | What it was | What was done |
|---|---|---|
| `StorefrontStringsAreKeyedTest` | the **chooser's** five lines of English, in `views/store/` | moved to `views/admin/`, where the back office's templates live. The five designs stay under `views/store/` and stay scanned — they pass. |
| `StableOrderingTest` | `PdpPreviewController:110` — `orderBy('name')` under a `limit(60)` **can tie**, so the chooser would list a different sixty on different requests | `->orderBy('id')` after it |
| `SetListPanelControlsTest` | the panel is `@include`d in a **third** file | the pin is **advanced, not loosened**: the list stays exact and now has three entries, with the reasoning and a note that it goes back to two when the previews are deleted. *This is a file outside this lane's own directories — flagged for the integrator.* |
| `EverythingIsMountedOnceTest` | `pdp-preview-admin.php is required 0 times` | the integrator's one line, above |
| `PdpPreviewTest > requires … exactly once` | the same thing, pinned by this lane | the same line |

> **M4 was GREEN on its first run and the test was wrong, not the code.** It
> asserted `toContain($member->name)` — and a set's member names are in the
> document anyway, because `Store\ProductController` publishes the set's contents
> in the schema.org block in `<head>`. It now asserts `ksl-rows`, a class only
> the panel draws. An assertion a bug walks straight past is worse than no
> assertion, because it is counted.

## Five defects this lane shipped, and caught in its own pictures and guards

Recorded because each is the kind of thing a screenshot catches and a passing
suite does not.

1. **The rating hairline and the preview's own chrome were both called
   `.pv-bar`.** Every rating on every candidate was painted as a solid ink
   rectangle by the chrome's rule — ten shots, all wrong. Renamed `.pv-ratebar`.
2. **`writing-mode: vertical-rl` swaps what block and inline mean on the element
   that carries it.** D's spines used `min-block-size:150px`, which asked for
   150px of *width* inside a 44px card with `overflow:hidden` — five empty white
   boxes. That is the one place in the sheet where physical `width`/`height` is
   correct and logical properties are the bug.
3. **The lifted card in B overlapped its own brand line.** The card starts 34px
   up the page and its top padding was 20px, so on a set — where the brand is
   long enough to reach the middle of the thumbnail strip — the strip painted
   over "Beauty of Joseon". The top padding is now the same 34px the card
   overlaps by: a z-index is the wrong answer to a layout that puts two things
   in the same place.
4. **The chooser's own prose sat in a shopper-facing directory.**
   `StorefrontStringsAreKeyedTest` walks every Blade outside `views/admin/` and
   reported five bare English sentences in it. It was right — the file was in
   `views/store/`. It is an admin screen and now lives in `views/admin/`. The
   five *designs* stay under `views/store/` and stay scanned on purpose: they
   are drafts of the shopper's page, and all five pass.
5. **`position: sticky` and full-page capture do not mix.** Chromium resizes the
   viewport to the whole document, so D's dock was painted *over the product
   title* and the shop's own sticky header landed mid-page in three of the five.
   The harness now shoots each design twice: a **flow** shot with sticky
   neutralised (the contact sheet) and a **sticky** shot of one viewport scrolled
   to the buy block. The flatten list had to be narrowed too — it originally
   included every `.pv-tabrow`, which removed the `position:relative` that C's
   sliding fill is positioned against and painted a 270px white rectangle down
   over the panel.

---

# Running it again

```sh
sh tools/pdp-shoot.sh 8987          # seed → English shots → Arabic on → Arabic shots
```

Order matters and the script enforces it: turning Arabic on adds a language
switcher to the header and an `hreflang` pair to `<head>` that the English shop
does not have today, so the ten English drawings are taken first.

- `tools/pdp-preview.sh` boots a throwaway SQLite shop and mounts
  `routes/pdp-preview-admin.php` from the preview's own copy of the front
  controller (never the repository's), with the same stack the integrator's
  `require` will give it.
- `tools/pdp-seed.php` builds the fixture: the ordinary product, the set, the
  sold-out one, five tabs, five real reviews.
- `tools/pdp-shots.cjs` takes the shots and the sheets and writes
  `MEASUREMENTS.json`.

```sh
KBB_WP_DB=kbb_wp_pdp vendor/bin/pest tests/Feature/PdpPreviewTest.php --compact
```

---

# What happens next

Pick one — or pick one and name what you want borrowed from another. Then the
chosen design becomes an **edit to `resources/views/store/product.blade.php`**,
not a sixth copy of it, and this whole directory goes:

    app/Http/Controllers/Admin/PdpPreviewController.php
    routes/pdp-preview-admin.php
    resources/views/store/pdp-preview/
    resources/views/admin/pdp-preview-index.blade.php
    tests/Feature/PdpPreviewTest.php
    tests/Support/PdpPreviewRoutes.php
    tools/pdp-preview.sh  tools/pdp-seed.php  tools/pdp-arabic-on.php
    tools/pdp-shots.cjs   tools/pdp-shoot.sh

The storefront is untouched by that removal, because it was never touched.
