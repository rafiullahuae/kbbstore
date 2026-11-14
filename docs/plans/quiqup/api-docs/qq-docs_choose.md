# Which API do I need

One question decides it: **who holds the stock the moment the order is placed?**

If Quiqup holds it, you are on **Fulfilment**. If you hold it, you are on **Last-mile**.

## By symptom

| What you are doing | API |
|  --- | --- |
| Sending Quiqup a list of products and their SKUs | Fulfilment |
| Booking a container or a pallet into a Quiqup warehouse | Fulfilment |
| Checking how many units of a SKU are on the shelf | Fulfilment |
| Creating an order and letting Quiqup pick it from stock | Fulfilment |
| Creating an order and telling us where to collect the parcel | Last-mile |
| Installing the Shopify or WooCommerce connector | Last-mile |
| Printing a label for a parcel you already packed | Last-mile |
| Booking a return from a customer back to your own warehouse | Last-mile |


## Tell from the account

You do not choose this per order. It follows your commercial agreement.

If your contract is for storage and pick-and-pack, your account is set up for fulfilment and the fulfilment endpoints will answer. If it is for delivery only, they will not.

The quickest test: call `GET /api/fulfilment/inventory` on `platform-api.quiqup.com` with your token. A fulfilment account returns a list, empty or not.

## Using both

Some merchants do. Quiqup fulfils part of the catalogue and the merchant ships the rest itself.

That works, and nothing extra is needed to set it up:

- The same credential authenticates both hosts. See [Authentication](/docs/authentication).
- Both produce orders that run the same state machine. See [Order lifecycle](/docs/order-lifecycle).
- Webhook subscriptions are registered per environment and per account, and `order.*` is emitted by both products. Fulfilment adds `inbound.*` and `inventory.*` on top.


What is not shared is the error envelope. The two hosts return different shapes and you have to handle both. See [Error responses](/docs/errors).

## The one thing that catches people out

A **fulfilment order** and a **last-mile order** are different objects with different IDs, created by different endpoints, and you cannot pass one to the other's endpoints.

A fulfilment order does produce a last-mile shipment once it is picked. You track it with `tracking_url` from the fulfilment order, not by looking it up on the last-mile API.