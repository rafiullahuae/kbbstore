<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Setting;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TabbyGateway;
use App\Services\SettingsService;
use App\Support\SiteHost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * =============================================================================
 * MOVING THE SHOP FROM extrabeauty.ae TO kbeautybliss.com (Lane DM)
 * =============================================================================
 *
 * The owner, 6 October 2026: "i want to connect the original domain to this
 * app. make sure nothing should be break, i want zero dependency of the old
 * domain (extrabeauty.ae)". docs/DOMAIN-MOVE-KBEAUTYBLISS.md is the runbook;
 * this file pins the three things in it that are code, not steps:
 *
 *  1. THE www TWIN OF AN OLD ADDRESS. www.extrabeauty.ae is a CNAME to the
 *     apex on the live DNS (CUTOVER-EXTRABEAUTY.md §7). Typing `extrabeauty.ae`
 *     under Old addresses forwarded the apex and left www UNLISTED: the whole
 *     shop, served on the domain being left, marked noindex -- measured, 200 +
 *     `X-Robots-Tag: noindex` on every path.
 *
 *  2. THE ORDER OF THE RUNBOOK IS SAFE. The settings step done BEFORE the DNS
 *     change (main address kbeautybliss.com, forwarding off) must change
 *     nothing on extrabeauty.ae, which is still the live shop at that moment.
 *
 *  3. TABBY'S OLD-DOMAIN WEBHOOK. "Re-register" added the kbeautybliss.com hook
 *     and left the extrabeauty.ae one registered for ever.
 *
 *  4. `kbb:domain-check`, the read-only proof the runbook asks for.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    SiteHost::forget();
    CheckRedirects::flushIndex();
});

function dmSettings(array $values): void
{
    $values += [
        SiteHost::KEY_CANONICAL => '',
        SiteHost::KEY_ALIASES => '',
        SiteHost::KEY_REDIRECT => '0',
        SiteHost::KEY_VISIBILITY => 'public',
    ];

    foreach ($values as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    SiteHost::forget();
}

/** One request through the real kernel, path spelled exactly (see RedirectMiddlewareTest::rmFetch). */
function dmFetch(string $url): Symfony\Component\HttpFoundation\Response
{
    SiteHost::forget();

    return app(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create($url, 'GET'));
}

function dmProduct(array $attributes = []): Product
{
    return Product::create($attributes + [
        'name' => 'DM Rice Toner',
        'slug' => 'dm-rice-toner',
        'sku' => 'DM-'.strtoupper(substr(md5(uniqid()), 0, 6)),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'image' => '/wp-content/uploads/2024/01/dm.jpg',
    ]);
}

/* ═════════════════════════════════════════ 1. the www twin of an old address ══ */

it('forwards www.extrabeauty.ae path for path, not just the apex that was typed', function () {
    /*
     * THE DEFECT, measured on this branch before the fix: with Old addresses
     * reading `extrabeauty.ae` and forwarding on,
     *
     *     GET https://www.extrabeauty.ae/product/dm-rice-toner/   200
     *         X-Robots-Tag: noindex, nofollow, noarchive
     *
     * -- the whole shop, on the old domain, invisible to Google and not moving
     * its ranking anywhere. The admin screen and CUTOVER-EXTRABEAUTY.md §4.1
     * both told the owner the www pair is handled and he need not type it.
     *
     * MUTATION: in SiteHost::aliases() put back `$out[$host] = true;` in place
     * of the twin loop. Red: 200 and noindex instead of the 301.
     */
    config(['app.url' => 'https://kbeautybliss.com']);
    dmSettings([
        SiteHost::KEY_CANONICAL => 'kbeautybliss.com',
        SiteHost::KEY_ALIASES => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '1',
    ]);
    dmProduct();

    $response = dmFetch('https://www.extrabeauty.ae/product/dm-rice-toner/?utm_source=ig');

    expect($response->getStatusCode())->toBe(301)
        ->and($response->headers->get('Location'))->toBe('https://kbeautybliss.com/product/dm-rice-toner/?utm_source=ig')
        ->and($response->headers->get('X-Robots-Tag'))->toBeNull();

    expect(SiteHost::aliases())->toContain('extrabeauty.ae', 'www.extrabeauty.ae', 'www.kbeautybliss.com');
});

it('never lists the main address as an alias of itself, whichever form was typed', function () {
    /*
     * The twin of a typed alias can BE the main address: main `www.shop.test`,
     * old address `shop.test`. Listing it would forward the live site to
     * itself -- CanonicalHost's null target stops the loop, but the verdict
     * would read ALIAS for the real site. It must stay CANONICAL.
     *
     * MUTATION: drop `&& $name !== $canonical` from the twin loop. Red.
     */
    dmSettings([
        SiteHost::KEY_CANONICAL => 'www.shop.test',
        SiteHost::KEY_ALIASES => "shop.test\nold-shop.test",
        SiteHost::KEY_REDIRECT => '1',
    ]);

    expect(SiteHost::aliases())->not->toContain('www.shop.test')
        ->and(SiteHost::aliases())->toContain('shop.test', 'old-shop.test', 'www.old-shop.test')
        ->and(SiteHost::classify('www.shop.test'))->toBe(SiteHost::CANONICAL);
});

/* ════════════════════════════════════════ 2. the runbook's order is safe ══ */

it('changes nothing on extrabeauty.ae when the main address is set before the DNS moves', function () {
    /*
     * Runbook step B1, done while kbeautybliss.com still points at WordPress:
     * main address kbeautybliss.com, old addresses extrabeauty.ae, forwarding
     * OFF, APP_URL untouched. extrabeauty.ae is still the live shop at that
     * moment, so it must keep answering exactly as it does today: 200, indexable,
     * its own canonical tag. An ALIAS with forwarding off is served, not hidden.
     *
     * And the reason the step exists: with the main address still
     * extrabeauty.ae, kbeautybliss.com pointed here would be UNLISTED and answer
     * noindex -- the second block pins that, so the runbook's warning stays true.
     *
     * MUTATION: make SiteHost::isPrivate() true for ALIAS as well as UNLISTED.
     * Red on the first block.
     */
    config(['app.url' => 'https://extrabeauty.ae']);
    dmSettings([
        SiteHost::KEY_CANONICAL => 'kbeautybliss.com',
        SiteHost::KEY_ALIASES => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '0',
    ]);

    foreach (['https://extrabeauty.ae/', 'https://www.extrabeauty.ae/'] as $url) {
        $response = dmFetch($url);

        expect($response->getStatusCode())->toBe(200, $url)
            ->and($response->headers->get('X-Robots-Tag'))->toBeNull()
            ->and((string) $response->getContent())->toContain('<link rel="canonical" href="https://extrabeauty.ae/">')
            ->and((string) $response->getContent())->not->toContain('noindex');
    }

    dmSettings([SiteHost::KEY_CANONICAL => 'extrabeauty.ae']);

    expect(dmFetch('https://kbeautybliss.com/')->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('serves kbeautybliss.com indexable once the switch is done, and forwards both old names in one hop', function () {
    config(['app.url' => 'https://kbeautybliss.com']);
    dmSettings([
        SiteHost::KEY_CANONICAL => 'kbeautybliss.com',
        SiteHost::KEY_ALIASES => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '1',
        'site_url' => 'https://kbeautybliss.com',
    ]);
    Redirect::query()->create(['source' => '/dm-old-page/', 'target' => '/dm-new-page/', 'code' => 301,
        'enabled' => true, 'auto_created' => true]);
    CheckRedirects::flushIndex();

    $home = dmFetch('https://kbeautybliss.com/');

    expect($home->getStatusCode())->toBe(200)
        ->and($home->headers->get('X-Robots-Tag'))->toBeNull()
        ->and((string) $home->getContent())->toContain('<link rel="canonical" href="https://kbeautybliss.com/">')
        ->and((string) $home->getContent())->not->toContain('extrabeauty.ae');

    expect(dmFetch('https://www.kbeautybliss.com/cart/')->headers->get('Location'))->toBe('https://kbeautybliss.com/cart/');

    // A row in `redirects` is folded into the same hop on both old names.
    foreach (['extrabeauty.ae', 'www.extrabeauty.ae'] as $host) {
        expect(dmFetch('https://'.$host.'/dm-old-page/')->headers->get('Location'))->toBe('https://kbeautybliss.com/dm-new-page/');
    }
});

/* ═══════════════════════════════════════════ 3. Tabby's old-domain webhook ══ */

function dmTabby(): TabbyGateway
{
    PaymentProvider::query()->delete();
    $row = PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    $row->config = [
        'public_key' => 'pk_test_11111111-2222-3333-4444-555555555555',
        'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555',
        'merchant_code' => 'AE',
        'webhook_secret' => 'whsec-tabby-abcdefghijklmnopqrstuvwxyz012345',
    ];
    $row->save();
    app(GatewayCredentials::class)->forget();

    return app(GatewayRegistry::class)->find('tabby');
}

it('removes the Tabby webhook still calling extrabeauty.ae when the shop re-registers on the new domain', function () {
    /*
     * After the move APP_URL is kbeautybliss.com and so is the prefix
     * staleHooks() compared against, so the hook Tabby still held for
     * https://extrabeauty.ae/api/payments/webhook/tabby/<secret> was neither
     * "ours" nor "stale". Re-register POSTed the new one and deleted nothing:
     * every payment event delivered twice, for ever, to a domain the owner is
     * leaving -- with the URL secret in it -- and Tabby has no screen to remove
     * it from.
     *
     * A hook on a host the owner did NOT list as an old address (staging, a
     * second store on the same Tabby account) is still never touched.
     *
     * MUTATION: drop `|| $this->isOursOnAnOldAddress($candidate, $prefix)` in
     * TabbyGateway::staleHooks(). Red: no DELETE for wh_old.
     */
    Http::preventStrayRequests();
    config(['app.url' => 'https://kbeautybliss.com']);
    dmSettings([
        SiteHost::KEY_CANONICAL => 'kbeautybliss.com',
        SiteHost::KEY_ALIASES => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '1',
    ]);

    $gateway = dmTabby();
    $path = '/api/payments/webhook/tabby/whsec-tabby-abcdefghijklmnopqrstuvwxyz012345';

    expect($gateway->ourWebhookUrl())->toBe('https://kbeautybliss.com'.$path);

    Http::fake([
        'api.tabby.ai/api/v1/webhooks/*' => Http::response([], 200),
        'api.tabby.ai/api/v1/webhooks' => Http::sequence()
            ->push([
                ['id' => 'wh_old', 'url' => 'https://extrabeauty.ae'.$path, 'is_test' => true],
                ['id' => 'wh_staging', 'url' => 'https://staging.extrabeauty.ae'.$path, 'is_test' => true],
                ['id' => 'wh_other', 'url' => 'https://extrabeauty.ae/some-other-system/hook', 'is_test' => true],
            ], 200)
            ->push(['id' => 'wh_new'], 200)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403)
            ->push(['errorType' => 'not_authorized'], 403),
    ]);

    $gateway->syncWebhooks();

    $deleted = collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'DELETE')
        ->map(fn ($pair) => $pair[0]->url())
        ->values()
        ->all();

    expect($deleted)->toBe(['https://api.tabby.ai/api/v1/webhooks/wh_old']);

    $created = collect(Http::recorded())->first(fn ($pair) => $pair[0]->method() === 'POST');

    expect(json_decode($created[0]->body(), true)['url'])->toBe('https://kbeautybliss.com'.$path);
});

/* ═════════════════════════════════════════════════ 4. kbb:domain-check ══ */

it('counts every stored address that depends on the old domain or breaks on the switch', function () {
    /*
     * The proof the runbook asks for before the day: one number, from the
     * database, rather than "it looked fine". Each seeded row is a real shape:
     *
     *   a redirect typed with an absolute target on the old domain      RISK
     *   a robots.txt override naming the old sitemap                    RISK
     *   a product photo still served by WordPress on kbeautybliss.com   RISK (404 on the day)
     *   a description picture this shop already holds at that path      OK
     *   a description link to a WordPress page on kbeautybliss.com      INFO (answered here after)
     *   info@kbeautybliss.com in the copy                               not reported
     *   `host_aliases` = extrabeauty.ae -- the forwarding itself        not reported
     *
     * MUTATION: return [] from DomainReadiness::references(). Red: exit 0 and
     * none of the lines.
     */
    config(['app.url' => 'https://kbeautybliss.com']);
    dmSettings([
        SiteHost::KEY_CANONICAL => 'kbeautybliss.com',
        SiteHost::KEY_ALIASES => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '1',
        'robots_txt' => "User-agent: *\nSitemap: https://extrabeauty.ae/sitemap.xml",
    ]);

    $here = public_path('wp-content/uploads/2024/01/dm-here.jpg');
    @mkdir(dirname($here), 0777, true);
    file_put_contents($here, 'x');

    Redirect::query()->create(['source' => '/dm-gone/', 'target' => 'https://extrabeauty.ae/collections/toners/',
        'code' => 301, 'enabled' => true, 'auto_created' => false]);
    dmProduct([
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2024/01/dm-gone.jpg',
        'description' => '<p><img src="https://kbeautybliss.com/wp-content/uploads/2024/01/dm-here.jpg"> '
            .'<a href="https://kbeautybliss.com/face-washes/">Face washes</a> — write to info@kbeautybliss.com</p>',
    ]);

    try {
        $this->artisan('kbb:domain-check', ['new' => 'kbeautybliss.com', '--old' => ['extrabeauty.ae']])
            ->expectsOutputToContain('RISK  redirects.target  1 links on extrabeauty.ae')
            ->expectsOutputToContain('RISK  settings.value  1 links on extrabeauty.ae')
            ->expectsOutputToContain('RISK  products.image  1 picture/video addresses on kbeautybliss.com')
            ->expectsOutputToContain('OK    products.description  1 picture/video addresses this shop already holds on kbeautybliss.com')
            ->expectsOutputToContain('INFO  products.description  1 links on kbeautybliss.com')
            ->doesntExpectOutputToContain('setting host_aliases')
            ->doesntExpectOutputToContain('email addresses on kbeautybliss.com')
            ->assertExitCode(1);
    } finally {
        @unlink($here);
    }
});

it('passes with nothing left, and reads without writing a single row', function () {
    /*
     * Read-only is a claim, so it is measured: every statement the command
     * sends is a SELECT (or SQLite's PRAGMA, which is how the schema is read).
     *
     * MUTATION: have the command call Setting::query()->updateOrCreate(...) or
     * any write. Red on the statement list.
     */
    config(['app.url' => 'https://kbeautybliss.com']);
    dmSettings([
        SiteHost::KEY_CANONICAL => 'kbeautybliss.com',
        SiteHost::KEY_ALIASES => 'extrabeauty.ae',
        SiteHost::KEY_REDIRECT => '1',
        'site_url' => 'https://kbeautybliss.com',
    ]);
    dmProduct(['description' => '<p>Write to info@kbeautybliss.com</p>']);

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(select|pragma|with)\b/i', $query->sql) !== 1) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('kbb:domain-check', ['new' => 'kbeautybliss.com'])
        ->expectsOutputToContain('leaving: extrabeauty.ae')
        ->expectsOutputToContain('RISK 0')
        ->assertExitCode(0);

    expect($writes)->toBe([]);
});
