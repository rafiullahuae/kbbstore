# Troubleshooting

**Applies to last-mile.** Start from the symptom. Each table says which integration route it applies to. The same symptom has a different cause on each.

Fulfilment symptoms are not covered here yet. Send those to [apisupport@quiqup.com](mailto:apisupport@quiqup.com) with the request, the response and a UTC timestamp.

## My orders are not reaching Quiqup

**Connector (Shopify, WooCommerce).** Go to [Integration Orders](/docs/lastmile/integration-orders). It lists every order from your shop with the reason it did not sync. Do this before anything else.

**Direct API.** There is no intermediate stage. If the API returned 2xx, the order exists. Work through this instead:

| Symptom | Most likely cause | Where to go |
|  --- | --- | --- |
| 2xx and an order id, the order sits under **Pending** in Quiqdash, and nothing happens | `ready_for_collection` was never called. | [Order lifecycle](/docs/order-lifecycle#create-then-make-it-ready) |
| No courier assigned to API orders, but manual orders get one | Same as above. Pending orders are invisible to dispatch. | [Order lifecycle](/docs/order-lifecycle#create-then-make-it-ready) |
| 2xx and an order id, but nothing in Quiqdash at all | Wrong environment, the dashboard user belongs to a different account, or the date range excludes the order. Pending orders are listed, so it is not that. | [Environments](/docs/environments#where-your-orders-appear) |
| Accepted, then never moved | Not a rejection: an accepted order stays in the state it was in. If `ready_for_collection` returned `422`, the release was refused and the error names why, usually an invalid UAE dropoff phone or an unresolvable address. Fix it and release again. | [Addresses](#addresses-and-contact-details) |
| Nothing at all, on every call | Wrong environment, or the dashboard user does not belong to the token's account | [Environments](/docs/environments#where-your-orders-appear) |


## It was working, then it stopped

**Connector.** Something broke the connection: an expired token between the platforms, regenerated store API keys, edited connector settings, or a changed rate set that no longer matches the mapping. Nobody is notified. [What to check](/docs/lastmile/integration-orders#when-a-working-connection-stops).

**Direct API.** Nothing exists to expire on the connection itself. Four causes: your token expired and the refresh failed, credentials were rotated, an account capability was switched off, or your own deploy changed the payload. Start with [Authentication errors](/docs/authentication#authentication-errors).

## Orders

*Direct API.*

| Symptom | Cause | Where to go |
|  --- | --- | --- |
| One order produced two or three AWBs | A retry without a `uuid`. `partner_order_id` does not enforce uniqueness. | [Retrying safely](/docs/lastmile/create-an-order#retrying-safely) |
| The order shows the wrong emirate | If you sent `coords`, check the order: it is `[longitude, latitude]`. If you did not, the address text did not resolve where you expected. | [Addresses](#addresses-and-contact-details) |
| The order went out on the wrong service | `kind` is fixed at creation. Cancel and re-book. | [Service types](/docs/order-lifecycle#service-types) |
| I need to change an address after creation | Not possible through the API at any point. Edit in Quiqdash. | [Every operation](/docs/lastmile/create-an-order#every-operation) |


*Connector.* A wrong service almost always means a wrong delivery-method mapping. [Fixing the mapping](/docs/lastmile/integration-orders#common-reasons-and-what-to-change)

## Authentication

*Direct API only. Connectors handle their own authentication.*

| Symptom | Cause |
|  --- | --- |
| `invalid_client` | Swapped id and secret, or credentials used against the wrong host |
| `401 Unauthorized` | An expired key, or you sent the `client_secret` instead of the exchanged `access_token`. The body is a bare `{"error":"unauthorized"}`, not the usual `api_error` envelope, because the request is rejected before the payload is read. Check the **Expiration** on the key first: a duration was almost certainly picked instead of Never. [Generating a key](/docs/authentication#option-1-generate-a-token-in-quiqdash) |
| `308` then a failure | Trailing-slash redirect dropped the POST body |
| Generic 500 or timeout on every call | The account is not enabled for API access |
| `You are not authorized` with a valid token | An account capability is off, not a token problem |


Full table in [Authentication](/docs/authentication#authentication-errors).

## Webhooks

*Direct API. Connector webhooks are created and owned by the connector.*

| Symptom | Cause |
|  --- | --- |
| No callbacks at all on the first live order | The production subscription was never registered. Staging does not carry over. |
| Callbacks stopped | Repeated failures paused the subscription. The Subscriptions tab shows it as **Paused** with a failure count. [Re-activating it](/docs/webhooks#paused-subscriptions) |
| Some events arrive, some do not | The missing events are not selected on the subscription, or your receiver returned 2xx and dropped them. Check the subscription's **Events**, then the delivery log. |
| I need to know why a delivery failed | Delivery logs, on the Webhooks screen. Status, attempts, HTTP code and error per attempt, with replay. |
| I missed events and need to catch up | Replay the **Failed** entries from the delivery log. For anything else, `GET /orders/{order_id}` returns the current `state`. The published API does not provide an order's state history, so if you need every transition, record each webhook as it arrives, or contact Quiqup support about a specific order. |


Full checklist in [Webhooks](/docs/webhooks#not-receiving-webhooks).

## Addresses and contact details

*Both routes. On a connector these come from your shop's checkout fields, so fix them there.*

The request fields are `address1`, `address2`, `coords`, `town` and `country`, a two-letter ISO code ([Country codes](/docs/lastmile/create-an-order#country-codes)). **There is no postcode or emirate field.** Responses and webhooks use a different shape, `coordinates: {lat, lng}`, so do not expect what you sent back verbatim. Full table in [Create an order](/docs/lastmile/create-an-order#address-object).

| Field | Rule |
|  --- | --- |
| `address1` / `address2` | Latin script. Arabic addresses are not interpreted. |
| `coords` | `[longitude, latitude]`. Dubai is `[55.16…, 25.05…]`, larger number first. Reversing them misroutes the order. |
| `contact_phone` | E.164 with a single `+`. See [Contact fields](/docs/lastmile/create-an-order#contact-fields). |


**Pickup address works differently on each route.** A connector reads one default pickup location off the account. The API takes a full pickup address on every order and never falls back to an account default, which is what makes multi-warehouse pickup an API-only capability.

The customer's phone number is not printed on the label, for privacy.

## Labels

*The first two rows apply to both routes. The rest are direct API, since connectors build the payload for you.*

| Symptom | Cause |
|  --- | --- |
| No PDF after creating the order | The order is not accepted yet, or the export service is not activated. Check the path too: the endpoint is `GET /order_label/{order_id}`. |
| The PDF downloads but is empty | The order id does not exist. The endpoint returns `200 application/pdf` with a zero-byte body rather than a 404. [Return values](/docs/lastmile/create-an-order#return-values-that-will-catch-you-out) |
| CORS error fetching a label | Fetch the label server-side, not from the storefront. |
| The label shows a different address than I sent | The label is a snapshot. Address edits after generation require regenerating it. |
| Too many labels, or too many bags | You sent each product as its own item. An item is a **parcel**. The goods go in `products`, which is a separate top-level list. [Parcels and products](/docs/lastmile/create-an-order#parcels-and-products) |
| The response shows 5 kg and 10 x 10 x 10 cm per parcel | Those are defaults, echoed on every item whatever you sent. Weight and dimensions belong on the **product**, not the parcel. [Product fields](/docs/lastmile/create-an-order#product-fields) |
| An export order is refused at `ready_for_collection` with a `422` | The message names each missing field. [What an export needs](/docs/lastmile/create-an-order#export-orders) |


## Payloads that are accepted and then go wrong

*Direct API.*

| Symptom | Cause |
|  --- | --- |
| A field I set had no effect | Unknown fields are accepted and ignored, so a misspelled name looks the same as one that did nothing. Read the order back and check the value. [Return values](/docs/lastmile/create-an-order#return-values-that-will-catch-you-out) |
| Orders erroring with no obvious cause | Neither a missing product `sku` nor a long item `name` is rejected at create, so look at what happened after acceptance rather than at the payload. [Webhooks](/docs/webhooks) |
| An order for the wrong country or emirate was accepted | Neither is validated at create. A destination country different from the pickup country also turns the order into an export. [Country codes](/docs/lastmile/create-an-order#country-codes) |


## Timeouts and retries

*Direct API.*

A retry after a timeout is what produces duplicate orders, unless the create carried a `uuid`. Send one and the retry returns the original order with HTTP 200, and no second order is created. A malformed `uuid`, or one another account already used, is rejected with 422.

`GET /orders` leaves `pending` orders out unless `filters[state]=pending` asks for them, so a search by `filters[partner_order_id]` alone cannot tell you whether a timed-out create landed. Send both filters together. See [Retrying safely](/docs/lastmile/create-an-order#retrying-safely).

Retrying `PUT /orders/{order_id}/ready_for_collection` is not obviously safe. Confirm the order's `state` with `GET /orders/{order_id}` rather than assuming a repeat is harmless.

## 5xx does not always mean Quiqup

*Direct API.*

Malformed payloads can surface as 500 rather than 422. See [5xx](/docs/errors#5xx). Check your request shape before assuming an outage.

If several of your orders failed in the same window, that is the signal for a platform issue. Report it with timestamps.