# KBB Ads Autopilot — build contract (v0, 20 Nov 2026)

The owner's offline Mac app that runs kbeautybliss.com's Meta (Facebook + Instagram) ads end to end: research what
skincare shoppers like now, write and design ads (Arabic first, English too), launch, watch, stop the losers, test new
creatives, scale the winners, and always hold the plan. Completely separate from the website (never in a website
package: `studio/` is in BuildPackage NEVER_SHIP) and from KBB Studio (its own folder, data folder and zip).

Research behind it: `reports/Claude Meta ads autopilot.md` (+ `research_notes/Claude Meta ads autopilot/`).

## The owner's decisions (20 Nov 2026)
- **Mac only.** Runs when he opens it; on open it auto-connects to the shop, Meta and Claude, catches up on what happened
  while closed, and keeps working (cycles) while open.
- **While the Mac is closed, Meta keeps the ads safe**: whenever the app launches or changes a campaign it also keeps
  Meta's own Automated Rules (adrules_library) and the account spending limit (spend_cap) in place, enforced by Meta.
- **Fully automatic inside hard limits he sets in the app.** No limit set = nothing launches. Only going ABOVE his
  limits needs him. He gets a daily summary. He never wants to look at what is running or what runs next.
- It must **learn**: the shop, its products and margins, the K-beauty / skincare market, competitors, trends, what
  wins for HIS audience (mostly Arab women, UAE/GCC). It is a full-time media buyer, not a button.
- **Arabic first**: every ad has an Arabic version (MSA/Gulf-friendly), Arabic audiences get the bulk of tests.
- **SaaS-quality UI**: one page app, instant screen switches, no page loads, super quick.

## Layout (`studio/ads-autopilot/`)
```
Start KBB Ads Autopilot.command   launcher (like KBB Studio's): picks arm64/amd64 engine, clears quarantine, starts it
engine/            Go 1.24, stdlib only, CGO_ENABLED=0, builds mac-arm64 / mac-amd64 / linux-amd64 (build.sh)
  main.go …          LANE ENGINE owns everything in engine/ EXCEPT engine/brain/
  brain/             LANE BRAIN owns: the planner, rules/statistics, research, copy, policy linter
app/               LANE BRAIN owns: the single-page UI (vanilla ES modules, no framework, no build step)
tools/             each lane adds its own check scripts (lane prefix in the name)
docs/              notes; this contract
```
Data lives outside the app folder in `~/KBB Ads Autopilot Data/` (state.json-style files or an append-only JSONL
log per collection — no cgo, no new Go modules unless pure-Go and justified in the report). Port: fixed 41927 on
127.0.0.1, random only if busy. Updates: same mechanism as KBB Studio's Configure → Updates (port update.go).

## Secrets
Meta system-user token, Meta app id/secret, Anthropic API key, shop API token: entered ONLY in the app's
Settings → Connections, stored in the macOS Keychain (`security add-generic-password`, service "KBB Ads Autopilot"),
fallback a 0600 file in the data folder on Linux tests. Never logged, never sent to the browser after saving (the UI
sees `{set: true, last4}`), never in the repo. The owner never pastes keys in chat.

## Engine HTTP API (127.0.0.1 only, JSON, a per-launch session token header `X-KAA` the page gets from /api/info)
| Method + path | Body / returns |
|---|---|
| GET /api/info | `{app, version, apiLevel, token, dataDir}` |
| GET /api/connections | `{meta:{ok, account:{id,name,currency,timezone,spend_cap}, error}, claude:{ok,model,error}, shop:{ok,products,orders_source,error}}` |
| PUT /api/connections/{meta\|claude\|shop} | save secrets/ids, then test; returns the same block |
| GET/PUT /api/limits | `{daily_aed, monthly_aed, max_budget_step_pct (default 20), cooldown_hours (default 72), max_active_ads, countries:["AE",…], autopilot:"off"\|"shadow"\|"auto"}` — numbers in AED (the engine converts to fils) |
| GET /api/state | everything the dashboard needs in ONE call: campaigns/adsets/ads with status + last metrics, today/7d/30d KPIs (spend, purchases, revenue, CPA, ROAS, CPC, CTR, CPM, frequency), the current plan, next cycle time, alerts |
| GET /api/events (SSE) | live pushes: `cycle.start/end`, `action.*`, `alert`, `metrics.updated`, `research.updated`; also the heartbeat |
| POST /api/cycle | run a cycle now (`{mode:"shadow"\|"auto"}`) |
| GET /api/log?after=… | the append-only audit log (snapshots, proposals, checks, executions, Meta responses) |
| GET/POST /api/creatives | list / add a creative: `{id, lang:"ar"\|"en", format:"1:1"\|"4:5"\|"9:16", png:base64 \| video:base64, headline, primary_text, cta, link, product_ids[], angle, source:"brain"\|"owner"}` |
| GET /api/research | the brain's market notes (trends, competitors, angles, Arabic insights), dated |
| POST /api/brain/{plan\|research\|copy} | LANE BRAIN's endpoints, served by the engine calling into `brain` |
| POST /api/kill | stop everything: pause every campaign the app made, autopilot off |

## Account scoping (the owner, 20 Nov 2026 — added by LANE ENGINE)
"Read the whole business portfolio including all ad accounts, and train itself … switch from one ad account to
another, and give multiple ad accounts to automate separately."
- The engine lists every ad account the Meta token sees (business owned + client, and personal ones) with the
  business's Pages, Instagram accounts, pixels, catalogs and WhatsApp accounts: `GET /api/accounts`.
- **Everything is per ad account**: automated on/off, limits + autopilot mode, linked assets (`AccountLinks`), the
  Meta safety rules + spend_cap, cooldowns / idempotency ledger, audit log, plan, report, creatives, history import —
  in `~/KBB Ads Autopilot Data/accounts/<act_id>/`. An account with no limits cannot launch anything.
- Account-scoped endpoints take `X-KAA-Account: act_…` (or `?account=`), else the selected account
  (`POST /api/accounts/select`). SSE events are `{account, data}`. `POST /api/kill` without an account stops ALL.
  Full list: `docs/ENGINE-API.md`.
- An optional all-accounts daily/monthly cap (`/api/limits/global`) is checked by the executor on top of each
  account's own.
- **One `CycleInput` = one ad account.** Its proposals act on that account only. Extra inputs (model fields, added,
  nothing renamed): `Links`, `LongHistory` (campaign-level daily rows up to 37 months), `Creatives37` (every past
  ad's creative kind), `Learnings` (portfolio-wide aggregates by format / objective / country across all accounts).
- Proposal kinds added: `create_audience`, `create_lookalike`, `create_product_set`; `upload_creative` takes
  `format` image | video | carousel | flexible | catalog | collection; ad sets take `destination` website | whatsapp |
  messenger | instagram_direct, placements and Advantage+ audience suggestions. Params per kind: comment above
  `model.Proposal`; facts: `docs/META-FACTS.md`; setup without Business Verification: `docs/META-SETUP.md`.

## The one rule: Claude proposes, the executor decides
`brain` NEVER calls Meta. It returns **Proposals**; only the engine's executor writes to Meta, after its checks.
```go
// engine/brain (LANE BRAIN) implements:
type Brain interface {
    Cycle(ctx context.Context, in CycleInput) (CycleOutput, error)      // the planner, run by the scheduler
    Research(ctx context.Context, in ResearchInput) (Research, error)   // market/trend/competitor research (web search)
}
// shared types live in engine/model (LANE ENGINE writes them first; BRAIN may add fields, never rename):
type CycleInput struct {
    Now time.Time; Limits Limits; Account Account
    Tree []Campaign            // campaigns > adsets > ads, status, budgets (AED), learning state, review feedback
    Metrics []MetricRow        // per object per day, attribution 7d_click/1d_view, AED; last 2 days flagged "settling"
    Shop ShopSnapshot          // products (id,title,price,stock,margin_pct if known,image,url), orders (net revenue, refunds, by day)
    Creatives []Creative       // library incl. unused
    Research Research          // latest notes
    History []LogEntry         // recent decisions + outcomes, so it learns
}
type CycleOutput struct { Plan Plan; Proposals []Proposal; Report string /* the daily-summary text, AR+EN */ }
type Proposal struct {
    ID string; Kind string // create_campaign | create_adset | create_ad | pause | resume | set_budget | set_bid | upload_creative | archive
    Target string          // Meta object id, or a local temp id for new objects
    Params map[string]any  // budgets in AED (float), never fils
    Why string; Confidence float64; Evidence []string
}
```
Executor checks, in order, every proposal: kind allow-listed → limits (daily/monthly caps incl. what is already
running, ±max_budget_step_pct, cooldown per object, max_active_ads, countries) → Meta write-rate (≤4 budget changes
per ad set per hour, ≥30 s between edits of one object, BUC headers) → policy linter (copy) → in `shadow` mode logged
only. AED → fils is done ONLY in the executor (`fils = round(aed*100)`, bounds-checked against the caps). New Meta
objects are created PAUSED and set ACTIVE as a separate checked step. After any launch/change the executor makes sure
the Meta-side safety rules and spend_cap (≤ monthly limit, never raised by the brain) are in place.

## Meta
Graph/Marketing API version configurable (default v27.0; verify on developers.facebook.com, now reachable).
Sales objective (OUTCOME_SALES), Advantage+ sales (campaign budget + Advantage+ audience + placements), pixel/dataset
of the shop, product catalog where useful. A mock Graph server in `engine/metamock` (LANE ENGINE) that both lanes'
checks run against — no check ever touches the real Meta.

## Shop
v0 reads the public product feed `https://kbeautybliss.com/feeds/meta-catalog.xml` (products, prices, images).
Orders/margins need an authenticated read-only shop endpoint — a WEBSITE change, done separately; the engine has a
`ShopSource` interface and returns `orders_source:"none"` until it exists.

## Proof
Every lane: check scripts with numbers, screenshots at 1440×900 and 1280×800 (UI), mutation notes, no console
errors, nothing outside `studio/ads-autopilot/` changed.
