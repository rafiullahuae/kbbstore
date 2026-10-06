# Lane PY — checkout payment boxes: logo + brand-colour previews

Owner's ask: proper Tabby / Tamara logos in the empty right side of each payment
box, and each box in its gateway's colour scheme — **previews first**. Nothing
here is wired into the shop; it ships once he picks a letter.

`docs/lane-py-shots/overview-390.png` shows every design side by side.

## Where it sits

Checkout → step **4 Payment**. The markup comes from
`resources/views/partials/checkout/payment-methods.blade.php` and the CSS from
`resources/css/kbb/kbb-checkout.css`. The checkout shows four methods, in the
order of their positions in Store → Payments: Tabby, Tamara,
Credit or debit card (Stripe) and Cash on delivery.

## Designs (all four keep today's 56 px rows, so there is no layout shift)

| | Look | Unselected | Selected |
|---|---|---|---|
| **A** soft tint | every box carries a light tint of its brand | tint + grey border | brand-gradient border |
| **B** brand stripe | white boxes, 5 px brand edge on the inline-start side | white | border in the brand ink, brand-filled radio dot, faint tint behind the description |
| **C** bold selected | neutral boxes with logos | white | the whole box fills with the brand's own colours (Tabby mint, Tamara pastel gradient, Visa blue, COD green), and the logo switches to the wordmark |
| **D** clean + plan | white boxes, logo always in its own badge | white | border in the brand ink and a 3 px brand bar; the panel shows the **4 payments with amounts and dates** (Tabby's own quarter-pie icons) |

Shots (`docs/lane-py-shots/`): `{now,a,b,c,d}-{tabby,tamara}-{390,1280}.png`,
`*-ar-390.png` (Arabic/RTL), `{a,b,c,d}-zoom-logos-390.png`, and
`measurements*.json`. Measured for every design at 390 and 1280:
`scrollWidth` = viewport, label height 56 px (the same as today, so the tap
target is ≥ 44 px), and the logo slot fixed at 84×26 px.

## Logos — official files, converted without redrawing

The machine cannot reach tabby.ai or tamara.co, so the logos came from the
providers' own SDKs. Each one is an Android VectorDrawable, converted to SVG by
`tools/pay-vd2svg.py`, which copies the path data byte for byte.

| File (`resources/payment-logos/`) | Source | Licence |
|---|---|---|
| `tabby-badge.svg`, `tabby-wordmark.svg` (badge ground dropped) | github.com/tabby-ai/tabby-android-sdk @ `655443b9` (2026-09-16), `tabby-sdk/src/main/res/drawable/ic_tabby_logo.xml` | MIT, © 2026 Tabby FZ-LLC |
| `tabby-installments/q1–q4.svg` | same repo, `drawable/ic_ellipse_q1..q4.xml` (Tabby's installments widget) | MIT |
| `tamara-badge.svg`, `tamara-badge-ar.svg`, `tamara-wordmark.svg`, `tamara-wordmark-ar.svg` | npm `react-native-tamara-sdk@1.1.3` (author "Tamara", homepage tamara.co), `android/src/main/res/drawable{,-ar}/tw_ic_logo_badge.xml`, `tw_ic_logo.xml` | package.json says ISC; its LICENSE file says MIT © Khoi Le |

Licence note: those code licences cover the files. They do **not** grant
trademark rights. Showing a provider's logo on its own payment option is what
merchants normally do, but the providers' merchant brand kits are the
authority. Tamara's npm package is not published from a Tamara-named npm
account, although it calls itself Tamara's SDK. If the owner wants the
belt-and-braces version, he can download the logos from the Tabby merchant
resources and the Tamara partner brand guidelines. They would replace these
files one for one.

Not usable: `tabby-react-native-sdk@2.0.0` (it has no logo, only a close icon),
and `@akinon/pz-tabby-extension` and `@akinon/pz-tamara-extension@2.0.143`
(no images).

The card marks are the Visa and Mastercard drawings the shop already ships in
`App\Support\PaymentMarkArt`. COD gets a plain banknote outline icon, which
replaces today's 💵 emoji. Today's emoji also disappears when COD is selected,
because the selected radio dot reuses the same `::after`.

## Colours used

- **Tabby:** `#3BFF9D` → `#3BFFC8` (badge gradient) and `#292929` (wordmark),
  both from `ic_tabby_logo.xml`; `#54545C` (widget grey) from `values/colors.xml`.
  The tints `#EBFFF6` and `#F3FFFA` are derived from these.
- **Tamara:** the badge gradient `#AAE1FF` `#F9BD9A` `#FFBC8C` `#FFBE92`
  `#F8BC8B` `#F29F7E` `#F0826B` and the black `#000000` wordmark, from
  `tw_ic_logo_badge.xml`. The tints `#F1F9FF` → `#FEF4EE` → `#FDEEE9` are derived from them.
- **Card:** `#1434CB`, the Visa blue already in `PaymentMarkArt`. **COD:** the
  shop's own green, `#1F7D52` with `#EEF8F1`.

Contrast (WCAG): the lowest pair is white on COD green in C, at 5.11:1. Next are
black on Tamara's darkest stop at 8.11, white on Visa blue at 8.81 and `#292929`
on mint at 11.07. The shop's ink-2 on every tint is ≥ 6.43.

## Reproduce

```bash
sh tools/pay-preview.sh            # seeded shop, all four gateways (fake keys)
node tools/pay-shots.cjs docs/lane-py-shots <port>
kill $(cat storage/framework/testing/lane-pay-preview/server.pid)
PAY_AR=1 sh tools/pay-preview.sh && node tools/pay-shots.cjs docs/lane-py-shots <port> ar
```

The designs live in `tools/pay-designs.css` (preview only, loaded by the shot
script). The shop's fixed side tab `.kbt-z` is hidden in the shots because it
overlaps the left edge.

## Phase 2: what shipped (the owner picked A)

> "option A, soft tint is fine. please proceed. and give controls too on backend."

The controls are in **Appearance → Checkout page → Payment boxes**. Each one
ships at A as previewed:

| Control | Default | Choices |
|---|---|---|
| Style | Soft tint | Soft tint / Today (the plain boxes, byte-identical to before; every control below is inert under it) |
| Show logos | on | |
| Logo height | 26 px | 20–32 px, step 2 (26 keeps every row 56 px tall) |
| Tint strength | Medium (as previewed) | Light / Medium / Strong |
| Border of the chosen box | Brand gradient | Brand gradient / Brand colour, solid / Shop pink |
| Tabby / Tamara / Card / Cash on delivery in brand colours | all on | Off keeps that one box plain, with its logo still shown |
| Tamara logo | Badge | Badge / Wordmark. The Arabic shop uses Tamara's Arabic artwork automatically |

Where each piece lives:
- `App\Support\PaymentMarkArt::checkoutLogo()` holds the logos as fixed
  constants, with the ids made unique per logo.
- `CheckoutPage` holds the schema. `bodyClass()` adds `cop-pay`,
  `cop-pay-light|strong` and `cop-pay-bsolid|bpink`; `cssVariables()` adds
  `--cop-paylogo`; and `paymentBoxes()` supplies the logo and brand data.
- `partials/checkout/payment-methods.blade.php` adds the `pay-brand` class to
  each box and the logo slot inside each label.
- `kbb-checkout.css` carries the "PAYMENT BOXES" block. It also holds the
  Arabic 44 px gap fix and the rounded bottom corners on the chosen box.

Shots: `final-*` in `docs/lane-py-shots/`. `final-today-*-390.png` is
pixel-identical (same md5) to the pre-lane `now-*-390.png`. Reproduce them
with `tools/pay-final-shots.cjs`.
