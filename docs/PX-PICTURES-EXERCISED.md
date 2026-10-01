# The picture pass, exercised end to end — Lane PX

The owner is about to import kbeautybliss.com into extrabeauty.ae and then switch
the old site off. After that its pictures are gone for good, so the picture pass
has to work the first time. `docs/IE-IMPORT-END-TO-END.md` §7.8 said it had never
been run. This lane ran it, against the fixture export, a fake old site, and a
671-product volume rig. It found **eight defects**. All eight are fixed, and each
fix has a test that goes red without it.

Everything below was measured. Where a number is quoted, the log it came from is
named. The logs are in `storage/px-logs/` in the lane's worktree and are not
committed. The screenshots are committed in `docs/px-shots/`.

---

## 1. What was run

### The rig

Nothing here reached the real kbeautybliss.com. This sandbox blocks outbound
traffic, so the old site is a local copy that answers to the real name.

| piece | what it is |
|---|---|
| **old site** | `php -S 127.0.0.1:9311` over `rig/oldsite/wp-content/uploads/…`. Every picture the fixture export names is a real JPEG, PNG or WebP made with GD, 14 files in all. Every request is logged. |
| **https** | A TLS front on 9312 with a certificate for `kbeautybliss.com`, signed by a local CA. PHP trusts that CA through `-d openssl.cafile=`, so verification stays on. |
| **the network** | A forward proxy on 9310. The shop's CLI runs with `HTTP(S)_PROXY` set to it and `NO_PROXY` empty, which Guzzle honours, so **every outbound request the picture pass makes goes through it and is logged**. It forwards only `kbeautybliss.com` on ports 80 and 443. It refuses anything else and logs it as `SSRF ATTEMPT`, which is how "did the app try?" was measured. |
| **canary** | Port 9313 on loopback. It must never be reached. |
| **the shop** | The repo's own front controller under `php -S` on 9320, served as `shop.px.test`. It has its own SQLite file, and `KBB_PUBLIC_PATH` points at a web root that is a different directory from the app (the production shape). |

**How the host was mapped.** The command has no host-override option, and none
was added. Standard proxy environment variables do the job, because Guzzle reads
them in the CLI. `/etc/hosts` was not touched. Chromium was pointed at the rig
with `--host-resolver-rules`, which also maps `kbeautybliss.com` to a closed port.
For the browser, that is exactly what "the old site is switched off" looks like.

### The export

`tests/Fixtures/kbb-export/` was copied, and 14 products and one variation
thumbnail were added. Every deliberate case is a real row in `products.csv`:

| case | address |
|---|---|
| missing (404) | `…/2022/02/px-missing.jpg` |
| very slow: headers, then **one byte a second** | `…/2022/02/px-slow.jpg` |
| not an image: an HTML page served as `image/jpeg` | `…/2022/02/px-not-image.jpg` |
| oversize: 13 MB streamed with no `Content-Length` | `…/2022/02/px-huge.jpg` |
| `http://` | `http://kbeautybliss.com/…/px-http.webp` (and the fixture's own review 8102) |
| `https://` | every other picture |
| SSRF to loopback | `http://127.0.0.1:9313/…/canary.jpg` |
| SSRF to cloud metadata | `http://169.254.169.254/…/latest.jpg` |
| SSRF by name | `http://localhost:9313/…`, `http://metadata.google.internal/…` |
| SSRF, same host on another port | `https://kbeautybliss.com:9313/…` |
| SSRF by redirect | `…/px-redirect.jpg` → `302 http://127.0.0.1:9313/…` |
| traversal | `…/2022/02/%2e%2e/%2e%2e/%2e%2e/%2e%2e/px-escape.jpg` |
| foreign scheme | `ftp://kbeautybliss.com/…` |
| variation thumbnail | `variations.csv` 4101 → `…/px-variant-50ml.jpg` |

### The steps

```
php artisan migrate --force
php artisan kbb:import --dir=<rig export> --timezone=Asia/Dubai --adopt-by-slug
php artisan kbb:import-media-fetch              # the picture pass (new; see §6)
storage/px-logs/rig/measure.php                 # the measurement below
```

**The measurement** (`rig/measure.php`) reads every picture reference the
database holds: products' image, gallery, description and short-description
`<img>`; variants; categories; brands; article cover and body; and review photos.
It judges each one against the disk, comparing **sha256 with the bytes the old
site served**. Then, separately, it scans **every text column of every table** for
`kbeautybliss.com…wp-content…uploads`. That second scan is how the missing
columns were found. A list of known columns cannot find a column nobody knew
about.

---

## 2. Measured results

### The fixture export: 29 picture references

| | baseline (code as it was) | after the fixes |
|---|---:|---:|
| local, file exists, **same bytes as the source** | **7** | **17** |
| local, wrong bytes | 0 | 0 |
| local, file missing | 0 | 0 |
| still on kbeautybliss.com | **18** | **8**, all of them deliberate failures, each reported by name |
| on another host (the SSRF rows) | 4, **all requested** | 4, **all refused, 0 requests** |
| whole-DB scan: columns still naming the old host | `products.image` 10, `products.images` 2, `reviews.images` 2, `products.description` 1, `products.seo` 1, `product_variants.image` 1 | `products.image` 8 (the deliberate ones) |
| outbound requests to anything but kbeautybliss.com:80/443 | 5 (`127.0.0.1:9313`, `169.254.169.254`, `localhost:9313`, `kbeautybliss.com:9313`, an internal name) | **0** |
| `.part` files left in the web root | 1 | 0 |

Logs: `m1-baseline-measure.txt`, `m3-measure1.txt`, `m3-run1.txt`, and
`rig/proxy.log`. The "before" column comes from a run that was killed and then
resumed, which is the realistic path. That is how defect 1 showed up.

**The eight reported failures**, as the command prints them and writes them to
`--csv` (`m3-run1-failures.csv`):

| state | address | reason, verbatim (first clause) |
|---|---|---|
| FAILED | `px-missing.jpg` | the old host answered 404 … |
| FAILED | `px-slow.jpg` | kbeautybliss.com was still sending this picture after 60s (61 B so far), so the download was abandoned … |
| FAILED | `px-not-image.jpg` | the body is not an image. The old host declared image/jpeg and sent an HTML page … |
| FAILED | `px-huge.jpg` | this is larger than the 12 MB limit for one picture; the download was abandoned |
| FAILED | `px-redirect.jpg` | refused to follow a redirect from kbeautybliss.com to 127.0.0.1 … |
| REFUSED | `kbeautybliss.com:9313/…` | this address names port 9313 … |
| REFUSED | `ftp://…` | this address uses "ftp://" … |
| REFUSED | `…%2e%2e…/px-escape.jpg` | this address contains a ".." segment … |
| REFUSED | `127.0.0.1:9313`, `localhost:9313` | this address names port 9313 … (first rule hit) |
| REFUSED | `169.254.169.254` | this address leads to 169.254.169.254, which is this server or a private network … |
| REFUSED | `metadata.google.internal` | … a name that only exists on a private network … |

### The customers' photographs (the new path)

All five review references in the fixture are local after the pass, with source
bytes. They were rendered in Chromium with the old site switched off, and all five
loaded at `naturalWidth` 800 (`m6-review-photos.json`,
`after-review-photos-{390,1280}.png`). That includes Layla's `layla-selfie.jpg`,
which no other row references.

**Before the fixes**, review 8102's `http://kbeautybliss.com/…/skincare-category.jpg`
**stayed on the old host**. The same file had already been fetched over https for
the category, so the http spelling was skipped and never re-pointed (defect 1).
After a killed run, review 8101's two photographs stayed remote as well.
`kbb:import-media` does read reviews: `MediaAudit` yields `reviews.images`, and
`MediaRewrite` has the review column. So reviews were not unread. They were left
behind by the resume/two-spellings defect.

### Idempotent re-run (`m3-run2-rerun.txt`)

| measure | value |
|---|---|
| requests to the old site | **0** |
| files under `wp-content/uploads` | 14 before, 14 after |
| per file: inode, mtime, size, sha256 | **identical** (`diff` of `m3-files-before-rerun.txt` and `m3-files-after-rerun.txt` is empty) |

### Killed mid-way, then resumed (`rig/kill-midway.sh`, `m4-*.txt`)

The pass was SIGKILLed 8 seconds in, while the slow file was downloading.

| | after the kill | after resuming |
|---|---:|---:|
| files on disk | 7 + one 8-byte `.part` | 14, `.part` gone |
| rows re-pointed | **0** of 17 | 17 |
| re-downloads of the 7 already landed | — | **0** (old-site log) |
| end state | — | identical to the clean run: 17 / 0 / 0 / 8 / 4 |

The leftover `.part` was aged past `STALE_PART_SECONDS` (`touch -d '-11 minutes'`)
before resuming, to stand for "resumed later". A fresh `.part` is deliberately
left alone, because another tab's batch may still be writing it.

### Volume: 671 products (`rig/volume.sh`, `v-run1.txt`)

The volume rig had 671 products, each with a main picture and 3 gallery pictures,
plus a review photo on every third product. That is **2,907 distinct files** of
268 KB each, all on the local old site. Measured:

| | |
|---|---|
| first run | **241 s**, 117 batches of 25, 2,907 requests, 2,907 files, 779 MB, exit 0, "0 row references still name another site" |
| bytes | all 2,907 files have the source's sha256 (`sha256sum \| uniq -c` gives one line, count 2907) |
| re-run | 0.29 s, 0 requests (exit 0 after the fix to defect 8) |

That is about **83 ms of the shop's own work per file** when the network costs
nothing.

---

## 3. The product pages (Chromium, 390 and 1280, old site OFF)

`kbeautybliss.com` was mapped to a closed port. Measured in the page (`m5-*.json`
for before, `m6-*.json` for after):

| page | width | `scrollWidth` | broken `<img>` visible | main frame | placeholder caption | CLS | requests to the old host |
|---|---|---:|---:|---|---|---:|---:|
| **px-missing**, before | 390 | 390 | **1** | 390×390 white, broken-image icon + "COSRX PX Missing Picture" | hidden | 0 | 1 |
| **px-missing**, before | 1280 | 1280 | **1** | 612×612 white, broken icon | hidden | 0 | 1 |
| **px-missing**, after | 390 | 390 | **0** | 390×390, the shop's gradient | **shown: "COSRX / Front"** | 0 | **0** |
| **px-missing**, after | 1280 | 1280 | **0** | 612×612, the gradient | **shown** | 0 | **0** |
| **serum-4021** (succeeded) | 390 | 397 ※ | 0 | 390×390, photo `naturalWidth` 800 | hidden | 0 | 0 |
| **serum-4021** (succeeded) | 1280 | 1280 | 0 | 612×612, photo 800 | hidden | 0 | 0 |
| /collections/toners/, before | 390 / 1280 | 390 / 1280 | **7 / 10** | — | — | 0 | — |
| /collections/toners/, after | 390 / 1280 | 390 / 1280 | **0 / 0** | gradient tiles with initials | — | 0 | 0 |

The frame is the same size with and without the picture, so swapping in the
placeholder causes no layout shift (CLS 0 in every row).

※ **Not this lane's, and not a picture.** On the serum's page at 390, the second
review card's star row (`.sr-cs`, `resources/css/kbb/sorina-reviews.css:46-49`)
ends at x = 397. Removing every review photo strip from the DOM leaves
`scrollWidth` at 397 (`storage/px-logs/overflow2.mjs`). The stars also visibly
clip in `after-review-photos-390.png`. It appears whenever a product has two
reviews in the two-column card grid. It is outside this brief and was left
unchanged under rule 1. It needs a lane that owns the reviews block.

Screenshots, absolute paths in the worktree:

```
docs/px-shots/before-px-missing-390.png       docs/px-shots/after-px-missing-390.png
docs/px-shots/before-px-missing-1280.png      docs/px-shots/after-px-missing-1280.png
docs/px-shots/before-serum-4021-390.png       docs/px-shots/after-serum-4021-390.png
docs/px-shots/before-serum-4021-1280.png      docs/px-shots/after-serum-4021-1280.png
docs/px-shots/before-listing-collections_toners-{390,1280}.png
docs/px-shots/after-collections_toners-{390,1280}.png
docs/px-shots/after-full-{serum-4021,px-missing}-{390,1280}.png   (full page)
docs/px-shots/after-review-photos-{390,1280}.png
```

---

## 4. Defects, root causes, fixes

File and line numbers refer to commit `9fb21cf`, plus `5b38b4e` for defect 8. Every
test is in `tests/Feature/PxPicturesExercisedTest.php`. Every mutation in §5 was
run.

### 1. Pictures that landed were never re-pointed after a kill, or under a second spelling

- **On the shop:** after a killed batch, five photographs were on disk with the
  right bytes and `fetched` in the ledger. Their product, gallery and review rows
  still named kbeautybliss.com, permanently. `plan()` counted them as `present`,
  so the page could show **Finished**. Review 8102's `http://` spelling of a file
  that had been fetched over https stayed remote for the same reason.
- **Root cause:** `MediaSideloader::batch()` re-points only the URLs that the same
  batch downloaded, and only at the end of the batch (`repoint($landed)`). Once a
  file existed, `if (is_file($full)) { continue; }` skipped it forever.
- **Fix:** `MediaSideloader.php:880`. A reference whose file the ledger says this
  class landed (`landedPaths()`, `:1761`) joins `$landed` and is re-pointed
  through the same `propose()` guards. `plan()` counts it in `remaining`, and as
  `to_repoint` (`:512`), so the Fetch button stays live. A file that arrived by
  FTP is still left to the owner's own apply, which is the restraint `repoint()`
  documents.
- **Tests:** *re-points a picture a killed batch landed…*, *re-points the http://
  spelling…*

### 2. SSRF: picture rows could aim this server at loopback, metadata or internal hosts

- **On the shop:** the proxy logged `GET http://127.0.0.1:9313/…`,
  `GET http://169.254.169.254/…`, `GET http://localhost:9313/…` and
  `CONNECT kbeautybliss.com:9313`. Each was filed as a FAILURE, so it was re-sent
  on every batch.
- **Root cause:** the host allowlist was "every host the catalogue names". The
  catalogue is an imported CSV, and nothing checked what kind of host a row named.
  Redirect hops were checked for host but not for port.
- **Fix:** `hostRefusal()` (`MediaSideloader.php:1640`) runs before any request,
  from `references()` (`:447`), and again on every redirect hop (`:1219`). A URL is
  refused permanently if any of these is true:
  - its scheme is not http or https;
  - its port is not the default for that scheme;
  - its host is `localhost` or a single label;
  - its host ends in `.localhost`, `.local`, `.internal`, `.intranet`, `.lan`,
    `.home.arpa` or `.corp`;
  - any address it is, or resolves to, is non-public. That covers loopback,
    RFC 1918, link-local including 169.254.169.254, CGNAT 100.64/10, reserved
    ranges, and `::ffff:`-mapped addresses. `127.1` and `2130706433` are refused
    because of what they resolve to.

  Also: `hosts()` cast numeric host keys back to strings (`:369`). Before this, a
  host like `2130706433` silently dropped out of the work list unreported.
- **Tests:** *sends no request to a loopback, private, metadata or internal
  address…* (12 addresses, `Http::assertNothingSent()` across two batches),
  *refuses a non-default port and a foreign scheme…*, *will not follow a same-host
  redirect onto another port*, *classifies addresses…*, and *fetches nothing a
  crafted media.csv names…*
- **Not closed, stated:**
  - DNS rebinding between the check and the connection. Closing it needs
    `CURLOPT_RESOLVE`, and `stream => true` selects PHP's stream wrapper, which
    has no equivalent. Exploiting it requires controlling DNS for the old site's
    own domain.
  - A name that does not resolve at all is let through, because the transport
    cannot connect to it either. Only an outbound proxy that resolves names
    itself would change that, and Cloudways has none.

### 3. A trickling old host held a batch indefinitely

- **On the shop:** one byte a second held a batch that promises to stop after 15 s
  for the full 150 s the rig allowed. At that rate the 1.8 KB file would take
  about 30 minutes.
- **Root cause:** with `stream => true`, Guzzle uses its StreamHandler, where
  `timeout` is a per-read socket timeout. It never fires while bytes keep
  arriving. Under PHP-FPM the wait is in a syscall, and Linux does not count that
  against `max_execution_time`.
- **Fix:** `MAX_FILE_SECONDS = 60` (`:281`), checked inside the read loop
  (`:1302`).
- **Test:** *abandons a picture the old host is still trickling…*

### 4. A host that went silent was reported as "Unable to read from stream"

- **On the shop:** that phrase, verbatim, was the whole reason shown to the owner.
- **Fix:** the read is wrapped in a try/catch (`:1318`) and reported as *"… stopped
  sending this picture part-way (N received) and did not continue within 20s …"*,
  with the transport's own message kept in brackets.
- **Test:** *names a host that stopped sending in words…*

### 5. A killed request left `.kbb-sideload-*.part` in the web root forever

- **Root cause:** `fetchOne()`'s `finally` does not run on SIGKILL, and nothing
  else ever cleaned these files up.
- **Fix:** `sweepStaleParts()` (`:1577`, called at `:1283`) removes this class's
  own temporary names in the directory being written, once they are older than
  `STALE_PART_SECONDS` (600 s).
- **Test:** *clears a temporary file a killed request left behind, and only a stale
  one*

### 6. Three places a product keeps a picture were never audited, fetched or re-pointed

- **On the shop:** these were found by the whole-database scan:
  - `product_variants.image`: the option swatch, and every basket and checkout
    thumbnail of a chosen option;
  - `<img>` in `products.description` and `short_description`: the description
    tab prints these raw, and media.csv lists them as field `description`;
  - `products.seo.og_image`: Yoast's share picture, which ProductController
    publishes as og:image.

  All three kept loading from the old site.
- **Fix:**
  - `MediaAudit.php:192` (`seo.og_image`), the description loop just above it, and
    `:215` (variants);
  - `MediaRewrite.php:180-181`, with a json-key branch in `replace()` at `:557` and
    in `references()` at `:649`;
  - `DocumentMediaRewrite.php:125-126`. Both doors into those columns already
    sanitise through `RichText::clean()`, so the existing srcset argument holds.

  `ImportUrlAndMediaTest > it reads a product with no images at all` was advanced
  on purpose. It cleared only `image`/`images` and passed *because* of this gap.
  It now also clears the description and seo, and says why.
- **Tests:** *brings every picture in the fixture export across…* and *fetches and
  re-points a variation photograph, a picture in the copy and the share image*

### 7. A picture that failed rendered as a broken `<img>`

- **On the shop:** see §3. A white frame with the broken-image icon and alt text,
  with the shop's own placeholder hidden behind it. On a listing: 7 broken tiles at
  390 and 10 at 1280.
- **Fix:** `app/Support/LostPictures.php`. An address the sideloader's ledger
  records as FAILED or REFUSED is treated as no picture:
  - `ProductController::gallery()` filters it out (`:445`), so a product with
    nothing left gets the existing placeholder shot;
  - `components/product-card.blade.php:174` uses `LostPictures::usable()`.

  It runs server-side with no script. It costs nothing for local or own-host
  addresses (pinned at 0 queries), and one query per request the first time a
  foreign host is seen. **Pages whose pictures came across render exactly as
  before.** The row itself is left untouched: it needs a new picture, and the old
  address is the only clue to what it was.
- **Tests:** *draws the placeholder, not a broken image…*, *draws the placeholder
  tile…*, and *costs no query for a local or own-host picture*

### 8. The live page and the command stopped early or exited wrong

- **8a. Batches of failures.** The progress page's loop stopped when a batch
  fetched and refused nothing. With ten broken pictures in a row, every untried
  picture behind them was left untouched.
  - **Fix:** `plan()` returns `untried` (`:565`). `media-progress.blade.php:358`
    keeps looping while `untried > 0`, or while a batch re-pointed rows.
- **8b. Re-run reported failure after success.** The runbook's re-run with
  `--host=kbeautybliss.com` exited 1 ("Nothing was fetched") once every row had
  been re-pointed. Measured on the 671-product rig.
  - **Fix:** the audit decides the exit code (`5b38b4e`).
- **Tests:** *reports untried work so a loop does not stop…*, *re-running the pass
  downloads nothing…*, and *tries each failure once per console run, and again only
  when asked*

---

## 5. Mutations: each fix undone, the suite watched

`storage/px-logs/mutate.py` applies each mutation, runs the named tests, and
restores the file with `git checkout`. **All 19 were RED** (`t4-mutations.txt`):

| # | mutation | result |
|---|---|---|
| M1 | is_file branch does not re-point | RED (2) |
| M2 | `hostRefusal()` returns null | RED (2) |
| M3 | no port check | RED |
| M4 | redirect hop not checked | RED |
| M5 | no per-file deadline | RED |
| M6 | read error not caught | RED |
| M7 | no stale-part sweep | RED |
| M8 | variant not in `MediaRewrite::COLUMNS` | RED |
| M9 | variant not in `MediaAudit` | RED |
| M10 | seo og_image not audited | RED (2) |
| M11 | product description not a document | RED (2) |
| M12 | page loop ignores `untried` | RED |
| M13 | `plan()` does not count `untried` | RED |
| M14 | PDP gallery keeps a lost picture | RED |
| M15 | card keeps a lost picture | RED |
| M16 | console retries failures every batch | RED |
| M17 | numeric host key not cast | RED |
| M18 | `plan()` does not count `to_repoint` as remaining | RED |
| M19 | re-run with `--host` fails after a finished pass | RED |

---

## 6. What the owner runs on the server

There is no new migration and no new route, so no `clear_caches_*` is needed. The
new command and the code ship in the normal package. Over SSH (Cloudways → Servers
→ Launch SSH Terminal):

```bash
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app

# 0. After the catalogue import. Which hosts do the pictures live on, how many,
#    and is there room? Fetches nothing.
php artisan kbb:import-media-fetch --plan

# 1. The pass. nohup so a dropped SSH session does not stop it; it resumes
#    anyway if it is stopped.
nohup php artisan kbb:import-media-fetch --host=kbeautybliss.com \
      --csv=storage/app/pictures-not-fetched.csv > storage/logs/pictures.log 2>&1 &
tail -f storage/logs/pictures.log          # Ctrl-C stops watching, not the pass

# 2. If anything FAILED for a reason that can change (timeouts, 5xx, a slow
#    host), try those once more. Refusals are never retried.
php artisan kbb:import-media-fetch --host=kbeautybliss.com --retry

# 3. Confirm before switching the old site off: exit code 0, and the last line reads
#    "Every picture the catalogue names is served by this shop."
php artisan kbb:import-media-fetch --host=kbeautybliss.com ; echo "exit=$?"
php artisan kbb:import-media --verdict=remote     # belt and braces: should list nothing
```

Step 0 prints the real hosts. If the export used `www.kbeautybliss.com`, use that
in `--host`, or repeat `--host` once per host. The same pass can still be driven
from **Store → Import → Pictures & live progress** (the Fetch button). The command
and the page share one engine, and the page shows a command run as RUNNING.

**Anything still listed after step 3** needs a new picture. Those products already
show the shop's placeholder, not a broken image. Re-upload in the product editor.

### How long

- **Measured here:** 2,907 files for 671 products took **241 s**, of which the
  shop's own work is about **83 ms per file**.
- **On the real network:** each picture is a fresh TLS request to kbeautybliss.com
  from Cloudways, typically **0.3–1 s** including a 150–350 KB body. For roughly
  2,700–3,000 files (671 products × a main picture and 2–4 gallery pictures, plus
  review photos), expect about **20–45 minutes**. **Budget an hour.**
- **The exact figure:** step 0 prints the true count, and each batch line prints
  the elapsed time.
- **Disk:** step 0 also prints free space. The run refuses to start without room
  for `remaining × 350 KB` plus a 64 MB reserve. The real total is about
  0.8–1 GB at these sizes.

---

## 7. Left as found, and why

- **Review-card star overflow at 390** (§3 ※). It is not a picture and not this
  lane's files.
- **Other places a lost picture is drawn:** cart and checkout thumbnails, a
  category tile, a brand logo, and a failed review photo. These still draw the
  dead address. The product page and the product card are covered, because those
  are what the brief named and where the owner and shoppers will see it first.
  `LostPictures::usable()` is a one-line change at each site if wanted.
- **Failures are retried by the page on every batch**, by design (GD §3). The
  command tries each one once per run, and `--retry` asks again.
- **FTP'd files are not auto-re-pointed** by the fetcher, which is the documented
  restraint. `kbb:import-media-rewrite --host=… --write` does that.

## 8. Test runs

```
KBB_WP_DB=kbb_wp_px vendor/bin/pest --compact tests/Feature/PxPicturesExercisedTest.php    18 passed (143 assertions)
media/import/card neighbours (22 files)                                                    377 passed (3394 assertions)
KBB_WP_DB=kbb_wp_px vendor/bin/pest --compact                                               8337 passed, 26 skipped (79081 assertions), 677.66s, exit 0
find app database routes tools -name '*.php' | xargs -n1 php -l                          clean
```
