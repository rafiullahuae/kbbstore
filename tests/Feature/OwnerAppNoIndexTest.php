<?php

declare(strict_types=1);

/*
 * "Make sure the sync etc must be OFF for any search engine" (the owner,
 * 5 October) — Lane OA3. Verified, not assumed: every private surface is
 * crawled and must answer `X-Robots-Tag: noindex, nofollow, noarchive` as a
 * HEADER, and every file written for a crawler is rendered with both secrets
 * moved to unusual values and must name neither.
 *
 * DEFECT this pins, as it was on the shop: the admin login page carried only
 * <meta name="robots" content="noindex,nofollow">; /admin-api JSON, the
 * console, the updates screen and the password-email token pages carried no
 * robots instruction at all; and on a staging copy (KBB_NOINDEX) the owner
 * app's own header was rewritten to the weaker `noindex, nofollow`.
 */

use App\Http\Controllers\OwnerApp\LiveController;
use App\Http\Middleware\PrivateNoIndex;
use App\Services\AdminPathService;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\Seo\IndexNow;
use App\Support\PrivateSurfaces;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\OwnerAppRoutes as OA;

const OA3_ROBOTS = 'noindex, nofollow, noarchive';

beforeEach(function () {
    OA::wire($this->app);
});

function oa3Rewire(): void
{
    $router = Route::getFacadeRoot();
    $kept = new RouteCollection();
    foreach ($router->getRoutes() as $r) {
        if (! str_starts_with((string) $r->getName(), 'owner-app.')) {
            $kept->add($r);
        }
    }
    $router->setRoutes($kept);
    Route::middleware('web')->group(base_path('routes/owner-app.php'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

it('is registered once, on the global stack, by a provider a package can ship', function () {
    // MUTATION: delete the pushMiddleware() call in OwnerAppServiceProvider.
    $global = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->getGlobalMiddleware();
    expect(array_count_values($global)[PrivateNoIndex::class] ?? 0)->toBe(1);
});

it('sends the header on every answer the owner app gives, on the shop host', function () {
    // MUTATION: drop the X-Robots-Tag line from OwnerAppHeaders AND the
    // owner-app.* clause from PrivateNoIndex — either alone is still green,
    // which is the point of two layers; both together turn every row red.
    $owner = OA::admin();
    OA::member($owner);
    [$c] = OA::enrol($this);
    $base = OA::base();

    $rows = [
        'shell' => fn () => $this->flushHeaders()->get($base),
        'manifest' => fn () => $this->flushHeaders()->get($base.'/manifest.webmanifest'),
        'sw.js' => fn () => $this->flushHeaders()->get($base.'/sw.js'),
        'api/state' => fn () => $this->flushHeaders()->getJson($base.'/api/state'),
        'api 401' => fn () => $this->flushHeaders()->withHeaders(['X-OA' => '1'])->getJson($base.'/api/orders'),
        'api 200' => fn () => OA::get($this, 'orders', $c),
        'cross-site 403' => fn () => $this->flushHeaders()->withHeaders(['X-OA' => '1', 'Origin' => 'https://evil.example'])->postJson($base.'/api/enrol', []),
        'catch-all 404' => fn () => $this->flushHeaders()->get($base.'/icons/nope.png'),
        'api 404' => fn () => $this->flushHeaders()->getJson($base.'/api/nope'),
        'POST on a GET path' => fn () => $this->flushHeaders()->withHeaders(['X-OA' => '1'])->postJson($base.'/sw.js', []),
    ];

    foreach ($rows as $what => $call) {
        $r = $call();
        expect($r->headers->get('X-Robots-Tag'))->toBe(OA3_ROBOTS, $what)
            ->and($r->headers->get('Referrer-Policy'))->toBe('no-referrer', $what);
    }

    // The worker's scope stays the app's own path: the manifest says so and
    // sw.js's Service-Worker-Allowed names that path and nothing wider.
    expect($this->flushHeaders()->get($base.'/manifest.webmanifest')->json('scope'))->toBe($base.'/')
        ->and($this->flushHeaders()->get($base.'/sw.js')->headers->get('Service-Worker-Allowed'))->toBe($base.'/');

    $this->app->bind(LiveController::class, fn () => throw new RuntimeException('boom'));
    $r = OA::get($this, 'changes?after=1', $c)->assertStatus(500);
    expect($r->headers->get('X-Robots-Tag'))->toBe(OA3_ROBOTS)->and($r->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('keeps the strong header on the owner app on a staging copy too', function () {
    // DEFECT: NoIndexStaging (web group, runs outside the route's own
    // OwnerAppHeaders) rewrote it to `noindex, nofollow` with KBB_NOINDEX set.
    // MUTATION: remove the owner-app.* clause from PrivateNoIndex.
    putenv('KBB_NOINDEX=1');
    $_ENV['KBB_NOINDEX'] = '1';
    $_SERVER['KBB_NOINDEX'] = '1';
    try {
        $r = $this->get(OA::base());
    } finally {
        putenv('KBB_NOINDEX');
        unset($_ENV['KBB_NOINDEX'], $_SERVER['KBB_NOINDEX']);
    }
    expect($r->headers->get('X-Robots-Tag'))->toBe(OA3_ROBOTS);
});

it('sends the header on the admin login, console, API, updates and every 4xx under them', function () {
    // DEFECT: the login page had only a meta tag; /admin-api JSON had nothing.
    // MUTATION: drop the admin/admin.* and auth:admin clauses from
    // PrivateNoIndex — the login, console and updates rows go red; drop
    // 'admin-api' from PrivateSurfaces::ROOTS and the API rows do.
    $owner = OA::admin();
    $admin = '/'.trim(AdminPathService::current(), '/');

    $guest = [
        'login page' => fn () => $this->get($admin.'/login'),
        'login POST, wrong password' => fn () => $this->post($admin.'/login', ['email' => 'owner@example.com', 'password' => 'wrong']),
        'console, signed out (redirect)' => fn () => $this->get($admin),
        'updates, signed out' => fn () => $this->get($admin.'/updates'),
        'admin-api, signed out' => fn () => $this->getJson('/admin-api/owner-app'),
        'admin-api, unknown path' => fn () => $this->getJson('/admin-api/no-such-endpoint'),
    ];
    foreach ($guest as $what => $call) {
        expect($call()->headers->get('X-Robots-Tag'))->toBe(OA3_ROBOTS, $what);
    }

    $signedIn = [
        'console' => fn () => $this->actingAs($owner, 'admin')->get($admin),
        'updates' => fn () => $this->actingAs($owner, 'admin')->get($admin.'/updates'),
        'admin-api 200' => fn () => $this->actingAs($owner, 'admin')->getJson('/admin-api/owner-app'),
        'admin-api 422' => fn () => $this->actingAs($owner, 'admin')->postJson('/admin-api/owner-app/address', ['path' => 'nope']),
    ];
    foreach ($signedIn as $what => $call) {
        expect($call()->headers->get('X-Robots-Tag'))->toBe(OA3_ROBOTS, $what);
    }
});

it('sends the header on the pages a password email links to, and on the internal endpoints', function () {
    // MUTATION: empty PrivateSurfaces::TOKEN_PAGES — the four token rows go red.
    $rows = [
        '/my-account/forgot' => fn () => $this->get('/my-account/forgot'),
        '/my-account/reset/{id}/{token}/' => fn () => $this->get('/my-account/reset/1/not-a-real-token/'),
        '/my-account/welcome/{token}/' => fn () => $this->get('/my-account/welcome/not-a-real-token/'),
        '/my-account/verify/{id}/{hash}/' => fn () => $this->get('/my-account/verify/1/abc/'),
        '/import-chain/continue' => fn () => $this->post('/import-chain/continue'),
        '/_kbb-health' => fn () => $this->get('/_kbb-health'),
        '/up' => fn () => $this->get('/up'),
    ];
    foreach ($rows as $what => $call) {
        expect($call()->headers->get('X-Robots-Tag'))->toBe(OA3_ROBOTS, $what);
    }
});

it('leaves the public shop exactly as it was, and answers a wrong spelling of the secret like any 404', function () {
    // Rule 1: nothing the owner did not ask about moves. And no oracle: if
    // the middleware matched the app by covers() rather than by its route,
    // /{APP} (upper case — a 404 the router gives the shop) would carry the
    // header and a plain 404 would not, confirming a guess.
    // MUTATION: make PrivateNoIndex call PrivateSurfaces::isPrivatePath().
    foreach (['/', '/shop', '/cart', '/my-account', '/robots.txt'] as $public) {
        expect($this->get($public)->headers->get('X-Robots-Tag'))->toBeNull($public);
    }

    $miss = $this->get('/zz_never_was_a_page');
    $upper = $this->get('/'.strtoupper((string) OwnerAppPath::current()));
    expect($upper->status())->toBe($miss->status())
        ->and($upper->headers->get('X-Robots-Tag'))->toBe($miss->headers->get('X-Robots-Tag'));
});

it('sends the header on EVERYTHING served on the owner app’s own host, the app and anything else', function () {
    // With no canonical host configured, SiteHost calls every host canonical,
    // so CanonicalHost's own noindex never fires there: a shop page reached on
    // the owner host was indexable. MUTATION: return false from isPrivateHost().
    $owner = OA::admin();
    OA::member($owner);

    try {
        OwnerAppPath::setHost('owner.example.test');
        oa3Rewire();
        $base = OA::base();
        $h = 'http://owner.example.test';

        $rows = [
            'shell' => fn () => $this->flushHeaders()->get($h.$base),
            'manifest' => fn () => $this->flushHeaders()->get($h.$base.'/manifest.webmanifest'),
            'sw.js' => fn () => $this->flushHeaders()->get($h.$base.'/sw.js'),
            'api/state' => fn () => $this->flushHeaders()->getJson($h.$base.'/api/state'),
            'catch-all 404' => fn () => $this->flushHeaders()->get($h.$base.'/nope'),
            'shop home on the owner host' => fn () => $this->flushHeaders()->get($h.'/'),
            'robots.txt on the owner host' => fn () => $this->flushHeaders()->get($h.'/robots.txt'),
            'a 404 on the owner host' => fn () => $this->flushHeaders()->get($h.'/zz-nothing'),
        ];
        foreach ($rows as $what => $call) {
            expect($call()->headers->get('X-Robots-Tag'))->toBe(OA3_ROBOTS, $what);
        }

        // The shop's own host is untouched, and the app's path there is the
        // shop's ordinary 404 — the same headers as any other miss.
        expect($this->flushHeaders()->get('http://localhost/')->headers->get('X-Robots-Tag'))->toBeNull();
        $miss = $this->flushHeaders()->get('http://localhost/zz_never_was_a_page');
        $app = $this->flushHeaders()->get('http://localhost'.$base);
        expect($app->status())->toBe(404)->and($app->headers->get('X-Robots-Tag'))->toBe($miss->headers->get('X-Robots-Tag'));
    } finally {
        OwnerAppPath::setHost('');
        oa3Rewire();
    }
});

it('names neither secret in robots.txt, the sitemaps, llms.txt, the key file or any storefront head', function () {
    // MUTATION: drop scrubRobots() from SeoFilesController::robots() — the
    // custom-robots row goes red. Every other row is green today and is
    // pinned so it stays so.
    $app = 'zq_oa3probe-7k2m';
    $admin = 'zqbackroom-9x4';
    $ownerHost = 'zqowner-oa3.example.test';
    OwnerAppPath::set($app);
    AdminPathService::set($admin);
    OwnerAppPath::setHost($ownerHost);

    try {
        DB::table('pages')->insert(['title' => 'About us', 'slug' => 'about-oa3', 'status' => 'published', 'content' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('settings')->updateOrInsert(['key' => 'indexnow_on'], ['value' => '1']);
        Cache::forget('kbb.settings');
        \App\Models\Setting::flushMap();

        $files = ['/robots.txt', '/sitemap.xml', '/llms.txt', '/'.IndexNow::key().'.txt', '/humans.txt', '/feed', '/', '/shop', '/about-oa3', '/my-account'];
        $seen = [];
        foreach ($files as $url) {
            $seen[$url] = $this->get($url)->getContent();
        }
        // Follow every child sitemap the index names.
        preg_match_all('#<loc>(https?://[^<]+\.xml[^<]*)</loc>#', (string) $seen['/sitemap.xml'], $m);
        foreach ($m[1] as $child) {
            $seen[$child] = $this->get(html_entity_decode($child))->getContent();
        }

        foreach ($seen as $url => $body) {
            $lower = strtolower((string) $body);
            // The shop now links its OWN manifest (App -> Site App, 2.60.404),
            // /manifest.webmanifest; what must never appear is the OWNER APP's,
            // which lives under its secret path -- covered by $app itself and
            // named here so the intent stays explicit.
            foreach ([$app, $admin, $ownerHost, $app.'/manifest.webmanifest'] as $needle) {
                expect(str_contains($lower, $needle))->toBeFalse($url.' names '.$needle);
            }
        }

        // The owner types a robots.txt that names both: neither line survives,
        // every other line is served byte for byte.
        DB::table('settings')->updateOrInsert(['key' => 'robots_txt'], ['value' => "User-agent: *\nDisallow: /{$app}/\nDisallow: /{$admin}\nDisallow: /cart\nSitemap: https://shop.test/sitemap.xml\n"]);
        Cache::flush();
        \App\Models\Setting::flushMap();
        $robots = $this->get('/robots.txt')->getContent();
        expect($robots)->toBe("User-agent: *\nDisallow: /cart\nSitemap: https://shop.test/sitemap.xml");
    } finally {
        OwnerAppPath::setHost('');
        AdminPathService::set('admin');
    }
});

it('never hands a private address to IndexNow', function () {
    // MUTATION: remove the PrivateSurfaces filter from IndexNow::submit().
    OwnerAppPath::set('zq_oa3probe-7k2m');
    DB::table('settings')->updateOrInsert(['key' => 'indexnow_on'], ['value' => '1']);
    DB::table('settings')->updateOrInsert(['key' => 'site_url'], ['value' => 'https://shop.test']);
    Cache::flush();
    \App\Models\Setting::flushMap();
    Http::fake(['*' => Http::response('', 200)]);

    IndexNow::submit([
        'https://shop.test/product/snail/',
        'https://shop.test/zq_oa3probe-7k2m/',
        'https://shop.test/admin/login',
        'https://shop.test/admin-api/products',
        'https://shop.test/my-account/reset/1/token/',
    ]);
    expect(IndexNow::submit(['https://shop.test/zq_oa3probe-7k2m/api/state']))->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $r) => $r['urlList'] === ['https://shop.test/product/snail/']);
});

it('classifies paths without reading a setting for the fixed half', function () {
    expect(PrivateSurfaces::isPrivateRoot('admin-api/x'))->toBeTrue()
        ->and(PrivateSurfaces::isPrivateRoot('my-account/reset/1/t'))->toBeTrue()
        ->and(PrivateSurfaces::isPrivateRoot('my-account/orders'))->toBeFalse()
        ->and(PrivateSurfaces::isPrivateRoot('shop'))->toBeFalse()
        ->and(PrivateSurfaces::isPrivateRoot('upcycled-serum'))->toBeFalse()
        ->and(PrivateSurfaces::isPrivatePath('/'.OwnerAppPath::current().'/sw.js'))->toBeTrue();
});

it('serves the app and nothing else on its own host: every other path is a bare 404 before shop code runs', function () {
    // 2.60.402, the gap lane OA3 reported: with the dedicated host set, shop
    // pages answered there (noindexed), so the shop's scripts and third-party
    // tags ran on the app's own origin -- the isolation the host exists for.
    // MUTATION: delete the host block at the top of PrivateNoIndex::handle()
    // -> the shop home answers 200 on the owner host, red.
    $owner = OA::admin();
    OA::member($owner);

    try {
        OwnerAppPath::setHost('owner.example.test');
        oa3Rewire();
        $base = OA::base();
        $h = 'http://owner.example.test';

        foreach (['/', '/robots.txt', '/shop/', '/sitemap.xml', strtoupper($base)] as $path) {
            $r = $this->flushHeaders()->get($h.$path);
            expect($r->status())->toBe(404, $path)
                ->and($r->getContent())->toBe('', $path)
                ->and($r->headers->get('X-Robots-Tag'))->toBe(OA3_ROBOTS, $path);
        }
        expect($this->flushHeaders()->get($h.$base)->status())->toBe(200)
            ->and($this->flushHeaders()->get('http://localhost/')->status())->toBe(200);
    } finally {
        OwnerAppPath::setHost('');
        oa3Rewire();
    }
});
