<?php

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE DEFECT, ON THE SHOP, 24 SEPTEMBER 2026
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `/_kbb-health` ran `SELECT 1` and returned `{"ok":true}`. It never rendered a
 * page. UpdateRunner asks it one question after writing files -- "does the site
 * still work?" -- and rolls the whole package back if the answer is no.
 *
 * Package 2.60.260 removed a class that every product tile resolves out of the
 * container. The home page, /shop, every category, every brand and every
 * product page went to 500. The health check answered ok, because PHP was
 * running and MySQL was up, which is all it had ever asked. The update was
 * KEPT, the rollback never fired, and the shop was down for an hour behind a
 * green check.
 *
 * These tests fail against that endpoint. `it notices when product pages are
 * down` and `it notices when the home page is down` both drive the storefront
 * into exactly 2.60.260's state and require the check to say so; a `SELECT 1`
 * cannot.
 *
 * MUTATION, run: in StorefrontHealth::check(), delete the two lines that push
 * homePage() and productPage() onto $checks -- which is precisely the endpoint
 * as it shipped. Eight of the fourteen tests in this file go green-to-red.
 *
 * SECOND MUTATION, run: leave the pages in but drop the `</html>` test from
 * judge(). `it notices a page that stops halfway` goes red. That case is the
 * one a status code cannot reach: a real PHP fatal mid-render flushes the
 * output buffer with 200 already sent.
 *
 * THIRD MUTATION, run: drop the $mustContain test from judge(). `it notices a
 * product page that does not know which product it is` goes red.
 * ═══════════════════════════════════════════════════════════════════════════
 */

use App\Models\Product;
use Illuminate\Support\Facades\Route;

const HEALTH_TOKEN = 'health-token-for-this-test-0123456789';

/**
 * Wires the route exactly the way routes/web.php is asked to wire it, so these
 * tests exercise the file that ships rather than a copy of its contents.
 *
 * Laravel's RouteCollection is keyed by method and URI, so this registration
 * replaces the closure web.php still carries until the integrator removes it.
 * That is also why the wiring note in routes/update-health.php insists the
 * closure be deleted rather than left: both work, and only one is honest.
 */
function wireHealthRoute(): void
{
    config(['kbb.health_token' => HEALTH_TOKEN, 'kbb.health_deep' => true]);

    Route::middleware('web')->group(base_path('routes/update-health.php'));
}

/**
 * The migration set seeds a real catalogue, so a test that needs to know WHICH
 * product the check will pick has to own the catalogue first. Hiding rather
 * than deleting: products are referenced by order lines, reviews and menus.
 */
function healthOnlyCatalogue(): void
{
    Product::query()->update(['is_visible' => false]);
}

function healthProduct(string $slug = 'health-check-toner'): Product
{
    return Product::create([
        'slug' => $slug,
        'name' => 'Health Check Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 100,
        'stock_status' => 'instock',
    ]);
}

/** @return array<string, array<string, mixed>> keyed by check name */
function healthChecks(array $body): array
{
    $out = [];

    foreach ($body['checks'] ?? [] as $check) {
        $out[$check['check']] = $check;
    }

    return $out;
}

/* ═════════════════════════════════════════════ the gate, unchanged ════════ */

it('is a 404 without the token, and says nothing else', function () {
    wireHealthRoute();

    // Not 401 and not 403: an endpoint that distinguishes "wrong token" from
    // "no such page" tells a scanner there is something here to guess at.
    $this->get('/_kbb-health')->assertNotFound();
    $this->get('/_kbb-health?token=nearly-right')->assertNotFound();
});

it('is a 404 when no token is configured at all', function () {
    wireHealthRoute();
    config(['kbb.health_token' => '']);

    $this->get('/_kbb-health?token=')->assertNotFound();
});

/* ═════════════════════════════════════════════ the working shop ═══════════ */

it('renders the home page and a product page and reports both', function () {
    wireHealthRoute();
    healthOnlyCatalogue();
    $product = healthProduct();

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    $response->assertOk();

    $body = $response->json();
    $checks = healthChecks($body);

    expect($body['ok'])->toBeTrue($body['reason'] ?? '')
        ->and($checks)->toHaveKeys(['database', 'home', 'product'])
        ->and($checks['home']['ok'])->toBeTrue()
        ->and($checks['product']['ok'])->toBeTrue()
        ->and($checks['product']['slug'])->toBe($product->slug);
});

it('still carries ok and version, so an older UpdateRunner is unaffected', function () {
    wireHealthRoute();
    healthProduct();

    $this->getJson('/_kbb-health?token='.HEALTH_TOKEN)
        ->assertOk()
        ->assertJson(['ok' => true, 'version' => config('kbb.version')]);
});

/* ═════════════════════════════════════════════ 2.60.260, reproduced ═══════ */

it('notices when product pages are down', function () {
    wireHealthRoute();
    healthProduct();

    /*
     * What 2.60.260 did: the templates behind every product page resolved a
     * class that was no longer installed, so the page threw. Laravel catches it
     * and renders 500 -- and the old endpoint, which never asked for the page,
     * answered ok anyway and the package was kept.
     */
    Route::middleware('web')
        ->get('/product/{slug}', fn () => throw new RuntimeException('Class App\Services\VariantPricing not found'))
        ->name('product.show');

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    $response->assertStatus(503);

    $body = $response->json();

    expect($body['ok'])->toBeFalse('a shop whose every product page 500s must not pass its own health check')
        ->and($body['reason'])->toContain('product')
        ->and(healthChecks($body)['product']['ok'])->toBeFalse();
});

it('notices when the home page is down', function () {
    wireHealthRoute();
    healthProduct();

    Route::middleware('web')
        ->get('/', fn () => throw new RuntimeException('Class App\Services\VariantPricing not found'))
        ->name('home');

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    $response->assertStatus(503);

    expect($response->json('ok'))->toBeFalse()
        ->and(healthChecks($response->json())['home']['ok'])->toBeFalse();
});

it('notices a page that stops halfway', function () {
    wireHealthRoute();
    healthProduct();

    /*
     * The case a status code cannot reach. A genuine PHP fatal during view
     * rendering -- a parse error in a file the package just replaced, an
     * exhausted memory limit -- is not a throwable. PHP flushes whatever the
     * output buffer holds and the response keeps the 200 it had already
     * started. What comes back is a real HTTP 200 carrying half a page.
     */
    Route::middleware('web')->get('/', fn () => response(
        '<!DOCTYPE html><html lang="en"><body><div class="grid">'.str_repeat('<span>x</span>', 200),
        200,
        ['Content-Type' => 'text/html; charset=UTF-8'],
    ))->name('home');

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    expect($response->json('ok'))->toBeFalse('a 200 that stops before </html> is a fatal mid-render, not a page')
        ->and(healthChecks($response->json())['home']['detail'])->toContain('</html>');
});

it('notices a product page that does not know which product it is', function () {
    wireHealthRoute();
    healthOnlyCatalogue();
    healthProduct();

    // A complete, valid, 200 HTML document that is not this product's page.
    Route::middleware('web')->get('/product/{slug}', fn () => response(
        '<!DOCTYPE html><html lang="en"><head><title>x</title></head><body>'
        .str_repeat('<p>a perfectly fine page about nothing</p>', 40)
        .'</body></html>',
        200,
        ['Content-Type' => 'text/html; charset=UTF-8'],
    ))->name('product.show');

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    expect($response->json('ok'))->toBeFalse()
        ->and(healthChecks($response->json())['product']['detail'])->toContain('health-check-toner');
});

it('notices a storefront page that redirects instead of rendering', function () {
    wireHealthRoute();
    healthProduct();

    Route::middleware('web')->get('/', fn () => redirect('/somewhere-else'))->name('home');

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    expect($response->json('ok'))->toBeFalse()
        ->and(healthChecks($response->json())['home']['detail'])->toContain('redirect');
});

/* ═══════════════════════════════════ what must NOT fail the check ═════════ */

it('passes on a shop with an empty catalogue', function () {
    wireHealthRoute();

    // A fresh install, or a catalogue mid-import. There is no product page to
    // render and that is not a fault. If this ever fails, a brand new shop can
    // never install its first update.
    healthOnlyCatalogue();

    expect(Product::query()->visible()->count())->toBe(0);

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    $response->assertOk();

    expect($response->json('ok'))->toBeTrue()
        ->and(healthChecks($response->json())['product']['detail'])->toContain('no visible products');
});

it('passes when one product is broken but the others render', function () {
    wireHealthRoute();

    // One bad row must not be able to block every future update for good. The
    // check tries the first few visible products and passes if any renders.
    healthOnlyCatalogue();
    healthProduct('first-product');
    healthProduct('second-product');

    Route::middleware('web')->get('/product/{slug}', function (string $slug) {
        if ($slug === 'first-product') {
            throw new RuntimeException('this one row is bad');
        }

        return response(
            '<!DOCTYPE html><html lang="en"><body>'.str_repeat('<p>'.e($slug).'</p>', 40).'</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    })->name('product.show');

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    expect($response->json('ok'))->toBeTrue($response->json('reason'))
        ->and(healthChecks($response->json())['product']['slug'])->toBe('second-product');
});

it('can be switched off in an emergency without editing code', function () {
    /*
     * The one escape hatch. A shop already broken for an unrelated reason
     * cannot install the package that repairs it, because the check fails on
     * damage the package did not cause. KBB_HEALTH_DEEP=false in .env over SSH
     * puts the endpoint back to what it was, for as long as it takes.
     */
    wireHealthRoute();
    config(['kbb.health_deep' => false]);

    Route::middleware('web')->get('/', fn () => throw new RuntimeException('down'))->name('home');

    $response = $this->getJson('/_kbb-health?token='.HEALTH_TOKEN);

    $response->assertOk();

    expect($response->json('ok'))->toBeTrue()
        ->and(healthChecks($response->json())['storefront']['detail'])->toContain('KBB_HEALTH_DEEP');
});

it('defaults to on, which is the whole point of the patch', function () {
    // Rule 1 says a new setting ships at the value the page already has. This
    // one deliberately does not, and the commit says so: shipping the check
    // switched off would have shipped the fix without the protection.
    expect(config('kbb.health_deep'))->toBeTrue();
});

/* ═══════════════════════════════════ the reason survives the trip ═════════ */

it('hands UpdateRunner a reason it can print, not just a status code', function () {
    /*
     * The endpoint answers 503 when the shop is not serving and puts the
     * diagnosis in the body. UpdateRunner::healthCheck() returned before
     * reading the body on any non-2xx, so every one of those became the string
     * "HTTP 503" on the Core Updates screen and the owner was told an update
     * had been rolled back without being told what for.
     *
     * MUTATION, run: restore the `if (! $response->successful()) return [...]`
     * early return at the top of healthCheck() and this goes red.
     */
    $source = file_get_contents(app_path('Services/Update/UpdateRunner.php'));

    expect($source)->toContain("\$reason = \$response->json('reason');")
        ->and($source)->toContain("? \$reason . ' (HTTP ' . \$response->status() . ')'")
        // The early return that threw the body away. Only the docblock explaining
        // why it is gone may still name it, so this looks for the statement.
        ->and($source)->not->toContain('return [\'ok\' => false, \'reason\' => \'HTTP \' . $response->status()];');
});
