# Meta measurement and data plumbing for kbeautybliss.com: CAPI, pixel, catalog, attribution (as of 20 Nov 2026)

> **How these notes were made, and how far they can be trusted.** This session's network refused developers.facebook.com and www.facebook.com (DNS refused by the sandbox allowlist), so **Meta's primary docs could not be fetched**. Facts below come from search-engine summaries of Meta's pages where those were returned, from vendor docs (Tamara, Tabby, GitHub) that could be read, and otherwise from third-party measurement blogs. Each item names its source. Treat every Meta number here as "corroborated by secondary sources, not read on Meta's page" until someone with access to developers.facebook.com confirms it. Items written from model memory, with no source, are in **Gaps**.
>
> **What the repo already has** (read from the code, not the web): a direct CAPI integration in `app/Services/Pixels/ServerEvents.php` (534 lines) posting to `https://graph.facebook.com` at `GRAPH_VERSION = 'v25.0'` (`PlatformClient.php:22`), plus TikTok and GA4 from the same dispatcher. Purchase uses `event_id = "purchase-<order number>"` and the browser `fbq` uses the same id through `ServerEvents::purchaseId()` (`MarketingPixels.php:399`). `action_source` is `website`. `external_id` is the SHA-256 of `kbb-customer-<id>`. `_fbp` and `_fbc` are read from cookies and format-checked (`BrowserContext.php:53-54`). `test_event_code` is supported. Purchase fires when an order enters `PLACED = ['processing','onhold','shipped','completed']` (`ServerEvents.php:69`, `MarketingPixelsServiceProvider.php:54-70`), after the DB commit, and **synchronously, because the host runs no queue worker** (`ServerEvents.php:55`). A 600 s circuit breaker exists. **No refund or cancellation event exists** (no match for refund or cancel in `app/Services/Pixels`). The catalog feeds are `/feeds/meta-catalog.xml` and `/feeds/tiktok-catalog.xml` (`routes/marketing-catalog-feeds.php`). Their ids follow `CatalogIds`: `<product id>` or `<product id>-<variant>`, `product_group` keyed to `item_group_id`, deliberately not the SKU that the Google feed uses.

## 1. Conversions API: endpoint, parameters, hashing

### Takeaway
CAPI is a POST of a `data` array to `graph.facebook.com/<version>/<pixel_id>/events`. Each event carries event_name, event_time, event_id, action_source=website, event_source_url and user_data. Email, phone and names are normalized, then SHA-256 hashed. fbc, fbp, IP and user agent are sent **unhashed**. Hashing external_id is recommended, not required. The repo already follows this shape. The current Graph version appears to be **v26.0** (the repo pins v25.0, which is still supported).

### Cited Findings
- Normalization before SHA-256: email trimmed and lowercased. Phone as digits only, with the country code and no symbols or leading zeros (so a UAE number becomes `9715XXXXXXXX`). fn and ln lowercase, with no punctuation or spaces. db as YYYYMMDD. — [lukaswojcik.com](https://www.lukaswojcik.com/blog/en/digital-marketing/conversions-api-hashing-normalising-customer-parameters-before-sha-256/); [adamigo.ai](https://www.adamigo.ai/blog/how-to-standardize-conversion-data-for-meta-integration)
- fbc, fbp, client_ip_address and client_user_agent are sent in plain text. Meta matches them against its own records, so a hashed value matches nothing. — [lukaswojcik.com](https://www.lukaswojcik.com/blog/en/digital-marketing/conversions-api-hashing-normalising-customer-parameters-before-sha-256/)
- Hashing external_id is recommended but not required. Customer.io hashes it automatically so that it matches across the Pixel and CAPI. — [docs.customer.io](https://docs.customer.io/integrations/data-out/connections/facebook-conversions-api/); [lukaswojcik.com](https://www.lukaswojcik.com/blog/en/digital-marketing/conversions-api-hashing-normalising-customer-parameters-before-sha-256/)
- An identifier sent through both the Pixel and CAPI must have the same form on both paths. Otherwise Meta sees two different identifiers. — [lukaswojcik.com](https://www.lukaswojcik.com/blog/en/digital-marketing/conversions-api-hashing-normalising-customer-parameters-before-sha-256/)
- event_time is a Unix timestamp that can be **at most 7 days old**. One source says a single over-age event fails the **whole request**. — [enalitica.com](https://enalitica.com/blog/meta-conversions-api-capi-ecommerce); [yosefk.me](https://yosefk.me/blog/meta-conversions-api/) (secondary sources; Meta's server-event page could not be fetched)
- action_source must be set on every event. Use `website` on both the browser and server sides so that the pair lines up. — [trackingwhisper.com](https://trackingwhisper.com/blog/meta-conversions-api-deduplication); [endframe.io](https://www.endframe.io/blog/meta-capi-event-deduplication-event-id)
- Graph API / Marketing API **v25.0** was released on 18 Feb 2026, and its expiry is listed as "TBD". — [Meta blog](https://developers.facebook.com/blog/post/2026/02/18/introducing-graph-api-v25-and-marketing-api-v25); [v25.0 changelog](https://developers.facebook.com/docs/graph-api/changelog/version25.0)
- **v26.0** is listed with a release date of 29 Jul 2026. — [v26.0 changelog](https://developers.facebook.com/docs/graph-api/changelog/version26.0). Conflict: some Marketing API versioning and changelog pages still name v25.0 as latest ([versioning](https://developers.facebook.com/documentation/ads-commerce/marketing-api/overview/versioning)). These come from search summaries, not fetched pages.
- Meta's developer docs now also live under a new path, `developers.facebook.com/documentation/ads-commerce/...`. The CAPI pages for using-the-api, server-event, offline events and value optimization all appear there. — [Ads and Commerce: using the API](https://developers.facebook.com/documentation/ads-commerce/conversions-api/using-the-api); [server-event](https://developers.facebook.com/documentation/ads-commerce/conversions-api/parameters/server-event)
- Official PHP SDK: `facebook/facebook-php-business-sdk` requires **PHP 8.0+**. Its ServerSide classes are `EventRequest` (pixel id, then `setEvents()`, then `execute()`), `UserData` (`setEmail`, `setClientIpAddress`, `setClientUserAgent`, …), `CustomData` (`setCurrency`, `setValue`) and `Event` (`setEventName`, `setEventTime`, `setEventSourceUrl`, `setActionSource`, …). The README points to external docs for async requests and concurrent batching. — [GitHub README](https://github.com/facebook/facebook-php-business-sdk)

### Inferences
- For AED purchases, custom_data should be `currency: "AED"`, `value` = what the shop counts as revenue (decide whether that is with or without shipping and VAT, and keep it the same in every report), `content_ids` / `contents[{id, quantity, item_price}]` using **exactly** the `CatalogIds` rule, `content_type` `product` (or `product_group`), `num_items` and `order_id`. The repo already builds custom_data. Whether it sends `order_id` and `num_items` was not checked here.
- The repo's external_id (`sha256("kbb-customer-<id>")`) is stable only for logged-in customers. Guest orders send none. Adding hashed em and ph (which the repo's `UserData` class appears to handle) matters more for match quality.
- Do **not** adopt the official PHP SDK. It is a large new dependency, and the repo already has a working client. CLAUDE.md forbids new dependencies, and the SDK adds no capability the existing `PlatformClient` lacks.

### Gaps
- Not confirmed on Meta's page: the full current list of customer-information keys (ct, st, zp, country normalization — believed lowercase, no spaces; country as an ISO-2 code like `ae`, hashed), the exact batch maximum (widely cited as **1,000 events per request**, from memory and not verified here), rate limits, and response format.
- Whether Meta has changed the 7-day event_time limit in 2026.

## 2. Deduplication, Event Match Quality, Test Events

### Takeaway
Dedup keys on **event_name + event_id**, sent to the **same pixel/dataset**. The 48-hour window runs from when Meta first **receives** an event, not from event_time, so a server Purchase delayed past 48 h is counted twice. EMQ is a 0–10 score per event, and hashed email, phone, external_id and fbc drive it. Third-party guidance targets 6+ to 8+.

### Cited Findings
- The pixel's `eventID` must equal CAPI's `event_id`, with the same event_name, on the same pixel (dataset) ID. — [relevantaudience.com](https://www.relevantaudience.com/facebook-ads/meta-pixel-and-conversions-api-guide/); [blazebytesolutions.com](https://www.blazebytesolutions.com/blog/meta-pixel-conversions-api-deduplication/)
- The 48 h dedup window starts at receipt, not at the event_time you send. A server event queued and sent two days later will not pair, and both copies count. — [endframe.io](https://www.endframe.io/blog/meta-capi-event-deduplication-event-id); [dev.to](https://dev.to/marketingkamran/meta-conversions-api-deduplication-generate-the-eventid-once-then-pass-it-2io2)
- The "28 days" figure belongs to order_id-based purchase dedup, which is limited to select partners. It is not the standard rule. Offline (`physical_store`) events dedupe on order_id with a 7-day maximum. — [trackingwhisper.com](https://trackingwhisper.com/blog/meta-conversions-api-deduplication)
- EMQ is scored 0–10 per event type. Benchmarks vary: "6.0 or higher" in one source, "8 or higher on key conversion events" in another. One vendor claims one-click/Gateway setups top out around 6.5 (unverified). — [chatterbuzzmedia.com](https://www.chatterbuzzmedia.com/blog/meta-conversions-api-guide/); [noticemesenpai.com](https://noticemesenpai.com/learn/meta-conversions-api-setup-emq-2026/); [vixi.agency](https://vixi.agency/blog/meta-conversions-api-setup-2026/)
- The biggest EMQ drivers are hashed email, hashed phone and the fbc click cookie, then external_id and fbp. — [yosefk.me](https://yosefk.me/blog/meta-conversions-api/); [bradfarleigh.com (Oct 2026)](https://www.bradfarleigh.com/2026/10/conversions-api-vs-capi-gateway-vs-gtm-server-side/)
- One guide says Meta shows EMQ only for web events as of Oct 2026. — [noticemesenpai.com](https://noticemesenpai.com/learn/meta-conversions-api-setup-emq-2026/)
- Meta's Payload Helper and the Test Events tool (`test_event_code`) are the validation path. — [lukaswojcik.com](https://www.lukaswojcik.com/blog/en/digital-marketing/conversions-api-hashing-normalising-customer-parameters-before-sha-256/)

### Inferences
- The repo's deterministic `purchase-<order number>` id is the right design. It is known to both sides without passing state. AddToCart and InitiateCheckout also carry an eventId.
- **Risk for BNPL:** if the browser fires Purchase on the thank-you page but the server fires only when a Tamara or Tabby webhook later moves the order to `processing`, any gap over 48 h double-counts. In practice BNPL approval takes minutes, so the risk is low. A server send retried after a long outage is the real case, so drop or flag a server Purchase older than about 47 h if the browser already fired.
- A browser Purchase for an order that is later declined is a false conversion. The browser pixel should fire Purchase only once the order is in a `PLACED` state when the thank-you page renders. Check `MarketingPixels.php` around line 399 for that.

### Gaps
- Meta's own wording on the 48 h window and on fbp/external_id fallback dedup could not be read.

## 3. Server-side practice for Laravel: queueing, retries, deferred payments, refunds

### Takeaway
Fire the server Purchase on **confirmed payment**: Stripe `payment_intent.succeeded` or the webhook path, Tamara `order_approved` followed by authorise, Tabby `authorized`. Never fire it on order creation. Send it within minutes. Retries must stay well inside 48 h for dedup, and event_time must stay within 7 days. Refunds and cancellations are **not** corrected in Ads Manager by any event, so the autopilot must reconcile against the shop's own orders.

### Cited Findings
- Tamara: the authorise call should be made on receipt of the `order_approved` webhook unless auto-authorisation is enabled. An order not authorised within **72 h** of approval expires. An uncaptured order auto-captures at **21 days**. Capture is a separate call at fulfilment. — [Tamara: Authorise Order](https://docs.tamara.co/reference/authoriseorder); [Tamara: order status flow](https://docs.tamara.co/docs/online-order-status-flow)
- Tabby: webhook statuses are lowercase (`authorized`), API statuses uppercase. An authorize and a capture both arrive as `authorized` (look at the `captures` array). A close and a refund both arrive as `closed`. If the webhook arrives before the order is saved, return non-200 so Tabby retries. A full capture auto-closes the payment. — [docs.tabby.ai webhooks](https://docs.tabby.ai/pay-in-4-custom-integration/webhooks); [docs.tabby.ai statuses](https://docs.tabby.ai/pay-in-4-custom-integration/payment-statuses); [Hookdeck guide](https://hookdeck.com/webhooks/platforms/guide-to-tabby-webhooks-features-and-best-practices)
- Refunds: sources conflict on whether CAPI accepts negative values. Pixel Plus sends a positive-value custom `Refund` event and says Meta does not accept negatives. Superwall sends a negative-value custom event. Both agree it should be a **custom** event, never a negative Purchase, and that ROAS and Purchase value in Ads Manager are **not** corrected by it. — [smart-modules Pixel Plus](https://help.smart-modules.com/modules/pixel-plus/refund-event); [Superwall](https://superwall.com/docs/integrations/meta-conversion-api)
- Meta's value-optimization guidance says `value` should correlate with the true business goal. A profit-based "value optimization for profit" integration guide exists. — [Meta value optimization](https://developers.facebook.com/documentation/ads-commerce/conversions-api/guides/value-optimization); [value optimization for profit](https://developers.facebook.com/documentation/ads-commerce/conversions-api/guides/value-optimization-for-profit/integration-guide) (titles and snippets only)

### Inferences
- **`onhold` counted as "placed" deserves review.** If on-hold means cash on delivery or an awaiting-payment state, Purchase is sent before money is certain. For COD (UAE-only here) that may be acceptable as the shop's own definition of a sale, but returned COD parcels then inflate Meta's count. Decide deliberately, and log it so the autopilot can discount it.
- Synchronous sending with no worker is acceptable at this volume. The repo has a breaker and a log table. A retry path for failed rows (re-send within hours, never after about 47 h for Purchase, never after 7 days) is the missing piece. A database-backed retry driven by the existing cron (`schedule:run` has been installed since 11 Oct 2026) needs no queue worker.
- For the autopilot, send a custom `OrderRefunded` / `OrderCancelled` CAPI event (value positive, same order_id) for audiences and diagnostics. The autopilot's spend decisions should use **shop-side net revenue**, never Meta's Purchase value.
- Any change here touches the payment freeze only if it edits `app/Services/Payments/`. Hooking refund events off order-status changes in `MarketingPixelsServiceProvider` avoids that.

### Gaps
- Stripe-specific webhook guidance was not researched separately; payment_intent.succeeded is the standard event (from memory).
- No primary Meta statement on negative values or on refund handling was found.

## 4. CAPI Gateway or partner integration vs direct

### Takeaway
The Gateway (and Meta's newer "Signals Gateway") mirrors what the pixel sees with little effort but little control. It cannot send webhook-time events (BNPL approvals, refunds) or rich hashed customer data the browser never saw. A direct integration, which this repo already has, is the better fit.

### Cited Findings
- The Gateway is low-effort with limited customization. It forwards pixel events, so offline and CRM events need direct API calls. A direct call with hashed email, phone and external_id beats a gateway that forwards only cookies. — [bradfarleigh.com, Oct 2026](https://www.bradfarleigh.com/2026/10/conversions-api-vs-capi-gateway-vs-gtm-server-side/); [advancedtrackingacademy.com](https://advancedtrackingacademy.com/blog/capi-gateway-vs-server-gtm/); [vixi.agency](https://vixi.agency/blog/meta-conversions-api-setup-2026/)

### Inferences
- Keep the direct integration. Adding a Gateway on top would create a second server source and dedup headaches.

### Gaps
- The current state of Meta's in-product "Conversions API setup assistant" and Signals Gateway pricing and hosting could not be verified.

## 5. Aggregated Event Measurement (AEM), the 8-event limit, domain verification

### Takeaway
Multiple 2025–2026 secondary sources say Meta **removed the 8-event cap and manual event prioritization for web in about June 2025**, along with the AEM tab in Events Manager. The model may persist for iOS app campaigns. No primary Meta page confirming this was readable here.

### Cited Findings
- June 2025: the 8-event cap was removed, manual prioritization ended, and the AEM configuration tab was deleted from Events Manager. — [segwise.ai](https://segwise.ai/blog/facebook-aggregated-event-measurement); [conversios.io](https://www.conversios.io/blog/meta-aggregated-event-measurement/)
- Jon Loomer: "You no longer need to prioritize eight conversion events per domain for web conversion optimization." — [Jon Loomer pubcast](https://pubcast.jonloomer.com/videos/the-changes-to-aggregated-event-measurement-and-meta-conversion-campaigns/)
- Scope: mainly web. The 8-event model may still apply to iOS app campaigns and to accounts not yet migrated. — [segwise.ai](https://segwise.ai/blog/facebook-aggregated-event-measurement)
- Conflict: DEPT dates the announcement to May. TrackParity notes Meta never clearly documented the change on a primary help page, and that some 2026 guides still describe the limit as active. It recommends checking your own Events Manager for an AEM screen. — [DEPT](https://www.deptagency.com/en-dk/insight/metas-removal-of-aggregated-event-measurement-aem-and-its-implications-for-advertisers/); [trackparity.com](https://www.trackparity.com/blog/meta-aggregated-event-measurement-aem-shopify/)

### Inferences
- The autopilot can optimize on Purchase and track the full funnel without slot management. Confirm by checking that no "Aggregated Event Measurement" tab appears in the shop's Events Manager.

### Gaps
- Whether **domain verification** is still required for anything after AEM's removal (link-editing permissions and branded content still used it historically). No 2026 source was found. Verifying kbeautybliss.com in Business Manager is cheap and recommended regardless; this is an inference, not sourced.

## 6. Product catalog

### Takeaway
A scheduled XML/CSV/TSV feed works. Core fields are id, title, description, availability, condition, price (with currency, e.g. "120.00 AED"), link, image_link and brand, plus gtin or mpn. Catalog ids must equal pixel and CAPI `content_ids`. For Arabic, use a **language override feed** (title, description, link) keyed by the same ids, with prices only in the main or country feed, and a single currency (AED).

### Cited Findings
- Required for ads and commerce: id, title, description, availability, condition, price, link, image_link, brand. Price needs a currency code. condition is new, refurbished or used. Items with missing or malformed required fields may not upload. — [Meta Catalog Fields (snippet)](https://developers.facebook.com/docs/commerce-platform/catalog/fields/); [webappick.com 2026](https://webappick.com/facebook-product-feed-template-ultimate-guide/)
- Brand is effectively required together with gtin or mpn; Meta's best practice is "gtin or mpn plus brand". — [Meta catalog best practices](https://developers.facebook.com/docs/marketing-api/catalog/best-practices); [zeotap](https://composable-docs.zeotap.com/destinations/advertising/meta-catalog)
- Override feeds: the override value is a supported ISO language code or ISO country code. Price, sale_price, availability and status go **only** in a country feed, never in a language feed. Each item id must exactly match the main feed. The main feed leaves the override column empty. Meta recommends only one currency per catalog. — [Meta: Advertising in multiple languages](https://en-gb.facebook.com/business/help/1852644738394400); [feedr help](https://help.feedr.com/meta-catalog/advanced/how-to-work-with-localization-feeds); [adtribes.io](https://adtribes.io/knowledge-base/how-to-create-facebook-country-and-language-override-feeds/)
- Language code format is described as `language_country` (e.g. `ar_AE`), but no source explicitly confirmed `ar_AE` as accepted. — [adtribes.io](https://adtribes.io/knowledge-base/how-to-create-facebook-country-and-language-override-feeds/)

### Inferences
- The repo's `/feeds/meta-catalog.xml` plus `CatalogIds` already solve the id-matching requirement. Gaps to check: an Arabic language override feed generated from the existing `/ar` pages (title, description, link to `/ar/...`), and brand plus gtin coverage.
- Scheduled fetch (hourly or daily) is sufficient at this catalog size. The Catalog Batch API is useful only for near-real-time stock changes, and it would need a stored catalog token.
- Product sets (by brand, category, price, custom_label_0–4) are what the autopilot should target. Populate custom_labels with margin band and bestseller flags from shop data.

### Gaps
- The current official field table, Advantage+ catalog ads requirements, and Catalog Batch API limits were not readable on Meta's pages.

## 7. Attribution settings and reconciliation

### Takeaway
In 2026, Meta restructured attribution. **7-day and 28-day view windows stopped returning in the Ads Insights API on 12 Jan 2026.** Click-through was narrowed to link clicks (around March 2026), and likes, shares, saves and 5 s+ video views moved to a new **1-day "engage-through"** bucket. The reported default for web sales is now 7-day click + 1-day engage-through + 1-day view. An **incremental attribution** option (rolled out from about April 2025) can both report and optimize for modeled incremental conversions. Meta-reported purchases will exceed true incremental sales, so the autopilot must reconcile against the shop's orders.

### Cited Findings
- From 12 Jan 2026, the Ads Insights API no longer returns the `7d_view` and `28d_view` windows. 1-day view, and 1-, 7- and 28-day click remain. — [dataslayer.ai](https://www.dataslayer.ai/blog/meta-ads-attribution-window-removed-january-2026); [jetfuel.agency](https://jetfuel.agency/meta-ads-attribution-settings-2026/)
- Click-through narrowed to link clicks only. Likes, shares, saves, comments, profile visits and 5 s+ video views move to "engage-through", which has a 1-day window. Date conflict: 3 Mar 2026 vs "late March". — [dataslayer.ai](https://www.dataslayer.ai/blog/meta-attribution-change-2026-what-engage-through-attribution-is-and-why-your-numbers-look-different); [adriselab.com](https://adriselab.com/blog/meta-attribution-engage-through-click-through-2026)
- The current default for website conversions is reported as 7-day click, 1-day engage-through, 1-day view. Older guides still say 7-day click + 1-day view. — [karb.ai](https://karb.ai/blog/meta-ads-attribution-settings); [zentric.digital](https://www.zentric.digital/insights/meta-ads-attribution-settings-guide)
- Changing the attribution setting mid-campaign can reset learning. Choose it at campaign creation. — [enrichlabs.ai](https://www.enrichlabs.ai/blog/meta-ads-attribution-settings-best-practices)
- Incremental attribution: rollout reported for April 2025, with data retroactive to 1 Apr 2025 (other sources say a 2024 announcement on 14 Aug 2024). It is selectable as an optimization model for Sales, Leads and Engagement. In reporting-only mode it is under Columns → Compare Attribution Settings → Incremental. Bid controls are not allowed with it. CPA and ROAS targets must be re-based. Availability depends on the account. — [jonathansnow.com](https://www.blog.jonathansnow.com/p/meta-s-launch-of-incremental-attribution-optimize-measure-2025); [adsuploader.com](https://adsuploader.com/blog/meta-incremental-attribution); [stellaheystella.com](https://www.stellaheystella.com/blog/metas-new-incremental-attribution-model-a-step-forward-or-a-step-back)
- One independent comparison found a 20-point gap between Meta's self-reported incrementality and GA4. — [stellaheystella.com](https://www.stellaheystella.com/blog/metas-new-incremental-attribution-model-a-step-forward-or-a-step-back)

### Inferences
- Reconciliation design: store on each order the landing UTMs, `fbclid` (the `_fbc` cookie the repo already captures at checkout through `captureContext`), and the CAPI event_id. Each day, pull Meta's `actions` and `action_values` for purchases at ad level and compare them with the shop's orders that carry fbc or utm_source=facebook|instagram. Report three numbers: Meta-claimed, shop-matched (fbc or UTM), and net of refunds and cancellations. The autopilot should optimize budget on shop-matched net revenue, with Meta's number as a ceiling.
- Use 7-day click (no view) for reporting to the owner. Consider incremental attribution for prospecting campaigns if the account has it.

### Gaps
- Meta's own help-center text on engage-through and the January 2026 window removal could not be read. All of it comes from secondary sources.

## 8. UAE PDPL and consent

### Takeaway
The PDPL (Federal Decree-Law 45/2021) has been in force since 2 Jan 2022, but its **Executive Regulations still appear unissued as of late 2026**, with no adequacy list and no transfer clauses. Article 4 makes consent the default basis, with listed exceptions. Articles 22–23 restrict cross-border transfers. Sending hashed customer data to Meta, a transfer outside the UAE for advertising, is safest on explicit consent, with a cookie/consent banner gating the pixel and the CAPI user_data. **This is not legal advice; it needs a lawyer's check.**

### Cited Findings
- PDPL in force since 2 Jan 2022. Executive Regulations unissued as of 2026. On 14 Jun 2026 the Cabinet created a Federal Authority for AI and Data that absorbs the Emirates Data Office and is expected to finalize the regulations. — [businessdubai.ae](https://www.businessdubai.ae/blogs/uae-pdpl-data-protection); [business-ciso.com](https://business-ciso.com/blog/uae-pdpl-executive-regulations-status)
- Conflict: two lower-quality pages claim the regulations were issued in 2026, with a 1 Jan 2027 deadline, but they cite no Gazette instrument. Treat that as unverified. — [business-ciso.com](https://business-ciso.com/blog/uae-pdpl-executive-regulations-status) (which flags the false claims)
- As of Feb 2026, no adequacy list and no federal standard contractual clauses had been published. — [business-ciso.com](https://business-ciso.com/blog/uae-pdpl-executive-regulations-status)
- Article 4: processing requires consent unless an exception applies (contract, legal obligation, vital interests, public interest, and others). Valid consent is specific, unambiguous, informed and freely given (Article 6). Withdrawal ends consent-based processing. — [uaelegislation.gov.ae (official text)](https://uaelegislation.gov.ae/en/legislations/1972/download); [modulos.ai](https://docs.modulos.ai/frameworks/uae-pdpl/lawful-processing-and-data-subject-rights)
- Article 22 allows transfers to adequate countries approved by the Data Office. Article 23 allows transfers elsewhere only in specified cases, and there is no contractual-safeguard route. — [Bird & Bird](https://www.twobirds.com/en/insights/2021/uae/how-does-the-new-uae-federal-decree-law-on-personal-data-protection-compare-against-the-gdpr); [trade.gov](https://www.trade.gov/market-intelligence/united-arab-emirates-allows-cross-border-data-flows-personal-data)

### Inferences
- Practical stance: show a consent banner, and load the pixel and send CAPI user_data (em, ph, fn, ln, external_id) only for shoppers who consented to marketing measurement. For non-consented shoppers, either send nothing or send only the minimum event without identifiers, as counsel decides. Disclose Meta in the privacy policy. Hashing reduces exposure, but hashed email is still personal data in Meta's hands.
- Because the regulations are pending, build the consent gate now, as a configurable switch, so that tightening later costs no rework.

### Gaps
- No official UAE source gives cookie-specific or direct-marketing rules. Whether Meta's `data_processing_options` (LDU) has any UAE use is unknown; it is a US-state mechanism, from memory.
