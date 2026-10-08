<?php

declare(strict_types=1);

/*
 * Appearance -> Coming Soon page (Lane CS).
 *
 * The owner points kbeautybliss.com at the server while extrabeauty.ae is
 * still the live shop. Visitors on kbeautybliss.com must see a Coming Soon
 * page; he (signed in, or holding the secret link) must see the real shop to
 * place test orders; the admin, payment webhooks and returns, and Let's
 * Encrypt must never be blocked; extrabeauty.ae must not change by a byte.
 *
 * Each test names the defect it catches as it would look on the shop, and the
 * one-line change that turns it red (MUTATION).
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\AdminPathService;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\ComingSoon;
use App\Support\ComingSoonPage;
use App\Support\SiteHost;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ComingSoonAdminRoutes;

const CS_NEW = 'https://kbeautybliss.com';
const CS_OLD = 'https://extrabeauty.ae';

/** Write settings the way the screen does (autoload off) and drop every memo. */
function csSet(array $settings): void
{
    foreach ($settings as $key => $value) {
        if ($value === null) {
            Setting::query()->where('key', $key)->delete();

            continue;
        }

        Setting::query()->updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : $value, 'autoload' => false]);
    }

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    SiteHost::forget();
}

/** ON for kbeautybliss.com, extrabeauty.ae the main address, with a live preview secret. */
function csOn(array $extra = []): void
{
    csSet($extra + [
        ComingSoon::KEY_ON => '1',
        ComingSoon::KEY_SCOPE => 'host',
        ComingSoon::KEY_HOST => 'kbeautybliss.com',
        ComingSoon::KEY_SECRET => bin2hex(random_bytes(32)),
        SiteHost::KEY_CANONICAL => 'extrabeauty.ae',
        SiteHost::KEY_ALIASES => '',
        SiteHost::KEY_REDIRECT => '0',
    ]);
}

function csOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'CS '.$role, 'email' => 'cs-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

/** A genuine link for $host, minted with the stored secret. */
function csToken(string $host = 'kbeautybliss.com', int $hours = 24): string
{
    return ComingSoon::sign($host, time() + $hours * 3600, (string) Setting::map()[ComingSoon::KEY_SECRET]);
}

function csIsPage(\Illuminate\Testing\TestResponse $r): bool
{
    return $r->headers->get('X-KBB-Coming-Soon') === '1';
}

beforeEach(function () {
    config(['app.url' => CS_OLD]);
    csSet([ComingSoon::KEY_ON => null, ComingSoon::KEY_SCOPE => null, ComingSoon::KEY_HOST => null,
        SiteHost::KEY_CANONICAL => 'extrabeauty.ae', SiteHost::KEY_ALIASES => '', SiteHost::KEY_REDIRECT => '0']);
});

/* ═══════════════════════════════════════════ 1. the page and its headers ══ */

it('answers kbeautybliss.com with a 503 Coming Soon page, SEO-safe and uncacheable, while extrabeauty.ae shows the shop', function () {
    /*
     * The defect: the new domain shows the half-set-up shop to the first
     * visitor (or Googlebot) who finds it -- or shows the page with a 200, so
     * Google indexes "Something new is coming" as the shop's home page, or
     * Varnish keeps the page after the switch goes off.
     * MUTATION: delete the prependMiddleware(ComingSoonGate) line in
     * AppServiceProvider and the first expectation is a 200.
     */
    csOn();

    $page = $this->get(CS_NEW.'/');
    $page->assertStatus(503)
        ->assertHeader('Retry-After', '3600')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Content-Language', 'en');

    $cc = (string) $page->headers->get('Cache-Control');
    expect($cc)->toContain('no-store')->and($cc)->toContain('private')
        ->and($page->headers->has('Set-Cookie'))->toBeFalse();

    $html = (string) $page->getContent();
    expect($html)->toContain('<h1>Something new is coming</h1>')
        ->and($html)->toContain('Please visit us again in a few hours.')
        ->and($html)->toContain('K-Beauty<span>Bliss</span>')
        // Tiny and self-contained: no script, no stylesheet or font file, no other host.
        ->and(strlen($html))->toBeLessThan(10 * 1024)
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<link')
        ->and(preg_match('#(?:src|href)="(?:https?:)?//#', $html))->toBe(0)
        // Nothing from the shop's layout.
        ->and($html)->not->toContain('kbb.css')
        ->and($html)->not->toContain('csrf-token');

    // The www twin is the same address.
    expect(csIsPage($this->get('https://www.kbeautybliss.com/')))->toBeTrue();

    // The shop that is taking orders today is untouched.
    $shop = $this->get(CS_OLD.'/');
    $shop->assertOk();
    expect(csIsPage($shop))->toBeFalse()
        ->and((string) $shop->getContent())->not->toContain('Something new is coming');
});

it('speaks Arabic on /ar/ and to a browser that prefers Arabic, right to left', function () {
    // MUTATION: make ComingSoonPage::language() return 'en' and both are English.
    csOn();

    $ar = $this->get(CS_NEW.'/ar/');
    $ar->assertStatus(503)->assertHeader('Content-Language', 'ar');
    expect((string) $ar->getContent())->toContain('<html lang="ar" dir="rtl">')
        ->and((string) $ar->getContent())->toContain('شيء جديد قادم');

    $byHeader = $this->get(CS_NEW.'/product/anything/', ['Accept-Language' => 'ar-AE,ar;q=0.9,en;q=0.5']);
    expect((string) $byHeader->getContent())->toContain('dir="rtl"');

    $en = $this->get(CS_NEW.'/', ['Accept-Language' => 'en-GB,en;q=0.9,ar;q=0.3']);
    expect((string) $en->getContent())->toContain('<html lang="en" dir="ltr">');
});

it('hides every address in "every address" mode, and nothing at all while off', function () {
    // MUTATION: make ComingSoon::hides() ignore the scope and extrabeauty.ae answers 200.
    csOn([ComingSoon::KEY_SCOPE => 'all']);

    expect(csIsPage($this->get(CS_OLD.'/')))->toBeTrue()
        ->and(csIsPage($this->get('https://staging.example.test/')))->toBeTrue();

    csSet([ComingSoon::KEY_ON => '0']);
    expect(csIsPage($this->get(CS_NEW.'/')))->toBeFalse()
        ->and(csIsPage($this->get(CS_OLD.'/')))->toBeFalse();
});

it('ships off: no setting written means every address shows the shop', function () {
    // The migration writes nothing; absent must read as off.
    // MUTATION: default KEY_ON to '1' in ComingSoon::on() and this is a 503.
    expect(Setting::query()->where('key', 'like', 'coming_soon_%')->count())->toBe(0);
    expect(csIsPage($this->get(CS_NEW.'/')))->toBeFalse();
});

/* ═══════════════════════════════════ 2. who still sees the shop there ══ */

it('lets a signed-in admin see the real shop on the hidden address, with the notice, never cached', function () {
    /*
     * The defect: the owner opens kbeautybliss.com to test and gets the
     * Coming Soon page he switched on -- or gets the shop, and Varnish caches
     * that copy and serves it to every visitor.
     * MUTATION: drop the appendMiddlewareToGroup(ComingSoonAdminPass) line and
     * the admin gets the 503.
     */
    csOn();

    $r = $this->actingAs(csOwner(), 'admin')->withCookie((string) config('session.cookie'), 'x')->get(CS_NEW.'/');
    $r->assertOk();

    expect(csIsPage($r))->toBeFalse()
        ->and((string) $r->getContent())->toContain('Coming Soon is ON for visitors on this address')
        ->and(substr_count((string) $r->getContent(), 'data-kbb-coming-soon-notice'))->toBe(1)
        ->and((string) $r->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $r->headers->get('Cache-Control'))->toContain('private')
        ->and((string) $r->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('answers a visitor who has a session (a shopper, not an admin) with the page, before any controller runs', function () {
    /*
     * The defect: "has a session cookie" taken as "is an admin", so anyone who
     * once opened the admin login page sees the shop -- or the gate decides
     * after the controller, so a POST to the checkout places an order first.
     * MUTATION: in ComingSoonAdminPass return $next($request) for every
     * PENDING request and the order count is no longer the page's 503.
     */
    csOn();
    $cookie = (string) config('session.cookie');

    expect(csIsPage($this->withCookie($cookie, 'x')->get(CS_NEW.'/')))->toBeTrue();

    // A web-group route that records whether its controller ran.
    $ran = 0;
    Route::middleware('web')->post('/cs-probe-write', function () use (&$ran) {
        $ran++;

        return 'written';
    });
    Route::getRoutes()->refreshNameLookups();

    $post = $this->withCookie($cookie, 'x')->post(CS_NEW.'/cs-probe-write');
    expect(csIsPage($post))->toBeTrue()->and($post->getStatusCode())->toBe(503)
        ->and($ran)->toBe(0);


    // An address no route matches never reaches the web group: still the page,
    // never the shop's own 404 or a redirect it would announce.
    expect(csIsPage($this->withCookie($cookie, 'x')->get(CS_NEW.'/no-such-page-anywhere/')))->toBeTrue()
        ->and(csIsPage($this->get(CS_NEW.'/no-such-page-anywhere/')))->toBeTrue();

    // The same POST from a signed-in admin does run, which proves the probe can.
    $this->actingAs(csOwner(), 'admin')->withCookie($cookie, 'x')->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->post(CS_NEW.'/cs-probe-write')->assertOk();
    expect($ran)->toBe(1);
});

it('lets the secret preview link through: it sets a Secure HttpOnly Lax cookie and drops itself from the address', function () {
    // MUTATION: make tokenValid() return false and the link answers 503.
    csOn();
    $token = csToken();

    $r = $this->get(CS_NEW.'/shop/?'.ComingSoon::QUERY.'='.$token.'&utm=a');
    $r->assertStatus(302)->assertHeader('Location', CS_NEW.'/shop/?utm=a');
    expect((string) $r->headers->get('Cache-Control'))->toContain('no-store');

    $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === ComingSoon::COOKIE);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getValue())->toBe($token)
        ->and($cookie->getExpiresTime())->toBe((int) strstr($token, '.', true));

    $shop = $this->withUnencryptedCookie(ComingSoon::COOKIE, $token)->get(CS_NEW.'/');
    $shop->assertOk();
    expect((string) $shop->getContent())->toContain('Preview — Coming Soon is ON for visitors on this address')
        ->and((string) $shop->headers->get('Cache-Control'))->toContain('no-store');
});

it('refuses an expired, rotated, forged, wrong-host or malformed preview token', function () {
    /*
     * The defect: a link that outlives the owner's say-so -- the old link,
     * still in a WhatsApp chat, shows the shop after he pressed "New link".
     * MUTATION: drop the expiry check in tokenValid() and the expired token
     * shows the shop; take the secret out of sign() and the rotated one does.
     */
    csOn();
    $secret = (string) Setting::map()[ComingSoon::KEY_SECRET];
    $sees = fn (string $t) => ! csIsPage($this->withUnencryptedCookie(ComingSoon::COOKIE, $t)->get(CS_NEW.'/'));

    $good = csToken();
    expect($sees($good))->toBeTrue();

    $expired = ComingSoon::sign('kbeautybliss.com', time() - 5, $secret);
    $wrongHost = ComingSoon::sign('other.example.test', time() + 3600, $secret);
    $tooFar = ComingSoon::sign('kbeautybliss.com', time() + 400 * 86400, $secret);
    $forged = substr($good, 0, -2).(str_ends_with($good, 'AA') ? 'BB' : 'AA');
    $stretched = (time() + 7200).strstr($good, '.');   // a later expiry on the same MAC

    foreach (['expired' => $expired, 'wrong host' => $wrongHost, 'too far ahead' => $tooFar, 'forged' => $forged,
        'stretched' => $stretched, 'malformed' => 'abc', 'empty' => ''] as $label => $bad) {
        expect($sees($bad))->toBeFalse($label.' token showed the shop');
        expect(csIsPage($this->get(CS_NEW.'/?'.ComingSoon::QUERY.'='.urlencode($bad))))->toBeTrue($label.' link was accepted');
    }

    // "New link" = a new secret: the good token dies with the old one.
    csSet([ComingSoon::KEY_SECRET => bin2hex(random_bytes(32))]);
    expect($sees($good))->toBeFalse();

    // No secret stored at all: nothing is valid, whatever it is signed with.
    csSet([ComingSoon::KEY_SECRET => null]);
    expect(ComingSoon::tokenValid(ComingSoon::sign('kbeautybliss.com', time() + 60, ''), 'kbeautybliss.com', Setting::map()))->toBeFalse();
});

/* ═══════════════════════════════════════════════ 3. never blocked ══ */

it('never blocks the paths the switch depends on: certificate, webhooks, payment returns, admin, health, files', function () {
    /*
     * The defect, one per line: Let's Encrypt cannot verify kbeautybliss.com
     * and Cloudways issues no certificate; Stripe/Tabby/Tamara webhooks get a
     * 503 and test orders never turn paid; a shopper returning from Tabby
     * lands on "Coming soon"; the owner cannot reach his admin; the updater's
     * health probe fails and every package rolls itself back.
     * MUTATION: delete any one line of ComingSoon::ALLOWED_PREFIXES and its
     * row here is the Coming Soon page.
     */
    csOn();
    $admin = AdminPathService::current();

    $get = [
        '/.well-known/acme-challenge/cs-probe', '/.well-known/apple-developer-merchantid-domain-association',
        '/.well-known/security.txt', '/up', '/_kbb-health', '/checkout/success?order=1001', '/checkout/pending?order=1001',
        '/ar/checkout/success?order=1001', '/'.$admin.'/login', '/'.$admin, '/admin-api/coming-soon',
        '/storage/cs/probe.png', '/build/assets/app.css', '/img-cache/cs/probe-300.webp', '/uploads/probe.jpg',
        '/wp-content/uploads/2024/01/probe.jpg', '/favicon.ico', '/site-app/icons/icon-192.png',
        '/my-account/reset/1/cs-reset-probe', '/mail/font/outfit-latin.woff2', '/email/u/cs-probe',
        '/api/products', '/api/settings', '/sw.js', '/manifest.webmanifest',
    ];

    foreach ($get as $path) {
        expect(csIsPage($this->get(CS_NEW.$path)))->toBeFalse('GET '.$path.' was blocked');
    }

    $post = ['/api/payments/webhook/stripe/cs-probe', '/api/payments/webhook/tabby/cs-probe', '/api/payments/webhook/tamara/cs-probe',
        '/checkout/card/paid', '/checkout/card/abandon', '/checkout/restore-basket', '/'.$admin.'/login', '/import-chain/continue',
        '/api/checkout/session'];

    foreach ($post as $path) {
        expect(csIsPage($this->post(CS_NEW.$path, [])))->toBeFalse('POST '.$path.' was blocked');
    }
});

it('allows every payment, webhook, wallet and health route the router actually registers', function () {
    /*
     * Built from the live route table, not from a list typed beside it: a
     * gateway route added or moved later and not covered is red here.
     */
    $names = ['payments.webhook', 'checkout.cardConfirmed', 'checkout.cardAbandoned', 'checkout.success', 'checkout.pending',
        'checkout.restore-basket', 'kbb.health', 'import.chain.continue', 'admin.login', 'admin.login.post', 'admin'];
    $uris = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        $uri = '/'.ltrim($route->uri(), '/');

        if (in_array($name, $names, true) || str_starts_with($uri, '/.well-known/') || str_starts_with($uri, '/api/payments/')
            || str_starts_with($uri, '/checkout/card/') || str_contains($uri, 'stripe/connect') || str_contains($uri, 'instagram/callback')) {
            $uris[$name ?: $uri] = (string) preg_replace('/\{[^}]+\}/', 'x', $uri);
        }
    }

    expect(count($uris))->toBeGreaterThanOrEqual(12);

    foreach ($uris as $label => $uri) {
        expect(ComingSoon::pathAllowed($uri))->toBeTrue($label.' ('.$uri.') is not allowed through');
    }

    // And the shop itself is not.
    foreach (['/', '/shop/', '/product/x/', '/collections/x/', '/brands/x/', '/cart/', '/checkout/', '/my-account/',
        '/sitemap.xml', '/llms.txt', '/ar/', '/blog/x/', '/x', '/offline'] as $uri) {
        expect(ComingSoon::pathAllowed($uri))->toBeFalse($uri.' would show the shop');
    }
});

it('keeps a custom admin address and the owner app reachable', function () {
    // MUTATION: drop the AdminPathService branch of pathAllowed() and the custom path is blocked.
    $prop = new ReflectionProperty(AdminPathService::class, 'memo');
    $prop->setValue(null, 'back-office-cs');

    try {
        expect(ComingSoon::pathAllowed('/back-office-cs'))->toBeTrue()
            ->and(ComingSoon::pathAllowed('/back-office-cs/login'))->toBeTrue()
            ->and(ComingSoon::pathAllowed('/back-office-csx'))->toBeFalse()
            ->and(ComingSoon::pathAllowed('/admin'))->toBeFalse();
    } finally {
        AdminPathService::forgetMemo();
    }

    $owner = new ReflectionProperty(\App\Services\OwnerApp\OwnerAppPath::class, 'memo');
    $owner->setValue(null, 'my-shop-app');

    try {
        expect(ComingSoon::pathAllowed('/my-shop-app'))->toBeTrue()
            ->and(ComingSoon::pathAllowed('/my-shop-app/api/state'))->toBeTrue();
    } finally {
        \App\Services\OwnerApp\OwnerAppPath::forgetMemo();
    }
});

it('serves robots.txt as disallow-all on the hidden address and hides its sitemap, leaving extrabeauty.ae alone', function () {
    // MUTATION: drop the robots.txt branch in ComingSoonGate and the hidden robots.txt is the 503.
    csOn();

    $robots = $this->get(CS_NEW.'/robots.txt');
    $robots->assertOk();
    expect((string) $robots->getContent())->toBe("User-agent: *\nDisallow: /\n")
        ->and((string) $robots->headers->get('Cache-Control'))->toContain('no-store');

    expect(csIsPage($this->get(CS_NEW.'/sitemap.xml')))->toBeTrue();

    $live = $this->get(CS_OLD.'/robots.txt');
    expect((string) $live->getContent())->not->toBe("User-agent: *\nDisallow: /\n");
});

/* ═════════════════════════════════════════ 4. with the domain switch ══ */

it('shows the page instead of forwarding, and cannot loop, when kbeautybliss.com is a forwarded old address', function () {
    /*
     * The defect: CanonicalHost runs first and 301s kbeautybliss.com to
     * extrabeauty.ae, so the owner's "Coming Soon on the new domain" never
     * appears -- or the two bounce a browser between hosts.
     * MUTATION: register ComingSoonGate before CanonicalHost is prepended
     * (so it runs after it) and the first request is a 301.
     */
    csOn([SiteHost::KEY_CANONICAL => 'extrabeauty.ae', SiteHost::KEY_ALIASES => 'kbeautybliss.com', SiteHost::KEY_REDIRECT => '1']);

    $r = $this->get(CS_NEW.'/shop/');
    expect($r->getStatusCode())->toBe(503)->and(csIsPage($r))->toBeTrue();

    // A preview holder is forwarded by CanonicalHost, once, to a host that is not hidden.
    $hop = $this->withUnencryptedCookie(ComingSoon::COOKIE, csToken())->get(CS_NEW.'/shop/');
    expect($hop->getStatusCode())->toBe(301)->and((string) $hop->headers->get('Location'))->toStartWith(CS_OLD.'/');
    expect($this->get((string) $hop->headers->get('Location'))->getStatusCode())->toBe(200);

    // After step 6: kbeautybliss.com is the main address and still hidden; the old address is served.
    csOn([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', SiteHost::KEY_REDIRECT => '0']);
    expect($this->get(CS_NEW.'/')->getStatusCode())->toBe(503)
        ->and($this->get(CS_OLD.'/')->getStatusCode())->toBe(200);

    // Every address hidden and forwarding on: an alias answers the page, never a redirect chain.
    csOn([ComingSoon::KEY_SCOPE => 'all', SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', SiteHost::KEY_REDIRECT => '1']);
    expect($this->get(CS_OLD.'/shop/')->getStatusCode())->toBe(503)
        ->and($this->get(CS_NEW.'/shop/')->getStatusCode())->toBe(503);
});

it('warns when forwarding would send the old address\'s visitors into the Coming Soon page', function () {
    csOn([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', SiteHost::KEY_REDIRECT => '1']);
    $status = ComingSoon::status(Setting::map());

    expect($status['line'])->toContain('ON — kbeautybliss.com (and www.kbeautybliss.com) shows the Coming Soon page')
        ->and($status['line'])->toContain('extrabeauty.ae')
        ->and(implode(' ', $status['warnings']))->toContain('Forward permanently is ON');
});

it('puts the Coming Soon page on and off as steps of the Domain switch installer', function () {
    csOn();
    $state = app(\App\Services\DomainMove\DomainSwitch::class)->state(\Illuminate\Http\Request::create(CS_OLD.'/admin'));

    expect($state['coming_soon'])->toBe(['on' => true, 'line' => 'Coming Soon page: ON for kbeautybliss.com']);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/domain-switch-screen.blade.php'));
    // Lane DW2: the wizard became a numbered installer. Coming Soon is two of
    // its steps -- on before the DNS change, off after the test orders -- each
    // with its own button, and a link to this screen for the words.
    $keys = array_keys(\App\Services\DomainMove\SwitchInstaller::STEPS);
    expect($screen)->toContain('data-screen="comingsoon"')
        ->and($screen)->toContain("btn('coming_soon_on'")
        ->and($screen)->toContain("btn('coming_soon_off'")
        ->and(array_search('cs_on', $keys, true))->toBeLessThan(array_search('dns_records', $keys, true))
        ->and(array_search('cs_off', $keys, true))->toBeGreaterThan(array_search('tests', $keys, true))
        ->and(array_search('cs_off', $keys, true))->toBeLessThan(array_search('forward', $keys, true));
});

/* ═════════════════════════════════════════════ 5. nothing else moves ══ */

it('leaves extrabeauty.ae byte-identical and query-identical, off or on for another address', function () {
    /*
     * The defect: the gate adds a header, a query or a settings read to every
     * page of the live shop.
     * MUTATION: make the gate set X-Robots-Tag on every response, or read
     * SettingsService instead of Setting::map(), and one of these differs.
     */
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $product = \App\Models\Product::query()->visible()->firstOrFail();
    $paths = ['/', '/product/'.$product->slug.'/'];
    $strip = fn (string $html) => (string) preg_replace('/name="csrf-token" content="[^"]*"|name="_token" value="[^"]*"|nonce="[^"]*"/', '', $html);

    $render = function () use ($paths, $strip) {
        $out = [];

        foreach ($paths as $p) {
            $this->get(CS_OLD.$p);    // warm
            SettingsService::forgetMemo();
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $r = $this->get(CS_OLD.$p);
            $headers = collect($r->headers->all())->except(['date', 'set-cookie'])->toArray();
            $out[$p] = [$r->getStatusCode(), $strip((string) $r->getContent()), $headers, $n];
        }

        return $out;
    };

    $absent = $render();
    csSet([ComingSoon::KEY_ON => '0', ComingSoon::KEY_HOST => 'kbeautybliss.com']);
    $off = $render();
    csOn();
    $onElsewhere = $render();

    foreach ($paths as $p) {
        expect($off[$p][0])->toBe(200);
        expect($off[$p][1])->toBe($absent[$p][1], $p.' HTML changed with the feature off');
        expect($onElsewhere[$p][1])->toBe($absent[$p][1], $p.' HTML changed with the page on for kbeautybliss.com');
        expect($onElsewhere[$p][2])->toBe($absent[$p][2], $p.' headers changed');
        expect($onElsewhere[$p][3])->toBe($absent[$p][3], $p.' query count changed');
    }
});

it('keeps every key out of the autoloaded settings map the shop reads on every page', function () {
    // MUTATION: write a key with autoload true in the controller and it is in all().
    ComingSoonAdminRoutes::wire($this->app);
    $this->actingAs(csOwner(), 'admin')->postJson(CS_OLD.'/admin-api/coming-soon', ['on' => true])->assertOk();

    $autoloaded = array_keys(app(SettingsService::class)->all());
    expect(array_filter($autoloaded, fn ($k) => str_starts_with((string) $k, 'coming_soon_')))->toBe([]);
});

/* ═════════════════════════════════════ 6. escaping and validation ══ */

it('escapes every word the owner types and prints only checked colours, pictures and links', function () {
    /*
     * The defect: a heading with markup in it runs script on the new domain,
     * a colour breaks out of the stylesheet, a picture loads from somebody
     * else's server, an Instagram setting becomes a javascript: link.
     * MUTATION: print $t['heading'] without $e() and the <script> survives.
     */
    csOn([
        ComingSoon::KEY_CONTENT => [
            'en' => ['heading' => '<script>alert(1)</script>Soon', 'message' => '"><img src=x onerror=alert(2)>', 'small' => "a\u{2028}b"],
            'bg' => 'red;}body{display:none', 'bg_image' => "/x.png');background:url('//evil.example/a.png",
            'show_instagram' => true, 'show_whatsapp' => true,
        ],
        'social_instagram' => 'javascript:alert(3)',
        'header_settings' => json_encode(['logo_text' => '<b>K</b>', 'logo_colour' => 'red;x:y', 'logo_accent_col' => '#123456']),
    ]);

    $html = (string) $this->get(CS_NEW.'/')->getContent();

    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;Soon')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('display:none')
        ->and($html)->not->toContain('evil.example')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('<b>K</b>')
        ->and($html)->not->toContain('red;x:y')
        ->and($html)->toContain('#123456')
        ->and($html)->toContain('background:radial-gradient')
        ->and($html)->toContain(ComingSoon::DEFAULT_BG);
});

it('takes a bare host name only: no scheme, path, port, IP or single label; IDN becomes ASCII', function () {
    // MUTATION: drop the character check in cleanHost() and the scheme/path/port forms are stored.
    foreach (['https://kbeautybliss.com', 'kbeautybliss.com/shop', 'kbeautybliss.com:8443', 'user@kbeautybliss.com',
        '127.0.0.1', 'localhost', 'kbeauty bliss.com', '', 'kbeautybliss.com?x', '[::1]', '-bad-.com'] as $bad) {
        expect(ComingSoon::cleanHost($bad))->toBeNull('accepted '.$bad);
    }

    expect(ComingSoon::cleanHost(' WWW.KBeautyBliss.com. '))->toBe('kbeautybliss.com')
        ->and(ComingSoon::cleanHost('extrabeauty.ae'))->toBe('extrabeauty.ae');

    if (function_exists('idn_to_ascii')) {
        expect(ComingSoon::cleanHost('bücher.example'))->toBe('xn--bcher-kva.example');
    }

    expect(ComingSoon::imagePath('/storage/media/bg.webp'))->toBe('/storage/media/bg.webp')
        ->and(ComingSoon::imagePath('https://kbeautybliss.com/storage/a.png', ['kbeautybliss.com']))->toBe('/storage/a.png')
        ->and(ComingSoon::imagePath('https://evil.example/a.png', ['kbeautybliss.com']))->toBe('')
        ->and(ComingSoon::imagePath('//evil.example/a.png'))->toBe('')
        ->and(ComingSoon::imagePath('/a/../b.png'))->toBe('')
        ->and(ComingSoon::imagePath("/a.png'"))->toBe('')
        ->and(ComingSoon::imagePath('/a.php'))->toBe('');
});

it('saves through the screen\'s endpoint, refuses bad input with nothing written, and the issued link works end to end', function () {
    ComingSoonAdminRoutes::wire($this->app);
    $owner = csOwner();

    $bad = $this->actingAs($owner, 'admin')->postJson(CS_OLD.'/admin-api/coming-soon', [
        'on' => true, 'scope' => 'host', 'host' => 'https://kbeautybliss.com/x',
        'content' => ['bg' => 'pink', 'en' => ['heading' => str_repeat('a', 81)]],
    ]);
    $bad->assertStatus(422);
    expect(array_keys($bad->json('fields')))->toEqualCanonicalizing(['host', 'content.bg', 'content.en.heading'])
        ->and(Setting::query()->where('key', ComingSoon::KEY_ON)->exists())->toBeFalse();

    $this->postJson(CS_OLD.'/admin-api/coming-soon', ['scope' => 'host', 'zap' => 1])->assertStatus(422);

    $ok = $this->postJson(CS_OLD.'/admin-api/coming-soon', [
        'on' => true, 'scope' => 'host', 'host' => 'www.kbeautybliss.com', 'hours' => 24,
        'content' => ['en' => ['heading' => 'Opening soon'], 'ar' => [], 'bg' => '#fde7ee'],
    ]);
    $ok->assertOk();
    expect($ok->json('config.host'))->toBe('kbeautybliss.com')
        ->and($ok->json('config.content.bg'))->toBe('#FDE7EE')
        ->and($ok->json('status.line'))->toStartWith('ON — kbeautybliss.com')
        ->and($ok->json('link.host'))->toBe('kbeautybliss.com');

    // The page says what was saved, and the link the screen shows lets a phone in.
    expect((string) $this->get(CS_NEW.'/')->getContent())->toContain('<h1>Opening soon</h1>');

    $url = (string) $ok->json('link.url');
    expect($url)->toStartWith(CS_NEW.'/?'.ComingSoon::QUERY.'=');
    $token = (string) substr($url, strpos($url, '=') + 1);
    $this->get($url)->assertStatus(302);
    expect(csIsPage($this->withUnencryptedCookie(ComingSoon::COOKIE, $token)->get(CS_NEW.'/')))->toBeFalse();

    // "New link" kills it.
    $this->actingAs($owner, 'admin')->postJson(CS_OLD.'/admin-api/coming-soon/link')->assertOk();
    expect(csIsPage($this->withUnencryptedCookie(ComingSoon::COOKIE, $token)->get(CS_NEW.'/')))->toBeTrue();

    // The preview draws the unsaved words, escaped, without saving them.
    $p = $this->actingAs($owner, 'admin')->postJson(CS_OLD.'/admin-api/coming-soon/preview', ['lang' => 'ar', 'content' => ['ar' => ['heading' => 'تجربة <i>']]]);
    expect($p->json('html'))->toContain('تجربة &lt;i&gt;')->and($p->json('bytes'))->toBeLessThan(10240);
    expect((string) $this->get(CS_NEW.'/ar/')->getContent())->not->toContain('تجربة');
});

/* ═════════════════════════════════════════════════ 7. who may change it ══ */

it('gives the screen its own owner-only capability, failing closed for everyone else', function () {
    // MUTATION: map admin-api/coming-soon to wabutton.manage and the manager gets 200.
    ComingSoonAdminRoutes::wire($this->app);

    foreach ([['GET', 'admin-api/coming-soon'], ['POST', 'admin-api/coming-soon'], ['POST', 'admin-api/coming-soon/link'],
        ['POST', 'admin-api/coming-soon/preview'], ['GET', 'admin-api/coming-soon/preview']] as [$m, $uri]) {
        expect(AdminCapabilities::forPath($m, $uri))->toBe('comingsoon.manage');
    }

    expect(AdminCapabilities::CAPABILITIES['comingsoon.manage'])->toBe(['owner']);

    foreach (['manager', 'editor'] as $role) {
        $this->actingAs(csOwner($role), 'admin')->getJson(CS_OLD.'/admin-api/coming-soon')->assertForbidden();
        $this->actingAs(csOwner($role), 'admin')->postJson(CS_OLD.'/admin-api/coming-soon', ['on' => true])->assertForbidden();
    }

    expect(Setting::query()->where('key', ComingSoon::KEY_ON)->exists())->toBeFalse();

    auth('admin')->logout();
    $guest = $this->getJson(CS_OLD.'/admin-api/coming-soon');
    expect($guest->getStatusCode())->toBeIn([401, 403]);

    $this->actingAs(csOwner(), 'admin')->getJson(CS_OLD.'/admin-api/coming-soon')->assertOk()
        ->assertJsonPath('emergency', 'php artisan kbb:coming-soon off');
});

/* ═══════════════════════════════════════════════ 8. the emergency switch ══ */

it('turns off from the shell with php artisan kbb:coming-soon off, and the next request shows the shop', function () {
    // MUTATION: have the command skip the write and the page is still served.
    csOn();
    expect(csIsPage($this->get(CS_NEW.'/')))->toBeTrue();

    expect(Artisan::call('kbb:coming-soon', ['action' => 'off']))->toBe(0)
        ->and(Artisan::output())->toContain('OFF — every address shows the shop.');

    expect(Setting::query()->where('key', ComingSoon::KEY_ON)->value('value'))->toBe('0')
        ->and(csIsPage($this->get(CS_NEW.'/')))->toBeFalse();

    expect(Artisan::call('kbb:coming-soon', ['action' => 'on']))->toBe(0);
    expect(csIsPage($this->get(CS_NEW.'/')))->toBeTrue();

    expect(Artisan::call('kbb:coming-soon', ['action' => 'sideways']))->not->toBe(0);
});

it('renders the page within budget: no query beyond the settings map, under 10 KB in both languages', function () {
    csOn();
    $this->get(CS_NEW.'/');   // warm the map

    Setting::flushMap();
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    $r = $this->get(CS_NEW.'/');

    // One read of the settings table at most (the map, cold); a warm map is zero.
    expect($n)->toBeLessThanOrEqual(1)
        ->and(strlen(ComingSoonPage::html(ComingSoon::content(Setting::map()), 'ar', Setting::map())))->toBeLessThan(10240);
});
