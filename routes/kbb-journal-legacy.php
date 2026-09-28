<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The Laravel-era article prefix, redirecting to the canonical form
|------------------------------------------------------------------------------
|
| CLAUDE.md forbids a lane editing routes/web.php, so this file holds the
| registration and web.php requires it — which it already does, in place, above
| the require of routes/kbb-brands-blog.php at the end of the file.
|
|
| WHAT THIS FILE USED TO CARRY AND NO LONGER DOES: `/blog`.
|
| It registered
|
|     Route::get('/blog', fn () => redirect(Url::redirect('/skincare-guide/'), 301));
|
| because /skincare-guide/ was the journal index. The address scheme reverses
| that — /blog/ IS the index now, and /skincare-guide/ 301s onto it — so the
| line had to go, and not merely as tidying:
|
|   IT WOULD HAVE SHADOWED THE INDEX. Laravel strips the trailing slash when it
|   registers a URI, so `/blog` and `/blog/` are one and the same route, and the
|   FIRST registration wins. This file is required at line 203 of web.php and
|   kbb-brands-blog.php at the very end, so the old line would have beaten the
|   real page to the address.
|
|   AND IT WOULD HAVE BEEN A LOOP, not a wasted hop: /blog -> /skincare-guide/
|   -> (PageController::blog) -> /blog. A browser gives up on that; a crawler
|   drops the page.
|
| /blog therefore needs no registration of its own. kbb-brands-blog.php serves
| it, and the slash-less spelling reaches the same route.
|
|
| WHAT IS LEFT, AND WHY IT IS STILL HERE. /post/{slug} is the article prefix
| this app served before 2.60.109. It is pointed at PageController::legacyPost
| rather than at a closure because legacyPost already IS this redirect, for the
| other retired prefix: empty slug -> the journal index, known slug ->
| /blog/{slug}/, unknown slug -> 404 with the redirects table still getting its
| turn on that 404.
|
| ONE HOP, and that is the part the scheme changed. legacyPost used to answer
| /{slug}/ — the site root — which is now itself a 301 onto /blog/{slug}/. Every
| indexed /post/ and /skincare-guide/ URL would have cost two hops. It answers
| the final address directly.
|
| App\Support\Url::redirect() is the house answer to the other two halves of
| this and is what legacyPost uses: Laravel's route() strips the trailing slash
| off a registered URI and knows nothing about the /ar language segment, so
| measured against a running server (docs/GA-SKINCARE-GUIDE.md §3) the old
| closures sent /ar/post/{slug} to /{slug} — the Arabic reader losing both the
| slash and Arabic. Url::redirect() returns an absolute URL carrying the base
| path exactly once and the current language segment.
|
| One behaviour change worth stating plainly, unchanged from the earlier pass:
| /post/unknown-slug was a 301 to /unknown-slug and then a 404; it is a 404 at
| /post/unknown-slug. Both end at 404 and neither matches a redirect row, so this
| costs nothing and stops the site advertising a 301 to a page that does not
| exist.
|
| Ships with database/migrations/2027_04_05_000000_clear_caches_url_scheme.php,
| because the host serves a compiled route table.
|
*/

use App\Http\Controllers\Store\PageController;
use Illuminate\Support\Facades\Route;

// /post/{slug} — the article prefix this app served before 2.60.109, and
// /post/ with no slug, which went to the index. legacyPost answers both, and
// answers them the way /skincare-guide/{slug}/ is already answered.
Route::get('/post/{slug?}', [PageController::class, 'legacyPost'])
    ->where('slug', '[A-Za-z0-9\-_]+');
