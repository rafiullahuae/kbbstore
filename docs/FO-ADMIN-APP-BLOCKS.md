# Lane FO — the three blocks for `resources/views/admin/app.blade.php`

Phase 15, Appearance → Homepage content.

That file is 19,000+ lines and several lanes edit it at once, so this lane did
not touch it. Everything the screen needs from it is below as **anchor → exact
replacement**, in the order they appear in the file. Each block is a copy of a
line that exists today with one addition; nothing is deleted and nothing else on
the line changes.

The screen itself is `resources/views/admin/partials/homepage-content-screen.blade.php`
and needs no other edit: it registers its own sidebar row through
`kbbAddNavEntry()` and wraps `window.go` for its own id, the way
`admin/partials/translation-screens.blade.php` and
`admin/partials/coupon-editor-screen.blade.php` already do.

All three were applied to a working copy, the console was driven in a browser,
and the screenshots in `docs/fo-homepage-shots/` were taken through them. They
applied with no adjustment.

**Blocks 1 and 2 must land together.** `AdminNavAndIdsTest` derives the
`LATE_RENDERED` set from the file — every `TITLES` id that misses `go()`'s
dispatch object, the frame maps and the `p-` branch — and fails if the literal
has drifted from it in either direction. Applying block 1 alone was tried, and
it fails with the sentence that names the consequence:

```
hpcontent  is in TITLES as "Appearance · Homepage content" but has no entry in
           go()'s dispatch object, no frame and no boot of its own, so
           ?go=hpcontent draws renderDash() under that heading and says nothing.
           Add it to LATE_RENDERED, or give go() a renderer for it.
```

With both applied, that file is 36 passed.

---

## Block 1 — `const TITLES` gains one entry

**The deep link, not the breadcrumb.** The screen sets its own crumb and title
when `window.go` reaches it, so those are already right. What `TITLES` decides
is whether `?go=hpcontent` is a routable id at all: the replay reads
`TITLES[asked] || PLACEHOLDERS[asked]` and falls back to `dash` otherwise, so
without this entry the Modules screen's Open button for `banners` — which now
points here — lands the owner on the dashboard.

**Anchor** (in the single-line `const TITLES={…}` literal, line ≈2638):

```
'homepage':['Appearance','Homepage'],'productpage':['Appearance','Product page'],
```

**Replace with:**

```
'homepage':['Appearance','Homepage'],'hpcontent':['Appearance','Homepage content'],'productpage':['Appearance','Product page'],
```

---

## Block 2 — `LATE_RENDERED` gains one id

The deep-link replay. `go()`'s dispatch object ends `||renderDash`, so an id
that is routable but absent from that object draws the **dashboard** under this
screen's own breadcrumb — and `?go=hpcontent`, which is what the Modules
screen's Open button for `banners` now sends the owner to, would land there with
nothing on the page to report it.

The screen satisfies the condition this set requires: its `window.go` calls
`render()` synchronously before it awaits anything, so the replay's marker
inside `#content` is already destroyed by the time its task runs and nothing is
drawn twice.

**Anchor** (line ≈6845):

```
const LATE_RENDERED=new Set(['media','tax','tr-settings','tr-progress','tr-strings','tr-machine']);
```

**Replace with:**

```
const LATE_RENDERED=new Set(['media','tax','tr-settings','tr-progress','tr-strings','tr-machine','hpcontent']);
```

---

## Block 3 — one `@include`, at the foot

Goes after the Translation include and before the raw block that closes the
document, so it runs once the console's own script has defined `window.go`,
`toast()`, `uToken()` and the design tokens the screen borrows.

**Anchor** (the last include in the file):

```
@include('admin.partials.translation-screens')
```

**Replace with:**

```
@include('admin.partials.translation-screens')

{{-- Appearance → Homepage content (Phase 15, Lane FO).

     The words on the homepage, as opposed to which of its sections appear —
     which is what Appearance → Homepage next door has always owned. Three
     settings the storefront reads were written by nothing in the tree:
     `home_banners` (the hero slider, carrying the page's only h1),
     `about_text` and `home_ticker`. This is the screen that gives them an
     owner. Every control on it is drawn from a field payload the server builds
     with App\Services\ModuleSchema, so it cannot disagree with the validator.

     It changes nothing on the live shop by being applied: `home_banners` stays
     absent, and absent is what makes HomepageContent fall through to the three
     slides the shop has been rendering all along. --}}
@include('admin.partials.homepage-content-screen')
```

---

## What is NOT asked for

- **No sidebar row in `NAV`.** The row joins the existing `Appearance` group
  from the partial, directly under `Homepage`, through `kbbAddNavEntry()`. A
  `NAV` entry as well would give the owner the row twice.
- **No entry in `go()`'s dispatch object.** The partial wraps `window.go` and
  handles its own id, which is the supported pattern for a self-registering
  screen and the one the coupon editor and the four Translation screens use.
- **No `AdminCapabilities::RULES` entry.** The two endpoints sit under
  `admin-api/homepage/`, which `['*', 'admin-api/homepage/**', 'content.manage']`
  already covers for both verbs. `HomepageContentEditorTest` pins that both
  resolve to `content.manage`, so a later narrowing of that wildcard fails in
  the suite rather than 403ing an owner on a host with no shell.

## And the one line in `routes/web.php`

Not part of this file, but it ships in the same package. Inside the existing
`admin-api` group, under the three homepage routes already there (≈line 542):

```php
Route::post('/homepage/layout', [\App\Http\Controllers\Admin\HomepageApiController::class, 'applyLayout']);
require __DIR__ . '/homepage-content-admin.php';
```

When that lands, the header of `routes/homepage-content-admin.php` must stop
saying the routes are unmounted — `RouteFileHeadersTest` fails any route file
that `web.php` requires while the file still describes itself that way. Rewrite
the sentence rather than quoting the old one; the guard reads the whole file.

`database/migrations/2026_11_18_000000_clear_caches_homepage_content.php` clears
the compiled route table and the compiled views, and must be in the same
package: without it the routes 404 and the old compiled `home.blade.php` keeps
printing the hero headline unescaped while the new console invites the owner to
type into it.
