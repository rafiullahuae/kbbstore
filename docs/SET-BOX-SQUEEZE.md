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

> **Corrected 29 September by Lane FIN2, measured rather than read.** The
> symptom below is real and still open. The *diagnosis* under it was wrong, and
> the one-line repair it offered would have taken two live controls off the shop.
> Both corrections are in this section; the paragraph that was here is quoted so
> the change is visible rather than silent.

On a **wrapped** member name the underline is one hairline under the WHOLE name,
drawn at the width of its LONGEST line — so at 390px, where seven of this
fixture's eight names wrap, it sits under the second line and runs well past the
end of it, and reads as a stray rule in a box whose whole idea is that it has no
rules. It is pre-existing: it is in today's drawing exactly as it is in the
three treatments, and none of them makes it worse or better.

**What was written here before, and why it is wrong:**

> "the link's underline draws under the FIRST line only … a border on an inline
> box is drawn on its first fragment rather than on every line of it."

`a.ksl-nm` is not an inline box. `.ksl-w` is `display:flex;flex-direction:column`
and the link is one of its children, so it is **blockified** — one block box,
one `border-bottom`, at the bottom of the whole name. Measured in Chromium at
390 with `Range.getClientRects()`, which returns one rect per line box
(`tools/fin2-underline-shots.cjs`, `storage/fin2-logs/fin2-underline-before.json`):

| | first line bottom | last line bottom | painted border |
|---|---|---|---|
| today | 849.3 | 865.4 | **870.5** — 5.1px BELOW the last line |

Under the old reading the border would have been at ~849. It is not. Anyone who
"fixes the first-line underline" is fixing something that is not happening, and
`box-decoration-break` — the property the old reading points at — would change
nothing here.

**The repair is still `text-decoration`**, because a decoration underlines every
line at each line's own width and says "link" where a full-width hairline says
"row rule". But it is **three rules and not one**, and the one-line version that
used to stand here is the dangerous part of this section:

    a.ksl-nm{border-bottom:0;                          ← WRONG, do not paste
             text-decoration:underline;
             text-decoration-color:var(--line,rgba(42,34,40,.10));
             text-underline-offset:2px}

Pasted as written that snippet kills two controls that did not exist when it was
drafted, silently, with no error anywhere:

* `.ksl-noul a.ksl-nm{border-bottom:0}` is the storefront half of
  **Appearance → Set → Desktop / Mobile · "Underline the linked names"**
  (`SetAppearance::SCHEMA['p_underline_on']`, emitted as the class `ksl-noul` by
  `SetAppearance::panelClass()`). Cancelling a border that is no longer drawn
  cancels nothing, and the switch reports success and moves the page not at all.
* `var(--line, …)` reaches past `--ksl-linec`, which is that same screen's line
  colour. The slider stays on the screen and stops reaching the shop.

**The whole repair, all three rules, in the partial's `@once` `<style>`:**

    a.ksl-nm{color:inherit;
             text-decoration:underline;
             text-decoration-color:var(--ksl-linec,var(--line,rgba(42,34,40,.10)));
             text-decoration-thickness:1px;text-underline-offset:2px;
             padding-block:3px;margin-block:-3px}
    a.ksl-nm:hover{text-decoration-color:currentColor}
    .ksl-noul a.ksl-nm{text-decoration:none}

Note that `text-decoration:none` comes OFF the base rule as well — leaving it
there would cancel the `underline` on the same rule.

**Measured cost of making the change** (Chromium, `tools/fin2-underline-shots.cjs`,
the eight-member fixture):

| | 390 | 1280 |
|---|---|---|
| panel height, today | 387px | 328px |
| panel height, repaired | **382px** | **328px** |
| `scrollWidth` | 390 → 390 | 1280 → 1280 |
| names wrapping to two lines | 7 of 8 | 0 of 8 |

The 5px at 390 is the border's own pixel plus the rounding under it, eight rows
of it; the box gets slightly SHORTER, which is the direction this whole document
is arguing for. `StorefrontEnglishUnchangedTest` does **not** move — measured
with the repair applied, 46 passed across that pin, `SetAppearanceTest`,
`SetBuyColumnTest` and `SetContentsBoxTreatmentsTest` — because the English walk
seeds no set product, so the panel's style block never reaches a pinned page.
That is worth knowing in both directions: the pin does not cover this box at all.

`tests/Feature/SetContentsUnderlineTest.php` is the guard. It does not pin
`border-bottom`; it reads which property actually draws the underline and then
requires the OFF switch and the `:hover` rule to name that same property, so it
is green today, green after the repair above, and red on the half-repair. Proved
both ways: pasting the old one-line snippet turns three of its four cases red.

Pictures: `docs/lane-fin2-shots/setbox-before-390.png` and `-after-390.png`
(and the 1280 pair), with the per-name line-box geometry beside them in
`underline-before.json` / `underline-after.json`.

**Whose it is.** `resources/views/partials/set-contents-panel.blade.php` is Lane
SA2's this round, so Lane FIN2 did not make the edit. The three rules above are
the whole change.

## To ship the one he picks

1. Append `tools/spl-box/t{n}-*.css` to the end of the `@once` `<style>` block in
   `resources/views/partials/set-contents-panel.blade.php`. Its rules are written
   at the same specificity as the ones already there, so they win by source order
   and by nothing else — no `!important`, no added selector.
2. Advance the `StorefrontEnglishUnchangedTest` pin for that one file,
   deliberately, reading the diff.
3. Delete `tools/spl-box/` and the other two treatments in the same commit.
