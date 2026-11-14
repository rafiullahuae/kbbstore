# Fulfilment orders

**Applies to the Fulfilment API only.**

For orders where you pack the parcel yourself, see [Create an order](/docs/lastmile/create-an-order).

A fulfilment order tells Quiqup to pick stock it already holds, pack it, and ship it to your customer. You do not send a parcel list. The warehouse builds the parcels.

## Creating one

`POST /api/fulfilment/orders`

| Field | Notes |
|  --- | --- |
| `partner_order_id` | Your own reference. Required. |
| `service_kind` | The service. Decides the picking SLA and the carrier. Required. See below. |
| `products` | What to pick: `sku` and `quantity` per line. Required. Every SKU must be a product on your account. |
| `shipping_address` | Where it goes. Required. See [Addresses](#addresses). |
| `origin_address` | Where it ships from. Required. See [Addresses](#addresses). |
| `payment_mode` | `pre_paid` or `paid_on_delivery`. Required. |
| `currency` | Three-letter code, `AED` or `SAR`. Required. |
| `payment_amount` | What the courier collects. Required, and not `0`, for `paid_on_delivery`. Send `0` or leave it out for `pre_paid`. |


Optional: `uuid` (below), `references` (below), `billing_address`, `billing_identifier`, `notes` (at most 255 characters), `delivery_options` (only `customer_identification_photo`), `carrier`, `incoterm` and `registration_numbers` for exports, and `is_return` with `initial_order_id` for returns.

```json
{
  "uuid": "3f6c1a52-8d2e-4b7a-9c1d-5e8f7a6b4c3d",
  "partner_order_id": "ORD-10045",
  "service_kind": "partner_next_day",
  "payment_mode": "paid_on_delivery",
  "payment_amount": 249.5,
  "currency": "AED",
  "products": [
    { "sku": "TSHIRT-BLK-M", "quantity": 2 },
    { "sku": "MUG-WHT", "quantity": 1 }
  ],
  "shipping_address": {
    "first_name": "Sara",
    "last_name": "Ahmed",
    "address1": "Villa 12, Street 4",
    "address2": "Al Wasl",
    "city": "Dubai",
    "state": "Dubai",
    "country_code": "AE",
    "phone": "+971500000001",
    "email": "sara.ahmed@example.com"
  },
  "origin_address": {
    "name": "Example Brand",
    "first_name": "Example",
    "address1": "Warehouse 7, Example Industrial Area",
    "city": "Dubai",
    "country_code": "AE",
    "phone": "+971500000003"
  },
  "references": [
    { "label": "marketplace_order_id", "value": "MP-778812", "is_unique": true }
  ]
}
```

### What happens next

The order is created `pending` and goes to the warehouse for picking on its own. **There is no release call to make**, and nothing to send on create to release it.

Two kinds of order wait first. An export waits until its carrier is settled. An order that needs a Saudi national address waits until the address is valid.

`mark_as_ready_for_collection` is for returns only. Sending it as `true` on any other order is rejected.

### Retrying safely

Send a `uuid`, any UUID you generate, and keep it for the retries. A repeat create with the same `uuid` returns the original order instead of making a second one, so a create that timed out is safe to resend. The `uuid` is scoped to your account. Without one, every request creates a new order.

`references` attaches your own identifiers, such as a marketplace order number, as `label` and `value` pairs. They are stored and returned when you read the order. Set `is_unique` to `true` on a reference and no other order on your account can carry the same label and value: a later create that repeats them is rejected. There is no endpoint that finds an order by reference.

## Addresses

`shipping_address`, `origin_address` and `billing_address` share one shape.

| Field | Notes |
|  --- | --- |
| `first_name` | Required. At most 50 characters. |
| `address1` | Required. Street address, at most 255 characters. |
| `city` | Required. At most 50 characters. |
| `country_code` | Required. ISO 3166-1 alpha-2, for example `AE`. |
| `phone` | Required. E.164, for example `+971501234567`. Spaces are removed before it is checked. |
| `email` | Required on the shipping address of a `partner_export` order. Optional otherwise. |
| `name` | Business or recipient name. When empty, the first and last names are used as the contact name. |
| `last_name`, `address2`, `state`, `postcode`, `country` | Optional. |
| `coordinate` | Optional `latitude` and `longitude`. |
| `ksa_national_address` | The Saudi national address short code, for deliveries in Saudi Arabia. |
| `notes` | Instructions for this address, at most 500 characters. |


**The origin address decides whether the order is an export.** When its `country_code` differs from the shipping address's, the order is created as `partner_export` whatever `service_kind` you sent, unless you sent `partner_store`. For stock Quiqup holds, the origin is the warehouse holding it. Ask your account manager for the exact address to use.

## Service kinds

| `service_kind` | Service | Where |
|  --- | --- | --- |
| `partner_next_day` | Next day. The standard service. | UAE and KSA |
| `partner_same_day` | Same day. | UAE only |
| `partner_4hr` | Within 4 hours. | Dubai only |
| `partner_export` | International. Carrier allocation is rule-based. | Outbound |
| `partner_store` | Store replenishment, business to business. | Domestic |
| `partner_return` | A return. Set `is_return` rather than sending this. |  |


`service_kind` sets the picking and packing SLA against your account's cut-off times, and picks the carrier.

Ask your account manager which of these are enabled on your account before you build against one.

## Returns

Set `is_return: true` and point `initial_order_id` at the original order, which must exist on your account.

The order is stored with `service_kind` `partner_return`, whatever you sent, and that is what reading it returns. It is not picked from your stock: the goods come back into the warehouse through a return [inbound](/docs/fulfilment/inbound). A return can carry `mark_as_ready_for_collection: true` to be created `ready_for_collection` instead of `pending`.

That is different from last-mile, where a return is its own `kind`.

## Statuses in the warehouse

Before the warehouse hands it over, a fulfilment order is in one of these:

| Status | Meaning |
|  --- | --- |
| `pending` | Created, and waiting for or in the warehouse. |
| `problematic` | A stock or warehouse problem needs attention. `status_reason` says what. |
| `wms_on_hold` | Held in the warehouse management system. |
| `picking_started` | The warehouse has begun picking. |


Once it is packed it moves to `ready_for_collection` and runs the standard state machine: same `status` values, same `order.*` webhooks, same meanings. See [Order lifecycle](/docs/order-lifecycle).

## Reading and updating

`GET /api/fulfilment/orders/{id}` returns the order, including `status`, `status_reason`, `parcels`, `tracking_url`, `tracking_url_advance`, `carrier` and `picking_order_created`, which turns `true` once the warehouse has the order.

`PATCH /api/fulfilment/orders/{id}` updates it. Top-level fields you leave out keep their current values, and any you send replace the current value whole:

- An address you send must be complete, with the same required fields as on create.
- `products` replaces the whole product list.
- **`billing_address` must be sent on every update**, as a complete address, and becomes the order's billing address. Send the current one to keep it. A request without it currently fails with a server error, cancellations included.


An order can be updated while it is `pending`, `problematic`, `wms_on_hold` or `picking_started`. After that, every update is rejected.

### Cancelling

There is no cancel endpoint. Send `PATCH` with `status` set to `cancelled` (and the `billing_address`, as above).

The status can only change while the order is `pending`, `problematic` or `wms_on_hold`, and only to one of those or to `cancelled`. Once picking has started the order cannot be cancelled through the API. Any other `status` value is rejected.

## Tracking it

Track with the `tracking_url` on the fulfilment order. Do not look the shipment up on the last-mile API. The IDs are not interchangeable.

The order `id` is a number sent as a string. The `order.*` webhooks for the order carry the same number as `payload.id`, and its `uuid` as `payload.uuid`. See [Webhooks](/docs/webhooks).

## What is not here

There is no list-orders endpoint and no batch create. One order, one call.