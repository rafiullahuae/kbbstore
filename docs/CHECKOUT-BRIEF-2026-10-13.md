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
Screenshot of the look he wants: `docs/checkout-brief/02-cart-applied-coupon-chip.png`
(a red/coral rounded chip with a tag icon and the code in white capitals,
"WEMISSYOU15", and beside it the saving "-AED 33").

His words: *"on the cart page, above the order value row, if user added coupon
from the checkout page, and then come back on the cart page, then the cart page
will show the applied coupon with off value as attached. but no any coupon field
will be by default, it should only display when user already added the coupon on
the checkout page. please plan well."*

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

