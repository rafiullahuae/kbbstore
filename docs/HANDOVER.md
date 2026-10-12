# KBB Storefront — complete handover

**Written 11 October 2026 at release 2.60.485, brought up to 2.60.488 on 12 October**, for whoever picks this project
up in a new account. Read this file first, then `CLAUDE.md` (the binding rules),
then `docs/REPO-STATE.md` (what the repository does and does not hold). Nothing
about this project lives anywhere except this repository and the live server:
earlier sessions' worktrees, scratch files and private preview links do **not**
travel, and everything that mattered from them has been committed (see §7).

---

## 0. At a glance

### What we have (live on the shop, or switchable in the admin)

| Area | What exists |
|---|---|
| **Storefront** | Home page of editable sections (banners/picture slider, product rows and grids, brand photos, trust strips, Instagram "Spotted", shoppable video), category, brand, shop, product, blog/journal, About, Contact (with inquiry inbox), policy pages, 404, Coming Soon. Mega menu and a phone menu (glass panel, V4 two-tone). Logo D (lotus lockup). Pages open on intent (prefetch) and inner pages render 3–6× faster than at the start of October |
| **Catalogue** | Products with variations and attributes, sets/bundles ("Buy these together", quantity bundles), brands, category trees (parents list their sub-categories' products), Reorder per category/brand, product labels, recently viewed, wishlist, quick view, Build my routine (off), typo-tolerant site search with brand-aware extended results, image SEO, media library |
| **Product page** | The **Ledger** design (owner-approved 30 Sep), gallery, price row with discount capsule, rating, bundles, delivery promise and dispatch cutoff, sticky add-to-cart, three related-product blocks, reviews with photos, sold-out popup, back-in-stock alerts |
| **Cart & checkout** | Cart panel (drawer, 32 settings, Browsed tab, coupon hint, free-delivery bar), cart page, one-page checkout (payment methods offered per country — Cash on delivery UAE only, 2.60.487) (floating labels, address book, Building / Area / Emirate fields, COD fee, inclusive VAT line, inline validation, express wallets). Payments: **Stripe** (cards, Apple Pay, Google Pay), **Tabby**, **Tamara**, **Cash on delivery** — all live; refusal reasons logged; payments are frozen (`CLAUDE.md`) |
| **Orders** | Order list and detail (payment journey, customer journey, every email sent), bulk status, invoices and bulk printing, refunds and notes, "Send order link" for unpaid orders, New Order (manual), Cart Tracking (live baskets with browser/device) |
| **Customers & accounts** | Account area, address book, the WordPress customers and orders imported, newsletter subscribers |
| **Emails** | Branded transactional emails for every order status (13 switches), review request after completion and feedback request after delivery, abandoned-cart and back-in-stock |
| **Marketing** | Marketing Emails (drag-and-drop builder; Standard, Playful and Personal-letter looks; customer groups; scheduling; rate and daily caps; per-campaign reports; editable footer words; sends in the background via the cron, and leaving Review & send never pulls the admin back (2.60.488); bounces & unsubscribes via a Gmail label), Push notifications, Marketing Pixels (Meta, GA4, TikTok, server events, catalogue feeds), Google Shopping feed, coupons, notification bar, mini-cart promo |
| **Analytics** | Realtime board, Online now, minute chart, live funnel, sources per order, time on site & engagement, movable blocks — in the admin and the owner app |
| **SEO** | SEO engine (titles, descriptions, schema), image SEO, sitemaps incl. images, redirects from old WordPress links, IndexNow |
| **Apps** | The shop installs as an app (Site App) with update prompts; the **Owner app** (design "Petal") at a secret address, PIN-locked, for the owner and chosen team members |
| **Arabic** | Full Arabic storefront and emails alongside English; Translation screens (strings, progress, machine translation) |
| **Platform** | Core Updates (patches, history, backups, restore), Domain switch (17-step installer for kbeautybliss.com), Security & Firewall (bot protection), Users & Roles with per-screen capabilities, Modules (55 registered modules, each with an on/off switch), Cache, Debug & Monitor, Import / Export (WordPress importer, export groups) |

How the Site App, the Owner App, Arabic, push, marketing emails and analytics
work, and where each is controlled: **§11**. Every payment incident we solved and
how to handle the next one: **§12**. The complete history: **§13** and
`docs/RELEASE-HISTORY.md`.

### What we prepared but have not switched on

- **Customer reviews section, designs A–H** — previews, code built, not wired (§7.1)
- **Shop by skin concern, designs A–E** with seven artworks — playground (§7.2)
- **Domain move to kbeautybliss.com** — runbook and installer (§2)
- **Package signing** — built, on hold (§3.5)
- **Laravel 12 upgrade plan** — `docs/LARAVEL-UPGRADE.md` (§9)
- **Security module plan** — `docs/LC-SECURITY-MODULE.md`
- Older design options still selectable in the admin (product cards B–D, slider
  looks, page backgrounds — §7.3)

### How we prepared it

Every change was built in its own lane (a git worktree and branch), at most
three at a time, each with tests that fail without the change, screenshots at
390 and 1280, and the admin path named; an integrator merged them into
`claude/kind-mayer-rpqesv`, ran the full suite (~11,800 tests), built a package
with `kbb:package`, verified it byte for byte, and handed it to the owner, who
applied it in Core Updates. Design work always went to him first as live
previews; only the letter he picked was built. §3 is the exact procedure.

### What is next (in order)

1. **Confirm the live state** — which release Core Updates shows, which domain
   is live, cron alive (§10).
2. **Get the two letters** — reviews section (A–H + city yes/no) and skin
   concerns (A–E + carousel settings), then build each as its own package,
   switched ON at his choice (§7.1, §7.2).
3. **Domain move to kbeautybliss.com**, if not done — only with him, step by step
   (`docs/DOMAIN-MOVE-KBEAUTYBLISS.md`).
4. Settle the older open choices in §7.3 one at a time.
5. Housekeeping when he agrees: Laravel 12 upgrade (security advisories),
   package signing, the remaining "true for the wrong reason" assertions.

---

## 1. What this is

A Laravel 11 rebuild of the owner's WooCommerce K-beauty shop **K-Beauty Bliss
UAE**: storefront, checkout with four payment methods, a full admin console
(every screen reachable from the sidebar), an installable shop app and a PIN-locked owner app, Arabic
alongside English, marketing emails, analytics, SEO, and an importer that
brought the WordPress catalogue, customers, orders and reviews across.

**The owner (Rafi)** is not a developer. He runs the shop, decides everything
visible, applies every update himself through the admin (Platform → Core Updates, §3), and judges work by what
he can see on his phone and laptop. He writes briefly and quickly, often several
asks in one message; read every clause and do all of them. He is in the UAE
(store time Asia/Dubai).

How he wants to be answered, learned the hard way:

- **Short, plain, and in his words.** Lead with the answer. No code, file paths
  or jargon unless he asks; an admin path is the one technical thing he always
  wants (`Appearance → Cart panel → Content → …`).
- **Never say "starting now" without starting.** He once waited for a package that
  had only been announced. Do the work, then report.
- **When he says urgent, he is about to use it** (a campaign, a sale). Ship the
  smallest correct fix first, with a workaround in the same reply if one exists.
- **Every patch arrives with pictures at 390px and 1280px** and the measured
  numbers. A claim with no picture is not accepted.
- **He asks for previews when he is choosing a design**, and nothing ships until
  he names a letter. When he has decided, what he asked for ships ON (see the
  30 September rule in `CLAUDE.md`).
- He repeats one brief on every request: *"secure, optimized, super light and bugs
  free"*, *"don't disturb anything"*. Those are rules, not moods (`CLAUDE.md`,
  "What every lane owes").

---

## 2. The server

| | |
|---|---|
| Host | **Cloudways** (server 1672906, application `yjmakdgtjs`). It **has SSH**: Cloudways → Servers → Launch SSH Terminal, or Master Credentials |
| App root | `/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app` |
| Web root | `/home/1672906.cloudwaysapps.com/yjmakdgtjs/public_html` (a **different folder** from the app; `bootstrap/app.php` points at it — leave that line alone) |
| PHP | 8.3 (Cloudways adds `PATH=/var/cw/php_versions/8.3/bin…` to the crontab itself) |
| Database | MySQL on the same server; credentials only in the server's `.env` |
| `KBB_BASE_PATH` | **empty** on live (no `/kbb-upgrade` prefix — that was the old Hostinger staging box) |
| Domains | Shop launched on `extrabeauty.ae`; the move to **`kbeautybliss.com`** is prepared as a 17-step installer at Platform → Domain switch (2.60.442) with the runbook `docs/DOMAIN-MOVE-KBEAUTYBLISS.md`. **Confirm with the owner which domain is live now** before building any URL |
| Logs | `storage/logs/laravel.log`. Live runs `LOG_LEVEL=error`, so only ERROR lines exist — anything that must be readable later is logged at error |
| Secrets | Stripe, Tabby, Tamara, mail and Meta keys are in the server `.env` and in admin settings. **Never in the repo, never in a reply** |

### The cron line — installed 11 October 2026

Background work (marketing campaigns carrying on after the tab closes, scheduled
campaigns, bounce reading, push, analytics roll-ups) runs from Laravel's
scheduler. Until 11 October there was **no cron on the server**, so a campaign
only sent while its admin page was open. The owner added it in Cloudways →
Application → Cron Job Management → **Advanced**:

```
* * * * * cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app && php artisan schedule:run >> /dev/null 2>&1
```

Check it is alive: the yellow "Scheduled sends need the cron line" note on
Growth & Marketing → Marketing Emails → Campaigns is gone, or by SSH
`ls -l --time-style=+%T storage/framework/kbb-campaigns.tick` shows a time
within the last minute. If he ever reports "the campaign stopped", check this
first.

### Useful SSH commands (from the app root)

```bash
php artisan migrate:status | tail -25      # which release migrations ran
php artisan migrate --force                # if a package's migrations did not run
php artisan config:clear && php artisan route:clear && php artisan view:clear
tail -n 200 storage/logs/laravel.log
php artisan schedule:list                  # what the cron runs
php artisan kbb:campaigns-step             # push a marketing send forward now
```

---

## 3. Patches — how we build them and how the owner applies them

**Code never reaches the shop by git.** Every change goes to the owner as a zip
("a patch", "a package"), and he applies it himself in the admin. Get this
section right and nothing else can hurt the live shop much; get it wrong and a
product page 500s for every customer (it has happened — §3.6).

**Two real packages are kept as the reference:** `docs/patch-reference/`
(2.60.485, the smallest normal shape, and 2.60.484, one that also carries
compiled admin assets), with a README explaining every byte of them. Read it
before building your first package.

### 3.1 The whole cycle, in order

| # | Step | Command / where |
|---|---|---|
| 1 | Branch off the integration branch in a fresh worktree | `git worktree add -b lane/<x> ../lane-<x> claude/kind-mayer-rpqesv`, then `git config user.email noreply@anthropic.com && git config user.name Claude` |
| 2 | Make the change; write the test that fails without it | prove it: revert the fix, run the test (red), restore (green) |
| 3 | Admin screen with inline script changed? Rebuild assets | `npx vite build`, then commit `public/build/` (manifest + new assets) |
| 4 | Add the cache-clearing migration | copy the newest `database/migrations/*_clear_caches_*.php`, next timestamp (last: `2027_10_28_700000`) |
| 5 | Release notes | `docs/CHANGELOG.md` (new entry on top), `docs/REPO-STATE.md` ("Repo synced to" = new, "Previous entry point" = old), `VERSION` |
| 6 | Run the full suite | `KBB_WP_DB=kbb_wp_<x> vendor/bin/pest --compact` (25–30 min). A shopper-visible change also runs the 7 Oct checks in `CLAUDE.md`; a payment change runs the payment set |
| 7 | Screenshots at 390 and 1280 | `docs/lane-<x>-shots/`, with the measured numbers |
| 8 | Commit | message `Release 2.60.N: <what the owner will see>`, body says which default moved and that he asked for it |
| 9 | Build the package | `php artisan kbb:package 2.60.N --since=<commit of the previous release>` → `storage/app/packages/kbb-update-2.60.N.zip`. "No signing key found, so this package is UNSIGNED" is expected |
| 10 | Verify it — never skip | `sh tools/verify-package.sh storage/app/packages/kbb-update-2.60.N.zip <since>` → must end **ALL CHECKS PASSED** |
| 11 | Merge and push | fast-forward `claude/kind-mayer-rpqesv` to your branch, `git push -u origin claude/kind-mayer-rpqesv` |
| 12 | Hand it over | copy the zip to the repo root (gitignored) and send it to the owner with the pictures, the admin path, and one plain sentence of what he will see |

**Finding `--since`:** it is the commit of the previous release, i.e.
`git log --oneline -1 --grep="Release 2.60.<N-1>"`. Two releases built from one
change? Each gets its own `--since` (the previous release's commit), never a
range that overlaps another package.

**One package per change, in sequence.** The owner wants them one by one and in
order. Never build a package on a branch that is behind the last release — the
2.60.102–.106 packages were, and they reverted three files on the server.

### 3.2 What `tools/verify-package.sh` checks

1. `update.json` has every key and its version equals `VERSION`;
2. every file in the zip is byte-identical to the committed tree;
3. every shippable file changed since `--since` is in the zip (catches a
   package built from the wrong tree);
4. `"migrations": true` exactly when the zip carries a migration;
5. `UpdatePackage::verify()` — the shop's own upload check — accepts it.

Re-check an older package against the commit it came from:
`sh tools/verify-package.sh <zip> <since> <release-commit>`.

### 3.3 What the owner does (tell him in these words when he needs it)

1. Admin → **Platform → Core Updates** (the last group in the sidebar, just
   above Console). Older notes call it "Store → Core Updates".
2. **Upload an update** → choose the zip → **Check package**.
3. The screen shows **Ready to apply** with the version and the file list
   (**Show every file**). Click **Apply update**. (**Cancel** throws the upload
   away; nothing changes.)
4. It copies the files, runs the migrations when the package declares them,
   clears caches, and records the release in **History**, where each row has
   **Download** (the archived zip) and the backups list offers **Restore a
   previous version**.
5. Hard refresh (Ctrl/Cmd + Shift + R) the admin or the shop if the changelog
   says so.

If a package fails to apply he sees the reason on the screen and nothing is
half-applied (`UpdateRunner` backs up first and rolls back). If an update ever
leaves the app unable to start at all, two standalone files in the web root work
without Laravel: **`kbb-recover.php`** restores the files from an update backup
exactly as they were, and **`kbb-doctor.php`** reads the real error log (both
are in `public-web-root/` in this repo). Logging out and back in clears a stuck
"pending upload" session.

### 3.4 Checking a package landed (SSH)

```bash
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
php artisan migrate:status | tail -5     # the package's clear_caches_* migration says Ran
php artisan migrate --force               # only if it says Pending
tail -n 50 storage/logs/laravel.log       # nothing new at ERROR
```

### 3.5 Signing (on hold)

Packages are **unsigned** (`"signature": ""`) and the shop accepts them: it is
in *permissive* mode with `KBB_UPDATE_SECRET` unset. Ed25519 signing is fully
built (`php artisan kbb:signing-key`, `--key=`, `docs/PACKAGE-SIGNING.md`) but
the owner put it on hold ("Lane B") to keep packages quick. **Do not switch a
shop to `required` without following that document's order exactly** — a shop
that requires signatures before it can check them refuses every package,
including the fix (that is what bricked the updater on 24 September 2026).

### 3.6 The mistakes this process exists to stop

- **Hand-built zips** (2.60.x, 24 Sep): eight migrations copied, never run,
  because `update.json` lacked `"migrations": true` → use `kbb:package`, always.
- **Stale tree** (2.60.102–.106): built from an old branch, reverted three files,
  500'd every product page → verify step 3, and branch from the latest release.
- **Release notes with `<tags>`** broke the Core Updates card → leave `notes` empty.
- **A route without a cache clear** → the route cache keeps serving the old table.
- **Admin script changed, `public/build/` not rebuilt** → the change ships inert.
- **A migration with a broken header** → the suite's first migrating test fails
  with a ParseError (happened 11 Oct, caught before shipping). Run `php -l` on it.

## 4. Rules — where they are and the ones that bite

`CLAUDE.md` is binding and complete; it is loaded automatically into every
session in this repository. The ones most likely to be broken by someone new:

- **Nothing he did not ask about may change.** `StorefrontEnglishUnchangedTest`
  is the instrument. A deliberate visible change is approved in
  `tests/Support/EnglishRenderWalk.php::approvedStorefrontChanges()`; an
  accidental one is reverted.
- **Speed is frozen (6 October)** and **every shopper-visible change owes the
  owner's 7 October checks** (clickable first time, every page type, prefetch
  still used, no new script, numbers before/after). `SettingsRequestMemo`,
  `InstantNav`, `StorefrontQueryBudgetTest`, `ImageVariants` are protected.
- **Payments are frozen (10 October).** Tamara DD-MM-YYYY risk dates, refusal
  reasons logged at ERROR, the Apple/Google Pay layout, basket give-back. Touch
  only with the gateway's own refusal text and a real order afterwards; run
  `Tamara* Tabby* Stripe* Wallet* Checkout* Order* Payment*` before reporting.
- **`/api/*` is public.** Allowlist every field a model returns.
- **Three parallel lanes at most**, and releases batched. Never `pkill` by pattern;
  namespace scratch files; check `df -h /` before chasing an intermittent DB error;
  check `git remote -v` before blaming a push failure.
- **Never assert that your own feature is not wired up** — pin the finished state
  (`substr_count(... ) === 1`).

---

## 5. Working environment for a new session

```bash
git clone -b claude/kind-mayer-rpqesv https://github.com/rafiullahuae/kbbstore kbbstore
cd kbbstore && composer install
# There is no .env.example. Create a local .env with these keys (values are for
# local use only): APP_NAME, APP_ENV=local, APP_KEY (php artisan key:generate),
# APP_DEBUG=true, APP_URL=http://127.0.0.1, DB_CONNECTION=sqlite,
# DB_DATABASE=<absolute path to a .sqlite file>, CACHE_STORE=file,
# SESSION_DRIVER=file, QUEUE_CONNECTION=sync, MAIL_MAILER=log.
# NEVER use env.staging.txt for this: it sets KBB_BASE_PATH=/kbb-upgrade and
# prefixes every route. Tests take their own env from phpunit.xml.
git config user.email noreply@anthropic.com && git config user.name Claude
```

- **Tests:** `KBB_WP_DB=kbb_wp_<lane> vendor/bin/pest --compact`. MySQL must be
  running (the WordPress-exporter tests use it):
  `mysql -u root -e "CREATE DATABASE IF NOT EXISTS kbb_wp_<lane>;"`. If mysqld is
  down: `rm -f /var/run/mysqld/*.lock /var/run/mysqld/*.sock; nohup su -s /bin/sh mysql -c "/usr/sbin/mysqld --log-error=/var/log/mysql/manual.err" &`.
  Full suite ≈ 11,870 tests, **25–30 minutes**; the last run at 2.60.485 was
  11,806 passed / 0 failed / 63 skipped. On a slow disk a two-test file can take
  eight minutes (each test runs the full set of ~800 migrations) — that is normal here.
- The suite writes into `docs/email-previews/`, `docs/invoice-previews/`,
  `docs/lane-se-shots/` as a side effect; `git checkout -- docs` before committing.
- **Do not change tests to `:memory:` SQLite** (the migration set breaks).
- **Previews with a browser:** `sh tools/qk13-preview.sh <port>` boots a seeded shop
  (admin `owner@example.com` / `preview-password`, 682 products). Chromium is at
  `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`. Shop pages need a real
  desktop user-agent (the firewall refuses HeadlessChrome). Example scripts:
  `tools/cpb-shots.cjs` (shop), `tools/ro2-shots.cjs` (admin builder).
- **Worktrees:** `git worktree add` can be very slow on this disk; reusing a
  finished worktree with `git checkout -b` is faster. Hard-link vendor:
  `cp -al ../kbbstore/vendor vendor`.

---

## 6. Where everything is

| Need | Look at |
|---|---|
| What each release did, with admin paths | `docs/CHANGELOG.md` (newest first, in full); `docs/RELEASE-HISTORY.md` (one line per release) |
| How to build and check a patch | §3 here; `docs/patch-reference/` (two real packages); `tools/verify-package.sh` |
| A payment fault | §12 here (every past incident, its cause and its guarding test) |
| The whole story | §13 here (timeline) |
| Which version the repo is at | `VERSION`, `docs/REPO-STATE.md` |
| History, phases, every decision's reasoning | `KBB-Master-Plan.md` |
| Rules, landmines, frozen areas | `CLAUDE.md` |
| Topic deep-dives | `docs/*.md` — e.g. `EMAILS-PLAN.md`, `EMAIL-BOUNCES-SETUP.md`, `MARKETING-PIXELS-GUIDE.md`, `IMPORT-RUNBOOK.md`, `DOMAIN-MOVE-KBEAUTYBLISS.md`, `PERF-PAGESPEED.md`, `LARAVEL-UPGRADE.md`, `LC-SECURITY-MODULE.md`, `BILINGUAL-PLAN.md` |
| Live-site emergency | In the web root, without Laravel: `kbb-doctor.php` (reads the real error log) and `kbb-recover.php` (restores files from an update backup). Sources in `public-web-root/` |
| Admin search | the admin's own search box (Ctrl K) indexes screens and settings by name |

### The admin, by area (sidebar order; sub-screens are the main ones, not a full list)

Dashboard · Analytics (Realtime, time on site, sources) · Cart Tracking ·
Orders · Platform (Core Updates, Domain switch, Cache) · App (Site App, Owner
App) · Safety · Catalog (Products, Categories, Brands, Reorder, Sets, Image SEO,
Product page) · Store (Store settings, Payments, Delivery & Shipping, Coupons,
Security/Firewall, Modules, Import/Export, Site Search) · Emails (order emails,
Design & branding) · Content (Pages, Posts, Instagram) · Translation (Arabic) ·
Appearance (Header, Menus, Homepage, Banners, Product grids, Cart panel,
Checkout page, Footer, Page background) · Pages · Growth & Marketing (Marketing
Emails, Bounces & unsubscribes, Newsletter, Push Notifications, Marketing
Pixels, Reviews).

---

## 7. Open decisions the owner has to make

Nothing below ships until he chooses. Each preview is **in the repository**, so
it survives the handover; open the HTML file in a browser (or publish it as a
private page) and send it to him.

### 7.1 Customer reviews section — designs A–H  ▲ waiting

He asked for "store reviews section design … multiple unique sections with
stunning layout and also carousel … super professional, minimal + modern", then
"live preview, not as screenshot".

- **Live preview:** `docs/review-showcase-live/index.html` (letters, English /
  Arabic, desktop / phone; carousels really move). The same page was shared as a
  private artifact that will not carry over.
- **Shots:** `docs/lane-rd-shots/` (every design at 390 and 1280, EN and AR).
- **Code, built and NOT wired:** `resources/views/partials/review-showcase/design-a…h.blade.php`
  and shared parts; `tests/Feature/ReviewShowcasePreviewTest.php`;
  `tools/rdr-*.{sh,php,cjs}` to rebuild previews.

| | Design | Carousel |
|---|---|---|
| A | Card carousel — 3 cards on a laptop, 1 + a peek on a phone; arrows and dots | yes |
| B | Spotlight quote — one large quote, cross-fades every 6 s, pauses on hover / Pause | yes |
| C | Marquee ticker — two rows of review pills drifting opposite ways under the score | yes |
| D | Photo reviews carousel — customer photos, stars, quote, product beneath | yes |
| E | Score + featured — big score, 5→1 bars, three featured reviews, no script | no |
| F | Masonry wall — photo-topped cards in 4 / 2 / 1 columns | no |
| G | Split hero quote — one large review beside the product photo and "Shop now" | no |
| H | Compact trust strip — slim band: score, newest 5★ review, "See all" | no |

**Recommendation already given to him: E for the homepage plus H as a strip
elsewhere** (no script, lightest). **Also his to answer: show the reviewer's city
(e.g. "Aisha · Dubai"), yes or no.**

To wire once he picks: include the chosen partial in the homepage section list
(Appearance → Homepage content, as a new section with an on/off and a title),
feed it approved reviews only with the reviewer's first name and initial (never
email or IP — `reviews` carries `author_email` and `ip`), eager-load products, keep
the query count flat (prove it with 3 vs 40 reviews), images through
`ImageVariants::srcsetFor`, and ship ON at the letter he chose.

### 7.2 Shop by skin concern — section layout A–E  ▲ waiting

His brief (9 October): a "Shop by skin concerns" carousel for Pore Care,
Pigmentation & Dark Spots, Acne & Blemish Care, Anti-Aging, Hydration & Glow,
Redness & Sensitive Skin, Oily Skin, with artwork for each and live controls.

- **Decided by him:** "pastel light colors + Set 4, duotone is fine. but give live
  type carousel, along with the controls arrows, styles etc." — done; Set 4
  duotone on pastel is the default in the playground.
- **Still to pick:** the section design **A Halo circles · B Bloom overlay ·
  C Pastel tiles · D Arch windows · E Concern chips**, and the carousel settings
  (arrow style and position, dots, autoplay on/off, items per screen). The
  playground's "Copy settings" button gives the full choice as text — ask him to
  send that.
- **Playground:** `docs/skin-concerns-options/index.html` (self-contained, live
  controls for shape, title position, sizes per device, ring, carousel).
  Shots: `docs/skin-concerns-options/shots/`. Nothing in app/ was changed.
- Note for the build: Design A's laptop tiles were enlarged 140→172px so the
  arrows appear; white/pink arrows read best on pastel; Bloom's white names are
  lighter on the palest tiles.

### 7.2b Variable products (colour and size) — card A–E, product page A–E  ▲ waiting

His brief (12 October): "variable products functionality, by color and by
size (ml), I want the selection on the product grid, and on the product page
itself, show me some nice designs to choose from."

- **Live preview:** `docs/variant-options/index.html` (also published as a
  private page). Phone/laptop switch, every swatch and size live, sold-out
  options, "Copy my choice". Shots: `docs/variant-options/overview-*.png`.
- Card: A dots + size chips · B named shade, larger swatches, price per ml ·
  C choices on the photo · D picker slides up inside the card on Add · E shade
  strip on the photo edge. Page: A round swatches + size pills · B size cards
  with per-ml value and Best value badge · C shade tiles + size switch · D two
  dropdowns · E summary row + sheet.
- Groundwork already in the shop: `product_variants` (price, sale price, stock,
  image per option) and `attributes` / `attribute_values` /
  `product_attribute_value`. What is missing is the shopper-facing picker (the
  page today lists options as rows) and the variant-to-attribute link for
  colour hex and size per option.

### 7.3 Older decisions — check their state on the live admin first

Recorded as open on 30 September; several were settled afterwards in
conversation (the product page uses the **Ledger** design he approved on 30 Sep).
Confirm with him before acting on any of these:

- Product card B/C/D (A is live) — Appearance → Product grids
- Picture slider look — Appearance → Banners → (set) → Look
- Site background treatments — Appearance → Page background
- Review block fonts (Fraunces / Hanken Grotesk never loaded → Georgia) — load
  two families or restyle; `docs/BG-SORINA-REVIEW-TYPE.md`
- Darkening the brand pink for contrast (accessibility 96, `--pink` 3.08:1)
- Whether the old site had brand archive pages and at what base (93 redirects)
- Bulk printing: cap of 100, skip cancelled orders, "Sheet 3 of 12" line
- Optional: Mega Menu colour "Clear" option

### 7.4 Things he chose to leave as they are

- Tabby's refusal wording ("Tabby said no") on expired sessions — leave.
- Stripe's "abandoned" cancellation wording — leave.

---

## 8. Recently shipped (2.60.400 → 2.60.493)

Packages handed to the owner in this stretch, newest first. Full detail and
admin paths in `docs/CHANGELOG.md`.

| Version | What |
|---|---|
| .493 | Urgent: phone search field always its own row ≤900px (logo no longer squeezed, no flicker on resize) |
| .492 | Urgent: lotus logo no longer fills the screen on older phones / in-app browsers (round() and color-mix() only inside @supports; SVG carries width/height; hard max size) |
| .491 | Checkout: "Already a member? Sign in" (option B, owner's pick) — Appearance → Checkout page → Coupon, express & sign in |
| .490 | Checkout Contact bar: "Not you? Clear details" off (Checkout page → Fields & attention), small user icon before "Sign in" (Checkout page → Coupon, express & sign in) |
| .489 | Cart panel: the product picture and name open the product page |
| .488 | Hotfix: a sending campaign no longer pulls the admin back to Review & send after you open another screen |
| .487 | Payments: Countries per method (Store → Payments → method → Countries); Cash on delivery UAE only; the payment list re-draws when the shopper changes country |
| .486 | Breadcrumb on one line (fades at the end), no 44px phone rows (81 → 37px), font size and line spacing per device — Appearance → Header → Breadcrumbs. Also ships the inert reviews-section partials |
| .485 | Cart panel: free-delivery bar shows when the bag is opened on any page (the page-load panel had no country) |
| .484 | Marketing emails: every footer line editable (Footer + unsubscribe → Footer words); a started campaign says "Locked" and offers Duplicate to edit |
| .483 | Every "Reset to default" asks Are you sure (Yes / No) |
| .482 | Category breadcrumb Home / Shop / Skincare sets |
| .481 | Reorder: parent categories show and order all sub-category products; Save applies every queued move |
| .480 | "How was your order?" review-request email, review page, reviews into approval |
| .479 | Set editor product search word by word |
| .478 | Product editor saves the brand again |
| .477 | Analytics: time on site & engagement block |
| .476 | Orders 4th in menu; Cart Tracking shows browser and device |
| .475 | Shipped email first line once; checkout back-to-top right; email product cards never one column |
| .474 | Marketing: Personal letter style for Gmail Primary; replies reach the owner |
| .473 | Reorder no longer sticks on Loading; Catalog faster |
| .472 | Send order link on failed/unpaid orders |
| .471 | New Order layout; Catalog 100/200/300 per page |
| .470 | Marketing: bounces list, safe Workspace sending, "New look, less prices" design, campaign report, Returns removed |
| .467–.469 | Payment journey on every order; Tamara/Tabby/Stripe refusals say why; Tamara capture state; Tamara hotfix |
| .463–.466 | Speed: pixels after first interaction, lighter phone pictures, cheaper animations |
| .453–.462 | Checkout polish series; Apple Pay / Google Pay buttons; VAT line |
| .440–.452 | Logo D; typo-tolerant search; analytics realtime; Instagram; firewall; domain-switch installer; contact inbox; pixels upgrade |
| .400–.439 | Owner app; shop app install; menu editor; push notifications; category trees; 3–6× faster inner pages and instant navigation (.411); Stripe settings; image SEO |

Older history: `KBB-Master-Plan.md` phases and `docs/CHANGELOG.md`.

### Which of these are on the live server?

The repository cannot know what the owner has applied. He applies packages
promptly and in order; the last ones handed over were **.484 to .490**. Confirm
with him (Platform → Core Updates → History) or by SSH
(`php artisan migrate:status | tail` — each release's `clear_caches_*` migration
shows Ran or Pending) before building the next package, and record the answer in
`docs/REPO-STATE.md`.

---

## 9. Known gaps and debts

- **Laravel 11 advisories** (CRLF in the email rule, signed-URL path confusion):
  fixing needs the 12.x upgrade, planned in `docs/LARAVEL-UPGRADE.md`. CI reports
  them without blocking.
- **Signed update packages** (Lane B) on hold by the owner.
- **Server version record** (§8) — the one fact the repo cannot hold by itself.
- **`docs/LANE-BRIEFS.md`** describes no running lanes now; write the next
  round's briefs there when lanes start.
- The 157 "true for the wrong reason" test assertions (Lane PLC, `tools/plc-needle-*`)
  — 88 remained when that lane last reported; settle the rows in the files you
  touch.

---

## 10. Your first hour in the new account

1. Clone, install, run `KBB_WP_DB=kbb_wp_main vendor/bin/pest --compact` once to
   know the baseline (expect ~11,806 passed).
2. Ask the owner which version Core Updates shows as last applied, and whether
   `kbeautybliss.com` is now the live domain. Record both in `docs/REPO-STATE.md`.
3. Confirm the cron is alive (§2).
4. Put §7.1 and §7.2 in front of him (open the two preview pages) and ask for the
   letters — they are the next features.
5. Keep this file current: when a decision is made or a release ships, update §7
   and §8 in the same commit.

---

## 11. The apps, Arabic and the other systems, one by one

Each entry: what it is, where the owner controls it, where the code is, and the
rule that keeps it safe.

### 11.1 Site App — the shop as an installable app

- **What:** customers can install the shop to their home screen (a PWA). Android
  installs directly from the footer's **Install App** button; iPhone shows the
  Share → Add to Home Screen steps. Installed apps get **Update App** prompts when
  a new version is out, an offline page, and the shop's own icons.
- **Admin:** **App → Site App** (name, colours, the four icons, install button,
  install prompts).
- **Code:** `routes/site-app.php` (manifest `/manifest.webmanifest`, service worker
  `/sw.js`, `/site-app.js`, icons, `/offline`), `SiteAppController`,
  `routes/site-app-admin.php`. Shots and wiring notes: `docs/pwa-shots/`,
  `docs/pwa-wiring.json`.
- **Rules:** these files are served from the stateless group (no cookies) and
  above every catch-all route. The service worker must never cache cart,
  checkout, account, admin or `/api` (same exclusion list as instant navigation
  in `CLAUDE.md`). A route change ships with a cache-clear migration.

### 11.2 Push notifications

- **What:** shoppers who installed the app can allow notifications; the owner
  sends offers or news to them.
- **Admin:** **Growth & Marketing → Push Notifications** (compose, audience,
  schedule, results).
- **Code:** `routes/site-app-push.php` (public subscribe / unsubscribe / viewed /
  click endpoints, throttled), `routes/push-admin.php`, `PushTick` (sent by the
  scheduler — needs the cron, §2).

### 11.3 Owner App — the owner's own phone app

- **What:** a private app for the owner and the team members he chooses, design
  **"Petal"**, modelled on the WooCommerce mobile app he showed us
  (`docs/OWNER-APP-REFERENCE.md`): My store (sales, visitors, conversion, chart,
  top performers), Orders (list, detail, status, notes, "Send order link"),
  Products (list and quick edit), Customers, Cart Tracking, Analytics /
  Realtime, and a customisable dashboard. It installs to the phone's home
  screen like the Site App.
- **Where it lives:** at a **secret address** — `/<secret>/` — never linked from
  the shop. The address comes from `KBB_OWNER_APP_PATH` in the server `.env`, or
  else the `owner_app_path` setting (generated by a migration). With neither,
  the app is not registered at all (fails closed). **Never print the address in
  a reply or a commit.**
- **Sign-in:** first use on a device = email + a PIN (enrolment); after that,
  the PIN unlocks it. It has its own two HttpOnly cookies scoped to its path
  and an HMAC header instead of the shop's session/CSRF — nothing is shared
  with the admin or the storefront.
- **Admin:** **App → Owner App** (who may use it, their PINs, the icon, and
  **Customise app**: which screens and functions each person sees — a screen
  switched off answers 403 even if called directly).
- **Code:** `routes/owner-app.php`, `routes/owner-app-admin.php`,
  `app/Services/OwnerApp/` (`OwnerAppPath`, `OwnerAppAuth`, `OwnerAppSession`,
  `OwnerAppUiGate`). Many features ship to the admin **and** the owner app
  together (Cart Tracking, Realtime, payment journey, Send order link) — when you
  change one, check the other.

### 11.4 Arabic

- **What:** a full Arabic version of the shop, written by the owner (or machine
  translated and then corrected) — never Google's on-the-fly page translation.
  English addresses do not move; Arabic sits under **`/ar/`**
  (`/ar/shop/`, `/ar/product/…`); `/en/…` redirects to the plain English
  address. Right-to-left layout, the Arabic font (Cairo on the shop; IBM Plex Sans
  Arabic in emails), Arabic order and marketing emails, Arabic SEO (hreflang, Arabic
  slugs where set).
- **Admin:** **Translation → Language settings** (Arabic on/off, right-to-left
  on/off), **Progress** (what is still untranslated), **Strings** (every
  interface sentence, English beside Arabic), **Machine translation** (Google
  provider; fills drafts which the owner then corrects; an estimate is shown
  before a run). Products, categories, pages and posts have Arabic fields in
  their own editors.
- **Code:** `app/Services/Translation/` (`InterfaceStrings` — the English
  source of every interface string; `ArabicInterfaceDrafts` — the Arabic
  drafts; `DatabaseTranslationLoader`, `TranslationStore`, `GoogleProvider`,
  `MachineTranslationRunner`), `routes/translations-admin.php`,
  `SetLocaleFromPath` middleware. Plans and records: `docs/BILINGUAL-PLAN.md`,
  `docs/FS-ARABIC-TYPOGRAPHY.md`, `docs/SEO-ARABIC-PARITY.md`,
  `docs/SEO-ARABIC-SLUGS.md`, `docs/fn-translation-at-scale.md`.
- **Rules:** every new shopper-facing sentence goes through `__()` with an entry in
  `InterfaceStrings` **and** an Arabic draft (usually a small migration seeding
  the drafts, e.g. `*_seed_*_arabic_drafts.php`). Every preview and screenshot is
  taken in English and Arabic. A render in Arabic must not leave the next
  English email or page in Arabic (`CampaignRenderer` restores the locale).
  Whether Arabic is switched on for shoppers today: check Translation →
  Language settings on the live admin.

### 11.5 Marketing Emails

- **Admin:** **Growth & Marketing → Marketing Emails** (Campaigns, Templates,
  Customer groups, Reports) and **Bounces & unsubscribes**; sending setup in
  `docs/EMAILS-PLAN.md`, bounces in `docs/EMAIL-BOUNCES-SETUP.md`.
- **How it sends:** no queue worker. `CampaignSender` steps through the list in
  small batches within the per-minute rate and daily cap; the steps are driven by
  the open admin page **or** the scheduler (`kbb:campaigns-step`, every minute —
  needs the cron, §2). Never from a shopper's page load.
- **Leaving Review & send (2.60.488):** the page's own send loop and every
  repaint check `ours()` (the screen is still the one on show). Before this, a
  sending campaign repainted itself over Dashboard every few seconds. Any new
  async screen code must do the same — `MarketingSendLoopStaysPutTest`.
- **Rules:** a campaign that has started is **locked** (its report must match what
  was sent) — duplicate it to edit. The footer (unsubscribe) block is required
  and always last; its words are editable (2.60.484) but the unsubscribe link can
  never be removed.

### 11.6 Breadcrumbs

- **Admin:** **Appearance → Header → Breadcrumbs** — on/off per device, space
  above/below, inner padding, and (2.60.486) Long breadcrumb (one line that
  fades at the end — shipped — or "…", or wrap), font size and line spacing per
  device. Printed by `HeaderSettings::breadcrumbCss()` as one small style block;
  `BreadcrumbControlsTest`, `BreadcrumbOneLineTest`.

### 11.7 Analytics, Cart Tracking, Instagram, WhatsApp

- **Analytics** (Dashboard → Analytics; also in the owner app): Realtime, Online
  now, funnel, sources, time on site. Day summaries are rolled up by the
  scheduler (`Rollup::cronAlive()`).
- **Cart Tracking** (sidebar, top): live baskets with country, browser and device.
- **Instagram** (Content → Instagram): Connect with Facebook, embeds by pasted
  address, the "Spotted" page. Meta app setup is the owner's account.
- **WhatsApp button**: Appearance → WhatsApp button (`routes/whatsapp-button-admin.php`);
  it is hidden on cart and checkout by the owner's choice.

---

## 12. Payment incidents we solved — the playbook for next time

Payments are **frozen** (`CLAUDE.md`, 10 October): change them only for a real
payment fault, with the gateway's own refusal text in hand, run the payment test
set (`Tamara* Tabby* Stripe* Wallet* Checkout* Order* Payment*`), and have the
owner place a real order afterwards. The four methods are **Stripe** (cards,
Apple Pay, Google Pay), **Tabby**, **Tamara** and **Cash on delivery**, set up at
**Store → Payments**. Code: `app/Services/Payments/`, the checkout controllers,
`resources/js/kbb/checkout.js`, `partials/checkout/express-wallets.blade.php`.
Guides: `docs/STRIPE-TEST-GUIDE.md`, `docs/WALLETS-APPLE-GOOGLE-PAY.md`,
`docs/FU-STRIPE-CARD-FIELDS.md`, `docs/FY-CHECKOUT-CARD-AND-PREFILL.md`.

### First move on any "payment failed" report

1. Open the order: **Orders → (order) → Payment journey** (also in the owner app).
   Since 2.60.467 it gives a one-line verdict and, for a refused start, the
   gateway's HTTP status, error code and field. Customer journey and Emails cards
   sit beside it.
2. Read `storage/logs/laravel.log` (live logs only ERROR — every refusal is
   written at ERROR on purpose) and **Store → Payments → (gateway) → Payment log**.
3. Check the gateway's own dashboard for that order reference.
4. Decide: our fault (code), the gateway's refusal (data we sent), or the shopper
   did not finish (expired / abandoned). Only the first two are ours to fix.

### What went wrong before, and the fix that is now locked in

| When | Symptom on the shop | Root cause | Fix (release) — test that guards it |
|---|---|---|---|
| Oct | Stripe test card: "Your bag is empty" on every retry | A refused card left the basket locked | Refusal reopens the basket at once; no duplicate orders or double stock (.425) — `CheckoutCardRetryTest` |
| Oct | Owner had to re-enter API keys on every save | Saving settings overwrote stored keys with blanks | A blank key box keeps the saved key (.425) |
| Oct | "We could not reach our card processor" | Four of our faults: statement suffix digits-only (Stripe refuses); publishable/secret keys in the wrong boxes accepted; test/live keys mixed; a second copy of the shop on the same keys blocked an order reference for 24 h | Suffix now `KBB* ORDER 10234`; key boxes validated; mixed keys switch cards off with a red reason box; one retry with a fresh reference (.429) |
| Oct | Place order dead in Firefox, fine in Chrome | Firefox restores a button's disabled state across reloads | `autocomplete=off` on the buttons, re-enabled on every fresh checkout, only this page's token sent (.431) |
| Oct | Bag empty after a cancelled / declined / abandoned payment | The basket was consumed when the order was created | `BasketRelease::failAndRestore()` gives back items, quantities, sets, coupon, gift wrap and stock on cancel, decline, timeout, Back or tab close — every method (.436) — `CheckoutCardRetryTest` |
| Oct | A card in its 3-D Secure check cancelled from another tab; "Total to pay" on a paid order; iPhone zooming on card fields | Cross-tab release too eager; status text; Stripe fields 14px | 15-minute protection during 3-D Secure; "Confirming payment"; 16px Stripe fields on touch screens (.440) |
| Oct | **Apple Pay / Google Pay never showed, on any phone** | Two faults: wallets mounted into a hidden zero-width box (Stripe draws nothing), then a layout Stripe refuses (`overflow:'never'` needs `maxRows: 0`) so Stripe threw | Mount box hidden with `visibility:hidden`, never `display:none`; `layout: { maxColumns: 2, maxRows: 0, overflow: 'never' }`; Apple domain file up to 32 KB; on-phone diagnostic `/checkout/?kbbdiag=1` (.453, .454) — `WalletPaymentsTest`, `AppleDomainFileTest`, `WalletMoneyPathTest` |
| 10 Oct | Tamara order #56187 failed, no reason anywhere | The refusal reply was dropped and logged at info (live discards info) | Every refused start (Tamara, Tabby, Stripe) stored with status, code and field on the order note, payment log and laravel.log at ERROR; phone/email masked, tokens never written (.467) — `PaymentJourneyTest` |
| 10 Oct | Every returning shopper refused by Tamara: "HTTP 400 — Invalid date format; risk_assessment_wrong_data_format" | Risk dates sent as ISO-8601 | `date_of_first_transaction` and `account_creation_date` as **DD-MM-YYYY**; a 400 naming `risk_assessment` re-sends once without that optional block; other refusals not retried (.468) — `TamaraGatewayParityTest` |
| 10 Oct | Tamara order showed "Capture AED 85" though Tamara had captured | Tamara auto-captures on this account | When Tamara reports captured at approval, the order records it; no capture call (.469) |
| 10 Oct | Tabby order #56192 "failed" | Not ours: the Tabby session **EXPIRED** — the shopper did not complete | No change. Owner chose to keep the wording; Tabby confirmed working with his own real order |
| 10 Oct | Stripe #56195 "abandoned" | Not ours: no card was ever tried; our clean-up closed the unused PaymentIntent 24 s later, which Stripe labels `abandoned` | No change, by the owner's choice |

### Countries per method (2.60.487)

Each method is offered only in the countries ticked at **Store → Payments →
(method) → Countries** (none ticked = every country). Cash on delivery ships
UAE only, at the owner's request. `GatewayCountries::allows()` is the one rule;
it runs inside `GatewayRegistry::availableFor()` (checkout list, the
country-change re-draw, Place order, the pay-link page) and in
`Api\CheckoutController`. A "payment method is not available" refusal on a
non-UAE COD order is this rule working. `PaymentCountriesTest` is its lock.

### Earlier, for the record

- **Stripe settings like the WordPress plugin** (.422): statement descriptor,
  order-number suffix, Test/Live keys with a badge, automatic webhook set-up (and
  removal of an old-domain webhook), authorise-now-capture-later, receipts,
  payment log. A test key can no longer take live payments.
- **Stripe webhook after the domain change:** if the domain moves, re-run "Set up
  webhook automatically" at Store → Payments → Stripe, and re-register the Apple
  Pay domain in Stripe (`docs/DOMAIN-MOVE-KBEAUTYBLISS.md` lists every outside
  service: Stripe, Tabby, Tamara, Meta, Google).
- **Cash on delivery** is hidden by order-value window via Store → Payment &
  Shipping Rules, enforced on the page, the form submit and the API.

---

## 13. The complete history

Three records, from broad to detailed:

1. **This timeline** — the eras and the turning points.
2. **`docs/RELEASE-HISTORY.md`** — every package from 2.60.72 to 2.60.485, one
   line each (266 entries), generated from the changelog by
   `tools/release-history.py`.
3. **`docs/CHANGELOG.md`** (every release in full, with the owner's words and
   the admin path) and **`KBB-Master-Plan.md`** (the phases, the reasoning, and
   the releases before 2.60.72). The git log (3,000+ commits since 10 Sep 2026)
   is the last word.

### Before the repository (up to 2.60.41)

- The original shop was **kbeautybliss.com on WordPress/WooCommerce**, run by a
  custom plugin (`wordpress-plugin/`, v2.39.0: 7,359 lines, 29 modules) and a
  custom theme. The Laravel port started from that plugin, module by module
  (`KBB-Master-Plan.md` → "The plugin, inventoried").
- Releases 2.38.0 → 2.60.41 were built and handed over as zips to a staging
  server on Hostinger (`easywebsol.com/kbb-upgrade`, no shell — the reason the
  zip updater exists at all). Phases 1–8: foundation, storefront chrome, module
  framework, search, product cards, product page, account area, cart and checkout.
- **The 2.60.36 episode:** that release's notes contained angle brackets, the
  Core Updates card printed them as HTML and broke, and the server silently stuck
  at 2.60.36 while everyone believed 2.60.41 was live. Fixed in .39–.41 (escaping,
  one pending-update key, a Cancel, the permanent patch archive).

### September 2026

| Date | Turning point |
|---|---|
| 10 Sep | **GitHub repository created** at the verified server state (2.60.36, not the assumed .41). `docs/REPO-STATE.md` was written so the repo always says what it holds |
| 14 Sep | Synced to **2.60.107**; `tools/kbb-manifest.php` drift report. **Packages .102–.106 withdrawn**: built from a stale tree, applied anyway, reverted three files and 500'd every product page → "diff every package against the repo". Owner's scope: all four gateways (Stripe, Tabby, Tamara, COD), server-side mail, his brands and blog content |
| 15–17 Sep | 2.60.108 → .200. Phase 8b (admin screens, engine parity, import foundation), **8c "Telling the truth"** (money, the shop's clock, delivery promises, claims with nothing behind them), 8d (what the shop publishes). Parallel lanes begin — five at once at .193. **Arabic approved** (.199) |
| 18–23 Sep | → .250. Content pages, Build my routine (.207, off), performance and SEO, the WordPress data importer; **Security module requested** (.239) |
| 20–24 Sep | **Go-live on `extrabeauty.ae`**, a new server on **Cloudways — with SSH**. (`docs/CUTOVER-EXTRABEAUTY.md` was first written for a Hostinger account with no shell; the shop actually landed on Cloudways, which `CLAUDE.md` corrected on 24 Sep.) The owner cloned the repo onto the server himself (20 Sep) |
| 24 Sep | **The updater was bricked for three hours**: five hand-built packages in one afternoon shipped eight migrations without `"migrations": true`, so they never ran; a missing column plus a swallowed exception turned every apply into "Server Error". Fixed at the door (`verify()` refuses that shape) and by rule: **`kbb:package`, always**. Same day: dedicated SEO research lane (against kbeautyarabia.com), UGC shoppable videos requested, **package signing ("Lane B") put on hold** |
| 27 Sep | 2.60.300 |
| 29 Sep | Shared-scratchpad collision between lanes → namespaced logs rule |
| 30 Sep | 2.60.327. **"What he asked for ships ON"** rule. **Ledger** product-page design approved. Lanes cut from five to **three** (about 40% cheaper, barely slower). Server's migration status checked by SSH for the first time |

### October 2026

| Date | Turning point |
|---|---|
| 1 Oct | **The real WordPress data imported** — products, categories, brands, customers, orders and 209 reviews (164 approved, 45 pending) |
| 2 Oct | 2.60.350. Product page as ordered sections, category header boxes |
| 4 Oct | "Work smart, minimal tokens; code super light, secure, optimized, bug-free" rule. SEO keyword research request |
| 5 Oct | **2.60.400 — the Owner app** ("Petal", PIN-locked, secret address). Site App install, push notifications follow (.404–.407) |
| 6 Oct | **2.60.411 — inner pages 3–6× faster, pages open on intent → "Speed is frozen".** Domain move to kbeautybliss.com planned (`docs/DOMAIN-MOVE-KBEAUTYBLISS.md`, installer .423/.442). Policy pages rewritten from his text |
| 7 Oct | Mega-menu hover bridges made 4 of 15 clicks dead (.416→.418) → **every front-end change owes the 7 Oct checks**. Address fields: Building / Area / Emirate |
| 8 Oct | Instagram via Facebook login, breadcrumb rules |
| 9 Oct | 2.60.450. Logo D (lotus lockup), About us text, analytics Realtime, firewall; **Skin concerns** brief |
| 10 Oct | **Payments day**: Tamara #56187 diagnosed → refusal reasons on every order (.467); Tamara date-format hotfix (.468); Tamara auto-capture (.469) — owner's real Tamara and Tabby orders went through → **"Payments are frozen"**. Marketing emails grew (bounces, designs, reports, Personal letter .470–.474). Reviews-section designs A–H previewed; review-request email (.480); Reorder for parent categories (.481) |
| 11 Oct | Breadcrumb (.482), reset confirmations (.483), marketing footer words + locked note (.484), cart-panel free-delivery bar (.485). **Scheduler cron installed on Cloudways** — campaigns now send in the background. **Handover** to a new account: this document, the patch reference, the verifier, and the two preview lanes merged |
