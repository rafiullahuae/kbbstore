# Meta Marketing API as the control surface for an automated ads system (as of 20 Nov 2026)

**Method note (read first):** This session's network proxy refused direct fetches of `developers.facebook.com`, `developers.meta.com`, `ppc.land`, `socialmediatoday.com`, `jonloomer.com` and every other third-party host except GitHub. Every finding below comes from (a) web-search result extracts that quote Meta's own docs pages (cited by their Meta URL), (b) a GitHub issue that summarises the v27.0 changelog, or (c) secondary sources, which are labelled as such. Nothing here was read in full from a Meta page. Before code depends on a value, re-check it on the cited Meta page. To fetch Meta docs directly, `developers.facebook.com` has to be allowed in the session's network settings. Meta now serves docs as Markdown at `developers.facebook.com/documentation/ads-commerce/marketing-api/...md`, which is useful for a later lane.

## 1. API version, deprecation cadence, object model

### Takeaway
The current version is **v27.0 (released 4 Nov 2026)**. v26.0 (29 Jul 2026) and v25.0 (18 Feb 2026) are still available. Meta ships roughly two to three versions a year and keeps the previous Marketing API version for at least 90 days after a new one. Pin a version explicitly and plan for an upgrade about every 6 months. The object model is: Business → Ad Account → Campaign → Ad Set → Ad → Ad Creative, plus Ad Images/Videos, Custom Audiences and Catalog/Product Sets.

### Cited Findings
- Graph/Marketing API **v27.0 released 2026-11-04**. Both version tables list v26.0 and v27.0 as "Available Until: TBD". The only dated Marketing API sunset cited is **v24.0 on 2026-10-06**. — [GitHub issue summarising Meta changelog](https://github.com/cesteral/mcp-open-advertising/issues/378) (it links [MAPI v27 changelog](https://developers.facebook.com/documentation/ads-commerce/marketing-api/marketing-api-changelog/version27.0))
- v26.0 launched **29 Jul 2026**, and v25.0 launched **18 Feb 2026**. The Graph API table gives v25.0 an end date of 29 Jul 2028, while its Marketing API end date is TBD. — [GitHub issue](https://github.com/cesteral/mcp-open-advertising/issues/378); [data365 (secondary)](https://data365.co/blog/meta-graph-api)
- Conflict: one third-party source gives v24.0's Marketing API sunset as 6 Oct 2026, and another gives 10 Nov 2026. A third-party source gives v25.0's Marketing API last day as 23 Feb 2027 (unverified). — [app369 (secondary)](https://app369.com/blog/facebook-graph-api-version-expiry-dates-2027/); [useadstudio tracker (secondary)](https://useadstudio.com/tools/ad-api-version-tracker)
- Changes in v27.0 that matter for a sales advertiser:
  - **Reach expansion across other mobile devices is on by default.** If `targeting.targeting_automation.individual_setting.device` is omitted, it becomes `1` on eligible Sales ad sets. This applies to v27 now and to all versions about 90 days after launch (around early Feb 2027, TBC).
  - **Product showcase may be enabled by default** on ad/creative create when `product_extensions` is omitted. Opt out with `enroll_status = OPT_OUT`.
  - The Catalog Batch API (`POST /{catalog-id}/batch`) is deprecated from v27.
  - `lookalike_spec.country` / `location_spec` are rejected on custom audience create.
  - `meta_moment_maker_spec` is removed.
  - There are no changes to insights fields or breakdowns.
  - Source: [GitHub issue](https://github.com/cesteral/mcp-open-advertising/issues/378); also [ppc.land](https://ppc.land/metas-api-v27-may-add-catalog-products-to-new-ads-by-default/) and [relevantaudience](https://www.relevantaudience.com/meta/meta-marketing-api-v27-product-showcase-device-expansion-defaults/) (secondary, not opened)
- v26.0 removed Instagram Explore Feed and Messenger Stories as selectable placements. — search extract citing [ppc.land (secondary)](https://ppc.land/meta-deprecates-legacy-campaign-apis-for-advantage-structure/)
- The Marketing API is for "creating, updating, and deleting ad campaigns, ad sets, and ads" programmatically. — [Meta Marketing API overview](https://developers.facebook.com/documentation/ads-commerce/marketing-api/overview)

### Inferences
- A defaults-changing release such as v27 can change delivery silently (device expansion, product showcase). An autopilot should set every field it cares about explicitly, never rely on defaults, and re-read the changelog at each version bump.
- For a Laravel client, pin `v27.0` (or `v26.0`) in a config constant and schedule a version review every 6 months.

### Gaps
- I could not open Meta's Versions page to confirm the v24, v25 and v26 Marketing API sunset dates. Third-party sources conflict.
- I did not verify the exact current object-model reference pages (catalog `product_sets`, `adimages`, `advideos` endpoints). They are long-standing, but this session did not re-verify them.

## 2. Campaign / ad set / ad CRUD, objectives, Advantage+ sales campaigns

### Takeaway
A program can create, edit, pause and delete campaigns, ad sets and ads with ODAX objectives (`OUTCOME_SALES`, etc.). The legacy **Advantage+ Shopping Campaign (ASC) API is gone**:
- From v24.0, ASC campaigns can no longer be created, duplicated or updated.
- From **19 May 2026**, that block applies on all versions.

The replacement is an ordinary `OUTCOME_SALES` campaign that becomes an "Advantage+ sales campaign" when three levers are all on: campaign budget, Advantage+ audience and Advantage+ placements. The `advantage_state_info` field reports this.

### Cited Findings
- `advantage_state_info` returns `advantage_state` (ADVANTAGE_PLUS_SALES / ADVANTAGE_PLUS_LEADS / … / DISABLED) together with `advantage_budget_state`, `advantage_audience_state` and `advantage_placement_state`. If any lever is DISABLED, the advantage_state is DISABLED. — [Meta: Advantage+ Campaign Experience for Sales, App, and Leads](https://developers.facebook.com/documentation/ads-commerce/marketing-api/advantage-campaigns); [Meta dev blog 3 Jun 2025](https://developers.facebook.com/blog/post/2025/06/03/advantage-plus-campaign-experience-for-sales-and-app/)
- The `advantage_state_info` flag arrived in v23.0. — search extract citing [ppc.land (secondary)](https://ppc.land/meta-launches-unified-api-structure-for-advantage-campaigns/)
- The budget lever needs a campaign-level budget with LOWEST_COST_WITHOUT_CAP, COST_CAP, LOWEST_COST_WITH_BID_CAP or LOWEST_COST_WITH_MIN_ROAS. — secondary extract, [ppc.land](https://ppc.land/meta-launches-unified-api-structure-for-advantage-campaigns/)
- Advantage+ placements is the default in the API, so no action is needed to opt in. — secondary extract (same search)
- Timeline:
  - **v24.0 (8 Oct 2025):** stopped creation, duplication and updates of ASC and Advantage+ app campaigns.
  - **v25.0 (18 Feb 2026):** made the unified structure mandatory. Meta's quoted wording: "can no longer be created or updated using the Marketing API, this will extend to all MAPI versions after 90 days (by May 19th 2026)".
  - Sources: [Social Media Today](https://www.socialmediatoday.com/news/meta-updates-marketing-api-to-align-with-latest-ad-shifts/812648/) and [ppc.land](https://ppc.land/meta-deprecates-legacy-campaign-apis-for-advantage-structure/) (secondary; search extracts only)
- Conflict: one source says legacy campaigns "remain editable after v25 but cannot be duplicated", while the Meta quote above says they cannot be updated. Another secondary source says remaining legacy campaigns are paused at v26.0 (unverified). — same sources
- The "existing customer budget cap" (Existing Customer Budget Percentage) is not available in the new structure. The suggested rebuild is two ad sets with spending limits. Campaigns that used it were not auto-migrated, and campaigns with more than 150 ads cannot be migrated via the API. — [Sprinklr help (secondary)](https://www.sprinklr.com/help/articles/facebook-advanced-capabilities/advantage-sales-app-promotion-and-leads-campaigns/675aac69b531144edef0d50e); search extract
- A campaign with more than 70 ad sets on campaign budget cannot turn campaign budget off. — secondary search extract (same search as above)

### Inferences
- For kbeautybliss.com, the automatable "Advantage+ sales" path is: create an `OUTCOME_SALES` campaign with a campaign budget, Advantage+ audience on and placements left automatic. Then confirm `advantage_state_info.advantage_state == ADVANTAGE_PLUS_SALES` after creation, and alert if it is not.
- The system can still build manual (non-Advantage+) Sales campaigns. It just has to switch one lever off.

### Gaps
- I could not see the enum values for `advantage_audience_state` / `advantage_placement_state`, or exactly which ad set targeting fields are still honoured (age floor, location, exclusions) under Advantage+ audience. This needs the Meta page itself.
- I could not confirm whether pre-existing ASC campaigns can still be paused or edited in late 2026.

## 3. Budgets and bidding

### Takeaway
- **Budget level:** set it on the campaign (Advantage campaign budget, formerly CBO) or on the ad set (ABO), never both.
- **Budget type:** daily or lifetime, in the ad account currency's minor unit (AED → fils).
- **Bid strategies:** LOWEST_COST_WITHOUT_CAP, COST_CAP, LOWEST_COST_WITH_BID_CAP and LOWEST_COST_WITH_MIN_ROAS (purchase-optimised only, `roas_average_floor` ×10,000).
- **Ad scheduling (dayparting, `adset_schedule`):** per Meta's API docs it requires a lifetime budget.
- **Budget changes:** an ad set's budget can change at most **4 times per hour**.

### Cited Findings
- Budgets and bids are set "at ad account currencies minimum denomination level, such as cents for USD". — [Meta: Budgets](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/overview/budgets.md)
- Advantage campaign budget guide. — [Meta](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/guides/advantage-campaign-budget). Ad set budget sharing guide. — [Meta](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/guides/adset-budget-sharing)
- "Ad scheduling can only be used with lifetime budgets" (restriction on the API pacing and scheduling page). — [Meta: Pacing and Scheduling](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/overview/pacing-and-scheduling). This conflicts with a secondary claim that the Help Center allows daily budgets. — [Madgicx (secondary)](https://madgicx.com/blog/meta-campaign-scheduling)
- `adset_schedule` is a list of DayPart entries. — [Meta: Ad Set reference](https://developers.facebook.com/documentation/ads-commerce/marketing-api/reference/ad-campaign)
- "Budget scheduling" is a separate feature that schedules daily-budget increases (up to 50 per ad set), after which the budget reverts. — [Jon Loomer (secondary)](https://www.jonloomer.com/updates-to-meta-ads-budgeting/)
- Bid strategies:
  - LOWEST_COST_WITH_MIN_ROAS requires `roas_average_floor`, scaled ×10,000 (15000 = 1.5x), valid range 100 to 10,000,000. COST_CAP, MIN_ROAS and BID_CAP are "manual bidding". TARGET_COST was deprecated in v9.
  - For cost cap or min ROAS, Meta advises scheduling the ad set for at least 3 full days.
  - Source: [Meta: Bid Strategy](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/overview/bid-strategy)
- MIN_ROAS applies only to ad sets optimising for PURCHASE. — [Zernio integration docs (secondary)](https://docs.zernio.com/ad-campaigns/list-bid-strategies)
- **An ad set's budget can change only 4 times per hour.** Beyond that, changes are blocked for an hour (error 613, subcode 1487632). The default edit limit is one edit per object every 30 seconds. — [Meta: Marketing API Rate Limiting](https://developers.facebook.com/documentation/ads-commerce/marketing-api/overview/rate-limiting)
- Learning phase:
  - Triggered by new ads or ad sets and by "significant edits".
  - Exits after about 50 optimisation events in the 7 days after the last significant edit. Ad sets under 50 weekly conversions reportedly pay about 28% more per purchase.
  - A small budget nudge (100→101) is unlikely to reset learning, but a 10x change may.
  - Sources: [Jon Loomer quoting Meta Help (secondary)](https://www.jonloomer.com/facebook-ads-edits-learning-phase/); [ppc.land (secondary)](https://ppc.land/meta-says-ad-sets-under-50-weekly-conversions-cost-28-more-per-purchase/)
- AED minimums: no official AED floor was found. Secondary sources claim USD $1/day (engagement) and $5/day (conversions), which is about AED 3.67 / 18.4 at the peg. A UAE agency's "200 AED minimum" is contradicted on its own page. — [tryvizup (secondary)](https://www.tryvizup.com/blog/meta-ads-minimum-budget-requirements-2026); [fueler (secondary)](https://fueler.io/blog/best-social-media-advertising-platforms-for-uae-businesses-in-2026)

### Inferences
- An autopilot should limit itself to at most one budget change per ad set or campaign per day, in steps of no more than about 20%, well inside the hard 4-per-hour cap, to avoid resetting learning.
- At 50 purchases a week per ad set, a small shop should run few ad sets (one Advantage+ sales campaign) rather than many.
- Read the account's minimum budget from the API instead of hard-coding one. Ad accounts expose `min_daily_budget`, but this was not verified in this session.

### Gaps
- No verified AED minimum daily budget. Whether daily-budget dayparting is now allowed is unresolved because sources conflict.
- I did not verify whether the 4-per-hour limit also applies to campaign budgets.

## 4. Insights API

### Takeaway
The Insights API (`/{object}/insights`, sync or async via `report_run`) returns spend, impressions, reach, frequency, CPM, CTR, actions (purchase), action_values (purchase value), `purchase_roas` and cost per action, with breakdowns.

**Since 12 Jan 2026 the 7-day-view and 28-day-view attribution windows are gone from `action_attribution_windows`.** Frequency breakdowns are limited to about 6 months of history. Async jobs can run up to about an hour, and large jobs must be split by date.

### Cited Findings
- Meta: "On January 12, 2026, we will deprecate certain attribution windows available today with the Ads Insights API's action_attribution_windows parameter" (7d_view, 28d_view). — [Meta dev blog, 16 Oct 2025](https://developers.facebook.com/blog/post/2025/10/16/ads-insights-api-metric-availability-updates); confirmed removed by [Airbyte connector docs](https://docs.airbyte.com/integrations/sources/facebook-marketing)
- Retention restrictions: breakdowns that include frequency keep about 6 months of history. — [ppc.land (secondary)](https://ppc.land/meta-restricts-attribution-windows-and-data-retention-in-ads-insights-api/)
- The default attribution reported by guides is 7d_click + 1d_view. — [adadvisor (secondary)](https://adadvisor.ai/blog/meta-ads-insights-api-reporting); [enrichlabs (secondary)](https://www.enrichlabs.ai/blog/meta-ads-attribution-settings-best-practices)
- Async jobs reportedly run for up to 3,600 s. Queue waits can exceed an hour under load, and a "Report too large" error means the job should be split by date. — [get-ryze (secondary)](https://www.get-ryze.ai/blog/meta-graph-api-timeout-limit-for-complex-ads-and-insights-requests)
- Meta's insights limits and best-practices page exists at [Meta: Insights Limits and Best Practices](https://developers.facebook.com/documentation/ads-commerce/marketing-api/insights/best-practices) (not opened).
- v27.0 lists no changes to insights fields or breakdowns. — [GitHub issue](https://github.com/cesteral/mcp-open-advertising/issues/378)

### Inferences
- Pull daily insights at the campaign, ad set and ad level for `today` and the last 3 days, and re-pull the trailing days because conversions are attributed late. Base decisions on 3–7-day windows, not intraday numbers.
- Reconcile Meta's purchases and value against the shop's own order table, because attributed conversions are modelled.

### Gaps
- Not verified from Meta: the exact data freshness (commonly said to be roughly 15–30 min for delivery metrics and longer for conversions), the full breakdown list, and the current default attribution setting. Treat these as unknowns.

## 5. Rate limits

### Takeaway
Business Use Case (BUC) limits apply per ad account per hour.

| Use case | Formula (per ad account per hour) |
|---|---|
| `ads_management` | (100,000 on Full access, or 300 on the Dev tier) + 40 × active ads |
| `ads_insights` | 190,000 + 400 × active ads − 0.001 × user errors (exact tier terms unverified) |

Read the `X-Business-Use-Case-Usage` header (call_count, total_cputime, total_time and estimated_time_to_regain_access, all as percentages) and `x-fb-ads-insights-throttle`. Other per-object limits: an ad set budget can change 4 times per hour, and any object can be edited once per 30 s.

### Cited Findings
- `ads_management` formula: "(100000 if your app is in the Marketing API Full access or 300 if your app is in the Dev tier) + 40 * Num of Active ads", per ad account per hour. — [Meta: Marketing API Rate Limiting](https://developers.facebook.com/documentation/ads-commerce/marketing-api/overview/rate-limiting.md)
- `ads_insights` formula: 190,000 + 400 × active ads − 0.001 × user errors. — search extract of [Meta rate-limiting](https://developers.facebook.com/docs/marketing-api/overview/rate-limiting) (tier qualifiers not seen)
- The `X-Business-Use-Case-Usage` header carries call_count, total_cputime, total_time and estimated_time_to_regain_access. — [Meta: Graph API Rate Limits](https://developers.facebook.com/docs/graph-api/overview/rate-limiting/)
- Third-party "point quota" models (for example 60 vs 9,000 points) do not match Meta's current formula. — [get-ryze (secondary)](https://www.get-ryze.ai/blog/meta-marketing-api-free-tier-limitations-and-quotas)

### Inferences
- A single shop with dozens of ads will never come near 100k calls an hour. The binding constraints are the per-object limits (budget 4/h, edits 30 s) and insights throttling, so back off on the header percentages and on error codes 17, 613 and 80004.

### Gaps
- I did not see the full `x-fb-ads-insights-throttle` field definitions (app_id_util_pct, acc_id_util_pct, ads_api_access_tier) on Meta's page this session.
- Whether Limited access gets the 100k figure or a lower one is unclear. The quoted formula names only Full and Dev.

## 6. Access: app, permissions, tiers, system users, verification, tokens

### Takeaway
The access path for kbeautybliss.com's own ad account:
1. Create a Meta app (Business type) inside the shop's Business Portfolio and add the Marketing API product.
2. Request the `ads_management`, `ads_read` and `business_management` permissions.
3. Create a **System User** in Business Settings.
4. Assign the app and the ad account (plus the Page, Pixel and Catalog) to the system user.
5. Generate a **non-expiring system-user token**. A 60-day expiring option also exists.

**App Review and Advanced Access are needed only when the app manages other people's ad accounts.** The tiers were renamed on **4 May 2026**:

| Old name | New name |
|---|---|
| "Ads Management Standard Access" | "Marketing API Access Tier" |
| Standard Access | **Limited access** |
| Advanced Access | **Full access** |

Full access now requires 500 Marketing API calls in 15 days, down from 1,500.

### Cited Findings
- Meta's description of the rename: "Update to Ads Management Standard Access: New Name, Revised Requirements…". The changes "take effect on May 4, 2026. This is not a breaking change … existing access levels are preserved automatically". The tiers are now "Limited access" and "Full access". — [Meta developer blog](https://developers.meta.com/blog/updates-to-ads-management-standard-access-feature/)
- The Full access threshold was reduced from 1,500 to 500 Marketing API calls in the past 15 days. Older pages still say 1,500 calls in 30 days. — [Meta: Authorization](https://developers.facebook.com/documentation/ads-commerce/marketing-api/get-started/authorization)
- "If your app is managing other people's ad accounts, you need advanced access to the ads_read and/or ads_management permissions". Development-level access is "not for production apps running for live advertisers". — [Meta: Authorization](https://developers.facebook.com/documentation/ads-commerce/marketing-api/get-started/authorization)
- "To create a system user access token, this app must have Standard Access". Only apps with Ads Management API standard access and above can be installed. — [Meta: System users, install apps and generate tokens](https://developers.facebook.com/docs/business-management-apis/system-users/install-apps-and-generate-tokens)
- System user limits: Standard access allows 1 system user and 1 admin system user. Advanced access allows 10 system users and 1 admin system user. Use the admin system user only for administrative actions. — [Meta: System Users overview](https://developers.facebook.com/docs/business-management-apis/system-users/overview)
- A system user token "does not expire", so it suits long-running services. It can still be invalidated for other reasons. The expiring option is valid for 60 days from generation or refresh. — [Meta: Marketing API Authentication](https://developers.facebook.com/documentation/ads-commerce/marketing-api/get-started/authentication); [Meta: Access Tokens](https://developers.facebook.com/docs/facebook-login/guides/access-tokens)
- Business verification is reportedly required for Advanced (Full) access to `ads_management` and for creating ad accounts programmatically, and not needed while only people with a role on the app use it. — secondary guides: [adlibrary](https://adlibrary.com/posts/meta-ads-api-integration-guide), [singhamandeep](https://singhamandeep.com/facebook-ads-api-permission-app-review/)
- Meta also runs an official **Ads MCP server**, a Meta-hosted server that lets an AI agent manage ads, as part of "ads AI connectors". It does not require owning a Meta app. — [Meta: Ads MCP Server overview](https://developers.facebook.com/documentation/ads-commerce/ads-ai-connectors/ads-mcp-server/ads-mcp-server-overview); [Get started](https://developers.facebook.com/documentation/ads-commerce/ads-ai-connectors/ads-mcp-server/ads-mcp-server-get-started); [Meta dev blog 16 Jul 2026](https://developers.facebook.com/blog/post/2026/07/16/meta-ads-mcp-server/). It reportedly launched in open beta on 29 Apr 2026 with 29 tools at mcp.facebook.com/ads. — [ppc.land (secondary)](https://ppc.land/meta-opens-its-ad-system-to-claude-and-chatgpt-with-new-ai-connectors/), [pasqualepillitteri (secondary)](https://pasqualepillitteri.it/en/news/1707/official-meta-ads-mcp-claude-29-tools-2026)

### Inferences
- For one shop's own account, a Business app in the shop's own portfolio with a system-user token should work without full App Review. Meta's wording ("must have Standard Access") suggests the system-user token needs at least the Limited tier.
- Store the token server-side only: in `.env` or encrypted settings, never in the DB response of `/api/*` (see CLAUDE.md), and never in front-end code. Give the system user the minimum assets it needs, and rotate the token by regenerating it.
- The Ads MCP server is a lower-code alternative for agent-driven management. It is still a user-authorised connector, though, not a headless server credential, so this is not verified for cron use.

### Gaps
- Not verified: whether Business Verification or two-factor authentication is strictly required for a system-user token on one's own account. No Meta source on 2FA was found this session.
- Not verified: the exact default tier of a newly created Business app.

## 7. Automated Rules API and Meta's built-in automation

### Takeaway
The Ad Rules Engine (`/act_{id}/adrules_library`) lets Meta itself run the rules server-side:
- **Rule parts:** `evaluation_spec` (SCHEDULE or TRIGGER, plus filters), `execution_spec` and an optional `schedule_spec` (DAILY / CUSTOM).
- **Actions:** PAUSE, UNPAUSE, CHANGE_BUDGET (ad set), CHANGE_CAMPAIGN_BUDGET and CHANGE_BID (schedule-only).
- **Built-in automation levers:** Advantage campaign budget, Advantage+ audience, Advantage+ placements and Advantage+ creative / flexible format.

### Cited Findings
- Ad rules are standalone objects in the ad rules library and minimally need `name`, `evaluation_spec` and `execution_spec`. `evaluation_type` is SCHEDULE or TRIGGER. — [Meta: Ad Rules Engine](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules.md); [Evaluation Spec](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules/overview/evaluation-spec)
- Schedule rules need a `schedule_spec` (DAILY, CUSTOM; custom schedules are OR'ed). Trigger rules run in real time without one. — [Meta: Schedule Based Rules](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules/guides/scheduled-based-rules); [Trigger Based Rules](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules/guides/trigger-based-rules)
- Actions: PAUSE/UNPAUSE (schedule or trigger); CHANGE_BUDGET via `change_spec` (ad sets only); CHANGE_CAMPAIGN_BUDGET; CHANGE_BID (schedule only, ad sets). There is an example of +10% budget capped at 5 executions. The change-spec page is dated June 2026. — [Meta: Execution Spec](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules/overview/execution-spec); [Change Spec](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules/overview/change-spec)
- Advantage+ creative docs. — [Meta: Get Started with Advantage+ Creative](https://developers.facebook.com/docs/marketing-api/creative/advantage-creative/get-started). The "Advantage+ standard enhancements" opt-in was deprecated from v22.0. — search extract of [MAPI changelog v22.0](https://developers.facebook.com/documentation/ads-commerce/marketing-api/marketing-api-changelog/version22.0)

### Inferences
- Guard rails (pause if CPA exceeds X with spend over Y, cap budget raises) can live in Meta's rules engine. They keep running if the Laravel cron dies, which makes them a safe "dead-man" layer under a custom autopilot.

### Gaps
- I did not see the full list of rule filter fields and metrics or the rule limit per account.

## 8. Creative upload, flexible format, ad review status

### Takeaway
- **Uploads:** images and videos are uploaded to the ad account (`adimages`, `advideos`; not re-verified) and referenced from an `AdCreative`.
- **Multi-asset ads:** use the **flexible ad format** (`creative_asset_groups_spec`, at least 1 image or video per group, at most 5 texts per text type) or Dynamic Creative via `asset_feed_spec`. Neither was found to be deprecated.
- **Review outcome:** read it via `effective_status` (DISAPPROVED, WITH_ISSUES, IN_PROCESS, …), `configured_status`, `ad_review_feedback` / `review_feedback` (the rejection reasons) and `issues_info`. Ads webhooks can push status changes.

### Cited Findings
- `creative_asset_groups_spec`: each group requires at least one image or video, with no more than 5 texts per text_type. An older guide (July 2024) limits the flexible format to the OUTCOME_SALES and OUTCOME_APP_PROMOTION objectives. — [Meta: Flexible ad format](https://developers.facebook.com/documentation/ads-commerce/marketing-api/flexible-ad-format.md); [asset groups spec ref](https://developers.facebook.com/docs/marketing-api/reference/ad-creative-asset-groups-spec)
- `asset_feed_spec` page updated 26 Jun 2026. For Dynamic Creative it has no customization rules, `AUTOMATIC_FORMAT` mixes images and videos, and only one ad_format is allowed per asset feed (May 2026 options page). — [Meta: Asset Feed Spec](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-creative/asset-feed-spec); [Options](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-creative/asset-feed-spec/options)
- Meta suggests reading `effective_status,configured_status,review_feedback` together. `review_feedback` gives the disapproval reason. — [Meta: Ads Webhooks effective status](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ads-webhooks/effective-status); [Ads Webhooks overview](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ads-webhooks/ads-webhooks-overview)
- `ad_review_feedback` is "review feedback for this ad after it is reviewed". Placement-specific feedback holds key/description reasons. — [Meta: Ad reference](https://developers.facebook.com/docs/marketing-api/reference/adgroup); [placement-specific review feedback](https://developers.facebook.com/docs/marketing-api/reference/adgroup-placement-specific-review-feedback/)
- `issues_info` is populated when effective_status is WITH_ISSUES (introduced v3.2). — [Meta: WITH_ISSUES ad objects](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ads-webhooks/with-issues-ad-objects); [issues_info ref](https://developers.facebook.com/docs/marketing-api/reference/ad-campaign-issues-info)

### Inferences
- The autopilot can generate creatives from catalog images, upload them, create ads and poll or subscribe for review results. On DISAPPROVED it should stop and alert, never auto-resubmit edited copy in a loop. Skincare claims are a common rejection area (this is an assumption to verify against Meta's Advertising Standards).
- With a product catalog, catalog-based (product set) ads also work. Note that v27 can switch on product showcase by default.

### Gaps
- `adimages` / `advideos` upload specs (size limits, chunked video upload) were not re-verified this session.
- No source found on whether the flexible format's objective limit still holds in 2026.

## 9. Terms and policies on programmatic management

### Takeaway
Programmatic management through the official Marketing API is the sanctioned route. Use is governed by the Meta Platform Terms and Developer Policies, which allow Meta to audit and to suspend apps or accounts for non-compliance. Automating Ads Manager outside the API (scraping or browser bots) is reportedly prohibited.

### Cited Findings
- The Marketing API is meant for creating, updating and deleting campaigns, ad sets and ads programmatically. All developers must follow the Platform Terms and Developer Policies. — [Meta: Authorization](https://developers.facebook.com/documentation/ads-commerce/marketing-api/get-started/authorization); [Meta Platform Terms](https://developers.facebook.com/terms); [Developer Policies](https://developers.facebook.com/devpolicy)
- Platform Terms: "if you fail to comply … we may suspend or terminate your App or account". — search extract of [Meta Platform Terms](https://developers.facebook.com/terms)
- Best-practices page: Meta "has the right to audit your activity anytime". — [Meta: Marketing API Best Practices](https://developers.facebook.com/documentation/ads-commerce/marketing-api/best-practices)
- Developer Policy page on separate ad accounts. — [Meta](https://developers.facebook.com/documentation/development/terms-and-policies/separate-ad-accounts) (not opened)
- Meta's terms reportedly prohibit automated tools that interact with Ads Manager outside the official API. — [Supermetrics (secondary)](https://supermetrics.com/blog/ai-agent-ad-account-banned)

### Inferences
- Use only the official API (or Meta's own MCP server), handle rate-limit errors gracefully, and keep a human-readable audit log of every automated change. This matches the audit clause and the owner's "secure" rule.

### Gaps
- I could not read the full Platform Terms text or any specific "Marketing API Terms" clause this session.
- No UAE-specific advertising or legal rules were researched here; that is out of scope. UAE-specific API facts found: only the AED minor-unit (fils) budget denomination, by inference from Meta's "minimum denomination" rule.
