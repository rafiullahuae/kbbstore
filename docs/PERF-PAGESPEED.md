# PageSpeed Insights on extrabeauty.ae — what was wrong, what was fixed, and what only you can do

Lane PERF. The report this answers is the one you ran on 29 September 2026:
`https://pagespeed.web.dev/analysis/https-extrabeauty-ae/az2xklr42s`.

    Performance 84 · Accessibility 95 · Best Practices 96 · SEO 92   (mobile)
    Performance 89 · Accessibility 95 · Best Practices 96 · SEO 100  (desktop)

---

## 1. Read the report again before reading anything else

Five of the items in that list were **already passing** and there is nothing to
do about them. The report shows them under **"Passed audits"**, which is easy to
miss because the headline list of opportunities looks like a to-do list.

| The thing it looks like | What the report actually says |
|---|---|
| "LCP resources should not use loading=lazy" | ✅ **Passes.** So does "fetchpriority=high applied" and "Request is discoverable in initial document". The homepage hero is **not** lazy-loaded. |
| "Use efficient cache lifetimes" | ✅ **Passes.** Your server's cache headers are already right. |
| "Enable text compression" | ✅ **Passes** — "Applies text compression". Gzip is already on. |
| "Legacy JavaScript" | ✅ **Passes.** Nothing is being transpiled that need not be. |
| "Reduce unused JavaScript" | ✅ **Passes.** |

And several more that look like failures and **cannot change the score at
all**, because Lighthouse 13 marks them *Unscored*: **Minify CSS**, **Minify
JavaScript**, **Reduce unused CSS**, **Improve image delivery**,
**Render-blocking requests**, and every "Trust and Safety" row (CSP, HSTS,
COOP, Trusted Types). They are still worth fixing — a render-blocking request
and 276 KiB of unnecessary image show up in the metrics below even though the
audit itself scores nothing — but fixing one does not, by itself, add a point.
The Performance score is computed from five measurements and nothing else:

    First Contentful Paint  10%      Largest Contentful Paint  25%
    Speed Index             10%      Total Blocking Time       30%
    Cumulative Layout Shift 25%

Your own report prints the split it gave you: **`84 = FCP +6 · LCP +20 · TBT
+30 · CLS +25 · SI +2`**. TBT and CLS were already perfect. The sixteen points
you were missing were **four in FCP, five in LCP and eight in Speed Index** —
and Speed Index, the largest single one, is the one nobody had looked at.

---

## 2. What Speed Index 7.7 s against an LCP of 2.9 s means

Speed Index measures how quickly the screen *stops changing*. An LCP of 2.9 s
with a Speed Index of 7.7 s says the main picture arrived on time and the page
went on rearranging itself for another five seconds. The report says exactly
what was doing it, in "Network dependency tree":

    Initial Navigation  https://extrabeauty.ae                 2,366 ms
      /css2?family=Poppins…       (fonts.googleapis.com)       2,364 ms
        …v24/pxiEyp8kv….woff2     (fonts.gstatic.com)          4,366 ms
        …v24/pxiByp8kv….woff2     (fonts.gstatic.com)          4,367 ms
        …v24/pxiByp8kv….woff2     (fonts.gstatic.com)          4,368 ms
        …v24/pxiByp8kv….woff2     (fonts.gstatic.com)          4,369 ms

    Maximum critical path latency: 4,369 ms

Two hops to two different companies' servers before a single letter of Poppins
exists. The `<link>` that starts it **blocks rendering** while it happens
(Lighthouse charged it 750 ms of the mobile render-blocking total on its own),
and because Google serves the face with `font-display: swap`, **every word on
your shop is painted twice** — once in the phone's default face at 2.7 s, and
again in Poppins at 4.4 s. A whole-page text repaint at 4.4 s is most of a
Speed Index of 7.7.

---

## 3. What this lane changed — five fixes, all in code, all in the package

| # | Audit it answers | What changed | Where |
|---|---|---|---|
| 1 | Render-blocking requests · Speed Index · FCP | **Poppins and Cairo are now served by your own shop.** Google's own font files, unchanged, committed and built into `public/build/`. The `@font-face` rules are inline in the page and the four weights the page uses are `<link rel=preload>`ed, so the download starts while the browser is still reading the first kilobyte of `<head>`. | `app/Support/WebFonts.php`, `resources/views/layouts/store.blade.php`, `resources/fonts/**`, `vite.config.js` |
| 2 | LCP breakdown · "Resource load delay 2,260 ms" | **The first banner picture is named in `<head>`.** Not the usual mistake — LCP request discovery *passes* on your shop. The document is 51 KiB over a slow connection and that `<img>` is two thirds of the way down it, so the browser did not know the picture existed until the page body had arrived. | `resources/views/partials/home/cards-banner.blade.php` |
| 3 | Improve image delivery · 276 KiB | **The banner serves the phone-sized copies you already generated.** Three 810×1440 originals were being painted into 298×529 boxes. The product tile beside them in the same report was already doing this properly; the banner was simply never given a `srcset`. | same file, `app/Support/ImageVariants.php` |
| 4 | Best Practices 96 → 100 | **One console 404 is gone.** Measured with `tools/perf-console-404.cjs`, which scrolls the page to the bottom the way a shopper does: before `["404 /uploads/ugc/perf-poster-clip-3.jpg"]`, after `no failed requests`. A shoppable-video clip's cover file was never written to disk (the cut failed), and nothing between the database and the browser checked. The tile still draws and the clip still plays; the browser just stops asking for a file that is not there. | `app/Services/Ugc/Tile.php` |
| 5 | Accessibility · heading order | **The footer's three headings were `<h5>` under nine `<h2>`.** Now `<h2>`. Not one pixel moves — `kbb.css` zeroes every margin, and the `.fcol` rule sets the size — but a screen-reader user is no longer told the footer is nested three levels inside the last section of the page. | `resources/views/partials/footer.blade.php`, `resources/css/kbb/kbb.css` |

### Where these sit in the admin

**Nowhere, and that is deliberate.** Not one of the five adds a control, a
setting or a switch. There is nothing to turn on after the package is applied
and nothing to move: the shop looks exactly as it looks now and is delivered
differently. The two things that *are* controls and that this work depends on
were already there:

- **Content → Media Library → "Make phone-sized copies"** — fix 3 does nothing
  until this has been run for an image. It has been run on your shop (your own
  report shows `img-cache/400/uploads/posters/…` being served), so the banner
  picks it up the moment the package is applied. Run it again after you upload
  a new banner picture.
- **Appearance → Homepage → Cards banner**, and **Appearance → Banners → (a
  set) → Cards per row** — fix 3's `sizes` is computed from whatever you have
  that slider set to, so changing it does not make the pictures wrong.

---

## 4. Measured, before and after, on the same machine at the same moment

Both trees were served at once — one from the commit this lane branched from,
one from its tip — and the Lighthouse runs **alternate** between them, so the
load on the machine lands on both halves rather than on one. Five pairs per
profile, median. `tools/perf-ab.sh` is the script.

**Mobile** — 5 interleaved pairs, median

| | before | after |
|---|---|---|
| **Performance** (measured) | 76 | 86 ✅ |
| **Performance** (with TBT at your shop's 0 ms) | 79 | 91 ✅ |
| Accessibility | 95 | 96 ✅ |
| Best Practices | 100 | 100 |
| SEO | 100 | 100 |
| First Contentful Paint | 2.83 s | 1.55 s ✅ |
| Largest Contentful Paint | 4.06 s | 3.09 s ✅ |
| Speed Index | 4.71 s | 3.97 s ✅ |
| Cumulative Layout Shift | 0 | 0 |
| Total Blocking Time (this machine, see below) | 225 ms | 217 ms ✅ |
| Page weight | 898 KiB | 684 KiB ✅ |

**Desktop** — 5 interleaved pairs, median

| | before | after |
|---|---|---|
| **Performance** (measured) | 88 | 99 ✅ |
| **Performance** (with TBT at your shop's 0 ms) | 96 | 100 ✅ |
| Accessibility | 95 | 96 ✅ |
| Best Practices | 100 | 100 |
| SEO | 100 | 100 |
| First Contentful Paint | 0.9 s | 0.39 s ✅ |
| Largest Contentful Paint | 1.14 s | 0.58 s ✅ |
| Speed Index | 1.49 s | 0.93 s ✅ |
| Cumulative Layout Shift | 0.007 | 0 ✅ |
| Total Blocking Time (this machine, see below) | 221 ms | 84 ms ✅ |
| Page weight | 898 KiB | 252 KiB ✅ |

### And the one fix this instrument cannot show

Your **"Resource load delay 2,260 ms"** does not move in the table above, and
that is a property of the instrument rather than of the fix. Lighthouse's
default throttling is *simulated*: it builds a dependency graph from the trace
and replays it at 150 ms round-trip, and in that graph **the document is one
node** — a request the document makes starts when the whole document has
finished, whether the browser read about it in the first kilobyte or the last.
On a server running on the same machine the document really arrives in 280 ms,
so a preload in `<head>` buys about 20 ms of observed time and the simulation
models the rest away. On your shop it is 51 KiB arriving over 1,638 kb/s, and
the picture is two thirds of the way down it.

So it is measured directly instead, over a *real* throttled connection at the
same 1,638 kb/s and 150 ms round-trip, by `tools/perf-lcp-discovery.cjs`:

    before   first request for the banner picture, 286 / 284 / 217 ms after
             the document — median 284
    after    first request for the banner picture, 226 / 217 / 180 ms after
             the document — median 217

It moves, and **67 ms is all it can be worth here**, which is worth saying
plainly rather than dressing up. The reason is a byte count: on this fixture
the preload sits at byte **1,650** of the document and the `<img>` at byte
**48,725**, and once compressed that gap is about **11 KB on the wire** — 54 ms
at 1,638 kb/s. Saying it 11 KB earlier saves 11 KB of waiting.

**Your 2,260 ms is a different number and it is worth reading twice.** It is
exactly the figure in your own "Render-blocking requests" table (1st party
2,260 ms), which is not a coincidence: the image request is queued behind the
document AND its render-blocking stylesheets, and a `<link rel=preload>` is
acted on by the *preload scanner* before any of that is parsed. That is the
standard fix and it is the right one. But the honest ranking of what will move
your LCP is:

1. **The font chain leaving the critical path** — 2,364 ms of it — which is
   what stops blocking the picture behind two third-party round trips.
2. **The picture being smaller**: your desktop LCP file goes from 134 KiB to the
   400w copy, and your mobile one from 131 KiB to 107 KiB. That is directly the
   "Resource load duration 1,900 ms" line.
3. **The preload**, which is worth the tens of milliseconds the byte count says
   it is, and which costs nothing.


### The pictures, and the reports

* `docs/perf-shots/index.html` — the homepage side by side at 390px and 1280px,
  before and after, with the geometry under each. `MEASUREMENTS.json` beside it
  is the raw read.
* `docs/perf-reports/` — the four Lighthouse reports the medians above come
  from, openable in a browser.

**Nothing moved.** At 1280px the page is the same height to the pixel
(8,271px) and at 390px it differs by 13px out of 9,259, which is the
"Recommended for you" grid drawing a different product — the two fixtures seed
the same catalogue and order it by a timestamp. `document.documentElement.
scrollWidth` is 390 and 1280 exactly, on both sides, so nothing gained
horizontal scroll. Every box measured is identical:

    footer heading   12px / 700 / rgb(255,255,255)     same
    product name     13px / 600                        same
    banner band      260 x 58                          same
    banner heading   13px / 650                        same
    banner card      260 x 346.66                      same
    faces loaded     Poppins 400 600 700 800           same

The one thing that differs, which is the point:

    first banner picture   before  /uploads/posters/perf-poster-1.jpg
                           after   /img-cache/400/uploads/posters/perf-poster-1.jpg

`StorefrontEnglishUnchangedTest` is the instrument that settles it properly —
39 pages rendered from the old templates and the new ones in the same process,
compared byte for byte, with the two deliberate changes cut out of both sides
and counted. It is green.

**About Total Blocking Time.** PageSpeed measured 0 ms on your shop, on both
profiles. This container runs six other agents and has no GPU, so the same page
here spends 8.6 s in "Other" and 2.9 s in "Rendering" where your run spent
1.3 s and 0.4 s, and TBT comes back anywhere between 0 and 1,700 ms from one run
to the next. TBT is 30% of the score, so the table gives both numbers: what this
machine scored, and the same category recomputed with the TBT audit held at the
1.0 your shop already earns. Nothing in this lane runs JavaScript, so the second
column is the one comparable with your report.

---

## 5. What YOU have to do — the server side, step by step

Your shop is on Cloudways and **it has a shell**. Everything below is done from
**Servers → (your server) → Launch SSH Terminal**, or with the Master
Credentials over your own SSH client.

Your paths:

    application root   /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
    web root           /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/public_html

### 5.0 One thing to check BEFORE the package is built — for whoever builds it

Your live shop is serving `build/assets/kbb-Dn1CuXv-.css`. This repository's
own build of the same stylesheet is a **different** file
(`kbb-Covswabr.css` after this lane's one-selector change). Two different
hashes means the bundle on your server was not built from this repository's
current source.

That matters because this package **must** carry `public/build/` — the font
files live there and `manifest.json` points at them. When it lands, your server
starts serving this repository's bundle. Anything that was in
`kbb-Dn1CuXv-.css` and is **not** in `resources/css/kbb/kbb.css` here would be
lost.

So, before the package is built: run `npx vite build` on the merged tree, and
diff the package's file list against the repository. `CLAUDE.md`'s first
landmine is exactly this — *"Packages 2.60.102–.106 were withdrawn for being
built against a stale tree, then applied anyway. They reverted three files and
500'd every product page. Before shipping a package, diff its contents against
the repo — never trust a file list."*

**This package needs no migration.** It adds no route and no setting, so there
is no compiled route cache to clear and no `clear_caches_*` to ship. A trial
build came out at 42 files and 754 KB with `"migrations": false`, which is
correct here and unusual for this repository — do not add one out of habit.

### 5.1 Apply the package (this is the whole of the code half)

1. Open **Store → Core Updates** in the admin.
2. Upload the package zip and press **Apply**.
3. When it reports success, open the shop's front page.
4. **Check it took**, with one command in the SSH terminal:

   ```sh
   ls -la /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/public_html/build/assets/ | grep poppins
   ```

   You should see **twelve** `poppins-*.woff2` files and **three**
   `cairo-*.woff2`. If you see none, the package did not carry `public/build/`
   and the shop is about to render in the phone's default face — **do not leave
   it like that**; tell the integrator before anything else.

5. And check the page is asking for them rather than for Google:

   ```sh
   curl -s https://extrabeauty.ae/ | grep -c fonts.googleapis.com
   ```

   This must print **0**. If it prints 2, the compiled view cache is stale:

   ```sh
   cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
   php artisan view:clear && php artisan config:clear
   ```

### 5.2 Give the font files a one-year cache lifetime

They are hashed — the filename changes whenever the file does — so they can be
cached forever and never go stale. Your `/build/` directory very likely already
has this (the "Use efficient cache lifetimes" audit passes), but it is worth
confirming now that there are fonts in it:

1. In the SSH terminal:

   ```sh
   curl -sI https://extrabeauty.ae/build/assets/$(ls /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/public_html/build/assets/ | grep poppins-latin-400 | head -1) | grep -i 'cache-control\|content-type'
   ```

2. You want to see `cache-control: public, max-age=31536000` (or anything at or
   above `max-age=2592000`) and `content-type: font/woff2`.
3. If `cache-control` is missing or short, add this to **Application → Settings
   & Packages → nginx** (or `/etc/nginx/sites-available/<app>` if you edit it
   directly), inside the `server` block, then **restart nginx** from
   **Servers → Manage Services**:

   ```nginx
   location ^~ /build/ {
       add_header Cache-Control "public, max-age=31536000, immutable";
       access_log off;
       try_files $uri =404;
   }
   ```

4. Confirm with the same `curl -sI` command. If the header did not change, the
   nginx reload did not happen — **Servers → Manage Services → nginx →
   Restart**.

### 5.3 Turn on HSTS

This is a **High** severity finding in your report's "Trust and Safety" block.
It does **not** change any score — Lighthouse 13 marks those rows *Unscored* —
but it is a real protection and it costs one line.

1. **Application → Settings & Packages → nginx**, in the `server` block that
   listens on 443:

   ```nginx
   add_header Strict-Transport-Security "max-age=300; includeSubDomains" always;
   ```

2. **Servers → Manage Services → nginx → Restart**.
3. Check it:

   ```sh
   curl -sI https://extrabeauty.ae/ | grep -i strict-transport
   ```

4. **Leave it at `max-age=300` (five minutes) for a week.** That is the whole
   point of starting low: if anything on the shop still needs plain HTTP you
   find out within five minutes instead of being locked out of your own domain
   for a year. After a week with no trouble, raise it to `max-age=31536000` and
   restart nginx again.

### 5.4 What NOT to do

**Do not add a Content-Security-Policy header.** Your report asks for one and it
is right in principle, but this storefront carries 24 inline `<script>` and 29
inline `<style>` blocks, and a CSP without `'unsafe-inline'` switches every one
of them off — the carousel stops, the cart stops, the checkout stops. There is
already a module being built for this (`App\Services\Security\
ContentSecurityPolicy`, shipping in *report-only* mode and counting exactly
those 53 blocks). It is the right route and it is deliberately sequenced
"report before enforce". The CSP row is *Unscored* anyway, so adding one today
buys no points and can take the shop down.

### 5.5 The SEO 92, which is not a defect

Your **mobile** run says:

    robots.txt is not valid — Fetch of robots.txt failed: Timed out fetching resource

Your **desktop** run, of the same URL in the same analysis, scored SEO **100**.
Same file, same server, ten seconds apart. `robots.txt` is fine: measured here
it answers in 20–46 ms and carries `Cache-Control: max-age=3600, public,
s-maxage=3600`. What happened is that Lighthouse asks for it *while it is also
pulling the 520 KiB of the throttled mobile page load*, and its fetch timed out.

So there is nothing to fix and one thing to do: **re-run the mobile test.** It
will almost certainly be 100. It is also less likely to happen again now, for
the boring reason that the page it competes with is much lighter.

If you want to remove the possibility entirely, serve `robots.txt` as a static
file — but note that it is generated from your SEO settings, so a static copy
stops tracking them. Not recommended.

---

## 6. What cannot reach 100 from code, and why

Said plainly, because you asked for "perfectly green".

### Accessibility 95 → this lane takes it to 96, not 100

There are exactly **two** scored audits failing. One is `heading-order` and it
is fixed — measured, that is 95 → **96**. The other is `color-contrast`, and it
is **108 failing elements** across the whole shop, from a handful of colours in
your palette:

| Foreground | Background | Ratio | Needs | Where it is |
|---|---|---|---|---|
| `#8C828A` (`--muted`) | `#ffffff` | **3.70** | 4.5 | Every secondary line: card category, brand name, routine steps, banner body |
| `#8C828A` | `#FFF0F4` / `#FFFDF8` | **3.35 / 3.64** | 4.5 | The same, on a tinted card skin |
| `#ffffff` | `#E0567B` (`--pink`) | **3.62** | 4.5 | **Every Add-to-cart button on the shop** |
| `#B3AAB0` | `#ffffff` | **2.25** | 4.5 | The struck-through "was" price |
| `#ffffff` | `#E23B57` (sale badge) | **4.20** | 4.5 | The −30% badge |
| `#ffffff` | `#1F9D55` (green badge) | **3.49** | 4.5 | The BESTSELLER badge |
| `#B8942E` (`--gold`) | `#FFFDF8` | **2.82** | 4.5 | The brand name on the gold skin |
| `#9A7B1F` | `#FFFDF8` | **3.95** | 4.5 | The price on the gold skin |
| `#2E9E6B` | `#E9F6EF` | **3.03** | 4.5 | The "see all" savings line |
| `#CDBFC6` | `#2E9E6B` | **1.90** | 4.5 | The footer's WhatsApp button |
| `#E0567B` | `#ffffff` / `#FDEFF4` | **3.62 / 3.25** | 4.5 | The search box's placeholder, the flag bar |

Fixing these is **darkening your brand pink, your sale red, your gold and your
grey**, on every page of the shop. This lane's brief is delivery, not design,
and four of those elements belong to another lane's stylesheet this round
(the product card and `kbb-grid-skins.css`). **It is your call, not a lane's.**
The cheapest version that clears all of them:

    --muted     #8C828A  →  #766D75   4.98 on white, 4.51 on #FFF0F4,
                                      4.90 on #FFFDF8, 4.74 on #FFF8F5
    --pink      #E0567B  →  #C6395F   5.06 with white text, 5.06 as text on
                                      white, 4.54 on the flag bar's #FDEFF4
    sale badge  #E23B57  →  #D22B47   5.03 with white text
    green badge #1F9D55  →  #1A7F45   5.04 with white text
    gold        #B8942E  →  #8A6E1F   4.77 on #FFFDF8 (covers the price too)
    was-price   #B3AAB0  →  #796C75   4.98 on white, 4.51 on #FFF0F4
    savings     #2E9E6B  →  #1F7A50   4.77 on #E9F6EF
    footer .wa  the LABEL cannot be fixed on its own: white on #2E9E6B is
                only 3.38. Darken the button to #27865B and use #FFFFFF, which
                is 4.52.

Every ratio above was computed, not estimated — each is the WCAG 2.1 relative-
luminance formula against the exact background the audit named, and each is the
smallest darkening of your own hue that clears 4.5 rather than a new colour.

None of that is in this package. Say the word and it is one small lane.

### Performance: the image saving is a desktop saving, mostly

`ImageVariants::WIDTHS` offers **200, 400 and 800** and nothing between, and
that decides how much this fix is worth on each device. Measured on the fixture,
one of the banner pictures:

    original (810 wide)   131,217 bytes
    800w copy             106,948 bytes      −18%
    400w copy              18,577 bytes      −86%
    200w copy               3,886 bytes

* **Desktop**, device-pixel-ratio 1, a 260 CSS pixel card: the browser takes the
  **400w** copy. That is the 276 KiB your report asked for, and more — the whole
  page went from 898 KiB to **252 KiB** here.
* **Phone**, device-pixel-ratio 1.75, a 248 CSS pixel card: it needs **434**
  device pixels, and with 400 and then 800 to choose from it takes the **800w**
  copy. Real, and 18% rather than 86% — the page went from 898 KiB to 684 KiB.

Adding a **600w** width would give the phone the desktop's saving. It is not in
this package because it would make **every variant set in your shop report
"incomplete"** on Content → Media Library until you re-ran the batch over the
whole catalogue — a visible change to something that works, for a phone-only
gain. Your call, and it is a good one to make.

The other phone-side lever your report names is **`modern-image-formats`, 311
KiB**: a WebP of the same photograph is roughly half the JPEG. `ImageVariants`
deliberately keeps "same format in as out" so a cut-out PNG keeps its
transparency, and changing that is a pipeline decision plus a re-run of the
batch — the same shape of question as the 600w, and not a delivery change.

### Best Practices: 96 → 100 from code, and it stays there only if covers get cut

The 404 is gone because the page stops asking for a cover that is not on disk.
**The cover is still not on disk.** The clip renders with an empty box until its
video paints. If you want the still back, re-cut it from **Content → Shoppable
video → (the clip) → Cover**, or re-upload the clip.

---

## 7. Everything this lane did NOT touch, and could not

- **`store/review-wall`, `store/app`, `store/skin-quiz`, `store/blog`,
  `store/post`** are five standalone layouts with `<head>`s of their own. Their
  **Arabic** face is self-hosted with everything else — the same change reaches
  them through `partials/arabic-face.blade.php` — but their **Latin** faces are
  still Google's: Poppins at weights 300 and 500, plus Fraunces and Hanken
  Grotesk, which this package does not carry. Their preconnect hints are
  therefore still doing real work and were deliberately left alone. Those five
  pages were not in your report; finishing them is a small follow-up.
- **The inline `<style>` and `<script>` in the shoppable-video rail** (7.2 KiB
  and 10.6 KiB unminified) are what "Minify CSS / Minify JavaScript" names.
  Both audits are *Unscored*; minifying them saves roughly 2 KiB after gzip and
  changes no score. Left alone deliberately.
- **`Reduce unused CSS`, 22 KiB.** Also unscored. It is real, and the honest fix
  is splitting `kbb.css`, which is a stylesheet four other lanes are editing
  this round.
