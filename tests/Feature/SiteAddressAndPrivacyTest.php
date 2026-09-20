<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Redirect;
use App\Models\Setting;
use App\Services\Seo\IndexNow;
use App\Support\SiteHost;

/**
 * =============================================================================
 * ONE SHOP, FOUR ADDRESSES — AND ONLY ONE OF THEM MAY BE IN GOOGLE
 * =============================================================================
 *
 * The owner is moving the shop to extrabeauty.ae and building the vendor side
 * on a domain of its own, with the console and the release-staging site on
 * subdomains of it. Both of those run this same code, and he said what he needs
 * in one sentence:
 *
 *     "i don't want that my staging site has been indexed by any search
 *      engine. do something proper for that, neither my super admin console."
 *
 * "Proper" is the word this file is about. There are four ways a second copy of
 * a shop gets into an index and three of them are not the obvious one:
 *
 *   1. the page is crawled and says nothing        -> X-Robots-Tag, and the
 *                                                     meta tag beside it
 *   2. the page is BLOCKED by robots.txt and gets
 *      listed from a link anyway, title-less,
 *      with its noindex unreadable behind the
 *      block                                       -> so a private install
 *                                                     INVITES the crawl
 *   3. the site tells a search engine to come and
 *      look                                        -> IndexNow, outbound, and
 *                                                     enabled by default on any
 *                                                     settings table copied from
 *                                                     production
 *   4. the old domain keeps serving the shop
 *      alongside the new one                       -> the alias forward
 *
 * ── AND THE CONSTRAINT THAT OUTRANKS ALL OF IT ──────────────────────────────
 *
 * There is no shell on this host. A redirect rule that sends the admin panel
 * somewhere unreachable cannot be undone from inside the admin panel. That is
 * why SiteHost forwards only hosts that are on an explicit list, instead of
 * "anything that is not canonical" — and the first four tests here are about
 * that property rather than about the feature.
 */

/** The state every test starts from: the feature exists and is switched off. */
function siteAddressReset(array $overrides = []): void
{
    $values = $overrides + [
        SiteHost::KEY_CANONICAL => '',
        SiteHost::KEY_ALIASES => '',
        SiteHost::KEY_REDIRECT => '0',
        SiteHost::KEY_VISIBILITY => 'public',
    ];

    foreach ($values as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    Setting::flushMap();
    SiteHost::forget();
}

/** An owner, built the way every other admin test here builds one. */
function siteAddressOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Site address owner',
        'email' => 'site-address-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

beforeEach(function () {
    siteAddressReset();
});

/* ═══════════════════════════════════ the lockout properties, first and loudest */

it('ships inert: with no canonical host nothing is forwarded and nothing is hidden', function () {
    /*
     * The package that introduces this must change the behaviour of a running
     * shop by exactly nothing. Every default is the off position, so the only
     * thing applying it does is put a screen in the admin.
     *
     * MUTATION: make SiteHost::classify() return UNLISTED for an unknown host
     * while the canonical host is empty. Red here, and red in every test below
     * that visits a page.
     */
    expect(SiteHost::classify('anything.test'))->toBe(SiteHost::CANONICAL);
    expect(SiteHost::redirectEnabled())->toBeFalse();
    expect(SiteHost::aliases())->toBe([]);

    $response = $this->get('http://whatever.test/');

    expect($response->headers->get('X-Robots-Tag'))->toBeNull(
        'an install with no canonical host configured must not mark anything noindex'
    );
});

it('never forwards a host that is not on the alias list, however wrong the canonical host is', function () {
    /*
     * ── THIS IS THE ONE THAT MATTERS ───────────────────────────────────────
     *
     * The canonical host is a typo — extrabeauy.ae, no `t` — and forwarding is
     * on. Every "redirect anything that is not canonical" implementation sends
     * the real site, the admin panel and the login form to a domain that does
     * not exist, and there is no shell to undo it with.
     *
     * Here the real host is simply not on the list, so it is served.
     *
     * MUTATION: change CanonicalHost::shouldForward() to forward when the
     * verdict is UNLISTED as well as ALIAS. Red, by name.
     */
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'extrabeauy.ae',   // sic
        SiteHost::KEY_ALIASES => 'kbeautybliss.com',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    expect(SiteHost::classify('extrabeauty.ae'))->toBe(SiteHost::UNLISTED);

    $response = $this->get('http://extrabeauty.ae/');

    expect($response->status())->not->toBe(301,
        'a typo in the canonical host must never redirect the host the owner actually uses — '
        .'there is no shell on this server to undo it with'
    );
});

it('does not forward a POST, because a 301 would drop the body', function () {
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'extrabeauty.ae',
        SiteHost::KEY_ALIASES => 'kbeautybliss.com',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    /*
     * Every browser converts a 301 on a POST into a GET and drops the body, so
     * forwarding a form submission silently discards it — an order, in the
     * worst case. Served where it lands instead.
     *
     * MUTATION: drop the method check from shouldForward(). Red.
     */
    $response = $this->post('http://kbeautybliss.com/cart', []);

    expect($response->status())->not->toBe(301);
});

it('never forwards the health check or the import loopback', function () {
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'extrabeauty.ae',
        SiteHost::KEY_ALIASES => 'kbeautybliss.com',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    /*
     * UpdateRunner fetches /_kbb-health over HTTP right after it writes files
     * and rolls the update back if it does not answer. A 301 there is an update
     * that always rolls itself back — a feature that appears to work and
     * silently undoes every package.
     *
     * MUTATION: empty CanonicalHost::EXEMPT_PREFIXES. Red.
     */
    $response = $this->get('http://kbeautybliss.com/_kbb-health');

    expect($response->status())->not->toBe(301,
        'the updater fetches this to decide whether to roll back; a redirect here breaks every update'
    );
});

/* ═══════════════════════════════════════════════════ what it does do */

it('forwards a listed alias to the canonical host, keeping the path', function () {
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'extrabeauty.ae',
        SiteHost::KEY_ALIASES => 'kbeautybliss.com',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    $response = $this->get('http://kbeautybliss.com/shop/');

    expect($response->status())->toBe(301);
    expect($response->headers->get('Location'))->toContain('extrabeauty.ae');
});

it('derives the www counterpart so nobody has to know to type it', function () {
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    /*
     * Folding `www.` away in normalise() would make the two compare equal and
     * the redirect that is the whole point would never fire. Deriving the
     * counterpart instead means the pair can never be configured
     * inconsistently.
     *
     * MUTATION: delete the counterpart block in SiteHost::aliases(). Red.
     */
    expect(SiteHost::aliases())->toContain('www.extrabeauty.ae');
    expect(SiteHost::classify('www.extrabeauty.ae'))->toBe(SiteHost::ALIAS);

    // And the other direction, for a shop whose canonical form carries www.
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'www.extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    expect(SiteHost::classify('extrabeauty.ae'))->toBe(SiteHost::ALIAS);
});

it('corrects the host and the path in one hop, not two', function () {
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'extrabeauty.ae',
        SiteHost::KEY_ALIASES => 'kbeautybliss.com',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    /*
     * BOTH SLASH FORMS, which is what RedirectMap actually writes — see
     * docs/GB-MEDIA-AND-REDIRECTS.md and the Phase 9 seed's precedent.
     *
     * It is also the only form this test can exercise, and the reason is worth
     * recording so the next reader does not "fix" the production code to match:
     * Laravel's test client puts every URI through prepareUrlForRequest(),
     * which ends in trim($uri, '/') and therefore STRIPS THE TRAILING SLASH
     * before the request is built. $this->get('/toners/') is indistinguishable
     * from $this->get('/toners') here. A real request keeps it —
     * Request::create('http://host/toners/')->getPathInfo() is '/toners/',
     * checked — which is precisely why CheckRedirects uses getPathInfo() and
     * not path(), and why the table carries both.
     */
    foreach (['/toners', '/toners/'] as $source) {
        Redirect::query()->updateOrCreate(
            ['source' => $source],
            ['target' => '/product-category/skincare/toners/', 'enabled' => true, 'code' => 301]
        );
    }

    /*
     * An old address on the old domain needs two corrections. Done naively that
     * is two 301s and a shopper pays the latency twice. CanonicalHost consults
     * the same `redirects` table CheckRedirects reads, on the same column, so
     * both corrections travel in one response.
     *
     * MUTATION: delete the Redirect lookup from targetFor(). The Location
     * header then still carries /toners/ and this goes red.
     */
    $response = $this->get('http://kbeautybliss.com/toners/');

    expect($response->status())->toBe(301);
    expect($response->headers->get('Location'))->toContain('/product-category/skincare/toners/');
});

it('serves an unlisted host rather than forwarding it, and marks it noindex', function () {
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    /*
     * A staging site beside production is UNLISTED. Redirecting it would make
     * staging unusable, and staging is where a release is checked before
     * customers see it. Hidden, not forwarded.
     */
    $response = $this->get('http://staging.extrabeauty.ae/');

    expect($response->status())->not->toBe(301, 'redirecting staging to production makes staging useless');
    expect(strtolower((string) $response->headers->get('X-Robots-Tag')))->toContain('noindex');
});

/* ═════════════════════════════ the switch, which is the half a host cannot answer */

it('keeps a private install out of the index even on its own canonical host', function () {
    /*
     * ── WHY THE EXPLICIT SWITCH EXISTS AT ALL ──────────────────────────────
     *
     * Inferring "private" from "this host is not the canonical one" works for a
     * staging site that lives BESIDE production and fails silently for one that
     * lives on a domain of its own — where it IS canonical, classifies as
     * CANONICAL, and gets indexed.
     *
     * That is exactly the arrangement the owner chose: the console and the
     * staging site on subdomains of a separate vendor domain, each canonical
     * for itself. The inference would have been wrong for both of the two sites
     * this was asked for.
     *
     * MUTATION: make SiteHost::isPrivate() return only
     * `current() === UNLISTED`. Red here, and this is the test that catches the
     * one failure the owner actually asked about.
     */
    siteAddressReset([
        SiteHost::KEY_CANONICAL => 'staging.vendor.test',
        SiteHost::KEY_VISIBILITY => 'private',
    ]);

    expect(SiteHost::classify('staging.vendor.test'))->toBe(SiteHost::CANONICAL);

    $response = $this->get('http://staging.vendor.test/');

    expect(strtolower((string) $response->headers->get('X-Robots-Tag')))->toContain('noindex');
});

it('invites the crawl on a private install instead of blocking it', function () {
    siteAddressReset([SiteHost::KEY_VISIBILITY => 'private']);

    /*
     * The trap, and the reason `Disallow: /` is the wrong answer for staging:
     * Disallow forbids CRAWLING, not indexing. A URL a crawler may not fetch is
     * a URL whose noindex it can never read, so one link is enough to list it
     * permanently — and the noindex that would have removed it is behind the
     * door robots.txt just shut.
     *
     * MUTATION: change the private robots.txt to "Disallow: /". Red.
     */
    $body = $this->get('http://anything.test/robots.txt')->getContent();

    expect($body)->toContain('User-agent: *');

    /*
     * str_contains, NOT expect()->not->toContain(): toContain is VARIADIC, so
     * the message would be read as a SECOND NEEDLE and `not` would then mean
     * "contains neither" -- which is trivially true and the assertion could
     * never fail. ExpectationsThatCannotFailTest caught exactly this line.
     */
    expect(str_contains($body, 'Disallow: /'))->toBeFalse(
        'a blocked URL is one whose noindex a crawler can never read — it stays in the index on one link'
    );
});

it('refuses to announce a private install to a search engine', function () {
    /*
     * The hole a noindex header does not close, and the worst one, because it
     * is not passive: IndexNow is an outbound POST that hands Bing a list of
     * URLs and asks it to come and look.
     *
     * A staging site cloned from production arrives with indexnow_on already
     * '1', because it is a copy of a live shop's settings table. The first
     * product saved on it would announce the staging URL — from the one site
     * that is meant to be invisible, by the one mechanism that reaches out.
     *
     * MUTATION: delete the isPrivate() guard from IndexNow::enabled(). Red.
     */
    siteAddressReset([SiteHost::KEY_VISIBILITY => 'private']);
    Setting::query()->updateOrCreate(['key' => 'indexnow_on'], ['value' => '1']);
    Setting::flushMap();
    SiteHost::forget();

    expect(IndexNow::enabled())->toBeFalse(
        'a private install must never push its URLs to a search engine, whatever the setting says'
    );

    siteAddressReset();
    Setting::query()->updateOrCreate(['key' => 'indexnow_on'], ['value' => '1']);
    Setting::flushMap();
    SiteHost::forget();

    expect(IndexNow::enabled())->toBeTrue('a public install is unaffected');
});

/* ═══════════════════════════════════════════════════════════ the screen */

it('will not switch forwarding on with nowhere to forward to', function () {
    $owner = siteAddressOwner();

    /*
     * A setting that reads as on and does nothing is worse than one that is
     * off: the owner, seeing it on, stops looking for why the old domain still
     * serves the shop.
     */
    $response = $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/site-address', [
            'canonical_host' => '',
            'aliases' => '',
            'redirect_enabled' => true,
            'visibility' => 'public',
        ]);

    $response->assertStatus(422);
});

it('accepts a pasted URL where a host was asked for, because people paste URLs', function () {
    $owner = siteAddressOwner();

    $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/site-address', [
            'canonical_host' => 'https://extrabeauty.ae/',
            'aliases' => "https://www.kbeautybliss.com/\nkbeautybliss.com",
            'redirect_enabled' => true,
            'visibility' => 'public',
        ])->assertOk();

    Setting::flushMap();
    SiteHost::forget();

    expect(SiteHost::canonical())->toBe('extrabeauty.ae');
    expect(SiteHost::classify('www.kbeautybliss.com'))->toBe(SiteHost::ALIAS);
});

it('does not leak the site address settings through the public settings API', function () {
    /*
     * CLAUDE.md's landmine: /api/* is unauthenticated and every endpoint there
     * is public. SettingController::PUBLIC_KEYS is an allow-list, so a new key
     * is private by default — this pins that it stays that way, because the
     * failure mode is the same shape as the three columns that leaked in
     * production.
     */
    siteAddressReset([SiteHost::KEY_CANONICAL => 'extrabeauty.ae']);

    $body = $this->getJson('/api/settings')->getContent();

    foreach ([SiteHost::KEY_CANONICAL, SiteHost::KEY_ALIASES, SiteHost::KEY_REDIRECT, SiteHost::KEY_VISIBILITY] as $key) {
        // str_contains for the same reason as above: toContain is variadic, so
        // a message passed here becomes a second needle and the negation
        // becomes unfailable.
        expect(str_contains($body, $key))->toBeFalse("the public settings endpoint must not carry {$key}");
    }
});
