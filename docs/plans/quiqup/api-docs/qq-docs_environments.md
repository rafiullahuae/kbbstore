# Environments

**Applies to both APIs.** Connector users never choose a host: the connector talks to production automatically, and there is no staging connector.

## Base URLs

|  | Staging | Production |
|  --- | --- | --- |
| Last-mile API | `https://api.staging.quiqup.com` | `https://api-ae.quiqup.com` |
| Fulfilment API | `https://platform-api.staging.quiqup.com` | `https://platform-api.quiqup.com` |
| OAuth token | `POST {base}/oauth/token` | `POST {base}/oauth/token` |
| Dashboard | `https://quiqdash-beta.staging.quiqup.com` | `https://business-ae-beta.quiqup.com` |


Every fulfilment path is prefixed `/api/fulfilment/`. Last-mile paths are not prefixed at all. That alone tells you which host a call belongs to.

**The `-beta` hosts are the current dashboard.** The older non-beta addresses (`quiqdash.staging.quiqup.com`, `business-ae.quiqup.com`) still work and some accounts still use them, but they are being deprecated. When that happens the beta version is promoted onto the old URL and this table changes. Every dashboard link in these docs points here rather than hard-coding a host, so one edit will carry it.

**One login covers both environments.** The same account and password work on both dashboards, and your organisation and its team are the same on both, because both come from one shared login service. You do not need a separate staging account. What the login does not share is data: see [Build on staging](#build-on-staging).

HTTPS is required. `http://` is redirected, and some clients drop the POST body when they follow the redirect, which surfaces as an empty or failed token call.

**Last mile and fulfilment are different hosts, but not different credentials.** The same OAuth2 client credentials, and the same Quiqdash Bearer key, authenticate against both. What differs is whether your account has the fulfilment product at all. See [Which API do I need](/docs/choose).

`quiqdash-ae.quiqup.com` is not an API host. Neither is any `business-*` address. Opening an API host in a browser returns nothing by design; it is not a login page and it is not an outage.

## The docs mock server is not an environment

Older Quiqup documentation, and Postman collections exported from it, point at
`https://stoplight.io/mocks/quiqup/...`.

**That is a mock server. It returns fabricated data and will not authenticate real credentials.** At
least one integration was built against it. If you inherited a collection or a link with
`stoplight.io` in the host, replace it with `api.staging.quiqup.com`.

`api-staging.quiqup.com`, with a hyphen, also appears in older material. It does not resolve. The staging
host is `api.staging.quiqup.com`, with a dot.

## Build on staging

Always. Production orders dispatch real couriers and are billed. This applies even when you integrate through middleware or an existing OMS partner.

Staging traffic does not affect live orders. The login is shared across both environments, but the data is not: staging orders and webhook subscriptions exist only on staging.

## Getting on each environment

**Staging.**

1. Ask your account manager for staging access. Say which product, fulfilment or last-mile, so the right services are enabled.
2. Generate a Quiqdash key, or request a client id and secret. See [Authentication](/docs/authentication).
3. Build and test against the staging host.


**Production.**

1. Your account manager activates the production account and enables your services on it.
2. Generate a key on the production dashboard, or request a production client id and secret, quoting your account. See [Which credentials cross environments](#which-credentials-cross-environments).
3. Switch the host and re-register your webhook. Staging subscriptions do not carry over.
4. Coordinate the first live orders with the operations team rather than switching everything over at once.


[Going live](/docs/lastmile/testing#going-live) has the checklist.

## Which credentials cross environments

The two kinds of credential behave differently.

| Credential | Staging and production |
|  --- | --- |
| Quiqdash API key | **Generate it on the dashboard of the environment you will call, and use it only on that host.** Keys come from the same shared login service as your login, so a key identifies the same account whichever host receives it. Do not rely on a host rejecting a key made for the other environment. |
| `client_id` and `client_secret` | Per environment. A staging pair is rejected by production with `invalid_client`, and the other way round. |
| Access token from `/oauth/token` | Per environment. A token exchanged on staging gets `401` from production. |


**The base URL decides which environment you are talking to.** A call to the production host that is accepted creates a real order on your live account. Nothing in a response says which environment answered, so do not rely on a rejection to catch a wrong base URL.

- Keep the base URL in per-environment configuration, never as a default in code.
- Generate a production key on the production dashboard, name each key after its environment, and keep each one on the host it was made for, so you can revoke the staging key without breaking production.
- A client-credential mismatch fails as an authentication error rather than a routing error, so it reads like a bad key.


## Where your orders appear

Orders created through the staging API appear in the staging dashboard, to the account the credentials belong to and, if the order carries a [`billing_identifier`](/docs/lastmile/create-an-order#billing_identifier), to the account it points at. Pending orders are listed too, under the **Pending** tab. If the API returns 2xx and you cannot see the order:

1. Check you are looking at the dashboard for the right environment.
2. Check the dashboard user belongs to the same account as the token.
3. Check the date range. The Orders list opens on the last month.


An order you can see but that never moves is a different problem: it is still `pending`. See [Order lifecycle](/docs/order-lifecycle#create-then-make-it-ready).

## Staging troubleshooting

| Symptom | What it means |
|  --- | --- |
| Login says the account does not exist, sign-up says it already exists | The account exists but is unconfirmed. Ask for the confirmation email to be resent. |
| A "payment overdue" banner on the staging dashboard | Cosmetic. It does not block API use. |
| The API host shows a blank page in a browser | Expected. The host serves the API, not a website. |
| Valid payloads intermittently rejected | Check for a staging deployment before debugging your own code. |