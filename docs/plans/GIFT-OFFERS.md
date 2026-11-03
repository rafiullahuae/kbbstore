# Gift offers: exit popup + extra gift in emails (one Marketing module)

The owner, 1–3 November 2026. **Status: previews agreed in part; nothing built.**
Saved on his request ("save these stuff, i will need this later"). The previews
are in `docs/plans/gift-offers/` — open the `.html` files in a browser.

## What it is

One new page under **Marketing** that runs an extra full-size gift, at AED 0,
in three places, with every control and a live preview in the admin:

1. **Exit popup** on the shop, when a shopper with a bag tries to leave or
   switch tab (mouse leaving the window, `visibilitychange`).
2. **Abandoned-cart email** (`emails/cart-recovery`).
3. **Pending-order reminder emails** (`emails/order-reminder`, both the
   30-minute and the 24-hour one).

Claiming adds the gift at AED 0 with a timer; a thin row shows under the gift
box in the cart panel, cart page and checkout summary.

## Decided

| Where | Choice |
|---|---|
| Popup design | **A · Ribbon card** (the owner wrote "1 · Popup design"; read as A — confirm) |
| Popup words | **3**: big "WAIT…", sub "Psst… a secret gift, just for you" |
| Popup product | name shown **right under the product photo** |
| Popup timer | **15 minutes**, shown live on the popup; the gift is held 15 minutes |
| Claim | big animated green tick, then the cart page opens |
| Cart / checkout row | **R2** (cream chips in R6's cream and gold, 93px, "Complete your order in" + big clock, thin grey border with a pink line that shrinks as time runs; only the clock moves) |
| Abandoned email | **A · Golden ticket**, words 1, placed **at the top, before the bag items**; the shop's green free-gifts box stays under the items |
| Email timer | **8 hours** from sending (live moving clock image assumed — confirm vs fixed end time) |
| Pending-order email | same Golden ticket at the top of "Your items", button "Pay now & claim my gift", free-gifts box kept; gift added to the order when it is paid inside 8 hours |
| Products | chosen **per cart-value range** in the admin |
| Admin | Marketing → new page: products per range, timers, all texts, design, who sees it, switches per place, live preview of popup and emails |
| Tracking | popup shown, claimed, dismissed; email sent; email claim clicked; gift added; order placed with it; expired |
| Primary + backup (owner, 3 Nov) | each range has a **primary** product and one or more **backup** products; when the primary is sold out or out of stock, the backup is offered instead — in the popup, the emails, the cart row and at Place order. Same rule as Free gifts' main/backup gifts (`FreeGifts`, "a sold-out main gift gives way to its backup") — reuse it, do not write a second one. If every product of a range is out of stock, that range offers nothing. |

## Still to decide

1. Popup content style **S1–S5** (`exit-popup-styles-S1-S5.html`).
2. Who sees the popup: **Bag** (anyone with items) or **Checkout** (only
   shoppers active on checkout).
3. The cart-value ranges and one product each; whether popup and emails share them.
4. 24-hour pending reminder: a fresh 8 hours, or one window from the first email.
5. Email timer: live moving clock (server-drawn GIF; Outlook desktop shows it
   still) or fixed end time text.

## Rules the build must keep

- A claim link only claims the gift: it never signs anyone in or restores a
  basket (see `CartRecoveryReminder`'s header on bearer links).
- The free-gifts box must stay in both emails — add a test that fails if it
  goes missing (the owner caught a preview without it, 2 November).
- Speed rules (CLAUDE.md): the popup script loads only when the bag has items,
  no layout-measuring JS, no new render-blocking asset, query budgets held.
- Payments are frozen: the AED 0 line must not touch gateway code; run the
  payment test set.
- Header is frozen: the popup must not restyle the header.

## Preview files

- `exit-popup-designs.html` — designs A–D, words 1–5, rows R1–R6, audience.
- `exit-popup-styles-S1-S5.html` / `exit-popup-styles.png` — design A, words 3, 15-minute timer.
- `abandoned-email-designs.html` — the real email, designs A–F, positions.
- `abandoned-email-chosen-phone.png` — the chosen A at the top.
- `pending-order-email.html` / `pending-order-email-phone.png` — the pending reminder.
- `xg-build.py`, `xgp-build.py` — how the email previews were assembled from
  real renders (they need a fresh render of the email as input).
