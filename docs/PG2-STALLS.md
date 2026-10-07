# Lane PG2 — product photos, gallery taps, and the rare "stuck loading"

The owner (7 October), with a phone screenshot of a product page on
extrabeauty.ae: an empty white frame, the product title printed top-left over
the `-41%` badge, and the four thumbnails showing their alt text:

> "when i open any product page, specially in incognito or after cache clear.
> it gives the empty box and title displays there in place of image ... and
> switching between the product gallery images, gives clear delays ... ALSO
> sometimes the inner pages stuck fully and keep loading ... only rarely"

Then: "i would love to display the loading grey bars", and "same thing for
product image too".

Everything below was measured on a MySQL-backed preview at live scale
(`tools/spd-preview.sh`, 1,200 products, ~190 KB photographs with their
phone-sized copies), in Chromium 141, phone = 390×844 DPR 3, 4× CPU,
1.6 Mbps / 150 ms through `tools/pg2g-throttle.cjs` (a proxy, so the shop's
service worker is slowed too — CDP throttling does not reach it).
Screenshots: `docs/lane-pg2-shots/`.

## 1. The title printed where the photo should be — found and fixed

**What Chrome paints.** A photo that is still downloading paints *nothing*;
alt text is painted only for an `<img>` whose request **failed**
(Chromium 141, tested: loading, hung, partial and 404 images side by side).
So the screenshot is a page on which the main photo and all four thumbnails
had failed.

**What failed them.** The shop's service worker (App → Site App, on by
default) answers every product photo. `resources/site-app/sw.js` (before,
line 151) handed the page its photo only *after* writing a copy to Cache
Storage and trimming the cache:

```js
return caches.open(IMAGES).then((c) => c.put(req, copy)).then(() => trim(IMAGES)).then(() => res);
```

If the browser refuses that write — `QuotaExceededError`, which is what a
browser with little storage (incognito, a full phone) does — the promise
rejects, the response is an error, the `<img>` breaks, and Chrome paints its
alt text. Reproduced exactly (`00-owner-symptom-reproduced-390.png`): same
page, worker in control, Chromium's quota for the site set to 400 KB → the
main photo and all four thumbnails show the title. Even when the write
succeeds, no photo could paint a row until it had fully downloaded *and* been
stored.

**Fix.** The worker returns the network response at once and stores the copy
in `event.waitUntil()` with its failure caught; a failed lookup is a miss
(`sw.js` lines 146–175). Same browser, same quota: every photo paints.
`tools/pwa-sw-unit.mjs` now proves both halves (a refused write and a write
that never finishes) — put the old line back and both checks go red.

**The empty white box, and the grey bars he asked for.** The frames were
`background:#fff` (gallery) and `#FFF8F5` (cards). Every gallery and card
`<img>` now carries the shop's skeleton grey (the cart drawer's `#f2f4f7` /
`#e9edf2` sweep) as its own background, behind its pixels, so the photo
covers it the instant it paints: no script for cards, nothing that can shift
(CLS 0 at 390 and 1280 on home, category, brand and product, photos held
6 s). Eight sweeps then plain grey (an endless background animation would
repaint every tile for the life of the page); static under
`prefers-reduced-motion`. `color:transparent` so a failed photo never prints
its title; the alt attribute stays for screen readers. The main photo is
`object-fit:contain`, so `pdp.js` `settle()` hands the side bars back to the
frame's white once it is decoded.

**Switch:** Appearance → Product styles → Layout → **Photo loading
placeholder**: Grey shimmer (shipped — he asked) / Plain grey / None. One
setting for both areas, because `ProductStyles::cardCss()` is printed on
every shop page, product page included.

**Thumbnails.** They were `loading="lazy"` with a comment "below the fold on
a phone" — they are not: the strip sits at ~530 px on a 390×844 phone, and a
lazy image is not even requested until layout. Now `loading="eager"
fetchpriority="low"`: thumbnails painted 2,603 → 1,451 ms (median, cold,
throttled) while the main photo kept `fetchpriority="high"` and was not
delayed.

**The main photo was already right:** eager, `fetchpriority="high"`,
`decoding="async"`, 1000×1000 attributes in a CSS `aspect-ratio:1` box, and
already preloaded in the product page head with the matching
`imagesrcset`/`imagesizes` (one download, not two). Nothing in the head
changed. What a phone downloads: `sizes` gives 350 px × DPR 3 = 1,050 px, so a
390 px phone takes the 1000 w **original** (191 KB here) whether or not the
copies exist; a 1280 laptop takes the 800 w copy (82 KB). Capping phones at
the 800 w copy would save ~57 % of the LCP bytes at a small cost in
sharpness — not done, not asked; offered.

## 2. The tap delay — found and fixed

`pdp.js` swapped photos by changing the `src` of the `<img>` on screen
(before, line 331). Chromium keeps the **old** photo painted until the new
file has fully arrived, so on a throttled phone the selection ring moved and
the photo did not, for 1.4 s (`03-thumbnail-tap-390-throttled.png`).

Now each tap puts a **new** `<img>` in the same box whose background is the
file the tapped thumbnail is already showing (decoded, so it paints in the
same frame, contained like the photo), and the full-size file paints over it
when it lands. A thumbnail with nothing yet shows the grey box, never the old
photo. And the next shot's file is fetched once the page has loaded (low
priority; never on Save-Data, 2G or 3G), any other on pointer-over — the same
srcset/sizes, so the tap finds it in the cache. No timers, no layout reads.

| tap → new photo (median) | before | after |
|---|---|---|
| phone, 2nd shot (warmed) | 1,547 ms | first picture 215 ms, full size 440 ms |
| phone, 3rd shot (not warmed) | ~1,270 ms, nothing changes until then | its thumbnail picture ~240 ms, full size ~1,210 ms |
| laptop, 2nd shot | 114 ms | 24 ms / 132 ms |

Cost: one extra photo per product view after load (+209 KB phone, +89 KB
laptop in this fixture), only on a connection that is not Save-Data/2G/3G.

## 3. "Sometimes the inner pages get stuck and keep loading" — what is proved, what needs the server

**No storefront page waits on a third party.** Every outbound HTTP call in
`app/` was traced (`Http::`, curl, `file_get_contents(http`): Instagram
(admin endpoints only), Super Sale order copy (admin), push/geo/web-push
(owner app, push sender), description videos and media side-loading
(import), Google translation (admin), category hierarchy (admin). The one
synchronous call that can sit on a page is **IndexNow**:
`Http::timeout(5)` (`app/Services/Seo/IndexNow.php:137`) inside
`Product::saved` (`app/Providers/AppServiceProvider.php:742`) — an **admin**
save of a live product can wait up to 5 s on Bing. No shop request saves a
product.

**No session or cache locks** on shop routes (no `->block(`; Laravel's file
and database sessions do not lock).

**Image work never runs inside a shop request — but it runs after one, in the
same worker.** Tile copies (`ImageVariants::tileAfterResponse`, `defer()`),
banner copies (`wideAfterResponse`) and the link-preview card
(`ShareImage::makeAfterResponse`) run after the response, inside the PHP-FPM
worker that served it, which takes no other request until it is done.
Measured (`spd-after.log`): the first home view spent **1,105 ms** after its
response; a cold category page 0.7–1.7 s; a cold product page 0.8–1.0 s; two
of Chrome's hover **prefetches** of cold category pages 1.1–3.1 s each. The
per-photo locks stopped two workers making the same picture, nothing stopped
every worker making different ones at once — and a small pool with every
worker busy is a navigation that sits there loading.

**Fixed:** one cache lock across all three jobs, so at most one worker at a
time makes pictures; any other skips and is free the moment its page is sent
(the photo is left for the next view, not lost). Prefetched pages schedule
no picture work at all: after-response time on two prefetches 1.1–3.1 s →
1–18 ms. `ImageVariants::oneWorkerAtATime()` /
`mayWorkAfterResponse()` (`app/Support/ImageVariants.php:375`, `:405`);
`tests/Feature/AfterResponsePicturesOneWorkerTest.php` goes red without
either half.

**Instant navigation** (unchanged, measured): a laptop resting 250 ms on each
of 15 grid links fired **16** prefetch renders in ~4 s (`moderate` eagerness,
`app/Support/InstantNav.php:167`); a phone scrolling a grid fired **0**. Each
hover prefetch was used by the click that followed (category, brand and
related-product links, before and after). Chrome documents a limit of two
kept `moderate` prefetches; the server still renders every one requested.

**Other after-response work worth knowing about** (not changed):
`OutboundTick` (`app/Services/OutboundTick.php:184`) sends up to ten emails
after a response, at most every five minutes, when back-in-stock or
abandoned-cart emails are switched on — against a remote SMTP server that can
hold a worker for seconds.

What cannot be settled from here is the live pool size and whether it is ever
full. That is what the commands below answer.

## Commands for the owner (Cloudways → Servers → Launch SSH Terminal)

App root: `/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app`.

**1. When a page is stuck, run this at once** — it shows whether every PHP
worker is busy and on what:

```sh
ps -eo pid,etimes,pcpu,rss,args | grep "php-fpm: pool" | grep -v grep
```

Many lines with a large `etimes` (seconds) and high `pcpu` at the moment of
the stall = the pool was full.

**2. Has the pool ever been full?** Cloudways keeps the app's logs beside the
app:

```sh
ls -la /home/1672906.cloudwaysapps.com/yjmakdgtjs/logs/
grep -i "max_children" /home/1672906.cloudwaysapps.com/yjmakdgtjs/logs/*.log | tail -20
```

A line saying `server reached pm.max_children setting` is the answer. The
configured size, if readable:

```sh
grep -h -E "^pm(\.max_children|\.start_servers)? *=" /etc/php/*/fpm/pool.d/*.conf 2>/dev/null
```

**3. The PHP slow log** (requests over the slow threshold, with the PHP stack
that was running) — in the same `logs/` folder if Cloudways has it on; the
file name contains `slow`:

```sh
ls /home/1672906.cloudwaysapps.com/yjmakdgtjs/logs/ | grep -i slow
tail -100 /home/1672906.cloudwaysapps.com/yjmakdgtjs/logs/*slow*.log
```

**4. Laravel's own log, for time-outs and the after-response picture work:**

```sh
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
grep -n -i -E "timed out|timeout|cURL error 28|Maximum execution time|Lock wait|Deadlock|indexnow|Share image" storage/logs/laravel.log | tail -40
```

**5. MySQL, while a page is stuck** (database name, user and password are in
`.env`):

```sh
mysql -u <DB_USERNAME> -p <DB_DATABASE> -e "SHOW FULL PROCESSLIST; SHOW GLOBAL STATUS LIKE 'Slow_queries'; SHOW GLOBAL STATUS LIKE 'Threads_running';"
```

A query in the process list with a large `Time` is the culprit; a growing
`Slow_queries` says the slow log is worth switching on in Cloudways.

**6. Catch a stall from the outside** — time to first byte of a product page
every 5 s for 5 minutes; a line far above the others is a stall, and its time
can be matched against 1–5:

```sh
for i in $(seq 60); do printf '%s ' "$(date +%T)"; curl -s -o /dev/null -w '%{http_code} %{time_starttransfer}s\n' https://extrabeauty.ae/shop/; sleep 5; done
```

**7. To rule the browser in or out:** on the phone, Chrome → ⋮ → Settings →
Site settings → All sites → extrabeauty.ae → *Clear & reset*. If stalls stop
for good after that, they were in the phone (service worker or storage), not
the server.
