# Engine HTTP API (LANE ENGINE → LANE BRAIN's app/)

127.0.0.1:41927 (random only if busy). JSON. Every `/api/*` call except `/api/info` needs the header
`X-KAA: <token>`; the SSE stream takes `?t=<token>`. Requests from another origin are refused (Origin /
Sec-Fetch-Site), and only Host `127.0.0.1:<port>` / `localhost:<port>` is served. apiLevel = 1.

## Account scoping
Every **account-scoped** endpoint acts on the ad account named by, in order:
1. header `X-KAA-Account: act_…`, or
2. query `?account=act_…` (use it for the SSE stream and links), or
3. the selected account (`POST /api/accounts/select`).

An account the token cannot see → 404. No account at all yet → 409 ("connect Meta first").
SSE events carry `{"account": "act_…" | "" (app-wide), "data": …}`.

## App-wide
| Method + path | Body → answer |
|---|---|
| GET /api/info | `{app, version, apiLevel, token, dataDir}` (same origin only) |
| GET /api/events?t= | SSE: `hello`, `heartbeat` (15 s), `connections`, `cycle.start`, `cycle.end`, `action.execution`, `action.safety`, `action.kill`, `action.alert`, `alert`, `metrics.updated`, `research.updated`, `backfill.progress`. The engine stops 20 s after the last stream closes. |
| GET /api/connections | `{meta:{ok, error, auth, token:{set,last4}, app_secret:{set,last4}, app_id, api_version, token_type, token_expires_at, token_days_left (-1 none), token_scopes, warning, access_tier, ad_accounts, usage}, claude:{ok,model,error,key,usage}, shop:{ok,products,orders_source,error,feed_url}, secret_store}` |
| PUT /api/connections/meta | `{auth:"system_user"\|"user_token", token, app_id, app_secret, api_version}` (+ legacy one-account `account_id, page_id, pixel_id`) → the meta block. A user token is exchanged for a 60-day one. |
| PUT /api/connections/claude | `{api_key, model}` → claude block |
| PUT /api/connections/shop | `{feed_url, token}` → shop block |
| GET /api/accounts | `{selected, accounts:[{id, name, currency, account_status, business_id, relation: owned\|client\|personal, automated, limits_set, autopilot, links:{page_id, instagram_user_id, pixel_id, catalog_id, whatsapp_number}, link_checks:{page:{ok,name,error},…}, backfill:{state, done, total, rows,…}, selected}], businesses:[{id,name,pages[],instagram_accounts[],pixels[],catalogs[],whatsapp_accounts[],ad_accounts[],error}], read_at, global_limits:{daily_aed, monthly_aed}}` |
| POST /api/accounts/select | `{account}` → `{selected}` (starts its history import if not done) |
| PUT /api/accounts/{act} | `{automated?: bool, links?: {page_id, instagram_user_id, pixel_id, catalog_id, whatsapp_number}}` → `{account, automated, links, link_checks}` (each link read live) |
| GET/PUT /api/limits/global | `{daily_aed, monthly_aed}` — the optional cap across ALL automated accounts (0 = none) |
| GET/PUT /api/settings | `{sync_minutes 15–1440, plans_per_day 1–24}` (+ read-only claude_model, api_version) |
| GET /api/research | the brain's market notes (shared) |
| GET /api/learnings | portfolio learnings `[{account_id, dimension: format\|objective\|country, key, spend, purchases, revenue, ads, cpa, roas, from, to}]` |
| POST /api/brain/research | `{focus?}` → Research |
| POST /api/kill | no account named (or `?account=all`) → EVERY account: `{accounts:[{account, paused[], failed[]}]}`; with an account → that one only |
| GET /api/update, POST /api/update/upload, /api/update/install, /api/update/rollback | the updater (ported from KBB Studio) |
| POST /api/stop | stops the engine |

## Per account (X-KAA-Account / ?account=)
| Method + path | Body → answer |
|---|---|
| GET /api/state | one call for the dashboard: `{account_id, automated, account, links, link_checks, limits, limits_set, campaigns:[…adsets:[…ads:[…]]] each with last7 KPIs, kpis:{today,7d,30d:{spend,purchases,revenue,cpa,roas,cpc,ctr,cpm,frequency,clicks,impressions}}, committed_daily, month_spend, plan, report:{date,text}, alerts[], last_sync, last_cycle, next_sync, next_cycle, cycle_running, backfill, safety (last report), connections, shop, research_at, claude_usage, creatives}` — served from memory (< 2 ms measured) |
| GET/PUT /api/limits | `{daily_aed, monthly_aed, max_budget_step_pct, cooldown_hours, max_active_ads, countries[], autopilot: off\|shadow\|auto, target_cpa_aed, kill_multiple}` (fields not sent keep their value) |
| POST /api/cycle | `{mode: "shadow"\|"auto"}` → `{account, counts:{done,refused,shadow,failed,duplicate}, results[], proposals, ms}`; 409 while one runs |
| POST /api/sync | → `{ok, account, at}` |
| POST /api/safety | → the safety report `{spend_cap:{before,target,after,action,reason}, rules:[{name,id,action}], alerts[]}` |
| GET/POST /api/backfill | status / start or resume the history import (`?again=1` to redo) |
| GET /api/log?after=&limit=&kind=a,b | the account's audit log `{account, entries:[{seq,at,kind,summary,data}], last}`; `&scope=app` = the app-wide log |
| GET/POST /api/creatives | the account's creative library (POST `{id?, lang, format, png\|video (base64), headline, primary_text, cta, link (kbeautybliss.com), product_ids, angle, source}`) |
| POST /api/brain/plan | a plan preview for this account (nothing executed) |
| POST /api/brain/copy | `{product_ids, langs, angle}` → `{variants[]}` |
