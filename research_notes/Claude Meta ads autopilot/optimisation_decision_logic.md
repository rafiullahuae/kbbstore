# Meta ads optimiser decision logic for a small UAE K-beauty shop (as of 20 Nov 2026)

**Method and access limits, read first.** The session's network refused direct fetches of facebook.com (Business Help Center), engineering.fb.com and jonloomer.com (DNS refused, 20 Nov 2026). Everything below comes from web-search result summaries, which quote those pages and secondary sources. Where a figure is "Meta-official", it means a Meta page quoted by a secondary source, unless the URL is a Meta domain. **Re-verify every Meta threshold against the Help Center before it becomes hard-coded in the agent.** I label each finding with a tag: [META] for Meta's own statement, [META-via-secondary] for a Meta claim relayed by someone else, [PRACTITIONER] for an agency, vendor or blog heuristic, [INFERENCE] for my own reasoning or arithmetic.

## 1. Learning phase, significant edits, consolidation, Advantage+ and broad targeting

### Takeaway
Meta's own rule (quoted by secondary sources) is that an ad set leaves learning after "about 50 results in the week after the ad set's last significant edit". Adding an ad counts as a significant edit, and so does a "large" budget change. Meta has never published a percentage for "large". The widely quoted "20%" is a practitioner rule of thumb. Meta's late-2026 guidance pushes hard toward consolidation, sizing the number of ad sets as weekly budget ÷ (CPA × 50). For a small shop, the consequence is one or very few ad sets.

### Cited Findings
- [META-via-secondary] Ad sets exit learning "after about 50 results in the week after the ad set's last significant edit." Source: [Wordstream](https://wordstream.com/blog/facebook-learning-phase); [Madgicx glossary](https://madgicx.com/glossary/learning-phase).
- [META-via-secondary] Meta's "Significant Edits and the Learning Phase" article lists "Adding a new ad to your ad set" as a significant edit. It is vague on budget size. Loomer's paraphrase: $100→$101 is unlikely to reset learning, but $100→$1000 may. He also notes the article is years old and may be outdated. Source: [Jon Loomer – New ads and the learning phase](https://www.jonloomer.com/qvt/new-ads-learning-phase/); [Jon Loomer – Updates to Meta Ads budgeting](https://www.jonloomer.com/updates-to-meta-ads-budgeting/).
- [PRACTITIONER] Budget-change thresholds conflict. "Under 30% is safe" (one source) and ">20% in a day can trigger learning" (others) are both unofficial. Source: [Soundlink help](https://intercom.help/Soundlink-Help-Center/en/articles/15799519); [Platzi course](https://platzi.com/cursos/meta-ads-2025/safe-meta-ads-changes-that-skip-relearni/).
- [PRACTITIONER, unresolved] Loomer is unsure whether a *scheduled* budget increase triggers re-learning. Source: [Jon Loomer](https://www.jonloomer.com/updates-to-meta-ads-budgeting/).
- [META-via-secondary, dated 21 Oct 2026] In a peak-season best-practice post, Meta reportedly said ad sets with fewer than ~50 conversions a week cost about **28% more per purchase**. The post gave a sizing formula: maximum ad sets = total weekly budget ÷ (cost per result × 50). Caveats: the claim reportedly came with no sample size, period, region, vertical or budget control, and smaller ad sets may be dearer for reasons other than learning (selection effect). Source: [Relevant Audience, 2026](https://www.relevantaudience.com/meta/meta-50-conversions-peak-season-brief/); [ppc.land, 25 Oct 2026](https://ppc.land/meta-says-ad-sets-under-50-weekly-conversions-cost-28-more-per-purchase/).
- [META-via-secondary, c.2022–2023] "Performance 5" makes account simplification the foundational element because "this streamlines the ad learning phase". Meta reportedly called simplification "the single most important lever to driving better CPAs". Source: [Social Media Today](https://www.socialmediatoday.com/news/Meta-Performance-5-Improve-Ad-Performance-Amid-Privacy-Changes/634750/); [Tiger Pistol](https://tigerpistol.com/metas-performance-5-framework-the-impact-of-simplifying-your-account-structure/).
- [UNVERIFIED Meta-attributed stat] Advertisers who kept less than 20% of spend in learning reportedly saw 68% lower CPAs than those with more than 50% of spend in learning. I could not trace this to a Meta primary source. Source: [podscripts – Today in Digital Marketing](https://podscripts.co/podcasts/today-in-digital-marketing/bonus-metas-revised-performance-5-framework).
- [META-via-secondary] Meta is further consolidating manual targeting options. Source: [MediaPost](https://www.mediapost.com/publications/article/408262/meta-further-consolidates-ad-targeting-options.html).
- [PRACTITIONER] "Broad targeting now produces better results than more refined, niche audience approaches." Source: [Social Media Today](https://www.socialmediatoday.com/news/meta-new-tips-maximize-ad-campaign-performance/652231/).
- [META, Jun 2025] The "Advantage+ campaign experience for sales" replaces the separate ASC API. The ASC API, including `existing_customer_budget_percentage`, is deprecated with Marketing API v25.0, and the field "is not available for new Advantage+ campaigns". Source: [Meta for Developers blog, 3 Jun 2025](https://developers.facebook.com/blog/post/2025/06/03/advantage-plus-campaign-experience-for-sales-and-app/); [Meta Marketing API – Advantage+ campaigns](https://developers.facebook.com/docs/marketing-api/advantage-campaigns).

### Inferences
- [INFERENCE] Take a small UAE shop at an assumed AED 150 CPA. Fifty purchases a week costs about AED 7,500 a week (~AED 1,070 a day) for a single ad set. Below that spend, the account will be "learning limited" whatever the agent does. The rational structure is therefore **one Advantage+ sales campaign with one ad set** (plus at most a separate existing-customer ad set if retargeting is wanted). The agent should not chase "exit learning" as a goal at small budgets. It should minimise resets.
- [INFERENCE] Treat every ad addition and every budget move as a potential reset. Batch creative additions (for example weekly, not daily), and never change budget and creatives on the same day.
- [INFERENCE] Where weekly purchase volume is far below 50, optimising for a higher-funnel event (such as Add to Cart) is a theoretical option. No source here tests whether it improves profitable orders, so the agent should not do this without a test.

### Gaps
- Exact current wording of Meta's "learning limited" definition and significant-edits list. I could not fetch it (facebook.com blocked).
- No Meta-published percentage threshold for budget changes. "20%" is folklore.
- The primary Meta post from 21 Oct 2026 was not retrieved.

## 2. Andromeda, creative diversification and the number of ads

### Takeaway
Andromeda (Meta engineering, 2 Dec 2024) is the retrieval stage that narrows tens of millions of ads to a few thousand candidates per request, with Meta-reported gains of +6% recall and +8% ads quality. Since 2025, Meta's advertiser messaging says creative diversification, not targeting, is the main lever for reaching relevant audiences. Recommended ad counts per ad set conflict between sources.

### Cited Findings
- [META] Andromeda is the retrieval stage. It selects "from tens of millions of ad candidates into a few thousand relevant ad candidates" and uses hierarchical indexing "to support exponential ad creatives growth from Advantage+ creative". Deployment across Instagram and Facebook achieved "+6% recall improvement … +8% ads quality improvement on selected segments", and inference QPS rose by more than 3x. These are Meta's own claims with no independent evaluation. Source: [Meta Engineering, 2 Dec 2024](https://engineering.fb.com/2024/12/02/production-engineering/meta-andromeda-advantage-automation-next-gen-personalized-ads-retrieval-engine/); [MediaPost](https://www.mediapost.com/publications/article/401591/meta-andromeda-ad-retrieval.html).
- [META-via-secondary, Apr 2025] "The focus has shifted from niche targeting to creative diversification as the best lever to find the most relevant audiences." Source: [Jon Loomer – Meta Andromeda](https://www.jonloomer.com/meta-andromeda/).
- [META-via-secondary, Dec 2025] Meta called creative diversification a "foundational best practice" and urged variation in formats, messages and personas. Source: [ppc.land](https://ppc.land/andromeda/).
- [PRACTITIONER, Loomer] Diversification means ads aimed at different pain points and personas, "not … near-duplicates with minor tweaks". Andromeda "doesn't rank the winning ads, it picks the short list". Source: [Jon Loomer](https://www.jonloomer.com/meta-andromeda/).
- [CONFLICTING] Ads per ad set: one source cites a Help Center hard cap of 50. Others cite Meta advice of "no more than five" or "six" ads per ad set for optimal delivery, not applying to Advantage+ shopping. Practitioners suggest 8–15+ meaningfully different variations, or 15–30 creatives grouped by theme in Advantage+. Source: [AdManage](https://admanage.ai/blog/how-many-facebook-ads-should-you-run-at-once); [Metricool 2026](https://metricool.com/how-many-ads-should-you-run/); [Search Engine Land](https://searchengineland.com/meta-ads-creative-diversity-485751).

### Inferences
- [INFERENCE] For an AI agent, "creative diversity" should be a measurable input. Tag each ad by persona (for example acne-prone teen, 30+ anti-ageing, hijabi daily routine), by pain point, by format (UGC video, static, carousel, catalog) and by language (EN/AR). Before adding a new ad, the agent should prefer one that fills an empty cell over a variant of an existing winner.
- [INFERENCE] With small budgets, 50 ads in one ad set means most ads get almost no spend. A pool of about 6–15 genuinely distinct concepts is a reasonable compromise between the conflicting sources. Treat that number as a heuristic, not a Meta rule.

### Gaps
- No Meta source found on GEM (the generative ads model) specifically. Not retrieved.
- No published evidence, Meta or independent, comparing UGC with studio creative for beauty.

## 3. Budget scaling and bidding

### Takeaway
Meta officially confirms only that "large" budget changes can re-trigger learning. Every percentage rule (+20% a day, +20–30% every 2–3 days) is practitioner folklore. Horizontal scaling (duplicating ad sets) conflicts with Meta's consolidation guidance and fragments the 50-event signal.

### Cited Findings
- [META-via-secondary] Budget-size dependence of re-learning, with no number given. Source: [Jon Loomer](https://www.jonloomer.com/updates-to-meta-ads-budgeting/).
- [PRACTITIONER] "Scale gradually, avoid simultaneous creative and budget edits." Source: [Metricool 2026](https://metricool.com/how-many-ads-should-you-run/).
- [META-via-secondary] The ad-set sizing formula (weekly budget ÷ (CPA × 50)) implies that horizontal splitting lowers conversions per ad set. Source: [Relevant Audience](https://www.relevantaudience.com/meta/meta-50-conversions-peak-season-brief/).

### Inferences
- [INFERENCE] Suggested agent rule: scale vertically in steps of no more than 20% at least 72 hours apart, and only when the trailing 7-day (attribution-settled) CPA beats target by a margin (for example 15% or more) and the result is statistically credible (see §5). Cut faster than you scale. Label this rule as a heuristic.
- [INFERENCE] Cost-cap or ROAS-goal bidding can enforce break-even ROAS automatically, but at low volume it tends to under-deliver. Start on highest-volume bidding with a hard daily cap, and consider cost cap only once weekly purchases are stable.

### Gaps
- I could not verify Meta's current daily-budget overspend allowance or how budget scheduling behaves (jonloomer.com and facebook.com were blocked).

## 4. Creative testing and fatigue signals

### Takeaway
Meta's built-in "creative fatigue" label only covers ad sets with one creative. It flags when cost per result is at least 2x what similar past ads achieved. Frequency thresholds are practitioner rules (about 1–3 per week for prospecting, or 3–4 cumulative) and they disagree.

### Cited Findings
- [META-via-secondary] Meta's creative fatigue diagnostic "is only available for ad sets with one creative". It excludes Advantage+ catalog ads, dynamic creative and Advantage+ app campaigns, and flags when cost per result is at least 2x that of past ads. Source: [Jon Loomer – Creative fatigue](https://www.jonloomer.com/creative-fatigue-meta-ads/); [Segwise](https://segwise.ai/blog/meta-ads-creative-fatigue).
- [PRACTITIONER] Frequency thresholds for prospecting: 3.0–4.0 (one source) versus a 1–3 per week "sweet spot" (another). Source: [Segwise](https://segwise.ai/blog/meta-ads-creative-fatigue); [Metricool](https://metricool.com/how-many-ads-should-you-run/).
- [PRACTITIONER] A portfolio of ads means each one is seen less often, which slows fatigue. Source: [AdManage](https://admanage.ai/blog/how-many-facebook-ads-should-you-run-at-once).
- [PRACTITIONER] "Don't wait for statistical significance, it's going to limit how fast you can move" (subscription-app testing). This is the opposite of a stats-driven approach. Source: [RevenueCat](https://www.revenuecat.com/blog/growth/7-meta-ad-testing-frameworks-for-subscription-apps/).

### Inferences
- [INFERENCE] Suggested fatigue trigger: an ad's 7-day CTR (link) is at least 30% below its own first-14-day CTR, **and** CPM or CPA is rising, **and** frequency is at least 2.5 over 7 days. Require at least two of the three signals before acting. Signals should be measured against the ad's own history, not against industry benchmarks.
- [INFERENCE] For a small budget, test creatives inside the main campaign rather than in a separate testing campaign. A separate campaign splits the already-small event volume. Accept that Meta's spend allocation across ads is not a fair A/B test.

### Gaps
- No sourced hook-rate or thumb-stop benchmarks (3-second views ÷ impressions) were found this session.
- Not retrieved: Foxwell or Common Thread Collective testing frameworks.

## 5. Kill and keep thresholds, statistics and attribution delay

### Takeaway
The most common practitioner kill rule is: pause when spend reaches 2x target CPA (some use 3x) with zero purchases. Meta's 7-day-click window means a purchase can be credited up to 7 days after the click, back to the day of the impression or click, so "today's" ROAS is understated. Since 12 Jan 2026, Meta no longer returns the 7-day-view and 28-day-view windows in the API.

### Cited Findings
- [PRACTITIONER] Don't judge an ad until it has spent at least 2x the target cost per result. Kill rule: IF spend > 2x target CPA AND conversions = 0 THEN pause. Some use 3x. Source: [Coinis](https://coinis.com/blog/when-to-kill-meta-ads-decision-framework); [LeadEnforce](https://leadenforce.com/blog/when-to-pause-kill-or-scale-an-ad-set); [AdManage](https://admanage.ai/blog/when-to-kill-a-facebook-ad).
- [PRACTITIONER] A reliable read needs about 50 conversions per variant for statistical confidence, which most budgets cannot wait for. Source: [Finsi](https://finsi.ai/blog/creative-testing-framework-meta/).
- [META] Automated rules only apply to active ads. Source: [AdManage](https://admanage.ai/blog/when-to-kill-a-facebook-ad).
- [META, announced 16 Oct 2025, effective 12 Jan 2026] The Ads Insights API stopped returning `7d_view` and `28d_view` (and combinations such as 7d click + 28d view). Still available: 1d click, 7d click, 28d click, 1d engaged view, 1d view. Source: [Meta for Developers blog, 16 Oct 2025](https://developers.facebook.com/blog/post/2025/10/16/ads-insights-api-metric-availability-updates/); [ppc.land](https://ppc.land/meta-restricts-attribution-windows-and-data-retention-in-ads-insights-api/); [Supermetrics](https://docs.supermetrics.com/docs/facebook-ads-new-historical-limitations-attribution-window-and-metric-removals-january-12-2026).
- [PRACTITIONER, unverified] Reported conversions fell 10–25% (another vendor says 15–40%) on accounts reporting with the removed windows. Source: [Dataslayer](https://www.dataslayer.ai/blog/meta-ads-attribution-window-removed-january-2026); [Conversios](https://www.conversios.io/blog/meta-attribution-window-changes-2026-fix-your-tracking/).
- [CONFLICT] The default attribution setting is 7-day click + 1-day view per Madgicx. Omega's help page says the default shrank from 28-day click + 7-day view. Source: [Madgicx](https://madgicx.com/glossary/attribution-window); [Omega help](https://help.omegatheme.com/en/article/m2fcjq/).

### Inferences
- [INFERENCE: statistics the agent should use]
  - Model each ad's CPA with a Gamma–Poisson posterior: purchases ~ Poisson(spend/CPA). With a weak prior anchored on the account's CPA, compute P(CPA < break-even CPA).
  - Model CVR with a Beta–Binomial posterior: Beta(α+conversions, β+clicks−conversions).
  - Act only when the posterior probability is at least 0.8 (keep or scale) or no more than 0.2 (kill).
  - The zero-conversion kill rule is a special case. With 0 purchases after spending k×CPA, the Poisson probability of seeing 0 purchases if the true CPA equals target is e^(−k): about 13.5% at 2x and about 5% at 3x. So the 2x rule is wrong about 1 time in 7 for a truly on-target ad, and 3x is the "95%" version.
- [INFERENCE] Never evaluate on the current day or the last 2 days. Use a trailing 7-day window that ends at least 3 days ago, or the 7d-click total for cohorts at least 7 days old. Compare against the same window in the shop's own order data.
- [INFERENCE] To avoid day-to-day noise, run at most one decision cycle a day, enforce a 72-hour cooldown per object after any change, and cap the number of changes per week.

### Gaps
- No Meta-official statement found on how long conversion reporting lags (hours to days). The 7-day click window defines the maximum credit lag, not the processing lag.

## 6. Unit economics, MER and incrementality

### Takeaway
Break-even ROAS follows from unit economics (see the formula below). UAE payment-fee data is poor. Stripe UAE charges an extra 1% on international cards. Third-party estimates put Tabby at 2.79–8% (conflicting), and no reliable Tamara figure was found. Meta Conversion Lift reportedly needs about USD 5,000 of spend in the past year and access through a Meta rep. GeoLift needs a power analysis and many comparable geos, which a UAE-only shop hardly has.

### Cited Findings
- [STRIPE] Stripe UAE pricing applies a 1% surcharge for international cards. The base domestic rate could not be read from the result (the page was in Swedish). Source: [Stripe support](https://support.stripe.com/questions/how-much-does-stripe-cost-in-the-uae).
- [THIRD-PARTY, conflicting] Tabby merchant fee estimates:
  - 2.79–5.99% ([paymentproviders.io](https://paymentproviders.io/compare/tabby-vs-stripe))
  - 5–8% ([checkoutflow](https://checkoutflow.pages.dev/ae-en/best-payment-gateway-ecommerce/tabby-guide-sellers))
  - 5–6% ([checkoutflow Tap page](https://checkoutflow.pages.dev/ae-en/best-payment-gateway-ecommerce/tap-payments-uae/))
  - about 3–4% take rate ([Sacra](https://sacra.com/chat/h/64e9930b-990b-458c-9cb8-c06ac23ee37a/))
  - a percentage plus 1 AED/SAR fixed ([Scribd copy of a Tabby document](https://www.scribd.com/document/704132578/Tabby-18))
- [THIRD-PARTY] Meta Conversion Lift reportedly needs USD 5,000 or more spent in the past year. Conversion requirements conflict (500 optimised conversions versus 100 a week). Meta's own docs say access is "currently limited. Please contact your Meta Representative." Source: [Madgicx](https://madgicx.com/blog/meta-incrementality-testing); [Triple Whale KB](https://kb.triplewhale.com/en/articles/10605805-meta-conversion-lift-experiment); [Meta Lift Studies API](https://developers.facebook.com/docs/marketing-api/guides/lift-studies).
- [META open source] GeoLift computes the minimum investment and minimum detectable effect per design. Guidance: at least 25 pre-treatment periods (minimum 10) and ideally 20+ geo units. "Inverse GeoLift" withholds ads from the treatment geo, which shrinks the holdout cost. Source: [GeoLift walkthrough](https://facebookincubator.github.io/GeoLift/docs/GettingStarted/Walkthrough); [Inverse GeoLift](https://facebookincubator.github.io/GeoLift/blog/inverse-geolift).
- [THIRD-PARTY, unverified] About USD 500k a year in ad spend is a practical threshold for geo holdouts at 80% power. Source: [madgicx / segmentstream discussions](https://segmentstream.com/blog/articles/misuse-geo-holdout-tests-guide-non-technical-leaders).

### Inferences
- [INFERENCE: formulas]
  - Contribution per order before ads = AOV_exVAT × gross margin − shipping cost − payment fee% × AOV_incVAT − fixed payment fee − expected return/refund cost.
  - Break-even CPA = that contribution. Break-even ROAS = AOV ÷ break-even CPA, using the same VAT basis on both sides. Meta's reported purchase value includes whatever the pixel sends, so the agent must know whether the pixel value includes VAT (5% in the UAE) and shipping.
  - Target CPA = break-even CPA × (1 − desired margin share). If repeat purchase is material, use a first-order CPA ceiling informed by 90-day LTV, and only with the owner's sign-off.
  - Use a **weighted payment fee** by the shop's actual method mix (card / Tabby / Tamara / COD). Use the real contract rates from the shop's own invoices, not the web estimates above.
- [INFERENCE] Primary health metric is MER (total revenue ÷ total ad spend, from the shop database) plus new-customer counts. Treat platform ROAS as a within-Meta ranking signal, not as truth.
- [INFERENCE] For a UAE-only small shop, formal incrementality testing is likely infeasible. A pragmatic substitute is a pre-registered on/off or budget-step test, such as 2 weeks at 50% budget compared with matched weeks, read on MER and new-customer orders, with seasonality caveats.

### Gaps
- No official Tabby or Tamara UAE fee pages were retrieved. Tamara fees were not found at all.
- I found no source on beauty return rates in the UAE.

## 7. Funnel, retargeting and new versus returning customers

### Takeaway
The existing-customer budget cap is gone from new Advantage+ sales campaigns. Meta's documented replacement is to create two ad sets, one targeting an existing-customer custom audience and one excluding it. Reporting still splits results into New / Engaged / Existing segments, defined in advertiser settings with custom audiences.

### Cited Findings
- [META] The `existing_customer_budget_percentage` field is not available for new Advantage+ campaigns and is deprecated with the ASC API (v25.0). Meta's guidance is to "create two ad sets per ad campaign that separate existing customers from new customers" using a custom audience ID. Source: [Meta Marketing API – Advantage+ campaigns](https://developers.facebook.com/docs/marketing-api/advantage-campaigns); [Meta dev blog, Jun 2025](https://developers.facebook.com/blog/post/2025/06/03/advantage-plus-campaign-experience-for-sales-and-app/).
- [PRACTITIONER] An ad set excluding existing customers is equivalent to an ASC with a 0% existing-customer cap. Reporting shows three rows (Engaged Audience, Existing Customers, New Audience), and a person in both Engaged and Existing counts as Existing. Source: [Jon Loomer – No more existing customer budget cap](https://www.jonloomer.com/qvt/no-more-existing-customer-budget-cap/); [Jon Loomer – Audience segments](https://www.jonloomer.com/audience-segments-/).

### Inferences
- [INFERENCE] The agent should keep the existing-customer list (a hashed customer upload from the shop database) refreshed at least weekly. The orders table is the source of truth for "returning". Watch the Existing Customers share of spend: if it climbs, reported ROAS inflates while incremental value falls.

### Gaps
- The lookback window for "Engaged audience" was not found.
- Current Advantage+ catalog ads best practices were not retrieved.

## 8. Dayparting and UAE seasonality

### Takeaway
Meta's developer docs say ad scheduling works only with lifetime budgets. One secondary source claims a daily budget now works too, which is unconfirmed. I found no controlled evidence that dayparting improves Meta results. In 2027, Ramadan is expected around 8 Feb and Eid al-Fitr around 9 Mar, both subject to moon sighting.

### Cited Findings
- [META] "Only use ad scheduling with lifetime budgets." Source: [Meta Marketing API – Pacing and scheduling](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/overview/pacing-and-scheduling); [Meta dev blog, 13 Aug 2014](https://developers.facebook.com/ads/blog/post/2014/08/13/ad-scheduling). Conflicting claim (daily budget allowed): [Madgicx](https://madgicx.com/blog/meta-campaign-scheduling).
- [PRACTITIONER] A lifetime budget needs an end date. A campaign cannot be switched from daily to lifetime after publishing (WordStream, 2019). Source: [Madgicx](https://madgicx.com/answers/how-to-schedule-ads-in-meta-ads-manager); [WordStream 2019](https://www.wordstream.com/blog/ws/2019/09/10/common-facebook-ads-issues).
- [NO EVIDENCE] I found no controlled study showing dayparting lift on Meta. Source: search across [Power Digital](https://powerdigitalmarketing.com/blog/dayparting-on-facebook-spending-when-it-counts/), [Coinis](https://coinis.com/how-to/dayparting-facebook-ads) and others.
- [NEWS] Expected 2027 dates:
  - Ramadan starts about Mon 8 Feb 2027 (crescent sighting 7 Feb)
  - Eid al-Fitr about Tue 9 Mar 2027
  - Eid al-Adha about Sat 14 Aug 2027
  - National Day holiday about 2–3 Dec 2027

  Source: [Khaleej Times](https://www.khaleejtimes.com/uae/ramadan-eid-al-fitr-eid-al-adha-2027-likely-dates); [The National, Sep 2026](https://www.thenationalnews.com/news/uae/2026/09/09/ramadan-2027-dates-eid-al-fitr-holiday/); [Gulf News](https://gulfnews.com/living-in-uae/ask-us/ramadan-2027-eid-al-fitr-eid-al-adha-uae-public-holidays-1.500712923).
- [VENDOR] UAE CPMs rise during Dubai Shopping Festival (mid-Dec to mid-Jan) and sharply in Ramadan and Eid, notably for beauty. Source: [SuperAds UAE CPM](https://www.superads.ai/facebook-ads-costs/cpm-cost-per-mille/united-arab-emirates).

### Inferences
- [INFERENCE] Do not daypart. It forces lifetime budgets, there is no evidence of benefit, and small accounts lose delivery volume. Instead, store a seasonality calendar and loosen CPA tolerance (or pre-build creative) for peak windows, using the shop's own prior-year data.
- [INFERENCE] Many UAE salaries are paid around month end. A payday effect is plausible, but I found no source for it.

### Gaps
- White Friday 2026 and DSF 2026–27 dates were not retrieved. No sourced payday-cycle evidence for the UAE.

## 9. Benchmarks for UAE beauty

### Takeaway
I found no credible UAE- or GCC-specific beauty benchmarks for CPC, CVR or CPA. A vendor dataset puts UAE CPM at about USD 15.8 (Nov 2025–Oct 2026), roughly 23% below global. Global beauty CPC is about USD 0.74–0.90. The shop's own history should replace these within weeks.

### Cited Findings
- [VENDOR] UAE average CPM USD 15.8 (Nov 2025–Oct 2026) versus a global baseline of USD 20.6. Wellness vertical UAE CPM 13.1 (Oct 2024–Sep 2025). Source: [SuperAds UAE CPM](https://www.superads.ai/facebook-ads-costs/cpm-cost-per-mille/united-arab-emirates); [SuperAds wellness UAE](https://www.superads.ai/facebook-ads-costs/cpm-cost-per-mille/wellness-holistic-health/united-arab-emirates).
- [VENDOR, US/global] Beauty & Personal Care average CPC USD 0.74 in 2025, down 21% versus 2024 ([WordStream 2025](https://wordstream.com/blog/facebook-ads-benchmarks-2025)). Beauty CPC USD 0.90 and general Meta CVR ~1.6% ([ContentStudio 2026](https://contentstudio.io/blog/meta-ads-benchmarks)).

### Inferences
- [INFERENCE] Use benchmarks only as cold-start priors, with low weight. Once the account has about 30 purchases, its own data should dominate.

### Gaps
- No UAE beauty CPA or CVR data from any credible source. A UAE e-commerce cost-per-purchase page exists on SuperAds but was not retrieved.
