# Cart and checkout parity: the website and the app (Lane APV3, 19 October 2026)

The owner asked for this in three messages:

> "The checkout process. we need the same one step as in site mobile version. or max two steps.
> more that we don't want in any case. we need super simple short checkout as we have on the
> site."
>
> "same for cart page."

The app now has **two screens before Pay now**: the bag (the site's cart page), then the checkout
(the site's one-page checkout). The nine step screens and the "1 · 2 · 3…" stepper are gone.
Anything that opens over the checkout doesn't count as a step: the Emirate picker, and the summary
and coupon, which open in place. Nor does anything after the tap: 3-D Secure, the Apple Pay /
Google Pay sheet, Tabby or Tamara approving in the app, the order placed, or a failed payment
(the bag is kept).

`tools/apv-shots.cjs` enforces this. The clicked walk records the stack at the moment Pay now is
tapped and fails if more than 2 screens come before it (`bag → checkout`). The app's own routing
answers the same question (`APV.beforePlace()`) and has to say 2 as well.

## How it was measured

kbeautybliss.com refuses its cart and checkout to automated browsers on a data-centre connection.
It answers "We can't take orders from this connection", which is the site's own guard, and it
does that even with an ordinary iPhone user-agent. So `tools/apv-site-co.cjs` renders the two
pages from **this repository's own shop** (`resources/views/store/cart.blade.php`,
`checkout.blade.php`), on SQLite, with the migrations' settings. Those settings include the
**"13 October" checkout layout** the shop runs: one step, summary bar, coupon in the summary, Pay
now. The tool puts two products in the bag through the product page's own Add-to-bag button and
reads the pages at 390. No order is created.

The app builds its bag and checkout from that markup (`en|ar/site-co.js`) and styles them with
those rules (`en|ar/site-co.css`, scoped to `.kc`). Every label, icon, logo and Arabic string is
the site's.

**Product names.** In the Arabic app, product names come from the live site's Arabic data, as
they do everywhere else in the app. The local render has no Arabic product translations, so its
lines show the English names.

**One limit:** settings the owner has changed on the live admin since the last package (for
example Appearance → Checkout page) aren't in the repository, so they can't show here. Re-run the
tool after a package, or from a connection the site accepts (`--url=`).

## The cart page (bag): same sections, same order

| # | Site (390) | App |
|---|---|---|
| — | The site header (logo, search row) | The same header (`HEADER-PARITY.md`) |
| 1 | ← Continue shopping | Same, goes Home |
| 2 | **Your Bag** (3 items), 21px / 800 | Same, 21px |
| 3 | Lines: photo, name, − qty + (30px), price, struck-through was price, Remove | Same. The steppers and Remove change the bag. |
| 4 | **Order Summary**: Subtotal · Total · "Delivery calculated at checkout" | Same, from the bag |
| 5 | **Proceed to checkout →** 53.2px, 12px corners, #C13E63 | 53.2px, 12px corners, opens the checkout |
| 6 | or continue shopping | Same |
| 7 | Visa · Mastercard · Tabby · Tamara · COD | Same |

The checker compares these against the site on every phone render: section order, button height
and corners, title size and stepper height (`cart` issues). The site has no free-delivery bar,
coupon or recommendations on this page in the 13 October layout, so neither does the app. The
coupon is in the checkout's summary, as on the site. On the site the button is in the page, not
fixed, and the app does the same.

## The checkout: one page, same sections, same order

| # | Site (390) | App |
|---|---|---|
| — | Slim header: logo, bag icon (55px) | Same, 55px under the status bar. The bag icon goes back to the bag. |
| 1 | **Order summary** bar, green, with the total and ▾ (50px). It opens to the lines (± and ✕), the promo-code field ("Enter promo code" / Apply), Subtotal, Delivery and Total. | Same. WELCOME10 takes 10% off. Any other code shows the error under the field. |
| 2 | **① Delivery Information**, with "Already a member? Sign in" | Same. Signed in, it shows the account's email instead and fills the fields. |
| | Email address* ("We send your order confirmation here."), Full name*, Phone*, Building / Apartment or Villa*, Area / Street*, Emirate* + Country* side by side | Same fields, same order, same floating labels and icons (51.8px). Emirate opens the app's picker over the page. Area / Street is typed, as on the site, which has no area picker. |
| | ☐ Create an account for faster checkout next time (shows a password field) | Same |
| | ☐ Send me order updates and new offers — on WhatsApp and by email (optional) | Same |
| 3 | **② Delivery**: "Free express delivery over AED 199"; ◉ Express Delivery AED 20, or Free Express Delivery · Free | Same rule (free from AED 199) |
| | ☐ This order is a gift **+AED 15**, with a message field when ticked | Same. Gift wrapping is added to the total. |
| 4 | **③ Payment**, "All transactions are secure & encrypted": ◉ Credit / Debit Card with Card number, Expiration date and Security code in the page; ○ Tabby; ○ Tamara; ○ Cash on delivery | Same list and logos, with the card fields in the page. **Apple Pay (iPhone) / Google Pay (Android) is the first row**, as the site's Apple Pay row is on Safari. |
| 5 | Total, "5% VAT inclusive" ▾ | Same (with the cash-on-delivery fee when that method is chosen) |
| 6 | **Pay now** (Cash on delivery: **Complete Order**), 49px, fully rounded (Arabic 59px, Cairo) | Same words, 49px (Arabic 59px), 99px corners |
| 7 | Safe checkout · 100% authentic · 1–3 days fast delivery all over UAE | Same |
| 8 | Slim footer: K-Beauty Bliss, Need Help?, WhatsApp, email, Shipping & Delivery, Privacy policy | Same. The links open the app's pages, and WhatsApp opens WhatsApp. |

On every phone render the checker compares these against the site: header height, summary bar,
Pay now height and corners, the order of the fields (by the site's own ids), the payment methods
(the site's list, plus the wallet row) and the number of sections (`checkout` issues).

## After Pay now (not steps)

| Method | Site | App |
|---|---|---|
| Card | Stripe confirms; the bank's 3-D Secure if asked | The bank's 3-D Secure screen, then **Order placed** |
| Apple Pay / Google Pay | The wallet sheet | The wallet sheet over the checkout, then Order placed |
| Tabby / Tamara | Hand-off to their approval | Their approval inside the app, then Order placed |
| Cash on delivery | Order placed at once ("Complete Order") | Order placed at once |
| Declined | Message, basket kept | **Payment didn't go through**, the bag kept, *Try again* (back to the checkout with the same method) or *Choose another method* |

## Validation

The site's live-validation module is off by default, so the site relies on each field's
`required`. The app judges the whole page when Pay now is tapped. It shows each message under its
field (red box, ⚠ and the words) and moves to the first one. A field is judged again when you
leave it. The checker's walk taps Pay now with the page empty and expects five or more inline
errors on the one screen. It also expects an empty card to flag its boxes.

## Pictures (`key/`)

- `en-cart.png`, `ar-cart.png`: the site's cart page (390) beside the app's bag, full length
- `en-checkout.png`, `ar-checkout.png`: the site's one-page checkout beside the app's, full length,
  then the states after Pay now
- `en-checkout-flow.png`, `ar-checkout-flow.png`: the clicked walk: bag → checkout → coupon →
  inline errors → emirate picker → filled → after the tap

The old 18-frame step sheets (`*-checkout-steps.png`) have been deleted.
