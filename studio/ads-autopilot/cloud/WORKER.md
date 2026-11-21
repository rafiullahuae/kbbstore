# KBB Ads cloud worker — instructions for the scheduled Claude Code task

You run every 2 hours on the owner's Claude Max plan, in a Claude Code cloud session that has two repositories:
`rafiullahuae/kbbstore` (this file, the app's renderer) and the exchange `rafiullahuae/kbb-ad-exchange`. Your job:
make the ads the owner's Mac app asked for while the Mac is closed. Nothing here needs an API key, and you never
write one anywhere. API credits stay off: you ARE the creator.

Paths below are relative to `studio/ads-autopilot/` in the kbbstore checkout. If the exchange is not checked out next
to kbbstore, the worker clones it (`KBB_EXCHANGE_REPO=owner/name` to use another one).

## Every run

0. Setup, once per session: `node -e "require('playwright')"` from the kbbstore root. If it fails: `npm ci` there
   (Playwright is already in package.json). If Chromium is missing: `npx playwright install chromium`.
1. `node cloud/worker.mjs claim --max 2` — pulls the exchange, claims up to two waiting jobs (a claim lasts 3 h;
   two runs never take the same job). "no waiting jobs" → print `node cloud/worker.mjs status` and stop.
2. For each claimed job `ACCT/JOB`:
   1. `node cloud/worker.mjs brief --job ACCT/JOB` and read ALL of it, then the recent feedback it prints (what the
      Mac refused last time and why — do not repeat it).
   2. Write `results/ACCT/JOB/ads.json` in the exchange checkout, exactly in the brief's `output` shape:
      `brief.count` ideas, each with one set per language in `brief.langs`, Arabic first.
   3. `node cloud/worker.mjs render --job ACCT/JOB` — draws every idea × language × format with the app's own
      renderer in headless Chromium, runs the app's quality gate on each, writes the PNGs (and one MP4 for an idea
      with `"motion": true`), and lists what the gate refused. If a format was refused (text too dense, product too
      small, contrast), shorten that set's text or pick another design family and run `render` again (at most 3
      times). Product too small usually means the photo has a busy background: try `hero` or `editorial`.
   4. `node cloud/worker.mjs finish --job ACCT/JOB` — writes done.json and pushes. Done.
   5. If the job cannot be made (no usable product photo, the brief is contradictory):
      `node cloud/worker.mjs fail --job ACCT/JOB --note "<one plain sentence why>"`.
3. End with one line per job: job, ideas, creatives rendered, refused by the gate.

## Writing the copy (the Mac re-checks every word; a refused set is thrown away)

- `owner_instructions_always_obey` comes first, always.
- Arabic is WRITTEN, not translated: Modern Standard Arabic, warm and Gulf-friendly, feminine address (ـكِ).
  No tatweel (ـ). English is a separate set with the same idea.
- The linter mode is `brief.linter.mode` (balanced by default) and its rules are `brief.linter.rules`. Never:
  cure / treat / heal / علاج claims, before/after, condition-shaming questions ("Do you have acne?", «هل تعانين»),
  whitening / تبييض, "#1", a % or amount off, invented urgency, invented gifts or pay-later. Product/skin-type
  framing is fine ("for acne-prone skin", "helps calm the look of redness").
- Offers: ONLY the lines in `brief.offers` (free gift, code GLOW, pay in 4 with Tabby or Tamara), word for word in
  meaning, and only when the list is not empty.
- Headline ≤ 40 characters (the benefit), `sub` = the hero ingredient (from the product's title / facts — never
  invent an ingredient), `bullets` 2-3 short benefits, `badge` = the brand or empty, `cta` = `SHOP_NOW`, 2-4 short
  lines of `primary_text`. Formula: hook → benefit for a skin type / routine step → proof (the ingredient + the
  Korean-skincare angle) → reason to buy (authentic, UAE delivery, the AED price) → CTA.
- `template` is one of `brief.design_families`. Vary families across ideas. 2+ products → `routine` or
  `collection`. `palette`: empty (the photo decides) or 2-4 hex colours close to `brief.brand_colours`.
- `product_ids` only from `brief.products` (and `brief.more_products` for routines). Use the lessons and
  `winning_patterns`; follow `research` angles when they fit.
- `scene` stays empty (no AI backgrounds in the cloud).

## Never

- Never put a key, token, password, customer name, e-mail, phone or order in the exchange.
- Never edit `jobs/*/brief.json` or `status.json` (the Mac owns them) or another worker's claim.
- Never push to kbbstore. Never change `app/` files to make a render pass.
- A job whose status is `withdrawn` is skipped (the owner made it himself).

## Self-test (no network)

`NODE_PATH=<kbbstore>/node_modules cloud/selftest.sh` runs the engine (-mock), a local bare git repo as the
exchange, two workers, the renderer and the import end to end; "SELFTEST PASSED" or the step that failed.
