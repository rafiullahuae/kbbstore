# Order lifecycle

**Applies to both APIs.** One state machine covers fulfilment and last-mile. A fulfilment order becomes a Quiqup shipment once it is picked, and from there reports the same values as any other order.

The statuses below are also the same for connector orders. What is not the same is the two-call create-then-ready sequence: the connector does both for you.

## Two fields, two names

The two APIs report the same values under different field names.

|  | Field | Endpoint |
|  --- | --- | --- |
| Last-mile | `state` | `GET /orders/{order_id}` |
| Fulfilment | `status` | `GET /api/fulfilment/orders/{id}` |


Webhooks carry it as `payload.state` for both.

## Create, then make it ready

**Last-mile.** Creating an order does not send it to Quiqup operations. A `pending` order shows in Quiqdash, under the **Pending** tab, but dispatch does not see it and it will never be collected.

```
POST /orders                                   -> order is pending
PUT  /orders/{order_id}/ready_for_collection   -> order enters the collection run
```

There are three ways an order becomes ready for collection:

1. `PUT /orders/{order_id}/ready_for_collection`
2. `ready_for_collection: true` on create
3. Account-level auto-RFC (automatic ready for collection), switched on by Quiqup on request


If the flag is set but the order still has validation errors, the order stays pending.

This is the usual cause of **"I got a 2xx but nothing happened"**: the order is in Quiqdash under **Pending**, and no courier comes. Checklist:

1. Was `ready_for_collection` called, and did it return 2xx?
2. Does `GET /orders/{order_id}` return the order?
3. Is the dropoff phone a valid UAE number? An invalid one is the most common reason an otherwise good order fails.
4. Is the account in the right operating area?


The same cause explains "no courier is assigned to my API orders while manual orders get one".

**Fulfilment.** Same idea, different field. `mark_as_ready_for_collection: true` on `POST /api/fulfilment/orders` releases the order on create. There is no separate release endpoint.

## Order statuses and their events

The `state` a `GET` returns and the event a webhook carries are the same transition. One table for both.

The wire field is **`state`**, not "status".

| `state` | Webhook event | What it means |
|  --- | --- | --- |
| `pending` | `order.pending` | Created as a draft. Quiqup ignores it until it is made ready. |
| `ready_for_collection` | `order.ready_for_collection` | Known to Quiqup, joins the next collection run. |
| `out_for_collection` | `order.out_for_collection` | A courier is assigned to collect. |
| `collected` | `order.collected` | Collected, on the way to the depot. |
| `collection_failed` | `order.collection_failed` | Collection attempted and failed. Carries a reason. |
| `received_at_depot` | `order.received_at_depot` | Received at the depot. |
| `at_depot` | `order.at_depot` | At the depot, awaiting scheduling or the next delivery day. |
| `scheduled` | `order.scheduled` | Scheduled for a future delivery with the customer. |
| `in_transit` | `order.in_transit` | Moving between the Dubai and Abu Dhabi warehouses. `in_transit` is the wire value; older documentation spelled it `Transit`. |
| `out_for_delivery` | `order.out_for_delivery` | Out for delivery. |
| `delivery_complete` | `order.delivery_complete` | Delivered. End of life. |
| `delivery_failed` | `order.delivery_failed` | Delivery failed. Carries a reason. |
| `return_to_origin` | `order.return_to_origin` | Will be returned to the merchant. |
| `out_for_return` | `order.out_for_return` | Being returned to the merchant. |
| `returned_to_origin` | `order.returned_to_origin` | Returned to the merchant. End of life. |
| `cancelled` | `order.cancelled` | Cancelled. |
| `on_hold` | `order.on_hold` | On hold. |
| `error` | `order.error` | Subscribable, and a delivery would carry `state: error`, but last-mile orders do not currently enter this state. See below. |
| `picking_started` | `order.picking_started` | Picking has begun. **Fulfilment only.** |
| `wms_on_hold` | None | Held inside the warehouse management system. **Fulfilment `status` only.** |
| `problematic` | None | Flagged as problematic by the warehouse. **Fulfilment `status` only.** |


`wms_on_hold` and `problematic` appear only in the fulfilment API's `status`. Neither has a webhook event, so no subscription receives them. On the last-mile side a problematic order is put `on_hold`.

**Handle every value, including ones you do not recognise.** `order.error` is in the event list for compatibility: no last-mile order moves to `error` today. A problem found after acceptance leaves the order in the state it was in, and a booking refused at release comes back as a `422` on `ready_for_collection` instead. A client that switches exhaustively on a hard-coded list should still treat an unknown `state` as "no change I understand" rather than fail.

### Where a failed state goes next

None of the failure states are dead ends.

| From | Goes back to |
|  --- | --- |
| `collection_failed` | `ready_for_collection`, for another attempt |
| `delivery_failed` | `at_depot`, to be rescheduled |
| `on_hold` | Various, depending on why it was held |
| `wms_on_hold` | Back into picking once the warehouse clears it |


A failure is a retry, not an ending. The end states are `delivery_complete`, `returned_to_origin` and `cancelled`.

### Reasons

A failed delivery carries `delivery_failure_reason`, on the order and in its webhook payload. It is
free text, not an enum.

`GET /orders/{order_id}` returns the current state only. The transitions are in your [webhook events](/docs/webhooks).

**You drive two transitions, and only two.** `PUT /orders/{order_id}/ready_for_collection` moves an order out of `pending`, and `PUT /orders/batch/set_cancelled` cancels it while it is `pending` or `ready_for_collection`. Everything else is set by operations and the courier application.

Status is order-level. When one parcel of several hits an exception, the order-level status can read `at_depot` while a customer has already received part of the shipment. That is the usual explanation for "shows At Depot but was delivered".

## Service types

Set `kind` on create. It cannot be changed afterwards. It is not required: an order without one is created as `partner_same_day`. Send it explicitly rather than relying on that. See [Top-level fields](/docs/lastmile/create-an-order#top-level-fields).

On fulfilment the same field is called `service_kind` and is required. See [Fulfilment orders](/docs/fulfilment/orders#service-kinds).

| `kind` (last-mile) | Service |
|  --- | --- |
| `partner_same_day` | Collected and delivered the same day if made ready before the agreed cut-off. |
| `partner_next_day` | Delivered the next day. |
| `partner_4hr` | Made ready between 08:00 and your 4-hour cut-off, 18:00 unless agreed otherwise: delivered within 4 hours. Made ready after the cut-off: by 12:00 the next day. Made ready before 08:00: by 12:00 the same day. UAE time throughout. |
| `partner_return` | A return. Pickup is the customer's address, dropoff is the merchant's. |
| `partner_store` | Accepted on create and resolves to the same-day service class. |
| `partner_export` | International shipping to GCC destinations, with rule-based carrier allocation. Also applied automatically when the destination country differs from the pickup country. See [Export orders](/docs/lastmile/create-an-order#export-orders). |


Fulfilment orders can be exports too, through `service_kind` on the [Fulfilment API](/docs/fulfilment/orders#service-kinds).

Values you may see referenced elsewhere and should not integrate against: `partner_ondemand` and any 1-hour service. On-demand is discontinued.

**`kind` on the way in is not `kind` on the way out.** The API responses carry both `service_kind`,
the service the order was created with, and `kind`, a service class that is not always the same.
Send `partner_store` and you get back `service_kind: partner_store` with `kind: partner_same_day`.
Webhooks carry `service_kind` and can carry `kind` empty. **Match on `service_kind`.**

An order past the cut-off is accepted and collected on the next cycle. It is not rejected.

**A service must be enabled on your account before you can use it.** Same Day is checked when the order is created: without it, `POST /orders` returns `422` with `Same day service is not enabled for your account`. The other services are not checked at create, so an order for one that is not enabled is accepted. Confirm with your account manager which services are on before you build.

**`kind` cannot be changed after creation.** A mis-serviced order has to be cancelled and re-booked, and an already-labelled parcel is returned rather than relabelled.

## Payment

`payment_mode` and `payment_amount` are request fields and are described once, in [Top-level fields](/docs/lastmile/create-an-order#top-level-fields).

Currency defaults to the country's currency: AED in the UAE.

## Identifiers

| Identifier | Who generates it | Notes |
|  --- | --- | --- |
| `partner_order_id` | You | Your own reference. Used for invoicing and COD reconciliation. **Uniqueness is not enforced**, so send a unique value. It does not make a retry safe: send a [`uuid`](/docs/lastmile/create-an-order#retrying-safely) for that. |
| Order id | Quiqup | Returned on create. Use it in later API calls. |
| Tracking id / `tracking_url` | Quiqup | Shareable and unauthenticated. Anyone with the link can open it. |
| Parcel barcode | Quiqup | Send an empty string. Quiqup generates it and prints the label. |


See [Retrying safely](/docs/lastmile/create-an-order#retrying-safely) for what that means when a create times out.

## billing_identifier

A request field, described in full under [billing_identifier](/docs/lastmile/create-an-order#billing_identifier).