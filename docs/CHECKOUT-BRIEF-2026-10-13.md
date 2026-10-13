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

## 4. Checkout page — Contact section merged into "Delivery Information"  ☐
His screenshot (phone, our checkout; not saved to disk — described here): the
"1 Contact" heading crossed out; an arrow from "Already a member? 👤 Sign in"
to the "2 Shipping Details" heading; an arrow from "Create an account for faster
checkout next time" down to where "Remember my shipping details on this device"
sits (under Country), which is to go.

His words: *"on the checkout page, the contact section i want to remove. the
email will go above the name field, the sign in row will be in shipping address
heading row, and also rename the Shipping Address > Delivery Information, AND
remove the Remeber my shipping address, it should work silently at background
auto by saving the fields data on the user browser. and bring the create account
concent line here."*

- 4a. Remove the **"1 Contact"** section and its heading.
- 4b. **Email address** moves into the delivery section, **above Full name**
  (first field).
- 4c. **"Already a member? 👤 Sign in"** moves to the right of the delivery
  section's heading row (guests only, as now).
- 4d. Rename the heading **"Shipping Details" → "Delivery Information"** (and
  the Arabic draft).
- 4e. Remove the tick **"Remember my shipping details on this device"**.
  Remembering keeps working **silently**: the details are saved in the
  shopper's own browser and filled in next time (as `remember_on` already does
  today — only the visible tick goes).
- 4f. **"Create an account for faster checkout next time"** moves to where that
  tick was: under Country, at the end of the delivery fields.
- Sections renumber: Delivery Information becomes 1, the next ones follow.

Plan notes (from the code, 13 Oct):
- `store/checkout.blade.php`: Contact `<h2>` (line ~139), `create_account_field`
  (~168), "step_shipping" heading (~200), `partials/checkout/remember-row` (~248).
  Field `name`s and ids stay the same (`billing_email`, `create_account`, ...),
  so the server's validation, the order, the remembered-details script and the
  sign-in window keep working unchanged; only their place on the page moves.
- Silent remembering: `remember_on` (Appearance → Checkout page → Fields &
  attention) already saves name, phone, email, address and delivery choice in
  the browser only — never cards, coupons, passwords or the payment method. With
  the tick gone there is no way for a shopper to say no on a SHARED phone or
  computer; "Not you? Clear details" was switched off at his request (2.60.490).
  Raise with the plan: keep a quiet way to clear (e.g. in the sign-in row or the
  account page), or accept as is.
- The cart journey (2.60.494) codes "Entered their email" and "Completed the
  address" read the same field names, so they keep working.

## 5. Checkout page — "Free express delivery over AED 199" only when NOT free  ☐
Screenshot: `docs/checkout-brief/05-delivery-free-line.png` (the grey line on
the right of the "3 Delivery" heading, while the option below already reads
"Free Express Delivery · Free").

His words: *"on the checkout, if the user is getting free delivery, then this
line should not show, it should only display if user order is below 199, i mean
if user is not getting free delivery."*

- 5a. Order qualifies for free delivery → the line is **hidden**.
- 5b. Order below the free-delivery amount (not free) → the line **shows**, as
  today ("Free express delivery over AED 199").
- 5c. It must follow changes on the page: a coupon, a quantity change or a
  country change that crosses AED 199 shows/hides it at once.

Plan notes (from the code, 13 Oct):
- The line is `CheckoutPage::deliveryNoteHtml()` (Appearance → Checkout page →
  delivery tab: "Free-delivery line beside “Delivery”", `dl_note_on`, wording
  `dl_note` / `dl_note_ar`), printed in `#kbbDeliveryNote`.
- The page already re-draws it from the server's answer (`data.deliveryNote`;
  `note.hidden = data.deliveryNote === ''` in checkout.js). So the fix is
  server-side only: deliveryNoteHtml() also returns '' when the basket already
  qualifies for free delivery, using the SAME rule the delivery options use
  (so the line and the "Free" price can never disagree), and every answer that
  can move the total across AED 199 (coupon, quantity, country) carries
  deliveryNote. No change to checkout.js (payments-frozen set).
- Tests: below 199 shows; at/above 199 hides; a coupon that drops the order
  below 199 shows it again; Arabic.

## 6. Checkout page — the "secure" line moves from the card box to the Payment heading  ☐
Screenshot: `docs/checkout-brief/06-payment-secure-line.png` ("4 Payment"
heading underlined; "🔒 100% secure & encrypted — Use any card" inside the
Credit / Debit Card box crossed out).

His words: *"on the checkout, remove the line from the stripe block, and add
line in the Payment heading row, under Payment heading 'All transactions are
secure & encrypted', you can increase the height of the Payment heading row as
per need."*

- 6a. Remove **"🔒 100% secure & encrypted — Use any card"** from inside the
  Credit / Debit Card (Stripe) box.
- 6b. In the **Payment heading row**, under the word "Payment", a second line:
  **"All transactions are secure & encrypted"** (smaller, grey; Arabic draft).
- 6c. The heading row may grow taller to fit the two lines.
- 6d. A small **green lock icon** beside that line (before the words). His
  words: *"and also add green lock icon beside the secure encrypted line"*.
  Inline SVG with width/height attributes (CLAUDE.md), no new request.

Plan notes (from the code, 13 Oct):
- The card-box line is `partials/checkout/stripe-card.blade.php` (~line 164,
  string `store.checkout.card_secure_line`). Only that text line is removed; the
  card fields, Stripe's mount points and their ids are not touched. Payment
  test set (`Stripe* Wallet* Checkout* Payment*`) runs, plus a real card form
  in the browser (mounts, accepts input, test card goes through on the preview).
- The heading line is new wording (`InterfaceStrings` + Arabic draft), editable
  in Appearance → Checkout page; it shows whatever payment method is chosen.
- Fits item 4's renumbering (Payment's number changes when Contact goes).

## 7. Checkout page — bottom order block: no pictures, Total only, savings, and the button wording  ☐
Screenshot: `docs/checkout-brief/07-bottom-total-place-order.png` (the block
under the payment methods: "Your bag · 7 items" pictures crossed out; Subtotal /
glow – AED 35 / Delivery rows; "Total AED 656" underlined and ticked; Place
order).

His words: *"on the checkout page turned off the pictures row completely, remove
the sub total rows. only keep the Total row, under the total value, add coupon
icon, Total saving and saving value, in grey, same as attached. and if no coupon
applied, then only total value with down arrow. The down arrow will open the
order summary here again like the one we have on the checkout page top. AND if
Cash on Delivery selected, the place order button text will become 'Complete
Order' and upon any other payment methods, the place order will be permanently
replaced to 'Pay now'"*

- 7a. **"Your bag · 7 items" picture row: off** (completely).
- 7b. **Remove the rows** above Total: Subtotal, the coupon row ("glow – AED
  35"), Delivery. (VAT line "5% VAT inclusive": not mentioned — keep unless he
  says; confirm with the plan.)
- 7c. Keep only **Total — AED 656**.
- 7d. **With a coupon:** under the total value, in grey: a **coupon icon +
  "Total savings" + the saving** (as in the reference from item 3e, "TOTAL
  SAVINGS AED 32.70" — his style).
- 7e. **Without a coupon:** the total value **with a down arrow**; tapping it
  **opens the order summary right there** (items, coupon field, rows — the same
  content as the top "Order summary" of item 3), and up-arrow closes it.
  (Confirm: with a coupon, does the arrow also show beside the total? Assumed
  yes — the savings line sits under it.)
- 7f. **Button wording by payment method:** Cash on delivery → **"Complete
  Order"**; every other method (card, Tabby, Tamara, Apple/Google Pay) → **"Pay
  now"**. "Place order" no longer appears. Applies to both buttons (this one and
  the floating bar on phones). Arabic drafts for both.

Plan notes (from the code, 13 Oct):
- The block is `partials/checkout/order-block.blade.php` (rows ~line 66 on;
  Place order button ~209, `data-place`) and the picture row
  `partials/checkout/thumbs.blade.php` (in checkout.blade.php ~373). No switch
  exists for the pictures yet: add one (ON = today), shipped OFF as he asked.
- Totals stay the server's own; only which rows are drawn changes. The full
  breakdown still exists in the opened summary (7e) and on the order emails.
- Button wording: checkout.js and the placing overlay find the button by
  `[data-place]` and never set its text (checked: placing-overlay only READS
  it), so the label can change safely. The label is printed by the server for
  the method selected on load and swapped on change by a few lines OUTSIDE
  checkout.js (payments-frozen), also after the payment list is re-drawn on a
  country change. Wording editable in Appearance → Checkout page.
- Payment test set + real browser orders with each method (COD shows
  "Complete Order"; card/Tabby/Tamara "Pay now"; the order still places).

## 8. Checkout page — replace "SSL secure" in the trust line under the button  ☐
Screenshot: `docs/checkout-brief/08-ssl-secure.png` ("🔒 SSL secure · ✓ 100%
authentic" under Place order, above "1–3 days fast delivery all over UAE").

His words: *"on the checkout page, i need to change the SSL secure to something
else, suggest me few options for this text."*

Options offered (13 Oct), shown live in the preview to pick from:
- A "Secure payment"
- B "Safe checkout"
- C "Protected payment"
- D "Encrypted & secure"
- E "Your data is safe"
(keeps the lock icon; "✓ 100% authentic" beside it unchanged)

---

**THE LIST IS COMPLETE (his words: "the changes list ended here, proceed to
live preview as per the instructions and changes above").** Next: one live
preview of every item on the real cart and checkout, with the open points as
choices, and friendly-UX suggestions marked as suggestions; he confirms each
item before anything is built.

## Build rule (his words, 13 Oct): nothing in the order or payment process may be disturbed
*"also we must need to make sure to do not disturb the order process or payment
process. and all functionality need to be adjusted and fixes wherever these
fields called on the whole site, don't assume on anything."*

Before building, for EVERY field, string, id, class or setting an item moves,
renames or removes: grep the whole repository (app/, resources/, routes/,
tests/, the owner app, emails, invoices, PDF/print views, the admin order
screens, the account area and address book, the remembered-details script, the
cart-journey codes, checkout.js and the placing overlay) and list each use in
the plan with what happens to it. Nothing is assumed unused. Field `name`s that
the server reads (billing_*, payment_method, coupon_code, create_account,
shipping_method) are never renamed. Proof: the payment test set, the full
suite, and real browser orders with every method on the preview shop.


---

## His answers to the live preview (13 Oct) — round 2 preview built from these

Preview: https://claude.ai/artifact/2Tq8LQW5brrpqgrdynu5Pz (sources in
`docs/checkout-preview/`, rebuild with `node tools/cpv-build.cjs`).

1. Button: lock icon + text. NEW: admin choice of text CAPITALS / Normal, and a
   text-size control (preview slider 12–18px, default 15).
2. Cart coupon: style B (dashed ticket, white inside), × to remove YES, Order
   Value AFTER the coupon. NEW: saving sits next to the code on the left (not
   pushed right), smaller text, tag only as wide as its words, left-aligned.
3. Checkout summary: coupon style B; back arrow removed; item-count badge NO.
   NEW: the whole summary is ONE green box, full width, no white around it; the
   old price small ABOVE the total (not beside); bigger "Order summary" text and
   total. Apple Pay / Google Pay must stay right after the summary box as
   today — express-wallets.blade.php is in the payments-frozen set: do not touch
   it, only what sits around it.
4. Sign-in stays in the heading row, BUT "Delivery Information" must be on ONE
   line: "Already a member?" stacked above "Sign in" at the right, heading row
   taller. "Not you? Clear" NO. Grey line under Email "We send your order
   confirmation here." YES.
5. Rule confirmed: the line shows whenever the shopper is NOT getting free
   delivery (bag under AED 199); hidden only when delivery is free. "We don't
   offer free delivery with coupon" — the line follows the delivery options'
   own rule, so it can never disagree with the price shown. "Delivery method"
   rename NO.
6. Secure line under Payment: smaller text.
7. VAT line kept, placed directly under the word "Total" (left column). Amount
   on the button NO.
8. "SSL secure" → B "Safe checkout".

## Round 3 answers (13 Oct) — final preview

Confirmed: 2, 5, 6, 8 (Safe checkout). Item 1: lock, CAPITALS, 14px.
- 3: green summary background runs the full phone width (edge to edge); its
  content keeps the normal width when opened. Space under the coupon field;
  field and Apply the same height (46px).
- 4: Emirate and Country in ONE row (Area / Street gets its own row). Country
  DISPLAYS "U.A.E" (option text only); value `AE` and the full name "United
  Arab Emirates" stay everywhere else (orders, emails, invoices, admin). Grep
  every place the country name is printed before building.
- 7: "Order summary" heading at the top of the bottom box with the arrow on its
  right (his reference: Shopify-style). Open = items, coupon field, Subtotal /
  discount / Delivery rows, Total. Total + VAT + savings always visible.
- 9 (new): Apple Pay ONLY (no Google Pay), as payment method no. 2 under
  Credit / Debit Card, with the outlined Apple Pay mark (his reference). The
  Express checkout box after the summary goes. Choosing it swaps Pay now for
  the black Apple Pay button. PAYMENTS-FROZEN: express-wallets / Stripe ECE
  (`WalletPaymentsTest`, `WalletMoneyPathTest`, `AppleDomainFileTest`) —
  same PaymentIntent, googlePay 'never', row hidden where Apple Pay is not
  available. Needs a real Apple Pay test on his iPhone before release.

## Round 4 answers (13 Oct)

Confirmed: 2, 5, 6, 8. Not yet checked: 1, 3, 9.
- 3: the checkout's bag icon is the WEBSITE's own cart icon (`HeaderIcons::cart()`).
  Opened, the top "Order summary" row alone is a shade darker green
  (#DCEFE3) than the panel, full width on a phone.
- 4: admin switch, Emirate + Country in ONE row or TWO rows (shipped: one).
  Area / Street always its own row. One 10px gap between every field of the
  section (today Area / Street touched the row under it).
- 7: closed = NO heading; the arrow sits just after the total amount. Open =
  "Order summary" heading appears at the top (weight 500, not bold) and the
  arrow moves beside it; the arrow beside the total goes.
- 10 (new): cart page "Continue shopping" OFF; admin switch + editable target
  (default `/`, the homepage; today it points to /shop/). Appearance → Cart
  page. Scheme-check the setting before it becomes an href (CLAUDE.md rule 5).

## Round 5 (13 Oct): spacing

- With the coupon box and the title gone, the summary box sat OVER Delivery
  Information on a phone (-4px): `.kbb-checkout .summary` is `position:sticky;
  top:18px`, which on a phone layout became an 18px push down. Build: top 0 on
  the phone layout, and one even gap between header / summary / Delivery
  Information (default 16px).
- Each step of the form ends with the same space before the next heading
  (default 18px; the account line used to add 10px more).
- Both spaces are admin sliders (his words: "give option to adjust the
  spacing between sections"): Appearance → Checkout page → Layout, "Space
  between boxes" 8–32px and "Space at the end of each step" 10–32px. A select
  or number stores only its own range (CLAUDE.md rule 5).
- 7 (round 5): "Total | AED 648 ⌄" on one line; "5% VAT inclusive | 🏷 Total
  savings AED 35" on ONE line right under it, aligned (savings sit directly
  under the amount, 2px gap, no extra space above them). The SAME two lines in
  the top order summary (its separate "5% VAT inclusive" row goes).
