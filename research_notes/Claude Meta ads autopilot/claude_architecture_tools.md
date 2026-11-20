# Claude as the "brain" of an autonomous Meta ads manager — Anthropic building blocks, Meta integrations, architecture (as of 20 Nov 2026)

Research date: 2026-11-20. Network note: developers.facebook.com, ppc.land and usecarly.com were NOT reachable from this research session (DNS refused by the session's network policy), so Meta's own MCP docs could not be read directly; Meta-side facts below come from search-result summaries of those pages and third-party guides and are flagged as such. Anthropic pricing was read live from platform.claude.com on 2026-11-20.

## 1. Anthropic options: surfaces, models, pricing, limits, policy

### Takeaway
Four ways to run the planner exist: (1) Messages API + your own tool loop (host it yourself, e.g. a Laravel artisan command on cron), (2) the SDK "Tool Runner" helper, (3) Claude Managed Agents (beta; Anthropic hosts loop + sandbox, has built-in cron "scheduled deployments", per-tool approval policies, vault-held credentials, dollar-capped session budgets, $0.08/session-hour), (4) Claude Agent SDK (Claude Code as a library, self-hosted). Current mid-tier price is Sonnet 5.5 at $2/$10 per MTok; Opus 5.5 $4/$20; Haiku 5.5 $0.10/$0.50; Batch API is 50% off. A Claude decision loop for one small shop costs on the order of a few US dollars per day.

### Cited Findings
**Model lineup and prices (USD per million tokens, read 2026-11-20)** — [Claude pricing docs](https://platform.claude.com/docs/en/about-claude/pricing)
- Claude Opus 5.5: input $4, 5-min cache write $5, 1-h cache write $8, cache hit $0.20, output $20.
- Claude Sonnet 5.5: input $2, cache write $2.50 / $4, cache hit $0.10, output $10.
- Claude Haiku 5.5 (prompts ≤100k tokens): input $0.10, cache write $0.125 / $0.20, cache hit $0.01, output $0.50; prompts >100k: $0.50 / $2.50.
- Claude Haiku 4.5: $1 / $5. Claude Fable 5.2 (most capable widely released): $8 / $40, cache hit $0.20. Fable 5.1 $10/$50.
- Cache hits on Opus 5.5 and Sonnet 5.5 are 0.05x base input (other models ~0.1x).
- Batch API: "50 percent discount on both input and output tokens" (asynchronous).
- Full 1M-token context at standard price for Claude 4.6+ models (except Haiku 5.5, priced by prompt length).
- `inference_geo: "us"` = 1.1x multiplier; default global = standard price. No UAE/Middle-East data-residency option was found in the pricing page.
- Managed Agents: billed on tokens at model rates + **session runtime $0.08 per session-hour**, accrued only while status is `running` (idle/waiting for tool confirmation does not count); web search $10 per 1,000; Batch discount does NOT apply to Managed Agents sessions.
- Cache economics: writes cost 1.25x (5-min TTL) or 2x (1-h TTL); reads refresh the TTL. Model IDs: `claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5` — [Anthropic claude-api skill reference, cached 2026-11-03, consistent with the live pricing page](https://platform.claude.com/docs/en/about-claude/pricing)

**API surfaces** (from Anthropic's official claude-api skill reference bundled with Claude Code, v2.1.329; cross-check URLs: [Messages/tool use](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview), [Managed Agents overview](https://platform.claude.com/docs/en/managed-agents/overview), [Agent SDK](https://code.claude.com/docs/en/agent-sdk))
- Messages API tool use: you define tools (JSON schema); `strict: true` on a tool guarantees inputs validate against the schema; structured outputs via `output_config.format` (old `output_format` deprecated). On Opus 5.5 / Sonnet 5.5 forced `tool_choice` (`any`/`tool`) returns 400 — use `auto` + `strict` or structured outputs.
- Opus 5.5: thinking cannot be disabled; effort (`low`…`max`) is the control, default `medium`. Sonnet 5.5 default effort `high`.
- MCP connector on the Messages API: `mcp_servers=[{type:"url",url,name}]` plus `tools=[{type:"mcp_toolset", mcp_server_name}]`, beta header `mcp-client-2025-11-20` — lets the API call a remote MCP server (e.g. a Meta Ads MCP) directly.
- Managed Agents (beta header `managed-agents-2026-04-01`): persisted versioned agent configs; per-session container; **scheduled deployments** run an agent on a cron `expression` + IANA `timezone` (minute granularity) with per-run records and pause/unpause; **permission policies** per tool: `always_allow`, `always_ask` (session pauses until a `user.tool_confirmation` event — the default for MCP toolsets), `auto` (server evaluates each call: runs, denies as high-risk, or pauses); **vault** credentials substituted at egress so the sandbox only sees a placeholder; **session budgets** are hard dollar-denominated, platform-enforced caps per session; webhooks for events. Not ZDR/HIPAA eligible; keeps session history until deleted.
- Task budgets (beta `task-budgets-2026-03-13`): advisory token ceiling the model paces against inside a loop (min 20,000).
- Prompt caching: prefix match; order tools → system → messages; max 4 breakpoints; keep timestamps/metrics after the last breakpoint.
- Mid-conversation `role: "system"` messages (Opus 5.5, Sonnet 5.5, Haiku 5.5) are described by Anthropic as "the prompt-injection-safe operator channel" that also preserves the cache.
- Refusal handling: Opus 5.5 / Sonnet 5.5 can return `stop_reason: "refusal"`; server-side `fallbacks` (beta `server-side-fallback-2026-07-01`) can reroute.
- Message Batches: async, results keyed by `custom_id`, any order.

**Usage policy**
- Anthropic updates the Usage Policy yearly; the 2026 update rewrote the "High-risk Use Case Requirements" section — [Anthropic 2026 usage policy update](https://anthropic.com/news/2026-usage-policy-update)
- The 2025 update said high-risk requirements (human oversight, disclosure) apply to legal, financial and employment use cases, and to consumer-facing outputs — [Anthropic usage policy update](https://www.anthropic.com/news/usage-policy-update); summary via [Neowin](https://www.neowin.net/news/anthropic-updates-policy-to-tackle-new-agentic-ai-risks/)
- Agent uses must adhere to the Usage Policy; Anthropic publishes non-exhaustive agentic examples — [Claude Help Center: using agents according to our usage policy](https://support.claude.com/en/articles/12005017-using-agents-according-to-our-usage-policy)
- A third-party tracker describes a policy version effective 12 Nov 2026 with a high-risk tier mandating qualified human-in-the-loop review — [ConductAtlas](https://conductatlas.com/platform/anthropic/anthropic-usage-policy/high-risk-use-case-requirements-for-medical-legal-and-financial-advice) (UNVERIFIED against anthropic.com/aup).

### Inferences
- Managing a shop's own ad spend is the operator's own business automation, not "financial advice to consumers" or creditworthiness decisions; it does not obviously fall in the high-risk consumer-facing category. Not legally verified — read the current [AUP](https://www.anthropic.com/aup) before launch.
- **Cost estimate (assumption-labelled):** hourly cycle, ~20k-token stable prefix (system prompt + tool schemas + rules), ~10k fresh tokens (metrics snapshot), ~3k output incl. thinking. Hourly gap > 5 min, so the prefix is re-written each cycle (or use 1-h TTL).
  - Sonnet 5.5: 20k×$2.50 + 10k×$2 + 3k×$10 per MTok ≈ $0.05+$0.02+$0.03 = ~$0.10/cycle → **~$2.40/day, ~$72/month**.
  - Opus 5.5: roughly double → **~$5/day**.
  - Haiku 5.5 as a cheap "anomaly watcher" every 15 min: ~$0.005/cycle → **~$0.50/day**.
  - Daily strategy review on Opus 5.5 via Batch API (50% off), ~100k in / 10k out ≈ $0.30/day.
  - If run on Managed Agents: add $0.08/session-hour of running time (24 sessions × ~5 min ≈ 2 h ≈ $0.16/day).
  - Thinking tokens are billed as output; effort level drives the real number — measure `usage` before trusting this estimate.
- For a Laravel/Cloudways shop with SSH and cron, the cheapest-to-own pattern is a PHP artisan command on the existing scheduler calling the Messages API (Anthropic ships an official PHP SDK, per the skill reference) with tools that hit the shop's own executor service. Managed Agents is attractive if the owner wants approvals/scheduling hosted by Anthropic, but it is beta and the Meta token would live in an Anthropic vault.

### Gaps
- Exact current rate-limit tiers (RPM / ITPM / OTPM per tier) were not fetched; see platform.claude.com/docs/en/api/rate-limits. For a once-an-hour loop they are not a constraint at any tier (inference).
- Claude Code "scheduled tasks" / remote routines exist (this research environment exposes them, minimum hourly), but I did not fetch public docs for them; treat as unverified for production use.
- No Anthropic data-residency option for the Gulf region was found.

## 2. Existing Meta Ads MCP servers / agent tools and commercial "AI media buyers"

### Takeaway
Meta now ships an **official remote Ads MCP server** (`mcp.facebook.com/ads`, open beta since 29 April 2026; developer-blog post 16 July 2026) with ~29 tools covering reads AND writes (create campaigns/ad sets/ads, change budgets/status), with Claude Desktop/Code listed as validated clients. New objects land PAUSED, but reportedly budget edits to live objects take effect immediately with no server-side approval step — approval is the client's job. Pipeboard's community server (BSL 1.1) is the main alternative. Commercial tools (Madgicx, Bïrch) cost ~$45–$250+/month and are rule/insight tools rather than a custom LLM brain.

### Cited Findings
**Official Meta Ads MCP / Ads AI Connectors** (Meta pages could not be fetched; facts from search summaries — treat endpoint/tool details as "reported")
- Meta documentation pages exist for "Ads AI Connectors → Ads MCP Server" — [Meta for Developers: Ads MCP Server overview](https://developers.facebook.com/documentation/ads-commerce/ads-ai-connectors/ads-mcp-server/ads-mcp-server-overview); [Get started](https://developers.facebook.com/documentation/ads-commerce/ads-ai-connectors/ads-mcp-server/ads-mcp-server-get-started); Meta developer blog post dated 16 July 2026 — [Meta ads MCP server](https://developers.facebook.com/blog/post/2026/07/16/meta-ads-mcp-server/)
- Open beta 29 April 2026; hosted HTTP MCP server at `mcp.facebook.com/ads` plus a CLI; free during beta; Meta Business OAuth (no developer app/token to manage) — [adadvisor.ai](https://adadvisor.ai/blog/mcp-facebook-com-ads-official-meta-setup); [mcp.directory](https://mcp.directory/blog/meta-ads-cli-mcp); [ppc.land](https://ppc.land/meta-opens-its-ad-system-to-claude-and-chatgpt-with-new-ai-connectors/). Conflicting endpoints (`mcp.ads.meta.com`, `mcp.meta.com/ads`) appear in single sources — [adsuploader](https://adsuploader.com/blog/meta-ads-mcp).
- Tool count: most sources say 29 (one says ~90); one breakdown: Campaign Management 5, Product Catalog 10, Assets 3, Diagnostics 4, Insights 7 — [pasqualepillitteri.it](https://pasqualepillitteri.it/en/news/1707/official-meta-ads-mcp-claude-29-tools-2026); [theadspend.com](https://theadspend.com/blog/what-meta-ads-mcp-can-and-cant-do)
- Validated clients reportedly include Claude Desktop, Claude Code, ChatGPT, Codex, Cursor — [Meta MCP documentation (via search summary)](https://developers.facebook.com/documentation/mcp)
- Writes: create campaigns/ad sets/ads; update budgets and status; requires `ads_management` permission — [ferrerponseti.com](https://ferrerponseti.com/en/blog/official-meta-ads-mcp-claude/); [paidsync.ai](https://paidsync.ai/blog/official-meta-ads-mcp-guide)
- Safety: created objects land PAUSED; but edits to existing campaigns (incl. budget) reportedly go live immediately with no staging/approval in the protocol; permission panel reportedly shipped with all 7 write actions enabled by default (Business Settings → Integrations → Ads MCP Server) — [mintec.co](https://mintec.co/blog/meta-ads-mcp-default-write-permissions/); [adadvisor.ai write failures](https://adadvisor.ai/blog/facebook-mcp-write-actions-fail). Rate limits: unpublished per one source; another cites standard Marketing API limits — [adsuploader](https://adsuploader.com/blog/meta-ads-mcp) (conflicting/unverified).

**Community / third-party MCP servers**
- Pipeboard `meta-ads-mcp`: 19 read tools + 9 write tools (create campaign/ad set/ad/creative, update ad/ad set/creative, upload image, create budget schedule); no direct "set daily budget" tool; pausing via status update; new objects default PAUSED; remote hosted OAuth or local with your own Meta app; **Business Source License 1.1** (no competing hosted service; converts to Apache 2.0 on 1 Jan 2029); ~1.4k stars, 287 forks, 550 commits; `?token=` URL puts a credential in the URL — [GitHub pipeboard-co/meta-ads-mcp](https://github.com/pipeboard-co/meta-ads-mcp) (fetched 2026-11-20; last-commit date not shown)
- Markifact hosted Meta Ads MCP: "approval on every write", OAuth, no local install — [GitHub markifact/meta-ads-mcp](https://github.com/markifact/meta-ads-mcp)
- Others: [attainmentlabs/meta-ads-mcp](https://github.com/attainmentlabs/meta-ads-mcp), [hashcott/meta-ads-mcp-server](https://mcpservers.org/servers/hashcott/meta-ads-mcp-server); a vendor test of 14 servers — [get-ryze.ai](https://www.get-ryze.ai/blog/best-mcp-for-meta-ads) (vendor-affiliated). Composio/Zapier/n8n Meta Ads tools: not researched in this pass (gap).

**Commercial "AI media buyer" tools** (mostly vendor/comparison blogs; verify on vendor sites)
- Bïrch (ex-Revealbot): rules-based automation across Meta/Google/TikTok; ~$49 Essential, ~$99 Pro; Pro reportedly $249 at ≤$30k monthly spend; 14-day trial — [adlibrary.com](https://adlibrary.com/posts/madgicx-vs-revealbot); [Madgicx blog (competitor)](https://madgicx.com/blog/birch-review)
- Madgicx: Meta-focused AI audiences/insights; spend-tiered from ~$45/mo (<$1k spend) to $329+; Tracking Pro attribution +$49/mo — [get-ryze.ai Madgicx pricing](https://www.get-ryze.ai/blog/madgicx-pricing-2026) (conflicting entry price ~$55 in another source)
- Meta's own automation: WSJ (June 2025) reported Meta aims to let brands fully create and target ads with AI by end of 2026 — advertiser supplies product image + budget, Meta generates image/video/text, targeting and budget suggestions — [Campaign Asia](https://www.campaignasia.com/article/meta-aims-to-fully-automate-ad-creation-with-ai-by-2026/502831); [eMarketer](https://www.emarketer.com/content/ai-driven-ad-creation-could-give-meta-more-control-decrease-need-agencies). No official Meta confirmation that this has shipped was found.

### Inferences
- Meta's Advantage+ already does in-auction optimisation (bidding, placement, audience) far better than any external agent can; a custom Claude brain should sit ABOVE it (what to promote, how much to spend per day, when to pause/refresh creative, reading shop margin/stock data Meta does not see) — complement, not replace.
- The official MCP is fine for read/analysis by Claude, but because writes are not gated server-side, an autonomous system should not hand Claude the MCP write tools directly; route writes through a self-owned executor with hard limits (section 3).
- For a small shop spending a few thousand AED/month, Madgicx/Bïrch subscription (~$45–$100) is comparable to or more than Claude token costs (~$70/month estimate), but they do not know the shop's margins/stock.

### Gaps
- Could not read Meta's own MCP docs (host blocked) — exact tool names, scopes, rate limits, UAE availability unverified.
- Smartly, AdCreative.ai pricing; Composio/Zapier/n8n Meta Ads action lists — not researched.
- Pipeboard last-commit date/maintenance cadence not visible in fetch.

## 3. Reference architecture (planner vs executor, approvals, scheduling, state, audit, secrets, eval)

### Takeaway
No authoritative published reference architecture for "LLM controls ad spend" was found; the design below is assembled from Anthropic's documented control primitives (permission policies, session budgets, strict tools, structured outputs) and Meta MCP safety facts. Core rule: Claude proposes structured actions; deterministic code validates them against hard limits and executes; risky actions wait for a human click.

### Cited Findings
- Managed Agents provides `always_ask` (pause until human confirms), `auto` (server judges each call: run/deny/ask) and `always_allow` per tool; MCP toolsets default to `always_ask`; a confirmation for a call the server denied cannot be overridden — [Managed Agents tools / permission policies (skill reference)](https://platform.claude.com/docs/en/managed-agents/overview)
- Managed Agents session budgets are hard dollar caps enforced by the platform; vault credentials keep secrets out of the sandbox — [Managed Agents overview](https://platform.claude.com/docs/en/managed-agents/overview); pricing [docs](https://platform.claude.com/docs/en/about-claude/pricing)
- Scheduled deployments: cron + timezone, minute granularity, run records, pause/unpause — [Managed Agents overview](https://platform.claude.com/docs/en/managed-agents/overview)
- Strict tool schemas guarantee argument shape (not argument sanity) — [tool use docs](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview)
- Meta writes on existing objects apply immediately; new objects default PAUSED — [mintec.co](https://mintec.co/blog/meta-ads-mcp-default-write-permissions/); [Pipeboard README](https://github.com/pipeboard-co/meta-ads-mcp)
- Third-party Meta-ads MCP vendors (Markifact, Pipeboard remote) advertise "approval on every write" as a selling point — [Markifact](https://github.com/markifact/meta-ads-mcp); [notfair.co](https://notfair.co/blog/meta-ads-mcp)

### Inferences (design proposal — not sourced as a standard)
- **Planner (Claude)**: reads a metrics snapshot the executor assembled (Meta Insights + shop orders/margin/stock from Laravel DB), outputs a JSON action list via structured outputs: `{action, object_id, from, to, reason, expected_effect, confidence}`. No direct Meta write credentials.
- **Executor (deterministic PHP)**: allow-list of actions (pause ad, resume ad, change ad-set daily budget, duplicate winning ad, create PAUSED draft); hard limits in config, not prompt: account daily cap, monthly cap, max ±20% budget change per object per 24h (Meta's learning phase resets on large edits — verify), min data threshold (e.g. ≥N conversions or ≥X spend before judging), cooldown per object, max actions per cycle; dry-run mode; kill switch (a setting/flag checked before every write); idempotency keys; re-reads the object from Meta before writing.
- **Approval tiers**: auto-execute low-risk (pause a loser, budget −/+ ≤10%); human approval for budget increases above threshold, new campaigns, anything activating spend; approvals via signed one-time links in email/WhatsApp/Slack with expiry; default on timeout = do nothing.
- **Scheduling**: the existing Cloudways `schedule:run` cron: hourly metrics pull + cheap Haiku anomaly check; Sonnet/Opus planner 1–4×/day (Meta data is delayed/attributed late, hourly re-planning mostly reacts to noise); event triggers for hard stops (spend spike, CPA blow-out, out-of-stock product) handled deterministically without the LLM.
- **State/audit**: tables for metrics snapshots, proposed actions, approvals, executed actions (before/after values, Meta response), model id, prompt hash, token usage; append-only.
- **Secrets**: Meta System User token (long-lived, least-privilege scope `ads_management`/`ads_read`) in `.env` on server, never in prompts; Anthropic key likewise; rotate.
- **Failure handling**: any API/parse/refusal error → no-op and alert; never retry a write blindly.
- **Evaluation/backtesting**: replay stored daily snapshots through the planner and score proposals against what happened next; shadow mode (dry-run, log-only) for 2–4 weeks before enabling writes; track decision outcome vs a "do nothing" baseline.

### Gaps
- No published case studies/benchmarks of LLM agents managing Meta budgets with measured ROAS outcomes were found.
- Meta's exact learning-phase reset rules for budget edits not verified in this pass.

## 4. Creative generation

### Takeaway
Claude can write ad copy, briefs, Arabic/English variants and critique creative, but does not generate images/video itself. Meta's Marketing API exposes generative creative features (text generation, image expansion, background generation) opted in per creative via `degrees_of_freedom_spec → creative_features_spec`.

### Cited Findings
- Meta's "Generative AI Features on Marketing API": text generation, image expansion, background generation, each opt-in with `enroll_status` OPT_IN/OPT_OUT inside `creative_features_spec` in `degrees_of_freedom_spec` on `adcreatives` — [Meta for Developers: Generative AI features](https://developers.facebook.com/documentation/ads-commerce/marketing-api/creative/generative-ai-features); [Degrees of Freedom Spec v26.0](https://developers.facebook.com/docs/marketing-api/reference/ad-creative-degrees-of-freedom-spec)
- Image expansion adjusts aspect ratios; background generation creates up to four backgrounds from product images (2024 announcement) — [Graph/Marketing API v20.0 blog](https://developers.facebook.com/blog/post/2024/05/21/introducing-facebook-graph-and-marketing-api-v20/)
- The standard-enhancements opt-in bundle was deprecated from v22.0; features opted individually (Meta wording vs third-party wording differs) — [Advantage+ Creative get started](https://developers.facebook.com/documentation/ads-commerce/marketing-api/creative/advantage-creative/get-started.md); [eggknite](https://www.eggknite.com/blog/turn-off-advantage-plus-creative-enhancements)
- Meta's agency catalogue advises starting AI-enabled creatives PAUSED — [facebookincubator catalogue-of-api-solutions](https://github.com/facebookincubator/catalogue-of-api-solutions/blob/main/solutions/creative/ai-creative-enhancer.md)
- Claude models accept image input (vision) and the code-execution sandbox has pillow/matplotlib — [skill reference / tool docs](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview)

### Inferences
- Practical split: Claude writes copy + picks product + drafts brief; real product photos from the shop's catalogue; Meta's own generative enhancements for format adaptation; human approves any new creative before it goes live (brand/claims risk, cosmetics claims regulation in UAE).

### Gaps
- Third-party image/video generators (licensing terms, commercial-use rights) not researched in this pass.
- UAE advertising rules for cosmetics claims not researched.

## 5. Failure modes of LLM agents controlling spend and mitigations

### Takeaway
Main risks: hallucinated/misread metrics, over-reaction to small-sample noise and Meta's delayed attribution, prompt injection through untrusted text (ad comments, product reviews, ad names), unbounded writes because Meta applies edits instantly, and silent tool failures. Mitigations are mostly deterministic: computed metrics, thresholds, caps, approvals, and treating all fetched text as data.

### Cited Findings
- Meta MCP edits to live objects apply immediately with no protocol-level approval; write tools reportedly enabled by default — [mintec.co](https://mintec.co/blog/meta-ads-mcp-default-write-permissions/)
- Common write-action failures through the Meta MCP documented by practitioners — [adadvisor.ai](https://adadvisor.ai/blog/facebook-mcp-write-actions-fail)
- Anthropic: mid-conversation system messages are the prompt-injection-safe operator channel; `auto` permission policy evaluates each call with session content and can deny high-risk calls — [Managed Agents / prompt caching docs (skill reference)](https://platform.claude.com/docs/en/managed-agents/overview)
- Opus 5.5 / Sonnet 5.5 may stop with `refusal`; code must check `stop_reason` before acting — [model docs](https://platform.claude.com/docs/en/about-claude/pricing)
- Tool-input JSON must be parsed, never string-matched; with eager input streaming inputs can be truncated and must be validated — [tool use docs](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview)

### Inferences
- Hallucinated metrics → executor computes all KPIs (CPA, ROAS, margin-adjusted ROAS) in SQL and passes numbers; Claude never computes the numbers it acts on; executor re-checks the "reason" numbers against its own data and rejects mismatches.
- Noise → minimum spend/conversion thresholds, multi-day windows, cooldowns, max % change; daily rather than hourly planning.
- Prompt injection → never feed raw comments/reviews into the planning context with write tools available; if comments are analysed, do it in a separate read-only call with no tools and pass only structured sentiment counts.
- Runaway spend → caps enforced in code and also set in Meta itself (account spending limit, campaign lifetime/daily budgets) as a second independent stop.

### Gaps
- No primary-source incident reports of LLM-agent ad overspend were found; no Anthropic page specifically on prompt injection was retrieved in this pass.
