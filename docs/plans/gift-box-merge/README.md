# Bonus gift inside the Free gifts box: previews (Lane GB)

The owner, 10 Nov 2026: *"on the cart panel, cart and checkout, the additional free gift
product should inside the gifts box with upper side live timer, give me multiple previews.
i don't want additional separate box for it as we have currently."*

These are previews only. No shop code changed. Each design is drawn INTO the real Free gifts
box (`partials/free-gifts/box.blade.php`) on the preview shop, and the separate gift-offer row
(`partials/gift-offers/row.blade.php`) is hidden. Revised 11 Nov 2026: the bonus gift sits LAST in every design ("i wanted the additional gift product at the last, not as 1st"); the timer stays on top. The claimed gift is Relief Sun ×2.

| | Design | What it is |
|---|---|---|
| A | Timer bar on top | A slim pink bar under the title reads "Your bonus gift is held for 14:52", with a line along its bottom that shrinks as time runs out. The bonus is the LAST photo in the row, with a pink ring, a BONUS tag and ×2. One line under the row gives its name. |
| B | Timer pill on the border | A dark stopwatch pill sits on the green border opposite "Free gifts for you". The regular photos come first; the bonus is a wide cream card at the foot (photo, name, Bonus · Free / Full size / ×2). |
| C | Countdown strip + ringed photo | A charcoal strip reads "Bonus gift held for you · 14:52". The bonus photo ends the row inside a pink ring that shrinks as time runs out. One line gives its name. |
| D | Two-part box | One outline: a slim cream line under the title ("BONUS GIFT" + clock chip), the regular gifts as they are today, a dashed green divider, then the bonus at the foot (photo, name and chips). |
| E | Flip-clock header | "BONUS GIFT RESERVED FOR" sits over small dark digit tiles that slide in as they change. The bonus ends the row in a gold frame with a gold ×2. One line gives its name. |

Every design has three states. **Live** shows the timer. **Under 2 minutes** turns the timer red.
**Expired** replaces the timer with a grey line, "Your bonus gift has expired", and removes the bonus photo.
In B the pill also changes to "Ended". The motion uses only `transform`/`opacity`: a `scaleX` line, a ring
drawn from two rotating halves, the stopwatch hand, and digits sliding in. Nothing is measured.

## Files
- `contact-A.jpg` … `contact-E.jpg`: one sheet per design. They show the cart panel, cart page
  and checkout summary at 390 and 1280, plus the under-2-minute and expired states.
- `preview.html`: all five designs side by side, at phone width (the 278px cart panel) and desktop width
  (the 568px cart page). The clocks tick.
- `shots/`: every screenshot (`<design>-<panel|cart|checkout>-<390|1280>-<live|low|ended>.png`),
  plus `before-*` (today's two separate boxes) and `preview-page-*`.
- `designs.cjs` (the designs), `shots.cjs` (draws and photographs them, and builds the sheets and
  preview.html), `check-preview.cjs`, `gb-seed.php` (adds three range minis so the row looks like
  the live shop), `result.json` (box sizes, scrollWidth).

## Run
```sh
GO_LANE=lane-gb GO_SEED=../docs/plans/gift-box-merge/gb-seed.php sh tools/go-preview.sh 9590
GO_BASE=http://127.0.0.1:9590 NODE_PATH=/home/user/kbbstore/node_modules node docs/plans/gift-box-merge/shots.cjs
```
The previews use a hold of 20 minutes (`TOTAL` in shots.cjs). The preview's claim comes from an
abandoned-cart link, which holds for 8 hours, and at that length the ring and the line would look
almost full in every shot.

## Height in the 278px cart panel (box only; today it is 137px for the box + 10px gap + 143px for the row = 290px)
A 198 · B 211 · C 200 · D 245 · E 204. scrollWidth equals the viewport in every shot.
