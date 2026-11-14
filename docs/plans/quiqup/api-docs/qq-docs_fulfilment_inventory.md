# Inventory

**Applies to the Fulfilment API only.**

Inventory is read-only in normal operation. It is the warehouse's count, not yours. It appears when an inbound is received and it moves when orders are picked.

## Reading stock

`GET /api/fulfilment/inventory` lists everything under your account. Filter with `sku`, `product_name` or `vendor`, page with `limit` (1 to 100, default 20) and `page` (starts at 1), sort with `sort` and `sort_desc`.

`GET /api/fulfilment/inventory/{sku}` gives one SKU. It takes the SKU, not a product id.

## The buckets

A quantity is never just a quantity. Every SKU splits across buckets, and only one of them is sellable.

| Bucket | Meaning |
|  --- | --- |
| `available` | On the shelf, sellable, not promised to anything. |
| `reserved` | Committed to an order that is being picked. |
| `damaged` | Written off. |
| `qc_hold` | Held for quality inspection. |
| `total` | The sum of all buckets. |


**Sell against `available`, not `total`.** `total` includes stock that is already promised, already broken, or already quarantined. Reading `total` as availability is the most common way to oversell.

`reserved` moves on its own as orders progress. You do not manage it.

## Batches and expiry

For a SKU with `batch_management` switched on, the quantity above is a roll-up.

`GET /api/fulfilment/inventory/{sku}/batches` breaks it down by batch: batch number, expiry date, manufacturing date and the batch's `available` and `unusable` quantities. Filter with `batch_number`, or with `expiring_before` to find stock about to age out.

`GET /api/fulfilment/batches/{batchId}` returns a single batch.

An inventory item also carries `expiry_groups`, grouping quantity by expiry date without the full batch detail.

Which batch gets picked is decided by the warehouse, using the product's `outbound_expiry_threshold_days`. You do not choose it per order.

## Adjusting stock

`POST /api/fulfilment/inventory/adjustments` writes a manual correction against one bucket: `sku`, `bucket`, `quantity_change`, `reason` (`damage`, `correction`, `write_off`, `qc_fail` or `other`) and optional `notes`.

The adjustment is a **delta**, positive or negative, not a new total. It is recorded as an inventory movement, so it is auditable. The SKU must already have an inventory record, and a change that would take a bucket below zero is rejected.

Use it for writing off damage found after receipt, correcting a discrepancy after a physical count, or moving stock into QC hold. Do not use it to load opening stock. That is what an [inbound](/docs/fulfilment/inbound) is for.

## Stock change events

Stock changes attributed to a received inbound, a picked or cancelled order, or an adjustment are published as an `inventory.moved` webhook. One event groups the movements that share a cause. For each SKU it carries the change, the bucket and the stock levels after the change (`available`, `damaged`, `reserved`, `qc_hold` and `total`), with a reference to the inbound, order or adjustment behind it.

Use it as a prompt to refresh, not as a ledger to sum: the inventory endpoints above are the count. See [Webhooks](/docs/webhooks).