# Policy, legal and operational risks of automated Meta ads for a UAE cosmetics/skincare shop (as of 20 Nov 2026)

> Method note (read first): in this session, WebFetch to `transparency.meta.com` and `developers.facebook.com` failed (host not allowed by the session's network policy), so every Meta finding below comes from search-result extracts of those official pages, not full-page reads. Exact wording should be re-checked on the live pages before it is quoted to the owner. Where only blogs/forums support a claim it is labelled as such. Sources are a mix of official (Meta, UAE government, WAM) and law-firm/press.

## 1. Meta Advertising Standards relevant to beauty/skincare (and appeals)

### Takeaway
Skincare ads are mostly rejected for (a) implying the viewer has a condition ("Do you have acne?" = personal attributes), (b) negative self-perception / zoomed-in condition imagery, (c) before/after imagery that shows idealised results, and (d) unsupported health/therapeutic claims; cosmetic-procedure and weight/dietary products must be 18+ targeted. Ordinary skincare is not a Special Ad Category.

### Cited Findings
- Ads "must not contain content that asserts or implies personal attributes", and health conditions (physical or mental) are among the listed attributes — so copy like "Do you have acne?" / "Your dark spots…" is non-compliant — [Meta: Privacy violations and personal attributes](https://transparency.meta.com/policies/ad-standards/objectionable-content/privacy-violations-personal-attributes/); [Meta: personal attributes](https://transparency.meta.com/policies/ad-standards/objectionable-content/personal-attributes)
- Ads must not imply or try to create negative self-perception to sell health products; must not present a body/figure as idealised; must not draw attention to beauty/health conditions — zoomed-in images of acne are given as a not-allowed example — [Meta Business Help: Advertising Policy on Personal Health](https://www.facebook.com/business/help/2489235377779939)
- Cosmetic ads may not contain "statements of inferiority" about physical appearance (negative terms attacking appearance, body parts or hygiene) — [Meta Business Help: Personal Health](https://www.facebook.com/business/help/2489235377779939); [Meta: Health and Wellness](https://transparency.meta.com/policies/ad-standards/restricted-goods-services/health-wellness)
- Before/after: weight-loss before/after images are shown as non-compliant; before/after images may not display idealised results — [Meta: personal health and appearance](https://transparency.meta.com/policies/ad-standards/objectionable-content/personal-health-and-appearance); [Meta Business Help](https://www.facebook.com/business/help/2489235377779939)
- Health & Wellness page lists "general cosmetic products, procedures or surgeries depicting before and after transformation" in the restricted group; ads for cosmetic surgery/procedures and for dietary/health/weight-loss products must target 18+ — [Meta: Health and Wellness](https://transparency.meta.com/policies/ad-standards/restricted-goods-services/health-wellness); [Meta: cosmetic procedures and wellness](https://transparency.meta.com/policies/ad-standards/content-specific-restrictions/cosmetic-procedures-and-wellness). CONFLICT: some marketing blogs describe before/after for cosmetics as banned outright, others as allowed at 18+ — [auditsocials.com (2026 blog)](https://www.auditsocials.com/blog/meta-beauty-cosmetics-ads-2026-before-after-photos-appearance-claims-policy); [accelerateddigitalmedia.com](https://www.accelerateddigitalmedia.com/insights/guide-to-social-media-health-ad-restrictions-2026/). The official locale versions of the page reportedly differ in wording.
- A blog claims a March 2026 reversal allowed cosmetic-surgery ads in US/Canada — unverified against Meta pages and not UAE-relevant — (search summary of [auditsocials.com](https://www.auditsocials.com/blog/meta-beauty-cosmetics-ads-2026-before-after-photos-appearance-claims-policy)).
- Meta's own small-business page on resolving ad rejections and requesting review — [Meta: Resolve your ad rejection](https://www.facebook.com/business/m/small-business/ad-policy-guidance)
- Appeals: rejected ads/restricted assets are reviewed via Account Quality / Business Support Home "Request review"; vendor guides report a limited number of re-requests and that a failed first appeal is not always final — [dicloak guide (vendor)](https://dicloak.com/blog-detail/how-to-recover-a-disabled-meta-ads-account-in-2026-stepbystep-guide); [leadsie (vendor)](https://www.leadsie.com/blog/recover-disabled-facebook-ad-account)

### Inferences
- An AI copywriter is the highest-risk component: second-person condition language ("your acne", "tired of wrinkles?") is precisely what the personal-attributes rule catches. A pre-flight lint (block "you/your" + condition words, "cure/treat/heal/eliminate", "guaranteed", "permanent", before/after) would avoid most rejections.
- Special Ad Categories (housing, employment, credit/financial products, social issues/elections/politics) do not cover skincare retail, so no category declaration is expected — this is from general knowledge, not a source fetched in this session; verify.
- Repeated rejections accumulate on the account's quality record; an agent that auto-resubmits rejected creatives in a loop could worsen account standing (see §2).

### Gaps
- Could not read the full current English Health and Wellness / cosmetic procedures pages (fetch blocked); exact before/after rule for ordinary skincare (not procedures) remains ambiguous.
- No official Meta statement found on how many rejected ads trigger an account restriction (strike thresholds are not published).

## 2. Account integrity: restrictions, spending limits, spend_cap, verification, recovery

### Takeaway
Payment failure pauses ads until the balance is paid; hitting the account spending limit pauses delivery. "Account integrity" restrictions (including for automation that "mimics human activity") are real and appeals can be denied without explanation. Meta publishes no official list of risk triggers for new accounts — most "sudden spike / new account" lore is anecdotal.

### Cited Findings
- When the payment method fails, ads are paused until the outstanding balance is paid; causes listed: insufficient funds, card limit reached, processing/verification issues, wrong bank details. Ads resume after payment — [Meta Business Help: Troubleshoot a disabled ad account due to payment failure](https://facebook.com/business/help/268196136699959); [Meta: Current balance](https://www.facebook.com/business/help/1394774314147262)
- When the account spending limit is reached, ads pause and stop spending; you can reset the amount spent toward the limit, or remove the limit (takes ~15 minutes); spending limits cannot be set on manual-payment / available-funds accounts — [Meta: Reset amount spent](https://www.facebook.com/business/help/1892523930869907); [Meta: Remove account spending limit](https://www.facebook.com/business/help/2135167003399680); [Meta: View spending limit history](https://www.facebook.com/business/help/172343252960227)
- Payment threshold (billing threshold) is adjustable; monthly-invoicing credit lines pause ads when the credit limit is hit — [Meta: Adjust payment threshold](https://www.facebook.com/business/help/1342874532544030); [Meta: Monthly invoicing credit lines](https://www.facebook.com/business/help/183197756325469)
- Meta has a page for unrecognised activity on an ad account (compromise path) — [Meta: Troubleshoot unrecognized activity](https://www.facebook.com/business/help/524424920973484)
- Marketing API: account spending limit (spend_cap / spend_cap_action) may be changed at most 10 times per day; exceeding returns error 613, subcode 1487225 — [Meta: Marketing API rate limiting](https://developers.facebook.com/docs/marketing-api/overview/rate-limiting)
- A Meta developer-community thread (July 2026) reports a Business Portfolio restricted for violating Advertising Standards on account integrity for automation that "mimics human activity"; appeal denied, no cause given; portfolio could no longer create ads or use/share audiences — [Meta developer community thread](https://developers.facebook.com/community/threads/1438234438064193/) (anecdotal, single case)
- Anecdotal: a new page/account can trip an "Account Integrity" flag with no manual-activity history — [ManyChat community post](https://community.manychat.com/general-q-a-43/ads-restricted-10104); new-account "safety cap" spending limits that rise with payment history — [lilachbullock.com (blog)](https://www.lilachbullock.com/why-is-my-facebook-account-restricted-from-advertising/)
- Business verification and restriction appeals are separate; verification doesn't lift a restriction automatically but may be required for recovery — [dicloak (vendor)](https://dicloak.com/blog-detail/how-to-recover-a-disabled-meta-ads-account-in-2026-stepbystep-guide)
- "Circumventing systems" suspensions (e.g., opening new accounts after a ban) are a distinct, severe category — [stubgroup (agency blog)](https://stubgroup.com/blog/meta-circumventing-systems-suspension-what-it-means-and-how-to-address-it/)

### Inferences
- Never let the agent create replacement ad accounts/BMs after a restriction — that pattern is what "circumventing systems" enforcement targets.
- The only official, bounded protection against overspend is the account spending limit (spend_cap); it is a pause, not a refund, and spend can slightly overshoot before delivery stops (general platform behaviour; not verified this session).
- Run the ads through an official Meta app (System User token under a verified Business) with normal, low-frequency writes, rather than browser automation; the July 2026 "mimics human activity" case suggests UI/browser-driving bots are the higher-risk pattern (inference from one anecdote).

### Gaps
- No official Meta document found listing new-account default spending limits or the risk signals ("sudden spend spikes") behind automated restrictions.
- Could not confirm from Meta's own reference (fetch blocked) the semantics of `spend_cap` = 0 (from memory, setting 0 removes the cap — a dangerous foot-gun if true; verify on the AdAccount reference before coding) or `account_status`/`disable_reason` code values.

## 3. Does Meta restrict programmatic/API management? Platform/Marketing API terms

### Takeaway
API use is sanctioned but rate-limited with hard per-object edit caps (ad-set budget 4 changes/hour; spend_cap 10/day); exceeding them blocks changes. Platform Terms require data deletion when no longer needed, breach notification/remediation, and (for higher-risk data) security testing.

### Cited Findings
- Ad set `daily_budget`/`lifetime_budget` may change only 4 times per hour per ad set; beyond that, budget changes are blocked for an hour — error 613, subcode 1487632 ("You can only change your ad set budget 4 times per hour") — [Meta: Marketing API rate limiting](https://developers.facebook.com/docs/marketing-api/overview/rate-limiting)
- Ad-account-level throttle errors 80000/80003/80004/80014 ("too many calls from this ad-account"); error 17 = user request limit reached (subcode 2446079 on Marketing API) — [Meta: Marketing API rate limiting](https://developers.facebook.com/docs/marketing-api/overview/rate-limiting); [Meta: Error codes](https://developers.facebook.com/docs/marketing-api/error-reference/)
- Meta advises scaling back changes when these errors occur — [Meta: rate limiting](https://developers.facebook.com/docs/marketing-api/overview/rate-limiting)
- Platform Terms (2020 update summary): developers must delete Platform Data when no longer needed for a legitimate business purpose, when the product stops operating, when Meta requests it, or when received in error; must notify Meta of data breaches, begin remediation, and cooperate — [Meta developer blog, 1 Jul 2020](https://developers.facebook.com/blog/post/2020/07/01/platform-terms-developer-policies)
- Oct 2024 update removed some duplicative requirements, e.g. the separate "notify Meta within 24 hours" incident clause, as covered elsewhere — [Meta developer blog, 15 Oct 2024](https://developers.facebook.com/blog/post/2024/10/15/platform-terms-and-developer-policies-updates/)
- From Nov 2024, user data-deletion requests arrive in the developer inbox per app ID — [Meta developer blog, 15 Nov 2024](https://developers.facebook.com/blog/post/2024/11/15/user-data-deletion-requests/)
- Apps with higher-risk data access are asked whether they test for vulnerabilities at least every 12 months — [Meta Terms & Policies FAQ](https://developers.facebook.com/documentation/development/terms-and-policies/faqs); [Meta: Developer data security best practices](https://developers.facebook.com/docs/development/data-security)
- Current Platform Terms — [developers.facebook.com/terms](https://developers.facebook.com/terms)
- A third-party summary claims a 90-day deletion window — unconfirmed against Meta text — [Conduct Atlas](https://conductatlas.com/platform/meta/meta-platform-policy/90-day-platform-data-deletion-obligation/)

### Inferences
- An agent that "nudges" budgets frequently will hit the 4/hour ad-set cap quickly; design for at most one budget decision per ad set per few hours, with backoff on 613/17/80004.
- Ads insights and audience data pulled into the shop DB are Platform Data: keep only what decisions need, set retention, and delete on app shutdown.

### Gaps
- No official statement found that high (but within-limit) edit frequency harms delivery or account standing, beyond Meta's "learning phase" guidance (not fetched this session).
- Marketing API–specific terms (separate from Platform Terms) not retrieved.

## 4. UAE law: Media Council, consumer protection/promotions, cosmetics claims, Arabic, VAT-inclusive pricing

### Takeaway
UAE advertising is governed by Federal Decree-Law 55/2023 (Media Regulation, in force 1 Dec 2023) plus the Media Council's advertising standards; penalties under Cabinet Resolution 42/2025. A business promoting its own products through its own account appears exempt from the 2025 Advertiser Permit (Mu'lin), but health/therapeutic claims need prior approval (now the Emirates Drug Establishment), discounts in Dubai need DET authorisation, and ad language should be Arabic (MSA or Emirati dialect).

### Cited Findings
- Media law: Federal Decree-Law No. 55 of 2023, in force 1 Dec 2023, applies to traditional and digital media; Media Council issued 20 mandatory content standards — [Tamimi](https://www.tamimi.com/law-update-articles/media-law-and-advertising-standards-in-the-uae-key-rules-and-restrictions/); [Gulf News](https://gulfnews.com/uae/uae-media-law-media-must-follow-20-key-standards-to-avoid-fines-of-up-to-dh1m-1.500157204); [u.ae Media regulation](https://u.ae/en/Media/Media-In-The-UAE/Media-regulation)
- Penalties: Cabinet Resolution No. 42 of 2025 sets the administrative violations schedule — [Clyde & Co, May 2025](https://www.clydeco.com/es/insights/2025/05/uae-media-regulations-framework). CONFLICT on ranges: AED 5,000–1,000,000 [Tamimi](https://www.tamimi.com/law-update-articles/media-law-and-advertising-standards-in-the-uae-key-rules-and-restrictions/); AED 10,000–1,000,000 [Gulf News](https://gulfnews.com/uae/uae-media-law-media-must-follow-20-key-standards-to-avoid-fines-of-up-to-dh1m-1.500157204); AED 5,000–40,000 for e.g. violating advertising regulations [Hadef Partners](https://hadefpartners.com/news-insights/insights/key-regulatory-update-uae-media-law-violations-and-administrative-penalties-issued/)
- Advertiser Permit (launched ~30 Jul 2025): required for social-media promotion; EXEMPT: an individual promoting their own products/services or those of a company they own through their personal account (plus some under-18 cases) — [Dubai DMI page](https://www.dmi.gov.ae/content/dubaione/en-ae/programs/181/AdvertiserPermit.html); [Gulf News explainer](https://gulfnews.com/living-in-uae/ask-us/what-is-the-uae-advertiser-permit-for-social-media-1.500218180); [The National, 30 Jul 2025](https://www.thenationalnews.com/news/uae/2025/07/30/uae-social-media-advertiser-permit). CONFLICT: a later Gulf News piece says it applies to everyone whether or not paid — [Gulf News](https://gulfnews.com/living-in-uae/telephone-internet/advertiser-permit-uae-who-needs-it-and-how-to-apply-online-1.500278441); and staff promoting on behalf of a company reportedly need a permit — [Chambers](https://chambers.com/articles/mandatory-advertiser-permit-required-for-promotional-activities-on-social-media)
- Media Council ad guide: no false, misleading, exaggerated or exclusivity claims, no disparaging competitors; obtain prior approval of the concerned authority for ads requiring it, e.g. health — [UAE Media Council ad guide (u.ae PDF)](https://u.ae/-/media/Media/Media-In-UAE/NMC-AD-Guide-EN-PDF-presntation.ashx)
- Arabic: advertising standards require ads in Modern Standard Arabic or Emirati dialect (new requirement with the Advertising Standards) — [Tamimi: Advertising standards for the UAE](https://www.tamimi.com/law-update/february-4/articles/advertising-standards-for-the-uae/); [The National](https://www.thenationalnews.com/business/national-media-council-tells-advertisers-to-keep-their-lingo-local-1.632647). Unclear whether this was carried into the 2023 law regime or how it applies to bilingual/English social ads.
- Enforcement: Sept 2025 Media Council legal action against a social account promoting a product with unverified medical/therapeutic claims lacking scientific backing and health-authority approval — [The National, 11 Sep 2025](https://www.thenationalnews.com/news/uae/2025/09/11/uae-launches-legal-action-after-misleading-medical-claims-on-social-media-account); [Gulf News](https://gulfnews.com/uae/government/uae-advertising-council-cracks-down-on-misleading-health-promotion-on-social-media-1.500265380)
- Health/medical-product ad approvals moved from MOHAP to the Emirates Drug Establishment (EDE), effective 29 Dec 2025 per MOHAP — [MOHAP](https://mohap.gov.ae/en/mte); WAM report (dated 18 Nov 2026 in search result) on EDE requiring approval before publishing any medical-product advertisement, information to be accurate and not misleading — [WAM](https://www.wam.ae/en/article/c3bga2x-emirates-drug-establishment-mandates-approval-for)
- Cosmetic vs drug: therapeutic/disease-treating claims can push a product into drug regulation; classification depends on formulation, intended purpose, presentation and claims — [Food Research Lab](https://www.foodresearchlab.com/insights/regulation-updates/cosmeceutical-cosmetic-or-drug-uae/); [ChemLinked UAE cosmetics](https://cosmetic.chemlinked.com/cosmepedia/united-arab-emirates-uae-cosmetic-regulation); older basis: Cabinet Resolution 7/2007 on health advertisements — [CMS expert guide](https://cms.law/en/int/expert-guides/cms-expert-guide-to-advertising-of-medicines-and-medical-devices/united-arab-emirates)
- Consumer protection: Federal Law 15/2020 (replaced 24/2006), objectives include combating misleading advertising; Executive Regulations Cabinet Resolution 66/2023 in force 14 Oct 2023 — [CMS](https://cms.law/en/are/legal-updates/the-uae-s-new-consumer-protection-landscape-implications-and-key-provisions); [Bracewell, 18 Sep 2023](https://www.bracewell.com/resources/seller-beware-impact-new-executive-regulations-under-uaes-consumer-protection-law); [MoE legislation list](https://www.moet.gov.ae/en/consumer-protection-legislations); [law text, uaelegislation.gov.ae](https://uaelegislation.gov.ae/en/legislations/1455/download)
- Suppliers cannot advertise discounts without consent of the competent authorities — [Pinsent Masons](https://www.pinsentmasons.com/out-law/analysis/uae-consumer-protection-regime-revamped)
- Dubai: DET handles retail promotion permits/certificates (discounts, offers, raffles; DFRE certificate via DET Connect) — [Dubai DET](https://dubaidet.gov.ae/en/our-services/for-consumers-and-students/apply-retail-promotion-certificate); retailers must show original price, discounted price and discount % — [Emirates 24/7](https://www.emirates247.com/uae/dubai-retailers-must-clearly-display-pre-discount-and-post-discount-prices/4739); consultancy claims a DET promotion permit is needed for advertised discounts by Dubai-licensed businesses (unverified) — [Noble Core Ventures](https://noblecoreventures.com/promotion-raffle-permit-dubai-2026/); Dubai fines for fake discounts — [Emirates 24/7 (2012)](https://www.emirates247.com/news/emirates/dubai-fines-retailers-that-mislead-customers-with-fake-discounts-2012-06-03-1.461380)

### Inferences
- The shop advertising its own products from its own Business/Page probably does not need an Advertiser Permit, but anyone (staff, creators) posting on its behalf may — confirm with the Media Council because sources conflict.
- An AI agent must never auto-generate "% off"/"sale" claims or strike-through prices in ads unless an approved promotion exists; and must never generate therapeutic claims (acne treatment, eczema, "heals", "medical-grade", SPF/whitening claims) without EDE approval.
- Which emirate licenses the business (Dubai DET vs Abu Dhabi ADDED vs a free zone) determines which discount-permit regime applies — needs the trade-licence fact.

### Gaps
- Did not find the exact text of the current Media Council advertising standards (post-2023) on Arabic language and ad labelling.
- Could not confirm the VAT-inclusive price display rule text (UAE practice is VAT-inclusive display; Exec Regs Art. 5 likely relevant but unread).
- No official source found on whether online-only discount advertising needs a DET permit; no source confirming whether EDE approval is required for ordinary (non-therapeutic) cosmetic ads.
- Dubai Municipality cosmetic product registration (Montaji) relevance to ads not researched to source level.

## 5. Payment: AED billing, VAT on Meta ads, payment failures

### Takeaway
Secondary sources say Meta charges 5% UAE VAT to UAE accounts without a TRN; entering a valid TRN moves the business to reverse charge (self-account 5% output and reclaim input). Payment failure pauses ads until the balance is settled.

### Cited Findings
- Meta charges 5% VAT on UAE ad purchases unless a valid TRN is submitted; TRN entry shifts treatment to reverse charge — [Farahat & Co (adviser blog)](https://farahatco.com/blog/vat-on-facebook-ads-in-uae/)
- UAE buyer of imported services reports 5% output VAT and reclaims as input; often applies to Meta/Google ads; keep supplier invoice, payment proof, FX workings (CBUAE rate), VAT return extract — [Alpha Partners](https://www.alphapartners.co/blog/vat-on-digital-services-and-saas-in-uae); [Meydan FZ reverse charge](https://www.meydanfz.ae/blog/uae-vat-reverse-charge)
- FTA has simplified documentation for VAT recovery on imported services (Sept 2025) — [KPMG](https://kpmg.com/us/en/taxnewsflash/news/2025/09/uae-simplified-documentation-requirements-vat-recovery-imported-services.html); 2026 VAT law amendments — [PwC VATP046](https://www.pwc.com/m1/en/tax/documents/2026/vatp046-amendments-to-the-vat-federal-decree-law-no-8-of-2017.pdf)
- Payment failure → ads paused until balance paid — [Meta Business Help](https://facebook.com/business/help/268196136699959)

### Inferences
- If the ad account currency is AED (offset 100 → fils), `spend_cap`/budgets are in fils; the account currency cannot normally be changed after creation (general knowledge, verify).

### Gaps
- No Meta primary page found naming the invoicing entity for UAE (likely Meta Platforms Ireland) or confirming the TRN mechanics; FTA guidance not fetched.

## 6. Operational risks of AI-controlled spend and mitigations

### Takeaway
The dangerous failures are unit errors (Meta budgets are integers in the currency's minor unit — AED 50 is 5000), runaway edit loops (hit 4/hour caps or look like abuse), unbounded spend from removing/raising caps, and leaked long-lived tokens. Layered hard limits that the agent cannot change are the main mitigation.

### Cited Findings
- Budgets and spend caps are set in the currency's minimum denomination (e.g., USD $20 → 2000); offset per currency (USD 100, VND 1) — [Meta: Budgets](https://developers.facebook.com/documentation/ads-commerce/marketing-api/bidding/overview/budgets); [Meta: Currency codes](https://developers.facebook.com/documentation/ads-commerce/marketing-api/currencies); campaign `spend_cap` is an integer in the subunit — [Meta: Ad campaign group reference](https://developers.facebook.com/documentation/ads-commerce/marketing-api/reference/ad-campaign-group); third-party wrappers sometimes use whole units instead, a source of 100x errors — [Zernio docs](https://docs.zernio.com/platforms/meta-ads/ad-sets)
- Edit-rate ceilings: ad set budget 4/hour; spend_cap 10/day; account-level throttles 80004 etc. — [Meta: rate limiting](https://developers.facebook.com/docs/marketing-api/overview/rate-limiting)
- Account spending limit pauses all ads when reached; reset/removal is a manual payment-settings action (removal ~15 min) — [Meta: Reset](https://www.facebook.com/business/help/1892523930869907); [Meta: Remove](https://www.facebook.com/business/help/2135167003399680)
- Meta also provides an Ad Rules engine (change specs) that can pause/adjust on metrics server-side — [Meta: Ad rules change spec](https://developers.facebook.com/documentation/ads-commerce/marketing-api/ad-rules/overview/change-spec)
- Breach/compromise: unrecognised ad-account activity path — [Meta help](https://www.facebook.com/business/help/524424920973484); developers must notify Meta of breaches and remediate — [Meta dev blog 2020](https://developers.facebook.com/blog/post/2020/07/01/platform-terms-developer-policies)

### Inferences (mitigations — design recommendations, not sourced rules)
- Hard ceiling outside the agent: set the account spending limit manually in Ads Manager to ~1 month of intended spend; do NOT give the agent any code path that writes `spend_cap`/`spend_cap_action` (and especially not 0 — verify whether 0 means "no cap").
- Server-side guard on every write: convert with the account's currency offset in one function; reject any budget > N× current or > fixed AED ceiling; reject non-integers; log before/after.
- Rate-limit the agent below Meta's caps (e.g., ≤1 budget change per ad set per 6h; max ±20% per change); stop on 613/17/80004 with exponential backoff, never retry-in-loop; circuit breaker after N errors.
- Create new campaigns/ads PAUSED; require owner approval (or a dry-run diff) before activation and for any creative copy; run the policy lint from §1 first.
- Daily reconciliation: compare `amount_spent` vs plan, alert owner (email/WhatsApp) on >X% deviation, on any rejection, on account_status ≠ active, on payment failure.
- Tokens: System User token scoped to one ad account with minimal permissions (`ads_management`, `ads_read`), stored encrypted server-side, never in logs/front-end/`/api/*`; rotate; kill switch that revokes it. Keep the shop's CLAUDE.md rule that `/api/*` is unauthenticated in mind — no ads endpoints there.
- Never auto-create new ad accounts/BMs or retry rejected ads unchanged (account-integrity risk, §2).

### Gaps
- No public data found on frequency of AI/automation-caused overspend incidents.
- Could not verify Meta's documented overspend tolerance (daily budget vs actual daily spend variance) this session.
