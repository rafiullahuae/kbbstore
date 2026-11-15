# Bonus gift inside the Free gifts box: previews (Lane GB, revised by Lane GB3)

The owner, 10 Nov 2026: *"on the cart panel, cart and checkout, the additional free gift
product should inside the gifts box with upper side live timer, give me multiple previews.
i don't want additional separate box for it as we have currently."*

These are previews only. No shop code changed. Each design is drawn INTO the real Free gifts
box (`partials/free-gifts/box.blade.php`) on the preview shop, and the separate gift-offer row
(`partials/gift-offers/row.blade.php`) is hidden. Revised 11 Nov 2026: the bonus gift sits LAST in every design ("i wanted the additional gift product at the last, not as 1st"). The claimed gift is Relief Sun ×2.

**Revised 15 Nov 2026 (Lane GB3).** The owner: *"in place of word bonus, i need the timer, and remove
teh top bar timer row also, display me again"*, then *"and if time end, it will removed completely from
the box silently without anything showing behind like Ended etc."* So in every design:
- **Nothing sits above the gifts row any more.** The bar, pill, strip, cream line and flip header are
  gone; the box's own title and wording line are exactly as the shop draws them today.
- **The live clock (mm:ss, ticking) sits where BONUS was**, on the bonus gift itself. It turns red under 2 minutes.
- **When the time ends the bonus simply leaves.** The bonus gift, its clock and its name line fade
  (opacity/transform, 0.4 s) and are removed: no "expired" line, no "Ended" pill, no gap. The box is then
  exactly as tall as today's box with only the regular gifts (measured below).

| | Design | Where the clock sits now |
|---|---|---|
| A | Pink timer tag | The bonus is the LAST photo, ringed in pink. Its pink tag, which read BONUS, now reads **14:52** with a hairline under the digits that shrinks as time runs out. ×2 on the corner; one line under the row names it. |
| B | Cream card, clock chip | Regular photos first; the bonus is the wide cream card at the foot (photo, name). Its first chip, which read "Bonus · Free", is now a dark stopwatch chip **⏱ 14:52**, then Free · Full size · ×2. |
| C | Ringed photo, clock on the ring | The bonus photo ends the row inside a pink ring that shrinks as time runs out; the clock is a small pill **on the foot of the ring**. One line under the row names it. |
| D | Two-part box, clock chip at the foot | Regular gifts as today, a dashed green divider, then the bonus at the foot (photo, name) with a dark clock chip **⏱ 14:52** first, then Free · Full size · ×2. |
| E | Gold frame, flip-digit tag | The bonus ends the row in a gold frame with a gold ×2; a gold tag at the foot of the frame holds tiny dark flip digits **1 4 : 5 2** that slide in as they change. |

Motion is `transform`/`opacity` only (the `scaleX` hairline, the ring's two rotating halves, the
stopwatch hand, digits sliding in, the fade on expiry). Nothing is measured.

**Readable at the 278px cart panel (390 phone), measured** (`result.json` → `timer`): clock text
A 10.5px · B 11px · C 10.5px · D 11px · E 10px. The tag overlaps the photo box by its bottom 2px only
(A 5.3%, C 5.4%, E 5.2% of the box: the frame and white margin, not the product); in B and D the chip
sits beside the photo (0). The bonus is the last gift in every live shot, and nothing of ours is above
the row in any of the 90 shots.

## Files
- `contact-A.jpg` … `contact-E.jpg`: one sheet per design: cart panel, cart page and checkout summary,
  each at 390 and 1280, each live / under 2 minutes / expired.
- `preview.html`: all five designs side by side at phone width (the 278px cart panel) and desktop
  width (the 568px cart page). The clocks tick; the "watch it end" box starts at 10 seconds and shows
  the bonus leave the box.
- `shots/`: every screenshot (`<design>-<panel|cart|checkout>-<390|1280>-<live|low|ended>.png`, 90),
  plus `before-*` (today's two separate boxes) and `preview-page-*`.
- `designs.cjs` (the designs), `shots.cjs` (draws and photographs them, and builds the sheets and
  preview.html), `check-preview.cjs`, `gb-seed.php` (adds three range minis so the row looks like
  the live shop), `result.json` (box sizes, scrollWidth, the clock's size and overlap).

## Run
```sh
GO_LANE=lane-gb3 GO_SEED=../docs/plans/gift-box-merge/gb-seed.php sh tools/go-preview.sh 9690
GO_LANE=lane-gb3 GO_BASE=http://127.0.0.1:9690 NODE_PATH=/home/user/kbbstore/node_modules node docs/plans/gift-box-merge/shots.cjs
NODE_PATH=/home/user/kbbstore/node_modules node docs/plans/gift-box-merge/check-preview.cjs
```
Both scripts pick the installed Chromium (`GB_CHROME` overrides). The previews use a hold of 20 minutes
(`TOTAL` in shots.cjs). The preview's claim comes from an abandoned-cart link, which holds for 8 hours,
and at that length the ring and the line would look almost full in every shot.

## Height in the 278px cart panel (box only; today it is 137px for the box + 10px gap + 143px for the row = 290px)
Live: A 171 · B 215 · C 176 · D 210 · E 171 (11 Nov, with the timer row: 198 · 211 · 200 · 245 · 204).
Expired: 137 in every design, the same as today's box on its own; likewise on the cart page and checkout
at 390 and 1280. scrollWidth equals the viewport in all 90 shots. `check-preview.cjs` passes at 390 and
1280: no horizontal scroll, no console errors, the clock ticks, and each "watch it end" box ends with no
bonus element, no expired/ended wording, and the same height as the static expired box. The one console
line in `result.json` (a 404 at 390) belongs to the preview shop and was there before this revision;
preview.html has none.
