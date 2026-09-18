<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Lane GA — the two Laravel-era journal addresses, redirecting to the canonical
| form instead of to a near-miss of it
|------------------------------------------------------------------------------
|
| CLAUDE.md forbids this lane editing routes/web.php, so the corrected
| registrations live here and the integrator applies them. This is a
| REPLACEMENT, not an addition: web.php registers `/blog` and `/post/{slug?}`
| itself, Laravel serves the first route registered for a URI, and requiring
| this file on top would therefore change nothing at all.
|
| WHAT IS WRONG WITH THE TWO LINES THIS REPLACES. Both build their Location
| with redirect()->route(...). Laravel strips the trailing slash off a
| registered URI and route() knows nothing about the /ar language segment, so
| measured against a running server (docs/GA-SKINCARE-GUIDE.md §3):
|
|     /blog                 301 -> /skincare-guide     (canonical is /skincare-guide/)
|     /post/{slug}          301 -> /{slug}             (canonical is /{slug}/)
|     /ar/blog              301 -> /skincare-guide     (the Arabic reader loses Arabic)
|     /ar/post/{slug}       301 -> /{slug}             (likewise)
|
| Nothing 404s — the slash-less form answers 200 and self-canonicalises to the
| slashed one — so this is a wasted hop for a crawler rather than lost traffic.
| The language drop is the part a person notices.
|
| App\Support\Url::redirect() is the house answer to both halves and is already
| what /checkout/pending, BrandController::legacyIndex and
| PageController::legacyPost next door use: it returns an absolute URL carrying
| the base path exactly once and the current language segment, so Laravel passes
| it through untouched.
|
| /post/{slug?} is pointed at PageController::legacyPost rather than at another
| closure because legacyPost already IS this redirect, for the other retired
| prefix: empty slug -> the Journal index, known slug -> /{slug}/, unknown slug
| -> 404 with the redirects table still getting its turn on that 404. One
| behaviour change worth stating plainly: /post/unknown-slug was a 301 to
| /unknown-slug and then a 404; it is now a 404 at /post/unknown-slug. Both end
| at 404 and neither matches a redirect row, so this costs nothing and stops the
| site advertising a 301 to a page that does not exist.
|
|
| THE EDIT, exact anchor and replacement. The anchor occurs exactly once in
| routes/web.php (verified by count at 201d913).
|
| ANCHOR — delete these six lines:
|
|     // The Laravel-era addresses, kept as 301s so existing links and anything
|     // already indexed survive.
|     Route::get('/blog', fn () => redirect()->route('blog', [], 301));
|     Route::get('/post/{slug?}', fn (string $slug = '') => $slug === ''
|         ? redirect()->route('blog', [], 301)
|         : redirect()->route('post', ['slug' => $slug], 301));
|
| REPLACEMENT — put this in their place:
|
|     // The Laravel-era addresses, kept as 301s so existing links and anything
|     // already indexed survive. Lane GA: the Location they emit has to be the
|     // canonical form (trailing slash, current language), which is what
|     // Url::redirect() returns and what route() cannot.
|     require __DIR__ . '/kbb-journal-legacy.php';
|
| WHERE: in place, which is above the require of routes/kbb-brands-blog.php at
| the end of web.php. That matters. kbb-brands-blog.php ends in a route matching
| a single path segment at the site root, and although 'blog' and 'post' are
| both in PageController::RESERVED_SLUGS and so cannot be swallowed by it, the
| reservation list is the second guard and registration order is the first.
| Keep both.
|
| Ships with database/migrations/2026_11_21_000000_clear_caches_journal_legacy_redirects.php,
| because the host serves a compiled route table and has no shell.
|
*/

use App\Http\Controllers\Store\PageController;
use App\Support\Url;
use Illuminate\Support\Facades\Route;

// /blog and /blog/ — the Journal index this app served before 2.60.109.
// Url::redirect('/skincare-guide/'), not route('blog'): the name resolves to
// the URI Laravel registered, which has had its trailing slash trimmed off.
Route::get('/blog', fn () => redirect(Url::redirect('/skincare-guide/'), 301));

// /post/{slug} — the article prefix this app served before 2.60.109, and
// /post/ with no slug, which went to the index. legacyPost answers both, and
// answers them the way /skincare-guide/{slug}/ is already answered.
Route::get('/post/{slug?}', [PageController::class, 'legacyPost'])
    ->where('slug', '[A-Za-z0-9\-_]+');
