<?php

declare(strict_types=1);

/**
 * The in-band / out-of-band split, asserted on the things that actually leave
 * this application.
 *
 * ── WHAT THE SPLIT IS ─────────────────────────────────────────────────────
 *
 *   IN-BAND      a redirect `Location`, a link on the page the visitor is
 *                looking at. The host of the REQUEST is right, because the
 *                visitor is already on it. Support\Url::redirect().
 *
 *   OUT-OF-BAND  an email, a webhook callback, a canonical tag, a sitemap
 *                entry. The reader is NOT the person who made the request, so
 *                the host must be one the owner confirmed and may never come
 *                from a header. Support\Url::external().
 *
 * Support\Url::redirect() used to build EVERY storefront redirect from APP_URL,
 * which is the wrong half of that split and is the defect these tests pin:
 * with APP_URL still naming the old domain, a shopper on the new one was thrown
 * back to the old one on the way to paying, where their session cookie — and so
 * their basket — does not exist.
 *
 * tests/Feature/SiteUrlMismatchTest.php covers the forged `Host:` case for the
 * password-reset email and the canonical tag. This file covers what that one
 * does not: the in-band direction, a forged X-FORWARDED-Host with proxies
 * actually trusted, the sitemap, the payment webhooks, the alias forwarding,
 * and the two mail views that built their own root-relative links.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Redirect;
use App\Models\Setting;
use App\Notifications\CustomerPasswordReset;
use App\Services\Payments\GatewayCredentials;
use App\Support\SiteUrl;
use App\Support\Url;
use Illuminate\Http\Request;

/**
 * Trust a proxy for the duration of one closure, so `X-Forwarded-Host` is
 * actually honoured by Symfony rather than ignored.
 *
 * WITHOUT THIS THE FORWARDED-HOST TESTS PROVE NOTHING. bootstrap/app.php
 * configures no trusted proxies today, so Symfony drops `X-Forwarded-*` on the
 * floor and a test that sets the header would pass against code that read it.
 * Turning trust ON is the hostile configuration — the one this shop would be in
 * the day it moves behind a load balancer or Cloudflare — and the assertions
 * below have to hold there, not merely in the configuration that happens to
 * make the header inert.
 */
function u2WithTrustedProxy(callable $body): mixed
{
    Request::setTrustedProxies(
        ['127.0.0.1', '10.0.0.0/8'],
        Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
    );

    try {
        return $body();
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
}

/* ══════════════════════════════════════ 1. in-band: the measured defect ══ */

it('sends a shopper on a new domain to that domain and not to APP_URL', function () {
    /*
     * ── THE DEFECT, EXACTLY AS LANE N MEASURED IT ───────────────────────────
     *
     *     APP_URL=https://old-shop.test
     *     GET https://new-shop.test/checkout  →  302 Location: https://old-shop.test/cart/
     *
     * The shopper is on new-shop.test. Their session cookie is scoped to
     * new-shop.test. They have just been sent to a host that cannot be shown
     * that cookie, so the basket they are being told to go and look at is
     * invisible when they arrive — and this is the redirect a shopper meets on
     * the way to paying. Once the old domain stops resolving it is a dead end
     * rather than a detour.
     *
     * MUTATION, RUN: put `SiteUrl::externalOrigin()` back in place of
     * `SiteUrl::origin($request)` in Support\Url::redirect() — i.e. restore the
     * old body — and this goes red with
     * `Failed asserting that 'https://old-shop.test/cart/' ... `.
     */
    config(['app.url' => 'https://old-shop.test']);

    $response = $this->get('https://new-shop.test/checkout');

    $response->assertRedirect('https://new-shop.test/cart/');

    /*
     * str_contains() and not ->not->toContain(), because toContain() is
     * VARIADIC: a second argument is read as another needle, not as a failure
     * message, and the expectation silently stops being able to fail.
     * ExpectationsThatCannotFailTest caught exactly that here.
     */
    expect(str_contains((string) $response->headers->get('Location'), 'old-shop.test'))
        ->toBeFalse('the visitor must not be thrown onto the configured host');
});

it('leaves a redirect alone on a shop served from the address it is configured with', function () {
    /*
     * RULE 1, AND IT IS THE WHOLE POINT OF SHIPPING THIS AT ALL.
     *
     * extrabeauty.ae is served from extrabeauty.ae. When the request host and
     * APP_URL agree — which is every correctly configured shop, and the live one
     * — in-band and out-of-band are the SAME STRING, so applying this package
     * moves nothing. If this ever goes red, the change has started rewriting
     * URLs on shops that were never broken.
     */
    config(['app.url' => 'https://real-shop.test']);

    $this->get('https://real-shop.test/checkout')
        ->assertRedirect('https://real-shop.test/cart/');

    $request = Request::create('https://real-shop.test/checkout');

    foreach (['/', '/cart/', '/checkout/success', '/product/foo/', '/my-account/orders'] as $path) {
        expect(Url::redirect($path, $request))->toBe(Url::external($path), $path);
    }
});

it('keeps the base path exactly once on a shop mounted in a sub-folder', function () {
    /*
     * The `/kbb-upgrade/kbb-upgrade/cart` trap, which is the reason
     * Url::redirect() exists at all rather than callers passing Url::to() to
     * redirect(). Both halves of the split have to survive it, and external()
     * gets it from SiteUrl::externalOrigin() stripping APP_URL's path rather
     * than from the old string-suffix arithmetic.
     */
    config(['app.url' => 'https://shop.example/kbb-upgrade', 'kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    $request = Request::create('https://shop.example/kbb-upgrade/checkout');

    expect(Url::external('/cart/'))->toBe('https://shop.example/kbb-upgrade/cart/')
        ->and(Url::redirect('/cart/', $request))->toBe('https://shop.example/kbb-upgrade/cart/');

    // And an already-built path is not prefixed a second time.
    expect(Url::externalise(Url::to('/cart/')))->toBe('https://shop.example/kbb-upgrade/cart/');

    Url::forgetBase();
});

/* ══════════════════════════════ 2. out-of-band: nothing from a header ═══ */

it('never lets a forged X-Forwarded-Host into a password-reset email', function () {
    /*
     * ACCOUNT TAKEOVER, AND THE REASON external() MAY NOT HAVE A REQUEST
     * FALLBACK.
     *
     * SiteUrlMismatchTest pins the bare `Host:` case. This is the same attack
     * one layer out: behind a trusted proxy, `X-Forwarded-Host` is what
     * getSchemeAndHttpHost() answers with, so a shop that reads the request at
     * all reads THIS. The reset link is a credential. A reset link naming a
     * domain the attacker owns, mailed by the shop itself, is the account.
     *
     * MUTATION, RUN: build the link the request-derived way —
     * `url(Url::to('/my-account/reset/'...))` in CustomerPasswordReset::url().
     * RED: the link comes out on attacker.test.
     *
     * ▲ AND A MUTATION THAT DOES *NOT* GO RED, SAID OUT LOUD BECAUSE IT WOULD
     * OTHERWISE LOOK LIKE THIS TEST COVERS MORE THAN IT DOES. Swapping
     * Url::external() for Url::redirect() here leaves this GREEN, and that is
     * not because the two are equivalent — it is because SiteUrl::origin()
     * refuses to reach for a bound request when app()->runningInConsole(), and
     * that is TRUE UNDER PHPUNIT. In production, under PHP-FPM, that same
     * substitution is request-derived and is the account takeover above. The
     * suite cannot see it, so `url(Url::to(...))` is used as the stand-in: it
     * reads the request in both worlds and so puts the assertion under load.
     * The same caveat applies to the webhook and alias tests below.
     */
    config(['app.url' => 'https://real-shop.test']);

    $customer = Customer::create([
        'email' => 'u2-reset-'.uniqid().'@example.test',
        'password' => bcrypt('secret-secret'),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);

    u2WithTrustedProxy(function () use ($customer) {
        $request = Request::create('https://real-shop.test/my-account/forgot', 'POST');
        $request->headers->set('X-Forwarded-Host', 'attacker.test');
        $request->headers->set('X-Forwarded-Proto', 'https');

        // The header really is being honoured — otherwise the assertions below
        // would pass against code that read the request.
        expect($request->getSchemeAndHttpHost())->toBe('https://attacker.test');

        app()->instance('request', $request);

        $mail = (new CustomerPasswordReset('token-value', (int) $customer->getKey()))->toMail($customer);
        $url = (string) ($mail->viewData['url'] ?? '');

        expect($url)->toStartWith('https://real-shop.test/')
            ->and($url)->not->toContain('attacker.test');
    });
});

it('never lets a forged host into a payment webhook URL', function () {
    /*
     * The webhook URL is what the owner pastes into Stripe's or Tamara's
     * dashboard, and it is where the PROVIDER posts "this order was paid". A
     * host from a header here means payment notifications addressed to somebody
     * else's server: the shopper is charged and the shop never hears about it,
     * which docs/DOMAIN-MOVE-CHECKLIST.md calls the worst failure on the page.
     *
     * Admin-authenticated because the screen is, which is the point — a
     * capability does not make a header trustworthy, and an admin session is
     * exactly as forgeable a `Host:` as an anonymous one.
     *
     * MUTATION, RUN: `url(Url::to('/api/payments/webhook/'...))` in
     * PaymentsApiController::webhookUrl(), which is the request-derived
     * spelling. RED, on attacker.test. (Url::redirect() in its place stays
     * green under PHPUnit only — see the caveat on the password-reset test.)
     */
    config(['app.url' => 'https://real-shop.test']);

    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();

    $admin = AdminUser::create([
        'name' => 'U2 Admin',
        'email' => 'u2-pay-admin@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    $url = u2WithTrustedProxy(fn () => $this->actingAs($admin, 'admin')
        ->withHeaders([
            'X-Forwarded-Host' => 'attacker.test',
            'X-Forwarded-Proto' => 'https',
        ])
        ->postJson('https://real-shop.test/admin-api/payments', [
            'id' => 'tamara',
            'enabled' => true,
            'settings' => [
                'api_token' => 'tamara_api_u2',
                'notification_token' => 'tamara_notify_u2_canary_00',
            ],
        ])->json('webhook_url'));

    expect($url)->toBeString()
        ->and($url)->toStartWith('https://real-shop.test/')
        ->and($url)->toContain('/api/payments/webhook/tamara/')
        ->and($url)->not->toContain('attacker.test');
});

it('never lets a forged host into sitemap.xml or robots.txt', function () {
    /*
     * SeoFilesController::base() had a THIRD candidate, `url('/')`, which is
     * request-derived. A `<loc>` built from a stranger's Host asks Google to
     * index this shop's catalogue under the stranger's domain, and
     * `Sitemap: https://attacker.test/sitemap.xml` in robots.txt tells a crawler
     * where to fetch its next instructions.
     *
     * IT WAS UNREACHABLE ONLY BY ACCIDENT: config/app.php spells
     * `env('APP_URL', 'http://localhost')`, so the second candidate is never
     * blank unless somebody writes a bare `APP_URL=` into .env — one keystroke
     * in a file the installer and Platform → Site address both write. So the
     * test blanks APP_URL, which is the only state in which the fallback was
     * ever reached, and asserts the request cannot get in even there.
     *
     * MUTATION, RUN: put `url('/')` back as the third argument to firstFilled()
     * and both assertions go red — every <loc> comes out on attacker.test.
     */
    config(['app.url' => '']);

    Setting::updateOrCreate(['key' => 'site_url'], ['value' => '']);
    Setting::flushMap();

    $sitemap = $this->get('https://attacker.test/sitemap.xml')->getContent();
    $robots = $this->get('https://attacker.test/robots.txt')->getContent();

    expect($sitemap)->not->toContain('attacker.test')
        ->and($robots)->not->toContain('attacker.test');

    // And the same with the header form, behind a trusted proxy.
    u2WithTrustedProxy(function () {
        $body = $this->withHeaders([
            'X-Forwarded-Host' => 'attacker.test',
            'X-Forwarded-Proto' => 'https',
        ])->get('https://real-shop.test/sitemap.xml')->getContent();

        expect($body)->not->toContain('attacker.test');
    });
});

/* ═══════════════════════════ 3. the alias forwarding must still forward ══ */

it('still forwards an alias host to the canonical one when the path also has a redirect row', function () {
    /*
     * THE REGRESSION THIS CHANGE COULD EASILY HAVE CAUSED, AND DID NOT.
     *
     * CanonicalHost::targetFor() folds a redirects-table hop into the same
     * response, and that branch built its URL with Url::redirect(). It is only
     * ever reached when the request arrived on an ALIAS — targetFor() returns
     * null the moment the request host IS the canonical one. So making
     * redirect() request-derived would have answered the alias with a Location
     * back ON the alias: the forwarding this middleware exists to perform would
     * have silently stopped, for exactly those paths that also have a redirect
     * row and only those. A visitor on the old domain would be told to stay on
     * the old domain — the opposite of the bug being fixed.
     *
     * That line is Url::external() now. This test is what holds it there.
     *
     * MUTATION, RUN: `Url::redirect($mapped->target, $request)` — which is
     * exactly what the bare `Url::redirect($mapped->target)` resolves to in
     * production, where SiteUrl::origin() picks the bound request up for
     * itself. RED: `Location: https://old-shop.test/u2-new-path/`, the visitor
     * told to stay on the domain they were being moved off.
     */
    config(['app.url' => 'https://real-shop.test']);

    foreach ([
        'canonical_host' => 'real-shop.test',
        'host_aliases' => 'old-shop.test',
        'host_redirect_enabled' => '1',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
    Setting::flushMap();

    /*
     * The source is spelled WITHOUT a trailing slash because
     * MakesHttpRequests::prepareUrlForRequest() ends in `trim(url($uri), '/')`
     * and strips one off everything the harness is handed — the trap
     * RedirectMiddlewareTest's own helper documents. A row the request cannot
     * match would send this down CanonicalHost's plain branch instead of the
     * folded one, and the test would pass while asserting nothing about the
     * line it exists for.
     */
    Redirect::query()->create([
        'source' => '/u2-old-path',
        'target' => '/u2-new-path/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => true,
    ]);

    // The source index is cached for ever; a row written after it was built is
    // invisible without this.
    App\Http\Middleware\CheckRedirects::flushIndex();

    $location = (string) $this->get('https://old-shop.test/u2-old-path')
        ->headers->get('Location');

    expect($location)->toBe('https://real-shop.test/u2-new-path/')
        ->and($location)->not->toContain('old-shop.test');
});

/* ══════════════════════════════════ 4. the two mail views that were dead ══ */

it('puts an absolute URL on the item link in a basket reminder', function () {
    /*
     * LIVE ON THE SHOP TODAY, AND NOT A DOMAIN-MOVE PROBLEM AT ALL.
     *
     * emails/cart-recovery.blade.php built its per-item link with Url::to(),
     * which returns a ROOT-RELATIVE path. Every basket reminder this shop has
     * ever sent carried `href="/product/foo/"`, which no mail client can
     * resolve — there is no origin in an inbox. The other two links on the
     * message ($cartUrl, $unsubscribeUrl) are handed in by OutboundSender
     * already absolute, which is why this one was the only one dead and why
     * nobody noticed.
     *
     * It is rendered by the scheduled sender, so there is no request to fall
     * back on either: external() is the only correct answer, not redirect().
     *
     * MUTATION, RUN: change the view back to Url::to(...) and this goes red —
     * `href="/product/rice-toner/"` does not start with the scheme.
     */
    config(['app.url' => 'https://real-shop.test']);

    $html = (string) (new App\Mail\CartRecoveryReminder(
        'Your basket is waiting',
        'You left something behind.',
        [[
            'name' => 'Rice Daily Moisturizing Toner 150ml',
            'slug' => 'rice-toner',
            'quantity' => 2,
            'unit_price' => 19900,
        ]],
        'https://real-shop.test/cart/',
        'https://real-shop.test/mail-preferences/cart/1',
    ))->render();

    expect($html)->toContain('href="https://real-shop.test/product/rice-toner/"')
        ->and($html)->not->toContain('href="/product/rice-toner/"');
});

it('builds no email link from a root-relative path in any mail view', function () {
    /*
     * THE INVARIANT, RATHER THAN THE TWO BUGS.
     *
     * cart-recovery was the one this lane was handed; emails/quiz-plan.blade.php
     * had the same defect through QuizController, which passed it
     * `Url::to('/shop/')` as the plan email's call to action — printed as an
     * href AND spelled out in full in the plain-text twin. Fixing the one that
     * was reported and leaving the other is how this comes back.
     *
     * Url::to() and Url::raw() return root-relative paths. They are right on a
     * page, where the browser has an origin to resolve them against, and dead in
     * an inbox. A mail view may only use Url::external()/Url::externalise(), or
     * print a URL it was handed.
     *
     * MUTATION, RUN: put Url::to( back into emails/cart-recovery.blade.php and
     * this names the file and the line.
     */
    $offenders = [];

    /*
     * The MAIL views only. resources/views/store/mail-preferences/ is a set of
     * web PAGES that happen to be about email — a visitor is standing on them
     * with an origin in the address bar — so Url::to() is correct there and
     * listing the directory here would have turned a real invariant into a
     * false alarm somebody eventually silences.
     */
    foreach ([
        'resources/views/emails',
        'resources/views/store/account/mail',
    ] as $dir) {
        $path = base_path($dir);

        if (! is_dir($path)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            /*
             * Blade comments are stripped FIRST, and as whole blocks rather
             * than line by line: these files explain at length why they do not
             * use Url::to(), and a line-wise check counted those explanations
             * as offences. Replaced with blank lines so the reported line
             * numbers still point at the real file.
             */
            $body = (string) preg_replace_callback(
                '/\{\{--.*?--\}\}/s',
                static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
                (string) file_get_contents($file->getPathname()),
            );

            foreach (explode("\n", $body) as $n => $line) {
                if (preg_match('/Url::(to|raw)\s*\(/', $line) === 1) {
                    $offenders[] = $dir.'/'.$file->getFilename().':'.($n + 1).' — '.trim($line);
                }
            }
        }
    }

    expect($offenders)->toBe([], "These mail views build a root-relative link, which is dead in an inbox:\n  "
        .implode("\n  ", $offenders));
});

it('absolutises the quiz plan email links without doubling the base path', function () {
    /*
     * QuizRoutineLink::map() is read by the quiz PAGE, where a root-relative
     * link is right, AND by the plan email, where it is dead. So the absolute
     * form is applied at the boundary into the email — QuizController — with
     * Url::externalise(), which prefixes the confirmed origin onto a path that
     * has ALREADY been through Url::to().
     *
     * The thing that can go wrong is spelling the base path twice, which is the
     * `/kbb-upgrade/kbb-upgrade/…` trap Url::redirect()'s own history records.
     * external() would do exactly that here, because it runs its argument
     * through to() a second time — so the two are not interchangeable and this
     * asserts the difference rather than assuming it.
     *
     * MUTATION, RUN: give Url::externalise() external()'s body —
     * `SiteUrl::externalOrigin() . self::to($url)`. RED, with the base path
     * spelled twice. The assertion below shows that doubling directly rather
     * than only asserting the good case, so the two helpers are held apart by
     * something a reader can see.
     */
    config(['app.url' => 'https://shop.example/kbb-upgrade', 'kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    $built = Url::to('/routines/acne/');
    expect($built)->toBe('/kbb-upgrade/routines/acne/');

    expect(Url::externalise($built))->toBe('https://shop.example/kbb-upgrade/routines/acne/')
        ->and(substr_count(Url::externalise($built), '/kbb-upgrade'))->toBe(1);

    // And this is what external() would have done with the same input, which is
    // why QuizController may not use it here.
    expect(substr_count(Url::external($built), '/kbb-upgrade'))->toBe(2);

    // And it leaves an already-absolute URL alone rather than prefixing it.
    expect(Url::externalise('https://other.test/x/'))->toBe('https://other.test/x/')
        ->and(Url::externalise(''))->toBe('');

    Url::forgetBase();
});

/* ══════════════════════════════════════════ 5. the helpers themselves ════ */

it('refuses to fall back to the request when APP_URL is unusable', function () {
    /*
     * external() answers the same thing whoever is asking, or it answers
     * nothing. An empty APP_URL yields a root-relative path — visibly broken,
     * and the safe direction to fail in — and must NEVER quietly become the
     * request's host, which is the failure mode this whole split exists to stop.
     *
     * MUTATION, RUN: `(SiteUrl::externalOrigin() ?: SiteUrl::origin(request()))`
     * in Url::external() — the "helpful" edit somebody makes when a blank
     * APP_URL produces relative links and the request is right there. RED.
     *
     * Note the explicit `request()`: written as a bare `SiteUrl::origin()` the
     * same edit stays GREEN under PHPUnit, because origin() will not reach for
     * a request while runningInConsole() is true. It is a live hole in
     * production either way, which is why the mutation is spelled the way that
     * makes the suite able to see it.
     */
    config(['app.url' => '']);

    app()->instance('request', Request::create('https://attacker.test/checkout'));

    expect(SiteUrl::externalOrigin())->toBe('')
        ->and(Url::external('/cart/'))->toBe('/cart/')
        ->and(Url::external('/cart/'))->not->toContain('attacker.test');

    // A host that is not a host is not an origin either.
    config(['app.url' => 'https://not a host/']);
    expect(Url::external('/cart/'))->not->toContain('not a host');
});

it('passes an absolute URL or a non-http scheme through both helpers untouched', function () {
    /*
     * Both helpers are handed operator input in places — EmailBranding::logoUrl()
     * passes a stored logo path through external() — so the guard that lets an
     * absolute URL past has to be on both, or a `https://cdn.example/logo.png`
     * comes out as `https://real-shop.test/https://cdn.example/logo.png`.
     */
    config(['app.url' => 'https://real-shop.test']);

    $request = Request::create('https://new-shop.test/x');

    foreach (['https://cdn.example/logo.png', 'mailto:hi@example.test', '//cdn.example/x.png'] as $absolute) {
        expect(Url::external($absolute))->toBe($absolute)
            ->and(Url::redirect($absolute, $request))->toBe($absolute);
    }
});
