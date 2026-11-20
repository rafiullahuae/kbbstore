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
| GET /api/info | `{app, version, folderVersion, apiLevel, build, token, dataDir}` (same origin only). `version` = what the engine started as, `folderVersion` = the app folder's VERSION now: when they differ (the folder was replaced while it ran) or `apiLevel` < the app's `APP_API_LEVEL` (app/js/api.js, 2), the page shows “the old engine is still running” and runs nothing. |
| GET /api/events?t= | SSE: `hello`, `heartbeat` (15 s), `connections`, `cycle.start`, `cycle.end`, `action.execution`, `action.safety`, `action.kill`, `action.alert`, `alert`, `metrics.updated`, `research.updated`, `backfill.progress`, `update` (the health check after an update). The engine stops 20 s after the last stream closes. |
| GET /api/connections | `{meta:{ok, error, auth, token:{set,last4}, app_secret:{set,last4}, app_id, api_version, token_type, token_expires_at, token_days_left (-1 none), token_scopes, warning, access_tier, ad_accounts, usage}, claude:{ok,model,error,key,usage}, shop:{ok,products,orders_source,error,feed_url}, secret_store}` |
| PUT /api/connections/meta | `{auth:"system_user"\|"user_token", token, app_id, app_secret, api_version}` (+ legacy one-account `account_id, page_id, pixel_id`) → the meta block. A user token is exchanged for a 60-day one. |
| PUT /api/connections/claude | `{api_key, model}` → claude block |
| PUT /api/connections/shop | `{feed_url, token}` → shop block |
| POST /api/connections/retest | reads every key again and tests all three (“Try again”) → the connections block. A key that could not be read is `{set:false, error:"…"}` — never just “not set”; keys are refused when they look like another field's (EAA… / sk-ant-… / kbbap_…) |
| GET /api/accounts | `{selected, accounts:[{id, name, currency, account_status, business_id, relation: owned\|client\|personal, automated, limits_set, autopilot, links:{page_id, instagram_user_id, pixel_id, catalog_id, whatsapp_number}, link_checks:{page:{ok,name,error},…}, backfill:{state, done, total, rows,…}, selected}], businesses:[{id,name,pages[],instagram_accounts[],pixels[],catalogs[],whatsapp_accounts[],ad_accounts[],error}], read_at, global_limits:{daily_aed, monthly_aed}}` |
| POST /api/accounts/select | `{account}` → `{selected}` (starts its history import if not done) |
| PUT /api/accounts/{act} | `{automated?: bool, links?: {page_id, instagram_user_id, pixel_id, catalog_id, whatsapp_number}}` → `{account, automated, links, link_checks}` (each link read live) |
| GET/PUT /api/limits/global | `{daily_aed, monthly_aed}` — the optional cap across ALL automated accounts (0 = none) |
| GET/PUT /api/settings | `{sync_minutes 15–1440, plans_per_day 1–24}` (+ read-only claude_model, api_version) |
| GET /api/research | the brain's market notes (shared) |
| GET /api/learnings | portfolio learnings `[{account_id, dimension: format\|objective\|country, key, spend, purchases, revenue, ads, cpa, roas, from, to}]` |
| POST /api/brain/research | `{focus?}` → Research |
| POST /api/kill | no account named (or `?account=all`) → EVERY account: `{accounts:[{account, paused[], failed[]}]}`; with an account → that one only |
| GET /api/update | `{version, engine, apiLevel, canUpdate, dataDir, history[], previous{version,at}, pending, upload{id,name,size,received}, health{state,from,to,kind,reason}, last{kind,from,to,ok,reason,at,seen}, busy, paused}` |
| POST /api/update/upload/begin | `{name, size, resume}` → `{id, received, chunk}` (same name + size + resume id: the same upload, at the byte it has) |
| PUT /api/update/upload/chunk?id=&offset= | the bytes, with `X-Chunk-SHA256`; only at exactly `received` (else 409 `{received}`), only whole, only when the sha matches (422) |
| GET /api/update/upload/status?id= · POST /api/update/upload/finish `{id}` | how much arrived · check + stage → the review (`{id, kind: full\|patch, from, to, notes, added, changed, removed, writes, engine, checks[]}`) or 422 `{error, code}` |
| POST /api/update/upload | the whole zip in one request (scripts) |
| POST /api/update/install `{id}` · POST /api/update/rollback | NDJSON, one line per step: `{step: verify\|stage\|swap\|restart, state: run\|done\|fail}`, last `{ok, from, to, kind, restarting}`; then the engine re-executes itself and checks its own health before the autopilot resumes (fails → rolls back by itself) |
| POST /api/update/cancel `{upload?}` · POST /api/update/ack | drop the checked package / the upload · the last result was seen |
| POST /api/stop | stops the engine |

## Per account (X-KAA-Account / ?account=)
| Method + path | Body → answer |
|---|---|
| GET /api/state | one call for the dashboard: `{account_id, automated, account, links, link_checks, limits, limits_set, campaigns:[…adsets:[…ads:[…]]] each with last7 KPIs, kpis:{today,7d,30d:{spend,purchases,revenue,cpa,roas,cpc,ctr,cpm,frequency,clicks,impressions}}, committed_daily, month_spend, plan, report:{date,text}, alerts[], last_sync, last_cycle, next_sync, next_cycle, cycle_running, backfill, safety (last report), connections, shop, research_at, claude_usage, creatives}` — served from memory (< 2 ms measured) |
| GET/PUT /api/limits | `{daily_aed, monthly_aed, max_budget_step_pct, cooldown_hours, max_active_ads, countries[], autopilot: off\|shadow\|auto, target_cpa_aed, kill_multiple, wording: strict\|balanced\|relaxed}` (fields not sent keep their value; the brain reads `wording` here) |
| POST /api/cycle | `{mode: "shadow"\|"auto"}` → `{account, counts:{done,refused,shadow,failed,duplicate}, results[], proposals, ms, starter?:{proposals, why, mode}}`; 409 while one runs. First launch: Auto (or Practice) + limits + none of the app's campaigns running or spending in 7 days → the starter set (starter.go) at once |
| GET /api/proposals | the last cycle's proposals `{cycle_at, mode, proposals:[{id, kind, target, params, why, confidence, evidence, status: shadow\|refused\|failed\|done\|duplicate\|rejected, result, decided_at, by: cycle\|owner}]}` |
| POST /api/proposals/{id}/approve | runs that one proposal + the waiting ones it names (its tmp: campaign / ad set / creative / product set / audience), in plan order, through every executor check (account automated, limits set, autopilot not off) → `{account, id, ran, counts, results[]}`; 404 unknown, 409 not waiting / busy |
| POST /api/proposals/{id}/reject | Not now → `{account, id, status:"rejected"}` (nothing written) |
| POST /api/sync | → `{ok, account, at}` |
| POST /api/safety | → the safety report `{spend_cap:{before,target,after,action,reason}, rules:[{name,id,action}], alerts[]}` |
| GET/POST /api/backfill | status / start or resume the history import (`?again=1` to redo) |
| GET /api/log?after=&limit=&kind=a,b | the account's audit log `{account, entries:[{seq,at,kind,summary,data}], last}`; `&scope=app` = the app-wide log |
| GET/POST /api/creatives | the account's creative library (POST `{id?, lang, format, png\|video (base64), headline, primary_text, cta, link (kbeautybliss.com), product_ids, angle, source}`); a starter creative has no media and `image_url` (the shop's photo, sent as link_data.picture) |
| GET /api/creatives/{id}/file | the stored PNG / MP4 of one of THIS account's creatives (Range works); 404 for no media / another account's id |
| POST /api/brain/plan | a plan preview for this account (nothing executed) |
| POST /api/brain/copy | `{product_ids, langs, angle}` → `{variants[]}` |

## upload_creative with the owner's own video
`{format: "video", video_path: "videos/<name>.mp4|mov|m4v", primary_text, headline?, link?, cta?, thumbnail_url? | thumbnail_creative_id?}`:
the file is read from the data folder's `videos/` only (symlinks resolved, regular files, ≤ 4 GB), streamed to Meta's
resumable upload once per file (the Meta video id is kept in the ledger), and the cover is `thumbnail_url`, a library
picture, or the frame the video analysis saved (`videos/<name>.jpg`). The brain's `path` param maps to it.

## -mock (checks only)
Three ad accounts (`MockAccounts`), each with an owner campaign; the fake Claude answers with scripted tool calls
(`metamock/claude.go`). Test endpoints: `/api/test/meta-inject`, `meta-writes`, `meta-tag` `{id, tag: WINNER|LOSER}`,
`clock` `{advance_hours}` (moves the engine's and the fake Meta's clock), `claude-calls`.

## Create with Claude + the Claude-app connector (Nov 2026)
One creation box (✨ top bar, Overview, Creatives, key C) and the owner's own Claude app (Max plan) through a local MCP
connector. Both feed the same pipeline: catalogue check → policy linter → local render + quality gate → executor.

| Method + path | Body → answer |
|---|---|
| POST /api/brain/x/create | `{prompt, product_ids, occasion, formats, langs, video_id, photo, request_id, key}` or a follow-up `{session_id, edit, target, key}` (one Claude API call), or authored `{authored:[ideas], key}` / `{session_id, revise:{…}, key}` (no model call: the MCP tools) → NDJSON `think` / `lint` / `done {session}` / `error`. The same `key` returns the same session; a second call while the first runs waits for it. A follow-up merges ONLY the fields Claude lists in `changed`. |
| GET /api/brain/x/create | the account's sessions (newest first) with `outcome {verdict: won\|lost\|running\|not_run, spend, purchases, roas}`; winners are fed to the next ask |
| PUT /api/brain/x/create/{id} | `{used, saved, ran, proposals}` — what the owner did with it |
| GET /api/brain/x/creation | `{mode: with_me\|api, fallback_hours, requests[], notifications[]}`; pushed as SSE `creation` whenever it changes |
| POST /api/brain/x/creation/{id}/start \| dismiss | Start opens Claude (`claude://claude.ai/new?q=<brief>`, brief on the clipboard) on a Mac → `{brief, url, opened}` |
| GET /api/brain/x/brief, /products | the MCP `get_brief` / `list_products` data |
| PUT /api/brain/x/settings | also `{creation_mode: with_me\|api, fallback_hours: 0–168}` (fields not sent keep their value; default with_me, fallback 0 = off) |
| PUT /api/brain/x/gen/one-key | `{key}` — the ONE "AI pictures & video" key (Google Gemini: Gemini image + Veo); `""` removes it |
| POST /api/launch | `{creative_ids[≤12], launch, key}` → creatives `approved` for the next cycle; `launch:true` also adds one small test campaign + ad set(s) + ads as owner proposals (`claude-cc<hash>-…`) and runs each ad through `/api/proposals/{id}/approve`'s path. Idempotent. |
| POST /api/render | `{session_id, op: preview\|save, rev}` → NDJSON `queued` / `progress {done,total}` / `done {result}` / `error {open_app}`. The open tab draws (SSE `render.request`, answer `POST /api/render/{job}`); jobs are kept until done and re-sent to a tab that (re)connects; one job per (session, rev, op). |
| GET /api/mcp, POST /api/mcp/install, POST /api/mcp/seen | the connector's status; install merges `mcpServers.kbb-ads-autopilot = {command: <engine>, args: [-mcp, -data, <data>]}` into Claude desktop's config (old file backed up; a broken file is refused untouched) |

`engine -mcp -data <dir>`: stdio MCP server (JSON-RPC 2.0, newline-delimited) for Claude desktop; finds the running
engine in `<data>/engine.json`. Tools: get_brief, list_products, get_research, create_ad_set, revise, save_to_library,
run_it (progress notifications when a progressToken is sent). Tests: `KAA_NOTIFY_CMD`, `KAA_OPEN_CMD`, `KAA_CLAUDE_CONFIG`.
Check: `NODE_PATH=… node tools/create-check.cjs [--shots]`.
