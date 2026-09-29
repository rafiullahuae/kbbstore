# Releasing a cancelled order's hold — the owner's procedure

**Where it is:** `Orders → (an order) → Items → Release the hold`

It sits inside the **Items** card, between the capture box and the **Refund**
button, so the three things you can do with an order's money read in the order
they happen: capture, release, refund.

---

## What this is, and why it did not exist until now

Tamara and Tabby **authorise** at checkout. The buyer signs up to a payment plan
and the money has not moved yet — the provider is holding the right to take it.

When you cancel such an order, three things happen and one does not:

- the order is cancelled, the units go back on the shelf, the coupon use is
  handed back, and the customer is emailed;
- **and the plan stays live on the buyer's Tamara account, for up to 180 days.**

They are asked for the first instalment on an order that no longer exists. They
ring Tamara, Tamara points at the shop, and nothing on the order says anybody
tried — because nothing could. The endpoint that releases a hold has been in
this shop, working and tested, with **no button anywhere**. This is the button.

Releasing a hold **is not a refund**. No money goes back, because none was ever
taken. It gives back the *right to take it*.

---

## The procedure

1. **`Orders`** in the sidebar, then **View** on the order.
2. Scroll to the **Items** card and look below the totals.
3. Read the panel:

   - **"Hold on the buyer's credit — AED 250.00"** with a
     **`Release the hold…`** button means the hold *can* be released. The line
     above the button says who is holding it and how much.
   - **"Hold on the buyer's credit"** with a greyed sentence and **no button**
     means it cannot, and the sentence is the reason — *"This order is
     processing. Cancel it first."*, *"The money has been captured. Refund it
     instead."*, *"Already released."*, or *"Nothing was ever authorised on this
     order."*
   - **No panel at all** means this order's payment method holds nothing to
     release. Cash on delivery, card payments through Stripe and every imported
     WooCommerce order are in that group — with those, cancelling the order *is*
     the whole of it.

4. Press **`Release the hold…`**. Nothing has happened yet: the panel opens a
   confirmation in place and names the order it is about.
5. Read the confirmation. It says, in the buyer's terms, exactly what pressing
   the next button does — *"This ends the buyer's Tamara payment plan for this
   order and gives back the credit it is holding against. Nothing recreates it:
   if the sale comes back, the buyer has to order and pay again."*
   **`Keep the hold`** closes it and changes nothing.
6. Press **`Yes, release AED 250.00`**. It disables itself while it works.

## How to tell it worked

Three ways, and they are independent of each other:

- **The panel** turns into *"Authorisation released — AED 250.00"* with the date
  and the provider's cancellation reference.
- **A toast** at the foot of the screen carries the provider's own answer.
- **The order's notes** carry a permanent line: *"Released the tamara
  authorisation for 250.00 AED. Cancellation reference cancel_…"*, in your name.
  That is the record a customer asking why they were still billed is answered
  from, and it survives everything.

The rest of the order screen — the grey capture box above the panel, the
"still refundable" figure, and the Order notes card — was drawn *before* you
pressed the button, so it still reads as it did. **Reopen the order** (Back to
Orders, then View) and all three catch up. The release itself is recorded
either way; this is only what is on the screen in front of you.

## If it does not work

The panel says which of the two it was, because they want different next steps:

- **"This server's compiled route table does not know the release endpoint
  yet."** — the package was applied but its caches were not cleared. `Platform →
  Cache`, or `php artisan route:clear` over SSH, then reload. Nothing was
  released and nothing is wrong at the provider.
- **Anything else** is the provider's own answer, printed as it arrived. The
  authorisation is **still live**, and the failure is written on the order's
  notes so there is a record that somebody tried.

## What cannot go wrong

- **It cannot fire twice.** Pressing the confirm button twice, or having the
  order open in two tabs, releases once — the shop claims the release in the
  database before it calls the provider, and the second attempt is answered
  *"already released"* without a second call.
- **It cannot release the wrong order.** The panel asks the server which order
  it is looking at before it draws anything, and asks again at the moment you
  press. If the answer is not the order whose number is at the top of the
  screen, nothing is drawn and nothing is sent.
- **It cannot release a live sale.** A hold is only releasable once the order is
  `cancelled` or `failed`. The button is not offered on anything else, and the
  server refuses it again even if it were.
- **It never changes the order's status.** Cancelling an order and releasing its
  hold are two acts and they can fail independently.

---

## From the shell, when the console is the broken thing

Cloudways gives this shop SSH (**Servers → Launch SSH Terminal**). From the
application root:

```bash
# What would happen — reads only, releases nothing:
php artisan payments:release-hold 1042 --dry-run
php artisan payments:release-hold KBB-1042 --dry-run

# Release it. It prints the order and what it would do, then asks you to type
# the order number back:
php artisan payments:release-hold KBB-1042
```

It takes **the order id or the order number**, and it refuses rather than
guesses if a number you typed is both (imported WooCommerce orders have numeric
order numbers).

**There is deliberately no `--force`,** and a run with nobody to ask — a cron
line, a deploy script, `--no-interaction` — is refused outright rather than
assumed to mean yes. A released authorisation cannot be recreated, so a flag
that answers the question for you is a flag that eventually releases the wrong
hold at three in the morning. If you want it released, it wants a terminal.

It is the same code the button runs, so the order note, the ledger row and the
"release once" guarantee are identical — the note simply says it came from the
shell.

---

## What this lane found and did not change

- **The "still refundable" figure on an authorised-but-uncaptured BNPL order
  shows the full order total**, so the Refund form offers a refund of money that
  was never taken (the provider then refuses it). It comes from
  `PaymentRefunder::capturedFils()`, which falls back to the order total when an
  order is marked paid — pre-existing, outside this lane's files, and reported
  rather than fixed because it is a live money path.
- **`GET /admin-api/orders/{id}/settlement` still has no caller.** It is kept on
  purpose; the reasoning, and the guard that now catches the drift that made it
  worth asking about, are in `tests/Feature/ReleaseTheHoldTest.php` under
  *"keeps the settlement endpoint and the order-detail payload in step"*.
