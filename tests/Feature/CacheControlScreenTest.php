<?php

declare(strict_types=1);

/**
 * Platform → Cache.
 *
 * tests/Feature/CacheHeaderPolicyTest.php already pins the POLICY — what a page
 * leaves with once the middleware is acting. This file pins the three things
 * that lane could not, because the middleware was registered nowhere:
 *
 *   1. that it is registered NOW, from a file a package can ship;
 *   2. that registering it changed NOTHING until somebody asks it to;
 *   3. that the one rule on it with a customer's basket behind it cannot be
 *      switched off by any value of any setting this screen stores.
 *
 * ── MUTATIONS THESE CATCH, EACH ONE APPLIED AND RUN ──────────────────────
 *
 * Run against this file and tests/Feature/CacheHeaderPolicyTest.php together,
 * 29 green at rest. The count is how many went red.
 *
 *   2  the `if (! CacheSettings::enabled(...)) return $response;` guard deleted
 *      from CacheHeaders::handle() -- registering it would then change a live
 *      shop's headers on package application
 *   5  the appendMiddlewareToGroup() block deleted from AppServiceProvider
 *   1  CacheSettings::HTML_MAX_AGE default changed from 0 to 60
 *   1  storefrontHeader() changed to emit `public, max-age=N`
 *   3  the isPrivatePage() branch deleted, so a customer's own page takes the
 *      storefront header instead of no-store
 *   1  CacheSettings::ASSET_EXTENSIONS edited away from the .htaccess's own list
 *   1  the cache.manage rules deleted from AdminCapabilities (the map's closed
 *      default keeps the 403s, so only the mapping case goes red -- which is
 *      the point of mapping rather than relying on the default)
 *   4  those rules changed to 'dashboard.view'
 *   1  the live reading replaced with CacheSettings::storefrontHeader()
 *   1  `'stores' => config('cache.stores')` added to the show() payload
 *   1  the memo clear removed from the `application` branch of clear()
 *
 * ── AND THREE PREDICTIONS THAT CAME BACK GREEN, RECORDED RATHER THAN HIDDEN ─
 *
 *   - A MEMO CLEAR IN save() WAS PREDICTED RED AND WAS NOT, AND THE CODE
 *     CHANGED RATHER THAN THE COMMENT. save() used to call
 *     forgetSettingMemos() before re-probing, on the reasoning in CLAUDE.md
 *     that Setting::map() and SettingsService both memoise in process-level
 *     statics. Deleting it left all 29 green: SettingsService::set() already
 *     forgets the cached map, drops the key from its own memo and calls
 *     Setting::flushMap(). So the call was removed, and the method now says
 *     that in those words. The clear in clear() is a different case and IS red
 *     -- see the last mutation above.
 *   - Deleting the `->middleware('throttle:...')` from routes/cache-admin.php
 *     changes nothing any case here can see. Nothing in this file makes thirty
 *     requests to one endpoint, and a case that did would be pinning Laravel's
 *     rate limiter rather than this route file. The throttles are argued for in
 *     that file's header instead.
 *   - Deleting the `finally` that restores the container's `request` after the
 *     storefront probe is invisible here too: every case fetches ONE thing and
 *     then asserts on the response body, so nothing afterwards reads request()
 *     in the same process. It is kept because HealthApiController was measured
 *     doing exactly this damage -- after a health run, request() inside the
 *     admin request answered /reviews/ -- and because the next case added to
 *     this file will not know it was ever a question.
 */

use App\Http\Middleware\CacheHeaders;
use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\CacheSettings;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Support\CacheAdminRoutes;

/* ------------------------------------------------------------------ helpers */

/**
 * Write a cache setting and make every reader in this process see it.
 *
 * THREE CLEARS AND NOT ONE, and CLAUDE.md says why: Setting::map() memoises in
 * a process-level static as well as in the cache store, SettingsService keeps a
 * snapshot memo of its own, and the cached autoload map is a third copy. A test
 * that writes a row and re-renders without all three reads back the value from
 * before the write -- which is how ReviewBadgeParityTest found the map memo in
 * the first place.
 */
function cchSet(string $key, string $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();
}

function cchOn(int $htmlMaxAge = 0): void
{
    cchSet(CacheSettings::ENABLED, '1');
    cchSet(CacheSettings::HTML_MAX_AGE, (string) $htmlMaxAge);
}

/** A Cache-Control as the SET of directives it is — Symfony reorders on the way out. */
function cchDirectives(string $header): array
{
    $parts = array_values(array_filter(
        array_map('trim', explode(',', strtolower($header))),
        static fn ($p) => $p !== ''
    ));

    sort($parts);

    return $parts;
}

function cchHeader(string $uri): string
{
    return (string) test()->get($uri)->headers->get('Cache-Control');
}

function cchOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'CCH Owner',
        'email' => 'cch-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function cchAsOwner(): void
{
    CacheAdminRoutes::wire(app());
    test()->actingAs(cchOwner(), 'admin');
}

/* ══════════════ 1. it is registered, and it is asleep ══════════════════════ */

it('puts the cache middleware in the web group from a file a package can ship', function () {
    /*
     * The defect this lane exists for. CacheHeaders was complete and tested and
     * called by NOTHING: its registration was written out in
     * docs/FQ-CACHE-HEADERS.md as a hand-edit to bootstrap/app.php, which is on
     * BuildPackage::NEVER_SHIP and UpdateGuard::FORBIDDEN_PREFIXES, so no
     * package could carry it and nobody made it by hand. Same file, same
     * reason, same outcome as the SetLocaleFromPath line that had the owner
     * reporting "/ar gives everywhere 404".
     *
     * Asserted on the KERNEL's live group rather than on the provider's source,
     * because the question is whether the middleware runs and not whether a
     * line of PHP exists somewhere. And bootstrap/app.php is checked for its
     * ABSENCE, so this case stays honest on a server where somebody did apply
     * the hand-edit as well: the group must hold it exactly once either way.
     */
    $group = app(Kernel::class)->getMiddlewareGroups()['web'] ?? [];

    expect(array_count_values(array_map('strval', $group))[CacheHeaders::class] ?? 0)
        ->toBe(1, 'CacheHeaders is in the web group ' . count(array_keys($group, CacheHeaders::class, true))
            . ' times; two registrations is the "two middlewares setting the same header" its own docblock warns about');

    /*
     * Not ->not->toContain(): toContain() is variadic in Pest, so a message
     * beside the needle becomes a SECOND needle and the expectation passes
     * whatever the file says. CacheHeaderPolicyTest carries the same note and
     * tests/Feature/ExpectationsThatCannotFailTest.php walks the whole suite
     * for it -- which is how this line was caught, written that way, here.
     */
    expect(str_contains((string) file_get_contents(base_path('bootstrap/app.php')), 'CacheHeaders'))
        ->toBeFalse('this repository\'s bootstrap/app.php names CacheHeaders, so the group registration above no longer proves the provider did it — and bootstrap/ cannot reach a server in a package at all');
});

it('changes not one response header until the owner asks it to', function () {
    /*
     * THE WHOLE POINT OF THE DEFAULT. Applying this package registers a
     * middleware on a LIVE shop taking real orders. If that alone moved a
     * header, the owner would be finding out afterwards.
     *
     * The expected value is Symfony's own: ResponseHeaderBag computes
     * `no-cache, private` for any response carrying no Cache-Control of its
     * own, and that is what every one of these pages left with before this
     * middleware existed. Compared as a SET, not a string.
     */
    expect((string) config('app.key'))->not->toBe('');

    foreach (['/', '/shop/', '/cart', '/checkout', '/my-account'] as $uri) {
        expect(cchDirectives(cchHeader($uri)))
            ->toBe(['no-cache', 'private'], "{$uri} answered differently with the feature switched off");
    }
});

it('switches on into exactly the policy the pipeline document states', function () {
    /*
     * Defaults untouched but for the switch itself. Turning caching on must be
     * ONE change and not two: the header a page leaves with has to be the
     * policy of docs/IMAGE-PIPELINE-AND-CACHE.md §9.1, which is
     * CacheHeaders::REVALIDATE, and not whatever a helpful default max-age
     * would have made of it.
     */
    cchSet(CacheSettings::ENABLED, '1');

    expect(cchDirectives(cchHeader('/')))->toBe(cchDirectives(CacheHeaders::REVALIDATE));
    expect(cchDirectives(cchHeader('/shop/')))->toBe(cchDirectives(CacheHeaders::REVALIDATE));
});

/* ══════════════ 2. the rule with a basket behind it ═══════════════════════ */

it('refuses to keep a customer\'s own page at every setting the screen can store', function () {
    /*
     * The non-negotiable one. A shop that lets a cache keep a signed-in
     * shopper's cart page hands one customer's basket to the next, so this is
     * asserted across the WHOLE legal range of both numbers rather than at the
     * defaults -- the question is not "is it no-store today" but "can anything
     * this screen stores make it not be".
     */
    foreach ([0, 1, CacheSettings::HTML_MAX_AGE_CEILING] as $htmlMaxAge) {
        foreach ([0, CacheSettings::ASSET_MAX_AGE_CEILING] as $assetMaxAge) {
            cchOn($htmlMaxAge);
            cchSet(CacheSettings::ASSET_MAX_AGE, (string) $assetMaxAge);

            foreach (['/cart', '/checkout', '/my-account', '/my-wishlist', '/track-my-order'] as $uri) {
                expect(str_contains(cchHeader($uri), 'no-store'))
                    ->toBeTrue("{$uri} let a cache keep it at html={$htmlMaxAge} asset={$assetMaxAge}");
            }
        }
    }
});

it('offers no key at all that could turn that off', function () {
    /*
     * The other half, and it is a statement about the SHAPE of the settings
     * rather than about a value. A test that only drove the two numbers would
     * go on passing the day somebody added a third key called
     * `cache.private_pages_enabled`, so this one asserts the schema itself:
     * these three keys and no others, and none of them named for the private
     * pages.
     */
    expect(array_keys(CacheSettings::SCHEMA))->toBe([
        CacheSettings::ENABLED,
        CacheSettings::HTML_MAX_AGE,
        CacheSettings::ASSET_MAX_AGE,
    ]);

    foreach (array_keys(CacheSettings::SCHEMA) as $key) {
        foreach (['private', 'store', 'account', 'cart', 'checkout'] as $word) {
            expect(str_contains($key, $word))
                ->toBeFalse("a setting named {$key} reads like a control over the pages that have none");
        }
    }
});

it('covers every storefront page that prints one customer their own data', function () {
    /*
     * PRIVATE_PREFIXES derived against the router rather than read back at
     * itself. The list is six first segments and the question is whether the
     * storefront has grown a seventh: a new account page added under its own
     * top-level address is protected by nothing, and nothing would say so.
     *
     * The five controllers below are the ones that render a signed-in
     * customer's own information -- their account, their addresses, their
     * wishlist, their basket and their placed order. /checkout/success is the
     * order confirmation and is covered by the `checkout` prefix, which is why
     * it is not a seventh entry.
     *
     * GET only: the middleware returns early on anything else, and a POST is
     * not a document a browser caches.
     */
    $private = ['AccountController', 'AddressController', 'CartController', 'CheckoutController', 'WishlistController'];
    $uncovered = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        /*
         * ▲ /api/cart/drawer AND /api/cart/debug ARE SKIPPED, AND THE REASON IS
         * WRITTEN HERE RATHER THAN LEFT AS A NARROWER LOOP.
         *
         * Both are CartController, both sit in the `web` group, and both carry
         * one shopper's basket -- the drawer returns it as rendered HTML inside
         * a JSON envelope. Neither is on PRIVATE_PREFIXES, whose entries are
         * FIRST path segments, and putting 'api' on that list would mark the
         * whole public JSON API no-store to protect two endpoints.
         *
         * Left alone deliberately, and it is not a hole that this feature
         * opens: CacheHeaders skips any response that is not text/html, so
         * these keep Symfony's computed `no-cache, private` exactly as they do
         * today, switch on or off. `private` is the directive that matters --
         * no shared cache may store either one, so there is no path from here
         * to one customer's basket reaching another. What `no-store` would add
         * is protection against a browser's own disk cache after a sign-out on
         * a shared machine, and a JSON response is not something a back button
         * redraws. Recorded so the next reader of this list knows it was looked
         * at rather than missed.
         */
        if (str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        $action = (string) $route->getAction('controller');

        if (! str_contains($action, '\\Store\\')) {
            continue;
        }

        $matched = false;
        foreach ($private as $controller) {
            if (str_contains($action, $controller)) {
                $matched = true;
            }
        }

        if (! $matched) {
            continue;
        }

        $first = strtok(trim($route->uri(), '/'), '/');

        if ($first === false || ! in_array($first, CacheHeaders::PRIVATE_PREFIXES, true)) {
            $uncovered[] = '/' . $route->uri() . '  (' . class_basename($action) . ')';
        }
    }

    expect($uncovered)->toBe(
        [],
        "these pages print a customer their own data and their first path segment is not on CacheHeaders::PRIVATE_PREFIXES:\n  "
        . implode("\n  ", $uncovered)
    );
});

it('never lets a shop page call itself publicly cacheable, at any max-age', function () {
    // `public` on this host is one shopper's basket shown to the next --
    // NoStoreAdminApi's docblock records that shared hosting commonly caches
    // GET responses by default. Every legal value, not a sample of nice ones.
    foreach ([0, 1, 59, 60, 900, CacheSettings::HTML_MAX_AGE_CEILING, CacheSettings::HTML_MAX_AGE_CEILING * 10, -5] as $seconds) {
        $header = CacheSettings::storefrontHeader($seconds);

        expect(str_contains($header, 'public'))->toBeFalse("max-age={$seconds} produced {$header}");
        expect(str_contains($header, 'private'))->toBeTrue("max-age={$seconds} produced {$header}");
    }
});

/* ══════════════ 3. the screen tells the truth ═════════════════════════════ */

it('reads the header off a real response rather than describing one', function () {
    /*
     * The owner's actual complaint was that he could not tell what his shop was
     * doing. A screen that printed the policy its own settings imply would have
     * answered him confidently and been wrong in precisely the case that
     * matters -- a middleware that is not running.
     *
     * So: with the feature OFF, the screen must report Symfony's default and
     * not the shop's policy. Those two differ, which is what makes this case
     * able to fail.
     */
    cchAsOwner();

    $body = test()->getJson('/admin-api/cache')->assertOk()->json();

    expect(cchDirectives((string) $body['live']['cache_control']))->toBe(['no-cache', 'private']);
    expect($body['live']['matches_policy'])->toBeFalse();
    expect($body['middleware']['enabled'])->toBeFalse();
    expect($body['middleware']['registered'])->toBeTrue();
    expect($body['middleware']['active'])->toBeFalse();

    // And the description it offers separately is the OTHER string, so the two
    // cannot be the same field wearing two names.
    expect(cchDirectives((string) $body['storefront_policy']))->toBe(cchDirectives(CacheHeaders::REVALIDATE));
});

it('reads the new header back after the switch it just saved', function () {
    /*
     * The trap CLAUDE.md names by hand: Setting::map() and SettingsService each
     * memoise in a process-level static, so a save followed by a read IN THE
     * SAME PROCESS answers with the value from before the save. The screen
     * re-probes the shop inside the save request, so without the memo clears in
     * CacheApiController::save() the owner would switch caching on and be shown
     * the old header as proof it had not worked.
     */
    cchAsOwner();

    $body = test()->postJson('/admin-api/cache', [
        'headers_enabled' => true,
    ])->assertOk()->json();

    expect($body['settings']['headers_enabled'])->toBeTrue();
    expect($body['middleware']['active'])->toBeTrue();
    expect($body['private_pages']['enforced'])->toBeTrue();
    expect(cchDirectives((string) $body['live']['cache_control']))->toBe(cchDirectives(CacheHeaders::REVALIDATE));
    expect($body['live']['matches_policy'])->toBeTrue();

    // And the storefront itself agrees, fetched outside the endpoint.
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    expect(cchDirectives(cchHeader('/')))->toBe(cchDirectives(CacheHeaders::REVALIDATE));
});

it('clamps a max-age the screen was never meant to be able to send', function () {
    cchAsOwner();

    test()->postJson('/admin-api/cache', ['html_max_age' => 99999])->assertStatus(422);

    $body = test()->postJson('/admin-api/cache', [
        'html_max_age' => CacheSettings::HTML_MAX_AGE_CEILING,
    ])->assertOk()->json();

    expect($body['settings']['html_max_age'])->toBe(CacheSettings::HTML_MAX_AGE_CEILING);
});

it('puts nothing on the screen that belongs in the env file', function () {
    /*
     * /api/* in this app is unauthenticated and this endpoint is deliberately
     * not there -- but an allowlist that is not pinned is a comment. The whole
     * payload is compared against the keys it is meant to have, so a later
     * `'config' => config('cache')` is a failure and not a convenience.
     */
    cchAsOwner();

    $response = test()->getJson('/admin-api/cache')->assertOk();
    $body = $response->json();

    expect(array_keys($body))->toBe([
        'ok', 'settings', 'limits', 'middleware', 'live', 'private_pages',
        'storefront_policy', 'compiled', 'store', 'assets',
    ]);

    expect(array_keys($body['store']))->toBe(['store', 'driver']);

    /*
     * AND NOW THE CREDENTIALS, IN TWO PASSES RATHER THAN ONE SUBSTRING SCAN.
     *
     * The key list above is what actually catches the mutation this case was
     * written for -- a later `'config' => config('cache')` or
     * `'stores' => config('cache.stores')` changes array_keys($body) and fails
     * before it gets here. This is the backstop for a credential arriving
     * inside a VALUE, where the key list would not see it.
     *
     * A BARE str_contains() OF EVERY CREDENTIAL IS NOT THAT BACKSTOP, and the
     * MySQL config proved it: phpunit-mysql.xml runs as DB_USERNAME=kbb,
     * DB_PASSWORD=kbb, and the payload legitimately carries
     * `"sample_url":"/build/assets/kbb-account-Ck4TS5ez.css"` -- the shop's own
     * name in its own asset filename. The case failed against a real server
     * with "the Cache screen echoed a credential back to the browser" and
     * nothing had leaked. Worse, on the SQLite config both DB values are the
     * empty string, so the loop `continue`d past them and the scan this case
     * is named for never ran at all.
     *
     * So: (a) no LEAF VALUE of the payload IS a credential, which is the shape
     * an actual leak takes and which runs for every credential on every engine;
     * and (b) no credential long enough for a coincidence to be implausible
     * appears anywhere in the raw body. Eight characters is the line -- APP_KEY
     * and any real password clear it, a three-letter harness login does not,
     * and a three-letter login is not evidence either way.
     */
    $raw = (string) $response->getContent();

    $secrets = array_values(array_filter([
        (string) config('app.key'),
        (string) config('database.connections.' . config('database.default') . '.password'),
        (string) config('database.connections.' . config('database.default') . '.username'),
    ], static fn (string $secret): bool => $secret !== ''));

    $leaves = [];
    array_walk_recursive($body, static function ($value) use (&$leaves): void {
        if (is_scalar($value)) {
            $leaves[] = (string) $value;
        }
    });

    foreach ($secrets as $secret) {
        expect(in_array($secret, $leaves, true))->toBeFalse('the Cache screen printed a credential as a value');

        if (strlen($secret) < 8) {
            continue;
        }

        expect(str_contains($raw, $secret))->toBeFalse('the Cache screen echoed a credential back to the browser');
    }
});

/* ══════════════ 4. the buttons, and who may press them ════════════════════ */

it('refuses every cache endpoint to a back-office account that is not the owner', function (string $role) {
    /*
     * AdminCapabilities fails CLOSED, so an unmapped route is owner-only
     * anyway. These are mapped to `cache.manage` all the same, because
     * "owner-only because nothing maps it" and "owner-only because somebody
     * decided so" are the same 403 and a very different piece of evidence --
     * and because AdminCapabilityMapTest requires every admin route the router
     * carries to be named in that file.
     *
     * The button behind these clears the compiled routes of a shop that is
     * serving customers.
     */
    CacheAdminRoutes::wire(app());

    test()->actingAs(AdminUser::create([
        'name' => 'CCH ' . $role,
        'email' => 'cch-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]), 'admin');

    test()->getJson('/admin-api/cache')->assertStatus(403);
    test()->postJson('/admin-api/cache', [])->assertStatus(403);
    test()->postJson('/admin-api/cache/clear', ['target' => 'application'])->assertStatus(403);
    test()->postJson('/admin-api/cache/probe', [])->assertStatus(403);
})->with(['manager', 'support', 'editor']);

it('refuses an anonymous caller at every cache endpoint', function () {
    CacheAdminRoutes::wire(app());

    expect(test()->getJson('/admin-api/cache')->getStatusCode())->toBe(401);
    expect(test()->postJson('/admin-api/cache/clear', ['target' => 'all'])->getStatusCode())->toBe(401);
});

it('names a capability that the role table actually grants', function () {
    // AdminCapabilityMapTest makes this statement over the whole map, but only
    // for routes the ROUTER carries -- and routes/cache-admin.php is not in
    // routes/web.php until the integrator adds the require. Mounted here, so
    // the mapping is checked on this branch rather than after a merge.
    CacheAdminRoutes::wire(app());

    $routes = CacheAdminRoutes::registered();

    expect($routes)->toHaveCount(4);

    foreach ($routes as $route) {
        expect(\App\Support\AdminCapabilities::for($route))
            ->toBe('cache.manage', $route->uri() . ' is mapped elsewhere');
    }

    expect(\App\Support\AdminCapabilities::CAPABILITIES['cache.manage'])->toBe(['owner']);
});

it('drops the compiled route table when asked, because there is no shell to do it', function () {
    /*
     * The buttons are the whole feature on a host with no command line: a
     * package can land complete and every new endpoint go on answering 404
     * until bootstrap/cache/routes-*.php is gone. That is the failure every
     * clear_caches_* migration in database/migrations exists for, and this is
     * the same operation on demand.
     *
     * A DECOY, AND THE REAL FILES PUT BACK. This suite's own migration set runs
     * config:cache and route:cache (warm_caches_2_60_4), so the compiled files
     * are shared by every case that boots afterwards. Deleting them for real
     * mid-run would be this file reaching into everyone else's -- the exact
     * class of cross-lane damage CLAUDE.md records under `pkill`. So: snapshot,
     * assert, restore.
     */
    cchAsOwner();

    $patterns = [
        base_path('bootstrap/cache/config.php'),
        base_path('bootstrap/cache/routes-*.php'),
        base_path('bootstrap/cache/services.php'),
        base_path('bootstrap/cache/packages.php'),
    ];

    $saved = [];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            $saved[$file] = (string) file_get_contents($file);
        }
    }

    $decoy = base_path('bootstrap/cache/routes-cch-decoy.php');
    @mkdir(dirname($decoy), 0775, true);
    file_put_contents($decoy, "<?php return [];\n");

    try {
        $body = test()->postJson('/admin-api/cache/clear', ['target' => 'compiled'])->assertOk()->json();

        expect(is_file($decoy))->toBeFalse('a compiled route file survived the button that exists to remove it');
        expect($body['ran']['files_removed'])->toBeGreaterThanOrEqual(1);
        expect($body['compiled']['routes'])->toBeFalse();
    } finally {
        @unlink($decoy);

        foreach ($saved as $file => $contents) {
            file_put_contents($file, $contents);
        }
    }
});

it('clears the shop\'s own working cache without touching the compiled half', function () {
    cchAsOwner();

    \Illuminate\Support\Facades\Cache::put('cch.probe', 'still here', 600);

    $body = test()->postJson('/admin-api/cache/clear', ['target' => 'application'])->assertOk()->json();

    expect(\Illuminate\Support\Facades\Cache::get('cch.probe'))->toBeNull();

    /*
     * AND THE PROCESS-LEVEL MEMOS WITH IT, which is the half a Cache::flush()
     * does not reach and the half the owner presses this button for.
     *
     * The row below is written STRAIGHT TO THE TABLE, not through
     * SettingsService::set() -- that method drops the memos itself, which is
     * exactly the case this is not about. This is the other one: a value that
     * changed underneath a long-lived process (a queue worker, an import run,
     * anything that read a setting once and kept going), where the store has
     * been cleared and two static arrays are still answering with what they
     * read at the start.
     */
    Setting::query()->updateOrCreate(['key' => 'cch.memo.probe'], ['value' => 'before', 'autoload' => false]);

    // The memo already holds a remembered MISS for this key -- the endpoint
    // above read the settings table and snapshot() keeps misses on purpose, so
    // that a re-read does not go back to the database for something that was
    // not there. Cleared once here so the reading below is the row and not that
    // miss; everything after this line is the real subject.
    SettingsService::forgetMemo();

    expect(app(SettingsService::class)->get('cch.memo.probe'))->toBe('before');

    Setting::query()->where('key', 'cch.memo.probe')->update(['value' => 'after']);
    expect(app(SettingsService::class)->get('cch.memo.probe'))->toBe('before', 'the memo is meant to be stale at this point, or this case proves nothing');

    test()->postJson('/admin-api/cache/clear', ['target' => 'application'])->assertOk();

    expect(app(SettingsService::class)->get('cch.memo.probe'))->toBe('after');

    // The three compiled commands must not have been reached: `application` is
    // the button the owner presses when a saved setting is not showing, and it
    // has no business recompiling the world.
    expect(array_key_exists('config:clear', $body['ran']))->toBeFalse();
    expect(array_key_exists('files_removed', $body['ran']))->toBeFalse();
    expect($body['ran']['cache:clear'])->toBeTrue();
});

it('refuses a clear target it was not offered', function () {
    cchAsOwner();

    test()->postJson('/admin-api/cache/clear', ['target' => 'everything'])->assertStatus(422);
    test()->postJson('/admin-api/cache/clear', [])->assertStatus(422);
});

/* ══════════════ 5. the half no PHP on this host can enforce ═══════════════ */

it('prints the same directives the repository keeps, so the two cannot drift', function () {
    /*
     * docs/cache-headers.htaccess is the canonical file and docs/ is on
     * BuildPackage::NEVER_SHIP -- so it does NOT EXIST on a customer's server,
     * which is the one machine where the owner needs to read it. The screen
     * therefore builds the text in PHP, and this holds the built text against
     * the file byte for byte, comment lines stripped.
     *
     * Stripped, and the reason is one CacheHeaderPolicyTest already paid for:
     * the file's own header explains at length what it does NOT contain, so a
     * comparison of the raw text compares an explanation with a directive.
     */
    $file = (string) file_get_contents(base_path('docs/cache-headers.htaccess'));

    $directives = implode("\n", array_values(array_filter(
        preg_split('/\R/', $file) ?: [],
        static fn ($line) => trim($line) !== '' && ! str_starts_with(ltrim($line), '#')
    )));

    expect(CacheSettings::htaccess(CacheSettings::ASSET_MAX_AGE_CEILING))->toBe($directives);

    // And at the default, the number in it is the one constant the application
    // and that file have always shared.
    expect(CacheSettings::assetHeader(CacheSettings::ASSET_MAX_AGE_CEILING))->toBe(CacheHeaders::IMMUTABLE);
});

it('never writes to an htaccess itself, whatever the screen offers', function () {
    /*
     * The file goes in the WEB ROOT, which on this host is a different
     * directory from the application root -- and the .htaccess already there is
     * the one that routes every URL on the site in front of index.php.
     * Replacing it is the withdrawn 2.60.102-.106 failure with a different
     * file, on a host with no shell to undo it with. The screen prints text for
     * a person to carry; nothing in this lane opens that file for writing.
     */
    foreach ([
        app_path('Support/CacheSettings.php'),
        app_path('Http/Controllers/Admin/CacheApiController.php'),
        app_path('Http/Middleware/CacheHeaders.php'),
    ] as $source) {
        $code = '';

        // A real scanner rather than a regex: these files discuss the .htaccess
        // at length in prose, and a substring search reads the discussion as
        // the deed.
        foreach (token_get_all((string) file_get_contents($source)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach (['file_put_contents', 'fopen', 'fwrite', 'copy(', 'rename('] as $writer) {
            expect(str_contains($code, $writer))
                ->toBeFalse(basename($source) . " calls {$writer} — nothing in this lane may write a file into the web root");
        }
    }
});
