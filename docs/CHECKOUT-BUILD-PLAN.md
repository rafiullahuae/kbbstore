# 2.60.495 build plan: the checkout & cart brief (13 October 2026)

STATUS: the owner said "ok proceed" (13 Oct) on the confirmed live preview
(https://claude.ai/artifact/2Tq8LQW5brrpqgrdynu5Pz; answers in
docs/CHECKOUT-BRIEF-2026-10-13.md, rounds 1-5 and the notes at its end). The
site-wide mapping below is DONE; no shop code has been written yet. The
session that did the mapping lost its shell (every command failed), so the
build continues in a new session from here.

His words when approving: "do super extra care by not disturbing any order
process, as the site is live. and no assumptions on anything, no touch to the
payment methods except stripe but we just needed the apple pay option in the
list, that's it. also make sure each and every single element is working fine
and data is transfering to the server too." And: "also map the old stuff in
case we messed something, so it can be recovered with one click from the
backend."

## The rule that governs everything here

Nothing in the order or payment process may change. Concretely:

- No field `name` the server reads is renamed or removed: `billing_*`,
  `payment_method`, `coupon_code`, `create_account`, `account_password`,
  `shipping_method`, `is_gift`, `gift_note`, `customer_note`,
  `billing_kbb_whatsapp`. Fields MOVE in the page; their name, id, autocomplete
  and validation are untouched.
- No file in the payments-frozen set changes behaviour: `checkout.js`,
  `app/Services/Payments/*`, the checkout controllers' place/confirm paths,
  `placing-overlay`, `stripe-elements`. Apple Pay (item 9) is the one payment
  change he asked for and is confined to `express-wallets` (see below).
- Every id the frozen scripts look up keeps existing exactly once:
  `#kbb_coupon_code`, `#kbb_apply_coupon`, `.coupon .crow` (checkout.js builds
  `#kbbCouponMsg` after it), `#kbbCouponMsg`, `[data-kbb-coupon-remove]`,
  `#kbb_remember`, `#create_account_field`, `#billing_email`,
  `#kbbDeliveryNote`, `#kbbDeliverySlot`, `#payment`, `[data-place]`,
  `.kbb-order-slot`, `.kbb-thumbs-slot`, `#kbbSummary .co-items`, `.js-total`,
  `.js-total-fee`, `.js-subtotal`, `.js-shipping`, `.js-coupons`, `.js-vat-*`,
  `#kbbSumRow`.

## Facts found in the code that decide the design (read before building)

1. **Remember tick.** checkout.js `rememberDetails()`: if `#kbb_remember` is
   ABSENT it calls `forgetRemembered()` and erases the shopper's saved details.
   So "remove the tick, remember silently" = keep the tick in the page,
   `checked`, inside a row with the `hidden` attribute. Do NOT delete it.
2. **Place order wording.** stripe-elements `lock()` caches the button's
   `textContent` in `data-kbb-label` and writes it back after a card attempt,
   so two spans inside the button would be flattened into "Pay nowComplete
   Order". Use plain text: server prints the label for the checked method; a
   small script OUTSIDE the frozen files re-labels on `payment_method` change
   and after `.kbb-order-slot` is swapped (MutationObserver), and keeps
   `data-kbb-label` in step when present. placing-overlay only READS the text.
3. **Coupon box.** checkout.js finds the message slot by
   `form.querySelector('.coupon .crow')`, so the moved field must keep a
   `.coupon` wrapper with `.crow` inside it (inside the summary panel now).
   The applied state is `data.couponHtml` = partials/checkout/coupon-applied,
   rendered by CheckoutController::couponUpdate() AND on page load via
   coupon-line: change that ONE partial to draw the ticket (code, saving, ×),
   keeping `[data-kbb-coupon-remove]` on the ×.
4. **The bottom summary needs the coupon field too** (item 7), but ids must
   stay unique: MOVE the one `.coupon` node into the bottom box when its arrow
   opens (and back when the top summary opens). Items in the bottom box: a
   clone of `#kbbSummary .co-items`, re-cloned by a MutationObserver after a
   swap (checkout.js only refreshes `#kbbSummary .co-items`).
5. **order-block is rendered twice** (aside + phone box) and swapped wholesale
   with `data.orderHtml`; differences between the two must be CSS by context
   (`.kbb-mobile-order …`), never different markup.
6. **Summary row total** (summary-row partial) is kept current by its own
   MutationObserver copying `.js-total`/`.js-total-fee` from the order slot;
   extend that observer for the small struck "was" total above it.
7. **The server compares the payment list on screen STRICTLY**
   (CheckoutController ~2674: `$shown !== $offeredIds`, from
   `#payment input[name="payment_method"]`). So the Apple Pay radio must NOT
   be `name="payment_method"`: give it NO name (never posted); a small script
   unchecks the payment_method radios when Apple Pay is picked and unchecks
   Apple Pay when any of them is picked; re-apply the pick after a `#payment`
   redraw. With nothing checked, placeable() keeps the floating bar away and
   stripe-elements stays out of it.
8. **Apple Pay itself** stays Stripe's Express Checkout Element exactly as
   express-wallets mounts it today (same PaymentIntent, payload() still posts
   `payment_method=stripe`), with: `googlePay: 'never'` in this mode; the mount
   box moved to just under the Pay now button (inside the visible order block,
   phone or desktop by matchMedia 900px); `ece.unmount()`/`mount()` again after
   an order-slot swap or a breakpoint change, plus `refreshAmount()`; the
   Apple Pay `<li>` (rendered by payment-methods partial, `hidden`) revealed
   only when Stripe's `ready` lists `applePay`; the mount box hidden with
   `visibility:hidden;height:0` (NEVER display:none: payments lock) until the
   Apple Pay row is chosen; Pay now / floating bar hidden while it is. The
   express row above the form (heading, OR line) is not drawn in this mode.
   Run WalletPaymentsTest, WalletMoneyPathTest, AppleDomainFileTest; the owner
   must place one real Apple Pay order on his iPhone before it is called done.
9. **Delivery note.** `#kbbDeliveryNote` is re-drawn from `data.deliveryNote`
   (checkout.js sets `hidden` when ''); make CheckoutPage::deliveryNoteHtml()
   return '' when the basket already gets free delivery (same rule as the
   rates), and make sure the coupon and quantity answers carry deliveryNote.
10. **Emirate list mode is what the live shop runs** (`state_list` on →
    partials/checkout/address-fields-list). Its row2 is Area/Street | Emirate,
    Country alone. Country option text: show "U.A.E" for `AE` only.
11. **On a phone the summary sat 18px low** (`.summary` relative with
    `top:var(--cop-d-sticktop,18px)`), overlapping Delivery Information once
    the coupon box is gone: top 0 on the phone layout. Existing spacing
    controls to reuse: `m_block_gap` / `d_block_gap` (space between blocks)
    and `m_sec_same` / `m_sec_gap` (end of each step).
12. **Bag pictures** (thumbs partial) are also rendered by the controller as
    `thumbsHtml`: gate inside the partial, so both paths agree.
13. **Cart button wording is a stored setting** (`cartpage_co_label`,
    default "Proceed to Checkout"): the migration snapshots the current value
    before writing "Secure checkout".
14. **Capabilities**: new endpoint gets its own capability, e.g.
    `checkoutlayout.restore` => owner, manager; route rule in
    App\Support\AdminCapabilities beside `admin-api/checkout-page`.

## Where each touched thing is used (grep of app/, resources/, routes/, tests/)

| Thing | Used by | What happens to it |
|---|---|---|
| `#kbb_remember` | checkout.js rememberDetails, remember-row, tests | Kept, checked, inside a `hidden` row: remembering continues silently |
| `#create_account_field` | checkout.blade, sign-in.blade (removed on sign-in) | Moved under the Emirate/Country row; id kept |
| `#billing_email_field` | x-checkout.field, inline-validation, tests | Moved above Full name; same component, same id |
| `.co-signin`, `.co-signin-q`, `[data-kbb-signin]` | checkout.blade, sign-in.blade, CSS | Moved to the Delivery Information heading, stacked |
| `step_contact` / `step_shipping` | checkout.blade, strings | Contact heading not drawn in one-step mode; new string for "Delivery Information" (+ Arabic draft) |
| `.co-head .secure`, `secure_badge` | checkout.blade, header-slim (other page) | Checkout header only: replaced by a cart link with `HeaderIcons::cart()`; header-slim untouched |
| `.co-titlebar` | checkout.blade, CSS, tests | Not drawn while the title switch is off (and no back links are on) |
| `.coupon` box, `.crow` | checkout.blade, checkout.js | Same `.coupon` > `.crow` markup, inside the order summary panel |
| `coupon-applied` partial | coupon-line, CheckoutController::couponUpdate | Draws the ticket in ticket/tag/line styles; `[data-kbb-coupon-remove]` kept |
| `.kbb-card-secure` / `card_secure_line` | stripe-card partial | Not drawn while the Payment heading carries the secure line |
| `ssl_secure` | order-block | Replaced by the trust wording setting ("Safe checkout") |
| `.kbb-thumbs-slot` content | thumbs partial, CheckoutController thumbsHtml | Partial draws nothing while "Your bag pictures" is off |
| order-block rows | page + every orderHtml answer | Same partial for both; layout by CSS context |
| Place order wording | order-block, mpbar | See fact 2 |
| `#billing_country` option text | address-fields-list | `AE` shows "U.A.E"; value `AE` and the stored name unchanged |
| `.row2` | address-fields(-list) | One row / two rows / as before, a select |
| `#kbbDeliveryNote` | CheckoutPage::deliveryNoteHtml, checkout.js | See fact 9 |
| express wallets | express-wallets partial | See fact 8 |
| cart `.cpg-totband`, `.srow.disc`, `.appliedcoupon` | cart-inner | Order Value in the green box; ticket replaces the two coupon rows; cart.js `[data-kcpremovecoupon]` kept on the × |
| cart `co_label` | CartPage, cart-inner | "Secure checkout" (snapshotted for restore) |
| cart `.backlink` | cart.blade | Switch (off) + target (default `/`, scheme-checked: a path starting with one `/`) |

## Switches (each shipped at his answer; OLD value = page before 2.60.495)

Checkout (CheckoutPage, new tab "13 October layout"): header cart icon (on),
title row (off), green summary (on), coupon field in the summary (on),
coupon style ticket/tag/line/as-before (ticket), one step "Delivery
Information" (on), email hint text, Emirate/Country one row/two rows/as
before (one), U.A.E short name (on), remember tick shown (off), free-delivery
line hidden when free (on), secure line under Payment text, bag pictures
(off), bottom box "Total only" (on), Pay now / Complete Order wording (on, both
editable + Arabic), trust wording ("Safe checkout"), Apple Pay row (on).
Cart (CartPage): Order Value in the green box (on), "Shipping calculated at
checkout" text, button: lock icon, CAPITALS, 14px, black (on), coupon ticket ×
(on), Order Value after the coupon (on), Continue shopping (off) + its target
(`/`).

## One-click restore

`App\Services\CheckoutLayout`: every new switch with its OLD and NEW value,
plus the existing stored values this package changes. The migration
snapshots the current values before it writes anything. Admin → Appearance →
Checkout page → "13 October layout": **Put back as before 2.60.495** / **Use
the 13 October layout**. Own capability.

## Proof owed before the package

Baseline first (payment set on the base commit, in its own worktree with its
own vendor copy, never a vendor symlink: composer's autoloader resolves the
symlink and loads app/ from the main checkout). Then: the payment set
(`Tamara* Tabby* Stripe* Wallet* Checkout* Order* Payment* AppleDomain*`),
the full suite, HeaderLayoutLockTest (checkout header changes need the sweep),
StorefrontEnglishUnchangedTest advanced deliberately for cart and checkout,
StorefrontQueryBudgetTest, browser orders on the preview shop for every
method reachable from the sandbox (COD always; card/Tabby/Tamara need their
test keys and domains), 390/1280 pictures, and server ms / queries for
product, category and brand before and after.
