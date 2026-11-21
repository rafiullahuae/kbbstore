# Meta facts the engine is coded to (read 20 Nov 2026)

Every fact below was read on Meta's own developer pages on 20 Nov 2026 (Meta serves them as Markdown at
`developers.facebook.com/documentation/…​.md`). **[verified]** = read on the page linked. **[inferred]** = not stated
on a page this lane could open; the engine takes the safe option, named in the line. Code that depends on a fact
names its § number.

Short link prefix: `MAPI` = `https://developers.facebook.com/documentation/ads-commerce/marketing-api`.

## §1 Version
- **[verified]** Graph/Marketing API **v27.0**, released 4 Nov 2026, "Available until TBD". v27 changes: Catalog Batch
  API deprecated; `meta_moment_maker_spec` removed; on eligible Sales ad sets that target devices/OS, "reach more
  people across other mobile devices" defaults ON when `targeting_automation.individual_setting.device` is omitted.
  — `MAPI/marketing-api-changelog/version27.0.md`
- Engine: `meta.DefaultVersion = "v27.0"`, configurable in Settings (`api_version`). The engine never targets by
  device/OS, so the v27 device default does not apply to its ad sets.

## §2 Sales campaigns / Advantage+ sales
- **[verified]** The ASC API (`smart_promotion_type=AUTOMATED_SHOPPING_ADS`) cannot create campaigns from v25. An
  Advantage+ sales campaign is an `OUTCOME_SALES` campaign with **campaign budget** (a supported bid strategy),
  **Advantage+ audience** (`targeting.targeting_automation.advantage_audience = 1`, or only geo targeting) and
  **Advantage+ placements** (no placement targeting; the API default). Read back with
  `GET <campaign>?fields=advantage_state_info` → `advantage_state: ADVANTAGE_PLUS_SALES`. — `MAPI/advantage-campaigns.md`
- **[verified]** Campaign create: `objective` enum includes `OUTCOME_SALES`; `special_ad_categories` is **required**;
  only `ACTIVE`/`PAUSED` valid at creation; `daily_budget` int64 (campaign budget shared by its ad sets; budget on the
  campaign OR the ad set, not both); campaign `spend_cap` min ≈ USD 100, 922337203685478 removes it.
  — `MAPI/reference/ad-account/campaigns.md`
- **[verified]** Ad set: `optimization_goal=OFFSITE_CONVERSIONS` with `promoted_object {pixel_id, custom_event_type:
  PURCHASE}`; `targeting` needs countries; only `ACTIVE`/`PAUSED` at creation. — `MAPI/reference/ad-account/adsets.md`
- Engine (executor): `create_campaign` = OUTCOME_SALES, `special_ad_categories=[]`, campaign `daily_budget`,
  `bid_strategy` (default LOWEST_COST_WITHOUT_CAP), status PAUSED. `create_adset` = OFFSITE_CONVERSIONS / PURCHASE on
  the saved pixel, `advantage_audience: 1`, countries from the owner's list, no placement targeting, PAUSED.

## §3 Money units
- **[verified]** Budgets and bids are in the account currency's minimum denomination ("cents for USD").
  — `MAPI/bidding/overview/budgets.md`. **AED offset = 100** (fils). — `MAPI/currencies.md`
- **[verified]** A daily budget may spend up to 25% more on a good day (the average holds). — same budgets page
- **[verified]** Insights `spend` is a numeric string in the currency (major units). — `MAPI/reference/ad-account/insights.md`
- **[verified]** Ad-rule Insights filters are in the currency's base unit ("1000 for spent = $10.00").
  — `MAPI/ad-rules/overview/evaluation-spec.md`
- Engine: AED→fils only in `executor.Fils` (`round(aed×100)`, refuses ≤0, NaN, > AED 100,000); reads convert
  minor→AED in `meta/api.go`; rule thresholds in fils in `safety`. The owner's daily cap is compared with the SUM of
  running daily budgets; the 25% day-to-day overspend is bounded by the KAA: daily-limit rule (§7).

## §4 Bid strategies, scheduling
- **[verified]** LOWEST_COST_WITHOUT_CAP, LOWEST_COST_WITH_BID_CAP, COST_CAP, LOWEST_COST_WITH_MIN_ROAS
  (`roas_average_floor` ×10,000). — `MAPI/bidding/overview/bid-strategy.md`
- **[verified]** Ad scheduling (`pacing_type=["day_parting"]` + `adset_schedule`) is shown with **lifetime** budgets.
  — `MAPI/bidding/overview/pacing-and-scheduling.md`. Engine: no dayparting (daily budgets only).

## §5 Rate limits and errors
- **[verified]** Score per ad account: read 1, write 3. **Limited tier (the default, no App Review): max 60, decay
  300 s, blocked 300 s; Full access: 9000**, blocked 60 s. Error 17/2446079, 613/1487742.
- **[verified]** Mutations ≤ 100 QPS per app+account (613/5044001); one edit per object every **30 s** (613/4841018);
  ad set budget ≤ **4 changes per hour** (613/1487632, blocked an hour); **account spending limit ≤ 10 changes a
  day** (17/1885172); BUC throttling 80000/80003/80004/80014; insights app throttling 4/1504022|1504039.
- **[verified]** Headers: `X-Business-Use-Case-Usage` (call_count, total_cputime, total_time,
  estimated_time_to_regain_access (minutes), ads_api_access_tier), `X-Ad-Account-Usage` (acc_id_util_pct, …),
  `X-FB-Ads-Insights-Throttle` (app_id_util_pct, acc_id_util_pct). — `MAPI/overview/rate-limiting.md`;
  `https://developers.facebook.com/docs/graph-api/overview/rate-limiting`
- **[verified]** 190 = token expired/invalid (subcodes 463/467 expired). — Graph API error-handling page
- Engine: the client paces itself to 80% of 60 points / 300 s until a header says `standard_access`; retries 4, 17,
  32, 613, 8000x, 5xx and `is_transient` with backoff (30 s for 4841018); never retries 190, 613/1487632,
  17/1885172 or 100; the executor adds its own ≤4 budget changes/hour, ≥30 s per object, and holds non-pause writes
  when usage ≥ 90% or Meta gave a regain time.

## §6 Account spending limit (spend_cap)
- **[verified]** Read: `spend_cap` numeric string in the currency's basic unit; **"A value of 0 means no
  spending-cap"**; "Setting a new spend cap only applies to spend AFTER the time at which you set it".
  `amount_spent` = spent "with respect to spend_cap". **Write: `spend_cap` float in the STANDARD denomination
  (23.50 for USD 23.50); 0 = no cap; `spend_cap_action=reset|delete`.** — `MAPI/reference/ad-account.md`
- Engine (`safety`, 0.6.5, the owner's rule of 22 Nov: his limits are for the app's own campaigns only): cap target =
  `amount_spent + his own campaigns' daily budgets × days left + max(0, monthly − the APP's spend this month)` (so
  the cap never stops his ads); the app's own campaigns are held by `KAA: daily limit` and `KAA: monthly limit`
  (TRIGGER, per KAA campaign, TODAY / THIS_MONTH spent > limit ÷ running KAA campaigns). Before 0.6.5: target =
  `amount_spent + max(0, monthly − spent this month)`, written in AED with 2
  decimals, never ≤ 0 (refused twice: `DecideCap` and `SetSpendCap`), never raises a lower cap the owner set by hand
  (only the cap the app itself last wrote), ≤ 6 writes a day, re-read after a write (if Meta reset amount_spent the
  cap is lowered again). **[inferred]** whether a write resets amount_spent: handled both ways by the re-read.

## §7 Automated Rules (adrules_library)
- **[verified]** `POST act_X/adrules_library` with `name`, `evaluation_spec`, `execution_spec` (+ `schedule_spec`
  for SCHEDULE rules); status ENABLED/DISABLED; delete to remove. — `MAPI/ad-rules.md`
- **[verified]** Filters (AND): `entity_type` AD|ADSET|CAMPAIGN, multi-level prefixes (`campaign.name`), `name`
  CONTAIN, `time_preset` (TODAY, LAST_7_DAYS includes today…), Insights fields `spent`, `cost_per_purchase_fb`,
  `offsite_conversion.fb_pixel_purchase` (all trigger-capable), units = base currency unit.
  — `MAPI/ad-rules/overview/evaluation-spec.md`
- **[verified]** Execution: PAUSE / UNPAUSE / NOTIFICATION (schedule+trigger), CHANGE_BUDGET etc. (schedule).
  — `MAPI/ad-rules/overview/execution-spec.md`. Schedule types DAILY, HOURLY, SEMI_HOURLY, CUSTOM.
  — `MAPI/ad-rules/guides/scheduled-based-rules.md`. Trigger rules: one `trigger` (`STATS_CHANGE` on a field),
  evaluated in real time (insights p99 ≈ 7.5 min), only `TODAY` presets. — `MAPI/ad-rules/guides/trigger-based-rules.md`
- **[verified]** If no `effective_status` filter is given, PAUSE rules only look at ACTIVE / PENDING_REVIEW objects.
- Engine: the four KAA: rules in `safety/safety.go` (see docs/SAFETY.md-equivalent block at the top of that file).
  **[inferred]** the number of rules an account may have: not found; the app uses 4.

## §8 Insights
- **[verified]** `GET act_X/insights` with `level` (ad|adset|campaign|account), `time_range {since,until}`,
  `time_increment=1`, `action_attribution_windows` (the engine sends `["7d_click","1d_view"]` explicitly: the page
  contradicts itself on what `default` means), `actions`, `action_values`, `inline_link_clicks`, `spend`, `reach`,
  `frequency`. 7d_view / 28d_view were deprecated 12 Jan 2026 (dev blog, 16 Oct 2025).
  — `MAPI/reference/ad-account/insights.md`
- **[inferred]** purchase `action_type` names: `omni_purchase`, `purchase`, `offsite_conversion.fb_pixel_purchase`
  (the AdsActionStats page 404'd). Engine: the FIRST of those present on a row, never a sum (they overlap).
- Engine: the last 2 days are marked `settling` and re-read on every sync.

## §9 Creatives, review
- **[verified]** `POST act_X/adimages` with `bytes` (base64) → `images{name:{hash}}`; `POST act_X/advideos` with
  `source` (multipart; chunked upload exists for big files). — `MAPI/reference/ad-account/adimages.md`, `…/advideos.md`
- **[verified]** `POST act_X/adcreatives` with `name` + `object_story_spec {page_id, link_data {message, link, …,
  call_to_action}}`. — `MAPI/get-started/basic-ad-creation/create-an-ad-creative.md`
- **[verified]** Ad fields `effective_status` (… PENDING_REVIEW, DISAPPROVED, WITH_ISSUES, IN_PROCESS),
  `ad_review_feedback`, `issues_info`; an ad is PENDING_REVIEW after creation. — `MAPI/reference/adgroup.md`
- **[inferred]** v27 "product showcase" default on ad create: the opt-out field location was not confirmed; the engine
  sends plain single-image/video link ads and leaves it at Meta's default (flagged for the integrator).

## §10 Idempotency
- **[verified]** `X-Client-Idempotent-Key` on `POST act_X/campaigns|adsets|ads|adcreatives`: a retry with the same
  key and body returns the first object (7 days; 1-128 chars `[A-Za-z0-9_-]`; same key + other body → 100/1349016;
  updates and batch requests ignore it). — `MAPI/idempotent-requests.md`
- Engine: key = `kaa-` + sha256(proposal id, kind, target, sorted params)[:40 hex], also recorded in the local ledger
  so a replayed proposal is answered without calling Meta.

## §11 Account
- **[verified]** `account_status` (1 ACTIVE, 2 DISABLED, 3 UNSETTLED, 7 PENDING_RISK_REVIEW, 9 IN_GRACE_PERIOD,
  101 CLOSED …), `disable_reason`, `min_daily_budget`, `currency`, `timezone_name`. — `MAPI/reference/ad-account.md`
- Engine: refuses to connect an account whose currency is not AED; alerts when status ≠ 1.

## §12 Access WITHOUT Business Verification (the owner's account is unverified)
- **[verified]** "If your app will only be used by app users who have a role on the app itself you do not need to
  complete verification; these users can grant your app any permissions at any time and all features are always
  active." — `https://developers.facebook.com/documentation/development/release/business-verification.md`
- **[verified]** Business Verification is required for **Advanced** access (other people's data). Standard access is
  automatic for Business apps, for people with a role on the app.
  — `https://developers.facebook.com/documentation/development/graph-api/overview/access-levels.md`
- **[verified]** "If your app is only managing your ad account, standard access to the `ads_read` and
  `ads_management` permissions are sufficient." Marketing API Access Tier: **Limited (default, automatic when the
  Marketing API product is added): heavily rate-limited, 1 system user + 1 admin system user**; Full access needs
  App Review (≥500 calls in 15 days, < 15% errors). — `MAPI/get-started/authorization.md`
- **[verified]** The "Create & manage ads with Marketing API" use case adds `ads_management`, `ads_read`,
  `business_management`, `pages_read_engagement`, `pages_show_list` (required) and optional `catalog_management`,
  `page_manage_ads`. — `https://developers.facebook.com/documentation/development/create-an-app/marketing-api-use-cases.md`
- **[verified]** System users: Business Settings → Users → System Users → Add; token: select the system user →
  Generate new token → pick the app and permissions. — `MAPI/collaborative-ads/managed-partner-ads/api-guide/prerequisites/create-system-user.md`,
  `…/generate-access-token-system-user.md`
- **[verified]** "Apps with Standard access to the Marketing API receive long-lived tokens that do not expire based on
  time … This also applies to access tokens for System Users." Long-lived user tokens otherwise last ≈ 60 days.
  — `https://developers.facebook.com/documentation/facebook-login/guides/access-tokens.md`
- **[verified]** Long-lived user token: `GET oauth/access_token?grant_type=fb_exchange_token&client_id&client_secret&
  fb_exchange_token` → `{access_token, expires_in≈5183944}`; an expired token cannot be exchanged; call it from the
  server (it carries the app secret). — `https://developers.facebook.com/documentation/facebook-login/guides/access-tokens/get-long-lived.md`
- **[verified]** `GET debug_token?input_token&access_token` (app token or a developer's user token).
  — `…/facebook-login/guides/access-tokens/get-session-info.md`. **[inferred]** the response field names
  (`type`, `expires_at` (0 = never), `is_valid`, `scopes`) are Meta's long-standing ones; the reference page was not
  reachable as Markdown.
- **[inferred]** Whether an UNVERIFIED business may create a system user: no Meta page says verification is needed
  for it, and the Limited tier explicitly allows 1. If Business Settings refuses, the user-token path (Path B in
  META-SETUP.md) works without it.
- **[inferred]** Whether re-exchanging a still-valid long-lived user token extends it: not stated. The engine tries
  once a day from 10 days before expiry and keeps the warning if the date does not move.

## §13 Portfolio discovery
- **[verified]** Business edges exist in the Marketing API reference: `owned_ad_accounts`, `client_ad_accounts`,
  `owned_pages`, `owned_instagram_accounts`, `owned_pixels`, `owned_product_catalogs`,
  `owned_whatsapp_business_accounts`. — `MAPI/reference/llms.txt` → `reference/business/*.md`
- **[inferred]** `me/businesses` and `me/adaccounts` (Graph user edges, long-standing; their pages were not reachable as
  Markdown). The engine reads both and treats a refused edge as a note on that business, never fatal.
- **[verified]** Insights reach back 37 months (`date_preset=maximum`; error 3018 "cannot be beyond 37 months").
  — `MAPI/reference/ad-account/insights.md`. The history import reads exactly that far.

## §14 Ad formats
- **[verified]** Carousel: `object_story_spec.link_data.child_attachments`, ≥2 cards (Instagram shows the first 5,
  Stories up to 10), each with `link` and `image_hash`/`picture` or `video_id` + thumbnail; `instagram_user_id` on
  the creative. — `…/ads-commerce/instagram/ads-api/guides/carousel-ads.md`, `MAPI/reference/ad-creative.md`
- **[verified]** Video upload, resumable: `POST act_X/video_ads upload_phase=start` → `{video_id, upload_url}` →
  `POST rupload.facebook.com/video-ads-upload/v27.0/<id>` with `Authorization: OAuth`, `offset`, `file_size` (resume
  from `status.uploading_phase.bytes_transferred`) → `upload_phase=finish` → poll `GET <id>?fields=status` until
  `video_status: ready` (processing makes thumbnails). Video creative: `video_data {video_id, image_url|image_hash}`.
  — `MAPI/guides/videoads/fbvideoads.md`, `MAPI/guides/videoads.md`
- **[verified]** Flexible / dynamic creative: `asset_feed_spec {images, videos, bodies, titles, descriptions,
  ad_formats, call_to_action_types, link_urls}`, no customization rules. — `MAPI/ad-creative/asset-feed-spec.md`
- **[verified]** Advantage+ creative: `degrees_of_freedom_spec.creative_features_spec.<feature>.enroll_status`
  OPT_IN/OPT_OUT; features listed include `image_touchups`, `inline_comment`, `image_templates`, `text_optimizations`,
  `text_translation`, `image_background_gen`, `product_extensions` … — `MAPI/creative/advantage-creative/get-started.md`.
  Engine: allow-list in `executor/formats.go`; generative/translation features OPT_OUT only; `product_extensions`
  (v27 product showcase default) sent OPT_OUT unless the brain opts in.
- **[verified]** Advantage+ catalog ads: campaign `objective=PRODUCT_CATALOG_SALES` + `promoted_object
  {product_catalog_id}`; ad set `promoted_object {product_set_id[, custom_event_type]}`; creative
  `object_story_spec.template_data {message, name, link, description}` with `{{product.*}}` tokens + `product_set_id`.
  — `MAPI/advantage-catalog-ads/get-started.md`. **[inferred]** the ODAX equivalent (OUTCOME_SALES + catalog) is not
  in the mapping table; the engine uses the objective the catalog page documents.
- **[verified]** Collection ads are built on an Instant Experience (formerly Canvas): `link =
  https://fb.com/canvas_doc/<canvas id>`. — `MAPI/creative/collection-ads.md`. Engine: only on an existing IE id.
- Product sets: `POST <catalog>/product_sets {name, filter}`. **[inferred]** from the catalog reference index; the
  engine sends only a name and a JSON filter.

## §15 Click-to-message and audiences
- **[verified]** Click-to-WhatsApp: objective OUTCOME_SALES (or ENGAGEMENT/LEADS/TRAFFIC); ad set
  `destination_type=WHATSAPP`, `optimization_goal` CONVERSATIONS (allowed for SALES), `promoted_object {page_id,
  whatsapp_phone_number}`; creative CTA `{type: WHATSAPP_MESSAGE, value {app_destination: WHATSAPP}}`; prerequisite: a
  Page with a WhatsApp number linked. — `MAPI/ad-creative/messaging-ads/click-to-whatsapp.md`
- **[verified]** Messenger: `destination_type=MESSENGER`, CTA `MESSAGE_PAGE`, `app_destination MESSENGER`.
  Instagram Direct: `destination_type=INSTAGRAM_DIRECT`, CTA `INSTAGRAM_MESSAGE`, `app_destination INSTAGRAM_DIRECT`,
  `instagram_user_id`. — `…/click-to-messenger.md`, `…/click-to-instagram.md`
- **[verified]** Website custom audience: `POST act_X/customaudiences {name, rule {inclusions {operator, rules
  [{event_sources [{id: pixel, type: pixel}], retention_seconds, filter {operator, filters [{field, operator,
  value}]}}]}}, prefill}`, retention 1–180 days. — `MAPI/audiences/guides/website-custom-audiences.md`
  **[inferred]** the `event` filter with operator `eq` (the page shows `i_contains` on `event`; the catalog page
  shows `{"event":{"eq":…}}`).
- **[verified]** Engagement audience: `event_sources [{type: page | ig_business, id}]`, events `page_engaged`,
  `ig_business_profile_all` … — `MAPI/audiences/guides/engagement-custom-audiences.md`
- **[verified]** Lookalike: `subtype=LOOKALIKE`, `origin_audience_id` (≥100 people), `lookalike_spec {ratio 0.01–0.20}`;
  **v27 rejects `country` / `location_spec`**. — `MAPI/audiences/guides/lookalike-audiences.md`. Engine: ratio ≤ 0.10.

## §16 Targeting, placements, frequency
- **[verified]** Advantage+ audience: `targeting_automation.advantage_audience` 1/0 (defaults to 1 from v23);
  with it on, `age_min` 18–25 only, `age_max` fixed 65, `age_range` is a suggestion. `genders` 1/2.
  — `MAPI/audiences/reference/targeting-expansion/advantage-audience.md`, `…/basic-targeting.md`
- **[inferred]** `locales` (ids from Meta's targeting search) — passed through as numbers.
- **[verified]** Placements: `publisher_platforms` (facebook, instagram, threads, messenger, audience_network),
  `facebook_positions` (feed, story, facebook_reels, …), `instagram_positions` (stream, story, reels, explore, …);
  omit them for Advantage+ placements. — `MAPI/audiences/reference/placement-targeting.md`
- **[verified]** `frequency_control_specs` can be written only on REACH / THRUPLAY ad sets.
  — `MAPI/reference/ad-account/adsets.md`. Engine: refuses a frequency cap on sales ad sets, with that reason.

## §19 The media-buying layer (read 20 Nov 2026 by the media-buying lane)
- **[verified]** Advantage+ audience never expands "location constraints, minimum age, language, and custom audience
  exclusions"; `age_range` is a suggestion with it on; from v23 `advantage_audience` must be explicit on create.
  — `MAPI/audiences/reference/targeting-expansion/advantage-audience.md`. Engine: no language (locale) targeting (it
  would be a hard limit); buyers are excluded with `excluded_custom_audiences` (honoured).
- **[verified]** `existing_customer_budget_percentage` is not available for new Advantage+ campaigns. Meta's replacement:
  two ad sets under the campaign budget, one including the existing customers (`custom_audiences` with
  `targeting_relaxation_types {custom_audience: 0}`) with an ad-set `daily_spend_cap` (+ optional
  `daily_min_spend_target`), one excluding them. — `MAPI/advantage-campaigns.md` ("Replicate
  existing_customer_budget_percentage behavior"). Engine: `create_adset {spend_cap, strict_audiences: 1}` (growth tier).
- **[verified]** Ad set `attribution_spec`: a list of `{event_type: CLICK_THROUGH | VIEW_THROUGH | ENGAGED_VIDEO_VIEW,
  window_days}`; `daily_spend_cap` / `daily_min_spend_target` int64 "in your account currency", only under a campaign
  daily budget. — `MAPI/reference/ad-account/adsets.md`. **[inferred]** the spend-cap unit is the minor unit like every
  other budget field (fils), which the engine sends.
- **[verified]** Website custom audience `rule {inclusions, exclusions}`, each `{operator, rules [{event_sources,
  retention_seconds, filter}]}`; up to 10 rules. The page's table says "if field is event, must use `=`" while its own
  examples use `"eq"`: the engine keeps `eq` (as before). — `MAPI/audiences/guides/audience-rules.md`
- **[verified]** Dynamic retargeting without a product-audience object: ad set `targeting.product_audience_specs
  [{product_set_id, inclusions [{retention_seconds, rule {event {eq}}}], exclusions [...]}]`; each rule one event with
  `eq`. Cross-sell: the audience on set A, the promoted set B. — `MAPI/advantage-catalog-ads/get-started.md`
- **[verified]** Product-set filters: `retailer_id {is_any: [...]}`, `availability {eq: "in stock"}`, `and` / `or`
  composites, max 500 KiB; an empty set never delivers. — `MAPI/reference/product-catalog/product_sets.md`
- **[verified]** Custom audience `approximate_count_lower_bound` / `_upper_bound` (−1 for inactive lookalikes) and
  `delivery_status {code, description}` (200 = ready). — `MAPI/reference/custom-audience.md`
- **[verified]** Learning phase: an ad set leaves it "after about 50 results in the week after the ad set's last
  significant edit"; a significant edit restarts it. — `facebook.com/business/help/112167992830700`
- **[verified]** Locale ids come from `GET /search?type=adlocale&q=…` (never hard-coded). — `MAPI/audiences/reference/targeting-search.md`
- **Practitioner heuristics** (labelled "practice" on Strategy → Our playbook): small-budget split ~80/20, retargeting
  scaled only under a 7-day frequency of ~3, 3–6 concepts per ad set, tests inside the main campaign, ≤ 20% steps
  every 3 days, cost cap only from ~50 purchases a week, Reels-first, benefit-first copy, seasonal ramp.
