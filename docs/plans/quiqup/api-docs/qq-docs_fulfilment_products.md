# Products

**Applies to the Fulfilment API only.**

A product is a SKU Quiqup can hold. Nothing else in fulfilment works until your products exist: an inbound references SKUs, inventory is keyed on SKU, and an order picks against them.

## Creating one

`POST /api/fulfilment/products`

Four fields are required:

| Field | Notes |
|  --- | --- |
| `sku` | Your stock keeping unit, 3 to 100 characters. Unique within your account. This is the key everything else uses. |
| `name` | 2 to 255 characters. |
| `selling_price` | What the customer pays, greater than 0. A number, not a string. |
| `currency` | `AED` or `SAR`. Those are the only two. |


A SKU that already exists on your account is rejected with `already_exists`.

Everything else is optional, but three groups of it matter operationally.

**Physical.** `weight`, `weight_unit` (`kg` or `g`) and `dimensions` (`length`, `width`, `height` and a `unit` of `cm` or `m`). The warehouse uses these for storage and the carrier uses them for shipping. Leave them out and the product is created with defaults: 0.5 kg, and 10 by 10 by 10 cm. Those defaults then reach the warehouse and the carrier, so send the real values.

**Customs.** `harmonized_system_code` and `country_code_of_origin` (ISO2). Required in practice for anything shipping on `partner_export`, not enforced at create.

**Shelf life.** `batch_management`, `expiry_management` and `track_expiry` switch on batch and expiry handling for the SKU. `inbound_expiry_threshold_days` is the minimum shelf life the warehouse will accept when receiving stock; `outbound_expiry_threshold_days` is the minimum it will pick with. Set these before the first inbound, not after.

Also available: `barcode` (defaults to the SKU), `description`, `vendor`, `image`, `type` (`physical` or `digital`), `cost_price`, `retail_price`, `requires_shipping`, `platform_price`, `partner_product_id` (defaults to the SKU) and `status`.

`retail_price` is your recommended retail price, and `requires_shipping` is stored for your reference. Neither changes how an order is fulfilled. When no retail price is stored, a read returns the selling price as `retail_price`.

`status` is `active`, `archived` or `draft`, and defaults to `active`. Archiving is how you retire a SKU. There is no delete.

`availability_status`, `inventory_tracked` and `total_sales` are accepted and ignored. Stock comes from [inventory](/docs/fulfilment/inventory), not from the product.

## Reading and updating

`GET /api/fulfilment/products/{sku}` takes the SKU. The product `id` is not accepted in its place.

`PATCH /api/fulfilment/products/{sku}` is **not a partial update**. The body is the whole product and replaces what is stored, so:

- Send `sku` (the same as in the path), `name`, `selling_price` and `currency` every time.
- Most fields you leave out are reset, not kept. `description`, `vendor`, `type`, `image`, `harmonized_system_code` and `country_code_of_origin` are cleared. `barcode` and `partner_product_id` go back to the SKU. `weight`, `weight_unit` and `dimensions` go back to the defaults above. `status` goes back to `active`, so a PATCH that leaves `status` out un-archives the SKU.
- These are kept when you leave them out: `cost_price`, `retail_price`, `requires_shipping`, `platform_price`, `track_expiry`, `batch_management`, `expiry_management`, `inbound_expiry_threshold_days` and `outbound_expiry_threshold_days`.


To change one field, read the product, change that field, and send the whole product back. One exception: when no cost or retail price is stored, a read returns the selling price as `cost_price` or `retail_price`, so leave those out rather than copying them back.

To remove a stored `platform_price`, send `clear_platform_price: true`. Leaving `platform_price` out keeps it.

## Bulk upload

Two calls, in this order, both taking a `multipart/form-data` body with the file in a part named `file`.

1. `POST /api/fulfilment/products/bulk/validate` parses the file and returns a per-row status: `valid`, `invalid`, `duplicate` (the SKU already exists on your account) or `duplicate_in_file`. Nothing is written.
2. `POST /api/fulfilment/products/bulk/commit` parses, validates and writes. The `update_duplicates` form field decides what happens to an existing SKU: `true` updates it, anything else skips it. Each row comes back `created`, `updated`, `skipped` or `failed`.


The file is a `.csv` (comma or semicolon separated) or an `.xlsx` workbook, of which only the first sheet is read. At most 10 MB and 5,000 data rows. The first row holds the column names, and eight columns are required: `sku`, `name`, `selling_price`, `currency`, `barcode`, `hs_code`, `batch_management` and `expiry_management`. The column names of Quiqup's product template are accepted too.

A file that cannot be read at all comes back `400` with a body of `{"error": "..."}`, not the usual [error envelope](/docs/errors). A failure on Quiqup's side while processing the file comes back `500` with the same `{"error": "..."}` body.

**Commit is not atomic.** Rows are processed one at a time, so a failure part way through leaves earlier rows written and later ones not. The response carries the status of every row. Read it and retry the failures individually rather than resubmitting the file.

Validate first. It costs one call and it is the only way to see the whole file's problems before any of it lands.

## What is not here

There is no delete, no list-all-products endpoint, and no way to create a product and its opening stock in one call. Stock arrives through an [inbound](/docs/fulfilment/inbound).