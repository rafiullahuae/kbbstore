<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\ArabicPreview;
use App\Support\Locale;
use Tests\Support\ArPreviewAdminRoutes;

/*
 * The private Arabic preview link (Lane AR, stage 2). The owner: Arabic ships
 * OFF, and he wants to see the whole Arabic shop on the real site first, with
 * nothing reaching a search engine or a visitor. Every case below is a way
 * that promise could be broken; each says what it would look like on the shop
 * and ends with the mutation that turns it red.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function arpFresh(): void
{
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function arpToken(): string
{
    $url = ArabicPreview::issue(7, 'https://shop.test')['url'];
    arpFresh();

    return substr($url, strpos($url, '=') + 1);
}

/*
 * 1. WITH ARABIC OFF AND NO LINK, /ar DOES NOT EXIST -- for a visitor, for a
 *    forged, truncated or expired token, and for a shop that never made a link
 *    (no secret: nothing verifies). A preview that answered any of these would
 *    put an unfinished Arabic shop in front of whoever guessed.
 *    Mutation: drop the secret check in verify(), or the hash_equals, and red.
 */
it('keeps /ar a 404 for anyone without a valid link, and fails closed with no secret', function () {
    expect(Locale::enabled('ar'))->toBeFalse();
    $this->get('/ar/')->assertNotFound();

    $exp = time() + 3600;
    $forged = $exp.'.'.str_repeat('a', 64);
    expect(ArabicPreview::verify($forged))->toBeNull();          // no secret yet
    $this->withUnencryptedCookie(ArabicPreview::COOKIE, $forged)->get('/ar/')->assertNotFound();
    $this->get('/ar/?'.ArabicPreview::PARAM.'='.$forged)->assertNotFound();

    $good = arpToken();
    [$e, $sig] = explode('.', $good);
    expect(ArabicPreview::verify($good))->toBe((int) $e)
        ->and(ArabicPreview::verify($e.'.'.strrev($sig)))->toBeNull()
        ->and(ArabicPreview::verify(($e + 60).'.'.$sig))->toBeNull()          // expiry is signed
        ->and(ArabicPreview::verify(substr($good, 0, -1)))->toBeNull()
        ->and(ArabicPreview::verify((time() - 10).'.'.$sig))->toBeNull()
        ->and(ArabicPreview::verify((time() + 400 * 86400).'.'.$sig))->toBeNull();
});

/*
 * 2. THE LINK OPENS THE ARABIC SHOP, RIGHT TO LEFT, FOR THAT BROWSER ONLY --
 *    and moves its token into an HttpOnly cookie, redirecting to a clean address
 *    so the token is not left in the address bar, the history or a Referer.
 *    Every page it serves is noindex (header and meta) and private/no-store, so
 *    neither Google nor the host's cache keeps a copy.
 *    Mutation: drop decorate() in SetLocaleFromPath, or the redirect, and red.
 */
it('opens the Arabic shop through the link, noindexed and uncached, then from its cookie', function () {
    $token = arpToken();

    $first = $this->get('/ar/?'.ArabicPreview::PARAM.'='.$token);
    $first->assertRedirect();
    expect($first->headers->get('Location'))->not->toContain($token)
        ->and($first->headers->get('Location'))->toMatch('#/ar/?$#');
    $cookie = collect($first->headers->getCookies())->first(fn ($c) => $c->getName() === ArabicPreview::COOKIE);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getValue())->toBe($token);

    $page = $this->withUnencryptedCookie(ArabicPreview::COOKIE, $token)->get('/ar/');
    $page->assertOk();
    $html = $page->getContent();
    expect($html)->toMatch('/<html[^>]*lang="ar"/')
        ->and($html)->toMatch('/<html[^>]*dir="rtl"/')
        ->and($html)->toContain('<meta name="robots" content="noindex, nofollow">')
        ->and($page->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($page->headers->get('Cache-Control'))->toContain('no-store')
        ->and($page->headers->get('Cache-Control'))->toContain('private');
});

/*
 * 3. NOTHING LEAKS TO A VISITOR OR A CRAWLER. Without the cookie the English
 *    page carries no hreflang to /ar and no noindex; and even WITH the cookie
 *    the sitemap (a machine document) stays English-only, so a previewer can
 *    never seed /ar into the sitemap or its cache.
 *    Mutation: let begin() run on paths with a dot, and the sitemap is red.
 */
it('leaves visitors, crawlers and the sitemap exactly as they were', function () {
    $token = arpToken();

    $en = $this->get('/');
    $en->assertOk();
    expect($en->getContent())->not->toContain('hreflang="ar"')
        ->and($en->getContent())->not->toContain('/ar/')
        ->and((string) $en->headers->get('X-Robots-Tag'))->not->toContain('noindex');

    $map = $this->withUnencryptedCookie(ArabicPreview::COOKIE, $token)->get('/sitemap.xml');
    expect($map->getContent())->not->toContain('/ar/')
        ->and(ArabicPreview::active())->toBeFalse();
});

/*
 * 4. "TURN OFF EVERY LINK" MEANS EVERY LINK, INCLUDING THE COOKIES ALREADY OUT.
 *    Mutation: make revoke() a no-op and the old cookie still opens /ar.
 */
it('kills every link and cookie already handed out when revoked', function () {
    $token = arpToken();
    $this->withUnencryptedCookie(ArabicPreview::COOKIE, $token)->get('/ar/')->assertOk();

    ArabicPreview::revoke();
    arpFresh();

    expect(ArabicPreview::verify($token))->toBeNull();
    $this->withUnencryptedCookie(ArabicPreview::COOKIE, $token)->get('/ar/')->assertNotFound();
});

/*
 * 5. THE ADMIN ENDPOINTS HAVE THEIR OWN CAPABILITY AND FAIL CLOSED: the owner
 *    makes a link; a manager is refused with 403; a guest never reaches it;
 *    a silly duration is refused. Mutation: map the routes to content.manage
 *    (or drop them from the map) and the manager case is red.
 */
it('lets only the owner make or kill a link', function () {
    ArPreviewAdminRoutes::wire(app());

    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/translations/preview-link'))->toBe('translations.preview')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/translations/preview-link/revoke'))->toBe('translations.preview')
        ->and(\App\Support\AdminCapabilities::CAPABILITIES['translations.preview'])->toBe(['owner']);

    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'owner@arp.test', 'password' => 'secret-secret', 'role' => 'owner']);
    $manager = AdminUser::create(['name' => 'Manager', 'email' => 'manager@arp.test', 'password' => 'secret-secret', 'role' => 'manager']);

    $this->postJson('/admin-api/translations/preview-link', ['days' => 7])->assertStatus(401);
    $this->actingAs($manager, 'admin')->postJson('/admin-api/translations/preview-link', ['days' => 7])->assertForbidden();
    $this->actingAs($manager, 'admin')->postJson('/admin-api/translations/preview-link/revoke')->assertForbidden();

    $made = $this->actingAs($owner, 'admin')->postJson('/admin-api/translations/preview-link', ['days' => 3]);
    $made->assertOk();
    expect($made->json('url'))->toContain('/ar/?'.ArabicPreview::PARAM.'=')
        ->and($made->json('days'))->toBe(3);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/translations/preview-link', ['days' => 99])->assertStatus(422);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/translations/preview-link/revoke')->assertOk();

    // The route file is required exactly once by the integrator's wiring -- pinned
    // as the FINISHED state (CLAUDE.md), which is green before and after.
    $web = (string) file_get_contents(base_path('routes/web.php'));
    expect(substr_count($web, "require __DIR__.'/ar-preview-admin.php';"))->toBeLessThanOrEqual(1);
});

/*
 * 6. A VISITOR PAYS NOTHING. No cookie and no parameter: begin() returns before
 *    reading a setting, so the speed lock's settings-map budget is untouched.
 *    Mutation: read the secret before the early return and this is red.
 */
it('costs a visitor without a link no settings read', function () {
    $reads = 0;
    \Illuminate\Support\Facades\Event::listen([\Illuminate\Cache\Events\CacheHit::class, \Illuminate\Cache\Events\CacheMissed::class], function ($e) use (&$reads) {
        $reads++;
    });
    SettingsService::forgetMemo();

    expect(ArabicPreview::begin(\Illuminate\Http\Request::create('/ar/'), '/ar/'))->toBeNull()
        ->and(ArabicPreview::active())->toBeFalse()
        ->and($reads)->toBe(0);
});
