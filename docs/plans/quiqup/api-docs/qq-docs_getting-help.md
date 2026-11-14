# Getting help

**Applies to both APIs.** [apisupport@quiqup.com](mailto:apisupport@quiqup.com) is the technical channel for API and connector questions, fulfilment and last-mile alike.

There is no phone or WhatsApp support line.

For commercial questions, pricing, adding services, activating your account, go to your account manager or BDM instead.

## What to send in the first email

Support asks for the same seven things on nearly every ticket. Sending them up front removes a full round trip.

```
Account email:      the address your Quiqup account is registered under
Environment:        staging or production
Integration:        direct API / Shopify connector / WooCommerce connector / partner name
                    (this is the first thing support has to establish, so lead with it)
Order references:   the affected order ids or partner_order_id values
Request:            exact body and headers, token redacted
Response:           exact body and HTTP status
Timestamp:          when the request was sent, in UTC
```

Support cannot act on a request from an address with no Quiqup account behind it. Requests from an unrecognised address stall.

## Before you email

| Question | Route | Where to look first |
|  --- | --- | --- |
| My store order did not reach Quiqup | Connector | [Integration Orders](/docs/lastmile/integration-orders) |
| My orders stopped syncing | Connector | [When a working connection stops](/docs/lastmile/integration-orders#when-a-working-connection-stops) |
| I created an order but cannot see it | API | [Order lifecycle](/docs/order-lifecycle#create-then-make-it-ready) |
| It was working and stopped | API | [Authentication errors](/docs/authentication#authentication-errors) |
| I am not receiving webhooks | API | [Webhooks](/docs/webhooks#not-receiving-webhooks) |
| Authentication is failing | API | [Authentication](/docs/authentication#authentication-errors) |
| I need API keys | API | Generate a Bearer token in [Quiqdash](/docs/authentication#option-1-generate-a-token-in-quiqdash). Only a `client_id` and `client_secret` needs a request. |
| I need a webhook registered | API | You do not. Register it in [Quiqdash](/docs/webhooks#register-your-endpoint). |


## Reporting a suspected outage

There is no public status page today, so "is it you or me" cannot be answered without asking.

Include the timestamp, the order references and the exact response. If several of your orders failed in the same window, say so. Several failures in one window points at a platform issue rather than a payload one.