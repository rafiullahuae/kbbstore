# FM · the block for `resources/views/admin/app.blade.php`, and the two route lines

Lane FM may not edit `resources/views/admin/app.blade.php` or `routes/web.php`.
Everything else in this lane is implemented; the **three** edits below are the
remainder, written so they can be applied mechanically.

**Each block is an exact ANCHOR and an exact REPLACEMENT.** Every anchor occurs
**exactly once** in the named file at the tip this branch was cut from —
verified by count, not by eye.

All three were applied to a scratch copy, the console and the storefront were
driven in real Chromium, and the result is the screenshots in
`docs/fm-routine-shots/`. The scratch copies were then reverted; nothing in this
branch touches either file.

---

## 1 · `resources/views/admin/app.blade.php` — one `@include`

The screen is a self-contained partial. It appends its own sidebar entry to the
rendered nav and wraps `window.go`, exactly as the nine screens already included
there do, so this single line is the whole of the change to that file.

**ANCHOR** (the last `@include` in the file, on its own line):

```
@include('admin.partials.translation-screens')
```

**REPLACEMENT**:

```
@include('admin.partials.translation-screens')

{{-- Catalog → Build my routine (Lane FM). The role each product plays, which
     products are still untagged, the eight routines and the module's two
     settings. It registers its own sidebar entry inside the Catalog group and
     wraps window.go, exactly as the screens above do, so this include is the
     whole of the change to this file.

     It changes nothing on the live shop by being applied: the module ships off,
     and with it off /routines is a 404 and no other page differs by a byte. --}}
@include('admin.partials.routines-screen')
```

**What breaks without it.** Nothing 500s and nothing errors, which is the
problem: the five `/admin-api/routine*` endpoints answer perfectly and there is
no screen anywhere in the console that calls them, so the role tagging — the
thing the whole feature depends on — cannot be done at all. The owner would find
Store → Modules offering a switch called "Build my routine" whose settings link
lands on a screen that does not exist.

---

## 2 · `routes/web.php` — the storefront pair

**ANCHOR** (occurs once):

```
Route::get('/reviews',     [PageController::class, 'reviewWall'])->name('review-wall');
```

**REPLACEMENT**:

```
Route::get('/reviews',     [PageController::class, 'reviewWall'])->name('review-wall');

// Phase 10 — Build my routine. Both pages 404 unless the module is on, and it
// ships off. NOT in a group: these are storefront pages. See the header of
// routes/build-my-routine.php for the whole argument.
require __DIR__.'/build-my-routine.php';
```

Position matters only in that it must be **before** the root catch-all `/{slug}`
at the end of the file, which serves blog posts from the site root. The pairing
that keeps it safe long-term is `Store\PageController::RESERVED_SLUGS`, which
gains `'routines'` in this branch;
`tests/Feature/RootSlugCollisionTest.php` walks the registered routes and fails
if a new static first segment is missing from that list.

---

## 3 · `routes/web.php` — the admin API

It **must** go inside the existing `admin-api` group — the one opened by

```
Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
```

which itself sits inside `Route::middleware('auth:admin')`.

**ANCHOR** (occurs once, inside that group):

```
        require __DIR__.'/product-editor-admin.php';
```

**REPLACEMENT**:

```
        require __DIR__.'/product-editor-admin.php';

        // Catalog → Build my routine: the role tagging, the routines and the
        // module's settings. Same guarded group as the rest of admin-api —
        // GET /admin-api/routine-products lists every product in the catalogue,
        // drafts and SKUs included.
        require __DIR__.'/build-my-routine-admin.php';
```

`tests/Feature/BuildMyRoutineTest.php` mounts both files through
`Tests\Support\BuildMyRoutineRoutes` exactly as written above and asserts the
guard off the **registered** routes — five admin paths carrying `auth:admin` and
`NoStoreAdminApi`, two storefront paths carrying neither — plus a 401 from every
admin endpoint for an anonymous caller. A remount outside the group goes red.

---

## The package must also ship the cache migration

`database/migrations/2026_11_18_000002_clear_caches_build_my_routine.php`.

On this host a route that is not in the compiled route table does not exist, and
the failure mode here is uniquely nasty: **the storefront pages 404 when the
module is off by design**, so a stale route table is indistinguishable from the
switch being off. Somebody would turn the module on, fetch `/routines`, get a
404 and conclude the feature does not work. A stale compiled `app.blade.php`
would paint the console with no Build-my-routine screen while its endpoints
answered.

---

## What this lane did NOT add, and where it belongs

**A link to `/routines` from anywhere on the storefront.** There is none. The
header, the mega menu and the footer are `menu_items` rows the owner edits on
Store → Mega Menu and the menu screens — not templates — and editing those
templates from this lane would be exactly the trace the module promises not to
leave when it is off.

So after the module is switched on, the owner adds one menu item:

| field | value |
| --- | --- |
| Label | Build my routine |
| URL | `/routines/` |

The skin quiz is the other obvious entry point — it already recommends the shape
of a routine and tells the shopper to go to the shop to fill it — but
`resources/views/store/skin-quiz.blade.php` belongs to the quiz lane, and the
link wants to carry the shopper's chosen concern into `/routines/{concern}`,
which is a mapping that lane should own. `App\Support\RoutineConcerns` exists to
make that a one-line lookup, and
`tests/Feature/QuizAndRoutinesShareOneConcernListTest.php` pins the two
vocabularies together so the join cannot drift.

---

## What the integrator does NOT have to remember

`tests/Feature/StorefrontRouteWalkTest.php` fails **both** ways — a registered
GET route with no expectation, and an expectation for a route that is not
registered — so a pair of entries added unconditionally would have to land in
the same commit as the `require` line above or the suite goes red either side of
it. This branch adds them **keyed off `Route::has('routines.index')`**, so they
are inert today and light up the moment the require line lands. Both were run in
both states before this was written.

`database/migrations/2026_11_18_000002_clear_caches_build_my_routine.php` is in
the package and needs nothing done to it.
