# H1 — the desktop header: matched to the site width, on one row

The owner's request, verbatim:

> "Also the header need to be matched the width, and also adjusted as per the
> screen wihout line breaking etc. it should also capable to ajust / reduce the
> sizes of the stuff present the same layout in small screens. for small tabs
> and mobile we have a dedicated mobile version, which is fine. just do these
> for desktops."

Four things, and the fourth is the one that constrains the other three.

---

## 1. The boundary: where the desktop header stops and the mobile one starts

Stated first, because every change below had to stay on one side of it.

| | selector | at-rule |
|---|---|---|
| the dedicated mobile header takes over | `.hin`, `.logo`, `.sbox`, `.hact`, `.hinfo`, `.trend` | `@media(max-width:900px)` |
| the menu icon (burger) appears | `.kbbmi{display:flex}` | `@media (max-width: 900px)` |
| the mobile sheet, tab bar and scrim appear | `.mmenu`, `.tabbar`, `.mscrim` | `@media (max-width: 900px)` |
| the **desktop** header row | `.hin{gap:24px}`, `.sbox{margin-inline:8px}` | `@media (min-width: 901px)` |
| the **desktop nav bar** | `.mbar{display:none}` | `@media(max-width:1000px)` |

So the desktop header is **`min-width: 901px`**, and its nav bar additionally
needs **`min-width: 1001px`**. Everything this lane changed is either inside
`min-width:901px`, or outside every at-rule but inert below 1001px because its
only consumer (`.navlink`, rendered in exactly one template —
`resources/views/partials/nav-bar.blade.php`) lives inside a bar that is
`display:none` there.

`DesktopHeaderWidthTest > it leaves the mobile boundary exactly where it was`
pins all five rows of that table.

### The proof that the mobile header did not move

A screenshot cannot make this claim on this shop. The home page's hero carousel
and its lazily-loaded images mean **two runs of the same build produce different
PNG bytes** at 390 and 768 — measured, with nothing changed between them. So the
claim is made on computed styles instead, by `tools/h1-header-styles.cjs`, which
reads every rendered property of all 24 elements the header is built from and
diffs two builds:

```
widths   320, 390, 414, 600, 768, 820, 900
pages    /   /shop/   /ar/shop/

188,055 properties compared
    210 differ
      0 rendered rects differ
      0 documentElement widths differ
```

All 210 are one of two harmless kinds:

| selector | property | before → after | why it renders nothing |
|---|---|---|---|
| `header > .wrap`, `header .mbar .wrap` | `max-width` | 1280px → 1680px | an order of magnitude above the viewport, so it is never the constraint — the rects are identical |
| `header .navlink` | `padding-*`, `column-gap`, `row-gap` | 13px → 6px, 6px → 4px | `.mbar` is `display:none` below 1001px, so the element has no box |

Every property of `.hin`, `.logo`, `.sbox`, `.sbox input`, `.hact`, `.ib`,
`.ib svg`, `.kbbmi`, `.hinfo`, `.hi`, `.trend`, `.mmenu`, `.tabbar` and
`.mscrim` is byte-identical at every mobile width on all three pages.

---

## 2. "The header need to be matched the width" — it was a switch that did nothing

Appearance → Site layout → Page width has shipped a switch, **"Header follows
the site width"**, since the width package. It wrote
`:root{--hd-max:var(--site-max)}` out of `SiteLayout::cssVariables()`.

`HeaderSettings::cssVariables()` writes **the same property into the `style`
attribute of the `<header>` element itself**, on every request, whether or not
anything has ever been saved. An inline declaration on the element beats a
`:root` declaration outright — not a specificity contest it can lose, a stronger
origin — and `header .wrap`, the only reader of `--hd-max` in the repository, is
a child of `<header>`, so it inherited the inline value and never saw the other.

Measured in Chromium on `/shop/`, with the switch **saved on**:

| viewport | `header .wrap` | page container |
|---:|---:|---:|
| 1280 | 1280 | 1280 |
| 1680 | **1280** | 1680 |
| 1920 | **1280** | 1680 |

The switch moved nothing at any width while the admin screen reported it as on.

**The fix.** `--hd-max` has one writer now, at the level that wins:
`HeaderSettings::maxWidthCss()` emits `var(--site-max)` when the header follows
and the header's own number when it does not. `SiteLayout` no longer emits the
property at all, and its docblock records why.

**And it now ships ON.** That is a deliberate default change of the same kind as
the 1680 before it: the owner asked for it in as many words. One click on
Appearance → Site layout → Page width puts it back.

---

## 3. "Without line breaking", and "reduce the sizes … same layout"

### What the bar actually spends its row on

Measured on the twelve-entry menu this shop ships (`tools/h1-nav-geom.cjs`),
with wrapping suspended and `--nav-scale` at 1, the row needs **1325px**:

| | px |
|---|---:|
| the words | 979 |
| `.navlink` side padding (13 × 2 × 12) | 312 |
| gaps between items (2 × 11) | 22 |
| gaps inside items | 12 |

`nav-fit.js` multiplied **all four** by one scale until the row fit, so a bar
short of room shrank its words and its whitespace equally. At a 1024px viewport,
where the bar has 980px, that meant 9.48px type — with 9.48px of padding on each
side of each item, twelve times over: 227px of the 980 spent on space between
words that were by then too small to read.

### The answer, in CSS, on one render

```css
header{
  --hd-room:calc(min(100vw,var(--hd-max)) - 2 * var(--site-gutter));
  --nav-pad-x:clamp(6px,calc(.016827 * var(--hd-room) - 10.49px),13px);
  --nav-item-gap:clamp(4px,calc(.004808 * var(--hd-room) - .712px),6px);
}
```

`--hd-room` is `.wrap`'s content box exactly, at every width, with no breakpoint
and no script — and it references the **settings**, so lowering Site width to
1100 on a 2560px screen taper the bar correctly, which a `vw` ladder could not.

Both tokens reach the design values (13px and 6px, what `.navlink` has always
had) at `--hd-room` 1396px — a 1440px viewport. **Every desktop from 1440 up
renders the bar at exactly the sizes it rendered before this lane.** The taper
exists only below that, where the alternative was smaller words.

Nothing that decides the bar's **height** follows any of it: `padding`'s
vertical pair and `line-height` stay literal, so the bar is 45.5px at every
width before and after, and no scale change can shift the page.
(`GridPhotoLoadingTest` pins that for `--nav-scale`;
`DesktopHeaderWidthTest > it keeps the bar exactly as tall …` pins it for the
two new tokens.)

### The support block stopped leaving

`@media(max-width:1080px){.hinfo{display:none}}` dropped the WhatsApp block —
the shop's contact route — between 901px and 1080px. That is rearranging, which
is the opposite of what was asked for, and it was **not there for room**:
measured on `/shop/` at 1024 with it forced back on, `.hin` is one 46px row with
logo 163.7 + search 403.9 + icons 132 + support 186.4 and three 24px gaps —
958px inside a 980px content box, no overflow. `.sbox` is `flex:1 1 auto` and
absorbs the difference. The rule is gone; the phone's own
`@media(max-width:900px){.hinfo{display:none}}` stays.

---

## 4. The sweep

`tools/h1-measure.cjs`, Chromium, against a seeded shop. **`nav rows`** is the
number of rows the top-level items occupy, clustered by vertical overlap rather
than counted as distinct `offsetTop` values — `.mbar .wrap` is
`align-items:center` with items of unequal height, so a single row legitimately
reports two or three different tops and a naive count reported "2 rows" for a
45.5px bar. **`overflow`** is `documentElement.scrollWidth - clientWidth`.

Bold is what changed.

### LTR — `/shop/`

| viewport | header container | page container | nav rows | bar height | nav font-size | `.navlink` padding-x | item gap | support block | overflow |
|---:|---|---|---:|---|---|---|---|---|---:|
| 390 | 390 | 390 | hidden | — | — | — | — | none | 0 |
| 768 | 768 | 768 | hidden | — | — | — | — | none | 0 |
| 901 | 901 | 901 | hidden | — | — | — | — | none → **flex** | 0 |
| 960 | 960 | 960 | hidden | — | — | — | — | none → **flex** | 0 |
| 1024 | 1024 | 1024 | 1 | 45.5 | 9.48 → **10.75** | 9.48 → **4.96** | 4.37 → **3.31** | none → **flex** | 0 |
| 1180 | 1180 | 1180 | 1 | 45.5 | 10.97 → **11.86** | 10.97 → **7.87** | 5.06 → **4.33** | flex | 0 |
| 1280 | 1280 | 1280 | 1 | 45.5 | 11.95 → **12.52** | 11.95 → **9.93** | 5.51 → **5.04** | flex | 0 |
| 1366 | 1280 → **1366** | 1366 | 1 | 45.5 | 11.95 → **13** | 11.95 → **11.76** | 5.51 → **5.64** | flex | 0 |
| 1440 | 1280 → **1440** | 1440 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1536 | 1280 → **1536** | 1536 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1680 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1920 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 2560 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |

### LTR — home page — `/`

| viewport | header container | page container | nav rows | bar height | nav font-size | `.navlink` padding-x | item gap | support block | overflow |
|---:|---|---|---:|---|---|---|---|---|---:|
| 390 | 390 | 390 | hidden | — | — | — | — | none | 0 |
| 768 | 768 | 744 | hidden | — | — | — | — | none | 0 |
| 901 | 901 | 877 | hidden | — | — | — | — | none → **flex** | 0 |
| 960 | 960 | 936 | hidden | — | — | — | — | none → **flex** | 0 |
| 1024 | 1024 | 1000 | 1 | 45.5 | 9.48 → **10.75** | 9.48 → **4.96** | 4.37 → **3.31** | none → **flex** | 0 |
| 1180 | 1180 | 1156 | 1 | 45.5 | 10.97 → **11.86** | 10.97 → **7.87** | 5.06 → **4.33** | flex | 0 |
| 1280 | 1280 | 1256 | 1 | 45.5 | 11.95 → **12.52** | 11.95 → **9.93** | 5.51 → **5.04** | flex | 0 |
| 1366 | 1280 → **1366** | 1342 | 1 | 45.5 | 11.95 → **13** | 11.95 → **11.76** | 5.51 → **5.64** | flex | 0 |
| 1440 | 1280 → **1440** | 1416 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1536 | 1280 → **1536** | 1512 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1680 | 1280 → **1680** | 1656 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1920 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 2560 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |

### RTL (Arabic storefront) — `/ar/shop/`

| viewport | header container | page container | nav rows | bar height | nav font-size | `.navlink` padding-x | item gap | support block | overflow |
|---:|---|---|---:|---|---|---|---|---|---:|
| 390 | 390 | 390 | hidden | — | — | — | — | none | 0 |
| 768 | 768 | 768 | hidden | — | — | — | — | none | 0 |
| 901 | 901 | 901 | hidden | — | — | — | — | none → **flex** | 0 |
| 960 | 960 | 960 | hidden | — | — | — | — | none → **flex** | 0 |
| 1024 | 1024 | 1024 | 1 | 45.5 | 9.48 → **10.75** | 9.48 → **4.96** | 4.37 → **3.31** | none → **flex** | 0 |
| 1180 | 1180 | 1180 | 1 | 45.5 | 10.97 → **11.86** | 10.97 → **7.87** | 5.06 → **4.33** | flex | 0 |
| 1280 | 1280 | 1280 | 1 | 45.5 | 11.95 → **12.52** | 11.95 → **9.93** | 5.51 → **5.04** | flex | 0 |
| 1366 | 1280 → **1366** | 1366 | 1 | 45.5 | 11.95 → **13** | 11.95 → **11.76** | 5.51 → **5.64** | flex | 0 |
| 1440 | 1280 → **1440** | 1440 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1536 | 1280 → **1536** | 1536 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1680 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1920 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 2560 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |

### RTL — home page — `/ar/`

| viewport | header container | page container | nav rows | bar height | nav font-size | `.navlink` padding-x | item gap | support block | overflow |
|---:|---|---|---:|---|---|---|---|---|---:|
| 390 | 390 | 390 | hidden | — | — | — | — | none | 0 |
| 768 | 768 | 744 | hidden | — | — | — | — | none | 0 |
| 901 | 901 | 877 | hidden | — | — | — | — | none → **flex** | 0 |
| 960 | 960 | 936 | hidden | — | — | — | — | none → **flex** | 0 |
| 1024 | 1024 | 1000 | 1 | 45.5 | 9.48 → **10.75** | 9.48 → **4.96** | 4.37 → **3.31** | none → **flex** | 0 |
| 1180 | 1180 | 1156 | 1 | 45.5 | 10.97 → **11.86** | 10.97 → **7.87** | 5.06 → **4.33** | flex | 0 |
| 1280 | 1280 | 1256 | 1 | 45.5 | 11.95 → **12.52** | 11.95 → **9.93** | 5.51 → **5.04** | flex | 0 |
| 1366 | 1280 → **1366** | 1342 | 1 | 45.5 | 11.95 → **13** | 11.95 → **11.76** | 5.51 → **5.64** | flex | 0 |
| 1440 | 1280 → **1440** | 1416 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1536 | 1280 → **1536** | 1512 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1680 | 1280 → **1680** | 1656 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 1920 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |
| 2560 | 1280 → **1680** | 1680 | 1 | 45.5 | 11.95 → **13** | 11.95 → **13** | 5.51 → **6** | flex | 0 |

`/best-sellers/`, `/new-in/`, `/super-sale/` and `/ar/best-sellers/` were swept
at the same widths and are identical to `/shop/` row for row.

**A caveat, stated rather than glossed.** The seeded Arabic storefront renders
the same English menu labels — the menu has no Arabic translations in this
database — so the RTL sweep proves the geometry is direction-independent (the
logical `inset-inline-*` insets, the mirrored row, zero overflow at every width)
but not that Arabic text metrics fit. That is the same limit the shipped answer
has, and it is the reason the last step of the fit is still a measurement rather
than an estimate: see below.

---

## 5. `nav-fit.js`: what changed, and what could not

The lane brief asked for this file to be replaced with a rendered-once CSS
answer. **Most of it now is one** — the whitespace taper above is where a third
of the row's width was going, and after it the shipped menu needs no scaling at
all from 1366px up. The remaining scale factors are:

| viewport | `--nav-scale` before | after |
|---:|---:|---:|
| 1024 | 0.729 | 0.827 |
| 1180 | 0.844 | 0.912 |
| 1280 | 0.919 | 0.963 |
| 1366 | 0.919 | **1 (none)** |
| 1440 and up | 0.919 | **1 (none)** |

**The last step cannot move, and the reason is not effort.** Fitting a row to
its text requires knowing how wide the text is, and CSS cannot ask: there is no
length meaning "the width of this element's content", and container query units
measure the **container** — the space available, never the space needed. So a
CSS-only answer has three possible shapes and this shop can have none of them:

- **ellipsis or clip** (`min-width:0` + `text-overflow`). Never wraps, never
  overflows, and truncates the menu's words instead of shrinking them. Not what
  was asked for.
- **a server-side estimate of the label widths**, emitted as a number the clamp
  multiplies. This was costed rather than dismissed: it needs per-glyph advance
  widths for Poppins 600, the labels are whatever the owner types in Store &
  content → Menus, and the Arabic storefront renders them in a fallback face
  this application has no metrics for. Underestimate by six pixels and the bar
  wraps — the exact defect `NavBarContainsItsOwnOverflowTest` was opened for. An
  estimate made deliberately generous instead never wraps and always renders
  smaller than it needed to, on every shop, in every language.
- **a fixed ladder of media queries**, which is what four column systems did on
  this shop before the width lane deleted them, and which cannot know the menu.

A real measurement, made once per resize on eleven boxes, is correct in every
language without knowing anything about any of them, and its one cost — the
layout shift that a changing font-size used to cause — was fixed separately by
the fixed 19.5px line box and is pinned by `GridPhotoLoadingTest`.

The shipped code is therefore: **CSS renders the bar at a size that is already
close, once, with no script; `nav-fit.js` closes the remaining few per cent and
is the only thing that can catch a menu nobody measured.** The file's own header
carries this argument so that a later reader does not "finish the job" by
deleting it. Its compiled output is byte-identical — `app-CaC60Fnq.js` keeps its
name across this change, because only comments moved.

---

## 6. Found, not fixed

- **There is no navigation at all between 901px and 1000px.** `.kbbmi` (the
  burger) is revealed only under 900px and `.mbar` is hidden under 1000px, so at
  901–1000 a visitor gets the header row with no menu of any kind and no way to
  reach one. Measured at 901 and 960 on every page swept, before and after this
  lane. Not fixed here: closing it means moving one of those two breakpoints,
  and `NavBarContainsItsOwnOverflowTest` pins the 1000px one by name with "this
  lane must not change it". It needs a decision about which surface owns that
  band, not a nudge.
- **`.stickybar .in` and `.head-in` in `kbb-product.css` are still 1180px**, a
  deliberate match for the old 1280px header that is now a mismatch with a
  1680px one. That sheet belongs to the product page, not to this lane.
- **`.hi:nth-child(2)` and `.hi:nth-child(3)`** are hidden at 1280px and 1500px
  respectively. They match nothing today — the header renders exactly one `.hi`
  — so they are inert, and they are somebody's policy for the day it renders
  more. Left alone.

## 7. Where it sits in the admin

- **Appearance → Site layout → Page width → "Header follows the site width"** —
  now ships **on**. Off returns the header to its own number.
- **Appearance → Header → Bar → Content width** — that own number, 1280px by
  default. It is what the switch defers to, and it only has an effect while the
  switch is off.
- Nothing else was added. The nav taper has no control: it is derived from the
  two sliders above plus **Appearance → Site layout → Page width → Side gutter**.
