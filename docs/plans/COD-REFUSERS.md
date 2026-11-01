# Hide Cash on Delivery from shoppers who refused a dispatched order

Owner, 1 November 2026: hide COD from anyone whose order was **shipped and then
cancelled** because they would not accept it at the door. Skip anyone who has at
least one **completed** order. Match them by email, phone and IP. Status: **plan,
waiting for the owner's go.** Payments are frozen (CLAUDE.md), so the build runs
the whole payment suite and is checked with a real order afterwards.

## Who counts as a refuser

An order whose history holds the move **shipped → cancelled**:
- orders changed in this shop: `OrderStatus::record()` writes
  "Status changed from shipped to cancelled." on the order notes;
- orders imported from WooCommerce: their notes say "Order status changed from
  Shipped to Cancelled.".

A customer stops counting the moment any of their orders is **completed**
(matched by email or phone). An order cancelled before it was shipped never
counts: a change of mind is not a refusal at the door.

## How it is stored (fast at checkout)

One small table, `cod_blocks`: email (lower-cased), phone (digits only, UAE
numbers normalised to 9715…), IP, the order number that caused it, and when.
- **Filled when it happens:** the shipped → cancelled transition in
  `OrderStatus` adds the row. One insert, only on that transition.
- **Filled once for the past:** the package's migration reads the existing
  notes once and adds a row for every past refuser without a completed order.
- **Cleared when it should be:** an order of theirs becoming completed removes
  their rows; the owner can also remove a row by hand.

Checkout reads it with **one indexed query**. Nothing scans orders or notes
while a shopper is waiting.

## Where COD disappears

1. **Checkout page load:** a signed-in customer's email and phone and the
   visitor's IP are checked before the payment list is drawn.
2. **While a guest types:** the checkout already sends the email to the server
   when it is entered (basket reminders). That same request answers whether COD
   is allowed, and the page hides the COD option. The phone number is checked on
   the same request when it is entered. No new request, nothing per keystroke.
3. **On Place order (the lock):** the server checks email, phone and IP again
   and refuses COD with a plain message. A shopper who forces the COD option
   back in the browser still cannot place a COD order.

Card, Apple Pay / Google Pay, Tabby and Tamara stay available to them. If COD
was the only method left, checkout shows the remaining methods as normal.

## IP — the one risk

Etisalat and du mobile data put many customers behind the same IP, and office,
mall and hotel Wi-Fi is shared. Blocking an IP forever would, over months, hide
COD from real customers. Recommendation: **email + phone always, IP for 30 days
after the refusal** (a setting). The owner chooses.

## Admin

**Store → Payments → Cash on delivery → Refused deliveries**:
- on/off (on, as asked);
- IP days (30), or IP off;
- the list: customer, email, phone, order number, date, with **Allow COD
  again** per row and a search.

Order screen: a small red tag "COD hidden: refused order #…" on the customer's
new orders, so staff see it during call confirmation.

## Proof the build owes

- Tests: shipped → cancelled adds the block; cancelled before shipping doesn't;
  a completed order lifts it; WooCommerce note wording is read; email in any
  letter case and phone in any format match; the IP expires after its days;
  the Place-order check refuses a forced COD; nobody else loses COD.
- Query budget: checkout page +1 query at most; flat for 3 or 4,000 blocks.
- The whole payment suite (`Tamara*`, `Tabby*`, `Stripe*`, `Wallet*`,
  `Checkout*`, `Order*`, `Payment*`) green, then one real COD order by a clean
  customer and one blocked attempt, with pictures at 390 and 1280.
