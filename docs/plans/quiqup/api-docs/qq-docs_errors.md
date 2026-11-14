# Error responses

**Applies to both APIs.**

The two return different error shapes, and an integration that spans both has to handle both.

A connector wraps all of this in its own message, so what you see on the Integration Orders page is a reason string, not an HTTP error. [That page](/docs/lastmile/integration-orders) is the right one.

## The two envelopes

Credentials, order states and webhooks are shared. Error shapes are not: the two APIs use completely different envelopes.

|  | Last-mile | Fulfilment |
|  --- | --- | --- |
| Host | `api-ae.quiqup.com` | `platform-api.quiqup.com` |
| Top-level key | `api_error` | `code`, `message`, `details` |
| Field errors | `attribute_errors`, an array of `{detail}` | `details.fields`, an array of `{field, message}` |
| Names the bad field | No | Yes |


If you call both, write two error handlers. Branch on the host, not on trying to parse one shape and falling back to the other.

## Fulfilment errors

`code` carries the error code, `message` carries a sentence, and `details.type` says what kind of failure it was.

```json
{
  "code": "invalid_argument",
  "message": "invalid input for data",
  "details": {
    "type": "input",
    "fields": [
      { "field": "shipping_address.phone", "message": "required" },
      { "field": "products[0].quantity", "message": "min 1" }
    ]
  }
}
```

`details.fields` names the field that failed by its JSON path in your request: dotted for nested objects and indexed for arrays, as in `shipping_address.phone` or `items[0].unit_price`. `message` is the rule that failed, followed by its parameter when it has one (`required`, `min 1`, `max 255`). Read it and show it to the user. This is the part last-mile does not have.

### Fulfilment error codes

| HTTP | Code | `details.type` |
|  --- | --- | --- |
| 400 | `invalid_argument` | input |
| 400 | `failed_precondition` | internal |
| 401 | `unauthenticated` | unauthorized |
| 403 | `permission_denied` | forbidden |
| 404 | `not_found` | not_found |
| 409 | `already_exists` | internal |
| 429 | `resource_exhausted` | internal |
| 499 | `canceled` | internal |
| 500 | `internal` | internal |
| 500 | `internal` | integration |
| 500 | `data_loss` | internal |
| 500 | `unknown` | internal |
| 503 | `unavailable` | internal |
| 504 | `deadline_exceeded` | internal |


The list is not exhaustive and more codes are added over time. Branch on the HTTP status and treat an unrecognised `code` as its status class.

## Last-mile errors

Which shape you get depends on the endpoint and on how early the request was rejected.

```json
{ "api_error": {
    "id": 1002,
    "code": "Error Code",
    "message": "Error Message",
    "human": "Human readable error message",
    "description": "Detailed human readable error description",
    "http_status_code": 400,
    "attribute_errors": [ { "detail": "additional error code" } ]
} }
```

```json
{ "error": "invalid_client" }
```

```json
{ "error": "unauthorized" }
```

```json
{ "result": 103, "level": "error", "message": "00001 is an invalid postcode" }
```

### Which last-mile endpoint returns which

| Endpoint | Envelope |
|  --- | --- |
| Every `/orders` operation | The `api_error` shape, sent alongside four more keys carrying the same two strings: `error` and `errors[0].detail` and `error_details[0].detail` repeat `api_error.message`, and `error_detail` repeats `api_error.human`. Read `error` for the machine-ish string and `api_error.human` for the sentence. |
| `POST /oauth/token` | `{ "error": "invalid_client", "error_description": "..." }` |
| Any endpoint, missing or expired token | HTTP 401 with a bare `{ "error": "unauthorized" }`. The request is rejected before the payload is read, so there is no `api_error`. |


The last shape above, `{ "result", "level", "message" }`, does not come from any `/orders` endpoint. It exists elsewhere on the platform, so handle it defensively if you meet it.

**Three things about the `api_error` shape will catch you out.**

`api_error.http_status_code` does not always match the HTTP status. A create with an invalid `items` array comes back as HTTP **400** carrying `http_status_code: 422`. Trust the HTTP status.

`api_error.message` and `error` are sometimes **empty strings**. An invalid `payment_mode`, and an invalid `kind`, both return 422 with every message field blank. There is nothing in the response telling you which field was wrong.

`attribute_errors` comes back as an **array** of `{detail}`. Some older Quiqup material shows it as a bare object. The array is what the API sends.

The five fields are not independent. `error`, `errors[0].detail`, `error_details[0].detail` and `api_error.message` all carry the same string, and `error_detail` repeats `api_error.human`.

### Validation errors on order creation

| Message | Field | Fix |
|  --- | --- | --- |
| `'dropoff.address1' must not be blank` | `destination.address.address1` | Error messages say `dropoff` and `pickup`; the request fields are `destination` and `origin`. |
| `Validation failed: Postcode can't be blank` | none | **There is no postcode field in the contract.** See [Create an order](/docs/lastmile/create-an-order#address-object) before acting on this. |
| `unknown servicekind: "..."` | kind | Use a value from [Service types](/docs/order-lifecycle#service-types). |
| `[order][billing_identifier] invalid` | billing_identifier | HTTP 400. The identifier could not be resolved. Retry once, then ask support. An identifier that simply is not linked to your account does **not** produce this: it is accepted and ignored, see [billing_identifier](/docs/lastmile/create-an-order#billing_identifier). |
| `Same day service is not enabled for your account` | kind | 422. Same Day is not enabled on the account. Ask your account manager. |
| `[origin] is missing, [destination] is missing, [items] is missing` | those three | 422. The three top-level objects the API actually requires. |
| `[destination][address][address1] is missing` | `destination.address.address1` | 422. Required on both stops. |
| `[order][items] invalid` | `items` | HTTP 400. An `items` entry with no `name`. |
| (empty string) | `payment_mode` or `kind` | 422 with no message at all. One of those two values is not in the allowed set. |
| `CanSubmitJobPolicy failed policy check` | any | See below. |


### CanSubmitJobPolicy failed policy check

One string covers several unrelated commercial restrictions. None of them are payload bugs, so debugging your request will not help.

| Restriction | Who lifts it | Does retrying later work? |
|  --- | --- | --- |
| Customer segmentation rule | Account manager | No |
| Tier closed | Account manager | No |
| Quiqdash closed for high demand | Operations | Yes |
| Hourly order limit reached | Account manager | Yes, after the window resets |
| 3PL (third-party logistics partner) not accepting orders | Operations | Yes |


### Missing orders do not give you a 404

| Case | What actually happens |
|  --- | --- |
| `GET /orders/{order_id}` on an id that does not exist | **403**, not 404. It reads like an authentication problem and is not. |
| `GET /order_label/{order_id}` on an id that does not exist | **200**, `Content-Type: application/pdf`, and a **zero-byte body**. Nothing tells you the order is missing. Check the content length before writing the response to a file. |
| A label request 404s | Check the path. The label endpoint is **`GET /order_label/{order_id}`**. A path built under the order resource does not exist. |


## 5xx

Both APIs. Malformed payloads can surface as 500 rather than 422 on the last-mile `POST /orders` and `PUT /orders/{order_id}/ready_for_collection`. Validate your payload shape before assuming an outage.

If it persists, send the exact request, the exact response and the UTC timestamp to [apisupport@quiqup.com](mailto:apisupport@quiqup.com).