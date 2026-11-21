# KBB Ads Autopilot — everything the owner asked for (living checklist)

One line per requirement, with its state. Every lane reads this before starting and updates the state of what it
built. "Done" means built, checked and shipped in a version he has.

| # | The owner asked (20 Nov 2026) | State |
|---|---|---|
| 1 | Offline Mac app, separate from KBB Studio and the website; auto-connects to shop, Meta, Claude on open; catches up | Done (0.1.0) |
| 2 | Ads, creation, launching, monitoring, stop losers, launch new, creative tests — fully automatic inside his limits | Done (0.1–0.2), deepened in Strategy round |
| 3 | Claude researches what people like now in skincare; Arabic versions; mostly Arab women | Done (research, AR-first copy) |
| 4 | Every feature; he never has to look at what runs or what's next; Claude always has the plan | Built (Strategy round): Plan → Upcoming jobs, runs by itself in Auto |
| 5 | Low CPC, attractive to ladies, aesthetic ads and videos | Done (designs 0.2.0); ongoing |
| 6 | Learn everything about kbeautybliss, the industry, competitors, market | Research + learned profiles + the self-learning loop (row 25) |
| 7 | Analyse his own videos (new and past ads) and run ads from them | Done (0.1–0.2) |
| 8 | Works with an unverified Meta business | Done (system user / 60-day token) |
| 9 | Every Meta ad type | Done in executor; WhatsApp ads DROPPED by owner (see 23) |
| 10 | Read the whole business portfolio, all ad accounts, train itself; switch accounts; automate several separately | Done (0.1.0) |
| 11 | SaaS look, instant, no page loads; picked H · Sage Calm | Done |
| 12 | Trending latest products research, daily; "Consider stocking" | Done (0.1.0) |
| 13 | Wording rules "half way" (acne products allowed) — Balanced default, Strict/Relaxed setting | Done |
| 14 | Mac closed → Meta's own rules + spend cap protect | Done |
| 15 | Spending limits set in the app; none set = nothing launches | Done |
| 16 | Create ads "through Claude" in one place, no model shopping | Done (0.3.0 box + one optional Gemini key) |
| 17 | Use his Claude MAX for creation (Claude desktop connector); notify when creation is due, Start button | Done (0.3.0) |
| 18 | API auto-creation fallback OFF by default ("don't involve credits") | Done (0.3.0) |
| 19 | Patch/update system inside the app, super reliable | Done (0.3.1) |
| 20 | Latest and trending products in ads, NOT old ones (e.g. snail mucin) — Playbook: focus/avoid, freshness | Built: Strategy → Your playbook / Products (Avoid enforced in code before Claude; fresh-first ranking; evergreens only on their own sales) |
| 21 | He can change upcoming jobs, edit the brief/prompt, edit the plan | Built: Plan → Approve / Edit / Skip / Earlier / Later / Show the brief / Ask Claude to replan |
| 22 | Understand how it decides; professional campaigns (prospecting, remarketing, existing customers, audiences, settings with reasons) | Built: Strategy → How it decides / Rules; Campaigns → the funnel (every setting + why); Audiences (sizes, purpose, used by) |
| 23 | NO click-to-WhatsApp ads | Built: removed from planner, funnel and UI (executor capability dormant) |
| 24 | Agency-grade low-budget playbook (consolidation, budget tiers, creative-as-targeting, learning-phase discipline) | Built: Strategy → Our playbook (Meta-verified vs practice), tiers editable, enforced in the funnel builder |
| 25 | Self-learning: improves itself day by day | Built: outcomes ledger, priors, lessons into planner/briefs, 80/20 exploration, bounded self-tuning (off / within bounds / ask me), weekly report + health on What it learned |
| 26 | Super reliable, super fast, real time | Standing rule for every lane |
| 27 | Judge on real shop orders/refunds/profit (website shop API, cost prices) | Done (website 2.60.678 + engine shop-api) |
| 28 | Benefit-first copy: product benefit + hero ingredient + Korean-skincare angle + why buy now + CTA, Arabic first | Built: copywriter prompt, briefs (box + connector), template copy, playbook rule; linter blocks timed results ("calms redness in 3 days") |
| 29 | Offers: free gift with every order + coupon code GLOW; never a % or amount off; gift names only when he enters them | Built: Playbook → Offers (pre-filled, editable); linter blocks % / amount discounts in every mode, EN + AR. TODO (website, later package): expose the current free gifts read-only on /autopilot-api so "Gifts we give" fills itself |
| 30 | Occasions (UAE/GCC/international, Hijri estimated), ads prepared in advance, his approval before anything seasonal goes live | Built: Strategy → Occasions (on/off, lead days, his offer, his own); Approvals → Occasions (AR + EN previews, reject per ad, Approve all); reminders at 7 and 3 days |
| 31 | Approvals empty with no reason; link Page/pixel easily | Built: "Nothing to approve because…" with one-click fixes (Overview + Approvals), skips in Activity, Settings → Accounts → Auto-link |
| 32 | His answers (20 Nov): ~300–500 orders / 28 days; customer lists NOT decided; sold-out / payment failures = alerts only, never pauses; "pay in 4 with Tabby/Tamara" allowed, no gift tiers; real economics (VAT first; Tabby/Tamara 7%, cards 2.5%, cash 1.5%; Quiqup AED 15; gift cost editable) | Built: shop orders/week + payment mix from the shop API in the economics (math on Strategy); customer lists shown disabled ("needs your consent decision"); sold-out alerts (Overview, Mac, Activity), no pause; pay-later in Offers; linter blocks gift tiers. Payment-failure alerts need a shop signal the API does not give yet |
| 33 | (21 Nov) "in the approval i can see the products and text etc. but those products i don't want to promote, give me a way to skip on products or choose the products myself also in case i don't like the products for any planned campaign" | Built: every waiting ad / ad set / product set in Approvals shows its products as chips → **Change products** (picker over the shop: search name/brand, photo, price, stock, rank + why, new / trending / pinned badges, avoided shown disabled "Avoided (Strategy)"; that proposal is rebuilt for exactly those — template copy with the offers, or a Creation request "waiting for you in Claude" — and checked again), **📌 Pin**, **⊘ Don't promote this product again** (Playbook Avoid; every waiting proposal and job that used it rebuilt; Undo toast). Same picker in Plan → Upcoming jobs (Edit → Choose products) and Approvals → Occasions (Change products: its ads prepared again). A proposal / job with no allowed product left says "all products for this job were avoided — choose products" instead of disappearing. Instant, no re-plan, logged to Activity, EN + AR. Tests: picker_test.go (5), tools/picker-ui.cjs |
