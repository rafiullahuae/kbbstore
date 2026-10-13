# Checkout & cart brief — the owner, 13 October 2026

Collected across several messages. **Nothing is built until he says the list is
complete.** Then: one plan, previews where the look changes, every change a
switch in the admin, payments untouched unless an item says so, full payment
test set + full suite + real test orders in a browser, one package.

Status key: ☐ noted · ◐ planned · ☑ built

---

## 1. Cart page — totals card and the bottom button  ☐
Screenshot: `docs/checkout-brief/01-cart-page-order-total.png` (phone, /cart/).

His words: *"The order value should come in the greenish box, and the order
total row will be removed. The Proceed to Checkout will become > SECURE
CHECKOUT and the button background will be dark black."*

- 1a. **"Order Value (7 items) ~~AED 935~~ AED 691"** moves INTO the light green
  box (where "Order Total AED 691" is now).
- 1b. The **"Order Total"** row is removed (the green box now shows Order Value
  instead — one row, not two).
- 1c. The bottom bar button **"Proceed to Checkout"** reads **"SECURE CHECKOUT"**.
  (Open point to confirm: he wrote "> SECURE CHECKOUT" — the ">" may mean "change
  to", or a lock/arrow icon. Ask with the plan.)
- 1d. That button's background becomes **dark black** (today: green).
- 1e. Under the Order Value row (inside the green box), one line of **grey text:
  "Shipping calculated at checkout"**. His words: *"under order value row on
  the cart page, add one line grey text. 'Shipping calculated at checkout'"*.
  (Wording editable in the admin; Arabic draft to add.)
- Where: Appearance → Cart page (controls to add). Unchanged: payment marks row,
  "Secure checkout" line, the 7 items / AED 691 on the left of the bottom bar.

---

## 2. Cart page — the applied coupon as a red chip, above Order Value  ☐
Screenshot of the CONTENT he wants (not the look): `docs/checkout-brief/02-cart-applied-coupon-chip.png`
(the code "WEMISSYOU15" and the saving "-AED 33").

His words: *"on the cart page, above the order value row, if user added coupon
from the checkout page, and then come back on the cart page, then the cart page
will show the applied coupon with off value as attached. but no any coupon field
will be by default, it should only display when user already added the coupon on
the checkout page. please plan well."*

- **STYLE: NOT the red box.** His follow-up: *"styling i need totally different,
  i don't such red box etc."* The screenshot shows WHAT (code + saving), not the
  look. Design to be previewed as options in the shop's own style with the plan.
- 2a. Shown **only when a coupon is applied** to the basket (from the checkout,
  the bag panel, or a coupon link). No coupon applied = nothing at all.
- 2b. Sits **above the Order Value row** (item 1: inside/at the top of the green
  box).
- 2c. **No coupon entry field** on the cart page by default — already true
  today: Store → Modules → "Cart coupon field" is OFF; it stays OFF.
- 2d. Replaces today's two rows below Order Value ("WEMISSYOU15 – AED 33" and
  "✓ WEMISSYOU15 · Remove") so the coupon shows once, not three times.

Plan (from the code, 13 Oct): the coupon lives on the basket (`carts.coupon`),
so the checkout's coupon is already on the cart page's totals
(`$totals['coupon_code']`, `$totals['discount']`) with no new storage or query.
The change is the cart page's markup (`store/cart-inner.blade.php`) and its
stylesheet only — the checkout and payments are not touched. The saving shown is
the same `discount` the checkout charges, so the two pages can never disagree.

Open points to confirm with the plan:
- **Remove:** today shoppers can take the coupon off on the cart page. Keep a
  small × on the chip (recommended — otherwise a coupon applied by mistake can
  only be removed at checkout), or no remove at all as in his picture?
- **Order Value with a coupon:** show the amount AFTER the coupon (what they will
  pay, e.g. ~~935~~ 658) or before it (691)? Recommended: after, so the green box
  and the bottom bar show the same figure.

## 3. Checkout page — header, title, order summary and the coupon inside it  ☐
Screenshots: `docs/checkout-brief/03-6.png` (our checkout, phone, with his
crosses), `03-7.png` and `03-8.png` (a reference site's order summary before and
after a coupon — CONTENT reference only; he wants a different style).

His words: *"on the checkout page, remove the right side secure checkout green
text beside the logo, and place there cart icon. which will go to the cart page.
AND remove the checkout page title and description completely. AND the Order
summary row background style will become same greenish box as we have on the
cart page order value box. AND bring the coupon field (only field, not full box
and remove the coupon box) and display inside order summary block as attached
from the reference site, reduce the height of coupon field little bit. and after
coupon apply the order summary block will become like this as attached with
coupon value row and coupon name added. ofcourse the styling of coupon row etc,
i want different from them."*

- 3a. Checkout's own header: remove the green **"🔒 Secure checkout"** text on
  the right of the logo; put a **cart icon** there that opens the **cart page**.
- 3b. Remove the **"Checkout" title and "Almost glowing — just a few details."**
  completely (the round back arrow went with that title row — confirm: drop it
  too, since the new cart icon goes back to the cart).
- 3c. The **"Order summary · AED 691 ⌄"** row gets the **same light-green box**
  as the cart page's Order Value box (item 1).
- 3d. **Remove the coupon box** (the dashed green panel with "Enter promo code /
  Apply"). Keep ONLY the field + Apply, and put them **inside the Order summary
  block** (when opened, under the items — as in the reference), **a little less
  tall** than today.
- 3e. **After a coupon is applied**, the order summary shows:
  - the applied code as a small tag with an × to remove it, under the field;
  - a discount row: "Order discount" + the code + "– AED 32.70";
  - the total with the old total struck through beside it (as the reference
    shows "~~AED 227.27~~ AED 194.57" on the summary row);
  - a "Total savings AED 32.70" line under the total.
  **His own style, NOT the reference's** — options to preview.

Plan notes (from the code, 13 Oct):
- The coupon field and Apply keep their ids (`kbb_coupon_code`,
  `kbb_apply_coupon`); `resources/js/kbb/checkout.js` handles them by id and
  by delegation, so MOVING them needs no change to checkout.js, which is in the
  payments-frozen set. To verify in the browser: apply, wrong code, remove,
  Enter key, and that the totals and Place order still agree.
- The checkout's own header (`.co-head`, with the logo) is covered by the
  header lock (CLAUDE.md "The header and logo are frozen"; `.co-head .in>.logo.lgx`
  in kbb.css). 3a touches it: run that rule's sweeps and re-pin
  HeaderLayoutLockTest deliberately, with his ask quoted.
- Existing switches to reuse where they fit: Appearance → Checkout page →
  Coupon, express & sign in ("Have a discount code?" OFF), the coupon line
  (`cline_*`), the summary row (`sum_row`), the back arrow (`head_back`).
- Payments untouched: only markup and CSS move; every total still comes from
  the server's own totals.

