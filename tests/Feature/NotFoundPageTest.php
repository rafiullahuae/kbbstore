<?php

declare(strict_types=1);

/*
 * The shop's 404 page (Lane NF).
 *
 * THE DEFECT IT REPLACES: every address that did not exist answered with
 * Laravel's bare "404 | Not Found" — no header, no search, no way back into
 * the shop. The owner: "i want 404 page for my site so nothing can give not
 * found error page". He chose design B and asked for A, C and D to stay
 * selectable under Safety → 404 page with full controls.
 *
 * What is pinned here: the page is drawn for every way a shopper reaches a
 * 404, in both languages, with a REAL 404 status and noindex; a stored
 * redirect still wins and the not-found log still records; the admin, the
 * APIs, the owner app and JSON keep the responses they had; the settings are
 * validated on save and re-validated on read; the trending strip is one cached
 * query and the page's cost does not grow with the catalogue.
 */

use App\Models\AdminUser;
use App\Models\NotFoundLog;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Setting;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\SettingsService;
use App\Support\Locale;
use App\Support\NotFoundPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Support\NotFoundPageRoutes;

function nfForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

/** Save through the same path the admin does, and drop every memo. */
function nfSet(array $patch): void
{
    expect(NotFoundPage::save(array_replace_recursive(NotFoundPage::defaults(), $patch)))->toBe([]);
    nfForget();
}

function nfAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'NF '.$role,
        'email' => "nf-{$role}@example.test",
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

function nfProducts(int $n): void
{
    static $seq = 0;
    for ($i = 1; $i <= $n; $i++) {
        $seq++;
        Product::create([
            'slug' => "nf-prod-{$seq}", 'name' => "NF Product {$seq}", 'price' => 1000 + $seq,
            'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
            'total_sales' => $seq,
        ]);
    }
}

function nfArabic(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
    nfForget();
    app(SettingsService::class)->flush();
}

function nfQueries(string $path): array
{
    nfForget();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($path)->assertStatus(404);
    $log = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $log;
}

beforeEach(function () {
    NotFoundPageRoutes::wire($this->app);
    Cache::flush();
    nfForget();
});

/* ─────────────────────────────── the page itself ─────────────────────────── */

it('answers an unknown address with the shop’s own 404 page, design B, still a real 404', function () {
    // MUTATION: in AppServiceProvider put `return null;` back where
    // NotFoundPage::respond() is called -> red: the bare framework page has no
    // nf-hero. Answer with status 200 in respond() -> red on the status.
    $r = $this->get('/no-such-page-at-all');

    $r->assertStatus(404);
    $html = $r->getContent();

    expect($html)->toContain('class="nf-hero"')
        ->and($html)->toContain('data-nf="b"')
        ->and($html)->toContain('Aah, you shouldn’t be here…')
        ->and($html)->toContain('<em>but you look gorgeous.</em>')
        ->and($html)->toContain('class="nf-art"')
        ->and($html)->toContain('<meta name="robots" content="noindex')
        ->and($html)->toContain('<header')
        ->and($html)->toContain('<footer')
        ->and($html)->toMatch('#<form class="nf-sf" action="[^"]*/shop/" method="get" role="search">.*?name="s"#s')
        ->and($html)->not->toContain('404 | Not Found');
});

it('wears the shop’s own header and footer, byte for byte the ones on /new-in/', function () {
    // StorefrontEnglishUnchangedTest cuts the old bare 404 whole, because it
    // had no chrome to compare. This is the comparison it hands over: the
    // header and the footer the 404 page draws are the shop's, unchanged.
    // MUTATION: render the page without @extends('layouts.store') -> red.
    $grab = function (string $html, string $tag): string {
        preg_match('#<'.$tag.'\b.*?</'.$tag.'>#s', $html, $m);

        return preg_replace('/name="_token" value="[^"]*"|content="[A-Za-z0-9]{40}"/', '', $m[0] ?? '');
    };

    $missing = $this->get('/no-such-page-at-all')->getContent();
    $listing = $this->get('/new-in/')->getContent();

    expect($grab($missing, 'header'))->not->toBe('')
        ->and($grab($missing, 'header'))->toBe($grab($listing, 'header'))
        ->and($grab($missing, 'footer'))->toBe($grab($listing, 'footer'));
});

it('is never a soft 200, for HEAD as well as GET', function () {
    // A 200 would let a crawler index every dead link as a thin page.
    $this->get('/still-not-here/')->assertStatus(404);
    $this->call('HEAD', '/still-not-here/')->assertStatus(404);
});

it('covers every way a shopper reaches a 404: product, brand, category, page and collection', function () {
    // MUTATION: narrow respond() to unmatched routes only (e.g. bail when the
    // request has a named route) -> red: the product, brand and category 404s
    // come from firstOrFail()/abort(404) inside a matched route.
    foreach (['/product/no-such-product/', '/brands/no-such-brand/', '/product-category/no-such-category/', '/no-such-page/', '/collections/no-such-collection/'] as $path) {
        $r = $this->get($path);
        expect($r->getStatusCode())->toBe(404, $path)
            ->and($r->getContent())->toContain('class="nf-hero"');
    }
});

it('speaks Arabic, right to left, on /ar/ addresses', function () {
    // MUTATION: read the language from the English default instead of
    // app()->getLocale() in viewData() -> red on the Arabic headline.
    nfArabic();

    $html = $this->get('/ar/no-such-page/')->assertStatus(404)->getContent();

    expect($html)->toMatch('#<html[^>]*lang="ar"#')
        ->and($html)->toMatch('#<html[^>]*dir="rtl"#')
        ->and($html)->toContain('آه، لا يُفترض أن تكوني هنا…')
        ->and($html)->toContain('خذيني إلى الرئيسية')
        ->and($html)->toContain('آه!');
});

it('draws each of the four designs the owner can pick, each with its own illustration', function () {
    // MUTATION: hard-code art-b in the view's @include -> red for a, c and d.
    foreach (['a' => 'cracked', 'b' => 'you look gorgeous', 'c' => 'a little smudge', 'd' => 'to be lost.'] as $d => $words) {
        nfSet(['design' => $d]);
        $html = $this->get('/nope-'.$d)->assertStatus(404)->getContent();
        expect($html)->toContain('data-nf="'.$d.'"')->and($html)->toContain($words)->and($html)->toContain('class="nf-art"');
    }
});

it('keeps the illustration light: under 5.5 KB of inline SVG for every design, with a viewBox so nothing shifts', function () {
    foreach (NotFoundPage::DESIGNS as $d) {
        foreach ([false, true] as $ar) {
            $svg = trim(NotFoundPage::art($d, $ar));
            expect(strlen($svg))->toBeLessThan(5500, $d)
                ->and($svg)->toMatch('#^<svg class="nf-art" width="100%" viewBox="0 0 \d+ \d+"#');
        }
    }
});

/* ─────────────────────── what still comes first, and still records ─────────────────────── */

it('lets a stored redirect win before the 404 page is drawn', function () {
    Redirect::query()->create(['source' => '/old-serum/', 'target' => '/shop/', 'code' => 301, 'enabled' => true, 'auto_created' => false]);
    \App\Http\Middleware\CheckRedirects::flushIndex();

    // Through the kernel, as RedirectMiddlewareTest does, so the global stack runs as on the server.
    $r = app(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create('http://localhost/old-serum/', 'GET'));
    expect($r->getStatusCode())->toBe(301);
    expect($r->headers->get('Location'))->toContain('/shop/')
        ->and($r->getContent())->not->toContain('nf-hero');
});

it('still records the miss in the not-found log', function () {
    // MUTATION: return respond() BEFORE NotFoundLogger::record() -> red.
    $this->get('/a-dead-link-from-instagram', ['Referer' => 'https://instagram.com/x'])->assertStatus(404);

    expect(NotFoundLog::query()->where('path', 'like', '%a-dead-link-from-instagram%')->count())->toBe(1);
});

/* ─────────────────────── what keeps its own 404 ─────────────────────── */

it('leaves the admin, admin-api, api, JSON and POST 404s exactly as they were', function () {
    // MUTATION: drop any one clause from NotFoundPage::isShopperPage() -> red
    // on that request: it would gain the shop's layout.
    $cases = [
        'admin' => $this->get('/admin/no-such-screen'),
        'admin-api' => $this->get('/admin-api/no-such-endpoint'),
        'api' => $this->get('/api/no-such-endpoint'),
        'json' => $this->getJson('/no-such-page-json'),
        'post' => $this->post('/no-such-form-target'),
    ];

    // Split so this file's own source, which a debug-mode error page quotes,
    // cannot satisfy the check.
    $marker = 'class="nf'.'-hero"';
    foreach ($cases as $k => $r) {
        expect($r->getStatusCode() >= 400)->toBeTrue("{$k}: {$r->getStatusCode()}")
            ->and($r->getContent())->not->toContain($marker);
    }

    $this->getJson('/no-such-page-json')->assertStatus(404)->assertJsonStructure(['message']);
});

it('leaves the owner app’s own JSON 404 alone', function () {
    $app = OwnerAppPath::current();

    $r = $this->get('/'.$app.'/no-such-thing-in-the-app');
    expect($r->getContent())->not->toContain('nf-hero');
    if (str_contains((string) $r->headers->get('Content-Type'), 'json')) {
        expect($r->json())->toBe(['ok' => false, 'code' => 'not_found']);
    }
})->skip(fn () => OwnerAppPath::current() === null, 'no owner app address on this install');

it('falls back to the plain 404, still a 404, if the page cannot be drawn', function () {
    // The layout failing (a database outage, a view not deployed yet) must not
    // turn a 404 into a 500. MUTATION: remove the try/catch in respond() -> red.
    View::composer('store.not-found', function () {
        throw new RuntimeException('layout down');
    });

    $r = $this->get('/no-such-page-while-down');
    expect($r->getStatusCode())->toBe(404)
        ->and($r->getContent())->not->toContain('nf-hero');
});

/* ─────────────────────── the controls reach the page ─────────────────────── */

it('prints the owner’s own words escaped, and the design’s words when a field is empty', function () {
    // MUTATION: print the headline with {!! !!} in the view -> red on the tag.
    nfSet(['text' => ['en' => ['h' => 'Oh honey <script>alert(1)</script>', 'em' => '', 's' => '']]]);

    $html = $this->get('/nope')->getContent();
    expect($html)->toContain('Oh honey &lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<em>but you look gorgeous.</em>')
        ->and($html)->toContain('This page slipped off like a sheet mask');
});

it('switches the button, the search box, each link and the strip on and off', function () {
    nfProducts(5);
    nfSet([
        'home' => ['on' => false], 'search' => false, 'trend' => ['on' => false], 'motion' => false,
        'links' => ['sale' => ['on' => false], 'wa' => ['on' => false], 'shop' => ['en' => 'Everything']],
    ]);

    $html = $this->get('/nope')->getContent();
    expect($html)->not->toContain('class="nf-cta"')
        ->and($html)->not->toContain('class="nf-sf"')
        ->and($html)->not->toContain('class="nf-sale"')
        ->and($html)->not->toContain('class="nf-wa"')
        ->and($html)->toContain('Everything</a>')
        ->and($html)->toContain('class="nf-best"')
        ->and($html)->not->toContain('class="nf-trend"')
        ->and($html)->toMatch('#class="nf nf-b[^"]*nf-still#');
});

it('carries the per-device values as custom properties and classes', function () {
    nfSet([
        'accent' => '#C13E63',
        'd' => ['art' => 120, 'h1' => 60, 'cols' => 6, 'align' => 'center'],
        'm' => ['art_on' => false, 'pt' => 10, 'align' => 'start'],
    ]);

    $html = $this->get('/nope')->getContent();
    expect($html)->toContain('--nf-art-d:1.20')
        ->and($html)->toContain('--nf-h1-d:60px')
        ->and($html)->toContain('--nf-cols-d:6')
        ->and($html)->toContain('--nf-pt-m:10px')
        ->and($html)->toContain('--nf-accent:#C13E63')
        ->and($html)->toContain('class="nf nf-b nf-noart-m nf-al-m-start nf-al-d-center"');
});

it('shows the trending strip from the list chosen, and links to that list', function () {
    nfProducts(6);
    nfSet(['trend' => ['list' => 'new', 'count' => 6]]);

    $html = $this->get('/nope')->getContent();
    preg_match('#<section class="nf-trend".*?</section>#s', $html, $strip);
    preg_match_all('#/product/nf-prod-\d+/#', $strip[0] ?? '', $m);
    expect(count(array_unique($m[0])))->toBe(6)
        ->and($html)->toContain('Just landed')
        ->and($html)->toMatch('#<a href="[^"]*/new-in/">See all</a>#');
});

/* ─────────────────────── validation: secure by construction ─────────────────────── */

it('refuses a home link that is not a shop path or an http(s) address', function () {
    // MUTATION: make validLink() return true -> red.
    foreach (['javascript:alert(1)', '//evil.test/x', 'data:text/html,hi', ' /shop x', '/\\evil.test', 'ftp://x.test/'] as $bad) {
        expect(NotFoundPage::save(array_replace_recursive(NotFoundPage::defaults(), ['home' => ['url' => $bad]])))
            ->toHaveKey('home.url');
    }
    foreach (['/', '/shop/', 'https://kbeautybliss.com/new-in/'] as $good) {
        expect(NotFoundPage::save(array_replace_recursive(NotFoundPage::defaults(), ['home' => ['url' => $good]])))->toBe([], $good);
    }
});

it('refuses an accent colour too faint to read on the chosen design', function () {
    // MUTATION: drop the contrast check in clean() -> red: #FFE4EC on design B
    // is about 1.1:1 against its blush background.
    expect(NotFoundPage::save(array_replace_recursive(NotFoundPage::defaults(), ['accent' => '#FFE4EC'])))->toHaveKey('accent');

    // Design A is dark, so the same pale colour is fine there and a dark one is not.
    expect(NotFoundPage::save(array_replace_recursive(NotFoundPage::defaults(), ['design' => 'a', 'accent' => '#FFE4EC'])))->toBe([])
        ->and(NotFoundPage::save(array_replace_recursive(NotFoundPage::defaults(), ['design' => 'a', 'accent' => '#2A2228'])))->toHaveKey('accent');
});

it('offers only palette colours that pass its own contrast floor', function () {
    foreach (NotFoundPage::PALETTE as $d => $colours) {
        foreach ($colours as $c) {
            expect(NotFoundPage::contrast($c, NotFoundPage::HERO_BG[$d]))->toBeGreaterThanOrEqual(NotFoundPage::MIN_CONTRAST, "{$d} {$c}");
        }
    }
});

it('stores one of its own options or nothing: unknown designs, lists, counts and ranges are refused', function () {
    $errors = NotFoundPage::save(array_replace_recursive(NotFoundPage::defaults(), [
        'design' => 'z', 'trend' => ['list' => 'everything', 'count' => 5],
        'd' => ['h1' => 500, 'cols' => 0], 'm' => ['align' => 'justify', 'art' => '100'],
        'text' => ['en' => ['h' => str_repeat('x', 81)]],
    ]));

    expect(array_keys($errors))->toEqualCanonicalizing(['design', 'trend.list', 'trend.count', 'd.h1', 'd.cols', 'm.align', 'm.art', 'text.en.h']);
    nfForget();
    expect(app(SettingsService::class)->get(NotFoundPage::KEY))->toBeNull();
});

it('reads a row written behind the screen’s back field by field, falling back to each default', function () {
    // MUTATION: return the stored row as-is from config() -> red.
    app(SettingsService::class)->set(NotFoundPage::KEY, [
        'design' => 'c', 'accent' => 'red;background:url(x)', 'd' => ['h1' => '50px;}', 'cols' => 6],
        'home' => ['url' => 'javascript:alert(1)'],
    ]);
    nfForget();

    $cfg = NotFoundPage::config();
    expect($cfg['design'])->toBe('c')
        ->and($cfg['accent'])->toBe('')
        ->and($cfg['d']['h1'])->toBe(50)
        ->and($cfg['d']['cols'])->toBe(6)
        ->and($cfg['home']['url'])->toBe('/');
});

/* ─────────────────────── fast, and flat in the catalogue ─────────────────────── */

it('costs the same with 3 products as with 40, and the strip costs no query once cached', function () {
    // MUTATION: drop Cache::remember() in trending() -> red on the products
    // query; load the brand per card instead of with() -> red on the counts.
    nfProducts(3);
    nfQueries('/warm-a');
    $three = nfQueries('/nope-a');

    nfProducts(37);
    NotFoundPage::forgetTrending();
    nfQueries('/warm-b');
    $forty = nfQueries('/nope-b');

    expect(count($forty))->toBe(count($three), implode("\n", $forty));

    $productReads = array_filter($forty, fn (string $q) => preg_match('/from ["`]?products["`]?/i', $q) === 1);
    expect(array_values($productReads))->toBe([], 'the trending strip re-queried products on a warm cache');
});

/* ─────────────────────── the admin endpoints ─────────────────────── */

it('serves the screen everything it needs in one GET, behind its own capability', function () {
    // MUTATION: remove the RULES line for admin-api/not-found-page -> the
    // manager is refused (a path missing from the map is owner-only).
    $json = $this->actingAs(nfAdmin('manager'), 'admin')->getJson('/admin-api/not-found-page')->assertOk()->json();

    expect($json['config']['design'])->toBe('b')
        ->and(array_keys($json['designs']))->toBe(['a', 'b', 'c', 'd'])
        ->and($json['designs']['b']['art']['ar'])->toContain('آه!')
        ->and($json['css'])->toContain('.nf-hero')
        ->and($json)->toHaveKeys(['defaults', 'labels', 'ranges', 'max', 'fontCss', 'icons']);
});

it('fails closed for a role without the capability', function () {
    // MUTATION: add 'editor' to notfoundpage.manage -> red.
    $editor = nfAdmin('editor');
    $this->actingAs($editor, 'admin')->getJson('/admin-api/not-found-page')->assertForbidden();
    $this->actingAs($editor, 'admin')->postJson('/admin-api/not-found-page', ['config' => NotFoundPage::defaults()])->assertForbidden();
});

it('saves once, and refuses a bad field without writing anything', function () {
    $owner = nfAdmin();
    $cfg = NotFoundPage::defaults();
    $cfg['design'] = 'd';

    $this->actingAs($owner, 'admin')->postJson('/admin-api/not-found-page', ['config' => $cfg])
        ->assertOk()->assertJsonPath('config.design', 'd');
    nfForget();
    expect(NotFoundPage::config()['design'])->toBe('d');

    $cfg['design'] = 'a';
    $cfg['home']['url'] = 'javascript:alert(1)';
    $r = $this->actingAs($owner, 'admin')->postJson('/admin-api/not-found-page', ['config' => $cfg])->assertStatus(422);
    expect($r->json('fields'))->toHaveKey('home.url');
    nfForget();
    expect(NotFoundPage::config()['design'])->toBe('d')
        ->and(NotFoundPage::config()['home']['url'])->toBe('/');
});

it('keeps the admin screen free of layout-measuring code and of any per-change request', function () {
    // The preview is drawn from the one GET; the only fetch() is api(), called
    // by load() and save(). MUTATION: fetch inside the input handler -> red.
    $src = (string) file_get_contents(resource_path('views/admin/partials/not-found-page-screen.blade.php'));

    expect(substr_count($src, 'fetch('))->toBe(1)
        ->and(substr_count($src, "api('GET')"))->toBe(1)
        ->and(substr_count($src, "api('POST'"))->toBe(1)
        ->and($src)->not->toMatch('/getBoundingClientRect|offsetWidth|offsetHeight|clientWidth|ResizeObserver|setInterval|setTimeout/')
        ->and($src)->toContain('sandbox="allow-same-origin"');
});
