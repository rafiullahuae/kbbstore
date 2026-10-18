# CPU investigation: 4–5% before Arabic, 14–16% now (Lane CPU, 18 October 2026)

**This is an investigation only. Nothing in app/, resources/, routes/, config/ or
database/ was changed.** Every number below was measured on a preview copy of the
shop: the `arf-preview` database (Arabic on, live state) with a live-sized
catalogue (761 products, 100 brands, 51 categories, 11 posts) and a live-sized
Arabic catalogue (4,711 published Arabic rows). It was served the way PHP-FPM
serves it: every request a fresh PHP process, opcache on, the file cache,
database sessions, and route and config caches built. Raw numbers are in
`docs/lane-cpu/`; the scripts are `tools/cpu-*`.

**Scale.** This machine is faster than the live box. The coordinator measured the
live English homepage at about 230 ms of server time; here it takes 56–61 ms. So
multiply the ms and CPU-seconds below by roughly **2–4×** for live. The
*ratios* (Arabic against English, cron against pages) carry over as they are.

## Short answer

The Arabic shop does **not** do anything per request that runs away. An Arabic
page costs **1.2–1.4×** its English twin, and the extra time is spread across the
templates. It is not in translations: the translation map is cached and read
once per request. Three things arrived in the same week, and together they
explain most of a rise of about 10 points on a 2-vCPU box:

1. the **per-minute cron** (11 Oct). It costs about 1.7 CPU-seconds every minute
   here, from 8 PHP processes a minute, even when it has nothing to do;
2. **twice the crawlable pages** (Arabic, mid-October): the sitemap went from 934
   to 1,868 addresses, and each Arabic page costs 24–41% more than the English one;
3. the **"Online now" heartbeat** (9 Oct): each visible tab sends one PHP request
   every 45 s.

14–16% average CPU on a 2-vCPU, 7.75 GB server with these numbers is **normal and
safe**: 85% of the box is idle. It is not a leak or a loop.

## Ranked contributors

| # | What | Measured here | Since | Verdict |
|---|------|---------------|-------|---------|
| 1 | Cron `schedule:run` every minute | 1.24–1.50 s CPU for `schedule:run` and the 5 commands it waits for, plus ~0.4 s for the background `ugc:cut-covers` and its `schedule:finish`. That is **≈1.7 CPU-s/min ≈ 100 CPU-s/hour, ≈1.4% of 2 vCPU here, est. 3–6% on live**. 8 PHP boots a minute, 11,520 a day. Each command's own work is 1–47 ms; **~95% of the cost is booting Laravel** (`artisan --version` alone is 0.18 s CPU). | 11 Oct | Low-risk saving available (see fix A) |
| 2 | Crawlers fetch 2× the pages | Sitemap 934 → **1,868** `<loc>` (934 under /ar/), every one with hreflang alternates. Per page, Arabic is +8 to +15 ms (+24–41%). A full crawl of the sitemap costs ~80 CPU-s here, against ~35 CPU-s before Arabic (**≈2.3×**). | mid-Oct | Leave it. This is the price of the Arabic shop. Check which bots and how often (see the owner's checks). AI crawlers are the one lever (fix C). |
| 3 | "Online now" heartbeat, `POST /api/online` | 12.8 ms (10.7 ms CPU) each, one every 45 s per visible tab, so ~80 an hour per open tab ≈ **0.9 CPU-s/hour per open tab**. Each page view also sends `POST /api/viewed`: 16 ms. | 9 Oct | Leave it, or beat every 90 s (fix D) |
| 4 | Campaign sending | While a campaign is `sending`, `kbb:campaigns-step` works up to **45 s of every minute** (`CampaignTick::BUDGET`), rendering and handing mails to SMTP | when sending | Leave it. Compare the CPU graph with the send times. |
| 5 | Database sessions for cookieless visitors (bots) | Every hit without a cookie inserts a `sessions` row: +3 queries, +3–6 ms | always | Leave it (changing sessions is not low-risk) |
| 6 | `ArabicSearch::map()` read 24× per Arabic page | 24 file-cache reads of `kbb.search.ar.v1` (10.5 KB, 0.032 ms each) ≈ **0.8 ms per Arabic page**. `HeaderSettings::trendingWords()` calls `arabicFor()` once per trending word, and `map()` has no in-process memo. | Arabic | Tiny fix available (fix B) |

### Measured and fine (leave them)

- **Translations.** `TranslationStore::map('ar')` is one file-cache read per
  request: 187 KB, **0.27 ms**. It is rebuilt at most once every 300 s per
  language. A cold homepage with an empty cache costs 182–240 ms and 34–43
  queries, once. The 295 translator calls on `/ar/` cost **2.1 ms in total**; on
  `/` there are 150 calls for 0.8 ms. Per-locale keys: `kbb.translations.ar` and
  `kbb.translations.en`. Long fields are read per page in one query (`longFor`).
- **img-cache adds nothing for Arabic.** `ImageVariants` is keyed by the
  picture's path with no locale anywhere (0 matches), so `/ar/` uses the same
  files. Generation runs after the response (`defer`), one worker at a time, and
  once per picture under a 24 h lock (`kbb.img-tile.<sha1>`). Wide copies use a
  10 min lock. Cost per picture: ~60 ms for a JPEG, ~360 ms for a PNG, off the
  response path. This is a one-off and does not grow with traffic.
- **OrderReminderTick.** With cron installed, a page request also runs the
  reminder sweep at most once a minute: 6 queries, ~47 ms. That is negligible.
- **sitemap.xml**: 57 ms, 23 queries, sent `Cache-Control: public, max-age=3600`.
- **Per-minute commands on an empty database:** order-reminders 6 queries,
  campaigns-step 2, push-step 11 (2 of them schema introspection every run),
  analytics-rollup 3, order-emirates 1, cut-covers 1. Every one is indexed and
  bounded (`limit 20 / 50 / 300 / 500`). Nothing scans a whole table every
  minute. (`docs/lane-cpu/cron-queries-empty.txt`)

### Per page, English vs Arabic

Median of 15 warm runs, ms (CPU ms), with the live-sized Arabic catalogue
(`docs/lane-cpu/requests-livetr.json`):

| page | EN | AR | AR/EN | queries EN/AR | cache reads EN/AR |
|---|---|---|---|---|---|
| home | 61.2 (58.7) | 76.0 (70.9) | 1.24 | 5 / 6 | 27 / 52 |
| product | 36.8 (34.5) | 50.7 (48.1) | 1.38 | 13 / 14 | 26 / 51 |
| category | 36.1 (33.5) | 51.0 (46.8) | 1.41 | 8 / 9 | 21 / 46 |
| brand | 34.5 (32.8) | 42.8 (41.4) | 1.24 | 8 / 9 | 21 / 46 |
| super-sale | 33.0 (30.8) | 42.0 (40.7) | 1.27 | 7 / 7 | 21 / 46 |
| shop | 31.1 (28.9) | 40.5 (38.5) | 1.30 | 7 / 7 | 20 / 45 |
| blog | 22.5 (20.5) | 31.8 (30.3) | 1.41 | 5 / 5 | 19 / 44 |
| search API | 12.8 | 14.2 | – | 1 / 4 | 7 / 9 |

Peak memory is 4 MB for English and 4–6 MB for Arabic. Settings: one `settings`
query per request in both languages (`SettingsRequestMemo` holds).

## The Arabic homepage: 2× on live, not reproduced here

The coordinator measured `/ar/` on live at ~0.52 s of server time against
~0.26 s for `/`. `/ar/super-sale/` matched English. Here, with live-sized
translations, `/ar/` costs **+15 to +20 ms (+24–38%)**, not +100%. The profile
(`tools/cpu-front.php` with `KBB_CPU_PROFILE=1`, `docs/lane-cpu/profile-home.json`):

- Blade time is 36.0 ms on `/` against 52.6 ms on `/ar/`. The extra is spread
  out: `store/home.blade.php` +5.7 ms, `product-card` +2.8, `header` +2.1,
  `layouts/store` +1.1, and nav, footer and rails under 1 ms each.
- Translator: 295 calls against 150, **2.1 ms against 0.8 ms in total**. The 97
  `store.wording.*` lookups that 2.60.537 and its neighbours added through
  `ArabicWording::of` → `__()` (HomeArabic::pick/twin, GridTabs::say,
  SpottedSettings) cost **under 1.5 ms together**, because the map is cached and
  `uiMap()` is memoised.
- There is 1 extra query (`longFor`, 0.24 ms) and 25 extra cache reads (24 are
  fix B's, 0.8 ms).

**So nothing in the code makes `/ar/` cost 2× by itself.** The live gap has to
come from something the preview does not have. The candidates, in order:

1. a cache entry on live that misses for `/ar/` every time. A file the web user
   cannot rewrite would do it, for example one created by the cron user.
2. live-only homepage content that is heavier in Arabic.
3. noise from the spike: 7 samples taken while CPU was at 22%.

The read-only probe settles it on live in one SSH command:

```sh
cd <app root>
KBB_CPU_HOST=https://<the shop domain, as in APP_URL> sh tools/cpu-live-probe.sh / /ar/ /super-sale/ /ar/super-sale/
```

The probe needs `tools/cpu-front.php`, `cpu-live-probe.sh`, `cpu-summary.php`
and `cpu-prof-diff.php` copied into `tools/`. It writes only to
`storage/cpu-logs/`. It prints, per address: ms, CPU ms, queries, cache
hits/misses by key, Blade ms per view, and translator calls. A `miss` on
`kbb.translations.ar` or `kbb.search.ar.v1` on every run, or one view far
heavier on `/ar/`, is the answer.

## Fixes, all optional (none implemented)

- **A. Fewer cron boots: risk low–medium, saves ~50% of cron CPU (≈0.9 CPU-s/min
  here, est. 1.5–3 points on live).** Run the five tiny every-minute commands
  (order-reminders, campaigns-step, push-step, analytics-rollup, order-emirates)
  in **one** process: one `kbb:minute` command that calls the five services in
  turn, each in its own try/catch, under one `withoutOverlapping`. Keep
  `ugc:cut-covers` separate (it runs ffmpeg in the background). That saves 4
  boots a minute. A smaller step with the same idea: move `kbb:analytics-rollup`,
  `kbb:order-emirates` and `kbb:push-step` to `everyFiveMinutes()`. Each saves
  ~0.22 CPU-s/min. Campaign pacing (`campaigns-step`) must stay every minute.
  Payment code is not involved.
- **B. Memoise `ArabicSearch::map()` per process: risk very low, saves ~1 ms per
  Arabic page** (24 file reads → 1). Add a static memo, and clear it in
  `flush()` the way `TranslationStore::forgetMemo()` does. Small enough to bundle
  with a later Arabic package, not worth a package of its own.
- **C. AI crawlers: the owner's decision, saves whatever they cost.**
  robots.txt has rules for `*` only. Bots are not limited, and
  `GoodBots::FAMILIES` (google, bing, apple, duckduckgo, yandex, meta, whatsapp,
  pinterest, tiktok, telegram, x, linkedin) has no AI crawlers in it. If the
  access log shows GPTBot, ClaudeBot, Bytespider, Amazonbot, meta-externalagent
  or PerplexityBot fetching both languages, a `Disallow` for the ones he does not
  want is the cheapest saving on the list. Listing pages also carry 11
  `?add-to-cart=` links each. They are `rel="nofollow"`, but nothing in robots
  forbids `/*?add-to-cart=`, and a badly behaved bot multiplies its page count
  through them. One `Disallow: /*?add-to-cart=` line closes that.
- **D. "Online now" every 90 s instead of 45 s: risk low, halves the heartbeat
  cost.** The board becomes slightly less instant. Only worth doing if the
  access log shows `/api/online` among the top URLs.

## The traffic spike: 168 users in 30 minutes, 80% on Super Sale, all "(direct)"

**1. Campaign emails do link to Super Sale, and they DO carry UTM tags.** Three
ready templates point at `/super-sale/`: "Super Sale", "New look, less prices"
(hero picture and two buttons), and the specials one
(`TemplateLibrary.php:65,107,333–338`). Every link to the shop gets
`utm_source=email&utm_medium=marketing&utm_campaign=mkt-<id>-<name>` added. It
is added at the click redirect `/email/c/<token>/<n>`, or directly in the link
for the letter style (`CampaignLinks::tag`). The redirect also sends
`Referrer-Policy: no-referrer`. So **email security scanners following a
campaign would show in GA as email / marketing, not "(direct)"**, which does
not fit what GA showed. One exception would make it fit: `tag()` only tags links
whose host is the shop's own (`SiteUrl::externalOrigin()`). A link typed by hand
with the *other* domain (kbeautybliss.com against extrabeauty.ae) passes through
untagged and with no referrer, which is exactly "(direct)". Order emails,
reminders and cart-recovery emails do not link to Super Sale. Push
notifications carry no UTM (they would be "(direct)"), but they go only to
existing subscribers, which does not fit 174 first visits from four continents.

**2. The shop's own analytics keeps out declared bots only.** `Tracker::isBot`
drops user agents matching `bot|crawl|spider|headless|preview|scan|check|…` and
the GoodBots families. It drops speculative prefetches too. A visit counts only
when its JavaScript sends `/api/viewed`. A scanner running real Chrome with an
ordinary user agent **is counted**, in the shop's own numbers and in GA alike.
Campaign clicks are recorded per device in `mkt_clicks`, with `dev = 'bot'` when
the user agent says so. **Where to compare:** admin → Dashboard → **Analytics**
(live visitors, pages, sources), and Store → Security → **Firewall** (floods,
countries, verified bots).

**3. Cost of the spike: cheap.** Super Sale costs 33 ms in English and 42 ms in
Arabic here, with 7 queries. Each view adds `/api/viewed` (16 ms) and, while the
tab stays visible, a heartbeat every 45 s (13 ms). 92 users in 5 minutes is
about 0.3 pages a second, roughly 1–3% of one core here. img-cache: the Super
Sale pictures already have their copies if the page had been opened before. A
first-ever picture costs ~60 ms once, after the response, one worker at a time.

**What the owner can check:**
- Admin → Marketing → Emails: did any campaign's **send time** fall just before
  the spike, and what share of its clicks are "bot" in the click table?
- GA4 → Reports → **Realtime**: source/medium. If it shows *email / marketing*,
  it was the campaign; *(direct)* means it was not, unless the link used the
  other domain. Then by **city**: datacentre cities (Ashburn, Frankfurt,
  Amsterdam, Singapore, Jakarta) point to scanners or bots. Then by
  **browser/OS**: a single Chrome version on Linux points to headless browsers.
- Admin → Dashboard → Analytics for the same 30 minutes. If the shop shows far
  fewer visitors than GA, the difference was bots that GA counted.

## What the owner can read on Cloudways to confirm

1. **Servers → (the server) → Monitoring → Details → CPU**, range 1 day and
   1 week. A raised *floor* that starts on 11 Oct, with no daily rhythm, is the
   cron. Bumps that follow daytime traffic are visitors and crawlers. A step
   starting on the day /ar went live, mostly at night, is crawlers on the
   doubled sitemap.
2. **Applications → (the app) → Monitoring (Analytics) → Traffic**: top bots,
   top IPs, top URLs for the last day. Look for `/api/online`, for
   `?add-to-cart=`, and for AI crawlers (GPTBot, ClaudeBot, Bytespider, Amazonbot,
   meta-externalagent, PerplexityBot).
3. **Same screen → PHP**: the slowest URLs. If `/ar/` stands out, run the probe
   above.
4. **Same screen → MySQL**: slow queries. None are expected; the heaviest
   per-minute query here is 2.7 ms.
5. **Over SSH** (Servers → Launch SSH Terminal), in the application's `logs/`
   folder:
   `awk -F'"' '{print $6}' <access log> | sort | uniq -c | sort -rn | head -20`
   lists the top user agents, and `grep -c ' /ar/' <access log>` against
   `grep -c ' / ' …` gives the Arabic share.
6. **Application → Cron Job Management**: there must be exactly **one**
   `schedule:run` line. Two lines double item 1.

## Files

- `docs/lane-cpu/requests-pages.json`, `requests-livetr.json`, `requests-dbsession.json`, `requests-beacons.json`: per-page numbers
- `docs/lane-cpu/profile-home.json`: Blade and translator profile, `/` vs `/ar/`
- `docs/lane-cpu/cron-empty.txt`, `cron-queries-empty.txt`: cron CPU and the queries per command
- `docs/lane-cpu/cold-cache-home.txt`, `cache-entry-reads.txt`, `traffic.txt`: cold-cache homepage, cache entry sizes and read times, sitemap/robots/headers
- `docs/lane-cpu/cli-boot-opcache.txt`: CLI opcache file cache made a boot *slower* here (0.43 s against 0.26 s), so it is not a fix
- `tools/cpu-serve.sh`, `cpu-front.php`, `cpu-bench.sh`, `cpu-summary.php`, `cpu-prof-diff.php`, `cpu-cron.sh`, `cpu-cron-queries.{sh,php}`, `cpu-cache-read.php`, `cpu-time.py`, `cpu-snapshot.php`, `cpu-live-translations.php`, `cpu-live-probe.sh`, `cpu-probe-local.sh`

Caveat: the catalogue's *counts* are live, but its words are synthetic. The real
snapshot (`storage/arc-logs`) is not in the repo, and the live host cannot be
reached from the lane machine.
