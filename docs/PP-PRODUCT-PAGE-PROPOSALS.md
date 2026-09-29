# Three proposals for the product page (Lane PP)

> *"i don't like that much the product page. can u also propose the product /
> set page more improved from the existing layout to derive more beautiful
> version, give me some options previews to choose from for now."*

Look at them here. No admin screen, no setting, nothing to switch on — put the
parameter on the end of any product URL:

| | |
|---|---|
| **A · Focus** | `/product/<slug>/?layout=focus` |
| **B · Editorial** | `/product/<slug>/?layout=editorial` |
| **C · Compact** | `/product/<slug>/?layout=compact` |

Take the parameter off and you are back on the page the shop ships, to the
byte. Try each on an ordinary product **and** on a set — they differ.

Photographs at 390 and 1280, plus a real phone viewport and `/ar`, are in
`docs/lane-pp-shots/`; every number below is in
`docs/lane-pp-shots/MEASUREMENTS.md`.

---

## First: what is wrong now, said precisely

The complaint is right. Measured, it is five things, and only the first four
are this lane's to answer.

1. **The gallery frame is 21% empty and it is what makes the page tall.** The
   frame is a square; the photographs are 4:5. At 1280 a 612×612 box paints a
   488×610 picture — the biggest element on the page is a fifth white.
2. **Eleven blocks, one rhythm.** The buy column's gaps run 7, 11, 6, 16, 18,
   9, 22, 6, 17, 14, 13. The largest gap on the page separates the options from
   the stock line; one of the smallest sits under the price. Nothing says which
   block is the decision.
3. **Three delivery promises, three styles, two places.** "Order within 11h 24m"
   above the button, "1–3 days fast delivery all over UAE" again below it, and
   the payment chips repeating Tabby and Tamara which the trust card just named.
4. **On a phone you scroll 1155px before you can buy**, on a screen 844px tall.
5. **28% of the page below the fold is empty boxes** — the description set to
   the full 1240px width for two lines, and a 454px reviews block for a product
   with no reviews. *That is `partials/product-tabs` and `partials/reviews`,
   another lane's files this round. Reported, not touched.*

---

## A · Focus — *"there is one decision on this page, so draw one object"*

Findings 2 and 4 are the same finding. The price, the options, the quantity and
the button are four of eleven equal blocks; make them **one bordered card** and
the other seven become what they always were — supporting text. Inside the card
Add to cart rises above the stock and delivery lines, because a shopper who has
chosen should not have to read a countdown first. The trust card and the payment
chips become two quiet rules at the foot of the column, and the columns are
rebalanced so a 4:5 frame is not a wall.

**Does better.** Add to cart **637 → 521** at 1280 and **1155 → 1093** at 390 —
and that second number is *after* the gallery grew 87px taller, which is the
proof the demotion pays for the picture. The page is shorter than it ships
(`.pdp` 753 → 728). The frame is 99% photograph. The bottom of the column stops
competing with itself.

**Gives up.** The blurb moves below the card on an ordinary product, so a
shopper who reads before deciding scrolls past the buy box — and that sits
slightly against the spacing you asked for in item 3, which is about the seam
*above* the options. This shop uses very few boxes; this adds a prominent one.

## B · Editorial — *"this is a beauty shop, not a spreadsheet"*

Answers the same flatness with **type** instead of a box. The title goes to
38px, the price to 34 with a rule above it, and the blurb is promoted to a lede
directly under the title on a 46-character measure — the order a magazine would
set it in. Buttons and option rows go square, groups are separated by hairlines
rather than by guessed gaps, and the gallery gets *more* room, not less.

**Does better.** It is the only one of the three that is actually beautiful. It
reads like a brand rather than a form, the blurb finally has a measure a person
can read, and the photograph is the argument.

**Gives up.** The money. Add to cart goes **637 → 758** at 1280 and 1155 → 1308
at 390, the page is 100px taller, and on a real 844px phone **the button is off
screen at the moment you are choosing an option**. It is a browsing page, not a
buying page.

## C · Compact — *"the shopper on a phone already knows what she wants"*

Finding 4 is the one that costs money. The frame is capped in viewport units
rather than sized from the column — a photograph may be big, but not the reason
a button is off screen. Option rows lose a third of their height, trust and
chips become two small lines, and on a phone the buy row is **stuck to the
bottom of the viewport** while the form is on screen: the same button, in the
same place in the reading order, not a second floating bar with a second copy
of the price.

**Does better.** The shortest page of the four at both widths (390: 3840 →
3754; `.pdp` 1280 → 1193). Add to cart is in the window whenever you are
choosing. Add to cart **637 → 554** at 1280.

**Gives up.** Warmth. A 21px title on a 1280 screen is timid, and capping the
frame in `vh` puts white **beside** the picture on a phone (297px of frame in a
346px column) — which is the same disease as finding 1, moved outside the frame.
On desktop the buy column now ends well above the gallery, leaving an empty
quarter to the right.

---

## What I would ship: **A · Focus**, with two borrowings

It is the only one that answers findings 1–4 together, and the only one that
makes the page both **shorter** and **easier to buy from** at both widths. It
does it with the shop's own type, colours and components — one border, and a
re-ordering — so it will not date, and it is the smallest change of the three to
hold in the codebase. Editorial is the prettiest and the worst at selling;
Compact sells best and does not look like anything.

Borrow two things into it:

- **Compact's sticky buy row on the phone.** It costs one rule and it is the
  single largest improvement available at 390.
- **Editorial's larger title.** 24px is too close to what ships. Settle around
  28–30px at 1280; 38 is a magazine, 24 is a spreadsheet.

### What shipping it would take

1. **The 4:5 frame is not mine to ship.** `.gmain{aspect-ratio:1}` and
   `partials/product-gallery.blade.php` are **Lane IM2's** this round. It should
   become a setting — *Appearance → Product page → Gallery frame* — defaulting
   to `1`, so applying the package moves nothing until somebody moves it
   (CLAUDE.md rule 1). Hardcoding 4:5 is right for this catalogue today and
   wrong for the first square photograph that arrives.
2. **Flex `order` is a preview device, not a shipping one.** It changes the
   visual order without changing the DOM or tab order, so a keyboard reaches Add
   to cart *after* the stock line while seeing it before. In the shipped version
   the markup moves, in `store/product.blade.php` — a small edit, and mine.
3. **The duplicate delivery promise is content, not CSS.** `DeliveryLine::here()`
   and `trust_delivery_text` say the same thing twice, and the chips repeat
   Tabby/Tamara from the trust card. Demoting them typographically hides the
   duplication; deleting one of them removes it. That is **Lane AD's** area
   (admin messaging).
4. **One decision is yours, not mine.** Does an ordinary product's blurb belong
   above the buy card, where it is now, or below it, as Focus draws it? Focus is
   built on "below"; item 3 of this round is about the space *above*. Say which
   and it is one line either way.
5. No route is added, so no `clear_caches_*` migration is needed — but the
   stylesheet changes, so the package must carry `public/build`.

**Nothing in this round ships any of it.** All three are CSS scoped under
`.pp-lay-*`, reached only by a query parameter, costing the same seven queries
as the page without one. Pick one and the next round builds it properly.

---

# THE ANSWER: none of them. (Lane PP2, 29 September 2026)

> *"all three options are exactly same, what's this? and the image will come
> square in any case and along with gallery images as thumnails."*

## He is right about the three

Read side by side, Focus and Editorial differ by a card border against two
hairline rules, one type step, and the capitalisation of one button. That is a
variation, not a choice. Three proposals a person cannot tell apart are one
proposal shown three times, and the fault is the round's, not the reader's.

So the feature is **deleted, not differentiated**: about 240 lines of
`.pp-lay`, `.pp-lay-focus`, `.pp-lay-editorial` and `.pp-lay-compact` are gone
from `resources/css/kbb/kbb-product.css`, and the three-entry map that emitted
the class is gone from `resources/views/store/product.blade.php`. `?layout=` is
an unread query parameter on this shop now; `.pdp` carries no class but `pdp`.

## And about the frame

`.pp-lay .gmain{aspect-ratio:4/5}` went with them, along with the two
`.pp-lay-compact .gmain{max-block-size:NNvh}` caps. **`.gmain{aspect-ratio:1}`
is untouched and is once again the only frame this shop has.**

The measurement above stands and is not in dispute — a 612×612 box paints a
488×610 picture at 1280, so a fifth of the largest element on the page is white.
It is kept here rather than left half-applied in a stylesheet, because it is a
fact about *this catalogue's current photographs*, and the person who
commissions the next shoot is the one who gets to decide whether the frame moves
or the photograph does. A square frame also has a property 4:5 does not: every
product page is the same height whatever shape its shot is.

## What was measured after the deletion

On a set carrying a main image, **three** gallery images and three pictured
members, plus the ordinary-product control (`tools/pp2-seed.php`,
`tools/pp2-shots.cjs`, shots in `docs/lane-pp2-shots/`):

| | scrollWidth | frame | fill | thumbnails | `.pdp` class |
|---|---|---|---|---|---|
| set, 390 | 390 | 346×346 | 99% | **4** | `pdp` |
| set, 1280 | 1280 | 612×612 | 99% | **4** | `pdp` |
| product, 390 | 390 | 346×346 | 99% | 4 | `pdp` |
| product, 1280 | 1280 | 612×612 | 99% | 4 | `pdp` |

`/ar` mirrors all four at identical geometry, `dir="rtl"`, `scrollWidth` equal
to the viewport at both widths.

**The 99% is the fixture, not a repair.** These seeded shots are square, so they
fill a square frame. On this catalogue's real 4:5 photographs the figure is the
79% measured at the top of this document. Nothing in this round moved it in
either direction.

## Gallery thumbnails on a set: there was no bug

The strip was missing from all six of the earlier shots because **the fixture
set carried one image**, and `partials/product-gallery.blade.php:99` renders
`.gthumbs` only `@if ($shotCount > 1)`. Nothing in the code path distinguishes a
set from any other product:

- `Store\ProductController::gallery()` (line 429) merges `[$product->image]`
  with `$product->images` and never looks at `type`;
- a set is a `products` row edited on the merged product editor, which owns the
  gallery field exactly as it does for a simple product
  (`Admin\ProductEditorApiController` lines 1091–1112 — no set branch);
- so a set with four shots draws four thumbnails, which is what the table above
  is, rendered.

The fix was the screenshot, not the code.
