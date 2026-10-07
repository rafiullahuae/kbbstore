# Stripe — set it up in test mode, test it, then go live

Everything is on **Store → Payments → Stripe** (the "Credit or debit card" tab).
The card has three parts:

- **The status block** at the top: a **TEST MODE** / **LIVE** badge, what a card
  statement and Stripe will say, the webhook, and the payment log.
- **1 · Keys from Credit or debit card** (left column): Mode, the Test keys,
  the Live keys, the webhook URL, Connect.
- **2 · How this shop uses it** (right column): every setting below.

Press **Save Credit or debit card** at the bottom after changing anything.

## The settings, and where each one is

| Setting (right column unless noted) | What it does | Ships as |
|---|---|---|
| **Mode** (left column, top) | Sandbox / test uses the Test keys; Live uses the Live keys. Both sets stay saved. | unchanged |
| **Test publishable / secret key, Test webhook signing secret** (left) | The test key set (`pk_test_…`, `sk_test_…`, `whsec_…`). | empty |
| **Live publishable / secret key, Live webhook signing secret** (left) | The live key set (`pk_live_…`, `sk_live_…`, `whsec_…`). | empty |
| **Statement descriptor (full)** | The full name you want on statements: 5–22 Latin characters, at least one letter, none of `< > \ ' " *`. Stripe does **not** accept this on card payments, so for cards it is set in your Stripe account (see below). The status block compares the two. | your shop name (`K-Beauty Bliss`) |
| **Statement descriptor suffix (cards)** | Added after your Stripe account's short prefix: `PREFIX* SUFFIX`, 22 characters in all. | empty, so no suffix (as before) |
| **Add the order number to card statements** | Adds the order number to the suffix, e.g. `KBEAUTY* KBB 10234`. | Off |
| **Order number prefix shown in Stripe** | e.g. `KBB-` makes order 10234 show as `KBB-10234` in Stripe. Your shop's own order numbers do not change. | empty |
| **Payment description** | What Stripe shows next to each payment. You can use `{number}`, `{order_number}` and `{shop}`. | `Order {number}` (as before) |
| **Authorise only, capture later** | On: the card is only authorised at checkout. You take the money with **Capture** on the order (Store → Orders → the order → Payment). Stripe releases an uncaptured authorisation after about 7 days. | Off (money is taken at checkout, as before) |
| **Stripe email receipts** | On: Stripe also emails its own receipt to the shopper. Stripe does not email receipts for test-mode payments. | Off |
| Stripe Link, Apple Pay, Google Pay, Apple Pay domain file | Unchanged from before. | unchanged |

**The name on card statements** comes from your Stripe account: Stripe Dashboard
→ **Settings → Business → Public details** sets the full statement descriptor and
the shortened prefix. The status block shows what your account says once
**Set up webhook automatically** has been pressed.

## 1. Connect in TEST mode

1. In the Stripe Dashboard, switch on **Test mode** (the toggle at the top),
   then open **Developers → API keys**.
2. On Store → Payments → Stripe, set **Mode** to **Sandbox / test**.
3. Paste the `pk_test_…` key into **Test publishable key** and the `sk_test_…`
   key into **Test secret key**. Save.
   (A key in the wrong box is refused, with a message saying which box it
   belongs in.)
4. Press **Set up webhook automatically** in the status block. Stripe creates
   the endpoint, and the shop stores its signing secret in **Test webhook
   signing secret** for you. If an old endpoint still points at this shop's
   previous address or URL, it is removed, and the message tells you how many.
   You can also use **Connect to Stripe** (paste the secret key), which does
   the same thing.
5. Switch **Offer this at checkout** on and save. The badge reads
   **TEST MODE**. Shoppers see no badge and no difference.

## 2. Test payments (Stripe's documented test cards)

Use any future expiry date, any 3-digit CVC and any postcode.

| Card | What happens in the shop | What you see in Stripe (Payments, test mode) |
|---|---|---|
| `4242 4242 4242 4242` | The order is placed, the received page opens, and the order is **Processing** and marked paid. | Payment **Succeeded**, description `Order …`, metadata `order_number`. |
| `4000 0025 0000 3155` (3-D Secure) | A bank pop-up opens. Press **Complete** and the order is paid as above. Press **Fail** and the card is declined; the shopper stays on checkout and can try another card. | Succeeded after authentication, or **Incomplete** / failed attempt. |
| `4000 0000 0000 0002` (declined) | "Your card was declined." The basket stays put and the order is not paid. | Payment **Failed** (`card_declined`). Shows in the shop's payment log. |
| `4000 0000 0000 9995` (insufficient funds) | Declined as above. | Failed (`insufficient_funds`). |

**With "Authorise only, capture later" on:** pay with 4242. The order shows an
amber **"Card authorised — not captured yet"** with a **Capture AED …** button
(Store → Orders → the order → Payment). In Stripe the payment reads
**Uncaptured**. Press Capture: the order turns green and Stripe shows Succeeded.

**Refunds:** Store → Orders → the order → Refund. You can refund part of the
amount, then the rest. Stripe shows each refund against the payment.

## 3. Check the webhook

- After any test payment, press **Refresh** in the status block. **Last event
  received** shows the time and `payment_intent.succeeded` (or
  `payment_intent.payment_failed` for a decline).
- In Stripe: **Developers → Webhooks** → the endpoint ending in
  `/api/payments/webhook/stripe/…` → each delivery should show response **200**.
- If your Dashboard has **Send test event** on that endpoint, use it. The test
  event names no order, so the shop answers 200 and logs it as received; it
  changes nothing.
- **Last signature failure**: if this shows a time, Stripe is delivering but
  with a secret this shop does not hold. Press **Set up webhook automatically**
  again.
- **Show the payment log** lists the last 500 gateway events, Stripe errors and
  webhook outcomes. It never contains card numbers, keys or secrets.

## 4. Switch to live safely

1. Do all your test orders first. A test-mode order is not a real sale: cancel
   or delete it.
2. In the Stripe Dashboard switch **Test mode off** → Developers → API keys.
   Paste `pk_live_…` into **Live publishable key** and `sk_live_…` into **Live
   secret key**. Leave **Mode** on Sandbox / test for now and save. The test
   keys stay as they are.
3. Set **Mode** to **Live** and save. Then press **Set up webhook automatically**
   again: live mode has its own endpoint and its own signing secret.
4. The badge now reads **LIVE**. Place one small real order with your own card,
   check it in Stripe (live), then refund it from the order.
5. To go back to testing, set Mode to Sandbox / test. Nothing needs re-pasting.

If Mode is Live while the Live boxes are empty or hold test keys, the shop takes
the card option off the checkout, and the status block says why. A test key
must never take "payments" on the live shop.

## What has not been proven against real Stripe

This build was tested only with simulated Stripe responses, because the code
cannot reach Stripe from where it was built. Your test-mode run in section 2 is
the first real check. Points to watch in it:

- The suffix and order number on a test payment's statement descriptor (Stripe
  shows it on the payment page).
- **Set up webhook automatically** creating the endpoint and the first delivery
  returning 200.
- **Capture** on an authorised-only order.
