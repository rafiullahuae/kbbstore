# Create an order

**Applies to direct API integrations.** Connectors build this payload for you from your store's order.

## The payload

This is the payload Quiqup support sends when someone asks how to start. Copy it, change the values.

For a UAE order you can drop the whole `products` array. It is here because export orders need it, and because it is where weight and dimensions live.

Save it as `order.json`.

```json
{
  "kind": "partner_same_day",
  "payment_mode": "pre_paid",
  "partner_order_id": "PARTNER-ORDER-ID",
  "origin": {
    "contact_name": "Warehouse contact",
    "contact_phone": "+971500000000",
    "address": {
      "address1": "Example Warehouse, Street 1",
      "address2": "Al Quoz Industrial Area 4",
      "country": "AE",
      "town": "Dubai"
    }
  },
  "destination": {
    "contact_name": "Customer name",
    "contact_phone": "+971599999999",
    "contact_email": "customer@example.com",
    "notes": "",
    "address": {
      "address1": "1st Road",
      "address2": "104G",
      "country": "AE",
      "town": "Dubai"
    }
  },
  "products": [
    {
      "sku": "SKU-RUN-001",
      "quantity": 1,
      "description": "Running shoe red",
      "barcode": "1234654-kt",
      "selling_price": 58.3,
      "hs_code": "64041100",
      "country_of_origin": "CN",
      "weight": 0.8,
      "height": 12,
      "width": 20,
      "length": 30
    }
  ],
  "items": [
    {
      "name": "Parcel 1",
      "quantity": 1,
      "parcel_barcode": ""
    }
  ]
}
```

Then:

```bash
curl -X POST 'https://api.staging.quiqup.com/orders' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d @order.json
```

That creates a `pending` order. It is not collected until you [make it ready](/docs/order-lifecycle#create-then-make-it-ready).

Three things to notice.

- **`products` and `items` are siblings.** Neither nests inside the other.
- **There are no coordinates in this payload.** A resolvable text address is enough.
- **Prepaid orders carry no amount on create.** The payload above omits `payment_amount` entirely. Only `paid_on_delivery` needs one.


## The response

The response wraps the order. The field you need from it is `order.id`:

```json
{
  "order": {
    "id": 10000001,
    "uuid": "00000000-0000-4000-8000-000000000001",
    "state": "pending",
    "partner_order_id": "PARTNER-ORDER-ID",
    "tracking_url": "https://track-parcel.quiqup.com/00000000-0000-4000-8000-000000000001"
  }
}
```

`order.id` is the `{order_id}` in every later path. `uuid` is what the tracking URL uses. Neither is your `partner_order_id`.

## Top-level fields

| Field | Notes |
|  --- | --- |
| `kind` | The service. See [Service types](/docs/order-lifecycle#service-types). Fixed at creation, so getting it wrong means cancelling and re-booking. An order whose destination country differs from its pickup country becomes an export whatever you send, see [Export orders](#export-orders). |
| `origin` | Where Quiqup collects. |
| `destination` | Where Quiqup delivers. |
| `items` | The **parcels**. One entry per physical box. |
| `products` | The **goods**. Optional domestically, required for export. |
| `partner_order_id` | Your own reference. **Uniqueness is not enforced**, so send something unique yourself. |
| `payment_mode` | `pre_paid` or `paid_on_delivery`. |
| `payment_amount` | Above 1 with `paid_on_delivery`. Omit it on create when prepaid. Changing it after creation is not supported through the API, so check it before you send. Payment is order-level, not per parcel, and collection on delivery is not available on international orders. |
| `billing_identifier` | Omit unless Quiqup set one up for you. See [billing_identifier](#billing_identifier) below. |
| `disallowed_payment_types` | Suppresses a collection method on a `paid_on_delivery` order. Accepts **`cash`** and **`card`**, and nothing else. Any other value returns 422 with an empty message. |
| `scheduled_for` | An ISO 8601 datetime. An unparseable value returns HTTP 400 `[order] creation build failed`. |
| `metadata` | Any JSON object. A string is rejected. Quiqup does not act on it. |
| `required_documents` | Only `[]` is accepted today. Do not send anything else. |
| `ready_for_collection` | Set `true` to skip the separate release call. **Applied asynchronously**: the create response still says `state: pending`, and the order moves a moment later. Do not read the release back off the create response. |
| `notes` | Free text. Declared at top level and on both `origin` and `destination`. Put courier instructions on `destination`, which is where the payload above puts them. |


Four of the fields declared in the contract are worth knowing about even though the payload above leaves them out, because most integrations do not need them: `required_documents`, `disallowed_payment_types`, `scheduled_for`, and `metadata`. All four are in the table above. The contract also declares `notes` in all three places. Use the top-level `partner_order_id` and the `destination.notes` that the payload above uses. Those are the ones support and the courier read.

## Parcels and products

Two levels. Getting them the wrong way round changes how many labels print and how many bags arrive.

|  | What it is |
|  --- | --- |
| **Item** | A **parcel**. One box, one bag, one stash box. A label is generated per item, so each box can be tracked separately. |
| **Product** | A **good**. A SKU. What is inside the boxes. |


**One label per item.** Three boxes means three entries in `items[]` and three labels. Ten garments returned in one bag is **one item and ten products**; send ten items and ten pouches turn up.

For a return you can leave `items` exactly as the example has it: one parcel, quantity 1.

### Item fields

| Field | Notes |
|  --- | --- |
| `name` | A label for the box, for example `Parcel 1`. Nothing rejects a long name at create, but names over about 250 characters can make the order fail later. Keep it short. |
| `quantity` | Always `1`. One entry is one parcel; do not use this to express a count of boxes. |
| `parcel_barcode` | Send an empty string. Quiqup generates the barcode and prints the label. A non-empty value tells Quiqup you are printing your own label, which is a separate arrangement. |


### Product fields

`products` is optional for domestic orders and useful there mainly so support can see what is in the box. **For export orders it is not optional**: the customs fields below are what clears the shipment.

| Field | Notes |
|  --- | --- |
| `sku` | Your stock code. Required for export. Send it on domestic orders too. |
| `quantity` | How many of this SKU. |
| `description` | The product name. Required for export. |
| `barcode` | The product's own barcode. Not the same thing as `parcel_barcode`, which belongs to the box. |
| `selling_price` | In AED. The declared customs value. Required for export. Not printed on the label. |
| `hs_code` | Harmonised System code. Required for export. |
| `country_of_origin` | ISO 3166-1 alpha-2, for example `CN`. Required for export. |
| `weight` | Kilograms. Required for export. |
| `height` / `width` / `length` | Centimetres. Required for export. |


**Weight and dimensions live on the product, not the parcel.** The create response echoes a default parcel weight of `"5.0"` and dimensions of 10 x 10 x 10 cm on every item, whatever you sent, because you never sent them at parcel level. Do not read those values back as confirmation of anything.

**Bundles must be expanded.** A bundle SKU sent as one product fails. Send its components.

**Keep `description` to 18 characters.** Nothing rejects a longer one at create, but export shipments go to several carriers and they do not agree on what they accept, so 18 is the safe ceiling.

**No product field is enforced at create.** A long `description`, products missing any of the export fields, and even `products: [{}]`, are all accepted. The export set is checked when the order is made ready for collection, so a create that succeeds is not a product payload that will ship. See [Export orders](#export-orders).

`selling_price` and `weight` are decimals, `quantity` is an integer, and all of them echo back unchanged.

## billing_identifier

A billing identifier lets one account's credentials create orders that belong to another account. It identifies a **billing account, not a pickup location**.

Quiqup links the two accounts and gives the link its identifier, with the agreement of the account that will own the orders. Then, when your credentials send that identifier:

|  | Who |
|  --- | --- |
| **Owner** of the order | The linked account. It is billed, its `brand_name` is on the order, and `business_partner_id` is its id. |
| **Author** of the order | You, the account whose credentials made the call. |
| Sees the order in Quiqdash and through `GET /orders` | Both of you. |
| Receives its webhooks | The owner's subscriptions, and yours. |


This is how one integration serves several brands: one credential, one identifier per brand. The alternative is a separate account, and a separate credential, per brand. [How credentials are scoped](/docs/authentication#how-credentials-are-scoped) compares the two.

- Omit the field entirely if you do not have one.
- **An identifier that is not linked to your account is accepted and ignored.** The create succeeds, the value is echoed back in `billing_identifier`, and the order belongs to you. Nothing tells you the identifier did not apply, so check `brand_name` on the response the first time you use a new identifier.
- If the identifier cannot be resolved, the create returns `400` with `[order][billing_identifier] invalid`. Retry once, then send the value to support.
- An order that sends no billing identifier gets `null` back.
- Having one does not remove the need for a full pickup address on every order. Branch addresses are not defaulted from the account.
- Links are set up per environment. An identifier that works on staging has to be set up again for production.


## Address object

The same fields on `origin.address` and `destination.address`.

| Field | Notes |
|  --- | --- |
| `address1` | Street and building. Required on both `origin` and `destination`. |
| `address2` | Unit, floor, apartment. |
| `town` | For example `Dubai`. |
| `country` | An ISO 3166-1 alpha-2 code: `AE` for the UAE, `SA` for Saudi Arabia. See [Country codes](#country-codes). |
| `coords` | Optional. `[longitude, latitude]`, so Dubai is `[55.1631, 25.0560]` with the larger number first: `[55.1372, 25.0805]` resolves to Dubai Marina. **Reversed coordinates are accepted without an error** and come back with a null zone and a null emirate, so nothing tells you it went wrong. |


**What is actually required:**

| Required | Not required |
|  --- | --- |
| `origin.contact_name`, `origin.contact_phone`, `origin.address.address1` | `kind`, which defaults to `partner_same_day` |
| `destination.contact_name`, `destination.contact_phone`, `destination.address.address1` | `payment_mode`, which defaults to `pre_paid` with `payment_amount` `0.0` |
| `items`, with at least one entry carrying a `name` | everything else in the payload above |


Older versions of this contract marked only `origin.address.address1`. The set above is what the API enforces.

### Country codes

**Send the two-letter ISO code**, `AE`, `SA`, `KW`, `BH`, `QA` or `OM`. Two other spellings are converted: `UAE` is stored as `AE`, and a full English country name, such as `Saudi Arabia`, is stored as its code. Anything else is stored exactly as sent. `KSA` stays `KSA`, and an export with it is refused at release with `Invalid country code`.

`GET /orders/{order_id}`, the list and webhooks return the stored value, so an order sent with `UAE` comes back with `AE`. Nothing validates the country at create.

**A destination country that differs from the pickup country makes the order an export**, whatever `kind` you send. See [Export orders](#export-orders).

**There is no postcode field and no emirate field.** If you are sending either, you are sending something this API does not declare. If you hit a postcode validation error, ask support rather than adding a field the contract does not declare.

Responses and webhooks return a different shape, `coordinates: {lat, lng}`, so what comes back will not look like what you sent.

## Contact fields

`contact_name` and `contact_phone` sit on both `origin` and `destination`, not inside `address`. `contact_email` is declared on `destination` only.

Phone numbers are E.164 with a single `+`. A dropoff number that is not a valid UAE number, or that carries a doubled `++`, is the most common reason an otherwise good order fails.

**On an international order, `contact_email` is required, and it is checked at release rather than at create.** An international order with no destination email is accepted by `POST /orders` and rejected on `PUT /orders/{order_id}/ready_for_collection`, which is why the field looks optional right up until it is not. Domestic orders do not need it.

Quiqup does not send customer tracking emails today. The field is there for the export paperwork, and notifications may use it later.

## Export orders

Exports to GCC destinations go through the same `POST /orders`. The account needs the international terms signed and the export service enabled first.

An order is an export in either of two cases:

- you send `kind: partner_export`, or
- the destination `country` differs from the pickup `country`. The order is then created as an export whatever `kind` you sent, and the response shows `service_kind: partner_export`. Returns and `partner_store` orders are never converted.


**An export is checked when it is made ready for collection, not when it is created.** `POST /orders` accepts it with any payload. `PUT /orders/{order_id}/ready_for_collection` then books it with the carrier, and refuses with `422` if anything the carrier needs is missing. The `error` string starts `Failed to book shipment for order, please fix missing details in the order` and names each problem with its field, for example `Invalid country code (field: dropoff.country, code: required_data_invalid)`, or `must not be empty (field: items, ...)` when `products` is missing.

The order stays `pending`, so you can cancel it and create it again with the missing fields.

What an export needs:

| Where | Fields |
|  --- | --- |
| `destination` | `contact_email`, and an ISO `country` code, see [Country codes](#country-codes) |
| Every entry in `products` | `sku`, `description`, `quantity`, `selling_price`, `weight`, `height`, `width`, `length`, `hs_code` and `country_of_origin`. See [Product fields](#product-fields). |


Collection on delivery is not available on exports.

## Every operation

Eight order operations, plus `POST /oauth/token` on the Auth reference if you use client credentials.

| Operation | What it does |
|  --- | --- |
| `POST /orders` | Create an order. The page above. |
| `GET /orders` | List your orders. Requires `page`, `per_page`, `from` and `to` (both `YYYY-MM-DD`). `filters[state]` and `filters[partner_order_id]` are optional. `filters[state]` takes the lowercase `state` strings from [Order statuses and their events](/docs/order-lifecycle#order-statuses-and-their-events); an unrecognised or wrongly-cased value returns 200, no results, and `total: 1`. **Pending orders are left out unless `filters[state]` asks for them**, and that includes a search by `filters[partner_order_id]`. |
| `GET /orders/{order_id}` | Retrieve one order. Current state only. |
| `GET /order_label/{order_id}` | Download the AWB (air waybill, the shipping label) as a PDF. **Check the content length**, see below. |
| `PUT /orders/{order_id}/ready_for_collection` | Release a pending order to operations. |
| `POST /orders/{order_id}/parcels` | Add a parcel to a pending order. |
| `DELETE /orders/{order_id}/parcels/{parcel_id}` | Remove a parcel from a pending order. The last remaining parcel cannot be removed. |
| `PUT /orders/batch/set_cancelled` | Cancel orders in bulk, while they are `pending` or `ready_for_collection`. |


Adding or removing parcels works only while the order is `pending`. No other change to an order is supported through the API: to correct the payment or the service, cancel it while it is `pending` and create it again. Address corrections are made in Quiqdash. Cancelling works until a courier is on the way to collect: from `out_for_collection` onwards the API refuses it, and cancellation moves to Quiqdash.

### The calls, with bodies

Every path below takes the same `Authorization: Bearer YOUR_TOKEN` header. `{order_id}` is `order.id` from the create response.

```bash
# Release a pending order. No body.
curl -X PUT 'https://api.staging.quiqup.com/orders/{order_id}/ready_for_collection' \
  -H 'Authorization: Bearer YOUR_TOKEN'

# Download the AWB. Returns a PDF, so write it to a file.
curl 'https://api.staging.quiqup.com/order_label/{order_id}' \
  -H 'Authorization: Bearer YOUR_TOKEN' -o label.pdf

# Current state.
curl 'https://api.staging.quiqup.com/orders/{order_id}' \
  -H 'Authorization: Bearer YOUR_TOKEN'

# Cancel orders that are pending or ready for collection. Takes a list of ids.
curl -X PUT 'https://api.staging.quiqup.com/orders/batch/set_cancelled' \
  -H 'Authorization: Bearer YOUR_TOKEN' -H 'Content-Type: application/json' \
  -d '{"order_ids": [10000001]}'

# Add a parcel to a pending order.
curl -X POST 'https://api.staging.quiqup.com/orders/{order_id}/parcels' \
  -H 'Authorization: Bearer YOUR_TOKEN' -H 'Content-Type: application/json' \
  -d '{"name": "Parcel 2"}'   # name is the only field it reads

# Remove a parcel. The last remaining parcel cannot be removed.
curl -X DELETE 'https://api.staging.quiqup.com/orders/{order_id}/parcels/{parcel_id}' \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

Swap `api.staging.quiqup.com` for `api-ae.quiqup.com` on production.

### Return values that will catch you out

**The label endpoint has no 404.** An order id that does not exist returns `200`,
`Content-Type: application/pdf`, and a **zero-byte body**. If you stream the response straight to a
file you get an empty PDF and no error. Check the content length before you write it.

**Unknown fields are accepted and ignored.** A misspelled field name, anywhere in the payload, does
not produce an error on any of these endpoints. A field that had no effect and a field you spelled
wrong look identical from the outside, so check the response for the value you meant to set rather
than assuming the call did what you asked.

`POST /orders/{order_id}/parcels` reads **`name` and nothing else**. An empty body returns `[name] is missing`, and a `quantity` of 2 is accepted and silently ignored, with the parcel created at quantity 1.

## Retrying safely

**Send a `uuid` on every create.** Generate a UUID for each order, put it at the top level of the payload, and send the same value again on a retry. Resending the same `uuid` from the same account returns the original order with HTTP **200** instead of 201, and no second order is created. A `uuid` that is not a valid UUID, or that another account already used, is rejected with 422.

```json
{
  "uuid": "9b2f6c1e-4d7a-4c1b-8e3f-2a5d6b7c8e90",
  "kind": "partner_same_day",
  "...": "the rest of the order"
}
```

Without a `uuid`, order creation is **not idempotent**. `partner_order_id` does not enforce uniqueness, so a retried create produces a second order and a second AWB. The rest of this section is for that case.

**`GET /orders` leaves pending orders out unless you ask for them.** With no `filters[state]`, the list returns every state except `pending`, and `filters[partner_order_id]` on its own does the same, so a search by reference returns `total: 0` for a pending order that exists.

A create that timed out leaves a `pending` order. That is precisely the case a plain reference search cannot see, so using it as a pre-retry check tells you the order does not exist when it does, and you create a duplicate.

**What works instead.** Ask for pending orders explicitly, together with your reference:

```bash
curl 'https://api.staging.quiqup.com/orders?page=1&per_page=10&from=2026-08-01&to=2026-08-31&filters%5Bstate%5D=pending&filters%5Bpartner_order_id%5D=PARTNER-ORDER-ID' \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

A `total` of 1 or more means the create landed. The reference filter matches **any reference that contains** the value you send, ignoring case, so `ORDER-1` also matches `ORDER-10`. Check `partner_order_id` on each result before you decide. Cover the day the create was sent in `from` and `to`.

Log the response, or the absence of one, at the moment you send the create.

Once you have an order id, `GET /orders/{order_id}` shows its current `state`, and your [webhook receiver](/docs/webhooks) records every transition from then on.