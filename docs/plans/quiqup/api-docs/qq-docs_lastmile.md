# Last-mile

**Applies to both the connectors and the direct API.**

You hold the stock. You pack the parcel. Quiqup collects it from an address you name and delivers it to your customer.

Three ways in. Almost everything else in this section depends on which one you pick.

|  | Who builds it | What you configure |
|  --- | --- | --- |
| **Shopify connector** | Nobody. Install it. | Delivery method mapping, pickup location, in Quiqdash |
| **WooCommerce connector** | Nobody. Install it. | Delivery method mapping, pickup location, in Quiqdash |
| **Direct API** | Your developer | Everything, per order, in your own code |


Shopify or WooCommerce: start with the connector. No developer needed. It still needs a pickup location and a delivery-method mapping set up in Quiqdash.

Everything else needs the API. Any stack that can make an HTTPS request will do, including mobile apps and no-code builders.

There is no plugin for Magento, Wix, Salla, Zid, Squarespace, Ecwid or a POS system. Those go through the API, or through a middleware partner such as Carriyo, ClickPost or Unicommerce.

**A default pickup location is a connector requirement, not an account one.** The connector has one place to collect from and reads it off the account, so without it the connection will not establish. A direct API integration sends a full pickup address on every order instead, and never falls back to an account default.

## If you are using a connector

1. Sign in to Quiqdash and add your pickup location. There is more than one route to it: the connector setup flow asks for one, the first manual order creation asks for one, and you can add them directly under address management.
2. **Integrations →** your platform. Install the connector from that page, not from a plugin file.
3. Connect your store.
4. **Map every delivery method** to a Quiqup service, on the connector's own settings page under **Integrations →** your platform. Orders on a method you leave unmapped are sent as Next Day; map it to **Do not read orders** to keep them out. [Unmapped methods](/docs/lastmile/integration-orders#delivery-methods-you-have-not-mapped)
5. Place a test order and check it appears.


You handle no credentials. The connector authenticates itself. Delivery rates are not pushed to your checkout, so enter them by hand in your shop's own shipping settings and map each one.

From there, use [Integration Orders](/docs/lastmile/integration-orders) whenever an order does not arrive.

## If you are building on the API

1. Sign in to the staging dashboard. Your existing Quiqup login works there: the login is the same on both environments. [Environments](/docs/environments) has the hosts. If the services you need are not enabled on staging, ask your account manager.
2. **Generate a Bearer token** in Quiqdash. See [Authentication](/docs/authentication).
3. **Create a test order** against `https://api.staging.quiqup.com`, sending a full pickup and dropoff address. See [Create an order](/docs/lastmile/create-an-order).
4. **Register your webhook** in Quiqdash ([how](/docs/webhooks#register-your-endpoint)), then drive the order through its states. See [Testing on staging](/docs/lastmile/testing).
5. **Go live.** Switch the base URL to `https://api-ae.quiqup.com` and register your production webhook. See [Going live](/docs/lastmile/testing#going-live).


The minimum working integration is three calls: [create an order](/docs/lastmile/create-an-order), mark it [ready for collection](/docs/order-lifecycle#create-then-make-it-ready), and download the label with `GET /order_label/{order_id}`. Two, if you set `ready_for_collection: true` on the create and skip the release call, remembering that the create response still reports `pending`. Client credentials add one call before any of these. A Quiqdash token adds none.

Add a [webhook receiver](/docs/webhooks) of your own if you want status updates pushed rather than polled.

## What Quiqup supports

All three routes are built for a **single merchant shipping from its own locations**. That is the whole supported model.

Not supported:

- C2C and peer-to-peer delivery
- Multi-vendor marketplaces where individual sellers ship
- Click-and-collect on a separate cycle


The API can send a different pickup address per order, but the parcels still have to be your parcels. If you are building a marketplace, talk to your account manager before you build.

Amazon and Noon have no direct integration. The supported route is importing marketplace orders into a connected Shopify or WooCommerce store, then entering the Quiqup tracking number back on the marketplace by hand.

## What no route does

|  |  |
|  --- | --- |
| Live shipping rates at checkout | Neither the connector nor the API returns rates. You enter them by hand in your shop. |
| Update an order after creating it | No API endpoint, beyond adding and removing parcels while it is `pending`. To correct the payment or the service, cancel it while it is `pending` and create it again. |
| Invoices and final charges | Dashboard only. |
| Serviceability check | No endpoint tells you whether an address is deliverable before you create the order. |
| Official SDKs | None in any language. |


## Pages in this section

- [Create an order](/docs/lastmile/create-an-order). The payload, field by field, and what is actually required.
- [Testing on staging](/docs/lastmile/testing). A complete test pass, and going live.
- [Integration Orders (connectors)](/docs/lastmile/integration-orders). When a connector order does not arrive.
- [Last-mile API reference](/apis/orders). Every operation and the webhook payload.