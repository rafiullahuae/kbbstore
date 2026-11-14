# Common questions

Two parts. The first is for anyone running a shop. The second is for whoever writes the code.

Almost every answer depends on **how you connect**, so each section says which route it applies to. The three routes are the Shopify connector, the WooCommerce connector, and a direct API integration.

**Everything below is last-mile** unless a question says otherwise. Fulfilment questions are answered in that section: [Fulfilment](/docs/fulfilment). If you are not sure which one you are on, [Which API do I need](/docs/choose).

## Part 1: for merchants

### Choosing how to connect

#### How do I get started with Quiqup deliveries?

1. Create a **business** account. Individual accounts cannot be connected.
2. Speak to your account manager. A commercial agreement comes before credentials.
3. Get the account activated: trade licence, bank card, finance details.
4. Have the services you need switched on. Same Day, Next Day, 4-hour and international are each enabled separately.
5. Connect your shop, or have a developer build against the API.
6. Test on staging, then go live.


[Full walkthrough](/docs)

#### Do I need a developer?

Not if your shop runs on **Shopify or WooCommerce**. Install the connector from Quiqdash, then map your delivery methods.

For anything else, yes. The API works from any system that can send an HTTPS request, but someone has to write that code.

#### My shop is on Magento, Wix, Salla, Zid, Squarespace, Ecwid or something else. Can I use Quiqup?

Yes, but there is no ready-made plugin. Only Shopify and WooCommerce have one. Everything else needs a developer, or a middleware partner such as Carriyo, ClickPost or Unicommerce.

#### Do I need to talk to sales first?

Yes. A commercial conversation and a signed agreement come before you go live. Start that early rather than at the end, because commercial sign-off is a gate on go-live.

#### Do I need an integration at all?

Not necessarily. If you send a handful of orders a day, create them by hand in Quiqdash.

Moving to the API later keeps the same account. You do not start over.

### If you use a connector

*Shopify and WooCommerce.*

#### What do I need on my account before the connector will work?

- A **default pickup location**. The connector collects from one place and reads it off the account, so without it the connection will not establish.
- Every **delivery method mapped** to a Quiqup service.
- The services you are mapping to, **enabled** on the account.


#### My orders are not coming through. Where do I look?

**Quiqdash → Integrations → Integration Orders.**

It lists every order from your shop, whether it reached Quiqup, and the reason if it did not. It also carries a **Repair Orders** action. Check this before emailing anyone.

[What the reasons mean](/docs/lastmile/integration-orders)

#### My orders were syncing and suddenly stopped. Why?

Something broke the connection between the two platforms. Four known causes, all connector-side, and nobody is notified when it happens. [What breaks a connection](/docs/lastmile/integration-orders#when-a-working-connection-stops)

Check the Integration Orders page, then reconnect.

#### Why do some of my orders never appear at all?

Check the Integration Orders page with the filter on **All**: every order the connector saw is there, including the ones it did not send. A delivery method mapped to **Do not read orders** shows its orders as Failed, by design. A method you never mapped does not hide orders: they are sent as Next Day, so they arrive, possibly on the wrong service. [Unmapped delivery methods](/docs/lastmile/integration-orders#delivery-methods-you-have-not-mapped)

Map **every** shipping method in your shop to a Quiqup service before you go live.

Orders placed before you configured the connector are also never fetched. There is no backfill.

#### Can I collect from more than one warehouse?

Not through a connector. One connection maps to one pickup location. Multi-warehouse pickup is one of the reasons to build on the API instead, which sends a pickup address per order.

#### Will Quiqup show live shipping rates at my checkout?

No. The connector does not push rates.

You enter your shipping charges by hand in your own shop's shipping settings, then map each one to a Quiqup service. This applies to domestic, weight-based and international rates alike.

### If you build on the API

#### Do I need a pickup address on my account?

No. Every order carries its own full pickup address, and the API never falls back to an account default. That is what lets an API integration collect from several locations, which a connector cannot.

#### My orders are not reaching Quiqup. Where do I look?

There is no intermediate page for API orders. If the call returned 2xx, the order exists in Quiqup.

The usual cause is that the order is still `pending`, which means `ready_for_collection` was never called. A pending order is invisible to operations and will never be collected.

[The checklist](/docs/troubleshooting#my-orders-are-not-reaching-quiqup)

#### It was working and stopped. Why?

Nothing exists on the connection to expire, so it is not the same failure as a connector dropping out. Four causes: your key hit the **Expiration** you chose when you created it, credentials were rotated, an account capability was switched off, or your own deploy changed the payload. Check the expiry first, it is the cheapest to rule out.

#### Will the API give me shipping rates?

No. There is no quote or rate endpoint anywhere in the API.

### Whichever route you take

#### Can I use Quiqup for Amazon or Noon orders?

Not directly. Import the marketplace orders into your Shopify or WooCommerce store, ship them through the connector, then enter the Quiqup tracking number back on the marketplace by hand.

#### I am building a marketplace where different sellers ship. Does that work?

No. Quiqup supports a single merchant shipping from its own locations.

Not supported: C2C, peer-to-peer, and multi-vendor marketplaces. [What Quiqup supports](/docs/lastmile#what-quiqup-supports) is worth reading before you build anything.

#### Do I need an account to read the documentation?

No. It is public, no login and no key.

#### Who do I contact when something breaks?

[apisupport@quiqup.com](mailto:apisupport@quiqup.com). Email only. [What to include](/docs/getting-help)

For pricing, adding services or activating your account, go to your account manager instead.

[What to put in the first email](/docs/getting-help#what-to-send-in-the-first-email)

#### Which emirates does Quiqup cover?

Coverage differs by service and changes, so ask your account manager for the current list rather than inferring it from the API. An order for an emirate outside coverage is **accepted at create** and stops later, so the API will not tell you.

#### What are the cut-off times?

Ask your account manager for the table that applies to your services, and check the Cutoff Times page in Quiqdash. Every service has one. An order placed after cut-off is **accepted and collected on the next cycle**, not rejected, which is why orders sometimes arrive later than expected without any error.

#### Can I ship outside the UAE?

Yes, to GCC destinations. Two things have to happen first: sign the international terms and conditions, and have the export service activated on your account.

Exports use the same `POST /orders`. Send `kind: partner_export`, or simply a destination country other than the pickup country: the order becomes an export either way.

Every export order also needs a destination `contact_email`, an ISO `country` code such as `SA`, and a populated `products` array with the customs fields on every product. [What an export needs](/docs/lastmile/create-an-order#export-orders).

**None of that is checked at create.** The order is accepted, and the missing fields are refused with a `422` naming them when you mark it ready for collection. The order is still `pending` then, so cancel it and create it again, ideally before the customer has paid.

## Part 2: for developers

*Everything below is the direct API. If you use a connector, none of it applies: the connector handles credentials, order creation and webhooks itself.*

### How do I authenticate?

Two options, and you only need one.

**Generate a Bearer token in Quiqdash** and send it as `Authorization: Bearer <token>`. No exchange step, and you can set it to never expire. This is the default.

**Or exchange a `client_id` and `client_secret`** at `POST /oauth/token` for an access token that lasts seven days, then send that.

Both grant identical access. If you have a Quiqdash token you never call `/oauth/token`.

[Full comparison](/docs/authentication)

### Can I generate a Client ID and Client Secret myself?

No. Those are issued by Quiqup on request and are the one part of access that still needs a human.

You probably do not need them. They do nothing a Bearer token cannot. They exist for middleware and carrier platforms whose setup screens ask for a Client ID and Client Secret and cannot accept a token.

[What to send in the request](/docs/authentication#requesting-a-pair)

### What are the base URLs?

Last mile is `https://api.staging.quiqup.com` and `https://api-ae.quiqup.com`. Fulfilment accounts use different hosts, and the dashboard URLs are separate again.

[The full table, including which dashboard host is current](/docs/environments#base-urls)

If you see `stoplight.io/mocks` anywhere, that is a mock server returning fake data. Replace the host.

### Are staging and production credentials interchangeable?

Treat them as not. A Quiqdash key comes from the login service both environments share, so it identifies the same account on either host; generate a production key on the production dashboard and use each key only on the host it was made for. A client id and secret, and the tokens exchanged from them, are per environment: production rejects a staging pair or token with `401`, which reads like a bad key rather than a routing mistake. Nothing in a response says which environment answered, so the base URL decides whether an order is real. [Which credentials cross environments](/docs/environments#which-credentials-cross-environments)

### Why does `/oauth/token` return `invalid_client`?

Three causes: id and secret swapped, credentials pointed at the wrong environment, or a `client_id` that has not been activated yet. A `client_id` ending `.apps.quiqup.com` is the correct current format.

[Full error table](/docs/authentication#authentication-errors)

### I get "You are not authorized" and my token is valid. Why?

It is rarely the token. Several capabilities are enabled per account and fail this way when off: payment modes, label generation, `ready_for_collection`, international export.

### Do I have to call `ready_for_collection`?

Yes, or the order never leaves `pending` and Quiqup operations never see it.

Three ways to do it: the `PUT` call, `ready_for_collection: true` at create time (applied asynchronously, so the create response still says `pending`), or account-level automatic release (auto-RFC, ready for collection on create).

An integration that stops at create produces "the API returned success but nothing happened".

[Details](/docs/order-lifecycle#create-then-make-it-ready)

### Do I send a pickup address on every order?

Yes. There is no account-level default for API orders, and a `billing_identifier` does not supply one either. Send a full pickup address every time.

### What are the valid `kind` values?

On last-mile the field is `kind`: `partner_same_day`, `partner_next_day`, `partner_4hr`, `partner_return`, `partner_export`. `partner_store` is also accepted and resolves to the same-day class.

On fulfilment the field is `service_kind`. See [Fulfilment orders](/docs/fulfilment/orders#service-kinds).

`kind` decides the service and **cannot be changed after creation**. A wrong one has to be cancelled and re-booked.

[Service table](/docs/order-lifecycle#service-types)

### What is the full list of order states?

The wire field is `state`. [One table mapping every state to its webhook event](/docs/order-lifecycle#order-statuses-and-their-events).

Handle unknown values gracefully. `order.error` is subscribable, though no last-mile order enters `error` today.

You drive two transitions through the API: out of `pending` with `ready_for_collection`, and to `cancelled` while the order is `pending` or `ready_for_collection`. Every other state is set by operations and the courier application.

### I got a 2xx and an order id, but nothing is in the dashboard.

Pending orders are listed, under **Pending**, so it is not that. In order: is it the right environment, does the dashboard user belong to the same account as the token, and does the date range cover the order. [Where your orders appear](/docs/environments#where-your-orders-appear)

If the order is there but never moves, it is still `pending`: it was never made ready for collection.

### Which address fields are required, and what about postcode?

The contract declares `address1`, `address2`, `coords`, `town` and `country`. `address1` is required on both origin and destination. `country` is a two-letter ISO code, see [Country codes](/docs/lastmile/create-an-order#country-codes). **There is no postcode field and no emirate field.** [The full field table](/docs/lastmile/create-an-order#address-object)

### Which way round are the coordinates?

The request field is `coords`, and it is `[longitude, latitude]`. Dubai is `[55.16…, 25.05…]`: the larger number comes first.

Responses and webhooks use a different shape, `coordinates: {lat, lng}`. [Why](/docs/lastmile/create-an-order#address-object)

### What phone format?

E.164 with a single `+`, on `contact_phone`. See [Contact fields](/docs/lastmile/create-an-order#contact-fields).

The customer's number is not printed on the label, for privacy.

### How do I set cash on delivery?

Two modes. `paid_on_delivery` needs `payment_amount` above 1. `pre_paid` needs no amount on create. [Top-level fields](/docs/lastmile/create-an-order#top-level-fields)

`cash_on_delivery` and `card_on_delivery` are retired. They show up in older documentation and in inherited integrations. Do not build against them.

Payment is order-level, not per parcel. COD is not available on international orders.

### What is `billing_identifier` and do I need one?

It identifies a **billing account**, not a pickup location, and lets one credential set create orders that belong to another account, which Quiqup has linked to yours. If you do not have one, **omit the field entirely**: a value that is not linked is accepted and ignored, and the order is created in your own account. [billing_identifier](/docs/lastmile/create-an-order#billing_identifier)

### Is order creation idempotent?

**Yes, if you send a `uuid`.** Resending the same `uuid` from the same account returns the original order with HTTP 200, and no second order is created. A malformed `uuid`, or one another account already used, is rejected with 422.

**Without one, no.** `partner_order_id` does not enforce uniqueness, so a retried create produces a second order and a second AWB (air waybill, the shipping label). And a reference search on its own will not save you: `GET /orders` leaves out `pending` orders, which is what a timed-out create leaves behind, unless you also send `filters[state]=pending`. [How to retry safely](/docs/lastmile/create-an-order#retrying-safely).

### How do I register a webhook?

**Quiqdash → Integrations → Webhooks → New subscription.** HTTPS URL required. The screen also does edit, delete, pause and send-test.

### Do I have to register separately for staging and production?

Yes. A staging registration never carries over. Everything passes on staging, then the first real order produces no callbacks at all.

### How do I verify a webhook signature?

Your secret is on the webhook's configuration page in Quiqdash. The signature is:

```
HMAC-SHA256(your_secret, "{timestamp}.{raw_body}")
```

Hex-encoded, no prefix, in `X-Quiqup-Signature`, which every delivery carries. Some older subscriptions also receive a deprecated `x-signature: sha1=` header; verify `X-Quiqup-Signature` instead. [The headers and the formula](/docs/webhooks#verifying-a-request).

### Can I get an order's full history?

Not from the API. `GET /orders/{order_id}` returns the current `state` only. The trail of transitions is your webhook events, each carrying `state_updated_at`, so store them as they arrive. [Webhooks](/docs/webhooks)

### Which events fire?

One named event per transition. Three families: `order.*`, plus `inbound.*` and `inventory.*` for fulfilment. `order.ready_for_collection` does fire, so build for it. [The full list, mapped to order states](/docs/order-lifecycle#order-statuses-and-their-events).

### I am not receiving webhooks.

Check **Quiqdash → Integrations → Webhooks → Delivery logs** first. It shows every attempt with its status, HTTP code and error, and you can replay failed ones yourself.

If the log is empty: is a subscription active on *this* environment, is the URL HTTPS, and did you register before the order was created. Registration is not retroactive.

[Full checklist](/docs/webhooks#not-receiving-webhooks)

### Should I poll or use webhooks?

Webhooks. `GET /orders/{order_id}` returns the current state only, so a poller misses everything that happens between samples.

A failed delivery can be replayed from the delivery log. [Replaying failed deliveries](/docs/webhooks#replaying-failed-deliveries)

Make your receiver idempotent. Events can be redelivered and can arrive out of order, and the payload carries no event id. [How to deduplicate](/docs/webhooks#what-your-endpoint-must-return)

### I downloaded a label and the PDF is empty.

Check the order id. The label endpoint returns `200` and an `application/pdf` content type even for
an order that does not exist, with a zero-byte body. An empty PDF almost always means the id was
wrong, not that the label failed. [The label call](/docs/lastmile/create-an-order#return-values-that-will-catch-you-out)

### How do I create a return?

Same `POST /orders` endpoint with `kind: partner_return`. Pickup is the customer's address, dropoff is yours.

### What is the difference between items and products?

An **item** is a parcel: a box or a bag. A **product** is a good. They are two separate top-level arrays, not one nested inside the other.

One label is produced **per item**, so each box can be tracked. Ten garments in one bag is one item and ten products. Send ten items and ten pouches turn up.

`products` is optional for UAE orders. For export it is not: the HS code, country of origin, weight and dimensions that clear customs all live there.

[The payload, with both arrays](/docs/lastmile/create-an-order#the-payload)

### My orders are erroring and I cannot see why.

Neither a missing product `sku` nor a very long item `name` is rejected at create, so if either causes trouble it does so further down the line.

### Where do weight and dimensions go?

On the **product**, not the parcel. The create response echoes a default 5.0 kg and 10 x 10 x 10 cm per parcel whatever you sent. [Product fields](/docs/lastmile/create-an-order#product-fields)

### Can I update an order after creating it?

Barely. While the order is `pending` you can add and remove parcels, and that is all. [Every operation](/docs/lastmile/create-an-order#every-operation)

To correct the payment or the service, cancel the order while it is `pending` and create it again. You cannot change an address through the API at all; that is done in Quiqdash. A change in your own system never reaches Quiqup.

### How do I cancel an order?

`PUT /orders/batch/set_cancelled` cancels orders in bulk while they are **`pending` or `ready_for_collection`**.

Once a courier is on the way to collect, from `out_for_collection` onwards, the API cannot cancel it. Cancel it in Quiqdash, and expect to talk to support. This matters because the fix for several other problems is "cancel and re-book", and that instruction only works through the API until collection starts.

### What does `CanSubmitJobPolicy failed policy check` mean?

One string covering several unrelated commercial restrictions: segmentation, a closed tier, Quiqdash closed for high demand, the hourly order limit, or a 3PL (third-party logistics partner) not accepting orders.

None of them are payload bugs. Debugging your request will not help.

[Which ones a retry fixes](/docs/errors#cansubmitjobpolicy-failed-policy-check)

### Is there a Postman collection or an SDK?

There is no SDK in any language, and no maintained Postman collection. Each API reference page has a download button for its OpenAPI file, which Postman and most HTTP clients import directly.

Avoid collections exported from the old Stoplight documentation: they point at a [mock server](/docs/environments#the-docs-mock-server-is-not-an-environment).

### How do I test properly before going live?

Everything on staging. You can drive a staging order through its statuses yourself from the dashboard, so you can exercise your webhook receiver without asking anyone.

[The twelve scenarios to run](/docs/lastmile/testing#a-complete-test-pass)