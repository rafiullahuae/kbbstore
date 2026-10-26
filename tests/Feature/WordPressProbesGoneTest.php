<?php

declare(strict_types=1);

/*
 * Lane WJ. The owner: "is there any solution to stop those urls etc?"
 *
 * THE DEFECT ON THE SHOP: Store → Analytics was full of "Page not found |
 * K-Beauty Bliss UAE · United States · desktop · Direct". Robots asked for
 * dead WordPress addresses (/wp-json/oembed/1.0/embed, /xmlrpc.php,
 * /wp-login.php ...), every one fell through to the themed 404 page, and that
 * page's analytics beacon counted it as a page view.
 *
 * Two fixes, pinned separately:
 *   1. The analytics never count the 404 page (Tracker / Online).
 *   2. WordPress probes are answered 410 Gone before anything is read, unless
 *      a redirect row claims the address, the address is under /wp-content,
 *      /ar, or is the owner's own admin path.
 */

use App\Http\Middleware\CheckRedirects;
use App\Http\Middleware\GoneWordPressProbes;
use App\Http\Middleware\SettingsRequestMemo;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\AdminPathService;
use App\Services\Analytics\Tracker;
use App\Services\SettingsService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

const WJ_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

function wjFetch(string $uri, string $method = 'GET'): \Symfony\Component\HttpFoundation\Response
{
    return app(\Illuminate\Contracts\Http\Kernel::class)->handle(
        \Illuminate\Http\Request::create('http://localhost'.$uri, $method),
    );
}

function wjBeacon(string $url, array $fields)
{
    return test()->flushHeaders()->withHeaders(['User-Agent' => WJ_UA])
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->post($url, $fields);
}

beforeEach(function () {
    Route::middleware('web')->group(base_path('routes/instant-nav.php'));
    DB::table('an_hits')->delete();
    DB::table('an_online')->delete();
    CheckRedirects::flushIndex();
    AdminPathService::forgetMemo();
    Cache::forget('kbb.admin_path');
});

/* ── 1. the 404 page is not a page view ─────────────────────────────── */

it('does not count the shop’s own 404 page as a page view, with the title it really prints', function () {
    // The title is read off the real 404 page, so a change to how the layout
    // builds <title> cannot slip past this.
    // MUTATION: delete the isNotFoundTitle() check in Tracker::row() -> red:
    // one an_hits row titled "Page not found | ...".
    $html = test()->get('/no-such-page-wj/')->assertStatus(404)->getContent();
    preg_match('#<title>(.*?)</title>#s', $html, $m);
    $title = html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    expect($title)->toStartWith('Page not found');

    wjBeacon('/api/viewed', ['p' => '/wp-json/oembed/1.0/embed', 't' => $title, 'n' => '1'])->assertNoContent();
    expect(DB::table('an_hits')->count())->toBe(0);

    // A real page from the same browser still counts.
    wjBeacon('/api/viewed', ['p' => '/shop/', 't' => 'Shop | K-Beauty Bliss UAE', 'n' => '0'])->assertNoContent();
    expect(DB::table('an_hits')->count())->toBe(1)
        ->and(DB::table('an_hits')->value('path'))->toBe('/shop/');
});

it('recognises the 404 title in both languages and with the shop name either side, and nothing else', function () {
    // MUTATION: compare with === only -> red on the suffixed titles, which
    // are the ones the layout actually prints.
    foreach (['Page not found', 'Page not found | K-Beauty Bliss UAE', 'K-Beauty Bliss UAE | Page not found',
        'الصفحة غير موجودة', 'الصفحة غير موجودة | كي بيوتي'] as $t) {
        expect(Tracker::isNotFoundTitle($t))->toBeTrue($t);
    }
    foreach (['', 'Shop | K-Beauty Bliss UAE', 'Page not founder serum', 'Why your page not found guide'] as $t) {
        expect(Tracker::isNotFoundTitle($t))->toBeFalse($t);
    }
});

it('does not list a 404 page under "online now" from its heartbeat', function () {
    // MUTATION: drop the isNotFoundTitle() guard in Online::ping() -> red: a
    // robot that sits 45 s on a dead address shows in the live pages list.
    wjBeacon(route('analytics.online'), ['p' => '/xmlrpc.php', 't' => 'Page not found | K-Beauty Bliss UAE', 'x' => 'hb']);
    expect(DB::table('an_online')->count())->toBe(0);

    wjBeacon(route('analytics.online'), ['p' => '/shop/', 't' => 'Shop', 'x' => 'hb']);
    expect(DB::table('an_online')->count())->toBe(1);
});

/* ── 2. WordPress probes are Gone ───────────────────────────────────── */

it('answers WordPress probe addresses 410 Gone with a tiny plain body', function () {
    // MUTATION: remove the prependMiddleware(GoneWordPressProbes) line in
    // AppServiceProvider -> red: every one is the themed 404 page.
    foreach ([
        '/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fkbeautybliss.com%2F',
        '/wp-json/', '/wp-json/wp/v2/users', '/xmlrpc.php', '/wp-login.php', '/wp-login.php?action=register',
        '/wp-admin/', '/wp-admin/admin-ajax.php', '/wp-includes/wlwmanifest.xml', '/wp-cron.php',
        '/wp-config.php.bak', '/WP-LOGIN.PHP', '/wordpress/wp-admin/setup-config.php', '/blog/wp-login.php',
        '/?rest_route=/wp/v2/users', '/?rest_route=',
    ] as $uri) {
        $r = wjFetch($uri);
        expect($r->getStatusCode())->toBe(410, $uri)
            ->and($r->getContent())->toBe("Gone\n")
            ->and($r->headers->get('Content-Type'))->toStartWith('text/plain')
            ->and($r->headers->getCookies())->toBe([], $uri.' started a session');
    }

    // xmlrpc.php is POSTed far more often than fetched.
    expect(wjFetch('/xmlrpc.php', 'POST')->getStatusCode())->toBe(410);
});

it('costs a probe no query, no settings read, no session and no 404-log row', function () {
    // MUTATION: register the middleware with pushMiddleware (after
    // ComingSoonGate/CanonicalHost, which read settings) -> red on the
    // settings read. Drop it -> red on the 404 log and the queries.
    wjFetch('/wp-login.php'); // builds the redirect index once, as on a running server

    $settings = 0;
    Event::listen([CacheHit::class, CacheMissed::class], static function ($e) use (&$settings): void {
        if ($e->key === 'kbb.settings') {
            $settings++;
        }
    });
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $r = wjFetch('/wp-json/oembed/1.0/embed');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($r->getStatusCode())->toBe(410)
        ->and($queries)->toBe([])
        ->and($settings)->toBe(0)
        ->and(DB::table('not_found_log')->where('path', 'like', 'wp-json%')->count())->toBe(0);
});

it('still redirects a WordPress-looking address that has a redirect row', function () {
    // An imported Search Console 404 or a row the owner wrote on Store → SEO
    // & Meta → Redirects & 404s outranks the 410.
    // MUTATION: drop the CheckRedirects::lookup() pass-through -> red: 410.
    Redirect::query()->create(['source' => '/wp-login.php', 'target' => '/my-account/', 'code' => 301, 'enabled' => true, 'auto_created' => false]);
    Redirect::query()->create(['source' => '/wp-json/wp/v2/posts/12', 'target' => '/blog/', 'code' => 301, 'enabled' => true, 'auto_created' => false]);
    CheckRedirects::flushIndex();

    $r = wjFetch('/wp-login.php');
    expect($r->getStatusCode())->toBe(301)
        ->and($r->headers->get('Location'))->toEndWith('/my-account/');

    expect(wjFetch('/wp-json/wp/v2/posts/12')->getStatusCode())->toBe(301);

    // A switched-off row does not save it.
    Redirect::query()->where('source', '/wp-login.php')->update(['enabled' => false]);
    CheckRedirects::flushIndex();
    expect(wjFetch('/wp-login.php')->getStatusCode())->toBe(410);
});

it('never answers /wp-content, /ar, or the owner’s admin path, and leaves real pages alone', function () {
    // MUTATION: drop 'wp-content' from GoneWordPressProbes::PASS -> red on the
    // plugin path; drop the AdminPathService check -> red on /wp-admin/.
    Redirect::query()->create(['source' => '/wp-content/uploads/2021/03/old-serum.jpg', 'target' => '/shop/', 'code' => 301, 'enabled' => true, 'auto_created' => true]);
    CheckRedirects::flushIndex();

    expect(wjFetch('/wp-content/uploads/2021/03/old-serum.jpg')->getStatusCode())->toBe(301)
        ->and(wjFetch('/wp-content/plugins/x/wp-login.php')->getStatusCode())->not->toBe(410)
        ->and(wjFetch('/ar/wp-login.php')->getStatusCode())->not->toBe(410)
        ->and(wjFetch('/no-such-page-wj/')->getStatusCode())->toBe(404)
        ->and(wjFetch('/')->getStatusCode())->toBe(200)
        ->and(wjFetch('/?s=wp-login')->getStatusCode())->toBe(200)
        ->and(wjFetch('/shop/')->getStatusCode())->toBe(200);

    Product::create(['slug' => 'wj-real', 'name' => 'WJ Real', 'price' => 1000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple']);
    expect(wjFetch('/product/wj-real/')->getStatusCode())->toBe(200);

    // The owner moved his back office to /wp-admin: it is his, not a probe.
    DB::table('settings')->updateOrInsert(['key' => 'admin_path'], ['value' => 'wp-admin', 'autoload' => true]);
    Cache::forget('kbb.admin_path');
    AdminPathService::forgetMemo();
    expect(GoneWordPressProbes::isProbe(\Illuminate\Http\Request::create('http://localhost/wp-admin/')))->toBeFalse()
        ->and(GoneWordPressProbes::isProbe(\Illuminate\Http\Request::create('http://localhost/wp-login.php')))->toBeTrue();
});

it('sits second in the global stack: inside the settings memo, ahead of everything that reads', function () {
    // MUTATION: prepend it AFTER the memo line -> red: it becomes outermost
    // and SettingsRequestMemoTest's "outermost" pin breaks too.
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $global = $kernel->getGlobalMiddleware();

    expect($global[0])->toBe(SettingsRequestMemo::class)
        ->and($global[1])->toBe(GoneWordPressProbes::class)
        ->and(array_count_values($global)[GoneWordPressProbes::class])->toBe(1)
        ->and(array_search(GoneWordPressProbes::class, $global, true))
        ->toBeLessThan(array_search(\App\Http\Middleware\CanonicalHost::class, $global, true));
});
