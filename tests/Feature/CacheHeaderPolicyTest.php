<?php

declare(strict_types=1);

/**
 * The cache-header half of Phase 12's "image pipeline · cache strategy".
 *
 * docs/IMAGE-PIPELINE-AND-CACHE.md §9 is the policy. This file is the part of
 * it that can be proved from here, and it is deliberately in two halves,
 * because the two halves are enforced in two different places:
 *
 *   1. HTML. The application's, set by App\Http\Middleware\CacheHeaders and
 *      asserted below on responses fetched through the full kernel.
 *
 *   2. Hashed build assets and generated image variants. NOT the application's:
 *      on this host those files are served straight off disk by the web server
 *      and never reach PHP, so a year-long immutable cache is an .htaccess and
 *      nothing else. What CAN be proved here is the PRECONDITION -- that the
 *      URLs really are content-addressed -- because a year-long cache over a
 *      URL whose bytes can change is a year-long outage, and the file that
 *      applies it (docs/cache-headers.htaccess) is a text file that would
 *      otherwise drift away from the constant beside it.
 *
 * ── WHY THESE CASES STILL SWITCH IT ON THEMSELVES ─────────────────────────
 *
 * THIS PARAGRAPH USED TO SAY THE MIDDLEWARE WAS REGISTERED NOWHERE, and it was
 * right: bootstrap/app.php is on BuildPackage::NEVER_SHIP, docs/FQ-CACHE-
 * HEADERS.md wrote the registration out as a hand-edit, and the hand-edit was
 * never made. AppServiceProvider::boot() now appends it to the `web` group from
 * a file that ships, so the group registration below is a no-op that is kept
 * deliberately -- appendMiddlewareToGroup() guards against duplicates, and a
 * case that states its own precondition survives the day somebody moves the
 * registration again.
 *
 * WHAT IS NOT A NO-OP is the switch. Registering a middleware that changes the
 * Cache-Control of every page on a shop taking orders, as a side effect of
 * applying a package, is not something anybody asked for -- so
 * CacheSettings::ENABLED ships FALSE and the middleware returns the response
 * untouched until an owner turns it on from Platform -> Cache. Every case below
 * is about what the policy IS, so every case turns it on first.
 *
 * tests/Feature/CacheControlScreenTest.php holds the other side: that with the
 * switch off these same pages answer exactly what they answered before this
 * middleware was registered at all.
 *
 * ── MUTATIONS THESE CATCH, EACH ONE RUN ──────────────────────────────────
 *
 *   - CacheHeaders::REVALIDATE changed to `public, max-age=60`
 *   - the guard that leaves an existing Cache-Control alone deleted, which
 *     downgrades the admin console from no-store to must-revalidate
 *   - the max-age in docs/cache-headers.htaccess edited away from the constant
 *   - a rewrite added to docs/cache-headers.htaccess
 *   - a Vite manifest entry whose built file carries no content hash
 *
 * ── AND ONE IT DOES NOT, RECORDED RATHER THAN CLAIMED ────────────────────
 *
 * Replacing the Locale::splitPath() call in isPrivatePage() with a raw
 * $request->path() changes nothing any case here can see. SetLocaleFromPath is
 * PREPENDED to the global stack and rewrites the request, so /ar/my-account/
 * already reads as /my-account/ by the time this middleware runs. The Arabic
 * case below is therefore a statement about the OUTCOME -- an Arabic private
 * page is private -- and not a guard on how that outcome is reached. The
 * middleware's own comment says the same thing in the same words, so nobody
 * reading either one is left thinking the split is load-bearing today.
 */

use App\Http\Middleware\CacheHeaders;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Contracts\Http\Kernel;

function chpRegister(): void
{
    app(Kernel::class)->appendMiddlewareToGroup('web', CacheHeaders::class);

    /*
     * And switch it on. All three clears, for the reason CLAUDE.md gives:
     * Setting::map() memoises in a process-level static as well as in the cache
     * store and SettingsService keeps a snapshot of its own, so a row written
     * here is invisible to the request two lines below without them.
     */
    Setting::query()->updateOrCreate(
        ['key' => \App\Support\CacheSettings::ENABLED],
        ['value' => '1', 'autoload' => true]
    );

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function chpArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();
}

function chpCacheControl(string $uri): string
{
    return (string) test()->get($uri)->assertOk()->headers->get('Cache-Control');
}

/**
 * A Cache-Control value as the SET OF DIRECTIVES it is.
 *
 * Symfony rewrites the header on the way out -- `private, no-cache, max-age=0,
 * must-revalidate` leaves as `max-age=0, must-revalidate, no-cache, private` --
 * so a test comparing the literal string would be pinning Symfony's sort order
 * and would go red the day it changed, while saying nothing about the policy.
 *
 * @return list<string>
 */
function chpDirectives(string $header): array
{
    $parts = array_map('trim', explode(',', strtolower($header)));
    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));

    sort($parts);

    return $parts;
}

/* ─────────────────────────── 1. storefront HTML ─────────────────────────── */

it('never lets a storefront page be held by a shared cache', function () {
    chpRegister();

    foreach (['/', '/shop/', '/skincare-guide/', '/reviews/'] as $uri) {
        $header = chpCacheControl($uri);

        expect(chpDirectives($header))
            ->toBe(chpDirectives(CacheHeaders::REVALIDATE), "{$uri} published {$header}");

        /*
         * Stated twice on purpose, and the second is the one that matters.
         * NoStoreAdminApi's docblock records that shared hosting commonly
         * caches GET responses by default; a storefront page carries a cart
         * badge, a signed-in name and a CSRF token, and `public` on this host
         * is one shopper's basket shown to the next.
         *
         * Not ->not->toContain(): toContain() is variadic in Pest, so a
         * message beside the needle becomes a SECOND needle and the assertion
         * passes vacuously whatever the header says.
         */
        expect(str_contains($header, 'public'))
            ->toBeFalse("{$uri} advertised itself as publicly cacheable: {$header}");
    }
});

it('refuses to store a page that prints one customer their own data', function () {
    chpRegister();

    foreach (['/cart', '/checkout', '/my-account', '/my-wishlist'] as $uri) {
        $header = (string) test()->get($uri)->headers->get('Cache-Control');

        expect(str_contains($header, 'no-store'))
            ->toBeTrue("{$uri} let the browser keep a copy on disk: {$header}");
    }
});

it('protects the Arabic address of a private page as well as the English one', function () {
    // A guard that reads the raw path protects English and quietly stops
    // protecting Arabic. splitPath() is what makes the two the same page.
    chpRegister();
    chpArabicOn();

    foreach (['/ar/my-account', '/ar/cart', '/ar/checkout'] as $uri) {
        $header = (string) test()->get($uri)->headers->get('Cache-Control');

        expect(str_contains($header, 'no-store'))
            ->toBeTrue("{$uri} was not treated as the private page it is: {$header}");
    }
});

it('leaves a stricter header that something closer to the route already set', function () {
    chpRegister();

    /*
     * The admin console is ONE HTML document at the configured admin path, and
     * Admin\PageController sets no-store on it by hand -- the comment there
     * records what a cached copy cost: a console showing yesterday's menu after
     * an update, indistinguishable from the update having failed.
     *
     * It is HTML, it answers 200, and its first path segment is the admin path
     * rather than anything on the private list, so without the guard in this
     * middleware it is exactly the response that would be quietly downgraded
     * from no-store to must-revalidate. That is the case this asserts, and it
     * is asserted on the console rather than on an /admin-api/ endpoint because
     * an endpoint returns JSON and is skipped by the content-type test anyway
     * -- so it would pass with the guard deleted, which is a guard proving
     * nothing.
     */
    test()->actingAs(\App\Models\AdminUser::create([
        'name' => 'CHP Owner',
        'email' => 'chp-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');

    $response = test()->get('/' . \App\Services\AdminPathService::current());

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->headers->get('Content-Type'))->toContain('text/html');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('changes nothing about a page it does not own', function () {
    chpRegister();

    // A redirect is cacheable by its own rules and is not a document. /blog
    // 301s to the Journal.
    $response = test()->get('/blog');

    expect($response->getStatusCode())->toBe(301);
    expect((string) $response->headers->get('Cache-Control'))->not->toBe(CacheHeaders::REVALIDATE);
});

/* ────────────────── 2. the preconditions for the year-long cache ────────── */

it('builds every asset under a content-addressed name', function () {
    /*
     * The precondition for `immutable` on /build/. Read off the manifest the
     * page actually loads from, not off a filename convention: if a future
     * vite.config.js turns hashing off, every browser that has seen the site
     * keeps last year's stylesheet for a year and no package can reach it.
     */
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect($manifest)->toBeArray();
    expect($manifest)->not->toBeEmpty();

    foreach ($manifest as $source => $entry) {
        $file = (string) ($entry['file'] ?? '');

        expect($file)->not->toBe('', "{$source} has no built file.");

        // assets/<name>-<hash>.<ext>, where the hash is Vite's base64url digest.
        expect((bool) preg_match('#-[A-Za-z0-9_-]{8}\.[a-z0-9]+$#', $file))
            ->toBeTrue("{$source} builds to {$file}, which carries no content hash — a year-long cache over it would be a year-long outage.");
    }
});

it('names every upload so that a variant URL can never change its bytes', function () {
    /*
     * The precondition for `immutable` on /img-cache/. The variant path mirrors
     * the original's path, so the question is whether an original's path can be
     * reused -- and the answer is the naming rule in MediaUploadController:
     * Ymd-His-<random>. This asserts the rule is still in that file rather than
     * assuming it from the docs, because the docs are what would be left saying
     * it after somebody changed the code.
     */
    $source = (string) file_get_contents(app_path('Http/Controllers/Admin/MediaUploadController.php'));

    // Comments are stripped with a real scanner, not a regex: a regex over a
    // source file reads the file's own prose as if it were code, and this
    // file's docblocks discuss the naming rule at length.
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    expect($code)->toContain('Ymd-His');
});

it('states one max-age in the application and in the file that applies it', function () {
    $htaccess = (string) file_get_contents(base_path('docs/cache-headers.htaccess'));

    expect($htaccess)->toContain(CacheHeaders::IMMUTABLE);

    /*
     * And the file must stay incapable of changing how a URL is routed. This
     * host has no shell: an .htaccess that is wrong cannot be fixed from here,
     * and the one class of mistake that takes the whole site down rather than
     * one directory is a rewrite. There is none, and there is no Options line
     * either, and every directive sits inside an <IfModule> guard so a server
     * without mod_headers skips the lot.
     */
    /*
     * THE COMMENT LINES ARE STRIPPED FIRST, and the reason is the lesson this
     * repository keeps relearning: the file's own header explains that it
     * contains no RewriteRule and no Options, so a search of the raw text finds
     * both words and reports the explanation as the defect. Caught by running
     * this case, which is why it is spelled out rather than tidied away.
     */
    $directives = implode(
        "\n",
        array_filter(
            preg_split('/\R/', $htaccess) ?: [],
            static fn ($line) => ! str_starts_with(ltrim($line), '#')
        )
    );

    foreach (['RewriteRule', 'RewriteEngine', 'Options ', 'ErrorDocument'] as $forbidden) {
        expect(str_contains($directives, $forbidden))
            ->toBeFalse("docs/cache-headers.htaccess contains {$forbidden}, which can change how a URL is served.");
    }

    // Every Header line is inside a guard: count them and count the guards.
    expect(substr_count($directives, 'Header set'))->toBe(1);
    expect(substr_count($directives, '<IfModule mod_headers.c>'))->toBe(1);
});
