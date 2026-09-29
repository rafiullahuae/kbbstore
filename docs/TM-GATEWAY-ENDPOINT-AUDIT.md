# Every payment and order admin endpoint, against the console that calls it

Lane TM, 29 September 2026, on `claude/kind-mayer-rpqesv` at `73aa51d`.

Lane QA found that the whole Tamara admin API had no caller. This is the same
question asked of **every** payment and order endpoint in the router: a grep
with its output, endpoint by endpoint, not an inference.

---

## The method, and why it is not one command

A general "every admin-api route has a console caller" check **cannot be written
against this console**, and the attempt is recorded here so nobody spends an
afternoon on it a second time.

- Roughly half the screens are partials using the `cache-screen` pattern —
  `api('/banners')`, where the helper prepends `/admin-api` — so their calls
  contain no `/admin-api` literal at all. A scan reads every route they serve as
  dead. Run naively, 241 of 536 routes come back "unreferenced", nearly all of
  them wrong.
- The other half build paths as `base + '/' + id + '/suffix'`, so the only thing
  a scan can match is the suffix.
- Anything loose enough to accept both accepts the literal
  `'/admin-api/payments'` as evidence that `/admin-api/payments/tamara/sweep` is
  called — which is the exact defect being looked for. Run that way, **0 of 536
  come back unreferenced**, including the five that were genuinely dead.

So the families below were each read by hand, both directions: the literal in
the console, and the route in `php artisan route:list`.
`tests/Feature/TamaraConsoleReachTest.php` pins the seven this lane wired.

---

## 1 · Tamara — five endpoints, zero callers. FIXED by this lane.

```
$ grep -rni "tamara" resources/views/admin/ resources/js/ | grep -E "fetch|api\(|admin-api"
$ echo $?
1                       # no matches at all
```

| Endpoint | Caller before | Caller now |
|---|---|---|
| `GET /admin-api/payments/tamara` | none | `Store → Gateway webhooks`, on open |
| `POST /admin-api/payments/tamara/webhook` | none | **Register the webhook** |
| `DELETE /admin-api/payments/tamara/webhook` | none | **Remove the registration…** (confirms first) |
| `POST /admin-api/payments/tamara/limits` | none | **Pull the limits from Tamara** |
| `POST /admin-api/payments/tamara/sweep` | `php artisan payments:tamara-sweep` only | **Run the sweep** |

The cost is in `routes/payments-tamara.php`'s own header: the webhook is how
Tamara reports a **decline**, and Tamara's merchant portal has no webhook
screen, so without a button it could not be done at all. A refused shopper's
order sat `pending` for ever, holding its stock claim and its coupon use.

---

## 2 · Tabby — two endpoints, zero callers. FIXED by this lane.

```
$ grep -rn "tabby/webhooks" resources/views/admin/ resources/js/
                        # nothing
```

| Endpoint | Caller before | Caller now |
|---|---|---|
| `GET /admin-api/payments/tabby/webhooks` | none | **Re-read the state** |
| `POST /admin-api/payments/tabby/webhooks` | none | **Register / re-sync Tabby's webhooks** |

`TabbyWebhookController::sync()` is the only thing that registers or prunes a
Tabby webhook per market, and nothing outside a test had ever called it.
Same card, same screen.

---

## 3 · Releasing an authorisation — two endpoints, zero callers. **NOT fixed.**

```
$ grep -rn -F "/void" resources/views/admin/ resources/js/
                        # nothing
```

| Endpoint | Capability | Caller |
|---|---|---|
| `GET /admin-api/orders/{id}/void` | `orders.money` | **none** |
| `POST /admin-api/orders/{id}/void` | `orders.money` | **none** |

`routes/payments-void.php` is mounted (web.php:403) and its header says *"both
404 while the button renders perfectly"* — there is no button. Its sibling
`POST /admin-api/orders/{id}/capture` **is** called, at app.blade.php:13778, so
this is one missing control beside a working one, not a missing screen.

**Why this lane did not fix it.** The control belongs on the order detail panel
in `resources/views/admin/app.blade.php`, which this lane may not edit, and
`PaymentRefunder`/the release path is Lane OD's this round (`docs/LANE-BRIEFS.md`
— *"Two money defects the owner has been asked about twice"*, item 1, which is
about a released authorisation being counted as refundable). It is a money path
and a cancelled order leaves the buyer's instalment plan live at Tamara for up
to 180 days, so it should not sit another round.

---

## 4 · Settlement read — one endpoint, zero callers. Redundant, not a gap.

```
$ grep -rn -F "/settlement" resources/views/admin/ resources/js/
                        # nothing
```

`GET /admin-api/orders/{id}/settlement` has no caller, but the console is not
missing the information: `AdminOrderController` puts the same
`'settlement' => $capturer->status($order)` block inside the order **detail**
payload (line 295), and app.blade.php reads `o.settlement` at lines 13398 and
13461. So the endpoint is a second door onto data that already arrives.

Worth saying out loud rather than deleting: `PaymentSettlementController::show()`
and `AdminOrderController` build that block separately, which is two
implementations of one answer and the kind of pair that drifts. Left for
whoever owns the settlement panel.

---

## 5 · Two index endpoints with no caller. Harmless, listed for completeness.

| Endpoint | Note |
|---|---|
| `GET /admin-api/payments/preflight` | the console only ever calls `preflight/{gateway}` (app.blade.php:20292). The index that lists every gateway has no caller. Read-only, reaches no provider. |
| `GET /admin-api/payments/reconcile` | the console calls `reconcile/start`, `reconcile/step`, `reconcile/{run}/findings`, `reconcile/{run}/findings/{finding}/ack` and `reconcile/cod`; the bare index has no caller. |

Neither is a money path and neither is a control the owner is looking for.

---

## 6 · Stripe — every endpoint has a caller.

| Endpoint | Caller |
|---|---|
| `GET /admin-api/payments/stripe/connect/status` | app.blade.php:20718 |
| `GET /admin-api/payments/stripe/connect/platform` | app.blade.php:20914 |
| `GET /admin-api/payments/stripe/connect/start` | app.blade.php:21240 |
| `POST /admin-api/payments/stripe/connect/application` | app.blade.php:20944 |
| `POST /admin-api/payments/stripe/connect` | app.blade.php:21185, 21310 |
| `POST /admin-api/payments/stripe/disconnect` | app.blade.php:21348 |
| `GET /admin-api/payments/stripe/connect/callback` | **none, and correctly so** — it is where Stripe redirects the browser, not something the console fetches. |

---

## 7 · Cash on delivery — nothing to find.

COD has **no admin endpoints of its own**. It is configured through the generic
`GET`/`POST /admin-api/payments` pair (app.blade.php:20596, 20645), which the
payments screen calls, and `payCard()` draws it as a one-column card that says
in as many words that there is nothing to paste in. Its only other endpoint is
`GET /admin-api/payments/reconcile/cod`, called at app.blade.php:20566.

---

## Summary

| Family | Endpoints | Had no caller | Fixed here |
|---|---|---|---|
| Tamara | 5 | 5 | 5 |
| Tabby | 2 | 2 | 2 |
| Release / void | 2 | 2 | 0 — reported, §3 |
| Settlement read | 1 | 1 | 0 — redundant, §4 |
| Preflight / reconcile index | 2 | 2 | 0 — harmless, §5 |
| Stripe | 7 | 0 (1 by design) | — |
| Cash on delivery | 0 of its own | — | — |
