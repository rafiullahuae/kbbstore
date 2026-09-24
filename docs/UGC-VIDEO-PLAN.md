# Shoppable UGC video — the plan, and the previews to choose from

Phase 20. Lane V, round one. **Nothing in this round ships to the shop.**

No storefront change, no migration, no admin screen, no setting, no route. The
owner asked to *"plan it super well and build the previews for now"*, so this
round is a written plan and a page of previews he can scroll on his phone and
point at. Building the grid before he has picked its shape is an hour spent on
the wrong grid.

**The previews:** `docs/UGC-VIDEO-PREVIEWS.html` — one self-contained file,
459 KB, opens from the filesystem with no server, no build step and no network.
Six grid styles, four opened states, all with real catalogue names and AED
prices, portrait placeholder clips at a true 9:16, creator handles and discount
pills. Pictures of every one at 390px and 1280px are in
`docs/ugc-preview-shots/`:

| File | What |
|---|---|
| `grid-s1..s6-*-390.png` / `-1280.png` | each of the six grid styles, at both widths |
| `player-A..D-*.png`, `player-A-expanded-*.png` | each of the four opened states, and the sheet both collapsed and expanded |
| `compare-390-problem-*.png` | **all four players side by side at true 390px** — the picture to look at first |
| `rtl-s1/s2/s6-*.png`, `rtl-player-A-*.png` | the same, flipped to Arabic and RTL |
| `budget-table-*.png` | the performance budget as the owner sees it on the page |
| `measurements.json` | `scrollWidth`, overflow and the decoder-count run, machine-readable |

Captured in Chromium at deviceScaleFactor 2, except the two twelve-tile grids
(`grid-s3-wall-*`, `grid-s5-two-*`) at 1× — they are judged on density and
rhythm rather than on reading a price off a tile, and at 2× they were half the
weight of the whole directory.

---

## 1. What there is to choose from

### Six grid styles

| # | Style | In one line | The cost |
|---|---|---|---|
| 1 | **Horizontal rail** | The benchmark rebuilt properly: a sideways-scrolling row of portrait videos, one product card pinned under each, with snap points so a card never stops half-shown. | Sells **one** product before a tap. Cheapest to build, ~330px tall on a phone, four clips fills it. |
| 2 | **Peek carousel with product chips** | Cards wide enough to read and narrow enough that the next is visibly cut off, with a scrollable row of chips under each — one chip per tagged product. | Sells **every** tagged product with no tap at all. ~420px tall; looks thin unless clips carry 3+ products. |
| 3 | **Masonry wall** | Mixed 9:16, 4:5 and 1:1 tiles packed into columns with a product-count badge instead of a card. Two columns on a phone, four on a desktop. | A browsing surface, not a strip — wants **12+ clips** or it looks empty. Sells nothing until a tap. Accepts clips that are not 9:16. |
| 4 | **Hero plus satellites** | One large clip that plays muted in place as it scrolls into view, four small ones beside it. Stacks on a phone, side by side from 820px. | Strongest first impression; one clip carries the whole section, and it is two layouts to keep right. |
| 5 | **Two-up compact** | Posters only, nothing mounted until a tap, two per row on a phone and six on a desktop, one price line under each. | **Zero video bytes until a tap** — the only style that is free on a slow phone at any length. No motion, so less pull. |
| 6 | **Story strip** | Circular thumbnails in a gradient ring, the Instagram-stories shape, with stacked product faces showing how many are tagged. | ~120px tall in total, so it fits above the fold on any page. Crops 9:16 to a circle; the caption has to be two words. |

**Recommendation, if the owner wants one.** Style 2 on the homepage and style 3
as a `/videos` page. Style 2 is the one that actually beats the benchmark on the
thing he asked for — several products sold per clip — and it does it *before*
anyone opens anything, which is where most of the revenue in a shoppable rail
is. Style 3 gives the library somewhere to live once it is bigger than a strip.

### Four opened states

| # | Player | In one line | Video left visible | The cost |
|---|---|---|---|---|
| A | **Sheet under the video** | Full-bleed clip with a bottom sheet resting on it; collapsed it shows one card and the edge of the next, expanded it is the full list. | 74% / 26% | Closest to the benchmark, so least risky. Scales to ~8 products. |
| B | **Card rail over the video** | One product card at a time swiped sideways across the foot of the clip, with dots for the count and an ADD on every card. | 78% | Products are found by swiping rather than scanning; past five the dots stop meaning anything. |
| C | **Split** | Desktop: clip left, a full product column right with a running total and *add the whole routine*. Phone: the clip shrinks to the top 58% and the column takes the rest — it does not become an overlay. | 58%, never covered | The one that sells a three-step routine **as a routine**. Gives up the most video. Unlimited products. |
| D | **Timeline-tagged** | Each product is pinned to the second of the clip where it appears, so the card changes as she picks up the next bottle. The dots under the scrubber are the product list; tap one to jump. | 76% | The one the benchmark cannot do at all. Somebody has to type a timestamp per product. |

**Recommendation.** **C on desktop, A on a phone** if he will accept two, and
**A alone** if he wants one. C is the strongest commercially — a running total
and an add-all is how a K-beauty routine gets sold as a set — but 58% of a phone
screen is a real sacrifice and he should look at the picture before agreeing to
it. D is the most impressive and the most expensive to *operate*: it is
beautiful on the ten clips somebody times by hand and pointless on the
hundredth.

`docs/ugc-preview-shots/compare-390-problem-*.png` is the picture to look at
first: all four players, at true 390px width, side by side.

---

## 2. Performance — the acceptance criterion

*"without stucking, handing or delaying"* is the requirement that decides
whether this ships, so it is designed in rather than tuned afterwards.

| What | Budget | How it is held |
|---|---|---|
| Bytes before a tap | one poster each, ~18–28 KB WebP | `preload="none"`, and no `<video>` element carries a `src` until intent. |
| **A rail of 12 on a mid-range Android on 4G** | **~260 KB, ~0.9 s, 0 video bytes** | Twelve posters at ~22 KB. A rail that is scrolled past costs exactly its posters — see below. |
| A clip, once opened | ~320 KB for the first 3 s | 720×1280 at ~800 kbps, range-requested. Not the whole file. |
| Videos decoding at once | **exactly 1** | One arbiter owns the playing element; mounting a second pauses and unloads the first. |
| Layout shift | **0** | `aspect-ratio:9/16` on the tile. The box exists at first paint, before any media. |
| Scroll handlers | **0** | `IntersectionObserver` to mount, CSS `scroll-snap` to page. |
| Main-thread work per tile | one element swap | A poster `<img>` becomes a `<video>` once. No per-frame JavaScript anywhere. |
| Off-screen | released | A tile leaving the viewport pauses, drops its `src` and calls `load()`, which frees the decoder. |

**Why the rail of twelve is ~260 KB and not ~4 MB.** The number that kills a
shoppable rail is video bytes fetched for clips nobody watched. So no clip is
ever fetched by scrolling past it. Only two things cause a fetch: the tile the
IntersectionObserver reports as more than 60% in view in an autoplay style
(never more than one at a time), and a tap. In styles 5 and 6 not even that —
they mount on tap only, so twelve clips cost twelve posters, full stop.

**Why `load()` and not just `pause()`.** A paused `<video>` keeps its decoded
stream resident. Scrolling a forty-clip wall would accumulate forty of them and
the page would die a third of the way down. Dropping the `src` and calling
`load()` is what actually hands the decoder back.

**No JavaScript measures layout.** This project forbids the element-measuring
APIs by name in `CheckoutFloatingBarGateTest` and `CartPageSqueezeTest`
(`getBoundingClientRect`, `offsetTop`, `offsetHeight`, `clientHeight`,
`scrollY`). Everything positional here is `aspect-ratio`, `scroll-snap`,
`inset-inline-*` and `IntersectionObserver`, which is the sanctioned answer.
The timeline player in D is driven by the video element's own `timeupdate`
event — an event it already fires — not by a timer and not by `requestAnimationFrame`.

**The claim is checkable, not asserted.** The preview page carries a live meter
reading `decoding N / 1 · mounted N`. Driven hard — 67 samples across a fast
full-page scroll of a 14,723px page at 390px — it never left
**maxDecoding 1, maxMountedVideoElements 1**. That measurement is in
`docs/ugc-preview-shots/measurements.json`.

**Horizontal overflow, measured at both widths and both directions:**

| | 390px | 1280px |
|---|---|---|
| `document.documentElement.scrollWidth` | 390 | 1280 |
| `scrollWidth − clientWidth`, LTR | **0** | **0** |
| `scrollWidth − clientWidth`, RTL | **0** | **0** |
| Console errors | none | none |

---

## 3. The video source — self-host, and here is the arithmetic

**Verdict: self-host. Do not embed Instagram or TikTok.** The admin should
*accept* an Instagram or TikTok URL, because that is how the owner thinks about
his library, and resolve it to a stored file rather than embedding at render
time. That is the master plan's position and testing it did not overturn it — but
two of the reasons are stronger than the plan states, and one argument runs the
other way and deserves saying out loud.

### 3.1 The strongest argument is the content-security policy, and it is measurable

`app/Services/Security/ContentSecurityPolicy.php` landed hours ago and its
directives were written from what this shop actually serves, line by line, with
a comment on each source naming the page that needs it. Two of them decide this:

```
media-src 'self'
frame-src https://js.stripe.com https://hooks.stripe.com
```

- **Self-hosting needs no CSP change at all.** A same-origin `.mp4` is already
  covered by `media-src 'self'`, and the poster is covered by `img-src 'self'`.
  Zero new hosts, zero new directives, zero edits to a file another lane wrote.
- **An embed needs four directives widened**: `frame-src` and `script-src` for
  the player and its loader, `connect-src` for its telemetry, `media-src` for
  the CDN the media actually comes from — and for Instagram that is a moving set
  of `*.cdninstagram.com` and `*.fbcdn.net` names. That is not a tidy addition.

And it is worse than an ordinary widening, because of *where the module is in
its sequence*. Phase 18 is deliberately **report before enforce**, and CSP is at
step three of five: the policy ships as `Content-Security-Policy-Report-Only`
and the *violations it collects are the input to the round that enforces*.
Putting a third-party player behind it pollutes exactly that dataset — the
violation list stops being a measurement of this shop and becomes a measurement
of Instagram's player. The one round that needs clean numbers is the next one.

### 3.2 Core Web Vitals, and the silent breakage

- An embed drags a third-party player, its stylesheet and its tracking onto
  **every page that carries the rail**, whether or not anybody plays a video.
  The budget in §2 — 260 KB for a rail of twelve — becomes roughly that per
  *embed*, before a single frame of video. This project has spent phases
  protecting these numbers.
- An embed **breaks silently** the day a creator deletes the post, makes the
  account private, or the post is taken down. The rail does not error; it goes
  blank, and nobody finds out until someone looks. A stored file cannot do that.
- An embed cannot be tagged with several products, which is the whole feature.
  The benchmark's one-product limit is a *consequence* of somebody else owning
  the player. Self-hosting is what makes requirement one possible at all.

### 3.3 The argument that runs the other way, and it is the legal one

**Embedding is the licensed way to display someone else's Instagram post.
Re-hosting it is not.** Instagram's terms contemplate embedding; downloading a
creator's video and serving it from `extrabeauty.ae` is a reproduction, and the
creator owns the copyright in it — being tagged in it, or it being about a
product he sold them, grants nothing. If a creator objects, the shop is in the
wrong, and "we saw it on Instagram" is not a defence.

**This is a real argument for embedding and the answer is not to embed, it is to
get permission.** So the data model in §4 carries rights as a first-class column
rather than a note in a spreadsheet:

- `rights_status` — one of `pending`, `granted`, `refused`. A video cannot be
  published unless it is `granted`. Fails closed.
- `rights_granted_at`, `rights_evidence` — the date and where the permission is
  recorded (a DM screenshot, an email, a signed release).
- The public storefront credits the creator by handle and links back to the
  original post, which is both the decent thing and the thing most creators
  actually want.

The honest shape of this: **a one-line DM asking "may we feature this on our
shop?" and a yes in writing** is fifteen minutes per creator and removes the
only genuine argument against self-hosting. If the owner will not do that step,
the answer is not to embed instead — it is **not to feature that creator**, and
link out to their post with an ordinary `<a>` (no CSP host, no player, no
tracking) if he wants to point at it.

### 3.4 What it costs him on Cloudways

**Storage is not a concern.** A 15-second 720×1280 H.264 clip at ~1.2 Mbps is
about **2.2 MB**, plus a ~25 KB poster. Fifty clips is **~110 MB**. A hundred is
~220 MB. That is noise next to the product images already on the box.

**Bandwidth is the number to watch, and it is manageable.** Bytes are only spent
on clips that are actually opened. At 10,000 opens a month and ~2 MB each that
is **~20 GB/month**; posters on top add ~22 KB per rail impression, so 100,000
page views carrying a rail of twelve is ~26 GB. Call it **50 GB/month at real
traffic**, which is within a standard Cloudways allowance but is not free at
ten times that. If it ever becomes one, the answer is a CDN in front of
`/uploads/ugc/`, which is a DNS and origin change and needs no application work.

**The encoding step is the honest problem, and it needs an answer before it is
designed.** `CLAUDE.md` was corrected on 24 September: the live shop is
`extrabeauty.ae` on Cloudways and **it has a shell** — `migrate`, `config:clear`
and the logs are all reachable over SSH. That is much better than the shell-less
box this project used to design around. But two things still do not exist there:

- **No queue worker and no cron.** `docs/LC-SECURITY-MODULE.md` says it plainly
  for retention — *"There is no cron and no queue worker on this host, so a
  schedule would be a schedule that never runs"* — and `ProductVisibility` sets
  the same precedent by comparing against `now()` instead of scheduling. So
  there is nowhere to put a background transcode job. Encoding has to happen
  in the request, off the box, or by hand over SSH.
- **ffmpeg is not known to be present, and Cloudways managed hosting does not
  give root**, so it may not be installable. **Nobody should design the resolver
  around ffmpeg until somebody has run `which ffmpeg` on that server.** It is a
  ten-second check and it decides the shape of round three.

That splits the work honestly, and it is why §6 stages it:

1. **Round 2 — upload a file.** The owner uploads an MP4 he already has. No
   downloader, no encoder, no ffmpeg, no queue. This works today on that host
   and delivers the whole feature.
2. **Round 3 — paste a URL.** Resolving an Instagram or TikTok URL to a stored
   file needs outbound fetching *and* a re-encode. If ffmpeg is absent, the URL
   field degrades to what it can honestly be — **attribution and a link back**,
   with the file still uploaded — rather than pretending to resolve and failing
   on the owner's first try.

A note on why encoding is needed at all rather than storing what the creator
sent: phone video is frequently 1080×1920 at 8–15 Mbps, which is 20 MB for
fifteen seconds. Serving that unmodified is how a rail becomes the slowest thing
on the shop. The re-encode to 720×1280 at ~800 kbps is roughly a **10× byte
reduction** and is the single biggest performance decision in this feature.
If it cannot run on the server, it must run somewhere — the owner's own machine
with a free tool, or a one-off command a lane runs — and the admin should
**refuse an upload over a size cap** rather than silently serving 20 MB.

---

## 4. The data model

Two tables and a pivot. Nothing here is built this round.

### `ugc_videos`

| Column | Why |
|---|---|
| `id`, `slug` | `slug` so a clip can have its own address later without a migration. |
| `title`, `caption` | The two translatable fields — see §5. |
| `status` | `draft` / `publish`, and `publish` is refused while `rights_status` is not `granted`. |
| `file_path`, `poster_path` | Paths under `/uploads/ugc/`, never absolute URLs, so the shop can move host without a rewrite. |
| `width`, `height`, `duration_ms`, `bytes` | Stored at upload. **The storefront reserves the box from `width`/`height` rather than measuring** — this is what keeps layout shift at zero without script. |
| `source_platform`, `source_url` | `instagram` / `tiktok` / `upload`. Attribution and link-back, never an embed target. |
| `creator_handle`, `creator_url` | Credit. Both end up in an attribute — see §7. |
| `rights_status`, `rights_granted_at`, `rights_evidence` | §3.3. Publication fails closed on these. |
| `locale` | `null` = shown on both storefronts; `en` or `ar` restricts it. A clip spoken in English is not automatically right for the Arabic shop. |
| `position` | Manual order, set by dragging. **Not `created_at`** — the owner will want the good one first, not the new one. |
| `published_at` | Compared against `now()` on read, the way `ProductVisibility` already does it, because there is no scheduler. |

### `ugc_video_product` (the pivot — this is requirement one)

| Column | Why |
|---|---|
| `ugc_video_id`, `product_id` | Many products per video. This is the whole point. |
| `position` | The order they appear in the player; the first is the one the grid shows. |
| `at_ms` | Nullable. The moment in the clip this product appears, used only by player D. Null everywhere else, so nothing forces anybody to type timestamps unless D is chosen. |

A unique index on `(ugc_video_id, product_id)`, and the eager-load is
`with('products.brand')` so a rail of twelve is **three queries, not
twenty-five** — `StorefrontQueryBudgetTest` is a budget, not a suggestion.

### What the grid needs per tile

Poster, width, height, creator handle, caption, product count, and the first
product's name and price. All of it comes from those two tables in one eager
load. No N+1, and nothing computed per tile that could be computed once.

---

## 5. Arabic and RTL, from the start

The storefront already puts `dir` on `<html>` (`resources/views/layouts/store.blade.php`),
`kbb.css` is written with logical properties (`margin-inline-start`,
`inset-inline`), and Cairo is already loaded and already allowed by the CSP's
`style-src`. So most of this is free — but three things are not, and two of them
**actually broke in the previews before they were fixed**, which is the evidence
that they are real rather than theoretical.

1. **Latin runs inside Arabic text reorder.** `@layla.skin` rendered as
   `layla.skin@`, `AED 1,199` lost its comma to the wrong side, and the discount
   pill `-30%` rendered as `30%-`. The fix is `<bdi>` around every creator handle
   and every price — not a `<span>`, not `direction:ltr`, which would also move
   the avatar to the wrong side. This is invisible until the page is flipped,
   which is exactly why it gets designed in now instead of retrofitted.
   `docs/ugc-preview-shots/rtl-player-A-390.png` is the fixed version.
2. **Transforms have no logical form and must be flipped by hand.** The timeline
   markers in player D are positioned with `inset-inline-start` (which flips for
   free) and centred with `translateX(-50%)` (which does not), so RTL needs
   `[dir="rtl"] .mk{transform:translateX(50%)}`. `kbb.css` already carries
   exactly this pattern for the drawer — `[dir="rtl"] .drawer{transform:translateX(-100%)}`
   with a comment explaining the sign — and this follows it.
3. **The rails flip for free and that is correct.** `overflow-x` scrolling
   reverses under `dir="rtl"`, so the first card sits on the right and swiping
   goes the other way, which is what an Arabic shopper expects. Verified:
   `docs/ugc-preview-shots/rtl-s1-rail-390.png`, `rtl-s2-peek-390.png`,
   `rtl-s6-story-390.png`, all at `scrollWidth` 390 with zero overflow.

**Translation.** `title` and `caption` go on `HasTranslations::$translatable`,
the same allowlist `Product` uses. They are short prose with no HTML, so the
machine-translation path would technically accept them — **it should not be
used.** A creator's caption is her voice, and a machine-translated caption
attributed to a named person is putting words in her mouth. Type them, or leave
the English and let the `locale` column keep that clip on the English storefront.

---

## 6. Where it will sit in the admin

Named exactly, in `Section → Screen → control` form. None of it is built yet.

| What | Path |
|---|---|
| The master on/off | `Store → Modules → Shoppable video` |
| The library — list, upload, poster, creator, rights | `Content → Shoppable video` |
| **Tagging several products to one clip** | `Content → Shoppable video → Edit → Products` — search, add, drag to order, optional timestamp per product |
| The rights record | `Content → Shoppable video → Edit → Rights & credit` |
| Which grid style, and where it appears | `Appearance → Shoppable video → Section style` |
| Which opened state | `Appearance → Shoppable video → When a video is opened` |
| How many tiles, and autoplay in view on/off | `Appearance → Shoppable video → Section style` |

`Content` and `Appearance` are both existing sidebar groups — `Content` already
holds `Blog Posts`, `HTML Blocks` and `Media Library`, so the library row sits
beside things of its own kind rather than inventing a group.

**It ships OFF.** A new `ModuleRegistry` row with its default `false`, exactly
like `recently_viewed` and `newsletter`, so applying the package changes no page
until the owner turns it on. Every new setting ships at the value the page
already has. The settings screen uses the `SCHEMA` array shape that
`CartPage`, `CheckoutPage`, `SlimFooter` and `SecurityModule` all use, so a new
option is a line in an array and not a new screen.

**Wiring.** Routes go in `routes/ugc-admin.php` and `routes/ugc.php` and are
`require`d from `routes/web.php` **by the integrator, not by this lane**, and
the package ships a `clear_caches_*` migration beside them so the compiled route
table is dropped when it applies — the convention every route-adding package
here follows.

### Staging

| Round | What |
|---|---|
| 1 *(this one)* | The plan and the previews. No code. |
| 2 | The two tables, the library, the multi-product tagging screen, **file upload**, the chosen grid style and the chosen player. Ships off. |
| 3 | The remaining styles the owner wants, and the source-URL resolver — **after** somebody has answered whether ffmpeg exists on that server (§3.4). |

---

## 7. Security

`/api/*` is unauthenticated on this shop and every endpoint there is public.
Three things follow, and each has a precedent in this tree to copy rather than a
rule to remember.

**What a public video endpoint returns is an allowlist, never a model.**
`GET /api/ugc-videos` returns, per clip and nothing else:

```
slug, title, caption, poster, src, width, height, duration_ms,
creator_handle, creator_url, source_url, products[]
```

and each product goes through the existing `Product::toApi()`, which is already
the allowlist that keeps `wc_id`, `sku` and `total_sales` off the wire. The
pattern is `SettingController::PUBLIC_KEYS` — an explicit list iterated over,
not a model with hidden fields, because a column added later is then invisible
by default instead of public by default. **Never returned:** `rights_status`,
`rights_evidence`, `rights_granted_at`, `status`, `position`, the uploading
admin, any filesystem path, and any timestamp that is not `published_at`.
`tests/Feature/ApiSecurityTest.php` is where the pin for this belongs, beside
the ones that exist because each case leaked in production.

**An admin-supplied URL is a URL that ends up in an attribute.** `source_url`
and `creator_url` become an `href`; `poster_path` becomes a `src`; `file_path`
becomes a `<video src>`. So:

- URLs are **re-parsed, not pattern-matched** — HTML entities decoded and
  whitespace and control characters stripped *first*, then the scheme checked
  against `http`/`https`. That decoding step is the point:
  `java&Tab;script:` and `&#106;avascript:` are the same string by the time a
  browser acts on them. `App\Support\RichText` already does exactly this and its
  docblock explains why; this uses the same helper rather than a second copy.
- Paths are an **allowlist of shapes** — root-relative under `/uploads/ugc/`, or
  nothing. `ReviewWall::photos()` is the precedent, and its comment names the
  trap: `Url::to()` passes any scheme through untouched, so the check has to
  happen *before* it, not inside it.
- `source_platform` is a select, and a select **stores one of its own options or
  the default** — the `SecurityModule::cast()` rule, so a hand-rolled POST of
  something else is stored as the default rather than reaching a `match`.

**Uploads.** Extension allowlist plus magic-byte sniffing (not the browser's
`Content-Type`, which the client controls), a hard size cap that refuses rather
than truncates, a generated filename — never the client's — and storage under
`/uploads/ugc/` which is served static and not executable.

**Admin endpoints.** A new capability `ugc.manage` in `App\Support\AdminCapabilities`,
**failing closed**, on every write route including the upload. Not reusing an
existing capability: the whole point of per-capability gating is that granting
somebody the video library does not grant them anything else.

**One trap worth naming.** `Setting::map()` memoises in a process-level static
as well as the cache, so within one long-lived process it will not see writes
made after the first call. Fine under PHP-FPM; a trap in tests. Any test for the
style selector has to allow for it.

---

## 8. What the owner needs to decide

1. **Which grid style.** Six of them in `docs/UGC-VIDEO-PREVIEWS.html`. Two
   minutes of scrolling. Recommendation in §1.
2. **Which opened state.** Four of them, and
   `compare-390-problem-*.png` is the picture that makes the trade concrete.
3. **The rights question (§3.3).** Will he ask creators for written permission?
   Yes → self-hosting has no downside left. No → those creators do not get
   featured, and that is a content decision, not an engineering one.
4. **Does `ffmpeg` exist on the Cloudways server?** `which ffmpeg` over SSH.
   Ten seconds, and it decides whether round 3 can resolve an Instagram URL or
   whether the URL field is attribution only.
5. **Roughly how many clips, and does he have them yet?** Style 3 wants twelve
   or it looks empty; style 1 is fine with four. The right style depends on the
   library he actually has.

---

## 9. What this round deliberately did not build

No migration, no model, no controller, no route, no Blade view, no setting, no
admin screen, no test. `docs/` is the only directory touched, so there is no
production code for the suite to regress and `StorefrontEnglishUnchangedTest`
cannot move. That is the point of a previews round: the owner picks the shape
before anybody pours the concrete.

**Suite, in one invocation: 5548 passed, 40 skipped, 0 failed** (40,468
assertions, 321 s).

One thing did have to change on the way, and it is worth recording because it
will happen to the next lane that inlines media into `docs/`.
`PackageSigningTest > it holds no private key in this repository` walks `docs/`
looking for `/[A-Za-z0-9+\/]{86}==/` — the shape of a base64 Ed25519 secret key —
and an unwrapped base64 video data URI ends in exactly that. Two of the six
clips matched and the test went red. **The test is right and the file was
wrong**: the payloads are now wrapped at 76 columns, MIME-style, and rejoined by
the page, which caps the longest unbroken base64 run at 76 and makes the
collision impossible however the clips are regenerated. The assembler asserts it
before writing. Nothing was added to the test's ignore list, because a scanner
with an exception for the one file somebody wanted to skip is not a scanner.
