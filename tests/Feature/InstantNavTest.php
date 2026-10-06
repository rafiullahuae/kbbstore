<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\SettingsService;
use App\Support\InstantNav;
use Illuminate\Support\Facades\Route;

/*
 * INSTANT PAGE CHANGES (Lane SP). Appearance → Site layout → Page speed.
 *
 * The owner: "when i go to any product etc page, the browser bar appears but
 * the layout is shifting late. i want super blazing speed with super fast
 * shifting layout from one page to another." App\Support\InstantNav says what
 * ships and why; this file pins the parts that would cost him money or trust
 * if they went wrong:
 *
 *   - ONLY read-only shop pages are ever fetched ahead -- never the cart,
 *     checkout, account, login, the admin, the owner app, /api, a file, or
 *     anything with a query (?add-to-cart=). Measured in Chromium 141 before
 *     this was written: the obvious rule "/product/*" fetched
 *     /product/x/?add-to-cart=1 on hover.
 *   - a page fetched ahead and never opened records nothing as viewed
 *     (Chromium stored the "Recently viewed" cookie from an unused prefetch).
 *   - a change (add to bag ...) throws away what was fetched ahead, so the
 *     next page never shows the old bag (measured: 1 on the page, 0 on the
 *     prefetched page, before ClearPrefetchOnWrite).
 *   - Data Saver / 2G fetch nothing; reduced motion gets no fade.
 */

function inPage(string $html): array
{
    preg_match('#<script data-r="([^"]+)">(.*?)</script>#s', $html, $m);

    return $m === [] ? [] : [json_decode(html_entity_decode($m[1], ENT_QUOTES), true), $m[2]];
}

/** Does any rule pathname match this address? search must be empty: a query never matches. */
function ruleMatches(array $rules, string $url): bool
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $query = (string) parse_url($url, PHP_URL_QUERY);

    foreach ($rules['prefetch'][0]['where']['and'][0]['href_matches'] as $p) {
        $re = '#^'.str_replace('\*', '.*', preg_quote($p['pathname'], '#')).'$#';
        if (preg_match($re, $path) === 1 && $query === ($p['search'] ?? '*')) {
            return true;
        }
    }

    return false;
}

function navSet(bool $instant, bool $fade): void
{
    $s = app(SettingsService::class);
    $s->set('layout_nav_instant', $instant ? '1' : '0');
    $s->set('layout_nav_fade', $fade ? '1' : '0');
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

beforeEach(function () {
    test()->seed(\Database\Seeders\DatabaseSeeder::class);
});

it('ships the instant switch ON and the fade OFF: one script line on every page', function () {
    $product = Product::query()->visible()->firstOrFail();

    foreach (['/', '/shop/', '/product/'.$product->slug.'/'] as $path) {
        $html = test()->get($path)->assertOk()->getContent();
        expect(substr_count($html, '<script data-r='))->toBe(1, $path)
            // MUTATION: ship nav_fade at true and this is red -- measured to
            // cost the next page 60-110 ms of first paint (InstantNav).
            ->and($html)->not->toContain('@view-transition');
    }
});

it('fetches ahead ONLY read-only shop pages, never anything that changes or is private', function () {
    $product = Product::query()->visible()->firstOrFail();
    [$rules] = inPage(test()->get('/product/'.$product->slug.'/')->getContent());

    expect($rules['prefetch'][0]['eagerness'])->toBe('moderate')
        ->and(array_keys($rules))->toBe(['prefetch']);       // never prerender: pixels would count

    $never = [
        '/cart/', '/cart', '/checkout/', '/checkout/place', '/checkout/success', '/my-account/', '/my-account/orders/',
        '/my-wishlist/', '/wishlist', '/wishlist/toggle', '/login/', '/logout', '/register/', '/order-pay/1/',
        '/api/cart/add', '/api/viewed', '/api/products', '/'.trim(\App\Services\AdminPathService::current(), '/').'/',
        '/'.trim((string) (\App\Services\OwnerApp\OwnerAppPath::current() ?? 'owner-app'), '/').'/',
        '/product/'.$product->slug.'/?add-to-cart='.$product->id, '/shop/?filter_brands=x', '/shop/?page=2',
        '/wp-content/uploads/a.pdf', '/storage/invoice.pdf', '/sitemap.xml', '/manifest.webmanifest', '/sw.js',
    ];
    $leaks = array_values(array_filter($never, fn (string $u): bool => ruleMatches($rules, $u)));
    expect($leaks)->toBe([], 'fetched ahead: '.implode(', ', $leaks));

    // And the other direction: every GET route the rules DO reach is a shop
    // page from a read-only storefront controller. A route added later under
    // /shop/ or /product/ that writes or is private turns this red.
    $readOnly = [
        \App\Http\Controllers\Store\HomeController::class, \App\Http\Controllers\Store\ShopController::class,
        \App\Http\Controllers\Store\ProductController::class, \App\Http\Controllers\Store\BrandController::class,
        \App\Http\Controllers\Store\CategoryArchiveController::class, \App\Http\Controllers\Store\CollectionController::class,
        \App\Http\Controllers\Store\PageController::class,
    ];
    $reached = [];
    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $sample = '/'.ltrim(preg_replace('#\{[^}]+\}#', 'x', $route->uri()), '/');
        $sample .= str_ends_with($sample, '/') ? '' : '/';
        if (! ruleMatches($rules, $sample)) {
            continue;
        }
        $action = $route->getActionName();
        $class = str_contains($action, '@') ? strstr($action, '@', true) : $action;
        if ($class !== 'Closure' && ! in_array($class, $readOnly, true) && ! str_contains($class, 'Blog') && ! str_contains($class, 'Post')) {
            $reached[] = $route->uri().' -> '.$action;
        }
    }
    expect($reached)->toBe([]);

    // The admin path is a secret setting: it is never printed to exclude it.
    $html = test()->get('/shop/')->getContent();
    expect($html)->not->toContain('/'.trim(\App\Services\AdminPathService::current(), '/').'/');

    // A query and the semantic opt-outs are refused by selector as well.
    $skip = $rules['prefetch'][0]['where']['and'][1]['not']['selector_matches'];
    foreach (['[href*=\'?\']', '[rel~=nofollow]', '[data-no-prefetch]', '[download]', '[target=_blank]'] as $sel) {
        expect($skip)->toContain($sel);
    }
});

it('runs the rules only where the browser can, and never on Data Saver or 2G', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        test()->markTestSkipped('node is not installed here');
    }
    [$rules, $js] = inPage(test()->get('/shop/')->getContent());
    $file = tempnam(sys_get_temp_dir(), 'kbbnav').'.js';

    // The page's own inline script, run four times in node against a stub
    // document: does it add a <script type=speculationrules>?
    file_put_contents($file, 'const JS = '.json_encode($js).'; const R = '.json_encode(json_encode($rules)).';
const out = {};
for (const [name, conn, supports] of [["4g", {effectiveType:"4g"}, true], ["saveData", {saveData:true, effectiveType:"4g"}, true],
    ["2g", {effectiveType:"2g"}, true], ["slow-2g", {effectiveType:"slow-2g"}, true], ["unsupported", {effectiveType:"4g"}, false]]) {
  const added = [];
  const doc = { currentScript: { dataset: { r: R } }, createElement: () => ({}), head: { appendChild: (e) => added.push(e) } };
  globalThis.HTMLScriptElement = { supports: (t) => supports && t === "speculationrules" };
  Object.defineProperty(globalThis, "navigator", { value: { connection: conn }, configurable: true });
  new Function("document", "window", JS.replace("(document,window)", "(document,window)"))(doc, globalThis);
  out[name] = added.map((e) => e.type + ":" + (e.textContent === R));
}
console.log(JSON.stringify(out));');
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    // MUTATION: drop `c.saveData||` or the /2g/ test from the partial and the
    // saveData / 2g rows below gain a rule.
    expect(json_decode(trim($raw), true))->toBe([
        '4g' => ['speculationrules:true'], 'saveData' => [], '2g' => [], 'slow-2g' => [], 'unsupported' => [],
    ], $raw);
});

it('gives reduced motion no fade, and each switch takes its own part away', function () {
    navSet(true, true);
    $html = test()->get('/shop/')->getContent();
    expect($html)->toContain('@media (prefers-reduced-motion:reduce){@view-transition{navigation:none}}')
        ->and($html)->toContain('body>header{view-transition-name:kbb-hd}');

    navSet(true, false);
    $html = test()->get('/shop/')->getContent();
    expect($html)->not->toContain('@view-transition')->and($html)->toContain('<script data-r=');

    navSet(false, true);
    $html = test()->get('/shop/')->getContent();
    expect($html)->toContain('@view-transition')->and($html)->not->toContain('<script data-r=');

    // Both off: the head is the page as it was, not one byte of this lane.
    navSet(false, false);
    $html = test()->get('/shop/')->getContent();
    expect($html)->not->toContain('@view-transition')->and($html)->not->toContain('data-r=')->and($html)->not->toContain('/api/viewed');
});

it('records nothing as viewed for a page fetched ahead, and records it when the page is opened', function () {
    $product = Product::query()->visible()->firstOrFail();
    $url = '/product/'.$product->slug.'/';

    // A speculative fetch: no "Recently viewed" cookie, and the page asks for
    // it itself -- a script that only runs if the page is shown.
    // MUTATION: call rememberViewed() unconditionally in ProductController and
    // the first assertion is red (that is what Chromium stored on hover).
    $spec = test()->withHeaders(['Sec-Purpose' => 'prefetch'])->get($url)->assertOk();
    expect(collect($spec->headers->getCookies())->map->getName()->all())->not->toContain('kbb_viewed')
        ->and($spec->getContent())->toContain('data-u="/api/viewed"')
        ->and($spec->getContent())->toContain('data-i="'.$product->id.'"');

    // An ordinary open: the cookie as before, and no extra script.
    $open = test()->withHeaders(['Sec-Purpose' => ''])->get($url)->assertOk();
    expect(collect($open->headers->getCookies())->map->getName()->all())->toContain('kbb_viewed')
        ->and($open->getContent())->not->toContain('/api/viewed');

    expect(InstantNav::isSpeculative(\Illuminate\Http\Request::create('/', 'GET', [], [], [], ['HTTP_SEC_PURPOSE' => 'prefetch;prerender'])))->toBeTrue()
        ->and(InstantNav::isSpeculative(\Illuminate\Http\Request::create('/', 'GET', [], [], [], ['HTTP_PURPOSE' => 'prefetch'])))->toBeTrue()
        ->and(InstantNav::isSpeculative(\Illuminate\Http\Request::create('/')))->toBeFalse();
});

it('writes "Recently viewed" from the opened page, for a visible product only', function () {
    Route::middleware('web')->group(base_path('routes/instant-nav.php'));
    $product = Product::query()->visible()->firstOrFail();

    $ok = test()->post('/api/viewed', ['id' => $product->id])->assertNoContent();
    expect(collect($ok->headers->getCookies())->map->getName()->all())->toContain('kbb_viewed');

    $hidden = Product::query()->visible()->skip(1)->firstOrFail();
    $hidden->forceFill(['status' => 'draft'])->save();
    test()->post('/api/viewed', ['id' => $hidden->id])->assertNotFound();
    test()->post('/api/viewed', ['id' => 'x'])->assertNotFound();
});

it('throws away pages fetched ahead whenever something changes, and only then', function () {
    Route::middleware('web')->group(base_path('routes/instant-nav.php'));
    $product = Product::query()->visible()->firstOrFail();

    // MUTATION: drop ClearPrefetchOnWrite from bootstrap/app.php and the
    // first assertion is red -- the bag shown on a prefetched page goes stale.
    expect(test()->post('/api/viewed', ['id' => $product->id])->headers->get('Clear-Site-Data'))->toBe('"prefetchCache"')
        ->and(test()->get('/shop/')->headers->get('Clear-Site-Data'))->toBeNull();

    navSet(false, true);
    expect(test()->post('/api/viewed', ['id' => $product->id])->headers->get('Clear-Site-Data'))->toBeNull();
});

it('leaves "back" where the shopper is: a page fetched ahead is not the previous page', function () {
    $product = Product::query()->visible()->firstOrFail();

    // A form that fails validation redirects back to the session's previous
    // URL. Hovering a link must not move it there -- Laravel skips
    // `Sec-Purpose: prefetch` (StartSession::storeCurrentUrl), and this pins
    // that the header InstantNav makes Chrome send is the one it skips.
    test()->get('/shop/')->assertOk();
    test()->withHeaders(['Sec-Purpose' => 'prefetch'])->get('/product/'.$product->slug.'/')->assertOk();

    expect(session()->previousUrl())->toEndWith('/shop');
});
