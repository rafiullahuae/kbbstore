<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Content → Shoppable video  (Lane V2 — Phase 20, the data model and the ingest)
|------------------------------------------------------------------------------
|
| The library: upload a clip, record who made it and whether they said yes, and
| tag several products on it. docs/UGC-VIDEO-PLAN.md §4 is the data model and
| §6 is where this sits in the admin.
|
| Resulting paths, all under the existing admin-api prefix:
|
|     GET    /admin-api/ugc-videos                 the library, plus whether
|                                                  this server can cut a teaser
|     POST   /admin-api/ugc-videos                 create
|     GET    /admin-api/ugc-videos/products        the product picker's search
|     GET    /admin-api/ugc-videos/{id}            one clip and its products
|     PUT    /admin-api/ugc-videos/{id}            save the fields
|     DELETE /admin-api/ugc-videos/{id}            delete it, and its files
|     POST   /admin-api/ugc-videos/{id}/media      upload clip / teaser / poster
|     POST   /admin-api/ugc-videos/{id}/poster     adopt a poster from the
|                                                  Media Library
|     POST   /admin-api/ugc-videos/{id}/derive     cut the poster and teaser now
|     POST   /admin-api/ugc-videos/{id}/products   tag products, in order
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| existing admin-api group — the one that already carries `web`, `auth:admin`
| and NoStoreAdminApi — beside the other Content route files. The guarded group
| is not a preference: GET /admin-api/ugc-videos/products lists products by
| name and the library rows carry a creator's rights evidence. `/api/*` is
| unauthenticated and nothing here belongs there.
|
| ── ROUTE ORDER: /products BEFORE /{id} ─────────────────────────────────────
|
| {id} carries no numeric constraint — deliberately, so /ugc-videos/abc is the
| ordinary 404 the controller returns rather than a TypeError under
| strict_types — which means a bare /ugc-videos/products WOULD match it if it
| were registered second. It is registered first. This is the same shape as the
| export-before-{id} pairs AdminCapabilityMapTest pins by name in the capability
| map, one layer down.
|
| ── CAPABILITIES. TWO, AND BOTH NEW ─────────────────────────────────────────
|
|   `ugc.view`    reading the library and searching products to tag.
|   `ugc.manage`  every write: create, save, delete, tag, AND THE UPLOAD.
|
| Neither reuses an existing capability, which is the whole point of
| per-capability gating: granting somebody the video library must not hand them
| the catalogue, the media library or the blog. §7 asks for `ugc.manage` by
| name; the read half is split off it for the reason `reviews.view` is split
| from `reviews.manage` — the person who checks whether a clip is live is not
| always the person who may replace its file.
|
| The rules in App\Support\AdminCapabilities put the WRITES ABOVE THE READS, and
| RULES is first-match-wins. A `GET admin-api/ugc-videos/**` rule listed first
| would swallow POST .../media on a read capability — the shape of the
| quiz-leads and coupons/manage mistakes that file names.
|
| They fail closed with no code here: an admin route the map does not recognise
| resolves to null and EnforceAdminCapability turns null into 403 for everyone
| but the owner. They are mapped anyway, so "owner-only because somebody decided
| so" is on the record rather than "owner-only because nobody mapped it".
|
| ── THE UPLOAD'S OWN THROTTLE ───────────────────────────────────────────────
|
| Tighter than its siblings, and for the reason the Security module's integrity
| button has one: this is the only endpoint here that does real work per call —
| up to 64 MB moved across the disk and, where ffmpeg exists, two transcodes on
| the request. Twelve a minute is more than anyone uploading by hand needs and
| far less than a shared plan minds.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2027_01_10_000001_clear_caches_ugc_library.php.
*/

use App\Http\Controllers\Admin\UgcVideoController;
use Illuminate\Support\Facades\Route;

Route::get('/ugc-videos', [UgcVideoController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.index');

Route::post('/ugc-videos', [UgcVideoController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.store');

/*
 * ▲ ABOVE /ugc-videos/{id}, and that placement is the whole rule. See the note
 * on route order above: {id} has no constraint, so this literal path has to be
 * registered before it or it is read as an id.
 */
Route::get('/ugc-videos/products', [UgcVideoController::class, 'products'])
    ->middleware('throttle:120,1')
    ->name('admin.ugc.products');

Route::get('/ugc-videos/{id}', [UgcVideoController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.show');

Route::put('/ugc-videos/{id}', [UgcVideoController::class, 'update'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.update');

Route::delete('/ugc-videos/{id}', [UgcVideoController::class, 'destroy'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.destroy');

Route::post('/ugc-videos/{id}/media', [UgcVideoController::class, 'upload'])
    ->middleware('throttle:12,1')
    ->name('admin.ugc.upload');

/*
 * The poster's only way in. There is NO raw file input for it on the screen —
 * the owner's rule is that the Media Library must be offered for any media
 * upload in the back office, and AdminMediaPickerEverywhereTest enforces it.
 * The clip and the teaser keep their file inputs and are excluded from that
 * rule by what they accept: the library is an image library.
 */
Route::post('/ugc-videos/{id}/poster', [UgcVideoController::class, 'poster'])
    ->middleware('throttle:30,1')
    ->name('admin.ugc.poster');

Route::post('/ugc-videos/{id}/derive', [UgcVideoController::class, 'derive'])
    ->middleware('throttle:12,1')
    ->name('admin.ugc.derive');

Route::post('/ugc-videos/{id}/products', [UgcVideoController::class, 'tag'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.tag');

/*
|------------------------------------------------------------------------------
| Lane V3 — sections, the appearance screen, and the engagement refresh
|------------------------------------------------------------------------------
|
|     GET    /admin-api/ugc-sections               the sections, and the library
|     POST   /admin-api/ugc-sections               create one
|     GET    /admin-api/ugc-sections/{id}          one section and its clips
|     PUT    /admin-api/ugc-sections/{id}          save the fields
|     DELETE /admin-api/ugc-sections/{id}          delete the section, NOT its clips
|     POST   /admin-api/ugc-sections/{id}/videos   set the ordered list
|
|     GET    /admin-api/ugc-appearance             Appearance -> Shoppable video
|     POST   /admin-api/ugc-appearance             save it
|
| ADDED TO THIS FILE RATHER THAN A NEW ONE, and that is worth a line: this file
| is ALREADY required from routes/web.php inside the admin-api group, so these
| nine routes need no wiring at all from the integrator. A second admin route
| file would have needed a second require in a file this lane may not edit, for no
| gain — the capabilities, the middleware stack and the reader are identical.
|
| The same capabilities, mapped in App\Support\AdminCapabilities with THE WRITES
| ABOVE THE READS, because RULES is first-match-wins and a GET rule listed first
| would resolve POST /ugc-sections/7/videos — which reorders a live rail — to
| ugc.view.
|
| ── NOTHING HERE MAKES AN OUTBOUND CALL ─────────────────────────────────────
|
| An earlier draft of this round carried POST /ugc-videos/{id}/metrics, which
| asked Instagram, TikTok or YouTube for a clip's like and comment counts. THE
| OWNER CUT IT — "okay, leave the counts for now, just get the videos from there"
| — so the endpoint, the three providers behind it and the credentials they read
| were all deleted rather than left inert. There is no fetcher in this module and
| no column for a third party's number to be written into.
*/

Route::get('/ugc-sections', [\App\Http\Controllers\Admin\UgcSectionController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.sections');

Route::post('/ugc-sections', [\App\Http\Controllers\Admin\UgcSectionController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.sections.store');

Route::get('/ugc-sections/{id}', [\App\Http\Controllers\Admin\UgcSectionController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.sections.show');

Route::put('/ugc-sections/{id}', [\App\Http\Controllers\Admin\UgcSectionController::class, 'update'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.sections.update');

Route::delete('/ugc-sections/{id}', [\App\Http\Controllers\Admin\UgcSectionController::class, 'destroy'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.sections.destroy');

Route::post('/ugc-sections/{id}/videos', [\App\Http\Controllers\Admin\UgcSectionController::class, 'videos'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.sections.videos');

Route::get('/ugc-appearance', [\App\Http\Controllers\Admin\UgcAppearanceController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.appearance');

Route::post('/ugc-appearance', [\App\Http\Controllers\Admin\UgcAppearanceController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.ugc.appearance.save');
