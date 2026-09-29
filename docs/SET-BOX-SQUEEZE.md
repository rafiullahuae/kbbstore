# The "What is in this set" box — three treatments, measured (Lane SPL)

> "i want to redesign the products list box on the set product page. want nice
>  light background box type and inside a squeezed products list."
> — the owner, 29 September 2026

**Where it sits.** The storefront, on a set's own product page: the buy column,
between the price and Add to cart — `/product/{a set}/`. There is no admin
screen for it; it is drawn by
`resources/views/partials/set-contents-panel.blade.php`.

**Nothing here is shipped.** Three treatments are proposed, the owner picks one,
and the chosen file is then appended to the end of that partial's `@once`
`<style>` — one paste, no markup change, no new class, no new element. The
shipped box is byte-identical on this branch and `StorefrontEnglishUnchangedTest`
is the pin.

## One note on how these were shot

Twice during this round the harness was pointed at **another lane's shop**: two
other previews claimed the ports it had chosen, `php -S` lost the bind and said
so only in a log, and the run photographed a different catalogue while looking
completely finished. `tools/spl-preview.sh` now refuses to start when something
is already listening on its port, and after booting it asks for a slug only this
lane's fixture creates — anything but a 200 and the run stops. Every picture
below was taken after that check passed, and all 40 files have distinct hashes.

## The pictures

* `docs/lane-spl-shots/contact-sheet-1280.png` and `-390.png` — **this is the
  one to look at**: today's box and all three treatments side by side, with the
  eight-member set above and the three-member set below, at one scale.
* `ar-contact-sheet-1280.png` / `-390.png` — the same sheet on `/ar`.
* `box-{long,short}-{now,t1,t2,t3}-{390,1280}.png` — each box on its own, with
  16px of air around it so treatment 3's overhang is not cropped off.
* `page-{now,t1,t2,t3}-1280.png` — the whole product page, so the box can be
  judged in the column it actually lives in.

## The three treatments

|  | the object | the rhythm | what it argues |
|---|---|---|---|
| **1 · Filled tint** | one `--cream` panel, no border, no shadow, radius 16 | **no row rules at all**; white photo chips make the beat | the quietest. The fill alone says "these belong together", and border + fill + a rule per row is the same statement made three times |
| **2 · White card** | white, hairline border, soft shadow, radius 16, **blush header strip** and a cream footer strip | hairlines kept, but inset so they stop short of the card's edge | the most "object". It lies *on* the page rather than being a patch *of* it, and the two tinted ends bracket the list |
| **3 · Hanging photos** | a `--pink-soft` panel, radius 18, **photographs standing 10px outside its inline-start edge** | no rules at all; the overhanging chips are the rhythm | the one you can name from across the room |

The tints are the shop's own tokens — `--cream` and `--pink-soft`, the same two
the trust card and the announcement bar already use. No new hex was invented.

## The squeeze — measured in Chromium, both widths

The squeeze is deliberately **identical in all three**, so the owner is choosing
the box and not the row height.

### Desktop (1280px viewport; the buy column is 582px)

| | today | t1 | t2 | t3 |
|---|---|---|---|---|
| photograph | 40px | 34px | 34px | 36px |
| row padding (each side) | 6px | 4px | 4px | 3px |
| row rule | 1px | **0** | 1px (lighter) | **0** |
| name | 13.5px | 13.5px | 13.5px | 13.5px |
| brand | 10px | 10px | 10px | 10px |
| **row height** (mean of the five standing rows) | **52.8px** | **42.0px** | **42.8px** | **42.0px** |
| panel radius | — | 16px | 16px | 18px |
| panel inner padding (inline) | — | 14px | 14px (on the children) | 20px |
| **block height, 8 members** | **364px** | **323px** | **327px** | **328px** |
| block height, 3 members | 224px | 210px | 208px | 213px |
| `scrollWidth` | 1280 | 1280 | 1280 | 1280 |

**−20% on the row and −11% on the eight-member box**, which is the case that
matters: that block sits between the price and the Add to cart button.

Row height is the **mean of the five standing rows**, not the first row's. Read
off row 1 alone the figure reverses the finding it is meant to report: at 390
today's first name happens to fit on one line and the next one does not, so row
1 says 46px for today's list and 52px for a treatment whose block is 11px
shorter overall. `tools/spl-box-shots.cjs` records every standing row as well as
the mean, so the wrapping is visible rather than smoothed away — today's five
rows at 390 are `[46, 60, 47, 60, 60]` and every treatment's are `[52, 52, 52,
52, 52]`, which is the other half of what the box buys: an even list.

### Phone (390px viewport; the buy column is 346px)

| | today | t1 | t2 | t3 |
|---|---|---|---|---|
| photograph | 36px | 32px | 32px | 32px |
| row padding | 5px | 3px | 3px | 3px |
| name | 13px | 13px | 13px | 13px |
| name leading | 1.3 | 1.24 | 1.24 | 1.24 |
| brand leading | 1.3 | 1.15 | 1.15 | 1.15 |
| **row height** (mean of the five standing rows) | **54.5px** | **51.7px** | **52.5px** | **51.7px** |
| panel inner padding (inline) | — | 9px | 10px | 16px start / 9px end |
| **block height, 8 members** | **396px** | **385px** | **391px** | **387px** |
| block height, 3 members | 243px | **253px** | **253px** | **253px** |
| `scrollWidth` | 390 | 390 | 390 | 390 |

### The two findings behind those phone numbers

**1. At 390 the rows' height is set by the WORDS, not by the photograph.** At
1280 every name in this catalogue fits on one line, so the photograph decides
the row and 40 → 34 is worth ten pixels a row. At 390 half the names wrap to two
lines and a 32px photograph is already shorter than they are — shrinking it
further buys nothing at all. What buys height there is the leading: 1.3 → 1.24
on a two-line name and 1.3 → 1.15 on the brand, five standing rows of it.

**2. A box costs padding, and the first cut of these made the block TALLER than
the bare list it was squeezing** — 408px against 396px, because 12px of inline
padding on a 346px column took enough measure away that two of the five standing
rows gained a line. A squeeze that grows the block has failed whatever the row
padding says. The phone padding is therefore 9–10px, not 12–14, and the gap
between the photograph and the words is 8px rather than 9.

**And the three-member box on a phone is the one case that is still 10px taller
than today (243 → 253), in all three treatments.** Three rows do not give the
squeeze enough rows to pay back the panel's 16px of vertical padding. It is a
real cost of having a box at all at that width, it is stated rather than hidden,
and it is the same 10px whichever treatment he picks — so it does not affect the
choice.

## The floor, which none of them goes near

* the member name never below **12.5px** (all three are at 13.5 / 13);
* the photograph never below **30px** (all three are at 34–36 / 32);
* a member name is a **link** and keeps its hit target: `a.ksl-nm` carries
  `padding-block` with an equal negative `margin-block`, so the tappable box is
  25px at 1280 and 39px at 390 while the row's own height is unchanged by it.

`tools/spl-box-shots.cjs` refuses to take the screenshot at all if any of those
three is breached, on either width, in either language.

## Arabic

`scrollWidth` equals the viewport for every treatment at both widths on `/ar` —
16 measurements, all clean. The row stays three grid tracks on the inline axis
with no `[dir]` selector anywhere, so the photograph moves to the right-hand
side and the quantity to the left from the same declarations. Treatment 3's
overhang is `margin-inline-start`, so the chips hang off the **right** edge on
an Arabic page; see `ar-contact-sheet-1280.png`.

`tests/Feature/SetContentsBoxTreatmentsTest.php` reads every declaration in the
three files with `Tests\Support\CssDirection` — a real CSS declaration reader,
not a grep — and fails on a physical direction property or a `[dir]` selector.

## What is not in any of them

* **No JavaScript.** The fold is still HTML's own `<details>`; nothing measures
  layout; the element-measuring APIs are in the camera and nowhere near the page.
* **No new query.** These are CSS over markup that is already rendered;
  `SetEagerLoad::on()` keeps the page flat at 3 members and at 30.
* **No new setting.** The owner is choosing, not configuring. A picker over a
  decision already taken is a screen nobody opens.

## Found and not fixed — one line that looks like a bug on the phone

On a **wrapped** member name the link's underline draws under the FIRST line
only, so at 390px a two-line name reads as though a rule has been drawn through
the middle of it. It is visible in every column of `contact-sheet-390.png`
including **Today's** — it pre-dates all three treatments and none of them makes
it worse or better.

The cause is that `a.ksl-nm` is underlined with `border-bottom`, and a border on
an inline box is drawn on its first fragment rather than on every line of it.
The one-line fix is to underline with the text decoration instead:

    a.ksl-nm{border-bottom:0;
             text-decoration:underline;
             text-decoration-color:var(--line,rgba(42,34,40,.10));
             text-underline-offset:2px}

It is left out of the three treatments deliberately: it changes a link style on
a shipped page and it is not the thing he asked for. It belongs in the same
commit that ships whichever treatment he picks, called out on its own.

## To ship the one he picks

1. Append `tools/spl-box/t{n}-*.css` to the end of the `@once` `<style>` block in
   `resources/views/partials/set-contents-panel.blade.php`. Its rules are written
   at the same specificity as the ones already there, so they win by source order
   and by nothing else — no `!important`, no added selector.
2. Advance the `StorefrontEnglishUnchangedTest` pin for that one file,
   deliberately, reading the diff.
3. Delete `tools/spl-box/` and the other two treatments in the same commit.
