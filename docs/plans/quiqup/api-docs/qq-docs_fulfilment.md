# Fulfilment

**Applies to the Fulfilment API only.** Host `platform-api.quiqup.com`. If you hold your own stock and just need it delivered, you want [Last-mile](/docs/lastmile) instead.

Quiqup stores your stock in a warehouse, picks it, packs it and ships it. Four objects, in the order you meet them.

| Object | What it is | You create it |
|  --- | --- | --- |
| **Product** | A SKU we can hold. Weight, dimensions, price, whether it is batch or expiry managed. | Once, before anything else |
| **Inbound** | A booked delivery of stock into the warehouse. | Every time you send us stock |
| **Inventory** | What is actually on the shelf, per SKU, split into buckets. | Never. You read it. |
| **Fulfilment order** | An instruction to pick, pack and ship to a customer. | Per customer order |


Nothing works until products exist. Inventory only appears after an inbound is received. An order for a SKU with no stock cannot be fulfilled.

## The shortest path to a shipped order

1. **Create your products.** `POST /api/fulfilment/products`, or upload a CSV. See [Products](/docs/fulfilment/products).
2. **Book stock in.** Find a slot, book an inbound, send the stock. See [Inbound](/docs/fulfilment/inbound).
3. **Wait for receipt.** The warehouse counts what arrived and inventory appears. See [Inventory](/docs/fulfilment/inventory).
4. **Create an order.** `POST /api/fulfilment/orders`. See [Fulfilment orders](/docs/fulfilment/orders).
5. **Track it.** The order carries a `tracking_url` and runs the same states as any other Quiqup shipment. See [Order lifecycle](/docs/order-lifecycle).


Steps 1 to 3 happen once per catalogue and once per restock. Step 4 is the loop you build.

## What is shared with Last-mile

More than you might expect.

- **Credentials.** The same token works on both hosts. See [Authentication](/docs/authentication).
- **Order states.** A fulfilment order reports the same `status` values as a last-mile order, because once it is picked it becomes a Quiqup shipment. See [Order lifecycle](/docs/order-lifecycle).
- **`order.*` webhooks.** Same events, same payload shape, same signing. See [Webhooks](/docs/webhooks).
- **Service kinds.** `partner_next_day`, `partner_same_day`, `partner_4hr` mean the same thing on both.


## What is not shared

- **The host.** `platform-api.quiqup.com`, not `api-ae.quiqup.com`.
- **The error envelope.** Fulfilment returns `{code, message, details}`, with no `status` field: read the HTTP status. Last-mile returns something else entirely. See [Error responses](/docs/errors).
- **The order object.** A fulfilment order ID is not a last-mile order ID. You cannot pass one to the other API's endpoints.
- **Two extra webhook families.** `inbound.*` and `inventory.*` only exist here.
- **Extra states.** `picking_started`, `wms_on_hold` and `problematic` only occur on fulfilment orders, before the warehouse hands the order over.


## Testing on staging

Build against `https://platform-api.staging.quiqup.com` with a staging credential. Paths, fields and errors are the same as in production.

- **Your staging account has to be enabled for fulfilment.** Until it is, booking an inbound fails with `failed_precondition` and the message "This account is not activated for fulfilment yet". Ask your account manager to enable it.
- **Products and inbound bookings behave as in production.** Create the SKUs you will test with first, then book an inbound for them.
- **No API call receives an inbound.** Stock appears only when the warehouse records a receipt, so on staging an inbound stays `BOOKED` and your inventory stays at zero until Quiqup receives it. To get stock onto a staging SKU, email [apisupport@quiqup.com](mailto:apisupport@quiqup.com) with the inbound ID or the SKUs and quantities you need.
- **An order for a SKU with no `available` stock cannot be fulfilled**, on staging as anywhere else. Check `GET /api/fulfilment/inventory/{sku}` before you create test orders.


## Pages in this section

- [Products](/docs/fulfilment/products). Loading your catalogue, one at a time or in bulk.
- [Inventory](/docs/fulfilment/inventory). Reading stock, buckets, batches and expiry.
- [Inbound](/docs/fulfilment/inbound). Booking stock into the warehouse.
- [Fulfilment orders](/docs/fulfilment/orders). Creating and updating orders.
- [Fulfilment API reference](/openapi). Every operation.