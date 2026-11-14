# Start here

Quiqup has two APIs. They are different products, on different hosts, and most integrations only need one.

|  | **Fulfilment API** | **Last-mile API** |
|  --- | --- | --- |
| What it does | Quiqup holds your stock, picks it, packs it, ships it | Quiqup collects a parcel you packed and delivers it |
| You send | Products, inbound stock, orders against stock we hold | Orders with a pickup address and a dropoff address |
| Host | `platform-api.quiqup.com` | `api-ae.quiqup.com` |
| Reference | [Fulfilment API](/openapi) | [Last-mile API](/apis/orders) |


Not sure which one you are on? [Which API do I need](/docs/choose).

**One credential works on both APIs.** The same OAuth2 client credentials, and the same Quiqdash Bearer key, authenticate against the Fulfilment and the Last-mile host of an environment. There is nothing extra to request if you end up using both. Staging and production are a different question, answered in [Environments](/docs/environments#which-credentials-cross-environments).

**One order state machine.** A fulfilment order becomes a last-mile shipment once it is picked. Both APIs report the same values, in a field called `state` on last-mile and `status` on fulfilment, and both emit the same `order.*` webhooks, which carry it as `payload.state`. [Order lifecycle](/docs/order-lifecycle) is written once and applies to both.

## What every account needs

Whichever product you are on:

1. **A business Quiqup account.** Individual accounts cannot be integrated.
2. **A commercial agreement.** Talk to your Business Development Manager before you build. Credentials follow the agreement, not the other way round.
3. **Account activation.** Trade licence, bank card and finance details.
4. **The services you need enabled.** Same Day, Next Day, 4-hour and international export are each switched on per account, by your account manager. Only Same Day is checked when an order is created, so do not rely on the API to tell you a service is off. [Service types](/docs/order-lifecycle#service-types)


## Then pick your section

**[Fulfilment](/docs/fulfilment)** if Quiqup stores your stock. You will load products, book stock in, and create orders against what we hold.

**[Last-mile](/docs/lastmile)** if you hold your own stock. You will create orders that name a pickup address and a dropoff address. This is also where the Shopify and WooCommerce connectors live.

## Shared across both

- [Environments](/docs/environments). Hosts, and what is different about staging.
- [Authentication](/docs/authentication). Tokens, keys, lifetimes.
- [Order lifecycle](/docs/order-lifecycle). The one state machine.
- [Webhooks](/docs/webhooks). Signing, delivery, the full event catalogue.
- [Error responses](/docs/errors). Both error shapes, and which host returns which.
- [Common questions](/docs/faq). Routed by product.


## Getting help

[apisupport@quiqup.com](mailto:apisupport@quiqup.com) is the technical channel. Support runs on email. See [Getting help](/docs/getting-help).

Before you email, read [what to send in the first message](/docs/getting-help#what-to-send-in-the-first-email). Getting those seven things right saves a round trip.