# Shoppable UGC video — R3 on the shop, and the proof that it is R3

Phase 20, Lane V3. The round that pours the concrete: the rail the owner chose, on
the storefront, placeable anywhere by shortcode, with the one change to it he asked
for.

> **"R3 — Card overlaid on the poster is final"**
>
> **"make sure you get 100% design the proposed design, i need exactly to match
> including every single thing."**

So the acceptance test for this round is not a screenshot that looks right. It is a
side-by-side, element-by-element, property-by-property comparison of the rendered
rail against the rendered preview, in one browser, at both widths. That comparison
is `docs/ugc-rail-shots/r3-compare.json` and it is summarised below.

---

## 0. What already existed, and what this round built

Three lanes this week found their brief four-fifths out of date. This one found it
about half.

**Already shipped and merged, so not rebuilt:**

| Thing | Where |
|---|---|
| `ugc_videos`, the products pivot with `position` and `at_ms` | `2027_01_10_000000_create_ugc_videos.php` |
| `UgcVideo` — publish gate, rights failing closed, the `toApi()` allowlist | `app/Models/UgcVideo.php` |
| The five-step upload check, the path sanitisers, the transcoder | `UgcMedia`, `UgcPath`, `UgcTranscoder` |
| `ugc.view` / `ugc.manage`, and the nine admin-api routes | `AdminCapabilities`, `routes/ugc-admin.php` — **already required from `routes/web.php`** |
| The library screen | `Content → Shoppable video` |
| The teaser decisions, measured | `docs/UGC-VIDEO-PLAN.md` §0b |

**Nothing of the storefront existed.** No rail, no player, no shortcode, no
setting, no section, no module row, no likes. That is what this round is.

| Built | Where |
|---|---|
| Sections — many rails, each ordered, one clip allowed in several | `ugc_sections` + `ugc_section_video`, `App\Models\UgcSection` |
| The shortcode | `[kbb_videos section="..."]`, a third arm on `App\Support\Shortcodes` |
| The R3 rail | `resources/views/ugc/rail.blade.php` + `assets.blade.php` |
| The opened player | built in the browser from inline JSON beside each tile |
| Every control | `App\Services\UgcSettings`, on `ModuleSchema`'s shared shape |
| The rail's read | `App\Services\UgcRail` + `App\Services\Ugc\Tile` — four queries, flat |
| Self-hosted likes | `ugc_videos.likes` + `ugc_video_likes`, `Api\UgcController` |
| The sections screen and the per-clip popup | `admin/partials/ugc-sections-screen.blade.php` |
| The look screen | `admin/partials/ugc-appearance-screen.blade.php` |

---

## 1. Where every control sits in the admin

| What | Path |
|---|---|
| The master on/off | **`Store → Modules → Shoppable video`** *(ships OFF)* |
| The clip library | **`Content → Shoppable video`** *(built last round)* |
| **Sections — make one, order its clips, copy its shortcode** | **`Content → Video sections`** |
| One clip's whole editor, in a popup | **`Content → Video sections → Open a section → Edit`** |
| Products on a clip | **`Content → Video sections → Open → Edit → Products in this video`** |
| The clip, the poster, the 2–3s loop | **`Content → Video sections → Open → Edit → The files`** |
| Permission, creator credit, source link | **`Content → Video sections → Open → Edit → Where it came from`** |
| Tiles across on a phone, tile width, gap, radius | **`Appearance → Video rail → Layout`** |
| The loop, how many play at once, what a tap does, sound | **`Appearance → Video rail → Motion`** |
| The rating bar, caption, handle, count badge, struck price | **`Appearance → Video rail → What a tile shows`** |
| Letting shoppers like a clip | **`Appearance → Video rail → Likes`** |
| A per-section override of the column choice | **`Content → Video sections → Open → Tiles across on a phone`** |

Pictures of every one of those, at 390px and 1280px, are in
`docs/ugc-rail-shots/` — `sections-list-*`, `section-open-*`, `video-popup-*`,
`appearance-layout-*`, `appearance-motion-*`, `appearance-what-a-tile-shows-*`,
`appearance-likes-*`. Zero horizontal overflow on every one
(`admin-measurements.json`).

---

## 2. The R3 comparison, element by element

**How it was done.** `docs/UGC-VIDEO-PREVIEWS.html` and the rendered storefront
rail are served over real HTTP and loaded in the SAME Chromium at the SAME width,
with `prefers-reduced-motion: reduce` on both so nothing autoplays and the run is
deterministic. **Neither page loads a font stylesheet** — the preview is
self-contained and offline, so its Poppins declaration falls back to `system-ui`,
and the harness page deliberately does the same, or every font metric would be a
comparison of two different faces. Twenty-one elements are mapped
preview-selector → storefront-selector and **forty-five computed properties** are
diffed on each, plus two pseudo-elements (the scrim and the play triangle).

**Result: 21 elements × 45 properties at each width, and 8 deltas — every one of
them accounted for, none unintended.**

| # | Element · property | R3 | The shop | Why |
|---|---|---|---|---|
| 1 | `overlay.height` | 83.25px | 58.25px | **the deliberate change.** The rating bar left the poster overlay, which is 25px of it. |
| 2 | `overlay.top` | 117.625 / 202.969px | 142.625 / 227.969px | The same 25px. `.over` is `inset:auto 0 0 0` so it grows upward — which is why `tile.height` and `cell.width` did **not** move. |
| 3 | `brand line.display` | block | flex | the brand row now carries the bar. |
| 4 | `brand line.alignItems` | normal | center | ditto. |
| 5 | `brand line.columnGap` | normal | 6px | ditto. |
| 6 | `brand line.rowGap` | normal | 6px | ditto. |
| 7 | `now.width` | 44.2969px | 44.3125px | **+0.0156px — one sixty-fourth of a pixel.** See below. |
| 8 | `was.width` | 38.8125px | 38.8281px | the same 1/64px, same cause. |

Deltas 1–6 are the rating-bar move and nothing else. Deltas 7–8 are the only
difference in the whole rail that is not a decision.

**The 1/64th of a pixel, and why it stays.** R3 writes a price as
`<bdi>AED 79</bdi>`. The shop writes it with `App\Support\Money::format()`, which is
`<span class="woocommerce-Price-amount amount" dir="ltr"><span
class="woocommerce-Price-currencySymbol" dir="auto">AED</span> 79</span>`. The text
is byte-identical — `AED 79` — and the extra width is the sub-pixel rounding of two
nested bidi isolates. Using the preview's markup instead would mean not using the
shop's own money formatter, which prints the currency the owner configured, carries
the `dir="ltr"` the Arabic storefront needs, and is what every other price on the
site goes through. **Trading a working Arabic price for 1/64 of a pixel is not a
trade this round will make**, so the delta is reported rather than removed.

### Three deltas that were real bugs, and were fixed

The comparison started at **32 deltas at 390px and 33 at 1280px**. Three of them
were faults, not differences, and none was visible in a screenshot:

1. **The ADD button was invisible.** `padding:0`, `background:transparent`,
   `color:#2A2228` on `#2A2228`, `font-size:16px`, `font-weight:400`. Cause:
   `.kbb-ugc button{…;padding:0}` is specificity (0,1,1) and **beats**
   `.ugcr-add{padding:5px 8px}` at (0,1,0). The preview gets it right by accident,
   because its reset is a bare `*` and a bare `button` at (0,0,0) and (0,0,1).
   **Scoping a reset to a class silently raises it above the components it is meant
   to sit under.** Fixed with `:where()`, which contributes zero specificity.
2. **The play disc's box was 16px/24px** where R3's is the UA default
   13.333px/normal — `font:inherit` on the reset also sets font-size and
   line-height. A button with no text in it still has a line box. Fixed to
   `font-family:inherit`.
3. **The rating bar cost the card 1.5px.** Measured, not noticed: `rating cost
   delta: 1.5`. The 9px score at `line-height:1.5` is a 13.5px line box in a row
   whose 8px brand sets 12px. Fixed with `line-height:1` on the bar.

Two more were differences worth removing rather than explaining: the tile's
`aspect-ratio` read `360 / 640` where R3 reads `9 / 16` (now reduced by the gcd —
same layout, identical computed string), and the poster's read `auto 360 / 640`
because of `width`/`height` attributes that are entirely inert on an
absolutely-positioned `inset:0` `object-fit:cover` image (dropped).

### The rail's own box

| | 390px | 1280px |
|---|---|---|
| `cell.width` (the tile) | **158px** both | **206px** both |
| `tile.height` | identical | identical |
| `card.height` | **78.25px** both | **62px** both |
| `document.scrollWidth − clientWidth`, preview | 0 | 0 |
| `document.scrollWidth − clientWidth`, shop | 0 | 0 |
| Console errors | none | none |

---

## 3. The one deliberate change: the rating bar moved into the product box

> **"inside the product box, i need a thin minimal type rating bar as marked
> attached"**

He marked up a screenshot: the black pill currently floats on the poster, with
arrows pointing down into the white product card.

### Where it went, and why there

**The inline end of the brand line.** That is the only row in that card with spare
inline space, and it is the only placement that costs the card zero pixels of
height — which was a requirement, not a preference.

The alternatives, and why not:

- **Its own row between the name and the price.** The natural reading order, and it
  adds ~12px to a card the owner asked to keep. The card is anchored `bottom:6px`,
  so it would grow *upward* and the rail height would not change — but the white box
  itself would get taller, which is what he said must not happen.
- **On the price row.** `.ugcr-row2` is 21px tall, driven by the Add button, and the
  price already fills the 130px beside it. No room, at any size.
- **On the name row.** The name is the thing a shopper reads to identify the
  product; the brand is redundant context beside a poster and a name.

### The treatment, and what moving it bought and cost

`4.8 ★ (1,284)` — score, one star, count. Type only: **no pill, no scrim, no blur,
no border.** All four existed on the poster bar to buy contrast against video and
none is needed against white. That is the gain, measured off the rendered pixels:

| | Colour | Ratio vs pure white | Needs |
|---|---|---|---|
| Score | `#2A2228` | **15.47:1** | 4.5:1 (text) |
| Count | `#5E545A` | **7.26:1** | 4.5:1 (text) |
| Star | `#A8760F` | **3.99:1** | 3:1 (non-text) |

And the cost, which is the honest half: **the amber had to get darker.** R3's
`#FFC53D` was chosen for a 78%-alpha scrim over video and is about **1.7:1 on
white** — it fails the 3:1 non-text threshold outright. So white makes *text*
contrast trivial and makes the *glyph* harder, not easier, and `#A8760F` is where
it lands.

**One star and not five.** Five stars at a size that fits this row are 38–45px of
8–9px glyphs in a 130px content width, beside a brand that wants up to 79px. They do
not fit, and at 2× device pixels they read as texture rather than as a rating. The
numeral is the information and the single star is the label that says what the
numeral means. The five-star row stays exactly where R3 put it — nowhere else on the
tile.

### It adds 0px, and that is measured

```
390px   card with the bar 78.25px   card with the bar hidden 78.25px   delta 0
1280px  card with the bar 62.00px   card with the bar hidden 62.00px   delta 0
```

Measured in the browser by hiding the bar and re-reading the card, in the same page,
with nothing else changed (`docs/ugc-rail-shots/r3-compare.json`, `rating`).

### Everything the previous round established, kept

- **Real data.** `products.rating` and `products.review_count` — the same
  denormalised pair the shop cards, `?sort=rating`, `?sort=popular`, the `top_rated`
  shortcode and the schema.org aggregateRating already read.
- **A product with no reviews gets NO BAR AT ALL.** Not an empty star row (reads as
  *rated badly*), not a zero (reads as *rated zero*). A product can be three years
  old and simply unreviewed, and the shop already draws this line —
  `partials/home/grid.blade.php` gates its New badge on `! $p->review_count`.
  Measured across the seven-tile demo rail: six bars, and **none** on the Isntree
  sun gel, which is 0/0 on purpose.
- **Rounded exactly as a shop card rounds.** `(int) round((float) $p->rating)`, so a
  tile and a card never disagree about how many stars a 4.6 gets.
- **The count goes first on a narrow tile**, in CSS and in one place:
  `@container (max-width:170px){ .ugcr-rate .ugcr-rc{display:none} }`. At the
  designed 158px tile the bar is 27.03px wide and the longest brand in this
  catalogue — "Beauty of Joseon", 79px at 8px uppercase — still fits with room to
  spare; at the 206px desktop tile the count appears and the bar is 54–63px. **Zero
  clipped bars and zero ellipsised brands across all seven tiles at both widths.**
  The `aria-label` carries the count either way.
- **Arabic and RTL.** The bar sits at the inline end in both directions with no
  `[dir]` rule (it is `margin-inline-start:auto` in a flex row), the score and count
  are wrapped in `<bdi>`, and the digits are Western because the price two lines
  below them is. Verified: `rating_at_inline_end: "left (correct for rtl)"`,
  `page_overflow: 0`, and the play triangle mirrored to `matrix(-1, 0, 0, 1, 0, 0)`.

---

## 4. Mobile columns

> "give control to show 2 columns, 2.3, single column etc."

`Appearance → Video rail → Layout → Tiles across on a phone`, and a per-section
override on the section itself. Measured tile width and page overflow for each:

| Option | 390px tile | 1280px tile | Page overflow |
|---|---|---|---|
| **`peek` — 2.3 across, as designed** *(default)* | **158.00px** | **206px** | 0 / 0 |
| `one` — one tile fills the screen | 324.00px | 1126.00px | 0 / 0 |
| `one_peek` — 1.2, the next peeking | 268.00px | 936.33px | 0 / 0 |
| `two` — exactly two | 156.00px | 557.00px | 0 / 0 |
| `two_three` — 2.3, computed from the screen | 134.08px | 482.78px | 0 / 0 |
| `three` | 99.98px | 367.33px | 0 / 0 |
| `grid_two` — two-column grid, no sideways scroll | 156.00px | 272.50px | 0 / 0 |

`peek` is the default because it is what R3 measures: a fixed 158px tile in a 390px
viewport with 16px gutters and a 12px gap shows 2.1 tiles and the edge of the third.
The fractional options subtract the gaps **exactly** rather than approximately —
`n` tiles carry `n-1` whole gaps plus the fraction belonging to the partly visible
one, which for 2.3 is 1.3 gaps. Getting that wrong is how a "2 across" rail
overflows by 24px; all seven measure zero.

Pictures: `docs/ugc-rail-shots/cols-*-390.png` and `-1280.png`.

---

## 5. Playback

Round three's machinery, **reused rather than rewritten** (`UGC-VIDEO-PLAN.md`
§0b): a separate teaser file rather than seeking (1.01 MB against 12.19 MB for a
rail of eight, measured over real HTTP); `muted` + `playsinline` as both the
property and the attribute, with the `play()` promise caught; ONE
`IntersectionObserver` at threshold 0.6 recording which tiles are on screen, with
what plays decided afterwards in one place; a tile leaving drops its `src` and calls
`load()`, which is what hands the decoder back; `prefers-reduced-motion` read live
through `matchMedia`; `Save-Data` strictly `=== true`, because absent means no.

**A tap opens the player and the full clip plays automatically** (`Motion → Play
automatically when a shopper opens a clip`, on). It is still muted unless the owner
says otherwise, and an unmuted `play()` that is refused is **retried muted** — so
"unmute on open" cannot silently become "do not play on open".

**No JavaScript in this feature measures layout.** No `getBoundingClientRect`, no
`offsetHeight`, no `clientHeight`, no `scrollY`, no scroll handler, no resize
listener, no `requestAnimationFrame`. Pinned by name in `UgcRailR3Test`.

**There is no third-party embed, and that is what makes the loop possible.**
`UgcVideo::published()` requires a `file_path`, so every clip a shopper can open is
one this shop serves — see §7 below for what that means for an Instagram or TikTok
URL.

---

## 6. What a rail costs, as a slope

`tests/Feature/UgcRailSlopeTest.php`. Warm-up pass, `forgetScopedInstances()`, and
`DB::enableQueryLog()` rather than `DB::listen()` (which never detaches).

| Videos in the rail | 1 | 2 | 5 | 10 |
|---|---|---|---|---|
| Queries | **4** | **4** | **4** | **4** |

And on the other axis: ten videos with **one** product each and ten with **six** are
both 4.

The four are named in the test rather than left a mystery: `ugc_sections`,
`ugc_section_video` (joining the videos), `ugc_video_product` (joining the
products, one eager load for all of them), `brands`. A **second render is 0**, from
the ten-minute cache, dropped by every admin write. With the module off it is **0
queries against any of these tables** — not four cheap ones.

The mutations, run:

| Mutation | Result |
|---|---|
| Drop the products eager load | **2/2/2/2** — flat and *cheaper*, because `Tile::fromVideo()` guards on `relationLoaded()`, so every tile silently loses its card. The query count alone rewards this; the content assertion is what catches it. |
| Drop `with('brand:id,name,slug')` only | **6/9/18/33** — the real N+1. A ceiling of 10 would have passed at one and two videos and shipped a rail costing 33 at ten. |
| Bypass the rail cache | second render 4 instead of 0 |
| Check the module switch after the query | 2 queries with the module off |

---

## 7. Instagram and TikTok — what a URL can honestly give you

The owner cut the counts mid-round — *"okay, leave the counts for now, just get the
videos from there"* — so there is **no fetcher, no credential and no column** for a
third party's like or comment count anywhere in this module. What remains is the
question of getting the **video** from such a URL, and the answer has three parts.

**EGRESS FROM THIS CONTAINER IS BLOCKED.** Nothing below was executed against a
live endpoint. Every claim is read off each platform's published documentation as of
this model's knowledge and is **UNVERIFIED** for that reason.

### You cannot re-host their file, and this shop does not

Downloading the MP4 and serving it from `/uploads/ugc/` is a reproduction; the
creator owns the copyright and being tagged in a clip grants nothing
(`UGC-VIDEO-PLAN.md` §3.3). It also breaks silently whenever a CDN URL rotates. So
`rights_status` is a column, it starts at `pending`, and `granted` is a
precondition of publishing — which is the module refusing to serve a file it has no
permission for, rather than a note somebody meant to check.

### What a URL can honestly give: a thumbnail and an embed

| | Without any credential | For counts |
|---|---|---|
| **Instagram** | The **static embed** appears still to work unauthenticated: `<blockquote class="instagram-media" data-instgrm-permalink="…">` plus `//www.instagram.com/embed.js`, and the `https://www.instagram.com/p/{code}/embed/` iframe. The **oEmbed API** does not: the unauthenticated `/oembed/` endpoint was retired in October 2020, and `/instagram_oembed` needs an app access token plus the oEmbed Read feature through App Review. So an embed yes; a *programmatic* thumbnail no. **UNVERIFIED.** | A Business/Creator account of our own, a linked Facebook Page, a Facebook app, a long-lived token and App Review — and then `business_discovery` returns another account's counts **only if that account is itself a public Business or Creator account**. Personal accounts return nothing. View counts are owner-only and never obtainable. |
| **TikTok** | **oEmbed is open and needs no key**: `https://www.tiktok.com/oembed?url=…` returns `title`, `author_name`, `author_url`, `thumbnail_url` and the embed html. This is the easier case. **UNVERIFIED.** | An OAuth grant from the account that **owns** the video, with the `video.list` scope, per creator, re-granted when the token expires. The Research API is academic-institution only. Effectively: no. |
| **YouTube** *(already in `UgcVideo::PLATFORMS`)* | — | A plain API key: `videos.list?part=statistics` returns views, likes and comments for any public video, no OAuth. The one easy case, and the plan never considered it. **UNVERIFIED.** |

### ▲ The consequence he needs told plainly

**An external video's tile cannot have the 2.5-second loop.** The loop needs a
teaser file we control, and an embed is their iframe, their player and their
autoplay rules. So a genuine Instagram or TikTok embed would be a **still poster in
the rail**, and clicking would open their embed — an uploaded clip loops and a linked
one does not. That would also cost **four CSP directives widened** (`frame-src`,
`script-src`, `connect-src`, `media-src`, and for Instagram a moving set of
`*.cdninstagram.com` and `*.fbcdn.net` names), in the middle of a report-only phase
whose violation list is the input to the round that enforces (§3.1).

**The way to get both the loop and the credit is what this module already does:**
upload the file he already has and enter the source URL for attribution.
`file_path` present, plus `source_url` and `creator_handle`, and the tile loops
*and* credits the creator with a link back to the original post. That is the answer
to what he actually wants, and it is why this round embeds nothing.

---

## 8. Likes — ours, real, and off by default

`ugc_videos.likes` is a count of clicks on this shop, maintained by one
`UPDATE … SET likes = likes + 1`. Behind it, `ugc_video_likes` holds **two
columns**: the clip, and the SHA-256 of a random 32-hex token this shop minted and
put in a cookie. **No address, no user agent, no customer id, no referrer** — the
rate limit lives in the RateLimiter's own expiring cache and is never written down.

The **unique index on `(ugc_video_id, token_hash)` is the rule**, and the insert is
how it is applied: a read-then-write guard is a race two taps in the same second
both win. Three guards in all — the route's throttle (30/min), a per-address ceiling
(40/hour, charged only when it actually writes), and the index.

**It ships off**, because R3 has no heart on it. `Appearance → Video rail →
Likes` defaults to false and `POST /api/ugc/{slug}/like` 404s while it is — the same
404 a draft clip, a rights-refused clip and a slug nobody issued all get, byte for
byte, because they are the same return statement.

---

## 9. The `spotted` section on the homepage — a recommendation, not an edit

`resources/views/store/home.blade.php:522` already has a `#KBeautyBliss spotted`
section: a "Shoppable" badge, four product photographs in a static grid, gated on
`$sections->hidden('spotted')`. **That file belongs to another lane this round and
has not been touched.**

**Recommendation: offer the rail as that section's CONTENT, and do not replace the
section.** Three reasons, in order of weight:

1. **The section is already the right promise and the wrong delivery.** Its heading,
   its badge and its subtitle all say "shoppable creator content"; what it draws is
   four product photographs that link to product pages. The rail is what that
   section has been claiming to be. Replacing the *markup* while keeping the
   heading, the `$sections` gate, the `classFor()` divider and the "View all" link
   is a smaller change than it sounds.
2. **Beside it would be two sections making the same promise**, twenty lines apart,
   on the most-read page on the shop.
3. **But it must not be an unconditional replacement.** That section renders today
   with zero setup; the rail renders nothing until the owner has uploaded clips,
   cleared permissions and switched the module on. Swapping it outright would empty
   a live homepage section on apply, which is rule 1.

**So the concrete proposal, for the integrator and W1 to weigh — one line, inside
the existing `@unless`:**

```blade
{{-- inside the existing spotted <section>, replacing the static .ugc grid --}}
@php $spottedRail = \App\Support\Shortcodes::render('[kbb_videos section="spotted"]'); @endphp
@if ($spottedRail !== '')
  {!! $spottedRail !!}
@else
  {{-- the existing static grid, exactly as it is today --}}
@endif
```

The `@else` is the whole safety of it: with no `spotted` section, no cleared clips,
or the module off, the shortcode returns the empty string and the homepage renders
**byte-identically to today**. `StorefrontEnglishUnchangedTest` cannot move on
apply, and the section upgrades itself the day the owner has content for it.

**The edit is W1's to make and I have not made it.**

---

## 10. Wiring, for the integrator

**Nothing is owed for the admin half.** `routes/ugc-admin.php` was already required
from `routes/web.php` last round, and this round's nine admin routes were added to
that file rather than to a new one precisely so no line is needed.

**One require, and it is already in this patch:**

```php
// routes/api.php, inside the existing SecurityHeaders group, beside
// payments-webhooks.php and security-csp.php
require __DIR__.'/ugc.php';
```

`routes/api.php` and not `routes/web.php`, for two reasons of substance:

1. **CSRF.** A like is posted by a script on a page that may be cached, so a
   session-minted token would be stale for the first shopper served that page. The
   api group has no CSRF middleware, which is why `/api/quiz` is already there.
2. **Cookie encryption.** The one-per-browser token is written and read by this one
   endpoint. In the api group `EncryptCookies` does not run, so it is written plain
   and read plain. Split across the two groups every like would look like a first
   like — **silently.** (This is not theory: the first version of the test used
   Laravel's `withCookie()`, which encrypts, and every request counted as a first
   like until it was changed to `withUnencryptedCookie()` + `withCredentials()`.)

**Two Blade includes, and I made them** — `resources/views/admin/app.blade.php` is
a file this lane was told it MAY edit, and it now ends with:

```blade
@include('admin.partials.ugc-library-screen')
@include('admin.partials.ugc-sections-screen')
@include('admin.partials.ugc-appearance-screen')
```

**A `clear_caches_` migration ships beside them:**
`database/migrations/2027_02_02_000002_clear_caches_ugc_rail.php`. Two new public
routes in a new route file, nine new admin routes, and five changed Blades — a route
added by a package does nothing until the compiled route table is gone, and a stale
compiled `app.blade.php` is a console with no Video sections row in its sidebar.

---

## 11. The pictures

`docs/ugc-rail-shots/`, Chromium at deviceScaleFactor 2, 390px and 1280px.

| File | What |
|---|---|
| `r3-preview-390/1280.png` | **R3 as previewed** |
| `r3-shop-390/1280.png` | **the shipped rail, same width, same browser** |
| `r3-compare.json` | the 21×45 property diff, the rating-cost measurement, the contrast ratios, the overflow run |
| `rail-390/1280.png` | the rail on its own |
| `rtl-rail-390/1280.png` | flipped to RTL |
| `cols-*-390/1280.png` | every mobile column option |
| `rail-extras.json` | per-tile rating/brand/clipping measurements, the column table, the RTL run |
| `sections-list-*`, `section-open-*` | `Content → Video sections` |
| `video-popup-*` | **the per-clip popup, with products, files and source URL** |
| `appearance-layout/motion/what-a-tile-shows/likes-*` | every tab of `Appearance → Video rail` |
| `admin-measurements.json` | the admin screens' overflow and field census |

---

## 12. The mutation run

**44 mutations applied, run, and reverted** — 37 red, 7 green on the first pass,
6 of those 7 fixed, and the last one green for a reason worth writing down. Rule 6:
a mutation note is the shortest way to prove a test asserts anything, and the greens
are where the work is.

Every mutation note in `tests/Feature/Ugc*.php` says which one it is and what the
numbers were.

### The seven that came back green, and what each one was

| Mutation | Why it was green | What was done |
|---|---|---|
| **Delete `v.muted = true` from `mount()`** | The string also appears in `open()`'s **retry-muted** branch, so a whole-file `toContain()` passed off a line about a different element — while every tile in the rail had silently stopped autoplaying anywhere. **A real gap, and the worst kind: invisible in a screenshot.** | The assertion is now scoped to `mount()`'s own body. **Now red.** |
| **Drop `->published()`… no — drop `array_unique()` from the section order** | `sync()` is handed an array **keyed by video id**, so a duplicate overwrites rather than duplicating — and in the order the test used (`[c, a, a, 999999, b]`) the overwrite happened not to move anything. | A case with the duplicate **last** (`[a, b, a]`), which does move: without `array_unique()` the order becomes `[b, a]`. **Now red.** |
| **Drop the empty-slug fallback from `handle()`** | The test used an Arabic title on the reasoning that `Str::slug` transliterates nothing outside its map. **It was simply wrong**: `Str::slug('روتين الصباح') === 'rotyn-alsbah'`. Nothing in the suite ever reached that arm. | `'♥♥♥'`, which measurably slugs to `''`, plus an assertion that the Arabic title keeps its transliteration. **Now red.** |
| **Move the videos pass above the blocks pass** | The mutation was **badly built** — it *added* a pass above and left the original below, so the nested case was still expanded by the second one. | The source order is now pinned directly (block < products < videos), and the mutation redone as a genuine move. **Now red.** |
| **Drop the module check from `Shortcodes::videos()`** | `UgcRail::section()` checks the same switch one layer down, so nothing observable changed. The two are redundant **by design** — the shortcode's is the cheap one. | A case asserting the cheap lock's one observable effect: with the module off, `UgcRail` is **never resolved out of the container**. **Now red.** |
| **Capability rules: reads above the writes** | The mutation added `GET` rules, and `GET` rules only match `GET` — so a `POST` still fell to its own rule. **Not a real hazard as written.** | Redone as a `['*', 'admin-api/ugc-sections/**', 'ugc.view']` rule above the writes, which *is* the hazard the comment describes. **Red.** |
| **`cssVariables()` trusts its override** | **Still green, and correctly so.** There are three locks between a shortcode attribute and the style attribute: the shortcode drops an unknown value, `cssVariables()` checks it again, and the `match` has a **default arm that ignores the value entirely**. Removing either of the first two is caught by the third, so no single lock can be tested by removing it. | The test now pins the **invariant** instead of one lock: over six adversarial inputs the output is always one of a fixed set of strings and contains nothing from the input. Mutating the `match`'s default arm to interpolate the value is **red on all six**. |

### The thirty-seven that were caught

Grouped, with the notes in the test files:

**The R3 design** — the rating bar moved back onto the poster; the no-reviews gate
widened to `rating >= 0`; `round()` → `floor()` on the stars; `gcd()` returning 1;
`line-height:1` → `1.5` on the bar (the 1.5px the card would grow);
`:where(button)` → `button` (the invisible ADD button); the container query on the
count; `preload:'none'` dropped; `unmount()` reduced to `pause()`; `tile_w`
defaulting to 170.

**The shortcode and the rail** — the assets emitted once per rail rather than once
per page; the `require` in `routes/api.php` deleted; `->published()` dropped from
the section's videos (a rights-refused clip on the shop); `columns` validated as
free text.

**The query cost** — the products eager load dropped (**2/2/2/2 — flat and
*cheaper*, because every tile silently loses its card**); the brand eager load
dropped (**6/9/18/33 — the rising N+1 a ceiling would have missed**); the rail cache
bypassed; the module switch checked after the query.

**The sections admin** — no `whereIn` against real ids; `destroy()` deleting the
clips; the handle regenerated on every save; `POLICY`'s `invalid` flipped to
`reject`; `likes_on` removed from `TABS` while left in `SCHEMA` (the
`reassure_auth_text` shape).

**The public write** — the `looksMinted()` branch removed; `RateLimiter::hit()`
removed; `hit()` moved above the write; the IP added to the ledger; the unique index
dropped from the migration; a draft clip given its own 403 (the id oracle);
`likes_on` unchecked; the feed returning models instead of the allowlist.

**Rule 1** — the registry row defaulting to `true` (red here **and** in
`StorefrontEnglishUnchangedTest`); the reader written as
`moduleEnabled(self::MODULE, …)`, which `ModuleFrameworkGuardTest` tokenises for a
literal and therefore cannot see.
