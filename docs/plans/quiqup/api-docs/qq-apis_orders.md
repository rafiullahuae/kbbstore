# Last-mile Orders API

Create and manage Last-mile orders on `api-ae.quiqup.com`.
The order operations in this reference are generated from the API's own code, so they always describe what the API accepts and returns. For a walkthrough, see [Create an order](/docs/lastmile/create-an-order).
Two responses are not JSON: the label download returns a PDF, and the webhook is a request Quiqup sends to you, shown as a callback on Create an order. See [Webhooks](/docs/webhooks).

Version: 1.0.0
License: Proprietary

## Servers

Production
```
https://api-ae.quiqup.com
```

Staging
```
https://api.staging.quiqup.com
```

## Security

### bearerToken

A Bearer token generated in Quiqdash under Organization > API keys. The usual path.

Type: http
Scheme: bearer

### oauthToken

Client credentials, issued by Quiqup on request. Only needed by platforms whose setup screens require a Client ID and Client Secret.
The token URL follows the environment: `https://api.staging.quiqup.com/oauth/token` on staging, `https://api-ae.quiqup.com/oauth/token` on production.

Type: oauth2
Token URL: https://api.staging.quiqup.com/oauth/token
Scopes:

## Download OpenAPI description

 - [Last-mile Orders API](https://developer-docs.quiqup.com/_bundle/apis/orders.yaml)

## Orders

 - [GET /order_label/{order_ids}](https://developer-docs.quiqup.com/apis/orders/orders/download-the-awb-label.md): Returns the AWB label for one order, or for several in one PDF. Save it, print it and attach it to the parcel: couriers and warehouse staff scan its barcode at every step. You can download it before o
 - [GET /orders](https://developer-docs.quiqup.com/apis/orders/orders/list-orders.md): Returns one page of the orders submitted between `from` and `to`, newest first. You see the orders of your account and of the other users on the same business account. **Pending orders are left out un
 - [POST /orders](https://developer-docs.quiqup.com/apis/orders/orders/create-an-order.md): Creates an order in the `pending` state. A pending order shows in Quiqdash but is not dispatched: mark it `ready_for_collection` (`PUT /orders/{id}/ready_for_collection`) once the parcel can be collec
 - [PUT /orders/batch/set_cancelled](https://developer-docs.quiqup.com/apis/orders/orders/cancel-pending-orders.md): Cancels one or more of your orders. A last-mile order can be cancelled while it is `pending` or `ready_for_collection`; a fulfilment order only while it is `pending`. Once a courier has been assigned
 - [POST /orders/{client_order_id}/parcels](https://developer-docs.quiqup.com/apis/orders/orders/add-a-parcel-to-a-pending-order.md): Adds one parcel to a `pending` order and returns the new parcel on its own, not the whole order. A label is generated per parcel. Once the order has left `pending` the call is rejected with 422.
 - [DELETE /orders/{client_order_id}/parcels/{core_api_item_id}](https://developer-docs.quiqup.com/apis/orders/orders/remove-a-parcel-from-a-pending-order.md): Removes one parcel from a `pending` order. An order must keep at least one parcel, so removing the last one is rejected with 422. Takes no request body.
 - [GET /orders/{id}](https://developer-docs.quiqup.com/apis/orders/orders/retrieve-an-order.md): Returns one order, wrapped in `order`. You can read the orders your account created; any other id, including one that does not exist, returns 403.
 - [PUT /orders/{id}/ready_for_collection](https://developer-docs.quiqup.com/apis/orders/orders/mark-ready-for-collection.md): Releases a `pending` order to Quiqup: from now on it is planned for collection. Call it once the parcel is packed and labelled. Orders left `pending` are never collected. You can skip this call by sen
