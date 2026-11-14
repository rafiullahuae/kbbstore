# Fulfilment API

The Fulfilment API runs fulfilment with Quiqup: Quiqup stores your stock in its warehouse, picks and packs it, and ships it to your customers. You load your products, book stock into the warehouse with an inbound, read your inventory, and create fulfilment orders.
**Hosts.** Staging is `https://platform-api.staging.quiqup.com` and production is `https://platform-api.quiqup.com`. Every path is under `/api/fulfilment/`. Staging credentials only work on staging.
**Authentication.** Send `Authorization: Bearer <token>` on every request, with a Quiqdash API key or an access token from `/oauth/token`. The same credential works on the Last-mile API. See [Authentication](/docs/authentication).
**Errors.** An error returns a JSON body with `code`, `message` and `details`, and `details.fields` names each field that failed validation. The two bulk product calls answer a rejected file differently. See [Error responses](/docs/errors).
**Guides.** [Fulfilment](/docs/fulfilment) walks through products, inbounds, inventory and orders in the order you meet them.

Version: 1.0.0

## Servers

Production
```
https://platform-api.quiqup.com
```

Staging
```
https://platform-api.staging.quiqup.com
```

## Security

### BearerAuth

A Quiqdash API key, or an access token from `/oauth/token`, sent as `Authorization: Bearer <token>`. See [Authentication](/docs/authentication).

Type: http
Scheme: bearer

### OAuth2

OAuth 2.0 client credentials, issued by Quiqup on request. Exchange them at `/oauth/token` on the host you are calling: `https://platform-api.staging.quiqup.com/oauth/token` on staging, `https://platform-api.quiqup.com/oauth/token` in production. The token carries the full access of your account; there are no scopes to request. See [Authentication](/docs/authentication).

Type: oauth2
Token URL: https://platform-api.quiqup.com/oauth/token
Scopes:

## Download OpenAPI description

 - [Fulfilment API](https://developer-docs.quiqup.com/_bundle/openapi.yaml)

## Fulfilment Products

 - [GET /api/fulfilment/product-documents/{sku}](https://developer-docs.quiqup.com/openapi/fulfilment-products/get:fulfilment_public_api.listproductdocuments.md): Lists the documents attached to a product, each with a download link valid for 24 hours.
 - [POST /api/fulfilment/product-documents/{sku}](https://developer-docs.quiqup.com/openapi/fulfilment-products/post:fulfilment_public_api.uploadproductdocument.md): Attaches a PDF to a product, such as the MSDS (safety data sheet) or a certificate. A document is stored once on the product and kept for the customs paperwork carriers request on its export shipments
 - [DELETE /api/fulfilment/product-documents/{sku}/{documentID}](https://developer-docs.quiqup.com/openapi/fulfilment-products/delete:fulfilment_public_api.deleteproductdocument.md): Removes a document from a product.
 - [POST /api/fulfilment/products](https://developer-docs.quiqup.com/openapi/fulfilment-products/post:fulfilment_public_api.createproduct.md): Creates a product: a SKU that Quiqup can hold for you. `sku`, `name`, `selling_price` and `currency` are required. A SKU that already exists on your account is rejected with `already_exists`. Everythi
 - [POST /api/fulfilment/products/bulk/commit](https://developer-docs.quiqup.com/openapi/fulfilment-products/post:fulfilment_public_api.bulkcommitproducts.md): Checks and saves a product file. Send the same file as to Bulk Validate Products, plus an optional `update_duplicates` form field: `true` updates the products whose SKU already exists, and any other v
 - [POST /api/fulfilment/products/bulk/validate](https://developer-docs.quiqup.com/openapi/fulfilment-products/post:fulfilment_public_api.bulkvalidateproducts.md): Checks a product file without saving anything, and returns the status of every row. Send `multipart/form-data` with the file in a part named `file`: a `.csv` file (comma or semicolon separated) or an
 - [GET /api/fulfilment/products/{sku}](https://developer-docs.quiqup.com/openapi/fulfilment-products/get:fulfilment_public_api.getproductbysku.md): Returns one product by its `sku`. The path takes the SKU, not the product id.
 - [PATCH /api/fulfilment/products/{sku}](https://developer-docs.quiqup.com/openapi/fulfilment-products/patch:fulfilment_public_api.updateproduct.md): Updates a product. This is not a partial update: the request is the whole product, and it replaces what is stored. Send `sku` (the same as in the path), `name`, `selling_price` and `currency` every ti
## Fulfilment Inbound

 - [POST /api/fulfilment/inbound/book](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/post:fulfilment_public_api.bookinbound.md): Books a delivery of stock into the Quiqup warehouse that serves your account. The warehouse is set from your account: there is no warehouse to choose. Only `items` is needed to create an inbound, and
 - [GET /api/fulfilment/inbound/{id}](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/get:fulfilment_public_api.getinbound.md): Returns one inbound by its id, with its items and documents.
 - [GET /api/fulfilment/inbound/{id}/state-history](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/get:fulfilment_public_api.getinboundstatehistory.md): Returns every status change of an inbound: the old and new status, when it happened, who or what made the change, why, where it came from (`webhook`, `pull` or `manual`) and, for a change reported by
 - [GET /api/fulfilment/inbounds](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/get:fulfilment_public_api.getinbounds.md): Lists your inbounds, one page at a time, 100 to a page unless you send `limit`. Filter by `state`, `type`, `ids` or `asn_id`, or by the start of the delivery window with `slot_from` and `slot_to`.
 - [PATCH /api/fulfilment/inbounds/{id}](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/patch:fulfilment_public_api.updateinbound.md): Adds or changes the courier details (`plate_number`, `courier_name`, `courier_contact`) and attaches documents, on an inbound that is `PENDING`, `BOOKED` or `IN_PROGRESS`. Fields you omit are kept. Wh
 - [POST /api/fulfilment/inbounds/{id}/cancel](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/post:fulfilment_public_api.cancelinbound.md): Cancels an inbound that is `PENDING`, `BOOKED` or `IN_PROGRESS`. The inbound is kept with status `CANCELLED`, and Quiqup cleans up the warehouse booking. You can only cancel your own inbounds. A `PEND
 - [GET /api/fulfilment/inbounds/{id}/items](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/get:fulfilment_public_api.getinbounditems.md): Returns the line items of an inbound with what the warehouse counted: the expected `quantity`, `received_qty`, `usable_quantity`, `unusable_quantity` and `quarantine_quantity`, plus the batch number,
 - [POST /api/fulfilment/inbounds/{id}/schedule](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/post:fulfilment_public_api.scheduleinbound.md): Gives a `PENDING` inbound its delivery window. The inbound moves to `BOOKED` and the warehouse booking is made. `slot_start_at` must be in the future and `slot_end_at` after it. Only a `PENDING` inbou
 - [GET /api/fulfilment/slots/available](https://developer-docs.quiqup.com/openapi/fulfilment-inbound/get:fulfilment_public_api.getavailableslots.md): Returns the delivery windows in which the warehouse serving your account can take an inbound, between `start_time` and `end_time`. Both are required. The warehouse is set from your account, so a `ware
## Fulfilment Inventory

 - [GET /api/fulfilment/batches/{batchId}](https://developer-docs.quiqup.com/openapi/fulfilment-inventory/get:fulfilment_public_api.getinventorybatch.md): Returns one batch by its id, as listed by List Inventory Batches.
 - [GET /api/fulfilment/inventory](https://developer-docs.quiqup.com/openapi/fulfilment-inventory/get:fulfilment_public_api.listinventory.md): Lists the stock of every SKU on your account, one page at a time. Filter by `sku`, `product_name` or `vendor`. A page holds 20 items unless you send `limit`.
 - [POST /api/fulfilment/inventory/adjustments](https://developer-docs.quiqup.com/openapi/fulfilment-inventory/post:fulfilment_public_api.adjuststock.md): Applies a manual correction to one bucket of one SKU. `quantity_change` is a delta, positive or negative, not a new total, and the change is recorded as an inventory movement. Use it to write off dama
 - [GET /api/fulfilment/inventory/{sku}](https://developer-docs.quiqup.com/openapi/fulfilment-inventory/get:fulfilment_public_api.getinventorybysku.md): Returns the stock of one SKU, split into buckets. Sell against `available`: `total` also counts stock that is reserved, damaged or on QC hold. For a product with expiry management, `expiry_groups` gro
 - [GET /api/fulfilment/inventory/{sku}/batches](https://developer-docs.quiqup.com/openapi/fulfilment-inventory/get:fulfilment_public_api.listinventorybatches.md): Lists the batches of one SKU, each with its batch number, expiry and manufacturing dates and quantities. Filter by `batch_number`, or by `expiring_before` to find stock about to expire.
## Fulfilment Orders

 - [POST /api/fulfilment/orders](https://developer-docs.quiqup.com/openapi/fulfilment-orders/post:fulfilment_public_api.createfulfilmentorder.md): Creates a fulfilment order: an instruction to pick stock Quiqup already holds for you, pack it and ship it to your customer. The warehouse builds the parcels, so you send products, not parcels. The or
 - [GET /api/fulfilment/orders/{id}](https://developer-docs.quiqup.com/openapi/fulfilment-orders/get:fulfilment_public_api.getfulfilmentorder.md): Returns a fulfilment order by its `id`, the number returned when you created it. `order.*` webhooks carry the same number as `payload.id`. The response includes `status`, `status_reason`, `parcels`, t
 - [PATCH /api/fulfilment/orders/{id}](https://developer-docs.quiqup.com/openapi/fulfilment-orders/patch:fulfilment_public_api.updatefulfilmentorder.md): Updates a fulfilment order. Top-level fields you omit keep their current values, with one exception: `billing_address` must be sent on every request, as a complete address, and becomes the order's bil
