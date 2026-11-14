# Authentication

Exchange a `client_id` and `client_secret` for a Bearer access token.
**One credential set covers both APIs.** The same OAuth2 client credentials, and the same Quiqdash
Bearer key, authenticate against the Fulfilment host and the Last-mile host. There is no separate
fulfilment credential to request.
Most integrations do not need the exchange at all. A Bearer token generated in Quiqdash under
Organization -> API keys grants identical access with no token endpoint involved. Client credentials
exist for middleware and carrier platforms whose setup screens require a Client ID and Client Secret.

Version: 1.0
License: Proprietary

## Servers

Last-mile, staging
```
https://api.staging.quiqup.com
```

Last-mile, production
```
https://api-ae.quiqup.com
```

Fulfilment, staging
```
https://platform-api.staging.quiqup.com
```

Fulfilment, production
```
https://platform-api.quiqup.com
```

## Download OpenAPI description

 - [Authentication](https://developer-docs.quiqup.com/_bundle/apis/auth.yaml)

## Auth

 - [POST /oauth/token](https://developer-docs.quiqup.com/apis/auth/auth/authorization-token.md): Every endpoint on both APIs supports OAuth 2.0. For background, see [oAuth 2.0](https://oauth.net/2/). To send authenticated requests: 1. Exchange your `client_id` and `client_secret` for an access
