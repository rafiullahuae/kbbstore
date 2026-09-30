# Appearance → Product grid — before and after                      (Lane GRID)

Taken in Chromium at 1280 and 390 against a real preview of this branch
(`./tools/plc-preview.sh 8993`, then `node tools/plc-grid-shots.cjs 8993
<before|after>`). The `before` pass runs the same script against the view as it
stood at the merge base, so the two sets differ only by this branch.

## The defect, and the number that proves it

`before-screen-1280.png` is the owner's screenshot reproduced: 32 designs, every
one an empty pink rectangle with a label under it.

**"32 designs are listed" was true the entire time the screen was broken**, so
the shot script counts something that was not: how many swatches contain a real
`.kbb-card`.

| measured                       | before | after |
|--------------------------------|--------|-------|
| swatches on the screen          | 32     | 32    |
| **swatches containing a card**  | **0**  | **32**|
| first swatch box @1280          | 151.4 × 90.3 | 146.7 × 306.1 |
| first swatch has a price        | false  | true  |
| first swatch has Add to cart    | false  | true  |
| right-hand preview exists       | false  | true  |
| cards in the preview            | 0      | 8     |
| card-content switches           | 0      | 7     |
| spacing sliders                 | 0      | 2     |

`document.documentElement.scrollWidth` equals `clientWidth` at both widths in
both passes — 1280 and 390 — so nothing added here overflows the page.

## The shots

| file | what it shows |
|------|----------------|
| `before-screen-{1280,390}.png` | the broken screen: 32 blank pink blocks |
| `after-screen-{1280,390}.png` | the designs rendering, preview on the right |
| `after-picked-bold-{1280,390}.png` | the preview following a selection — `Bold — dark luxury`, `previewSkin` reads `bold` |
| `after-cart-off-{1280,390}.png` | **Add to cart button** switched off: 0 visible Add-to-cart buttons across all 8 preview cards |
| `after-gap-32-{1280,390}.png` | **Gap between cards** dragged 16px → 32px, `--pg-gap` reads `32px` |

`after-cart-off-1280.png` also shows **Category label** and **Brand name** off —
their shipped 2.60.330 values — with neither line drawn in the preview. That is
the second defect fixed here: those switches reached no preview at all, because
the admin rules are `.skinprev .pc-nobrand …` (a descendant combinator) while
`psPreview()` puts the class on `.skinprev` itself.

## One layout bug these pictures caught

The first `after` pass measured four 85.5px tracks carrying four **132px** cards
— `.skinprev .kbb-card{width:132px}` pins the card to the popup thumbnail's
width — so `.pgpv-in` scrolled 451px inside a 418px box. `.pgprev.skinprev
.kbb-card{width:auto}` is the fix; after it, 100.5px tracks carrying 100.5px
cards and `scrollWidth === clientWidth === 478`. `ProductGridPreviewTest` pins
both that rule and the swatch's `width:100%`.

## Nothing on the shop moved

Every control here writes a key that already existed, through the endpoint that
already owned it, and each ships at the value the shop renders today. A save
sends only the keys the owner actually changed, so a control he never touched
leaves no `settings` row behind — a stored row at the current value is what
stops a later default from being seen.
