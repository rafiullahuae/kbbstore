# Bliss Points + Store credit — plan (on hold)

Status: **preview approved for planning only, 31 October 2026. Nothing built.**
The owner: "for now only previews, no development until i approve" — then
"ok save this in plan. we will come back later to this."

Playable preview: https://claude.ai/artifact/HCu3hhaiBFfVsn92ZPiMqb
(source kept beside this file: `points-and-credit-preview.html`; open it in a
browser — the admin settings drive every shopper screen).

## What he asked for

- Points on orders; he decides points per AED and the value of a point
  ("1 AED = 1 point").
- Shoppers can transfer points to another account.
- Points used on later orders.
- Store credit given by hand to any customer, with internal notes, used at
  checkout with the total shown and a "use your credit" switch.
- Super light, easy, merged with the site.

## Design in the preview

### Points
- Settings: Off / Testing / Live (PIN-locked in the owner app like Free gifts);
  points per AED 1 (default 1); value of 1 point (default AED 0.05 = 5% back);
  usable when **delivered** (default) or when paid; expiry in months (0 = never,
  reminder email 14 days before); minimum to use (100); up to X% of an order (50).
- Earned on the products' total after coupons — never on delivery, fees, gifts,
  points used or store credit used.
- Pending ("on the way") until the trigger; a cancel / refund removes what the
  order earned and returns what it used, automatically.
- Product page: "Earn N Bliss Points, worth AED x" under the price — computed
  from the price alone, no query. Bag panel: points to earn + balance.
- Checkout: "Use my Bliss Points" switch, slider, quick amounts; whole dirhams.
- Account → Points & credit: balance + value, on the way, expiring, history,
  how it works.
- Bonus: on joining, birthday, approved review (all optional numbers).

### Transfers
- To another Bliss account by email or UAE mobile; recipient shown by initials.
- Minimum send, daily cap per account, account must be N days old to send,
  6-digit email code to confirm (default on), unusual sends held for review in
  admin (release / return). Only available (not pending) points. Both emailed.

### Store credit
- Admin adds / removes AED credit (whole dirhams) from a customer's page or the
  Store credit tab: **internal note (team only, with staff name)**, optional
  message to the customer, optional expiry.
- Checkout: "Use my store credit" switch showing total credit, used on this
  order and left; pays any part of the rest **including delivery**, up to the
  whole order (no payment step when fully covered).
- Account shows the credit and the message.

### Admin / owner app
- Growth & Marketing → Points & credit: Settings, Overview (earned, used,
  outstanding = liability), Activity (one ledger line per movement, CSV),
  Transfers, Store credit.
- Customer page: points + credit cards with add / remove.
- Owner app: "Points & credit" row on My store; same controls, PIN for the mode.

## Build notes (when approved)
- One ledger table (`point_entries`: customer, delta, kind earn/redeem/
  transfer_in/out/adjust/expire/reverse, order, status pending/available,
  expires_at, note, author) and one for credit (`credit_entries`, same shape,
  fils, internal_note). Balances cached on `customers`, rebuilt from the ledger.
- Redemption as order lines the payment gateways already understand (a
  discount), reversed by the same path that returns stock on a failed payment
  (BasketRelease) — payments are frozen: needs the payment suite and a real
  order before release (CLAUDE.md "Payments are frozen").
- Shop pages: no new query for a guest; one indexed read for a signed-in
  customer on checkout / account only. StorefrontQueryBudgetTest unchanged.
- English and Arabic for every string; emails in both.
