# The media sideloader · a live progress URL for the whole migration

Lane GD. The shop now fetches its own photographs off the old WordPress site,
over HTTPS, one bounded batch per request, until there are none left — and there
is a page the owner can open and watch while it does.

The owner's words, twice:

> *"i thought it will come along with the products and pages. whatever used as
> photos, the system will start downloading directly from the old server. and
> this job will keep continue until all media imported."*

> *"with a live progress url of everything."*

He is right on both counts. He does not have to FTP `wp-content/uploads` across.

---

## 1. The objection this reverses, and the answer to it

`ImportMediaAudit` said, in its own header:

> READ-ONLY, ALWAYS. There is no --write and there is deliberately no
> downloader: see `App\Services\Import\MediaAudit` for why a half-successful
> fetch would be worse than a list of filenames.

and `MediaAudit` gave the reason:

> a downloader that silently half-succeeds would leave the owner worse off than
> a list of names.

**That is a good objection and it has been answered rather than ignored.** It is
true of a fetch that half-succeeds *silently*. It is not a property of fetching.
A half-finished download is worse than a list only when two things are true of
it: it cannot be resumed, so the half that failed has to be guessed at; and it
cannot be told apart from a finished one, so nobody knows to look.

`ImportRunner` met the identical objection for **rows** and answered it with
checkpoints — it was SIGKILLed mid-run eight times during the volume work
(`docs/FV-IMPORT-AT-VOLUME.md`) and resumed onto exactly the rows it had not
done. `App\Services\Import\MediaSideloader` is that answer for **bytes**, in the
same four parts:

| `ImportRunner` | `MediaSideloader` |
|---|---|
| the batch's rows and its checkpoint commit in one transaction | the file is validated in a temporary sibling and moved into place with **one `rename()`** — a killed request leaves a complete file or no file, never a truncated one |
| `processed` is the resume offset and is read back from the table | **"already done" is `is_file(public_path($path))`** — the disk, not a row claiming there is one |
| the work list is the source file, re-read each run | the work list is **re-derived from the catalogue on every request**; nothing is cached, queued or held across a request |
| `--limit` is a resume point, not a truncation | `files` / `bytes` / `seconds` bound one batch; the next batch is the identical call |

And the part `ImportRunner` did not need, because a shell prints a prompt when
it is done: **IDLE, RUNNING and STALLED are three different states on the live
page**, with a heartbeat behind them. A progress bar frozen at 118/530 because
the last request was killed is precisely the silent half-success the old header
feared, so it is named on screen rather than left to be inferred.

Both headers were corrected in place rather than deleted, so the objection and
its answer stay on the record:
`app/Console/Commands/ImportMediaAudit.php` and
`app/Services/Import/MediaAudit.php`.

The audit still does not fetch, and that separation is still right — a counter
that changes what it counts is a counter nobody can check. It is a division of
labour now, not a prohibition.

---

## 2. What was built

| File | What it is |
|---|---|
| `app/Services/Import/MediaSideloader.php` | the engine: work list, guards, one bounded batch, the ledger, the run state |
| `app/Services/Import/MigrationProgress.php` | "everything", assembled: pictures, image paths, addresses, catalogue import |
| `app/Http/Controllers/Admin/MediaSideloadApiController.php` | four endpoints |
| `routes/media-sideload-admin.php` | where they mount |
| `resources/views/admin/media-progress.blade.php` | the live page |
| `database/migrations/2026_09_18_000000_create_media_sideload_tables.php` | the ledger and the run heartbeat |
| `database/migrations/2026_09_18_000001_clear_caches_media_sideloader.php` | the `clear_caches_*` that ships with any new route |
| `tests/Feature/GdMediaSideloaderTest.php` | 86 tests, 248 assertions |
| `tests/Support/MediaSideloadAdminRoutes.php` | mounts the route file the way the integrator is told to |

Endpoints, all under the existing `/admin-api/urls-media/` prefix inside the
`auth:admin` + `NoStoreAdminApi` group:

```
GET  /admin-api/urls-media/progress         everything, one call, JSON
GET  /admin-api/urls-media/progress-page    the live page — the owner's URL
GET  /admin-api/urls-media/sideload.csv     every failure and refusal, as a spreadsheet
POST /admin-api/urls-media/sideload         {action: fetch|stop|retry}
```

### Why the prefix is shared with Lane GB

`AdminCapabilities::RULES` already carries
`['*', 'admin-api/urls-media/**', 'data.import']`, and `**` matches everything
under it. **A prefix of its own would have fallen through to the closed
owner-only default** and `AdminCapabilityMapTest` would have failed by name. So
no new rule was added — a rule shadowed by an earlier wildcard is dead text —
and the pairing is pinned instead by a test of this lane's own
("it maps every one of its endpoints to data.import and never to the closed
default"), so a future tidy-up that narrows the wildcard fails in the suite
rather than as a 403 on a host with no shell.

### Where the files land

`public_path()`, and only that. On the server the web root is
`public_html/kbb-upgrade`, **a different directory from the application root** —
`bootstrap/app.php`'s `usePublicPath()`. The path under it is the URL's path
relative to the uploads root, **unchanged**, so `MediaRewrite::propose()` finds
the file exactly where it already looks. That contract is asserted as such:
*"it preserves the uploads-relative path MediaRewrite re-points rows at"* fetches
nothing and instead drives a real `MediaRewrite::propose()` over the path this
lane produces.

---

## 3. The security model

**This is the only code in the application that writes bytes chosen by a third
party into the web root.** The third party is a WordPress installation being
migrated away from precisely because nobody maintains it. Assume it is hostile.
A bug in the happy path costs a photograph; a bug in a refusal costs the shop.

Every guard below has a test that goes red when it is removed. §5 is the list.

### 1. Hosts come from the catalogue, never from the request

`MediaSideloader::hosts()` returns the hosts this shop's own image columns name,
minus its own (`MediaAudit::isOwnHost()`, so the shop's own uploads are not
mistaken for the old site — the false positive `MediaAudit::judge()` exists for).
A request may **narrow** that set and may not add to it; anything it names that
the catalogue does not is dropped and reported back in `ignored_hosts`.

This is the single most important guard here. An admin who can post a hostname
that is then fetched server-side owns an SSRF into the host's private network.
An admin who can only pick from a list already written on 2,600 product rows
owns nothing new.

*Tested:* `resolveHosts(['169.254.169.254','internal.corp'])` returns no hosts
and two ignored; a `batch()` naming `169.254.169.254` fetches nothing and
`Http::assertNothingSent()` — absence proved by the recorder, not by
`->not->toContain()`.

### 2. No redirect off the host

`allow_redirects => false`. Redirects are followed **manually**, at most 3, and
every hop must keep the same host and stay on `http`/`https`. Same host,
different scheme is allowed — a WordPress host forcing http→https is not an
attack. A protocol-relative `Location: //evil.test/x.jpg` is resolved to an
absolute URL *carrying its own host*, so the host check sees it as the host
change it is.

Guzzle's own follower would have walked a `Location:` to 169.254.169.254 without
comment.

*Tested:* the cross-host hop fails with a named reason, no file is written, and
the hosts actually requested are collected and asserted to be `['old-shop.test']`
only. Proved again against a live fake in §4: `elsewhere.local`'s request log
stayed empty.

### 3. It must really be an image, proved twice and not by its name

The declared `Content-Type` must be in `SAFE_TYPES`, **and** the first bytes must
sniff by magic number to the same type, **and** the head of the body is swept for
the markers of a thing that executes (`<?php`, `<?=`, `<script`, `<html`,
`<!doctype`, `<svg`, `#!/`).

`getimagesizefromstring()` was deliberately **not** used as the only test: it is
a large, historically CVE-prone parser being handed hostile bytes, it does not
know webp or avif on every build, and it answers "is this parseable" rather than
"is this safe to put in the web root".

The marker sweep is not redundant with the magic number. A file can begin
`GIF89a` and carry `<?php` three bytes later — the classic polyglot upload — and
that has its own test.

### 4. The extension is forced, by refusal rather than by renaming

Only the extensions in `SAFE_TYPES` (`jpg jpeg png gif webp avif`) can ever be
written, and the URL's extension must agree with the sniffed bytes.

**Renaming the file to match the sniff was considered and rejected.**
`MediaRewrite` re-points a row at `uploadsRelative($url)`, so a file saved under
a different name than the URL implies is a file the rest of this importer cannot
find — every rewritten row would point at nothing. Refusing the mismatch keeps
both properties, and the refusal is reported by URL so the owner can look at it.
This is a real trade: a WordPress site that serves PNG bytes from a `.jpg`
address loses that picture here and has to have it re-uploaded by hand. §7 says
what to watch.

**SVG is excluded on purpose.** An SVG is a document: it carries `<script>` and
`<foreignObject>`, and a browser executes it same-origin when navigated to
directly. Putting one in the web root of the shop that holds the admin session is
stored XSS with extra steps. No WooCommerce product photograph is an SVG; a brand
logo occasionally is, and that one is worth uploading by hand.

### 5. Nothing that could execute

Every **dot-separated part** of the basename is checked against
`DANGEROUS_PARTS`, not just the last one. `photo.php.jpg` is refused on the
`php` in the middle — the shape that gets past "check the extension" and past a
misconfigured Apache `AddHandler`. `.htaccess` is in the list because a single
dropped `.htaccess` turns the uploads folder back into a place where `.jpg`
executes. A leading dot is refused outright.

### 6. The path is normalised and traversal is refused

`MediaUsage::normalise()` already `rawurldecode`s, so `%2e%2e%2f` arrives here
as a real `../` rather than sneaking past a check for the literal. Any `.` or
`..` segment, any backslash, any NUL or control byte, and anything not under
`wp-content/uploads/` or `uploads/` is refused. The longer root is tested first,
because `wp-content/uploads/` **contains** `uploads/` and matching the shorter
one first cuts in the middle of it.

Then, separately, the **resolved** absolute path is checked to be inside
`public_path()` — a symlinked year folder pointing outside the web root is the
case that catches and the string test does not.

> One honest note found while testing. PHP's own `parse_url()` rewrites a **raw**
> control byte in a URL to `_` before `normalise()` ever returns, so the raw form
> never reaches the guard; the reachable form is the percent-encoded one that
> `rawurldecode` restores. The first version of that test used the raw byte and
> would have passed against a guard that did nothing. It now uses `%0a` and says
> why in the test.

### 7. Bytes are capped three ways

* **per file** — `MAX_FILE_BYTES` (12 MB). The body is read in 64 KB chunks and
  the read is abandoned the moment it passes the cap, so a host that streams
  forever costs one cap's worth of disk and nothing more. A declared
  `Content-Length` is used as an *early* refusal and is never trusted as the
  real size.
* **per batch** — the caller's byte budget, default 8 MB.
* **against the volume** — before the first byte, and again before every single
  write.

### 8. Connect and read timeouts, both set

5 s and 20 s. A host that accepts the connection and then says nothing is the
cheapest way to hold a PHP-FPM worker open until the pool is exhausted, and the
Guzzle default for both is "wait".

These are two lines that nothing would otherwise check, so they are pinned where
they can actually be seen: **Laravel hands a stub callback the Guzzle options as
its second argument**, and the test asserts `connect_timeout`, `timeout`,
`allow_redirects === false` and `stream === true` on the real request.

### Disk

`plan()` reports, before anything is written: how many are outstanding, an
**estimate** of the bytes (`remaining × 350 KB`, stated on screen *as* an
estimate — there is no way to know the real total without asking the old host
2,600 times, which is the run), and the free space on the volume. A batch that
does not fit **refuses to start**, writes nothing, and answers **HTTP 507**. Before
every individual write the volume must still have room for one more capped file
plus a 64 MB reserve, or the run stops and says so.

Half-filling a shared host's volume takes the whole site down, not just the
pictures.

### One bad file does not stop the run

Every failure is recorded against its URL with the reason — the pattern
`EntityReport::reject()` uses for a refused row — and the batch carries on. A run
ends when there is nothing left, not when something goes wrong. `fetchOne()`
returns rather than throws, and its `catch (\Throwable)` makes that true even of
a file that throws.

**Failures and refusals are kept apart**, because they ask different things of
the owner. A *failure* is "the old host was busy, press it again"; it is retried
on a later batch. A *refusal* is "this reference cannot be satisfied safely and
needs a decision" — a URL under no uploads root, a name that could execute — and
retrying produces the identical refusal one batch later. Folding them together
would mean either retrying a refusal for ever (so "remaining" never reaches zero)
or quietly giving up on a 502.

---

## 4. Proving it

**Every external host is blocked by this sandbox's egress proxy. Nothing here was
fetched from kbeautybliss.com and nothing below pretends otherwise.** The proof
is against a local HTTP server written for the purpose that imitates the old
site, including its nastiest behaviour.

### The rig

* `oldshop.local:8942` — a PHP router serving a good JPEG/PNG for any ordinary
  address, plus eleven deliberately broken ones by filename, with 150 ms of
  latency so a run of a hundred photographs is not over before the browser
  repaints.
* `elsewhere.local:8943` — a second host that serves a perfectly good image and
  **logs every request**. The offsite-redirect case points here, so anything in
  that log is proof the hop was followed.
* `oldshop.local:8944` — accepts the connection and never answers, for the read
  timeout.
* `newshop.local:8941` — the shop itself, `php -S` over the real front
  controller, its own SQLite database, `KBB_PUBLIC_PATH` pointing at a web root
  that is a **different directory** from the application root, and `vendor/`
  hard-linked rather than symlinked.
* Distinct hostnames, not ports, because `MediaAudit` matches own-host on **host
  only** — a port does not make a file a different file — so `127.0.0.1:8942` and
  `127.0.0.1:8941` would have been the same site to it.

`routes/web.php` was patched with the one-line anchor from §6 for the duration
of the run and reverted afterwards (`git checkout routes/web.php`), which also
proves that anchor applies cleanly. The catalogue: 72 products, 110 image
references, one brand logo, one category image.

### The transcript

Before anything was written, over HTTP as a logged-in owner:

```
pictures   never     Idle — 103 pictures still to fetch.
paths      attention 110 image references are still served by another site …
addresses  attention 12 redirects proposed, 10 stored on the shop now.
catalogue  never     No catalogue import has been run from the admin screen.
plan {"total":110,"present":0,"remaining":103,"failed":0,"refused":7,
      "estimated_bytes":36915200,"enough_room":true}
```

Seven refused **before a single request was made** — the path-shaped ones. Then
three batches, each its own HTTP POST:

```
batch: ok=True fetched=12 failed=0 refused=0 bytes=75855 remaining=91  stopped=this batch's file limit (12)
batch: ok=True fetched=12 failed=0 refused=0 bytes=87703 remaining=79  stopped=this batch's file limit (12)
batch: ok=True fetched=12 failed=0 refused=0 bytes=64014 remaining=67  stopped=this batch's file limit (12)
```

### Every nasty case, and what it did

Driven from Chromium against the live page. `93` fetched, `10` failed, `7`
refused, `110` accounted for.

| case | outcome |
|---|---|
| redirect to another host | **refused** — *"refused to follow a redirect from oldshop.local to elsewhere.local"*. `elsewhere.local`'s request log stayed empty |
| redirect on the same host | **fetched**, saved under the name the row points at |
| `text/html` body served as `image/jpeg` | **refused** — *"declared image/jpeg and sent an HTML page (usually the old site's 404 or a login wall)"* |
| PHP payload named `.jpg`, served as `image/jpeg` | **refused** — *"sent PHP source"*. No file, no leftover `.part` |
| polyglot: valid `GIF89a` header with `<?php` inside | **refused** — the magic number said image; the marker sweep caught it |
| 16 MB file | **refused**, download abandoned mid-stream |
| endless body, no `Content-Length` | **refused**, abandoned at the cap |
| `Content-Type: text/html` | **refused** — *"not an image type this will write into the web root"* |
| PNG bytes behind a `.jpg` address | **refused** — *"the file is saved under the name the catalogue rows point at, so a name that disagrees with its contents is refused rather than renamed"* |
| 404 | **failed** — *"If that is 404 the picture is gone from WordPress too and the row needs a new one"* |
| a host that accepts and never answers | **failed after exactly 20.0 s**, the read timeout |
| `../../../../etc/passwd.jpg` | **refused**, no request made |
| `%2e%2e%2f%2e%2e%2fshell.jpg` | **refused**, no request made |
| `shell.php`, `photo.php.jpg`, `.htaccess`, `logo.svg` | **refused**, no request made |
| an address under no uploads root | **refused**, no request made |

Afterwards, in the web root: **93 files, 0 leftover `.part` files, 0 files with a
`.php*`, `.htaccess` or `.svg` name.**

### Two things the live run found that the tests had not

1. **A batch made entirely of previously-failed references halted the auto-loop
   before untried files behind them were ever reached.** The broken fixtures sat
   together in catalogue order, one whole batch was nothing but failures, and the
   page's loop — which quite correctly stops when a batch fetched nothing rather
   than hammering a dying host for ever — stopped there with four good
   photographs untouched. Fixed by `untriedFirst()`: untried work is processed
   before previously-failed work, a stable partition that keeps catalogue order
   within each half. That makes "this batch fetched nothing" mean what the loop
   assumes it means. Pinned by *"it puts untried pictures ahead of ones that
   already failed"*.

2. **The transport's own wording was being used as the headline.** A host that
   accepted the connection and then said nothing came back as *"Connection
   refused for URI …"* — Guzzle naming a cURL failure class, not what happened.
   Nothing was refused; the old host never answered. An owner with no shell and
   no log reads that and goes looking for a firewall. The sentence that is true
   of every case in that branch now comes first and the transport's message is
   kept after it, in brackets.

Both were found only by running it, which is the argument for running it.

### The live page

Screenshots in `docs/gd-media-shots/`, all Chromium against the rig above:

| file | what it shows |
|---|---|
| `1-idle-before-any-run.png` | **Idle**, 103 outstanding, nothing started |
| `2-running-mid-batch.png` | **Running — 20 of 110 fetched, 83 to go**, polling every 3 s |
| `3-running-later.png` | the same run at **50 of 110**, four seconds later |
| `4-finished.png` | settled, with every failure and refusal listed by reason |
| `5-stalled.png` | **STALLED**, banner and all |
| `6-finished-nothing-left.png` | **Finished — every picture the catalogue names is on this shop's own disk** |
| `7-console-card.png` | the card §6.2 adds to Store → Import, rendered in place |

The three states, in the page's own words:

```
Idle     — 103 pictures still to fetch.
Running  — 50 of 110 fetched, 53 to go.
STALLED  — 10 still to fetch, and the last batch did not come back.
           Nothing is lost; press Fetch to continue.
Finished — every picture the catalogue names is on this shop's own disk.
```

---

## 5. Every mutation, and its result

Each guard was broken, the suite watched, and the guard restored. Twenty-four
mutations. **Two did not go red first time and both were real gaps — they are
reported here rather than hidden, and both are now closed.**

| # | mutation | result |
|---|---|---|
| M1 | host allowlist: trust the request's hosts | RED (2 failed) |
| M2 | redirect: follow a hop to another host | RED |
| M3 | content type: accept whatever the old host declares | RED |
| M4 | sniff: keep the magic number, drop the executable-marker sweep | RED |
| M5 | sniff: skip it entirely, believe the header | RED (2) |
| M6 | declared/sniffed agreement: drop it | RED |
| M7 | extension/bytes agreement: drop it | RED |
| M8 | path: allow a `..` segment | RED (2) |
| M9 | path: only check the LAST dot-separated part | RED (8) |
| M10 | path: allow any extension | RED (6) |
| M11 | per-file cap: never abandon a long body | RED |
| M12 | `Content-Length`: drop the early refusal | RED |
| M13 | disk: always claim there is room | RED (2) |
| M14 | idempotency: fetch again even when the file is on disk | RED (6) |
| M15 | stalled: report a dead run as running | RED (2) |
| M16 | `absolute()`: stop checking the path stays under the web root | RED |
| M17 | redirect: resolve a scheme this does not speak | RED (3) |
| M18 | transport: drop both timeouts | RED |
| M19 | transport: let Guzzle follow redirects itself | RED (2) |
| M20 | path: match the SHORTER uploads root first | RED (9) |
| M21 | refusals: retry a refused reference every batch | **STILL GREEN** → now RED |
| M22 | temporary file: leave it behind on a refusal | RED |
| M23 | empty body: write a zero-byte file | **STILL GREEN** → now RED |
| M24 | dotfile: allow a leading dot | RED |

### M21 — the one that mattered

Removing the `$state === REFUSED` skip at the top of the batch loop left the
whole suite green. The reason is worth writing down: **the only refusal any test
drove through a `batch()` was a PATH refusal**, and that branch continues on its
own. The one refusal that comes from `absolute()` — a directory under the web
root that *resolves* somewhere else — had no batch-level test at all, so the line
that stops it being re-reported on every batch for ever was a line nothing
checked. That is the dead-filter shape `Api\ProductController`'s `status` filter
already cost this repository once.

Closed by *"it refuses a year folder that is a symlink out of the web root, once
and for good"*, which builds a real symlinked year folder pointing outside the
web root, drives two batches, and asserts the second reports nothing.

### M23 — the smaller one, reported anyway

Removing the zero-length check left the suite green because an empty head sniffs
to `null` anyway, so the file is still refused. **The safety outcome was
identical and the sentence was not** — and the sentence is the entire product for
somebody with no shell and no log access. *"the old host returned an empty body"*
is actionable; *"the body is not an image"* sends him looking for a corrupt file
that does not exist. Closed by a test that pins the exact wording.

### One test-harness finding, recorded because it was nearly a false green

`Http::fake(['url' => Http::response($body, …)])` hands out **the same PSR-7
response object** to every matched request, and its body stream is at EOF after
the first read. The second fetch of the same stub therefore read zero bytes.
The code did the right thing — refused it as an empty body — but a suite written
that way tests the first request and nothing after it. All 27 stubs in this file
are closures, so every request gets a fresh body.

---

## 6. Changes for the integrator · anchor + replacement

Three, in two files this lane may not edit. Every anchor verified **by count** in
the current tree.

### 6.1 `routes/web.php` — mount the route file

**Anchor** (occurs **exactly once**), inside the existing `admin-api` group:

```php
        require __DIR__.'/urls-media-admin.php';
```

**Replacement** — this **appends** one line after the anchor; the anchor text is
kept unchanged:

```php
        require __DIR__.'/urls-media-admin.php';
        require __DIR__.'/media-sideload-admin.php';
```

That group already carries `auth:admin` and `NoStoreAdminApi`, which is the whole
requirement: nothing may be chained on, because `RouteRegistrar::middleware()`
*replaces* rather than appends.

### 6.2 `resources/views/admin/app.blade.php` — the card on Store → Import

**Anchor A** (occurs **exactly once**), inside `impPaint()`:

```js
    +gbUrlsMediaCard()
    +impExportCard()
```

**Replacement** — **inserts one line into the middle** of the anchor, between the
two existing lines:

```js
    +gbUrlsMediaCard()
    +gdLiveProgressCard()
    +impExportCard()
```

**Anchor B** (occurs **exactly once**):

```js
function gbUrlsMediaCard(){
```

**Replacement** — **prepends** a whole new function immediately before the
anchor; the anchor line itself is unchanged and must remain the last line of the
replacement:

```js
/* Store → Import → the live progress page (Lane GD).

   A LINK, NOT A PANEL. The page it points at is a standalone document served
   from /admin-api/urls-media/progress-page with no build step and no dependency
   on this bundle — deliberately, because its whole job is to be trustworthy at
   the moment something has gone wrong, and this file is the thing most likely
   to be what is broken. See MediaSideloadApiController::page(). */
function gdLiveProgressCard(){
  return '<div class="card pad">'
    +'<b style="font-size:14px">Pictures &amp; live progress</b>'
    +'<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:680px">'
    +'Fetch the product photographs off the old site, straight into this shop, and watch it happen. '
    +'The page keeps going until there are none left, tells you which ones failed and why, and says '
    +'plainly whether a run is going, finished, or stopped halfway.</p>'
    +'<a href="'+impBase()+'/urls-media/progress-page" target="_blank" rel="noopener">'
    +'<button class="btn" style="margin-top:10px">Open the live progress page</button></a></div>';
}

function gbUrlsMediaCard(){
```

`impBase()` is the console's own helper (line 7677) and already resolves
`/admin-api` correctly under `KBB_BASE_PATH` and a moved admin path, so the link
needs no separate handling for either.

### 6.2b These three were applied and reverted, not merely written out

All three anchors were applied to this worktree, the preview rebuilt, and the
result driven in Chromium before being reverted with
`git checkout routes/web.php resources/views/admin/app.blade.php`. The console
rendered its Store → Import screen with **no page errors**, in this order:

```
… SEO (Yoast) · Addresses & pictures · Pictures & live progress · Export …
```

and the button opened `http://…/admin-api/urls-media/progress-page`, which
rendered *"Idle — 103 pictures still to fetch."* Screenshot:
`docs/gd-media-shots/7-console-card.png`. So the anchors are known to apply and
the result is known to work; neither is being taken on trust.

### 6.3 Nothing else

No change to `AdminCapabilities::RULES` — see §2. No change to
`KBB-Master-Plan.md`, `KBB-Progress-Dashboard.html` or `bootstrap/app.php`.

---

## 7. What still needs one real run against the live site

Nothing below can be settled from a sandbox with no egress. Each is one press of
Fetch away from being known.

1. **Does `kbeautybliss.com` still answer?** Everything here assumes it does.
   If it is already gone, the sideloader will report a page of connection
   failures — truthfully, and without writing anything — and the answer is the
   FTP copy after all. **Check this first; it decides whether any of the rest
   matters.**

2. **Is there a rate limit or a bot wall in front of it?** Cloudflare, a
   security plugin, or the host's own throttle will start returning 403 or 429
   partway through a few hundred requests. Those land as *failures*, so pressing
   Fetch again later picks them up — but if most of a run fails this way, the
   batch size wants lowering and the run wants spreading over a day. The
   spreadsheet tells him which it is: a wall of identical statuses is a wall.

3. **How big is it really?** The screen's estimate is `remaining × 350 KB` and
   nothing more. The true total is only known once it is fetched. **Watch the
   "free on the volume" figure on the first few batches** and stop if it moves
   faster than expected — shared hosting quotas are small and the site goes down
   before the pictures do.

4. **Extension/content mismatches.** Refused by design (§3 guard 4), and on a
   real WordPress library there may be a handful — a `.jpg` that is really a PNG
   is a thing older editors produced. They appear in the spreadsheet with that
   exact reason. If there are only a few, re-upload them by hand through
   Store → Media. If there are hundreds, tell the integrator: it is then worth
   the separate, careful change of renaming the file *and* re-pointing the row in
   the same transaction, which this lane deliberately did not build.

5. **The gallery count.** 671 products with galleries is the number in the brief;
   this lane never saw the real export. The first `progress` read gives the true
   `total`, and it is the number to sanity-check before starting.

6. **Then, and only then, the rewrite.** Fetching puts the file on disk; it does
   **not** change the row. Until Lane GB's *Addresses & pictures → apply* is run,
   every product page still points at the old host and the paths stage still
   reads 110 remote. The progress page says so in its own words. **Do not switch
   the old site off between the two steps.**

### What the owner should watch, in one line each

* **"still on the old site" on the paths card.** That is the number that decides
  whether the migration is finished. It does not move when pictures are fetched;
  it moves when the rows are re-pointed.
* **The STALLED banner.** It means a request was killed, not that anything broke.
  Press Fetch.
* **The failures table.** Nine identical 403s is a wall in front of the old site.
  Nine different reasons is nine different pictures.
* **"free on the volume".** If it drops fast, stop and ask.

---

## 8. What was chosen not to do, and why

* **No rename-to-match-the-sniff.** §3 guard 4. It would break the contract with
  `MediaRewrite` and silently point rows at files that are not there.
* **No SVG.** §3 guard 4.
* **No `getimagesizefromstring()` as the primary test.** §3 guard 3.
* **No queue, no background job.** There is no worker on this host.
  `ImportDriver` established that and nothing has changed.
* **No new Composer dependency.** Laravel's HTTP client was already there;
  `vendor/` never ships.
* **No websockets and no server-sent events.** Shared hosting. The page polls,
  the **server** decides the interval (3 s while running, 15 s otherwise), and it
  stops polling entirely when the tab is hidden.
* **No percentage for the catalogue import stage.** `import_checkpoints` records
  how many source rows were *consumed* and nothing records how many a CSV
  contains until it has been read to the end. A bar with no denominator looks
  like information and is not — and drawing it at 0/0 filled a full green bar
  under the words *"No catalogue import has been run"*, which was caught in the
  screenshots and fixed: **a stage with no total now gets no bar at all.**
* **No shared `uploadsRelative()` with `MediaRewrite`.** The duplication is
  deliberate and documented at the method. That one answers "which rows may I
  rewrite", where a wrong answer writes a string into a column. This one answers
  "where may I write bytes into the web root", where a wrong answer is remote
  code execution. They agree on the two upload roots — they have to, or the file
  lands where nothing looks for it — and this one is strictly harsher about
  everything else.
* **No retry of refusals by the ordinary Retry button.** A refusal re-tried
  produces the identical refusal one batch later. `retry(true)` exists for the
  case where the catalogue itself was corrected, and is not wired to a button.

## 9. Reproducing the proof

The rig is not committed — it is three `php -S` servers and a throwaway SQLite
database. To rebuild it: a router that serves the cases in the §4 table on one
hostname, a second hostname that logs every request it receives, a third that
never answers, the shop under `php -S` with `KBB_PUBLIC_PATH` pointing at a web
root outside the application root, **distinct hostnames rather than ports**
(§4), and `routes/web.php` patched with §6.1 for the duration.

`vendor/` in a worktree must be **hard-linked**
(`cp -al …/vendor …/gd/vendor`), never symlinked: a symlink resolves Composer's
`$baseDir` back to the main repo and silently tests the wrong code.

## 10. Test runs

```
vendor/bin/pest                                    4450 passed, 21 skipped (30495 assertions)
KBB_TEST_DB=kbb_gd vendor/bin/pest -c phpunit-mysql.xml
                                                   4456 passed, 15 skipped (30821 assertions)
find app database routes -name '*.php' -print0 | xargs -0 -n1 php -l    clean
```

This lane's own file: **86 tests, 248 assertions.** Baseline before the lane was
4364 passed, so it adds 86 and breaks none.

`expect(...)->not->toContain($needle, $message)` appears **nowhere** in this
lane: `toContain` is variadic and reads the message as a second needle, so it
passes vacuously. Absence is asserted with `array_diff`, `str_contains` or a
plain identity throughout.
