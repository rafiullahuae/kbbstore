# Shoppable UGC video — the plan, and the previews to choose from

Phase 20. Lane V — rounds one, two and three. **Nothing in any of them ships to
the shop.** Round three (§0b) is the newest: the 2–3 second teaser loop and the
rating bar on the video, built into every rail rather than into one.

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
| `measurements.json` | round one's `scrollWidth`, overflow and decoder-count run |
| `v2-grid-r0..r6-*.png` | the chosen rail and its six variants, at both widths |
| `v2-player-B0..B5-6p-*.png` / `-1p-*.png` | each player variant against a six-product and a one-product video |
| `v2-player-B2-fanned-*`, `v2-player-B3-revealed-*` | the two variants that have a second state |
| `v2-rtl-*.png` | the rails and B1, flipped to Arabic and RTL |
| `measurements-round2.json` | round two's overflow, decoder run and the measured rail heights |
| `v3-r0/r3/r4/r5-*.png` | round three's rails, teasers running and the rating bar on the video |
| `v3-no-reviews-*.png` | **the tile whose product has no reviews, beside one that has** — the picture for §10.2 |
| `v3-contrast-*.png` | the rating bar over white, black and the brightest and darkest real frames, with the measured ratios |
| `v3-reduced-motion-*.png`, `v3-save-data-390.png`, `v3-teaser-off-390.png` | the three states in which nothing autoplays |
| `v3-teaser-costs-*.png` | the byte table for the two ways of producing a 2–3 second loop |
| `v3-rtl-r0-*.png` | the rail, the loop and the rating bar, flipped to Arabic and RTL |
| `measurements-round3.json` | round three's overflow, the concurrency run, the byte measurement and the contrast ratios |

Captured in Chromium at deviceScaleFactor 2, except the two twelve-tile grids
(`grid-s3-wall-*`, `grid-s5-two-*`) at 1× — they are judged on density and
rhythm rather than on reading a price off a tile, and at 2× they were half the
weight of the whole directory.

---

## 0. Round two — the two you chose, and variants of each

**Chosen: the horizontal rail (R0) and the card rail over the video (B0).**
Round two derives variants from those two and changes nothing else. Both parents
sit at the top of their section in `docs/UGC-VIDEO-PREVIEWS.html`, marked
*chosen* and unchanged, with the variants underneath; the four grids and three
players that were not chosen are kept at the foot of the page rather than
deleted.

### Six rails, each changing one decision

| | Variant | The single decision changed from R0 | Height at 390px |
|---|---|---|---|
| R0 | **Horizontal rail** *(chosen, unchanged)* | — | **440px** |
| R1 | Cycling card | The card is no longer fixed to the first product — it cycles through all of them | 474px |
| R2 | Card + chip row | A second row was added below the card for the products the card does not show | 480px |
| R3 | Card overlaid | Where the card sits — on the poster rather than below it | **295px** |
| R4 | Badge only | The card was removed entirely; products live in the player | **249px** |
| R5 | Plays in the rail | Video autoplays in the rail instead of waiting for a tap | 440px |
| R6 | Card expands | The card can open in place to show every product | 458px (closed) |

Heights are measured in Chromium at 390px and baked into the page at build time
— the page itself never measures layout, because the design it is previewing is
not allowed to.

### Five players, each changing one decision

| | Variant | The single decision changed from B0 |
|---|---|---|
| B0 | **Card rail over the video** *(chosen, unchanged)* | — |
| B1 | Running total and add-all | A total bar and an add-all above the rail |
| B2 | One card, fan to open | Products are a stack you open, not a rail you swipe |
| B3 | Hidden until you tap | The rail starts hidden and is revealed by tapping the video |
| B4 | Confirm and advance | What happens when a product is added |
| B5 | Swipe up for the next | How you reach the next video from inside the player |

Every player can be opened against a **one-product**, **three-product** or
**six-product** video from buttons on its own card, because six is where a card
rail stops being easy and one is where a total bar starts looking silly. The
demo library is deliberately ragged for the same reason: one clip has a single
product and no discount at all, one has six.

### Where "stunning" and "easy to use" actually conflict

Two places, and the previews say so in their own captions rather than picking
silently. **B3** is the best-looking thing on the page and the weakest at
selling — a shopper who never taps the video sees no product at all. **R5** is
the one that most resembles the app in the benchmark screenshots and is the only
variant that costs video bytes to scroll past. Both are worth choosing; neither
should be chosen by accident.

### Two defects the round found

- **A rail card is not a player card.** At 158px the shared card layout left
  about 55px of text width: "Advanced Snail 96 Mucin Power Essence" truncated to
  "Adva Sn…" and the price broke across three lines. It was also what made R0
  measure 467px. In a rail the poster above *is* the product picture, so the
  thumbnail is dropped and name, price and a full-width Add are stacked — R0
  came down to 440px and became readable.
- **"One decoding" is not "one mounted".** `claim()` paused the element it
  displaced instead of unloading it, so two `<video>` elements were resident at
  once whenever two rail tiles were both over the observer threshold, and again
  when a player opened over an autoplaying rail. Decoding never exceeded one, so
  the round-one meter never caught it. The displaced element now gives its
  stream back at the moment it loses the decoder. Measured over 96 samples of a
  full-page scroll plus five clips stepped through B5's feed:
  **maxDecoding 1, maxMountedVideoElements 1.**

---

## 0b. Round three — the teaser loop and the rating bar

The owner, in his own words: *"I want the videos in ugc section, must be auto
play the first 2-3 seconds on repeat loop to attract the visitors more. also i
want the rating + stars inside the product box upon video, just as a small
bar."*

He has not yet picked a rail, so **both features are built into all seven of
them** rather than into one. Neither is a reason to prefer a variant; seeing the
loop run may well be how he picks. `docs/` is on `BuildPackage::NEVER_SHIP`, so
this round again ships **no package, no migration, no route, no setting and no
admin control**.

### 0b.1 Where the 2–3 seconds comes from — the one decision that costs money

Two ways to show a three-second loop. They are **identical on screen**. One
costs twelve times as much, and it was measured rather than reasoned about: a
rail of eight tiles served over real HTTP in Chromium at 390px, swiped end to
end, with `Content-Length` summed from the responses the browser actually made.

| | How the loop is made | Video bytes, rail of 8 | Per tile |
|---|---|---|---|
| **A** | one full clip, `currentTime = 0` every 2.5 s | **12,786,600 B** | 1.52 MB |
| **C** | the same clip with `#t=0,2.5` | **12,786,600 B** | 1.52 MB |
| **B** | a separate 2.5 s teaser file, looped | **1,055,160 B** | 129 KB |

**Seeking back to zero saves nothing, and the media fragment saves nothing
either.** A plays only the first 2.5 seconds and the browser fetched all
1,598,325 bytes of every file regardless — eight times the whole clip, to the
byte. C adds `#t=0,2.5`, which is the obvious clever answer, and the counter
reads *exactly the same number*: a media fragment tells the player where to
start, not the network what to fetch.

**Recommendation: B, a separate teaser clip.** The saving is 12.1×, and it is
not only shorter — it is a *different rendition for a different box*. A rail
tile is 158 CSS px wide (206 on a desktop), so 316–412 device pixels, and
360×640 at ~400 kbps is the right encode for it; the full clip has to stay
720×1280 because the opened player is full screen. **A can never have that
saving, because it has only one file.**

**What the shop has to do to produce it.** One extra derivative per video, cut
from the file that was already uploaded — `-ss 0 -t 2.5 -vf scale=360:640
-b:v 400k`, no audio. That is the same transcode step §3.4 already says the
upload needs, with one more output, so it costs the pipeline nothing new and
costs the owner nothing at all: **he is never asked for a second video.** If the
transcode cannot run — question 4 in §8, *does `ffmpeg` exist on that Cloudways
box*, is still unanswered — the honest fallback is **not** to fall back to A. It
is to ship the rail with poster frames only until the teaser exists, which is
exactly what the previews do under Save-Data, at ~22 KB a tile instead of
1.52 MB.

### 0b.2 The other four decisions, each with the number behind it

- **Autoplay at all.** `muted`, `playsinline`, `webkit-playsinline`, and the
  `play()` promise is caught. Every browser refuses an unmuted autoplay and iOS
  Safari additionally refuses one without `playsinline` — it takes the clip full
  screen instead, which is worse than not playing. Get this wrong and the rail
  looks correct in a desktop screenshot and does nothing on half the traffic.
- **Only what is on screen plays.** One `IntersectionObserver` at threshold 0.6
  and a hard cap of four. **Measured at 390px, the viewport itself never lets
  more than two tiles past the threshold**, so the cap does not bind on a phone —
  it binds on the twelve-tile wall at 1280px, which is what it is for. A tile
  that leaves drops its `src` and calls `load()`, which is what hands the
  decoder back; measured over 84 samples of a full-page scroll plus five clips
  through B5's feed, `maxPlaying 4, maxMounted 4` — mounted never exceeds
  playing, so nothing is left resident. No scroll handler, no
  `getBoundingClientRect`.
- **`prefers-reduced-motion: reduce`.** Nothing autoplays; the poster stands.
  Read live through `matchMedia`, so changing the OS setting with the page open
  takes effect without a reload. Verified against the real media query as well
  as the page's own simulator: 0 mounted, 0 holding a `src`.
- **`Save-Data`.** Honoured where it is sent, and strictly
  `navigator.connection.saveData === true`. **Absent means no** — Safari and
  Firefox do not implement `navigator.connection` at all, so the property is
  `undefined` on a large share of the traffic, and treating unknown as "save
  data" would silently turn the feature off for most of the people who can
  afford it.

**Round two's "exactly one decoding" budget is retired, deliberately.** A rail
in which only one of the two visible tiles moves looks broken rather than calm,
and the owner asked for the section to attract the eye. The rule is now
*everything on screen plays, nothing off screen does, never more than four, and
an opened player is still exclusive*. The §2 table should be read with that
substitution.

### 0b.3 The rating bar

Real data: `products.rating` and `products.review_count`, the same denormalised
pair the shop cards, `?sort=rating`, `?sort=popular`, the `top_rated` shortcode
and the schema.org aggregateRating already read. Nothing display-only.

- **A product with no reviews gets no bar at all.** An empty five-star row reads
  as *rated badly* and a zero reads as *rated zero*; a "New" chip in its place is
  a claim the data does not support, because a product can be three years old
  and simply unreviewed. The shop already draws this line —
  `partials/home/grid.blade.php` gates its New badge on `! $p->review_count`, and
  `2026_11_06_000000_retire_demo_derived_ratings.php` says those products "end at
  0/0 and their card shows the New badge instead of a star row". The bar is
  absolutely positioned, so its absence leaves no hole to fill.
- **It adds 0px.** The bar lives inside `.over`, which is `inset:auto 0 0 0`, so
  it grows the overlay upward into the scrim that was already there. Every rail
  height printed in §0 is unchanged, and `scrollWidth` is still 390 and 1280.
- **Contrast is a correctness problem and was solved by arithmetic, then
  measured.** A 78% scrim is the lowest alpha at which the score, the filled
  star and the empty star all clear their thresholds against a *pure white*
  frame. Read off the rendered PNG at 4×:

  | Frame | Score (needs 4.5:1) | Filled star (3:1) | Empty star (3:1) |
  |---|---|---|---|
  | pure white, luminance 255 | **9.69:1** | **6.14:1** | **3.38:1** |
  | brightest real frame, 185 | 12.90:1 | 8.17:1 | 3.96:1 |
  | darkest real frame, 59 | 17.89:1 | 11.34:1 | 4.47:1 |
  | pure black, 0 | 19.51:1 | 12.36:1 | 4.51:1 |

  The proof strip uses the 4.4 product deliberately: five filled stars would
  hide the empty star, and the empty star is the lowest-contrast thing in the
  bar.
- **Arabic and RTL from the start.** The stars are a flex row, which follows
  `direction`, so the filled ones sit at the inline start in both directions
  with no `[dir]` rule and no transform to flip. The score and the count are
  wrapped in `<bdi>` — `(1,284)` without it paints its brackets mirrored inside
  an Arabic run. **The digits are Western**, because the price two lines below
  them is: `App\Support\Money` prints `AED 1,199` in Western digits on the
  Arabic storefront and `App\Support\Bidi` isolates it there, so Arabic-Indic
  numerals in the rating and Western in the price would be two numbering systems
  in one tile.
- **The count is the first thing to go on a narrow tile.** R4's tiles are 132px
  and `(1,284)` ran off the end of one, clipped mid-number. A container query
  answers it in CSS and in one place — `.vt{container-type:inline-size}` plus
  `@container (max-width:150px){.rbar .rc{display:none}}` — which is the
  rendered-once answer this project prefers to a script that measures. The
  `aria-label` still carries the count, so nothing is lost to a screen reader.
- **Where the bar is not.** On the video and nowhere else. It is deliberately
  not added to the product card under the tile or to the cards inside the
  player: both of those are in the layout, so a line of stars there is a line of
  extra height in a box round two already cut back to name, price and Add.

### 0b.4 What R5 is now

R5 was *"the one that autoplays"*. That fork no longer exists — every rail
autoplays. R5 has been recast as the fork that still costs money: its tiles
produce the loop by playing the clip and seeking back to zero (approach A above)
while every other rail loops a short file (approach B). Put them side by side
and you cannot tell, which is the entire point. Nothing was deleted.

### 0b.5 What the previews fake

The six clips are ~2.46 s VP8 files inlined as data URIs, so they *are* teasers
and they loop natively — but nothing is fetched over a network, so the byte
table was measured in a separate harness with real HTTP rather than in the page.
A real deployment stores **two files per video** under `/uploads/ugc/`: the full
clip (720×1280 H.264 MP4, ~1.5 MB for 15 s) and the teaser (360×640, 2.5 s,
~130 KB), plus a ~22 KB WebP poster. The data model in §4 therefore needs one
more pair of columns beside `file_path` — `teaser_path`, and `teaser_bytes` — and
publication should not require the teaser: a video with no teaser yet shows its
poster, which is the Save-Data behaviour and is already drawn.

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
5. **Does he want the teaser loop on, at all, on a phone?** The previews turn
   it off from the toolbar so he can see the section both ways. The recommended
   shape is on, at ~129 KB a tile and only while the tile is on screen (§0b.1) —
   but it is his data bill and his shoppers', and the poster-only rail is not a
   worse-looking page, only a quieter one.
6. **Roughly how many clips, and does he have them yet?** Style 3 wants twelve
   or it looks empty; style 1 is fine with four. The right style depends on the
   library he actually has.

---

## 9. What this round deliberately did not build

No migration, no model, no controller, no route, no Blade view, no setting and
no admin screen — in round one, round two or round three. `docs/` and
`tests/Feature/UgcPreviewContractTest.php` are the only paths touched, so there
is no production code for the suite to regress and
`StorefrontEnglishUnchangedTest` cannot move. That is the point of a previews
round: the owner picks the shape before anybody pours the concrete.

**Round three does carry one test**, and it is a test of the previews rather
than of the shop. `UgcPreviewContractTest` pins the handful of things in
`UGC-VIDEO-PREVIEWS.html` that are invisible when they break — a video created
without `muted` or `playsinline` autoplays nowhere and looks fine in a
screenshot; a `preload` that is not `none` turns a rail of posters into a rail
of clips; a rating bar that stops checking `review_count` draws "0.0 (0)" on
every unreviewed product. Each assertion carries the mutation that makes it red,
and all four were run.

**Suite, in one invocation.** Round one: 5548 passed, 40 skipped, 0 failed
(40,468 assertions, 321 s). Round three, on a tree several lanes further on and
with this round's six new cases in it: **5967 passed, 22 skipped, 0 failed**
(50,715 assertions, 378 s).

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
