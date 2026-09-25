# Shoppable UGC video — the storage side

Phase 20, Lane V2. The round after the previews: the two tables, the model, the
ingest path and the admin screen. **Nothing in it reaches the storefront**, and
that is the plan's own sequencing rather than an unfinished feature — the owner
has not picked a rail or a player (`docs/UGC-VIDEO-PLAN.md` §8, questions 1 and
2), and the round that draws one is the round that adds a page.

`docs/UGC-VIDEO-PLAN.md` §4 is the data model this implements, §0b.5 is the pair
of columns round three added to it, §6 is where the screen sits and §7 is its
security. This file records what was built, what was decided, and the two things
that are still somebody else's to answer.

---

## Where it is in the admin

**`Content → Shoppable video`** — a row in the existing Content group, under
Media Library.

| What | Path |
|---|---|
| The library | `Content → Shoppable video` |
| Add one | `Content → Shoppable video → New video` |
| The files | `Content → Shoppable video → Edit → The files` |
| Credit and permission | `Content → Shoppable video → Edit → Credit and permission` |
| Publishing and language | `Content → Shoppable video → Edit → Where and when` |
| **Tagging several products** | `Content → Shoppable video → Edit → Products in this video` |

There is no `Appearance → Shoppable video` yet: §6 puts the grid style and the
opened state there, and both are choices the owner has not made.

---

## What the owner does per video, in plain words

It depends on one thing nobody has checked yet, so the screen asks the server
and says which it is before he uploads anything.

**If `ffmpeg` is on the server — he uploads one file.**

1. New video, type a title.
2. Choose the MP4. The poster frame and a 2.5-second silent 360×640 loop are cut
   from it on that same request.
3. Type the creator's handle and their link, and set Permission to **granted**.
4. Search for the products that appear in the clip and add them, in the order he
   wants them shown.
5. Status → published. Save.

**If `ffmpeg` is not there — he uploads two files.**

The same steps, with one addition: after the video, he presses **Choose from the
Media Library** and picks a **poster image** (the popup's own *Upload new* is how
a picture that is not in the library yet gets there). That is all a video needs
to be published. A teaser is optional, and a library with no teasers is not a
broken feature — it is the poster-only rail at about 22 KB a tile, which is
exactly what the previews already draw under Save-Data. If he ever wants the
loop, he can cut one on his own machine and upload it into the third box.

**The poster has no "Choose File" button at all, in either case.** The owner's
own rule — *"on any upload media on the whole backend, the media library is a
must to show"* — is enforced by `AdminMediaPickerEverywhereTest`, which scans
every admin Blade for a raw `<input type="file">`. It caught this screen's first
draft. The clip and the teaser keep their file inputs and are excluded from that
rule by what they accept: the library is an image library, and a 64 MB clip has
no business in it. A library picture is **copied** into `/uploads/ugc/` and
re-checked byte by byte — see `UgcMedia::adopt()` for why a copy rather than a
reference.

**He is never asked for a second video in either case.** §0b.1 is explicit about
that, and it is the reason the teaser is a derivative rather than a field.

---

## When there is no teaser

A first-class state, not an error. `UgcVideo::mediaState()` answers one of three:

| State | Means | Publishable |
|---|---|---|
| `none` | no clip, or no poster | no |
| `poster_only` | clip + poster, no teaser | **yes** |
| `ready` | clip + poster + teaser | yes |

`publishBlockers()` is the gate and the teaser is deliberately absent from it.
`UgcPublicationGateTest > it publishes a clip that has a poster and no teaser at
all` is the pin; adding a teaser check to `publishBlockers()` turns it and the
scope case beside it red.

The poster **is** required, and not for looks: §2 budgets layout shift at zero
and the tile reserves its box from `width`/`height`, because two tests in this
repo forbid the element-measuring APIs by name. Those two columns are read from
the poster's own header with `getimagesize()`, so a server with no ffprobe still
knows the box.

---

## The data model

### `ugc_videos`

`slug`, `title`, `caption`, `status`, `file_path` + `bytes`, `teaser_path` +
`teaser_bytes`, `poster_path` + `poster_bytes`, `width`, `height`,
`duration_ms`, `source_platform`, `source_url`, `creator_handle`, `creator_url`,
`rights_status`, `rights_granted_at`, `rights_evidence`, `locale`, `position`,
`published_at`.

Three file paths rather than one, because round three measured that seeking a
full clip back to zero and the `#t=0,2.5` media fragment both fetch **every
byte** of the file: 12,786,600 B for a rail of eight either way, against
1,055,160 B of separate teasers. A media fragment tells the player where to
start and the network nothing.

`published_at` is compared against `now()` on read — this host has no cron and no
queue worker, so a scheduled flip would be a flip that never happens.
`ProductVisibility` sets the same precedent for the catalogue.

### `ugc_video_product`

`ugc_video_id`, `product_id`, `position`, `at_ms`, with a unique index on the
first two. This is requirement one: several products on one clip, in the owner's
own order. `at_ms` is player D's and nobody else's — nullable and null
everywhere by default, so choosing any other player never asks anybody to type a
timestamp.

### Arabic

`title` and `caption` are on `UgcVideo::$translatable`, and the screen draws the
shared `KBBArabic` box beside each, saving through the same
`TranslationInput` → `saveTranslations()` path every other editor uses.

**No Translate button is drawn on either field, deliberately.** §5: a creator's
caption is her voice, and a machine-translated caption attributed to a named
person is putting words in her mouth. `UgcVideo` is therefore also absent from
`TranslationEstimate`'s machine-translation map. Type it, or leave the English
and let `locale` keep the clip on the English storefront.

---

## Security

| Rule | Where |
|---|---|
| Uploads validated by **content, twice**, never by name | `App\Services\UgcMedia::store()` |
| A URL is decoded and stripped **before** its scheme is read | `App\Services\UgcPath::link()` |
| A stored path is an allowlist of shapes | `App\Services\UgcPath::stored()` |
| A library path is a *separate*, slightly wider allowlist | `App\Services\UgcPath::library()` |
| A select stores one of its own options or the default | `Rule::in()` in `UgcVideoController::write()` |
| A public feed is an explicit key list, never a model | `UgcVideo::toApi()` |
| Every admin endpoint has its own capability, failing closed | `ugc.view`, `ugc.manage` |

### The upload check, in order

1. **Size first**, before the file is opened. A refusal, never a truncation —
   §3.4 asks for exactly this, and a half-written MP4 is a tile that spins for
   ever. 64 MB for a clip, 8 MB for a teaser, 4 MB for a poster.
2. **finfo over the bytes on disk** (`UploadedFile::getMimeType()`), never
   `getClientMimeType()`, which is a string the browser sends.
3. **Our own magic-byte read** of the first 32 bytes — ISO-BMFF's `ftyp` box
   *with its major brand checked*, Matroska's EBML signature, and the three
   still-image signatures.
4. **The two must agree** on the same stored extension. Either alone is a single
   point of failure in a different direction: finfo's `magic` database lives
   outside this repo and is configured outside this application, and our own
   table is short by design.
5. **A last look for a PHP open tag** in the first 512 bytes. A real ISO-BMFF or
   EBML header there is box lengths and four-character codes.

Then a **generated filename** — the client's never touches the filesystem — with
the extension the bytes earned, under `public/uploads/ugc/`.

**`public/uploads/`, not the storage symlink.** This app deploys as a zip applied
through Store → Core Updates, and nothing in that path runs
`php artisan storage:link`. The symlink is a silent failure on the live server:
the upload succeeds and the file 404s for ever with nothing in any log.
`Store\ReviewController` records this in its own comment and
`Admin\MediaUploadController` repeats it; this is the third place following the
two.

### What was deliberately not done

**No `.htaccess` in the upload directory.** Every form of it — `Require all
denied` in a `FilesMatch`, `Options -ExecCGI`, `php_flag engine off` — is
answered by Apache with a **500 for the whole directory** when the server's
`AllowOverride` does not permit that directive class. Nobody in this repo can see
the Cloudways vhost, so shipping one is a coin-flip between a lock that adds
nothing (the files already carry the extension their own bytes earned) and a
video library that 500s on the live shop with nothing in the application log to
explain it.

**To add it once somebody has read that vhost:** confirm `AllowOverride` includes
`Limit` (or `All`) for the web root, then drop this into
`public/uploads/ugc/.htaccess`:

```apache
<FilesMatch "\.(?i:php|phtml|phps|phar|pl|py|cgi|sh)$">
    Require all denied
</FilesMatch>
```

Under nginx it is ignored and costs nothing either way.

---

## Two questions this round could not answer

1. **Does `ffmpeg` exist on the Cloudways box?** §8 question 4, still open. It is
   `which ffmpeg` in **Servers → Launch SSH Terminal**, ten seconds. Both answers
   are supported and the screen says which one this server gave. If it is absent
   and cannot be installed, `KBB_FFMPEG` in `.env` will point at a binary
   anywhere on the box, so a static build dropped in a home directory is enough.
2. **Will the owner ask creators for written permission?** §3.3. `rights_status`
   makes it a column and a precondition rather than a note, but the answer is a
   content decision. No permission → that creator is not featured; link out to
   their post with an ordinary `<a>` instead.

---

## Wiring, for the integrator

Two lines, both in files this lane may not edit.

**`routes/web.php`** — inside the existing `admin-api` group (the one carrying
`web`, `auth:admin` and `NoStoreAdminApi`), beside the other Content route files:

```php
require __DIR__.'/ugc-admin.php';
```

**`resources/views/admin/app.blade.php`** — at the very end, beside the nine
other screen partials, after `@include('admin.partials.site-url-banner')`:

```blade
@include('admin.partials.ugc-library-screen')
```

The partial registers its own sidebar entry and wraps `window.go`, so that one
line is the whole of the change to that file.

`database/migrations/2027_01_10_000001_clear_caches_ugc_library.php` ships beside
them: a route added by a package does nothing until the compiled route table is
gone.

---

## The pictures

`docs/ugc-admin-shots/` — every screen at 390px and at 1280px, in Chromium at
deviceScaleFactor 2, with `docs/ugc-admin-shots/measurements.json` carrying the
numbers behind them.

| File | What |
|---|---|
| `library-empty-*` | the library with nothing in it, and the server's own answer about ffmpeg |
| `editor-new-*`, `editor-filled-*` | the form, empty and filled, with an Arabic box beside the title and the caption |
| `upload-disguised-refused-*` | **a PHP script named `clip.mp4`, refused, with the sentence the owner is shown** |
| `poster-picker-*`, `poster-picked-*` | the Media Library popup — the poster's only way in |
| `editor-uploaded-*` | clip in, poster in, teaser absent, and the box read off the poster |
| `editor-tagged-*` | three products tagged, in order, with reorder and remove |
| `editor-published-*` | the same video, published |
| `editor-blocked-*` | a publish refused, with the three reasons named |
| `library-one-*` | the library row: creator, product count, and the four state pills |
| `storefront-home-*`, `storefront-shop-*` | **the proof that nothing appeared on the shop** with a published video in the table |

Measured at both widths, both directions of the run: `scrollWidth 390 / 1280`,
`#content` scrollWidth equal to its clientWidth (390 and 1032), widest child 362
and 996, **zero horizontal overflow anywhere**. The storefront pages measured
`scrollWidth − clientWidth = 0`, no `<video>` element and no mention of the
published clip.
