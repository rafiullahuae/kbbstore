<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Setting;
use App\Notifications\CustomerPasswordReset;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\Route;

/**
 * =============================================================================
 * A `Host:` HEADER IS NOT A SETTING, AND AN OWNER'S CLICK IS
 * =============================================================================
 *
 * The shop is becoming a product. It will be installed on domains nobody has
 * seen, and it will be moved between them. "Update APP_URL automatically from
 * the request" is the obvious way to make that painless and it is, done
 * naively, HOST-HEADER INJECTION: a visitor picks the `Host` header, so a
 * visitor would pick where this shop's password-reset emails point. That is
 * account takeover, not a cosmetic bug.
 *
 * The split that makes it safe:
 *
 *   IN-BAND      a redirect Location, a link on the page in front of the
 *                visitor. The request's own host is correct and harmless --
 *                a forged Host redirects the forger to their own domain.
 *
 *   OUT-OF-BAND  an email, a webhook callback, a canonical tag, a sitemap
 *                entry. The recipient is not the person who made the request,
 *                so the host must come from a value the OWNER confirmed.
 *
 * This file is the enforcement. The first three cases are the ones that matter:
 * a forged `Host` and a forged `X-Forwarded-Host` must not reach `.env`, must
 * not reach an email, and must not reach a canonical tag.
 *
 * ── WHY AN AUTHENTICATED CLICK IS GENUINELY DIFFERENT ───────────────────────
 *
 * Because a browser sends a cookie to the domain it thinks it is talking to. To
 * reach this application with BOTH a forged host and the owner's session you
 * must already hold that session on the shop's real domain -- at which point
 * the shop is yours and `.env` is the least of it. The adopt endpoint therefore
 * takes the address from the request it is answering and not from its body:
 * a body field is a value an attacker chooses, a request host is one they can
 * only choose for a request they are themselves making.
 */

/**
 * The two routes, registered here because routes/web.php belongs to the
 * integrator and no lane may edit it. Same middleware as the group they will be
 * required into (routes/site-url-admin.php names the line), so the owner-only
 * assertion below goes through the real EnforceAdminCapability.
 */
function siteUrlRoutes(): void
{
    Route::middleware(['web', 'auth:admin'])
        ->prefix('admin-api')
        ->group(base_path('routes/site-url-admin.php'));
}

function siteUrlOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'site-url-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A .env of this test's own, so nothing here can touch the real one. */
function siteUrlSandboxEnv(string $appUrl = 'https://real-shop.test'): string
{
    $dir = sys_get_temp_dir().'/kbb-siteurl-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    file_put_contents($dir.'/.env', "APP_KEY=base64:AAAA\nAPP_URL={$appUrl}\nDB_PASSWORD=\"keep me\"\n");

    app()->useEnvironmentPath($dir);

    return $dir.'/.env';
}

beforeEach(function () {
    config(['app.url' => 'https://real-shop.test']);
});

/* ═══════════════════════════════════════ 1. the forged host reaches nothing */

it('never lets a forged Host header reach .env', function () {
    /*
     * ── THE TEST THIS WHOLE LANE EXISTS FOR ─────────────────────────────────
     *
     * An unauthenticated request, arriving on a host of the attacker's choosing,
     * asking this shop to call itself that from now on. If it worked, the next
     * password-reset email every customer received would point at
     * attacker.test, and the attacker would hold a valid reset token for any
     * account they asked for.
     *
     * Two things stop it and both are asserted: the route requires an admin
     * session, and the endpoint takes the address from the request rather than
     * from the body -- so there is no field to put attacker.test in either.
     *
     * MUTATION: drop `auth:admin` from siteUrlRoutes() above (or, in the real
     * wiring, move the require in routes/web.php outside the admin group).
     * Red -- the file changes and the assertion on its bytes fails.
     */
    siteUrlRoutes();
    $env = siteUrlSandboxEnv();
    $before = (string) file_get_contents($env);

    $response = $this->post(
        'https://attacker.test/admin-api/site-url/adopt',
        ['confirm' => 'https://attacker.test'],
        ['Host' => 'attacker.test']
    );

    expect($response->status())->not->toBe(200);
    expect((string) file_get_contents($env))->toBe($before);
    expect((string) file_get_contents($env))->not->toContain('attacker.test');
});

it('never lets a forged X-Forwarded-Host reach .env, and does not trust the header at all', function () {
    /*
     * The shop sits behind Cloudways' proxy, so X-Forwarded-Host is a header
     * that arrives on real traffic -- which is exactly why it is dangerous: it
     * LOOKS like infrastructure and is typed by whoever sent the request.
     *
     * The application registers no trusted proxies, so Symfony ignores the
     * header outright and getHost() stays with the real Host. That is the
     * correct posture and it is asserted rather than assumed, because
     * `$middleware->trustProxies()` is one line somebody could add in
     * bootstrap/app.php for an unrelated reason and silently make this header
     * authoritative.
     *
     * MUTATION: add `$middleware->trustProxies(at: '*')` to bootstrap/app.php.
     * Red on the second expectation.
     */
    siteUrlRoutes();
    $env = siteUrlSandboxEnv();
    $before = (string) file_get_contents($env);

    $owner = siteUrlOwner();

    $response = $this->actingAs($owner, 'admin')->post(
        'https://real-shop.test/admin-api/site-url/adopt',
        ['confirm' => 'https://real-shop.test'],
        ['X-Forwarded-Host' => 'attacker.test', 'X-Forwarded-Proto' => 'http']
    );

    // The owner IS signed in, so the request is allowed -- and what it writes
    // is the real host, because the forwarded one was never consulted.
    expect($response->status())->toBe(200);
    expect((string) file_get_contents($env))->not->toContain('attacker.test');

    // Nothing changed: the real host already matched APP_URL.
    expect((string) file_get_contents($env))->toBe($before);
});

it('never lets a forged Host header into an email', function () {
    /*
     * ── THE OUT-OF-BAND CASE, AND THE ONE THAT IS ACCOUNT TAKEOVER ──────────
     *
     * A password-reset link is sent to an inbox. The person who receives it is
     * not the person who made the request that produced it, so the host in it
     * must come from APP_URL and never from the request. Laravel's OWN
     * ResetPassword notification gets this wrong by default -- it renders
     * against the request root -- which is why this project has its own.
     *
     * The request is made on attacker.test while APP_URL says real-shop.test,
     * and the link in the message must say real-shop.test.
     *
     * MUTATION: change CustomerPasswordReset::url() to build with
     * url()->to(...) or Url::to(...) instead of Url::redirect(). Red.
     */
    $customer = Customer::create([
        'email' => 'reset-'.uniqid().'@example.test',
        'password' => bcrypt('secret-secret'),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);

    // A request in flight on the forged host, exactly as it would be if the
    // reset were requested through the storefront form.
    app()->instance('request', Illuminate\Http\Request::create('https://attacker.test/my-account/forgot', 'POST'));

    $mail = (new CustomerPasswordReset('token-value', (int) $customer->getKey()))->toMail($customer);

    $url = (string) ($mail->viewData['url'] ?? '');

    expect($url)->toStartWith('https://real-shop.test/');
    expect($url)->not->toContain('attacker.test');
});

it('never lets a forged Host header into a canonical tag', function () {
    /*
     * The other out-of-band consumer. A canonical built from the request host
     * is how one shop becomes an unbounded number of indexed duplicates, each
     * naming a domain the attacker owns -- and a crawler that fetched the page
     * with that Host would be told, by the page itself, that the attacker's
     * copy is the real one.
     *
     * MUTATION: change Seo's `$base` to fall back to url('/') before
     * config('app.url'). Red.
     */
    Setting::updateOrCreate(['key' => 'site_url'], ['value' => 'https://real-shop.test']);
    Setting::flushMap();

    $html = $this->get('https://attacker.test/korean-skincare-brands/')->getContent();

    expect($html)->toContain('<link rel="canonical" href="https://real-shop.test/');

    /*
     * Every URL this page tells a CRAWLER about -- canonical, og:url, the
     * JSON-LD @id -- has to say real-shop.test. The <script> and <link> tags
     * that fetch this page's own CSS and JavaScript do NOT: those are in-band,
     * the browser is already on that host, and Vite building them from the
     * request root is correct. So the assertion is on the head block's URLs and
     * not on the whole document, which is the in-band/out-of-band line drawn
     * exactly where it belongs rather than one character to the left.
     */
    foreach (['<link rel="canonical"', 'property="og:url"', '"@id"', 'hreflang='] as $needle) {
        foreach (explode('>', $html) as $tag) {
            if (str_contains($tag, $needle)) {
                expect($tag)->not->toContain('attacker.test');
            }
        }
    }

    expect(substr_count($html, 'attacker.test'))
        ->toBeLessThanOrEqual(4, 'only the four Vite asset tags may carry the request host');
});

/* ═══════════════════════════════════════════════ 2. the banner, and inertness */

it('reports no mismatch when the shop is served from the address it is configured with', function () {
    /*
     * INERTNESS, WHICH IS RULE 1. extrabeauty.ae is served from extrabeauty.ae,
     * so applying this package puts nothing on any screen there. If this goes
     * red the feature is not shippable, whatever else passes.
     *
     * MUTATION: make SiteUrl::mismatch() compare hosts case-sensitively before
     * normalising. Red.
     */
    siteUrlRoutes();

    $request = Illuminate\Http\Request::create('https://real-shop.test/anything', 'GET');

    expect(SiteUrl::mismatch($request))->toBeNull();

    $data = $this->actingAs(siteUrlOwner(), 'admin')
        ->get('https://real-shop.test/admin-api/site-url')
        ->assertOk()
        ->json();

    expect($data['mismatch'])->toBeFalse();
    expect($data['also_moves'])->toBe([]);
});

it('reports the mismatch, naming both addresses, when the shop has moved', function () {
    /*
     * The storefront keeps working -- nothing here refuses a request, and the
     * assertion below proves the page still renders on the new host. What
     * changes is that the admin says so.
     *
     * MUTATION: have SiteUrl::mismatch() return null whenever the CURRENT host
     * is unknown rather than when it is unusable. Red.
     */
    siteUrlRoutes();

    $data = $this->actingAs(siteUrlOwner(), 'admin')
        ->get('https://moved-shop.test/admin-api/site-url')
        ->assertOk()
        ->json();

    expect($data['mismatch'])->toBeTrue();
    expect($data['configured'])->toBe('https://real-shop.test');
    expect($data['current'])->toBe('https://moved-shop.test');
    expect($data['confirm'])->toBe('https://moved-shop.test');

    // Both hosts named, because "a different address" with one of them on
    // screen is the message that makes an owner guess.
    expect($data['configured_host'])->toBe('real-shop.test');
    expect($data['current_host'])->toBe('moved-shop.test');

    // And the storefront is still a storefront on the new host.
    $this->get('https://moved-shop.test/korean-skincare-brands/')->assertOk();
});

it('names the things a domain move breaks that writing APP_URL does not fix', function () {
    /*
     * Stripe's webhook endpoint still points at the old address after a move.
     * Payments appear to work and the order status never updates -- silently,
     * weeks later, on a shop nobody is watching. The owner has to be TOLD, not
     * left to discover it, and nothing inside this application can change it.
     *
     * MUTATION: return [] from SiteUrlApiController::alsoMoves(). Red.
     */
    siteUrlRoutes();

    Setting::updateOrCreate(['key' => 'site_url'], ['value' => 'https://real-shop.test']);
    Setting::updateOrCreate(['key' => App\Support\SiteHost::KEY_CANONICAL], ['value' => 'real-shop.test']);
    Setting::flushMap();

    $data = $this->actingAs(siteUrlOwner(), 'admin')
        ->get('https://moved-shop.test/admin-api/site-url')
        ->assertOk()
        ->json();

    $all = json_encode($data['also_moves']);

    expect($all)->toContain('Stripe');
    expect($all)->toContain('third-party');
    expect($all)->toContain('Site address');
    expect($all)->toContain('Site URL');
});

/* ═══════════════════════════════════════════════════════ 3. adopting it */

it('writes the address this request arrived on, once an owner confirms it', function () {
    /*
     * The happy path, and the only path that writes anything. Note what is
     * asserted about the FILE: APP_URL changed and DB_PASSWORD did not.
     *
     * MUTATION: have adopt() take `$request->input('app_url')` instead of
     * SiteUrl::fromRequest(). The next test goes red.
     */
    siteUrlRoutes();
    $env = siteUrlSandboxEnv();

    $data = $this->actingAs(siteUrlOwner(), 'admin')
        ->post('https://moved-shop.test/admin-api/site-url/adopt', ['confirm' => 'https://moved-shop.test'])
        ->assertOk()
        ->json();

    expect($data['ok'])->toBeTrue();
    expect($data['changed'])->toBeTrue();
    expect($data['mismatch'])->toBeFalse('after the write the shop agrees with itself');

    $after = (string) file_get_contents($env);

    expect($after)->toContain('APP_URL="https://moved-shop.test"');
    expect($after)->toContain('DB_PASSWORD="keep me"');
});

it('refuses a confirmation that does not match the address it is answering on', function () {
    /*
     * ── WHERE THE BODY IS ALLOWED TO HAVE AN OPINION, AND WHERE IT IS NOT ───
     *
     * `confirm` cannot INTRODUCE a value -- adopt() derives the address from
     * the request either way. All it can do is disagree, and a disagreement
     * means the owner is looking at a stale screen. So the useful assertion is
     * the second one: even a body naming a completely different host writes the
     * request's host or nothing at all. It writes nothing here.
     *
     * MUTATION: delete the hash_equals check. Red on the status.
     */
    siteUrlRoutes();
    $env = siteUrlSandboxEnv();
    $before = (string) file_get_contents($env);

    $this->actingAs(siteUrlOwner(), 'admin')
        ->post('https://moved-shop.test/admin-api/site-url/adopt', ['confirm' => 'https://attacker.test'])
        ->assertStatus(409);

    expect((string) file_get_contents($env))->toBe($before);
    expect((string) file_get_contents($env))->not->toContain('attacker.test');
});

it('is owner-only, by being mapped to an owner-only capability', function () {
    /*
     * THIS TEST ASSERTED THE OPPOSITE WHEN IT WAS WRITTEN, and the reasoning
     * behind that was checkably wrong on two counts.
     *
     * It argued that leaving these routes out of AdminCapabilities is the
     * strictest setting available, because the map fails closed -- for() gives
     * null, nobody holds null, EnforceAdminCapability 403s every role but the
     * owner. The runtime half of that is true. The rest is not:
     *
     *   1. AdminCapabilityMapTest requires every admin route to be mapped, and
     *      pins the deny-by-default property against a route it registers
     *      inside itself, PRECISELY so that real routes cannot lean on it. A
     *      real unmapped route makes that guard weaker, not the route stronger.
     *   2. Its cited precedent was wrong. `admin-api/site-address`, the route
     *      immediately beside this one, IS mapped -- to `store.settings`.
     *
     * And CLAUDE.md rule 5 asks for the capability by name: "Every new admin
     * endpoint gets its own capability and fails closed."
     *
     * `platform.site_url` rather than `store.settings` because the blast radius
     * differs in kind. A wrong currency shows up on the next page load; a wrong
     * APP_URL is invisible until somebody's password-reset link arrives on a
     * domain this shop no longer owns.
     *
     * Both halves are still asserted -- the mapping AND the 403 -- because
     * "owner-only" stated in a comment is exactly the property a later tidy-up
     * of the map removes without noticing.
     *
     * MUTATION (run, red): change the two `admin-api/site-url` rules to
     * 'catalog.view'. The first expectations fail, and the manager below is let
     * in and writes .env.
     */
    siteUrlRoutes();

    expect(App\Support\AdminCapabilities::forPath('POST', 'admin-api/site-url/adopt'))->toBe('platform.site_url');
    expect(App\Support\AdminCapabilities::forPath('GET', 'admin-api/site-url'))->toBe('platform.site_url');
    expect(App\Support\AdminCapabilities::CAPABILITIES['platform.site_url'])->toBe(['owner']);

    $manager = AdminUser::create([
        'name' => 'Manager',
        'email' => 'site-url-manager-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'manager',
    ]);

    $env = siteUrlSandboxEnv();
    $before = (string) file_get_contents($env);

    $this->actingAs($manager, 'admin')
        ->post('https://moved-shop.test/admin-api/site-url/adopt', ['confirm' => 'https://moved-shop.test'])
        ->assertStatus(403);

    expect((string) file_get_contents($env))->toBe($before);
});

it('takes a second press as a no-op rather than rewriting .env again', function () {
    siteUrlRoutes();
    siteUrlSandboxEnv('https://real-shop.test');

    $data = $this->actingAs(siteUrlOwner(), 'admin')
        ->post('https://real-shop.test/admin-api/site-url/adopt', ['confirm' => 'https://real-shop.test'])
        ->assertOk()
        ->json();

    expect($data['ok'])->toBeTrue();
    expect($data['changed'])->toBeFalse();
});

/* ══════════════════════════ 4. the two origins, and the split they encode */

it('gives an IN-BAND origin from the request and an OUT-OF-BAND one from APP_URL', function () {
    /*
     * ── THE WHOLE LANE IN ONE ASSERTION ─────────────────────────────────────
     *
     * Same request, two answers, and the difference is who is on the other end.
     * A redirect Location may say moved-shop.test, because the visitor is
     * already there. An email may not, because the reader is not.
     *
     * MUTATION: make origin() return externalOrigin() unconditionally -- the
     * first expectation goes red, and the storefront on a moved domain starts
     * bouncing visitors back to the old one. Make externalOrigin() fall back to
     * the request -- the second goes red, and a forged Host is in a
     * password-reset email.
     */
    $request = Illuminate\Http\Request::create('https://moved-shop.test/checkout', 'GET');

    expect(SiteUrl::origin($request))->toBe('https://moved-shop.test');
    expect(SiteUrl::externalOrigin())->toBe('https://real-shop.test');
});

it('falls back to APP_URL for the in-band origin when there is no request at all', function () {
    /*
     * A queue worker rendering a mailable, a scheduled command, artisan. There
     * is no visitor to be in-band with, so the confirmed value is the only
     * answer available -- and it is the safe direction to fail in.
     *
     * MUTATION: drop the runningInConsole() guard from origin(). Red under the
     * console, where request() is a synthetic Request for `http://localhost`.
     */
    expect(SiteUrl::origin())->toBe('https://real-shop.test');
    expect(SiteUrl::externalOrigin())->toBe('https://real-shop.test');
});

it('strips the base path off the out-of-band origin, so it cannot be counted twice', function () {
    /*
     * APP_URL on a sub-folder install ends in the base path
     * (env.staging.txt: https://easywebsol.com/kbb-upgrade) and Url::to()
     * already adds that prefix. Combining both is the /kbb-upgrade/kbb-upgrade/
     * 404 this project has fixed twice — in the IndexNow hook and in the upload
     * endpoint. origin() and externalOrigin() are the HOST only, so a caller
     * that appends Url::to() gets the base exactly once however APP_URL is
     * written.
     *
     * MUTATION: return self::configured() from externalOrigin(). Red.
     */
    config(['app.url' => 'https://easywebsol.com/kbb-upgrade']);

    expect(SiteUrl::externalOrigin())->toBe('https://easywebsol.com');
    expect(SiteUrl::configured())->toBe('https://easywebsol.com/kbb-upgrade');
});
