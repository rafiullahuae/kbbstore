# Inbound

**Applies to the Fulfilment API only.**

An inbound is a booked delivery of stock into a Quiqup warehouse. It is how inventory comes into existence.

The warehouse is resolved automatically from your account's facility configuration. There is no warehouse parameter to send. `GET /api/fulfilment/slots/available` ignores any `warehouse_code` you pass it.

## The normal path

**1. Find a slot.** `GET /api/fulfilment/slots/available` with `start_time` and `end_time`. Both are required. It returns the windows the warehouse can take a delivery in.

**2. Book it.** `POST /api/fulfilment/inbound/book`.

| Field | Notes |
|  --- | --- |
| `items` | The SKUs you are sending. Required, at least one. Each item needs `sku`, `quantity` (at least 1) and `unit_price` (not 0). Every SKU must be an active product on your account. |
| `slot_start_at`, `slot_end_at` | The window from step 1. Send both, or neither. The start must be in the future. Without a slot the inbound lands as `PENDING` rather than `BOOKED`. |
| `attendees` | Who will be at the delivery. Required, with at least one name, when you send a slot. |
| `type` | `inbound` (default), `client_return` or `facility_return`. |
| `original_order_ids` | On each item. Required on every item for `client_return` and `facility_return`: the orders the stock came back from. |
| `partner_inbound_id` | Your own reference. See [Duplicates](#duplicates). |
| `courier_name`, `courier_contact`, `plate_number` | The driver. Required when the warehouse needs a gate pass and the booking has a slot; otherwise they can be added later. |
| `pickup_address`, `pickup_contact` | Only if Quiqup is collecting from your supplier rather than you delivering. Send both: the inbound is a collection only when both are present. Every `pickup_address` field you send, including `address2` and `coordinate`, is stored and returned on the inbound. |
| `documents` | Invoices, manifests, gate passes. |


On each item you can also send `batch_number`, `expiry_date`, `manufacturing_date`, `uom` and `uom_units`.

```json
{
  "partner_inbound_id": "PO-2026-0042",
  "slot_start_at": "2030-03-04T06:00:00Z",
  "slot_end_at": "2030-03-04T08:00:00Z",
  "attendees": ["Omar Khalid"],
  "items": [
    { "sku": "TSHIRT-BLK-M", "quantity": 200, "unit_price": 22.5 },
    { "sku": "VITC-60", "quantity": 48, "unit_price": 31, "batch_number": "LOT-2030-02", "expiry_date": "2031-02-28T00:00:00Z" }
  ],
  "courier_name": "Omar Khalid",
  "courier_contact": "+971500000002",
  "plate_number": "DXB-A-12345"
}
```

The response carries `inbound_id`, `status`, `asn_id` and `already_existed`.

**3. Send the stock.** Off-API.

**4. Wait for the GRN (goods received note).** The warehouse counts what arrived. `GET /api/fulfilment/inbounds/{id}/items` returns the line items: SKU, expected quantity, received, usable, unusable, quarantine, plus batch number, expiry date and manufacturing date.

Expected and received will not always match. That difference is the whole reason to read this endpoint rather than assume the booking was the outcome.

## Duplicates

**One slot, one inbound.** A booking for a window your account has already booked returns the existing inbound with `already_existed: true`. Nothing new is created, and the items you sent are not added to it, so a second shipment needs a window of its own.

`partner_inbound_id` is a reference for you, not a uniqueness key. For a booking without a slot, a retry with the same `partner_inbound_id` that arrives while the first request is still being processed returns the same inbound. After that, Quiqup does not check it, and every unscheduled booking creates a new inbound. Send it anyway: it is how you follow a shipment across a cancel and rebook.

## Reading inbounds

- `GET /api/fulfilment/inbound/{id}` returns one inbound with its items and documents.
- `GET /api/fulfilment/inbounds` lists them, 100 to a page unless you send `limit`. Filter by `state` (the status), `type`, `ids`, `asn_id`, or by the start of the delivery window with `slot_from` and `slot_to` (dates, inclusive; unscheduled inbounds have no window and are left out).
- `GET /api/fulfilment/inbounds/{id}/items` returns the GRN lines, as above.


The inbound carries a `services` object (`barcode_printing`, `customs_clearance`, `lab_testing`, `stickering`). It is read-only: these added-value services are recorded by Quiqup and cannot be requested through the API.

## Statuses

| Status | Meaning |
|  --- | --- |
| `PENDING` | Created without a slot. Not yet scheduled. |
| `BOOKED` | Slot confirmed. The ASN (advance shipping notice) exists in the WMS (the warehouse management system). |
| `IN_PROGRESS` | Being received at the warehouse. |
| `COMPLETED` | Received and counted. |
| `CLOSED` | Finished. |
| `CANCELLED` | Cancelled. Soft delete: the record stays. |
| `ERROR` | Something failed. |


The statuses are uppercase on the API. The matching webhook events are lowercase: `BOOKED` arrives as `inbound.booked`. Each `inbound.*` event carries the inbound (its id, your reference, the ASN number, type, new and previous status, warehouse, slot and timestamps) and its item lines with expected and received quantities. See [Webhooks](/docs/webhooks).

`GET /api/fulfilment/inbound/{id}/state-history` returns every transition with `old_status`, `new_status`, who changed it, the reason, the source (`webhook`, `pull` or `manual`) and the underlying `wms_status`.

## Scheduling a pending inbound

An inbound booked without a slot sits at `PENDING`. Two ways to give it one:

- `POST /api/fulfilment/inbounds/{id}/schedule` with slot times and attendees.
- `PATCH /api/fulfilment/inbounds/{id}` with `slot_start_at`, `slot_end_at` and `attendees`.


Either moves it to `BOOKED` and triggers the full workflow: ASN creation, calendar booking, facility notification. Both refuse slot times on an inbound that is not `PENDING`.

`PATCH` is also how you attach courier details and documents after booking, on a `PENDING`, `BOOKED` or `IN_PROGRESS` inbound. Fields you leave out are kept.

## Cancelling

`POST /api/fulfilment/inbounds/{id}/cancel`, with an optional `reason`.

`PENDING`, `BOOKED` and `IN_PROGRESS` inbounds can be cancelled. It is a soft delete: the record stays with status `CANCELLED`, and the WMS ASN, the scheduling event and the facility notification are cleaned up. A `PENDING` inbound that has no warehouse ASN yet is cancelled at once, and the response message is `Inbound cancelled`. Any inbound with an ASN is cancelled in the background: that includes a `PENDING` inbound booked without a slot whose ASN was registered at booking, and every `BOOKED` and `IN_PROGRESS` inbound. The call then returns the message `Inbound cancellation signaled`, and the status becomes `CANCELLED` shortly after. Read the inbound back with `GET /api/fulfilment/inbound/{id}` to confirm.

**A booked inbound cannot be moved to another slot.** Cancel it and book again. That gets you a new inbound ID, so carry your own `partner_inbound_id` if you need to follow the shipment across the change. A `PENDING` inbound you no longer need is dropped the same way: cancel it.