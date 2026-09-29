# Registering the Tamara webhook — what to click, and how to tell it worked

Lane TM. Written for the owner, who runs everything himself.

---

## Why this matters, in one paragraph

Tamara tells this shop that an order was **approved** through the callback it
receives at checkout. It tells this shop that an order was **declined** or has
**expired** through a *different* channel — a webhook that has to be registered
through Tamara's own API first. Tamara's merchant portal has no screen for it,
and until now neither did this admin. So a refused shopper left an order sitting
at **pending** for ever: its stock still claimed, its coupon still spent, and no
trace of what happened except an order that never moves. Registering the webhook
is a one-time job that closes that.

---

## 1 · The procedure

1. **Open `Store → Payments`** in the sidebar and press the **Tamara** tab.
   Check that the API token and notification token are filled in and that the
   card's status pill does not say "not configured". If it does, paste the two
   keys from Tamara's merchant portal (**Settings → API**), press
   **Save Pay later with Tamara**, and wait for the toast.

   > The webhook registration needs a *webhook secret*, and that secret is
   > generated the first time the Tamara tab is saved. If Tamara has never been
   > saved on this shop, step 4 will tell you so rather than failing oddly.

2. **Press `Webhook & limits…`** in the row of buttons at the bottom of that
   Tamara card — it sits between *Check this setup* and *Save Pay later with
   Tamara*. (It is the same screen as **`Store → Gateway webhooks`** in the
   sidebar, one row below *Payments*; either way in.)

3. On **`Store → Gateway webhooks`**, read the **Tamara** card's four boxes:

   | Box | What it should say before you start |
   |---|---|
   | Keys stored | **Yes** |
   | Webhook | **Not registered** — in amber, with the sentence about declines |
   | Events it sends | `order_expired, order_declined` |
   | Basket limits | **— – —**, meaning none have been pulled yet |

4. **Press `Register the webhook`.**

5. **Read the strip that appears at the top of the screen.** It says one of:

   | What it says | What it means | What to do |
   |---|---|---|
   | *Tamara will now send expiry and decline notices to this shop.* | Done. | Nothing. |
   | *A webhook was already registered; nothing was changed.* | It was already done, possibly by the command line. | Nothing. This is a success. |
   | *Tamara has no API token stored yet…* | Step 1 was not finished. | Paste the keys on `Store → Payments → Tamara` and save. |
   | *This gateway has no webhook secret yet…* | Tamara has never been saved on this shop. | Press **Save Pay later with Tamara** once, come back, press again. |
   | *Tamara refused the request. Nothing was changed.* | Tamara's end. | Check you are on the right **Mode** (Sandbox vs Live) for the keys you pasted, then try again. |

6. **How to tell it worked.** The **Webhook** box turns green and reads
   **Registered**, with `Tamara's id for it: wh_…` underneath. That id comes
   from Tamara, not from this shop — a shop that had not really registered
   cannot invent one. Leaving the screen and coming back re-reads it from the
   database, so it is not a message that fades: if it still says *Registered*
   tomorrow, it is registered.

7. **While you are there, press `Pull the limits from Tamara`.** Tamara refuses
   a basket outside the minimum and maximum it agreed with you, and with those
   two numbers blank this shop offers Tamara on *every* basket — including the
   ones Tamara will reject after the shopper has already pressed *Place order*.
   The strip reports the two numbers and the **Basket limits** box fills in.
   Leave the market dropdown on *This shop's own market* unless you are
   checking another country.

---

## 2 · The one destructive button

**`Remove the registration…`** appears only once a webhook is registered, and it
asks before it does anything: a warm strip opens with **Yes, remove it** and
**Keep it registered**, and nothing is sent until you press one. Removing it
puts the shop back in the state described at the top of this page — declines stop
arriving and orders sit at `pending`. There is no reason to press it except to
re-register against a different Tamara account.

---

## 3 · If an approval went missing anyway

**`Run the sweep`** on the same screen asks Tamara about this shop's own
**pending** Tamara orders and marks the approved ones paid. Use it if a customer
says they paid and the order is still pending.

- It **names no order and carries no amount**. It cannot be pointed at one
  order, which is deliberate: "settle this order" is "mark this order paid".
- The three boxes beside it bound the work: skip orders newer than *N* minutes
  (a shopper may still be on Tamara's page), look back *N* days, examine at most
  *N* orders.
- It reports, in a sentence: *Checked 1 order(s) with Tamara. Marked paid: 1.
  Closed as declined or expired: 0. Still waiting: 0. Could not check: 0.* —
  and lists each order it touched underneath.

---

## 4 · The same four jobs from the Cloudways shell

Cloudways gives this shop SSH (**Servers → Launch SSH Terminal**). These are for
the day the admin console will not load, or a release has been applied and its
route cache has not been cleared — which is exactly when a screen cannot help.

```bash
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app

# What is registered right now. Calls Tamara for nothing.
php artisan payments:tamara-webhook

# Register it. Doing this twice is safe: the second run changes nothing.
php artisan payments:tamara-webhook --register

# Remove it. Asks first; --force is how a script says it means it.
php artisan payments:tamara-webhook --remove
php artisan payments:tamara-webhook --remove --force

# The basket limits.
php artisan payments:tamara-limits --show              # print, call nothing
php artisan payments:tamara-limits                     # pull for this shop's market
php artisan payments:tamara-limits --country=SA --currency=SAR

# The recovery sweep (this one already existed).
php artisan payments:tamara-sweep --dry                # list, ask Tamara nothing
php artisan payments:tamara-sweep
```

Exit code 0 means the thing asked for is true afterwards; 1 means it is not.
Nothing above prints a key, a token or the webhook address.

**Worth a cron line**, one only — Laravel's scheduler needs exactly one entry and
`routes/console.php` decides the rest:

```
* * * * * cd <app root> && php artisan schedule:run >> /dev/null 2>&1
```

---

## 5 · Tabby, on the same screen

The **Tabby** card underneath does the same job for Tabby, which registers a
webhook **per market** rather than one for the shop. It shipped with the same
defect: `GET` and `POST /admin-api/payments/tabby/webhooks` were live and
nothing in the console called either.

- **Register / re-sync Tabby's webhooks** registers the missing markets and
  prunes stale registrations pointing at an address this shop no longer serves.
- **Re-read the state** asks again without changing anything.
- The list underneath is one line per market: `AE — registered`, `SA — missing`,
  and so on, with Tabby's own reason when one fails.

The card shows **whether** a webhook address exists, never what it is — the
address embeds the secret that verifies every delivery, so it is treated as a
credential and this screen is not given it.

---

## 6 · Where everything sits, in one list

| What | Where |
|---|---|
| Tamara keys, mode, labels, exclusions | `Store → Payments → Tamara` |
| Webhook registration, basket limits, sweep | `Store → Gateway webhooks → Tamara` |
| Tabby per-market webhooks | `Store → Gateway webhooks → Tabby` |
| The button that takes you from one to the other | `Store → Payments → Tamara →` **Webhook & limits…** |
| Clearing a stale route cache after a release | `Platform → Cache` |

Screenshots of every step above, at 390px and 1280px, are in `docs/tm-shots/`.
