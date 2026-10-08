# Payments: test every gateway before the switch

The owner, 8 October 2026: *"before switch, i need to finalized check for
payment gateways."* This is the order to do it in. About 30 minutes.

## 0. One button first

**Platform → Domain switch → step 1b "Payments ready?" → Check everything.**

It asks Stripe, Tabby and Tamara (read-only, nothing is changed there) and
checks cash on delivery. Every line is green, amber or red, and every red says
which button to press.

- **Before the switch** the webhooks read **amber** ("points at extrabeauty.ae,
  the address the shop uses today"). That is correct. They turn green after
  step 6 and step 7.
- Anything **red** before the switch: fix it first, then press the button again.

## 1. Card (Stripe), in test mode

Store → Payments → Stripe → Mode **Sandbox / test** (test keys in). Then place
three orders on the shop, each with expiry **any future date**, CVC **any 3
digits**, any postcode:

| Card | What it tests | A pass looks like |
|---|---|---|
| `4242 4242 4242 4242` | a normal payment | Thank-you page; within a minute the order is **Paid** in Store → Orders (that is the webhook working) |
| `4000 0025 0000 3155` | 3-D Secure | Stripe's test "Complete / Fail" window opens; press **Complete** → the order is **Paid** |
| `4000 0000 0000 0002` | a declined card | "Your card was declined." The basket stays; the order is **not** paid |

If it fails: the red box on the checkout says why; **Store → Payments →
Payment log** has the full line; step 1b shows the last failure under
"Last card payment". More detail: `docs/STRIPE-TEST-GUIDE.md`.

Apple Pay / Google Pay (if switched on): on an iPhone in Safari the Apple Pay
button must show. If it does not, step 1b says which domain to add in Stripe →
Settings → **Payment method domains**.

## 2. Tabby, with sandbox keys

Tabby's test details only work with **test keys** (`pk_test_…` / `sk_test_…`),
from Tabby's own testing page ([docs.tabby.ai → Testing
Credentials](https://docs.tabby.ai/testing-guidelines/testing-credentials)):

| | Email | Phone | OTP | A pass looks like |
|---|---|---|---|---|
| Approved | `otp.success@tabby.ai` | `+971500000001` | `8888` | you come back to the shop's thank-you page and the order turns **Paid** |
| Rejected | `otp.rejected@tabby.ai` | `+971500000001` | `8888` | Tabby says no; back on the shop the order is **not** paid and the basket is still there |

Same email with `+971500000002` is Tabby's "rejected before scoring" case.
Tabby updates this page from time to time: if a number is refused, take the
current one from that page or from your Tabby dashboard.

If it fails: step 1b's **Tabby** box (keys, merchant code, webhook), and
Store → Gateway webhooks → Tabby.

## 3. Tamara, with the sandbox token

Tamara does **not** publish test phone numbers: **get the sandbox test login
(phone number) from your Tamara dashboard / your Tamara contact.** In the
sandbox the OTP is shown on the login screen.

Tamara's own test cards ([docs.tamara.co → Testing
Cards](https://docs.tamara.co/docs/testing-cards)), expiry `01/99` or any
future date:

| Card | CVV | A pass looks like |
|---|---|---|
| `4242 4242 4242 4242` (3-D Secure) | `100` | back on the thank-you page; the order turns **Paid** |
| `4111 1111 1111 1111` (no 3-D Secure) | `100` | same |
| `4556 2537 5271 2245` (fails) | any | Tamara refuses; the order is **not** paid |

If it fails: step 1b's **Tamara** box ("Tamara's SANDBOX server refused the API
token" means Mode and token do not match), and Store → Gateway webhooks →
Tamara.

## 4. Cash on delivery

Place one order with **Cash on delivery**. A pass: the order goes straight to
**Processing**, the confirmation email arrives, and the COD fee (if any) shows
in the total.

## 5. Go live, then once more after the switch

1. Put the **live** keys in for each gateway (Stripe Mode → Live; Tabby `pk_`
   / `sk_` keys; Tamara Mode → Live with the live token).
2. Press **Check everything** again: no red.
3. After the switch (Domain switch step 6, then step 7's three buttons), press
   **Check everything** once more. Now every webhook must read green "points at
   https://kbeautybliss.com/…, the main address".
4. One small real card order, then refund it from Store → Orders.
