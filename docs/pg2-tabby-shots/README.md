# Tabby — Store → Payments → Tabby, photographed

Lane PG2. Chromium 1194 (`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`)
via Playwright, `newContext({viewport})` — not `setViewportSize`, which has
produced wrong-sized shots on this box — `deviceScaleFactor: 1`,
`reducedMotion: 'reduce'`, `fullPage: true` for the screen shots and an element
screenshot at a tall viewport for the card (an element shot is clipped to the
window, which is what cut the first attempt off at "Capture window (days)").

Served by `php -S` in front of `public-web-root/index.php` through
`tests/browser/preview-router.php`, against a scratch SQLite database seeded
with an owner account, the four gateway rows and **invented Tabby keys**
(`pk_test_11111111-…`, `sk_test_11111111-…`). No Tabby account, no real key.
Egress to `api.tabby.ai` is blocked in this container, which is why the webhook
states in the JSON below read `unreadable` — that is the honest degradation and
it is the point of showing it.

| # | File | What it shows |
| --- | --- | --- |
| 01 | `01-payments-tabby-{390,1280}` | The whole Payments screen, for context. |
| 02 | `02-tabby-card-{390,1280}` | The Tabby card end to end, every control. |
| 03 | `03-new-setting-{390,1280}` | **The only control this lane adds**, and the webhook block under it. |

## The one new control

**Store → Payments → Tabby → Send past-order history to Tabby.**

It ships **Off**, which is CLAUDE.md rule 1 — applying the package moves
nothing. Measured, not asserted: `selectedOption` is `"Off"` and
`selectedValue` is `""` at both widths.

Off, Tabby is sent no `order_history` key at all. On, it is sent up to ten of
this buyer's previous orders with the name, phone, email, delivery address and
items on each — which is why it is a switch and not a default.

`buyer_history` (when this customer registered, how many orders they have
finished) is NOT this switch and is sent either way. It names no order, no
address and no amount.

## Measured

| | 390 | 1280 |
| --- | --- | --- |
| `document.documentElement.scrollWidth` | 390 | 1280 |
| viewport (`clientWidth`) | 390 | 1280 |
| `#content` scrollWidth − clientWidth | **0** | **0** |
| Tabby card width | 362 | 996 |
| Tabby card height | 1582 | 1418 |
| controls carrying `data-payg="tabby"` | 5 | 5 |
| every control's width × height | 328 × 33 (35 for the select) | 520 × 33 (35 for the select) |
| control font size | 13 | 13 |

No horizontal overflow at either width, and the new select is the same width and
one pixel taller than the text boxes above it because it is a `<select>` — the
same shape every other `bool` field on this screen already draws.

`#content` and not the document for the overflow number, for the reason
`tests/browser/admin-overflow.mjs` gives: the admin is a two-pane layout whose
`#content` column is itself `overflow-x:auto`, so the document metric reads
exactly the viewport width however wide the content is.

## The endpoints, driven live

`routes/payments-tabby.php` was mounted into a **scratch copy** of
`routes/web.php` at the line its own header tells the integrator to use, driven,
and the scratch copy reverted — `routes/web.php` on this branch is untouched.
Through the real router, the real `auth:admin` guard and real CSRF:

    unauthenticated   GET  /admin-api/payments/tabby/webhooks   302 → login
    unauthenticated   POST /admin-api/payments/tabby/webhooks   419
    unauthenticated   POST /admin-api/orders/1/void             419

    owner  GET  /admin-api/orders/1/void   (tabby, cancelled, uncaptured)
      {"provider":"tabby","void_supported":true,"voided":false,"voidable":true}

    owner  GET  /admin-api/orders/2/void   (cash on delivery)
      {"provider":"cod","void_supported":false,"voided":false,"voidable":false}

    owner  POST /admin-api/orders/2/void            502
      {"ok":false,"code":"unsupported",
       "message":"This order was not paid through a gateway whose authorisation can be released."}

    owner  POST /admin-api/orders/1/void            502
      {"ok":false,"code":"unreachable",
       "message":"Tabby could not be reached. The authorisation is still open; try again.",
       "void":{"provider":"tabby","voided":false,"voidable":true}}

    owner  POST /admin-api/payments/tabby/webhooks  200
      {"ok":true,"webhook_url_ready":true,"is_test":true,"keys_disagree":false,
       "registered_anywhere":false,
       "countries":[{"country":"AE","state":"unreadable","stale":0,"pruned":0,
                     "message":"Tabby would not list this country's webhooks, so nothing was changed."}, …]}

Three things in that last group are worth reading twice, and all three are the
network being genuinely down rather than a fixture:

- **`"voided": false` after a failed release.** PaymentVoider claims `voided_at`
  before it calls out and PUT IT BACK when the call failed. An order flagged
  released against a hold that is still open is the one lie that class is shaped
  around, and this is that restore path running against a real failure.
- **`"registered_anywhere": false`.** The sync reports that it registered
  nothing anywhere instead of a bare success. A green tick over five countries
  it could not reach is the report that would stop the owner looking.
- **The webhook URL is absent from every one of those bodies.** Its random tail
  is what makes this shop's endpoint unguessable; `webhook_url_ready` says
  whether one exists, which is all the screen needs.
