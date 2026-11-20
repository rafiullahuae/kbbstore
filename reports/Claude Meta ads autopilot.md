# Let Claude steer, let code hold the brakes

**Bottom line.** kbeautybliss.com can build its own Meta ads autopilot on parts it mostly has already. The shop has a direct Conversions API integration, a catalog feed, a Laravel scheduler cron on Cloudways and SSH access. To these it adds a Meta System User token, a Claude planning loop called from an artisan command, and a deterministic PHP "executor" that is the only thing allowed to write to Meta. The design that survives the evidence is **not** "Claude runs the ad account". It is: Claude reads numbers the shop computed, proposes structured actions, and hard limits in code decide what actually happens. Three reasons drive that design. Meta applies edits to live campaigns instantly, with no approval step. Meta's own algorithm (Advantage+ sales) already does in-auction bidding and targeting better than any outside agent. And a small shop's purchase volume is far too low for hourly decisions to be anything but reactions to noise. Claude adds value above Meta's machine, deciding what to promote, how much to spend per day, when to pause or refresh creative, and doing so with margin, stock and refund data Meta never sees. Running costs are modest: on the order of **USD 70 a month in Claude tokens** for an hourly Sonnet-class loop (a labelled estimate), versus USD 45–330 a month for commercial tools that do not know the shop's margins. The real risks are elsewhere. They are AI-written skincare copy that breaks Meta's personal-attributes and health rules or UAE health-claim rules, budget unit errors (Meta budgets are integers in fils), and account-integrity restrictions that are hard to appeal. **Read this first: this research environment's proxy refused developers.facebook.com, facebook.com and transparency.meta.com. Every Meta fact in this report comes from search-result extracts of Meta's pages, a GitHub issue summarising Meta's changelog, or third-party sources. None was read on Meta's live page. Every Meta number, field name, limit and policy wording below must be checked on the live page before it is hard-coded.**

> **Evidence labels used throughout.** **[Verified-in-repo]** = read in this repository's code on 20 Nov 2026. **[Anthropic-live]** = read live on platform.claude.com on 20 Nov 2026. **[Meta-extract]** = a Meta page's wording as quoted in a search result, not opened. **[Secondary]** = vendor, agency, blog or press source. **[Inference]** = the research team's reasoning, not a sourced fact. Nothing labelled Meta-extract or Secondary should be treated as verified.

## The shop already owns half the plumbing; refunds and consent are the holes

The measurement layer an autopilot depends on is largely built. **[Verified-in-repo]** `app/Services/Pixels/ServerEvents.php` posts server events to `graph.facebook.com` at `GRAPH_VERSION = 'v25.0'` (`app/Services/Pixels/PlatformClient.php:22`). The browser pixel and the server share a deterministic `purchase-<order number>` event id, which is the correct way to deduplicate. Purchase fires when an order reaches `PLACED = ['processing','onhold','shipped','completed']` (`ServerEvents.php:69`). It fires synchronously, because the host runs no queue worker. `_fbp` and `_fbc` are captured from cookies. A catalog feed exists at `/feeds/meta-catalog.xml` (`routes/marketing-catalog-feeds.php`), with ids that follow the shop's `CatalogIds` rule. **No refund or cancellation event exists anywhere in `app/Services/Pixels`.**

That gap matters more than it looks. Third-party sources agree that refunds should go to Meta as a *custom* event, never as a negative Purchase. They also agree that such an event **does not correct ROAS or purchase value in Ads Manager** ([Pixel Plus](https://help.smart-modules.com/modules/pixel-plus/refund-event); [Superwall](https://superwall.com/docs/integrations/meta-conversion-api)). So an autopilot that trusts Meta's purchase value will over-credit Meta for every returned cash-on-delivery parcel and every refunded BNPL order. The `onhold` status deserves a deliberate decision too **[Inference]**. If on-hold means COD or awaiting payment, Purchase is sent before money is certain. That may be an acceptable definition of a sale, but the autopilot then has to discount it.

Deduplication has one subtle trap. Meta pairs browser and server events on event_name plus event_id within **48 hours of when Meta first receives an event, not from the event_time sent** ([endframe.io](https://www.endframe.io/blog/meta-capi-event-deduplication-event-id)) [Secondary]. Server events may also be at most **7 days old**, and one source says a single over-age event fails the whole request ([enalitica](https://enalitica.com/blog/meta-conversions-api-capi-ecommerce)) [Secondary]. The missing piece is therefore a database-backed retry for failed sends, driven by the existing cron. It should never re-send a Purchase older than about 47 hours, and nothing older than 7 days. Payment confirmation timing supports firing on authorisation rather than creation. Tamara expects authorisation on its `order_approved` webhook, and an approved order expires if not authorised within **72 hours** ([Tamara docs](https://docs.tamara.co/reference/authoriseorder)). Tabby reports both authorise and capture as `authorized`, and both close and refund as `closed` ([Tabby docs](https://docs.tabby.ai/pay-in-4-custom-integration/webhooks)). Hooking refund and cancel events off order-status changes in `MarketingPixelsServiceProvider` would avoid touching `app/Services/Payments/`, which CLAUDE.md freezes **[Inference]**.

Match quality and attribution need attention as well. Hashed email, hashed phone and the `fbc` click id drive Event Match Quality. Practitioner targets range from "6+" to "8+" on a 0–10 scale ([chatterbuzzmedia](https://www.chatterbuzzmedia.com/blog/meta-conversions-api-guide/); [noticemesenpai](https://noticemesenpai.com/learn/meta-conversions-api-setup-emq-2026/)) [Secondary]. The repo's `external_id` is a hash of `kbb-customer-<id>`, so guests send none. Adding hashed `em` and `ph` (UAE phone normalised to `9715…`) is the bigger lever **[Inference]**. Meta's reporting model also changed under the shop's feet in 2026:

- **7-day-view and 28-day-view windows stopped returning in the Ads Insights API on 12 January 2026** ([Meta dev blog, 16 Oct 2025](https://developers.facebook.com/blog/post/2025/10/16/ads-insights-api-metric-availability-updates)) [Meta-extract].
- "Click-through" was narrowed to link clicks. Likes, saves and video views moved to a 1-day "engage-through" bucket ([dataslayer](https://www.dataslayer.ai/blog/meta-attribution-change-2026-what-engage-through-attribution-is-and-why-your-numbers-look-different)) [Secondary].

The consequence is blunt. **Meta-reported purchases are a modelled number that will exceed true sales**, so the autopilot must judge spend on shop-side, refund-netted revenue and treat Meta's figure as a ceiling. Reconciliation can report three numbers daily: Meta-claimed, shop-matched (orders carrying `fbc` or `utm_source=facebook|instagram`), and net of refunds and cancellations.

Two more measurement items are cheap but unconfirmed. Several 2025–26 sources say Meta **removed the 8-event Aggregated Event Measurement cap for web around June 2025** ([segwise](https://segwise.ai/blog/facebook-aggregated-event-measurement); [Jon Loomer](https://pubcast.jonloomer.com/videos/the-changes-to-aggregated-event-measurement-and-meta-conversion-campaigns/)) [Secondary]. Another notes that Meta never documented this on a primary page ([trackparity](https://www.trackparity.com/blog/meta-aggregated-event-measurement-aem-shopify/)). Check the shop's own Events Manager for an AEM tab. For Arabic ads, Meta supports **language override feeds** keyed by the same item ids, with prices only in the main or country feed ([Meta Business Help](https://en-gb.facebook.com/business/help/1852644738394400)) [Meta-extract]. The exact `ar_AE` code format was not confirmed.

## Meta's API allows full control, but its limits shape every decision rule

Programmatic management through the Marketing API is the sanctioned route. It covers "creating, updating, and deleting ad campaigns, ad sets, and ads" ([Meta Marketing API overview](https://developers.facebook.com/documentation/ads-commerce/marketing-api/overview)) [Meta-extract].

**Access path for the shop's own account [Meta-extract, inferred sequence]:**

1. Create a Business-type Meta app inside the shop's Business Portfolio and add the Marketing API product.
2. Request `ads_management`, `ads_read` and `business_management`.
3. Create a **System User** in Business Settings.
4. Assign the app, ad account, Page, pixel and catalog to that System User.
5. Generate a token that "does not expire" but can still be invalidated ([Meta: Authentication](https://developers.facebook.com/documentation/ads-commerce/marketing-api/get-started/authentication)).

App Review with Full access is needed only for managing *other people's* ad accounts ([Meta: Authorization](https://developers.facebook.com/documentation/ads-commerce/marketing-api/get-started/authorization)). However, a system-user token reportedly requires the app to have at least the standard tier ([Meta: System users](https://developers.facebook.com/docs/business-management-apis/system-users/install-apps-and-generate-tokens)). On **4 May 2026** Meta renamed Standard Access to **"Limited access"** and Advanced Access to **"Full access"**, and cut the Full-access threshold to 500 API calls in 15 days ([Meta developer blog](https://developers.meta.com/blog/updates-to-ads-management-standard-access-feature/)). The default tier of a new app, and whether Business Verification or 2FA is strictly needed for a token on one's own account, were **not verified**.

**API version.** The current version is reported as **v27.0, released 4 November 2026**, with v26.0 (29 July 2026) and v25.0 still available. The repo pins v25.0. Third-party sources conflict on v24/v25 sunset dates ([GitHub issue summarising Meta's changelog](https://github.com/cesteral/mcp-open-advertising/issues/378); [app369](https://app369.com/blog/facebook-graph-api-version-expiry-dates-2027/)). v27.0 silently changes defaults:

- Reach expansion to other mobile devices becomes **on by default** for eligible Sales ad sets when `targeting_automation.individual_setting.device` is omitted.
- **Product showcase may be enabled by default** on new creatives unless `product_extensions` is opted out ([same GitHub issue](https://github.com/cesteral/mcp-open-advertising/issues/378)) [Secondary summary of Meta changelog].

The design rule that follows is the most important engineering point in this section **[Inference]**: set every field the autopilot cares about explicitly, never rely on defaults, and re-read the changelog at each version bump, roughly every six months.

**Campaign structure.** The legacy Advantage+ Shopping Campaign API is gone. Creation and updates stopped at v24.0, and the block extended to all versions by **19 May 2026** ([Social Media Today](https://www.socialmediatoday.com/news/meta-updates-marketing-api-to-align-with-latest-ad-shifts/812648/)) [Secondary quoting Meta]. Its replacement is an ordinary `OUTCOME_SALES` campaign. It counts as an "Advantage+ sales campaign" when campaign budget, Advantage+ audience and Advantage+ placements are all on, and the `advantage_state_info` field reports which levers are active ([Meta: Advantage+ campaigns](https://developers.facebook.com/documentation/ads-commerce/marketing-api/advantage-campaigns)) [Meta-extract]. The old existing-customer budget cap is not available. Meta's documented substitute is two ad sets, one targeting an existing-customer custom audience and one excluding it ([Meta dev blog, 3 Jun 2025](https://developers.facebook.com/blog/post/2025/06/03/advantage-plus-campaign-experience-for-sales-and-app/)) [Meta-extract].

The hard limits below are the constraints an executor must respect. Treat each as Meta-extract until confirmed.

| Constraint | Reported value | Source |
|---|---|---|
| Budget unit | Integer in the account currency's minor unit (AED 50 = `5000` fils) | [Meta: Budgets](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/overview/budgets.md) |
| Ad set budget edits | **4 per hour** per ad set, then blocked 1 h (error 613 / 1487632) | [Meta: Rate limiting](https://developers.facebook.com/documentation/ads-commerce/marketing-api/overview/rate-limiting) |
| Any object edit | ~1 per 30 seconds | same |
| Account `spend_cap` edits | **10 per day** (error 613 / 1487225) | [Meta: Rate limiting](https://developers.facebook.com/docs/marketing-api/overview/rate-limiting) |
| `ads_management` calls | 100,000 (Full) or 300 (Dev) + 40 × active ads, per account per hour | same |
| Ad scheduling (dayparting) | Lifetime budgets only, per API docs (a secondary source disputes this) | [Meta: Pacing](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/overview/pacing-and-scheduling); [Madgicx](https://madgicx.com/blog/meta-campaign-scheduling) |
| Min-ROAS bidding | `roas_average_floor` ×10,000 (15000 = 1.5x); purchase-optimised only | [Meta: Bid strategy](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/overview/bid-strategy) |
| AED minimum daily budget | **Not found**; read `min_daily_budget` from the account instead (unverified field) | [tryvizup](https://www.tryvizup.com/blog/meta-ads-minimum-budget-requirements-2026) |

A single shop with dozens of ads will never approach the hourly call ceilings **[Inference]**. The binding limits are the per-object edit caps and insights throttling, read from the `X-Business-Use-Case-Usage` header ([Meta: Graph API rate limits](https://developers.facebook.com/docs/graph-api/overview/rate-limiting/)).

Ad review outcomes are readable from these fields ([Meta: Ads Webhooks effective status](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ads-webhooks/effective-status)) [Meta-extract]:

- `effective_status`: DISAPPROVED, WITH_ISSUES, IN_PROCESS and others.
- `review_feedback` / `ad_review_feedback`: the rejection reasons.
- `issues_info`: populated when the status is WITH_ISSUES.

Webhooks can push these changes instead of the system polling for them.

Meta also runs a server-side **Ad Rules Engine** (`/act_{id}/adrules_library`) with PAUSE, UNPAUSE, CHANGE_BUDGET and CHANGE_CAMPAIGN_BUDGET actions on schedules or triggers ([Meta: Ad Rules Engine](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules.md); [Execution spec](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules/overview/execution-spec)) [Meta-extract]. Its value here is as a dead-man layer **[Inference]**: rules that pause a runaway ad set keep running inside Meta even if the Laravel cron or the Claude loop dies.

A newer option is Meta's official **Ads MCP server**. It reportedly opened in beta on 29 April 2026 at `mcp.facebook.com/ads`, is free in beta, has about 29 tools covering reads and writes, and lists Claude Desktop and Claude Code as clients ([Meta dev blog, 16 Jul 2026](https://developers.facebook.com/blog/post/2026/07/16/meta-ads-mcp-server/); [ppc.land](https://ppc.land/meta-opens-its-ad-system-to-claude-and-chatgpt-with-new-ai-connectors/)) [Meta-extract plus Secondary; endpoint and tool count conflict between sources]. The catch is safety. **New objects land paused, but edits to live objects, budgets included, reportedly take effect immediately, and the write permissions reportedly ship enabled by default** ([mintec](https://mintec.co/blog/meta-ads-mcp-default-write-permissions/)) [Secondary]. It also uses user OAuth rather than a headless server credential, so its suitability for unattended cron use is unverified. It is a good read and analysis channel. It should not be the write path for an autonomous loop.

## Claude proposes, PHP disposes: a planner–executor design

No published reference architecture for "an LLM controls ad spend" was found, and no case studies with measured ROAS outcomes either. The design below is assembled from documented control primitives and is labelled **[Inference]** as a whole. It fits the shop's constraints: Cloudways with SSH, the `schedule:run` cron installed on 11 October 2026, no queue worker, and CLAUDE.md's rules against new dependencies, polling loops and unauthenticated `/api/*` exposure.

**The planner is Claude, with no write credentials.** Each cycle, the shop assembles a snapshot. It combines Meta Insights at campaign, ad set and ad level for trailing windows with shop-side orders, net revenue, refunds, margin bands and stock. All the KPIs (CPA, margin-adjusted ROAS, MER) are computed in SQL, so **Claude never computes the numbers it acts on**. Claude returns a JSON action list through structured outputs or a `strict: true` tool schema ([Anthropic tool use](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview)) [Anthropic-live]. Each action has the shape `{action, object_id, from, to, reason, expected_effect, confidence}`. Strict schemas guarantee the *shape* of an argument, not its sanity. Code must also check `stop_reason` for `refusal` before acting on anything.

**The executor is deterministic PHP and the only Meta writer.** It works from an allow-list of actions: pause ad, resume ad, change ad-set or campaign daily budget, duplicate a winning ad, create a PAUSED draft. Its limits live in config, not in the prompt, and include:

- an account daily cap and a monthly cap;
- a maximum budget change of ±20% per object per 24 hours;
- a minimum-data threshold before any object is judged;
- a 72-hour cooldown per object after a change;
- a maximum number of actions per cycle;
- a kill-switch setting checked before every write;
- idempotency keys;
- a fresh read of each object from Meta before writing to it;
- a single currency-conversion function that rejects non-integer fils and any budget above a fixed AED ceiling.

It never writes `spend_cap`. The account spending limit is set by hand in Ads Manager, so it remains an independent stop the AI cannot move. One source recalls that `spend_cap = 0` means "no cap", which, if true, would be a dangerous foot-gun. This is unverified. Any API, parse or refusal error results in no action plus an alert, never a blind retry of a write.

**Approvals are tiered.** Low-risk moves execute automatically: pausing a clear loser, or budget changes of 10% or less. Budget increases above a threshold, new campaigns, anything that starts spend, and **all new ad copy** wait for the owner. Approval arrives through a signed, expiring one-time link, and the default on timeout is to do nothing. Anthropic's Managed Agents product offers the same pattern natively, with per-tool policies of `always_allow`, `always_ask` and `auto`, platform-enforced dollar session budgets, vault-held credentials and cron "scheduled deployments" ([Managed Agents overview](https://platform.claude.com/docs/en/managed-agents/overview)) [Anthropic-live]. It is in beta, though, and would put the Meta token in Anthropic's vault. For a shop that already has SSH and cron, the cheaper and more controllable path is an artisan command calling the Messages API **[Inference]**.

**Cadence follows the data, not the clock.** Meta credits a purchase up to 7 days after the click, so "today's" ROAS is always understated. The research team's recommended rhythm **[Inference]** has three parts:

- An hourly deterministic metrics pull, plus an optional cheap Haiku anomaly check.
- Hard stops handled by code with no LLM involved: a spend spike, a CPA blow-out, a product going out of stock.
- The Claude planner one to four times a day, with budget decisions at most once a day per object.

"24-hour monitoring" therefore means continuous *watching* by code and Meta's rules engine, with *deciding* kept deliberately slow.

**Prompt injection is a real attack surface.** Ad comments, product reviews and ad names are untrusted text. They should never enter a planning context that has write tools available. If they are analysed at all, a separate read-only call with no tools should reduce them to structured counts. Anthropic describes mid-conversation `system` messages as the injection-safe operator channel ([Anthropic docs](https://platform.claude.com/docs/en/managed-agents/overview)) [Anthropic-live].

**State and audit live in append-only tables** **[Inference]**. These hold metrics snapshots, proposed actions, approvals, and executed actions with before and after values and Meta's response, along with model id, prompt hash and token usage. Meta's Platform Terms allow audits and require deletion of Platform Data once it is no longer needed ([Meta: Best practices](https://developers.facebook.com/documentation/ads-commerce/marketing-api/best-practices); [Meta dev blog 2020](https://developers.facebook.com/blog/post/2020/07/01/platform-terms-developer-policies)) [Meta-extract]. A retention period must be set. Two security points carry over from the repo's own rules: no ads endpoint may live under unauthenticated `/api/*`, and the System User token and Anthropic key belong in `.env`, never in prompts, logs or front-end code.

## Decision rules: consolidate, wait, and judge on shop money

The decision logic is where folklore and fact separate most sharply.

**Learning phase.** Meta's rule, relayed by secondary sources, is that an ad set leaves learning after "about 50 results in the week after the ad set's last significant edit" ([Wordstream](https://wordstream.com/blog/facebook-learning-phase)) [Meta-via-secondary]. Adding an ad counts as a significant edit, and so does a "large" budget change. **Meta has never published a percentage for "large"**, so the ubiquitous "20%" is practitioner folklore ([Jon Loomer](https://www.jonloomer.com/updates-to-meta-ads-budgeting/)).

**Consolidation.** An October 2026 Meta brief reportedly said that ad sets under about 50 weekly conversions pay **about 28% more per purchase**. It gave a sizing rule: maximum ad sets = weekly budget ÷ (CPA × 50) ([ppc.land, 25 Oct 2026](https://ppc.land/meta-says-ad-sets-under-50-weekly-conversions-cost-28-more-per-purchase/)) [Meta-via-secondary; no sample size or controls disclosed]. The arithmetic for a small shop is sobering **[Inference]**. At an assumed AED 150 CPA, one ad set needs about AED 7,500 a week (about AED 1,070 a day) just to exit learning. Below that spend the account will be "learning limited" whatever the agent does. The rational structure is therefore **one Advantage+ sales campaign with one prospecting ad set**, plus at most an existing-customer ad set. The agent's goal is to minimise resets: batch creative additions weekly, and never change budget and creative on the same day.

**Creative diversity.** Meta's Andromeda retrieval system narrows "tens of millions of ad candidates into a few thousand" per request ([Meta Engineering, 2 Dec 2024](https://engineering.fb.com/2024/12/02/production-engineering/meta-andromeda-advantage-automation-next-gen-personalized-ads-retrieval-engine/)) [Meta]. Meta's advertiser messaging since 2025 calls creative diversification "the best lever to find the most relevant audiences" ([Jon Loomer](https://www.jonloomer.com/meta-andromeda/)) [Meta-via-secondary]. Recommended ad counts per ad set conflict, from "no more than five or six" to 15–30 ([AdManage](https://admanage.ai/blog/how-many-facebook-ads-should-you-run-at-once); [Search Engine Land](https://searchengineland.com/meta-ads-creative-diversity-485751)). The working compromise **[Inference]** is a pool of about 6–15 genuinely distinct concepts, tagged by persona, pain point, format and language (EN/AR). Claude prefers a new ad that fills an empty cell over a near-duplicate of an existing winner.

**Kill and keep thresholds.** The common practitioner kill rule pauses an ad at spend of 2x target CPA with zero purchases ([Coinis](https://coinis.com/blog/when-to-kill-meta-ads-decision-framework)) [Practitioner]. The statistics behind it are worth stating **[Inference]**. If an ad's true CPA equals target, the Poisson probability of zero purchases after spending k×CPA is e^(−k). That is **about 13.5% at 2x and about 5% at 3x**, so the 2x rule wrongly kills about one good ad in seven. A better rule gives each ad a Gamma–Poisson posterior on CPA and acts only when P(CPA < break-even) is ≥0.8 (keep or scale) or ≤0.2 (kill). Four further rules complete the set:

- Never judge on the current day or the last 2 days.
- Use trailing 7-day windows that end at least 3 days ago.
- Scale vertically in steps of 20% or less, at least 72 hours apart, and only when settled CPA beats target by 15% or more.
- Cut faster than you scale.

**Fatigue.** Meta's built-in creative-fatigue label only covers ad sets with a single creative ([Jon Loomer](https://www.jonloomer.com/creative-fatigue-meta-ads/)) [Meta-via-secondary], so the agent needs its own trigger **[Inference]**. Act when at least two of these three signals appear, each measured against the ad's own history:

- the ad's 7-day link CTR is 30% or more below its own first 14 days;
- CPM or CPA is rising;
- 7-day frequency is 2.5 or higher.

**The economic anchor is break-even, not Meta's ROAS** **[Inference]**. Contribution per order = AOV ex-VAT × gross margin − shipping − weighted payment fee − expected refund cost. Break-even CPA equals that contribution, and target CPA sits below it by the owner's desired margin. Payment-fee data from the web is poor. Stripe UAE adds 1% for international cards ([Stripe](https://support.stripe.com/questions/how-much-does-stripe-cost-in-the-uae)). Tabby estimates range from 2.79% to 8% across sources ([paymentproviders.io](https://paymentproviders.io/compare/tabby-vs-stripe); [checkoutflow](https://checkoutflow.pages.dev/ae-en/best-payment-gateway-ecommerce/tabby-guide-sellers)). No Tamara figure was found. **Use the shop's own contract rates and payment-method mix.**

The primary health metric is **MER** (total shop revenue ÷ total ad spend), plus new-customer orders, with platform ROAS used only to rank ads within Meta. Formal incrementality testing is probably out of reach. Conversion Lift reportedly needs USD 5,000+ of spend and a Meta rep ([Madgicx](https://madgicx.com/blog/meta-incrementality-testing)). GeoLift wants about 20 comparable geographic units ([GeoLift docs](https://facebookincubator.github.io/GeoLift/docs/GettingStarted/Walkthrough)), which a UAE-only shop lacks. The practical substitute is a pre-registered budget-step test, read on MER.

**Seasonality instead of dayparting.** No controlled evidence was found that dayparting helps on Meta, and it forces lifetime budgets, so do not daypart **[Inference]**. Instead keep a seasonality calendar. Ramadan 2027 is expected around 8 February and Eid al-Fitr around 9 March, subject to moon sighting ([Khaleej Times](https://www.khaleejtimes.com/uae/ramadan-eid-al-fitr-eid-al-adha-2027-likely-dates)). UAE CPMs rise during Dubai Shopping Festival, Ramadan and Eid, notably for beauty ([SuperAds](https://www.superads.ai/facebook-ads-costs/cpm-cost-per-mille/united-arab-emirates)). The vendor's UAE average CPM is about **USD 15.8**, and no credible UAE beauty CPA or CVR benchmark exists. The shop's own data should replace priors after roughly 30 purchases.

## Costs are small; the expensive failures are policy and units

| Cost item | Estimate | Basis |
|---|---|---|
| Claude planner, hourly, Sonnet 5.5 ($2 in / $10 out per MTok) | ~$0.10/cycle, **~$2.40/day, ~$72/month** | [Anthropic pricing](https://platform.claude.com/docs/en/about-claude/pricing) [Anthropic-live]; token volumes are an **[Inference]** (20k cached prefix, 10k fresh, 3k output) |
| Same on Opus 5.5 ($4 / $20) | ~$5/day | same |
| Haiku 5.5 anomaly watcher every 15 min ($0.10 / $0.50) | ~$0.50/day | same |
| Daily Opus strategy review via Batch API (50% off) | ~$0.30/day | same |
| Managed Agents runtime (if used) | +$0.08 per running session-hour | same |
| Meta Ads MCP server | Free during beta (reported) | [adadvisor](https://adadvisor.ai/blog/mcp-facebook-com-ads-official-meta-setup) [Secondary] |
| Commercial alternatives | Bïrch ~$49–249/mo; Madgicx ~$45–329+/mo | [adlibrary](https://adlibrary.com/posts/madgicx-vs-revealbot); [get-ryze](https://www.get-ryze.ai/blog/madgicx-pricing-2026) [Secondary] |
| VAT on Meta ad spend | 5% charged without a TRN; reverse charge with one | [Farahat & Co](https://farahatco.com/blog/vat-on-facebook-ads-in-uae/) [Secondary] |

Thinking tokens bill as output, so measure the `usage` field in the first week before trusting any of these numbers. Hourly Claude planning is also more frequent than the data justifies. A once-to-four-times-daily Sonnet planner plus a code-only hourly watcher would cost well under the hourly figure.

**Policy is the larger risk, and AI-written copy is its sharpest edge.** Meta's rules say ads "must not contain content that asserts or implies personal attributes", and health conditions are among them, so "Do you have acne?" or "your dark spots" is non-compliant ([Meta: personal attributes](https://transparency.meta.com/policies/ad-standards/objectionable-content/privacy-violations-personal-attributes/)) [Meta-extract]. Health and beauty ads may not create negative self-perception or zoom in on conditions. Before/after imagery may not show idealised results, and sources conflict on whether cosmetics before/after is banned outright or allowed at 18+ ([Meta Business Help](https://www.facebook.com/business/help/2489235377779939); [Meta: Health and Wellness](https://transparency.meta.com/policies/ad-standards/restricted-goods-services/health-wellness)) [Meta-extract]. A pre-flight lint in code catches most rejections **[Inference]**. It should block "you/your" next to a condition word; "cure", "treat", "heal", "eliminate", "guaranteed" and "permanent"; and before/after language. Behind the lint, the owner approves every new creative. The agent must stop and alert on DISAPPROVED and never auto-resubmit, because rejections accumulate on an account's quality record and Meta publishes no strike thresholds.

**Account integrity failures are hard to reverse.** A July 2026 Meta developer-community thread reports a Business Portfolio restricted for automation that "mimics human activity", with the appeal denied and no cause given ([Meta community thread](https://developers.facebook.com/community/threads/1438234438064193/)) [Anecdotal, single case]. That argues for official API calls at low write frequency and against any browser or UI automation. Opening new accounts after a ban falls under the severe "circumventing systems" category ([stubgroup](https://stubgroup.com/blog/meta-circumventing-systems-suspension-what-it-means-and-how-to-address-it/)), so the agent must never create ad accounts or Business portfolios. A failed payment pauses all ads until the balance is paid ([Meta Business Help](https://facebook.com/business/help/268196136699959)), and the daily reconciliation should alert on it.

**UAE law adds four distinct exposures** [Secondary law-firm and press sources; **not legal advice, needs a UAE lawyer**].

1. **Media law.** Advertising falls under Federal Decree-Law 55/2023, in force since 1 December 2023, and the Media Council's standards. Penalties are set by Cabinet Resolution 42/2025, with ranges quoted from AED 5,000 to AED 1,000,000 ([Tamimi](https://www.tamimi.com/law-update-articles/media-law-and-advertising-standards-in-the-uae-key-rules-and-restrictions/); [Hadef Partners](https://hadefpartners.com/news-insights/insights/key-regulatory-update-uae-media-law-violations-and-administrative-penalties-issued/)).
2. **Health claims.** Health and medical-product ads need prior approval, now from the **Emirates Drug Establishment** ([MOHAP](https://mohap.gov.ae/en/mte); [WAM](https://www.wam.ae/en/article/c3bga2x-emirates-drug-establishment-mandates-approval-for)). Therapeutic claims can push a skincare product into drug regulation. The Media Council took legal action in September 2025 over unverified therapeutic claims on social media ([The National](https://www.thenationalnews.com/news/uae/2025/09/11/uae-launches-legal-action-after-misleading-medical-claims-on-social-media-account)).
3. **Discounts.** Advertised discounts need authority consent ([Pinsent Masons](https://www.pinsentmasons.com/out-law/analysis/uae-consumer-protection-regime-revamped)), via Dubai DET for Dubai-licensed businesses ([Dubai DET](https://dubaidet.gov.ae/en/our-services/for-consumers-and-students/apply-retail-promotion-certificate)). So Claude must **never generate "% off" or strike-through prices without an approved promotion on file**.
4. **Arabic and permits.** Advertising standards have required Modern Standard Arabic or Emirati dialect ([Tamimi](https://www.tamimi.com/law-update/february-4/articles/advertising-standards-for-the-uae/)). How that applies to English social ads is unclear. The 2025 Advertiser Permit appears to exempt a business promoting its own products from its own account, but sources conflict ([Gulf News](https://gulfnews.com/living-in-uae/ask-us/what-is-the-uae-advertiser-permit-for-social-media-1.500218180)).

**Data protection.** The UAE PDPL (Decree-Law 45/2021) makes consent the default basis and restricts cross-border transfers, but its **Executive Regulations still appear unissued as of late 2026** ([businessdubai.ae](https://www.businessdubai.ae/blogs/uae-pdpl-data-protection); [official text](https://uaelegislation.gov.ae/en/legislations/1972/download)). Sending hashed customer data to Meta is safest behind a consent banner that gates both the pixel and the CAPI `user_data`, built now as a switch **[Inference]**.

**Anthropic's own policy.** The 2026 Usage Policy update rewrote its high-risk use-case section ([Anthropic](https://anthropic.com/news/2026-usage-policy-update)). Managing one's own ad spend does not obviously fall in a high-risk consumer-facing category **[Inference]**, but read the current [AUP](https://www.anthropic.com/aup) before launch.

## A five-phase build, with writes switched on last

Each phase below is an **[Inference]** from the evidence above. It respects CLAUDE.md's rules: packages built with `kbb:package`, routes in a lane's own file, no new dependency, and the payment freeze untouched.

| Phase | What gets built | Exit test before the next phase |
|---|---|---|
| **0. Decide and verify (1 week)** | Owner confirms margins, contract payment fees, COD/`onhold` policy, consent stance, trade licence emirate, monthly ad ceiling. Someone with Meta access checks every item in the verify table below on the live pages. Set the account spending limit by hand in Ads Manager. | Written break-even CPA and verified Meta facts |
| **1. Measurement hardening (1–2 weeks)** | Custom refund/cancel CAPI events off order status (outside `app/Services/Payments/`); cron-driven retry for failed sends (≤47 h Purchase, ≤7 d anything); hashed em/ph for guests; consent gate switch; daily reconciliation table (Meta-claimed vs shop-matched vs net); Arabic override feed; review the Graph version pin | EMQ readable and stable; reconciliation within an agreed gap for 2 weeks |
| **2. Read-only observer (2 weeks)** | Meta app + System User token in `.env`; insights pull into append-only snapshot tables; KPIs in SQL; owner dashboard in the admin (own capability, fails closed); Meta Ad Rules as dead-man pauses | Numbers match Ads Manager and the orders table |
| **3. Shadow planner (2–4 weeks)** | Claude planner on cron producing proposals only; executor validates and logs "would have done"; copy lint; backtest proposals against outcomes and a "do nothing" baseline | Proposals beat or match baseline; zero limit violations |
| **4. Gated autopilot** | Executor writes enabled for pause and small budget moves only; everything else via signed approval links; kill switch; alerts on rejection, payment failure, `account_status`, spend deviation. Widen the auto-tier only after weeks of clean logs | Monthly MER and new-customer orders vs pre-launch weeks |

Before any value is hard-coded, verify each of the following on Meta's live pages (blocked in this research) or with the named party:

| Must still be verified | Where |
|---|---|
| Current API version, v25/v26 sunset dates, v27 default changes and opt-out field names | Meta Marketing API changelog and versions pages |
| Rate limits: 4/hour budget cap (and whether it covers campaign budgets), 30 s edit limit, 10/day `spend_cap`, insights throttle fields | Meta rate-limiting page |
| `spend_cap = 0` semantics; `min_daily_budget`; `account_status`/`disable_reason` codes; whether AED currency can change | AdAccount reference |
| Whether a system-user token on one's own account needs Business Verification or 2FA; default tier of a new app | Meta Authorization / System Users pages |
| Advantage+ audience field behaviour (age floor, exclusions); `advantage_state_info` enums; status of any old ASC campaigns | Advantage+ campaigns page |
| Dayparting with daily budgets; learning-phase significant-edit list | Meta Help Center |
| Attribution default, engage-through definition, incremental attribution availability for this account | Ads Manager and Meta Help Center |
| CAPI batch size, event_time limit, 48 h dedup wording; AEM tab absence; `ar_AE` override code | CAPI docs; the shop's Events Manager |
| Ads MCP endpoint, tool list, write gating, headless use | Meta Ads MCP docs |
| Before/after and personal-attribute rules for ordinary skincare | Meta Transparency Center (English) |
| Advertiser Permit exemption, EDE approval scope for cosmetics, DET discount permit, Arabic requirement, PDPL consent stance | UAE lawyer; Media Council; EDE; DET |
| Real Stripe, Tabby and Tamara fee rates | The shop's own contracts and invoices |
| Current Anthropic AUP high-risk clauses; real token usage per cycle | anthropic.com/aup; the API `usage` field |

## Conclusion

The research shifts the question from "can Claude run Meta ads?" to "what should be left for Claude to decide?" Meta's 2026 machinery (Advantage+ sales, Andromeda, consolidation guidance) has absorbed most of the decisions a human media buyer used to make: audiences, placements and bids. For a shop at this spend, that leaves a narrow but valuable band. That band covers daily budget level against true margin, which products and messages get creative, when an ad is genuinely fatigued, and what the refund-adjusted truth is. Those are judgement calls over data that only the shop holds, which is exactly where a language model plus SQL beats both Meta's optimiser and a commercial rules tool. The most counter-intuitive finding is that **doing less, more slowly, is the profitable design**. Every edit risks a learning reset, the data settles over 3–7 days, and the 2x kill rule is wrong one time in seven.

The second implication is about the order of work. The first money spent should go on measurement, not on the agent. Refund events, guest match quality, a consent gate and a daily reconciliation against the orders table decide whether any autopilot optimises toward profit or toward Meta's modelled ceiling. Built in that order, with writes switched on last and the spending limit outside the AI's reach, the worst plausible failure is a paused campaign and an alert, not an overspend or a banned account. Every Meta-specific number in this plan was read second-hand, so the verify table is the first deliverable, not an appendix.
