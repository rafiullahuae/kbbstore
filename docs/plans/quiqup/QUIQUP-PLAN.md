# Quiqup deliveries: schedule from the Orders list, live tracking for customers

> **Status: PLAN, waiting for the owner's go.** No app code has been written.
> Owner's request, 14 November 2026: select orders, click Schedule, and they
> appear in Quiqup without retyping. Quiqup status changes (out for delivery,
> delayed, failed attempt, returned, delivered) show on the website
> automatically. Customers get a full step-by-step delivery timeline on the
> track-order page and on every order in My account.
>
> Sources read for this plan: every page of developer-docs.quiqup.com (its
> `llms.txt` index, the 21 guide pages and both OpenAPI bundles, `orders.yaml`
> and `auth.yaml`), the Quiqup WooCommerce plugin v1.0.3 (read only, never run),
> and this repo at `claude/funny-knuth-237xo7` (f0827f6f6).

---

## 0. In plain words: what you get, and what I need from you

**What you will do in the admin:**

1. Open **Store → Orders**, tick the orders you have packed, and choose
   **Schedule with Quiqup…** from the bar that appears above the list.
2. A panel slides in from the right with one line per order. The shop has
   already filled everything in: the customer's name, a phone number in the
   format Quiqup accepts, the address and emirate, the cash amount to collect
   for COD, the number of parcels and the service (Same Day, Next Day or
   4-hour). Anything Quiqup would refuse, such as a bad phone number, a missing
   address or an unpaid order, is marked in red with a box to fix it there.
3. Press **Schedule 12 orders**. Each line turns green and shows its Quiqup
   number and tracking link, or turns red with Quiqup's own reason. Pressing
   it twice, closing the tab or a slow connection can never book the same
   order twice.
4. **Print labels** prints every label for the selection as one PDF. **Cancel**
   and **Re-book** are on each order while Quiqup still allows them.

**What your customers will see.** On the track-order page and on each order in
My account, a timeline: *Placed → Confirmed → Booked with courier → Picked up →
At the delivery hub → Out for delivery → Delivered*, with the date and time of
each step. It also shows side steps when they happen: *Delivery attempt 1
missed*, *Delayed*, *Returning to us*, *Returned*. It is in English and Arabic
and reads right to left on /ar. The steps move by themselves. Quiqup tells the
shop the moment a status changes, and the shop also checks with Quiqup every
few minutes in case a message was missed.

**What changes on its own.** By default, when Quiqup picks a parcel up, the order
becomes **Shipped**, and when Quiqup delivers it, the order becomes **Completed**.
These are the same changes you make by hand today, so they send the same
emails. Every mapping can be changed in **Store → Quiqup → Statuses**.

**What I need from you before building (§12 has the full list):**
- A Quiqup **staging** API key (Quiqdash → Organization → API keys, expiry
  **Never**). Ask your account manager to switch staging on if needed.
- Your **pickup address** and the contact name and phone the courier should
  call.
- Which **services** are switched on for your account (Same Day / Next Day /
  4-hour) and your **cut-off times**.
- Five short answers, listed in §12.

**Size:** about **4 packages** over 3 rounds of work, sandbox first, then live.

---

## 1. What Quiqup actually offers (facts from the docs, quoted)

### 1.1 Environments, auth

| | Staging | Production |
|---|---|---|
| Last-mile API | `https://api.staging.quiqup.com` | `https://api-ae.quiqup.com` |
| Dashboard | `https://quiqdash-beta.staging.quiqup.com` | `https://business-ae-beta.quiqup.com` |

- Auth: `Authorization: Bearer <token>`, using a key generated in **Quiqdash →
  Organization → API keys**. Expiry is a dropdown: *Never, 1 Day, 7 Days, 30
  Days, 60 Days*. The docs call a key that silently expires "the most common
  outage". **We use a Quiqdash key set to Never**, so there is no OAuth
  exchange and no refresh logic. (`POST /oauth/token` client credentials exist,
  with `expires_in` 604800 s, but are meant for middleware platforms.)
- Keys and webhook subscriptions are **per environment**. "Nothing in a
  response says which environment answered", so the base URL lives in our
  stored config next to the key, never as a code default.
- `http://` redirects and can drop POST bodies. HTTPS only.

### 1.2 The order calls (last-mile, unprefixed paths)

| Operation | Use here |
|---|---|
| `POST /orders` | Create one order. **There is no batch create**, so a selection is N calls. |
| `PUT /orders/{id}/ready_for_collection` | Release a `pending` order. It can be skipped with `"ready_for_collection": true` on the create, which is **applied asynchronously**: the create response still says `pending`. It may answer **202** `x-projection-pending: true`, meaning *do not retry, read it back*. |
| `GET /orders/{id}` | Current state only, no history. An unknown id gives **403, not 404**. |
| `GET /orders?page&per_page&from&to[&filters[state]][&filters[partner_order_id]]` | List. **Leaves `pending` out unless `filters[state]` names it.** `partner_order_id` matches as a *contains*, case-insensitive. `total` can be wrong; page until `results` < `per_page`. `from`/`to` are submission days in UAE time. Items carry `state`, `state_updated_at`, `delivery_attempts`, `delivery_failure_reason`, `tracking_url`. |
| `GET /order_label/{id}[,{id}…]` | AWB PDF, one page per parcel, batchable with commas. **An unknown id returns 200, `application/pdf`, zero bytes.** We must check length and `%PDF` magic. |
| `PUT /orders/batch/set_cancelled` `{"order_ids":[…],"reason":"…"}` | Only while `pending` or `ready_for_collection`. **All-or-nothing for the batch**: one bad id cancels nothing. |
| `POST /orders/{id}/parcels` `{"name":…}`, `DELETE /orders/{id}/parcels/{parcel_id}` | Only while `pending`. |

**No update endpoint.** Payment, COD amount and service cannot be changed after
creation. The fix is to cancel while `pending`/`ready_for_collection` and
create again. Address corrections are made in Quiqdash only. From
`out_for_collection` onwards the API refuses cancellation.

**Idempotency (the key fact):** *"Send a `uuid` on every create … Resending the
same `uuid` from the same account returns the original order with HTTP 200
instead of 201, and no second order is created."* Without it, retries make
duplicate orders and labels.

### 1.3 The create payload we will send (UAE domestic)

```json
{
  "uuid": "<deterministic UUIDv5, see §4.3>",
  "kind": "partner_next_day",
  "partner_order_id": "<orders.order_number>",
  "payment_mode": "paid_on_delivery",
  "payment_amount": 189.00,
  "disallowed_payment_types": [],
  "ready_for_collection": true,
  "origin":      { "contact_name": "…", "contact_phone": "+9715…",
                   "address": { "address1": "…", "address2": "…", "town": "Dubai", "country": "AE" },
                   "emirate": "Dubai" },
  "destination": { "contact_name": "…", "contact_phone": "+9715…", "notes": "…",
                   "address": { "address1": "…", "address2": "…", "town": "…", "country": "AE" },
                   "emirate": "…" },
  "items":    [ { "name": "Parcel 1 of 1", "quantity": 1, "parcel_barcode": "" } ],
  "products": [ { "sku": "…", "description": "≤18 chars", "quantity": 2 } ]
}
```

- Required in practice: `origin.contact_name/contact_phone/address.address1`,
  the same three on `destination`, and `items[]` with a `name`.
  `kind` defaults to `partner_same_day` and we always send it explicitly.
- Phones: **E.164 with one `+`**. "A dropoff number that is not a valid UAE
  number … is the most common reason an otherwise good order fails."
  `App\Support\UaePhone::e164()` / `isMobile()` already exist.
- `payment_amount` only with `paid_on_delivery`, and it must be **above 1**.
  Prepaid sends no amount. Payment is order-level.
- `emirate` sits on the waypoint and is stored as sent. Docs examples:
  `Abu Dhabi`, `Dubai`, `Sharjah`, `Ajman`, `Umm al-Qaywayn`,
  `Ras Al-Khaimah`, `Fujairah`. Ours (`OrderAddress::EMIRATES`) spell two of
  them differently (`Umm Al Quwain`, `Ras Al Khaimah`), so we map them.
- **No postcode, no Makani, no UAE national address field.**
  `ksa_national_address` exists for Saudi orders only. `coords` are optional
  `[lng, lat]`; reversed coordinates fail silently. **We send none** (we do not
  collect them).
- `scheduled_for`: "For a `partner_4hr` order, the earliest time Quiqup should
  collect it … between 04:00 and 14:59 UTC (08:00 to 18:59 Dubai time) … Send
  it with the `Z` suffix". It is ignored for other services. **This is the
  only time slot the API takes.** There is no delivery slot or booking-window
  endpoint.
- `products` is optional domestically ("useful there mainly so support can see
  what is in the box"). Bundles must be expanded. Keep `description` ≤ 18
  characters.
- **Unknown fields are silently ignored** on every endpoint, so a typo looks
  like success. Contract tests pin our field names (§8).
- No serviceability check, no rates endpoint, no SDK.

### 1.4 Service types (`kind`; read back as `service_kind`)

`partner_same_day`, `partner_next_day`, `partner_4hr`, `partner_return`,
`partner_export` (`partner_store` resolves to same-day, and `partner_ondemand`
and 1-hour are discontinued). Each must be enabled on the account. Only Same
Day is checked at create (`422 Same day service is not enabled for your
account`); the others are accepted even when off. "An order past the cut-off is
accepted and collected on the next cycle. It is not rejected." Cut-off times
come from the account manager or Quiqdash, not the API.

### 1.5 The state machine (wire field `state`; webhook `payload.state`)

`pending · ready_for_collection · out_for_collection · collected ·
collection_failed · received_at_depot · at_depot · scheduled · in_transit ·
out_for_delivery · delivery_complete · delivery_failed · return_to_origin ·
out_for_return · returned_to_origin · cancelled · on_hold · error`
(`error` "last-mile orders do not currently enter this state"). End states:
`delivery_complete`, `returned_to_origin`, `cancelled`. Failures loop back:
`collection_failed → ready_for_collection`, `delivery_failed → at_depot`.
`delivery_failure_reason` is free text. "Handle every value, including ones
you do not recognise." Status is order-level, so a multi-parcel order can read
`at_depot` while one parcel has already been delivered.

### 1.6 Webhooks (the live part)

- Registered by hand in **Quiqdash → Integrations → Webhooks → New
  subscription**: HTTPS target URL, events (Quick select "All order events" =
  19 events), optional **custom headers**, Active. Registrations are **per
  environment and not retroactive**.
- Signature: `X-Quiqup-Signature` = hex `HMAC-SHA256(secret, "{X-Quiqup-Timestamp}.{raw_body}")`.
  Reject when the timestamp is more than **5 minutes** off. Use the raw bytes.
  The secret is revealable any time in Quiqdash (eye icon). The legacy
  `x-signature` (SHA1) header is ignored.
- Delivery: any 2xx counts as delivered. A **15 s timeout**, 429 or 5xx is
  retried 5 times in total about 1, 2, 4 and 8 s apart, so the retries cover
  only about 15 s. Any other 4xx is not retried.
  10 failures open a circuit (events dropped, shown as *Circuit open*, never
  re-sent); **50 failures pause the subscription**. "Events that occur while a
  subscription is paused … are not delivered later."
- **No event id.** Dedupe on `payload.id + payload.state + payload.state_updated_at`.
  Events "may be redelivered and can arrive out of order". Order by
  `state_updated_at`, not `sent_at`.
- A delivery-log **Retry** "does not carry your custom headers", so **custom
  headers cannot be our authentication**. We use a secret URL segment plus the
  HMAC instead (§5).
- Body: `{action:"update", type:"order", sent_at, payload:{…}}`. The payload
  carries `id`, `uuid`, `state`, `state_updated_at`, `service_kind`,
  `partner_order_id`, `payment_*`, `collection_after/before`,
  `delivery_after/before`, `delivered_at`, `delivery_attempts`,
  `delivery_failure_reason`, `tracking_url`, `otp_code`, `courier_id`,
  `mission_id`, full origin and destination with names, phones and
  coordinates, and items. Timestamps are UTC with `Z` in webhooks and `+04:00`
  in API responses, so we parse the offset.

### 1.7 Errors

Last-mile errors use `{api_error:{code,message,human,description,http_status_code,attribute_errors:[{detail}]}}`
plus the duplicate keys `error`, `errors[0].detail` and `error_detail`. A 401 is a bare
`{"error":"unauthorized"}`. **Trust the HTTP status**, not
`api_error.http_status_code`. An invalid `kind`/`payment_mode` gives **422
with every message empty**. `CanSubmitJobPolicy failed policy check` covers
commercial blocks (tier closed, demand, **hourly order limit reached**).
Malformed payloads can surface as **500**.

### 1.8 Tracking and driver location

`tracking_url` (`https://track-parcel.quiqup.com/<uuid>`) is "shareable and
unauthenticated. Anyone with the link can open it." **No API or webhook field
carries a courier's live position.** Only `courier_id` and `mission_id` are
sent. So **we build no map**. The verified customer gets a "Courier's live
view" link to Quiqup's own page. Whether that page shows a live map is not
documented. "Quiqup does not send customer tracking emails today."

### 1.9 What the WordPress plugin really does (and why we do not copy it)

The plugin makes **no Quiqup API calls at all**. On activation it:
1. inserts a WooCommerce REST key with **`read_write`** permissions for the
   current admin (`woocommerce_api_keys`, description "Quiqup Connector"),
2. base64-encodes `ck_…:cs_…` and **redirects the browser to
   `https://business-ae-beta.quiqup.com/integrations?token=<key>&shop_name=…&site_url=…&connector=woocommerce`**,
   so Quiqup's servers then **pull** orders from WooCommerce and write statuses
   back over the WC REST API,
3. adds a "National Address (Saudi Arabia)" checkout field (8 alphanumerics,
   `_billing_national_address`).

Not in the docs: the read/write store-key handover, which puts the key in a
URL and so in browser history and server logs, and the auto-pull model.
Delivery-method mapping happens in Quiqdash. **None of it applies to us:** we
have no WooCommerce REST API, and handing an outside party a read-write key to
our order database is the opposite of §5. We use the **direct API** route, which
is the one the docs recommend for a custom stack. It also gives us the per-order
control the owner asked for (select, then schedule) where the connector would
import every order automatically.

### 1.10 Not documented, so not assumed

- **No API rate limits are published.** The only limit named is the commercial
  "Hourly order limit reached". We self-limit (§7.3).
- **Maximum ids per label call and per cancel call** are not stated. We chunk
  labels at 25 and cancel **one order per call** (cancel is all-or-nothing).
- `scheduled_for` says "On a Last-mile account the order is released for
  collection when it is created". It is unclear whether that makes
  `scheduled_for` imply release. We send `ready_for_collection` explicitly
  either way and verify it on staging.
- Cancellation after a courier is assigned: one page says "moves to
  Quiqdash", the API reference says "has to be cancelled by Quiqup support".
  Our UI says "Courier already assigned: cancel it in Quiqdash or ask Quiqup
  support".
- Whether `track-parcel.quiqup.com` is in Arabic, or shows a map. To be
  checked on staging.
- Webhook source IPs are not published. That does not matter here (§5.2).

---

## 2. What already exists in this shop (reused, not rebuilt)

| Need | Already here | File |
|---|---|---|
| Order statuses + moving them safely | `OrderStatus::moveTo()` (locks, notes, stock, coupons, COD refusals, gift offers, emails via observer) | `app/Services/Orders/OrderStatus.php` |
| Order notes | `OrderNote` | `app/Models/OrderNote.php` |
| Status emails | `OrderStatusChanged` (shipped / completed / … wording; `TRACKABLE`) | `app/Mail/OrderStatusChanged.php` |
| Web push on status | `PushAutomations::orderStatus()` (rules `order_on`, `order_statuses`) | `app/Services/Push/PushAutomations.php` |
| Public track page, anti-oracle, throttled, signed links | `AccountController::track()`, `OrderLinks::trackUrl()/resolve()` | `app/Http/Controllers/Store/AccountController.php`, `app/Support/OrderLinks.php` |
| Account order page + 4-step stepper | `OrderProgress::steps()`, `partials/account/steps.blade.php` | `app/Support/OrderProgress.php` |
| EN+AR strings in one list | `AccountAreaStrings` pattern + Arabic-publish migration | `app/Services/Translation/AccountAreaStrings.php` |
| Encrypted credentials off the `settings` table | `MailCredential` / `PaymentProvider` (`encrypted:array`), `GatewayCredentials` | `app/Models/MailCredential.php` |
| Unauthenticated webhook route shape | `/api/payments/webhook/{gateway}/{secret}`, throttle, no body logging | `routes/payments-webhooks.php`, `app/Http/Controllers/Payments/WebhookController.php` |
| Firewall/BlockGate never look at webhooks | any URI containing `webhook` is exempt | `app/Services/Security/Firewall.php:527`, `app/Http/Middleware/BlockGate.php:209` |
| Orders list, selection bar, bulk endpoints | `olSelectionBar()`, `/admin-api/orders-list`, `orders-bulk-*` (flat paths) | `resources/views/admin/app.blade.php` ~14505, `routes/orders-admin.php` |
| Capabilities, fail-closed | `AdminCapabilities::CAPABILITIES` + `RULES`, unmapped = owner-only | `app/Support/AdminCapabilities.php` |
| Phone and emirate normalising | `UaePhone`, `OrderAddress::EMIRATES`, `orders.ship_emirate` | `app/Support/UaePhone.php`, `app/Support/OrderAddress.php` |
| Scheduler | cron installed (`schedule:run` every minute). Pattern: `everyMinute()->withoutOverlapping()->runInBackground()` | `routes/console.php` |
| Queue | **No worker on live.** `QUEUE_CONNECTION` defaults to `database` with nothing consuming it (HANDOVER §11.5, EMAILS-AUDIT). **We use no queued jobs.** Work is driven by the open admin page and/or the minute cron, the `CampaignSender` pattern. | `config/queue.php`, `docs/HANDOVER.md` |

**Nothing courier-related exists yet** (grep for quiqup, aramex, awb, shipment and
courier finds only email wording). `OrderStatusChanged` says today: *"No courier
reference exists in this shop and none is invented."* This plan adds the first
one.

---

## 3. Admin

### 3.1 Every admin path this adds

| Path | What |
|---|---|
| **Store → Orders → (tick orders) → Schedule with Quiqup…** | The schedule sheet (§3.2), a button in the existing selection bar |
| **Store → Orders → (tick orders) → Print… → Quiqup labels** | One PDF for the selection (a new option in the existing *Print…* select) |
| **Store → Orders → filter chips "Quiqup: not booked · booked · out for delivery · attempt failed · returned"** | Filters on `orders.ship_state`, no extra query |
| **Store → Orders → (an order) → Delivery card** | Quiqup number, tracking link, timeline, *Label*, *Cancel*, *Re-book*, *Refresh from Quiqup* |
| **Store → Quiqup → Connection** | Environment (Sandbox / Live), API key, webhook URL to paste, webhook signing secret, *Test connection*, last webhook seen |
| **Store → Quiqup → Pickup & defaults** | Pickup address, contact, default service per delivery method, parcels, release-on-create, notes policy, 4-hour collection time, cut-off times |
| **Store → Quiqup → Statuses** | Quiqup state → order status mapping; customer label EN/AR; email/push per state |
| **Store → Quiqup → Activity** | In-flight shipments, failures, webhook health, poll health |
| **Settings → (index card) Quiqup deliveries** | Card on the settings index pointing to the above |

### 3.2 The schedule sheet

A right-hand sheet built from the rows the list already holds, plus **one**
`POST /admin-api/quiqup-preview` that returns each order's prepared draft and
checks. **No request per keystroke.** Edits are local until *Schedule*.

**Top bar (applies to all, each row can override):** Service (from the
delivery-method mapping, else default), Parcels (1), *Release to courier now*
(on), 4-hour collection time (only shown for `partner_4hr`; 08:00–18:59
Dubai; future).

**One row per order:**

| Column | Filled from | Check (all local, as there is no serviceability API) |
|---|---|---|
| Order # / customer | `order_number`, shipping first+last | — |
| Phone | `UaePhone::e164(shipping phone ?? order phone)` | **Block** unless `UaePhone::isMobile()`. Inline fix. |
| Address | `line1` / `area + line2` / `city` | **Block** if `line1` empty. Warn if `city` empty. |
| Emirate | `ship_emirate`, mapped to Quiqup spelling | **Block** if unknown (select from 7) |
| Country | `shipping_address.country` | **Block** unless `AE`. Export is off until the owner enables it (Q4). |
| Payment | COD + unpaid → `paid_on_delivery`, amount = `(total − refunded_total)/100`. Paid (card/Tabby/Tamara/manual) → `pre_paid` | **Block** COD amount ≤ 1. **Block** non-COD that is not paid. |
| Status | `orders.status` | **Block** unless in `Order::REAL_STATUSES` minus `shipped`/`completed` (warn and allow if the owner ticks "already shipped") |
| Already booked | active shipment | **Skip**, showing its Quiqup # and link |
| Service | mapping by `orders.shipping_method` | Warn: *after today's cut-off, collected next cycle* (from settings) |
| Parcels | 1 | 1–10 |
| Courier note | `customer_note` trimmed to 250 chars, editable | **Never** `gift_note` (a private message) |

**Schedule N orders** sends the ids plus the per-row overrides to
`POST /admin-api/quiqup-schedule`, which **writes one `shipments` row per order
(state `queued`) in one transaction** and returns at once. The sheet then drives
`POST /admin-api/quiqup-step` (up to 5 creates per request, so each request
stays well inside PHP's time limit), painting each row as answers arrive:
- ✓ `Quiqup #10000001 · Track ↗ · Label`
- ✗ Quiqup's exact text: `HTTP 422 — [destination][address][address1] is missing`,
  with our explanation beside it for the known traps (empty-message 422 means
  "service type or payment mode refused"; `CanSubmitJobPolicy` lists the 5
  causes; 401 means "API key rejected or expired").

The loop stops when the queue is empty, **checks `ours()`** before painting
(the `#content` landmine in CLAUDE.md), and if the tab is closed the **minute
cron finishes the queue** (§4.4). No poll timer: every request is triggered by
the previous answer.

### 3.3 Cancel, re-book, labels

- **Cancel** (per order or for a selection): allowed only when the shipment
  `state ∈ {pending, ready_for_collection}`. One `PUT /orders/batch/set_cancelled`
  **per order** with `reason`. A refusal shows Quiqup's text. Our order's
  status is **not** changed: cancelling a delivery is not cancelling the sale.
- **Re-book** (wrong service, COD amount, parcels): cancel, then create with
  `generation + 1` (new deterministic uuid). It is only offered while
  cancellable. Otherwise: *"Courier already assigned: change it in Quiqdash"*
  with a link to the environment's dashboard.
- **Labels:** `GET /admin-api/quiqup-labels?ids=…` → `GET /order_label/a,b,c`
  in chunks of 25, **refusing a body that is empty or not `%PDF`** ("Quiqup
  returned an empty label for #123"). It is streamed to the browser and never
  stored. One chunk gives one PDF; several chunks give one PDF per chunk, since
  we merge nothing and add no dependency.

### 3.4 Settings (Store → Quiqup)

**Connection:** Environment `sandbox|live` (radio; a select stores only its own
options). API key (write-only field, shown as `••••a1b2` with *Replace*).
Webhook URL (read-only, built with `Url::external()`, copy button):
`https://kbeautybliss.com/api/shipping/quiqup/webhook/<url-secret>` (empty base
path on live). *Regenerate URL secret*. Webhook signing secret (write-only,
pasted from Quiqdash). **Test connection** runs
`GET /orders?page=1&per_page=1&from=<today>&to=<today>` and shows
`OK · staging · 0.4 s` or the exact error. **Last webhook received:** time and
state, or "never".
Switching environment requires re-entering key and secret (one pair per
environment is kept, so switching back does not lose them).

**Pickup & defaults:** pickup contact name, phone (E.164 validated), address1,
address2, town, emirate (select), notes. Service per delivery method (one row
per `shipping_method` title seen in the last 90 days, plus *Default*; select
from the enabled services the owner ticks). Default parcels. *Release to
courier on create* (on). Courier-note policy: *customer note / none*. 4-hour
default collection time. Cut-off time per service (informational warnings
only). *Send product list to Quiqup* (on; SKU, ≤18-char name, qty; sets
expanded to their members).

**Statuses:** one row per Quiqup state, with: *Our order status* (select from an
allowlist: `— no change —`, `shipped`, `completed`, `onhold`, `cancelled`).
Customer label EN, customer label AR (text, escaped, 60 chars). *Email the
customer* (checkbox). *Push* (checkbox). Defaults are in §6.2.

### 3.5 Capabilities (all fail closed via `AdminCapabilities::RULES`)

| Capability | Default roles | Covers |
|---|---|---|
| `quiqup.view` | owner, manager, support | Activity, shipment card, preview, refresh |
| `quiqup.ship` | owner, manager | schedule, step, cancel, re-book, labels (each one books a billed courier) |
| `quiqup.settings` | owner | Connection, Pickup & defaults, Statuses, Test |

Each admin route is mapped explicitly. A role without the capability gets the
console's 403. The buttons are hidden by `can()` and the server still refuses.

### 3.6 Admin endpoints (`routes/quiqup-admin.php`, flat paths like `orders-admin.php`)

```
GET  /admin-api/quiqup-settings         quiqup.view      (secrets → {filled, last4} only)
POST /admin-api/quiqup-settings         quiqup.settings
POST /admin-api/quiqup-test             quiqup.settings
POST /admin-api/quiqup-preview          quiqup.view      {ids[] ≤ 100}
POST /admin-api/quiqup-schedule         quiqup.ship      {ids[], defaults, rows{id: overrides}}
POST /admin-api/quiqup-step             quiqup.ship      {batch?}  → per-order results
POST /admin-api/quiqup-cancel           quiqup.ship      {order_id, reason}
POST /admin-api/quiqup-rebook           quiqup.ship      {order_id, overrides}
GET  /admin-api/quiqup-labels           quiqup.ship      ?ids=1,2,3   → application/pdf
GET  /admin-api/quiqup-shipment         quiqup.view      ?order=ID
POST /admin-api/quiqup-refresh          quiqup.view      {order_id}  (one GET /orders/{id})
GET  /admin-api/quiqup-activity         quiqup.view
```
Mounted by the integrator inside the existing `admin-api` group (auth:admin +
NoStoreAdminApi), and pinned with `substr_count(…) === 1`, not by asserting
absence.

---

## 4. Data model

### 4.1 Migrations

**`quiqup_config`** (1 row per environment; never the `settings` table, so the
settings map and `SettingsRequestMemo` are untouched)
```
id            string(16) PK   'sandbox' | 'live'
active        bool            exactly one true
secrets       text            encrypted:array  {api_key, webhook_secret, url_secret}
options       json            pickup, defaults, mappings, labels, notify flags (non-secret)
last_webhook_at timestamp null, last_poll_at timestamp null
timestamps
```

**`shipments`**
```
id                bigint PK
order_id          FK orders, index
provider          string(16) 'quiqup'
env               string(8)  'sandbox'|'live'
generation        smallint   0,1,2… (re-book)
request_uuid      uuid UNIQUE                     -- the idempotency key we send
active_key        bigint NULL UNIQUE              -- = order_id while not cancelled/failed-final; NULL otherwise
qq_id             bigint NULL UNIQUE              -- Quiqup order.id
qq_uuid           uuid NULL                       -- tracking uuid
tracking_url      string(255) NULL                -- only https://track-parcel.quiqup.com/… or track-ae.quiqup.com
service_kind      string(24)
payment_mode      string(20)
cod_fils          int NULL
parcels           tinyint
job               string(12)  queued|creating|created|failed|cancelled   -- OUR side
state             string(32) NULL                 -- Quiqup state, verbatim
state_at          timestamp(6) NULL               -- Quiqup state_updated_at (UTC)
delivery_after/before, collection_after/before  timestamp NULL
delivered_at      timestamp NULL
attempts          tinyint  default 0              -- delivery_attempts
last_reason       string(300) NULL                -- delivery_failure_reason, ADMIN ONLY
last_error        string(500) NULL, last_error_at timestamp NULL, tries tinyint
claimed_at        timestamp NULL                  -- create claim (conditional UPDATE)
next_poll_at      timestamp NULL, index
created_by        string(100)
timestamps
index (job), index (state, next_poll_at)
```

**`shipment_events`**
```
id, shipment_id FK index
state string(32), state_at timestamp(6)
source string(8) 'webhook'|'poll'|'api'|'admin'
attempts tinyint NULL, reason string(300) NULL (admin only)
body_sha256 char(64) NULL                          -- of the raw webhook body; the body is never stored
received_at timestamp, applied_at timestamp NULL   -- NULL = not yet applied → cron re-applies
UNIQUE (shipment_id, state, state_at)              -- the documented dedupe key
```

**`orders`**: two denormalised columns, added with a guarded `hasColumn` check
like `ship_emirate`: `ship_state string(32) NULL index` and `ship_state_at
timestamp NULL`. These let the Orders list, its chips, My account → Orders and
the email wording know the delivery stage **with zero extra queries**.

### 4.2 What is stored, and what never is

**Stored:** Quiqup ids, tracking uuid and url, service, payment mode, COD amount,
parcel count, state times, attempt count, failure reason (admin only, 300
chars), and a SHA-256 of each raw webhook body for audit.

**Never stored:** webhook bodies, courier ids, mission ids, **`otp_code`**, names,
phones, emails, addresses, coordinates and signatures from Quiqup payloads.
They are already on our order or are not ours to keep. Never logged: the API
key, webhook secrets, request or response bodies. Logs carry order id, Quiqup
id, HTTP status and Quiqup's error code and message. Phones in an error
message are masked the same way the payment logs mask them.

**Retention:** shipments and events live as long as their order (a few rows
each). Soft-deleted orders keep them. There is no separate prune, because the
volume is about 10 rows per order.

### 4.3 Idempotency, end to end

- `request_uuid = UUIDv5(ns = HMAC(APP_KEY,'quiqup-ns'), "{env}:{order_id}:{generation}")`.
  This is deterministic: a double click, two admins, the page and the cron
  racing, or a timeout all resend **the same uuid**, so Quiqup answers 200 with
  the original order.
- Locally, `active_key UNIQUE` means one live shipment per order at the
  database level. Scheduling an already-booked order is a no-op that returns
  the existing one.
- The create claim is `UPDATE shipments SET job='creating', claimed_at=now
  WHERE id=? AND (job='queued' OR (job='creating' AND claimed_at < now-120s))`.
  Exactly one worker sends. A stale claim is resent with the same uuid, which
  is safe.
- Webhooks: `UNIQUE (shipment_id, state, state_at)` plus "apply only if
  `state_at` > `shipments.state_at`".

### 4.4 /api exposure

**None.** No shipment or event model gets a `toApi()`, and nothing goes under
`/api/*` except the webhook receiver, which returns `{"result":"ok"|"rejected"}`
and nothing else. The one public JSON is the signed tracking refresh (§6.4),
built from an explicit allowlist. `ApiSecurityTest` gains cases that assert
neither table's columns appear in any `/api` response.

---

## 5. Security

### 5.1 Secrets
API key and both webhook secrets live in `quiqup_config.secrets`
(`encrypted:array`, the `MailCredential` precedent). They are write-only in the
admin (`{filled:true,last4}`), never sent to the browser, never in the
`settings` table or `/api/settings`, and never in logs or exception context.
The base URL comes from the environment row (`sandbox` → `api.staging.quiqup.com`,
`live` → `api-ae.quiqup.com`): **two constants, no host from a setting, so
there is no SSRF surface.** The tracking URL is accepted only if it matches
`^https://track-(parcel|ae)\.quiqup\.com/[A-Za-z0-9-]{16,64}$`. Anything else
is dropped and the link is not shown.

### 5.2 Webhook receiver (`routes/quiqup-webhooks.php`, required from `routes/api.php`, no CSRF)

`POST /api/shipping/quiqup/webhook/{secret}`, `where secret [A-Za-z0-9_-]{32,64}`,
`throttle:600,1`. A 429 is something Quiqup retries, so throttling never loses
an event outright. The path contains `webhook`, so Firewall/BlockGate exemptions
already apply.

Order of checks (each failure is answered with the same minimal body; reasons
go only to our log):
1. URL secret `hash_equals` against the **active** environment's `url_secret`
   → else **404** (indistinguishable from no route).
2. `Content-Length` ≤ 256 KB → else 413. Read `$request->getContent()` once,
   raw.
3. `X-Quiqup-Timestamp` is digits and within ±300 s of our clock → else 401.
4. `X-Quiqup-Signature` is 64 hex characters, and
   `hash_equals(hash_hmac('sha256', "$ts.$raw", $secret), $sig)` → else 401.
   Accept the *previous* signing secret for 24 h after a rotation.
5. `json_decode`; `type==='order'`, `action==='update'`, `payload.id` int,
   `payload.state` matches `^[a-z_]{3,32}$`, and `state_updated_at` parses →
   else 200 *ignored* (never make Quiqup retry junk).
6. Find the shipment by `qq_id` (unique index), falling back to `qq_uuid` or
   `request_uuid`. Unknown means an order made by hand in Quiqdash: 200
   *ignored*, counted on Activity.
7. **Durable write first:** `INSERT … shipment_events` (duplicate → done,
   200). Then, in the same request, apply it (§6.1). This is a handful of
   indexed queries, far inside Quiqup's 15 s.
8. Side effects (status emails and push sent by `OrderStatus::moveTo`'s
   observers) run **after the response** (`dispatch(...)->afterResponse()`,
   `fastcgi_finish_request` under PHP-FPM). If the apply throws, the event row
   keeps `applied_at = NULL`, we still answer **200**, and the minute cron
   re-applies it. A 5xx is returned only when the event could not be stored at
   all (database down), which is exactly when we want Quiqup to retry.

Replay protection = timestamp window plus the unique event key. The legacy
`x-signature` is never read.

### 5.3 Public tracking
It reuses `AccountController::track()` unchanged in shape: one answer for a
wrong number or a wrong email, `hash_equals` on every attempt, and the
IP+email throttle (10 per 10 min). The signed `?t=` link from emails keeps
working. The tracking refresh endpoint takes **only** the signed token (never
number + email), so it cannot be used to probe.

### 5.4 Audit
Every schedule, cancel, re-book, label download, settings save, environment
switch and secret replacement writes an `OrderNote` (per order, with the admin's
name) and, for settings, an `AuditEvent` through `SecurityModule` (owner
visible under `security.view`). Notes say what happened, e.g. *"Booked with
Quiqup (Next Day, COD AED 189.00, 1 parcel) — Quiqup #10000001 — by Rafi"*.
Webhook-driven moves write a system note: *"Quiqup: Out for delivery
(14:05)"*.

---

## 6. Live status sync

### 6.1 Applying an event (one place: `App\Services\Quiqup\ShipmentSync::apply()`)

```
if event.state_at <= shipment.state_at        → store only (late/out-of-order), done
shipment.state, state_at, attempts, windows, delivered_at, last_reason  ← event
orders.ship_state, ship_state_at              ← event          (same transaction)
target = mapping[event.state]                 (Statuses screen)
if target and forward(order.status → target) → OrderStatus::moveTo(order, target, 'Quiqup', note)
mark event applied_at
```
`forward()` never moves an order backwards: `processing/onhold → shipped →
completed`. `cancelled` is allowed only from `shipped`, if the owner maps it.
`refunded`, `cancelled`, `failed` and `draft` orders are never touched. A
`collection_failed → ready_for_collection` or `delivery_failed → at_depot` loop
updates the timeline but never the order status. Unknown states are stored
and shown to the admin as raw text, with no status change and no customer
step.

### 6.2 Default mapping (shipped ON per CLAUDE.md "what he asked for ships on"; all editable)

| Quiqup `state` | Our order status | Customer step (EN) | Customer step (AR) | Email / Push default |
|---|---|---|---|---|
| `pending` | — | Booked with courier | تم حجز الشحن | off / off |
| `ready_for_collection` | — | Booked with courier | تم حجز الشحن | off / off |
| `out_for_collection` | — | Courier on the way to collect | المندوب في طريقه لاستلام الطلب | off / off |
| `collection_failed` | — | *(admin alert only)* | — | off / off |
| `collected` | **shipped** | Picked up by courier | تم الاستلام من المندوب | existing *shipped* email (as today) / push off |
| `received_at_depot`, `at_depot` | — | At the delivery hub | في مركز التوزيع | off / off |
| `in_transit` | — | On the way to your emirate | في الطريق إلى إمارتك | off / off |
| `scheduled` | — | Delivery scheduled *(+ window)* | تم تحديد موعد التوصيل | off / off |
| `out_for_delivery` | — | Out for delivery | خرج للتوصيل | off / **on** (if push is set up) |
| `delivery_failed` | — | Delivery attempt {n} missed, we will try again | تعذّر التوصيل (المحاولة {n})، سنحاول مجدداً | off / **on** |
| `on_hold` | — | Delayed | متأخر | off / off |
| `delivery_complete` | **completed** | Delivered | تم التوصيل | existing *completed* email / push off |
| `return_to_origin`, `out_for_return` | — | Returning to us | قيد الإرجاع إلينا | off / off |
| `returned_to_origin` | — *(see Q3)* | Returned to us | أُعيد إلينا | off / off |
| `cancelled` | — | *(courier steps hidden; order shows as before)* | — | off / off |
| `error`, unknown | — | *(nothing)* | — | off / off |

The Arabic wording is a first draft for the owner's review, published through
the `AccountAreaStrings` mechanism (a `ShipmentTrackingStrings` class plus an
Arabic-publish migration). The free-text failure reason is **never** shown to
customers, because it can carry a courier's notes about the person.

**Owner-visible consequences of `collected → shipped`, stated plainly:** it is
the same move he makes by hand today, so it sends the existing "on its way"
email and push, and Tamara orders become eligible for the existing hourly
capture sweep (`TamaraCaptureSweep::FULFILLED`) once the goods have actually
left. No payment code changes. This is the documented meaning of
"shipped". Q2 confirms it.

### 6.3 Reconciliation poll (backup, never primary)

`php artisan kbb:quiqup-sync` with
`->everyMinute()->withoutOverlapping(5)->runInBackground()`. When there is
nothing to do it costs one indexed query a minute. Each tick, in order:
1. **Re-apply** `shipment_events` with `applied_at IS NULL` (≤ 50).
2. **Finish queued creates** left by a closed tab (`job=queued`, or `creating`
   with a stale claim), up to 5 per tick.
3. **Poll due shipments**: `next_poll_at <= now AND state NOT IN (end states)`.
   This is one `GET /orders?filters[state]=…&from=<oldest due>&to=<today>&per_page=100`
   page, which returns `state`, `state_updated_at` and `delivery_attempts` for up
   to 100 orders in one call. It falls back to `GET /orders/{id}` for `pending`
   ones (the list hides them unless asked). The result goes through the same
   `apply()` with `source=poll`.

Cadence (`next_poll_at`): out_for_delivery / out_for_collection every 10 min;
other in-flight states every 30 min; pending every 60 min. It **doubles** while
webhooks are healthy (one received in the last 2 h) and stops at an end state
or after 30 days. On API error it backs off ×2 up to 2 h. **Ceiling: 30 API
calls per hour**, whatever the backlog. A poll that skips intermediate states
(for example `at_depot` straight to `delivery_complete`) adds only the states
it saw. The customer timeline fills the skipped stages as *done* without a
time rather than inventing one.

**Webhook health** on Store → Quiqup → Activity: an amber banner if shipments are
in flight and no webhook has arrived for 6 h. That is the sign the Quiqdash
subscription was **paused** after 50 failures, which Quiqup never tells anyone.
The banner text gives the fix: Quiqdash → Integrations → Webhooks → Edit →
Active → Save.

### 6.4 Live updates for the shopper: the choice and why

| Option | Verdict |
|---|---|
| **Server-Sent Events / WebSocket** | **Rejected.** Each open tab holds a PHP-FPM worker for as long as it is open. On Cloudways' fixed worker pool, 30 customers watching "out for delivery" would starve the shop, which breaks the speed freeze. No WebSocket server exists, and adding one is a new dependency. |
| **Polling every N seconds** | **Rejected.** That is "a timer that never stops" (CLAUDE.md). |
| **Web Push** (already built: Site App + `PushAutomations`) | **Used** for the moments that matter (out for delivery, missed attempt), for customers who opted in. True push, zero page cost. |
| **Refresh when the shopper comes back** | **Used.** A ~0.8 KB same-origin deferred file `kbb-track-live.js`, loaded **only** on the track page and the account order page, and **only** while a shipment is in flight. On `visibilitychange → visible` and `online` it makes one conditional `GET` (ETag → **304**, about 200 bytes) at most once per 60 s, and swaps the timeline `<ol>` from the returned allowlisted JSON. While the tab stays visible it makes at most **6** checks, 5 min apart (30 min), then stops for good. It stops at once on an end state. It never measures layout. |

The refresh endpoints: `GET /track-my-order/live?order=&t=<signed>` (guest,
signed token only) and `GET /my-account/orders/{id}/live` (session, own
orders). Both return
`{stage, label, at_local, window_local, attempts, ended, track_url?}` and
nothing else, with `Cache-Control: private, no-cache` and an ETag of
`ship_state_at`. They are added to InstantNav's exclusions by construction
(query string, account path) and are never prefetched. This is a new script
on two non-shop pages. CLAUDE.md requires the owner's OK, and **Q5 asks for
it**. Without it, the page is fresh on every load and Web Push carries the live
moments.

---

## 7. Customer side

### 7.1 Timeline ("road map")

A new partial `partials/account/delivery-timeline.blade.php`, rendered in
place of the 4-step `steps` partial **only when the order has a shipment**.
Orders with no shipment render byte-identical (`StorefrontEnglishUnchangedTest`
stays green). An ordered list (screen readers hear the sequence), CSS-only,
logical properties so RTL mirrors itself, inline SVG icons with
width/height attributes, no images.

```
✓ Placed               12 Nov, 10:14
✓ Confirmed            12 Nov, 10:15
✓ Booked with courier  12 Nov, 16:02
✓ Picked up            13 Nov, 09:40
✓ At the delivery hub  13 Nov, 11:05
! Delivery attempt 1 missed — we will try again   13 Nov, 17:30
● Out for delivery     14 Nov, 08:55   Expected by 15:00
○ Delivered
                       [ Courier's live view ↗ ]   (Quiqup tracking page, new tab, rel=noopener noreferrer)
```
Times are shown in **Asia/Dubai** (the shop's time-zone setting), from UTC in
the database. The expected window comes from `delivery_before`, shown only when
present and in the future. Side steps (missed attempt, delayed, returning,
returned) are inserted where they happened, in amber or red. Returned ends
the list.

### 7.2 Where it appears
- **/track-my-order** (number + email form, or the signed email link): the
  timeline under the existing status card. The same anti-oracle rules apply.
  The tracking link is shown only after verification.
- **My account → Orders → (order)**: the same partial (`orderDetail()` loads
  the shipment and its events in **one query, and only if
  `orders.ship_state` is not null**).
- **My account → Orders (list)**: the row's status chip reads the delivery stage
  ("Out for delivery") from `orders.ship_state`, **zero extra queries**.
- **Emails**: the existing shipped/completed emails gain one line, "Track your
  delivery", linking to our signed track page (never Quiqup's URL directly),
  so the customer stays on our site. Optional extra emails per state stay
  **off** unless the owner ticks them.
- WhatsApp: the shop has no WhatsApp sending API (only a chat button), so it
  is **out of scope**. `ShipmentSync` fires an internal event
  `ShipmentStateChanged`, which is the hook point if one is added later.

---

## 8. Speed (frozen rules respected)

- **Shop pages (home, category, shop, brand, product, blog): zero change.** No
  new query, no settings read (Quiqup config is not in `settings`), no script,
  no CSS in kbb.css. Header and logo files are untouched.
- Account order page: **+1 query only for orders with a shipment**
  (budget 7 stays for the seeded order without one; a second budget case with a
  shipment is set at **8**, raised deliberately and flagged to the integrator).
  Track page: same (5, or **6** with a shipment). Account orders list: **+0**.
- Flat cost: the account orders list is checked with 3 and 40 orders, same
  query count (`BrandPageOwnerAsksTest` method).
- Admin: the Orders list adds two columns read by the existing query (+0). The
  sheet's preview is one request for the whole selection, with eager-loaded
  items (no N+1, asserted with 3 vs 40 orders).
- The webhook request is a handful of indexed queries with no outbound HTTP
  call. The cron does nothing when nothing is due.
- Before/after numbers (server ms, queries, settings-map reads) for product,
  category and brand pages are reported in every package, as CLAUDE.md
  requires. They must be identical.

---

## 9. Failure handling and operations

| Situation | What happens |
|---|---|
| Quiqup down or timeout on create | Row shows "Quiqup did not answer, will retry". `tries++`, retried by the page or cron with the **same uuid** at 1 → 2 → 5 → 10 → 30 min, then `job=failed` with the last error. Never a duplicate. |
| Partial batch failure | Each order is independent (there is no batch create). Successes stay, failures show their reason, *Retry failed* re-queues only those. |
| 202 `x-projection-pending` on release | Not retried. The next poll or webhook confirms. |
| `ready_for_collection: true` but validation errors | Quiqup leaves it `pending`. The poll sees `pending` 15 min later and the Activity screen flags *"Still pending at Quiqup: open in Quiqdash"*. |
| Wrong address or phone found later | No API change is possible. The card says "Fix in Quiqdash" (link), or *Re-book* while it is still cancellable. |
| Webhook missed / subscription paused | The poll catches up within 10–30 min, plus the health banner (§6.3). |
| Out-of-order / duplicate events | Unique key, plus the `state_at` comparison. Late events are stored for the history but do not move the current state. |
| Status loops (failed → depot) | Timeline only. The order status never regresses. |
| API key expired / rotated (401) | Every call stops with "Quiqup rejected the API key" on the sheet, the Activity screen and laravel.log at **ERROR** (live runs `LOG_LEVEL=error`). |
| `CanSubmitJobPolicy failed policy check` | Shown with the docs' five causes, and which ones a retry can fix. |
| Same Day not enabled | Quiqup's 422 text, with "ask your account manager". The sheet also limits the service select to the services the owner ticked as enabled. |
| Time zones | DB in UTC. `state_updated_at` is parsed with its offset (`Z` or `+04:00`). `scheduled_for` is sent in UTC with `Z` and validated as 08:00–18:59 Dubai. Shown in Asia/Dubai. |
| Wrong environment | The environment is stored per config row and shown as a coloured **SANDBOX** pill on the sheet, the card and every note. Live needs its own key, so a sandbox key cannot book a real courier by accident. |
| Disk / DB intermittents | See CLAUDE.md's `df -h /` rule before debugging any flaky event insert. |

**Ops runbook** (to `docs/HANDOVER.md` at the end): where to see failures (Store →
Quiqup → Activity), how to re-activate a paused subscription, how to rotate the
key or secret, `php artisan kbb:quiqup-sync --dry` to see what is due, and
`kbb:quiqup-sync --order=123` to force one refresh.

---

## 10. Tests

**Fakes:** `Http::fake()` against both hosts (67 test files already use it), plus
a `tests/Support/QuiqupFake.php` that records requests and serves the documented
bodies. **No network in tests.**

**Contract tests (fixtures copied verbatim from the docs, under `tests/Fixtures/quiqup/`):**
create response (201 and the 200 replay), the full `ready_for_collection`
webhook body from §1.6, the `api_error` 422 envelope, the empty-message 422,
the bare 401, `CanSubmitJobPolicy`, the 202 projection-pending answer, the list
response, and the zero-byte label. Our payload builder is asserted **key by
key** against the documented field names. Unknown fields are ignored upstream,
so a typo would otherwise pass silently. Mutation note: rename `contact_phone`
→ `phone` and the contract test goes red.

**Feature tests (each names the defect it prevents):**
- `QuiqupScheduleTest`: builds the payload from a COD, a card-paid and a gift
  order (no `gift_note` sent); blocks a bad phone, a non-AE country, an unpaid
  card order, COD ≤ 1 and a cancelled order; skips an already-booked order.
- `QuiqupIdempotencyTest`: double click, page and cron racing, and a timeout
  then retry all send one uuid and create one shipment. `active_key` refuses a
  second active shipment. Re-book uses `generation+1`.
- `QuiqupWebhookTest`: good signature → 200 and applied; wrong URL secret →
  404; bad signature, stale timestamp (±301 s) or a missing header → 401;
  replayed body → deduped; out-of-order older event → stored, not applied; an
  unknown order → 200 ignored; a body over 256 KB → 413; the apply throwing
  still gives 200, `applied_at` NULL, then the cron applies it; the response body
  is identical across rejections; nothing about the body in logs.
- `QuiqupStatusMappingTest`: `collected → shipped`, `delivery_complete →
  completed`, never backwards, never touches refunded or cancelled orders, a
  failure loop is not a status change, an unknown state does nothing.
- `QuiqupSyncCommandTest`: the poll cadence, the 30 calls/h ceiling, backoff,
  stopping at end states, pending orders read by id, and one query when idle.
- `QuiqupLabelsTest`: refuses a zero-byte or non-`%PDF` body, chunks at 25.
- `QuiqupCancelTest`: one order per call, refused after `out_for_collection`,
  our order status unchanged.
- `QuiqupAdminCapabilitiesTest`: every route mapped; support cannot ship;
  editor sees nothing; settings never return a secret.
- `QuiqupTrackingPageTest`: wrong email equals wrong number (same body, same
  status); the signed refresh accepts only a token; the JSON contains only the
  allowlisted keys (no reason, phone, courier or otp); ETag gives 304.
- `QuiqupApiExposureTest` (in the `ApiSecurityTest` family): no shipment
  column is reachable from `/api/*`.
- `StorefrontEnglishUnchangedTest` and `StorefrontQueryBudgetTest`: green with no
  shipment; the new budget case is 8 with one.
- `DeliveryTimelineRtlTest`: Arabic labels are present, there is no
  hard-coded left/right, and every `<svg>` carries width/height.
- Wiring pins: `substr_count(web.php/api.php, "require …quiqup-*.php") === 1`,
  and the screen partial is included exactly once.

**Playwright (`tools/qq-shots.cjs`, Chromium, 390 and 1280, output in
`docs/lane-qq-shots/`):**
Orders list with selection and the new button; the sheet with blockers, during
scheduling and with results; Delivery card; Store → Quiqup's 4 tabs; track page
EN and AR at each stage (booked, out for delivery, missed attempt, delivered,
returned); account order page EN and AR; `scrollWidth == width` everywhere; the
tracking link answering a real click (`elementFromPoint`); no console errors;
product, category and brand pages unchanged byte for byte, with the same
request count.

---

## 11. Rollout

### 11.1 Phases and packages

| # | Package (built with `php artisan kbb:package <v> --since=<ref>`) | Ships | Customer sees | Owner does |
|---|---|---|---|---|
| **P1** | *Quiqup foundation* | `quiqup_config`, `shipments`, `shipment_events`, `orders.ship_state*` migrations; `QuiqupClient` (two fixed hosts, timeouts 10 s, error decoding); webhook receiver; `kbb:quiqup-sync` (idle until configured); **Store → Quiqup → Connection / Pickup & defaults / Statuses / Activity**; capabilities; `clear_caches_quiqup_*` migration | nothing | pastes the **staging** key, signing secret and pickup address; registers the webhook URL in **staging** Quiqdash with "All order events"; presses *Test connection* and *Send test* |
| **P2** | *Schedule from Orders* | the bulk sheet, preview/schedule/step, labels, cancel/re-book, Delivery card, list chips | nothing | books 5 staging orders (the docs' 12-scenario test pass §11.2) and drives them through states with Quiqdash's *Bulk Change State* |
| **P3** | *Live delivery timeline* | `ShipmentSync` mapping to order statuses, the timeline partial (track + account, EN/AR, RTL), email "Track your delivery" line, push hooks, `kbb-track-live.js` (if Q5 = yes), Arabic-publish migration | the timeline, on staging-booked orders only | checks the wording EN/AR; switches **Live** on with the production key/secret and production webhook; books one real order with Quiqup ops watching |
| **P4** | *Hardening (after a week live)* | whatever the first live week shows; `docs/HANDOVER.md` runbook; tune poll cadence from real webhook reliability | — | — |

**Lanes:** at most three. Round 1: **QA** (backend: client, tables, webhook,
sync) and **QB** (Store → Quiqup screens), giving P1. Round 2: QB (bulk sheet,
labels, card) and **QC** (timeline, strings, track/account), giving P2. Round
3: QA+QC (mapping, notifications, live script, shots), giving P3. Route and
partial wiring stays with the integrator.

### 11.2 Staging acceptance (mirrors the docs' "complete test pass")
Next Day prepaid · Next Day COD (amount > 1) · Same Day · create then cancel ·
release three · download each AWB (non-empty PDF) · retrieve one · drive one to
`delivery_complete` (webhook at each step, timeline matches) · drive one to
`delivery_failed` then `at_depot` then delivered · replay a create with the same
uuid (200, no duplicate) · pause the webhook subscription, move an order, see
the poll catch up and the health banner show.

### 11.3 Going live checklist
Production services enabled · production key ("Never" expiry, named
"kbeautybliss live") · **production webhook registered** (staging does not carry
over) · environment switched to Live in Store → Quiqup → Connection · one real
order created, released, collected and delivered before the first batch.

---

## 12. Open questions for the owner (only what blocks)

1. **Credentials and pickup:** staging API key (expiry *Never*), the webhook
   signing secret after you add our URL, your pickup address and the courier
   contact name and phone. Which services are on: Same Day / Next Day / 4-hour?
2. **Auto-status:** should *picked up* make the order **Shipped** and
   *delivered* make it **Completed** automatically? (Default yes. It sends the
   same emails as when you do it by hand, and lets Tamara capture after
   pickup.)
3. **Returned to you:** when Quiqup marks a parcel *returned to origin*, should
   the order become **Cancelled** automatically? For COD, that also records the
   customer as a refused delivery and hides COD from them next time (your
   1 November rule). Default: **no change, flag it for you**.
4. **Outside the UAE:** the shop also sells to other GCC countries. Quiqup can
   ship there only with export enabled, and needs HS codes, weights and sizes
   per product, which we do not hold. Keep Quiqup **UAE-only** for now?
   (Default yes.)
5. **Live refresh on the tracking page:** may the track page and account order
   page load one tiny new script (under 1 KB, only while a parcel is moving) so
   the timeline updates when the customer looks at it again? Without it, the
   page is fresh on every visit and phone notifications carry the live moments.
   (Recommended yes.)

Not blocking (sensible defaults, changeable later): release to courier
immediately on booking (yes) · send the customer's order note to the courier
(yes, never the gift message) · send the product list (yes) · who may book
(owner and managers).

---

## 13. Not confirmed from the docs (stated, not guessed)

- API rate limits: not published.
- Maximum ids per `GET /order_label/…` and per cancel call: not published.
- Whether `scheduled_for` on a 4-hour order also releases it: wording is
  ambiguous. We release explicitly.
- Whether Quiqup's tracking page shows a live courier map, or Arabic: not
  documented. Checked on staging. No position data exists in the API.
- Who can cancel after courier assignment (Quiqdash or support): the two pages
  disagree.
- Whether `ready_for_collection` fires a webhook: the docs now say it does
  ("Live delivery logs show it delivered with a 200"). Tested on staging.
- Same Day/4-hour cut-off times: account-specific, not in the API. The owner
  enters them.
