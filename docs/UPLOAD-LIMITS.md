# Upload limits — what caps a file, and how to raise it on Cloudways

Lane U1, 27 September 2026. Written because the owner uploaded an 8.4 MB `.mp4`
at **Content → Shoppable video → All clips → step 2**, watched the progress bar
reach 100%, and was told *"That file was not accepted. 8.4 MB — nothing on the
clip was changed."* His file was fine. The server threw it away and the
application blamed the file.

---

## 1. The three numbers, and which one is biting

An upload has to fit under all three of these. The smallest one wins.

| Limit | Where it lives | On this shop |
|---|---|---|
| `upload_max_filesize` | PHP, per file | **2M** |
| `post_max_size` | PHP, per whole request body | **8M** |
| `UgcMedia::MAX_BYTES[clip]` | this app, per clip | 64 MB |

So the real ceiling for a video today is **2 MB**, not 64 MB, and the thing
capping it is PHP. `post_max_size` bounds the *whole* multipart body — the
`kind` field, the boundaries and the part headers go inside it too — so the
usable figure is `post_max_size` minus a few hundred bytes of overhead
(`App\Support\ServerUploadLimits::MULTIPART_OVERHEAD` allows 4 KB, generously).

`max_file_uploads` (20 here) bounds how many files one request may carry. This
module sends one file per request, so it never binds — it is listed because
anyone changing the other two will see it beside them.

### Read the live numbers without SSH

Two places in the admin now print them, and both read the process that is
actually serving the request — which is the only reading that settles it, because
the PHP-FPM pool serving the web has a different `php.ini` from the `php` you get
on the SSH command line:

- **Content → Shoppable video → All clips → step 2.** Where the server is the
  binding limit, an amber note above the two file boxes names both directives,
  both values and the real ceiling. Where it is not, the note is not drawn and
  the box says 64 MB.
- **Store → Store Import / Export.** Has printed *"This server accepts uploads up
  to 2M each (form limit 8M)"* since long before this lane. Same two values.

---

## 2. The two ways an upload dies, and how they look

Both were reproduced against a bare `php -S` on the build box with the same two
values the live shop has. These are measurements, not theory.

### Mode A — the file is over `upload_max_filesize`, the body fits

```
3 MB file, post_max_size 8M
CONTENT_LENGTH = 3146044   count($_POST) = 1   count($_FILES) = 1
$_FILES['file']['error'] = 1 (UPLOAD_ERR_INI_SIZE)   ['size'] = 0
```

PHP hands over a file handle pointing at nothing. Before this lane,
`$request->validate(['file' => ['required','file']])` failed on it and the screen
printed its generic sentence. It now answers **422** naming
`upload_max_filesize`, its value, and that the file itself is fine.

### Mode B — the whole body is over `post_max_size`

```
9 MB file, post_max_size 8M
CONTENT_LENGTH = 9437499   count($_POST) = 0   count($_FILES) = 0
```

PHP discards the **entire** request body. There is nothing left to validate. The
owner's 8.4 MB clip was this one.

Two things happen here, and it is worth knowing which:

- `Illuminate\Http\Middleware\ValidatePostSize` is in Laravel 11's **global**
  middleware and throws `PostTooLargeException` → **413** before the router runs,
  so the controller is never entered. Its 413 body carries `message` and no
  `error` key, which is exactly why the console fell through to "That file was
  not accepted". The screen now explains a 413 itself, from the numbers the
  library payload gave it.
- That middleware's guard is `$request->server('CONTENT_LENGTH') > $max`, so a
  request with **no** `CONTENT_LENGTH` — a chunked body, which a proxy can
  produce — walks past it while PHP still throws the body away.
  `App\Support\UploadArrival` closes that gap by looking at the bags rather than
  the header: a `multipart/form-data` POST with both `$_POST` and `$_FILES` empty
  did not lose its file, it lost its whole body.

**CSRF is not a third failure mode here, and that was checked rather than
assumed.** The console signs writes with an `X-XSRF-TOKEN` *header* read from the
`XSRF-TOKEN` cookie, not a form field, so a discarded body takes no token with
it. `UgcAddClipFlowTest` pins the header form of that signing by name.

### A genuinely bad file still reads as a bad file

`UgcMedia`'s own refusals were good and are untouched: wrong type, the two
readings disagreeing, a PHP open tag in the header. Those still say "the check
reads the file itself, not its name — renaming it will not help", and they carry
no `reason` key, so the screen does not dress them up as a server limit.

---

## 3. Raising it on Cloudways

The shop is `extrabeauty.ae` on Cloudways. App root
`/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app`, web root
`/home/1672906.cloudwaysapps.com/yjmakdgtjs/public_html` (CLAUDE.md is the
authority on that layout). **It has SSH** — Servers → Launch SSH Terminal, or
Master Credentials.

Pick a target first. A 15-second 1080×1920 phone clip is ~20 MB, so `64M` per
file is the number that makes the app's own cap reachable; `post_max_size` must
be a little larger than `upload_max_filesize`, never equal, because the body
carries more than the file.

```
upload_max_filesize = 64M
post_max_size       = 72M
```

### Route 1 — the Cloudways panel (server-wide, survives everything)

**Servers → (your server) → Settings & Packages → Settings → Basic → Upload
Size**, set in MB, then **Save Changes**. Cloudways applies it to PHP-FPM and
restarts the pool.

⚠ **Two honest caveats.** Cloudways moves these labels between releases, so if
"Upload Size" is not where this says, look for the PHP settings on the same
Settings & Packages page. And that single control does not always move
`post_max_size` in step with `upload_max_filesize`. **Do not trust the panel —
verify with §4 below**, which reads the live serving process.

### Route 2 — a `.user.ini` in the web root (per-application, no panel needed)

Both directives are `PHP_INI_PERDIR`, which under PHP-FPM means a `.user.ini` in
the document root sets them. One command over SSH:

```sh
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/public_html
printf 'upload_max_filesize = 64M\npost_max_size = 72M\n' > .user.ini
cat .user.ini
```

⚠ **It takes up to five minutes to take effect.** PHP caches `.user.ini` for
`user_ini.cache_ttl` seconds, 300 by default. A screen that still says 2M one
minute later has not failed; wait and reload.

### Route 3 — Application Settings → PHP-FPM Settings

**Applications → (your app) → Application Settings → PHP-FPM Settings** takes
pool directives. Both of these are pool-settable:

```
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 72M
```

Save and let Cloudways restart the pool. Use this where route 1's single control
will not move the two independently.

### ▲ What a package CANNOT do, stated rather than attempted

`App\Services\Update\UpdateGuard::ALLOWED_PREFIXES` is `app/`, `config/`,
`database/migrations/`, `database/seeders/`, `resources/`, `routes/`,
`public/build/`, plus four named files. A `.user.ini` or `.htaccess` at the app
root or the web root is **outside every one of them** and the guard refuses the
package at the door.

`public/build/` is inside the allowed set, but a `.user.ini` there governs only
that directory — which serves compiled assets and takes no uploads — so shipping
one would be a file that lands and does nothing. **Nothing in this repo can raise
these limits by shipping code.** It is a one-time server change, by hand, through
one of the three routes above. What the app *can* do, and now does, is measure
the limit, advertise it honestly, and name the directive to change.

---

## 4. Verify it, from the process that actually serves the request

**This is the step that matters**, because the CLI and the web run different ini
files. `php -i` over SSH tells you about the CLI and can disagree with the web by
a factor of thirty.

1. Reload **Content → Shoppable video → All clips**, open any clip, go to step 2.
2. If the amber note is gone and the video box says **"up to 64 MB"**, the
   serving process has the new values. Done.
3. If the note is still there, it prints the values PHP-FPM currently has — so it
   also tells you whether route 1 moved one directive and not the other.

Two more readings, for a second opinion:

- **Store → Store Import / Export** prints the same two values.
- Applying any package prints them: the `clear_caches_upload_limits` migration
  echoes `upload_max_filesize`, `post_max_size` and the largest clip that can
  really be uploaded.

For the CLI reading (useful, but *not* the web answer):

```sh
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("post_max_size"), PHP_EOL;'
php --ini | head -3
```

---

## 5. Where the code lives

| File | What it does |
|---|---|
| `app/Support/ServerUploadLimits.php` | parses the ini shorthand PHP's own way, and computes `min(app cap, upload_max_filesize, post_max_size − overhead)` |
| `app/Support/UploadArrival.php` | decides whether an upload arrived, and whose fault it was if not |
| `app/Http/Controllers/Admin/UgcVideoController.php` | runs the arrival check **before** `validate()`, and sends the real caps down with the library |
| `resources/views/admin/partials/ugc-library-screen.blade.php` | advertises the real ceiling and draws the amber note |
| `app/Services/ImportConsole/ImportWorkspace.php` | its two ini reads now come through the shared reader |
| `tests/Feature/UploadLimitsTest.php` | the arithmetic, both modes end to end, and Import unmoved |

### The ini shorthand is not a cast, and its edge cases are measured

`(int) '8M'` is 8. PHP reads the leading integer and then takes the **last
character** as the multiplier. Both of these were run against a real `php -S`
with a real upload, not read off the manual:

| Value | What PHP enforces | Evidence |
|---|---|---|
| `1.5M` | 1 MiB — the fraction is dropped, the multiplier kept | 1.5 MiB file → `error=1`; 1,048,000 bytes → `error=0` |
| `8MB` | **8 bytes** — `B` is not a multiplier, so the suffix is ignored | 1 MB file → `error=1` |
| `0` / `-1` | no limit, for **both** directives | 1,048,000-byte upload arrived intact, four ways |

So write `64M`, never `64MB` and never `64.5M`. PHP warns about both at startup
and then quietly does something else.

---

### One guard this lane tripped, and satisfied rather than relaxed

`UgcShipsOffTest` greps every file under `app/` and the storefront views for the
shoppable-video model's class name, requiring zero hits outside the module and
the admin controllers — because that module has to be able to ship switched off,
and a grep is the only check a non-firing code path cannot fool. The first draft
of `UploadArrival` named the video controller in a *docblock* and the guard went
red, correctly: a `Support/` class is inside that net. The explanation survives
without the name, so the name went and the docblock now records why. The guard
was not touched.

## 6. Known, and not fixed by this lane

- **The Media Library's own upload endpoint** (`Admin\MediaUploadController`,
  `POST /admin-api/media/upload`) has the same blind spot: it caps at 5 MB with
  no idea the server stops at 2M, and a picture over it gets the validator's
  message. The clip editor's *cover* box no longer sends such a file — its
  pre-flight now refuses against the real ceiling — but the Media Library screen
  itself, and every other screen that uploads through it, still reads as "that
  image is too large" when the server is the thing refusing. It is a different
  lane's controller and a different lane's screen.
- **Content → Shoppable video → Sections has a second clip uploader with the
  same lie.** `resources/views/admin/partials/ugc-sections-screen.blade.php`
  line ~1148 hard-codes *"Up to 64MB. Checked by its own bytes and not by its
  name."* on a file row that POSTs to the **same** `/ugc-videos/{id}/media`
  endpoint. Two halves, and only one of them is still broken:
  - Its refusals are already honest, for free. Its `explain()` ends with
    `e.body.error`, so every sentence this lane's controller composes — mode A,
    the chunked mode B, a server fault — reaches that screen unchanged.
  - The **advertised number** is still 64MB, and a 413 raised by
    `ValidatePostSize` still falls through to "That file was not accepted"
    because that body carries no `error` key. It also uploads with plain
    `fetch`, so it has no progress bar at all.

  Left alone deliberately: that screen and `UgcSectionController` belong to
  another lane, the fix needs both files (the payload does not carry `limits`
  there yet), and the round only needed the one screen. The work is a copy of
  `serverCapHTML()`, `capBytes()` and the 413 branch, plus `limits` on
  `UgcSectionController::index` — all four already exist to copy from.
- **A console-wide fix exists and was deliberately not taken.** A
  `renderable(PostTooLargeException::class, ...)` in `AppServiceProvider::boot()`
  would give every upload screen in the back office an honest 413 body in about
  ten lines, from a file that ships. It was left alone because
  `AppServiceProvider` is shared by every lane and this round only needed the one
  screen. It is the right next step if a second screen needs it.

---

## 7. The pictures, and what was measured

`docs/upload-limit-shots/` — Chromium 1194 (playwright), at **1280×900** and
**390×844**, against a real app on `php -S` with this box's own
`upload_max_filesize=2M` / `post_max_size=8M`. Harness:
`tests/browser/lane-u1-upload-limits.mjs`, which runs unchanged against either
revision so the two sets are comparable.

| Shot | What it shows |
|---|---|
| `*-1-step2-resting` | step 2 as it sits |
| `*-2-refused-8.4mb` | **the owner's own file size** |
| `*-3-refused-3mb` | over `upload_max_filesize`, under `post_max_size` |
| `*-4-in-flight` | the bar at 19%, throttled to 60 KB/s |
| `*-5-slow` | the same upload at 72%, 23 seconds in |
| `*-6-cancelled` | Cancel, and the Try again it leaves (AFTER only) |
| `*-7-accepted` | 1.9 MB arriving whole |

### The sentence the owner was shown, and the one he gets now

**BEFORE**, `before-1280-2-refused-8.4mb.png`, verbatim from the panel:

> owners-8.4mb.mp4 — not accepted — **That file was not accepted.** 8.4 MB —
> nothing on the clip was changed.

The drop zone above it read *"or press to choose one · MP4 or WebM, up to 64
MB"*, and the browser console logged **413**.

**AFTER**, `after-1280-2-refused-8.4mb.png`:

> owners-8.4mb.mp4 — not accepted — **That file is 8.4 MB and the most that can
> be uploaded here is 2 MB. It was not sent, so nothing on the clip was changed.
> The limit is PHP on this server and not Shoppable video, which allows 64 MB for
> a clip: upload_max_filesize is 2M. Raise it on the server and this box will
> take the bigger file.**

The drop zone reads *"up to 2 MB"*, and the amber note above it explains the
number. No request is made at all — the pre-flight now refuses against the real
ceiling, so the 8.4 MB never leaves the browser.

### Numbers

| | 1280 | 390 |
|---|---|---|
| `documentElement.scrollWidth` / `clientWidth` | 1280 / 1280 | 390 / 390 |
| `#content` scrollWidth / clientWidth | 1032 / 1032 | — |
| amber note box | 966 × 135 | 336 × 313 |
| amber note font-size | 11.5px | 11.5px |
| upload panel, in flight | 966 × 109 | 336 × 109 |
| upload panel, cancelled | 966 × 96 | 336 × 180 |
| upload panel, accepted | 966 × 77 | 336 × 77 |
| broken images | 0 | 0 |

No horizontal overflow at either width, at any of the seven states. The note is
tall on a phone (313px of an 844px viewport) because it carries the remedy; it is
drawn only while the server is the binding limit and disappears the moment that
stops being true.

### The progress bar, throttled to 60 KB/s so the numbers were real

| | BEFORE | AFTER |
|---|---|---|
| at 19% | `Sending to the server.` | `Sending to the server. 6s so far.` |
| at 72% | `Sending to the server.` | `Sending to the server. 23s so far.` |
| Cancel | not offered | `You stopped that upload, so nothing was sent and nothing on the clip was changed.` + **Try again** |
| accepted | `✓ 100% · 1.9 MB arrived whole` | unchanged |

⚠ **One defect the picture caught and the report would not have.** The first
draft used one clock for both stages. While bytes are flowing, "time since the
last byte moved" is 0 or 1 by definition, so a 23-second upload read *"Sending to
the server. 1s so far."* The send stage now shows elapsed time and the stall
interval only once it really is a stall; the server stage shows time since the
handover, which is the interval that had no way of being described at all.
